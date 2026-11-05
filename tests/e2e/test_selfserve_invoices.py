"""Browser + HTTP e2e for self-serve invoices (the public /pay/{storeId} page).

Covers the customer-facing surface:
  - When self-serve is disabled (the default) the page 404s, so store IDs and
    the feature itself aren't discoverable.
  - When enabled for the store, the form renders and a customer can create +
    be redirected to a real, payable invoice.
  - Untrusted input is enforced: an amount above the per-store maximum is
    rejected with a clear error instead of creating an oversized invoice.

Self-serve is store-only: the enable toggle + max are seeded directly on the
stores row (the same columns SelfServe writes) to keep the test focused on the
public page rather than the admin UI; the resolution + validation logic itself
is unit-tested in tests/php/test_selfserve_resolution.php and
test_selfserve_validation.php.
"""
from __future__ import annotations

import json
import time

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.browser import expand_settings_cards


def _enable_store_selfserve(shared_configured: ConfiguredPayserver, enabled: bool = True) -> None:
    with shared_configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            (1 if enabled else 0, shared_configured.store_id),
        )


def _set_store_max(shared_configured: ConfiguredPayserver, max_sats) -> None:
    with shared_configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_max_sats = ? WHERE id = ?",
            (max_sats, shared_configured.store_id),
        )


def _set_store_currency(shared_configured: ConfiguredPayserver, currency: str) -> None:
    with shared_configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET default_currency = ? WHERE id = ?",
            (currency, shared_configured.store_id),
        )


def test_pay_page_404_when_disabled(shared_configured: ConfiguredPayserver) -> None:
    # Disabled by default → generic 404 (no leak of the store or the feature).
    r = requests.get(
        f"{shared_configured.handle.url}/pay/{shared_configured.store_id}", timeout=15, allow_redirects=False
    )
    assert r.status_code == 404, r.text


