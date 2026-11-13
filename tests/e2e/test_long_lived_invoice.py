"""Long-lived (shareable) invoices end to end.

A payment request made from the admin Request modal, the self-serve page, or
the API with checkout.expirationMinutes stays payable for the shared lifetime
(90 days by default) instead of the one-hour checkout window. Its Lightning
bolt11 still lapses on its own schedule, so the payment page re-sources it:
on load when it has already lapsed, and live (with a "Refreshed ... ago"
flash under the QR) when the timer runs out while someone is watching. The
bolt11 it replaced stays under watch, so a payer who scanned the previous QR
still settles the invoice. A fiat amount is re-quoted in sats on page load.

Covered here against the real regtest stack (nutshell mint + LND payer):

  - API contract: expirationMinutes → longLived + far expirationTime; an
    e-commerce invoice (metadata.orderId) is never long-lived; range and type
    validation; invoices without the field are unchanged.
  - Payment page: ordinary invoice renders exactly as before; a long-lived
    one shows the "refreshes in" timer and the refreshed label; a lapsed
    bolt11 is replaced on load; the in-page timer triggers a live refresh
    (new QR + flash) and the refreshed invoice is payable.
  - Superseded quote: paying the PREVIOUS bolt11 after a refresh settles the
    invoice through cron's superseded-quote poll, with that bolt11 recorded.
  - Fiat re-quote on load at a moved rate (Lightning + on-chain amount, with
    the on-chain floor), locked once a payment is seen.
  - Admin Request modal and self-serve page produce long-lived invoices.

Unit-level truth tables (rail sourcing, lock, grace, static-address lock,
poll backoff) live in tests/php/test_long_lived_*.php.
"""
from __future__ import annotations

import json
import re
import time
import uuid

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle
from fixtures.onchain import OnchainContext, configure_store_for_onchain, configure_store_for_static_onchain

NINETY_DAYS_MIN = 90 * 1440


# --------------------------------------------------------------------- helpers


def _row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        r = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert r is not None, invoice_id
        return dict(r)


def _set(cfg: ConfiguredPayserver, invoice_id: str, **cols) -> None:
    sets = ", ".join(f"{k} = ?" for k in cols)
    with cfg.handle.db() as db:
        db.execute(f"UPDATE invoices SET {sets} WHERE id = ?", (*cols.values(), invoice_id))


def _parked(cfg: ConfiguredPayserver, invoice_id: str) -> list[dict]:
    with cfg.handle.db() as db:
        return [dict(r) for r in db.execute(
            "SELECT * FROM invoice_ln_quotes WHERE invoice_id = ? ORDER BY created_at, id", (invoice_id,)
        )]


def _seed_usd_rate(cfg: ConfiguredPayserver, btc_usd: float) -> None:
    """Seed ExchangeRates' fresh cache so the fiat path never phones a live
    provider (key shape mirrors ExchangeRates::saveToCache)."""
    now = int(time.time())
    payload = json.dumps({"rate": btc_usd, "timestamp": now, "provider": "e2e"})
    with cfg.handle.db() as db:
        for key in ("rate_usd", "rate_lastgood_usd"):
            db.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                (key, payload, now, now),
            )


def _create_long_lived(cfg: ConfiguredPayserver, amount: str = "1000", currency: str = "sat") -> dict:
    return cfg.greenfield.create_invoice(
        cfg.store_id, amount=amount, currency=currency,
        checkout={"expirationMinutes": NINETY_DAYS_MIN},
    )


def _bolt11(invoice: dict) -> str:
    return invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]


def _payment_url(cfg: ConfiguredPayserver, invoice_id: str) -> str:
    return f"{cfg.handle.url}/payment.php?id={invoice_id}"


def _wait_status(cfg: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30, cron: bool = False) -> dict:
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        if cron:
            cfg.handle.trigger_cron()
        last = cfg.greenfield.get_invoice(cfg.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} did not reach {status}; last={last}")


def _api_post(cfg: ConfiguredPayserver, body: dict) -> requests.Response:
    return requests.post(
        f"{cfg.handle.url}/api/v1/stores/{cfg.store_id}/invoices",
        json=body, headers={"Authorization": f"token {cfg.api_token}"}, timeout=30,
    )


# ------------------------------------------------------------------- API shape


