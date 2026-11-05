"""Settings footer "Check for updates" link is channel-aware.

Stable (main-channel) installs must land on /releases/latest — the newest
stable release, prereleases skipped — while testing-channel installs must get
the full releases listing, the only page their prereleases show on. The anchor
is rendered server-side from Updater::releasesUrl(), so the href changes on
the next page load after the channel is saved.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui

_FOOTER_LINK = '#view-settings .view-footer a[href*="/releases"]'


def _login_and_open_settings(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.click('[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#card-auto-update", state="visible")
    _wait_card_loaded(page)


def _wait_card_loaded(page) -> None:
    # loadAutoUpdateCard() populates the card (and may reset the channel
    # select) when its update_status fetch lands; the cron line filling in
    # marks that load as complete, so interactions after this can't be undone
    # by a late response.
    page.wait_for_function(
        "() => { const c = document.querySelector('#auto-update-cron-line');"
        " return c && c.textContent.includes('update.php'); }"
    )


def test_releases_link_follows_update_channel(
    configured: ConfiguredPayserver, page
) -> None:
    _login_and_open_settings(configured, page)

    # Default channel is main: the footer link skips prereleases.
    href = page.get_attribute(_FOOTER_LINK, "href")
    assert href is not None and href.endswith("/releases/latest"), href

    # Switch to the testing channel through the card UI.
    page.select_option("#auto-update-channel", "testing")
    page.click("#btn-save-update-channel")
    page.wait_for_selector("#toast.show")
    assert "testing" in page.locator("#toast").text_content().lower()

    # Server-rendered link: the change lands on the next page load.
    page.reload()
    page.wait_for_selector("#app", state="visible")
    href = page.get_attribute(_FOOTER_LINK, "href")
    assert href is not None and href.endswith("/releases"), href

    # And back to main -> /releases/latest again.
    page.click('[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#card-auto-update", state="visible")
    _wait_card_loaded(page)
    page.select_option("#auto-update-channel", "main")
    page.click("#btn-save-update-channel")
    page.wait_for_selector("#toast.show")
    page.reload()
    page.wait_for_selector("#app", state="visible")
    href = page.get_attribute(_FOOTER_LINK, "href")
    assert href is not None and href.endswith("/releases/latest"), href
