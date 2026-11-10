"""CLINK noffer settings on hosts whose PHP lacks the GMP extension.

Since the NostrCrypto/BigNum port, the noffer payer path runs on ext-gmp OR
ext-bcmath: a GMP-less shared host (the common WordPress case) falls back to
BCMath instead of losing the feature. These tests pin the new contract on both
settings surfaces (the mirror of test_setup_onchain_gmp.py's coverage for the
on-chain screens, which still require GMP proper):

  - GMP-less host WITH BCMath: the wizard's noffer field is enabled, carries
    the non-blocking "enable php-gmp" advisory, and a noffer actually saves —
    from the wizard and from admin save_lightning_payments.
  - Host with NEITHER GMP nor BCMath: the environment gate fires the way the
    GMP gate used to — field disabled with an actionable message naming
    php-gmp and php-bcmath, direct POSTs rejected, stored noffers preserved
    rather than silently deleted.
  - Healthy host: no warning, no advisory, everything saves.

The degraded hosts are simulated by starting the payserver with the relevant
functions disabled (calling a disabled function raises the same Error an
absent extension does, and function_exists() reports false).
"""
from __future__ import annotations

import re
import sqlite3
import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import (
    REFERENCE_NOFFER,
    SetupWizard,
    wizard_error,
    wizard_heading,
)

GMP_FUNCS = (
    "gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,gmp_strval,"
    "gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,gmp_and,gmp_or,"
    "gmp_setbit,gmp_testbit,gmp_neg"
)
BC_FUNCS = "bcadd,bcsub,bcmul,bcdiv,bcmod,bccomp,bcpow,bcpowmod,bcsqrt,bcscale"


def _degraded_payserver(lnurlp_server, disabled: str, tag: str) -> Iterator[PayserverHandle]:
    # The lnurlp mock is routed in so the plain lnaddresses these tests save
    # alongside noffers pass the save-time LUD-21 gate.
    workdir = SESSION_TMP / f"payserver-noffer-{tag}-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_php_args=["-d", f"disable_functions={disabled}"],
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server.url_template},
    )
    yield handle
    stop_payserver(handle)


@pytest.fixture()
def gmpless_payserver(lnurlp_server) -> Iterator[PayserverHandle]:
    """GMP disabled, BCMath present: the fallback host that must WORK."""
    yield from _degraded_payserver(lnurlp_server, GMP_FUNCS, "gmpless")


@pytest.fixture()
def bignumless_payserver(lnurlp_server) -> Iterator[PayserverHandle]:
    """Neither GMP nor BCMath: the host where the gate must fire."""
    yield from _degraded_payserver(lnurlp_server, f"{GMP_FUNCS},{BC_FUNCS}", "bignumless")


def _noffer_input_chunk(body: str) -> str:
    """The <input ...> tag of the wizard's noffer field."""
    m = re.search(r'<input[^>]*name="noffers\[\]"[^>]*>', body)
    assert m, "lightning screen should render the noffer input"
    return m.group(0)


def _to_lightning_screen(w: SetupWizard, store: str) -> str:
    w.through_store(store)
    return w.post(step="onchain", onchain_action="skip")


def _chain_rows(handle: PayserverHandle) -> list[sqlite3.Row]:
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        return list(conn.execute(
            "SELECT store_id, address, type, position FROM store_ln_addresses "
            "ORDER BY position ASC"
        ))


def _seed_noffer(handle: PayserverHandle, noffer: str) -> str:
    """Insert a noffer directly, as if saved before the host degraded.
    Returns the store id it was attached to."""
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        store_id = conn.execute("SELECT id FROM stores").fetchone()[0]
        conn.execute(
            "INSERT INTO store_ln_addresses (store_id, position, address, type, supports_verify) "
            "VALUES (?, ?, ?, 'noffer', NULL)",
            (store_id, 0, noffer),
        )
        conn.commit()
    return store_id


# ---------------------------------------------- GMP-less host (BCMath works)


