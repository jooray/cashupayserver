"""Shared bring-up helpers for submarine-swap testing/dev.

Used by both the pytest e2e (`tests/e2e/test_submarine_swap_via_electrum.py`)
and the interactive dev driver (`tests/scripts/swap_dev.py`) so the two
can't drift.

Topology (single bitcoind, Electrum acts as customer-LN AND merchant-xpub):

      ┌─────────────────────────────────────┐
      │ Boltz regtest stack (Docker)        │
      │  bitcoind  <─ LND-1/2/3  <─ Boltz   │
      │     ▲           ▲                   │
      │     │           │                   │
      └─────┼───────────┼───────────────────┘
            │ RPC :28443│ P2P :29735
            │           │
   ┌────────┼───────────┼──────────────────┐
   │ Host:  │           │                  │
   │  Fulcrum (Electrum proto) ── Electrum │
   │     ▲                       (vpub +   │
   │     │                       LN funds) │
   │     │                                 │
   │  cashupayserver (php -S)              │
   │     └─── /v2/swap/reverse → Boltz API │
   └───────────────────────────────────────┘
"""
from __future__ import annotations

import hashlib
import json
import os
import secrets
import socket
import subprocess
import sys
import time
import uuid
from base64 import b64encode
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import urllib.request
import urllib.error

from . import backend, binaries
from .boltz_regtest import BoltzRegtestHandle

REPO_ROOT = Path(__file__).resolve().parent.parent.parent


def _php_bin() -> Path:
    """Resolve the static PHP binary, downloading it via the binary manager if
    it is not present yet. The swap-stack fixtures do not pull the
    `installed_binaries` fixture the rest of the suite relies on to fetch PHP,
    so a hardcoded path could be missing on a fresh CI checkout — which surfaced
    as FileNotFoundError deep in payserver setup."""
    return binaries.ensure(binaries.PHP)["php"]


def fulcrum_bin() -> Path:
    """Resolve Fulcrum via the binary manager for the same reason as _php_bin:
    a hardcoded tests/bin path is missing on a fresh CI checkout."""
    return binaries.ensure(binaries.FULCRUM)["Fulcrum"]


def electrum_bin() -> Path:
    """Resolve the Electrum AppImage via the binary manager (see _php_bin)."""
    return binaries.ensure_file(binaries.ELECTRUM)


def free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


# ---------- Fulcrum ----------

@dataclass
class FulcrumProc:
    process: subprocess.Popen
    port: int
    datadir: Path


def start_fulcrum(workdir: Path, boltz: BoltzRegtestHandle) -> FulcrumProc:
    datadir = workdir / "fulcrum"
    datadir.mkdir(parents=True, exist_ok=True)
    cookie = boltz.bitcoind_cookie()
    user, _, password = cookie.partition(":")
    tcp_port = free_port()
    conf = datadir / "fulcrum.conf"
    conf.write_text("\n".join([
        f"datadir = {datadir}",
        f"bitcoind = {boltz.bitcoind_rpc_host}:{boltz.bitcoind_rpc_port}",
        f"rpcuser = {user}",
        f"rpcpassword = {password}",
        f"tcp = 127.0.0.1:{tcp_port}",
        "debug = false",
        "polltime = 1",
        "",
    ]))
    log = (datadir / "fulcrum.log").open("ab")
    proc = subprocess.Popen([str(fulcrum_bin()), str(conf)], stdout=log, stderr=subprocess.STDOUT)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", tcp_port), timeout=1.0):
                return FulcrumProc(process=proc, port=tcp_port, datadir=datadir)
        except OSError:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"Fulcrum did not come up on :{tcp_port}")


def stop_fulcrum(fulcrum: FulcrumProc) -> None:
    if fulcrum.process.poll() is None:
        fulcrum.process.terminate()
        try:
            fulcrum.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            fulcrum.process.kill()


# ---------- Electrum ----------

@dataclass
class ElectrumProc:
    process: subprocess.Popen | None
    datadir: Path
    wallet_path: Path
    rpc_port: int
    rpc_user: str
    rpc_password: str
    lightning_listen_port: int
    vpub: str = ""
    gui_process: subprocess.Popen | None = None


def electrum_cli(datadir: Path, *args: str, timeout: float = 30.0) -> str:
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    res = subprocess.run(
        [str(electrum_bin()), "--regtest", "--dir", str(datadir), *args],
        capture_output=True, text=True, env=env, timeout=timeout,
    )
    if res.returncode != 0:
        raise RuntimeError(f"electrum {args}: {res.stderr.strip()}\n{res.stdout.strip()}")
    return res.stdout.strip()


