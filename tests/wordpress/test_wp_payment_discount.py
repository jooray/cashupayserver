"""The Bitcoin discount's SETTINGS surfaces, against a real WordPress.

The discount math and the checkout behaviour have their own coverage (the
pure functions in tests/php, the Store API + browser checkout journeys).
What this module pins is how the merchant EDITS the percentage — the two
admin surfaces that share the one barebits_discount_percent option:

  - the BareBits Connection page form (admin-post barebits_save_discount),
    driven over HTTP exactly as the browser submits it;
  - the field injected into the BTCPay gateway's own WooCommerce settings
    form, whose value must bridge into the shared option and never persist
    inside the gateway's stored settings — and whose rendered form must show
    the UNSUFFIXED stored title (the runtime "(X% discount)" suffix baking
    itself into a form save is the one way the read-time design could rot).
"""
from __future__ import annotations

import html
import json
import re

import pytest

from wordpress.conftest import onboarding_page, post_onboarding, wp_login, wp_option
from fixtures.wordpress import WordPressHandle, install_woocommerce

pytestmark = pytest.mark.wordpress

DEAD_SERVER_URL = "http://127.0.0.1:1/nonexistent"


def _mark_wired(wp: WordPressHandle) -> None:
    """The state a URL-mode shop is in after onboarding: configured enough
    that the BareBits page renders the Connection panel (which hosts the
    discount form). The dead server URL proves the form never talks to the
    payserver."""
    for name, value in {
        "barebits_mode": "url",
        "barebits_server_url": DEAD_SERVER_URL,
        "barebits_store_id": "teststore",
        "barebits_api_key": "a" * 64,
        "barebits_wired_at": "1",
    }.items():
        wp.wp_cli("option", "update", name, value, check=False)


def _eval_json(wp: WordPressHandle, code: str):
    out = wp.wp_cli("eval", code).stdout.strip().splitlines()[-1]
    return json.loads(out)


def test_connection_page_discount_form_saves_and_validates(wordpress: WordPressHandle) -> None:
    wp = wordpress
    _mark_wired(wp)
    s = wp_login(wp)

    body = onboarding_page(s, wp)
    assert "Bitcoin discount" in body, body[-2000:]

    # A fractional percent saves, normalized, and the flash advertises it.
    body = post_onboarding(s, wp, "barebits_save_discount",
                           {"barebits_discount_percent": "7.25"})
    assert "7.25% off" in body, body[-2000:]
    assert wp_option(wp, "barebits_discount_percent") == "7.25"

    # Trailing zeros are trimmed to the canonical form.
    post_onboarding(s, wp, "barebits_save_discount",
                    {"barebits_discount_percent": "3.50"})
    assert wp_option(wp, "barebits_discount_percent") == "3.5"

    # An invalid value is refused with an error and the old value kept.
    body = post_onboarding(s, wp, "barebits_save_discount",
                           {"barebits_discount_percent": "150"})
    assert "between 0 and 100" in body, body[-2000:]
    assert wp_option(wp, "barebits_discount_percent") == "3.5"

    # Empty means "no discount", not an error.
    body = post_onboarding(s, wp, "barebits_save_discount",
                           {"barebits_discount_percent": ""})
    assert "no discount is applied" in body, body[-2000:]
    assert wp_option(wp, "barebits_discount_percent") == "0"


def test_gateway_settings_field_bridges_to_the_shared_option(wordpress: WordPressHandle) -> None:
    wp = wordpress
    wp.wp_cli("option", "update", "barebits_discount_percent", "2.5", check=False)

    # The injected field renders with the CURRENT shared value as its default
    # (the gateway's stored settings never hold the key, so the settings API
    # falls back to this default when painting the form).
    fields = _eval_json(
        wp, "echo json_encode(barebits_inject_gateway_discount_field([]));"
    )
    assert fields["barebits_discount_percent"]["default"] == "2.5", fields

    # Saving the gateway form bridges the posted value into the shared option
    # and strips the key from what WooCommerce persists.
    settings = _eval_json(
        wp,
        "echo json_encode(barebits_extract_gateway_discount_field("
        "['title' => 'T', 'barebits_discount_percent' => '4.75']));",
    )
    assert settings == {"title": "T"}, settings
    assert wp_option(wp, "barebits_discount_percent") == "4.75"

    # An unusable posted value is stripped but NOT saved — the previous
    # percent survives a mangled form submit.
    settings = _eval_json(
        wp,
        "echo json_encode(barebits_extract_gateway_discount_field("
        "['title' => 'T', 'barebits_discount_percent' => '999']));",
    )
    assert settings == {"title": "T"}, settings
    assert wp_option(wp, "barebits_discount_percent") == "4.75"


def test_gateway_settings_form_shows_stored_title_and_injected_field(wordpress: WordPressHandle) -> None:
    """The one admin surface that writes the title back — the gateway's own
    settings form — must render the STORED title (no runtime suffix, or a
    save would bake it in) alongside the injected discount field carrying the
    shared option's value."""
    wp = wordpress
    install_woocommerce(wp)
    wp.wp_cli("eval", "barebits_apply_btcpay_gateway_branding();")
    wp.wp_cli("option", "update", "barebits_discount_percent", "4", check=False)

    s = wp_login(wp)
    r = s.get(
        f"{wp.url}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=btcpaygf_default",
        timeout=120,
    )
    assert r.status_code == 200, r.text[:500]

    def field_value(name: str) -> str:
        m = re.search(
            rf'<input[^>]*id="woocommerce_btcpaygf_default_{name}"[^>]*>', r.text
        )
        assert m, f"no {name} input on the gateway settings form"
        v = re.search(r'value="([^"]*)"', m.group(0))
        return html.unescape(v.group(1)) if v else ""

    assert field_value("title") == "BareBits (Bitcoin + Lightning)", (
        "the settings form must render the stored title, never the "
        "runtime-suffixed one — saving it back would bake the suffix in"
    )
    assert field_value("barebits_discount_percent") == "4"

    # And the customer-facing read (any non-admin context) still advertises.
    filtered = _eval_json(
        wp, "echo json_encode(get_option('woocommerce_btcpaygf_default_settings'));"
    )
    assert filtered["title"] == "BareBits (Bitcoin + Lightning) (4% discount)"

    # WooCommerce's OWN settings writer (the one behind the Payments-list
    # enable toggle and the REST gateway controller) read-modify-writes the
    # whole settings array through the filtered read. This CLI eval is such a
    # filtered context — the write-side stripper must keep the suffix out of
    # the stored title.
    wp.wp_cli(
        "eval",
        "$gw = WC()->payment_gateways->payment_gateways()['btcpaygf_default'];"
        "$gw->update_option('enabled', 'yes');",
    )
    stored = _eval_json(
        wp, "echo json_encode(barebits_gateway_stored_settings());"
    )
    assert stored["title"] == "BareBits (Bitcoin + Lightning)", (
        "a WC_Settings_API::update_option pass must never bake the runtime "
        f"suffix into the stored title: {stored['title']!r}"
    )
    assert stored["enabled"] == "yes", stored
