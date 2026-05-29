"""Auto-melt: when store balance exceeds threshold, the cron task should
issue a melt to the configured Lightning address and the receiving LND
node should observe a settled BOLT11 invoice for ~that amount."""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle
from fixtures.lnurlp_server import LnurlpServer


SETTLE_AMOUNT_SAT = 5000
AUTO_MELT_THRESHOLD_SAT = 100  # well below 5000 so the cron will trigger


def _settle_invoice(configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> None:
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(amount_sat), currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if configured.greenfield.get_invoice(configured.store_id, invoice["id"])["status"] == "Settled":
            return
        time.sleep(0.3)
    raise AssertionError("source invoice did not settle")


def _enable_auto_melt(configured: ConfiguredPayserver, address: str, threshold_sat: int) -> None:
    result = configured.admin._post_action(
        "save_auto_melt",
        store_id=configured.store_id,
        address=address,
        enabled="1",
        threshold=str(threshold_sat),
    )
    assert result.get("success"), result


def _store_balance(configured: ConfiguredPayserver) -> int:
    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    return int(r.json()["balance"])


def test_auto_melt_drains_balance_to_lightning_address(
    configured_with_lnurlp: ConfiguredPayserver,
    lnd_payer: LndHandle,
    lnurlp_server: LnurlpServer,
) -> None:
    configured = configured_with_lnurlp

    # 1. Fund the store via a paid invoice.
    _settle_invoice(configured, lnd_payer, SETTLE_AMOUNT_SAT)
    assert _store_balance(configured) >= SETTLE_AMOUNT_SAT

    payer_channel_before = lnd_payer.channel_balance_sat()

    # 2. Enable auto-melt; the LNURL mock domain doesn't have to resolve in DNS
    #    because CASHU_LNURL_URL_TEMPLATE overrides the URL builder.
    _enable_auto_melt(configured, address="test@example.test", threshold_sat=AUTO_MELT_THRESHOLD_SAT)

    # 3. Trigger cron; this fires LightningAddress::checkAutoMelt() which
    #    drains balance > threshold to the configured address.
    r = configured.handle.trigger_cron()
    assert r.status_code == 200, r.text
    # Cron may emit non-JSON lines (e.g. a Donation::send HTTP 500 warning from
    # the donation sink) before the JSON payload. Parse what we can find.
    body_text = r.text.strip()
    try:
        cron_body = r.json()
    except Exception:
        # Find the first valid JSON object in the body.
        import json as _json
        idx = body_text.find("{")
        cron_body = _json.loads(body_text[idx:]) if idx >= 0 else {}
    auto_melt_result = cron_body.get("tasks", {}).get("auto_melt")
    assert auto_melt_result and auto_melt_result != "skipped", (
        f"auto_melt task didn't run; body={body_text[:600]!r}"
    )

    # 4. Receiving LND should now have higher channel balance, and the
    #    store balance should have dropped near zero.
    payer_channel_after = lnd_payer.channel_balance_sat()
    delta = payer_channel_after - payer_channel_before
    # Allow for routing fees (1-100 sat in regtest).
    assert delta >= SETTLE_AMOUNT_SAT - 200, (
        f"expected ~{SETTLE_AMOUNT_SAT} sats delivered, got delta={delta}"
    )

    remaining = _store_balance(configured)
    # After a melt the residual change is folded back as new proofs; the
    # remainder should be small relative to the melt amount.
    assert remaining < AUTO_MELT_THRESHOLD_SAT, f"store balance not drained: {remaining}"
