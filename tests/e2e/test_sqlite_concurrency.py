"""Many invoices settling at once on one SQLite database.

The claim under test: concurrent payments are safe on SQLite. Eight invoices
are paid at the same moment over Lightning while eight real payment pages
poll them from the browser, six API pollers hammer every invoice, and cron
ticks in a loop — the heaviest write contention a single store can see. The
server runs 8 PHP workers so those requests genuinely overlap.

Exactly-once is the property: every invoice ends Settled, every page shows
the paid state, every invoice fires InvoiceSettled exactly once (the
status-guarded compare-and-set in Invoice::checkPayment is what prevents a
second poller from re-firing it), no poller ever saw an error, and the lock
retry (Database::retryOnBusy) never ran out.
"""
from __future__ import annotations

import threading
import time
import uuid
from collections.abc import Iterator
from concurrent.futures import ThreadPoolExecutor

import pytest
import requests

from conftest import SESSION_TMP, ConfiguredPayserver, _configure, _maybe_remove_workdir, _module_went_green
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.payserver import start_payserver, stop_payserver
from fixtures.webhook_sink import WebhookSink

INVOICES = 8
POLLERS = 6
INVOICE_AMOUNT_SAT = "1000"
WORKERS = 8


@pytest.fixture(scope="module")
def busy_server(request, mint: MintHandle, backup_mint: MintHandle) -> Iterator[ConfiguredPayserver]:
    """A configured payserver with enough PHP workers for requests to overlap.

    The shared module server runs php -S's single worker, which serialises
    every request and would hide the very contention this module exists to
    exercise."""
    workdir = SESSION_TMP / f"payserver-concurrency-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env={"PHP_CLI_SERVER_WORKERS": str(WORKERS)})
    try:
        cfg = _configure(handle, mint, backup_mint)
    except Exception:
        stop_payserver(handle)
        raise
    yield cfg
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _module_went_green(request))


def _bolt11(invoice: dict) -> str:
    dest = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert dest and dest.lower().startswith("lnbcrt"), f"expected regtest BOLT11, got {dest}"
    return dest


def test_eight_invoices_settle_exactly_once_under_contention(
    busy_server: ConfiguredPayserver,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
    browser,
) -> None:
    cfg = busy_server
    gc = cfg.greenfield
    gc.create_webhook(cfg.store_id, webhook_sink.endpoint("concurrency"), secret="wh-secret-conc")

    invoices = [gc.create_invoice(cfg.store_id, amount=INVOICE_AMOUNT_SAT, currency="sat") for _ in range(INVOICES)]
    ids = [inv["id"] for inv in invoices]
    bolt11s = [_bolt11(inv) for inv in invoices]

    # One real payment page per invoice: these are the production pollers.
    ctx = browser.new_context()
    pages = []
    try:
        for inv_id in ids:
            page = ctx.new_page()
            page.goto(f"{cfg.handle.url}/payment.php?id={inv_id}", wait_until="domcontentloaded")
            pages.append(page)

        stop = threading.Event()
        errors: list[str] = []
        lock = threading.Lock()

        def record(err: str) -> None:
            with lock:
                errors.append(err)

        def status_url(inv_id: str) -> str:
            # The payment page's own poll: it runs Invoice::pollSingleQuote,
            # i.e. the real settlement check, on every request — exactly what
            # eight open checkout tabs do every 2 s. (The Greenfield API
            # would do too, but it is rate-limited per IP at 100/min, and all
            # the pollers here share one address.)
            return f"{cfg.handle.url}/payment.php?id={inv_id}&json=1"

        def poller() -> None:
            session = requests.Session()  # one per thread: Session is not thread-safe
            while not stop.is_set():
                for inv_id in ids:
                    try:
                        r = session.get(status_url(inv_id), timeout=30)
                        if r.status_code != 200:
                            record(f"poller: HTTP {r.status_code} {r.text[:200]}")
                    except Exception as e:  # noqa: BLE001 — every failure is a finding
                        record(f"poller: {e!r}")
                time.sleep(0.05)

        def cron_hammer() -> None:
            while not stop.is_set():
                try:
                    r = cfg.handle.trigger_cron()
                    if r.status_code >= 500:
                        record(f"cron: HTTP {r.status_code} {r.text[:200]}")
                except Exception as e:  # noqa: BLE001
                    record(f"cron: {e!r}")
                time.sleep(0.3)

        threads = [threading.Thread(target=poller, daemon=True) for _ in range(POLLERS)]
        threads.append(threading.Thread(target=cron_hammer, daemon=True))
        for t in threads:
            t.start()
        time.sleep(1.0)  # let the pollers reach steady state before the burst

        # Pay all eight at once.
        with ThreadPoolExecutor(max_workers=INVOICES) as ex:
            results = list(ex.map(lambda b: lnd_payer.pay_invoice_sync(b, timeout=60), bolt11s))
        for res in results:
            assert not res.get("payment_error"), f"payment failed: {res}"
            assert res.get("payment_preimage"), f"missing preimage: {res}"

        # Every invoice reaches Settled.
        deadline = time.monotonic() + 90
        pending = set(ids)
        last: dict[str, dict] = {}
        while pending and time.monotonic() < deadline:
            for inv_id in list(pending):
                inv = requests.get(status_url(inv_id), timeout=30).json()
                last[inv_id] = inv
                if inv.get("status") == "Settled":
                    pending.discard(inv_id)
            time.sleep(0.5)
        assert not pending, f"invoices never settled: {[last[i] for i in pending]}"

        # Every payment page shows the paid state on its own.
        for page, inv_id in zip(pages, ids):
            page.wait_for_selector("#payment-success.show", state="visible", timeout=30000)

        stop.set()
        for t in threads:
            t.join(timeout=30)
        assert errors == [], f"{len(errors)} poller/cron errors under contention: {errors[:10]}"

        # Exactly one InvoiceSettled per invoice. Wait for the last one to be
        # delivered (async outbox), then hold a little longer so a duplicate
        # fired by a racing poller would have had time to arrive too.
        def settled_counts() -> dict[str, int]:
            counts = {inv_id: 0 for inv_id in ids}
            for req in webhook_sink.by_path("/hook/concurrency"):
                body = req.json()
                if body.get("type") == "InvoiceSettled" and body.get("invoiceId") in counts:
                    counts[body["invoiceId"]] += 1
            return counts

        deadline = time.monotonic() + 60
        while time.monotonic() < deadline and any(c == 0 for c in settled_counts().values()):
            cfg.handle.trigger_cron()
            time.sleep(0.5)
        time.sleep(5)
        cfg.handle.trigger_cron()
        time.sleep(2)
        counts = settled_counts()
        assert all(c == 1 for c in counts.values()), f"InvoiceSettled must fire exactly once per invoice: {counts}"
    finally:
        ctx.close()

    # The retry wrapper may have kicked in (that is fine, and logged), but it
    # must never have run out: that line means a request failed on a lock.
    log = cfg.handle.workdir / "php-server.log"
    if log.exists():
        text = log.read_text(errors="replace")
        assert "SQLite still busy after" not in text, "a request exhausted the lock retries"
        assert "database is locked" not in text, "an unhandled lock error reached the log"
