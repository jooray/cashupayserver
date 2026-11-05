"""Browser + HTTP e2e for the per-store / per-invoice invoice-memo privacy
controls ("Hide store name on invoice" / "Hide note on invoice").

These gate whether the store name and the payer-facing note are embedded in
the memo a customer's wallet records (the Lightning noffer NIP-69 description
and the Cashu NUT-18 payment-request memo). Defaults are show; the payment web
page is intentionally NOT affected.

Covered here, end-to-end against the real handlers:
  - Defaults: a fresh store reports invoicePrivacy = {show, show}.
  - save_store_privacy round-trips through the dashboard read AND the stores
    columns, both directions, and a missing checkbox saves as "show" (0).
  - A per-invoice override carried in the Greenfield API invoice metadata
    persists on the invoice row (so the memo builder can honour it later).
  - The store-settings UI renders the two checkboxes, reflects the saved
    state, and the Request Payment modal pre-fills its override checkboxes
    from the store default.
  - Scope guard: hiding the store name does NOT remove it from the payment
    web page.

The actual on-the-wire memo suppression (noffer NIP-69 description) is asserted
in tests/php/test_clink_noffer_memo.php; the cashu memo shares the exact same
Invoice::buildInvoiceMemo path, unit-tested in test_invoice_memo_privacy.php.
"""
from __future__ import annotations

import json

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards


@pytest.fixture
def admin_page(shared_configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page plus the per-test store's config."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": shared_configured.admin_password},
    )
    page = ctx.new_page()
    yield page, shared_configured
    ctx.close()


def _post_action(page, body: str) -> dict:
    """Fire an admin POST action through the page's real CSRF helper."""
    return page.evaluate(
        """async (body) => {
            const r = await postWithCsrf(adminUrl, body);
            let parsed = null;
            try { parsed = await r.json(); } catch (e) { parsed = null; }
            return { status: r.status, body: parsed };
        }""",
        body,
    )


def _dashboard(page, store_id: str) -> dict:
    return page.evaluate(
        """async (sid) => {
            const r = await fetch(adminUrl + '?api=dashboard&store_id=' + encodeURIComponent(sid),
                                  { credentials: 'include' });
            return await r.json();
        }""",
        store_id,
    )


def _store_row(handle, store_id: str) -> dict:
    with handle.db() as conn:
        row = conn.execute(
            "SELECT hide_store_name_on_invoice, hide_note_on_invoice FROM stores WHERE id = ?",
            (store_id,),
        ).fetchone()
    return dict(row)


def _invoice_metadata(handle, invoice_id: str) -> dict:
    with handle.db() as conn:
        row = conn.execute(
            "SELECT metadata FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
    assert row is not None, f"invoice {invoice_id} not found"
    return json.loads(row["metadata"]) if row["metadata"] else {}


def _goto_stores(page, base):
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1000)


# --------------------------------------------------------------------- HTTP


