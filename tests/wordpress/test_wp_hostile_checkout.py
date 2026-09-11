"""Customer checkout against the alongside install on Local WP's host shape.

The journey and onboarding suites prove a merchant can SET UP on a
rewrite-hostile host with a tight PHP worker pool (no .htaccess rewrites, a
small fixed per-site pool — Local WP, much managed nginx hosting). This
module proves the shop can then actually SELL something there — the step the
suites used to stop short of, which let checkout ship broken on exactly that
host shape:

    a guest places a real order through the WooCommerce Store API
      -> the (real, third-party) BTCPay gateway creates the invoice on the
         alongside install over its Greenfield API
      -> the returned checkoutLink serves the BareBits payment page, not the
         surrounding WordPress site's themed 404
      -> the invoice is paid over regtest Lightning, the server's cron drains
         the signed webhook, and the order reaches a paid state.

The worker math is the whole test. Before the gateway rode api.php's query
transport, its canonical {install}/api/v1/... call fell through the web
server into WordPress and was replayed by the plugin's API bridge against
api.php — THREE simultaneous same-site PHP requests (the Store API checkout,
the bridged WordPress request, api.php). The checkout phase runs with two
workers free, one short of that chain, so it only passes via the direct
api.php transport (two simultaneous requests) the wiring now configures.

Settlement needs three simultaneous requests even on the direct transport
(cron.php, the wc-api webhook handler, and the gateway's verify call back
into api.php), so the pin is released for that phase: three free workers is
the flat MINIMUM a shop host needs to confirm orders, and this pins that the
stack works at exactly that minimum.

Needs the mint + LND fixtures — the invoice must carry a payable Lightning
rail, and settlement is asserted end to end.
"""
from __future__ import annotations

import threading
import time

import pytest
import requests

from wordpress.conftest import post_onboarding, wp_login, wp_option
from wordpress.test_wp_onboarding_install_mode import _walk_barebits_wizard
from wordpress.test_wp_woocommerce_checkout import (
    _checkout_response,
    _flush_rewrites,
    _order_field,
)
from fixtures.lnd import LndHandle
from fixtures.wordpress import WordPressHandle, install_woocommerce

pytestmark = pytest.mark.wordpress

PIN_SECONDS = 600


def _pin_one_worker_releasable(wp: WordPressHandle):
    """Like test_wp_onboarding_tight_worker_pool._pin_one_worker, but the pin
    can be RELEASED: the sleeper holds its worker only while the hold file
    exists, checking once a second. Returns a zero-argument release callable.
    """
    hold = wp.workdir / "pin-hold"
    hold.touch()
    started = wp.workdir / "pin-started"
    (wp.wp_root / "barebits-test-pin.php").write_text(
        "<?php touch(" + repr(str(started)) + ");\n"
        "$deadline = time() + " + str(PIN_SECONDS) + ";\n"
        "while (file_exists(" + repr(str(hold)) + ") && time() < $deadline) { sleep(1); }\n"
    )

    def _hold() -> None:
        try:
            requests.get(f"{wp.url}/barebits-test-pin.php", timeout=PIN_SECONDS + 30)
        except requests.RequestException:
            pass  # server torn down mid-sleep at test end — expected

    threading.Thread(target=_hold, daemon=True).start()
    deadline = time.monotonic() + 30
    while not started.exists():
        assert time.monotonic() < deadline, "worker pin never started"
        time.sleep(0.1)

    def _release() -> None:
        hold.unlink(missing_ok=True)
        time.sleep(1.5)  # one sleeper tick, so the worker is provably free

    return _release


def _invoice(wp: WordPressHandle, store_id: str, invoice_id: str) -> dict:
    """Fetch one invoice through api.php's query transport — a real file this
    host executes, exactly like every URL the gateway now builds."""
    r = requests.get(
        f"{wp.barebits_url}/api.php?cashupay_path=/api/v1/stores/{store_id}/invoices/{invoice_id}",
        headers={"Authorization": f"token {wp_option(wp, 'btcpay_gf_api_key')}"},
        timeout=30,
    )
    assert r.status_code == 200, r.text[:300]
    return r.json()


