"""Playwright drives the setup wizard click-by-click."""
from __future__ import annotations

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle

pytestmark = pytest.mark.ui


def test_setup_wizard_completes_in_browser(
    payserver: PayserverHandle,
    mint: MintHandle,
    page,
) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{payserver.url}/setup")

    # Step 1: security ack.
    page.check("#security_acknowledged")
    page.click("button[type=submit]")

    # Step 2: admin password.
    page.fill("#password", "wizard-test-pw")
    page.fill("#confirm_password", "wizard-test-pw")
    page.click("button[type=submit]")

    # Step 4: store name.
    page.fill("#store_name", "Browser Store")
    page.click("button[type=submit]")

    # Step 5a: mint URL.
    page.fill("#mint_url", mint.url)
    page.click("button[type=submit]")

    # Step 5b: unit selector — pick 'sat' and submit.
    page.wait_for_selector("#mint_unit")
    page.select_option("#mint_unit", "sat")
    page.click("#continue-btn")

    # Step 6: generate a fresh seed.
    page.wait_for_selector("button[type=submit]")
    # "Generate New Seed Phrase" — the page shows this if no seed exists yet.
    page.click("button:has-text('Generate New Seed Phrase')")
    # The generated seed flow shows a confirmation checkbox.
    page.wait_for_selector("#seed_confirmed")
    page.check("#seed_confirmed")
    page.click("button[type=submit]")

    # Step 7: completion. Either a "Go to admin" link or admin redirect.
    # The setup_complete config flag should now be set.
    import sqlite3
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute(
            "SELECT value FROM config WHERE key = 'setup_complete'"
        ).fetchone()
    finally:
        conn.close()
    assert row is not None, "setup_complete should be written after the final step"
