#!/usr/bin/env python3
"""Dev iteration script — spins up the whole stack against fresh data, walks
the install wizard, configures on-chain Bitcoin payments, and pays 2 invoices
each via Lightning, on-chain, and Cashu. Then halts so you can poke at the
running stack; clean shutdown on Enter.

Usage (from repo root):
    .venv/bin/python tests/scripts/iterate.py
or:
    tests/scripts/iterate.py    # if marked executable; uses tests/.venv automatically

Each run is meant to be ephemeral — the script itself is tracked, but the
workdirs it creates under app/.tmp/ are not.
"""
from __future__ import annotations

import os
import shutil
import signal
import subprocess
import sys
import time
import uuid
from pathlib import Path

# ------------------------------------------------------------------------------
# Path setup — make tests/ imports work without a package install.
# ------------------------------------------------------------------------------

SCRIPT_DIR = Path(__file__).resolve().parent
TESTS_DIR = SCRIPT_DIR.parent
REPO_ROOT = TESTS_DIR.parent
sys.path.insert(0, str(TESTS_DIR))

# Bootstrap the test venv if the user accidentally ran this with the system
# python. Comparing the raw (unresolved) paths is intentional: venv/bin/python
# is usually a symlink to the system python, but they activate different
# sys.path / site-packages. We must re-exec via the venv path itself so the
# venv's site-packages takes precedence.
_VENV_PY = TESTS_DIR / ".venv" / "bin" / "python"
if _VENV_PY.exists() and str(_VENV_PY) != sys.executable:
    os.execv(str(_VENV_PY), [str(_VENV_PY), *sys.argv])


from fixtures import binaries  # noqa: E402
from fixtures.api_client import AdminClient, GreenfieldClient  # noqa: E402
from fixtures.bitcoind import BitcoindHandle, start_bitcoind, stop_bitcoind  # noqa: E402
from fixtures.cashume import CashuMeHandle, start_cashume, stop_cashume  # noqa: E402
from fixtures.clink_relay import start_clink_relay, stop_clink_relay  # noqa: E402
from fixtures.electrum import (  # noqa: E402
    CLINK_SENDER_WALLET_NAME,
    ElectrumHandle,
    electrum_clink,
    electrum_node_uri,
    electrum_send_onchain,
    fund_electrum_from_bitcoind,
    launch_electrum_gui,
    open_electrum_channel_to_lnd,
    open_electrum_channel_to_peer,
    start_electrum,
    stop_electrum,
)
from fixtures.fulcrum import FulcrumHandle, start_fulcrum, stop_fulcrum  # noqa: E402
from fixtures.lnd import (  # noqa: E402
    LndHandle,
    open_dual_channels,
    open_extra_channel,
    start_lnd,
    stop_lnd,
)
from fixtures.lnurlp_server import (  # noqa: E402
    LnurlpServer,
    start_lnurlp_server,
    stop_lnurlp_server,
)
from fixtures.nutshell import MintHandle, NUTSHELL_VENV, start_mint, stop_mint  # noqa: E402
from fixtures.onchain import (  # noqa: E402
    OnchainContext,
    configure_store_for_onchain,
    configure_store_for_static_onchain,
    make_onchain_context,
)
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver  # noqa: E402
from fixtures.setup_helpers import browser_walk_to_store, run_setup_wizard  # noqa: E402

# ------------------------------------------------------------------------------
# Config
# ------------------------------------------------------------------------------

ADMIN_PASSWORD = "password"
STORE_ONECONF = "oneconf"
STORE_ZEROCONF = "zeroconf"
STORE_SINGLECONF_SINGLE = "singleconf_single"  # static-address mode
LIGHTNING_AMOUNT_SAT = 1500
ONCHAIN_AMOUNT_SAT = 25000
# Two distinct base amounts for the static-address store so the customer
# (Electrum) can pay each invoice with a single tx of the exact tweaked total.
SINGLE_ONCHAIN_AMOUNT_SAT_A = 30000
SINGLE_ONCHAIN_AMOUNT_SAT_B = 45000
CASHU_AMOUNT_SAT = 800
CASHU_FUNDING_SAT = 5000  # how much to mint into the auto-pay Cashu wallet

# Lightning-address + fee-redirect demo (section 7b). These exercise the
# lnaddress rail through the mock LNURL host so the admin invoices view shows
# a real LN destination + bolt11 "txid", and a fee redirect so the fee badge
# is visible. The domains are arbitrary — CASHU_LNURL_URL_TEMPLATE routes
# every address through the local mock regardless of the @domain.
STORE_FEEREDIRECT = "feeredirect"
MERCHANT_LN_ADDRESS = "merchant@iterate.local"   # store auto-melt destination
DEV_FEE_LN_ADDRESS = "dev-fee@iterate.local"     # CASHUPAY_DEV_FEE_LNURL override
LNADDR_AMOUNT_SAT = 5000          # > fees owed, so it routes to the merchant LN address
FEE_REDIRECT_AMOUNT_SAT = 2000    # << seeded dev-fee owed, so the invoice is redirected
FEE_REDIRECT_SEED_REVENUE_SAT = 1_000_000  # 2% dev fee => ~20k sat owed, dwarfs the invoice
# Customer-side wallet funding (the wallets handed off for manual play)
ELECTRUM_FUNDING_SAT = 10_000_000   # 0.1 BTC on-chain
ELECTRUM_CHANNEL_SAT = 200_000      # Lightning channel capacity to lnd_mint
# CLINK: the existing Electrum wallet runs the electrum_clink plugin (the noffer
# *service* / receiver). A second wallet, cashu_clink_sender, is the customer who
# pays a noffer-issued bolt11; it opens a *balanced* direct channel to the
# merchant so the merchant gets inbound liquidity (to receive) and the sender
# gets both outbound (to pay) and inbound.
CLINK_SENDER_FUNDING_SAT = 10_000_000   # 0.1 BTC on-chain for the sender wallet
CLINK_SENDER_CHANNEL_SAT = 5_000_000    # balanced direct channel sender <-> merchant
CLINK_FEES_LN_USER = "clink_fees"       # dev-fee payout is redirected to this local lnurlp user
CASHUME_FUNDING_SAT = 100_000_000   # 1 BTC pre-loaded into the cashu.me wallet
AUTOMINE_INTERVAL_SEC = 30          # regtest blocks tick this often so 1-conf invoices settle

