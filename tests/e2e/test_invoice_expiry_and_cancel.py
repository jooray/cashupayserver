"""Invoice expiry sweep and admin-initiated cancel/invalidate."""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.webhook_sink import WebhookSink


def _wait_status(configured: ConfiguredPayserver, invoice_id: str, expected: str, timeout_s: float = 10.0) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        last = configured.greenfield.get_invoice(configured.store_id, invoice_id)
        if last.get("status") == expected:
            return last
        time.sleep(0.2)
    raise AssertionError(f"invoice {invoice_id} did not reach {expected}; last={last}")


@pytest.mark.xfail(
    reason="Invoice::markExpiredInvoices() bulk-updates without firing "
    "InvoiceExpired webhooks (see includes/invoice.php:188). The test asserts "
    "the contract; flip to passing once the source is fixed.",
    strict=False,
)
def test_unpaid_invoice_expires_via_cron(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """Expire an invoice by backdating expiration_time, then run the cron sweep."""
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("expire"), secret="x"
    )
    invoice = configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")
    invoice_id = invoice["id"]
    assert invoice["status"] in ("New", "Processing")

    # Backdate so the cron sweep marks it expired.
    with configured.handle.db() as db:
        db.execute(
            "UPDATE invoices SET expiration_time = ? WHERE id = ?",
            (int(time.time()) - 60, invoice_id),
        )

    r = configured.handle.trigger_cron()
    assert r.status_code == 200, r.text

    expired = _wait_status(configured, invoice_id, "Expired", timeout_s=10)
    assert expired["status"] == "Expired"

    captured = webhook_sink.wait_for("/hook/expire", count=1, timeout_s=15)
    types = {r.json().get("type") for r in captured}
    assert "InvoiceExpired" in types, f"missing InvoiceExpired; saw {types}"


def test_admin_can_cancel_new_invoice(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("invalid"), secret="x"
    )
    invoice = configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")
    invoice_id = invoice["id"]

    result = configured.greenfield.mark_invoice_status(configured.store_id, invoice_id, "Invalid")
    assert result["status"] == "Invalid"

    fetched = configured.greenfield.get_invoice(configured.store_id, invoice_id)
    assert fetched["status"] == "Invalid"

    captured = webhook_sink.wait_for("/hook/invalid", count=1, timeout_s=15)
    types = {r.json().get("type") for r in captured}
    assert "InvoiceInvalid" in types, f"missing InvoiceInvalid; saw {types}"


def test_cannot_invalidate_already_settled_invoice(configured: ConfiguredPayserver) -> None:
    """The API only allows Invalid for New/Processing invoices."""
    invoice = configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")
    invoice_id = invoice["id"]

    # Simulate Settled via direct DB write so we don't need a real payment.
    with configured.handle.db() as db:
        db.execute("UPDATE invoices SET status = 'Settled' WHERE id = ?", (invoice_id,))

    with pytest.raises(RuntimeError, match="validation-error"):
        configured.greenfield.mark_invoice_status(configured.store_id, invoice_id, "Invalid")
