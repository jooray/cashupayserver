"""Playwright drives the onboarding wizard click-by-click.

Covers the full standalone happy path with an on-chain xpub, so the zero-conf
screen is part of the sequence, and finishes through the cron reminder to the
completion screen.
"""
from __future__ import annotations

import sqlite3

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle

pytestmark = pytest.mark.ui

# Mainnet BIP44-prefixed key. The wizard infers mainnet from the prefix and
# defaults to P2WPKH; no network or address-type control is shown.
MAINNET_XPUB = (
    "xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLj"
    "Kd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1"
)
LIGHTNING_ADDRESS = "awesomemerchant@strike.me"


def _store_row(payserver: PayserverHandle) -> sqlite3.Row:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores LIMIT 1").fetchone()
    finally:
        conn.close()


def _config_value(payserver: PayserverHandle, key: str) -> str | None:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def test_setup_wizard_completes_in_browser(
    payserver_with_lnurlp: PayserverHandle,
    mint: MintHandle,
    backup_mint: MintHandle,
    page,
) -> None:
    # The lnurlp-backed stack routes the typed Lightning address to the mock
    # LNURL host (which serves a LUD-21 verify URL), so the setup step's
    # save-time gate passes.
    payserver = payserver_with_lnurlp
    page.set_default_timeout(30000)
    page.goto(f"{payserver.url}/setup")

    # terms: accept the first-run terms-of-service gate.
    page.check("#terms_legal")
    page.check("#terms_warranty")
    page.check("#terms_fee")
    page.click("button[type=submit]")

    # security: acknowledge the database-exposure check.
    page.check("#security_acknowledged")
    page.click("button[type=submit]")

    # password.
    page.fill("#password", "wizard-test-pw")
    page.fill("#confirm_password", "wizard-test-pw")
    page.click("button[type=submit]")

    # store name.
    page.fill("#store_name", "Browser Store")
    page.click("button[type=submit]")

    # onchain: paste an xpub, check it, continue. The Continue button stays
    # disabled until the address preview has been fetched.
    page.wait_for_selector("#onchain-form")
    page.fill("#onchain_xpub", MAINNET_XPUB)
    page.click("#onchain-validate-btn")
    page.wait_for_selector("#onchain-validation:has-text('Looks good')")
    assert page.locator("#onchain-testnet-row").is_hidden(), (
        "the test-network picker must stay hidden for a mainnet xpub"
    )
    page.click("#onchain-save-btn")

    # lightning: an LNURL address, no noffer.
    page.wait_for_selector("#lightning_address")
    page.fill("#lightning_address", LIGHTNING_ADDRESS)
    page.click("button:has-text('Continue')")

    # zeroconf: after lightning (where Strike on-chain — the other possible
    # receive source — is answered), present because an xpub was saved.
    page.wait_for_selector("button:has-text('Enable zero-conf')")
    page.click("button:has-text('Enable zero-conf')")

    # swaps: enabled (permitted because the store has an xpub).
    page.wait_for_selector("button:has-text('Enable submarine swaps')")
    page.click("button:has-text('Enable submarine swaps')")

    # mints: skip auto-discovery (no Nostr relays reachable from the test
    # sandbox) and enter the two local mints by hand.
    page.wait_for_selector("#mint-manual-toggle")
    page.click("#mint-manual-toggle")
    page.fill("#mint_url_manual", mint.url)
    page.fill("#backup_mint_url_manual", backup_mint.url)
    page.click("#mints-continue-btn")

    # cron reminder, then completion.
    page.wait_for_selector("h2:has-text('Enable cron')")
    assert "X-CRON-KEY" in page.content(), "the cron screen should show a ready-made crontab line"
    page.click("button:has-text('Continue')")
    page.wait_for_selector("h2:has-text(\"You're all set!\")")

    # The seed is generated silently and revealed once, here.
    assert page.locator(".seed-display").count() == 1, "recovery phrase should appear on the final screen"
    seed_words = page.locator(".seed-display").inner_text().split()
    assert len(seed_words) == 12, f"expected a 12-word phrase, got {len(seed_words)}"

    assert _config_value(payserver, "setup_complete") == "true"
    # The swaps step is store-only now: enabling it must write the store flag
    # (asserted below) without flipping any site-wide config key.
    assert _config_value(payserver, "swaps_enabled") is None, (
        "the wizard must not write the retired site-wide swaps_enabled key"
    )

    row = _store_row(payserver)
    assert row["name"] == "Browser Store"
    assert row["onchain_xpub"] == MAINNET_XPUB
    assert row["onchain_network"] == "mainnet"
    assert row["onchain_address_type"] == "P2WPKH"
    assert row["onchain_min_confs"] == 0, "zero-conf was chosen"
    assert row["swaps_enabled"] == 1
    assert row["mint_url"].rstrip("/") == mint.url.rstrip("/")
    assert row["mint_unit"] == "sat"
    assert row["seed_phrase"], "a seed should have been generated for the mint wallet"
    # Declining mints is what pins strict mode; enabling them leaves the
    # column at its schema default (-1, a legacy value that resolves to
    # "allow mint fallback").
    assert row["strict_no_mint_fallback"] == -1


