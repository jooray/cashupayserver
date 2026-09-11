"""BareBits branding of the BTCPay WooCommerce gateway settings.

barebits_apply_btcpay_gateway_branding() runs as part of the WooCommerce
wiring (barebits_ensure_woocommerce_integration) and must set the checkout
title, customer message, and gateway logo — but only when those fields are
empty or still carry the stock BTCPay defaults, so a merchant's manual
customization survives re-runs. The logo is sideloaded into the media library
WITHOUT intermediate sizes (the BTCPay plugin fetches the icon at 'thumbnail'
size, and a 150x150 crop would truncate the wordmark).

barebits_apply_btcpay_order_states() applies the same conservatism to the
Expired→wc-failed remap: WooCommerce keeps failed orders payable, so an
expired invoice leaves the customer a retry affordance, but a merchant's
deliberate mapping choice is never overwritten.

These tests drive the real plugin PHP via wp-cli eval; they need neither
WooCommerce nor the BTCPay plugin installed, because both writers only touch
wp_options and the media library.
"""
from __future__ import annotations

import json

import pytest
import requests

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

BAREBITS_TITLE = "BareBits (Bitcoin + Lightning)"
BAREBITS_DESCRIPTION = (
    "CashApp, PayPal, and Venmo are all Bitcoin wallets. "
    "You will be redirected to BareBits to complete this payment."
)
STOCK_TITLE = "BTCPay (Bitcoin, Lightning Network, ...)"
STOCK_DESCRIPTION = "You will be redirected to BTCPay to complete your purchase."

APPLY = "barebits_apply_btcpay_gateway_branding();"
# A wp-cli eval is not a wp-admin request, so a plain get_option runs through
# payment-discount.php's runtime title filter — that read is what customers
# see. The RAW dump bypasses the filter to inspect what is actually stored.
DUMP = "echo json_encode(get_option('woocommerce_btcpaygf_default_settings'));"
DUMP_RAW = (
    "echo json_encode(barebits_gateway_stored_settings());"
)

APPLY_STATES = "barebits_apply_btcpay_order_states();"
DUMP_STATES = "echo json_encode(get_option('btcpay_gf_order_states'));"


def _settings(wp: WordPressHandle) -> dict:
    out = wp.wp_cli("eval", DUMP).stdout.strip().splitlines()[-1]
    data = json.loads(out)
    assert isinstance(data, dict), f"unexpected settings payload: {out!r}"
    return data


def _stored_settings(wp: WordPressHandle) -> dict:
    out = wp.wp_cli("eval", DUMP_RAW).stdout.strip().splitlines()[-1]
    data = json.loads(out)
    assert isinstance(data, dict), f"unexpected raw settings payload: {out!r}"
    return data


def _set_discount(wp: WordPressHandle, value: str | None) -> None:
    if value is None:
        wp.wp_cli("option", "delete", "barebits_discount_percent", check=False)
    else:
        wp.wp_cli("option", "update", "barebits_discount_percent", value, check=False)


def _order_states(wp: WordPressHandle) -> dict:
    out = wp.wp_cli("eval", DUMP_STATES).stdout.strip().splitlines()[-1]
    data = json.loads(out)
    assert isinstance(data, dict), f"unexpected order-states payload: {out!r}"
    return data


def test_branding_applied_on_fresh_settings(wordpress: WordPressHandle) -> None:
    wordpress.wp_cli("eval", APPLY)
    settings = _settings(wordpress)
    assert settings["title"] == BAREBITS_TITLE
    assert settings["description"] == BAREBITS_DESCRIPTION

    media_id = int(settings.get("icon_media_id") or 0)
    assert media_id > 0, f"icon_media_id not set: {settings}"

    # The attachment must resolve to a served PNG whose original (uncropped)
    # file is what wp_get_attachment_image_src() returns at 'thumbnail' size —
    # i.e. no intermediate sizes were generated.
    check = wordpress.wp_cli(
        "eval",
        f"$src = wp_get_attachment_image_src({media_id});"
        f"$meta = wp_get_attachment_metadata({media_id});"
        "echo json_encode(['src' => $src ? $src[0] : null, 'sizes' => $meta['sizes'] ?? null]);",
    ).stdout.strip().splitlines()[-1]
    info = json.loads(check)
    assert info["src"] and info["src"].endswith("barebits-gateway-logo.png"), info
    assert not info["sizes"], f"intermediate sizes must not exist: {info}"

    r = requests.get(info["src"], timeout=10)
    assert r.status_code == 200 and r.content[:8] == b"\x89PNG\r\n\x1a\n"


def test_branding_replaces_stock_btcpay_defaults(wordpress: WordPressHandle) -> None:
    seed = json.dumps({"enabled": "yes", "title": STOCK_TITLE, "description": STOCK_DESCRIPTION})
    wordpress.wp_cli(
        "eval",
        f"update_option('woocommerce_btcpaygf_default_settings', json_decode({seed!r}, true));",
    )
    wordpress.wp_cli("eval", APPLY)
    settings = _settings(wordpress)
    assert settings["title"] == BAREBITS_TITLE
    assert settings["description"] == BAREBITS_DESCRIPTION
    assert settings["enabled"] == "yes", "unrelated keys survive"


