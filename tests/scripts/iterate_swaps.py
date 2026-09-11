#!/usr/bin/env python3
"""Unified iterate.py-flavored dev driver with submarine swaps enabled.

Spins up the full stack pointed at ONE bitcoind (Boltz's regtest container):

  - Boltz regtest Docker stack (bitcoind + lnd-1/2/3 + cln-1/2 + backend)
  - LND-mint + LND-payer (host-side, pointed at Boltz's bitcoind via the
    container's host-exposed RPC + ZMQ ports)
  - Nutshell cashu mint backed by LND-mint
  - Fulcrum (Electrum protocol) -> Boltz bitcoind
  - Electrum daemon (host) -> Fulcrum; fresh wallet provides the merchant
    xpub AND the customer-side LN payer for swap invoices
  - cashupayserver with four stores configured (all sharing one vpub):
        oneconf       : min_confs=1, cashu mint + onchain, NO swaps
        zeroconf      : min_confs=0, cashu mint + onchain, NO swaps
        oneconf_swap  : min_confs=1, swaps enabled (Boltz, strict — no
                        mint fallback). Mint URL is still set on the row
                        so an operator can flip off strict mode in admin
                        to enable hybrid behavior.
        zeroconf_swap : same as oneconf_swap with min_confs=0
  - Lightning channels:
      payer  <-> mint    (so a cashu deposit can be funded from payer)
      Electrum <-> cln-2 (Boltz's BOLT11 receiver; direct so swaps work
                          without depending on regtest LN gossip)
  - Per-store demo invoices are created + paid so all four rails show
    activity in the admin dashboard immediately
  - Electrum GUI is left running so you can drive more invoices yourself

Run from the repo root:
    python3 tests/scripts/iterate_swaps.py

Halts on Enter (TTY) or SIGTERM (when backgrounded).
"""
from __future__ import annotations

import hashlib
import json
import os
import signal
import subprocess
import sys
import time
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
TESTS_DIR = REPO_ROOT / "tests"
if str(TESTS_DIR) not in sys.path:
    sys.path.insert(0, str(TESTS_DIR))

from fixtures import backend  # noqa: E402
from fixtures.boltz_regtest import (  # noqa: E402
    BoltzRegtestHandle,
    _check_docker_available,
    start_boltz_regtest,
    stop_boltz_regtest,
    HOST_PORT_BITCOIND_RPC,
    HOST_PORT_BITCOIND_ZMQ_TX,
    HOST_PORT_BITCOIND_ZMQ_BLOCK,
)
from fixtures.lnd import (  # noqa: E402
    LndHandle,
    open_dual_channels,
    start_lnd,
    stop_lnd,
)
from fixtures.nutshell import MintHandle, start_mint, stop_mint  # noqa: E402
from fixtures import swap_stack  # noqa: E402
from fixtures.swap_stack import (  # noqa: E402
    create_swap_invoice,
    drive_swap_to_terminal,
    fetch_invoice_row,
    fetch_swap_row,
    fund_electrum,
    open_channel_to_boltz_receiver,
    pay_bolt11,
    setup_payserver,
    start_electrum,
    start_fulcrum,
    stop_electrum,
    stop_fulcrum,
    stop_payserver,
    wait_for_lightning_route,
    electrum_bin,
)


def echo(msg: str) -> None:
    print(f"[iterate-swaps] {msg}", flush=True)


# ---------- BitcoindHandle shim around Boltz's bitcoind ----------

