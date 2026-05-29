"""Tiny HTTP server that captures POST bodies for webhook assertions.

Function-scoped per test. Each test gets a unique base URL; the sink
records every POST as (path, headers, body) keyed by path so a test can
register multiple webhooks and inspect each independently.
"""
from __future__ import annotations

import json
import threading
import time
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any, Optional

from . import ports


@dataclass
class CapturedRequest:
    path: str
    headers: dict[str, str]
    body: bytes
    received_at: float

    def json(self) -> Any:
        return json.loads(self.body.decode())


@dataclass
class WebhookSink:
    port: int
    server: HTTPServer
    thread: threading.Thread
    captured: list[CapturedRequest] = field(default_factory=list)
    response_status: dict[str, int] = field(default_factory=dict)  # path-prefix -> status

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def endpoint(self, label: str) -> str:
        """A clean URL prefix the test can hand to cashupayserver as a webhook target."""
        return f"{self.url}/hook/{label}"

    def by_path(self, prefix: str) -> list[CapturedRequest]:
        return [r for r in self.captured if r.path.startswith(prefix)]

    def wait_for(self, prefix: str, count: int = 1, timeout_s: float = 30) -> list[CapturedRequest]:
        deadline = time.monotonic() + timeout_s
        while time.monotonic() < deadline:
            matches = self.by_path(prefix)
            if len(matches) >= count:
                return matches
            time.sleep(0.1)
        raise TimeoutError(
            f"webhook sink: expected {count} POST(s) to {prefix}, got {len(self.by_path(prefix))} "
            f"within {timeout_s}s (all paths: {[r.path for r in self.captured]})"
        )

    def force_response(self, prefix: str, status: int) -> None:
        """Make subsequent POSTs to `prefix` return `status` (for retry testing)."""
        self.response_status[prefix] = status

    def clear(self) -> None:
        self.captured.clear()
        self.response_status.clear()


def start_webhook_sink() -> WebhookSink:
    port = ports.allocate(1)[0]
    sink_holder: dict[str, WebhookSink] = {}

    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):  # noqa: N802
            length = int(self.headers.get("Content-Length", "0") or "0")
            body = self.rfile.read(length) if length else b""
            sink = sink_holder["sink"]
            sink.captured.append(
                CapturedRequest(
                    path=self.path,
                    headers={k: v for k, v in self.headers.items()},
                    body=body,
                    received_at=time.time(),
                )
            )
            status = 200
            for prefix, override in sink.response_status.items():
                if self.path.startswith(prefix):
                    status = override
                    break
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", "2")
            self.end_headers()
            self.wfile.write(b"{}")

        def do_GET(self):  # noqa: N802
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.end_headers()
            self.wfile.write(b"ok")

        def log_message(self, *args, **kwargs):
            # Silence default stderr noise
            pass

    server = HTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True, name=f"webhook-sink-{port}")
    thread.start()

    sink = WebhookSink(port=port, server=server, thread=thread)
    sink_holder["sink"] = sink
    return sink


def stop_webhook_sink(sink: WebhookSink) -> None:
    sink.server.shutdown()
    sink.server.server_close()
    sink.thread.join(timeout=5)
