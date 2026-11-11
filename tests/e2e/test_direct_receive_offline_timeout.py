"""End-to-end: offline NWC/noffer wallets must not wedge invoice creation.

A store's destination chain is a noffer + an NWC connection, both pointing at
a live in-rig Nostr relay with *nobody behind it* — the relay accepts the
subscription and the published request but no wallet/service ever answers.
That is exactly the "merchant's wallet is offline" failure mode: the relay
(public infrastructure) is up, the wallet is not.

Invoice creation walks the chain server-side, so the customer's checkout
request blocks while each dead destination times out. With the enforced
per-destination checkout budget (5s each by default) the whole walk must stay
bounded and fall back to the mint rail; before the budgets were enforced this
took 20s+ (10s per destination, with socket reads able to overrun further).

The destinations are inserted straight into sqlite because the admin save
path probes NWC connections at save time and would (correctly) refuse a dead
wallet — the scenario here is a wallet that was healthy at save time and went
offline later.

PHP-side counterparts: tests/php/test_destination_timeout_budget.php and
tests/php/test_invoice_direct_receive_timeout.php.
"""
from __future__ import annotations

import json
import sqlite3
import subprocess
import time
from pathlib import Path
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures import binaries
from fixtures.clink_relay import ClinkRelayHandle, start_clink_relay, stop_clink_relay
from fixtures.nostr_crypto import generate_privkey, pubkey_xonly

REPO_ROOT = Path(__file__).resolve().parents[2]

# noffer (5s) + NWC (5s) + mint quote + page overhead. The old, unenforced
# behaviour cost 20s+ before the mint rail was even attempted.
MAX_CREATE_SECONDS = 17.0
# Both dead destinations must actually be waited out (2 × 5s budget), which
# also proves the chain was walked rather than skipped.
MIN_CREATE_SECONDS = 9.0


@pytest.fixture(scope="module")
def dead_relay() -> Iterator[ClinkRelayHandle]:
    """A live in-rig relay with no wallet or noffer service connected.
    Module-scoped: nothing ever answers on it, and no test asserts on its
    contents, so accumulated (unanswered) requests can't leak."""
    workdir = SESSION_TMP / f"dead-relay-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    relay = start_clink_relay(workdir)
    yield relay
    stop_clink_relay(relay)


def _encode_noffer(pubkey: str, relay_url: str) -> str:
    """Encode a noffer with the repo's own codec (no python bech32 needed)."""
    php = str(binaries.ensure(binaries.PHP)["php"])
    script = (
        "require $argv[1] . '/includes/clink/noffer.php';"
        "echo ClinkNoffer::encode(['pubkey' => $argv[2], 'relay' => $argv[3],"
        " 'offer' => 'e2e-offline', 'price_type' => ClinkNoffer::PRICE_SPONTANEOUS]);"
    )
    out = subprocess.run(
        [php, "-r", script, str(REPO_ROOT / "app"), pubkey, relay_url],
        capture_output=True, text=True, timeout=30, check=True,
    ).stdout.strip()
    assert out.startswith("noffer1"), out
    return out


def _set_destinations(payserver, store_id: str, chain: list[tuple[str, str]]) -> None:
    """Write an ordered (type, address) destination chain straight to sqlite."""
    db = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db)) as conn:
        conn.execute("DELETE FROM store_ln_addresses WHERE store_id = ?", (store_id,))
        for position, (dest_type, address) in enumerate(chain):
            conn.execute(
                "INSERT INTO store_ln_addresses (store_id, position, address, type)"
                " VALUES (?, ?, ?, ?)",
                (store_id, position, address, dest_type),
            )
        conn.commit()


def test_offline_destinations_fall_back_to_mint_within_budget(
    shared_configured: ConfiguredPayserver, dead_relay: ClinkRelayHandle
) -> None:
    configured = shared_configured
    payserver = configured.handle
    store_id = configured.store_id

    noffer = _encode_noffer(pubkey_xonly(generate_privkey()), dead_relay.ws_url)
    nwc_uri = (
        f"nostr+walletconnect://{pubkey_xonly(generate_privkey())}"
        f"?relay={dead_relay.ws_url}&secret={generate_privkey()}"
    )
    _set_destinations(payserver, store_id, [("noffer", noffer), ("nwc", nwc_uri)])
    try:
        t0 = time.monotonic()
        invoice = configured.greenfield.create_invoice(store_id, amount="1000", currency="sat")
        elapsed = time.monotonic() - t0

        # The chain was exhausted and checkout landed on the mint rail with a
        # usable lightning invoice — the customer still gets to pay.
        db = payserver.data_dir / "cashupay.sqlite"
        with sqlite3.connect(str(db)) as conn:
            conn.row_factory = sqlite3.Row
            row = conn.execute(
                "SELECT payment_rail, receive_errors FROM invoices WHERE id = ?",
                (invoice["id"],),
            ).fetchone()
        assert row is not None and row["payment_rail"] == "mint", dict(row or {})

        # Both dead destinations were recorded as sanitized receive errors:
        # wallet type + fixed reason, never the noffer/NWC material itself.
        errors = json.loads(row["receive_errors"])
        assert {e["type"] for e in errors} == {"noffer", "nwc"}, errors
        raw = row["receive_errors"]
        for secret in (noffer, nwc_uri, dead_relay.ws_url):
            assert secret not in raw, f"receive_errors leaked destination material: {raw}"

        # ...and the payer sees them on the payment page, with the same
        # sanitization: wallet types named, no relay URL / URI / noffer string.
        page = requests.get(
            f"{payserver.url}/payment.php", params={"id": invoice["id"]}, timeout=15
        )
        assert page.status_code == 200
        assert "wallet connections had a problem" in page.text
        assert "NWC wallet" in page.text
        assert "Noffer (Nostr offer)" in page.text
        for secret in (noffer, nwc_uri, dead_relay.ws_url):
            assert secret not in page.text, "payment page leaked destination material"
        bolt11 = (
            invoice.get("checkout", {}).get("paymentMethods", {})
            .get("BTC-LightningNetwork", {}).get("destination")
        )
        assert bolt11 and bolt11.lower().startswith("lnbcrt"), bolt11

        assert elapsed >= MIN_CREATE_SECONDS, (
            f"invoice creation took {elapsed:.1f}s — the dead destinations were "
            "not actually waited out, so the chain was probably skipped"
        )
        assert elapsed < MAX_CREATE_SECONDS, (
            f"invoice creation took {elapsed:.1f}s with two offline destinations; "
            "the per-destination checkout budget is not being enforced"
        )
    finally:
        # Leave the shared store destination-free for whatever runs next.
        _set_destinations(payserver, store_id, [])
