"""Onboarding wizard: server-side step machine and validation.

Browser coverage lives in tests/ui/test_setup_wizard_ui.py, and whole-flow
choice combinations in test_setup_onboarding_matrix.py. This file pins the
step machine itself — sequencing, validation messages, and the store-identity
rules — over plain HTTP, so failures point at the handler rather than at a
selector or at a combination.

Only a payserver is needed: every path here skips or declines the mint screen,
so no LND/nutshell stack has to come up.
"""
from __future__ import annotations

import sqlite3

from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import (
    MAINNET_ADDRESS,
    MAINNET_XPUB,
    REFERENCE_NOFFER,
    SECOND_NOFFER,
    SetupWizard,
    TESTNET_TPUB,
    wizard_error as _error,
    wizard_heading as _heading,
)

ADMIN_PW = SetupWizard.DEFAULT_PASSWORD


def _stores(handle: PayserverHandle) -> list[sqlite3.Row]:
    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores").fetchall()
    finally:
        conn.close()


def Wizard(handle: PayserverHandle) -> SetupWizard:
    """Adapter kept so the cases below read as before."""
    return SetupWizard(handle.url)


def test_full_flow_persists_every_answer(
    payserver_with_lnurlp: PayserverHandle,
) -> None:
    # The lightning step's LUD-21 gate probes the typed address; the lnurlp
    # stack routes it to the mock host, which serves a verify URL.
    payserver = payserver_with_lnurlp
    w = Wizard(payserver)
    body = w.through_store("Persisted Store")
    assert _heading(body) == "On-chain Bitcoin payments"

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="xpub",
        onchain_xpub=MAINNET_XPUB,
    )
    # Saving an on-chain destination pulls zero-conf into the sequence — it
    # sits after the lightning screen (where the last possible on-chain
    # receive source, Strike on-chain minting, is answered).
    assert _heading(body) == "Lightning payments"
    assert "of 11" in body

    body = w.post(step="lightning", lightning_action="save", lightning_address="merchant@strike.me")
    assert _heading(body) == "Zero-conf payments"
    # The zero-conf risk mention links out to the help article explaining it.
    assert (
        '<a href="https://getbarebits.com/help/Understanding%20Bitcoin/'
        'Zero-conf%20transactions/" target="_blank" rel="noopener"'
    ) in body, "the zero-conf screen must link the gaming risk to the help article"
    assert "could try to game it</a>" in body

    body = w.post(step="zeroconf", zero_conf="1")
    assert _heading(body) == "Submarine swaps"

    body = w.post(step="swaps", swaps_enabled="1")
    assert _heading(body) == "Cashu mints"

    # Decline mints — the mint screen is where setup_complete flips.
    body = w.post(step="mints", mints_enabled="0")
    assert _heading(body) == "Enable cron"
    assert "X-CRON-KEY" in body, "the cron screen should render a ready-made crontab line"

    body = w.post(step="cron")
    assert _heading(body) == "You&#039;re all set!" or "all set" in _heading(body)
    # A stray PHP close tag once leaked onto this screen as literal text.
    assert "?>" not in body, "the completion screen must not render a bare ?> as text"

    row = _stores(payserver)[0]
    assert row["name"] == "Persisted Store"
    assert row["onchain_xpub"] == MAINNET_XPUB
    assert row["onchain_network"] == "mainnet"
    assert row["onchain_address_type"] == "P2WPKH"
    assert row["onchain_min_confs"] == 0
    assert row["swaps_enabled"] == 1
    assert row["strict_no_mint_fallback"] == 1, "declining mints pins strict mode"
    assert row["auto_melt_enabled"] == 1, "a Lightning destination enables threshold cashout"
    assert row["auto_melt_use_swap"] == 0, "and it sweeps over Lightning, not via a swap"
    assert not row["mint_url"] and not row["seed_phrase"]


