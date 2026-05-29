"""Admin dashboard UI: login, balance display, store selector."""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_password_login_loads_dashboard(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")

    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")

    # The SPA flips visibility classes; #app should become displayed and
    # #store-select populated.
    page.wait_for_selector("#app", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#store-select') && document.querySelector('#store-select').options.length > 0"
    )


def test_dashboard_shows_balance_after_settle(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    # Settle 2500 sats first so there's a visible balance.
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="2500", currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        if configured.greenfield.get_invoice(configured.store_id, invoice["id"])["status"] == "Settled":
            break
        time.sleep(0.3)

    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # The balance card eventually renders "2500" once the SPA finishes the
    # dashboard fetch.
    page.wait_for_function(
        "() => document.body.innerText.includes('2500')",
        timeout=15000,
    )
