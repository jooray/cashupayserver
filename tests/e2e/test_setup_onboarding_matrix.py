"""Onboarding wizard: the choice matrix.

Every screen is a question, and the answers interact — most sharply in
SetupFlow::resolveAutoCashout, whose result is a function of three of them
(Lightning destinations, mints, and whether the on-chain rail is an xpub or a
reused address — submarine swaps have no screen and derive from the xpub).
Testing the screens one at a time cannot catch a wrong combination, so this
walks whole flows and asserts the complete persisted state each one produces.

The full cross product is ~600 flows and mostly redundant — the screens are
independent except through the derived columns. This table instead covers:

  * every value of every choice at least once, and
  * every reachable combination of the three inputs the auto-cashout rail and
    the swaps default actually depend on.

Uses the session-scoped nutshell mints, so the mints=ON rows cost one stack
startup for the whole run rather than one each.
"""
from __future__ import annotations

import sqlite3
from dataclasses import dataclass, field

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import (
    MAINNET_ADDRESS,
    MAINNET_XPUB,
    REFERENCE_NOFFER,
    SetupWizard,
    TESTNET_TPUB,
    wizard_error,
    wizard_heading,
)

LN_ADDRESS = "merchant@strike.me"

# Tri-states, mirrored from SwapsConfig/SwapAutoMelt so the expectations read
# the same way the columns do.
INHERIT, OFF, ON = -1, 0, 1


@dataclass
class Choices:
    """One walk through the wizard."""

    onchain: str  # 'skip' | 'xpub' | 'tpub' | 'static'
    lightning: str  # 'skip' | 'address' | 'noffer' | 'both'
    mints: bool
    zero_conf: bool = True


@dataclass
class Expect:
    """The state that walk should leave behind."""

    onchain_mode: str | None  # None = no on-chain rail configured
    network: str | None
    min_confs: int
    swaps_enabled: int
    strict: int
    has_mint: bool
    ln_chain: list[tuple[str, str]] = field(default_factory=list)
    auto_melt_enabled: int = 0
    auto_melt_use_swap: int = OFF


def _store(payserver: PayserverHandle) -> sqlite3.Row:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores LIMIT 1").fetchone()
    finally:
        conn.close()


def _ln_chain(payserver: PayserverHandle) -> list[tuple[str, str]]:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        rows = conn.execute(
            "SELECT type, address FROM store_ln_addresses ORDER BY position ASC"
        ).fetchall()
        return [(r[0], r[1]) for r in rows]
    finally:
        conn.close()


def _backup_mints(payserver: PayserverHandle) -> list[str]:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        return [r[0] for r in conn.execute("SELECT mint_url FROM store_mints").fetchall()]
    finally:
        conn.close()


def walk(
    payserver: PayserverHandle,
    choices: Choices,
    mint: MintHandle | None = None,
    backup_mint: MintHandle | None = None,
    *,
    store_name: str = "Matrix Store",
) -> SetupWizard:
    """Answer every screen per `choices`. Returns the wizard session, still
    logged in on the screen after the auto-update question (answered "not
    now", the default-off choice), so callers can continue to cron/done.

    Submarine swaps are not a choice: the wizard switches them on at the end
    of the rail screens whenever the store has an xpub (and this test PHP has
    GMP), and leaves them off otherwise."""
    w = SetupWizard(payserver.url)
    w.through_store(store_name)

    if choices.onchain == "skip":
        body = w.post(step="onchain", onchain_action="skip")
    elif choices.onchain == "static":
        body = w.post(
            step="onchain",
            onchain_action="save",
            onchain_address_mode="static",
            onchain_static_address=MAINNET_ADDRESS,
        )
    elif choices.onchain == "tpub":
        body = w.post(
            step="onchain",
            onchain_action="save",
            onchain_address_mode="xpub",
            onchain_xpub=TESTNET_TPUB,
            onchain_testnet_network="regtest",
        )
    else:
        body = w.post(
            step="onchain", onchain_action="save",
            onchain_address_mode="xpub", onchain_xpub=MAINNET_XPUB,
        )
    assert wizard_error(body) is None, wizard_error(body)

    if choices.lightning == "skip":
        body = w.post(step="lightning", lightning_action="skip")
    else:
        fields = {}
        if choices.lightning in ("address", "both"):
            fields["lightning_address"] = LN_ADDRESS
        if choices.lightning in ("noffer", "both"):
            fields["noffers[]"] = REFERENCE_NOFFER
        body = w.post(step="lightning", lightning_action="save", **fields)
    assert wizard_error(body) is None, wizard_error(body)

    # zeroconf only exists once there is an on-chain receive source to time;
    # it sits after lightning because Strike on-chain (the other possible
    # source) is answered there.
    if choices.onchain != "skip":
        body = w.post(step="zeroconf", zero_conf="1" if choices.zero_conf else "0")
        assert wizard_error(body) is None, wizard_error(body)

    if choices.mints:
        assert mint and backup_mint, "mints=True needs the nutshell fixtures"
        body = w.post(
            step="mints", mints_enabled="1",
            mint_url=mint.url, backup_mint_url=backup_mint.url, mint_unit="sat",
        )
    else:
        body = w.post(step="mints", mints_enabled="0")
    assert wizard_error(body) is None, wizard_error(body)

    # wallet_tabs follows the last rail screen on every path; skip it (none).
    assert wizard_heading(body) == "Popular wallet tabs", wizard_heading(body)
    body = w.post(step="wallet_tabs", wallet_tabs_action="skip")
    assert wizard_error(body) is None, wizard_error(body)

    # A seed was minted: the recovery-phrase screen comes first.
    if choices.mints:
        assert wizard_heading(body) == "Save your recovery phrase", wizard_heading(body)
        body = w.post(step="seed", seed_saved="1")
        assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Enable automatic updates?", wizard_heading(body)
    # updates: decline automatic updates (the safe default), landing on cron.
    # (Usage statistics were declined on screen two by through_store.)
    body = w.post(step="updates", auto_update="0")
    assert wizard_error(body) is None, wizard_error(body)
    return w


