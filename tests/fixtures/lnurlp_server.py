"""LNURL-pay mock server for auto-melt tests.

Responds to GET /.well-known/lnurlp/{user} with valid LNURL-pay metadata
pointing at /callback?user=<name>, which on hit asks the supplied LND
node to issue a BOLT11 and returns it.

Setting CASHU_LNURL_URL_TEMPLATE in cashupayserver's environment to
"http://127.0.0.1:<port>/.well-known/lnurlp/{user}" routes
LightningAddress::resolve() through this mock.
"""
from __future__ import annotations

import json
import threading
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Callable
from urllib.parse import urlparse, parse_qs

from . import ports
from .lnd import LndHandle


@dataclass
class LnurlpServer:
    port: int
    server: HTTPServer
    thread: threading.Thread

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def url_template(self) -> str:
        """Value to set as CASHU_LNURL_URL_TEMPLATE."""
        return f"{self.base_url}/.well-known/lnurlp/{{user}}"


def start_lnurlp_server(receiver: LndHandle) -> LnurlpServer:
    port = ports.allocate(1)[0]

    class Handler(BaseHTTPRequestHandler):
        def _send_json(self, status: int, body: dict) -> None:
            payload = json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def do_GET(self):  # noqa: N802
            parsed = urlparse(self.path)
            path = parsed.path

            if path.startswith("/.well-known/lnurlp/"):
                user = path[len("/.well-known/lnurlp/"):]
                callback = f"http://127.0.0.1:{port}/callback?user={user}"
                self._send_json(200, {
                    "callback": callback,
                    "minSendable": 1000,           # 1 sat
                    "maxSendable": 100_000_000_000, # 0.1 BTC
                    "metadata": json.dumps([["text/plain", f"pay {user}"]]),
                    "commentAllowed": 0,
                    "tag": "payRequest",
                })
                return

            if path == "/callback":
                qs = parse_qs(parsed.query)
                amount_msat_list = qs.get("amount", [])
                if not amount_msat_list:
                    self._send_json(400, {"status": "ERROR", "reason": "missing amount"})
                    return
                amount_sat = int(amount_msat_list[0]) // 1000
                user = (qs.get("user") or ["unknown"])[0]
                try:
                    invoice = receiver.add_invoice(amount_sat, memo=f"lnurlp:{user}")
                except Exception as e:
                    self._send_json(500, {"status": "ERROR", "reason": str(e)})
                    return
                self._send_json(200, {"pr": invoice["payment_request"], "routes": []})
                return

            self._send_json(404, {"status": "ERROR", "reason": "not found"})

        def log_message(self, *args, **kwargs):
            pass

    server = HTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True, name=f"lnurlp-{port}")
    thread.start()
    return LnurlpServer(port=port, server=server, thread=thread)


def stop_lnurlp_server(s: LnurlpServer) -> None:
    s.server.shutdown()
    s.server.server_close()
    s.thread.join(timeout=5)
