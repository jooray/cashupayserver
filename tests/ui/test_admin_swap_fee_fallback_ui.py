"""Admin UI: the per-store submarine-swap card.

The store-only settings refactor removed the site-wide swaps card entirely;
every swap setting now lives on the store card (Stores view):
  * #store-swaps-enabled checkbox (replaces the old tri-state select),
    provider checkboxes, auto-select controls and the strict on/off select.
  * fee-too-high → mint-fallback thresholds: #store-swaps-fee-pct /
    #store-swaps-fee-sats save into the stores columns via
    action=save_store_swaps; blank inherits the config-file constant and the
    placeholder reads "default (N)" or "off".

Run with: pytest tests/ui/test_admin_swap_fee_fallback_ui.py -v -s
"""
from __future__ import annotations

import sqlite3

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _open_store_swaps_card(page, configured: ConfiguredPayserver) -> None:
    # Pin the SPA to this test's own store BEFORE the first load: the shared
    # server otherwise auto-selects its first store and switching afterwards
    # kicks off a second async dashboard load whose re-render can clobber
    # in-flight DOM edits (seen resetting the swaps checkbox mid-test).
    page.add_init_script(
        f"localStorage.setItem('selectedStoreId', {configured.store_id!r})"
    )
    _login(page, configured)
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#store-swaps-fee-pct", state="visible")
    # Wait until refreshStoreSwapsCard() has run: the fee placeholders flip
    # from the static markup to "default (N)" / "off" once dashboardData lands.
    page.wait_for_function(
        "() => { const el = document.getElementById('store-swaps-fee-pct');"
        " return el && (el.placeholder === 'off' || el.placeholder.startsWith('default (')); }"
    )


def _store_swaps_row(configured: ConfiguredPayserver) -> tuple:
    with sqlite3.connect(configured.handle.db_path) as db:
        return db.execute(
            "SELECT swaps_enabled, swaps_fee_fallback_max_pct, "
            "swaps_fee_fallback_max_sats FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()


def test_site_swaps_card_is_gone(shared_configured: ConfiguredPayserver, page) -> None:
    """The Settings view must no longer render the site-wide swaps card (or
    the site auto-cashout default selector) under any of the old ids."""
    _login(page, shared_configured)
    page.locator('.nav-item[data-view="settings"]').click()
    expand_settings_cards(page)
    # The Email Notifications card survives the refactor — use it as the
    # signal that the Settings view has rendered.
    page.wait_for_selector("#card-notifications", state="visible")

    for selector in ("#card-swaps", "#btn-save-swaps", "#swaps-enabled",
                     "#swaps-strict", "#swaps-provider-checkboxes",
                     "#swaps-fee-max-pct", "#swaps-fee-max-sats",
                     "#aw-site", "#auto-melt-use-swap-default"):
        assert page.locator(selector).count() == 0, (
            f"{selector} should no longer exist anywhere in the admin UI"
        )


def test_store_swaps_card_controls(shared_configured: ConfiguredPayserver, page) -> None:
    _open_store_swaps_card(page, shared_configured)

    # The old tri-state select is gone; the checkbox replaces it.
    assert page.locator("#store-swaps-override").count() == 0
    assert page.locator("#store-swaps-enabled").count() == 1
    assert page.locator("#store-swaps-site-default").count() == 0
    assert page.locator("#store-swaps-strict-effective").count() == 0

    # New provider / auto-select controls are present; the provider list is
    # populated from dashboardData.swaps.knownProviders.
    assert page.locator(
        "#store-swaps-provider-checkboxes input[type=checkbox]"
    ).count() >= 1
    for selector in ("#store-swaps-auto-select", "#store-swaps-auto-threshold",
                     "#store-swaps-min-sats"):
        assert page.locator(selector).count() == 1, selector

    # Strict select is a plain on/off pair (no "-1"/inherit).
    strict_values = page.eval_on_selector_all(
        "#store-swaps-strict option", "opts => opts.map(o => o.value)"
    )
    assert strict_values == ["0", "1"], strict_values


def test_enable_without_xpub_is_blocked(shared_configured: ConfiguredPayserver, page) -> None:
    """The wizard-walked store has no on-chain xpub (the add_store wizard
    skips it and forces swaps off), so turning swaps on is refused
    client-side with an inline error and nothing is saved."""
    configured = shared_configured
    _open_store_swaps_card(page, configured)
    assert _store_swaps_row(configured)[0] == 0, "wizard turned swaps off"

    # The toggle's <input> is CSS-hidden, so set it directly.
    page.evaluate("() => { document.getElementById('store-swaps-enabled').checked = true; }")
    page.click("#btn-save-store-swaps")
    page.wait_for_selector("#store-swaps-error:not(.hidden)")
    assert "xpub" in page.locator("#store-swaps-error").inner_text()
    assert _store_swaps_row(configured)[0] == 0, "blocked save must not persist"


def test_store_fee_fallback_round_trip(shared_configured: ConfiguredPayserver, page) -> None:
    configured = shared_configured
    _open_store_swaps_card(page, configured)

    page.fill("#store-swaps-fee-pct", "3.5")
    page.fill("#store-swaps-fee-sats", "900")
    page.click("#btn-save-store-swaps")
    page.wait_for_timeout(2000)

    row = _store_swaps_row(configured)
    assert row is not None
    assert float(row[1]) == 3.5
    assert int(row[2]) == 900

    # Blanking both fields clears the per-store values (back to the
    # config-file default, reflected by the "default (N)"/"off" placeholder).
    page.fill("#store-swaps-fee-pct", "")
    page.fill("#store-swaps-fee-sats", "")
    page.click("#btn-save-store-swaps")
    page.wait_for_timeout(2000)

    row = _store_swaps_row(configured)
    assert row[1] is None and row[2] is None