@dataclass
class BoltzBitcoindShim:
    """Looks enough like fixtures/bitcoind.py:BitcoindHandle for the LND +
    channel-bringup fixtures to use it.

    Talks to Boltz's bitcoind via the host-exposed RPC port + cookie auth.
    The cookie is cached on first access; if bitcoind restarts mid-session
    we won't auto-refresh (acceptable for a single-shot interactive run).
    """
    boltz: BoltzRegtestHandle
    rpc_port: int = HOST_PORT_BITCOIND_RPC
    zmq_block_port: int = HOST_PORT_BITCOIND_ZMQ_BLOCK
    zmq_tx_port: int = HOST_PORT_BITCOIND_ZMQ_TX
    p2p_port: int = 0  # not used by LND fixture
    miner_address: str | None = None
    default_wallet: str = "miner"
    _cookie_user: str = ""
    _cookie_pass: str = ""

    def __post_init__(self):
        cookie = self.boltz.bitcoind_cookie()
        self._cookie_user, _, self._cookie_pass = cookie.partition(":")

    # Boltz bitcoind has two wallets loaded ('regtest' = mining wallet,
    # 'client' = boltz client wallet). Bitcoin Core requires per-wallet RPC
    # URL paths when multiple wallets are loaded, so we route all wallet RPCs
    # to the 'regtest' wallet. Non-wallet RPCs (getblockcount, generatetoaddress)
    # work on the global endpoint.
    WALLET_NAME = "regtest"
    WALLET_RPCS = {"getnewaddress", "sendtoaddress", "getbalance", "listunspent",
                   "getwalletinfo", "createwallet", "loadwallet", "unloadwallet"}

    def rpc(self, method: str, *params: Any) -> Any:
        import urllib.request
        from base64 import b64encode
        body = json.dumps({"jsonrpc": "1.0", "id": "shim", "method": method,
                           "params": list(params)}).encode()
        auth = b64encode(f"{self._cookie_user}:{self._cookie_pass}".encode()).decode()
        wallet_path = f"/wallet/{self.WALLET_NAME}" if method in self.WALLET_RPCS else "/"
        req = urllib.request.Request(
            f"http://127.0.0.1:{self.rpc_port}{wallet_path}",
            data=body,
            headers={"Authorization": f"Basic {auth}", "Content-Type": "text/plain"},
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read())
        if data.get("error"):
            raise RuntimeError(f"bitcoind RPC {method}: {data['error']}")
        return data.get("result")

    def new_address(self) -> str:
        return self.rpc("getnewaddress")

    def mine(self, n: int = 1) -> list[str]:
        if self.miner_address is None:
            self.miner_address = self.new_address()
        return self.rpc("generatetoaddress", n, self.miner_address)

    def send_to_address(self, address: str, amount_btc: float) -> str:
        return self.rpc("sendtoaddress", address, amount_btc)

    def block_count(self) -> int:
        return self.rpc("getblockcount")


# ---------- cashupayserver multi-store setup ----------

# Per-store configuration. Each entry yields one store row.
STORE_CONFIGS = [
    {
        "key": "oneconf",       "min_confs": 1, "swaps_force_on": False,
        "name": "iterate-oneconf",
    },
    {
        "key": "zeroconf",      "min_confs": 0, "swaps_force_on": False,
        "name": "iterate-zeroconf",
    },
    {
        "key": "oneconf_swap",  "min_confs": 1, "swaps_force_on": True,
        "name": "iterate-oneconf-swap",
    },
    {
        "key": "zeroconf_swap", "min_confs": 0, "swaps_force_on": True,
        "name": "iterate-zeroconf-swap",
    },
]


@dataclass
class StoreInfo:
    key: str               # short tag from STORE_CONFIGS
    store_id: str          # full store_xxx id
    api_token: str         # public API token (curl-friendly)
    min_confs: int
    swaps_force_on: bool   # if True: this is a swap-rail store


@dataclass
class MultiStorePayserver:
    proc: subprocess.Popen
    port: int
    data_dir: Path
    stores: dict[str, StoreInfo]   # key (e.g. "oneconf_swap") -> StoreInfo

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"


