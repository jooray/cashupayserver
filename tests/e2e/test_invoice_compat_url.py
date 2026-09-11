"""The BTCPay-compatible invoice URL, /i/{invoiceId}.

BTCPay API clients build this link themselves by concatenating onto the
server URL they were configured with — the BTCPay-for-WooCommerce gateway's
"pay again" redirect for an order with an existing invoice is exactly
`{btcpay_gf_url}/i/{invoiceId}`. BareBits' checkout page is payment.php, so
the server must answer both forms of the link:

  - the canonical path /i/{id}, dispatched by router.php (reached through the
    .htaccess catch-all, the nginx @router front controller, or router.php
    itself), serving the payment page directly;
  - the query transport api.php?cashupay_path=/i/{id} — what the gateway
    produces when configured with the alongside install's query base
    (barebits_gateway_base_url) — answering with a redirect to the page.

Before these routes existed, every "pay again" click 404'd on every host.
"""
from __future__ import annotations

import pytest
import requests


@pytest.fixture
def invoice(shared_configured) -> dict:
    return shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="21", currency="sat"
    )


def test_canonical_i_path_serves_the_payment_page(shared_configured, invoice) -> None:
    r = requests.get(
        f"{shared_configured.handle.url}/i/{invoice['id']}", timeout=15
    )
    assert r.status_code == 200, (r.status_code, r.text[:300])
    assert invoice["id"] in r.text, "payment page does not show this invoice"


def test_query_transport_i_path_redirects_to_the_payment_page(
    shared_configured, invoice
) -> None:
    r = requests.get(
        f"{shared_configured.handle.url}/api.php?cashupay_path=/i/{invoice['id']}",
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 302, (r.status_code, r.text[:300])
    location = r.headers.get("Location") or ""
    assert invoice["id"] in location, location
    followed = requests.get(location, timeout=15)
    assert followed.status_code == 200 and invoice["id"] in followed.text


def test_unknown_invoice_answers_payment_pages_own_404(shared_configured) -> None:
    r = requests.get(
        f"{shared_configured.handle.url}/i/no-such-invoice", timeout=15
    )
    # payment.php's own 404 — proof the route reached PHP rather than dying
    # as a web-server 404 before dispatch.
    assert r.status_code == 404 and "Invoice not found" in r.text, (
        r.status_code,
        r.text[:200],
    )