def test_skipping_onchain_removes_the_zeroconf_screen(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    body = w.through_store("Skip Onchain")
    assert "of 10" in body, "without an on-chain rail the wizard is 10 screens, not 11"

    body = w.post(step="onchain", onchain_action="skip")
    assert _heading(body) == "Lightning payments"

    # No Strike on-chain answer on the lightning screen either, so there is
    # still nothing for zero-conf to time and the screen stays out.
    body = w.post(step="lightning", lightning_action="skip")
    assert _heading(body) == "Submarine swaps", "zero-conf must be skipped with nothing to time"

    # Reaching it directly still renders (it is a known slug) but nothing
    # downstream depends on that; the sequence simply never routes there.
    assert _stores(payserver)[0]["onchain_min_confs"] == 1, "the default is left untouched"


def test_strike_only_store_is_asked_the_zeroconf_question(
    payserver_with_strike: PayserverHandle,
) -> None:
    """A store with ONLY a Strike API key (no xpub/static address) still gets
    on-chain receive addresses — minted in the merchant's Strike account and
    settled by the chain watcher against onchain_min_confs — so enabling
    Strike on-chain on the lightning screen must pull the zero-conf screen
    into the sequence. Before the reorder this store could never opt into
    zero-conf: the screen sat before lightning and dropped out for lack of an
    address destination."""
    from fixtures.strike_api import TEST_STRIKE_KEY

    payserver = payserver_with_strike
    w = Wizard(payserver)
    body = w.through_store("Strike Only")
    assert "of 10" in body, "no on-chain receive source yet, so no zeroconf screen"

    body = w.post(step="onchain", onchain_action="skip")
    assert _heading(body) == "Lightning payments"

    # A Strike key alone (checkbox unticked) is a Lightning-only rail:
    # nothing mints on-chain addresses, so zero-conf still has no subject.
    body = w.post(
        step="lightning", lightning_action="save",
        strike_api_key=TEST_STRIKE_KEY,
    )
    assert _heading(body) == "Submarine swaps", "a Strike key without on-chain must not add zeroconf"

    # Back to lightning; this time tick "also accept on-chain via Strike".
    # The gate probes the key's receive-request scope against the mock.
    body = w.post(
        step="lightning", lightning_action="save",
        strike_api_key=TEST_STRIKE_KEY,
        strike_onchain="1",
    )
    assert _heading(body) == "Zero-conf payments", "Strike on-chain must pull zeroconf in"
    assert "of 11" in body, "the step counter must count the screen it just gained"

    body = w.post(step="zeroconf", zero_conf="1")
    assert _heading(body) == "Submarine swaps"

    row = _stores(payserver)[0]
    assert row["onchain_min_confs"] == 0, "the zero-conf answer must persist"
    assert row["onchain_strike_enabled"] == 1
    assert not row["onchain_xpub"] and not row["onchain_static_address"]


def test_swaps_require_an_xpub_not_a_static_address(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Static Store")
    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="static",
        onchain_static_address=MAINNET_ADDRESS,
    )
    assert _heading(body) == "Lightning payments"
    row = _stores(payserver)[0]
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_network"] == "mainnet", "the network is inferred from the address encoding"

    body = w.post(step="lightning", lightning_action="skip")
    assert _heading(body) == "Zero-conf payments", "a static address is still an on-chain rail"
    w.post(step="zeroconf", zero_conf="0")

    body = w.post(step="swaps", swaps_enabled="1")
    assert "need an xpub" in (_error(body) or ""), _error(body)
    # -1 is the untouched legacy column default (it resolves to off; swaps are
    # store-only) — the point is the rejected answer must not be saved.
    assert _stores(payserver)[0]["swaps_enabled"] == -1, "the rejected answer must not be saved"

    body = w.post(step="swaps", swaps_enabled="0")
    assert _heading(body) == "Cashu mints"
    assert _stores(payserver)[0]["swaps_enabled"] == 0


def test_testnet_family_xpub_takes_the_declared_subnetwork(payserver: PayserverHandle) -> None:
    """A tpub/upub/vpub could be testnet, signet or regtest — the version
    bytes are shared. The wizard reveals a picker and honours it, because
    guessing wrong generates addresses on the wrong chain."""
    w = Wizard(payserver)
    w.through_store("Regtest Store")
    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="xpub",
        onchain_xpub=TESTNET_TPUB,
        onchain_testnet_network="regtest",
    )
    assert _error(body) is None, _error(body)
    row = _stores(payserver)[0]
    assert row["onchain_network"] == "regtest"
    # A BIP44 prefix says nothing about the address type, so the wizard
    # defaults to native SegWit and lets the address preview settle it.
    assert row["onchain_address_type"] == "P2WPKH"


def test_invalid_inputs_are_rejected_with_readable_messages(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Validation Store")

    body = w.post(step="onchain", onchain_action="save", onchain_address_mode="xpub", onchain_xpub="nope")
    assert "extended public key" in (_error(body) or ""), _error(body)

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="static",
        onchain_static_address="definitely-not-an-address",
    )
    assert "Bitcoin address" in (_error(body) or ""), _error(body)

    w.post(step="onchain", onchain_action="skip")

    body = w.post(step="lightning", lightning_action="save", lightning_address="not-an-address")
    assert "myname@wallet.com" in (_error(body) or ""), _error(body)

    body = w.post(step="lightning", lightning_action="save", **{"noffers[]": "noffer1garbage"})
    assert "noffer1" in (_error(body) or ""), _error(body)

    # A well-formed address whose host can't be reached fails the LUD-21
    # save-time gate (.invalid never resolves, so this is deterministic
    # with or without outbound network).
    body = w.post(
        step="lightning",
        lightning_action="save",
        lightning_address="merchant@unreachable.invalid",
    )
    assert "didn't respond" in (_error(body) or ""), _error(body)
    assert _heading(body) == "Lightning payments", "a blocked save stays on the screen"

    # Nothing partial should have been written by the rejected submissions.
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        assert conn.execute("SELECT COUNT(*) FROM store_ln_addresses").fetchone()[0] == 0
    finally:
        conn.close()


