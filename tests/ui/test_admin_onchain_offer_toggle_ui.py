"""Admin UI: the on-chain payment offer controls.

Covers the per-store "Offer on-chain to customers" on/off select (Bitcoin tab),
added so a merchant can present a Lightning-only checkout while keeping an
xpub (submarine swaps still settle on-chain to it). The site-wide "Offer
on-chain payments by default" toggle was removed in the store-only settings
refactor — a test asserts the card is gone.
"""
from __future__ import annotations

import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _store_offer(handle, store_id: str):
    with sqlite3.connect(handle.db_path) as db:
        row = db.execute(
            "SELECT onchain_offer_enabled FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    return row[0] if row else None


def _wait_offer(handle, store_id: str, expected: int, timeout_s: float = 10) -> None:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if _store_offer(handle, store_id) == expected:
            return
        time.sleep(0.25)
    raise AssertionError(
        f"onchain_offer_enabled did not become {expected}; "
        f"last={_store_offer(handle, store_id)}"
    )


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def test_per_store_onchain_offer_toggle_persists(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _login_admin(page, configured)
    # Pin the header selector to this test's own store — the shared server
    # auto-selects its first store, and both the instant-save and the DB
    # assertions below must target the per-test store's row.
    page.select_option("#store-select", configured.store_id)
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#onchain-offer-override", state="visible")
    # Wait until the card is populated from dashboardData ("Currently
    # effective" filled in).
    page.wait_for_function(
        "() => { const el = document.getElementById('onchain-offer-effective');"
        " return el && el.textContent.trim() && el.textContent.trim() !== '\\u2014'; }"
    )

    try:
        # The select is a plain on/off pair now — no "-1"/inherit option.
        values = page.eval_on_selector_all(
            "#onchain-offer-override option", "opts => opts.map(o => o.value)"
        )
        assert values == ["1", "0"], f"expected only on/off options, got {values}"

        # Default: the wizard-created store (add_store wizard skips on-chain,
        # leaving the column's -1 default / legacy NULL) resolves to ON.
        assert _store_offer(configured.handle, configured.store_id) in (-1, None)
        assert page.locator("#onchain-offer-override").input_value() == "1"

        # Force OFF -> onchange instant-saves -> column becomes 0, warning shows.
        page.select_option("#onchain-offer-override", "0")
        _wait_offer(configured.handle, configured.store_id, 0)
        assert page.locator("#onchain-offer-warning").is_visible(), \
            "the 'some wallets can't pay you' warning should show when off"

        # Force ON -> column becomes 1, warning hidden.
        page.select_option("#onchain-offer-override", "1")
        _wait_offer(configured.handle, configured.store_id, 1)
        assert not page.locator("#onchain-offer-warning").is_visible()
    finally:
        # Restore the legacy row value so the shared session store doesn't
        # leak state (a stale -1 resolves to ON — the built-in default).
        with sqlite3.connect(configured.handle.db_path) as db:
            db.execute(
                "UPDATE stores SET onchain_offer_enabled = -1 WHERE id = ?",
                (configured.store_id,),
            )
            db.commit()


def test_site_wide_onchain_card_is_gone(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """The store-only settings refactor removed the site-wide on-chain offer
    card entirely — the Settings view must not render the old card, checkbox
    or save button under any id."""
    _login_admin(page, shared_configured)
    page.locator('.nav-item[data-view="settings"]').click()
    expand_settings_cards(page)
    # The Email Notifications card survives the refactor — use it as the
    # signal that the Settings view has rendered.
    page.wait_for_selector("#card-notifications", state="visible")

    for selector in ("#card-onchain-site", "#onchain-site-enabled",
                     "#btn-save-onchain-site", "#onchain-site-warning"):
        assert page.locator(selector).count() == 0, (
            f"{selector} should no longer exist anywhere in the admin UI"
        )
