"""E2E: a Lightning-path failure must never abort invoice creation while
another rail can still serve the invoice.

Scenario 1 reproduces the reported self-serve bug end-to-end: a large amount
is rejected by the store's noffer service (NIP-69 error code 5 — the
"checkout: invalid amount (acceptable range 1-x sats)" admin-log entry), the
store runs submarine swaps in strict no-mint-fallback mode (no swap provider
can serve either), and an on-chain xpub is configured. Before the fix
Invoice::create threw and the self-serve page collapsed it into the opaque
"Could not create the invoice right now. Please try again later."; now the
invoice must come out payable on-chain, with sanitized receive_errors shown
on the payment page, and the mint must still never be quoted (strict
semantics preserved).

Scenario 2: an amount-rejecting noffer must not stop the destination walk —
the next destination in the chain (an NWC wallet) still serves the Lightning
invoice.

Both scenarios drive the public self-serve page (POST /pay/{storeId}), the
surface the bug was reported against. The mocks are the same in-repo PHP
relay/wallet mocks the unit suite uses (tests/php/mock_clink_relay.php,
tests/php/mock_nwc_wallet.php) — no external network.

PHP-side counterpart: tests/php/test_invoice_strict_swap_onchain_fallback.php.
"""
from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures import binaries, ports
from fixtures.nostr_crypto import generate_privkey, pubkey_xonly
from fixtures.onchain import TEST_TPUB, configure_store_for_onchain

REPO_ROOT = Path(__file__).resolve().parents[2]

# The rejecting mock replies with NIP-69 code 5 and range [10, 1000000] sats;
# anything above 1M sats is "too large" for the Lightning path.
NOFFER_RANGE_MAX_SATS = 1_000_000
LARGE_AMOUNT_SATS = 2_000_000
SELFSERVE_MAX_SATS = 5_000_000


def _php() -> str:
    return str(binaries.ensure(binaries.PHP)["php"])


def _start_php_mock(script: str, port_var: str, env_extra: dict) -> tuple[subprocess.Popen, int]:
    """Start one of the unit suite's PHP relay/wallet mocks on a free port."""
    port = ports.free_port()
    env = {"PATH": os.environ.get("PATH", ""), port_var: str(port), **env_extra}
    proc = subprocess.Popen(
        [_php(), str(REPO_ROOT / "tests" / "php" / script)],
        env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    try:
        ports.wait_listening(port, timeout_s=30)
    except Exception:
        proc.terminate()
        raise
    return proc, port


def _encode_noffer(pubkey: str, relay_url: str) -> str:
    """Encode a noffer with the repo's own codec (no python bech32 needed)."""
    script = (
        "require $argv[1] . '/includes/clink/noffer.php';"
        "echo ClinkNoffer::encode(['pubkey' => $argv[2], 'relay' => $argv[3],"
        " 'offer' => 'e2e-fallback', 'price_type' => ClinkNoffer::PRICE_SPONTANEOUS]);"
    )
    out = subprocess.run(
        [_php(), "-r", script, str(REPO_ROOT / "app"), pubkey, relay_url],
        capture_output=True, text=True, timeout=30, check=True,
    ).stdout.strip()
    assert out.startswith("noffer1"), out
    return out


@pytest.fixture(scope="module")
def rejecting_noffer() -> Iterator[str]:
    """A live noffer service that rejects every amount with NIP-69 code 5.
    Module-scoped: the mock (phrity/websocket server loop) is stateless across
    connections and each test wires it to its own store."""
    merchant_sk = generate_privkey()
    proc, port = _start_php_mock("mock_clink_relay.php", "MOCK_CLINK_PORT", {
        "MOCK_CLINK_MERCHANT_SK": merchant_sk,
        "MOCK_CLINK_ERROR_CODE": "5",
    })
    try:
        yield _encode_noffer(pubkey_xonly(merchant_sk), f"ws://127.0.0.1:{port}")
    finally:
        proc.terminate()


def _set_destinations(configured: ConfiguredPayserver, chain: list[tuple[str, str]]) -> None:
    with configured.handle.db() as db:
        db.execute("DELETE FROM store_ln_addresses WHERE store_id = ?", (configured.store_id,))
        for position, (dest_type, address) in enumerate(chain):
            db.execute(
                "INSERT INTO store_ln_addresses (store_id, position, address, type)"
                " VALUES (?, ?, ?, ?)",
                (configured.store_id, position, address, dest_type),
            )


def _enable_selfserve(configured: ConfiguredPayserver, max_sats: int) -> None:
    with configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_enabled = 1, selfserve_max_sats = ? WHERE id = ?",
            (max_sats, configured.store_id),
        )


def _reset_store(configured: ConfiguredPayserver) -> None:
    """Undo every store mutation these tests make (the rig is shared)."""
    with configured.handle.db() as db:
        db.execute(
            """
            UPDATE stores
               SET selfserve_enabled = 0, selfserve_max_sats = NULL,
                   swaps_enabled = -1, strict_no_mint_fallback = -1,
                   onchain_xpub = NULL, onchain_provider = 'esplora',
                   onchain_provider_url = NULL, onchain_network = 'mainnet'
             WHERE id = ?
            """,
            (configured.store_id,),
        )
        db.execute("DELETE FROM store_ln_addresses WHERE store_id = ?", (configured.store_id,))


