"""wordpress.org review gate (2026-09 submission feedback), live half.

The static tests in test_wp_plugin_check.py prove the auth calls exist in the
source; these prove they bite over HTTP against a real WordPress:

  - every nonce-protected admin-post handler refuses a POST without (or with
    a forged) nonce, and stores nothing
  - the same POST from a logged-out client is inert
  - the pairing callback — nonce-exempt by design — refuses a forged state
    token, burns the real one doing so (single use), and stores nothing
  - the reveal-password ajax endpoint never leaks the password without a
    valid nonce

No payserver is needed: everything asserted here refuses before any server
contact would happen.
"""
from __future__ import annotations

from urllib.parse import parse_qs, urlparse

import pytest
import requests

from wordpress.conftest import onboarding_page, page_nonces, wp_login, wp_option
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

# One representative per handler shape: the mode chooser (writes options from
# input), finish (writes the discount), reset (deletes options), and the
# Connection-page discount save. All share the inline capability+nonce
# preamble the static gate enforces.
PROTECTED_ACTIONS = {
    "barebits_choose_mode": {"barebits_mode": "url", "barebits_server_url": "https://evil.example"},
    "barebits_finish": {"barebits_discount_percent": "99"},
    "barebits_reset_onboarding": {},
    "barebits_save_discount": {"barebits_discount_percent": "99"},
}


def _assert_nothing_stored(wp: WordPressHandle) -> None:
    assert wp_option(wp, "barebits_mode") == ""
    assert wp_option(wp, "barebits_server_url") == ""
    assert wp_option(wp, "barebits_discount_percent") == ""


def test_admin_post_refused_without_nonce(wordpress) -> None:
    wp = wordpress
    s = wp_login(wp)
    for action, data in PROTECTED_ACTIONS.items():
        for extra in ({}, {"_wpnonce": "0123456789"}):  # missing, then forged
            r = s.post(
                f"{wp.url}/wp-admin/admin-post.php",
                data={"action": action, **data, **extra},
                timeout=30,
                allow_redirects=False,
            )
            assert r.status_code == 403, (
                f"{action} with {'forged' if extra else 'no'} nonce answered "
                f"{r.status_code}, expected 403: {r.text[:200]}"
            )
    _assert_nothing_stored(wp)


def test_admin_post_inert_when_logged_out(wordpress) -> None:
    """No nopriv registration exists for the protected actions, so a
    cookie-less POST fires no handler at all — nothing stored, no 500s."""
    wp = wordpress
    for action, data in PROTECTED_ACTIONS.items():
        r = requests.post(
            f"{wp.url}/wp-admin/admin-post.php",
            data={"action": action, **data},
            timeout=30,
            allow_redirects=False,
        )
        assert r.status_code < 500, f"{action} logged out -> {r.status_code}"
    _assert_nothing_stored(wp)


def test_pairing_callback_refuses_forged_state(wordpress) -> None:
    wp = wordpress

    # With no pairing in flight there is no expected state: any state fails.
    r = requests.post(
        f"{wp.url}/wp-admin/admin-post.php?action=barebits_pairing_callback&state=" + "ab" * 16,
        data={"apiKey": "f" * 64, "storeId": "store_attacker"},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text[:300]

    # Start a real pairing to mint a state token. The server URL never gets
    # contacted by start_pairing (it only builds the redirect), so a dead
    # address is fine — everything asserted here refuses locally.
    wp.wp_cli("option", "update", "barebits_mode", "url")
    wp.wp_cli("option", "update", "barebits_server_url", "http://127.0.0.1:9")
    s = wp_login(wp)
    nonces = page_nonces(onboarding_page(s, wp))
    r = s.post(
        f"{wp.url}/wp-admin/admin-post.php",
        data={"action": "barebits_start_pairing", "_wpnonce": nonces["barebits_start_pairing"]},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code == 302, r.text[:300]
    callback = parse_qs(urlparse(r.headers["Location"]).query)["redirect"][0]
    state = parse_qs(urlparse(callback).query)["state"][0]

    # A forged state is refused with 403 and stores nothing.
    forged = callback.replace(state, "ef" * 16)
    r = requests.post(
        forged,
        data={"apiKey": "f" * 64, "storeId": "store_attacker"},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text[:300]
    assert wp_option(wp, "barebits_api_key") == ""
    assert wp_option(wp, "barebits_store_id") == ""

    # ...and burns the expected token doing so: the REAL state is now dead
    # too (single use, success or not), so the guess had exactly one shot.
    r = requests.post(
        callback,
        data={"apiKey": "f" * 64, "storeId": "store_attacker"},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text[:300]
    assert wp_option(wp, "barebits_api_key") == ""


def test_reveal_password_ajax_needs_nonce(wordpress) -> None:
    wp = wordpress
    sentinel = "hunter2-sentinel-password"
    wp.wp_cli("option", "update", "barebits_admin_password", sentinel)

    # Logged in but no nonce: check_ajax_referer dies before the handler.
    s = wp_login(wp)
    r = s.post(
        f"{wp.url}/wp-admin/admin-ajax.php",
        data={"action": "barebits_reveal_password"},
        timeout=30,
    )
    assert sentinel not in r.text, "password leaked without a nonce"

    # Logged out entirely: no nopriv registration, WP refuses the action.
    r = requests.post(
        f"{wp.url}/wp-admin/admin-ajax.php",
        data={"action": "barebits_reveal_password", "nonce": "0123456789"},
        timeout=30,
    )
    assert sentinel not in r.text, "password leaked to a logged-out client"
