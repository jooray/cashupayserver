"""Access-control hardening: setup gate, wizard CSRF, login lockout, migration
fail-closed.

Every test here mutates global server state (lockout counters, the setup
wizard, the database file), so they all use function-scoped servers.
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import stat
import threading
import uuid
from pathlib import Path
from typing import Iterator

import pytest
import requests

from conftest import (
    SESSION_TMP,
    ConfiguredPayserver,
    DEFAULT_ADMIN_PASSWORD,
    _maybe_remove_workdir,
    _test_went_green,
)
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import run_setup_wizard, wizard_csrf_token


def _config(handle: PayserverHandle, key: str):
    with handle.db() as db:
        row = db.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    if row is None:
        return None
    raw = row["value"]
    try:
        return json.loads(raw)
    except (ValueError, TypeError):
        return raw  # strings are stored raw


# ---------- SO-2: completed-install gate ----------


def test_anonymous_tail_post_on_configured_install_is_refused(configured: ConfiguredPayserver) -> None:
    """A fresh session posting step=updates to a live install must be bounced to
    admin: no auto-update flip, no cron screen, no cron key in the body."""
    handle = configured.handle
    before = _config(handle, "auto_update_enabled")
    cron_key = _config(handle, "cron_key")
    assert cron_key, "cron_key is seeded at install"

    stranger = requests.Session()
    for step, data in (
        ("wallet_tabs", {"step": "wallet_tabs", "wallet_tabs_action": "skip"}),
        ("updates", {"step": "updates", "auto_update": "0"}),
        ("cron", {"step": "cron"}),
        ("done", {"step": "done"}),
    ):
        r = stranger.post(f"{handle.url}/setup.php", data=data, timeout=15, allow_redirects=False)
        assert r.status_code == 302, f"step={step}: expected 302, got {r.status_code}: {r.text[:300]}"
        assert "admin" in r.headers.get("Location", ""), r.headers
        assert cron_key not in r.text, f"step={step} leaked the cron key"
        assert "X-CRON-KEY" not in r.text

    assert _config(handle, "auto_update_enabled") == before, "the auto-update opt-in must be untouched"

    # GET of the tail screens is bounced too.
    r = stranger.get(f"{handle.url}/setup.php", params={"step": "cron"}, timeout=15, allow_redirects=False)
    assert r.status_code == 302


# ---------- wizard CSRF ----------


def test_wizard_post_without_csrf_token_is_refused(payserver: PayserverHandle) -> None:
    s = requests.Session()
    r = s.get(f"{payserver.url}/setup.php", timeout=30)
    assert r.status_code == 200
    token = wizard_csrf_token(r.text)
    assert token, "the wizard renders a csrf_token field"

    terms = {"step": "terms", "terms_legal": "1", "terms_warranty": "1", "terms_fee": "1"}
    r = s.post(f"{payserver.url}/setup.php", data=terms, timeout=15, allow_redirects=False)
    assert r.status_code == 403, r.text[:300]
    assert "Session expired or invalid request" in r.text
    assert 'name="step" value="terms"' in r.text, "the wizard did not advance"

    r = s.post(f"{payserver.url}/setup.php", data={**terms, "csrf_token": "0" * 64}, timeout=15, allow_redirects=False)
    assert r.status_code == 403

    # The AJAX helper is refused with JSON, and writes nothing.
    r = s.post(f"{payserver.url}/setup.php", data={"action": "save_url_mode", "mode": "clean"}, timeout=15)
    assert r.status_code == 403
    assert r.json().get("success") is False
    assert _config(payserver, "url_mode") is None

    # With the token the same POST advances.
    r = s.post(f"{payserver.url}/setup.php", data={**terms, "csrf_token": token}, timeout=15, allow_redirects=False)
    assert r.status_code == 200, r.text[:300]
    assert 'name="step" value="terms"' not in r.text, "the wizard advanced past terms"


# ---------- AU-1: atomic lockout ----------


def test_parallel_wrong_passwords_are_counted_before_the_check(configured: ConfiguredPayserver) -> None:
    """8 wrong passwords fired together from one address: at most 5 reach the
    password check (401), the rest are refused (429), and the next sequential
    attempt is locked out."""
    url = f"{configured.handle.url}/admin"
    statuses: list[int] = []
    lock = threading.Lock()

    def attempt(i: int) -> None:
        r = requests.post(url, data={"action": "login", "username": "admin", "password": f"wrong-{i}"}, timeout=30)
        with lock:
            statuses.append(r.status_code)

    threads = [threading.Thread(target=attempt, args=(i,)) for i in range(8)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    assert len(statuses) == 8
    assert statuses.count(401) <= 5, f"more than 5 guesses reached the password check: {statuses}"
    assert statuses.count(429) >= 3, f"expected at least 3 refused before the check: {statuses}"
    assert set(statuses) <= {401, 429}, statuses

    r = requests.post(url, data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD}, timeout=15)
    assert r.status_code == 429, f"the address is locked out even for the right password: {r.status_code} {r.text[:200]}"
    assert "Too many" in r.text


# ---------- AU-7: global sign-in budget ----------


@pytest.fixture
def proxied_payserver(request, mint, backup_mint) -> Iterator[PayserverHandle]:
    """A configured server that trusts 127.0.0.1 as a reverse proxy, so
    X-Forwarded-For picks the client address for the lockout counters."""
    workdir = SESSION_TMP / f"payserver-proxied-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env={"CASHUPAY_TRUSTED_PROXIES": "127.0.0.1"})
    run_setup_wizard(
        handle.url,
        admin_password=DEFAULT_ADMIN_PASSWORD,
        mint_url=mint.url,
        backup_mint_url=backup_mint.url,
    )
    yield handle
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


def test_global_sign_in_budget_bounds_address_rotation(proxied_payserver: PayserverHandle) -> None:
    """25 wrong passwords from 25 distinct forwarded addresses: the first 20
    are checked, the rest hit the global pause — including a correct password
    from a fresh address."""
    url = f"{proxied_payserver.url}/admin"
    statuses = []
    for i in range(1, 26):
        r = requests.post(
            url,
            data={"action": "login", "username": "admin", "password": f"wrong-{i}"},
            headers={"X-Forwarded-For": f"203.0.113.{i}"},
            timeout=15,
        )
        statuses.append(r.status_code)
    assert statuses[:20] == [401] * 20, statuses
    assert statuses[20:] == [429] * 5, statuses

    r = requests.post(
        url,
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        headers={"X-Forwarded-For": "198.51.100.200"},
        timeout=15,
    )
    assert r.status_code == 429, r.text
    assert "Too many failed attempts" in r.text


# ---------- MF-1: migration failure must not open the wizard ----------


def test_migration_failure_shows_upgrade_failed_page_not_the_wizard(configured: ConfiguredPayserver) -> None:
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        pytest.skip("read-only simulation needs a non-root user")
    handle = configured.handle
    db_path = handle.db_path
    data_dir = db_path.parent

    # Simulate "code upgraded, migration pending, database wedged": drop the
    # migration marker column, fold the WAL into the main file, then make the
    # file and its directory read-only so the ALTER TABLE cannot run.
    conn = sqlite3.connect(db_path, isolation_level=None)
    try:
        conn.execute("ALTER TABLE stores DROP COLUMN wallet_tabs")
        conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        conn.execute("PRAGMA journal_mode = DELETE")
    finally:
        conn.close()
    stores_before = None
    file_mode = stat.S_IMODE(os.stat(db_path).st_mode)
    dir_mode = stat.S_IMODE(os.stat(data_dir).st_mode)
    os.chmod(db_path, 0o444)
    os.chmod(data_dir, 0o555)
    try:
        r = requests.get(f"{handle.url}/setup.php", timeout=15, allow_redirects=False)
        assert r.status_code == 503, f"expected the upgrade-failed page, got {r.status_code}: {r.text[:300]}"
        assert "Database upgrade failed" in r.text
        assert 'name="step"' not in r.text, "no wizard screen must render"

        # No handler runs either: the first-run store step is refused.
        s = requests.Session()
        r = s.post(
            f"{handle.url}/setup.php",
            data={"step": "store", "store_name": "Wedged Store", "default_currency": "sat"},
            timeout=15,
            allow_redirects=False,
        )
        assert r.status_code == 503, r.text[:300]

        # The root entry point does not redirect into the wizard either.
        r = requests.get(f"{handle.url}/", timeout=15, allow_redirects=False)
        assert r.status_code != 302 or "setup" not in r.headers.get("Location", ""), (
            f"root must not redirect to the wizard on a wedged install: {r.status_code} {r.headers.get('Location')}"
        )
    finally:
        os.chmod(data_dir, dir_mode)
        os.chmod(db_path, file_mode)

    # Self-heal: writable again -> the migration runs and the install is live.
    r = requests.get(f"{handle.url}/setup.php", timeout=15, allow_redirects=False)
    assert r.status_code == 302 and "admin" in r.headers.get("Location", ""), (r.status_code, r.headers)
    with handle.db() as db:
        cols = [row[1] for row in db.execute("PRAGMA table_info(stores)")]
        assert "wallet_tabs" in cols, "marker column restored by the migration"
        names = [row[0] for row in db.execute("SELECT name FROM stores")]
    assert "Wedged Store" not in names, "no store was created while wedged"
