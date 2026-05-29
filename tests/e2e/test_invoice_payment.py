"""Happy path: create invoice, pay it via Lightning, observe settlement + webhook.

The first vertical-slice test — exercises the whole stack:
bitcoind -> two LND nodes -> nutshell mint -> cashupayserver -> webhook sink.
"""
from __future__ import annotations

import time

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle
from fixtures.webhook_sink import WebhookSink

INVOICE_AMOUNT_SAT = "1000"


def _poll_invoice_until(configured: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        last = configured.greenfield.get_invoice(configured.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} did not reach {status} within {timeout_s}s; last={last}")


def test_invoice_payment_settles_end_to_end(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
) -> None:
    gc = configured.greenfield

    # Register a webhook so we can observe state changes.
    webhook_url = webhook_sink.endpoint("settle")
    gc.create_webhook(configured.store_id, webhook_url, secret="wh-secret-e2e")

    # Create an invoice in sats — bypasses the fiat rate fetch.
    invoice = gc.create_invoice(configured.store_id, amount=INVOICE_AMOUNT_SAT, currency="sat")
    invoice_id = invoice["id"]
    assert invoice["status"] in ("New", "Processing"), invoice

    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), f"expected regtest BOLT11, got {bolt11}"

    # Pay from the customer-side LND.
    pay_result = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay_result.get("payment_error"), f"payment failed: {pay_result}"
    assert pay_result.get("payment_preimage"), f"missing preimage: {pay_result}"

    settled = _poll_invoice_until(configured, invoice_id, "Settled", timeout_s=30)
    assert settled["status"] == "Settled"

    captured = webhook_sink.wait_for("/hook/settle", count=1, timeout_s=15)
    delivered_types = {r.json().get("type") for r in captured}
    assert "InvoiceSettled" in delivered_types, f"missing InvoiceSettled in {delivered_types}"
