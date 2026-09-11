"""HPOS auto-enable on a SQLite host: the wiring steers storage, checkout works.

WooCommerce enables HPOS order storage on every new shop, and on the SQLite
database drop-in that combination corrupts order totals: the HPOS DECIMAL
total column is a REAL, the drop-in stringifies what it reads back the way
PHP prints floats ("1.5E-5" for a 0.00001500 BTC total), and WooCommerce's
own wc_format_decimal() strips the exponent character during hydration —
1.50000000. The stored row stays correct; every read is wrong, no filter
fires early enough to repair it, and the gateway would mint a 100000x
invoice (today the server rejects the exponent string outright, so every
checkout fails instead — see includes/api/invoices.php). The host is not
hypothetical: any SQLite-drop-in WordPress gets WooCommerce's HPOS
auto-enable out of the box.

The plugin's answer is barebits_pin_order_storage_for_sqlite (decision
matrix pinned by tests/php/test_wp_order_storage_pin.php): every wiring run
pins order storage to the immune posts table on SQLite hosts — unless the
HPOS table already holds orders, which a flip would orphan. This module
proves both halves end to end on the real stack:

  1. a shop in the freshly-auto-enabled state (HPOS on, no orders) is
     steered back to the posts table by the wiring, and a real Store API
     guest checkout then settles over regtest Lightning at the CORRECT
     invoice amount, and
  2. a shop with an order already in the HPOS table is left alone by a
     re-wiring run (its own fresh shop: WooCommerce itself throws on
     enabling HPOS once unsynced posts-table orders exist, so the populated
     HPOS state can only be built order-first).
"""
from __future__ import annotations

import time
from decimal import Decimal

import pytest
import requests

from fixtures.lnd import LndHandle
from fixtures.wordpress import WC_PRODUCT_PRICE_BTC, WP_ADMIN_USER
from wordpress.conftest import wp_option
from wordpress.test_wp_woocommerce_checkout import (  # noqa: F401 — fixture
    _await_settled,
    _ensure_integration,
    _flush_rewrites,
    _order_field,
    _place_order,
    _wire,
    configured_multiworker,
)

pytestmark = pytest.mark.wordpress


def _hpos_enabled(wp) -> bool:
    out = wp.wp_cli(
        "eval",
        "echo Automattic\\WooCommerce\\Utilities\\OrderUtil::"
        "custom_orders_table_usage_is_enabled() ? 'yes' : 'no';",
    ).stdout.strip().splitlines()[-1]
    return out == "yes"


def test_wiring_steers_hpos_shop_and_checkout_settles(
    woocommerce,
    configured_multiworker,
    lnd_payer: LndHandle,
) -> None:
    configured = configured_multiworker
    wp, info = woocommerce

    # Put the shop in the state WooCommerce's deferred HPOS-for-new-shops job
    # leaves every new SQLite install in: HPOS on, no orders yet. (The golden
    # template pins HPOS off precisely because of this bug; the pin under
    # test is what lets a real shop survive without that hand-tuning.)
    wp.wp_cli("option", "update", "woocommerce_custom_orders_table_enabled", "yes")
    wp.wp_cli("option", "update", "woocommerce_newly_installed", "yes", check=False)
    assert _hpos_enabled(wp), "precondition: HPOS must be driving order storage"

    _flush_rewrites(wp)
    _wire(wp, configured)

    # --- half 1: the wiring pinned storage back to the posts table ---
    assert not _hpos_enabled(wp), (
        "wiring on a SQLite host must pin order storage to the posts table"
    )
    assert wp_option(wp, "woocommerce_custom_orders_table_enabled") == "no"
    assert wp_option(wp, "woocommerce_newly_installed") == "no", (
        "the deferred HPOS-for-new-shops flag must be cleared, or WooCommerce "
        "flips HPOS back on minutes later"
    )

    # --- checkout on the steered shop: the amount survives intact ---
    checkout = _place_order(wp, info["product_id"])
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", checkout

    total = _order_field(wp, order_id, "get_total()")
    assert Decimal(total) == Decimal(WC_PRODUCT_PRICE_BTC), (
        f"posts-table total corrupted: {total!r}"
    )

    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", checkout
    api = f"{configured.handle.url}/api/v1"
    auth = {"Authorization": f"token {configured.api_token}"}
    inv = requests.get(
        f"{api}/stores/{configured.store_id}/invoices/{invoice_id}",
        headers=auth,
        timeout=15,
    )
    inv.raise_for_status()
    body = inv.json()
    assert Decimal(str(body["amount"])) == Decimal(WC_PRODUCT_PRICE_BTC), (
        f"invoice amount corrupted: {body['amount']!r} for a "
        f"{WC_PRODUCT_PRICE_BTC} BTC order — the 1.5E-5 mangle is back"
    )

    # --- pay, settle, drain the webhook, and require a paid order ---
    bolt11 = body["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    assert bolt11.lower().startswith("lnbcrt"), bolt11
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay

    _await_settled(configured, invoice_id)

    deadline = time.monotonic() + 30
    status = None
    while time.monotonic() < deadline:
        r = configured.handle.trigger_cron()
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            break
        time.sleep(1)
    assert status in ("processing", "completed"), (
        f"order never reached a paid state (last status {status!r})"
    )


def test_rewiring_leaves_populated_hpos_table_alone(woocommerce, configured) -> None:
    """A shop with an order already in the HPOS table is left alone by a
    re-wiring run (the merchant's "Re-run WooCommerce wiring" button):
    flipping storage would make those orders invisible, which is worse than
    the degraded totals it cures. Fresh shop on purpose — once the settle
    test's posts-table order exists unsynced, WooCommerce itself refuses to
    enable HPOS at all, so the populated state must be built order-first."""
    wp, _info = woocommerce
    _wire(wp, configured)

    # Enable HPOS the way the merchant (or WooCommerce's deferred job on a
    # not-yet-wired shop) would, then trade THROUGH it.
    wp.wp_cli("option", "update", "woocommerce_custom_orders_table_enabled", "yes")
    assert _hpos_enabled(wp)
    wp.wp_cli(
        "wc", "shop_order", "create", "--status=completed", f"--user={WP_ADMIN_USER}"
    )

    _ensure_integration(wp, configured.store_id, configured.api_token)
    assert _hpos_enabled(wp), (
        "re-wiring must never flip storage away from an HPOS table that "
        "already holds orders — that would make them invisible"
    )
