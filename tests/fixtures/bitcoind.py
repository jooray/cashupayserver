"""bitcoind regtest fixture.

Spawns a single bitcoind in regtest mode on free ports, exposes RPC + ZMQ.
Session-scoped: one daemon for the whole pytest run, isolated under
tests/.tmp/<session_id>/bitcoind/.
"""
from __future__ import annotations

import json
import shutil
import signal
import subprocess
import time
import urllib.error
import urllib.request
from base64 import b64encode
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from . import binaries, ports

RPC_USER = "regtest"
RPC_PASSWORD = "regtest"


@dataclass
class BitcoindHandle:
    process: subprocess.Popen[bytes]
    datadir: Path
    rpc_port: int
    p2p_port: int
    zmq_block_port: int
    zmq_tx_port: int
    bitcoind_exe: Path
    bitcoin_cli_exe: Path
    miner_address: str | None = None

    @property
    def rpc_url(self) -> str:
        return f"http://{RPC_USER}:{RPC_PASSWORD}@127.0.0.1:{self.rpc_port}"

    @property
    def zmq_block_url(self) -> str:
        return f"tcp://127.0.0.1:{self.zmq_block_port}"

    def rpc(self, method: str, *params: Any) -> Any:
        body = json.dumps(
            {"jsonrpc": "1.0", "id": "tests", "method": method, "params": list(params)}
        ).encode()
        auth = b64encode(f"{RPC_USER}:{RPC_PASSWORD}".encode()).decode()
        req = urllib.request.Request(
            f"http://127.0.0.1:{self.rpc_port}/",
            data=body,
            headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                payload = json.loads(resp.read())
        except urllib.error.HTTPError as e:
            payload = json.loads(e.read())
        if payload.get("error"):
            raise RuntimeError(f"bitcoind RPC error for {method}: {payload['error']}")
        return payload["result"]

    def wait_ready(self, timeout_s: float = 30.0) -> None:
        deadline = time.monotonic() + timeout_s
        while time.monotonic() < deadline:
            try:
                self.rpc("getblockchaininfo")
                return
            except Exception:
                time.sleep(0.2)
        raise TimeoutError("bitcoind did not become ready")

    def ensure_wallet(self, name: str = "miner") -> None:
        try:
            self.rpc("loadwallet", name)
            return
        except RuntimeError as e:
            msg = str(e).lower()
            if "already loaded" in msg:
                return
            if "not found" in msg or "does not exist" in msg:
                # Fall through to createwallet
                pass
            else:
                raise
        # createwallet(wallet_name, disable_private_keys, blank, passphrase, avoid_reuse, descriptors, load_on_startup)
        self.rpc("createwallet", name, False, False, "", False, True, True)

    def new_address(self) -> str:
        return self.rpc("getnewaddress")

    def generate_to_address(self, n: int, address: str) -> list[str]:
        return self.rpc("generatetoaddress", n, address)

    def mine(self, n: int = 1) -> list[str]:
        if self.miner_address is None:
            self.miner_address = self.new_address()
        return self.generate_to_address(n, self.miner_address)

    def send_to_address(self, address: str, amount_btc: float) -> str:
        return self.rpc("sendtoaddress", address, amount_btc)

    def block_count(self) -> int:
        return self.rpc("getblockcount")


def start_bitcoind(workdir: Path) -> BitcoindHandle:
    exes = binaries.ensure(binaries.BITCOIND)
    bitcoind_exe = exes["bitcoind"]
    bitcoin_cli_exe = exes["bitcoin-cli"]

    datadir = workdir / "bitcoind"
    datadir.mkdir(parents=True, exist_ok=True)

    rpc_port, p2p_port, zmq_block_port, zmq_tx_port = ports.allocate(4)

    conf = datadir / "bitcoin.conf"
    conf.write_text(
        "\n".join(
            [
                "regtest=1",
                "fallbackfee=0.0001",
                "server=1",
                "txindex=1",
                "rpcallowip=127.0.0.1",
                f"rpcuser={RPC_USER}",
                f"rpcpassword={RPC_PASSWORD}",
                "[regtest]",
                f"rpcbind=127.0.0.1:{rpc_port}",
                f"port={p2p_port}",
                f"zmqpubrawblock=tcp://127.0.0.1:{zmq_block_port}",
                f"zmqpubrawtx=tcp://127.0.0.1:{zmq_tx_port}",
                "",
            ]
        )
    )

    log = (datadir / "bitcoind.log").open("ab")
    proc = subprocess.Popen(
        [
            str(bitcoind_exe),
            f"-datadir={datadir}",
            "-printtoconsole=0",
        ],
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = BitcoindHandle(
        process=proc,
        datadir=datadir,
        rpc_port=rpc_port,
        p2p_port=p2p_port,
        zmq_block_port=zmq_block_port,
        zmq_tx_port=zmq_tx_port,
        bitcoind_exe=bitcoind_exe,
        bitcoin_cli_exe=bitcoin_cli_exe,
    )
    try:
        handle.wait_ready()
        handle.ensure_wallet()
        handle.miner_address = handle.new_address()
    except Exception:
        stop_bitcoind(handle)
        raise
    return handle


def stop_bitcoind(handle: BitcoindHandle) -> None:
    if handle.process.poll() is None:
        try:
            handle.rpc("stop")
        except Exception:
            handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()
