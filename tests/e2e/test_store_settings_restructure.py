"""Store-settings restructure (store-only settings refactor):

  - The "Lightning payments" card sits after Store Info and carries the
    Strike / LN-address / NWC / noffer priority lists plus the path-order
    note (Strike API, LNURL/lightning address, NWC, noffer — the order the
    saved chain is actually walked in). Since the collapsible-sections
    change, the Cashu automatic cashout + offline Cashu settings live inside
    the same card too, in its trailing "Cashu" section (no separate cards).
  - The cashout column selector has exactly two modes (the "Use global
    settings" card is gone).
  - The site-wide settings cards for swaps / self-serve / on-chain default
    (including the #aw-site selector) no longer exist.
  - The cashout settings grey out when the selected store has no Cashu mint
    wallet, and server-side when the host can run neither GMP nor BCMath.
"""
from __future__ import annotations

import re
import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard
from fixtures.browser import expand_settings_cards

GMP_FUNCS = (
    "gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,gmp_strval,"
    "gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,gmp_and,gmp_or,"
    "gmp_setbit,gmp_testbit,gmp_neg"
)
BCMATH_FUNCS = "bcadd,bcsub,bcmul,bcdiv,bcmod,bcpow,bcpowmod,bccomp,bcsqrt,bcscale"


def _walk_wizard_mintless(url: str, store: str) -> None:
    """Complete the wizard with no on-chain, no lightning and no mint — the
    minimal path that unlocks the admin dashboard."""
    w = SetupWizard(url)
    w.through_store(store)
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    w.post(step="mints", mints_enabled="0")


@pytest.fixture()
def mintless_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-restructure-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    _walk_wizard_mintless(handle.url, "Restructure Store")
    yield handle
    stop_payserver(handle)


def _admin_html(handle: PayserverHandle) -> str:
    admin = AdminClient(handle.url)
    admin.login(SetupWizard.DEFAULT_PASSWORD)
    return admin.s.get(admin._admin_url, timeout=15).text


# ----------------------------------------------------------- raw-HTML contract


def test_lightning_payments_card_order_and_note(mintless_payserver) -> None:
    page = _admin_html(mintless_payserver)

    # The card exists; the cashout settings live inside it as the trailing
    # "Cashu" section (the separate cashout card is gone).
    lp = page.index('id="card-lightning-payments"')
    cashu_section = page.index('id="ln-section-cashu"')
    assert lp < cashu_section
    assert 'id="card-cashu-cashout"' not in page

    # The old titles are gone; the new ones render (the cashout heading is a
    # label inside the Cashu section now).
    assert ">Auto-Cashout<" not in page
    assert ">Lightning payments<" in page
    assert ">Cashu automatic cashout<" in page

    # The fixed path-order note was replaced by the configurable "payment
    # path priority" widget (see test_rail_order.py for its behavior). The
    # note wraps across source lines, so compare whitespace-normalized.
    assert "Lightning payment paths are tried in this order" in re.sub(r"\s+", " ", page)
    assert ">Payment path priority<" in page

    # The priority widget and the per-type lists live in the card, before the
    # Cashu section; the save button sits at the bottom of the card, after
    # the Cashu section.
    for group in (
        'id="ln-rail-order-group"',
        'id="auto-melt-address-group"',
        'id="auto-melt-nwc-group"',
        'id="auto-melt-noffer-group"',
    ):
        assert lp < page.index(group) < cashu_section, group
    save_btn = page.index('id="btn-save-lightning-payments"')
    assert cashu_section < save_btn < page.index('id="card-store-swaps"')


def test_cashout_card_has_two_modes_no_global(mintless_payserver) -> None:
    page = _admin_html(mintless_payserver)
    # Only the store scope selector remains, with modes 0 and 1.
    aw_store = page.index('id="aw-store"')
    modes = re.findall(r'data-aw-mode="(-?\d+)"', page)
    assert sorted(modes) == ["0", "1"], modes
    assert "Use global settings" not in page
    assert 'id="auto-melt-mode-default-label"' not in page
    assert aw_store > page.index('id="ln-section-cashu"')


def test_site_wide_cards_removed(mintless_payserver) -> None:
    page = _admin_html(mintless_payserver)
    for gone in (
        'id="card-swaps"',
        'id="card-selfserve"',
        'id="card-onchain-site"',
        'id="aw-site"',
        'id="auto-melt-use-swap-default"',
        'id="btn-save-swaps"',
        'id="btn-save-selfserve"',
        'id="btn-save-onchain-site"',
        'id="swaps-enabled"',
        'id="selfserve-enabled"',
        'id="onchain-site-enabled"',
    ):
        assert gone not in page, gone
    # Email notifications stays site-wide (deliberately unchanged).
    assert 'id="card-notifications"' in page


def test_cashout_env_gate_when_no_bignum_math(mintless_payserver) -> None:
    """Restart the configured instance with both GMP and BCMath disabled:
    the cashout card must render inert with the cashu warning. (Setup can't
    complete on such a host — its requirements screen hard-fails — so the
    store is configured first and the host 'loses' the extensions after.)"""
    stop_payserver(mintless_payserver)
    handle = start_payserver(
        mintless_payserver.workdir,
        extra_php_args=["-d", f"disable_functions={GMP_FUNCS},{BCMATH_FUNCS}"],
    )
    try:
        page = _admin_html(handle)
        assert "Cashu is unavailable on this server" in page
        body = re.search(r'<div id="cashu-cashout-body"[^>]*>', page)
        assert body, "cashout body wrapper should render"
        assert 'data-env-error="1"' in body.group(0)
        assert "pointer-events: none" in body.group(0)
    finally:
        stop_payserver(handle)


def test_cashout_not_env_gated_with_math_present(mintless_payserver) -> None:
    page = _admin_html(mintless_payserver)
    assert "Cashu is unavailable on this server" not in page
    body = re.search(r'<div id="cashu-cashout-body"[^>]*>', page)
    assert body and "data-env-error" not in body.group(0)


# ------------------------------------------------------------- browser (JS)


@pytest.fixture()
def admin_page(mintless_payserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{mintless_payserver.url}/admin",
        form={
            "action": "login",
            "username": "admin",
            "password": SetupWizard.DEFAULT_PASSWORD,
        },
    )
    page = ctx.new_page()
    yield page, mintless_payserver.url
    ctx.close()


def test_cashout_greys_out_without_mint(admin_page) -> None:
    """The fixture store has no mint wallet, so the cashout card body must be
    inert with the per-store warning shown; the Lightning payments card above
    stays fully interactive."""
    page, base = admin_page
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)

    assert page.evaluate(
        "getComputedStyle(document.getElementById('cashu-cashout-body')).pointerEvents"
    ) == "none"
    assert page.evaluate(
        "document.getElementById('cashu-cashout-mint-warning').classList.contains('hidden')"
    ) is False

    # Lightning payments card is not gated by the missing mint.
    assert page.evaluate(
        "getComputedStyle(document.querySelector('#card-lightning-payments .card-body')).pointerEvents"
    ) != "none"
    assert page.evaluate("!!document.getElementById('btn-add-ln-address')")

    # Simulating a mint-configured store un-greys the card (the same code
    # path loadDashboard() drives after a real mint is added).
    page.evaluate("dashboardData.storeConfigured = true; renderAutoMeltMode();")
    assert page.evaluate(
        "getComputedStyle(document.getElementById('cashu-cashout-body')).pointerEvents"
    ) != "none"
    assert page.evaluate(
        "document.getElementById('cashu-cashout-mint-warning').classList.contains('hidden')"
    ) is True