def test_api_expiration_minutes_makes_long_lived_invoice(shared_configured: ConfiguredPayserver) -> None:
    now = int(time.time())
    inv = _create_long_lived(shared_configured)
    assert inv["longLived"] is True
    assert abs(inv["expirationTime"] - (now + NINETY_DAYS_MIN * 60)) < 120, inv["expirationTime"]
    row = _row(shared_configured, inv["id"])
    assert row["long_lived"] == 1
    # The bolt11 (a nutshell mint quote) keeps its own, much shorter expiry.
    assert row["ln_expires_at"] is not None
    assert now < row["ln_expires_at"] < now + 2 * 86400, row["ln_expires_at"]
    assert row["fee_redirect_note"] is None


def test_api_order_invoice_is_never_long_lived(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="1000", currency="sat",
        metadata={"orderId": "wc-4711"}, checkout={"expirationMinutes": 1440},
    )
    assert inv["longLived"] is False
    assert _row(shared_configured, inv["id"])["long_lived"] == 0
    # Lifetime still honoured as the invoice window (the mint quote may be shorter).
    assert inv["expirationTime"] <= int(time.time()) + 1440 * 60 + 60


def test_api_without_field_is_unchanged(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(shared_configured.store_id, amount="1000", currency="sat")
    assert inv["longLived"] is False
    # Pre-existing behaviour: a mint-rail checkout invoice expires with its
    # mint quote (nutshell: 24 h), nowhere near the shared lifetime.
    row = _row(shared_configured, inv["id"])
    assert inv["expirationTime"] == row["ln_expires_at"]
    assert inv["expirationTime"] < int(time.time()) + 7 * 86400
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=15)
    assert r.status_code == 400, r.text
    assert r.json()["reason"] == "not_refreshable"


@pytest.mark.parametrize("bad", [0, -5, "abc", 1.5, NINETY_DAYS_MIN + 1, True, [10]])
def test_api_rejects_invalid_expiration_minutes(shared_configured: ConfiguredPayserver, bad) -> None:
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": bad}})
    assert r.status_code == 400, (bad, r.text)
    assert r.json().get("code") == "validation-error", r.text
    assert "expirationMinutes" in r.json().get("message", ""), r.text


def test_api_accepts_string_and_null_expiration_minutes(shared_configured: ConfiguredPayserver) -> None:
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": "60"}})
    assert r.status_code == 200, r.text
    assert r.json()["longLived"] is True
    assert r.json()["expirationTime"] <= int(time.time()) + 3600 + 60
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": None}})
    assert r.status_code == 200, r.text
    assert r.json()["longLived"] is False


# --------------------------------------------------------------- payment page


def test_ordinary_payment_page_is_unchanged(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(shared_configured.store_id, amount="1000", currency="sat")
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=15).text
    assert 'id="ln-refreshed"' not in html
    assert "const longLived = false;" in html
    poll = requests.get(_payment_url(shared_configured, inv["id"]) + "&json=1", timeout=15).json()
    assert poll["longLived"] is False
    assert "bolt11" not in poll


def test_lapsed_bolt11_is_refreshed_on_page_load(shared_configured: ConfiguredPayserver) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    # Pretend the quote lapsed: the server re-sources it before rendering.
    _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 10)
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=30).text
    row = _row(shared_configured, inv["id"])
    assert row["bolt11"] != first, "lapsed bolt11 replaced on load"
    assert row["bolt11"].lower().startswith("lnbcrt")
    assert row["ln_expires_at"] > int(time.time()) + 60
    assert row["ln_refreshed_at"] is not None
    assert row["bolt11"] in html, "page rendered the fresh bolt11"
    assert 'id="ln-refreshed"' in html
    assert "const longLived = true;" in html
    parked = _parked(shared_configured, inv["id"])
    assert [p["bolt11"] for p in parked] == [first]
    assert parked[0]["state"] == "open"
    poll = requests.get(_payment_url(shared_configured, inv["id"]) + "&json=1", timeout=15).json()
    assert poll["longLived"] is True and poll["bolt11"] == row["bolt11"]
    assert poll["lnExpiresAt"] == row["ln_expires_at"]


