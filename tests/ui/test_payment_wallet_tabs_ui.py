"""Customer payment page: the per-store "Popular wallet tabs" (WalletTabs).

A store that opted in gets Cash App / Venmo / PayPal tabs after the native
ones. Each tab repeats a native QR (Cash App: Lightning when offered, else
on-chain; Venmo / PayPal: on-chain only) under the app's note, with the same
button row. On a phone the wallet tabs sit on their own strip under the
native strip so all five stay fully visible without horizontal scrolling.
The brand logos in the bottom row switch to their tab. The admin card
instant-saves the set, and the wizard screen writes the ticked wallets.
"""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import browser_walk_to_store

pytestmark = pytest.mark.ui

DESKTOP = {"width": 1280, "height": 900}
PHONE = {"width": 390, "height": 844}
NARROW_PHONE = {"width": 360, "height": 740}

CASHAPP_NOTE = 'Go to the QR code icon in Cash App or tap "Pay with Cash App".'
VENMO_NOTE_START = "In Venmo, go to Crypto → Bitcoin → Transfer (arrow icons) → Send."
PAYPAL_NOTE = "In PayPal app, go to Finances → Crypto → Transfer arrows."


def _set_wallet_tabs(handle, store_id: str, value) -> None:
    with handle.db() as conn:
        conn.execute("UPDATE stores SET wallet_tabs = ? WHERE id = ?", (value, store_id))


def _wallet_tabs(handle, store_id: str):
    with handle.db() as conn:
        row = conn.execute("SELECT wallet_tabs FROM stores WHERE id = ?", (store_id,)).fetchone()
    return row[0] if row else None


def _wait_wallet_tabs(handle, store_id: str, expected, timeout_s: float = 10) -> None:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if _wallet_tabs(handle, store_id) == expected:
            return
        time.sleep(0.25)
    raise AssertionError(
        f"wallet_tabs did not become {expected!r}; last={_wallet_tabs(handle, store_id)!r}"
    )


def _both_rail_invoice(configured: ConfiguredPayserver) -> str:
    """A Lightning invoice with an on-chain address stamped on so the page
    offers both rails (the real xpub pipeline is covered elsewhere)."""
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET onchain_address = ?, onchain_amount_sat = ? WHERE id = ?",
            ("bc1qwallettabsuitestaddr000000000000000000", 1000, invoice["id"]),
        )
    return invoice["id"]


def _tab_order(page) -> list[str]:
    return page.eval_on_selector_all(".method-tab", "tabs => tabs.map(t => t.dataset.method)")


def _active_tab(page) -> str:
    return page.locator(".method-tab.active").get_attribute("data-method")


def _open_payment(page, configured: ConfiguredPayserver, invoice_id: str, viewport: dict) -> None:
    page.set_viewport_size(viewport)
    page.goto(f"{configured.handle.url}/payment?id={invoice_id}")
    page.wait_for_selector(".method-tabs", state="visible")
    page.wait_for_function("() => document.querySelector('#qr-onchain canvas') !== null")


