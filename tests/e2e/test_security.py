"""Webserver access control (router.php / .htaccess / nginx rules), admin
CSRF, login lockout. The path-blocking tests assert status codes only — the
403 body differs per serving backend (router.php's "Forbidden" vs the
Apache/nginx error pages)."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.payserver import APP_ROOT, PayserverHandle


# ---------- router.php path blocking ----------


def test_router_blocks_data_directory(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/data/cashupay.sqlite", timeout=5)
    assert r.status_code == 403, r.text


def test_live_data_dir_sqlite_not_downloadable(payserver: PayserverHandle) -> None:
    """The instance's REAL database file. Its data dir lives under app/.tmp
    inside the served tree (see conftest.SESSION_TMP), so on Apache/nginx only
    the runtime-written data/.htaccess (Database::ensureDataDirectoryProtections),
    the global sqlite deny, and the nginx rules stand between the web and the
    wallet DB — router.php's blocklist never sees a request Apache serves as a
    real file."""
    # A wizard page load initializes the schema, which creates the DB file and
    # (via ensureDataDirectoryProtections) the data dir's own deny files.
    requests.get(f"{payserver.url}/setup.php", timeout=10)
    assert payserver.db_path.exists()
    rel = payserver.db_path.relative_to(APP_ROOT).as_posix()
    r = requests.get(f"{payserver.url}/{rel}", timeout=5)
    assert r.status_code == 403, f"{rel} -> {r.status_code}"
    assert b"SQLite format 3" not in r.content, "database bytes leaked"
    # WAL sidecar carries the same payment data.
    r = requests.get(f"{payserver.url}/{rel}-wal", timeout=5)
    assert r.status_code in (403, 404), f"{rel}-wal -> {r.status_code}"
    assert b"SQLite" not in r.content


def test_live_data_dir_listing_denied(payserver: PayserverHandle) -> None:
    rel = payserver.data_dir.relative_to(APP_ROOT).as_posix()
    r = requests.get(f"{payserver.url}/{rel}/", timeout=5)
    assert r.status_code == 403, f"{rel}/ -> {r.status_code}"


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
