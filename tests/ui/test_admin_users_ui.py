"""Playwright tests for the Users section of the admin Settings panel.

Covers the admin-only user management UI: list, add, reset password,
reset PIN, delete. Also confirms a non-admin user does not see the
Users card.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import AdminClient
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _login(page, base_url: str, username: str, password: str) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{base_url}/admin")
    page.fill("#username-input", username)
    page.fill("#password-input", password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _open_settings(page) -> None:
    page.click('.nav-item[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#view-settings.active")


def test_admin_sees_users_card(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    # The admin's account card should appear, with role 'admin'.
    page.wait_for_selector("#card-my-account")
    assert "admin" in page.locator("#my-role-badge").text_content()
    # The Users card should be visible for admins.
    page.wait_for_selector("#card-users:not(.hidden)")
    # The admin user should be listed.
    page.wait_for_selector("#users-list .list-item")
    assert "admin" in page.locator("#users-list").text_content()


def test_admin_can_create_and_delete_user(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.wait_for_selector("#card-users:not(.hidden)")

    # Add user via modal.
    page.click("#btn-add-user")
    page.fill("#au-username", "alice")
    page.fill("#au-password", "alicepw1234")
    page.select_option("#au-role", "user")
    page.click("#btn-confirm-add-user")

    # Wait for the new row.
    page.wait_for_function("() => document.body.innerText.includes('alice')",
                           timeout=5000)

    # Delete it (confirm dialog).
    page.once("dialog", lambda d: d.accept())
    # Find Delete button in the row whose text includes 'alice'.
    page.evaluate(
        """() => {
            const items = document.querySelectorAll('#users-list .list-item');
            for (const it of items) {
                if (it.innerText.includes('alice')) {
                    it.querySelector('button.btn-danger').click();
                    return;
                }
            }
        }"""
    )

    page.wait_for_function(
        "() => !document.querySelector('#users-list').innerText.includes('alice')",
        timeout=5000,
    )


def test_admin_cannot_see_delete_for_self(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.wait_for_selector("#users-list .list-item")

    # The row containing 'admin' must NOT have a Delete button — the UI hides
    # it for the current user (server also enforces this; this is the UI side).
    has_self_delete = page.evaluate(
        """() => {
            const items = document.querySelectorAll('#users-list .list-item');
            for (const it of items) {
                if (it.innerText.startsWith('admin')) {
                    return !!it.querySelector('button.btn-danger');
                }
            }
            return false;
        }"""
    )
    assert has_self_delete is False, "self row should not show a Delete button"


def test_non_admin_does_not_see_users_card(
    configured: ConfiguredPayserver, page,
) -> None:
    # Create a non-admin via API first.
    configured.admin._post_action(
        "create_user", username="bob", password="bobpw1234abc", role="user"
    )
    _login(page, configured.handle.url, "bob", "bobpw1234abc")
    _open_settings(page)
    # My Account card should still appear.
    page.wait_for_selector("#card-my-account")
    assert "user" in page.locator("#my-role-badge").text_content()
    # Users card should remain hidden.
    is_hidden = page.evaluate(
        "() => document.getElementById('card-users').classList.contains('hidden')"
    )
    assert is_hidden, "non-admin must not see the Users card"


def test_admin_reset_other_users_password_lets_them_login(
    configured: ConfiguredPayserver, page,
) -> None:
    """Admin uses the Reset password UI on another user; that user can then
    log in with the new password."""
    configured.admin._post_action(
        "create_user", username="carol", password="carolpw1234", role="user"
    )

    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.wait_for_selector("#users-list .list-item")

    # Click Reset password for carol's row.
    page.evaluate(
        """() => {
            const items = document.querySelectorAll('#users-list .list-item');
            for (const it of items) {
                if (it.innerText.includes('carol')) {
                    const buttons = it.querySelectorAll('button');
                    for (const b of buttons) {
                        if (b.innerText.includes('Reset password')) { b.click(); return; }
                    }
                }
            }
        }"""
    )
    page.wait_for_selector("#modal-reset-user-password.visible")
    page.fill("#rup-new", "carolnew99XYZ")
    page.click("#btn-confirm-reset-user-password")
    page.wait_for_selector("#modal-reset-user-password.visible", state="hidden")

    # Carol can log in with the new password via the API client.
    carol = AdminClient(configured.handle.url)
    carol.login("carolnew99XYZ", username="carol")
    assert carol.csrf_token
