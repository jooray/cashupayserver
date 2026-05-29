"""Smoke tests for /api/v1/server/info — the only unauthenticated endpoint."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.payserver import PayserverHandle


def test_server_info_returns_503_before_setup(payserver: PayserverHandle) -> None:
    """Before the install wizard runs the server should advertise that fact."""
    r = requests.get(f"{payserver.url}/api/v1/server/info", timeout=5)
    assert r.status_code == 503
    body = r.json()
    assert body.get("code") == "service-unavailable"


def test_server_info_no_auth_required_after_setup(configured: ConfiguredPayserver) -> None:
    """After setup, server/info should be reachable without an API token."""
    r = requests.get(f"{configured.handle.url}/api/v1/server/info", timeout=5)
    assert r.status_code == 200, r.text
    body = r.json()
    assert "version" in body, body
    assert "BTC-LightningNetwork" in body.get("supportedPaymentMethods", []), body
    assert body.get("isCashuPayServer") is True