def test_checkout_and_settlement_on_starved_hostile_host(
    wordpress_hostile_tight_pool, mint, backup_mint, lnd_payer: LndHandle
) -> None:
    wp = wordpress_hostile_tight_pool
    # Two workers FREE during checkout, like the onboarding tight-pool test:
    # the pin models the unrelated traffic (admin-ajax heartbeats, WP-cron)
    # that always holds part of a real host's small pool.
    release_pin = _pin_one_worker_releasable(wp)
    s = wp_login(wp)

    # Onboard for real: install alongside, walk the wizard WITH the mint
    # (checkout needs an invoice that can actually be paid), collect
    # credentials, wire WooCommerce. All of this is proven to survive the
    # starved pool by the onboarding suites; it is scaffolding here.
    post_onboarding(s, wp, "barebits_choose_mode", {"barebits_mode": "install"})
    body = post_onboarding(s, wp, "barebits_run_install")
    assert "BareBits is installed at" in body, body[:2000]
    _walk_barebits_wizard(wp, mint.url, backup_mint.url)
    body = post_onboarding(s, wp, "barebits_collect_provision")
    assert "Connected!" in body, body[:2000]

    info = install_woocommerce(wp)
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": "0"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]
    # The wiring must have handed the gateway the direct api.php base — the
    # canonical bare URL is exactly what starves this host at checkout.
    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_gateway_url
    _flush_rewrites(wp)

    # --- phase 1: a guest checkout on the starved pool ---
    r = _checkout_response(wp, info["product_id"])
    assert r.status_code in (200, 201), (
        f"checkout failed: {r.status_code} {r.text[:500]}"
    )
    data = r.json()
    assert data.get("payment_result", {}).get("payment_status") == "success", data

    invoice_id = _order_field(wp, data["order_id"], "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", (
        f"gateway did not store a BareBits invoice id: {data}"
    )

    # The buyer's redirect target must be the BareBits payment page — served
    # by the install, not the surrounding WordPress site's themed 404. Fetch
    # the invoice's checkoutLink the way the buyer's browser would.
    store_id = wp_option(wp, "barebits_store_id")
    checkout_link = _invoice(wp, store_id, invoice_id)["checkoutLink"]
    page = requests.get(checkout_link, timeout=30)
    assert page.status_code == 200, (
        f"checkoutLink {checkout_link} -> {page.status_code}: {page.text[:300]}"
    )
    assert "Page not found" not in page.text, (
        f"checkoutLink {checkout_link} fell through into WordPress's 404"
    )
    assert invoice_id in page.text, "payment page does not show this invoice"

    # The gateway's own "pay again" link ({base}/i/{id}) must reach the same
    # page: on the query base it lands on api.php, which redirects.
    again = requests.get(
        f"{wp.barebits_gateway_url}/i/{invoice_id}", timeout=30, allow_redirects=False
    )
    assert again.status_code == 302, (again.status_code, again.text[:200])
    assert invoice_id in (again.headers.get("Location") or ""), again.headers

    # --- phase 2: pay and settle at the three-worker minimum ---
    release_pin()

    bolt11 = _invoice(wp, store_id, invoice_id)["checkout"]["paymentMethods"][
        "BTC-LightningNetwork"
    ]["destination"]
    assert bolt11.lower().startswith("lnbcrt"), bolt11
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay

    # Let the server notice settlement (the GET drives the poll), then drain
    # the webhook outbox through cron.php — the request the WP-cron pinger
    # makes every minute, driven externally here. During each drain the shop
    # host runs cron.php, the wc-api webhook handler, and the gateway's
    # verify call concurrently: exactly the three workers now free.
    cron_key = wp_option(wp, "barebits_cron_key")
    deadline = time.monotonic() + 60
    status = None
    while time.monotonic() < deadline:
        inv = _invoice(wp, store_id, invoice_id)
        cron = requests.get(
            f"{wp.barebits_url}/cron.php",
            headers={"X-CRON-KEY": cron_key},
            timeout=30,
        )
        assert cron.status_code == 200, f"cron drain refused: {cron.status_code} {cron.text[:200]}"
        status = _order_field(wp, data["order_id"], "get_status()")
        if status in ("processing", "completed"):
            break
        time.sleep(1)

    assert status in ("processing", "completed"), (
        f"order never reached a paid state (last status {status!r}, "
        f"invoice {inv.get('status')!r}); the BTCPay plugin should have run "
        f"payment_complete() on the signed InvoiceSettled webhook"
    )

    # --- phase 3: the bridge cannot be repointed through its query string ---
    # wp.org review gate (2026-09, second round), the one live assertion that
    # needs a fully SET-UP install (test_wp_api_bridge_live.py covers the
    # rest): the canonical /api/v1 URL falls through into WordPress on this
    # host and rides the plugin's API bridge, which re-encodes the query pair
    # by pair and strips any smuggled cashupay_path. Unstripped, the
    # duplicate parameter would win at api.php and this would answer as
    # server/info instead of the invoice.
    auth = {"Authorization": f"token {wp_option(wp, 'btcpay_gf_api_key')}"}
    canonical = f"{wp.barebits_url}/api/v1/stores/{store_id}/invoices/{invoice_id}"
    r = requests.get(canonical, headers=auth, timeout=30)
    assert r.status_code == 200, f"bridged canonical GET -> {r.status_code}: {r.text[:300]}"
    assert r.json().get("id") == invoice_id, r.text[:300]
    r = requests.get(
        canonical + "?cashupay_path=/api/v1/server/info&dup=1&dup=2&odd=a+b",
        headers=auth,
        timeout=30,
    )
    assert r.status_code == 200, f"bridged query GET -> {r.status_code}: {r.text[:300]}"
    assert r.json().get("id") == invoice_id, (
        f"bridged replay was repointed or corrupted by its query string: {r.text[:300]}"
    )
