"""Auto-update card UI: the auto-rollback warning banner + dismiss, and the
recommended dedicated-cron line.

When the isolated updater detects that an applied build failed its health
check, it rolls back, blocks the build, and records `updater_last_auto_rollback`
in config. The Settings → Auto-update card surfaces that as a red banner until
the admin dismisses it.
"""
from __future__ import annotations

import json
import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui

BAD_SHA = "abc123def456" + "0" * 28  # 40 chars; UI shows the first 12


def _seed_rollback(configured: ConfiguredPayserver) -> None:
    record = {
        "bad_sha": BAD_SHA,
        "version": "9.9-bad",
        "from_version": "9.8-good",
        "backup": "20260101-000000-deadbeef0000",
        "rolled_back": True,
        "rolled_back_at": int(time.time()),
    }
    now = int(time.time())
    with configured.handle.db() as db:
        for key, value in (
            ("updater_last_auto_rollback", json.dumps(record)),
            ("updater_auto_rollback_dismissed", "false"),
        ):
            db.execute(
                "INSERT INTO config (key, value, created_at, updated_at) "
                "VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value, "
                "updated_at = excluded.updated_at",
                (key, value, now, now),
            )


def _login_and_open_settings(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.click('[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#card-auto-update", state="visible")


def test_auto_rollback_banner_shows_and_dismisses(
    configured: ConfiguredPayserver, page
) -> None:
    _seed_rollback(configured)
    _login_and_open_settings(configured, page)

    # The red rollback banner becomes visible once loadAutoUpdateCard() fetches
    # update_status and sees the (undismissed) auto-rollback record.
    page.wait_for_function(
        "() => { const w = document.querySelector('#auto-update-rollback-warning');"
        " return w && !w.classList.contains('hidden'); }"
    )
    detail = page.inner_text("#auto-update-rollback-detail")
    assert "abc123def456" in detail, detail  # first 12 chars of the bad SHA

    # Dismiss it.
    page.click("#btn-dismiss-auto-rollback")
    page.wait_for_function(
        "() => { const w = document.querySelector('#auto-update-rollback-warning');"
        " return w && w.classList.contains('hidden'); }"
    )

    # The dismissal is persisted so it doesn't re-appear on reload. The UI
    # hides the banner optimistically BEFORE its POST resolves, so poll the
    # config write instead of racing it with a single read.
    deadline = time.monotonic() + 15
    while True:
        with configured.handle.db() as db:
            row = db.execute(
                "SELECT value FROM config WHERE key = 'updater_auto_rollback_dismissed'"
            ).fetchone()
        if row is not None and json.loads(row[0]) is True:
            break
        assert time.monotonic() < deadline, (
            f"dismissal never persisted; row={row[0] if row else None!r}"
        )
        time.sleep(0.2)


def test_recommended_cron_line_is_shown(
    configured: ConfiguredPayserver, page
) -> None:
    _login_and_open_settings(configured, page)
    page.wait_for_function(
        "() => { const c = document.querySelector('#auto-update-cron-line');"
        " return c && c.textContent.includes('update.php'); }"
    )
    line = page.inner_text("#auto-update-cron-line")
    assert "update.php" in line
    assert "X-CRON-KEY" in line
