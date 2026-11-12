"""Mock Esplora (mempool.space / blockstream.info compatible) HTTP API.

Serves the endpoints the payserver uses:

    GET  /blocks/tip/height          -> settable tip
    GET  /address/{addr}/txs         -> per-address tx list (one page)
    GET  /address/{addr}/txs/chain/* -> always empty (no older history)
    GET  /fee-estimates              -> settable sat/vB table
    POST /tx                         -> records the raw hex, answers a txid

Behaviour switches for outage / misconfiguration tests:

    mode = "ok" | "down" (HTTP 500 everywhere) | "garbage" (HTTP 200 HTML)
    api_prefix = "/api"  -> only paths under the prefix behave like an API;
                            everything else returns the HTML "web root" page
                            (the "pasted the website, not the API" case)

Every request is appended to ``hits`` (``"GET /blocks/tip/height"``) so a
test can prove which explorer was (not) contacted.
"""
from __future__ import annotations

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from . import ports

DEFAULT_TIP_HEIGHT = 850_000


class MockEsplora:
    """Minimal Esplora API: a settable tip, per-address tx lists, a hit log."""

    def __init__(self, *, tip: int = DEFAULT_TIP_HEIGHT, api_prefix: str | None = None) -> None:
        self.tip = tip
        # address -> list of esplora tx dicts
        self.txs: dict[str, list[dict]] = {}
        self.mode = "ok"
        self.api_prefix = api_prefix
        self.fee_estimates: dict[str, float] = {"1": 12.0, "2": 10.0, "3": 8.0, "6": 5.0}
        self.broadcasts: list[str] = []
        self.broadcast_txid = "f" * 64
        self.hits: list[str] = []
        self._lock = threading.Lock()
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def _record(self) -> None:
                with outer._lock:
                    outer.hits.append(f"{self.command} {self.path}")

            def _send(self, status: int, body: bytes, ctype: str = "application/json") -> None:
                self.send_response(status)
                self.send_header("Content-Type", ctype)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def _route(self) -> str | None:
                """Path with the api prefix stripped, or None for the web root."""
                path = self.path
                if outer.api_prefix is None:
                    return path
                if path == outer.api_prefix or path.startswith(outer.api_prefix + "/"):
                    return path[len(outer.api_prefix):] or "/"
                return None

            def do_GET(self):  # noqa: N802
                self._record()
                if outer.mode == "down":
                    self._send(500, b"mock-esplora-down", "text/plain")
                    return
                if outer.mode == "garbage":
                    self._send(200, b"<html><body>not json</body></html>", "text/html")
                    return
                path = self._route()
                if path is None:
                    self._send(200, b"<!doctype html><html><body>mempool web ui</body></html>", "text/html")
                    return
                parts = path.strip("/").split("/")
                if path == "/blocks/tip/height":
                    body = str(outer.tip).encode()
                    self._send(200, body, "text/plain")
                    return
                if path == "/fee-estimates":
                    self._send(200, json.dumps(outer.fee_estimates).encode())
                    return
                if len(parts) >= 3 and parts[0] == "address" and parts[2] == "txs":
                    # /address/{addr}/txs and /address/{addr}/txs/chain/{txid}
                    # (the chain page is always empty — one page of history).
                    txs = outer.txs.get(parts[1], []) if len(parts) == 3 else []
                    self._send(200, json.dumps(txs).encode())
                    return
                self._send(404, b"[]")

            def do_POST(self):  # noqa: N802
                self._record()
                length = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(length).decode(errors="replace") if length else ""
                if outer.mode == "down":
                    self._send(500, b"mock-esplora-down", "text/plain")
                    return
                path = self._route()
                if path == "/tx":
                    with outer._lock:
                        outer.broadcasts.append(raw)
                    self._send(200, outer.broadcast_txid.encode(), "text/plain")
                    return
                self._send(404, b"not found", "text/plain")

            def log_message(self, *args, **kwargs):
                pass

        self.port = ports.allocate(1)[0]
        self.server = ThreadingHTTPServer(("127.0.0.1", self.port), Handler)
        self.server.daemon_threads = True
        self.thread = threading.Thread(
            target=self.server.serve_forever, daemon=True, name=f"mock-esplora-{self.port}"
        )
        self.thread.start()

    @property
    def url(self) -> str:
        """Base URL of the mock (the API base when no api_prefix is set)."""
        return f"http://127.0.0.1:{self.port}"

    @property
    def api_url(self) -> str:
        """The URL an operator should enter: base + api prefix (if any)."""
        return self.url + (self.api_prefix or "")

    def pay(self, address: str, amount_sat: int, txid: str, height: int | None) -> None:
        """Record a payment to `address`. height=None -> unconfirmed (mempool)."""
        status = {"confirmed": True, "block_height": height} if height is not None else {"confirmed": False}
        self.txs.setdefault(address, []).append({
            "txid": txid,
            "status": status,
            "vout": [{"scriptpubkey_address": address, "value": amount_sat}],
        })

    def hit_count(self, needle: str = "") -> int:
        with self._lock:
            return sum(1 for h in self.hits if needle in h)

    def reset_hits(self) -> None:
        with self._lock:
            self.hits.clear()

    def stop(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
