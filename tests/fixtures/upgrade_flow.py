"""Shared steps of the upgrade tier: seed a real installed release like a
merchant would, pay invoices on both regtest rails, drive its auto-updater,
and prove the install still works afterwards.

The install under test may be any release in the window, so everything here
goes through surfaces that are stable across releases: the onboarding wizard
via scripts/upgrade-smoke.php (which answers whatever screen the wizard
renders), the admin actions the dashboard itself uses, and the BTCPay-
compatible Greenfield API. Payments come from the regtest stack the rest of
the suite runs on (bitcoind + LND + nutshell).
"""
from __future__ import annotations

import json
import subprocess
import time
from dataclasses import dataclass, field
from pathlib import Path

import requests

from . import binaries
from .api_client import AdminClient, GreenfieldClient
from .lnd import LndHandle
from .nutshell import MintHandle
from .onchain import OnchainContext, configure_store_for_onchain
from .payserver import PayserverHandle
from .webhook_sink import WebhookSink

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
DRIVER = REPO_ROOT / "scripts" / "upgrade-smoke.php"

ADMIN_PASSWORD = "upgrade-admin-pw-1234"
STORE_NAME = "Upgrade Store"
PRODUCT_COUNT = 3
LN_AMOUNT_SAT = 1000
ONCHAIN_AMOUNT_SAT = 50_000
WEBHOOK_SECRET = "upgrade-webhook-secret"
# For the build under test when it is served by the regular fixture
# (start_payserver): the same dev-fee neutralisation installed_payserver.py
# applies to the old release, so revenue carried across the hop cannot flip
# the next Lightning invoice onto the (mainnet) fee redirect.
CANDIDATE_ENV = {"CASHUPAY_DEV_FEE_LNURL": "fees@fee-lnurl.invalid"}


@dataclass
class SeededInstall:
    """One installed release after seed_install(): the handles a test needs
    to pay it, upgrade it and verify it."""

    handle: PayserverHandle
    install: Path
    tag: str
    admin: AdminClient
    greenfield: GreenfieldClient
    store_id: str
    api_key: str
    product_titles: list[str]
    password: str = ADMIN_PASSWORD
    store_name: str = STORE_NAME
    invoice_ids: list[str] = field(default_factory=list)
    webhook_path: str | None = None

    @property
    def url(self) -> str:
        return self.handle.url


# --------------------------------------------------------------------------
# driver


def run_driver(url: str, mode: str, **kv: str) -> dict:
    """Run scripts/upgrade-smoke.php and return its RESULT= payload."""
    php = str(binaries.ensure(binaries.PHP)["php"])
    args = [php, str(DRIVER), mode, url] + [f"--{k.replace('_', '-')}={v}" for k, v in kv.items()]
    result = subprocess.run(args, capture_output=True, text=True, timeout=300)
    if result.returncode != 0:
        raise AssertionError(
            f"upgrade-smoke.php {mode} failed (rc={result.returncode})\n"
            f"--- stdout ---\n{result.stdout}\n--- stderr ---\n{result.stderr}"
        )
    for line in reversed(result.stdout.splitlines()):
        if line.startswith("RESULT="):
            return json.loads(line[len("RESULT="):])
    raise AssertionError(f"upgrade-smoke.php {mode} printed no RESULT line:\n{result.stdout}")


def seed_install(
    handle: PayserverHandle,
    install: Path,
    tag: str,
    *,
    mint: MintHandle,
    backup_mint: MintHandle,
    onchain: OnchainContext,
    webhook_sink: WebhookSink | None = None,
    products: int = PRODUCT_COUNT,
) -> SeededInstall:
    """Make the fresh install look lived-in: wizard with the regtest mints,
    the regtest xpub on the store, products, an API key and a webhook."""
    res = run_driver(
        handle.url, "seed",
        password=ADMIN_PASSWORD, store=STORE_NAME,
        mint=mint.url, backup_mint=backup_mint.url, unit="sat",
        products=str(products),
    )
    store_id = res["store_id"]
    configure_store_for_onchain(
        handle.db_path, store_id,
        xpub=onchain.tpub, network="regtest", address_type="P2WPKH",
        min_confs=1, confirm_timeout_sec=86400,
        provider_url=onchain.watch_wallet_url, start_index=0,
    )
    admin = AdminClient(handle.url)
    admin.login(ADMIN_PASSWORD)
    seeded = SeededInstall(
        handle=handle, install=install, tag=tag, admin=admin,
        greenfield=GreenfieldClient(handle.url, res["api_key"]),
        store_id=store_id, api_key=res["api_key"],
        product_titles=[p["title"] for p in res["products"]],
    )
    if webhook_sink is not None:
        seeded.webhook_path = f"/hook/upgrade-{tag}"
        seeded.greenfield.create_webhook(
            store_id, webhook_sink.url.rstrip("/") + seeded.webhook_path, secret=WEBHOOK_SECRET
        )
    return seeded


