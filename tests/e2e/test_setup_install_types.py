"""Onboarding wizard: the installation-type screen and what each type does.

Screen three asks how the operator wants to receive money (SetupFlow::
INSTALL_TYPES). The choice is remembered (session + config) and reshapes the
rail screens:

  strike      one Strike screen instead of on-chain + Lightning; mints are
              skipped and pinned off; zeroconf follows when on-chain-via-
              Strike is ticked.
  own_wallet  every rail screen, unchanged.
  cashu       every rail screen; the Lightning screen explains the sweep
              role and the mints screen loses its "run without mints" exit.
  other       every rail screen, no nudges.

Also pins the Back links (every screen but terms/done, never landing on the
password screen or the safety check) and the reworded on-chain warning.
"""
from __future__ import annotations

import re
import sqlite3

from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import (
    MAINNET_XPUB,
    SetupWizard,
    wizard_error,
    wizard_heading,
)

HELP_URL = "https://getbarebits.com/help/Understanding%20Bitcoin/On-Chain%20vs%20Lightning/"


def _flat(body: str) -> str:
    """The rendered HTML with runs of whitespace collapsed, so copy
    assertions survive the templates' line wrapping."""
    return re.sub(r"\s+", " ", body)


def _store(handle: PayserverHandle) -> sqlite3.Row:
    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores LIMIT 1").fetchone()
    finally:
        conn.close()


def _config(handle: PayserverHandle, key: str):
    import json

    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    finally:
        conn.close()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except (TypeError, ValueError):
        return row[0]


def _to_install_type(handle: PayserverHandle) -> tuple[SetupWizard, str]:
    w = SetupWizard(handle.url)
    w.accept_terms()
    body = w.decline_analytics()
    assert wizard_heading(body) == "Choose your installation type", wizard_heading(body)
    return w, body


def test_install_type_screen_lists_the_four_options_with_pros_and_cons(payserver: PayserverHandle) -> None:
    _, body = _to_install_type(payserver)
    for key in ("strike", "own_wallet", "cashu", "other"):
        assert f'id="install-option-{key}"' in body, key
        assert f'name="install_type" value="{key}"' in body, key
    # Pros and cons as the ✅ / ❌ lists, with the on-chain vs Lightning help link.
    assert body.count(HELP_URL) == 3, "Strike, own wallet and Cashu each link the on-chain/Lightning explainer"
    assert "✅" in body and "❌" in body
    assert "Instant USD/EUR conversion" in body
    assert "Requires approval from Strike" in body
    assert "You are fully in control of your funds" in body
    assert "You must manage liquidity to receive Lightning payments" in body
    assert "no need to manage liquidity" in body
    assert "controlled by the mint" in body
    assert "periodically swept into your own wallet" in body
    assert "maximum customization" in body
    # Screen three has a Back link (to the usage-statistics opt-in).
    assert 'id="wizard-back-link"' in body
    assert "step=analytics" in body


def test_install_type_is_required_and_remembered(payserver: PayserverHandle) -> None:
    w, _ = _to_install_type(payserver)
    body = w.post(step="install_type", install_type="bogus")
    assert "choose one of the installation types" in (wizard_error(body) or ""), wizard_error(body)
    assert _config(payserver, "setup_install_type") is None

    body = w.choose_install_type("cashu")
    assert wizard_error(body) is None
    assert _config(payserver, "setup_install_type") == "cashu"
    # Revisiting renders the current choice open.
    body = w.get("install_type")
    assert '<details class="install-option" id="install-option-cashu" open>' in body


