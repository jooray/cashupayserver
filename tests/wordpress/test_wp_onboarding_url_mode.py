"""The plugin's "use an existing BareBits server" onboarding path.

A separate, already-configured standalone payserver plays the merchant's
existing server. The plugin validates the URL, pairs through the server's
BTCPay-compatible /api-keys/authorize redirect flow (state-token
authenticated callback), then wires WooCommerce: gateway plugin options,
webhook registered over the Greenfield API, branding, discount saved.
"""
from __future__ import annotations

import html
import json
import re
from urllib.parse import parse_qs, urlparse

import pytest
import requests

from wordpress.conftest import (
    onboarding_page,
    post_onboarding,
    page_nonces,
    wp_login,
    wp_option,
)
from fixtures.wordpress import install_woocommerce

pytestmark = pytest.mark.wordpress


def _drive_pairing(wp, configured, s: requests.Session) -> None:
    """Do what the merchant's browser does after clicking "Pair with
    BareBits": sign in on the server's authorize page, approve, and let the
    approval POST the minted key back to the plugin's callback."""
    nonces = page_nonces(onboarding_page(s, wp))
    r = s.post(
        f"{wp.url}/wp-admin/admin-post.php",
        data={"action": "barebits_start_pairing", "_wpnonce": nonces["barebits_start_pairing"]},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code == 302, r.text[:300]
    authorize_url = r.headers["Location"]
    assert authorize_url.startswith(configured.handle.url), authorize_url
    # Always the real file, never the pretty /api-keys/authorize rewrite —
    # remote hosts that ignore .htaccess 404 the extension-less path.
    assert urlparse(authorize_url).path.endswith("/api-keys/authorize.php"), authorize_url
    query = parse_qs(urlparse(authorize_url).query)
    callback = query["redirect"][0]
    assert "state=" in callback
    assert "barebits_pairing_callback" in callback
    # BTCPay-convention repeated bare permissions.
    assert "btcpay.store.webhooks.canmodifywebhooks" in query["permissions"]

    # The server side: log in and approve. Fresh session — the payserver knows
    # nothing about WordPress cookies.
    ps = requests.Session()
    body = ps.get(authorize_url, timeout=30).text
    csrf = re.search(r'name="csrf_token" value="([^"]+)"', body).group(1)
    body = ps.post(
        authorize_url,
        data={
            "action": "login",
            "username": "admin",
            "password": configured.admin_password,
            "csrf_token": csrf,
        },
        timeout=30,
    ).text
    csrf = re.search(r'name="csrf_token" value="([^"]+)"', body).group(1)
    body = ps.post(
        authorize_url,
        data={
            "action": "approve",
            "store_id": configured.store_id,
            "csrf_token": csrf,
        },
        timeout=30,
    ).text

    # The approval renders an auto-submit form POSTing the key to the
    # callback; replay it the way the browser would. The action attribute is
    # HTML-escaped (&amp; between query params), so unescape like a browser.
    form_action = html.unescape(re.search(r'<form[^>]+action="([^"]+)"', body).group(1))
    fields = {
        html.unescape(name): html.unescape(value)
        for name, value in re.findall(
            r'<input type="hidden" name="([^"]+)" value="([^"]*)"', body
        )
    }
    assert fields.get("apiKey") and fields.get("storeId") == configured.store_id, body[:500]
    r = requests.post(form_action, data=fields, timeout=60, allow_redirects=False)
    assert r.status_code in (301, 302), f"callback -> {r.status_code}: {r.text[:300]}"

    # Replay protection: the state token is single-use.
    replay = requests.post(form_action, data=fields, timeout=30)
    assert replay.status_code == 403


def test_url_mode_end_to_end(wordpress, woocommerce, configured) -> None:
    wp = wordpress
    s = wp_login(wp)

    # A URL that is reachable but not a BareBits server is refused.
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": wp.url},
    )
    assert "does not look like a BareBits server" in body, body[:2000]
    assert wp_option(wp, "barebits_mode") == ""

    # The real server validates and moves us to the pairing step.
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": configured.handle.url},
    )
    assert "Pair with your BareBits server" in body, body[:2000]
    assert wp_option(wp, "barebits_server_url") == configured.handle.url

    _drive_pairing(wp, configured, s)
    assert wp_option(wp, "barebits_store_id") == configured.store_id

    # Finish: wire WooCommerce with a 2% discount.
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": "2"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]

    assert wp_option(wp, "btcpay_gf_url") == configured.handle.url
    assert wp_option(wp, "btcpay_gf_store_id") == configured.store_id
    paired_key = wp_option(wp, "btcpay_gf_api_key")
    assert re.fullmatch(r"[0-9a-f]{64}", paired_key)

    # Webhook registered on the REMOTE server over its Greenfield API.
    webhook_opt = json.loads(
        wp.wp_cli("option", "get", "btcpay_gf_webhook", "--format=json").stdout.strip()
    )
    assert webhook_opt["url"] == f"{wp.url}/?wc-api=btcpaygf_default"
    listed = requests.get(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/webhooks",
        headers={"Authorization": f"token {configured.api_token}"},
        timeout=30,
    ).json()
    ours = [h for h in listed if h["id"] == webhook_opt["id"]]
    assert ours and ours[0]["enabled"] is True

    # `wp option get` reads through the runtime title filter (a CLI context is
    # not wp-admin), so this is the title customers see: suffixed with the
    # discount the merchant just chose.
    gateway = json.loads(
        wp.wp_cli(
            "option", "get", "woocommerce_btcpaygf_default_settings", "--format=json"
        ).stdout.strip()
    )
    assert gateway["enabled"] == "yes"
    assert "2% discount" in gateway["title"]

    # The discount option itself carries the answer; the checkout fee and the
    # title suffix are both derived from it at runtime (payment-discount.php).
    assert wp_option(wp, "barebits_discount_percent") == "2"

    # No cron pinger in URL mode: the remote server runs its own cron.
    assert wp_option(wp, "barebits_cron_key") == ""
    next_run = wp.wp_cli("cron", "event", "list", "--format=json").stdout
    assert "barebits_cron_tick" not in next_run

    # Status panel reflects the connection.
    body = onboarding_page(s, wp)
    assert "WooCommerce is connected" in body
    assert "Existing server (connected by URL)" in body


def test_pairing_denied_returns_to_onboarding(wordpress, configured) -> None:
    """A merchant clicking Deny on the approval screen lands back on the
    onboarding page with an error, and nothing is stored."""
    wp = wordpress
    s = wp_login(wp)
    post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": configured.handle.url},
    )
    nonces = page_nonces(onboarding_page(s, wp))
    r = s.post(
        f"{wp.url}/wp-admin/admin-post.php",
        data={"action": "barebits_start_pairing", "_wpnonce": nonces["barebits_start_pairing"]},
        timeout=30,
        allow_redirects=False,
    )
    callback = parse_qs(urlparse(r.headers["Location"]).query)["redirect"][0]

    # The server's deny path redirects to the callback with ?error=access_denied.
    denied = requests.get(callback + "&error=access_denied", timeout=30, allow_redirects=False)
    # GET with a valid state: the handler treats it as denied/incomplete.
    assert denied.status_code in (301, 302), denied.text[:300]
    assert wp_option(wp, "barebits_store_id") == ""

    body = onboarding_page(s, wp)
    assert "denied or came back incomplete" in body, body[:2000]
