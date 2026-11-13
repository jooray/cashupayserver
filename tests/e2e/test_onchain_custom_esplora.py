"""Custom Esplora provider URL: validation, opt-in public fallback, privacy.

The payserver under test has its built-in "public" explorer chain pointed at
a mock (CASHUPAY_ESPLORA_DEFAULT_URLS, honoured only because the rig allows
private endpoints), so every statement about "the public explorers" below is
proven by that mock's hit log — never by the real mempool.space.

Covers:
  - save_onchain refuses malformed URLs (credentials, scheme, query) and
    writes nothing;
  - save_onchain refuses a URL whose GET /blocks/tip/height fails (HTTP
    error, connection refused) or answers with a web page, suggesting the
    `/api` base when that one works;
  - a reachable URL is verified (tip height echoed back), persisted together
    with the fallback flag, and NOT re-probed on later saves that leave the
    URL unchanged; an empty URL always clears the flag;
  - with the fallback OFF, an outage of the custom explorer never produces a
    single request to the public chain, and detection resumes as soon as the
    custom explorer is back (single-host chain ignores its own cooldown);
  - with the fallback ON, the public chain is only contacted during the
    outage, and a payment it reports settles the invoice;
  - the admin card's checkbox is disabled without a URL, round-trips through
    save + reload, and the inline box shows the verification / the refusal.
"""
from __future__ import annotations

import json
import time
from typing import Iterator

import pytest
import requests

from conftest import (
    DEFAULT_ADMIN_PASSWORD,
    ConfiguredPayserver,
    _add_test_store,
    _start_shared_server,
)
from fixtures import ports
from fixtures.browser import expand_settings_cards, wait_for_store_settings_loaded
from fixtures.esplora_mock import MockEsplora
from fixtures.onchain import TEST_TPUB

INVOICE_AMOUNT_SAT = 42_000
TIP = 900_000


# ---------------------------------------------------------------------------
# fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def public_esplora() -> Iterator[MockEsplora]:
    """Stands in for mempool.space / blockstream.info for the whole module."""
    s = MockEsplora(tip=TIP)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def esplora_server(
    request, mint, backup_mint, public_esplora: MockEsplora
) -> Iterator[ConfiguredPayserver]:
    """Shared payserver whose built-in public explorer chain is the mock."""
    yield from _start_shared_server(
        request, mint, backup_mint,
        extra_env={"CASHUPAY_ESPLORA_DEFAULT_URLS": public_esplora.url},
    )


@pytest.fixture
def cfg(esplora_server: ConfiguredPayserver, mint, backup_mint, request) -> ConfiguredPayserver:
    return _add_test_store(esplora_server, mint, backup_mint, request)


@pytest.fixture
def custom_esplora() -> Iterator[MockEsplora]:
    """The operator's own explorer — one per test so cooldown state and hit
    logs never leak between tests."""
    s = MockEsplora(tip=TIP)
    yield s
    s.stop()


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------


def _save(cfg: ConfiguredPayserver, *, provider_url: str, fallback: str | None = None, **over) -> requests.Response:
    data = {
        "action": "save_onchain",
        "store_id": cfg.store_id,
        "mode": "xpub",
        "xpub": TEST_TPUB,
        "network": "regtest",
        "address_type": "P2WPKH",
        "min_confs": "1",
        "confirm_timeout_sec": "86400",
        "provider_url": provider_url,
    }
    if fallback is not None:
        data["provider_fallback"] = fallback
    data.update(over)
    return cfg.admin.s.post(
        f"{cfg.handle.url}/admin", data=data,
        headers={"X-CSRF-Token": cfg.admin.csrf_token}, timeout=30,
    )


def _store_row(cfg: ConfiguredPayserver) -> dict:
    with cfg.handle.db() as db:
        row = db.execute(
            "SELECT onchain_provider, onchain_provider_url, onchain_provider_fallback, "
            "onchain_min_confs, onchain_xpub FROM stores WHERE id = ?",
            (cfg.store_id,),
        ).fetchone()
    assert row is not None
    return dict(row)