def _assert_tabs_fit(page) -> None:
    """Every tab is fully inside the viewport, no page-level horizontal
    scroll, and each tab's label is visible (not clipped to zero width)."""
    vw = page.evaluate("() => document.documentElement.clientWidth")
    overflowing = page.evaluate(
        """() => {
            const vw = document.documentElement.clientWidth;
            const out = [];
            document.querySelectorAll('body *').forEach(el => {
                const r = el.getBoundingClientRect();
                if (r.width > 0 && r.right > vw + 1) {
                    out.push(el.tagName + '#' + el.id + '.' + String(el.className).slice(0, 40)
                        + ' ' + Math.round(r.left) + '..' + Math.round(r.right));
                }
            });
            return [document.documentElement.scrollWidth, vw, out.slice(0, 8)];
        }"""
    )
    assert overflowing[0] <= overflowing[1], f"the payment page must not scroll sideways: {overflowing}"
    boxes = page.eval_on_selector_all(
        ".method-tab",
        "tabs => tabs.map(t => { const b = t.getBoundingClientRect(); return [t.dataset.method, b.left, b.right, b.width, b.height]; })",
    )
    assert boxes, "tabs rendered"
    for method, left, right, width, height in boxes:
        assert left >= 0 and right <= vw + 0.5, f"tab {method} overflows the viewport: {left}..{right} of {vw}"
        assert width >= 60 and height >= 30, f"tab {method} is squashed: {width}x{height}"
    for lbl in page.locator(".method-tab-wallet .method-tab-label").all():
        assert lbl.is_visible()
        assert lbl.evaluate("el => el.scrollWidth <= el.clientWidth + 1"), "wallet tab label clipped"
    for logo in page.locator(".method-tab-wallet .method-tab-logo").all():
        box = logo.bounding_box()
        assert box and box["height"] >= 20, "wallet tab logo rendered"


