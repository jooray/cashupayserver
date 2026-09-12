"""End-to-end tests for static-address on-chain mode.

In this mode the store reuses a single Bitcoin address for every invoice
and disambiguates incoming transactions by tweaking each invoice's expected
amount by a unique sat-offset. These tests verify:
  - the admin save_onchain endpoint accepts/validates the static config,
  - allocation picks unique tweaks (totals never collide),
  - an exact-amount payment settles the right invoice,
  - colliding totals (forced via direct DB write) flag both invoices for
    manual confirmation and don't auto-settle,
  - the resolve_onchain_manual admin action attributes a tx to one invoice
    and clears the candidate from any siblings,
  - tweak-slot exhaustion produces a user-visible error,
  - switching modes wipes the other source field.
"""
from __future__ import annotations

import json
import sqlite3
import time

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.bitcoind import BitcoindHandle
from fixtures.onchain import OnchainContext, configure_store_for_static_onchain


INVOICE_AMOUNT_SAT = 50_000


# ---------- helpers ----------


def _wire_static(
    shared_configured: ConfiguredPayserver,
    onchain: OnchainContext,
    *,
    tweak_range: int = 1000,
    min_confs: int = 0,
) -> str:
    """Configure the store for static-address mode, returning the address.

    Uses the dedicated watch-only bitcoind wallet the OnchainContext owns,
    so funding via onchain.fund_address() lands somewhere the payserver's
    BitcoindRpcProvider can see.
    """
    addr = onchain.bitcoind.rpc("getnewaddress", "", "bech32")
    configure_store_for_static_onchain(
        shared_configured.handle.db_path,
        shared_configured.store_id,
        static_address=addr,
        network="regtest",
        tweak_range=tweak_range,
        min_confs=min_confs,
        confirm_timeout_sec=86400,
        provider_url=onchain.watch_wallet_url,
    )
    return addr


def _onchain_destination(inv: dict) -> str:
    return inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]


def _onchain_amount_sat(inv: dict) -> int:
    return int(inv["checkout"]["paymentMethods"]["BTC-OnChain"]["amount"])


def _poll_until(
    shared_configured: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30
) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        shared_configured.handle.trigger_cron()
        last = shared_configured.greenfield.get_invoice(shared_configured.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status}; last={last}")


def _admin_post(shared_configured: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    return shared_configured.admin.s.post(
        f"{shared_configured.handle.url}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": shared_configured.admin.csrf_token},
        timeout=15,
    )


def _read_invoice_row(db_path, invoice_id: str) -> dict:
    conn = sqlite3.connect(db_path)
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT * FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
        if row is None:
            raise AssertionError(f"invoice {invoice_id} not in DB")
        return dict(row)
    finally:
        conn.close()


# ---------- admin save flow ----------


def test_save_onchain_static_persists_address_and_clears_xpub(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Saving static mode via the admin form writes the address, the mode,
    the tweak range, and wipes any previously-configured xpub."""
    # First set xpub mode so we have something to clear.
    xpub_resp = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="xpub",
        xpub=onchain.tpub,
        network="regtest",
        address_type="P2WPKH",
        min_confs="0",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert xpub_resp.ok, xpub_resp.text

    addr = onchain.bitcoind.rpc("getnewaddress", "", "bech32")
    r = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="static",
        static_address=addr,
        static_tweak_range="500",
        network="regtest",
        min_confs="0",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert r.ok, r.text
    body = r.json()
    assert body["success"] is True
    assert body["mode"] == "static"
    assert body["staticAddress"] == addr
    assert body["tweakRange"] == 500

    # DB should reflect: mode=static, address set, xpub cleared.
    conn = sqlite3.connect(shared_configured.handle.db_path)
    try:
        conn.row_factory = sqlite3.Row
        row = dict(conn.execute(
            "SELECT onchain_address_mode, onchain_static_address, "
            "onchain_static_tweak_range, onchain_xpub FROM stores WHERE id = ?",
            (shared_configured.store_id,)
        ).fetchone())
    finally:
        conn.close()
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_static_address"] == addr
    assert row["onchain_static_tweak_range"] == 500
    assert row["onchain_xpub"] is None


def test_save_onchain_with_empty_address_still_persists_confirmation_policy(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Saving with no xpub/static address clears the address source but must
    still persist the shared confirmation-policy settings (min confs,
    confirmation window, provider URL). A Strike-only store — addresses
    minted in the merchant's Strike account, settled by the same chain
    watcher — has no other way to opt into zero-conf from the admin card."""
    # Seed a non-default state so persistence is observable.
    seed = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="xpub",
        xpub=onchain.tpub,
        network="regtest",
        address_type="P2WPKH",
        min_confs="3",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert seed.ok, seed.text

    r = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="xpub",
        xpub="",
        network="regtest",
        address_type="P2WPKH",
        min_confs="0",
        confirm_timeout_sec="3600",
        provider_url=onchain.watch_wallet_url,
    )
    assert r.ok, r.text
    body = r.json()
    assert body["success"] is True
    assert body["disabled"] is True
    assert body["strikeOnchain"] is False, "this store has no Strike on-chain option enabled"

    conn = sqlite3.connect(shared_configured.handle.db_path)
    try:
        conn.row_factory = sqlite3.Row
        row = dict(conn.execute(
            "SELECT onchain_xpub, onchain_static_address, onchain_min_confs, "
            "onchain_confirm_timeout_sec, onchain_provider_url FROM stores WHERE id = ?",
            (shared_configured.store_id,)
        ).fetchone())
    finally:
        conn.close()
    assert row["onchain_xpub"] is None, "the address source is gone"
    assert row["onchain_static_address"] is None
    assert row["onchain_min_confs"] == 0, "zero-conf answer persisted without an address"
    assert row["onchain_confirm_timeout_sec"] == 3600
    assert row["onchain_provider_url"] == onchain.watch_wallet_url


