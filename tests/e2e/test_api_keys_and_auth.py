"""Greenfield API auth: token required, rate limit, multi-store access."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.api_client import GreenfieldClient


def test_authenticated_endpoint_requires_token(configured: ConfiguredPayserver) -> None:
    r = requests.get(f"{configured.handle.url}/api/v1/stores", timeout=5)
    assert r.status_code == 401
    assert r.json()["code"] == "unauthenticated"


def test_invalid_token_is_rejected(configured: ConfiguredPayserver) -> None:
    r = requests.get(
        f"{configured.handle.url}/api/v1/stores",
        headers={"Authorization": "token wrong-token-xyz"},
        timeout=5,
    )
    assert r.status_code == 401


def test_api_key_can_access_its_store_invoices(configured: ConfiguredPayserver) -> None:
    invoices = configured.greenfield.list_invoices(configured.store_id)
    assert isinstance(invoices, list)


def test_deleting_an_api_key_revokes_it(configured: ConfiguredPayserver) -> None:
    # Create a throwaway key, exercise it, then delete and re-test.
    extra = configured.admin.create_api_key(configured.store_id, label="throwaway")
    extra_token = extra["key"]
    extra_client = GreenfieldClient(configured.handle.url, extra_token)
    extra_client.list_stores()  # works

    configured.admin.delete_api_key(extra["id"])
    r = requests.get(
        f"{configured.handle.url}/api/v1/stores",
        headers={"Authorization": f"token {extra_token}"},
        timeout=5,
    )
    assert r.status_code == 401, r.text


def test_rate_limit_returns_429(configured: ConfiguredPayserver) -> None:
    """100 req/min/IP. Burst past it on a cheap endpoint and expect 429."""
    s = requests.Session()
    s.headers["Authorization"] = f"token {configured.api_token}"
    url = f"{configured.handle.url}/api/v1/server/info"

    got_429 = False
    for _ in range(110):
        r = s.get(url, timeout=5)
        if r.status_code == 429:
            got_429 = True
            assert r.json().get("code") == "rate-limited"
            break
    assert got_429, "expected to hit rate limit within 110 requests"