def test_wallet_tabs_render_switch_and_fit_on_desktop_and_phone(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    page.set_default_timeout(15000)
    _set_wallet_tabs(configured.handle, configured.store_id, "cashapp,venmo,paypal")
    try:
        invoice_id = _both_rail_invoice(configured)
        bolt11 = page.evaluate("() => null")  # placeholder so the name exists below

        for viewport in (DESKTOP, PHONE, NARROW_PHONE):
            _open_payment(page, configured, invoice_id, viewport)
            assert _tab_order(page) == ["onchain", "lightning", "cashapp", "venmo", "paypal"]
            assert _active_tab(page) == "onchain", "a wallet tab is never the default"
            # Five tabs: the wallet tabs sit on their own strip under the native one.
            assert page.locator(".method-tabs-wallets").count() == 1
            native_bottom = page.locator(".method-tabs").first.bounding_box()
            wallet_top = page.locator(".method-tabs-wallets").bounding_box()
            assert wallet_top["y"] >= native_bottom["y"] + native_bottom["height"] - 1, (
                "the wallet strip renders below the native strip"
            )
            _assert_tabs_fit(page)
            for key in ("cashapp", "venmo", "paypal"):
                assert page.locator(f'[data-method-block="{key}"]').is_hidden(), f"{key} block starts hidden"

            # Cash App: Lightning QR, its own note and button label; the brand
            # row keys off Lightning but keeps the Venmo/PayPal logos (they
            # have tabs now).
            page.click('.method-tab[data-method="cashapp"]')
            assert _active_tab(page) == "cashapp"
            block = page.locator('[data-method-block="cashapp"]')
            assert block.is_visible()
            assert page.locator('[data-method-block="onchain"]').is_hidden()
            page.wait_for_function("() => document.querySelector('#qr-cashapp canvas') !== null")
            assert block.locator(".wallet-note").inner_text().strip() == CASHAPP_NOTE
            open_link = block.locator("a.btn")
            assert open_link.inner_text().strip() == "Pay with Cash App"
            assert open_link.get_attribute("href").startswith("lightning:lnbc")
            assert block.locator("button.btn", has_text="Copy Invoice").count() == 1
            assert page.get_attribute("#payment-methods", "data-method") == "lightning"
            assert page.locator('.pm-item[data-tab="venmo"]').is_visible(), "Venmo logo stays on the Lightning-keyed view"
            assert page.locator('.pm-item[data-tab="paypal"]').is_visible()
            # The note sits above the QR on the tab.
            note_box = block.locator(".wallet-note").bounding_box()
            qr_box = block.locator("#qr-cashapp").bounding_box()
            assert note_box["y"] + note_box["height"] <= qr_box["y"] + 1
            assert note_box["x"] >= 0 and note_box["x"] + note_box["width"] <= viewport["width"] + 0.5

            # Venmo via its brand-row logo: on-chain QR, generic button.
            page.click('.pm-item[data-tab="venmo"]')
            assert _active_tab(page) == "venmo"
            block = page.locator('[data-method-block="venmo"]')
            assert block.is_visible()
            assert page.locator('[data-method-block="cashapp"]').is_hidden()
            page.wait_for_function("() => document.querySelector('#qr-venmo canvas') !== null")
            assert block.locator(".wallet-note").inner_text().strip().startswith(VENMO_NOTE_START)
            assert "Venmo charges additional fees" in block.locator(".wallet-note").inner_text()
            assert block.locator("a.btn").inner_text().strip() == "Pay with BTC wallet"
            assert block.locator("a.btn").get_attribute("href").startswith("bitcoin:bc1qwallettabsuitestaddr")
            assert block.locator("button.btn", has_text="Copy Address").count() == 1
            assert page.get_attribute("#payment-methods", "data-method") == "onchain"

            # PayPal via its tab.
            page.click('.method-tab[data-method="paypal"]')
            block = page.locator('[data-method-block="paypal"]')
            assert block.is_visible()
            page.wait_for_function("() => document.querySelector('#qr-paypal canvas') !== null")
            assert block.locator(".wallet-note").inner_text().strip() == PAYPAL_NOTE
            assert block.locator("a.btn").inner_text().strip() == "Pay with BTC wallet"

            # Back to a native tab: wallet blocks hide again, brand links return.
            page.click('.method-tab[data-method="lightning"]')
            assert page.locator('[data-method-block="lightning"]').is_visible()
            assert page.locator('[data-method-block="paypal"]').is_hidden()
            assert page.locator('.pm-item[aria-label="Pay with BTC wallet (Strike)"]').get_attribute("href").startswith("lightning:")
    finally:
        _set_wallet_tabs(configured.handle, configured.store_id, None)


def test_wallet_tabs_drop_out_without_their_source_rail(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """Lightning-only invoice: Venmo and PayPal have nothing to scan and are
    not rendered; Cash App stays on Lightning. Three tabs share one strip."""
    configured = shared_configured
    page.set_default_timeout(15000)
    _set_wallet_tabs(configured.handle, configured.store_id, "cashapp,venmo,paypal")
    try:
        invoice = configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")
        for viewport in (DESKTOP, PHONE):
            page.set_viewport_size(viewport)
            page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
            page.wait_for_selector(".method-tabs", state="visible")
            assert _tab_order(page) == ["lightning", "cashapp"]
            assert page.locator(".method-tabs-wallets").count() == 0, "two tabs share the native strip"
            _assert_tabs_fit(page)
            # Venmo/PayPal logos keep hiding on the Lightning view (no tab).
            assert page.locator('.pm-item[data-tab="venmo"]').count() == 0
            assert page.locator(".pm-item.pm-no-lightning").first.is_hidden()
    finally:
        _set_wallet_tabs(configured.handle, configured.store_id, None)


def test_store_that_never_opted_in_renders_no_wallet_tabs(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    page.set_default_timeout(15000)
    assert _wallet_tabs(configured.handle, configured.store_id) in (None, ""), "fixture stores opt out"
    invoice_id = _both_rail_invoice(configured)
    _open_payment(page, configured, invoice_id, DESKTOP)
    assert _tab_order(page) == ["onchain", "lightning"]
    assert page.locator(".method-tab-wallet").count() == 0
    assert page.locator(".pm-item[data-tab]").count() == 0


# ---------------------------------------------------------------- admin card


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _open_store_settings(page, configured: ConfiguredPayserver) -> None:
    page.select_option("#store-select", configured.store_id)
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#card-wallet-tabs", state="visible")
    page.wait_for_function(
        "(id) => document.getElementById('store-settings-id').textContent.trim() === id",
        arg=configured.store_id,
    )


def _checked(page) -> list[str]:
    return page.eval_on_selector_all(
        ".wallet-tab-toggle", "els => els.filter(e => e.checked).map(e => e.dataset.wallet)"
    )


def test_admin_wallet_tabs_card_instant_saves_and_drives_the_payment_page(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _login_admin(page, configured)
    _open_store_settings(page, configured)
    invoice_id = _both_rail_invoice(configured)
    try:
        # Card sits under Payment order; nothing ticked for a NULL row.
        titles = page.eval_on_selector_all(
            "#store-settings-content > .card > .card-header > .card-title",
            "els => els.map(e => e.textContent.trim())",
        )
        assert titles.index("Popular wallet tabs") == titles.index("Payment order") + 1, titles
        assert page.locator(".wallet-tab-toggle").count() == 3
        assert _checked(page) == []

        page.locator("#wallet-tab-cashapp + .toggle-slider").click()
        _wait_wallet_tabs(configured.handle, configured.store_id, "cashapp")
        page.locator("#wallet-tab-paypal + .toggle-slider").click()
        _wait_wallet_tabs(configured.handle, configured.store_id, "cashapp,paypal")
        assert page.locator("#wallet-tabs-error").is_hidden()

        # Survives a reload: the toggles hydrate from the stored row.
        page.reload()
        page.wait_for_selector("#app", state="visible")
        _open_store_settings(page, configured)
        assert _checked(page) == ["cashapp", "paypal"]

        # Payment page shows exactly those two tabs.
        _open_payment(page, configured, invoice_id, PHONE)
        assert _tab_order(page) == ["onchain", "lightning", "cashapp", "paypal"]
        _assert_tabs_fit(page)

        # Untick one -> explicit smaller set; untick all -> '' (none).
        page.goto(f"{configured.handle.url}/admin")
        page.wait_for_selector("#app", state="visible")
        _open_store_settings(page, configured)
        page.locator("#wallet-tab-cashapp + .toggle-slider").click()
        _wait_wallet_tabs(configured.handle, configured.store_id, "paypal")
        page.locator("#wallet-tab-paypal + .toggle-slider").click()
        _wait_wallet_tabs(configured.handle, configured.store_id, "")

        _open_payment(page, configured, invoice_id, DESKTOP)
        assert _tab_order(page) == ["onchain", "lightning"]
    finally:
        _set_wallet_tabs(configured.handle, configured.store_id, None)


# ------------------------------------------------------------- wizard screen


def test_wizard_screen_writes_the_ticked_wallets(payserver: PayserverHandle, page) -> None:
    """First run: the screen follows mints with all three pre-ticked; unticking
    Venmo and continuing stores the other two, and the phone layout keeps the
    rows and both buttons inside the viewport."""
    page.set_default_timeout(30000)
    page.set_viewport_size(PHONE)
    browser_walk_to_store(page, payserver.url, "wizard-tabs-pw", store_name="Tabs UI Store")
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("button:has-text('No thanks, run without mints')")
    page.click("button:has-text('No thanks, run without mints')")

    page.wait_for_selector("h2:has-text('Popular wallet tabs')")
    assert "Enable additional tabs on the payment page for these popular wallets?" in page.locator("body").inner_text()
    for key in ("cashapp", "venmo", "paypal"):
        assert page.locator(f"#wallet_tab_{key}").is_checked(), f"{key} pre-ticked"
        row = page.locator(f'label[for="wallet_tab_{key}"]').bounding_box()
        assert row["x"] >= 0 and row["x"] + row["width"] <= PHONE["width"] + 0.5, f"{key} row fits the phone"
    assert page.evaluate("() => document.documentElement.scrollWidth <= document.documentElement.clientWidth")
    page.uncheck("#wallet_tab_venmo")
    page.click("button[name='wallet_tabs_action'][value='save']")
    page.wait_for_selector("h2:has-text('Enable automatic updates?')")

    with payserver.db() as conn:
        row = conn.execute("SELECT wallet_tabs FROM stores WHERE name = 'Tabs UI Store'").fetchone()
    assert row[0] == "cashapp,paypal"