def test_strike_type_replaces_onchain_and_lightning_and_skips_mints(
    payserver_with_strike: PayserverHandle,
) -> None:
    from fixtures.strike_api import TEST_STRIKE_KEY

    payserver = payserver_with_strike
    w = SetupWizard(payserver.url)
    body = w.through_store("Strike Store", install_type="strike")
    assert wizard_heading(body) == "Connect your Strike account", wizard_heading(body)
    # Mints and the seed screen are gone; counter reflects the Strike shape.
    # terms, analytics, type, security, password, email, store, strike,
    # wallet_tabs, updates, cron, done — no onchain/lightning/mints/seed, no
    # zeroconf yet.
    assert "of 12" in body, "the Strike shape is 12 screens before zeroconf joins"
    assert 'name="strike_onchain" value="1" checked' in body, "on-chain via Strike is ticked by default"
    assert 'id="wizard-back-link"' in body and "step=store" in body

    # An empty key is refused; a malformed one too, without echoing it back.
    body = w.post(step="strike", strike_action="save", strike_api_key="")
    assert "Paste your Strike API key" in (wizard_error(body) or ""), wizard_error(body)
    body = w.post(step="strike", strike_action="save", strike_api_key="not a key!!")
    assert "doesn't look right" in (wizard_error(body) or ""), wizard_error(body)
    assert "not a key!!" not in body

    # The real key (probed against the mock) with on-chain ticked pulls the
    # zero-conf question in; after it the install is finalised: no mints
    # screen, mints pinned off, straight to the auto-update question.
    body = w.post(step="strike", strike_action="save", strike_api_key=TEST_STRIKE_KEY, strike_onchain="1")
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Zero-conf payments", wizard_heading(body)
    assert "of 13" in body, "zeroconf joined the Strike shape"
    body = w.post(step="zeroconf", zero_conf="0")
    assert wizard_heading(body) == "Popular wallet tabs", wizard_heading(body)
    body = w.post(step="wallet_tabs", wallet_tabs_action="skip")
    assert wizard_heading(body) == "Enable automatic updates?", wizard_heading(body)

    row = _store(payserver)
    assert row["onchain_strike_enabled"] == 1
    assert row["onchain_min_confs"] == 1
    assert row["strict_no_mint_fallback"] == 1, "the Strike type pins mints off"
    assert not row["mint_url"] and not row["seed_phrase"]
    assert row["swaps_enabled"] == 0, "no xpub, so no swaps"
    assert row["auto_melt_enabled"] == 1, "a Strike rail is a Lightning destination for the sweep"
    assert _config(payserver, "setup_complete") is True
    assert _config(payserver, "setup_install_type") == "strike"

    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        rows = conn.execute("SELECT type, address FROM store_ln_addresses").fetchall()
    finally:
        conn.close()
    assert rows == [("strike", TEST_STRIKE_KEY)]

    # Back from the auto-update screen lands on wallet tabs, then zeroconf,
    # then the Strike screen (which shows the saved key as a masked label,
    # never the key).
    body = w.get("wallet_tabs")
    assert wizard_heading(body) == "Popular wallet tabs"
    body = w.get("zeroconf")
    assert wizard_heading(body) == "Zero-conf payments"
    body = w.get("strike")
    assert wizard_heading(body) == "Connect your Strike account"
    assert TEST_STRIKE_KEY not in body
    assert 'name="strike_keep_ref"' in body


def test_strike_type_without_onchain_has_no_zeroconf(payserver_with_strike: PayserverHandle) -> None:
    from fixtures.strike_api import TEST_STRIKE_KEY

    w = SetupWizard(payserver_with_strike.url)
    w.through_store("Strike LN Only", install_type="strike")
    body = w.post(step="strike", strike_action="save", strike_api_key=TEST_STRIKE_KEY)
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Popular wallet tabs", "no on-chain source, so no zeroconf and no mints"
    row = _store(payserver_with_strike)
    assert row["onchain_strike_enabled"] == 0
    assert row["strict_no_mint_fallback"] == 1


def test_strike_type_skip_leaves_the_store_without_rails(payserver: PayserverHandle) -> None:
    w = SetupWizard(payserver.url)
    body = w.through_store("Strike Skipped", install_type="strike")
    assert wizard_heading(body) == "Connect your Strike account"
    body = w.post(step="strike", strike_action="skip")
    assert wizard_heading(body) == "Popular wallet tabs", wizard_heading(body)
    body = w.post(step="wallet_tabs", wallet_tabs_action="skip")
    assert wizard_heading(body) == "Enable automatic updates?", wizard_heading(body)
    body = w.post(step="updates", auto_update="0")
    assert wizard_heading(body) == "Enable cron"
    body = w.post(step="cron")
    assert "No payment method yet" in body, "the completion screen warns about the empty store"