def electrum_cli_wallet(datadir: Path, wallet: Path, *args: str, timeout: float = 30.0) -> str:
    """CLI in the context of a specific wallet (for wallet-scoped commands like lnpay, list_channels)."""
    return electrum_cli(datadir, *args, "-w", str(wallet), timeout=timeout)


def electrum_rpc(rpc_port: int, user: str, password: str, method: str, params: list | dict | None = None) -> dict:
    body = json.dumps({"id": 0, "method": method, "params": params or []}).encode()
    auth = b64encode(f"{user}:{password}".encode()).decode()
    req = urllib.request.Request(
        f"http://127.0.0.1:{rpc_port}/",
        data=body,
        headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=20) as resp:
        return json.loads(resp.read().decode())


def start_electrum(workdir: Path, fulcrum_port: int) -> ElectrumProc:
    datadir = workdir / "electrum"
    datadir.mkdir(parents=True, exist_ok=True)
    rpc_user = "electrum"
    rpc_password = secrets.token_hex(16)
    rpc_port = free_port()
    lightning_listen_port = free_port()

    for k, v in [
        ("rpcuser", rpc_user),
        ("rpcpassword", rpc_password),
        ("rpcport", str(rpc_port)),
        ("server", f"127.0.0.1:{fulcrum_port}:t"),
        ("oneserver", "true"),
        ("auto_connect", "false"),
        ("use_gossip", "true"),
        ("lightning_listen", f"127.0.0.1:{lightning_listen_port}"),
        ("decimal_point", "0"),
        ("dont_show_testnet_warning", "true"),
        ("check_updates", "false"),
    ]:
        electrum_cli(datadir, "--offline", "setconfig", k, v)

    electrum_cli(datadir, "--offline", "create")

    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    log = (datadir / "daemon.log").open("ab")
    proc = subprocess.Popen(
        [str(electrum_bin()), "--regtest", "--dir", str(datadir), "daemon", "-v"],
        env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            electrum_rpc(rpc_port, rpc_user, rpc_password, "version")
            break
        except Exception:
            time.sleep(0.4)
    else:
        proc.kill()
        raise TimeoutError("Electrum daemon did not start")

    wallet_path = datadir / "regtest" / "wallets" / "default_wallet"
    electrum_rpc(rpc_port, rpc_user, rpc_password, "load_wallet", {"wallet_path": str(wallet_path)})

    # vpub from CLI (RPC variant of getmpk is wallet-scope-sensitive).
    vpub = electrum_cli_wallet(datadir, wallet_path, "getmpk").strip()

    return ElectrumProc(
        process=proc,
        datadir=datadir,
        wallet_path=wallet_path,
        rpc_port=rpc_port,
        rpc_user=rpc_user,
        rpc_password=rpc_password,
        lightning_listen_port=lightning_listen_port,
        vpub=vpub,
    )


def stop_electrum(electrum: ElectrumProc) -> None:
    # Stop GUI first if running, then daemon.
    if electrum.gui_process and electrum.gui_process.poll() is None:
        electrum.gui_process.terminate()
        try:
            electrum.gui_process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            electrum.gui_process.kill()
    if electrum.process and electrum.process.poll() is None:
        try:
            electrum_cli(electrum.datadir, "stop", timeout=10)
        except Exception:
            pass
        try:
            electrum.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            electrum.process.kill()


def fund_electrum(electrum: ElectrumProc, boltz: BoltzRegtestHandle, sats: int) -> str:
    """Fund the Electrum wallet on-chain. Returns the funding address."""
    address = electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "getunusedaddress").strip()
    if not address:
        raise RuntimeError("Electrum did not return a funding address")
    boltz.send_to_address(address, sats)
    boltz.mine_blocks(6)
    # Wait for Electrum to see the balance
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            bal = json.loads(electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "getbalance"))
            confirmed_sats = int(float(bal.get("confirmed", "0")) * 100_000_000)
            if confirmed_sats >= sats // 2:
                return address
        except Exception:
            pass
        time.sleep(1)
    raise TimeoutError(f"Electrum did not see funded balance at {address}")