def test_live_refresh_in_page_and_refreshed_invoice_is_payable(
    shared_configured: ConfiguredPayserver, lnd_payer: LndHandle, browser
) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    url = _payment_url(shared_configured, inv["id"])
    ctx = browser.new_context()
    page = ctx.new_page()
    try:
        page.goto(url, wait_until="domcontentloaded")
        page.wait_for_selector("#ln-refreshed", timeout=10000)
        page.wait_for_function(
            "document.getElementById('timer').textContent.startsWith('Lightning invoice refreshes in')",
            timeout=10000,
        )
        assert page.locator("#ln-refreshed").inner_text().startswith("Refreshed ")
        # Watch for the one-second flash the refresh must trigger.
        page.evaluate(
            """() => {
                window.__flashes = 0;
                new MutationObserver(() => {
                    if (document.getElementById('ln-refreshed').classList.contains('flash')) window.__flashes++;
                }).observe(document.getElementById('ln-refreshed'), { attributes: true, attributeFilter: ['class'] });
            }"""
        )
        # Lapse the bolt11: server-side (so the refresh is due) and in the
        # page's own clock (so its timer fires without waiting an hour).
        _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 1)
        page.evaluate("lnExpiresAt = Math.floor(Date.now() / 1000) + 2")
        page.wait_for_function(
            "(first) => document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy !== first",
            arg=first, timeout=20000,
        )
        shown = page.evaluate("document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy")
        row = _row(shared_configured, inv["id"])
        assert shown == row["bolt11"] != first
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] a.btn').href") == "lightning:" + row["bolt11"].upper()
        # The refreshed QR and the button carry the same string.
        assert page.get_attribute("#qr-lightning", "data-qr-value") == "lightning:" + row["bolt11"].upper()
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] button[data-copy]').dataset.copy") == row["bolt11"]
        page.wait_for_function("window.__flashes >= 1", timeout=5000)
        page.wait_for_function(
            "document.getElementById('timer').textContent.startsWith('Lightning invoice refreshes in')",
            timeout=10000,
        )
        assert page.locator("#ln-refreshed").inner_text().startswith("Refreshed ")
        assert row["status"] == "New"
        assert [p["bolt11"] for p in _parked(shared_configured, inv["id"])] == [first]

        # The refreshed bolt11 is a real, payable quote.
        pay = lnd_payer.pay_invoice_sync(row["bolt11"], timeout=30)
        assert not pay.get("payment_error"), pay
        page.wait_for_selector("#payment-success.show", timeout=30000)
    finally:
        ctx.close()
    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=30)
    assert settled["status"] == "Settled"
    assert _row(shared_configured, inv["id"])["bolt11"] == row["bolt11"]


def test_paying_the_previous_bolt11_after_refresh_settles(
    shared_configured: ConfiguredPayserver, lnd_payer: LndHandle
) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 1)
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=30)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["ok"] is True and body["bolt11"] != first, body
    second = body["bolt11"]
    # Refreshing again right away is refused (lock) but reports the current bolt11.
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=30)
    assert r.json()["ok"] is False and r.json()["reason"] in ("busy", "not_due") and r.json()["bolt11"] == second

    # The payer scanned the FIRST QR a moment before the refresh.
    pay = lnd_payer.pay_invoice_sync(first, timeout=30)
    assert not pay.get("payment_error"), pay

    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=60, cron=True)
    assert settled["status"] == "Settled"
    row = _row(shared_configured, inv["id"])
    assert row["bolt11"] == first, "the bolt11 actually paid is recorded on the invoice"
    assert row["settled_rail"] == "mint"
    states = {p["bolt11"]: p["state"] for p in _parked(shared_configured, inv["id"])}
    assert states[first] == "settled"
    assert states[second] in ("open", "closed")
    summary = shared_configured.handle.trigger_cron_json()
    assert "poll_superseded_ln" in summary.get("tasks", {}), summary


# ------------------------------------------------------------ fiat re-quote