def assert_state(payserver: PayserverHandle, expect: Expect) -> None:
    row = _store(payserver)
    if expect.onchain_mode is None:
        assert not row["onchain_xpub"] and not row["onchain_static_address"]
    else:
        assert row["onchain_address_mode"] == expect.onchain_mode
        assert row["onchain_network"] == expect.network
    assert row["onchain_min_confs"] == expect.min_confs
    assert row["swaps_enabled"] == expect.swaps_enabled, "swaps tri-state"
    assert row["strict_no_mint_fallback"] == expect.strict, "strict-no-mint tri-state"
    assert bool(row["mint_url"]) is expect.has_mint, "mint configured"
    assert bool(row["seed_phrase"]) is expect.has_mint, "a mint implies a wallet seed"
    assert _ln_chain(payserver) == expect.ln_chain, "Lightning destination chain"
    assert row["auto_melt_enabled"] == expect.auto_melt_enabled, "auto-cashout enabled"
    assert row["auto_melt_use_swap"] == expect.auto_melt_use_swap, "auto-cashout rail"


# ---------------------------------------------------------------------------
# Rows that need no mint stack. Each names the interaction it pins down.
# ---------------------------------------------------------------------------

MINTLESS_MATRIX = [
    pytest.param(
        # Nothing configured at all: the store exists but can take no payment.
        Choices(onchain="skip", lightning="skip", mints=False),
        Expect(onchain_mode=None, network=None, min_confs=1,
               swaps_enabled=OFF, strict=ON, has_mint=False,
               auto_melt_enabled=0, auto_melt_use_swap=OFF),
        id="everything-skipped",
    ),
    pytest.param(
        # A Lightning address alone is enough to sweep over Lightning, with no
        # on-chain rail and no mint anywhere.
        Choices(onchain="skip", lightning="address", mints=False),
        Expect(onchain_mode=None, network=None, min_confs=1,
               swaps_enabled=OFF, strict=ON, has_mint=False,
               ln_chain=[("lnaddress", LN_ADDRESS)],
               auto_melt_enabled=1, auto_melt_use_swap=OFF),
        id="lnaddress-only",
    ),
    pytest.param(
        # A noffer on its own is a destination too — it must not need an LNURL
        # address alongside it to count.
        Choices(onchain="skip", lightning="noffer", mints=False),
        Expect(onchain_mode=None, network=None, min_confs=1,
               swaps_enabled=OFF, strict=ON, has_mint=False,
               ln_chain=[("noffer", REFERENCE_NOFFER)],
               auto_melt_enabled=1, auto_melt_use_swap=OFF),
        id="noffer-only",
    ),
    pytest.param(
        # Both destinations: address first, noffer as the fallback — the order
        # Invoice::create walks at payment time.
        Choices(onchain="xpub", lightning="both", mints=False, zero_conf=False),
        Expect(onchain_mode="xpub", network="mainnet", min_confs=1,
               swaps_enabled=ON, strict=ON, has_mint=False,
               ln_chain=[("lnaddress", LN_ADDRESS), ("noffer", REFERENCE_NOFFER)],
               auto_melt_enabled=1, auto_melt_use_swap=OFF),
        id="both-destinations-oneconf",
    ),
    pytest.param(
        # Zero-conf on, mainnet xpub, swaps on, no mint: swaps are enabled but
        # there is no mint balance to sweep, so auto-cashout stays off.
        Choices(onchain="xpub", lightning="skip", mints=False),
        Expect(onchain_mode="xpub", network="mainnet", min_confs=0,
               swaps_enabled=ON, strict=ON, has_mint=False,
               auto_melt_enabled=0, auto_melt_use_swap=OFF),
        id="xpub-swaps-no-mint",
    ),
    pytest.param(
        # A reused static address is a valid receive rail but cannot back
        # swaps, so they stay off.
        Choices(onchain="static", lightning="skip", mints=False, zero_conf=False),
        Expect(onchain_mode="static", network="mainnet", min_confs=1,
               swaps_enabled=OFF, strict=ON, has_mint=False,
               auto_melt_enabled=0, auto_melt_use_swap=OFF),
        id="static-address-oneconf",
    ),
    pytest.param(
        # Testnet-family key with an explicit sub-network, zero-conf on.
        Choices(onchain="tpub", lightning="address", mints=False),
        Expect(onchain_mode="xpub", network="regtest", min_confs=0,
               swaps_enabled=ON, strict=ON, has_mint=False,
               ln_chain=[("lnaddress", LN_ADDRESS)],
               auto_melt_enabled=1, auto_melt_use_swap=OFF),
        id="regtest-xpub",
    ),
]