def test_wizard_noffer_enabled_on_bcmath_host(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host with BCMath: the noffer field is usable and the screen
    carries the non-blocking enable-php-gmp advisory instead of a gate."""
    body = _to_lightning_screen(SetupWizard(gmpless_payserver.url), "Noffer BCMath Store")
    assert wizard_heading(body) == "Lightning payments"
    assert "disabled" not in _noffer_input_chunk(body)
    assert "BCMath" in body, "advisory should say the BCMath fallback is active"
    assert "php-gmp" in body, "advisory should say what to enable"


def test_wizard_saves_noffer_on_bcmath_host(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host with BCMath: a new noffer saves and persists — the
    feature this port exists to unlock."""
    w = SetupWizard(gmpless_payserver.url)
    _to_lightning_screen(w, "Noffer Saves Store")
    body = w.post(step="lightning", lightning_action="save", **{"noffers[]": REFERENCE_NOFFER})
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Cashu mints"
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [(REFERENCE_NOFFER, "noffer")]


def test_admin_saves_noffer_on_bcmath_host(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host with BCMath: admin save_lightning_payments accepts a new
    noffer and the settings card renders ungated with the advisory."""
    admin, store_id = _admin_for(gmpless_payserver, "Admin BCMath Store")
    r = _save_lightning_payments(admin, store_id, noffers=[REFERENCE_NOFFER])
    assert r.json().get("success") is True, r.text
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [(REFERENCE_NOFFER, "noffer")]

    page = admin.s.get(admin._admin_url, timeout=15).text
    assert 'id="auto-melt-noffer-group"' in page
    assert "data-env-error" not in page
    assert "noffers are unavailable on this server" not in page
    assert "BCMath" in page, "advisory should surface on the settings card"


# ------------------------------------------ no-bignum host (the gate fires)


def test_wizard_noffer_field_disabled_without_bignum(
    bignumless_payserver: PayserverHandle,
) -> None:
    """No GMP, no BCMath: the lightning screen gates the noffer field with a
    message naming both extensions; the LNURL field stays usable."""
    body = _to_lightning_screen(SetupWizard(bignumless_payserver.url), "Noffer Gated Store")
    assert wizard_heading(body) == "Lightning payments"
    assert "php-gmp" in body
    assert "BCMath" in body
    assert "disabled" in _noffer_input_chunk(body)
    ln_chunk = re.search(r'<input[^>]*name="lightning_address"[^>]*>', body)
    assert ln_chunk and "disabled" not in ln_chunk.group(0)


def test_wizard_rejects_new_noffer_without_bignum(
    bignumless_payserver: PayserverHandle,
) -> None:
    """No GMP, no BCMath: a direct POST past the disabled field is refused
    with the actionable message and nothing is persisted."""
    w = SetupWizard(bignumless_payserver.url)
    _to_lightning_screen(w, "Noffer POST Store")
    body = w.post(step="lightning", lightning_action="save", **{"noffers[]": REFERENCE_NOFFER})
    err = wizard_error(body)
    assert err is not None and "GMP" in err, f"error was: {err!r}"
    assert wizard_heading(body) == "Lightning payments"
    assert _chain_rows(bignumless_payserver) == []


def test_wizard_save_preserves_stored_noffer_without_bignum(
    bignumless_payserver: PayserverHandle,
) -> None:
    """No GMP, no BCMath: the disabled noffer field doesn't submit, so a save
    must keep a previously stored noffer rather than silently deleting it —
    it starts working again the moment the host gains a bignum extension."""
    w = SetupWizard(bignumless_payserver.url)
    body = _to_lightning_screen(w, "Preserve Noffer Store")
    _seed_noffer(bignumless_payserver, REFERENCE_NOFFER)

    # Re-render: the stored noffer shows in the (disabled) field.
    body = w.get("lightning")
    assert REFERENCE_NOFFER in body

    body = w.post(
        step="lightning", lightning_action="save", lightning_address="keep@example.test"
    )
    assert wizard_error(body) is None, wizard_error(body)
    rows = _chain_rows(bignumless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [
        ("keep@example.test", "lnaddress"),
        (REFERENCE_NOFFER, "noffer"),
    ]


# ------------------------------------------------------------- admin helpers


def _walk_wizard_mintless(w: SetupWizard, store: str) -> None:
    """Complete the wizard with no on-chain, no lightning and no mint — the
    minimal path that unlocks the admin dashboard on a degraded host."""
    _to_lightning_screen(w, store)
    w.post(step="lightning", lightning_action="skip")
    w.post(step="mints", mints_enabled="0")


def _admin_for(handle: PayserverHandle, store: str) -> tuple[AdminClient, str]:
    w = SetupWizard(handle.url)
    _walk_wizard_mintless(w, store)
    admin = AdminClient(handle.url)
    admin.login(SetupWizard.DEFAULT_PASSWORD)
    stores = admin.list_stores()
    assert stores, "wizard walk should have created a store"
    return admin, stores[0]["id"]


def _save_lightning_payments(admin: AdminClient, store_id: str, *, ln=(), noffers=()):
    data = [
        ("action", "save_lightning_payments"),
        ("store_id", store_id),
    ]
    for a in ln:
        data.append(("ln_addresses[]", a))
    for n in noffers:
        data.append(("noffers[]", n))
    return admin.s.post(
        admin._admin_url,
        data=data,
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=30,
    )


def test_admin_rejects_new_noffer_without_bignum(
    bignumless_payserver: PayserverHandle,
) -> None:
    """No GMP, no BCMath: save_lightning_payments refuses to ADD a noffer,
    names php-gmp, and persists nothing."""
    admin, store_id = _admin_for(bignumless_payserver, "Admin Gate Store")
    r = _save_lightning_payments(admin, store_id, noffers=[REFERENCE_NOFFER])
    body = r.json()
    assert body.get("success") is not True, body
    assert "GMP" in (body.get("error") or ""), body
    assert _chain_rows(bignumless_payserver) == []

    # The settings page itself renders the section gated (the noffer group
    # lives in the "Lightning payments" card).
    page = admin.s.get(admin._admin_url, timeout=15).text
    assert 'id="auto-melt-noffer-group"' in page
    assert "data-env-error" in page
    assert "noffers are unavailable on this server" in page


def test_admin_keeps_and_removes_stored_noffers_without_bignum(
    bignumless_payserver: PayserverHandle,
) -> None:
    """No GMP, no BCMath: noffers stored before the host degraded can be
    saved back unchanged (the card stays editable) and can be removed — only
    NEW ones are refused."""
    admin, store_id = _admin_for(bignumless_payserver, "Admin Keep Store")
    _seed_noffer(bignumless_payserver, REFERENCE_NOFFER)

    # Re-saving the same chain (what the greyed-out UI submits) is allowed.
    r = _save_lightning_payments(admin, store_id, ln=["keep@example.test"], noffers=[REFERENCE_NOFFER])
    assert r.json().get("success") is True, r.text
    rows = _chain_rows(bignumless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [
        ("keep@example.test", "lnaddress"),
        (REFERENCE_NOFFER, "noffer"),
    ]

    # Removing it is allowed too.
    r = _save_lightning_payments(admin, store_id, ln=["keep@example.test"])
    assert r.json().get("success") is True, r.text
    rows = _chain_rows(bignumless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [("keep@example.test", "lnaddress")]


# ------------------------------------------------------- healthy-host contrast


def test_wizard_noffer_field_enabled_with_gmp(payserver: PayserverHandle) -> None:
    """Healthy host: no warning, no advisory, field enabled, and the noffer
    saves — pins that neither the gate nor the notice fires when the
    environment is fine."""
    w = SetupWizard(payserver.url)
    body = _to_lightning_screen(w, "Healthy Noffer Store")
    assert "php-gmp" not in body
    assert "BCMath" not in body
    assert "disabled" not in _noffer_input_chunk(body)

    body = w.post(step="lightning", lightning_action="save", **{"noffers[]": REFERENCE_NOFFER})
    assert wizard_error(body) is None, wizard_error(body)
    rows = _chain_rows(payserver)
    assert [(r["address"], r["type"]) for r in rows] == [(REFERENCE_NOFFER, "noffer")]