def _dashboard_onchain(cfg: ConfiguredPayserver) -> dict:
    r = cfg.admin.s.get(
        cfg.admin._admin_url, params={"api": "dashboard", "store_id": cfg.store_id}, timeout=30
    )
    r.raise_for_status()
    return r.json()["onchain"]


def _create_onchain_invoice(cfg: ConfiguredPayserver) -> tuple[str, str]:
    inv = cfg.greenfield.create_invoice(cfg.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat")
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    assert addr.startswith("bcrt1"), addr
    return inv["id"], addr


def _poll_until(cfg: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        cfg.handle.trigger_cron()
        last = cfg.greenfield.get_invoice(cfg.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status}; last={last}")


# ---------------------------------------------------------------------------
# admin save: validation
# ---------------------------------------------------------------------------


def test_save_rejects_malformed_urls_and_writes_nothing(
    cfg: ConfiguredPayserver, public_esplora: MockEsplora
) -> None:
    before = _store_row(cfg)
    assert before["onchain_provider_url"] is None
    cases = [
        ("http://user:pw@127.0.0.1:18443/wallet/cashupay", "username or password"),
        ("http://user:pw@127.0.0.1:18443/wallet/cashupay", "Bitcoin Core"),
        ("mempool.space/api", "http://"),
        ("ftp://explorer.test/api", "http://"),
        ("https://mempool.space/api?x=1", "query"),
        ("https://mem pool.space/api", "spaces"),
    ]
    for url, needle in cases:
        r = _save(cfg, provider_url=url, fallback="1", min_confs="7")
        assert r.status_code == 400, (url, r.text)
        err = r.json().get("error") or ""
        assert needle.lower() in err.lower(), (url, err)
    after = _store_row(cfg)
    assert after == before, "a refused save must not touch the row"
    assert after["onchain_min_confs"] != 7
    assert public_esplora.hit_count() == 0, "format refusals never contact any explorer"


def test_save_rejects_unreachable_or_non_api_url(
    cfg: ConfiguredPayserver, custom_esplora: MockEsplora, public_esplora: MockEsplora
) -> None:
    # HTTP error from the operator's explorer.
    custom_esplora.mode = "down"
    r = _save(cfg, provider_url=custom_esplora.url, min_confs="7")
    assert r.status_code == 400, r.text
    err = r.json()["error"]
    assert "HTTP 500" in err, err
    assert "Nothing was saved" in err, err
    assert custom_esplora.hit_count("/blocks/tip/height") >= 1
    custom_esplora.mode = "ok"

    # Nothing listening at all.
    dead_port = ports.allocate(1)[0]
    r = _save(cfg, provider_url=f"http://127.0.0.1:{dead_port}/api", min_confs="7")
    assert r.status_code == 400, r.text
    assert "could not be reached" in r.json()["error"], r.text

    # The explorer's web root instead of its API base: HTML answers, but
    # {url}/api works -> the message suggests it (and does not auto-correct).
    webroot = MockEsplora(tip=TIP, api_prefix="/api")
    try:
        r = _save(cfg, provider_url=webroot.url, min_confs="7")
        assert r.status_code == 400, r.text
        err = r.json()["error"]
        assert "did not return a block height" in err, err
        assert f"Did you mean {webroot.url}/api" in err, err
        assert _store_row(cfg)["onchain_provider_url"] is None, "suggestion is not applied silently"

        # ...and the suggested URL saves.
        r = _save(cfg, provider_url=webroot.api_url)
        assert r.status_code == 200, r.text
        assert r.json()["providerProbe"]["tipHeight"] == TIP
        assert _store_row(cfg)["onchain_provider_url"] == webroot.api_url
    finally:
        webroot.stop()

    row = _store_row(cfg)
    assert row["onchain_min_confs"] != 7, "refused saves changed nothing"
    assert public_esplora.hit_count() == 0, "probe failures never fall through to the public chain"


def test_save_verifies_persists_and_reprobes_only_on_change(
    cfg: ConfiguredPayserver, custom_esplora: MockEsplora, public_esplora: MockEsplora
) -> None:
    # 1. New URL (with a trailing slash) + fallback on: probed, normalised, persisted.
    r = _save(cfg, provider_url=custom_esplora.url + "/", fallback="1")
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert body["providerProbe"] == {"url": custom_esplora.url, "tipHeight": TIP}, body
    assert custom_esplora.hit_count("GET /blocks/tip/height") == 1
    row = _store_row(cfg)
    assert row["onchain_provider"] == "esplora"
    assert row["onchain_provider_url"] == custom_esplora.url
    assert row["onchain_provider_fallback"] == 1
    dash = _dashboard_onchain(cfg)
    assert dash["providerUrl"] == custom_esplora.url
    assert dash["providerFallback"] is True

    # 2. Same URL, flag off: no re-probe, flag persisted.
    r = _save(cfg, provider_url=custom_esplora.url, fallback="0")
    assert r.status_code == 200, r.text
    assert r.json()["providerProbe"] is None, "unchanged URL is not re-probed"
    assert custom_esplora.hit_count("GET /blocks/tip/height") == 1
    assert _store_row(cfg)["onchain_provider_fallback"] == 0
    assert _dashboard_onchain(cfg)["providerFallback"] is False

    # 3. Unchanged URL while the explorer is down: the confirmation policy
    #    can still be edited (no probe), which is the point of probing only
    #    on change.
    custom_esplora.mode = "down"
    r = _save(cfg, provider_url=custom_esplora.url, fallback="1", min_confs="3")
    assert r.status_code == 200, r.text
    assert _store_row(cfg)["onchain_min_confs"] == 3
    assert _store_row(cfg)["onchain_provider_fallback"] == 1
    custom_esplora.mode = "ok"

    # 4. Empty URL: cleared, and the flag is meaningless without a URL.
    r = _save(cfg, provider_url="", fallback="1")
    assert r.status_code == 200, r.text
    row = _store_row(cfg)
    assert row["onchain_provider_url"] is None
    assert row["onchain_provider_fallback"] == 0
    assert _dashboard_onchain(cfg)["providerFallback"] is False

    # 5. Re-entering the URL counts as a change -> probed again; a down
    #    explorer is refused.
    custom_esplora.mode = "down"
    r = _save(cfg, provider_url=custom_esplora.url)
    assert r.status_code == 400, r.text
    assert _store_row(cfg)["onchain_provider_url"] is None
    custom_esplora.mode = "ok"
    assert public_esplora.hit_count() == 0


# ---------------------------------------------------------------------------
# chain watcher: outage behaviour
# ---------------------------------------------------------------------------


def test_outage_without_fallback_never_contacts_public_explorers(
    cfg: ConfiguredPayserver, custom_esplora: MockEsplora, public_esplora: MockEsplora
) -> None:
    r = _save(cfg, provider_url=custom_esplora.url, fallback="0")
    assert r.status_code == 200, r.text
    public_esplora.reset_hits()
    custom_esplora.reset_hits()

    invoice_id, addr = _create_onchain_invoice(cfg)
    assert custom_esplora.hit_count() >= 1, "allocation reads the tip from the custom explorer"

    # Outage: polls fail against the custom host and go nowhere else.
    custom_esplora.mode = "down"
    custom_esplora.reset_hits()
    for _ in range(3):
        cfg.handle.trigger_cron()
        inv = cfg.greenfield.get_invoice(cfg.store_id, invoice_id)
        assert inv["status"] == "New", inv
        time.sleep(0.3)
    assert custom_esplora.hit_count() >= 1, "the custom explorer keeps being tried"
    assert public_esplora.hit_count() == 0, "fallback off: the public chain is never contacted"

    # Recovery: the payment shows up on the custom explorer and settles —
    # immediately, although the host just earned an EndpointHealth cooldown
    # (a single-host chain is still tried while everything is cooling down).
    custom_esplora.mode = "ok"
    custom_esplora.pay(addr, INVOICE_AMOUNT_SAT, txid="a" * 64, height=TIP)
    settled = _poll_until(cfg, invoice_id, "Settled", timeout_s=30)
    assert settled["status"] == "Settled"
    assert public_esplora.hit_count() == 0, "still nothing reached the public chain"


def test_outage_with_fallback_uses_public_chain_only_during_outage(
    cfg: ConfiguredPayserver, custom_esplora: MockEsplora, public_esplora: MockEsplora
) -> None:
    r = _save(cfg, provider_url=custom_esplora.url, fallback="1")
    assert r.status_code == 200, r.text
    public_esplora.reset_hits()

    invoice_id, addr = _create_onchain_invoice(cfg)
    cfg.handle.trigger_cron()
    assert cfg.greenfield.get_invoice(cfg.store_id, invoice_id)["status"] == "New"
    assert public_esplora.hit_count() == 0, "healthy custom explorer: public chain untouched even with fallback on"

    # Outage: the payment is only visible to the public chain.
    custom_esplora.mode = "down"
    public_esplora.pay(addr, INVOICE_AMOUNT_SAT, txid="b" * 64, height=TIP)
    settled = _poll_until(cfg, invoice_id, "Settled", timeout_s=30)
    assert settled["status"] == "Settled"
    assert public_esplora.hit_count("/address/") >= 1, "the public chain answered the address query"
    assert public_esplora.hit_count("/blocks/tip/height") >= 1


# ---------------------------------------------------------------------------
# admin UI (Playwright)
# ---------------------------------------------------------------------------


@pytest.fixture
def admin_page(cfg: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.add_init_script(
        "window.localStorage.setItem('selectedStoreId', " f"{json.dumps(cfg.store_id)});"
    )
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, cfg.handle.url
    ctx.close()


def _open_onchain_advanced(page, base: str, store_id: str) -> None:
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    wait_for_store_settings_loaded(page, store_id)
    page.wait_for_function(
        "() => typeof dashboardLoadSeq === 'number' && dashboardRenderedSeq === dashboardLoadSeq"
    )
    page.evaluate("() => document.getElementById('onchain-advanced').classList.remove('collapsed')")
    page.wait_for_selector("#onchain-provider-url", state="visible")


@pytest.mark.ui
def test_admin_card_fallback_checkbox_roundtrip(
    cfg: ConfiguredPayserver, custom_esplora: MockEsplora, admin_page
) -> None:
    page, base = admin_page
    _open_onchain_advanced(page, base, cfg.store_id)

    # Without a URL the opt-in is disabled; typing one enables it.
    assert page.input_value("#onchain-provider-url") == ""
    assert page.is_disabled("#onchain-provider-fallback")
    page.fill("#onchain-provider-url", "http://user:pw@127.0.0.1:1/wallet/x")
    assert not page.is_disabled("#onchain-provider-fallback")

    # A refused URL shows the server's reason inline; nothing is saved.
    page.fill("#onchain-xpub", TEST_TPUB)
    page.select_option("#onchain-network", "regtest")
    page.click("#btn-save-onchain")
    page.wait_for_function(
        "() => document.getElementById('onchain-validation-box').innerText.includes('Save failed')"
    )
    box = page.inner_text("#onchain-validation-box")
    assert "username or password" in box, box
    assert _store_row(cfg)["onchain_provider_url"] is None

    # A reachable URL + the opt-in: saved, and the inline box reports the
    # verification with the explorer's block height.
    page.fill("#onchain-provider-url", custom_esplora.url)
    page.check("#onchain-provider-fallback")
    page.click("#btn-save-onchain")
    page.wait_for_function(
        "() => document.getElementById('onchain-validation-box').innerText.includes('verified')"
    )
    box = page.inner_text("#onchain-validation-box")
    assert "Saved" in box and f"{TIP:,}" in box, box
    row = _store_row(cfg)
    assert row["onchain_provider_url"] == custom_esplora.url
    assert row["onchain_provider_fallback"] == 1

    # Reload: both the URL and the opt-in come back from the server.
    _open_onchain_advanced(page, base, cfg.store_id)
    assert page.input_value("#onchain-provider-url") == custom_esplora.url
    assert page.is_checked("#onchain-provider-fallback")
    assert not page.is_disabled("#onchain-provider-fallback")

    # Clearing the URL disables (and visually dims) the opt-in again.
    page.fill("#onchain-provider-url", "")
    assert page.is_disabled("#onchain-provider-fallback")