def open_channel_to_boltz_receiver(electrum: ElectrumProc, boltz: BoltzRegtestHandle,
                                    capacity_sat: int = 300_000) -> str:
    """Open a Lightning channel from Electrum directly to Boltz's BOLT11
    destination (cln-2). Direct channel = 1-hop payment, no graph routing
    needed — regtest LN gossip is fragile and we can sidestep it entirely
    by being adjacent to the receiver.

    Returns the channel point (txid:vout).
    """
    pubkey = boltz.cln2_pubkey
    if not pubkey:
        raise RuntimeError("boltz handle has no cln2_pubkey")
    node_uri = f"{pubkey}@{boltz.cln2_p2p_host}:{boltz.cln2_p2p_port}"
    capacity_btc = f"{capacity_sat / 100_000_000:.8f}"
    # `open_channel` blocks until the funding tx is broadcast.
    raw = electrum_cli_wallet(electrum.datadir, electrum.wallet_path,
                              "open_channel", node_uri, capacity_btc, timeout=120)
    import re
    m = re.search(r"([0-9a-f]{64}:\d+)", raw)
    if not m:
        raise RuntimeError(f"open_channel did not yield a channel point: {raw}")
    channel_point = m.group(1)

    # Mine for funding confirmations + LN graph propagation.
    boltz.mine_blocks(6)

    # Wait until the channel is in an active/open state.
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        try:
            channels = json.loads(electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "list_channels"))
        except Exception:
            channels = []
        for c in channels:
            state = (c.get("state") or "").upper()
            if state in ("OPEN", "FUNDED", "OPENING_DONE"):
                return channel_point
        time.sleep(2)
    raise TimeoutError(f"Electrum channel to {pubkey[:16]}… did not open within 90s")


def wait_for_lightning_route(electrum: ElectrumProc, boltz_target_pubkey: str | None = None,
                              timeout: float = 60.0) -> None:
    """Brief grace period for our direct channel to be considered routable.

    With a direct channel to cln-2 (Boltz's BOLT11 receiver), no gossip is
    needed for routing — the payment is 1-hop along our own channel.
    Electrum still needs the channel's own updates internalized, which is
    near-instant. A short sleep is enough.
    """
    time.sleep(3)


def pay_bolt11(electrum: ElectrumProc, bolt11: str, timeout: int = 60) -> dict:
    """Pay a BOLT11 from the Electrum wallet via `lnpay`.

    Electrum's `lnpay` returns a JSON dict with `payment_hash` and `success`.
    """
    raw = electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "lnpay", bolt11,
                              timeout=timeout)
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw}


# ---------- cashupayserver ----------

@dataclass
class PayserverProc:
    process: subprocess.Popen
    port: int
    data_dir: Path
    store_id: str
    api_token: str

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"


def _php_eval(data_dir: Path, snippet: str) -> str:
    code = (
        f"define('CASHUPAY_DATA_DIR', {str(data_dir)!r});\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'database.php')!r};\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'config.php')!r};\n"
        + snippet
    )
    res = subprocess.run([str(_php_bin()), "-r", code], capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"php failed: {res.stderr}\n{res.stdout}")
    return res.stdout.strip()


