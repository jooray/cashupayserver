"""Admin UI: the NWC connections section of the "Lightning payments" card.

Drives the browser flow end to end against a live fake wallet: paste a
connection string, save the lists via #btn-save-lightning-payments (which runs
the real save-time probe — a 1-sat make_invoice + lookup_invoice through the
wallet), and verify persistence. Then the security contract: after a reload
the saved row renders as a masked label (wallet + relay) with no secret
anywhere in the DOM, and re-saving the card unchanged round-trips the keep ref
without ever exposing the URI. Finally, removing the row clears the chain.

Run with: pytest tests/ui/test_admin_nwc_ui.py -v -s
"""
from __future__ import annotations

import sqlite3
import time
from typing import Iterator

import pytest

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.browser import (
    expand_settings_cards,
    dashboard_seq,
    wait_for_dashboard_settled,
    wait_for_store_settings_loaded,
)
from fixtures.lnd import LndHandle
from fixtures.nwc_wallet import NwcStack, start_nwc_stack, stop_nwc_stack

pytestmark = pytest.mark.ui


@pytest.fixture(scope="module")
def nwc_stack(lnd_mint: LndHandle, channels: None) -> Iterator[NwcStack]:
    """Module-scoped: generic infrastructure (each test wires its own store
    to it); the save-time probe is keyed per invoice, so wallet/relay state
    can't leak between tests."""
    workdir = SESSION_TMP / f"nwc-admin-ui-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_mint, info_methods=["make_invoice", "lookup_invoice", "get_info"]
    )
    yield stack
    stop_nwc_stack(stack)


def _chain(handle, store_id: str) -> list[tuple[str, str]]:
    with sqlite3.connect(handle.db_path) as db:
        rows = db.execute(
            "SELECT type, address FROM store_ln_addresses WHERE store_id = ? "
            "ORDER BY position ASC",
            (store_id,),
        ).fetchall()
    return [(r[0], r[1]) for r in rows]


def _reset_toast(page) -> None:
    """The #toast div keeps its old text after hiding, so it is reset first —
    otherwise a second save could match the stale toast from the first and
    race the actual request."""
    page.evaluate(
        "() => { const t = document.getElementById('toast'); t.textContent = ''; t.className = 'toast'; }"
    )


def _save_lists_and_wait(page) -> None:
    """Save the Lightning payments lists and wait for a fresh success toast."""
    _reset_toast(page)
    seq = dashboard_seq(page)
    page.click("#btn-save-lightning-payments")
    page.wait_for_selector("#toast.show:has-text('Settings saved!')", timeout=30000)
    # The save kicks off an un-awaited dashboard reload that re-renders the
    # lists; wait it out so the next edit can't be undone by it.
    wait_for_dashboard_settled(page, after_seq=seq)


def _save_cashout_and_wait(page) -> None:
    """Save the Cashu automatic cashout card and wait for its success toast."""
    _reset_toast(page)
    seq = dashboard_seq(page)
    page.click("#btn-save-auto-melt")
    page.wait_for_selector("#toast.show:has-text('Settings saved!')", timeout=30000)
    # Same un-awaited post-save reload as the lists save.
    wait_for_dashboard_settled(page, after_seq=seq)


def _open_lightning_payments(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(20000)
    # Pin the admin UI to THIS test's store before any app script runs: on a
    # shared server multiple stores exist and the UI otherwise defaults to the
    # first one. `currentStoreId` is initialised from localStorage at script
    # parse, so an init script (which runs on every navigation, including a
    # post-login reload) is the race-free way to select the store.
    page.add_init_script(
        f"try {{ localStorage.setItem('selectedStoreId', {configured.store_id!r}); }} catch (e) {{}}"
    )
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    # The header selector echoes the pinned store once the dashboard payload
    # lands — this both proves the pin took and gates on that first load.
    page.wait_for_function(
        "sid => document.getElementById('store-select')"
        " && document.getElementById('store-select').value === sid",
        arg=configured.store_id,
    )
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    # The lists live in the always-visible "Lightning payments" card now — no
    # cashout-mode column click needed to reveal them.
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
    page.wait_for_selector("#btn-add-nwc", state="visible")


def test_add_save_masked_rerender_and_remove(
    shared_configured: ConfiguredPayserver, nwc_stack: NwcStack, page
) -> None:
    configured = shared_configured
    uri = nwc_stack.wallet.connection_uri()
    secret = uri.split("secret=", 1)[1].split("&", 1)[0]
    store_id = configured.store_id

    # --- add + save (runs the live probe) ---
    _open_lightning_payments(page, configured)
    page.click("#btn-add-nwc")
    page.locator("#auto-melt-nwc-list input.nwc-input").fill(uri)
    # Lists first, then the enable/threshold pair — they save through separate
    # buttons now.
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == [("nwc", uri)]

    # Enable auto-melt + a threshold in the cashout card as well. Done after
    # the list save: its dashboard reload resets these inputs to persisted
    # values. The toggle is a CSS-hidden checkbox, so set it directly; the
    # value is only read on save.
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", "100")
    _save_cashout_and_wait(page)

    # --- masked re-render: label row, keep ref, no secret in the DOM ---
    page.wait_for_selector("#auto-melt-nwc-list .nwc-saved-label", timeout=20000)
    label_text = page.locator("#auto-melt-nwc-list .nwc-saved-label").inner_text()
    assert label_text.startswith("NWC wallet "), label_text
    assert secret not in page.content(), "the connection secret must never reach the DOM"
    assert page.locator("#auto-melt-nwc-list input.nwc-input").count() == 0, (
        "saved rows must not render as editable URI inputs"
    )

    # --- re-save unchanged: the keep ref round-trips, row survives ---
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == [("nwc", uri)]

    # --- remove + save clears the chain ---
    # Turn auto-melt off first (its own save button), then clear the list —
    # leaving auto-cashout enabled with no destinations would be a dead config.
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = false; }")
    _save_cashout_and_wait(page)
    page.wait_for_selector("#auto-melt-nwc-list .nwc-saved-label", timeout=20000)
    page.locator("#auto-melt-nwc-list .nwc-row button[title='Remove']").click()
    _save_lists_and_wait(page)
    assert _chain(configured.handle, store_id) == []


def test_malformed_paste_is_rejected_client_side(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """A malformed NWC paste is caught by the client shape check with a message
    that does not echo the paste (it may carry a secret)."""
    configured = shared_configured
    _open_lightning_payments(page, configured)
    page.click("#btn-add-nwc")
    bogus_secret = "cd" * 32
    page.locator("#auto-melt-nwc-list input.nwc-input").fill(
        "nostr+walletconnect://broken?secret=" + bogus_secret
    )
    page.click("#btn-save-lightning-payments")
    err = page.locator("#lightning-payments-error")
    page.wait_for_selector("#lightning-payments-error:not(.hidden)")
    text = err.inner_text()
    assert "connection string" in text, text
    assert bogus_secret not in text, "client error must not echo the paste"
    assert _chain(configured.handle, configured.store_id) == []