def setup_payserver_with_stores(workdir: Path, vpub: str, mint_url: str,
                                  boltz_api_url: str) -> MultiStorePayserver:
    """Set up cashupayserver with the four-store layout described in the
    module docstring. All stores share the given vpub; swap stores have
    stores.swaps_enabled set to FORCE_ON (1), non-swap stores set to
    FORCE_OFF (0). Swap settings are store-only now, so every store carries
    its own boltz-only provider order and strict-no-mint-fallback flag
    (strict on, so the swap stores can't quietly degrade to mint).

    Uses direct DB seeding rather than walking the Playwright wizard.
    """
    data_dir = workdir / "payserver-data"
    data_dir.mkdir(parents=True, exist_ok=True)
    swap_stack._php_eval(data_dir, "Database::initialize(); echo 'ok';")

    import sqlite3
    db = data_dir / "cashupay.sqlite"
    now = int(time.time())
    admin_pw_hash = swap_stack._php_eval(
        data_dir, "echo password_hash('password', PASSWORD_BCRYPT);"
    ).strip()

    # Each store gets its own fresh BIP39 seed (cashu wallet) + own API token.
    stores: dict[str, StoreInfo] = {}
    store_inserts: list[tuple] = []
    api_key_inserts: list[tuple] = []
    for cfg in STORE_CONFIGS:
        sid = f"store_{cfg['key']}_{uuid.uuid4().hex[:8]}"
        token_public = "dev-" + uuid.uuid4().hex[:20]
        token_internal = "internal-" + uuid.uuid4().hex[:24]
        seed_phrase = swap_stack._php_eval(
            data_dir, "echo \\Cashu\\Mnemonic::generate();"
        ).strip()
        # Swap-rail stores get swaps_enabled=1; non-swap = 0. Provider order
        # + strict flag are per-store columns now (boltz only; strict on so
        # a swap store errors rather than quietly falling back to the mint).
        swaps_enabled_flag = 1 if cfg["swaps_force_on"] else 0
        store_inserts.append((
            sid, cfg["name"], mint_url, seed_phrase, now, vpub,
            cfg["min_confs"], swaps_enabled_flag, json.dumps(["boltz"]), 1,
            token_internal,
        ))
        api_key_inserts.append(("key_" + uuid.uuid4().hex[:12],
                                hashlib.sha256(token_public.encode()).hexdigest(),
                                sid, "dev"))
        api_key_inserts.append(("key_" + uuid.uuid4().hex[:12],
                                hashlib.sha256(token_internal.encode()).hexdigest(),
                                sid, "internal"))
        stores[cfg["key"]] = StoreInfo(
            key=cfg["key"], store_id=sid, api_token=token_public,
            min_confs=cfg["min_confs"], swaps_force_on=cfg["swaps_force_on"],
        )

    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        kvs = [
            ("setup_complete", json.dumps(True)),
            # Backend-appropriate url_mode (fixtures.backend): 'direct' under
            # php -S, 'clean' under the container backends' front controllers.
            ("url_mode", json.dumps(backend.default_url_mode())),
            # swaps_boltz_regtest_url is still a live config key (dev/test
            # knob); the enable/provider/strict settings moved to per-store
            # columns seeded in the store rows below.
            ("swaps_boltz_regtest_url", json.dumps(boltz_api_url)),
            ("cron_key", json.dumps("dev-cron-key")),
        ]
        for k, v in kvs:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        cur.execute(
            "INSERT INTO users (id, username, password_hash, role, created_at) "
            "VALUES (?, 'admin', ?, 'admin', ?)",
            ("user_" + uuid.uuid4().hex[:12], admin_pw_hash, now),
        )
        for row in store_inserts:
            cur.execute(
                "INSERT INTO stores (id, name, mint_url, mint_unit, default_currency, "
                "seed_phrase, created_at, onchain_xpub, onchain_address_type, "
                "onchain_network, onchain_min_confs, swaps_enabled, "
                "swaps_provider_order, strict_no_mint_fallback, internal_api_key) VALUES "
                "(?, ?, ?, 'sat', 'sat', ?, ?, ?, 'P2WPKH', 'regtest', ?, ?, ?, ?, ?)",
                row,
            )
        for row in api_key_inserts:
            kid, khash, sid, label = row
            cur.execute(
                "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
                "VALUES (?, ?, ?, ?, ?, ?)",
                (kid, khash, sid, label, json.dumps(["*"]), now),
            )
        conn.commit()
    finally:
        conn.close()

    # Start the PHP server.
    wrapper = data_dir / "router_wrapper.php"
    wrapper.write_text(
        "<?php\n"
        "$d = getenv('CASHUPAY_DATA_DIR');\n"
        "if ($d !== false && $d !== '' && !defined('CASHUPAY_DATA_DIR')) {\n"
        "    define('CASHUPAY_DATA_DIR', $d);\n"
        "}\n"
        f"return require {str(REPO_ROOT / 'router.php')!r};\n"
    )
    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    # Honoured by includes/updater.php::isDisabledForTests() — keep the
    # auto-updater from clobbering the working tree mid-run.
    env.setdefault("CASHUPAY_UPDATER_DISABLED", "1")
    port = swap_stack.free_port()
    log = (data_dir / "payserver.log").open("ab")
    proc = subprocess.Popen(
        [str(swap_stack.PHP), "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT), str(wrapper)],
        cwd=str(REPO_ROOT), env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 20
    import urllib.request
    while time.monotonic() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/v1/server/info", timeout=1.0)
            return MultiStorePayserver(proc=proc, port=port, data_dir=data_dir, stores=stores)
        except Exception:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"cashupayserver did not come up on :{port}")


# ---------- Channel: payer fundedness ----------

def fund_lnd(bitcoind_shim: BoltzBitcoindShim, node: LndHandle, btc: float = 1.0) -> None:
    addr = node.new_address()
    bitcoind_shim.send_to_address(addr, btc)
    bitcoind_shim.mine(6)


def launch_electrum_gui(electrum: swap_stack.ElectrumProc) -> None:
    if electrum.process and electrum.process.poll() is None:
        try:
            swap_stack.electrum_cli(electrum.datadir, "stop", timeout=10)
        except Exception:
            pass
        try:
            electrum.process.wait(timeout=10)
        except Exception:
            try: electrum.process.kill()
            except Exception: pass
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    electrum.gui_process = subprocess.Popen(
        [str(electrum_bin()), "--regtest", "--dir", str(electrum.datadir),
         "--wallet", str(electrum.wallet_path)],
        env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )


# ---------- per-store demos ----------

def _create_invoice(payserver: MultiStorePayserver, store: StoreInfo,
                     amount_sats: int, currency: str = "sat") -> dict:
    status, body = swap_stack.http_json(
        f"{payserver.url}/api/v1/stores/{store.store_id}/invoices",
        method="POST",
        body={"amount": amount_sats, "currency": currency},
        headers={"Authorization": f"token {store.api_token}"},
    )
    if status != 200 or not isinstance(body, dict) or "id" not in body:
        raise RuntimeError(f"[{store.key}] create invoice failed: status={status} body={body}")
    return body


def _fetch_invoice(payserver: MultiStorePayserver, invoice_id: str) -> dict | None:
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def _trigger_cron(payserver: MultiStorePayserver) -> None:
    import urllib.request
    try:
        with urllib.request.urlopen(f"{payserver.url}/cron.php?key=dev-cron-key", timeout=10) as r:
            r.read()
    except Exception:
        pass


def demo_ln_via_mint(payserver: MultiStorePayserver, store: StoreInfo,
                      lnd_payer: LndHandle, amount_sats: int = 1500) -> dict:
    """Non-swap store: create an invoice, pay BOLT11 from lnd_payer, settle
    via the mint quote. Used for the oneconf/zeroconf stores."""
    inv = _create_invoice(payserver, store, amount_sats)
    bolt11 = inv["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=60)
    for _ in range(20):
        _trigger_cron(payserver)
        row = _fetch_invoice(payserver, inv["id"])
        if row and row["status"] == "Settled":
            return row
        time.sleep(1)
    return _fetch_invoice(payserver, inv["id"]) or {}


def demo_onchain(payserver: MultiStorePayserver, store: StoreInfo,
                  bitcoind: "BoltzBitcoindShim", amount_sats: int = 25_000) -> dict:
    """Non-swap store: create invoice, send BTC to the allocated on-chain
    address from bitcoind, drive cron until Settled (zeroconf: 0 conf,
    oneconf: 1+ confs after mining)."""
    inv = _create_invoice(payserver, store, amount_sats)
    methods = inv["checkout"]["paymentMethods"]
    onchain = methods.get("BTC-OnChain") or methods.get("BTC-OnChain")
    if onchain is None:
        raise RuntimeError(f"[{store.key}] invoice has no on-chain payment method")
    address = onchain.get("destination") or onchain.get("address")
    if not address:
        raise RuntimeError(f"[{store.key}] missing onchain address in payment method: {onchain}")
    btc = amount_sats / 100_000_000
    bitcoind.send_to_address(address, btc)
    # oneconf needs 1 conf; zeroconf needs 0. Mine a block either way.
    if store.min_confs >= 1:
        bitcoind.mine(store.min_confs + 1)
    for _ in range(20):
        _trigger_cron(payserver)
        row = _fetch_invoice(payserver, inv["id"])
        if row and row["status"] == "Settled":
            return row
        time.sleep(1)
    return _fetch_invoice(payserver, inv["id"]) or {}


def demo_swap(payserver: MultiStorePayserver, store: StoreInfo,
                electrum: swap_stack.ElectrumProc,
                boltz: BoltzRegtestHandle,
                amount_sats: int = 70_000) -> dict:
    """Swap-rail store: create invoice (swap), pay via Electrum lnpay,
    poll until on-chain claim settles the invoice. Returns the final
    swap_attempts row (richer than the invoice row)."""
    inv = _create_invoice(payserver, store, amount_sats)
    bolt11 = inv["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    import threading
    pay_result: dict = {}
    def _pay():
        try:
            pay_result.update(pay_bolt11(electrum, bolt11, timeout=180))
        except Exception as e:
            pay_result["error"] = str(e)
    t = threading.Thread(target=_pay, daemon=True)
    t.start()

    # Re-implement drive_swap_to_terminal with multi-store payserver shape.
    terminal = {"invoice.settled", "swap.expired", "transaction.refunded",
                "transaction.failed", "invoice.expired", "claim.confirmed", "error"}
    deadline = time.monotonic() + 180
    tick = 0
    while time.monotonic() < deadline:
        _trigger_cron(payserver)
        tick += 1
        if tick % 3 == 0:
            boltz.mine_blocks(1)
        # Look at swap_attempts directly for the most informative status.
        import sqlite3
        conn = sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite"))
        try:
            conn.row_factory = sqlite3.Row
            row = conn.execute("SELECT * FROM swap_attempts WHERE invoice_id = ?",
                                (inv["id"],)).fetchone()
            row = dict(row) if row else None
        finally:
            conn.close()
        if row and row["status"] in terminal:
            t.join(timeout=5)
            return row
        time.sleep(2)
    t.join(timeout=5)
    raise TimeoutError(f"[{store.key}] swap {inv['id']} did not reach terminal state")


# ---------- main ----------

def main() -> int:
    skip = _check_docker_available()
    if skip:
        echo(f"FATAL: {skip}")
        return 1

    workdir = Path("/tmp") / f"iterate-swaps-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir()
    echo(f"workdir = {workdir}")

    boltz = None
    bitcoind_shim = None
    lnd_payer = None
    lnd_mint = None
    mint = None
    fulcrum = None
    electrum = None
    payserver = None

    try:
        echo("starting Boltz regtest stack (~60-90s)...")
        boltz = start_boltz_regtest()
        echo(f"Boltz API: {boltz.api_url}")

        echo("wrapping Boltz bitcoind as a BitcoindHandle shim ...")
        bitcoind_shim = BoltzBitcoindShim(boltz=boltz)
        # Ensure coinbase maturity for spendable balance.
        height = bitcoind_shim.block_count()
        if height < 101:
            echo(f"mining {101 - height} blocks for coinbase maturity ...")
            bitcoind_shim.mine(101 - height)

        echo("starting LND mint + payer (host-side, pointed at Boltz bitcoind via cookie auth) ...")
        cookie = boltz.bitcoind_cookie()
        user, _, password = cookie.partition(":")
        lnd_mint = start_lnd(workdir, "mint", bitcoind_shim, rpc_user=user, rpc_pass=password)
        lnd_payer = start_lnd(workdir, "payer", bitcoind_shim, rpc_user=user, rpc_pass=password)

        echo("opening dual 10M-sat channels payer<->mint (5M push each way) ...")
        open_dual_channels(bitcoind_shim, lnd_payer, lnd_mint)

        echo("starting nutshell cashu mint backed by lnd_mint ...")
        mint = start_mint(workdir, lnd_mint)
        echo(f"mint URL: {mint.url}")

        echo("starting Fulcrum + Electrum (regtest, pointed at Boltz bitcoind) ...")
        fulcrum = start_fulcrum(workdir, boltz)
        electrum = start_electrum(workdir, fulcrum.port)
        echo(f"Electrum vpub = {electrum.vpub}")

        echo("funding Electrum with 5,000,000 sats (so the swap-claim destination has visible UTXOs) ...")
        fund_electrum(electrum, boltz, 5_000_000)

        echo("opening 300,000-sat Lightning channel Electrum -> cln-2 (Boltz BOLT11 receiver) ...")
        open_channel_to_boltz_receiver(electrum, boltz, capacity_sat=300_000)
        wait_for_lightning_route(electrum, boltz.cln2_pubkey, timeout=10)

        echo("starting cashupayserver with four stores + swap config + Electrum vpub ...")
        payserver = setup_payserver_with_stores(workdir, electrum.vpub, mint.url, boltz.api_url)
        echo(f"payserver: {payserver.url}")

        # Per-store demos. Track results for the banner. Each demo is wrapped
        # so a single failure doesn't crash the whole script — the user can
        # always drive more invoices manually from the admin once we halt.
        demo_results: dict[str, dict] = {}

        # Non-swap stores: LN (via mint) + on-chain
        for key in ("oneconf", "zeroconf"):
            store = payserver.stores[key]
            echo(f"[{key}] LN demo (1,500 sat via cashu mint, paid by lnd_payer) ...")
            try:
                row = demo_ln_via_mint(payserver, store, lnd_payer, amount_sats=1500)
                demo_results.setdefault(key, {})["ln"] = row.get("id"), row.get("status")
            except Exception as e:
                echo(f"  [{key}] LN demo failed: {e}")
                demo_results.setdefault(key, {})["ln"] = None, f"err:{e}"

            echo(f"[{key}] on-chain demo (25,000 sat sent to xpub address) ...")
            try:
                row = demo_onchain(payserver, store, bitcoind_shim, amount_sats=25_000)
                demo_results.setdefault(key, {})["onchain"] = row.get("id"), row.get("status")
            except Exception as e:
                echo(f"  [{key}] on-chain demo failed: {e}")
                demo_results.setdefault(key, {})["onchain"] = None, f"err:{e}"

        # Swap stores: swap-rail (Electrum -> Boltz -> on-chain claim)
        for key in ("oneconf_swap", "zeroconf_swap"):
            store = payserver.stores[key]
            echo(f"[{key}] swap demo (70,000 sat, Electrum lnpay -> Boltz -> on-chain claim) ...")
            try:
                row = demo_swap(payserver, store, electrum, boltz, amount_sats=70_000)
                demo_results.setdefault(key, {})["swap"] = row.get("id"), row.get("status"), row.get("claim_txid")
            except Exception as e:
                echo(f"  [{key}] swap demo failed: {e}")
                demo_results.setdefault(key, {})["swap"] = None, f"err:{e}", None

        echo("launching Electrum GUI ...")
        launch_electrum_gui(electrum)
        boltz.mine_blocks(2)

        print()
        print("=" * 72)
        print("Unified iterate-swaps stack ready")
        print("=" * 72)
        print(f"Bitcoin RPC (Boltz):   127.0.0.1:{HOST_PORT_BITCOIND_RPC}  (shared chain)")
        print(f"Boltz API:             {boltz.api_url}")
        print(f"Boltz web app:         http://localhost:8080")
        print(f"Mint URL:              {mint.url}")
        print(f"Fulcrum:               127.0.0.1:{fulcrum.port}")
        print(f"Electrum vpub:         {electrum.vpub}")
        print(f"Payserver:             {payserver.url}")
        print(f"Payserver admin:       {payserver.url}/admin  (user `admin`, password `password`)")
        print(f"LND-mint REST:         {lnd_mint.rest_url}")
        print(f"LND-payer REST:        {lnd_payer.rest_url}")
        print(f"Workdir:               {workdir}")
        print()
        print("Stores:")
        for cfg in STORE_CONFIGS:
            store = payserver.stores[cfg["key"]]
            rail = "swap (strict)" if cfg["swaps_force_on"] else "cashu mint + onchain"
            print(f"  [{cfg['key']:<14}] {store.store_id}  min_confs={cfg['min_confs']}  rail={rail}")
            print(f"  {'':<16}    API token: {store.api_token}")

        print()
        print("Demo invoices ran on startup:")
        for key in ("oneconf", "zeroconf"):
            r = demo_results.get(key, {})
            ln = r.get("ln") or (None, None)
            oc = r.get("onchain") or (None, None)
            print(f"  [{key}] LN        : {ln[0]}  -> {ln[1]}")
            print(f"  [{key}] On-chain  : {oc[0]}  -> {oc[1]}")
        for key in ("oneconf_swap", "zeroconf_swap"):
            r = demo_results.get(key, {})
            sw = r.get("swap") or (None, None, None)
            extra = f"  claim_txid={sw[2]}" if sw[2] else ""
            print(f"  [{key}] Swap     : {sw[0]}  -> {sw[1]}{extra}")

        print()
        print("Ctrl-C / SIGTERM to clean everything up (Boltz stack included).")
        print()

        if sys.stdin.isatty():
            try:
                input("Press Enter to clean up and exit ... ")
            except (EOFError, KeyboardInterrupt):
                pass
        else:
            done = [False]
            def _handler(*_):
                done[0] = True
            signal.signal(signal.SIGTERM, _handler)
            signal.signal(signal.SIGINT, _handler)
            while not done[0]:
                signal.pause()

    finally:
        echo("cleaning up ...")
        if payserver is not None:
            try:
                if payserver.proc.poll() is None:
                    payserver.proc.terminate()
                    payserver.proc.wait(timeout=5)
            except Exception:
                try: payserver.proc.kill()
                except Exception: pass
        if electrum is not None:
            stop_electrum(electrum)
        if fulcrum is not None:
            stop_fulcrum(fulcrum)
        if mint is not None:
            stop_mint(mint)
        if lnd_mint is not None:
            stop_lnd(lnd_mint)
        if lnd_payer is not None:
            stop_lnd(lnd_payer)
        if boltz is not None:
            echo("tearing down Boltz regtest stack ...")
            stop_boltz_regtest(boltz)
    return 0


if __name__ == "__main__":
    sys.exit(main())
