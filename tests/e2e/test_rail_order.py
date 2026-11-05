"""E2E: configurable payment-rail ordering (Lightning type order + on-chain
address source order).

The module runs one shared payserver wired to BOTH the mock Strike API and
the LNURL-pay mock, so a store can hold two working Lightning rails (a
Strike key and a LUD-21 lightning address) and the configured type order
decides which one serves the invoice:

    save_lightning_payments with rail_order=lnaddress,... -> the next
      invoice's payment_rail flips from 'strike' to 'lnaddress'
    save_onchain_source_order with source_order=local,strike -> the next
      invoice's on-chain address flips from a Strike receive request to the
      store's own static address

plus the two admin-card priority widgets driven through Playwright: the
Lightning "payment path priority" rows reorder with the arrow buttons and
persist via the card's save button; the on-chain "address source priority"
rows save instantly on reorder.

PHP-side counterparts: tests/php/test_rail_order.php,
tests/php/test_invoice_ln_rail_order.php and
tests/php/test_invoice_onchain_source_order.php.
"""
from __future__ import annotations

import json
from typing import Iterator

import pytest
import requests

from conftest import (
    DEFAULT_ADMIN_PASSWORD,
    ConfiguredPayserver,
    _add_test_store,
    _start_shared_server,
)
from e2e.test_strike_onchain_receive import MockEsplora
from fixtures.api_client import AdminClient
from fixtures.lnurlp_server import LnurlpServer
from fixtures.strike_api import TEST_STRIKE_KEY, StrikeApiServer
from fixtures.browser import expand_settings_cards

LNURL_ADDRESS = "merchant@example.test"
STATIC_ADDR = "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2"
DEFAULT_LN_ORDER = ["strike", "lnaddress", "nwc", "noffer"]


@pytest.fixture(scope="module")
def shared_server_strike_lnurlp(
    request, mint, backup_mint, strike_api_shared: StrikeApiServer,
    lnurlp_server_shared: LnurlpServer,
) -> Iterator[ConfiguredPayserver]:
    """Shared server pointed at BOTH the Strike mock and the LNURL-pay mock."""
    yield from _start_shared_server(
        request,
        mint,
        backup_mint,
        extra_env={
            "CASHUPAY_STRIKE_API_BASE": strike_api_shared.api_base,
            "CASHU_LNURL_URL_TEMPLATE": lnurlp_server_shared.url_template,
        },
    )


@pytest.fixture
def cfg(
    shared_server_strike_lnurlp: ConfiguredPayserver, mint, backup_mint, request
) -> ConfiguredPayserver:
    return _add_test_store(shared_server_strike_lnurlp, mint, backup_mint, request)


@pytest.fixture(scope="module")
def mock_esplora() -> Iterator[MockEsplora]:
    s = MockEsplora()
    yield s
    s.stop()


def _save_lightning(
    admin: AdminClient, store_id: str, *, rail_order: str | None = None,
    strike_key: str | None = None, ln_address: str | None = None,
    strike_onchain: str | None = None,
) -> requests.Response:
    data: list[tuple[str, str]] = [
        ("action", "save_lightning_payments"),
        ("store_id", store_id),
    ]
    if strike_key is not None:
        data.append(("strike[]", strike_key))
    if ln_address is not None:
        data.append(("ln_addresses[]", ln_address))
    if strike_onchain is not None:
        data.append(("strike_onchain", strike_onchain))
    if rail_order is not None:
        data.append(("rail_order", rail_order))
    return admin.s.post(
        admin._admin_url, data=data,
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )


def _save_source_order(admin: AdminClient, store_id: str, order: str) -> requests.Response:
    return admin.s.post(
        admin._admin_url,
        data=[
            ("action", "save_onchain_source_order"),
            ("store_id", store_id),
            ("source_order", order),
        ],
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )


def _store_col(cfg: ConfiguredPayserver, col: str) -> str | None:
    with cfg.handle.db() as db:
        row = db.execute(
            f"SELECT {col} FROM stores WHERE id = ?", (cfg.store_id,)
        ).fetchone()
    assert row is not None
    return row[0]


