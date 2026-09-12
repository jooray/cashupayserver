"""Onboarding wizard: the payment-rail branches that persist store settings.

Complements test_setup_wizard_ui.py (the happy path) with the decision points
that write different state:

  - declining Cashu mints pins the per-store strict-no-mint-fallback flag and
    leaves the store without a mint rail at all
  - submarine swaps cannot be enabled without an xpub
  - a CLINK noffer is accepted alongside a Lightning address and ordered after
    it, matching the chain Invoice::create walks
  - add_store mode runs the same screens against an already-configured install
  - navigating Back to the store screen renames the store under construction
    rather than creating a second one

Run with: pytest tests/ui/test_setup_payment_rails_ui.py -v -s
"""
from __future__ import annotations

import sqlite3

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle

pytestmark = pytest.mark.ui

MAINNET_XPUB = (
    "xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLj"
    "Kd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1"
)
# Reference noffer from @shocknet/clink-sdk — decodes to a valid pubkey/relay/
# offer. Only persisted here, never dialled.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)
ADMIN_PW = "wizard-test-pw"


def _rows(payserver: PayserverHandle, sql: str) -> list[sqlite3.Row]:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute(sql).fetchall()
    finally:
        conn.close()


def _walk_to_store(page, payserver: PayserverHandle) -> None:
    """terms → security ack → password → the store-name screen."""
    page.set_default_timeout(30000)
    page.goto(f"{payserver.url}/setup")
    page.check("#terms_legal")
    page.check("#terms_warranty")
    page.check("#terms_fee")
    page.click("button[type=submit]")
    page.check("#security_acknowledged")
    page.click("button[type=submit]")
    page.fill("#password", ADMIN_PW)
    page.fill("#confirm_password", ADMIN_PW)
    page.click("button[type=submit]")
    page.wait_for_selector("#store_name")