def test_fiat_invoice_is_requoted_on_load(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    configure_store_for_onchain(
        shared_configured.handle.db_path, shared_configured.store_id,
        xpub=onchain.tpub, network="regtest", address_type="P2WPKH",
        min_confs=1, confirm_timeout_sec=86400, provider_url=onchain.watch_wallet_url,
        start_index=700,
    )
    _seed_usd_rate(shared_configured, 100_000.0)  # $10 = 10 000 sats
    inv = _create_long_lived(shared_configured, amount="10.00", currency="USD")
    assert inv["longLived"] is True
    row = _row(shared_configured, inv["id"])
    assert row["amount_sats"] == 10_000 and row["onchain_amount_sat"] == 10_000
    first = row["bolt11"]

    # Link opened later, after the price doubled: $10 is now 5 000 sats.
    _seed_usd_rate(shared_configured, 200_000.0)
    _set(shared_configured, inv["id"], requoted_at=None)
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=30).text
    row = _row(shared_configured, inv["id"])
    assert row["onchain_amount_sat"] == 5_000
    assert row["onchain_amount_floor_sat"] == 5_000
    assert row["amount_sats"] == 5_000
    assert row["bolt11"] != first, "Lightning re-sourced at the new amount"
    assert float(row["exchange_rate"]) == 200_000.0
    assert row["amount"] == "10.00"
    assert "5,000 sat" in html
    assert row["bolt11"] in html
    assert [p["bolt11"] for p in _parked(shared_configured, inv["id"])] == [first]

    # Reload inside the throttle window: nothing moves.
    _seed_usd_rate(shared_configured, 50_000.0)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    assert _row(shared_configured, inv["id"])["onchain_amount_sat"] == 5_000

    # Later reload at the lower price: amount up, floor stays at the minimum shown.
    _set(shared_configured, inv["id"], requoted_at=None)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    row = _row(shared_configured, inv["id"])
    assert row["onchain_amount_sat"] == 20_000 and row["onchain_amount_floor_sat"] == 5_000
    assert row["amount_sats"] == 20_000

    # Pay the earlier (5 000 sat) on-chain amount: it was shown, so it settles.
    txid = onchain.fund_address(row["onchain_address"], 5_000)
    assert txid
    onchain.bitcoind.mine(1)
    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=60, cron=True)
    assert settled["status"] == "Settled"
    assert _row(shared_configured, inv["id"])["settled_rail"] == "onchain"


