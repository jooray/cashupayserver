"""Webhook delivery semantics: event filtering, multiple subscribers, retry."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.webhook_sink import WebhookSink


def test_multiple_webhooks_each_receive_event(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("alpha"), secret="a"
    )
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("beta"), secret="b"
    )

    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    alpha = webhook_sink.wait_for("/hook/alpha", count=1, timeout_s=15)
    beta = webhook_sink.wait_for("/hook/beta", count=1, timeout_s=15)

    assert alpha[0].json()["type"] == "InvoiceCreated"
    assert beta[0].json()["type"] == "InvoiceCreated"


def test_event_filter_only_delivers_subscribed_events(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """A webhook subscribed only to InvoiceSettled should NOT receive InvoiceCreated."""
    configured.greenfield.create_webhook(
        configured.store_id,
        webhook_sink.endpoint("settled-only"),
        secret="x",
        authorized_events={"specificEvents": ["InvoiceSettled"]},
    )

    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    # Brief wait — if the filter is wrong, an InvoiceCreated webhook would land.
    import time
    time.sleep(2)
    captured = webhook_sink.by_path("/hook/settled-only")
    types = [r.json().get("type") for r in captured]
    assert "InvoiceCreated" not in types, f"filter not honored, saw: {types}"


def test_webhook_delivery_logged_in_database(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """Each delivery attempt should create a row in webhook_deliveries."""
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("logged"), secret="x"
    )
    invoice = configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    webhook_sink.wait_for("/hook/logged", count=1, timeout_s=15)

    with configured.handle.db() as db:
        deliveries = db.execute(
            "SELECT event_type, status_code, invoice_id FROM webhook_deliveries WHERE invoice_id = ?",
            (invoice["id"],),
        ).fetchall()
    assert len(deliveries) >= 1, "no delivery row written"
    assert any(d["event_type"] == "InvoiceCreated" for d in deliveries)
    assert all(200 <= d["status_code"] < 300 for d in deliveries), [dict(d) for d in deliveries]


@pytest.mark.xfail(
    reason="WebhookSender::deliverWebhook does not actually retry on 5xx — "
    "MAX_RETRIES is declared but never used (see includes/webhook_sender.php:11). "
    "Flip to passing once retry is implemented.",
    strict=False,
)
def test_webhook_retried_on_5xx_response(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """A 5xx response from the sink should cause cashupayserver to retry."""
    webhook_sink.force_response("/hook/retry", 503)
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("retry"), secret="x"
    )
    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    captured = webhook_sink.wait_for("/hook/retry", count=2, timeout_s=20)
    assert len(captured) >= 2, "expected at least one retry"