def setup_payserver(workdir: Path, vpub: str, boltz_api_url: str,
                    strict_no_mint_fallback: bool = True,
                    admin_password: str = "password") -> PayserverProc:
    """Initialize DB, seed setup-complete + one store using the given vpub
    (swaps enabled per-store: swaps live entirely on the stores table now),
    create an admin user (admin/<admin_password>) + a public API token + the
    store's internal-API-key (used by the admin UI). Start php -S.
    Returns a handle."""
    data_dir = workdir / "payserver-data"
    data_dir.mkdir(parents=True, exist_ok=True)
    _php_eval(data_dir, "Database::initialize(); echo 'ok';")

    import sqlite3
    db = data_dir / "cashupay.sqlite"
    now = int(time.time())
    store_id = f"store_{uuid.uuid4().hex[:12]}"
    api_token = "dev-" + uuid.uuid4().hex[:20]
    api_token_hash = hashlib.sha256(api_token.encode()).hexdigest()

    # The admin UI's invoice form reads stores.internal_api_key, which is
    # created on demand by Auth::getOrCreateInternalApiKey() the first time
    # an authed admin loads the store row. Seed it directly so the UI works
    # without requiring an admin browser load first.
    internal_api_token = "internal-" + uuid.uuid4().hex[:24]
    internal_api_hash = hashlib.sha256(internal_api_token.encode()).hexdigest()

    # Admin user password hash. The PHP layer uses password_hash(PASSWORD_BCRYPT)
    # — we generate that hash here via php -r so we don't have to ship a PHP
    # bcrypt implementation.
    admin_pw_hash = _php_eval(
        data_dir,
        f"echo password_hash({admin_password!r}, PASSWORD_BCRYPT);",
    ).strip()

    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        kvs = [
            ("setup_complete", json.dumps(True)),
            # We skip the wizard, so pin the url_mode its probe would have
            # detected on the active backend (see fixtures.backend): 'direct'
            # under php -S — where /router.php is a real file that bypasses
            # the router wrapper and its CASHUPAY_DATA_DIR define — and
            # 'clean' under the real Apache/nginx front controllers.
            ("url_mode", json.dumps(backend.default_url_mode())),
            # swaps_boltz_regtest_url is still a live config key (dev/test
            # knob); the other swap settings moved to stores columns below.
            ("swaps_boltz_regtest_url", json.dumps(boltz_api_url)),
            ("cron_key", json.dumps("dev-cron-key")),
        ]
        for k, v in kvs:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        # Admin user — username 'admin', password from arg (default 'password')
        cur.execute(
            "INSERT INTO users (id, username, password_hash, role, created_at) "
            "VALUES (?, 'admin', ?, 'admin', ?)",
            ("user_" + uuid.uuid4().hex[:12], admin_pw_hash, now),
        )
        # Store + store.internal_api_key. Swap settings are per-store now:
        # swaps on, boltz-only provider order, strict flag from the arg.
        cur.execute(
            "INSERT INTO stores (id, name, mint_unit, default_currency, created_at, "
            "onchain_xpub, onchain_address_type, onchain_network, internal_api_key, "
            "swaps_enabled, swaps_provider_order, strict_no_mint_fallback) VALUES "
            "(?, 'Swap Dev Store', 'sat', 'sat', ?, ?, 'P2WPKH', 'regtest', ?, 1, ?, ?)",
            (store_id, now, vpub, internal_api_token,
             json.dumps(["boltz"]), 1 if strict_no_mint_fallback else 0),
        )
        # API keys: external dev token + the internal one referenced by the UI
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'dev', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], api_token_hash, store_id,
             json.dumps(["*"]), now),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'internal', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], internal_api_hash, store_id,
             json.dumps(["*"]), now),
        )
        conn.commit()
    finally:
        conn.close()

    # php -S with a wrapper that defines CASHUPAY_DATA_DIR
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
    # Kill the auto-updater for the whole test run — without this, a swap test
    # that runs for several minutes will eventually overlay the working tree
    # with the latest channel-main build mid-run.
    env.setdefault("CASHUPAY_UPDATER_DISABLED", "1")
    # Allow outbound HTTP to private/loopback IPs: the local stack points
    # the mint, Esplora, and Boltz at 127.0.0.1 / regtest endpoints, which
    # SafeHttp's default posture would reject.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    port = free_port()
    log = (data_dir / "payserver.log").open("ab")
    proc = subprocess.Popen(
        [str(_php_bin()), "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT), str(wrapper)],
        cwd=str(REPO_ROOT), env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/v1/server/info", timeout=1.0)
            return PayserverProc(process=proc, port=port, data_dir=data_dir,
                                  store_id=store_id, api_token=api_token)
        except Exception:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"cashupayserver did not come up on :{port}")


def stop_payserver(payserver: PayserverProc) -> None:
    if payserver.process.poll() is None:
        payserver.process.terminate()
        try:
            payserver.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            payserver.process.kill()


# ---------- API helpers ----------

def http_json(url: str, *, method: str = "GET", body: dict | None = None,
              headers: dict | None = None, timeout: float = 15.0) -> tuple[int, dict | str]:
    """Use curl as transport; PHP built-in server + urllib have some
    edge-case POST interactions we'd rather not debug."""
    cmd = ["curl", "-s", "-o", "/dev/stdout", "-w", "\n__HTTP_STATUS__:%{http_code}",
           "-X", method, "--max-time", str(int(timeout))]
    for k, v in {"Accept": "application/json", **(headers or {})}.items():
        cmd += ["-H", f"{k}: {v}"]
    if body is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(body)]
    cmd += [url]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 5)
    raw = proc.stdout
    status = 0
    body_str = raw
    if "\n__HTTP_STATUS__:" in raw:
        body_str, _, status_line = raw.rpartition("\n__HTTP_STATUS__:")
        status = int(status_line.strip())
    try:
        return status, json.loads(body_str)
    except (json.JSONDecodeError, ValueError):
        return status, body_str


def create_swap_invoice(payserver: PayserverProc, amount_sats: int,
                        currency: str = "sat") -> dict:
    status, body = http_json(
        f"{payserver.url}/api/v1/stores/{payserver.store_id}/invoices",
        method="POST",
        body={"amount": amount_sats, "currency": currency},
        headers={"Authorization": f"token {payserver.api_token}"},
    )
    if status != 200 or not isinstance(body, dict) or "id" not in body:
        raise RuntimeError(f"invoice creation failed: status={status} body={body}")
    return body