def test_cashu_type_nudges_lightning_and_requires_a_mint(payserver: PayserverHandle) -> None:
    w = SetupWizard(payserver.url)
    body = w.through_store("Cashu Store", install_type="cashu")
    assert wizard_heading(body) == "On-chain Bitcoin payments"
    # The on-chain screen carries no mint-sweep warning (removed 2026-11-09).
    assert "Without an on-chain wallet" not in _flat(body)
    assert "sweep funds out of your Cashu mint" not in _flat(body)

    body = w.post(step="onchain", onchain_action="skip")
    assert wizard_heading(body) == "Lightning payments"
    assert 'id="lightning-cashu-note"' in body
    assert "You have selected to use a Cashu mint" in _flat(body)
    assert "the mint will only be used as a fallback" in _flat(body)

    body = w.post(step="lightning", lightning_action="skip")
    assert wizard_heading(body) == "Cashu mints"
    assert "no online lightning wallet" in _flat(body), "the reworded fallback sentence"
    assert "LNURL/noffer" not in body
    assert "No thanks, run without mints" not in body, "the Cashu type has no run-without-mints exit"
    assert 'id="mints-required-note"' in body


def test_other_and_own_wallet_types_keep_every_screen(payserver: PayserverHandle) -> None:
    w = SetupWizard(payserver.url)
    body = w.through_store("Own Wallet Store", install_type="own_wallet")
    assert wizard_heading(body) == "On-chain Bitcoin payments"
    assert "of 14" in body
    body = w.post(step="onchain", onchain_action="skip")
    assert 'id="lightning-cashu-note"' not in body, "the Cashu note is Cashu-only"
    # The Strike API option stays a section of the Lightning screen here.
    assert 'id="strike-section"' in body
    body = w.post(step="lightning", lightning_action="skip")
    assert "No thanks, run without mints" in body


def test_back_links_on_every_screen_but_terms_and_done(payserver: PayserverHandle) -> None:
    w = SetupWizard(payserver.url)
    assert 'id="wizard-back-link"' not in w.get("terms")
    body = w.accept_terms()
    assert "step=terms" in body.split('id="wizard-back-link"')[0][-200:], "analytics goes back to terms"
    w.decline_analytics()
    body = w.choose_install_type("other")
    assert wizard_heading(body) == "Quick safety check"
    assert "step=install_type" in body.split('id="wizard-back-link"')[0][-200:], "the safety check goes back to the type"
    body = w.post(step="security", security_acknowledged="1")
    assert "step=install_type" in body.split('id="wizard-back-link"')[0][-200:], "password steps over the safety check"
    body = w.post(step="password", password=SetupWizard.DEFAULT_PASSWORD, confirm_password=SetupWizard.DEFAULT_PASSWORD)
    assert "step=install_type" in body.split('id="wizard-back-link"')[0][-200:], "e-mail steps over password + safety check"
    body = w.skip_email()
    assert "step=email" in body.split('id="wizard-back-link"')[0][-200:], "store goes back to e-mail"
    w.post(step="store", store_name="Back Store")
    w.post(step="onchain", onchain_action="save", onchain_address_mode="xpub", onchain_xpub=MAINNET_XPUB)
    w.post(step="lightning", lightning_action="skip")
    body = w.post(step="zeroconf", zero_conf="1")
    assert "step=zeroconf" in body.split('id="wizard-back-link"')[0][-200:], "mints goes back to zeroconf"
    body = w.post(step="mints", mints_enabled="0")
    assert "step=mints" in body.split('id="wizard-back-link"')[0][-200:], "wallet tabs goes back to mints"
    # The completing session may actually follow that link.
    body = w.get("mints")
    assert wizard_heading(body) == "Cashu mints"
    body = w.post(step="wallet_tabs", wallet_tabs_action="skip")
    assert "step=wallet_tabs" in body.split('id="wizard-back-link"')[0][-200:], "updates goes back to wallet tabs"
    body = w.post(step="updates", auto_update="0")
    assert "step=updates" in body.split('id="wizard-back-link"')[0][-200:], "cron goes back to updates"
    body = w.post(step="cron")
    assert 'id="wizard-back-link"' not in body, "the completion screen has no Back"


def test_wizard_shows_the_barebits_logo_not_a_lightning_glyph(payserver: PayserverHandle) -> None:
    body = SetupWizard(payserver.url).get("terms")
    assert 'class="logo"><img src=' in body and "barebits-logo.svg" in body
    assert "&#9889;" not in body