def verify_install(seeded: SeededInstall, *, min_invoices: int | None = None) -> dict:
    """The merchant-facing contract after any hop: login, store, products,
    API key, invoices, update status. Returns the driver's RESULT payload."""
    kv = dict(
        password=seeded.password, store_id=seeded.store_id, api_key=seeded.api_key,
        store_name=seeded.store_name, products=str(len(seeded.product_titles)),
        product_titles="|".join(seeded.product_titles),
    )
    if min_invoices is not None:
        kv["invoices"] = str(min_invoices)
    try:
        return run_driver(seeded.url, "verify", **kv)
    except AssertionError as e:
        raise AssertionError(f"{e}\n--- server log (PHP errors) ---\n{server_php_errors(seeded.handle)}") from None


def server_php_errors(handle: PayserverHandle, limit: int = 15) -> str:
    """The last PHP fatal/parse/warning lines the served install logged —
    what a 500 from the driver actually means."""
    log = handle.workdir / "php-server.log"
    if not log.is_file():
        return "(no php-server.log)"
    lines = [
        ln for ln in log.read_text(errors="replace").splitlines()
        if "PHP Fatal" in ln or "PHP Parse" in ln or "PHP Warning" in ln or "Uncaught" in ln
    ]
    return "\n".join(lines[-limit:]) or "(no PHP error lines)"


# --------------------------------------------------------------------------
# invoices + payments


def _poll_status(seeded: SeededInstall, invoice_id: str, status: str, *, timeout_s: float, drive_cron: bool) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        if drive_cron:
            seeded.handle.trigger_cron()
        last = seeded.greenfield.get_invoice(seeded.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status} on {seeded.tag}; last={last}")


def _method(invoice: dict, method: str) -> str:
    dest = (invoice.get("checkout", {}).get("paymentMethods", {}).get(method, {}) or {}).get("destination")
    assert dest, f"invoice {invoice.get('id')} has no {method} destination: {invoice}"
    return dest


def create_ln_invoice(seeded: SeededInstall, amount_sat: int = LN_AMOUNT_SAT) -> tuple[str, str]:
    inv = seeded.greenfield.create_invoice(seeded.store_id, amount=str(amount_sat), currency="sat")
    seeded.invoice_ids.append(inv["id"])
    bolt11 = _method(inv, "BTC-LightningNetwork")
    assert bolt11.lower().startswith("lnbcrt"), f"expected a regtest BOLT11, got {bolt11}"
    return inv["id"], bolt11


def pay_ln_invoice(seeded: SeededInstall, lnd_payer: LndHandle, invoice_id: str, *, timeout_s: float = 45) -> dict:
    """Pay an invoice over Lightning and wait for Settled. The BOLT11 is
    re-read from the invoice right before paying: a build may re-quote a
    long-lived invoice on load, and the payer must honour what the server
    shows the customer now."""
    inv = seeded.greenfield.get_invoice(seeded.store_id, invoice_id)
    bolt11 = _method(inv, "BTC-LightningNetwork")
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), f"LN payment failed on {seeded.tag}: {pay}"
    assert pay.get("payment_preimage"), f"LN payment returned no preimage: {pay}"
    return _poll_status(seeded, invoice_id, "Settled", timeout_s=timeout_s, drive_cron=True)


def create_onchain_invoice(seeded: SeededInstall, amount_sat: int = ONCHAIN_AMOUNT_SAT) -> tuple[str, str]:
    inv = seeded.greenfield.create_invoice(seeded.store_id, amount=str(amount_sat), currency="sat")
    seeded.invoice_ids.append(inv["id"])
    return inv["id"], _method(inv, "BTC-OnChain")


def pay_onchain_invoice(
    seeded: SeededInstall, onchain: OnchainContext, invoice_id: str,
    amount_sat: int = ONCHAIN_AMOUNT_SAT, *, timeout_s: float = 60,
) -> dict:
    inv = seeded.greenfield.get_invoice(seeded.store_id, invoice_id)
    address = _method(inv, "BTC-OnChain")
    txid = onchain.fund_address(address, amount_sat)
    assert len(txid) == 64, txid
    onchain.confirm(1)
    return _poll_status(seeded, invoice_id, "Settled", timeout_s=timeout_s, drive_cron=True)


