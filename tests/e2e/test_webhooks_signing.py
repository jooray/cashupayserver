"""HMAC-SHA256 signature on webhook deliveries (BTCPay-compatible)."""
from __future__ import annotations

import hmac
import hashlib
import time

from conftest import ConfiguredPayserver
from fixtures.webhook_sink import WebhookSink


def _settle_invoice_via_db(configured: ConfiguredPayserver, invoice_id: str) -> None:
    """Force-settle without paying via Lightning. We're testing signing, not LN."""
    with configured.handle.db() as db:
        # Read current row to grab the data the webhook payload will need.
        row = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert row is not None


def test_webhook_signature_matches_hmac_sha256(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    # NB: the server generates its own secret and ignores the one in the
    # request body. The create response surfaces the generated secret once.
    response = configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("sign"), secret="caller-secret"
    )
    secret = response.get("secret")
    assert secret, f"expected secret in create response, got {response}"
    configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")

    captured = webhook_sink.wait_for("/hook/sign", count=1, timeout_s=15)
    body = captured[0].body
    header_lookup = {k.lower(): v for k, v in captured[0].headers.items()}
    sig_header = header_lookup.get("btcpay-sig", "")
    assert sig_header.startswith("sha256="), f"unexpected sig header: {sig_header!r}"

    expected = "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    assert hmac.compare_digest(sig_header, expected), (
        f"signature mismatch\n  got:      {sig_header}\n  expected: {expected}"
    )


def test_webhook_payload_has_btcpay_envelope(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("shape"), secret="x"
    )
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="2500", currency="sat", metadata={"orderId": "test-1"}
    )

    captured = webhook_sink.wait_for("/hook/shape", count=1, timeout_s=15)
    payload = captured[0].json()

    assert payload["type"] == "InvoiceCreated"
    assert payload["invoiceId"] == invoice["id"]
    assert payload["storeId"] == configured.store_id
    assert payload["isRedelivery"] is False
    assert "deliveryId" in payload and payload["deliveryId"].startswith("del_")
    assert "timestamp" in payload
    # InvoiceCreated must include metadata per WebhookSender::deliverWebhook.
    assert payload.get("metadata", {}).get("orderId") == "test-1", payload
    assert payload["invoice"]["id"] == invoice["id"]
    assert payload["invoice"]["amount"] == "2500"
    assert payload["invoice"]["currency"].upper() == "SAT"