def test_pay_page_404_unknown_store(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    r = requests.get(
        f"{shared_configured.handle.url}/pay/store_does_not_exist", timeout=15, allow_redirects=False
    )
    assert r.status_code == 404, r.text


def test_pay_page_renders_when_enabled(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    r = requests.get(f"{shared_configured.handle.url}/pay/{shared_configured.store_id}", timeout=15)
    assert r.status_code == 200, r.text
    assert "Continue to payment" in r.text
    # Sat-only store: the max hint is shown.
    assert "Maximum" in r.text


def test_pay_page_defaults_to_store_currency(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    # A fiat store offers [sat, USD]; the selector must pre-select the store's
    # default display currency (USD), not sat.
    _enable_store_selfserve(shared_configured)
    _set_store_currency(shared_configured, "USD")
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(
            f"{shared_configured.handle.url}/pay/{shared_configured.store_id}", wait_until="networkidle"
        )
        # The currency <select> is only rendered for fiat stores.
        assert page.locator("#currency").count() == 1, "fiat store should show a currency selector"
        assert page.eval_on_selector("#currency", "el => el.value") == "USD"
    finally:
        ctx.close()
        _set_store_currency(shared_configured, "sat")


def test_over_max_amount_rejected(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    _set_store_max(shared_configured, 1000)
    # Post an amount above the per-store cap; expect the form back with an error
    # and NO redirect to a created invoice.
    r = requests.post(
        f"{shared_configured.handle.url}/pay/{shared_configured.store_id}",
        data={"amount": "5000", "currency": "sat", "notes": ""},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 200, r.text
    assert "exceeds the maximum" in r.text.lower() or "maximum" in r.text.lower()
    _set_store_max(shared_configured, None)


def test_create_and_redirect_to_payment(shared_configured: ConfiguredPayserver, browser) -> None:
    _enable_store_selfserve(shared_configured)
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(
            f"{shared_configured.handle.url}/pay/{shared_configured.store_id}", wait_until="networkidle"
        )
        page.fill("#amount", "1500")
        page.fill("#notes", "e2e self-serve test")
        page.click("button[type=submit]")
        # Should land on the regular payment display page for the new invoice.
        page.wait_for_url("**/payment.php?id=*", timeout=15000)
        assert "payment.php?id=" in page.url
    finally:
        ctx.close()

    # The invoice exists, belongs to this store, is unpaid, and carries the note.
    # Filter by store_id: on the shared server other tests' invoices coexist.
    with shared_configured.handle.db() as db:
        row = db.execute(
            "SELECT store_id, status, amount, currency, metadata FROM invoices "
            "WHERE store_id = ? ORDER BY created_at DESC LIMIT 1",
            (shared_configured.store_id,),
        ).fetchone()
    assert row is not None, "an invoice should have been created"
    assert row["store_id"] == shared_configured.store_id
    assert row["status"] == "New"
    assert str(row["amount"]) == "1500"
    assert (row["currency"] or "").upper() == "SAT"
    assert "e2e self-serve test" in (row["metadata"] or "")


def _seed_usd_rate(shared_configured: ConfiguredPayserver, btc_usd: float) -> None:
    """Seed ExchangeRates' fresh cache for USD so the fiat path never phones a
    live price provider (key shape mirrors ExchangeRates::saveToCache)."""
    now = int(time.time())
    payload = json.dumps({"rate": btc_usd, "timestamp": now, "provider": "e2e"})
    with shared_configured.handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            ("rate_usd", payload, now, now),
        )


def test_leading_dot_fiat_amount_accepted(shared_configured: ConfiguredPayserver, browser) -> None:
    """Regression: typing ".10" (ten cents) into the USD amount field was
    rejected as "Amount is not a valid number" while "0.10" worked. The
    leading-dot form must be accepted, normalized to 0.10, converted at the
    store rate, and land on the payment page like any other amount."""
    _enable_store_selfserve(shared_configured)
    _set_store_currency(shared_configured, "USD")
    _seed_usd_rate(shared_configured, 100_000.0)  # 0.10 USD == 100 sats
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(
            f"{shared_configured.handle.url}/pay/{shared_configured.store_id}", wait_until="networkidle"
        )
        page.select_option("#currency", "USD")
        page.fill("#amount", ".10")
        page.fill("#notes", "e2e leading-dot amount")
        page.click("button[type=submit]")
        page.wait_for_url("**/payment.php?id=*", timeout=15000)
        assert "not a valid number" not in page.content().lower()

        # The server still rejects the genuinely malformed neighbours of ".10"
        # (while the store still offers USD).
        for bad in (".", ".555", "10."):
            r = requests.post(
                f"{shared_configured.handle.url}/pay/{shared_configured.store_id}",
                data={"amount": bad, "currency": "USD", "notes": ""},
                timeout=15,
                allow_redirects=False,
            )
            assert r.status_code == 200, (bad, r.status_code)
            assert "not a valid number" in r.text.lower(), (bad, r.text[:300])
    finally:
        ctx.close()
        _set_store_currency(shared_configured, "sat")

    with shared_configured.handle.db() as db:
        row = db.execute(
            "SELECT amount, currency, metadata FROM invoices "
            "WHERE store_id = ? ORDER BY created_at DESC LIMIT 1",
            (shared_configured.store_id,),
        ).fetchone()
    assert row is not None, "an invoice should have been created"
    assert "e2e leading-dot amount" in (row["metadata"] or "")
    assert (row["currency"] or "").upper() == "USD"
    assert str(row["amount"]) in ("0.10", "0.1"), row["amount"]


def test_admin_invoices_view_shows_selfserve_link(shared_configured: ConfiguredPayserver, browser) -> None:
    # When self-serve is on for the store, the Invoices view surfaces a banner
    # with the public link so operators can discover + share it.
    _enable_store_selfserve(shared_configured)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Multi-store shared server: pre-seed the dashboard's store selector so
    # loadDashboard picks this test's store instead of stores[0].
    ctx.add_init_script(
        f"localStorage.setItem('selectedStoreId', {shared_configured.store_id!r})"
    )
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{shared_configured.handle.url}/admin/invoices", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#card-selfserve-link")
        assert banner.is_visible(), "self-serve link banner should be visible when enabled"
        link = page.locator("#invoices-selfserve-link").input_value()
        assert shared_configured.store_id in link, f"banner link should target the store, got {link}"
    finally:
        ctx.close()


def test_store_settings_info_no_unit_and_selfserve_link(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    # The store-info block drops the always-"sat" Unit row and, when self-serve
    # is effectively on, surfaces the public link with a Copy button.
    _enable_store_selfserve(shared_configured)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Multi-store shared server: pre-seed the dashboard's store selector so
    # loadDashboard picks this test's store instead of stores[0].
    ctx.add_init_script(
        f"localStorage.setItem('selectedStoreId', {shared_configured.store_id!r})"
    )
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{shared_configured.handle.url}/admin/stores", wait_until="networkidle")
        expand_settings_cards(page)
        # loadDashboard auto-selects a store, populates dashboardData.selfserve
        # and runs refreshStoreSelfServeCard (which toggles the info-grid link);
        # loadStoreSettings fills the store-info block.
        page.evaluate("async () => { await loadDashboard(); await loadStoreSettings(); }")
        page.wait_for_timeout(1000)

        # The Unit row is gone entirely (element removed, not just hidden).
        assert page.locator("#store-settings-unit").count() == 0, "Unit row should be removed"

        # Self-serve is on for this payment-capable store → link visible.
        row = page.locator("#store-info-selfserve-row")
        assert row.is_visible(), "self-serve link row should show when enabled"
        link = page.locator("#store-info-selfserve-link").input_value()
        assert shared_configured.store_id in link, f"link should target the store, got {link}"
        assert page.locator("#btn-copy-store-info-selfserve").is_visible(), "Copy button present"
    finally:
        ctx.close()


def test_store_settings_info_hides_selfserve_link_when_off(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    # With self-serve off for the store (the default), the info-grid link row
    # stays hidden.
    _enable_store_selfserve(shared_configured, enabled=False)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Multi-store shared server: pre-seed the dashboard's store selector so
    # loadDashboard picks this test's store instead of stores[0].
    ctx.add_init_script(
        f"localStorage.setItem('selectedStoreId', {shared_configured.store_id!r})"
    )
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{shared_configured.handle.url}/admin/stores", wait_until="networkidle")
        expand_settings_cards(page)
        page.evaluate("async () => { await loadDashboard(); await loadStoreSettings(); }")
        page.wait_for_timeout(1000)
        assert not page.locator("#store-info-selfserve-row").is_visible(), (
            "self-serve link row should be hidden when the feature is off"
        )
    finally:
        ctx.close()


def _login_page(shared_configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page in a fresh context."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Multi-store shared server: pre-seed the dashboard's store selector so
    # loadDashboard picks this test's store instead of stores[0].
    ctx.add_init_script(
        f"localStorage.setItem('selectedStoreId', {shared_configured.store_id!r})"
    )
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    return ctx, ctx.new_page()


def _store_selfserve_flag(shared_configured: ConfiguredPayserver) -> int:
    with shared_configured.handle.db() as db:
        row = db.execute(
            "SELECT selfserve_enabled FROM stores WHERE id = ?",
            (shared_configured.store_id,),
        ).fetchone()
    return row["selfserve_enabled"]


def test_site_selfserve_card_gone_and_store_card_round_trips(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    # Self-serve became store-only: the site-wide card was removed from
    # /admin/settings, and the per-store card is now the only toggle. Assert
    # the old card really is gone, then round-trip the store card: flip the
    # select (values "0"/"1", posted to save_store_selfserve as `enabled`) to
    # "1", save, confirm the toast + the persisted stores row, then back to "0".
    ctx, page = _login_page(shared_configured, browser)
    try:
        page.goto(f"{shared_configured.handle.url}/admin/settings", wait_until="networkidle")
        expand_settings_cards(page)
        assert page.locator("#card-selfserve").count() == 0, "site self-serve card must be gone"
        assert page.locator("#btn-save-selfserve").count() == 0
        assert page.locator("#selfserve-enabled").count() == 0

        page.goto(f"{shared_configured.handle.url}/admin/stores", wait_until="networkidle")
        expand_settings_cards(page)
        page.evaluate("async () => { await loadDashboard(); }")
        page.wait_for_timeout(300)
        assert page.evaluate("currentStoreId") is not None, "a store should be selected"

        # The per-test store has a mint, so it is payment-capable and the
        # enable path is legal both client- and server-side.
        page.select_option("#store-selfserve-override", "1")
        page.click("#btn-save-store-selfserve")
        page.wait_for_timeout(1000)
        toast_text = page.text_content("#toast")
        toast_class = page.get_attribute("#toast", "class") or ""
        assert toast_text == "Store self-serve setting saved", toast_text
        assert "error" not in toast_class.split(), toast_class
        assert _store_selfserve_flag(shared_configured) == 1, "enable must persist on the stores row"

        page.select_option("#store-selfserve-override", "0")
        page.click("#btn-save-store-selfserve")
        page.wait_for_timeout(1000)
        assert _store_selfserve_flag(shared_configured) == 0, "disable must persist on the stores row"
    finally:
        ctx.close()


def test_admin_save_store_selfserve_shows_success(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    # Same regression as above for the per-store save button, whose success
    # branch unconditionally refreshes the dashboard.
    ctx, page = _login_page(shared_configured, browser)
    try:
        page.goto(f"{shared_configured.handle.url}/admin/stores", wait_until="networkidle")
        expand_settings_cards(page)
        page.evaluate("async () => { await loadDashboard(); }")
        page.wait_for_timeout(300)
        # Force the override off so neither the client- nor server-side
        # payment-capability check is in play — we're testing the save/refresh
        # wiring, not the resolution rules (those are covered elsewhere).
        page.select_option("#store-selfserve-override", "0")

        page.click("#btn-save-store-selfserve")
        page.wait_for_timeout(1000)
        toast_text = page.text_content("#toast")
        toast_class = page.get_attribute("#toast", "class") or ""
        assert toast_text == "Store self-serve setting saved", toast_text
        assert "error" not in toast_class.split(), toast_class
    finally:
        ctx.close()
