"""The two waits the Lightning-payments UI tests gate on, exercised against
held-back API responses so the races they exist for are deterministic.

Background: clicking the Stores tab fires loadStoreSettings(), which awaits
?api=stores and then re-renders the Lightning-payments lists from scratch;
every save fires an un-awaited loadDashboard() that does the same. A row
added before either lands is wiped — the cause of the strike/nwc/ln-address
UI flakes in CI. The helpers in fixtures/browser.py make the tests wait for
those loads instead of sleeping; these tests pin down that they really block
while a load is in flight and release once it has rendered."""
from __future__ import annotations

import json

import pytest
from playwright.sync_api import TimeoutError as PlaywrightTimeoutError

from conftest import ConfiguredPayserver
from fixtures.browser import (
    expand_settings_cards,
    dashboard_seq,
    wait_for_dashboard_settled,
    wait_for_store_settings_loaded,
)

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(20000)
    page.add_init_script(
        f"window.localStorage.setItem('selectedStoreId', {json.dumps(configured.store_id)});"
    )
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _hold(page, needle: str) -> list:
    """Park every request whose URL contains `needle` until the test releases
    it (route.continue_()). Returns the list the parked routes land in."""
    pending: list = []
    page.route(lambda url: needle in url, lambda route: pending.append(route))
    return pending


def test_store_settings_wait_blocks_until_the_stores_response_lands(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _login(page, configured)
    wait_for_dashboard_settled(page)
    held = _hold(page, "api=stores")

    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#card-lightning-payments", state="visible")
    assert len(held) == 1, "the Stores click fetches ?api=stores exactly once"

    # Held → the gate must not pass: the re-render it guards against has not
    # happened yet.
    with pytest.raises(PlaywrightTimeoutError):
        wait_for_store_settings_loaded(page, configured.store_id, timeout=1500)

    held.pop().continue_()
    wait_for_store_settings_loaded(page, configured.store_id)

    # Rows added after the gate survive: nothing is left in flight to wipe them.
    page.evaluate(
        "document.querySelectorAll('#card-lightning-payments .subsection.collapsed')"
        ".forEach(el => el.classList.remove('collapsed'))"
    )
    page.click("#btn-add-ln-address")
    rows = page.locator("#auto-melt-address-list input.ln-address-input")
    rows.first.fill("someone@example.com")
    page.wait_for_timeout(1000)
    assert rows.count() == 1
    assert rows.first.input_value() == "someone@example.com"


def test_dashboard_settled_wait_tracks_in_flight_loads(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _login(page, configured)
    wait_for_dashboard_settled(page)
    seq0 = dashboard_seq(page)
    held = _hold(page, "api=dashboard")

    # A reload the page kicks off without awaiting (what every save does).
    page.evaluate("() => { loadDashboard(); }")
    assert dashboard_seq(page) == seq0 + 1
    with pytest.raises(PlaywrightTimeoutError):
        wait_for_dashboard_settled(page, timeout=1500)
    # after_seq: a reading taken before the action must see a NEWER load.
    with pytest.raises(PlaywrightTimeoutError):
        wait_for_dashboard_settled(page, after_seq=seq0 + 1, timeout=1500)

    # A second load supersedes the first. Releasing only the first must NOT
    # settle anything: its handler bails out as superseded and must not mark
    # the newer load as rendered.
    page.evaluate("() => { loadDashboard(); }")
    assert dashboard_seq(page) == seq0 + 2
    assert len(held) == 2
    held.pop(0).continue_()
    with pytest.raises(PlaywrightTimeoutError):
        wait_for_dashboard_settled(page, timeout=1500)

    held.pop(0).continue_()
    wait_for_dashboard_settled(page, after_seq=seq0)
    assert page.evaluate("() => dashboardRenderedSeq === dashboardLoadSeq")
    assert dashboard_seq(page) == seq0 + 2