@pytest.mark.parametrize("choices,expect", MINTLESS_MATRIX)
def test_choice_matrix_without_mints(
    payserver_with_lnurlp: PayserverHandle, choices: Choices, expect: Expect
) -> None:
    # The lnurlp-backed stack routes LN_ADDRESS to the mock LUD-21 host, so
    # the lightning step's save-time gate passes for the rows that type one.
    payserver = payserver_with_lnurlp
    walk(payserver, choices)
    assert_state(payserver, expect)


# ---------------------------------------------------------------------------
# Rows that need real mints. These are the ones that decide whether the mint
# balance gets swept, and how.
# ---------------------------------------------------------------------------

MINTED_MATRIX = [
    pytest.param(
        # THE case the mints screen promises: no Lightning destination, but a
        # mint plus swaps plus an xpub, so the balance sweeps on-chain via a
        # submarine swap. Deciding this on the Lightning screen (before the
        # swaps and mints answers exist) would leave it off.
        Choices(onchain="xpub", lightning="skip", mints=True),
        Expect(onchain_mode="xpub", network="mainnet", min_confs=0,
               swaps_enabled=ON, strict=INHERIT, has_mint=True,
               auto_melt_enabled=1, auto_melt_use_swap=ON),
        id="mint-swaps-xpub-sweeps-onchain",
    ),
    pytest.param(
        # A mint with a static address: swaps are impossible (a swap claim
        # needs a fresh address), so again nothing to sweep to.
        Choices(onchain="static", lightning="skip", mints=True),
        Expect(onchain_mode="static", network="mainnet", min_confs=0,
               swaps_enabled=OFF, strict=INHERIT, has_mint=True,
               auto_melt_enabled=0, auto_melt_use_swap=OFF),
        id="mint-static-address-cannot-sweep",
    ),
    pytest.param(
        # Lightning beats swaps when both are available — cheapest rail wins.
        Choices(onchain="xpub", lightning="address", mints=True),
        Expect(onchain_mode="xpub", network="mainnet", min_confs=0,
               swaps_enabled=ON, strict=INHERIT, has_mint=True,
               ln_chain=[("lnaddress", LN_ADDRESS)],
               auto_melt_enabled=1, auto_melt_use_swap=OFF),
        id="lightning-beats-swap-sweep",
    ),
    pytest.param(
        # A mint with no on-chain rail at all and no Lightning: the store can
        # take Lightning payments via the mint but has no payout route.
        Choices(onchain="skip", lightning="skip", mints=True),
        Expect(onchain_mode=None, network=None, min_confs=1,
               swaps_enabled=OFF, strict=INHERIT, has_mint=True,
               auto_melt_enabled=0, auto_melt_use_swap=OFF),
        id="mint-only-no-payout-route",
    ),
]