def test_setup_wizard_without_onchain_skips_zeroconf(
    payserver: PayserverHandle,
    mint: MintHandle,
    page,
) -> None:
    """Skipping the on-chain screen removes zero-conf from the sequence — it
    has nothing to apply to — and shortens the step counter accordingly."""
    page.set_default_timeout(30000)
    page.goto(f"{payserver.url}/setup")
    page.check("#terms_legal")
    page.check("#terms_warranty")
    page.check("#terms_fee")
    page.click("button[type=submit]")
    page.check("#security_acknowledged")
    page.click("button[type=submit]")
    page.fill("#password", "wizard-test-pw")
    page.fill("#confirm_password", "wizard-test-pw")
    page.click("button[type=submit]")
    page.fill("#store_name", "No Onchain Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    assert "of 10" in page.locator(".subtitle").inner_text(), (
        "without an on-chain rail the wizard should advertise 10 screens, not 11"
    )
    page.click("button:has-text('Skip for now')")

    # Straight to Lightning — no zero-conf screen in between.
    page.wait_for_selector("#lightning_address")


def test_outside_webroot_skips_security_screen_but_still_detects_url_mode(
    page,
) -> None:
    """With the data directory outside the web root the security screen is
    dropped from the flow — terms lands straight on the password screen — but
    the URL-mode probe that screen normally hosts must still run: it moves to
    the terms screen (silently) and saves a working url_mode."""
    import tempfile
    import time
    import uuid
    from pathlib import Path

    from conftest import SESSION_TMP
    from fixtures.payserver import start_payserver, stop_payserver

    workdir = SESSION_TMP / f"outside-webroot-ui-{uuid.uuid4().hex[:8]}"
    with tempfile.TemporaryDirectory(prefix="cashupay-data-") as outside:
        handle = start_payserver(workdir, extra_env={"CASHUPAY_DATA_DIR": outside})
        try:
            page.set_default_timeout(30000)
            page.goto(f"{handle.url}/setup")

            # The terms screen hosts the silent URL-mode probe now; give the
            # fetch round-trips a moment and check the config row landed.
            db = Path(outside) / "cashupay.sqlite"
            deadline = time.time() + 20
            mode = None
            while time.time() < deadline:
                conn = sqlite3.connect(db)
                try:
                    row = conn.execute(
                        "SELECT value FROM config WHERE key = 'url_mode'"
                    ).fetchone()
                finally:
                    conn.close()
                if row:
                    mode = row[0]
                    break
                time.sleep(0.5)
            assert mode is not None, "the terms screen must save a detected url_mode"
            assert mode in ('"clean"', '"direct"', '"router"', "clean", "direct", "router"), mode

            # Terms → password directly; no security screen in the flow.
            page.check("#terms_legal")
            page.check("#terms_warranty")
            page.check("#terms_fee")
            page.click("button[type=submit]")
            page.wait_for_selector("#password")
            assert "of 9" in page.locator(".subtitle").inner_text(), (
                "skipping the security screen should advertise 9 screens"
            )
        finally:
            stop_payserver(handle)
