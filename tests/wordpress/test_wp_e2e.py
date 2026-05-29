"""End-to-end invoice round-trip via the WP-hosted plugin.

The full Greenfield API surface is already covered by the standalone E2E
tier; here we verify the WP-specific code paths actually wire up to the
same backend: rewrite rules route /cashupay/api/v1/ to api.php, the
cashupay SQLite DB is created under CASHUPAY_DATA_DIR, and webhooks
fire normally.
"""
from __future__ import annotations

import time
import secrets

import pytest
import requests

from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.webhook_sink import WebhookSink
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def _seed_cashupay_via_wp_cli(
    wp: WordPressHandle,
    *,
    mint_url: str,
    mint_unit: str = "sat",
    admin_password: str = "wp-cashupay-pw",
) -> tuple[str, str]:
    """Populate the cashupay SQLite DB directly via the plugin's PHP. Returns
    (store_id, api_key) so the test can talk to the Greenfield API."""
    seed_words = (
        "abandon abandon abandon abandon abandon abandon "
        "abandon abandon abandon abandon abandon about"
    )
    php_snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';

Database::initialize();
Auth::setAdminPassword({admin_password!r});
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'WP Test Store',
    'mint_url' => {mint_url!r},
    'mint_unit' => {mint_unit!r},
    'seed_phrase' => {seed_words!r},
    'created_at' => Database::timestamp(),
]);
Config::set('setup_complete', true);
$key = Auth::createApiKey($storeId, 'wp-e2e');
echo $storeId . '|' . $key['key'];
"""
    result = wp.wp_cli("eval", php_snippet)
    if "|" not in result.stdout:
        raise RuntimeError(f"seeding failed: stdout={result.stdout!r} stderr={result.stderr!r}")
    # Take the last non-empty line — any PHP warnings precede the echo output.
    line = [ln for ln in result.stdout.strip().splitlines() if "|" in ln][-1]
    store_id, api_key = line.strip().split("|", 1)
    return store_id, api_key


def _flush_rewrites(wp: WordPressHandle) -> None:
    """Activate the plugin's custom rewrite rules so /cashupay/api/v1/ routes work.
    WP only honors rewrite rules when permalinks are non-default."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def test_greenfield_invoice_settles_via_wp_routes(
    wordpress: WordPressHandle,
    mint: MintHandle,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
) -> None:
    store_id, api_key = _seed_cashupay_via_wp_cli(wordpress, mint_url=mint.url)
    _flush_rewrites(wordpress)

    headers = {"Authorization": f"token {api_key}"}

    # The plugin routes /cashupay/api/v1/* to includes/api/.
    base_api = f"{wordpress.url}/cashupay/api/v1"

    # Register a webhook (also via the WP-routed API).
    r = requests.post(
        f"{base_api}/stores/{store_id}/webhooks",
        headers={**headers, "Content-Type": "application/json"},
        json={"url": webhook_sink.endpoint("wp-settle"), "authorizedEvents": {"everything": True}},
        timeout=15,
    )
    assert r.status_code in (200, 201), f"webhook create failed: {r.status_code} {r.text}"

    # Create an invoice — same Greenfield shape, just a WP URL prefix.
    r = requests.post(
        f"{base_api}/stores/{store_id}/invoices",
        headers={**headers, "Content-Type": "application/json"},
        json={"amount": "1500", "currency": "sat"},
        timeout=30,
    )
    assert r.status_code == 200, f"create failed: {r.status_code} {r.text}"
    invoice = r.json()
    invoice_id = invoice["id"]
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    assert bolt11.lower().startswith("lnbcrt")

    # Pay it from the customer LND.
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay

    # Poll get_invoice (triggers Invoice::pollSingleQuote, which flips state).
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        r = requests.get(
            f"{base_api}/stores/{store_id}/invoices/{invoice_id}", headers=headers, timeout=10
        )
        r.raise_for_status()
        if r.json().get("status") == "Settled":
            break
        time.sleep(0.5)
    else:
        raise AssertionError(f"invoice never settled; last body: {r.text}")

    # Confirm the webhook delivered through the WP plumbing.
    captured = webhook_sink.wait_for("/hook/wp-settle", count=1, timeout_s=15)
    types = {c.json().get("type") for c in captured}
    assert "InvoiceSettled" in types, f"missing InvoiceSettled in WP delivery: {types}"


def test_greenfield_server_info_via_wp_rewrite(wordpress: WordPressHandle, mint: MintHandle) -> None:
    """The /cashupay/api/v1/server/info no-auth probe should be reachable after seeding."""
    _seed_cashupay_via_wp_cli(wordpress, mint_url=mint.url)
    _flush_rewrites(wordpress)

    r = requests.get(f"{wordpress.url}/cashupay/api/v1/server/info", timeout=10)
    assert r.status_code == 200, f"status={r.status_code} body={r.text[:600]}"
    assert r.headers.get("Content-Type", "").startswith("application/json"), (
        f"non-JSON response: ct={r.headers.get('Content-Type')!r} body={r.text[:600]}"
    )
    body = r.json()
    assert body.get("isCashuPayServer") is True
    assert "BTC-LightningNetwork" in body.get("supportedPaymentMethods", [])
