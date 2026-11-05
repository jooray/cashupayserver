"""On-chain admin buttons must never fail silently.

Regression for the reported bug: on the store settings page, clicking "Save
on-chain settings" (and "Validate & preview") appeared to do nothing. The
server-side derivation is fine for every key type, so the failure mode is a
client-side one: saveOnchain()/validateOnchainXpub() called r.json() with no
guard, so any non-JSON body (a PHP fatal, an expired-session redirect, an empty
500) rejected the promise and left the button dead — no toast, no inline error.

These tests intercept the admin POST and fulfil it with a non-JSON 500 to prove
the UI now surfaces the failure instead of swallowing it.

Run with: pytest tests/ui/test_admin_onchain_error_surfacing_ui.py -v
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui

# A structurally valid mainnet xpub (BIP44 test vector) so client-side checks
# pass and the code reaches the POST we intercept.
_MAINNET_XPUB = (
    "xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2"
    "cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz"
)


def _login_to_stores(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    # Pin the header selector to this test's own store: the shared server
    # auto-selects its first store, and these tests rely on the selected
    # store having no xpub yet (fresh per-test store guarantees that).
    page.select_option("#store-select", configured.store_id)
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#onchain-xpub", state="visible")


def _fulfil_500_for(page, action: str) -> None:
    """Route the admin endpoint so any POST whose body carries `action` comes
    back as a non-JSON 500 (a stand-in for a PHP fatal)."""
    def handler(route):
        post = route.request.post_data or ""
        if f"action={action}" in post:
            route.fulfill(
                status=500,
                content_type="text/html",
                body="<b>Fatal error</b>: something blew up server-side",
            )
        else:
            route.continue_()
    page.route("**/admin*", handler)


def test_save_onchain_surfaces_non_json_500(shared_configured: ConfiguredPayserver, page) -> None:
    _login_to_stores(page, shared_configured)
    _fulfil_500_for(page, "save_onchain")

    # Fresh store has no xpub yet, so no confirm modal — the POST fires directly.
    page.fill("#onchain-xpub", _MAINNET_XPUB)
    page.click("#btn-save-onchain")

    box = page.locator("#onchain-validation-box")
    box.wait_for(state="visible")
    text = box.inner_text()
    assert "Save failed" in text, f"expected a visible failure, got: {text!r}"


def test_validate_preview_surfaces_non_json_500(shared_configured: ConfiguredPayserver, page) -> None:
    _login_to_stores(page, shared_configured)
    _fulfil_500_for(page, "validate_onchain_xpub")

    page.fill("#onchain-xpub", _MAINNET_XPUB)
    page.click("#btn-validate-onchain")

    box = page.locator("#onchain-validation-box")
    box.wait_for(state="visible")
    text = box.inner_text()
    assert "Invalid" in text, f"expected a visible failure, got: {text!r}"