def pay_both_rails(seeded: SeededInstall, lnd_payer: LndHandle, onchain: OnchainContext) -> tuple[str, str]:
    """Create and pay one fresh invoice per rail; returns their ids."""
    ln_id, _ = create_ln_invoice(seeded)
    pay_ln_invoice(seeded, lnd_payer, ln_id)
    oc_id, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_id)
    return ln_id, oc_id


def wait_webhook_settled(seeded: SeededInstall, sink: WebhookSink, invoice_id: str, *, timeout_s: float = 30) -> None:
    """Webhook delivery is an async outbox drained by cron; drive it until the
    InvoiceSettled event for `invoice_id` reached the sink."""
    assert seeded.webhook_path, "seed_install() was called without a webhook sink"

    def delivered() -> bool:
        for req in sink.by_path(seeded.webhook_path):
            body = req.json()
            if body.get("type") == "InvoiceSettled" and body.get("invoiceId") == invoice_id:
                return True
        return False

    seeded.handle.drive_cron_until(delivered, timeout_s=timeout_s, label=f"InvoiceSettled webhook for {invoice_id}")


# --------------------------------------------------------------------------
# updater


def cron_key(handle: PayserverHandle) -> str:
    with handle.db() as conn:
        row = conn.execute("SELECT value FROM config WHERE key = 'cron_key'").fetchone()
    assert row, "cron_key not seeded"
    raw = row["value"]
    try:
        key = json.loads(raw)
        return key if isinstance(key, str) else raw
    except (json.JSONDecodeError, ValueError):
        return raw


def update_status(seeded: SeededInstall) -> dict:
    r = seeded.admin.s.get(f"{seeded.url}/admin.php", params={"api": "update_status"}, timeout=30)
    r.raise_for_status()
    return r.json()


def set_channel(seeded: SeededInstall, channel: str) -> None:
    resp = seeded.admin._post_action("save_update_channel", channel=channel)
    assert resp.get("success") is True and resp.get("channel") == channel, resp


def trigger_manual_update(seeded: SeededInstall, *, timeout_s: float = 300) -> dict:
    """Click "Update now" the way the dashboard does and wait for the
    manual run's terminal state. Returns the final update_status payload."""
    resp = seeded.admin._post_action("start_manual_update")
    assert resp.get("success") is True, f"start_manual_update refused on {seeded.tag}: {resp}"
    deadline = time.monotonic() + timeout_s
    status: dict = {}
    while time.monotonic() < deadline:
        status = update_status(seeded)
        run = status.get("manual_run") or {}
        if run.get("state") and run["state"] != "running":
            return status
        time.sleep(2)
    raise AssertionError(f"manual update on {seeded.tag} still running after {timeout_s}s: {status}")


def run_update_endpoint(seeded: SeededInstall, *, manual: bool = False, timeout_s: float = 300) -> dict:
    """The operator's cron line: GET update.php with the cron key. Runs the
    whole apply + health-verify in that one request and returns its JSON."""
    params = {"manual": "1"} if manual else None
    r = requests.get(
        f"{seeded.url}/update.php", params=params,
        headers={"X-CRON-KEY": cron_key(seeded.handle)}, timeout=timeout_s,
    )
    try:
        body = r.json()
    except ValueError:
        body = {"raw": r.text[:2000]}
    body["_http_status"] = r.status_code
    return body


def rollback_update(seeded: SeededInstall) -> dict:
    return seeded.admin._post_action("rollback_update")


def assert_healthy_apply(seeded: SeededInstall, status: dict, *, expect_version: str, expect_sha: str) -> None:
    """The install now runs the expected build, verified healthy, no
    rollback, with a backup to return to."""
    run = status.get("manual_run") or {}
    assert run.get("state") == "success", (
        f"manual update on {seeded.tag} ended in {run.get('state')!r}: run={run} "
        f"last_auto_rollback={status.get('last_auto_rollback')} pending={status.get('pending_verify')}"
    )
    assert status.get("pending_verify") in (None, {}), f"update still pending verify: {status.get('pending_verify')}"
    assert not status.get("last_auto_rollback"), f"an auto-rollback happened: {status.get('last_auto_rollback')}"
    assert status.get("current_sha") == expect_sha, f"BUILD_INFO sha is {status.get('current_sha')}, expected {expect_sha}"
    assert status.get("current_version") == expect_version, status.get("current_version")
    last = status.get("last_update") or {}
    assert last.get("to_sha") == expect_sha, last
    assert status.get("backups"), "no backup recorded for the applied update"