def _selfserve_create(configured: ConfiguredPayserver, amount_sats: int) -> requests.Response:
    return requests.post(
        f"{configured.handle.url}/pay/{configured.store_id}",
        data={"amount": str(amount_sats), "currency": "sat", "notes": "e2e fallback"},
        timeout=60,
        allow_redirects=False,
    )


def _invoice_row(configured: ConfiguredPayserver, invoice_id: str) -> dict:
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT * FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
    assert row is not None, f"invoice {invoice_id} not found"
    return dict(row)


def _invoice_id_from_redirect(r: requests.Response) -> str:
    assert r.status_code == 302, (
        f"expected redirect to the payment page, got HTTP {r.status_code}: "
        f"{'Could not create the invoice' in r.text and 'the reported opaque failure' or r.text[:300]}"
    )
    location = r.headers["Location"]
    invoice_id = location.rstrip("/").split("/")[-1].split("=")[-1]
    assert invoice_id.startswith("inv"), f"unexpected redirect target: {location}"
    return invoice_id


def test_noffer_amount_rejection_falls_back_to_onchain_under_strict_swaps(
    shared_configured: ConfiguredPayserver, rejecting_noffer: str
) -> None:
    configured = shared_configured
    payserver = configured.handle
    try:
        # Store: rejecting noffer + strict-mode swaps (regtest network → no
        # provider endpoint exists, so the swap rail can't serve either) + a
        # working mint (from the rig) + an on-chain xpub.
        configure_store_for_onchain(
            payserver.data_dir / "cashupay.sqlite", configured.store_id,
            xpub=TEST_TPUB, network="regtest", start_index=7000,
        )
        with payserver.db() as db:
            db.execute(
                "UPDATE stores SET swaps_enabled = 1, strict_no_mint_fallback = 1 WHERE id = ?",
                (configured.store_id,),
            )
        _enable_selfserve(configured, SELFSERVE_MAX_SATS)
        _set_destinations(configured, [("noffer", rejecting_noffer)])

        r = _selfserve_create(configured, LARGE_AMOUNT_SATS)
        invoice_id = _invoice_id_from_redirect(r)

        row = _invoice_row(configured, invoice_id)
        # The Lightning path failed (noffer rejected the amount, no swap
        # provider, mint blocked by strict mode) — the invoice must still be
        # payable on-chain.
        assert row["payment_rail"] == "onchain", row["payment_rail"]
        assert row["onchain_address"], "no on-chain address allocated"
        assert row["bolt11"] is None, "no Lightning rail should have won"
        # Strict no-mint-fallback held: the rig's working mint was not used.
        assert row["mint_url"] is None, "strict mode must not fall back to the mint"

        # Failures are recorded, sanitized (fixed phrases; no noffer material).
        errors = json.loads(row["receive_errors"])
        types = {e["type"] for e in errors}
        assert {"noffer", "swap"} <= types, errors
        assert rejecting_noffer not in row["receive_errors"]

        # The payer lands on a payable payment page: on-chain address shown,
        # wallet problems named without leaking destination material.
        page = requests.get(
            f"{payserver.url}/payment.php", params={"id": invoice_id}, timeout=30
        )
        assert page.status_code == 200
        assert row["onchain_address"] in page.text, "on-chain payment option not offered"
        assert "wallet connections had a problem" in page.text
        assert "Noffer (Nostr offer)" in page.text
        assert "Lightning (submarine swap)" in page.text
        assert rejecting_noffer not in page.text, "payment page leaked the noffer string"
    finally:
        _reset_store(configured)


def test_noffer_amount_rejection_still_tries_next_lightning_destination(
    shared_configured: ConfiguredPayserver, rejecting_noffer: str
) -> None:
    configured = shared_configured
    wallet_sk = generate_privkey()
    client_sk = generate_privkey()
    proc, port = _start_php_mock("mock_nwc_wallet.php", "MOCK_NWC_PORT", {
        "MOCK_NWC_WALLET_SK": wallet_sk,
    })
    try:
        nwc_uri = (
            f"nostr+walletconnect://{pubkey_xonly(wallet_sk)}"
            f"?relay=ws://127.0.0.1:{port}&secret={client_sk}"
        )
        _enable_selfserve(configured, SELFSERVE_MAX_SATS)
        # Priority order: the rejecting noffer first, the live NWC wallet next.
        _set_destinations(configured, [("noffer", rejecting_noffer), ("nwc", nwc_uri)])

        r = _selfserve_create(configured, 1000)
        invoice_id = _invoice_id_from_redirect(r)

        row = _invoice_row(configured, invoice_id)
        # The chain kept walking: the NWC wallet minted the BOLT11.
        assert row["payment_rail"] == "nwc", row["payment_rail"]
        assert row["bolt11"], "NWC wallet should have produced the invoice"
        errors = json.loads(row["receive_errors"])
        assert [e["type"] for e in errors] == ["noffer"], errors
        assert "amount" in errors[0]["reason"], errors
    finally:
        proc.terminate()
        _reset_store(configured)
