"""End-to-end coverage for offline Cashu acceptance (NUT-12).

Two angles:
  1. HTTP receive flow against a DEAD mint, using the official NUT-12 "Carol"
     DLEQ vector as a real verifiable token. Exercises receive.php's offline
     fallback, the Provisional cashu invoice, replay rejection, the disabled
     gate, and the reconcile-stays-provisional-while-offline path — fully
     deterministic (no live mint needed for this branch).
  2. The admin settings UI round-trip (enable + caps + save persist) driven in
     a real logged-in browser through the actual endpoints (CSRF included).
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

# Shared-server note: the offline-token tests below deliberately stay on the
# function-scoped `configured` fixture. They all present the SAME NUT-12 vector
# token and insert the SAME fixed (wallet_id, keyset_id) row into
# cashu_keyset_keys — and both cashu_offline_locks (y TEXT PRIMARY KEY) and
# cashu_keyset_keys (PRIMARY KEY(wallet_id, keyset_id)) are server-global
# tables, so on one shared instance the first accepting test's replay lock and
# cached-keys row would poison every later test. Only the two store-scoped
# tests at the bottom use the shared server.

# Dead mint — nothing listens on 127.0.0.1:1, so the server's swap attempt fails
# fast with a network error, triggering the offline path.
DEAD_MINT = "http://127.0.0.1:1"
WALLET_ID = "1a956f7d4e5771d6"  # substr(sha256(DEAD_MINT:sat), 0, 16)
KEYSET = "00882760bfa2eb41"
MINT_KEY_A = "0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798"
SEED = "about about about about about about about about about about about above"

# cashuB token carrying the NUT-12 Carol vector proof (amount 1, with DLEQ),
# serialized from mint http://127.0.0.1:1. Generated with the bundled PHP lib.
VECTOR_TOKEN = (
    "cashuBo2Ftcmh0dHA6Ly8xMjcuMC4wLjE6MWF1Y3NhdGF0gaJhaUgAiCdgv6LrQWFwgaRhYQFhc3hAZGFm"
    "NGRkMDBhMmI2OGEwODU4YTgwNDUwZjUyYzhhN2QyY2NmODdkMzc1ZTQzZTIxNmUwYzU3MWYwODlmNjNlOWFj"
    "WCECQ2nS0iqA7PePOTfanV8wwbn3TwwyaE1YPMoPpqYc3PxhZKNhZVggsx5YrGUn80l1_6sT5wpIttKw01q8"
    "SwPwFR8J7hqXY9Rhc1ggj7rgBMWedU1x32fjkrauTikpMRPdwuyGWSoEMdFjBthhclggptE_zXoYRC5gdvXh"
    "58iHrV3kCgGYJL36n-dA0wLo2GE"
)


def _make_offline_store(handle, store_id: str, *, enabled: bool, allow_mint: bool, cache_keys: bool,
                        accept_all: bool = False, per_tx_override: bool = False) -> None:
    """Provision an isolated store wired to the dead mint, optionally with the
    keyset key cached and the mint on the offline allowlist."""
    now = int(__import__("time").time())
    with handle.db() as conn:
        conn.execute(
            "INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, default_currency,"
            " primary_mint_source, offline_cashu_enabled, offline_cashu_policy,"
            " offline_cashu_accept_all_mints, offline_cashu_per_tx_override, created_at)"
            " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            (store_id, "offline-e2e", DEAD_MINT, "sat", SEED, "sat", "manual",
             1 if enabled else 0, "dleq", 1 if accept_all else 0,
             1 if per_tx_override else 0, now),
        )
        if cache_keys:
            import json
            conn.execute(
                "INSERT INTO cashu_keyset_keys (wallet_id, keyset_id, unit, keys_json, input_fee_ppk, updated_at)"
                " VALUES (?,?,?,?,?,?)",
                (WALLET_ID, KEYSET, "sat", json.dumps({"1": MINT_KEY_A}), 0, now),
            )
        if allow_mint:
            conn.execute(
                "INSERT INTO store_offline_mints (store_id, mint_url, enabled, created_at) VALUES (?,?,1,?)",
                (store_id, DEAD_MINT, now),
            )


def _post_token(base: str, store_id: str, token: str) -> requests.Response:
    return requests.post(
        f"{base}/receive.php",
        json={"store_id": store_id, "token": token},
        timeout=15,
    )


def _invoice_row(handle, store_id: str) -> dict | None:
    with handle.db() as conn:
        row = conn.execute(
            "SELECT * FROM invoices WHERE store_id = ? ORDER BY created_at DESC LIMIT 1",
            (store_id,),
        ).fetchone()
    return dict(row) if row else None


def test_offline_accept_provisional_and_reconcile(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    base = handle.url
    store_id = "store_offline_ok"
    _make_offline_store(handle, store_id, enabled=True, allow_mint=True, cache_keys=True)

    # Present the token while the mint is unreachable -> provisional acceptance.
    resp = _post_token(base, store_id, VECTOR_TOKEN)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["success"] is True
    assert body["settlement"] == "offline_provisional", body
    assert body["amount"] == 1
    assert "warning" in body and "provisional" in body["warning"].lower()

    inv = _invoice_row(handle, store_id)
    assert inv is not None
    assert inv["status"] == "Provisional"
    assert inv["payment_rail"] == "cashu"
    assert int(inv["amount_sats"]) == 1
    assert inv["cashu_offline_token"] == VECTOR_TOKEN

    # A lock row guards against replay.
    with handle.db() as conn:
        locks = conn.execute(
            "SELECT COUNT(*) c FROM cashu_offline_locks WHERE store_id = ?", (store_id,)
        ).fetchone()["c"]
    assert locks == 1

    # Re-presenting the same token is rejected as a replay.
    replay = _post_token(base, store_id, VECTOR_TOKEN)
    assert replay.status_code == 400
    assert "replay" in replay.json()["error"].lower()

    # Reconcile while the mint is still dead -> stays Provisional.
    handle.trigger_cron()
    inv2 = _invoice_row(handle, store_id)
    assert inv2["status"] == "Provisional"


def test_offline_disabled_returns_unavailable(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    store_id = "store_offline_disabled"
    _make_offline_store(handle, store_id, enabled=False, allow_mint=True, cache_keys=True)

    resp = _post_token(handle.url, store_id, VECTOR_TOKEN)
    # Mint unreachable + offline disabled -> 503, no invoice recorded.
    assert resp.status_code == 503, resp.text
    assert _invoice_row(handle, store_id) is None


def test_offline_mint_not_on_allowlist_rejected(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    store_id = "store_offline_notallowed"
    # Enabled + keys cached, but the mint is NOT on the allowlist.
    _make_offline_store(handle, store_id, enabled=True, allow_mint=False, cache_keys=True)

    resp = _post_token(handle.url, store_id, VECTOR_TOKEN)
    assert resp.status_code == 400
    assert "allowlist" in resp.json()["error"].lower()


def test_offline_accept_all_mints_bypasses_allowlist(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    store_id = "store_offline_acceptall"
    # Enabled + keys cached, mint NOT on the allowlist, but accept-all is ON.
    _make_offline_store(handle, store_id, enabled=True, allow_mint=False, cache_keys=True, accept_all=True)

    resp = _post_token(handle.url, store_id, VECTOR_TOKEN)
    assert resp.status_code == 200, resp.text
    assert resp.json()["settlement"] == "offline_provisional", resp.text
    inv = _invoice_row(handle, store_id)
    assert inv is not None and inv["status"] == "Provisional"


def _insert_cashu_invoice(handle, store_id: str, invoice_id: str, amount_sats: int,
                          *, allow_any_mint: bool = False) -> None:
    now = int(__import__("time").time())
    with handle.db() as conn:
        conn.execute(
            "INSERT INTO invoices (id, store_id, status, amount, currency, amount_sats,"
            " payment_rail, mint_url, cashu_offline_allow_any_mint, created_at, expiration_time)"
            " VALUES (?,?, 'New', ?, 'sat', ?, 'cashu', ?, ?, ?, ?)",
            (invoice_id, store_id, str(amount_sats), amount_sats, DEAD_MINT,
             1 if allow_any_mint else 0, now, now + 3600),
        )


def test_offline_per_tx_override_allows_any_mint(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    base = handle.url
    # Mint NOT on allowlist, accept-all OFF, but per-tx override ON and the
    # invoice opts in -> the off-allowlist token is accepted.
    store_id = "store_offline_pertx"
    _make_offline_store(handle, store_id, enabled=True, allow_mint=False, cache_keys=True,
                        accept_all=False, per_tx_override=True)
    inv_id = "inv_pertx_ok"
    _insert_cashu_invoice(handle, store_id, inv_id, 1, allow_any_mint=True)

    resp = requests.post(f"{base}/receive.php",
                         json={"store_id": store_id, "id": inv_id, "token": VECTOR_TOKEN}, timeout=15)
    assert resp.status_code == 200, resp.text
    assert resp.json()["settlement"] == "offline_provisional", resp.text
    with handle.db() as conn:
        row = conn.execute("SELECT status FROM invoices WHERE id = ?", (inv_id,)).fetchone()
    assert row["status"] == "Provisional"


def test_offline_per_tx_override_disabled_still_rejects(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    # Invoice opts in, but the STORE has not enabled per-tx override -> rejected.
    store_id = "store_offline_pertx_off"
    _make_offline_store(handle, store_id, enabled=True, allow_mint=False, cache_keys=True,
                        accept_all=False, per_tx_override=False)
    inv_id = "inv_pertx_off"
    _insert_cashu_invoice(handle, store_id, inv_id, 1, allow_any_mint=True)

    resp = requests.post(f"{handle.url}/receive.php",
                         json={"store_id": store_id, "id": inv_id, "token": VECTOR_TOKEN}, timeout=15)
    assert resp.status_code == 400
    assert "allowlist" in resp.json()["error"].lower()


def test_offline_token_underpays_invoice_rejected(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    store_id = "store_offline_underpay"
    _make_offline_store(handle, store_id, enabled=True, allow_mint=True, cache_keys=True)
    inv_id = "inv_underpay"
    # Invoice wants 5 sats; the vector token is only worth 1.
    _insert_cashu_invoice(handle, store_id, inv_id, 5)

    resp = requests.post(f"{handle.url}/receive.php",
                         json={"store_id": store_id, "id": inv_id, "token": VECTOR_TOKEN}, timeout=15)
    assert resp.status_code == 400
    assert "less than the invoice amount" in resp.json()["error"].lower()


def test_checkout_shows_cashu_method(shared_configured: ConfiguredPayserver) -> None:
    """A New cashu invoice's checkout page offers the Cashu pay option (the QR
    request + token paste box) so it can be paid by presenting a token.

    The Cashu rail is gated on the store opting into offline acceptance (see
    payment.php, commit "gate Cashu on offline"), so enable it on the live-mint
    store before rendering the checkout."""
    handle = shared_configured.handle
    store_id = shared_configured.store_id  # live-mint per-test store from the fixture
    with handle.db() as conn:
        conn.execute(
            "UPDATE stores SET offline_cashu_enabled = 1, offline_cashu_policy = 'dleq'"
            " WHERE id = ?",
            (store_id,),
        )
    inv_id = "inv_checkout_cashu"
    _insert_cashu_invoice(handle, store_id, inv_id, 1)

    html = requests.get(f"{handle.url}/payment.php?id={inv_id}", timeout=15).text
    assert 'data-method-block="cashu"' in html
    assert "cashu-token-input" in html
    assert "creq" in html  # the serialized NUT-18 request string


def test_offline_settings_ui_roundtrip(shared_configured: ConfiguredPayserver, browser) -> None:
    """In a real logged-in admin browser, exercise the offline-cashu settings
    and allowlist endpoints (auth + CSRF go through the live admin JS) and
    confirm the settings card shipped in the store-settings UI."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{shared_configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": shared_configured.admin_password},
    )
    page = ctx.new_page()
    base = shared_configured.handle.url
    sid = shared_configured.store_id
    try:
        page.goto(f"{base}/admin/stores", wait_until="networkidle")
        expand_settings_cards(page)
        page.wait_for_timeout(800)

        # The offline-cashu settings card is part of the store-settings template.
        assert "offline-cashu-body" in page.content()

        # Save settings via the real admin endpoint (CSRF-protected, admin-only).
        saved = page.evaluate(
            """async (sid) => {
                const r = await postWithCsrf(adminUrl,
                    'action=save_offline_cashu&store_id=' + encodeURIComponent(sid) +
                    '&enabled=1&accept_all_mints=1&per_tx_override=1&max_per_tx=5000&max_outstanding=20000');
                return { ok: r.ok, body: await r.json() };
            }""",
            sid,
        )
        assert saved["ok"] and saved["body"].get("success"), saved

        data = page.evaluate(
            """async (sid) => (await fetch(adminUrl + '?api=get_offline_cashu&store_id=' +
                encodeURIComponent(sid), {credentials:'same-origin'})).json()""",
            sid,
        )
        assert data["enabled"] is True, data
        assert data["accept_all_mints"] is True, data
        assert data["per_tx_override"] is True, data
        assert int(data["max_per_tx"]) == 5000, data
        assert int(data["max_outstanding"]) == 20000, data

        # Allowlist add + remove via the real endpoints.
        page.evaluate(
            """async (sid) => { await postWithCsrf(adminUrl,
                'action=add_offline_mint&store_id=' + encodeURIComponent(sid) +
                '&mint_url=' + encodeURIComponent('https://mint.example.com')); }""",
            sid,
        )
        after_add = page.evaluate(
            """async (sid) => (await fetch(adminUrl + '?api=get_offline_cashu&store_id=' +
                encodeURIComponent(sid), {credentials:'same-origin'})).json()""",
            sid,
        )
        assert any(m["mint_url"] == "https://mint.example.com" for m in after_add["mints"]), after_add

        page.evaluate(
            """async (sid) => { await postWithCsrf(adminUrl,
                'action=remove_offline_mint&store_id=' + encodeURIComponent(sid) +
                '&mint_url=' + encodeURIComponent('https://mint.example.com')); }""",
            sid,
        )
        after_rm = page.evaluate(
            """async (sid) => (await fetch(adminUrl + '?api=get_offline_cashu&store_id=' +
                encodeURIComponent(sid), {credentials:'same-origin'})).json()""",
            sid,
        )
        assert all(m["mint_url"] != "https://mint.example.com" for m in after_rm["mints"]), after_rm
    finally:
        ctx.close()
