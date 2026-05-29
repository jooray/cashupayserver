"""cron.php auth, task execution, and pre-setup behavior."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.payserver import PayserverHandle


def test_cron_returns_503_before_setup(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/cron.php", timeout=10)
    assert r.status_code == 503
    assert "Not configured" in r.text


def test_cron_runs_without_key_when_unconfigured(configured: ConfiguredPayserver) -> None:
    """No cron_key set during the test wizard, so any caller can run it."""
    r = configured.handle.trigger_cron()
    assert r.status_code == 200, r.text
    body = r.json()
    assert "tasks" in body
    # All four cron tasks should be present.
    for task in ("poll_quotes", "auto_melt", "clean_cache", "expire_invoices"):
        assert task in body["tasks"], body


def test_cron_rejects_wrong_key_when_configured(configured: ConfiguredPayserver) -> None:
    with configured.handle.db() as db:
        now = "strftime('%s','now')"
        db.execute(
            f"INSERT INTO config (key, value, created_at, updated_at) "
            f"VALUES ('cron_key', 'real-cron-key', {now}, {now}) "
            f"ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = {now}"
        )
    r = requests.get(
        f"{configured.handle.url}/cron.php",
        params={"key": "wrong-key"},
        timeout=10,
    )
    assert r.status_code == 403
    assert "Invalid cron key" in r.text

    r2 = requests.get(
        f"{configured.handle.url}/cron.php",
        params={"key": "real-cron-key"},
        timeout=10,
    )
    assert r2.status_code == 200


def test_cron_internal_mode_requires_internal_key(configured: ConfiguredPayserver) -> None:
    r = requests.get(
        f"{configured.handle.url}/cron.php",
        params={"internal": "1", "key": "definitely-wrong"},
        timeout=10,
    )
    assert r.status_code == 403
    assert "Invalid internal key" in r.text

    # Pull the real internal key from the DB and prove it works.
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT value FROM config WHERE key = 'internal_background_key'"
        ).fetchone()
    assert row, "internal_background_key should be auto-generated during setup"
    real_key = row["value"]

    r2 = configured.handle.trigger_cron(internal_key=real_key)
    assert r2.status_code == 200, r2.text
