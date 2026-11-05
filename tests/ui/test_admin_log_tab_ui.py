"""Admin UI: the Log tab (unified recent-issues feed) and the site-wide
"suppress errors on invoice screen" toggle.

The Log tab is admin-only and merges the admin_event_log (NWC/noffer/LNURL
endpoint failures) with the other error/event tables; rows are seeded
straight into sqlite here — producing real failures needs live dead wallets,
which the e2e suite covers.
"""
from __future__ import annotations

import json
import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui

SUPPRESS_KEY = "suppress_receive_errors_on_invoice"


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _seed_log_rows(handle, store_id: str) -> None:
    now = int(time.time())
    with sqlite3.connect(handle.db_path) as db:
        db.execute("DELETE FROM admin_event_log")
        db.execute(
            "INSERT INTO admin_event_log (timestamp, category, context, store_id,"
            " invoice_id, label, message) VALUES (?, 'nwc', 'checkout', ?, NULL,"
            " 'NWC wallet abcd1234… via relay.test', 'No response from NWC wallet within 5s')",
            (now, store_id),
        )
        db.execute(
            "INSERT INTO admin_event_log (timestamp, category, context, store_id,"
            " invoice_id, label, message) VALUES (?, 'lnurl', 'poll', ?, 'inv_ui_test',"
            " 'merchant@host.test', 'verify poll failed: connection refused')",
            (now - 10, store_id),
        )
        db.execute(
            "INSERT INTO mint_event_log (mint_url, timestamp, event_type,"
            " failure_type, store_id, address, details) VALUES"
            " ('https://mint.ui.test', ?, 'QUOTE_FAILURE', 'TIMEOUT', ?, NULL, 'ui seed')",
            (now - 20, store_id),
        )
        db.commit()


def _suppress_value(handle):
    with sqlite3.connect(handle.db_path) as db:
        row = db.execute(
            "SELECT value FROM config WHERE key = ?", (SUPPRESS_KEY,)
        ).fetchone()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except (ValueError, TypeError):
        return row[0]


def _wait_suppress(handle, expected, timeout_s: float = 10) -> None:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if _suppress_value(handle) == expected:
            return
        time.sleep(0.25)
    raise AssertionError(
        f"{SUPPRESS_KEY} did not become {expected}; last={_suppress_value(handle)}"
    )


def test_log_tab_lists_and_filters_recent_issues(
    configured: ConfiguredPayserver, page
) -> None:
    _seed_log_rows(configured.handle, configured.store_id)
    _login_admin(page, configured)

    nav = page.locator('.nav-item[data-view="log"]')
    assert nav.is_visible(), "Log nav entry should be visible for an admin"
    nav.click()
    page.wait_for_selector("#admin-log-list table", state="visible")

    body = page.locator("#admin-log-list").inner_text()
    assert "NWC wallet" in body, body
    assert "No response from NWC wallet within 5s" in body
    assert "merchant@host.test" in body
    assert "QUOTE_FAILURE" in body, "mint reliability events appear in the merged feed"

    # Category filter narrows to one source.
    page.select_option("#log-category-filter", "nwc")
    page.wait_for_function(
        "() => { const el = document.getElementById('admin-log-list');"
        " return el && !el.innerText.includes('QUOTE_FAILURE'); }"
    )
    body = page.locator("#admin-log-list").inner_text()
    assert "No response from NWC wallet within 5s" in body
    assert "merchant@host.test" not in body, "lnurl row filtered out"

    # Back to all.
    page.select_option("#log-category-filter", "")
    page.wait_for_function(
        "() => { const el = document.getElementById('admin-log-list');"
        " return el && el.innerText.includes('QUOTE_FAILURE'); }"
    )


def test_suppress_invoice_errors_toggle_persists(
    configured: ConfiguredPayserver, page
) -> None:
    _login_admin(page, configured)
    # Opening Settings kicks off loadInvoiceErrorSettings(), whose response
    # writes the checkbox from the server value. A click that lands before
    # that response is undone by it (the checkbox snaps back to off and the
    # save posts 0) — so wait for the load to complete before touching it.
    with page.expect_response(
        lambda r: "get_invoice_error_settings" in (r.request.post_data or "")
    ) as load_info:
        page.locator('.nav-item[data-view="settings"]').click()
    load_info.value.finished()  # body parsed → checkbox written on the next tick
    page.wait_for_selector("#card-invoice-errors", state="attached")

    toggle = page.locator("#suppress-invoice-errors")
    assert not toggle.is_checked(), "suppression must default to off"

    # The checkbox input itself is visually hidden (opacity:0, zero size) —
    # the clickable control is the styled slider next to it.
    slider = page.locator("#card-invoice-errors .toggle-slider")
    save_btn = page.locator("#btn-save-invoice-errors")

    try:
        slider.scroll_into_view_if_needed()
        slider.click()
        assert toggle.is_checked()
        save_btn.scroll_into_view_if_needed()
        save_btn.click()
        _wait_suppress(configured.handle, True)

        # Reload → the saved value round-trips into the checkbox. The URL
        # deep-links back to /settings, so the view restores itself — no nav
        # click (a second switchView would fire a duplicate settings load
        # whose late response could undo the toggle below).
        page.reload()
        page.wait_for_selector("#app", state="visible")
        page.wait_for_selector("#card-invoice-errors", state="attached")
        page.wait_for_function(
            "() => document.getElementById('suppress-invoice-errors').checked === true"
        )
        page.wait_for_load_state("networkidle")

        slider = page.locator("#card-invoice-errors .toggle-slider")
        slider.scroll_into_view_if_needed()
        slider.click()
        assert not page.locator("#suppress-invoice-errors").is_checked()
        save_btn = page.locator("#btn-save-invoice-errors")
        save_btn.scroll_into_view_if_needed()
        save_btn.click()
        _wait_suppress(configured.handle, False)
    finally:
        # Never leak a suppressed state into the shared session fixture.
        with sqlite3.connect(configured.handle.db_path) as db:
            db.execute("DELETE FROM config WHERE key = ?", (SUPPRESS_KEY,))
            db.commit()
