"""The shop-side expired-invoice retry endpoint (/?cashupay-retry={invoiceId}).

The install's payment page links expired e-commerce invoices here (via
CASHUPAY_RETRY_URL_TEMPLATE). The plugin resolves the invoice back to its
WooCommerce order through the BTCPay_id order meta — the same lookup the
gateway's webhook handler uses — and lands the customer where their order
actually is:

  - order still needs payment  → WooCommerce's order-pay page, where
    clicking "Pay" makes the gateway mint a fresh invoice for the same order
  - order paid/cancelled since → the order-received page, which explains the
    order's actual state
  - no (or ambiguous) match    → the shop's front page — a dead end would
    loop back to the expired payment page that linked here

Runs against a real WordPress + WooCommerce with orders created through the
WooCommerce API (wp-cli eval); no payserver is needed — the handler never
contacts the server.
"""
from __future__ import annotations

import json

import pytest
import requests

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def _make_order(wp: WordPressHandle, product_id: int, invoice_id: str) -> dict:
    """Create a pending (needs-payment) order carrying the gateway's
    BTCPay_id meta, exactly as the BTCPay plugin stamps it at checkout.
    Returns the order id and its canonical destination URLs."""
    snippet = f"""
$order = wc_create_order();
$order->add_product(wc_get_product({product_id}), 1);
$order->set_billing_email('buyer@example.test');
$order->calculate_totals();
$order->update_meta_data('BTCPay_id', {invoice_id!r});
$order->update_status('pending');
$order->save();
echo json_encode([
    'id' => $order->get_id(),
    'pay_url' => $order->get_checkout_payment_url(),
    'received_url' => $order->get_checkout_order_received_url(),
]);
"""
    out = wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1]
    return json.loads(out)


def _retry(wp: WordPressHandle, invoice_id: str) -> requests.Response:
    return requests.get(
        f"{wp.url}/?cashupay-retry={invoice_id}",
        timeout=30,
        allow_redirects=False,
    )


def test_retry_routes_by_order_state(woocommerce) -> None:
    wp, info = woocommerce

    # --- unpaid order: the customer lands on the order-pay page --------------
    order = _make_order(wp, info["product_id"], "inv_retry_unpaid")
    r = _retry(wp, "inv_retry_unpaid")
    assert r.status_code in (301, 302), r.text[:300]
    assert r.headers["Location"] == order["pay_url"], (
        f"expected the order-pay page, got {r.headers['Location']}"
    )

    # --- the same order paid meanwhile: order-received instead ---------------
    # (The invoice expired, the customer clicked retry late, but a webhook
    # already settled the order — re-paying would double-charge.)
    wp.wp_cli(
        "eval",
        f"$o = wc_get_order({order['id']}); $o->payment_complete('txid-test'); $o->save();",
    )
    r = _retry(wp, "inv_retry_unpaid")
    assert r.status_code in (301, 302), r.text[:300]
    assert r.headers["Location"] == order["received_url"], (
        f"a paid order must land on order-received, got {r.headers['Location']}"
    )

    # --- unknown invoice: front page, never a dead end -----------------------
    r = _retry(wp, "inv_retry_nonexistent")
    assert r.status_code in (301, 302), r.text[:300]
    assert r.headers["Location"].rstrip("/") == wp.url.rstrip("/")

    # --- ambiguous match (two orders, one invoice id): refuse to guess -------
    _make_order(wp, info["product_id"], "inv_retry_dup")
    _make_order(wp, info["product_id"], "inv_retry_dup")
    r = _retry(wp, "inv_retry_dup")
    assert r.status_code in (301, 302), r.text[:300]
    assert r.headers["Location"].rstrip("/") == wp.url.rstrip("/"), (
        "two orders claiming one invoice must fall back to the front page, "
        f"got {r.headers['Location']}"
    )


def test_retry_malformed_id_never_reaches_the_database(wordpress) -> None:
    """Ids outside the server's invoice-id shape (^[A-Za-z0-9_-]{1,64}$) are
    ignored outright — the request renders as a normal page instead of
    triggering the meta lookup + redirect (wordpress.org review hardening,
    2026-09)."""
    wp = wordpress
    for bad in (
        "inv%20retry",          # whitespace
        "..%2F..%2Fetc",        # traversal shape
        "%3Cscript%3E",         # markup
        "inv%7Cretry",          # pipe
        "a" * 65,               # over the length bound
    ):
        r = requests.get(
            f"{wp.url}/?cashupay-retry={bad}", timeout=30, allow_redirects=False
        )
        assert r.status_code == 200, (
            f"malformed id {bad!r} must be ignored (plain page render), "
            f"got {r.status_code} -> {r.headers.get('Location')}"
        )


def test_retry_without_woocommerce_falls_back_to_front_page(wordpress) -> None:
    """On a WP install with no WooCommerce at all (the plugin activates fine
    without it), the retry endpoint must still answer with a safe redirect."""
    wp = wordpress
    r = _retry(wp, "inv_retry_no_woo")
    assert r.status_code in (301, 302), r.text[:300]
    assert r.headers["Location"].rstrip("/") == wp.url.rstrip("/")