def test_requote_locked_once_payment_seen(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    configure_store_for_onchain(
        shared_configured.handle.db_path, shared_configured.store_id,
        xpub=onchain.tpub, network="regtest", address_type="P2WPKH",
        min_confs=6, confirm_timeout_sec=86400, provider_url=onchain.watch_wallet_url,
        start_index=740,
    )
    _seed_usd_rate(shared_configured, 100_000.0)
    inv = _create_long_lived(shared_configured, amount="10.00", currency="USD")
    row = _row(shared_configured, inv["id"])
    onchain.fund_address(row["onchain_address"], 10_000)  # mempool only (min_confs=6)
    _wait_status(shared_configured, inv["id"], "Processing", timeout_s=60, cron=True)
    _seed_usd_rate(shared_configured, 200_000.0)
    _set(shared_configured, inv["id"], requoted_at=None)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    after = _row(shared_configured, inv["id"])
    assert after["onchain_amount_sat"] == 10_000 and after["onchain_amount_floor_sat"] is None
    assert after["amount_sats"] == 10_000 and after["bolt11"] == row["bolt11"]


# ---------------------------------------------------- admin modal + self-serve


def test_admin_request_modal_creates_long_lived_invoice(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": cfg.admin_password},
    )
    page = ctx.new_page()
    # The admin picks its store from localStorage; pin it before the first load.
    page.add_init_script("localStorage.setItem('selectedStoreId', %s)" % json.dumps(cfg.store_id))
    try:
        page.goto(f"{cfg.handle.url}/admin", wait_until="networkidle")
        page.wait_for_function(
            "typeof dashboardData !== 'undefined' && dashboardData && dashboardData.sharedInvoiceLifetimeMinutes > 0"
            " && (dashboardData.stores || []).some(s => s.internalApiKey)",
            timeout=20000,
        )
        assert page.evaluate("dashboardData.sharedInvoiceLifetimeMinutes") == NINETY_DAYS_MIN
        assert page.evaluate("currentStoreId") == cfg.store_id
        page.evaluate("openModal('modal-request')")
        page.fill("#request-amount", "1234")
        with page.expect_request(
            lambda r: r.method == "POST" and "/api/v1/stores/" in r.url and r.url.endswith("/invoices"),
            timeout=30000,
        ) as req_info:
            page.click("#btn-generate-request")
        req = req_info.value
        body = json.loads(req.post_data)
        # The modal asks for the shared lifetime: that is what makes the
        # invoice a long-lived payment request on the server.
        assert body["checkout"]["expirationMinutes"] == NINETY_DAYS_MIN, body
        assert body["amount"] == 1234 and body["currency"] == "sat", body
        # The modal lands the admin on the new invoice's payment page (the
        # response body is gone once the page navigates, so read the id from
        # the URL: /payment.php?id=... or /payment/...).
        page.wait_for_url("**/payment**", timeout=30000)
        current = page.url
        invoice_id = current.split("id=")[-1].split("&")[0] if "id=" in current else current.rstrip("/").split("/")[-1]
    finally:
        ctx.close()
    row = _row(cfg, invoice_id)
    assert row["long_lived"] == 1
    assert row["amount"] == "1234"
    assert abs(row["expiration_time"] - (int(time.time()) + NINETY_DAYS_MIN * 60)) < 300


def test_selfserve_invoice_is_long_lived(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    with cfg.handle.db() as db:
        db.execute("UPDATE stores SET selfserve_enabled = 1 WHERE id = ?", (cfg.store_id,))
    r = requests.post(
        f"{cfg.handle.url}/pay/{cfg.store_id}",
        data={"amount": "777", "currency": "sat", "notes": "long-lived self-serve"},
        timeout=30, allow_redirects=False,
    )
    assert r.status_code in (302, 303), r.text[:300]
    loc = r.headers["Location"]
    invoice_id = loc.split("id=")[-1] if "id=" in loc else loc.rstrip("/").split("/")[-1]
    row = _row(cfg, invoice_id)
    assert row["long_lived"] == 1
    assert abs(row["expiration_time"] - (int(time.time()) + NINETY_DAYS_MIN * 60)) < 300


def test_router_front_controller_api_form_works_under_builtin_server(shared_configured: ConfiguredPayserver) -> None:
    """The admin modal (and any 'router' URL-mode client) calls
    /router.php/api/v1/...; php -S has no PATH_INFO for that form, so router.php
    now strips the prefix itself. Both URL forms must answer identically."""
    cfg = shared_configured
    headers = {"Authorization": f"token {cfg.api_token}"}
    r = requests.post(
        f"{cfg.handle.url}/router.php/api/v1/stores/{cfg.store_id}/invoices",
        json={"amount": "1000", "currency": "sat"}, headers=headers, timeout=30,
    )
    assert r.status_code == 200, r.text[:300]
    inv = r.json()
    r2 = requests.get(f"{cfg.handle.url}/router.php/api/v1/stores/{cfg.store_id}/invoices/{inv['id']}", headers=headers, timeout=15)
    assert r2.status_code == 200 and r2.json()["id"] == inv["id"], r2.text[:300]
    r3 = requests.get(f"{cfg.handle.url}/router.php/api/v1/server/info", headers=headers, timeout=15)
    assert r3.status_code == 200, r3.text[:300]
    # The bare prefix still lands on the front page redirect, and the
    # sensitive-path block still applies through the prefix.
    r4 = requests.get(f"{cfg.handle.url}/router.php", timeout=15, allow_redirects=False)
    assert r4.status_code in (200, 302), r4.status_code
    r5 = requests.get(f"{cfg.handle.url}/router.php/data/cashupay.sqlite", timeout=15)
    assert r5.status_code == 403


def test_order_invoice_explicit_lifetime_bounds_a_longer_quote(shared_configured: ConfiguredPayserver) -> None:
    """An e-commerce invoice asking for 10 minutes gets 10 minutes even though
    the mint quote lasts a day: an explicit lifetime is an upper bound. (The
    historical rule — the quote's expiry wins over the DEFAULT window — is
    kept for invoices without the field; see test_api_without_field_is_unchanged.)"""
    now = int(time.time())
    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="1000", currency="sat",
        metadata={"orderId": "wc-10min"}, checkout={"expirationMinutes": 10},
    )
    assert inv["longLived"] is False
    assert abs(inv["expirationTime"] - (now + 600)) < 60, inv["expirationTime"]
    row = _row(shared_configured, inv["id"])
    assert row["ln_expires_at"] > row["expiration_time"], "the quote itself outlives the invoice window"


def test_static_address_store_keeps_onchain_amount_but_requotes_lightning(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    """A static shared address attributes payments by exact amount, so its
    amount is locked at creation; the Lightning side still re-quotes."""
    addr = onchain.bitcoind.rpc("getnewaddress", "", "bech32")
    configure_store_for_static_onchain(
        shared_configured.handle.db_path, shared_configured.store_id,
        static_address=addr, network="regtest", tweak_range=1000, min_confs=0,
        confirm_timeout_sec=86400, provider_url=onchain.watch_wallet_url,
    )
    _seed_usd_rate(shared_configured, 100_000.0)
    inv = _create_long_lived(shared_configured, amount="10.00", currency="USD")
    row = _row(shared_configured, inv["id"])
    assert row["onchain_address"] == addr
    locked = row["onchain_amount_sat"]
    assert 10_000 <= locked < 11_000, locked  # 10 000 + uniqueness tweak
    assert row["amount_sats"] == 10_000

    _seed_usd_rate(shared_configured, 200_000.0)
    _set(shared_configured, inv["id"], requoted_at=None)
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=30).text
    after = _row(shared_configured, inv["id"])
    assert after["onchain_amount_sat"] == locked, "static address amount locked"
    assert after["onchain_amount_floor_sat"] is None
    assert after["amount_sats"] == 5_000, "Lightning re-quoted"
    assert after["bolt11"] != row["bolt11"]
    assert f"{locked:,} sat" in html


def test_cart_checkout_from_admin_is_long_lived(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    pid = "prod_" + uuid.uuid4().hex[:12]
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO products (id, store_id, title, price, currency, image_type, image_value,"
            " purchase_count, enabled, created_at, updated_at) VALUES (?, ?, 'LL Cart Item', '600', 'sat', 'none', NULL, 0, 1, ?, ?)",
            (pid, cfg.store_id, now, now),
        )
    res = cfg.admin._post_action(
        "cart_checkout", store_id=cfg.store_id, items=json.dumps([{"product_id": pid, "quantity": 2}]), memo="cart ll",
    )
    assert res.get("success") is True and res.get("invoiceId"), res
    row = _row(cfg, res["invoiceId"])
    assert row["long_lived"] == 1
    assert row["amount"] == "1200"
    assert abs(row["expiration_time"] - (now + NINETY_DAYS_MIN * 60)) < 300


def test_selfserve_product_link_checkout_is_long_lived(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    pid = "prod_" + uuid.uuid4().hex[:12]
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute("UPDATE stores SET selfserve_enabled = 1 WHERE id = ?", (cfg.store_id,))
        db.execute(
            "INSERT INTO products (id, store_id, title, price, currency, image_type, image_value,"
            " purchase_count, enabled, created_at, updated_at) VALUES (?, ?, 'LL Link Item', '800', 'sat', 'none', NULL, 0, 1, ?, ?)",
            (pid, cfg.store_id, now, now),
        )
    r = requests.post(
        f"{cfg.handle.url}/pay/{cfg.store_id}?products={pid}",
        data={"tip_mode": "", "tip_percent": "", "tip_amount": "", "notes": ""},
        timeout=30, allow_redirects=False,
    )
    assert r.status_code in (301, 302, 303), r.text[:300]
    loc = r.headers["Location"]
    invoice_id = loc.split("id=")[-1].split("&")[0] if "id=" in loc else loc.rstrip("/").split("/")[-1]
    row = _row(cfg, invoice_id)
    assert row["long_lived"] == 1 and row["amount"] == "800"


def test_cron_skips_lapsed_long_lived_bolt11_and_closes_stale_parked_quotes(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    now = int(time.time())
    ll = _create_long_lived(cfg)
    ordinary = cfg.greenfield.create_invoice(cfg.store_id, amount="1000", currency="sat")
    _set(cfg, ll["id"], ln_expires_at=now - 7200, last_polled_at=None)
    _set(cfg, ordinary["id"], last_polled_at=None)
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO invoice_ln_quotes (id, invoice_id, store_id, payment_rail, bolt11, amount_sats, context,"
            " expires_at, superseded_at, state, created_at) VALUES ('lnq_e2e_old', ?, ?, 'mint', 'lnbcrt1old', 1000,"
            " '{}', ?, ?, 'open', ?)",
            (ll["id"], cfg.store_id, now - 7200, now - 7000, now - 7000),
        )
    summary = cfg.handle.trigger_cron_json()
    # Other tests on this shared server may have parked quotes of their own;
    # only the stale one here must have been closed by this pass.
    task = summary["tasks"]["poll_superseded_ln"]
    assert int(re.search(r"closed (\d+)", task).group(1)) >= 1, task
    assert _row(cfg, ll["id"])["last_polled_at"] is None, "lapsed long-lived bolt11 is not polled"
    assert _row(cfg, ordinary["id"])["last_polled_at"] is not None, "ordinary live invoice still polled"
    with cfg.handle.db() as db:
        state = db.execute("SELECT state FROM invoice_ln_quotes WHERE id = 'lnq_e2e_old'").fetchone()[0]
    assert state == "closed"
    # Nothing expired it: the invoice lives for the shared lifetime.
    assert cfg.greenfield.get_invoice(cfg.store_id, ll["id"])["status"] == "New"


def _set_store_mints(cfg: ConfiguredPayserver, url: str | None, backup_url: str | None) -> dict:
    """Point the store's primary + backup mints somewhere else; returns the previous values."""
    with cfg.handle.db() as db:
        prev_primary = db.execute("SELECT mint_url FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()[0]
        prev_backups = [r[0] for r in db.execute("SELECT mint_url FROM store_mints WHERE store_id = ? ORDER BY priority", (cfg.store_id,))]
        if url is not None:
            db.execute("UPDATE stores SET mint_url = ? WHERE id = ?", (url, cfg.store_id))
            db.execute("UPDATE store_mints SET mint_url = ? WHERE store_id = ?", (backup_url or url, cfg.store_id))
    return {"primary": prev_primary, "backups": prev_backups}


def test_refresh_failure_shows_retry_and_recovers(shared_configured: ConfiguredPayserver, browser) -> None:
    """Mint unreachable when the bolt11 lapses: the page says so and keeps
    retrying every 30 s; once the mint is back the next attempt succeeds."""
    cfg = shared_configured
    inv = _create_long_lived(cfg)
    first = _bolt11(inv)
    url = _payment_url(cfg, inv["id"])
    prev = _set_store_mints(cfg, "http://127.0.0.1:1", "http://127.0.0.1:1")
    ctx = browser.new_context()
    page = ctx.new_page()
    try:
        page.goto(url, wait_until="domcontentloaded")
        page.wait_for_selector("#ln-refreshed", timeout=10000)
        _set(cfg, inv["id"], ln_expires_at=int(time.time()) - 1)
        page.evaluate("lnExpiresAt = Math.floor(Date.now() / 1000) + 1")
        page.wait_for_function(
            "document.getElementById('timer').textContent.includes('retrying')", timeout=20000,
        )
        assert _row(cfg, inv["id"])["bolt11"] == first, "failed refresh keeps the current bolt11"
        assert _row(cfg, inv["id"])["status"] == "New"
        # Mint back: the retry (forced early instead of waiting 30 s) succeeds.
        with cfg.handle.db() as db:
            db.execute("UPDATE stores SET mint_url = ? WHERE id = ?", (prev["primary"], cfg.store_id))
            for i, u in enumerate(prev["backups"]):
                db.execute(
                    "UPDATE store_mints SET mint_url = ? WHERE store_id = ? AND rowid IN "
                    "(SELECT rowid FROM store_mints WHERE store_id = ? ORDER BY priority LIMIT 1 OFFSET ?)",
                    (u, cfg.store_id, cfg.store_id, i),
                )
        _set(cfg, inv["id"], ln_refresh_lock_at=None)
        page.evaluate("lnRefreshNextAt = 0")
        page.wait_for_function(
            "(first) => document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy !== first",
            arg=first, timeout=40000,
        )
        page.wait_for_function(
            "document.getElementById('timer').textContent.startsWith('Lightning invoice refreshes in')", timeout=10000,
        )
        assert _row(cfg, inv["id"])["bolt11"].lower().startswith("lnbcrt")
    finally:
        ctx.close()
        with cfg.handle.db() as db:
            db.execute("UPDATE stores SET mint_url = ? WHERE id = ?", (prev["primary"], cfg.store_id))


def test_other_tab_adopts_refreshed_bolt11(shared_configured: ConfiguredPayserver, browser) -> None:
    """A second tab (or a reload elsewhere) refreshed the bolt11: the open
    page learns it from its status poll and swaps QR + copy targets, flashing
    the refreshed label."""
    cfg = shared_configured
    inv = _create_long_lived(cfg)
    first = _bolt11(inv)
    url = _payment_url(cfg, inv["id"])
    ctx = browser.new_context()
    page = ctx.new_page()
    try:
        page.goto(url, wait_until="domcontentloaded")
        page.wait_for_selector("#ln-refreshed", timeout=10000)
        page.evaluate(
            """() => {
                window.__flashes = 0;
                new MutationObserver(() => {
                    if (document.getElementById('ln-refreshed').classList.contains('flash')) window.__flashes++;
                }).observe(document.getElementById('ln-refreshed'), { attributes: true, attributeFilter: ['class'] });
            }"""
        )
        _set(cfg, inv["id"], ln_expires_at=int(time.time()) - 1)
        r = requests.post(url, data={"action": "refresh_lightning"}, timeout=30)
        assert r.json()["ok"] is True, r.text
        second = r.json()["bolt11"]
        page.wait_for_function(
            "(second) => document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy === second",
            arg=second, timeout=15000,
        )
        page.wait_for_function("window.__flashes >= 1", timeout=5000)
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] a.btn').href") == "lightning:" + second.upper()
        assert page.evaluate("lnExpiresAt") == _row(cfg, inv["id"])["ln_expires_at"]
    finally:
        ctx.close()


def test_refresh_rewrites_cash_app_links(shared_configured: ConfiguredPayserver, browser) -> None:
    """The Cash App wallet tab's button and the brand-row logo open the app's
    universal link (https://cash.app/launch/lightning/<BOLT11>), not the
    lightning: scheme; a refreshed bolt11 must land in those too, while the
    tab's QR keeps the lightning: URI the scanner reads."""
    cfg = shared_configured
    base = "https://cash.app/launch/lightning/"
    with cfg.handle.db() as db:
        prev = db.execute("SELECT wallet_tabs FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()[0]
        db.execute("UPDATE stores SET wallet_tabs = 'cashapp' WHERE id = ?", (cfg.store_id,))
    ctx = browser.new_context()
    page = ctx.new_page()
    try:
        inv = _create_long_lived(cfg)
        first = _bolt11(inv)
        url = _payment_url(cfg, inv["id"])
        page.goto(url, wait_until="domcontentloaded")
        page.wait_for_selector("#ln-refreshed", timeout=10000)
        page.click('.method-tab[data-method="cashapp"]')
        page.wait_for_function("() => document.querySelector('#qr-cashapp canvas') !== null")
        btn = '[data-method-block="cashapp"] a.btn'
        assert page.get_attribute(btn, "href") == base + first.upper()
        assert page.get_attribute("#qr-cashapp", "data-qr-value") == "lightning:" + first.upper()
        # The Cash App tab keys the brand row off Lightning; with its own tab
        # shown the logo switches tabs rather than linking, so check the
        # data-href the row would hand a tab-less logo.
        assert page.get_attribute("#payment-methods", "data-href-cashapp") == base + first.upper()

        _set(cfg, inv["id"], ln_expires_at=int(time.time()) - 1)
        r = requests.post(url, data={"action": "refresh_lightning"}, timeout=30)
        assert r.json()["ok"] is True, r.text
        second = r.json()["bolt11"]
        assert second != first
        page.wait_for_function(
            "(second) => document.querySelector('[data-method-block=\"cashapp\"] .invoice-input').dataset.copy === second",
            arg=second, timeout=15000,
        )
        assert page.get_attribute(btn, "href") == base + second.upper()
        assert page.get_attribute("#qr-cashapp", "data-qr-value") == "lightning:" + second.upper()
        assert page.get_attribute("#payment-methods", "data-href-cashapp") == base + second.upper()
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] a.btn').href") == "lightning:" + second.upper()
        assert base + first.upper() not in page.content()
    finally:
        ctx.close()
        with cfg.handle.db() as db:
            db.execute("UPDATE stores SET wallet_tabs = ? WHERE id = ?", (prev, cfg.store_id))
