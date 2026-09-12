"""On-chain onboarding on a host whose PHP lacks the GMP extension.

Shared WordPress hosts frequently ship PHP without ext-gmp. Before the fix,
the setup wizard's on-chain screen failed there in two misleading ways:

  1. "Check my xpub" POSTs action=validate_xpub expecting JSON back, but
     OnchainWallet::validateXpub died with an uncaught Error (gmp_init
     undefined) — an HTML fatal the wizard JS could only report as "could not
     reach the server".
  2. Saving a perfectly valid static address was rejected with "we could not
     read that as a Bitcoin address": the validator swallowed the same Error
     and treated the address as unparseable on every network.

The GMP-less host is simulated by starting the payserver with the gmp_*
functions disabled (calling a disabled function raises the same Error an
absent extension does). A companion happy-path case pins the AJAX contract on
a healthy host, where no e2e coverage existed before.
"""
from __future__ import annotations

import json
import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import (
    MAINNET_XPUB,
    SetupWizard,
    wizard_error,
    wizard_heading,
)

# The address from the original bug report (BIP173 P2WPKH vector).
REPORTED_ADDRESS = "bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq"

GMP_FUNCS = (
    "gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,gmp_strval,"
    "gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,gmp_and,gmp_or,"
    "gmp_setbit,gmp_testbit,gmp_neg"
)


@pytest.fixture()
def gmpless_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-gmpless-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_php_args=["-d", f"disable_functions={GMP_FUNCS}"],
    )
    yield handle
    stop_payserver(handle)


def _validate_xpub(w: SetupWizard, xpub: str) -> dict:
    """POST the wizard's validate_xpub AJAX action; the response must be JSON
    regardless of server health — that IS the regression being pinned."""
    r = w.s.post(
        w.url,
        data={
            "action": "validate_xpub",
            "xpub": xpub,
            "network": "mainnet",
            "address_type": "P2WPKH",
        },
        timeout=30,
    )
    try:
        return json.loads(r.text)
    except json.JSONDecodeError:
        pytest.fail(
            f"validate_xpub returned non-JSON (HTTP {r.status_code}): {r.text[:300]}"
        )


def test_validate_xpub_ajax_happy_path(payserver: PayserverHandle) -> None:
    """Healthy host: the endpoint returns valid:true plus 3 preview addresses."""
    w = SetupWizard(payserver.url)
    w.through_store("Xpub Check Store")

    data = _validate_xpub(w, MAINNET_XPUB)
    assert data["valid"] is True, data
    assert data["inferredNetwork"] == "mainnet"
    assert len(data["preview"]) == 3
    assert all(a.startswith("bc1q") for a in data["preview"])


def test_validate_xpub_without_gmp_returns_actionable_json(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: still JSON (not an HTML fatal), and the error tells the
    operator what to ask their host for instead of "could not reach server"."""
    w = SetupWizard(gmpless_payserver.url)
    w.through_store("GMP-less Store")

    data = _validate_xpub(w, MAINNET_XPUB)
    assert data["valid"] is False, data
    assert "GMP" in (data["error"] or ""), data


def test_static_address_saves_without_gmp(gmpless_payserver: PayserverHandle) -> None:
    """GMP-less host: a valid static address passes the pure-PHP validator and
    the wizard advances (the original bug rejected it as unreadable)."""
    w = SetupWizard(gmpless_payserver.url)
    body = w.through_store("Static GMP-less Store")
    # The on-chain screen must carry the capability warning...
    assert "GMP extension" in body
    # ...and, with nothing saved yet, open in static mode rather than
    # pre-selecting a mode that cannot work on this server.
    assert 'id="onchain_address_mode" value="static"' in body

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="static",
        onchain_static_address=REPORTED_ADDRESS,
    )
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Lightning payments"
    # The saved static address is an on-chain receive source, so zeroconf
    # follows the lightning screen even on a GMP-less host.
    body = w.post(step="lightning", lightning_action="skip")
    assert wizard_heading(body) == "Zero-conf payments"

    import sqlite3

    conn = sqlite3.connect(gmpless_payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute("SELECT * FROM stores").fetchone()
    finally:
        conn.close()
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_static_address"] == REPORTED_ADDRESS
    assert row["onchain_network"] == "mainnet"


def test_xpub_save_without_gmp_shows_actionable_error(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: trying to SAVE an xpub (form fallback path, no JS) stays
    on the on-chain screen with the php-gmp message, not a fatal."""
    w = SetupWizard(gmpless_payserver.url)
    w.through_store("Xpub GMP-less Store")

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="xpub",
        onchain_xpub=MAINNET_XPUB,
    )
    err = wizard_error(body)
    assert err is not None and "GMP" in err, f"error was: {err!r}"
    assert wizard_heading(body) == "On-chain Bitcoin payments"


def test_swaps_step_disabled_without_gmp(gmpless_payserver: PayserverHandle) -> None:
    """GMP-less host: the swaps screen explains why swaps can't be enabled."""
    w = SetupWizard(gmpless_payserver.url)
    w.through_store("Swaps GMP-less Store")
    w.post(step="onchain", onchain_action="skip")
    body = w.post(step="lightning", lightning_action="skip")
    assert wizard_heading(body) == "Submarine swaps"
    assert "GMP" in body
    # The enable button is rendered disabled ("disabled" follows the value
    # attribute inside the same <button> tag).
    assert 'name="swaps_enabled" value="1"' in body
    enable_chunk = body.split('name="swaps_enabled" value="1"', 1)[1]
    enable_chunk = enable_chunk.split(">", 1)[0]
    assert "disabled" in enable_chunk