def test_defaults_show_both(admin_page):
    page, cfg = admin_page
    page.goto(f"{cfg.handle.url}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)

    data = _dashboard(page, cfg.store_id)
    assert data["invoicePrivacy"] == {"hideStoreName": False, "hideNote": False}

    # Columns start NULL (= show) on a freshly-provisioned store.
    row = _store_row(cfg.handle, cfg.store_id)
    assert row["hide_store_name_on_invoice"] in (None, 0)
    assert row["hide_note_on_invoice"] in (None, 0)


def test_save_privacy_round_trips_both_directions(admin_page):
    page, cfg = admin_page
    page.goto(f"{cfg.handle.url}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)

    # Hide the store name, keep the note shown.
    res = _post_action(
        page,
        f"action=save_store_privacy&store_id={cfg.store_id}"
        "&hide_store_name_on_invoice=1&hide_note_on_invoice=0",
    )
    assert res["status"] == 200 and res["body"]["success"] is True

    data = _dashboard(page, cfg.store_id)
    assert data["invoicePrivacy"] == {"hideStoreName": True, "hideNote": False}
    row = _store_row(cfg.handle, cfg.store_id)
    assert row["hide_store_name_on_invoice"] == 1
    assert row["hide_note_on_invoice"] == 0

    # Flip: show the name, hide the note.
    _post_action(
        page,
        f"action=save_store_privacy&store_id={cfg.store_id}"
        "&hide_store_name_on_invoice=0&hide_note_on_invoice=1",
    )
    data = _dashboard(page, cfg.store_id)
    assert data["invoicePrivacy"] == {"hideStoreName": False, "hideNote": True}

    # A missing checkbox param is treated as "show" (0), not left untouched.
    _post_action(page, f"action=save_store_privacy&store_id={cfg.store_id}")
    row = _store_row(cfg.handle, cfg.store_id)
    assert row["hide_store_name_on_invoice"] == 0
    assert row["hide_note_on_invoice"] == 0


def test_save_privacy_rejects_unknown_store(admin_page):
    page, cfg = admin_page
    page.goto(f"{cfg.handle.url}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    res = _post_action(
        page, "action=save_store_privacy&store_id=does_not_exist&hide_store_name_on_invoice=1"
    )
    assert res["status"] == 400
    assert "not found" in (res["body"]["error"]).lower()


def test_per_invoice_metadata_override_persists(admin_page):
    page, cfg = admin_page

    # Store default = show everything; the invoice itself asks to hide the name.
    inv = cfg.greenfield.create_invoice(
        cfg.store_id, "1000", "sat",
        metadata={"itemDesc": "2x Latte", "hideStoreName": True, "hideNote": False},
    )
    meta = _invoice_metadata(cfg.handle, inv["id"])
    assert meta["hideStoreName"] is True
    assert meta["hideNote"] is False
    assert meta["itemDesc"] == "2x Latte"


def test_store_name_still_shown_on_payment_page_when_hidden(admin_page):
    """Scope guard: hiding the name only affects the embedded invoice memo,
    never the customer-facing payment web page."""
    page, cfg = admin_page
    page.goto(f"{cfg.handle.url}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    _post_action(
        page,
        f"action=save_store_privacy&store_id={cfg.store_id}"
        "&hide_store_name_on_invoice=1&hide_note_on_invoice=1",
    )
    inv = cfg.greenfield.create_invoice(
        cfg.store_id, "1000", "sat", metadata={"itemDesc": "2x Latte"},
    )
    page.goto(inv["checkoutLink"], wait_until="networkidle")
    assert cfg.store_name in page.content()


# ------------------------------------------------------------------- browser


def test_store_settings_checkboxes_render_and_persist(admin_page):
    page, cfg = admin_page
    _goto_stores(page, cfg.handle.url)

    assert page.evaluate("!!document.getElementById('store-hide-store-name')")
    assert page.evaluate("!!document.getElementById('store-hide-note')")
    assert page.evaluate("!!document.getElementById('btn-save-store-privacy')")

    # The raw checkbox is the CSS-hidden half of a toggle switch inside a
    # collapsible card, so set its state and invoke the real save handler in
    # the page rather than fighting the toggle's visibility.
    page.evaluate(
        """async () => {
            document.getElementById('store-hide-store-name').checked = true;
            document.getElementById('store-hide-note').checked = true;
            await saveStorePrivacy();
        }"""
    )
    page.wait_for_timeout(500)

    row = _store_row(cfg.handle, cfg.store_id)
    assert row["hide_store_name_on_invoice"] == 1
    assert row["hide_note_on_invoice"] == 1

    # Reload the store view; the saved state is reflected back into the boxes.
    _goto_stores(page, cfg.handle.url)
    assert page.evaluate("document.getElementById('store-hide-store-name').checked") is True
    assert page.evaluate("document.getElementById('store-hide-note').checked") is True


def test_request_modal_prefills_from_store_default(admin_page):
    page, cfg = admin_page
    # Store hides the name by default, shows the note.
    page.goto(f"{cfg.handle.url}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    _post_action(
        page,
        f"action=save_store_privacy&store_id={cfg.store_id}"
        "&hide_store_name_on_invoice=1&hide_note_on_invoice=0",
    )

    # Reload so the dashboard payload (and its invoicePrivacy) is fresh, then
    # open the Request Payment modal and read the override checkboxes.
    _goto_stores(page, cfg.handle.url)
    assert page.evaluate("!!document.getElementById('request-hide-store-name')")
    assert page.evaluate("!!document.getElementById('request-hide-note')")
    page.evaluate("openModal('modal-request')")
    page.wait_for_timeout(300)
    assert page.evaluate("document.getElementById('request-hide-store-name').checked") is True
    assert page.evaluate("document.getElementById('request-hide-note').checked") is False
