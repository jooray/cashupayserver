"""LND fixtures: two regtest LND nodes wired to the shared bitcoind, plus
dual-channel setup (two 10M-sat channels, each pushing 5M sats to the
other side) so both directions have liquidity.

Communication with each node is over the REST gateway (TLS + macaroon).
Using REST keeps the dependency surface small — no proto stubs to ship.
"""
from __future__ import annotations

import json
import shutil
import signal
import subprocess
import time
import urllib.parse
from base64 import urlsafe_b64encode
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

from . import binaries, ports
from .bitcoind import BitcoindHandle


@dataclass
class LndHandle:
    name: str
    process: subprocess.Popen[bytes]
    home: Path
    rpc_port: int        # gRPC port (we don't use, but reserved)
    p2p_port: int
    rest_port: int
    lnd_exe: Path
    lncli_exe: Path

    @property
    def tls_cert(self) -> Path:
        return self.home / "tls.cert"

    @property
    def admin_macaroon(self) -> Path:
        return self.home / "data" / "chain" / "bitcoin" / "regtest" / "admin.macaroon"

    @property
    def rest_url(self) -> str:
        return f"https://127.0.0.1:{self.rest_port}"

    def _headers(self) -> dict[str, str]:
        mac_hex = self.admin_macaroon.read_bytes().hex()
        return {"Grpc-Metadata-macaroon": mac_hex, "Content-Type": "application/json"}

    def _request(self, method: str, path: str, *, json_body: Any = None, timeout: float = 30) -> Any:
        url = f"{self.rest_url}{path}"
        resp = requests.request(
            method,
            url,
            headers=self._headers(),
            json=json_body,
            verify=str(self.tls_cert),
            timeout=timeout,
        )
        if not resp.ok:
            raise RuntimeError(f"LND {self.name} {method} {path} -> {resp.status_code}: {resp.text}")
        if not resp.content:
            return None
        return resp.json()

    def wait_ready(self, timeout_s: float = 120.0) -> None:
        """LND has several startup phases; getinfo can return before the gRPC
        sub-servers (wallet, peer, router) are up. We poll three RPCs that
        hit different subservers and only succeed once everything's online."""
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            if not self.admin_macaroon.exists() or not self.tls_cert.exists():
                time.sleep(0.3)
                continue
            try:
                self._request("GET", "/v1/getinfo", timeout=3)
                # listpeers fails with code 2 'server still starting' until the
                # subservers are up.
                self._request("GET", "/v1/peers", timeout=3)
                # newaddress (wallet sub-server) is the last to come online.
                self._request("GET", "/v1/newaddress?type=0", timeout=3)
                return
            except Exception as e:
                last = e
            time.sleep(0.3)
        raise TimeoutError(f"LND {self.name} not ready after {timeout_s}s ({last})")

    def get_info(self) -> dict[str, Any]:
        return self._request("GET", "/v1/getinfo")

    @property
    def pubkey(self) -> str:
        return self.get_info()["identity_pubkey"]

    def new_address(self, addr_type: str = "p2wkh") -> str:
        # LND REST: GET /v1/newaddress?type=<n> where n is the AddressType enum int.
        # 0=WITNESS_PUBKEY_HASH, 1=NESTED_PUBKEY_HASH, 4=TAPROOT_PUBKEY.
        type_map = {"p2wkh": 0, "np2wkh": 1, "p2tr": 4}
        n = type_map.get(addr_type, 0)
        return self._request("GET", f"/v1/newaddress?type={n}")["address"]

    def wallet_balance_sat(self) -> int:
        return int(self._request("GET", "/v1/balance/blockchain")["total_balance"])

    def channel_balance_sat(self) -> int:
        return int(self._request("GET", "/v1/balance/channels")["local_balance"]["sat"])

    def connect_peer(self, pubkey: str, host: str) -> None:
        body = {"addr": {"pubkey": pubkey, "host": host}, "perm": True}
        # The peer sub-server is occasionally still starting up even after
        # wait_ready() returns. Retry briefly on 'server still starting'.
        last_err: Exception | None = None
        for _ in range(15):
            try:
                self._request("POST", "/v1/peers", json_body=body, timeout=10)
                return
            except RuntimeError as e:
                msg = str(e)
                if "already connected" in msg:
                    return
                if "still in the process of starting" in msg:
                    last_err = e
                    time.sleep(0.5)
                    continue
                raise
        if last_err:
            raise last_err

    def list_peers(self) -> list[dict[str, Any]]:
        return self._request("GET", "/v1/peers").get("peers", [])

    def list_channels(self) -> list[dict[str, Any]]:
        return self._request("GET", "/v1/channels").get("channels", [])

    def open_channel(self, peer_pubkey: str, local_funding_sat: int, push_sat: int) -> dict[str, Any]:
        body = {
            "node_pubkey": urlsafe_b64encode(bytes.fromhex(peer_pubkey)).decode(),
            "local_funding_amount": str(local_funding_sat),
            "push_sat": str(push_sat),
            "sat_per_vbyte": "1",
            "private": False,
        }
        # Returns ChannelPoint with funding_txid_bytes (base64) and output_index.
        return self._request("POST", "/v1/channels", json_body=body)

    def add_invoice(self, value_sat: int, memo: str = "") -> dict[str, Any]:
        body = {"value": str(value_sat), "memo": memo}
        return self._request("POST", "/v1/invoices", json_body=body)

    def pay_invoice_sync(self, payment_request: str, timeout: float = 30) -> dict[str, Any]:
        body = {"payment_request": payment_request}
        return self._request("POST", "/v1/channels/transactions", json_body=body, timeout=timeout)

    def lookup_invoice(self, r_hash_hex: str) -> dict[str, Any]:
        # REST expects URL-safe base64 in path
        encoded = urlsafe_b64encode(bytes.fromhex(r_hash_hex)).decode().rstrip("=")
        return self._request("GET", f"/v2/invoices/lookup?payment_hash={urllib.parse.quote(encoded, safe='')}")


