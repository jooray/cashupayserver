"""Mock Strike REST API for the Strike receive-rail tests.

Serves the endpoints the payserver's StrikeClient uses:

    POST /v1/invoices              -> create (captures the request body)
    POST /v1/invoices/{id}/quote   -> quote (returns a mock BOLT11)
    GET  /v1/invoices/{id}         -> read-back (UNPAID until mark_paid())
    POST /v1/receive-requests      -> on-chain receive (fresh valid mainnet
                                      address per request; settlement is chain-
                                      watched by the payserver, so the mock has
                                      no receive read-back)

Auth mirrors the real API: only ``Authorization: Bearer <expected_key>`` is
accepted; anything else gets Strike's 401 UNAUTHORIZED error shape.

Because the mock never routes real Lightning payments, "the customer paid"
is simulated by ``mark_paid(invoice_id)`` / ``mark_all_paid()``, after which
the read-back reports state=PAID and the payserver pollers settle.

Point the payserver at it by starting it with
``CASHUPAY_STRIKE_API_BASE=<strike_api.api_base>`` in extra_env.
"""
from __future__ import annotations

import json
import re
import threading
import uuid
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, HTTPServer

from . import ports

TEST_STRIKE_KEY = "E2ETESTKEY" + "A" * 30


@dataclass
class StrikeApiServer:
    port: int
    server: HTTPServer
    thread: threading.Thread
    expected_key: str
    # invoice_id -> {"body": <create request body>, "state": "UNPAID"|"PAID"|"CANCELLED"}
    invoices: dict[str, dict] = field(default_factory=dict)
    # receive_request_id -> {"body": <request body>, "address": <address handed out>}
    receive_requests: dict[str, dict] = field(default_factory=dict)
    # Set to an HTTP status (e.g. 500) to fail the matching endpoint.
    fail_create: int | None = None
    fail_quote: int | None = None
    fail_read: int | None = None
    fail_receive_request: int | None = None
    # Address POST /v1/receive-requests returns instead of the valid-mainnet
    # rotation (the payserver checksum-validates what it gets back).
    receive_address_override: str | None = None

    @property
    def api_base(self) -> str:
        """Value to set as CASHUPAY_STRIKE_API_BASE."""
        return f"http://127.0.0.1:{self.port}/v1"

    def mark_paid(self, invoice_id: str) -> None:
        self.invoices[invoice_id]["state"] = "PAID"

    def mark_all_paid(self) -> None:
        for inv in self.invoices.values():
            inv["state"] = "PAID"


def start_strike_api(expected_key: str = TEST_STRIKE_KEY) -> StrikeApiServer:
    port = ports.allocate(1)[0]
    handle: StrikeApiServer  # assigned below; the handler closes over it

    class Handler(BaseHTTPRequestHandler):
        def _send_json(self, status: int, body: dict) -> None:
            payload = json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def _fail(self, status: int, code: str) -> None:
            self._send_json(status, {"data": {"code": code, "message": "mock failure"}})

        def _authed(self) -> bool:
            auth = self.headers.get("Authorization", "")
            if auth != f"Bearer {handle.expected_key}":
                self._fail(401, "UNAUTHORIZED")
                return False
            return True

        def do_POST(self):  # noqa: N802
            if not self._authed():
                return
            if self.path == "/v1/receive-requests":
                if handle.fail_receive_request is not None:
                    self._fail(handle.fail_receive_request, "MOCK_RECEIVE_REQUEST_FAIL")
                    return
                length = int(self.headers.get("Content-Length") or 0)
                body = json.loads(self.rfile.read(length) or b"{}")
                rr_id = str(uuid.uuid4())
                # Real mainnet addresses (BIP173/BIP350 test vectors) in
                # rotation — the payserver refuses checksum-invalid addresses.
                pool = [
                    "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4",
                    "bc1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3qccfmv3",
                    "bc1p5d7rjq7g6rdk2yhzks9smlaqtedr4dekq08ge8ztwac72sfr9rusxg3297",
                ]
                addr = handle.receive_address_override or pool[len(handle.receive_requests) % len(pool)]
                handle.receive_requests[rr_id] = {"body": body, "address": addr}
                btc = ((body.get("onchain") or {}).get("amount") or {}).get("amount", "0")
                self._send_json(201, {
                    "receiveRequestId": rr_id,
                    "onchain": {
                        "address": addr,
                        "addressUri": f"bitcoin:{addr}?amount={btc}",
                        "btcAmount": btc,
                        "requestedAmount": (body.get("onchain") or {}).get("amount"),
                    },
                })
                return
            if self.path == "/v1/invoices":
                if handle.fail_create is not None:
                    self._fail(handle.fail_create, "MOCK_CREATE_FAIL")
                    return
                length = int(self.headers.get("Content-Length") or 0)
                body = json.loads(self.rfile.read(length) or b"{}")
                invoice_id = str(uuid.uuid4())
                handle.invoices[invoice_id] = {"body": body, "state": "UNPAID"}
                self._send_json(201, {
                    "invoiceId": invoice_id,
                    "state": "UNPAID",
                    "amount": body.get("amount"),
                })
                return
            m = re.fullmatch(r"/v1/invoices/([^/]+)/quote", self.path)
            if m:
                if handle.fail_quote is not None:
                    self._fail(handle.fail_quote, "MOCK_QUOTE_FAIL")
                    return
                inv = handle.invoices.get(m.group(1))
                if inv is None:
                    self._fail(404, "NOT_FOUND")
                    return
                amount = (inv["body"].get("amount") or {})
                self._send_json(201, {
                    "quoteId": f"q-{m.group(1)}",
                    "lnInvoice": f"lnbcmockstrike{m.group(1).replace('-', '')}",
                    "expirationInSec": 300,
                    "sourceAmount": amount,
                    "targetAmount": amount,
                })
                return
            self._fail(404, "NOT_FOUND")

        def do_GET(self):  # noqa: N802
            if not self._authed():
                return
            if self.path.startswith("/v1/invoices?") or self.path == "/v1/invoices":
                self._send_json(200, {"items": [], "count": 0})
                return
            m = re.fullmatch(r"/v1/invoices/([^/?]+)", self.path)
            if m:
                if handle.fail_read is not None:
                    self._fail(handle.fail_read, "MOCK_READ_FAIL")
                    return
                inv = handle.invoices.get(m.group(1))
                if inv is None:
                    self._fail(404, "NOT_FOUND")
                    return
                self._send_json(200, {
                    "invoiceId": m.group(1),
                    "state": inv["state"],
                    "amount": inv["body"].get("amount"),
                })
                return
            self._fail(404, "NOT_FOUND")

        def log_message(self, *args, **kwargs):
            pass

    server = HTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True, name=f"strike-api-{port}")
    handle = StrikeApiServer(port=port, server=server, thread=thread, expected_key=expected_key)
    thread.start()
    return handle


def stop_strike_api(s: StrikeApiServer) -> None:
    s.server.shutdown()
    s.server.server_close()
    s.thread.join(timeout=5)