def test_discount_percent_lands_in_the_checkout_title(wordpress: WordPressHandle) -> None:
    """The merchant's chosen Bitcoin discount is advertised in the gateway
    title so customers see the incentive before picking a payment method —
    as a read-time suffix. The STORED title stays clean: the suffix must
    track later percent changes without ever rewriting the option."""
    wordpress.wp_cli(
        "eval", "delete_option('woocommerce_btcpaygf_default_settings');"
    )
    _set_discount(wordpress, "5")
    try:
        wordpress.wp_cli("eval", APPLY)
        settings = _settings(wordpress)
        assert settings["title"] == f"{BAREBITS_TITLE} (5% discount)"
        assert settings["description"] == BAREBITS_DESCRIPTION
        assert _stored_settings(wordpress)["title"] == BAREBITS_TITLE, (
            "the stored title must never carry the runtime discount suffix"
        )
    finally:
        _set_discount(wordpress, None)


def test_discount_title_tracks_percent_changes(wordpress: WordPressHandle) -> None:
    """Changing the percent changes the advertised title immediately — no
    re-run of the branding pass, no write to the gateway settings. This is
    the behaviour the old write-once baked-in suffix could not deliver."""
    wordpress.wp_cli(
        "eval", "delete_option('woocommerce_btcpaygf_default_settings');"
    )
    wordpress.wp_cli("eval", APPLY)
    try:
        _set_discount(wordpress, "5")
        assert _settings(wordpress)["title"] == f"{BAREBITS_TITLE} (5% discount)"

        _set_discount(wordpress, "2.5")
        assert _settings(wordpress)["title"] == f"{BAREBITS_TITLE} (2.5% discount)", (
            "the advertised percent must follow the option, decimals included"
        )

        _set_discount(wordpress, "0")
        assert _settings(wordpress)["title"] == BAREBITS_TITLE, (
            "no discount, no suffix"
        )

        assert _stored_settings(wordpress)["title"] == BAREBITS_TITLE, (
            "none of the reads above may have baked a suffix into the option"
        )
    finally:
        _set_discount(wordpress, None)


def test_branding_never_clobbers_merchant_customization(wordpress: WordPressHandle) -> None:
    seed = json.dumps({
        "title": "My Custom Gateway",
        "description": "Pay me in corn.",
        "icon_media_id": "424242",
    })
    wordpress.wp_cli(
        "eval",
        f"update_option('woocommerce_btcpaygf_default_settings', json_decode({seed!r}, true));",
    )
    wordpress.wp_cli("eval", APPLY)
    settings = _settings(wordpress)
    assert settings["title"] == "My Custom Gateway"
    assert settings["description"] == "Pay me in corn."
    assert settings["icon_media_id"] == "424242"

    # A customized title still gets the runtime discount advertisement — the
    # point of the read-time suffix is that it composes with ANY stored title
    # instead of fighting the merchant for ownership of the field.
    _set_discount(wordpress, "3")
    try:
        assert _settings(wordpress)["title"] == "My Custom Gateway (3% discount)"
        assert _stored_settings(wordpress)["title"] == "My Custom Gateway"
    finally:
        _set_discount(wordpress, None)


def test_branding_is_idempotent_reuses_attachment(wordpress: WordPressHandle) -> None:
    wordpress.wp_cli("eval", APPLY)
    first = _settings(wordpress)["icon_media_id"]
    wordpress.wp_cli("eval", APPLY)
    second = _settings(wordpress)["icon_media_id"]
    assert first == second, "re-running must reuse the sideloaded attachment"

    count = wordpress.wp_cli(
        "eval",
        "echo count(get_posts(['post_type' => 'attachment', 'numberposts' => -1,"
        " 's' => 'BareBits payment gateway logo']));",
    ).stdout.strip().splitlines()[-1]
    assert count == "1", f"expected exactly one logo attachment, got {count}"


def test_order_states_expired_remapped_to_failed(wordpress: WordPressHandle) -> None:
    """Fresh settings: the full mapping is written (the gateway's webhook
    handler indexes every state once the option exists) with Expired remapped
    from the stock wc-cancelled to wc-failed, keeping expired orders payable."""
    wordpress.wp_cli("eval", APPLY_STATES)
    states = _order_states(wordpress)
    assert states["Expired"] == "wc-failed", states
    # The rest of the map keeps the plugin's stock defaults.
    assert states["New"] == "wc-pending", states
    assert states["Settled"] == "BTCPAY_IGNORE", states
    assert states["SettledPaidOver"] == "wc-processing", states
    assert states["Invalid"] == "wc-failed", states
    assert states["ExpiredPaidPartial"] == "wc-failed", states
    assert states["ExpiredPaidLate"] == "wc-processing", states


def test_order_states_stock_cancelled_mapping_is_still_remapped(wordpress: WordPressHandle) -> None:
    """A stored mapping still carrying the stock wc-cancelled for Expired is
    treated as untouched-by-the-merchant and remapped."""
    wordpress.wp_cli(
        "eval",
        "update_option('btcpay_gf_order_states', ['Expired' => 'wc-cancelled']);",
    )
    wordpress.wp_cli("eval", APPLY_STATES)
    assert _order_states(wordpress)["Expired"] == "wc-failed"


def test_order_states_preserve_merchant_choice(wordpress: WordPressHandle) -> None:
    """A merchant who deliberately mapped Expired to something else keeps that
    choice across re-runs of the wiring."""
    wordpress.wp_cli(
        "eval",
        "update_option('btcpay_gf_order_states',"
        " ['Expired' => 'wc-on-hold', 'New' => 'wc-pending']);",
    )
    wordpress.wp_cli("eval", APPLY_STATES)
    states = _order_states(wordpress)
    assert states["Expired"] == "wc-on-hold", (
        "a merchant's deliberate Expired mapping must survive the wiring"
    )
