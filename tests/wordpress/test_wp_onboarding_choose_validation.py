"""The onboarding chooser's browser-side form validation.

The chooser is ONE form holding both mode radios and the URL-mode
`<input type="url">`. Browsers validate every enabled field in a form on
submit, whatever radio is selected — so any text in the URL field that is
not a scheme-qualified URL (a pasted "pay.example.com", browser autofill)
used to block "Install BareBits alongside WordPress" with the native
"Please enter a URL" bubble. The plugin now disables the URL field while
the install mode is selected (and requires it while URL mode is), which is
inherently browser behavior — hence a real Chromium via Playwright instead
of the requests-driven flow the rest of the suite uses.
"""
from __future__ import annotations

import pytest

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress


def _login_and_open_onboarding(page, wp: WordPressHandle) -> None:
    onboarding = f"{wp.url}/wp-admin/admin.php?page=barebits"
    # The fixture's php -S router cannot serve the bare /wp-admin/ directory,
    # so send the post-login redirect straight to the plugin page.
    page.goto(f"{wp.url}/wp-login.php?redirect_to={onboarding}")
    page.fill("#user_login", WP_ADMIN_USER)
    page.fill("#user_pass", WP_ADMIN_PASSWORD)
    page.click("#wp-submit")
    page.wait_for_selector("#barebits-mode-install")
    # The chooser's behaviors (URL-field sync, maintenance guard, password
    # reveal) live in enqueued script FILES since the wp.org review rework.
    # wait_for_selector resolves as soon as the element parses — possibly
    # before those footer scripts have been fetched and run on a loaded CI
    # host — so wait for the load event, which classic scripts gate, before
    # interacting.
    page.wait_for_load_state()


def test_stale_url_text_does_not_block_install_mode(wordpress, page) -> None:
    """Regression: text left in the URL field must not veto the install
    choice. The merchant types a schemeless address, thinks better of it,
    picks "Install alongside", and must land on the install confirmation."""
    wp = wordpress
    _login_and_open_onboarding(page, wp)

    page.fill("#barebits-server-url", "pay.example.com")
    page.check("#barebits-mode-install")
    # Selecting install mode takes the URL field out of the game entirely.
    assert page.is_disabled("#barebits-server-url")

    page.click("#submit")
    page.wait_for_selector("h2:has-text('Install BareBits alongside WordPress')")
    assert wp.wp_cli("option", "get", "barebits_mode").stdout.strip() == "install"


def test_maintenance_mode_waits_out_the_submit(wordpress, page) -> None:
    """WordPress auto-updates put the whole site behind the .maintenance 503
    screen for up to a minute. A merchant who clicks an onboarding button
    during that window must NOT land on the maintenance screen with their
    choice unsaved — the guard probes first, shows the waiting note, and
    submits the original choice automatically once WordPress answers again
    (the same wait-it-out the setup wizard's return handoff does)."""
    wp = wordpress
    _login_and_open_onboarding(page, wp)
    page.check("#barebits-mode-install")

    # The real thing WordPress core writes: a fresh $upgrading stamp (an
    # empty file would read as >10 minutes old and not trigger maintenance).
    flag = wp.wp_root / ".maintenance"
    flag.write_text("<?php $upgrading = time(); ?>")
    try:
        page.click("#submit")
        page.wait_for_selector("#barebits-maintenance-waiting", state="visible")
        # Still on the chooser: the POST was held back, not swallowed by the
        # maintenance screen.
        assert page.is_visible("#barebits-mode-install")
    finally:
        flag.unlink()

    # The next re-probe (≤5s out) finds WordPress back and submits the held
    # choice — the merchant clicks nothing.
    page.wait_for_selector(
        "h2:has-text('Install BareBits alongside WordPress')", timeout=15_000
    )
    assert wp.wp_cli("option", "get", "barebits_mode").stdout.strip() == "install"


def test_password_reveal_retries_through_maintenance(wordpress, page) -> None:
    """The chooser's reconnect hint reveals the saved admin password over
    admin-ajax. During a WordPress maintenance window that call answers 503
    (as HTML) and used to fail silently — the button did nothing. It must
    show it's waiting and deliver the password once WordPress is back."""
    wp = wordpress
    # Fake a surviving alongside-install record so the chooser renders the
    # reconnect hint with the reveal button.
    wp.wp_cli("option", "update", "barebits_install_dir", str(wp.wp_root / "barebits"))
    wp.wp_cli("option", "update", "barebits_install_url", f"{wp.url}/barebits")
    wp.wp_cli("option", "update", "barebits_admin_password", "guard-test-password")
    _login_and_open_onboarding(page, wp)
    page.wait_for_selector("#barebits-reveal-password")

    flag = wp.wp_root / ".maintenance"
    flag.write_text("<?php $upgrading = time(); ?>")
    try:
        page.click("#barebits-reveal-password")
        page.wait_for_selector("#barebits-reveal-password:has-text('Waiting for WordPress')")
    finally:
        flag.unlink()

    # The next retry (≤5s out) gets through and reveals the password.
    page.wait_for_selector(
        "#barebits-admin-password:has-text('guard-test-password')", timeout=15_000
    )


def test_url_mode_requires_a_url(wordpress, page) -> None:
    """URL mode keeps (and strengthens) the validation: an empty URL field
    never leaves the browser, and the field is live again after switching
    back from install mode."""
    wp = wordpress
    _login_and_open_onboarding(page, wp)

    # Flip to install and back: the field must re-enable.
    page.check("#barebits-mode-install")
    page.check("#barebits-mode-url")
    assert page.is_enabled("#barebits-server-url")

    page.click("#submit")
    # The native `required` bubble held the submit; still on the chooser,
    # nothing chosen server-side.
    assert page.is_visible("#barebits-mode-url")
    message = page.eval_on_selector(
        "#barebits-server-url", "el => el.validationMessage"
    )
    assert message, "expected the browser's required-field message"
    assert wp.wp_cli("option", "get", "barebits_mode", check=False).stdout.strip() == ""
