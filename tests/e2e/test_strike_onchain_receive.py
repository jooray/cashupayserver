"""E2E: the Strike on-chain receive option against the mock Strike API.

The payserver runs with CASHUPAY_STRIKE_API_BASE pointing at
fixtures/strike_api.py, so the real StrikeClient receive-request path runs —
bearer auth, BTC-denominated onchain amount, address returned — without
touching strike.me. Settlement is CHAIN-WATCHED (no Strike read-back): the
store's Esplora provider is pointed at a tiny in-test mock that reports the
paying transaction, and cron's on-chain poll settles the invoice.

    save_lightning_payments with strike[]=<key> + strike_onchain=1
      -> the enable probe issues one real receive request through the mock
      -> invoice creation mints the on-chain address via a Strike receive
         request (fresh valid mainnet address per invoice), recorded as
         invoices.strike_receive_request_id
      -> "payment" is simulated by the mock Esplora reporting a confirmed
         UTXO on that address; cron settles with settled_rail='onchain'.

A key without the receive-request scope (mocked as a 403) is refused at
enable time and the flag stays off.

PHP-side counterparts: tests/php/test_invoice_strike_onchain.php,
tests/php/test_strike_onchain_gate.php and
tests/php/test_onchain_dual_rail_cron_poll.php (the cron-throttle fix).
"""
from __future__ import annotations

import json
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures import ports
from fixtures.api_client import AdminClient
from fixtures.strike_api import TEST_STRIKE_KEY, StrikeApiServer

INVOICE_AMOUNT_SAT = 21_000
TIP_HEIGHT = 850_000


class MockEsplora:
    """Minimal Esplora API: a settable tip and per-address tx lists."""

    def __init__(self) -> None:
        self.tip = TIP_HEIGHT
        # address -> list of esplora tx dicts
        self.txs: dict[str, list[dict]] = {}
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):  # noqa: N802
                parts = self.path.strip("/").split("/")
                if self.path == "/blocks/tip/height":
                    body = str(outer.tip).encode()
                elif len(parts) >= 3 and parts[0] == "address" and parts[2] == "txs":
                    # /address/{addr}/txs and /address/{addr}/txs/chain/{txid}
                    # (the chain page is always empty — one page of history).
                    txs = outer.txs.get(parts[1], []) if len(parts) == 3 else []
                    body = json.dumps(txs).encode()
                else:
                    self.send_response(404)
                    self.end_headers()
                    return
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args, **kwargs):
                pass

        self.port = ports.allocate(1)[0]
        self.server = HTTPServer(("127.0.0.1", self.port), Handler)
        self.thread = threading.Thread(
            target=self.server.serve_forever, daemon=True, name=f"mock-esplora-{self.port}"
        )
        self.thread.start()

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def pay(self, address: str, amount_sat: int, txid: str, height: int) -> None:
        self.txs.setdefault(address, []).append({
            "txid": txid,
            "status": {"confirmed": True, "block_height": height},
            "vout": [{"scriptpubkey_address": address, "value": amount_sat}],
        })

    def stop(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)


@pytest.fixture(scope="module")
def mock_esplora() -> Iterator[MockEsplora]:
    s = MockEsplora()
    yield s
    s.stop()


def _save_strike(admin: AdminClient, store_id: str, key: str, onchain: str) -> requests.Response:
    return admin.s.post(
        admin._admin_url,
        data=[
            ("action", "save_lightning_payments"),
            ("store_id", store_id),
            ("strike[]", key),
            ("strike_onchain", onchain),
        ],
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )


def _invoice_row(configured: ConfiguredPayserver, invoice_id: str) -> dict:
    with configured.handle.db() as db:
        row = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row is not None, f"invoice {invoice_id} not found"
    return dict(row)