def trigger_cron(payserver: PayserverProc) -> None:
    """Fire-and-forget cron tick. Errors swallowed (it may 500 transiently)."""
    try:
        with urllib.request.urlopen(f"{payserver.url}/cron.php?key=dev-cron-key", timeout=10) as r:
            r.read()
    except Exception:
        pass


def fetch_invoice_row(payserver: PayserverProc, invoice_id: str) -> dict | None:
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def fetch_swap_row(payserver: PayserverProc, invoice_id: str) -> dict | None:
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM swap_attempts WHERE invoice_id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


# ---------- High-level convenience ----------

def drive_swap_to_terminal(payserver: PayserverProc, invoice_id: str,
                            boltz: BoltzRegtestHandle,
                            timeout: float = 180.0) -> dict:
    """Loop cron + mine until the swap reaches a terminal state. Returns the
    final swap_attempts row.

    Terminal: invoice.settled OR any of the failure statuses.
    """
    terminal = {"invoice.settled", "swap.expired", "transaction.refunded",
                "transaction.failed", "invoice.expired", "claim.confirmed", "error"}
    deadline = time.monotonic() + timeout
    tick = 0
    while time.monotonic() < deadline:
        trigger_cron(payserver)
        tick += 1
        if tick % 3 == 0:
            boltz.mine_blocks(1)
        row = fetch_swap_row(payserver, invoice_id)
        if row and row["status"] in terminal:
            return row
        time.sleep(2)
    raise TimeoutError(f"Swap {invoice_id} did not reach a terminal state within {timeout}s")


def poll_checkout(payserver: PayserverProc, invoice_id: str) -> dict:
    """Hit the customer checkout poll endpoint (payment.php?json=1) exactly as
    the checkout page's JS does. This drives swap settlement inline via
    Invoice::pollSingleQuote → SwapPoller::pollByInvoiceId. Returns the status
    JSON payload (empty dict on transport error)."""
    status, body = http_json(
        f"{payserver.url}/payment.php?id={invoice_id}&json=1", method="GET")
    return body if isinstance(body, dict) else {}


def drive_swap_to_terminal_via_checkout(payserver: PayserverProc, invoice_id: str,
                                        boltz: BoltzRegtestHandle,
                                        timeout: float = 180.0) -> dict:
    """Same as drive_swap_to_terminal, but advances the swap by polling the
    customer checkout endpoint instead of firing cron — exercising the inline
    SwapPoller::pollByInvoiceId path. Block mining still stands in for external
    block production (the checkout poll never mines); cron is never invoked.
    """
    terminal = {"invoice.settled", "swap.expired", "transaction.refunded",
                "transaction.failed", "invoice.expired", "claim.confirmed", "error"}
    deadline = time.monotonic() + timeout
    tick = 0
    while time.monotonic() < deadline:
        poll_checkout(payserver, invoice_id)
        tick += 1
        if tick % 3 == 0:
            boltz.mine_blocks(1)
        row = fetch_swap_row(payserver, invoice_id)
        if row and row["status"] in terminal:
            return row
        time.sleep(2)
    raise TimeoutError(f"Swap {invoice_id} did not reach a terminal state within {timeout}s")


# ===========================================================================
# Sweep / auto-melt helpers
# ===========================================================================
#
# The pieces below are shared between the auto-melt e2e test and
# any interactive dev driver that wants to exercise the sweep path.
# They build on top of the Boltz regtest stack: a host-side LND backs the
# cashu mint, a direct LND<->Boltz-lnd1 channel provides both inbound
# (funding the cashu wallet) and outbound (paying the sweep's BOLT11)
# liquidity.

try:
    from fixtures.boltz_regtest import (
        HOST_PORT_BITCOIND_RPC,
        HOST_PORT_BITCOIND_ZMQ_BLOCK,
        HOST_PORT_BITCOIND_ZMQ_TX,
    )
except ImportError:
    # Fallback when imported as fixtures.swap_stack rather than via the test
    # bootstrap path. Mirrors the values in fixtures/boltz_regtest.py.
    HOST_PORT_BITCOIND_RPC = 28443  # type: ignore[assignment]
    HOST_PORT_BITCOIND_ZMQ_BLOCK = 29001  # type: ignore[assignment]
    HOST_PORT_BITCOIND_ZMQ_TX = 29000  # type: ignore[assignment]