def _invoice_row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        row = db.execute(
            "SELECT * FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
    assert row is not None, f"invoice {invoice_id} not found"
    return dict(row)


def _dashboard(admin: AdminClient, store_id: str) -> dict:
    return admin.s.get(
        admin._admin_url, params={"api": "dashboard", "store_id": store_id}, timeout=30
    ).json()


# ---------------------------------------------------------------------------
# HTTP contract + checkout behavior
# ---------------------------------------------------------------------------


def test_checkout_honors_lightning_rail_order(
    cfg: ConfiguredPayserver, strike_api_shared: StrikeApiServer
) -> None:
    admin = cfg.admin
    store_id = cfg.store_id

    # Two working Lightning rails: Strike key + LUD-21 lightning address.
    r = _save_lightning(
        admin, store_id, strike_key=TEST_STRIKE_KEY, ln_address=LNURL_ADDRESS
    )
    assert r.status_code == 200, r.text

    # No rail_order posted -> stored order untouched (NULL = default).
    assert _store_col(cfg, "ln_rail_order") is None

    # Default order: Strike leads.
    inv1 = cfg.greenfield.create_invoice(store_id, amount="1000", currency="sat")
    row1 = _invoice_row(cfg, inv1["id"])
    assert row1["payment_rail"] == "strike", row1["payment_rail"]
    assert row1["strike_invoice_id"], row1

    # Reorder: lightning address first. Reposting the stored key alongside is
    # the UI contract (kept entries skip the save-time probe).
    r = _save_lightning(
        admin, store_id, strike_key=TEST_STRIKE_KEY, ln_address=LNURL_ADDRESS,
        rail_order="lnaddress,nwc,noffer,strike",
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("railOrder") == ["lnaddress", "nwc", "noffer", "strike"], body
    assert _store_col(cfg, "ln_rail_order") == "lnaddress,nwc,noffer,strike"

    # The dashboard payload feeds the widget the effective order.
    dash = _dashboard(admin, store_id)
    assert dash["autoMelt"]["railOrder"] == ["lnaddress", "nwc", "noffer", "strike"], (
        dash["autoMelt"]
    )

    # The same store's next invoice now comes from the lightning address, and
    # Strike is not contacted for it.
    creates_before = set(strike_api_shared.invoices)
    inv2 = cfg.greenfield.create_invoice(store_id, amount="1000", currency="sat")
    row2 = _invoice_row(cfg, inv2["id"])
    assert row2["payment_rail"] == "lnaddress", row2["payment_rail"]
    assert row2["ln_destination"] == LNURL_ADDRESS, row2["ln_destination"]
    assert row2["strike_invoice_id"] is None, row2
    assert set(strike_api_shared.invoices) == creates_before, (
        "Strike must not be asked when a higher-priority rail works"
    )

    # Back to strike-first: the rail flips back.
    r = _save_lightning(
        admin, store_id, strike_key=TEST_STRIKE_KEY, ln_address=LNURL_ADDRESS,
        rail_order="strike,lnaddress,nwc,noffer",
    )
    assert r.status_code == 200, r.text
    inv3 = cfg.greenfield.create_invoice(store_id, amount="1000", currency="sat")
    assert _invoice_row(cfg, inv3["id"])["payment_rail"] == "strike"

    # Invalid orders are refused wholesale and leave the stored order alone.
    for bad in ("strike,lnaddress,nwc", "strike,strike,nwc,noffer", "bogus"):
        r = _save_lightning(
            admin, store_id, strike_key=TEST_STRIKE_KEY, ln_address=LNURL_ADDRESS,
            rail_order=bad,
        )
        assert r.status_code == 400, (bad, r.text)
    assert _store_col(cfg, "ln_rail_order") == "strike,lnaddress,nwc,noffer"


def test_checkout_honors_onchain_source_order(
    cfg: ConfiguredPayserver,
    strike_api_shared: StrikeApiServer,
    mock_esplora: MockEsplora,
) -> None:
    admin = cfg.admin
    store_id = cfg.store_id

    # Strike key + the on-chain option (the enable probe runs via the mock).
    r = _save_lightning(admin, store_id, strike_key=TEST_STRIKE_KEY, strike_onchain="1")
    assert r.status_code == 200, r.text
    assert r.json().get("strikeOnchainEnabled") is True

    # A local static-address source alongside, chain-watched via the mock
    # Esplora (mainnet is required by the Strike path).
    with cfg.handle.db() as db:
        db.execute(
            """
            UPDATE stores
               SET onchain_address_mode = 'static', onchain_static_address = ?,
                   onchain_static_tweak_range = 1000,
                   onchain_provider = 'esplora', onchain_provider_url = ?,
                   onchain_network = 'mainnet', onchain_min_confs = 1
             WHERE id = ?
            """,
            (STATIC_ADDR, mock_esplora.url, store_id),
        )

    # Default order: the address is minted in Strike.
    inv1 = cfg.greenfield.create_invoice(store_id, amount="21000", currency="sat")
    row1 = _invoice_row(cfg, inv1["id"])
    assert row1["strike_receive_request_id"], row1
    assert row1["onchain_address"] and row1["onchain_address"].startswith("bc1"), row1
    assert row1["onchain_address"] != STATIC_ADDR

    # Flip to local-first (the widget's instant save).
    r = _save_source_order(admin, store_id, "local,strike")
    assert r.status_code == 200, r.text
    assert r.json().get("sourceOrder") == ["local", "strike"], r.text
    assert _store_col(cfg, "onchain_source_order") == "local,strike"
    dash = _dashboard(admin, store_id)
    assert dash["onchain"]["sourceOrder"] == ["local", "strike"], dash["onchain"]

    # The next invoice uses the static address; Strike gets no receive request.
    rr_before = set(strike_api_shared.receive_requests)
    inv2 = cfg.greenfield.create_invoice(store_id, amount="21000", currency="sat")
    row2 = _invoice_row(cfg, inv2["id"])
    assert row2["onchain_address"] == STATIC_ADDR, row2["onchain_address"]
    assert row2["strike_receive_request_id"] is None, row2
    assert row2["onchain_amount_tweak_sats"] is not None, (
        "static-mode tweak expected on the local source"
    )
    assert set(strike_api_shared.receive_requests) == rr_before, (
        "no receive request when the local source comes first and works"
    )

    # Back to strike-first: the next address is Strike-minted again.
    r = _save_source_order(admin, store_id, "strike,local")
    assert r.status_code == 200, r.text
    inv3 = cfg.greenfield.create_invoice(store_id, amount="21000", currency="sat")
    row3 = _invoice_row(cfg, inv3["id"])
    assert row3["strike_receive_request_id"], row3
    assert row3["onchain_address"] != STATIC_ADDR

    # Invalid values are refused.
    for bad in ("strike", "strike,local,strike", "strike,bogus", ""):
        r = _save_source_order(admin, store_id, bad)
        assert r.status_code == 400, (bad, r.text)
    assert _store_col(cfg, "onchain_source_order") == "strike,local"


# ---------------------------------------------------------------------------
# Admin UI widgets (Playwright)
# ---------------------------------------------------------------------------


@pytest.fixture
def admin_page(cfg: ConfiguredPayserver, browser):
    """Logged-in admin page pinned to this test's store."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.add_init_script(
        "window.localStorage.setItem('selectedStoreId', "
        f"{json.dumps(cfg.store_id)});"
    )
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={
            "action": "login",
            "username": "admin",
            "password": DEFAULT_ADMIN_PASSWORD,
        },
    )
    page = ctx.new_page()
    yield page, cfg.handle.url
    ctx.close()


def _goto_stores(page, base: str) -> None:
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1200)


def _widget_order(page, list_id: str) -> list[str]:
    return page.eval_on_selector_all(
        f"#{list_id} [data-rail]", "els => els.map(e => e.dataset.rail)"
    )


@pytest.mark.ui
def test_lightning_priority_widget_reorders_and_persists(
    cfg: ConfiguredPayserver, admin_page
) -> None:
    page, base = admin_page
    _goto_stores(page, base)

    assert _widget_order(page, "ln-rail-order-list") == DEFAULT_LN_ORDER

    # Top row's up-arrow is disabled; bottom row's down-arrow is disabled.
    assert page.get_attribute("#ln-rail-order-up-strike", "disabled") is not None
    assert page.get_attribute("#ln-rail-order-down-noffer", "disabled") is not None

    # Move strike below lnaddress, then save the card.
    page.click("#ln-rail-order-down-strike")
    assert _widget_order(page, "ln-rail-order-list") == [
        "lnaddress", "strike", "nwc", "noffer",
    ]
    page.click("#btn-save-lightning-payments")
    page.wait_for_timeout(1500)
    toast_text = page.text_content("#toast")
    toast_class = page.get_attribute("#toast", "class") or ""
    assert toast_text == "Settings saved!", toast_text
    assert "error" not in toast_class.split(), toast_class
    assert _store_col(cfg, "ln_rail_order") == "lnaddress,strike,nwc,noffer"

    # A reload renders the persisted order back into the widget.
    _goto_stores(page, base)
    assert _widget_order(page, "ln-rail-order-list") == [
        "lnaddress", "strike", "nwc", "noffer",
    ]


@pytest.mark.ui
def test_onchain_source_priority_widget_saves_instantly(
    cfg: ConfiguredPayserver, admin_page
) -> None:
    page, base = admin_page
    _goto_stores(page, base)

    assert _widget_order(page, "onchain-source-order-list") == ["strike", "local"]

    # Reordering saves immediately — no separate save button.
    page.click("#onchain-source-order-down-strike")
    page.wait_for_timeout(1500)
    toast_text = page.text_content("#toast")
    toast_class = page.get_attribute("#toast", "class") or ""
    assert toast_text == "On-chain address source priority saved", toast_text
    assert "error" not in toast_class.split(), toast_class
    assert _widget_order(page, "onchain-source-order-list") == ["local", "strike"]
    assert _store_col(cfg, "onchain_source_order") == "local,strike"

    # Reload renders the persisted order; flipping back saves again.
    _goto_stores(page, base)
    assert _widget_order(page, "onchain-source-order-list") == ["local", "strike"]
    page.click("#onchain-source-order-down-local")
    page.wait_for_timeout(1500)
    assert _store_col(cfg, "onchain_source_order") == "strike,local"
