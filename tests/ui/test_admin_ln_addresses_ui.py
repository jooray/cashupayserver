"""Admin UI: ordered Lightning-address fallback chain.

Drives the per-store "Lightning payments" card (the destination lists moved
there from the old Auto-Cashout card and are always visible): add several
Lightning addresses, reorder them with the up/down arrows, save via
#btn-save-lightning-payments, and verify the store_ln_addresses table reflects
the on-screen priority order. Also checks that the save button enforces
client-side validation (duplicate + malformed address). The enable/threshold
half of auto-cashout is saved separately via #btn-save-auto-melt and is
exercised alongside the list saves.

Saving the lists runs the server-side LUD-21 gate, so the persistence tests use
the `shared_configured_with_lnurlp` stack — its CASHU_LNURL_URL_TEMPLATE routes
every address to the mock LNURL host, which advertises a verify URL. The last
test runs against the plain `shared_configured` stack (no LNURL host reachable)
and asserts the gate blocks the save with a visible error. Both are shared
module servers with one store per test, so each test pins the UI to its own
store via localStorage before navigating.

Run with: pytest tests/ui/test_admin_ln_addresses_ui.py -v -s
"""
from __future__ import annotations

import json
import sqlite3

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import (
    expand_settings_cards,
    dashboard_seq,
    wait_for_dashboard_settled,
    wait_for_store_settings_loaded,
)

pytestmark = pytest.mark.ui

ADDR_A = "alice@wallet.test"
ADDR_B = "bob@wallet.test"
ADDR_C = "carol@wallet.test"


def _ln_addresses(handle, store_id: str) -> list[str]:
    with sqlite3.connect(handle.db_path) as db:
        rows = db.execute(
            "SELECT address FROM store_ln_addresses WHERE store_id = ? "
            "ORDER BY position ASC",
            (store_id,),
        ).fetchall()
    return [r[0] for r in rows]