def test_strike_onchain_enable_checkout_and_chain_settle(
    shared_configured_with_strike: ConfiguredPayserver,
    strike_api_shared: StrikeApiServer,
    mock_esplora: MockEsplora,
) -> None:
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle

    # 1. Enable: save the key with the on-chain checkbox ticked. The enable
    # probe issues exactly one receive request through the mock (the LN probe
    # separately issues its 1-sat invoice).
    rr_before = set(strike_api.receive_requests)
    r = _save_strike(admin, store_id, TEST_STRIKE_KEY, "1")
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body
    assert body.get("strikeOnchainEnabled") is True, body
    assert TEST_STRIKE_KEY not in str(body), "save response must never echo the key"
    probe_rrs = [v for k, v in strike_api.receive_requests.items() if k not in rr_before]
    assert len(probe_rrs) == 1, "the enable probe issued exactly one receive request"
    assert probe_rrs[0]["body"]["onchain"]["amount"] == {
        "amount": "0.00000001", "currency": "BTC",
    }, probe_rrs

    # The dashboard payload surfaces the flag for both settings cards.
    dash = admin.s.get(
        admin._admin_url, params={"api": "dashboard", "store_id": store_id}, timeout=30
    ).json()
    assert dash["onchain"]["strikeOnchainEnabled"] is True, dash["onchain"]
    assert dash["onchain"]["strikeKeyConfigured"] is True, dash["onchain"]

    # 2. Point the store's chain watcher at the mock Esplora (mainnet is the
    # column default; stated explicitly because the Strike path requires it).
    with payserver.db() as db:
        db.execute(
            """
            UPDATE stores
               SET onchain_provider = 'esplora', onchain_provider_url = ?,
                   onchain_network = 'mainnet', onchain_min_confs = 1
             WHERE id = ?
            """,
            (mock_esplora.url, store_id),
        )

    # 3. Checkout: the invoice's on-chain address is minted via a Strike
    # receive request; the Strike LN rail still serves the bolt11 alongside.
    rr_before = set(strike_api.receive_requests)
    invoice = configured.greenfield.create_invoice(
        store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]
    row = _invoice_row(configured, invoice_id)
    assert row["strike_receive_request_id"], row
    assert row["onchain_address"] and row["onchain_address"].startswith("bc1"), row
    assert row["onchain_amount_tweak_sats"] is None, "no tweak on a Strike-minted address"
    assert row["onchain_amount_sat"] == INVOICE_AMOUNT_SAT, row

    made = strike_api.receive_requests.get(row["strike_receive_request_id"])
    assert made is not None, "checkout created the receive request through the mock"
    assert made["address"] == row["onchain_address"], made
    assert made["body"]["onchain"]["amount"] == {"amount": "0.00021000", "currency": "BTC"}, made
    new_rrs = set(strike_api.receive_requests) - rr_before
    assert new_rrs == {row["strike_receive_request_id"]}, "exactly one receive request per invoice"

    # The Greenfield payload carries the Strike address as the BTC-OnChain
    # method plus the receive-request id for dashboard reconciliation — and
    # never the key.
    api_view = configured.greenfield.get_invoice(store_id, invoice_id)
    assert TEST_STRIKE_KEY not in str(api_view), "API payload must never contain the key"
    onchain_method = api_view["checkout"]["paymentMethods"]["BTC-OnChain"]
    assert onchain_method["destination"] == row["onchain_address"], onchain_method
    assert api_view.get("strikeReceiveRequestId") == row["strike_receive_request_id"], api_view

    # The customer's payment page shows the Strike-minted address.
    page = requests.get(
        f"{payserver.url}/payment.php", params={"id": invoice_id}, timeout=30
    )
    assert page.status_code == 200
    assert row["onchain_address"] in page.text, "on-chain payment option not offered"

    # 4. "The customer pays on-chain AND closes the tab": the mock Esplora
    # reports a confirmed UTXO on the Strike address, and CRON alone (no
    # payment-page polls) must settle the invoice. This is the path the
    # dedicated onchain_last_polled_at throttle restored — the Strike LN
    # poller stamps the shared last_polled_at every cron pass, and the old
    # shared-column throttle (broken further by the PDO text-affinity
    # comparison) never chain-polled a dual-rail invoice again.
    mock_esplora.pay(
        row["onchain_address"], INVOICE_AMOUNT_SAT,
        txid="e2e" + "f" * 61, height=TIP_HEIGHT,
    )
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        cron = payserver.trigger_cron()
        assert cron.status_code == 200, cron.text
        if _invoice_row(configured, invoice_id)["status"] == "Settled":
            break
        time.sleep(2)
    final = _invoice_row(configured, invoice_id)
    assert final["status"] == "Settled", f"cron did not settle the invoice: {final['status']}"
    assert final["onchain_last_polled_at"], "the on-chain poller stamped its own throttle column"
    assert final["status"] == "Settled", final
    assert final["settled_rail"] == "onchain", final
    with payserver.db() as db:
        paid = db.execute(
            "SELECT amount_sat FROM onchain_payments WHERE invoice_id = ?", (invoice_id,)
        ).fetchall()
    assert [p[0] for p in paid] == [INVOICE_AMOUNT_SAT], paid


