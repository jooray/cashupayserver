"""HTTP clients for cashupayserver: AdminClient for admin.php session +
GreenfieldClient for the BTCPay-compatible /api/v1/ surface.

AdminClient logs in, holds the session cookie + CSRF token, and exposes
admin-only operations (create API key, list stores, etc.). It's how a
test bootstraps an API key for the Greenfield client.
"""
from __future__ import annotations

import re
from typing import Any, Optional

import requests


class AdminClient:
    def __init__(self, base_url: str, *, session: Optional[requests.Session] = None) -> None:
        self.base_url = base_url.rstrip("/")
        self.s = session or requests.Session()
        self.csrf_token: Optional[str] = None

    @property
    def _admin_url(self) -> str:
        return f"{self.base_url}/admin"

    def login(self, password: str) -> None:
        r = self.s.post(self._admin_url, data={"action": "login", "password": password}, timeout=15)
        r.raise_for_status()
        body = r.json()
        if not body.get("success"):
            raise RuntimeError(f"admin login failed: {body}")
        self.csrf_token = body.get("csrfToken")
        if not self.csrf_token:
            # Fall back to scraping from a subsequent GET if the login response didn't include it
            self._refresh_csrf()

    def _refresh_csrf(self) -> None:
        r = self.s.get(self._admin_url, timeout=15)
        r.raise_for_status()
        m = re.search(r'name="csrf-token"\s+content="([^"]+)"', r.text)
        if not m:
            raise RuntimeError("admin GET did not include csrf-token meta")
        self.csrf_token = m.group(1)

    def _post_action(self, action: str, **fields: Any) -> dict[str, Any]:
        if not self.csrf_token:
            raise RuntimeError("AdminClient: login() before calling actions")
        data = {"action": action, **fields}
        r = self.s.post(
            self._admin_url,
            data=data,
            headers={"X-CSRF-Token": self.csrf_token},
            timeout=30,
        )
        if r.status_code >= 400:
            raise RuntimeError(f"admin {action} -> {r.status_code}: {r.text[:400]}")
        return r.json() if r.content else {}

    def get_dashboard(self) -> dict[str, Any]:
        r = self.s.get(f"{self._admin_url}?api=dashboard", timeout=15)
        r.raise_for_status()
        return r.json()

    def list_stores(self) -> list[dict[str, Any]]:
        return self.get_dashboard().get("stores", [])

    def create_api_key(self, store_id: str, label: str = "test-key") -> dict[str, Any]:
        return self._post_action("create_api_key", store_id=store_id, label=label)

    def delete_api_key(self, key_id: str) -> dict[str, Any]:
        return self._post_action("delete_api_key", key_id=key_id)


class GreenfieldClient:
    """BTCPay Greenfield-compatible client. Each instance carries one API token."""

    def __init__(self, base_url: str, token: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.s = requests.Session()
        self.s.headers["Authorization"] = f"token {token}"

    # --- store ---

    def server_info(self) -> dict[str, Any]:
        return self._get("/api/v1/server/info")

    def list_stores(self) -> list[dict[str, Any]]:
        return self._get("/api/v1/stores")

    def get_store(self, store_id: str) -> dict[str, Any]:
        return self._get(f"/api/v1/stores/{store_id}")

    # --- invoice ---

    def create_invoice(
        self,
        store_id: str,
        amount: str,
        currency: str = "sat",
        *,
        metadata: Optional[dict[str, Any]] = None,
        checkout: Optional[dict[str, Any]] = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"amount": amount, "currency": currency}
        if metadata is not None:
            body["metadata"] = metadata
        if checkout is not None:
            body["checkout"] = checkout
        return self._post(f"/api/v1/stores/{store_id}/invoices", body)

    def get_invoice(self, store_id: str, invoice_id: str) -> dict[str, Any]:
        return self._get(f"/api/v1/stores/{store_id}/invoices/{invoice_id}")

    def list_invoices(self, store_id: str) -> list[dict[str, Any]]:
        return self._get(f"/api/v1/stores/{store_id}/invoices")

    def get_invoice_payment_methods(self, store_id: str, invoice_id: str) -> Any:
        return self._get(f"/api/v1/stores/{store_id}/invoices/{invoice_id}/payment-methods")

    def mark_invoice_status(self, store_id: str, invoice_id: str, status: str) -> dict[str, Any]:
        return self._post(f"/api/v1/stores/{store_id}/invoices/{invoice_id}/status", {"status": status})

    # --- webhooks ---

    def create_webhook(
        self,
        store_id: str,
        url: str,
        *,
        secret: Optional[str] = None,
        authorized_events: Optional[dict[str, Any]] = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"url": url}
        if secret is not None:
            body["secret"] = secret
        if authorized_events is not None:
            body["authorizedEvents"] = authorized_events
        else:
            body["authorizedEvents"] = {"everything": True}
        return self._post(f"/api/v1/stores/{store_id}/webhooks", body)

    def list_webhooks(self, store_id: str) -> list[dict[str, Any]]:
        return self._get(f"/api/v1/stores/{store_id}/webhooks")

    def delete_webhook(self, store_id: str, webhook_id: str) -> None:
        self._delete(f"/api/v1/stores/{store_id}/webhooks/{webhook_id}")

    # --- low-level ---

    def _get(self, path: str) -> Any:
        r = self.s.get(f"{self.base_url}{path}", timeout=30)
        self._raise(r, "GET", path)
        return r.json() if r.content else None

    def _post(self, path: str, body: Any) -> Any:
        r = self.s.post(f"{self.base_url}{path}", json=body, timeout=30)
        self._raise(r, "POST", path)
        return r.json() if r.content else None

    def _delete(self, path: str) -> None:
        r = self.s.delete(f"{self.base_url}{path}", timeout=30)
        self._raise(r, "DELETE", path)

    @staticmethod
    def _raise(r: requests.Response, method: str, path: str) -> None:
        if r.status_code >= 400:
            raise RuntimeError(f"Greenfield {method} {path} -> {r.status_code}: {r.text[:400]}")