@dataclass
class BoltzBitcoindShim:
    """Looks enough like fixtures/bitcoind.py:BitcoindHandle for the LND +
    channel-bringup fixtures to use it.

    Talks to Boltz's bitcoind via the host-exposed RPC port + cookie auth.
    The cookie is cached on first access; if bitcoind restarts mid-session
    we won't auto-refresh (acceptable for a single-shot interactive run or
    a single pytest invocation).
    """
    boltz: BoltzRegtestHandle
    rpc_port: int = HOST_PORT_BITCOIND_RPC
    zmq_block_port: int = HOST_PORT_BITCOIND_ZMQ_BLOCK
    zmq_tx_port: int = HOST_PORT_BITCOIND_ZMQ_TX
    p2p_port: int = 0  # not used by the LND fixture
    miner_address: str | None = None
    default_wallet: str = "miner"
    _cookie_user: str = ""
    _cookie_pass: str = ""

    def __post_init__(self):
        cookie = self.boltz.bitcoind_cookie()
        self._cookie_user, _, self._cookie_pass = cookie.partition(":")

    # Boltz bitcoind has two wallets loaded ('regtest' = mining, 'client' =
    # boltz client wallet). Bitcoin Core requires per-wallet URLs when
    # multiple are loaded, so route wallet RPCs to the mining wallet.
    WALLET_NAME = "regtest"
    WALLET_RPCS = {"getnewaddress", "sendtoaddress", "getbalance", "listunspent",
                   "getwalletinfo", "createwallet", "loadwallet", "unloadwallet"}

    def rpc(self, method: str, *params: Any) -> Any:
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


def open_lnd_to_boltz_lnd1_channel(lnd, boltz: BoltzRegtestHandle,
                                    bitcoind_shim: BoltzBitcoindShim,
                                    capacity_sat: int = 10_000_000,
                                    push_sat: int = 5_000_000,
                                    confirmations: int = 6,
                                    timeout: float = 90.0) -> None:
    """Open a dual-direction channel from `lnd` to Boltz's lnd-1.

    Funds `lnd`'s on-chain wallet first, then connects + opens a channel
    with the requested push so both directions have liquidity. Mines for
    confirmations and waits for the channel to be active before returning.

    Used by the auto-melt sweep test: lnd_mint needs outbound liquidity
    TO Boltz so the cashu mint can route the sweep's BOLT11 payment, AND
    inbound liquidity FROM Boltz so Boltz's lnd-1 can fund the cashu
    wallet via an LN payment to the mint.
    """
    # 1. Top up lnd's on-chain wallet — channel funding needs capacity +
    # change + miner fee. Send a bit more than capacity.
    btc_to_fund = (capacity_sat + 200_000) / 100_000_000
    addr = lnd.new_address()
    bitcoind_shim.send_to_address(addr, btc_to_fund)
    bitcoind_shim.mine(confirmations)

    # 2. Wait until lnd is synced + sees the UTXO.
    deadline = time.monotonic() + timeout
    needed = capacity_sat + 50_000
    target_height = bitcoind_shim.block_count()
    while time.monotonic() < deadline:
        try:
            info = lnd.get_info()
            synced = bool(info.get("synced_to_chain"))
            height = int(info.get("block_height", 0))
            if synced and height >= target_height and lnd.wallet_balance_sat() >= needed:
                break
        except Exception:
            pass
        time.sleep(0.5)
    else:
        raise TimeoutError(
            f"LND {lnd.name} didn't reach synced + balance>={needed} within {timeout}s"
        )

    # 3. Connect to the Boltz lnd-1 peer. LND's POST /v1/peers returns when
    # the peer is queued; the actual TCP connection comes shortly after.
    # Poll list_peers until the peer is confirmed online, then open the
    # channel with push so both sides have liquidity.
    lnd.connect_peer(boltz.lnd1_pubkey,
                     f"{boltz.lnd1_p2p_host}:{boltz.lnd1_p2p_port}")
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        peers = lnd.list_peers()
        if any(p.get("pub_key") == boltz.lnd1_pubkey for p in peers):
            break
        time.sleep(0.5)
    else:
        raise TimeoutError(
            f"LND {lnd.name} did not connect to Boltz lnd-1 ({boltz.lnd1_pubkey[:16]}…) within 30s"
        )
    lnd.open_channel(boltz.lnd1_pubkey, capacity_sat, push_sat)
    bitcoind_shim.mine(confirmations)

    # 4. Wait for the channel to become active. Channels in OPENING state
    # take a few blocks beyond the funding confs to be usable for routing.
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        channels = lnd.list_channels()
        for c in channels:
            if (c.get("remote_pubkey") == boltz.lnd1_pubkey
                    and c.get("active", False)):
                return
        time.sleep(2)
    raise TimeoutError(
        f"LND {lnd.name} -> Boltz lnd-1 channel did not become active within {timeout}s"
    )