def test_strike_only_store_saves_zeroconf_from_the_onchain_card(
    shared_configured_with_strike: ConfiguredPayserver,
    strike_api_shared: StrikeApiServer,
) -> None:
    """A Strike-only store (Strike on-chain enabled, no xpub/static address)
    must be able to opt into zero-conf from the admin on-chain card: saving
    with an empty address persists min_confs, and the response flags the
    still-active Strike rail so the UI doesn't announce "on-chain disabled"."""
    configured = shared_configured_with_strike
    store_id = configured.store_id
    admin = configured.admin

    r = _save_strike(admin, store_id, TEST_STRIKE_KEY, "1")
    assert r.status_code == 200, r.text

    r = admin.s.post(
        admin._admin_url,
        data={
            "action": "save_onchain",
            "store_id": store_id,
            "mode": "xpub",
            "xpub": "",
            "network": "mainnet",
            "address_type": "P2WPKH",
            "min_confs": "0",
            "confirm_timeout_sec": "86400",
            "provider_url": "",
        },
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=30,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert body["disabled"] is True, "no address source was configured"
    assert body["strikeOnchain"] is True, "the response must flag the active Strike rail"

    with configured.handle.db() as db:
        row = db.execute(
            "SELECT onchain_min_confs, onchain_strike_enabled, onchain_xpub "
            "FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    assert row[0] == 0, "zero-conf persisted for the Strike-only store"
    assert row[1] == 1, "the address save must not touch the Strike flag"
    assert row[2] is None


def test_strike_onchain_enable_refused_without_scope(
    shared_configured_with_strike: ConfiguredPayserver,
    strike_api_shared: StrikeApiServer,
) -> None:
    """A key that can't create receive requests (403) is refused at enable
    time with a message naming the missing scope, and the flag stays off.

    fail_receive_request is a mock-global toggle, but tests run sequentially
    and only this test enables the option while it is set; restored in the
    finally, so sharing the module mock is safe."""
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    store_id = configured.store_id

    strike_api.fail_receive_request = 403
    try:
        r = _save_strike(configured.admin, store_id, TEST_STRIKE_KEY, "1")
    finally:
        strike_api.fail_receive_request = None
    assert r.status_code == 400, r.text
    err = r.json().get("error", "")
    assert "create receive requests" in err, err
    assert TEST_STRIKE_KEY not in err, "error must never contain the key"

    with configured.handle.db() as db:
        flag = db.execute(
            "SELECT onchain_strike_enabled FROM stores WHERE id = ?", (store_id,)
        ).fetchone()[0]
    assert not flag, "the flag must stay off after a refused enable"

    # Saving with the box unticked still works (no probe, key saved, flag off).
    r = _save_strike(configured.admin, store_id, TEST_STRIKE_KEY, "0")
    assert r.status_code == 200, r.text
    assert r.json().get("strikeOnchainEnabled") is False, r.text