def _open_lightning_payments(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    # On the shared server multiple stores exist and the dashboard defaults to
    # stores[0]; pin the UI to this test's own store before any navigation.
    page.add_init_script(
        f"window.localStorage.setItem('selectedStoreId', {json.dumps(configured.store_id)});"
    )
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    # The "Lightning payments" card is always visible — no cashout-mode click
    # needed. The list div (#auto-melt-address-list) starts empty and thus has
    # zero size, which Playwright treats as hidden — so wait on the
    # always-present Add button.
    page.wait_for_selector("#card-lightning-payments", state="visible")
    # Two async loads re-render the saved lists from scratch and would wipe
    # rows added before they land: the dashboard payload (already loaded at
    # login, and re-fetched on store switches) and loadStoreSettings(), which
    # the Stores click above fires and which awaits ?api=stores first. Gate on
    # both. They also apply the per-section default collapse states, so the
    # expansion below must come after them or it would be undone.
    wait_for_store_settings_loaded(page, configured.store_id)
    wait_for_dashboard_settled(page)
    # Sections with no saved entries are collapsed by default; expand them all
    # so the test can drive the controls directly (the default-state behavior
    # has its own coverage in test_settings_lightning_sections.py).
    page.evaluate(
        "document.querySelectorAll('#card-lightning-payments .subsection.collapsed')"
        ".forEach(el => el.classList.remove('collapsed'))"
    )
    page.wait_for_selector("#btn-add-ln-address", state="visible")


def _reset_toast(page) -> None:
    """The #toast div keeps its old text after hiding; reset it so a wait for
    the next toast can't match a stale one."""
    page.evaluate(
        "() => { const t = document.getElementById('toast'); t.textContent = ''; t.className = 'toast'; }"
    )


def _save_lists_and_wait(page) -> None:
    """Save the Lightning payments lists and wait for the success toast."""
    _reset_toast(page)
    seq = dashboard_seq(page)
    page.click("#btn-save-lightning-payments")
    page.wait_for_selector("#toast.show:has-text('Settings saved!')", timeout=30000)
    # The save kicks off an un-awaited dashboard reload that re-renders the
    # lists; wait it out so the next edit can't be undone by it.
    wait_for_dashboard_settled(page, after_seq=seq)


def _save_cashout_and_wait(page) -> None:
    """Save the Cashu automatic cashout card (enabled/threshold/mode) and wait
    for the success toast."""
    _reset_toast(page)
    seq = dashboard_seq(page)
    page.click("#btn-save-auto-melt")
    page.wait_for_selector("#toast.show:has-text('Settings saved!')", timeout=30000)
    # Same un-awaited post-save reload as the lists save.
    wait_for_dashboard_settled(page, after_seq=seq)


def _enable_cashout_and_save(page, threshold: str = "100") -> None:
    """Turn auto-cashout on with a threshold and save the cashout card.

    Run AFTER the list save: that save reloads the dashboard, which resets
    #auto-melt-enabled / #auto-melt-threshold to their persisted values and
    would clobber values set before it. The toggle is a CSS-hidden checkbox
    positioned off the viewport, so it is set directly rather than clicked;
    its value is only read on save (no change handler)."""
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", threshold)
    _save_cashout_and_wait(page)


def _fill_rows(page, addresses: list[str]) -> None:
    """Add a row per address (via the + button) and fill each input."""
    for _ in addresses:
        page.click("#btn-add-ln-address")
    inputs = page.locator("#auto-melt-address-list input.ln-address-input")
    for i, addr in enumerate(addresses):
        inputs.nth(i).fill(addr)


def test_add_reorder_and_save_chain(
    shared_configured_with_lnurlp: ConfiguredPayserver, page
) -> None:
    configured = shared_configured_with_lnurlp
    _open_lightning_payments(page, configured)

    # Add three addresses in order A, B, C.
    _fill_rows(page, [ADDR_A, ADDR_B, ADDR_C])

    # Move the third row (C) up one → order becomes A, C, B.
    rows = page.locator("#auto-melt-address-list .ln-address-row")
    rows.nth(2).locator('button[title="Move up"]').click()

    # The lists and the enable/threshold pair now save through separate
    # buttons — lists first, then the cashout settings (the store has a mint
    # wallet, so the cashout card is interactive).
    _save_lists_and_wait(page)
    _enable_cashout_and_save(page)

    assert _ln_addresses(configured.handle, configured.store_id) == [
        ADDR_A,
        ADDR_C,
        ADDR_B,
    ], "saved chain should match on-screen priority order after reorder"


def test_remove_row(shared_configured_with_lnurlp: ConfiguredPayserver, page) -> None:
    configured = shared_configured_with_lnurlp
    _open_lightning_payments(page, configured)
    _fill_rows(page, [ADDR_A, ADDR_B])

    # Remove the first row (A) → only B remains.
    rows = page.locator("#auto-melt-address-list .ln-address-row")
    rows.nth(0).locator('button[title="Remove"]').click()

    _save_lists_and_wait(page)
    _enable_cashout_and_save(page)

    assert _ln_addresses(configured.handle, configured.store_id) == [ADDR_B]


def test_duplicate_rejected_client_side(shared_configured: ConfiguredPayserver, page) -> None:
    configured = shared_configured
    _open_lightning_payments(page, configured)
    _fill_rows(page, [ADDR_A, ADDR_A])

    page.click("#btn-save-lightning-payments")
    page.wait_for_timeout(1000)

    # The inline error surfaces (in the Lightning payments card's own error
    # div) and nothing is persisted.
    err = page.locator("#lightning-payments-error")
    assert err.is_visible(), "expected inline duplicate-address error"
    assert "Duplicate" in err.text_content()
    assert _ln_addresses(configured.handle, configured.store_id) == []


def test_unverifiable_address_blocked_server_side(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """On the plain stack no LNURL host is reachable, so the server-side
    LUD-21 gate rejects the save: the error toast shows and nothing lands in
    store_ln_addresses."""
    configured = shared_configured
    _open_lightning_payments(page, configured)
    _fill_rows(page, [ADDR_A])

    page.click("#btn-save-lightning-payments")
    # The probe times out server-side before the 400 comes back — allow for it.
    page.wait_for_selector("#toast.show.error", timeout=20000)
    assert "didn't respond" in page.locator("#toast").text_content()
    assert _ln_addresses(configured.handle, configured.store_id) == []