def start_lnd(workdir: Path, name: str, bitcoind: BitcoindHandle) -> LndHandle:
    exes = binaries.ensure(binaries.LND)
    lnd_exe = exes["lnd"]
    lncli_exe = exes["lncli"]

    home = workdir / f"lnd-{name}"
    home.mkdir(parents=True, exist_ok=True)

    rpc_port, p2p_port, rest_port = ports.allocate(3)

    cfg = home / "lnd.conf"
    cfg.write_text(
        "\n".join(
            [
                "[Application Options]",
                "debuglevel=info",
                f"alias=test-{name}",
                "no-macaroons=false",
                "noseedbackup=true",
                "tlsdisableautofill=true",
                f"rpclisten=127.0.0.1:{rpc_port}",
                f"listen=127.0.0.1:{p2p_port}",
                f"restlisten=127.0.0.1:{rest_port}",
                f"externalip=127.0.0.1:{p2p_port}",
                "accept-keysend=true",
                "maxpendingchannels=5",
                "",
                "[Bitcoin]",
                "bitcoin.active=true",
                "bitcoin.regtest=true",
                "bitcoin.node=bitcoind",
                "bitcoin.defaultchanconfs=3",
                "",
                "[Bitcoind]",
                f"bitcoind.rpchost=127.0.0.1:{bitcoind.rpc_port}",
                "bitcoind.rpcuser=regtest",
                "bitcoind.rpcpass=regtest",
                f"bitcoind.zmqpubrawblock=tcp://127.0.0.1:{bitcoind.zmq_block_port}",
                f"bitcoind.zmqpubrawtx=tcp://127.0.0.1:{bitcoind.zmq_tx_port}",
                "",
                "[protocol]",
                "protocol.wumbo-channels=true",
                "",
            ]
        )
    )

    log = (home / "lnd.log").open("ab")
    proc = subprocess.Popen(
        [str(lnd_exe), f"--lnddir={home}", f"--configfile={cfg}"],
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = LndHandle(
        name=name,
        process=proc,
        home=home,
        rpc_port=rpc_port,
        p2p_port=p2p_port,
        rest_port=rest_port,
        lnd_exe=lnd_exe,
        lncli_exe=lncli_exe,
    )
    try:
        handle.wait_ready()
    except Exception:
        stop_lnd(handle)
        raise
    return handle


def stop_lnd(handle: LndHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()


# ---------- channel bring-up ----------

CHANNEL_CAPACITY_SAT = 10_000_000
CHANNEL_PUSH_SAT = 5_000_000


def fund_node(bitcoind: BitcoindHandle, node: LndHandle, btc_amount: float = 1.0) -> None:
    addr = node.new_address()
    bitcoind.send_to_address(addr, btc_amount)
    bitcoind.mine(6)
    # Wait for LND to see the UTXO
    deadline = time.monotonic() + 30
    target_sat = int(btc_amount * 100_000_000)
    while time.monotonic() < deadline:
        if node.wallet_balance_sat() >= target_sat - 50_000:  # account for fees
            return
        time.sleep(0.5)
    raise TimeoutError(f"LND {node.name} did not see on-chain funds after 30s")


def open_dual_channels(bitcoind: BitcoindHandle, lnd_payer: LndHandle, lnd_mint: LndHandle) -> None:
    """Open two 10M-sat channels with 5M pushed each way, so both nodes have
    bidirectional liquidity."""
    # Coinbase maturity: need 100 confirmations before regtest coinbase is spendable.
    if bitcoind.block_count() < 101:
        bitcoind.mine(101 - bitcoind.block_count())

    fund_node(bitcoind, lnd_payer, 1.0)
    fund_node(bitcoind, lnd_mint, 1.0)

    # Mutual peer connection (one direction is enough)
    lnd_payer.connect_peer(lnd_mint.pubkey, f"127.0.0.1:{lnd_mint.p2p_port}")
    _wait_for_peer(lnd_payer, lnd_mint.pubkey)
    _wait_for_peer(lnd_mint, lnd_payer.pubkey)

    lnd_payer.open_channel(lnd_mint.pubkey, CHANNEL_CAPACITY_SAT, CHANNEL_PUSH_SAT)
    lnd_mint.open_channel(lnd_payer.pubkey, CHANNEL_CAPACITY_SAT, CHANNEL_PUSH_SAT)

    bitcoind.mine(6)
    _wait_for_channels_active(lnd_payer, expected=2)
    _wait_for_channels_active(lnd_mint, expected=2)


def _wait_for_peer(node: LndHandle, peer_pubkey: str, timeout_s: float = 15.0) -> None:
    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        if any(p["pub_key"] == peer_pubkey for p in node.list_peers()):
            return
        time.sleep(0.3)
    raise TimeoutError(f"LND {node.name} did not register peer {peer_pubkey[:16]}")


def _wait_for_channels_active(node: LndHandle, expected: int, timeout_s: float = 60.0) -> None:
    deadline = time.monotonic() + timeout_s
    while time.monotonic() < deadline:
        chans = node.list_channels()
        active = [c for c in chans if c.get("active")]
        if len(active) >= expected:
            return
        time.sleep(0.5)
    raise TimeoutError(f"LND {node.name} only has {len(active)}/{expected} active channels after {timeout_s}s")
