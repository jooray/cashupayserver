"""Admin UI: the Strike API keys section of the "Lightning payments" card.

Drives the browser flow against the mock Strike API: paste a key, save the
lists via #btn-save-lightning-payments (which runs the real save-time probe —
a 1-sat create + quote + read through the mock), and verify persistence.
Then the security contract: after the dashboard reload the saved row renders
as a masked label with no key anywhere in the DOM, and re-saving the card
unchanged round-trips the keep ref. A @strike.me lightning address in the
addresses list is blocked client-side with a pointer at the Strike section.
Finally, removing the row clears the chain.

Run with: pytest tests/ui/test_admin_strike_ui.py -v -s
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
from fixtures.strike_api import TEST_STRIKE_KEY, StrikeApiServer

pytestmark = pytest.mark.ui


def _chain(handle, store_id: str) -> list[tuple[str, str]]:
    with sqlite3.connect(handle.db_path) as db:
        rows = db.execute(
            "SELECT type, address FROM store_ln_addresses WHERE store_id = ? "
            "ORDER BY position ASC",
            (store_id,),
        ).fetchall()
    return [(r[0], r[1]) for r in rows]


def _reset_toast(page) -> None:
    page.evaluate(
        "() => { const t = document.getElementById('toast'); t.textContent = ''; t.className = 'toast'; }"
    )


def _save_lists_and_wait(page) -> None:
    _reset_toast(page)
    seq = dashboard_seq(page)
    page.click("#btn-save-lightning-payments")
    page.wait_for_selector("#toast.show:has-text('Settings saved!')", timeout=30000)
    # The save kicks off an un-awaited dashboard reload that re-renders the
    # lists; wait it out so the next edit can't be undone by it.
    wait_for_dashboard_settled(page, after_seq=seq)


def _open_lightning_payments(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(20000)
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
    page.wait_for_selector("#btn-add-strike", state="visible")


def test_add_save_masked_rerender_and_remove(
    shared_configured_with_strike: ConfiguredPayserver,
    strike_api_shared: StrikeApiServer,
    page,
) -> None:
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    store_id = configured.store_id

    # --- add + save (runs the live probe against the mock) ---
    # The mock is module-scoped (other tests' invoices accumulate in it), so
    # the probe assertions count only invoices created by this test's saves.
    _open_lightning_payments(page, configured)
    ids_before = set(strike_api.invoices)
    page.click("#btn-add-strike")
    page.locator("#auto-melt-strike-list input.strike-input").fill(TEST_STRIKE_KEY)
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == [("strike", TEST_STRIKE_KEY)]
    probe_ids = set(strike_api.invoices) - ids_before
    assert len(probe_ids) == 1, "probe issued exactly one test invoice"

    # --- masked re-render: label row, keep ref, no key in the DOM ---
    page.wait_for_selector("#auto-melt-strike-list .strike-saved-label", timeout=20000)
    label_text = page.locator("#auto-melt-strike-list .strike-saved-label").inner_text()
    assert label_text.startswith("Strike API ("), label_text
    assert TEST_STRIKE_KEY not in page.content(), "the API key must never reach the DOM"
    assert page.locator("#auto-melt-strike-list input.strike-input").count() == 0, (
        "saved rows must not render as editable key inputs"
    )

    # --- re-save unchanged: the keep ref round-trips, row survives, and the
    # grandfathered key is NOT re-probed (no second mock invoice) ---
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == [("strike", TEST_STRIKE_KEY)]
    assert set(strike_api.invoices) - ids_before == probe_ids, "kept keys are not re-probed"

    # --- remove + save clears the chain ---
    page.wait_for_selector("#auto-melt-strike-list .strike-saved-label", timeout=20000)
    page.locator("#auto-melt-strike-list .strike-row button[title='Remove']").click()
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == []


def test_strike_lightning_address_blocked_with_pointer(
    shared_configured_with_strike: ConfiguredPayserver, page
) -> None:
    """A @strike.me lightning address can never pass the LUD-21 gate; the
    client blocks it up front and points at the Strike API section."""
    configured = shared_configured_with_strike
    _open_lightning_payments(page, configured)
    page.click("#btn-add-ln-address")
    addr_input = page.locator("#auto-melt-address-list input.ln-address-input")
    addr_input.fill("merchant@strike.me")

    # The live per-row hint fires while typing.
    hint = page.locator("#auto-melt-address-list .ln-address-hint")
    assert "Strike API keys section" in hint.inner_text(), hint.inner_text()

    # Saving is blocked client-side with the pointer message.
    page.click("#btn-save-lightning-payments")
    page.wait_for_selector("#lightning-payments-error:not(.hidden)")
    text = page.locator("#lightning-payments-error").inner_text()
    assert "Strike API keys section" in text, text
    assert _chain(configured.handle, configured.store_id) == []


def test_malformed_key_rejected_client_side(
    shared_configured_with_strike: ConfiguredPayserver, page
) -> None:
    """A malformed key paste is caught by the client shape check with a
    message that does not echo the paste (it may be most of a real key)."""
    configured = shared_configured_with_strike
    _open_lightning_payments(page, configured)
    page.click("#btn-add-strike")
    bogus = "almost-a-key-with-dashes-0000"
    page.locator("#auto-melt-strike-list input.strike-input").fill(bogus)
    page.click("#btn-save-lightning-payments")
    page.wait_for_selector("#lightning-payments-error:not(.hidden)")
    text = page.locator("#lightning-payments-error").inner_text()
    assert "Strike API entry" in text, text
    assert bogus not in text, "client error must not echo the paste"
    assert _chain(configured.handle, configured.store_id) == []