@dataclass
class SweepPayserverProc:
    """Variant of PayserverProc for the auto-melt-sweep flow: includes the
    seed phrase so the cashu wallet is fully usable, and tracks the store
    in swap-mode (auto_melt_use_swap=1)."""
    process: subprocess.Popen
    port: int
    data_dir: Path
    store_id: str
    api_token: str
    seed_phrase: str

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"


def setup_payserver_for_sweep(workdir: Path, vpub: str, mint_url: str,
                               boltz_api_url: str,
                               admin_password: str = "password") -> SweepPayserverProc:
    """Stand up cashupayserver with a single store wired for the sweep flow:
      - Cashu mint + seed (so the wallet can hold proofs)
      - On-chain xpub (so SwapAutoMelt has a sweep destination)
      - swaps_enabled = FORCE_OFF initially — flip to FORCE_ON after funding
        via {@see enable_sweep_mode_for_store} so the funding-side invoice
        creation goes through the cashu mint path, not swap.
      - auto_melt_enabled = 1, auto_melt_use_swap = 1 (force swap).
      - Per-store swap columns: boltz-only provider order, mint fallback
        allowed (swap settings are store-only; no site keys any more).

    Returns a handle including the seed phrase + store id + API token.
    """
    data_dir = workdir / "payserver-data"
    data_dir.mkdir(parents=True, exist_ok=True)
    _php_eval(data_dir, "Database::initialize(); echo 'ok';")

    import sqlite3
    db = data_dir / "cashupay.sqlite"
    now = int(time.time())
    store_id = f"store_{uuid.uuid4().hex[:12]}"
    api_token = "dev-" + uuid.uuid4().hex[:20]
    api_hash = hashlib.sha256(api_token.encode()).hexdigest()
    internal_token = "internal-" + uuid.uuid4().hex[:24]
    internal_hash = hashlib.sha256(internal_token.encode()).hexdigest()

    seed_phrase = _php_eval(
        data_dir,
        "require_once " + repr(str(REPO_ROOT / "cashu-wallet-php" / "CashuWallet.php")) + ";"
        "echo \\Cashu\\Mnemonic::generate();",
    ).strip()
    admin_pw_hash = _php_eval(
        data_dir,
        f"echo password_hash({admin_password!r}, PASSWORD_BCRYPT);",
    ).strip()

    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        kvs = [
            ("setup_complete", json.dumps(True)),
            ("url_mode", json.dumps(backend.default_url_mode())),
            # swaps_boltz_regtest_url is still a live config key (dev/test
            # knob); every other swap / cashout-mode setting is per-store now.
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
        # Store: swaps initially FORCE_OFF so funding works via cashu mint
        # rail. auto_melt_use_swap initially 0 (lightning) so the funding
        # invoice's checkAutoMelt run won't try to sweep before we tell it to.
        # auto_melt_enabled left at 0 — flipped on after funding. Provider
        # order + strict live on the store row now (boltz only, mint fallback
        # allowed).
        cur.execute(
            "INSERT INTO stores ("
            " id, name, mint_url, mint_unit, default_currency, seed_phrase,"
            " created_at, onchain_xpub, onchain_address_type, onchain_network,"
            " onchain_min_confs, swaps_enabled, swaps_provider_order,"
            " strict_no_mint_fallback,"
            " auto_melt_enabled, auto_melt_threshold, auto_melt_use_swap,"
            " internal_api_key"
            ") VALUES "
            "(?, 'Sweep Test Store', ?, 'sat', 'sat', ?, ?, ?, 'P2WPKH',"
            " 'regtest', 1, 0, ?, 0, 0, 1000, 0, ?)",
            (store_id, mint_url, seed_phrase, now, vpub,
             json.dumps(["boltz"]), internal_token),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'dev', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], api_hash, store_id, json.dumps(["*"]), now),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'internal', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], internal_hash, store_id, json.dumps(["*"]), now),
        )
        conn.commit()
    finally:
        conn.close()

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
    env.setdefault("CASHUPAY_UPDATER_DISABLED", "1")
    # Same reason as the swap-stack payserver: local mint/Esplora/Boltz
    # all live on loopback in this test rig.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    port = free_port()
    log = (data_dir / "payserver.log").open("ab")
    proc = subprocess.Popen(
        [str(_php_bin()), "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT), str(wrapper)],
        cwd=str(REPO_ROOT), env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/v1/server/info", timeout=1.0)
            return SweepPayserverProc(process=proc, port=port, data_dir=data_dir,
                                       store_id=store_id, api_token=api_token,
                                       seed_phrase=seed_phrase)
        except Exception:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"cashupayserver did not come up on :{port}")


def stop_sweep_payserver(p: SweepPayserverProc) -> None:
    if p.process.poll() is None:
        p.process.terminate()
        try:
            p.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            p.process.kill()


def enable_sweep_mode_for_store(payserver: SweepPayserverProc) -> None:
    """Flip the store row into the active-sweep configuration. Done after
    cashu funding so the funding invoice goes through the mint rail, not
    swap. After this call, the next cron tick will trigger SwapAutoMelt
    for this store.
    """
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE stores SET swaps_enabled = 1, auto_melt_enabled = 1,"
            "                  auto_melt_use_swap = 1"
            " WHERE id = ?",
            (payserver.store_id,),
        )
        conn.commit()
    finally:
        conn.close()