ITERATE_ROOT = REPO_ROOT / "app" / ".tmp"  # inside the served tree, like the pytest fixtures
PLAYWRIGHT_BROWSERS = TESTS_DIR / "bin" / "playwright-browsers"


# ------------------------------------------------------------------------------
# Stale process cleanup
# ------------------------------------------------------------------------------

def kill_stale_processes() -> None:
    """Kill any leftover bitcoind / lnd / cashu-mint / php / chromium processes
    from prior iterate.py runs. Matches on the tests/ paths to avoid touching
    unrelated processes on the host."""
    needles = [
        str(TESTS_DIR / "bin"),
        str(REPO_ROOT / "app" / ".tmp"),
        str(NUTSHELL_VENV / "bin" / "mint"),
        str(PLAYWRIGHT_BROWSERS),
    ]
    print("[iterate] killing stale processes from prior runs ...")
    for needle in needles:
        try:
            subprocess.run(["pkill", "-9", "-f", needle], check=False)
        except FileNotFoundError:
            pass
    time.sleep(0.5)


# ------------------------------------------------------------------------------
# Cashu wallet (the customer side) via the nutshell CLI
# ------------------------------------------------------------------------------

class CashuWallet:
    """Thin wrapper around the nutshell `cashu` CLI used by the dev script
    to mint and spend tokens against the same mint cashupayserver uses.

    Operates in an isolated CASHU_DIR so multiple runs don't collide."""

    def __init__(self, mint_url: str, data_dir: Path) -> None:
        self.mint_url = mint_url
        self.data_dir = data_dir
        self.data_dir.mkdir(parents=True, exist_ok=True)
        self.cli = NUTSHELL_VENV / "bin" / "cashu"
        if not self.cli.is_file():
            raise RuntimeError(f"nutshell CLI missing at {self.cli}")

    def _env(self) -> dict[str, str]:
        env = os.environ.copy()
        env["CASHU_DIR"] = str(self.data_dir)
        env["MINT_URL"] = self.mint_url
        env["DEBUG"] = "false"
        # Disable the CLI's interactive nostr nag.
        env["NOSTR_PRIVATE_KEY"] = ""
        return env

    def _run(self, *args: str, timeout: float = 60.0) -> subprocess.CompletedProcess[str]:
        cmd = [str(self.cli), "--host", self.mint_url, *args]
        result = subprocess.run(
            cmd, env=self._env(), capture_output=True, text=True, timeout=timeout,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"cashu CLI failed: {' '.join(args)}\n"
                f"stdout: {result.stdout}\nstderr: {result.stderr}"
            )
        return result

    def request_mint_quote(self, amount_sat: int) -> tuple[str, str]:
        """Returns (bolt11, quote_id) for an inbound mint. nutshell's CLI
        prints `Invoice: <bolt11>` on one line and recommends a follow-up
        command of the form `cashu invoice <amount> --id <quote_id>`."""
        import re
        out = self._run("invoice", str(amount_sat), "--no-check").stdout
        bolt11_match = re.search(r"Invoice:\s+(lnbc\w+)", out)
        id_match = re.search(r"--id\s+([A-Za-z0-9_\-]+)", out)
        if not bolt11_match:
            raise RuntimeError(f"could not parse bolt11 from cashu invoice output:\n{out}")
        if not id_match:
            raise RuntimeError(f"could not parse quote id from cashu invoice output:\n{out}")
        return bolt11_match.group(1), id_match.group(1)

    def claim_mint(self, quote_id: str, amount_sat: int) -> None:
        """After the bolt11 from request_mint_quote has been paid externally,
        finalize the mint to receive the proofs."""
        self._run("invoice", str(amount_sat), "--id", quote_id, timeout=30)

    def balance_sat(self) -> int:
        out = self._run("balance").stdout
        for line in out.splitlines():
            if "balance:" in line.lower():
                # e.g. "Balance: 5000 sat"
                parts = line.split(":")[-1].strip().split()
                if parts and parts[0].isdigit():
                    return int(parts[0])
        return 0

    def pay_bolt11(self, bolt11: str) -> None:
        """Melt tokens to pay an external bolt11. Used to settle a
        cashupayserver invoice's bolt11 via the Cashu/melt flow."""
        # Newer nutshell uses `cashu pay`; older versions used `cashu melt`.
        # Try `pay` first, fall back to `melt`.
        try:
            self._run("pay", bolt11, "--yes", timeout=60)
        except RuntimeError:
            self._run("melt", bolt11, "--yes", timeout=60)

    def send_token(self, amount_sat: int) -> str:
        """Extract `amount_sat` worth of proofs into a Cashu token string the
        receiver can import (e.g. paste into cashu.me). Returns the token."""
        import re
        out = self._run("send", str(amount_sat), "--yes", "--offline", timeout=30).stdout
        # nutshell prints the token on its own line, beginning with cashuA... or cashuB...
        m = re.search(r"(cashu[AB][A-Za-z0-9+/=_\-]+)", out)
        if not m:
            raise RuntimeError(f"could not parse Cashu token from `cashu send` output:\n{out}")
        return m.group(1)


# ------------------------------------------------------------------------------
# Payment helpers
# ------------------------------------------------------------------------------

def create_lightning_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(LIGHTNING_AMOUNT_SAT), currency="sat",
                             metadata={"label": "lightning-iterate"})


def create_onchain_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(ONCHAIN_AMOUNT_SAT), currency="sat",
                             metadata={"label": "onchain-iterate"})


def create_cashu_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(CASHU_AMOUNT_SAT), currency="sat",
                             metadata={"label": "cashu-iterate"})


def bolt11_of(inv: dict) -> str:
    return inv["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]


def onchain_address_of(inv: dict) -> str:
    return inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]


def wait_for_status(gc: GreenfieldClient, store_id: str, invoice_id: str, expected: str,
                    timeout_s: float = 60.0, label: str = "") -> dict:
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        last = gc.get_invoice(store_id, invoice_id)
        if last.get("status") == expected:
            return last
        time.sleep(0.5)
    raise AssertionError(f"{label or invoice_id} never reached {expected}; last={last}")