def test_declining_mints_pins_strict_mode(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Mintless Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.fill("#onchain_xpub", MAINNET_XPUB)
    page.click("#onchain-validate-btn")
    page.wait_for_selector("#onchain-validation:has-text('Looks good')")
    page.click("#onchain-save-btn")

    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("button:has-text('Wait for 1 confirmation')")
    page.click("button:has-text('Wait for 1 confirmation')")

    page.wait_for_selector("button:has-text('Enable submarine swaps')")
    page.click("button:has-text('Enable submarine swaps')")

    page.wait_for_selector("button:has-text('No thanks, run without mints')")
    page.click("button:has-text('No thanks, run without mints')")

    page.wait_for_selector("h2:has-text('Enable cron')")

    row = _rows(payserver, "SELECT * FROM stores")[0]
    assert row["strict_no_mint_fallback"] == 1, "declining mints must pin strict mode on the store"
    assert not row["mint_url"], "no mint should have been configured"
    assert not row["seed_phrase"], "no mint means no mint wallet seed"
    assert row["onchain_min_confs"] == 1, "one confirmation was chosen"
    assert _rows(payserver, "SELECT * FROM store_mints") == [], "no backup mints either"


def test_swaps_cannot_be_enabled_without_an_xpub(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_store(page, payserver)
    page.fill("#store_name", "No Xpub Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("button:has-text('No thanks')")
    enable = page.locator("button:has-text('Enable submarine swaps')")
    assert enable.is_disabled(), "swaps need an on-chain xpub, so the button must be disabled"
    assert "they need an xpub" in page.content()


def test_noffer_is_stored_after_the_lightning_address(
    payserver_with_lnurlp: PayserverHandle, mint: MintHandle, backup_mint: MintHandle, page
) -> None:
    """Both destination types are accepted on one screen and saved as an
    ordered chain: LNURL address first, noffer as the fallback. Uses the
    lnurlp-backed stack so the setup step's LUD-21 gate passes."""
    payserver = payserver_with_lnurlp
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Noffer Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    page.fill("#lightning_address", "merchant@strike.me")
    page.click("#noffer-section > summary")
    page.fill("#noffer", REFERENCE_NOFFER)
    page.click("button:has-text('Continue')")

    page.wait_for_selector("button:has-text('No thanks')")

    chain = _rows(
        payserver, "SELECT address, type, position FROM store_ln_addresses ORDER BY position ASC"
    )
    assert [(r["type"], r["position"]) for r in chain] == [("lnaddress", 0), ("noffer", 1)]
    assert chain[0]["address"] == "merchant@strike.me"
    assert chain[1]["address"] == REFERENCE_NOFFER


def test_add_another_noffer_button_saves_multiple(
    payserver: PayserverHandle, page
) -> None:
    """The noffer section starts with one input; "+ Add another noffer" adds a
    second row, and both persist as ordered chain rows. Noffers carry no
    save-time LUD-21 probe, so the plain payserver stack suffices. Also pins
    the section's ONLINE warning and the separate-relays helper copy."""
    from fixtures.setup_helpers import SECOND_NOFFER

    _walk_to_store(page, payserver)
    page.fill("#store_name", "Multi Noffer Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    page.click("#noffer-section > summary")
    assert page.locator("#noffer-list input[name='noffers[]']").count() == 1
    content = page.content()
    assert "Your wallet must be ONLINE to receive lightning payments" in content
    assert "two noffers on separate relays" in content

    page.fill("#noffer", REFERENCE_NOFFER)
    page.click("#add-noffer")
    inputs = page.locator("#noffer-list input[name='noffers[]']")
    assert inputs.count() == 2
    assert inputs.nth(1).input_value() == "", "a fresh row must start empty"
    inputs.nth(1).fill(SECOND_NOFFER)
    page.click("button:has-text('Continue')")

    page.wait_for_selector("button:has-text('No thanks')")
    chain = _rows(
        payserver, "SELECT address, type, position FROM store_ln_addresses ORDER BY position ASC"
    )
    assert [(r["type"], r["address"]) for r in chain] == [
        ("noffer", REFERENCE_NOFFER),
        ("noffer", SECOND_NOFFER),
    ]


def test_all_three_destination_types_store_in_chain_order(
    payserver_with_lnurlp: PayserverHandle, mint: MintHandle, backup_mint: MintHandle,
    lnd_mint, channels, page
) -> None:
    """The lightning screen accepts all three destination types at once and
    persists the chain in the order Invoice::create walks it: LNURL address,
    then the NWC connection, then the noffer. Uses the lnurlp-backed stack for
    the LUD-21 gate and a live fake NWC wallet for the save-time probe."""
    import time as _time

    from conftest import SESSION_TMP
    from fixtures.nwc_wallet import start_nwc_stack, stop_nwc_stack

    workdir = SESSION_TMP / f"nwc-wizard-ui-{int(_time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_mint, info_methods=["make_invoice", "lookup_invoice", "get_info"]
    )
    try:
        nwc_uri = stack.wallet.connection_uri()
        payserver = payserver_with_lnurlp
        _walk_to_store(page, payserver)
        page.fill("#store_name", "Three Rails Store")
        page.click("button[type=submit]")

        page.wait_for_selector("#onchain-form")
        page.click("button:has-text('Skip for now')")

        page.wait_for_selector("#lightning_address")
        page.fill("#lightning_address", "merchant@strike.me")
        page.click("#nwc-section > summary")
        page.fill("#nwc", nwc_uri)
        page.click("#noffer-section > summary")
        page.fill("#noffer", REFERENCE_NOFFER)
        page.click("button:has-text('Continue')")

        page.wait_for_selector("button:has-text('No thanks')")

        chain = _rows(
            payserver,
            "SELECT address, type, position FROM store_ln_addresses ORDER BY position ASC",
        )
        assert [(r["type"], r["position"]) for r in chain] == [
            ("lnaddress", 0), ("nwc", 1), ("noffer", 2),
        ]
        assert chain[1]["address"] == nwc_uri
        # The wizard page itself must never re-render the secret.
        secret = nwc_uri.split("secret=", 1)[1].split("&", 1)[0]
        assert secret not in page.content()
    finally:
        stop_nwc_stack(stack)


def test_back_to_store_screen_renames_instead_of_duplicating(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_store(page, payserver)
    page.fill("#store_name", "First Name")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.fill("#onchain_xpub", MAINNET_XPUB)
    page.click("#onchain-validate-btn")
    page.wait_for_selector("#onchain-validation:has-text('Looks good')")
    page.click("#onchain-save-btn")

    # Lightning screen → Back lands on the on-chain screen → Back again on store.
    page.wait_for_selector("#lightning_address")
    page.click("a:has-text('Back')")
    page.wait_for_selector("#onchain-form")
    page.click("a:has-text('Back')")

    page.wait_for_selector("#store_name")
    assert page.input_value("#store_name") == "First Name", "the screen should prefill what was saved"
    page.fill("#store_name", "Renamed Store")
    page.click("button[type=submit]")

    stores = _rows(payserver, "SELECT id, name, onchain_xpub FROM stores")
    assert len(stores) == 1, f"going back must not create a second store, got {len(stores)}"
    assert stores[0]["name"] == "Renamed Store"
    assert stores[0]["onchain_xpub"] == MAINNET_XPUB, "the xpub saved earlier must survive the rename"


def test_add_store_mode_runs_the_same_screens(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle, page
) -> None:
    """add_store starts at the store screen, skips security/password/cron/done,
    and returns to admin once mints are answered."""
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Initial Store")
    page.click("button[type=submit]")
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("button:has-text('No thanks')")
    page.click("button:has-text('No thanks')")
    page.wait_for_selector("button:has-text('No thanks, run without mints')")
    page.click("button:has-text('No thanks, run without mints')")
    page.wait_for_selector("h2:has-text('Enable cron')")

    page.goto(f"{payserver.url}/admin")
    page.fill("#password-input", ADMIN_PW)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    page.goto(f"{payserver.url}/setup?mode=add_store")
    page.wait_for_selector("h1:has-text('Add New Store')")
    # add_store mode has no security/password ahead of it, and the counter
    # starts at five because zero-conf only joins once an on-chain rail exists.
    assert "Step 1 of 5" in page.locator(".subtitle").inner_text()
    page.fill("#store_name", "Second Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("button:has-text('No thanks')")
    page.click("button:has-text('No thanks')")
    page.wait_for_selector("button:has-text('No thanks, run without mints')")
    page.click("button:has-text('No thanks, run without mints')")

    # No mints means no seed was generated, so add_store hands straight back to
    # the admin panel rather than showing cron/done or a hand-off panel.
    page.wait_for_selector("#app", state="visible")
    names = [r["name"] for r in _rows(payserver, "SELECT name FROM stores ORDER BY created_at")]
    assert names == ["Initial Store", "Second Store"]


def test_add_store_with_mints_shows_the_generated_seed_once(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle, page
) -> None:
    """Enabling mints for an added store mints a wallet seed. add_store has no
    completion screen of its own, so without the hand-off panel that seed would
    be created and never shown — unrecoverable if the database is later lost."""
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Host Store")
    page.click("button[type=submit]")
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("button:has-text('No thanks')")
    page.click("button:has-text('No thanks')")
    page.wait_for_selector("button:has-text('No thanks, run without mints')")
    page.click("button:has-text('No thanks, run without mints')")
    page.wait_for_selector("h2:has-text('Enable cron')")

    page.goto(f"{payserver.url}/admin")
    page.fill("#password-input", ADMIN_PW)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    page.goto(f"{payserver.url}/setup?mode=add_store")
    page.wait_for_selector("h1:has-text('Add New Store')")
    page.fill("#store_name", "Minted Store")
    page.click("button[type=submit]")
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("button:has-text('No thanks')")
    page.click("button:has-text('No thanks')")

    page.wait_for_selector("#mint-manual-toggle")
    page.click("#mint-manual-toggle")
    page.fill("#mint_url_manual", mint.url)
    page.fill("#backup_mint_url_manual", backup_mint.url)
    page.click("#mints-continue-btn")

    page.wait_for_selector("h2:has-text('Your store is ready!')")
    shown = page.locator(".seed-display").inner_text().split()
    assert len(shown) == 12, f"expected a 12-word phrase, got {len(shown)}"

    stored = _rows(payserver, "SELECT name, seed_phrase FROM stores WHERE name = 'Minted Store'")[0]
    assert stored["seed_phrase"].split() == shown, "the phrase shown must be the one actually stored"

    page.click("a:has-text('Go to BareBits Admin')")
    page.wait_for_selector("#app", state="visible")


def test_lightning_screen_collapses_nwc_and_noffer_sections(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    """All four destinations live in <details> sections titled Method 1-4.
    The LNURL section (with the Strike help box inside it) starts open;
    Strike API, NWC and noffer start collapsed."""
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Collapsed Rails Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    # The LNURL section renders open, so its input is visible; the NWC and
    # noffer sections render collapsed, so their inputs are hidden.
    assert page.locator("#lnurl-section[open]").count() == 1, "LNURL section must start open"
    assert page.locator("#lightning_address").is_visible()
    assert page.locator("#strike-section[open]").count() == 0, "Strike section must start collapsed"
    assert page.locator("#nwc-section[open]").count() == 0, "NWC section must start collapsed"
    assert page.locator("#noffer-section[open]").count() == 0, "noffer section must start collapsed"
    assert not page.locator("#strike_api_key").is_visible(), "collapsed Strike section must hide its input"
    assert not page.locator("#nwc").is_visible(), "collapsed NWC section must hide its input"
    assert not page.locator("#noffer").is_visible(), "collapsed noffer section must hide its input"

    # The section titles number the methods in fallback order.
    assert "Method 1: LNURL/lightning address" in page.locator("#lnurl-section > summary").inner_text()
    assert "Method 2: Strike API" in page.locator("#strike-section > summary").inner_text()
    assert "Method 3: Nostr Wallet Connect" in page.locator("#nwc-section > summary").inner_text()
    assert "Method 4: noffer (CLINK)" in page.locator("#noffer-section > summary").inner_text()

    # Only one method is required; the intro says so in bold.
    assert (
        "Only one method is needed for lightning payments to work"
        in page.locator("strong", has_text="Only one method").inner_text()
    )

    # The help box sits inside the LNURL section, below its input and above
    # the NWC section.
    order = page.evaluate(
        """() => {
            const before = (a, b) => !!(
                document.querySelector(a).compareDocumentPosition(document.querySelector(b))
                & Node.DOCUMENT_POSITION_FOLLOWING
            );
            return [
                before('#lightning_address', '#ln-help-box'),
                before('#ln-help-box', '#strike-section'),
                before('#strike-section', '#nwc-section'),
                before('#nwc-section', '#noffer-section'),
                !!document.querySelector('#lnurl-section #ln-help-box'),
            ];
        }"""
    )
    assert order == [True, True, True, True, True], f"screen order wrong: {order}"
    assert "Strike API" in page.locator("#ln-help-box").inner_text()

    # Collapsing the LNURL section via its summary hides the input; expanding
    # the others via their summaries reveals theirs.
    page.click("#lnurl-section > summary")
    assert not page.locator("#lightning_address").is_visible(), (
        "the LNURL section must be collapsible"
    )
    page.click("#lnurl-section > summary")
    assert page.locator("#lightning_address").is_visible()
    page.click("#nwc-section > summary")
    assert page.locator("#nwc").is_visible()
    page.click("#noffer-section > summary")
    assert page.locator("#noffer").is_visible()


def test_validation_error_scrolls_back_to_top(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    """A failed validation re-renders the wizard via POST, and browsers restore
    the pre-submit scroll position on that navigation — on the long lightning
    screen the operator sits scrolled past the error banner and never sees why
    the save failed. Rendering an error must pin the view back to the top."""
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Scroll Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    page.click("#nwc-section > summary")
    page.fill("#nwc", "not-a-wallet-connect-string")
    # Submit from the bottom of the page, like an operator who scrolled down
    # to reach the Continue button.
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.click("button:has-text('Continue')")

    page.wait_for_selector("#setup-error")
    assert "nostr+walletconnect://" in page.locator("#setup-error").inner_text()
    # The error render turns browser scroll restoration off and scrolls to the
    # top, so the banner lands inside the viewport.
    assert page.evaluate("history.scrollRestoration") == "manual"
    page.wait_for_function("window.scrollY === 0")
    box = page.locator("#setup-error").bounding_box()
    assert box is not None, "the error banner must be rendered"
    assert box["y"] + box["height"] <= page.viewport_size["height"], (
        "the error banner must be inside the viewport after the auto-scroll"
    )


def test_noffer_section_reopens_when_a_rejected_value_is_echoed(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    """A failed validation re-renders the screen with the operator's noffer
    echoed back; the section must render open so the value the error names
    isn't hidden behind a collapsed toggle."""
    _walk_to_store(page, payserver)
    page.fill("#store_name", "Reopen Store")
    page.click("button[type=submit]")

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    page.wait_for_selector("#lightning_address")
    page.click("#noffer-section > summary")
    page.fill("#noffer", "noffer-not-valid")
    page.click("button:has-text('Continue')")

    page.wait_for_selector(".error")
    assert "noffer1" in page.locator(".error").inner_text()
    assert page.locator("#noffer-section[open]").count() == 1, (
        "the echoed noffer must render its section open"
    )
    assert page.input_value("#noffer") == "noffer-not-valid"
    # The untouched NWC section stays collapsed.
    assert page.locator("#nwc-section[open]").count() == 0