def test_save_onchain_static_rejects_invalid_address(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """A plainly malformed address is rejected with 400 + the validator's
    hint about which prefixes are acceptable for the configured network."""
    r = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="static",
        static_address="definitely-not-an-address",
        static_tweak_range="1000",
        network="regtest",
        min_confs="0",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert r.status_code == 400
    assert "regtest" in (r.json().get("error") or "").lower()


def test_save_onchain_static_rejects_mainnet_address_on_regtest(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Network mismatch should be caught by validateAddress."""
    r = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="static",
        # Well-known Genesis-block payout address (mainnet P2PKH).
        static_address="1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa",
        static_tweak_range="1000",
        network="regtest",
        min_confs="0",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert r.status_code == 400


def test_switching_static_to_xpub_clears_static_address(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Going back to xpub mode must wipe the previously-saved static address
    so we never end up with both fields populated simultaneously."""
    addr = _wire_static(shared_configured, onchain)
    # Sanity: row says static.
    conn = sqlite3.connect(shared_configured.handle.db_path)
    try:
        conn.row_factory = sqlite3.Row
        before = dict(conn.execute(
            "SELECT onchain_address_mode, onchain_static_address FROM stores WHERE id = ?",
            (shared_configured.store_id,)
        ).fetchone())
    finally:
        conn.close()
    assert before["onchain_static_address"] == addr

    r = _admin_post(
        shared_configured, "save_onchain",
        store_id=shared_configured.store_id,
        mode="xpub",
        xpub=onchain.tpub,
        network="regtest",
        address_type="P2WPKH",
        min_confs="0",
        confirm_timeout_sec="86400",
        provider_url=onchain.watch_wallet_url,
    )
    assert r.ok, r.text

    conn = sqlite3.connect(shared_configured.handle.db_path)
    try:
        conn.row_factory = sqlite3.Row
        after = dict(conn.execute(
            "SELECT onchain_address_mode, onchain_static_address, onchain_xpub "
            "FROM stores WHERE id = ?",
            (shared_configured.store_id,)
        ).fetchone())
    finally:
        conn.close()
    assert after["onchain_address_mode"] == "xpub"
    assert after["onchain_static_address"] is None
    assert after["onchain_xpub"] == onchain.tpub


# ---------- invoice creation & payment ----------


def test_invoice_uses_static_address_with_tweak(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """The created invoice's BTC-OnChain destination is the static address
    and the expected amount is base + tweak (tweak in [0, range-1])."""
    addr = _wire_static(shared_configured, onchain, tweak_range=100)

    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    assert _onchain_destination(inv) == addr
    total = _onchain_amount_sat(inv)
    tweak = total - INVOICE_AMOUNT_SAT
    assert 0 <= tweak < 100, f"tweak {tweak} outside expected range"

    # Tweak is also persisted on the invoice row.
    row = _read_invoice_row(shared_configured.handle.db_path, inv["id"])
    assert row["onchain_amount_tweak_sats"] == tweak
    assert row["onchain_amount_sat"] == total


def test_two_invoices_get_distinct_totals(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Concurrent invoices for the same base must end up with different totals
    so the poller can attribute each incoming tx unambiguously."""
    _wire_static(shared_configured, onchain, tweak_range=100)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    inv2 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    assert _onchain_destination(inv1) == _onchain_destination(inv2)
    assert _onchain_amount_sat(inv1) != _onchain_amount_sat(inv2)


def test_exact_amount_payment_settles(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Sending the exact tweaked amount in a single tx settles the invoice."""
    _wire_static(shared_configured, onchain, min_confs=0)
    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    onchain.fund_address(_onchain_destination(inv), _onchain_amount_sat(inv))

    settled = _poll_until(shared_configured, inv["id"], "Settled", timeout_s=20)
    assert settled["status"] == "Settled"


def test_payment_with_wrong_amount_does_not_settle(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """An off-by-one payment does NOT match the invoice; it stays New."""
    _wire_static(shared_configured, onchain, min_confs=0)
    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    onchain.fund_address(_onchain_destination(inv), _onchain_amount_sat(inv) + 1)

    # Drive the poller a few times — invoice should stay New (no attribution).
    for _ in range(5):
        shared_configured.handle.trigger_cron()
        time.sleep(0.3)
    got = shared_configured.greenfield.get_invoice(shared_configured.store_id, inv["id"])
    assert got["status"] == "New", got


def test_concurrent_invoices_each_settle_with_their_own_amount(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Two open invoices with distinct totals: paying each exact amount
    settles each independently. Exercises the cross-invoice competitor check
    in pollInvoice() without triggering ambiguity."""
    _wire_static(shared_configured, onchain, min_confs=0)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    inv2 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    addr = _onchain_destination(inv1)
    onchain.fund_address(addr, _onchain_amount_sat(inv1))
    onchain.fund_address(addr, _onchain_amount_sat(inv2))

    a = _poll_until(shared_configured, inv1["id"], "Settled", timeout_s=20)
    b = _poll_until(shared_configured, inv2["id"], "Settled", timeout_s=20)
    assert a["status"] == b["status"] == "Settled"


# ---------- ambiguity / manual confirmation ----------


def test_colliding_totals_flag_manual_confirmation_and_do_not_settle(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """If two open invoices on the same address have the same total (rare in
    practice — would require either a manual DB tweak or simultaneous
    creation in different processes), an incoming tx that matches must
    NOT auto-settle either invoice. Both get flagged for manual
    confirmation with the candidate (txid, vout) recorded."""
    addr = _wire_static(shared_configured, onchain, min_confs=0)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    inv2 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    # Force the second invoice to share the first's total. The allocator
    # would never produce this on its own; we patch the DB to simulate the
    # collision case (e.g. an operator running concurrent processes without
    # proper locking, or a future code path that bypasses the allocator).
    target_total = _onchain_amount_sat(inv1)
    conn = sqlite3.connect(shared_configured.handle.db_path, isolation_level=None)
    try:
        conn.execute(
            "UPDATE invoices SET onchain_amount_sat = ?, onchain_amount_tweak_sats = ? WHERE id = ?",
            (target_total, target_total - INVOICE_AMOUNT_SAT, inv2["id"]),
        )
    finally:
        conn.close()

    onchain.fund_address(addr, target_total)

    # Drive the poller; neither invoice should settle.
    for _ in range(6):
        shared_configured.handle.trigger_cron()
        time.sleep(0.4)

    g1 = shared_configured.greenfield.get_invoice(shared_configured.store_id, inv1["id"])
    g2 = shared_configured.greenfield.get_invoice(shared_configured.store_id, inv2["id"])
    assert g1["status"] != "Settled", g1
    assert g2["status"] != "Settled", g2

    row1 = _read_invoice_row(shared_configured.handle.db_path, inv1["id"])
    row2 = _read_invoice_row(shared_configured.handle.db_path, inv2["id"])
    assert row1["onchain_needs_manual_confirmation"] == 1
    assert row2["onchain_needs_manual_confirmation"] == 1
    cands1 = json.loads(row1["onchain_manual_candidates"])
    cands2 = json.loads(row2["onchain_manual_candidates"])
    assert len(cands1) == 1 and len(cands2) == 1
    assert cands1[0]["txid"] == cands2[0]["txid"]
    assert cands1[0]["amount_sat"] == target_total


def test_manual_attribute_settles_chosen_invoice_and_clears_others(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Drive the ambiguity case above, then call resolve_onchain_manual to
    pick one invoice. That invoice settles; the sibling clears its
    candidate but stays open. Double-attribute is refused."""
    addr = _wire_static(shared_configured, onchain, min_confs=0)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    inv2 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    target_total = _onchain_amount_sat(inv1)
    conn = sqlite3.connect(shared_configured.handle.db_path, isolation_level=None)
    try:
        conn.execute(
            "UPDATE invoices SET onchain_amount_sat = ?, onchain_amount_tweak_sats = ? WHERE id = ?",
            (target_total, target_total - INVOICE_AMOUNT_SAT, inv2["id"]),
        )
    finally:
        conn.close()

    txid = onchain.fund_address(addr, target_total)
    # Drive the cron the same way the colliding-totals test does — that
    # combination of cron tick + get_invoice API call lets the poller
    # observe the new mempool tx within the window.
    for _ in range(6):
        shared_configured.handle.trigger_cron()
        time.sleep(0.4)
    # Refresh both invoices via the API (this is what nudges the watch wallet).
    shared_configured.greenfield.get_invoice(shared_configured.store_id, inv1["id"])
    shared_configured.greenfield.get_invoice(shared_configured.store_id, inv2["id"])

    row1 = _read_invoice_row(shared_configured.handle.db_path, inv1["id"])
    assert row1["onchain_needs_manual_confirmation"] == 1, row1
    cands = json.loads(row1["onchain_manual_candidates"])
    assert cands, row1
    # bitcoind picks the destination's vout based on tx-output ordering,
    # which is amount-sorted (BIP69) by default — read it from the
    # candidate row rather than guessing.
    vout = int(cands[0]["vout"])

    r = _admin_post(
        shared_configured, "resolve_onchain_manual",
        invoice_id=inv1["id"], txid=txid, vout=str(vout),
    )
    assert r.ok, r.text

    settled = _poll_until(shared_configured, inv1["id"], "Settled", timeout_s=10)
    assert settled["status"] == "Settled"

    # inv2 should no longer carry the candidate.
    row2 = _read_invoice_row(shared_configured.handle.db_path, inv2["id"])
    assert row2["onchain_needs_manual_confirmation"] == 0
    assert row2["onchain_manual_candidates"] in (None, "", "[]")

    # And re-attributing the same tx to inv2 must be refused.
    r2 = _admin_post(
        shared_configured, "resolve_onchain_manual",
        invoice_id=inv2["id"], txid=txid, vout=str(vout),
    )
    assert r2.status_code == 400
    err = (r2.json().get("error") or "").lower()
    assert "already attributed" in err or "not listed" in err, err


# ---------- slot exhaustion ----------


def test_slot_exhaustion_blocks_new_invoice_creation(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """With tweak_range=1 only one open invoice is allowed at a time.
    Creating a second one before the first settles/expires must error."""
    _wire_static(shared_configured, onchain, tweak_range=1, min_confs=0)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    assert inv1["status"] == "New"

    # Greenfield create_invoice raises on 4xx; assert the failure shape.
    with pytest.raises(Exception) as excinfo:
        shared_configured.greenfield.create_invoice(
            shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
        )
    msg = str(excinfo.value).lower()
    assert "slot" in msg or "reserved" in msg or "exhausted" in msg, msg


def test_slot_recycles_after_invoice_settles(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """Once an invoice reaches Settled, its tweak slot is freed and a new
    invoice can reuse the same tweak value."""
    addr = _wire_static(shared_configured, onchain, tweak_range=1, min_confs=0)
    inv1 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    total1 = _onchain_amount_sat(inv1)
    onchain.fund_address(addr, total1)
    _poll_until(shared_configured, inv1["id"], "Settled", timeout_s=20)

    # Now the slot is free; create a fresh invoice — tweak should match
    # (the only slot in range is 0).
    inv2 = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    assert _onchain_amount_sat(inv2) == total1


# ---------- swap-claim interaction ----------


def test_static_mode_disables_swap_claim_allocation(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """OnchainPayments::allocateClaimAddress() must return null in static
    mode — submarine swap claims would arrive at the static address with
    arbitrary amounts and could collide with open invoice totals. This
    test exercises the negative path indirectly: the store is in static
    mode, so even though on-chain is configured, a swap-rail invoice
    cannot grab a claim address. We rely on the existence of the static
    mode itself; the deeper swap-rail e2e lives in
    test_submarine_swap_via_electrum.py."""
    _wire_static(shared_configured, onchain)
    # Sanity: store does NOT have an xpub anymore, so allocateClaimAddress
    # returns null. We don't have a direct PHP entry point here, but we
    # can verify the row state.
    conn = sqlite3.connect(shared_configured.handle.db_path)
    try:
        conn.row_factory = sqlite3.Row
        row = dict(conn.execute(
            "SELECT onchain_address_mode, onchain_xpub FROM stores WHERE id = ?",
            (shared_configured.store_id,)
        ).fetchone())
    finally:
        conn.close()
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_xpub"] is None
