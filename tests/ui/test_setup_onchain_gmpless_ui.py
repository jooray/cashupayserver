"""Playwright drives the wizard's on-chain screen against a GMP-less server.

The original bug: on shared hosts whose PHP lacks ext-gmp, "Check my xpub"
surfaced "could not reach the server" (the endpoint fataled to HTML and the
JS conflated that with a network failure), and a valid pasted static address
was rejected as unreadable. This pins the fixed browser behaviour: the check
button shows the actionable php-gmp message, and the static-address path
works end-to-end.
"""
from __future__ import annotations

import sqlite3
import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver

pytestmark = pytest.mark.ui

MAINNET_XPUB = (
    "xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLj"
    "Kd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1"
)
REPORTED_ADDRESS = "bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq"

GMP_FUNCS = (
    "gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,gmp_strval,"
    "gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,gmp_and,gmp_or,"
    "gmp_setbit,gmp_testbit,gmp_neg"
)


@pytest.fixture()
def gmpless_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-gmpless-ui-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_php_args=["-d", f"disable_functions={GMP_FUNCS}"],
    )
    yield handle
    stop_payserver(handle)


def test_onchain_screen_on_gmpless_host(
    gmpless_payserver: PayserverHandle, page
) -> None:
    page.set_default_timeout(30000)
    page.goto(f"{gmpless_payserver.url}/setup")

    page.check("#terms_legal")
    page.check("#terms_warranty")
    page.check("#terms_fee")
    page.click("button[type=submit]")

    # security screen: the capability heads-up renders alongside the ack.
    assert page.locator("text=GMP extension").first.is_visible()
    page.check("#security_acknowledged")
    page.click("button[type=submit]")

    page.fill("#password", "wizard-test-pw")
    page.fill("#confirm_password", "wizard-test-pw")
    page.click("button[type=submit]")

    page.fill("#store_name", "GMP-less Browser Store")
    page.click("button[type=submit]")

    # onchain: warning shown, static mode preselected on a GMP-less host.
    page.wait_for_selector("#onchain-form")
    assert page.locator("text=Xpub wallets won't work on this server").is_visible()
    assert page.locator("#onchain-static-row").is_visible()
    assert page.locator("#onchain-xpub-row").is_hidden()

    # Switch to xpub mode anyway and run the check: the box must show the
    # actionable php-gmp message — NOT "Could not reach the server".
    page.click("#onchain-mode-toggle")
    page.fill("#onchain_xpub", MAINNET_XPUB)
    page.click("#onchain-validate-btn")
    page.wait_for_selector("#onchain-validation:has-text('GMP')")
    box_text = page.locator("#onchain-validation").inner_text()
    assert "Could not reach the server" not in box_text
    assert page.locator("#onchain-save-btn").is_disabled()

    # Back to static mode: the reported address saves and the wizard advances
    # (to lightning; zeroconf follows that screen since the sequence reorder).
    page.click("#onchain-mode-toggle")
    page.fill("#onchain_static_address", REPORTED_ADDRESS)
    page.click("#onchain-save-btn")
    page.wait_for_selector("h2:has-text('Lightning payments')")

    conn = sqlite3.connect(gmpless_payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute("SELECT * FROM stores LIMIT 1").fetchone()
    finally:
        conn.close()
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_static_address"] == REPORTED_ADDRESS