def _enable_auto_melt(admin: AdminClient, store_id: str, address: str,
                      threshold_sat: int = 1) -> dict:
    """Configure a store's auto-cashout (auto-melt) LN address via the same
    admin endpoints the dashboard uses. The destination chain moved to its own
    action (save_lightning_payments — probes the address for LUD-21 support
    through the mock LNURL host); save_auto_melt now carries only the
    enabled/threshold/mode settings. With the address set, a lightning invoice
    on this store routes to the lnaddress rail."""
    chain_res = admin._post_action(
        "save_lightning_payments",
        store_id=store_id,
        address=address,
    )
    melt_res = admin._post_action(
        "save_auto_melt",
        store_id=store_id,
        enabled="1",
        threshold=str(threshold_sat),
        mode_override="0",
    )
    return {
        "success": bool(chain_res.get("success")) and bool(melt_res.get("success")),
        "addresses": chain_res.get("addresses"),
    }


def _seed_fee_revenue(payserver: PayserverHandle, store_id: str, sats: int) -> None:
    """Insert a synthetic Settled invoice so DevFee::computeOwed sees revenue
    without us having to pay it. Also zeroes fee_tracking_start_at so the row
    falls inside the accounting window. Mirrors the e2e fee-redirect fixture —
    used here to push the dev fee owed above the demo invoice so the redirect
    fires deterministically."""
    import sqlite3
    db_path = payserver.data_dir / "cashupay.sqlite"
    now = int(time.time())
    with sqlite3.connect(str(db_path)) as conn:
        conn.execute(
            "INSERT OR REPLACE INTO config (key, value, created_at, updated_at) "
            "VALUES ('fee_tracking_start_at', '0', ?, ?)",
            (now, now),
        )
        conn.execute(
            "INSERT INTO invoices "
            "(id, store_id, status, amount, currency, amount_sats, "
            " created_at, expiration_time, payment_rail, settled_rail, paid_at) "
            "VALUES (?, ?, 'Settled', ?, 'sat', ?, ?, ?, 'lnaddress', 'lnaddress', ?)",
            (
                f"seed_{uuid.uuid4().hex[:8]}",
                store_id,
                str(sats),
                sats,
                now - 60,
                now + 3600,
                now - 60,
            ),
        )
        conn.commit()


def _seed_payment_path_debug(payserver: PayserverHandle) -> None:
    """Turn ON the admin-only payment-path debug labels for this local stack.

    The site-wide default is OFF; this dev rig flips it ON so the labels are
    visible on every payment screen during manual review. Stored as JSON 'true'
    so Config::get(...) === true matches (Config json-decodes stored values)."""
    import sqlite3
    db_path = payserver.data_dir / "cashupay.sqlite"
    now = int(time.time())
    with sqlite3.connect(str(db_path)) as conn:
        conn.execute(
            "INSERT OR REPLACE INTO config (key, value, created_at, updated_at) "
            "VALUES ('show_payment_path_debug', 'true', ?, ?)",
            (now, now),
        )
        conn.commit()


def settle_lnaddress(payserver: PayserverHandle, gc: GreenfieldClient, store_id: str,
                     invoice_id: str, timeout_s: float = 45.0, label: str = "") -> dict:
    """Drive an lnaddress-rail invoice to Settled. The customer-side payment
    is already done (the bolt11 was paid on the receiver LND); settlement is
    detected by polling the LUD-21 verify URL. Hitting payment.php?json=1
    dispatches Invoice::pollSingleLnAddress, the same poll the live payment
    page runs, so we don't have to wait for the cron tick."""
    import requests
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        try:
            requests.get(
                f"{payserver.url}/payment.php",
                params={"id": invoice_id, "json": "1"},
                timeout=15,
            )
        except requests.RequestException:
            pass
        last = gc.get_invoice(store_id, invoice_id)
        if last.get("status") == "Settled":
            return last
        time.sleep(0.5)
    raise AssertionError(f"{label or invoice_id} (lnaddress) never settled; last={last}")


def _create_second_store(admin: AdminClient, db_path: Path, name: str, model_store_id: str) -> str:
    """Create a second store via the admin create_store action, then copy the
    wizard-created store's mint config (mint_url, mint_unit, seed_phrase) over
    via direct DB write so both stores can accept Lightning/Cashu invoices.
    Returns the new store's id."""
    import sqlite3
    result = admin._post_action("create_store", name=name)
    new_id = result["id"]
    conn = sqlite3.connect(db_path, isolation_level=None)
    try:
        row = conn.execute(
            "SELECT mint_url, mint_unit, seed_phrase, default_currency FROM stores WHERE id = ?",
            (model_store_id,),
        ).fetchone()
        if not row:
            raise RuntimeError(f"model store {model_store_id} not found")
        conn.execute(
            "UPDATE stores SET mint_url = ?, mint_unit = ?, seed_phrase = ?, default_currency = ? "
            "WHERE id = ?",
            (*row, new_id),
        )
    finally:
        conn.close()
    return new_id


# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------

