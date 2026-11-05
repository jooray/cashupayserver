"""Playwright browser fixture.

We don't use pytest-playwright to keep the plugin set small. The synchronous
playwright API is fine for our needs: one browser per session, fresh context
per test for cookie isolation.
"""
from __future__ import annotations

from typing import Iterator

import pytest


@pytest.fixture(scope="session")
def playwright_instance():
    from playwright.sync_api import sync_playwright

    with sync_playwright() as p:
        yield p


@pytest.fixture(scope="session")
def browser(playwright_instance):
    browser = playwright_instance.chromium.launch(
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage"],
    )
    yield browser
    browser.close()


@pytest.fixture
def page(browser):
    context = browser.new_context()
    page = context.new_page()
    yield page
    context.close()


def dashboard_seq(page) -> int:
    """The admin page's dashboard load counter (see loadDashboard in admin.php)."""
    return int(page.evaluate("() => (typeof dashboardLoadSeq === 'number') ? dashboardLoadSeq : 0"))


def wait_for_dashboard_settled(page, after_seq: int | None = None, timeout: int = 30000) -> None:
    """Block until no dashboard load is in flight — every loadDashboard() that
    started has finished rendering. Several admin saves kick off a reload
    without awaiting it; that reload re-renders the Lightning-payments lists
    (and more) from the payload, wiping unsaved rows and undoing in-progress
    edits. Pass `after_seq` (a dashboard_seq() reading taken before the action)
    to additionally require that at least one NEW load started since, so a
    reload the action is expected to trigger can't be missed by arriving
    early."""
    page.wait_for_function(
        "(n) => typeof dashboardLoadSeq === 'number'"
        " && dashboardRenderedSeq === dashboardLoadSeq"
        " && (n === null || dashboardLoadSeq > n)",
        arg=after_seq,
        timeout=timeout,
    )


def wait_for_store_settings_loaded(page, store_id: str, timeout: int = 30000) -> None:
    """The Stores view's loadStoreSettings() awaits ?api=stores and then
    re-renders the per-store cards — including the Lightning-payments lists —
    from scratch. Store Info's id is written in the same synchronous block,
    so once it shows this store the re-render has already happened and rows
    added afterwards survive."""
    page.wait_for_function(
        "(id) => document.getElementById('store-settings-id')"
        " && document.getElementById('store-settings-id').textContent.trim() === id",
        arg=store_id,
        timeout=timeout,
    )


def open_manual_mints(page) -> None:
    """Expand the wizard's manual mint entry with verify-and-retry.

    The toggle's click handler is bound by an inline script that runs after
    the anchor is already in the DOM, so a click that lands in that gap hits
    the bare href="#" and expands nothing. Re-click until the manual field is
    actually visible.
    """
    from playwright.sync_api import TimeoutError as PlaywrightTimeoutError

    page.wait_for_selector("#mint-manual-toggle")
    for attempt in range(3):
        page.click("#mint-manual-toggle")
        try:
            page.wait_for_selector("#mint_url_manual", state="visible", timeout=3000)
            return
        except PlaywrightTimeoutError:
            if attempt == 2:
                raise


def expand_settings_cards(page) -> None:
    """Open every collapsible card in the active Stores / Settings view.

    Both views collapse all of their cards each time they are shown (see
    collapseSettingsCards() in admin.php), so a test that drives controls
    inside a card must expand it first. Waits for the view to be active, which
    switchView() sets in the same synchronous block as the collapse, so the
    expansion can't be undone by a late initial-view restore. A later
    switchView() back into the view re-collapses them. The per-section
    defaults inside the Lightning payments card (the .subsection toggles) are
    untouched; they have their own coverage.
    """
    page.wait_for_selector("#view-stores.active, #view-settings.active", state="attached")
    page.evaluate(
        "() => document.querySelectorAll('.view.active .card.collapsible')"
        ".forEach(c => c.classList.remove('collapsed'))"
    )
