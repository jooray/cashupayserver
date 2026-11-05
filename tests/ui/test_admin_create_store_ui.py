"""Repro for: clicking "Create Store" in admin does nothing.

After path-based view routing (commit 96edae4), admin pages live under
/admin/<view> (e.g. /admin/stores). Urls::setup() returned the bare
relative string 'setup.php', so window.location.href = setupUrl + ...
resolved against /admin/stores → /admin/setup.php, which router.php
forwards to admin.php as an unknown view → 302 to /admin/dashboard.
Net effect: the click appears to do nothing.

This test exercises both entry points and asserts the user actually
lands on the Add New Store wizard.

Run with: pytest tests/ui/test_admin_create_store_ui.py -v -s
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _login_to_stores_view(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#store-select') && "
        "document.querySelector('#store-select').options.length > 0"
    )


def test_create_store_dropdown_option_navigates_to_wizard(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """+ Create Store in the header store dropdown must land on the wizard."""
    _login_to_stores_view(shared_configured, page)

    # Land on a non-dashboard view so the bug (relative URL resolving
    # against /admin/<view>) actually triggers. Dashboard happens to be
    # the canonical landing path, but the dropdown is visible on stores too.
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_url("**/admin/stores")

    # Select the "+ Create Store" sentinel value. onStoreSelectChange()
    # reads this and sets window.location.href = setupUrl + '?mode=add_store'.
    page.select_option("#store-select", "__create__")

    # Before the fix: browser stays on /admin/<something> after a silent
    # 302 back to dashboard. After the fix: setup.php loads in add_store
    # mode and shows the "Add New Store" heading.
    page.wait_for_url("**/setup.php?mode=add_store", timeout=10000)
    page.wait_for_selector("h1:has-text('Add New Store')", state="visible")


def test_create_store_empty_state_button_navigates_to_wizard(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """The empty-state "Create Store" button (shown when no store is
    selected on the stores view) must also land on the wizard."""
    _login_to_stores_view(shared_configured, page)

    # The empty-state button lives inside #store-settings-empty, which is
    # display:none unless loadStoreSettings() decides there's no store.
    # We can't easily get into that state with the configured fixture
    # (which always has one store), so force the button visible and
    # dispatch its click to exercise the same handler the user would.
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_url("**/admin/stores")

    page.evaluate(
        """() => {
            document.getElementById('store-settings-content').style.display = 'none';
            const empty = document.getElementById('store-settings-empty');
            empty.style.display = 'block';
        }"""
    )
    page.locator("#btn-create-store").click()

    page.wait_for_url("**/setup.php?mode=add_store", timeout=10000)
    page.wait_for_selector("h1:has-text('Add New Store')", state="visible")