def fund_cashu_wallet_via_lnd1(payserver: SweepPayserverProc,
                                boltz: BoltzRegtestHandle,
                                amount_sats: int,
                                timeout: float = 90.0) -> dict:
    """Fund the payserver's cashu wallet by creating a mint-rail store
    invoice and paying its BOLT11 from Boltz's lnd-1.

    Requires the lnd_mint <-> lnd-1 channel to be active (so lnd-1 has a
    route to the mint-backing LND) AND swaps_enabled=FORCE_OFF on the
    store (so Invoice::create uses the cashu mint path).

    Returns the final invoice row once it transitions to Settled.
    """
    status, body = http_json(
        f"{payserver.url}/api/v1/stores/{payserver.store_id}/invoices",
        method="POST",
        body={"amount": amount_sats, "currency": "sat"},
        headers={"Authorization": f"token {payserver.api_token}"},
    )
    if status != 200 or not isinstance(body, dict) or "id" not in body:
        raise RuntimeError(f"create funding invoice failed: status={status} body={body}")
    inv_id = body["id"]
    bolt11 = body["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    # Pay from Boltz lnd-1 -> our LND-mint -> cashu mint.
    boltz.lnd1_payinvoice(bolt11, timeout=int(timeout))

    # Poll cron until the invoice is Settled (mint quote claimed).
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        trigger_cron(payserver)
        row = fetch_invoice_row_compat(payserver, inv_id)
        if row and row["status"] == "Settled":
            return row
        time.sleep(1)
    raise TimeoutError(f"funding invoice {inv_id} did not Settle within {timeout}s")


def fetch_invoice_row_compat(payserver, invoice_id: str) -> dict | None:
    """Like fetch_invoice_row but accepts either PayserverProc shape."""
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def fetch_sweep_row(payserver, store_id: str) -> dict | None:
    """Return the most recent sweep_attempts row for the given store, or None."""
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT * FROM sweep_attempts WHERE store_id = ?"
            " ORDER BY created_at DESC, id DESC LIMIT 1",
            (store_id,),
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def trigger_cron_compat(payserver) -> None:
    """Like trigger_cron but accepts either PayserverProc shape."""
    try:
        with urllib.request.urlopen(
            f"{payserver.url}/cron.php?key=dev-cron-key", timeout=15) as r:
            r.read()
    except Exception:
        pass


def drive_sweep_to_terminal(payserver: SweepPayserverProc,
                             boltz: BoltzRegtestHandle,
                             timeout: float = 240.0) -> dict:
    """Loop cron + mine until a sweep_attempts row for the store reaches a
    terminal state. Returns the final row.

    Mines a regtest block every ~6 ticks so the lockup tx confirms +
    propagates (Boltz's status transitions watch the chain tip).
    """
    terminal = {"invoice.settled", "swap.expired", "transaction.refunded",
                "transaction.failed", "invoice.expired", "claim.confirmed", "error"}
    deadline = time.monotonic() + timeout
    tick = 0
    last_status = None
    while time.monotonic() < deadline:
        trigger_cron_compat(payserver)
        tick += 1
        if tick % 3 == 0:
            boltz.mine_blocks(1)
        row = fetch_sweep_row(payserver, payserver.store_id)
        if row:
            if row["status"] != last_status:
                last_status = row["status"]
            if row["status"] in terminal:
                return row
        time.sleep(2)
    raise TimeoutError(
        f"sweep for store {payserver.store_id} did not reach a terminal state "
        f"within {timeout}s (last status={last_status})"
    )
