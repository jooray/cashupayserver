"""router.php access control, admin CSRF, login lockout."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.payserver import PayserverHandle


# ---------- router.php path blocking ----------


def test_router_blocks_data_directory(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/data/cashupay.sqlite", timeout=5)
    assert r.status_code == 403, r.text


def test_router_blocks_includes_php_files(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/includes/database.php", timeout=5)
    assert r.status_code == 403, r.text


def test_router_blocks_cashu_wallet_php_internals(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/cashu-wallet-php/CashuWallet.php", timeout=5)
    assert r.status_code == 403, r.text


def test_router_blocks_dotfiles(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/.htaccess", timeout=5)
    assert r.status_code == 403, r.text


def test_router_blocks_sqlite_extension(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/data/something.sqlite", timeout=5)
    assert r.status_code == 403, r.text


# ---------- admin CSRF ----------


def test_admin_post_without_csrf_token_rejected(configured: ConfiguredPayserver) -> None:
    """Already-logged-in session, but a CSRF-less POST should still be rejected."""
    # Use the admin client's session cookies but skip the X-CSRF-Token header.
    r = configured.admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "delete_store", "store_id": configured.store_id},
        timeout=10,
    )
    assert r.status_code == 403, r.text
    assert "csrf" in r.text.lower()


# ---------- login lockout ----------


def test_login_lockout_after_repeated_failures(configured: ConfiguredPayserver) -> None:
    """5 failed admin login attempts should trigger HTTP 429 lockout.

    Pre-setup the admin route redirects to setup wizard, so we need
    `configured` (wizard already walked) before login attempts work."""
    # Use a fresh session so we don't accidentally piggyback on
    # `configured.admin`'s already-authenticated cookie.
    s = requests.Session()
    saw_429 = False
    for i in range(8):
        r = s.post(
            f"{configured.handle.url}/admin",
            data={"action": "login", "password": f"wrong-{i}"},
            timeout=10,
        )
        if r.status_code == 429:
            saw_429 = True
            assert "Too many" in r.text or "lockout" in r.text.lower()
            break
        assert r.status_code == 401, f"expected 401 (or 429), got {r.status_code}: {r.text[:200]}"
    assert saw_429, "expected login lockout within 8 attempts"
