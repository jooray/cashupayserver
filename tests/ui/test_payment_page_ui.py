"""Customer payment.php page: BOLT11 QR rendering, live status polling."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_payment_page_renders_qr_and_polls_to_settled(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    configured = shared_configured
    page.set_default_timeout(20000)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    page.goto(f"{configured.handle.url}/payment?id={invoice_id}")

    # The QR canvas renders into #qr-lightning (and #qr-onchain when both
    # methods are configured); wait for the Lightning QR specifically.
    page.wait_for_function(
        "() => { const el = document.getElementById('qr-lightning'); return el && el.childElementCount > 0; }"
    )

    # The pending state ("Waiting for payment") should be visible.
    page.wait_for_selector("#payment-pending", state="visible")

    # Pay the invoice — the JS poller should pick it up and flip the page.
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    # Settled state shows #payment-success with class "show".
    page.wait_for_function(
        "() => { const el = document.getElementById('payment-success'); return el && el.classList.contains('show'); }",
        timeout=30000,
    )


def test_payment_page_qr_size_and_button_row(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """The QR fills the checkout column (not the old fixed 220px), the
    open-in-wallet / copy buttons share one row, and the invoice-details box
    sits below the payment method blocks."""
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_function(
        "() => document.querySelector('#qr-lightning canvas') !== null"
    )

    # Desktop viewport: the QR should render well above the old 220px.
    canvas = page.locator("#qr-lightning canvas")
    box = canvas.bounding_box()
    assert box["width"] >= 300, f"QR too small on desktop: {box['width']}px"
    assert abs(box["width"] - box["height"]) < 2, "QR should stay square"
    # Internal canvas resolution is oversized so CSS upscaling stays crisp.
    assert canvas.evaluate("c => c.width") >= 380

    # "Pay with BTC wallet" + Copy Invoice share a single row.
    block = page.locator('[data-method-block="lightning"]')
    open_box = block.locator("a.btn", has_text="Pay with BTC wallet").bounding_box()
    copy_box = block.locator("button.btn", has_text="Copy Invoice").bounding_box()
    assert abs(open_box["y"] - copy_box["y"]) < 2, "buttons should share a row"
    assert open_box["x"] + open_box["width"] <= copy_box["x"] + 1

    # Invoice details were moved below the method blocks to lift the QR.
    assert page.evaluate(
        """() => {
            const details = document.querySelector('#payment-pending .invoice-details');
            const block = document.querySelector('[data-method-block="lightning"]');
            return !!(details && block
                && (block.compareDocumentPosition(details) & Node.DOCUMENT_POSITION_FOLLOWING));
        }"""
    )

    # One compact header line: store name, then "powered by", then the
    # BareBits wordmark (the same asset as the admin header) — no
    # lightning-bolt glyph.
    header = page.locator(".merchant-header")
    assert header.count() == 1
    assert header.locator("img.logo").count() == 1
    assert (header.locator("img.logo").get_attribute("src") or "").endswith("img/barebits-logo.svg")
    assert header.locator("img.logo").get_attribute("alt") == "BareBits"
    assert "\u26a1" not in header.inner_text()
    assert header.locator(".merchant-name").count() == 1
    assert header.locator(".powered-by").inner_text().strip() == "powered by"
    assert page.evaluate(
        """() => {
            const h = document.querySelector('.merchant-header');
            const [name, by, logo] = ['.merchant-name', '.powered-by', 'img.logo'].map(s => h.querySelector(s));
            const after = (a, b) => !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
            return after(name, by) && after(by, logo);
        }"""
    ), "header order must be store name, 'powered by', logo"
    name_box = header.locator(".merchant-name").bounding_box()
    logo_box = header.locator("img.logo").bounding_box()
    assert name_box["x"] + name_box["width"] <= logo_box["x"] + 1
    assert abs(name_box["y"] + name_box["height"] / 2 - (logo_box["y"] + logo_box["height"] / 2)) < 6
    # The Lightning-network blurb is gone from the notice line.
    notice = page.locator(".barebits-notice").inner_text()
    assert "Powered by the Bitcoin Lightning Network" not in notice
    assert "pay with any Lightning or Cashu wallet" not in notice
    assert "BareBits" in notice


def test_payment_page_displays_invoice_amount(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="4242", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_function("() => document.body.innerText.includes('4242')")


# Each "Pay with" brand shows a name caption and acts as the "Pay with BTC
# wallet" button of the active method.
EXPECTED_BRANDS = ["Cash App", "Strike", "Coinbase", "Kraken", "Venmo", "PayPal"]


def _logo_hrefs(page) -> list:
    return page.eval_on_selector_all(
        "#payment-methods a.pm-item", "els => els.map(e => e.getAttribute('href'))"
    )


def test_payment_page_provider_logos_open_wallet_and_label(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")

    items = page.locator("#payment-methods .pm-item")
    items.first.wait_for(state="attached")
    assert items.count() == len(EXPECTED_BRANDS)

    for name in EXPECTED_BRANDS:
        link = page.locator(f"#payment-methods a.pm-item:has(.pm-name:text-is('{name}'))")
        assert link.count() == 1, f"expected one logo for {name}"
        # Same target as the "Pay with BTC wallet" button: the invoice's
        # lightning: URI, opened in this tab (no provider-site new-tab link).
        assert link.get_attribute("href") == f"lightning:{bolt11}"
        assert link.get_attribute("target") is None
        assert link.locator("img.pm-logo").get_attribute("alt") == name
        assert "Pay with BTC wallet" in (link.get_attribute("aria-label") or "")

    # "or any Bitcoin wallet" sits on its own line beneath the logos, larger
    # than the brand captions.
    label = page.locator("#payment-methods .pm-label-any")
    assert label.inner_text().strip() == "or any Bitcoin wallet"
    label_box = label.bounding_box()
    logos_box = page.locator("#payment-methods .pm-logos").bounding_box()
    assert label_box["y"] >= logos_box["y"] + logos_box["height"] - 1, (
        "the 'or any Bitcoin wallet' label must sit below the logo row"
    )
    label_px = page.evaluate("el => parseFloat(getComputedStyle(el).fontSize)", label.element_handle())
    caption_px = page.evaluate(
        "() => parseFloat(getComputedStyle(document.querySelector('#payment-methods .pm-name')).fontSize)"
    )
    pay_with_px = page.evaluate(
        "() => parseFloat(getComputedStyle(document.querySelector('#payment-methods .pm-label:not(.pm-label-any)')).fontSize)"
    )
    assert label_px > caption_px and label_px > pay_with_px, (label_px, caption_px, pay_with_px)


def test_payment_page_provider_logos_follow_active_method(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """With both rails offered the logos track the active tab: the BIP-21 URI
    on the on-chain view, the lightning: URI on the Lightning view."""
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    addr = "bc1qpaymentlogosuitestaddr000000000000000"
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET onchain_address = ?, onchain_amount_sat = ? WHERE id = ?",
            (addr, 1000, invoice["id"]),
        )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_selector(".method-tabs", state="visible")

    expected = {
        "onchain": f"bitcoin:{addr}?amount=0.00001",
        "lightning": f"lightning:{bolt11}",
    }
    for _ in range(2):
        for method in ("onchain", "lightning"):
            page.click(f'.method-tab[data-method="{method}"]')
            assert page.get_attribute("#payment-methods", "data-method") == method
            hrefs = _logo_hrefs(page)
            assert hrefs and all(h == expected[method] for h in hrefs), (method, hrefs)
            # The logos match the method's own "Pay with BTC wallet" button.
            btn = page.get_attribute(f'[data-method-block="{method}"] a.btn', "href")
            assert btn == expected[method]


def test_payment_page_provider_logos_on_cashu_view(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """The Cashu view has no "Pay with BTC wallet" button of its own, so the
    logos reuse the Lightning URI there — and carry no link at all when the
    invoice has no Lightning leg."""
    configured = shared_configured
    page.set_default_timeout(15000)
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE stores SET offline_cashu_enabled = 1 WHERE id = ?",
            (configured.store_id,),
        )
    try:
        invoice = configured.greenfield.create_invoice(
            configured.store_id, amount="1000", currency="sat"
        )
        bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
        page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
        page.wait_for_selector(".method-tabs", state="visible")
        page.click('.method-tab[data-method="cashu"]')
        assert page.get_attribute("#payment-methods", "data-method") == "cashu"
        hrefs = _logo_hrefs(page)
        assert hrefs and all(h == f"lightning:{bolt11}" for h in hrefs), hrefs

        # Strip the Lightning leg: Cashu is the only method, nothing to open.
        with configured.handle.db() as conn:
            conn.execute(
                "UPDATE invoices SET bolt11 = NULL WHERE id = ?", (invoice["id"],)
            )
        page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
        page.wait_for_selector('[data-method-block="cashu"]', state="visible")
        assert page.locator('[data-method-block="lightning"]').count() == 0
        assert page.get_attribute("#payment-methods", "data-method") == "cashu"
        hrefs = _logo_hrefs(page)
        assert hrefs and all(h is None for h in hrefs), hrefs
    finally:
        with configured.handle.db() as conn:
            conn.execute(
                "UPDATE stores SET offline_cashu_enabled = 0 WHERE id = ?",
                (configured.store_id,),
            )


def test_payment_page_footer_powered_by_links_to_barebits(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")

    footer_link = page.locator(".footer a", has_text="BareBits")
    footer_link.wait_for(state="attached")
    assert footer_link.get_attribute("href") == "https://getbarebits.com"
    assert footer_link.get_attribute("target") == "_blank"


def test_cashu_option_only_shown_when_offline_acceptance_enabled(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """Cashu is offered on the payment screen only when the store has opted
    into offline Cashu acceptance (offline_cashu_enabled). The DB writes are
    scoped to this test's own store on the shared server (and restored)."""
    configured = shared_configured
    page.set_default_timeout(15000)

    # Offline acceptance is OFF by default → no Cashu tab or block.
    inv_off = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.goto(f"{configured.handle.url}/payment?id={inv_off['id']}")
    page.wait_for_selector("#payment-pending", state="visible")
    assert page.locator('[data-method-block="cashu"]').count() == 0
    assert page.locator('.method-tab[data-method="cashu"]').count() == 0

    # Enable offline Cashu acceptance for the store → Cashu block appears.
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE stores SET offline_cashu_enabled = 1 WHERE id = ?",
            (configured.store_id,),
        )
    try:
        inv_on = configured.greenfield.create_invoice(
            configured.store_id, amount="1000", currency="sat"
        )
        page.goto(f"{configured.handle.url}/payment?id={inv_on['id']}")
        page.wait_for_selector("#payment-pending", state="visible")
        assert page.locator('[data-method-block="cashu"]').count() == 1
    finally:
        # Restore the default so the shared session store doesn't leak state.
        with configured.handle.db() as conn:
            conn.execute(
                "UPDATE stores SET offline_cashu_enabled = 0 WHERE id = ?",
                (configured.store_id,),
            )
