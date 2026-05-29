"""Customer payment.php page: BOLT11 QR rendering, live status polling."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_payment_page_renders_qr_and_polls_to_settled(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    page.set_default_timeout(20000)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    page.goto(f"{configured.handle.url}/payment?id={invoice_id}")

    # The QR canvas / SVG renders into #qr-code; wait for it to populate.
    page.wait_for_function(
        "() => { const el = document.getElementById('qr-code'); return el && el.childElementCount > 0; }"
    )

    # The pending state ("Waiting for payment") should be visible.
    page.wait_for_selector("#payment-pending", state="visible")

    # Pay the invoice — the JS poller should pick it up and flip the page.
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    # Settled state shows #payment-success with class "show".
    page.wait_for_function(
        "() => { const el = document.getElementById('payment-success'); return el && el.classList.contains('show'); }",
        timeout=30000,
    )


def test_payment_page_displays_invoice_amount(
    configured: ConfiguredPayserver,
    page,
) -> None:
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="4242", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_function("() => document.body.innerText.includes('4242')")