def main() -> int:
    kill_stale_processes()

    # Honoured by includes/updater.php::isDisabledForTests() — keep the
    # auto-updater from overlaying the working tree mid-session.
    os.environ.setdefault("CASHUPAY_UPDATER_DISABLED", "1")
    os.environ.setdefault("CASHUPAY_DEV_FEE_BRANCH_ENDPOINT", "disabled")
    os.environ.setdefault("CASHUPAY_USAGE_STATS_ENDPOINT", "disabled")

    # Honoured by includes/safe_http.php — the local stack points the mint
    # and onchain provider at 127.0.0.1 / regtest endpoints, which
    # SafeHttp's default posture (block private/loopback IPs) would reject.
    os.environ.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")

    workdir = ITERATE_ROOT / f"iterate-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True, exist_ok=True)
    print(f"[iterate] workdir = {workdir}")

    # 0. Make sure binaries are cached.
    binaries.ensure_all()

    handles: list[tuple[str, callable, object]] = []  # (label, stopper, handle)
    try:
        # 1. Infra
        print("[iterate] starting bitcoind regtest ...")
        bitcoind = start_bitcoind(workdir)
        handles.append(("bitcoind", stop_bitcoind, bitcoind))

        print("[iterate] starting LND mint + payer ...")
        lnd_mint = start_lnd(workdir, "mint", bitcoind)
        handles.append(("lnd-mint", stop_lnd, lnd_mint))
        lnd_payer = start_lnd(workdir, "payer", bitcoind)
        handles.append(("lnd-payer", stop_lnd, lnd_payer))

        print("[iterate] opening dual channels (10M cap, 5M push each side) ...")
        open_dual_channels(bitcoind, lnd_payer, lnd_mint)

        # Auto-mine 1 block every AUTOMINE_INTERVAL_SEC seconds for the rest
        # of the session so on-chain payments to the "oneconf" store settle
        # without manual intervention. The daemon thread is registered with
        # the cleanup handler list so it stops on signal.
        import threading
        automine_stop = threading.Event()
        def _automine():
            while not automine_stop.wait(AUTOMINE_INTERVAL_SEC):
                try:
                    bitcoind.mine(1)
                except Exception as e:
                    print(f"[iterate] auto-mine tick failed: {e}")
        automine_thread = threading.Thread(target=_automine, name="iterate-automine", daemon=True)
        automine_thread.start()
        def _stop_automine(_):
            automine_stop.set()
            automine_thread.join(timeout=5)
        handles.append(("automine", _stop_automine, None))
        print(f"[iterate] auto-mine started ({AUTOMINE_INTERVAL_SEC}s interval)")

        print("[iterate] starting nutshell mint ...")
        mint = start_mint(workdir, lnd_mint)
        handles.append(("mint", stop_mint, mint))

        # Second nutshell instance just to satisfy the setup wizard's now-
        # required backup-mint step. Same LND backend; isolated datadir/port.
        print("[iterate] starting backup nutshell mint ...")
        backup_mint = start_mint(workdir, lnd_mint, name="nutshell-backup")
        handles.append(("backup_mint", stop_mint, backup_mint))

        # Mock LNURL-pay host (LUD-16 + LUD-21), backed by lnd_mint so the
        # customer (lnd_payer) can pay invoices it issues. Started before the
        # payserver so its URL template can be injected via the environment.
        print("[iterate] starting mock LNURL-pay host (backed by lnd_mint) ...")
        lnurlp = start_lnurlp_server(lnd_mint, lud21=True)
        handles.append(("lnurlp", stop_lnurlp_server, lnurlp))

        print("[iterate] starting cashupayserver ...")
        payserver = start_payserver(
            workdir / "payserver",
            extra_env={
                # Route every LN address (merchant + dev fee) through the mock.
                "CASHU_LNURL_URL_TEMPLATE": lnurlp.url_template,
                # Override the dev-fee LNURL so a fee redirect can be shown
                # end-to-end without ever probing the real mainnet default
                # (fees@getbarebits.com).
                "CASHUPAY_DEV_FEE_LNURL": DEV_FEE_LN_ADDRESS,
            },
        )
        handles.append(("payserver", stop_payserver, payserver))

        onchain = make_onchain_context(bitcoind, f"cashupay-watch-iter-{uuid.uuid4().hex[:6]}")

        # 2. Setup wizard — driven through the browser so the user can see the
        #    initial state and so the UI flow is exercised end-to-end.
        print("[iterate] running setup wizard via Playwright (headless) ...")
        os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", str(PLAYWRIGHT_BROWSERS))
        from playwright.sync_api import sync_playwright  # noqa: E402

        with sync_playwright() as pw:
            browser = pw.chromium.launch(
                headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"],
            )
            ctx = browser.new_context()
            page = ctx.new_page()
            page.set_default_timeout(15000)

            # terms → analytics (decline) → installation type ("something
            # else") → safety check (auto-advances) → password → e-mail
            # (skip) → store name.
            browser_walk_to_store(page, payserver.url, ADMIN_PASSWORD, store_name=STORE_ONECONF)
            # onchain: skip. The real xpub (Electrum's vpub, on regtest) is
            # registered further down once Electrum is up, via direct SQL —
            # the wizard can't know it yet. Skipping here also drops the
            # zero-conf screen from the sequence.
            page.wait_for_selector("#onchain-form")
            page.click("button:has-text('Skip for now')")
            # lightning: skip. Auto-cashout destinations are configured from
            # admin once the stack is up.
            page.wait_for_selector("#lightning_address")
            page.click("button:has-text('Skip for now')")
            # (no swaps screen: they stay off because this store has no xpub yet)
            # mints: Nostr discovery can't reach relays from the test sandbox,
            # so go straight to manual entry and point at the two local
            # nutshell instances.
            page.wait_for_selector("#mint-manual-toggle")
            page.click("#mint-manual-toggle")
            page.fill("#mint_url_manual", mint.url)
            page.fill("#backup_mint_url_manual", backup_mint.url)
            page.click("#mints-continue-btn")
            # setup_complete flips here; wallet tabs, seed, updates, cron and
            # done are advisory. Keep all three wallet tabs (pre-ticked) so
            # the dev stack's payment page shows the Cash App / Venmo /
            # PayPal tabs.
            page.wait_for_selector("h2:has-text('Popular wallet tabs')")
            page.click("button[name='wallet_tabs_action'][value='save']")
            page.wait_for_selector("h2:has-text('Save your recovery phrase')")
            page.click("button:has-text('continue')")
            page.wait_for_selector("h2:has-text('Enable automatic updates?')")
            page.click("button:has-text('Not now')")
            page.wait_for_selector("h2:has-text('Enable cron')")
            page.click("button:has-text('Continue')")
            page.wait_for_selector("h2:has-text(\"You're all set!\")")

            browser.close()

        # 3. Bring up Fulcrum + Electrum NOW (before configuring stores) so
        #    we can pull Electrum's vpub and register it as the on-chain xpub
        #    for both stores. That way addresses cashupayserver generates
        #    match what Electrum's wallet displays for receive.
        print("[iterate] starting Fulcrum (Electrum protocol server -> bitcoind) ...")
        fulcrum = start_fulcrum(workdir, bitcoind)
        handles.append(("fulcrum", stop_fulcrum, fulcrum))

        # CLINK: in-rig Nostr relay the plugin (and cashupayserver's ClinkClient)
        # speak over. Started before Electrum so the plugin can subscribe on
        # startup. Loopback-only; no auth — throwaway rig only.
        print("[iterate] starting in-rig CLINK Nostr relay ...")
        clink_relay = start_clink_relay(workdir)
        handles.append(("clink-relay", stop_clink_relay, clink_relay))
        print(f"[iterate] CLINK relay: {clink_relay.ws_url}")

        # Fake NWC wallet on the same relay, minting from lnd_payer (which
        # lnd_mint can pay over the dual channels — so both direct-receive
        # invoices, paid from lnd_mint, and auto-melt drains, paid BY the
        # store's mint, settle in-rig). Paste the printed connection string
        # into a store's NWC section or the wizard's NWC field.
        print("[iterate] starting fake NWC wallet (NIP-47) on the in-rig relay ...")
        from fixtures.nwc_wallet import start_nwc_wallet
        nwc_wallet = start_nwc_wallet(
            clink_relay.ws_url, lnd_payer,
            info_methods=["make_invoice", "lookup_invoice", "get_info"],
        )
        handles.append(("nwc-wallet", lambda h: h.stop(), nwc_wallet))
        nwc_demo_uri = nwc_wallet.connection_uri()
        # Redirect the plugin's opt-out dev-fee payout to the local LNURL host so
        # nothing ever leaves the rig (default is a real mainnet address).
        clink_devfee_dest = f"{lnurlp.base_url}/.well-known/lnurlp/{CLINK_FEES_LN_USER}"

        print("[iterate] starting Electrum daemon (regtest) + CLINK noffer plugin ...")
        electrum = start_electrum(
            workdir, fulcrum,
            with_clink=True,
            clink_relay_url=clink_relay.ws_url,
            clink_devfee_dest=clink_devfee_dest,
        )
        handles.append(("electrum", stop_electrum, electrum))

        electrum_vpub = electrum.cli("getmpk").strip()
        print(f"[iterate] Electrum vpub: {electrum_vpub[:16]}...{electrum_vpub[-8:]}")

        # 4. Login as admin via the AdminClient + mint an API key.
        print("[iterate] logging in as admin + creating API key ...")
        admin = AdminClient(payserver.url)
        admin.login(ADMIN_PASSWORD)

        # Dev rig: surface the admin-only payment-path debug labels on every
        # payment screen (site-wide default is OFF).
        _seed_payment_path_debug(payserver)

        stores = admin.list_stores()
        oneconf_store_id = next(s["id"] for s in stores if s["name"] == STORE_ONECONF)

        # 5. Create the second store via the Greenfield API + grant the same
        #    seed/mint config the wizard gave to "oneconf" so it can take
        #    Cashu invoices too.
        print(f"[iterate] creating second store '{STORE_ZEROCONF}' ...")
        # Use the admin AJAX endpoint instead of the bare Greenfield POST /stores
        # because the wizard sets mint_url + seed on the wizard store; for the
        # second store we copy those over via direct DB write.
        zeroconf_store_id = _create_second_store(
            admin, payserver.db_path, STORE_ZEROCONF, oneconf_store_id,
        )

        key = admin.create_api_key(oneconf_store_id, label="iterate-oneconf")
        token_oneconf = key["key"]
        gc_oneconf = GreenfieldClient(payserver.url, token_oneconf)
        key2 = admin.create_api_key(zeroconf_store_id, label="iterate-zeroconf")
        token_zeroconf = key2["key"]
        gc_zeroconf = GreenfieldClient(payserver.url, token_zeroconf)

        # 6. Configure on-chain on BOTH stores with Electrum's vpub. They
        #    share the same xpub; the per-xpub counter in onchain_xpub_state
        #    keeps their derivation indices in sync so they can't collide.
        print(f"[iterate] configuring on-chain on both stores (vpub={electrum_vpub[:12]}...) ...")
        configure_store_for_onchain(
            payserver.db_path, oneconf_store_id,
            xpub=electrum_vpub, network="regtest", address_type="P2WPKH",
            min_confs=1, confirm_timeout_sec=86400,
            provider_url=onchain.watch_wallet_url, start_index=0,
        )
        configure_store_for_onchain(
            payserver.db_path, zeroconf_store_id,
            xpub=electrum_vpub, network="regtest", address_type="P2WPKH",
            min_confs=0, confirm_timeout_sec=86400,
            provider_url=onchain.watch_wallet_url, start_index=0,
        )

        # 6b. Third store: static-address mode. A single Bitcoin address
        #     (pulled from the Electrum wallet) is reused for every invoice;
        #     each invoice gets a unique sat-tweak so totals never collide.
        #     We use a separate fresh Electrum address here so it doesn't
        #     overlap with the deposit address fund_electrum_from_bitcoind
        #     uses for its initial 0.1 BTC funding (avoids confusing the
        #     poller's tip-height filter with pre-existing UTXOs).
        print(f"[iterate] creating third store '{STORE_SINGLECONF_SINGLE}' (static-address mode) ...")
        single_store_id = _create_second_store(
            admin, payserver.db_path, STORE_SINGLECONF_SINGLE, oneconf_store_id,
        )
        key3 = admin.create_api_key(single_store_id, label="iterate-single")
        gc_single = GreenfieldClient(payserver.url, key3["key"])
        single_static_addr = electrum.cli("createnewaddress").strip()
        print(f"[iterate] static address for {STORE_SINGLECONF_SINGLE}: {single_static_addr}")
        configure_store_for_static_onchain(
            payserver.db_path, single_store_id,
            static_address=single_static_addr, network="regtest",
            tweak_range=1000, min_confs=1, confirm_timeout_sec=86400,
            provider_url=onchain.watch_wallet_url,
        )

        # 5. Prepare the customer-side Cashu wallet — mint enough tokens upfront
        #    that we can pay the two Cashu-flow invoices with melts.
        print(f"[iterate] funding customer Cashu wallet with {CASHU_FUNDING_SAT} sats ...")
        cashu_wallet = CashuWallet(mint.url, workdir / "cashu-wallet")
        bolt11_for_mint, quote_id = cashu_wallet.request_mint_quote(CASHU_FUNDING_SAT)
        pay_result = lnd_payer.pay_invoice_sync(bolt11_for_mint, timeout=30)
        if pay_result.get("payment_error"):
            raise RuntimeError(f"Cashu wallet funding payment failed: {pay_result}")
        # Give the mint a moment to mark the quote paid, then claim.
        time.sleep(1.5)
        cashu_wallet.claim_mint(quote_id, CASHU_FUNDING_SAT)
        print(f"[iterate] Cashu wallet balance: {cashu_wallet.balance_sat()} sat")

        # 7. Create + pay 6 invoices: 1 of each method per store.
        results: list[tuple[str, str, str, str]] = []  # (store, method, invoice_id, status)

        store_pairs = [
            ("oneconf", oneconf_store_id, gc_oneconf),
            ("zeroconf", zeroconf_store_id, gc_zeroconf),
        ]

        print("\n[iterate] === Lightning invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_lightning_invoice(gc, sid)
            print(f"  [{label}] {inv['id']} -> paying {LIGHTNING_AMOUNT_SAT} sat via lnd_payer")
            lnd_payer.pay_invoice_sync(bolt11_of(inv), timeout=30)
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=30, label=f"lightning {label}")
            results.append((label, "Lightning", inv["id"], settled["status"]))

        print("\n[iterate] === On-chain invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_onchain_invoice(gc, sid)
            addr = onchain_address_of(inv)
            print(f"  [{label}] {inv['id']} -> sending {ONCHAIN_AMOUNT_SAT} sat to {addr}")
            onchain.fund_address(addr, ONCHAIN_AMOUNT_SAT)
            # oneconf needs a block; zeroconf settles on mempool sighting
            onchain.confirm(1)
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=45, label=f"onchain {label}")
            results.append((label, "On-chain", inv["id"], settled["status"]))

        print("\n[iterate] === Cashu (wallet melt) invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_cashu_invoice(gc, sid)
            print(f"  [{label}] {inv['id']} -> paying {CASHU_AMOUNT_SAT} sat via Cashu wallet melt")
            cashu_wallet.pay_bolt11(bolt11_of(inv))
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=30, label=f"cashu {label}")
            results.append((label, "Cashu", inv["id"], settled["status"]))

        # 7b. Lightning-address rail + fee-redirect examples. Both ride the
        #     lnaddress rail through the mock LNURL host and are paid by
        #     lnd_payer, so no customer wallet is needed yet.
        print("\n[iterate] === Lightning-address + fee-redirect examples ===")

        # (a) Normal LN-address payment: route a lightning invoice to the
        #     merchant's auto-cashout LN address. Surfaces the LN destination
        #     (the address) + bolt11 "txid" in the admin invoices view, with
        #     no fee badge. Amount is well above oneconf's owed fees so it is
        #     neither redirected nor forced onto the mint rail.
        save_res = _enable_auto_melt(admin, oneconf_store_id, MERCHANT_LN_ADDRESS)
        print(f"  [lnaddr] auto-melt configured on oneconf -> {MERCHANT_LN_ADDRESS} "
              f"(success={save_res.get('success')})")
        lnaddr_inv = gc_oneconf.create_invoice(
            oneconf_store_id, amount=str(LNADDR_AMOUNT_SAT), currency="sat",
            metadata={"label": "lnaddress-iterate"},
        )
        print(f"  [lnaddr] {lnaddr_inv['id']} rail={lnaddr_inv.get('paymentRail')} "
              f"-> paying {LNADDR_AMOUNT_SAT} sat via lnd_payer")
        lnd_payer.pay_invoice_sync(bolt11_of(lnaddr_inv), timeout=30)
        settled = settle_lnaddress(payserver, gc_oneconf, oneconf_store_id,
                                   lnaddr_inv["id"], label=f"lnaddress oneconf")
        results.append(("oneconf", "LN-address", lnaddr_inv["id"], settled["status"]))

        # (b) Fee redirect: a dedicated store with the dev fee owed seeded well
        #     above the invoice amount, so FeeRedirect::decide points the whole
        #     (lightning-only) invoice at the dev fee LNURL. Paying it records a
        #     redirect credit instead of crediting the merchant — the admin
        #     invoices view shows the "Fee payment" badge + the fee destination.
        print(f"  [fee] creating '{STORE_FEEREDIRECT}' store + seeding "
              f"{FEE_REDIRECT_SEED_REVENUE_SAT:,} sat revenue ...")
        feeredirect_store_id = _create_second_store(
            admin, payserver.db_path, STORE_FEEREDIRECT, oneconf_store_id,
        )
        key_fee = admin.create_api_key(feeredirect_store_id, label="iterate-feeredirect")
        gc_fee = GreenfieldClient(payserver.url, key_fee["key"])
        _seed_fee_revenue(payserver, feeredirect_store_id, sats=FEE_REDIRECT_SEED_REVENUE_SAT)
        fee_inv = gc_fee.create_invoice(
            feeredirect_store_id, amount=str(FEE_REDIRECT_AMOUNT_SAT), currency="sat",
            metadata={"label": "fee-redirect-iterate"},
        )
        fr = fee_inv.get("feeRedirect")
        print(f"  [fee] {fee_inv['id']} rail={fee_inv.get('paymentRail')} "
              f"feeRedirect={fr} -> paying {FEE_REDIRECT_AMOUNT_SAT} sat via lnd_payer")
        if not fr:
            print("  [fee] WARNING: invoice was NOT redirected to a fee — check "
                  "seeded revenue / dev fee config")
        lnd_payer.pay_invoice_sync(bolt11_of(fee_inv), timeout=30)
        settled = settle_lnaddress(payserver, gc_fee, feeredirect_store_id,
                                   fee_inv["id"], label="fee-redirect")
        results.append((STORE_FEEREDIRECT, "Fee-redirect", fee_inv["id"], settled["status"]))

        # 8. Customer-side wallets: fund Electrum on-chain and open its LN
        #    channel. (Electrum + Fulcrum were started earlier so we could
        #    grab the vpub before configuring the stores.)
        print("\n[iterate] === Setting up customer wallets ===")
        print(f"[iterate] funding Electrum on-chain with {ELECTRUM_FUNDING_SAT:,} sat ...")
        fund_electrum_from_bitcoind(electrum, bitcoind, ELECTRUM_FUNDING_SAT, confirmations=3)

        print(f"[iterate] opening Electrum Lightning channel to lnd_mint ({ELECTRUM_CHANNEL_SAT:,} sat) ...")
        open_electrum_channel_to_lnd(
            electrum, bitcoind, lnd_mint.pubkey, "127.0.0.1", lnd_mint.p2p_port,
            capacity_sat=ELECTRUM_CHANNEL_SAT,
        )

        # 8a. CLINK sender wallet: a second Electrum instance (cashu_clink_sender)
        #     that pays noffer-issued bolt11s. It opens a balanced direct channel
        #     to the merchant (the plugin wallet), giving the merchant the inbound
        #     liquidity it needs to receive CLINK payments and the sender both
        #     outbound and inbound.
        print(f"\n[iterate] === Setting up CLINK sender wallet ({CLINK_SENDER_WALLET_NAME}) ===")
        clink_sender = start_electrum(
            workdir, fulcrum,
            instance="electrum-clink-sender",
            wallet_name=CLINK_SENDER_WALLET_NAME,
        )
        handles.append(("electrum-clink-sender", stop_electrum, clink_sender))
        print(f"[iterate] funding {CLINK_SENDER_WALLET_NAME} on-chain with {CLINK_SENDER_FUNDING_SAT:,} sat ...")
        fund_electrum_from_bitcoind(clink_sender, bitcoind, CLINK_SENDER_FUNDING_SAT, confirmations=3)
        print(f"[iterate] opening balanced channel {CLINK_SENDER_WALLET_NAME} <-> merchant "
              f"({CLINK_SENDER_CHANNEL_SAT:,} sat, half pushed) ...")
        open_electrum_channel_to_peer(
            clink_sender, electrum_node_uri(electrum), bitcoind,
            capacity_sat=CLINK_SENDER_CHANNEL_SAT,
        )
        # Wait for the plugin to see inbound liquidity, then mint a demo noffer
        # the operator can pay from cashu_clink_sender (or wire into a store's
        # auto-cashout chain in admin).
        clink_noffer = None
        try:
            for _ in range(45):
                avail = int(electrum_clink(electrum, "clink_status").get("available_sat", 0) or 0)
                if avail > 0:
                    break
                bitcoind.mine(1)
                time.sleep(2)
            clink_noffer = electrum_clink(electrum, "add_offer", "--label", "iterate-demo")["noffer"]
            print(f"[iterate] merchant inbound liquidity: {avail:,} sat; demo noffer: {clink_noffer}")
        except Exception as e:
            print(f"[iterate] (note) CLINK demo offer not minted: {e}")

        # 8b. Static-address store: create + pay 2 invoices, paid from the
        #     now-funded Electrum wallet. Each invoice has a distinct base
        #     amount + unique tweak, so Electrum sends two single-tx
        #     payments and each one settles unambiguously.
        print(f"\n[iterate] === Static-address invoices for '{STORE_SINGLECONF_SINGLE}' ===")
        for label, base_amount in [
            ("static-A", SINGLE_ONCHAIN_AMOUNT_SAT_A),
            ("static-B", SINGLE_ONCHAIN_AMOUNT_SAT_B),
        ]:
            inv = gc_single.create_invoice(
                single_store_id, amount=str(base_amount), currency="sat",
                metadata={"label": f"{label}-iterate"},
            )
            total = int(inv["checkout"]["paymentMethods"]["BTC-OnChain"]["amount"])
            tweak = total - base_amount
            addr = onchain_address_of(inv)
            print(f"  [{label}] {inv['id']} -> base={base_amount} tweak={tweak} total={total} "
                  f"-> sending from Electrum to {addr}")
            txid = electrum_send_onchain(electrum, bitcoind, addr, total, confirmations=1)
            print(f"  [{label}] broadcast txid={txid}")
            settled = wait_for_status(
                gc_single, single_store_id, inv["id"], "Settled",
                timeout_s=60, label=f"static {label}",
            )
            results.append((STORE_SINGLECONF_SINGLE, f"OnChain-Static ({label})",
                            inv["id"], settled["status"]))

        # 1 BTC pre-mint needs much more outbound than the dual-channel 5M
        # push gives. Open an extra big channel from the customer LN to the
        # mint backend's LN so the mint quote payment routes through it.
        cashu_fund_channel_sat = CASHUME_FUNDING_SAT + 10_000_000  # headroom for fees + LND reserves
        print(f"[iterate] opening extra {cashu_fund_channel_sat:,} sat channel "
              f"for the {CASHUME_FUNDING_SAT:,} sat Cashu pre-fund ...")
        open_extra_channel(
            bitcoind, lnd_payer, lnd_mint,
            capacity_sat=cashu_fund_channel_sat, push_sat=0,
        )

        print(f"[iterate] minting {CASHUME_FUNDING_SAT:,} sat ({CASHUME_FUNDING_SAT/1e8:.4f} BTC) "
              f"Cashu token for cashu.me ...")
        bolt11_cashume, qid_cashume = cashu_wallet.request_mint_quote(CASHUME_FUNDING_SAT)
        pay_result = lnd_payer.pay_invoice_sync(bolt11_cashume, timeout=120)
        if pay_result.get("payment_error"):
            raise RuntimeError(f"cashu.me funding payment failed: {pay_result['payment_error']}")
        time.sleep(3)
        cashu_wallet.claim_mint(qid_cashume, CASHUME_FUNDING_SAT)
        cashume_token = cashu_wallet.send_token(CASHUME_FUNDING_SAT)

        print("[iterate] starting cashu.me dev server ...")
        cashume = start_cashume()
        handles.append(("cashu.me", stop_cashume, cashume))
        cashume_url = cashume.deeplink(mint_url=mint.url, token=cashume_token)

        print("[iterate] launching Electrum GUIs (merchant + CLINK sender) ...")
        launch_electrum_gui(electrum)
        launch_electrum_gui(clink_sender)

        print("[iterate] launching cashu.me + admin in a single Chromium window ...")
        # Use Playwright's manual lifecycle (start/stop instead of context
        # manager) so the browser stays open for the user while we wait for
        # the cleanup signal. All URLs land as tabs of the same window so
        # the user has a single Chromium to alt-tab through.
        from playwright.sync_api import sync_playwright  # noqa: E402
        wallet_pw = sync_playwright().start()
        wallet_browser = wallet_pw.chromium.launch(
            headless=False, args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        wallet_ctx = wallet_browser.new_context()

        # Pre-seed cashu.me's localStorage to bypass the welcome wizard
        # before the page even mounts. Keys derived from
        # tests/bin/cashu.me/src/stores/welcome.ts. useLocalStorage from
        # vueuse stores values as JSON strings.
        wallet_ctx.add_init_script("""
            try {
                if (location.host.startsWith('127.0.0.1') || location.host.startsWith('localhost')) {
                    localStorage.setItem('cashu.welcome.showWelcome', 'false');
                    localStorage.setItem('cashu.welcome.termsAccepted', 'true');
                    localStorage.setItem('cashu.welcome.seedPhraseValidated', 'true');
                    localStorage.setItem('cashu.welcome.mintSetupCompleted', 'true');
                    localStorage.setItem('cashu.welcome.ecashRestoreCompleted', 'true');
                    localStorage.setItem('cashu.welcome.path', '"new"');
                }
            } catch (_) {}
        """)

        cashume_tab = wallet_ctx.new_page()
        cashume_tab.set_default_timeout(15000)
        try:
            # First navigation triggers Vite's cold-start SPA bundle compile,
            # which can comfortably exceed the 15s default on a fresh
            # node_modules/.cache. Give it 2 minutes; warm runs return in
            # seconds anyway.
            cashume_tab.goto(cashume_url, timeout=120000)
            # The ?mint=... deeplink opens AddMintDialog ("Do you trust this
            # mint?"). Its primary action button has a stable class .add-btn.
            cashume_tab.locator(".add-btn:visible").first.click(timeout=15000)
            # Give the add-mint dialog its close animation + give the
            # ReceiveTokenDialog its open animation. Without this delay we
            # race and end up clicking a stale button.
            cashume_tab.wait_for_selector(".add-btn", state="hidden", timeout=10000)

            # The receive dialog has three potential buttons: "Later"
            # (flat), "Swap" (outline), and the primary handleReceive button
            # ("Receive"/"Receive Ecash", unelevated, full-width, size lg).
            # A plain `:has-text('Receive')` would also match tooltips, so
            # we target the unique class combination of the primary button.
            cashume_tab.locator(
                ".q-dialog button.full-width.q-btn--unelevated:not(.q-btn--flat):not(.q-btn--outline):visible"
            ).first.click(timeout=20000)
        except Exception as e:
            print(f"[iterate] (note) cashu.me auto-click hit a snag — finish manually: {e}")

        admin_tab = wallet_ctx.new_page()
        admin_tab.goto(f"{payserver.url}/admin")

        def stop_wallet_browser(_):
            try: wallet_ctx.close()
            except Exception: pass
            try: wallet_browser.close()
            except Exception: pass
            try: wallet_pw.stop()
            except Exception: pass
        handles.append(("wallet-browser", stop_wallet_browser, None))

        # 9. Summary + halt
        print("\n" + "=" * 72)
        print(f"{'Store':<10} {'Method':<12} {'Invoice ID':<32} {'Status'}")
        print("-" * 72)
        for store_label, method, iid, status in results:
            print(f"{store_label:<10} {method:<12} {iid:<32} {status}")
        print("=" * 72)
        print(f"\nAdmin URL:        {payserver.url}/admin     (password: {ADMIN_PASSWORD})")
        print(f"Payserver:        {payserver.url}")
        print(f"Mint:             {mint.url}")
        print(f"oneconf store id: {oneconf_store_id}    (on-chain min_confs=1)")
        print(f"oneconf API key:  {token_oneconf}")
        print(f"zeroconf store id:{zeroconf_store_id}    (on-chain min_confs=0)")
        print(f"zeroconf API key: {token_zeroconf}")
        print(f"feeredirect store:{feeredirect_store_id}    (dev-fee redirect demo; LN-only)")
        print(f"LNURL mock host:  {lnurlp.base_url}    (merchant + dev-fee LN addresses)")
        print(f"                  oneconf has an LN-address payment ({MERCHANT_LN_ADDRESS});")
        print(f"                  feeredirect store has a 'Fee payment' redirect to {DEV_FEE_LN_ADDRESS}")
        print(f"Workdir:          {workdir}")
        print(f"Path debug:       ON (admin-only payment-path labels visible on payment screens)")
        print(f"Auto-mine:        every {AUTOMINE_INTERVAL_SEC}s (so oneconf on-chain invoices settle)")
        print(f"\nElectrum:         GUI launched (regtest); vpub registered on both stores")
        print(f"                  ~{ELECTRUM_FUNDING_SAT/1e8:.4f} BTC on-chain, "
              f"~{ELECTRUM_CHANNEL_SAT:,} sat channel to lnd_mint")
        print(f"                  + runs the electrum_clink plugin (noffer service)")
        print(f"CLINK relay:      {clink_relay.ws_url}    (in-rig Nostr relay)")
        print(f"CLINK sender:     {CLINK_SENDER_WALLET_NAME} GUI launched; "
              f"~{CLINK_SENDER_FUNDING_SAT/1e8:.4f} BTC on-chain,")
        print(f"                  balanced ~{CLINK_SENDER_CHANNEL_SAT:,} sat channel to the merchant")
        if clink_noffer:
            print(f"CLINK demo noffer:{clink_noffer}")
            print(f"                  (pay it from {CLINK_SENDER_WALLET_NAME}, or add it to a store's")
            print(f"                   auto-cashout chain in admin to direct-receive over CLINK)")
        print(f"CLINK dev-fee:    redirected to {clink_devfee_dest} (local; never leaves the rig)")
        print(f"NWC demo string:  {nwc_demo_uri}")
        print(f"                  (fake NIP-47 wallet backed by lnd_payer; paste it into a store's")
        print(f"                   NWC section — invoices it mints are payable from lnd_mint)")
        print(f"cashu.me:         {cashume_url}")
        print(f"                  opened in Chromium with {CASHUME_FUNDING_SAT} sat pre-loaded")

        # If stdin is a terminal, halt on Enter as advertised. When the script
        # is launched in the background (no controlling terminal — stdin is
        # closed / piped), input() raises EOFError immediately and we'd tear
        # everything down before the caller can use the stack. In that case,
        # block until we get a signal (SIGTERM/SIGINT) so the caller can drive
        # the lifecycle externally.
        if sys.stdin.isatty():
            print("\nPress Enter to clean up everything and exit ...")
            try:
                input()
            except (EOFError, KeyboardInterrupt):
                pass
        else:
            print("\n[iterate] running in background; send SIGTERM/SIGINT to clean up.", flush=True)
            stop_event = signal.SIGTERM  # sentinel for the handler below
            received: list[int] = []
            def _handler(signum, _frame):
                received.append(signum)
            signal.signal(signal.SIGTERM, _handler)
            signal.signal(signal.SIGINT, _handler)
            while not received:
                signal.pause()

    finally:
        print("\n[iterate] cleaning up ...")
        for label, stopper, handle in reversed(handles):
            try:
                stopper(handle)
                print(f"  stopped {label}")
            except Exception as e:
                print(f"  failed to stop {label}: {e}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