def test_multiple_noffers_persist_in_order(payserver: PayserverHandle) -> None:
    """The noffer section accepts several entries (name="noffers[]"); all of
    them persist as chain rows in the submitted order. Noffers carry no LUD-21
    probe, so no lnurlp stack is needed. A duplicate row is rejected with the
    same readable message the admin panel's chain uses, and revisiting the
    screen prefills one input per stored noffer."""
    w = Wizard(payserver)
    w.through_store("Two Noffer Store")
    w.post(step="onchain", onchain_action="skip")

    body = w.post(
        step="lightning",
        lightning_action="save",
        **{"noffers[]": [REFERENCE_NOFFER, SECOND_NOFFER, REFERENCE_NOFFER]},
    )
    assert "Duplicate destination" in (_error(body) or ""), _error(body)

    body = w.post(
        step="lightning",
        lightning_action="save",
        **{"noffers[]": [REFERENCE_NOFFER, SECOND_NOFFER]},
    )
    assert _error(body) is None, _error(body)
    assert _heading(body) == "Submarine swaps"

    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        rows = conn.execute(
            "SELECT address, type FROM store_ln_addresses ORDER BY position"
        ).fetchall()
    finally:
        conn.close()
    assert [(r["type"], r["address"]) for r in rows] == [
        ("noffer", REFERENCE_NOFFER),
        ("noffer", SECOND_NOFFER),
    ], [dict(r) for r in rows]

    # Revisiting the screen renders one prefilled input per stored noffer.
    body = w.get("lightning")
    assert body.count('name="noffers[]"') == 2, body.count('name="noffers[]"')
    assert REFERENCE_NOFFER in body and SECOND_NOFFER in body


