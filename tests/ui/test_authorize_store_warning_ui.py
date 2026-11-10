"""The pairing chooser and admin store selector for stores without mints.

A store can decline mints in the wizard ("No thanks, run without mints") and
run entirely over Lightning or on-chain rails — or, temporarily, over no
rail at all. The pairing chooser at /api-keys/authorize.php used to filter
on the mint columns, so exactly those stores were told "No stores found"
and could not pair the WordPress plugin. Now every store is listed; one
with no payment rail at all is flagged inline and selecting it surfaces a
warning that checkouts will fail until a rail is added. The admin header's
store selector keys its "(not configured)" suffix on the same
has-any-payment-rail check instead of the mint columns.
"""
from __future__ import annotations

import uuid

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.setup_helpers import SetupWizard, wizard_error

pytestmark = pytest.mark.ui


def _create_zero_rail_store(configured: ConfiguredPayserver, store_name: str) -> str:
    """Walk the add_store wizard declining every rail (onchain skip,
    lightning skip, mints declined, wallet tabs skipped); returns the new
    store id."""
    s = requests.Session()
    r = s.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": configured.admin_password},
        timeout=15,
    )
    assert r.status_code == 200 and r.json().get("success") is True, r.text[:200]

    # The wizard's POSTs carry the session's CSRF token; SetupWizard lifts it
    # from the priming GET (add_store mode, admin session).
    w = SetupWizard(configured.handle.url, mode="add_store", session=s)

    def post(**data) -> None:
        body = w.post(**data)
        assert wizard_error(body) is None, wizard_error(body)

    post(step="store", store_name=store_name, default_currency="sat")
    post(step="onchain", onchain_action="skip")
    post(step="lightning", lightning_action="skip")
    post(step="mints", mints_enabled="0")
    # wallet_tabs is the last add_store screen; answering it hands off (302).
    w.post(step="wallet_tabs", wallet_tabs_action="skip")

    stores = configured.admin.list_stores()
    matches = [st for st in stores if st["name"] == store_name]
    assert len(matches) == 1, f"expected the zero-rail store to exist, got {matches}"
    return matches[0]["id"]


def test_chooser_lists_mintless_store_and_warns_on_zero_rail(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    zero_rail_id = _create_zero_rail_store(
        configured, f"norail-{uuid.uuid4().hex[:6]}"
    )

    page.set_default_timeout(15_000)
    page.goto(
        f"{configured.handle.url}/api-keys/authorize.php"
        "?applicationName=Warn%20Test&strict=true"
        "&permissions=btcpay.store.cancreateinvoice"
    )
    page.fill("#username", "admin")
    page.fill("#password", configured.admin_password)
    page.click("button:has-text('Sign In')")
    page.wait_for_selector("#store_id")

    # Both stores are listed — the zero-rail one flagged inline.
    zero_opt = page.locator(f"#store_id option[value='{zero_rail_id}']")
    assert "(no payment methods yet)" in zero_opt.inner_text()
    mint_opt = page.locator(f"#store_id option[value='{configured.store_id}']")
    assert "no payment methods" not in mint_opt.inner_text()

    # Selecting the zero-rail store surfaces the warning; a store with a
    # rail hides it again.
    page.select_option("#store_id", zero_rail_id)
    page.wait_for_selector("#no-rail-warning", state="visible")
    page.select_option("#store_id", configured.store_id)
    page.wait_for_selector("#no-rail-warning", state="hidden")

    # The warning is advisory: approving for the zero-rail store still works.
    page.select_option("#store_id", zero_rail_id)
    page.click("button:has-text('Authorize')")
    page.wait_for_selector("body:has-text('Authorization Successful')")


def test_admin_selector_labels_only_zero_rail_stores(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    zero_rail_id = _create_zero_rail_store(
        configured, f"norail-{uuid.uuid4().hex[:6]}"
    )

    page.set_default_timeout(15_000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#store-select') && "
        "document.querySelector('#store-select').options.length > 0"
    )

    zero_opt = page.locator(f"#store-select option[value='{zero_rail_id}']")
    assert "(not configured)" in zero_opt.inner_text()
    mint_opt = page.locator(f"#store-select option[value='{configured.store_id}']")
    assert "not configured" not in mint_opt.inner_text()
