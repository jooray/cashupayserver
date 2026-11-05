"""CLINK noffer dedicated-section e2e.

The admin "Lightning payments" card keeps CLINK noffers in their own section
below the Lightning Addresses section, and submits the two as separate ordered
lists (ln_addresses[] + noffers[]) to the save_lightning_payments action. The
server combines them LN-first (noffers as fallback) via
StoreLnAddresses::chainFromLists and validates each list against its declared
type.

Covered here:
  - HTTP contract: the split lists persist LN-first then noffers, in order.
  - HTTP validation: a noffer in ln_addresses[] (or an address in noffers[])
    is rejected, since each section is type-specific now.
  - HTTP: a noffers-only chain works (no lightning address required).
  - Browser: the noffer section renders directly below the address section,
    and adding a noffer through the UI + Save persists it as type='noffer'.

The legacy single auto-detected addresses[] chain is still covered by
test_clink_noffer.py (back-compat path), which this change preserves.
"""
from __future__ import annotations

import json
import sqlite3

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import AdminClient
from fixtures.browser import expand_settings_cards

# Reference noffer from @shocknet/clink-sdk (decodes to a valid pubkey/relay/
# offer). Used only for config persistence — never dialled in this test.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)


def _post_split(admin: AdminClient, store_id: str, *, ln=(), noffers=()) -> "object":
    """POST save_lightning_payments with the split contract
    (ln_addresses[] + noffers[])."""
    data = [
        ("action", "save_lightning_payments"),
        ("store_id", store_id),
    ]
    for a in ln:
        data.append(("ln_addresses[]", a))
    for n in noffers:
        data.append(("noffers[]", n))
    return admin.s.post(
        admin._admin_url,
        data=data,
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=30,
    )


def _chain_rows(payserver, store_id: str) -> list[sqlite3.Row]:
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        return list(conn.execute(
            "SELECT address, type, position, supports_verify "
            "FROM store_ln_addresses WHERE store_id = ? ORDER BY position ASC",
            (store_id,),
        ))


# ---------------------------------------------------------------- HTTP contract


def test_split_lists_persist_ln_first_then_noffers(
    shared_configured_with_lnurlp: ConfiguredPayserver,
) -> None:
    # lnaddresses in the chain must pass the save-time LUD-21 gate.
    configured = shared_configured_with_lnurlp
    admin, store_id = configured.admin, configured.store_id
    r = _post_split(
        admin, store_id,
        ln=["primary@example.test", "backup@example.test"],
        noffers=[REFERENCE_NOFFER],
    )
    assert r.status_code == 200, r.text
    assert r.json().get("success"), r.text

    rows = _chain_rows(configured.handle, store_id)
    assert [(row["type"], row["position"]) for row in rows] == [
        ("lnaddress", 0), ("lnaddress", 1), ("noffer", 2),
    ], [dict(r) for r in rows]
    assert rows[0]["address"] == "primary@example.test"
    assert rows[1]["address"] == "backup@example.test"
    assert rows[2]["address"] == REFERENCE_NOFFER
    # noffers never carry a LUD-21 verify flag.
    assert rows[2]["supports_verify"] is None


def test_noffers_only_chain(shared_configured: ConfiguredPayserver) -> None:
    admin, store_id = shared_configured.admin, shared_configured.store_id
    r = _post_split(admin, store_id, ln=[], noffers=[REFERENCE_NOFFER])
    assert r.status_code == 200, r.text
    rows = _chain_rows(shared_configured.handle, store_id)
    assert len(rows) == 1, [dict(r) for r in rows]
    assert rows[0]["type"] == "noffer"
    assert rows[0]["address"] == REFERENCE_NOFFER


def test_noffer_in_address_list_rejected(shared_configured: ConfiguredPayserver) -> None:
    admin, store_id = shared_configured.admin, shared_configured.store_id
    # A noffer declared as a lightning address must be rejected (the address
    # section is type-specific now).
    r = _post_split(admin, store_id, ln=[REFERENCE_NOFFER], noffers=[])
    assert r.status_code == 400, r.text
    assert "lightning address" in r.json().get("error", "").lower()


def test_address_in_noffer_list_rejected(shared_configured: ConfiguredPayserver) -> None:
    admin, store_id = shared_configured.admin, shared_configured.store_id
    r = _post_split(admin, store_id, ln=[], noffers=["someone@example.test"])
    assert r.status_code == 400, r.text
    assert "noffer" in r.json().get("error", "").lower()


# ---------------------------------------------------------------------- browser


@pytest.fixture
def admin_page(shared_configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page (cookie established via context.request).

    On the shared server multiple stores exist and the dashboard defaults to
    stores[0], so pin the UI to this test's own store before any navigation."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.add_init_script(
        "window.localStorage.setItem('selectedStoreId', "
        f"{json.dumps(shared_configured.store_id)});"
    )
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, shared_configured.handle.url
    ctx.close()


def _goto_stores(page, base):
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1200)


def test_noffer_section_renders_below_addresses(admin_page) -> None:
    page, base = admin_page
    _goto_stores(page, base)

    # The dedicated noffer group exists with its list + add button.
    assert page.evaluate("!!document.getElementById('auto-melt-noffer-group')")
    assert page.evaluate("!!document.getElementById('auto-melt-noffer-list')")
    assert page.evaluate("!!document.getElementById('btn-add-noffer')")

    # …and it sits immediately after the Lightning Addresses group in the DOM.
    after = page.evaluate(
        "(() => {"
        "  const a = document.getElementById('auto-melt-address-group');"
        "  const n = document.getElementById('auto-melt-noffer-group');"
        "  return !!(a && n) && (a.compareDocumentPosition(n) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;"
        "})()"
    )
    assert after, "noffer group should follow the address group"

    # The dedicated noffer group carries the receipt-loss warning with the
    # Electrum / CLINK-plugin links.
    group_html = page.evaluate(
        "document.getElementById('auto-melt-noffer-group').innerHTML"
    )
    assert "STRONGLY recommended" in group_html
    assert 'href="https://electrum.org"' in group_html
    assert 'href="https://github.com/BareBits/electrum_clink"' in group_html


def test_add_noffer_via_ui_persists(admin_page, shared_configured: ConfiguredPayserver) -> None:
    page, base = admin_page
    _goto_stores(page, base)

    # The destination sections live in the always-active "Lightning payments"
    # card now, each one a collapsible section that starts collapsed while it
    # has no saved entries — expand the noffer section through its real
    # toggle, then add a row and fill it.
    page.query_selector("#ln-section-noffers .subsection-toggle").click()
    page.query_selector("#btn-add-noffer").click()
    page.fill("#auto-melt-noffer-list input.noffer-input", REFERENCE_NOFFER)
    page.query_selector("#btn-save-lightning-payments").click()
    page.wait_for_timeout(1500)

    rows = _chain_rows(shared_configured.handle, shared_configured.store_id)
    noffers = [dict(r) for r in rows if r["type"] == "noffer"]
    assert len(noffers) == 1, [dict(r) for r in rows]
    assert noffers[0]["address"] == REFERENCE_NOFFER
    assert noffers[0]["supports_verify"] is None