@pytest.mark.parametrize("choices,expect", MINTED_MATRIX)
def test_choice_matrix_with_mints(
    payserver_with_lnurlp: PayserverHandle,
    mint: MintHandle,
    backup_mint: MintHandle,
    choices: Choices,
    expect: Expect,
) -> None:
    payserver = payserver_with_lnurlp
    walk(payserver, choices, mint, backup_mint)
    assert_state(payserver, expect)

    row = _store(payserver)
    assert row["mint_url"].rstrip("/") == mint.url.rstrip("/")
    assert row["mint_unit"] == "sat"
    assert len(row["seed_phrase"].split()) == 12
    backups = [u.rstrip("/") for u in _backup_mints(payserver)]
    assert backup_mint.url.rstrip("/") in backups, f"backup mint not saved: {backups}"


# ---------------------------------------------------------------------------
# Mint-screen error paths and idempotency.
# ---------------------------------------------------------------------------


def test_mint_error_paths(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> None:
    """Each rejection on the mints screen must name what to fix, and must not
    half-configure the store on the way out."""
    w = SetupWizard(payserver.url)
    w.through_store("Mint Errors")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")

    body = w.post(step="mints", mints_enabled="1", mint_url="", backup_mint_url=mint.url)
    assert "main mint" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(step="mints", mints_enabled="1", mint_url=mint.url, backup_mint_url="")
    assert "backup mint" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(step="mints", mints_enabled="1", mint_url=mint.url, backup_mint_url=mint.url)
    assert "different from your main" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(
        step="mints", mints_enabled="1",
        mint_url="https://mint.invalid", backup_mint_url=mint.url,
    )
    assert "couldn't reach" in (wizard_error(body) or ""), wizard_error(body)

    # Both mints are sat-only, so asking for usd must be refused rather than
    # saved as a unit the mint cannot actually issue. Two distinct mints here,
    # otherwise the "must be different" check fires first and this asserts
    # nothing about units.
    body = w.post(
        step="mints", mints_enabled="1",
        mint_url=mint.url, backup_mint_url=backup_mint.url, mint_unit="usd",
    )
    assert "usd" in (wizard_error(body) or ""), wizard_error(body)

    row = _store(payserver)
    assert not row["mint_url"], "a rejected mints answer must not persist a mint"
    assert not row["seed_phrase"], "nor mint a wallet seed"
    assert _backup_mints(payserver) == []


def test_resubmitting_the_mints_screen_is_idempotent(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> None:
    """Going Back and re-confirming mints must not mint a second seed (which
    would orphan any ecash already issued against the first) nor duplicate the
    backup mint row. Declining first and then choosing a mint via Back must
    also lift the strict pin the decline set."""
    w = SetupWizard(payserver.url)
    w.through_store("Idempotent Store")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")

    body = w.post(step="mints", mints_enabled="0")
    assert wizard_error(body) is None, wizard_error(body)
    assert _store(payserver)["strict_no_mint_fallback"] == ON, "declining pins strict"
    body = w.get("mints")
    assert wizard_heading(body) == "Cashu mints", "Back onto the mints screen after completion"

    mint_answer = dict(
        step="mints", mints_enabled="1",
        mint_url=mint.url, backup_mint_url=backup_mint.url, mint_unit="sat",
    )
    body = w.post(**mint_answer)
    assert wizard_error(body) is None, wizard_error(body)
    first = _store(payserver)["seed_phrase"]
    assert first
    assert _store(payserver)["strict_no_mint_fallback"] == OFF, "choosing a mint after a decline lifts the strict pin"

    w.post(**mint_answer)
    assert _store(payserver)["seed_phrase"] == first, "the wallet seed must not be regenerated"
    backups = _backup_mints(payserver)
    assert len(backups) == len(set(backups)), f"backup mint duplicated: {backups}"


def test_completion_screen_warns_when_no_rail_was_configured(
    payserver: PayserverHandle,
) -> None:
    """Skipping every rail leaves a store that cannot be paid. The final screen
    has to say so rather than claiming the server is ready."""
    w = walk(payserver, Choices(onchain="skip", lightning="skip", mints=False))
    body = w.post(step="cron")
    assert wizard_heading(body).startswith("You"), wizard_heading(body)
    assert "No payment method yet" in body
    assert "can't take payments just yet" in body


def test_completion_screen_is_clean_when_a_rail_exists(
    payserver_with_lnurlp: PayserverHandle,
) -> None:
    payserver = payserver_with_lnurlp
    w = walk(payserver, Choices(onchain="xpub", lightning="address", mints=False))
    body = w.post(step="cron")
    assert "No payment method is set up yet" not in body


def test_completion_screen_does_not_cry_wolf_on_a_fresh_session(
    payserver_with_lnurlp: PayserverHandle,
) -> None:
    """Reaching the final screen without the wizard's session — browser
    restarted, cookie expired, link reopened in another tab — leaves no store
    id to inspect. An unknown store must not be reported as unpayable: that
    reads as "your setup failed" to an operator whose setup went fine.

    On a configured install the tail screens are admin-only (an anonymous
    tail POST is bounced to admin), so the fresh session signs in first; it
    still has no wizard store id."""
    payserver = payserver_with_lnurlp
    walk(payserver, Choices(onchain="skip", lightning="address", mints=False))

    stranger = SetupWizard(payserver.url)  # brand new cookie jar
    r = stranger.s.post(stranger.url, data={"step": "cron"}, timeout=15, allow_redirects=False)
    assert r.status_code == 302 and "admin" in r.headers.get("Location", ""), (
        "an anonymous tail POST must be bounced to admin"
    )
    stranger.login(payserver.url)
    body = stranger.post(step="cron")
    assert wizard_heading(body).startswith("You"), wizard_heading(body)
    assert "No payment method is set up yet" not in body, (
        "a store we cannot identify must not be accused of having no rails"
    )


def _config(payserver: PayserverHandle, key: str) -> str | None:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def test_wizard_updates_screen_persists_the_choice(payserver: PayserverHandle) -> None:
    """The auto-update screen writes the instance-level opt-in both ways: an
    explicit "Not now" is a decision (stored false), and enabling stores true.
    walk() already answers it with "0", so re-post the screen to flip it."""
    walk(payserver, Choices(onchain="skip", lightning="skip", mints=False))
    assert _config(payserver, "auto_update_enabled") == "false", (
        "declining automatic updates must persist an explicit false"
    )

    w2 = SetupWizard(payserver.url)  # fresh session: tail posts are admin-only
    w2.login(payserver.url)
    body = w2.post(step="updates", auto_update="1")
    assert wizard_error(body) is None, wizard_error(body)
    assert _config(payserver, "auto_update_enabled") == "true", (
        "enabling automatic updates must persist true"
    )


def test_first_run_xpub_default_sets_only_the_store_swaps_flag(payserver: PayserverHandle) -> None:
    """Swaps are store-only now: saying yes on a first run writes the store
    flag and must NOT create a site-wide swaps_enabled config row — that key
    no longer exists as a setting, and a row here would just be a stale-looking
    artifact (it is ignored by resolution either way)."""
    walk(payserver, Choices(onchain="xpub", lightning="skip", mints=False))
    assert _config(payserver, "swaps_enabled") is None, (
        "the wizard must not write a site-wide swaps_enabled config row"
    )
    assert _store(payserver)["swaps_enabled"] == ON


def test_first_run_without_an_xpub_leaves_swaps_off_and_writes_no_config_row(
    payserver: PayserverHandle,
) -> None:
    """Declining writes only the store flag too — the site-wide layer is gone,
    so no config row may appear in either direction."""
    walk(payserver, Choices(onchain="skip", lightning="skip", mints=False))
    assert _config(payserver, "swaps_enabled") is None
    assert _store(payserver)["swaps_enabled"] == OFF


# ---------------------------------------------------------------------------
# Store-only swaps resolution. The old tri-state × site-key matrix is gone:
# stores.swaps_enabled alone decides — 1 → on (given an xpub), 0 → off, and a
# legacy -1 "inherit" row (from when a site-wide default existed) → off. A
# stale site-wide config row must be ignored even when present.
# ---------------------------------------------------------------------------


def _set_store_swaps_flag(payserver: PayserverHandle, value: int) -> None:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        conn.execute("UPDATE stores SET swaps_enabled = ?", (value,))
        conn.commit()
    finally:
        conn.close()


def _seed_stale_site_swaps_row(payserver: PayserverHandle) -> None:
    """Plant a leftover site-wide swaps_enabled=true row, as an install that
    upgraded from the site-default era would still carry."""
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        conn.execute(
            "INSERT INTO config (key, value, created_at, updated_at) "
            "VALUES ('swaps_enabled', 'true', strftime('%s','now'), strftime('%s','now')) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        )
        conn.commit()
    finally:
        conn.close()


@pytest.mark.parametrize(
    "flag,expected",
    [
        pytest.param(ON, True, id="store-flag-1-resolves-on"),
        pytest.param(OFF, False, id="store-flag-0-resolves-off"),
        pytest.param(INHERIT, False, id="legacy-minus1-resolves-off"),
    ],
)
def test_swaps_resolution_is_store_only(
    payserver: PayserverHandle, flag: int, expected: bool
) -> None:
    """stores.swaps_enabled alone decides the effective swaps state (the store
    has an xpub, so the on-chain requirement is met). The stale site-wide
    config row is planted on purpose: if any site fallback survived the
    refactor, the OFF and legacy-INHERIT rows would wrongly flip on."""
    w = walk(payserver, Choices(onchain="xpub", lightning="skip", mints=False))
    _seed_stale_site_swaps_row(payserver)
    _set_store_swaps_flag(payserver, flag)

    w.login(payserver.url)
    store_id = _store(payserver)["id"]
    r = w.s.get(
        f"{payserver.url}/admin",
        params={"api": "dashboard", "store_id": store_id},
        timeout=15,
    )
    assert r.status_code == 200, r.text[:300]
    swaps = r.json()["swaps"]
    assert swaps["effective"] is expected, swaps
    # The site-default era keys must not resurface in the payload.
    assert "siteDefault" not in swaps and "override" not in swaps, swaps


# ---------------------------------------------------------------------------
# add_store runs the same screens, so the same interactions have to hold there.
# ---------------------------------------------------------------------------


def test_add_store_applies_the_same_rail_resolution(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> None:
    """A store added later must get the same derived state a first-run store
    would — including the swap sweep rail, which only the combination of
    mints + swaps + xpub produces."""
    w = walk(payserver, Choices(onchain="skip", lightning="skip", mints=False),
             store_name="First Store")
    w.login(payserver.url)
    # Same (admin) session, primed under add_store so the wizard's store id is
    # cleared and the CSRF token of that screen is lifted.
    w2 = SetupWizard(payserver.url, mode="add_store", session=w.s)

    w2.post(step="store", store_name="Added Store")
    w2.post(step="onchain", onchain_action="save",
            onchain_address_mode="xpub", onchain_xpub=MAINNET_XPUB)
    w2.post(step="lightning", lightning_action="skip")
    w2.post(step="zeroconf", zero_conf="1")
    body = w2.post(step="mints", mints_enabled="1",
                   mint_url=mint.url, backup_mint_url=backup_mint.url, mint_unit="sat")
    assert wizard_error(body) is None, wizard_error(body)
    # add_store asks about wallet tabs last; answering it is the hand-off.
    assert wizard_heading(body) == "Popular wallet tabs", wizard_heading(body)
    body = w2.post(step="wallet_tabs", wallet_tabs_action="skip")
    # A seed was generated, so add_store stops on its hand-off panel to show it.
    assert wizard_heading(body) == "Your store is ready!", wizard_heading(body)

    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        added = conn.execute("SELECT * FROM stores WHERE name = 'Added Store'").fetchone()
        first = conn.execute("SELECT * FROM stores WHERE name = 'First Store'").fetchone()
    finally:
        conn.close()

    assert added is not None, "add_store must create a second store"
    assert added["swaps_enabled"] == ON, "add_store applies the xpub swaps default too"
    assert added["strict_no_mint_fallback"] == INHERIT
    assert added["onchain_min_confs"] == 0
    assert added["auto_melt_enabled"] == 1
    assert added["auto_melt_use_swap"] == ON, "mint + swaps + xpub must sweep on-chain here too"
    assert len(added["seed_phrase"].split()) == 12
    # Swaps are store-only: no wizard run — first-run or add_store — may write
    # a site-wide swaps_enabled config row.
    assert _config(payserver, "swaps_enabled") is None, (
        "add_store must not write a site-wide swaps_enabled config row"
    )

    # The first store must be untouched — in particular still strict and mintless.
    assert first["strict_no_mint_fallback"] == ON
    assert not first["mint_url"]
    assert first["seed_phrase"] != added["seed_phrase"], "each store needs its own wallet seed"
