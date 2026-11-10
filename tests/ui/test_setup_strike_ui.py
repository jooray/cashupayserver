"""Onboarding wizard: the Strike API method on the lightning screen.

  - typing a @strike.me address into the LNURL box raises the live JS
    warning pointing at the Strike API section; clearing it hides it again
  - saving a Strike API key (the save-time probe runs create+quote+read
    against the mock Strike API) persists a type='strike' chain row at
    position 0, and the raw key never renders back into the page
  - a malformed key is rejected server-side with a message that does not
    echo the paste

Run with: pytest tests/ui/test_setup_strike_ui.py -v -s
"""
from __future__ import annotations

import sqlite3

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import browser_walk_to_store
from fixtures.strike_api import TEST_STRIKE_KEY, StrikeApiServer

pytestmark = pytest.mark.ui

ADMIN_PW = "wizard-strike-pw"


def _rows(payserver: PayserverHandle, sql: str) -> list[sqlite3.Row]:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute(sql).fetchall()
    finally:
        conn.close()


def _walk_to_lightning(page, payserver: PayserverHandle, store_name: str) -> None:
    """terms → analytics → installation type ("something else", which keeps
    the Strike API option as a section of the Lightning screen) → safety
    check → password → e-mail skip → store name → skip on-chain → the
    lightning screen."""
    page.set_default_timeout(30000)
    browser_walk_to_store(page, payserver.url, ADMIN_PW, store_name=store_name)
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")


def test_strike_address_warning_is_live(
    payserver_with_strike: PayserverHandle, mint: MintHandle, page
) -> None:
    payserver = payserver_with_strike
    _walk_to_lightning(page, payserver, "Strike Warn Store")

    warning = page.locator("#strike-address-warning")
    assert not warning.is_visible(), "warning hidden before any input"

    page.fill("#lightning_address", "merchant@strike.me")
    assert warning.is_visible(), "typing a @strike.me address raises the warning"
    assert "Strike API method below" in warning.inner_text()

    # Case-insensitive detection.
    page.fill("#lightning_address", "merchant@STRIKE.ME")
    assert warning.is_visible(), "detection is case-insensitive"

    # The warning's link opens the collapsed Strike section.
    assert page.locator("#strike-section[open]").count() == 0
    page.click("#strike-address-warning-link")
    assert page.locator("#strike-section[open]").count() == 1, "link opens the Strike section"

    page.fill("#lightning_address", "merchant@other-wallet.com")
    assert not warning.is_visible(), "a non-Strike address clears the warning"


def test_strike_key_saves_as_chain_head(
    payserver_with_strike: PayserverHandle, strike_api: StrikeApiServer,
    mint: MintHandle, page
) -> None:
    payserver = payserver_with_strike
    _walk_to_lightning(page, payserver, "Strike Key Store")

    # Collapsed by default; open and paste the key.
    assert page.locator("#strike-section[open]").count() == 0, "Strike section starts collapsed"
    page.click("#strike-section > summary")
    page.fill("#strike_api_key", TEST_STRIKE_KEY)
    page.click("button[type=submit]:has-text('Continue')")

    # The save-time probe ran against the mock (one 1-sat test invoice) and
    # the wizard advanced to the mints screen (no swaps screen any more).
    page.wait_for_selector("h2:has-text('Cashu mints')")
    assert len(strike_api.invoices) == 1, "probe issued exactly one test invoice"

    rows = _rows(payserver, "SELECT type, address, position FROM store_ln_addresses ORDER BY position")
    assert [(r["type"], r["address"], r["position"]) for r in rows] == [
        ("strike", TEST_STRIKE_KEY, 0)
    ], [dict(r) for r in rows]


def test_malformed_key_rejected_without_echo(
    payserver_with_strike: PayserverHandle, mint: MintHandle, page
) -> None:
    payserver = payserver_with_strike
    _walk_to_lightning(page, payserver, "Strike Bad Key Store")

    page.click("#strike-section > summary")
    bogus = "not a real key !!!"
    page.fill("#strike_api_key", bogus)
    page.click("button[type=submit]:has-text('Continue')")

    page.wait_for_selector("text=That Strike API key doesn't look right")
    # The paste is never echoed back into the re-rendered page.
    assert bogus not in page.content(), "a rejected key paste must not be echoed"
    assert _rows(payserver, "SELECT * FROM store_ln_addresses") == []
