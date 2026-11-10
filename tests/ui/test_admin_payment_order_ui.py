"""Admin UI: the store-settings "Payment order" card.

The card sits directly below Store Info and holds two radios (On-chain first /
Lightning first) that instant-save stores.invoice_first_method. The customer
payment page (payment.php) then shows the chosen method's tab active and
listed first whenever an invoice offers both on-chain and Lightning; the
other method stays selectable.
"""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _store_first_method(handle, store_id: str):
    with handle.db() as conn:
        row = conn.execute(
            "SELECT invoice_first_method FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    return row[0] if row else None


def _wait_first_method(handle, store_id: str, expected, timeout_s: float = 10) -> None:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if _store_first_method(handle, store_id) == expected:
            return
        time.sleep(0.25)
    raise AssertionError(
        f"invoice_first_method did not become {expected!r}; "
        f"last={_store_first_method(handle, store_id)!r}"
    )


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _open_store_settings(page, configured: ConfiguredPayserver) -> None:
    # Pin the header selector to this test's own store — the shared server
    # auto-selects its first store, and both the instant-save and the DB
    # assertions must target the per-test store's row.
    page.select_option("#store-select", configured.store_id)
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#card-payment-order", state="visible")
    # The card hydrates from the ?api=stores fetch; wait for Store Info to be
    # populated with this store's id so the radios reflect its row.
    page.wait_for_function(
        "(id) => document.getElementById('store-settings-id').textContent.trim() === id",
        arg=configured.store_id,
    )


def _tab_order(page) -> list[str]:
    return page.eval_on_selector_all(
        ".method-tab", "tabs => tabs.map(t => t.dataset.method)"
    )


def _active_tab(page) -> str:
    return page.locator(".method-tab.active").get_attribute("data-method")


def _both_rail_invoice(configured: ConfiguredPayserver) -> str:
    """A Lightning invoice from the API with an on-chain address stamped on
    so the page offers both methods (the real xpub pipeline is covered by
    tests/e2e/test_onchain_payments.py; this test pins the page's ordering)."""
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET onchain_address = ?, onchain_amount_sat = ? WHERE id = ?",
            ("bc1qpaymentorderuitestaddr00000000000000", 1000, invoice["id"]),
        )
    return invoice["id"]


def test_payment_order_card_sits_below_store_info(
    shared_configured: ConfiguredPayserver, page
) -> None:
    _login_admin(page, shared_configured)
    _open_store_settings(page, shared_configured)

    titles = page.eval_on_selector_all(
        "#store-settings-content > .card > .card-header > .card-title",
        "els => els.map(e => e.textContent.trim())",
    )
    assert titles[:4] == ["Store Info", "Payment order", "Popular wallet tabs", "Lightning payments"], titles

    # Two radios, on-chain checked by default for a store that never saved.
    assert _store_first_method(shared_configured.handle, shared_configured.store_id) is None
    assert page.locator('input[name="invoice-first-method"]').count() == 2
    assert page.locator("#payment-order-onchain").is_checked()
    assert not page.locator("#payment-order-lightning").is_checked()


def test_payment_order_radio_instant_saves_and_reorders_payment_page(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _login_admin(page, configured)
    _open_store_settings(page, configured)
    invoice_id = _both_rail_invoice(configured)

    try:
        # Default (NULL): on-chain active and first on the payment page.
        page.goto(f"{configured.handle.url}/payment?id={invoice_id}")
        page.wait_for_selector(".method-tabs", state="visible")
        assert _tab_order(page) == ["onchain", "lightning"]
        assert _active_tab(page) == "onchain"
        assert page.locator('[data-method-block="onchain"]').is_visible()
        assert page.locator('[data-method-block="lightning"]').is_hidden()

        # Pick "Lightning first" -> instant save -> column flips.
        page.goto(f"{configured.handle.url}/admin")
        page.wait_for_selector("#app", state="visible")
        _open_store_settings(page, configured)
        page.check("#payment-order-lightning")
        _wait_first_method(configured.handle, configured.store_id, "lightning")
        assert page.locator("#payment-order-error").is_hidden()

        # Survives a reload: the radio hydrates from the stored row.
        page.reload()
        page.wait_for_selector("#app", state="visible")
        _open_store_settings(page, configured)
        assert page.locator("#payment-order-lightning").is_checked()
        assert not page.locator("#payment-order-onchain").is_checked()

        # Payment page now leads with Lightning; on-chain still one click away.
        page.goto(f"{configured.handle.url}/payment?id={invoice_id}")
        page.wait_for_selector(".method-tabs", state="visible")
        assert _tab_order(page) == ["lightning", "onchain"]
        assert _active_tab(page) == "lightning"
        assert page.locator('[data-method-block="lightning"]').is_visible()
        assert page.locator('[data-method-block="onchain"]').is_hidden()
        page.click('.method-tab[data-method="onchain"]')
        assert page.locator('[data-method-block="onchain"]').is_visible()
        assert page.locator('[data-method-block="lightning"]').is_hidden()

        # Back to "On-chain first" -> explicit 'onchain' persisted.
        page.goto(f"{configured.handle.url}/admin")
        page.wait_for_selector("#app", state="visible")
        _open_store_settings(page, configured)
        page.check("#payment-order-onchain")
        _wait_first_method(configured.handle, configured.store_id, "onchain")

        page.goto(f"{configured.handle.url}/payment?id={invoice_id}")
        page.wait_for_selector(".method-tabs", state="visible")
        assert _tab_order(page) == ["onchain", "lightning"]
        assert _active_tab(page) == "onchain"
    finally:
        # Restore the pristine row so the shared session store doesn't leak
        # state into other modules.
        with configured.handle.db() as conn:
            conn.execute(
                "UPDATE stores SET invoice_first_method = NULL WHERE id = ?",
                (configured.store_id,),
            )


def test_payment_order_refused_save_reverts_radio(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """A save the server refuses must not leave the UI claiming a preference
    that was never persisted: the radio reverts and the inline error shows."""
    configured = shared_configured
    _login_admin(page, configured)
    _open_store_settings(page, configured)

    # Make the next save fail by routing it to a store id that doesn't exist.
    page.evaluate("() => { window.__poRealStore = currentStoreId; currentStoreId = 'store_does_not_exist'; }")
    try:
        page.check("#payment-order-lightning")
        page.wait_for_selector("#payment-order-error", state="visible")
        assert "Store not found" in page.locator("#payment-order-error").inner_text()
        assert page.locator("#payment-order-onchain").is_checked()
        assert not page.locator("#payment-order-lightning").is_checked()
        assert _store_first_method(configured.handle, configured.store_id) is None
    finally:
        page.evaluate("() => { currentStoreId = window.__poRealStore; }")