def test_duplicate_store_name_is_rejected_but_a_rename_is_not(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Original")

    # Same name again from the store screen is a rename of the store already
    # under construction, not a clash with itself.
    body = w.post(step="store", store_name="Original")
    assert _error(body) is None, _error(body)
    assert len(_stores(payserver)) == 1

    body = w.post(step="store", store_name="Renamed")
    assert _error(body) is None, _error(body)
    stores = _stores(payserver)
    assert len(stores) == 1, "going back to the store screen must not create a second store"
    assert stores[0]["name"] == "Renamed"


def test_store_step_offers_default_currency_and_persists_it(payserver: PayserverHandle) -> None:
    """The store-name screen also asks for a default display/quote currency,
    with the copy that clarifies settlement is always Bitcoin. The chosen
    currency is written to the store's default_currency column."""
    w = Wizard(payserver)
    w.accept_terms()
    w.post(step="security", security_acknowledged="1")
    # password -> lands on the store screen; inspect that rendered body.
    store_body = w.post(
        step="password", password=ADMIN_PW, confirm_password=ADMIN_PW
    )
    assert 'name="default_currency"' in store_body, "store screen must render a currency selector"
    assert "Payments are always received as Bitcoin" in store_body, "the settlement-note copy must be shown"
    assert 'value="USD"' in store_body, "fiat options must be offered alongside sat"

    body = w.post(step="store", store_name="Fiat Store", default_currency="USD")
    assert _error(body) is None, _error(body)
    stores = _stores(payserver)
    assert len(stores) == 1
    assert stores[0]["default_currency"] == "USD", "the chosen currency must persist"


def test_store_step_rejects_out_of_range_currency_by_falling_back_to_sat(
    payserver: PayserverHandle,
) -> None:
    """The selector is a controlled list; a tampered value must not persist —
    it falls back to sat rather than hard-failing an onboarding step."""
    w = Wizard(payserver)
    w.accept_terms()
    w.post(step="security", security_acknowledged="1")
    w.post(step="password", password=ADMIN_PW, confirm_password=ADMIN_PW)
    body = w.post(step="store", store_name="Tamper Store", default_currency="DOGE")
    assert _error(body) is None, _error(body)
    stores = _stores(payserver)
    assert stores[0]["default_currency"] == "sat", "an unsupported currency must fall back to sat"


def test_add_store_does_not_rename_the_store_from_first_run(payserver: PayserverHandle) -> None:
    """The store screen reuses the in-progress store id so navigating Back
    renames rather than duplicates. That reuse must not leak across wizard
    runs: the session still holds the first-run store when the operator goes
    straight to "Create Store", and reusing it there would rename their live
    store instead of adding a new one.
    """
    w = Wizard(payserver)
    w.through_store("Live Store")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    w.post(step="mints", mints_enabled="0")
    assert [r["name"] for r in _stores(payserver)] == ["Live Store"]

    # add_store is admin-gated. Logging in on the same session keeps the
    # wizard's session data (session_regenerate_id preserves $_SESSION), which
    # is exactly how the stale store id survives into add_store.
    login = w.s.post(
        f"{payserver.url}/admin",
        data={"action": "login", "username": "admin", "password": ADMIN_PW},
        timeout=15,
    )
    assert login.status_code == 200 and login.json()["success"] is True, login.text

    # Same session, straight into add_store — no cron/done screen in between.
    w.s.get(w.url, params={"mode": "add_store"}, timeout=15)
    w.post(step="store", store_name="Extra Store", mode="add_store")

    names = sorted(r["name"] for r in _stores(payserver))
    assert names == ["Extra Store", "Live Store"], f"add_store must add, not rename: {names}"


def test_unknown_step_restarts_rather_than_rendering_blank(payserver: PayserverHandle) -> None:
    """Stale bookmarks and forms saved from the pre-slug wizard (step=4, …)
    must land somewhere usable."""
    w = Wizard(payserver)
    for stale in ("4", "", "bogus"):
        body = w.get(stale)
        assert _heading(body) == "Let's agree on a few things", f"step={stale!r} should restart the wizard"


def test_terms_gate_requires_all_three_checkboxes(payserver: PayserverHandle) -> None:
    """The terms-of-service gate opens every first run and only advances when
    all three boxes are ticked: legal-use, as-is/no-warranty, and the
    incoming-payment fee acknowledgement."""
    w = Wizard(payserver)

    # The wizard lands on the terms gate before anything else.
    landing = w.get("")
    assert _heading(landing) == "Let's agree on a few things", _heading(landing)
    assert "github.com/BareBits/cashupayserver/blob/main/LICENSE.md" in landing, (
        "the license must link to the LICENSE file on GitHub"
    )
    # The mandatory fee acknowledgement shows the configured dev fee (1%).
    assert "1% fee is assessed on all incoming payments" in landing, (
        "the fee acknowledgement must state the incoming-payment fee"
    )
    # The warranty box opens with "I agree", not the older casual "I get".
    assert "I agree that this software comes as-is" in landing, (
        "the warranty acknowledgement must read 'I agree'"
    )

    # No box, then any two of the three alone: all rejected, none advances.
    partial = (
        {},
        {"terms_legal": "1"},
        {"terms_warranty": "1"},
        {"terms_fee": "1"},
        {"terms_legal": "1", "terms_warranty": "1"},
        {"terms_legal": "1", "terms_fee": "1"},
        {"terms_warranty": "1", "terms_fee": "1"},
    )
    for data in partial:
        body = w.post(step="terms", **data)
        assert "accept all three terms" in (_error(body) or ""), _error(body)
        assert _heading(body) == "Let's agree on a few things", "a partial acceptance must not advance"

    # All three ticked advances to the safety check.
    body = w.post(step="terms", terms_legal="1", terms_warranty="1", terms_fee="1")
    assert _error(body) is None, _error(body)
    assert _heading(body) == "Quick safety check", _heading(body)


def test_security_screen_skipped_when_data_dir_is_outside_the_webroot() -> None:
    """The security screen exists to prove the database can't be fetched over
    HTTP. With the data directory outside the web root that exposure is
    impossible, so the wizard drops the screen — terms goes straight to the
    password screen and the step counter shrinks accordingly. (The default
    fixture keeps its data dir inside the repo/webroot, which is why every
    other case here still sees the screen.)"""
    import tempfile
    import uuid

    from conftest import SESSION_TMP
    from fixtures.payserver import start_payserver, stop_payserver

    workdir = SESSION_TMP / f"outside-webroot-{uuid.uuid4().hex[:8]}"
    with tempfile.TemporaryDirectory(prefix="cashupay-data-") as outside:
        handle = start_payserver(workdir, extra_env={"CASHUPAY_DATA_DIR": outside})
        try:
            w = SetupWizard(handle.url)
            body = w.get("terms")
            assert "of 9" in body, (
                "skipping the security screen makes the standalone wizard 9 screens"
            )

            body = w.post(step="terms", terms_legal="1", terms_warranty="1", terms_fee="1")
            assert _error(body) is None, _error(body)
            assert _heading(body) == "Create your admin password", (
                f"terms must land straight on the password screen, got {_heading(body)!r}"
            )
            # The terms screen carries the (normally security-screen-hosted)
            # silent URL-mode probe so routing detection still happens.
            terms = w.get("terms")
            assert "save_url_mode" in terms, (
                "the terms screen must host the URL-mode probe when the security screen is skipped"
            )
        finally:
            stop_payserver(handle)
