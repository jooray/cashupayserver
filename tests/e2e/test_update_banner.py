"""Browser e2e for the manual-update banner + Auto-update card status.

Covers the user-facing surface of the manual-update feature:
  - The "update available" banner appears (with the version) when the cached
    availability verdict says a newer build exists — on EVERY admin view, not
    just the dashboard (auto-update is off by default, so the nudge must not
    be missable) — and stays hidden when the install is current.
  - The Auto-update card's opt-in toggle persists the DB-backed
    auto_update_enabled key both ways, and the notifications card's
    "update available" email checkbox defaults on.
  - The Auto-update settings card reflects the availability verdict and exposes
    an "Update now" button.
  - In the dev/test stack the updater is intentionally disabled (the
    .updater_disabled sentinel), so the manual-update API refuses with a clear
    "disabled" reason rather than overlaying the dev checkout.

The actual download/overlay/health-verify path is covered hermetically by the
PHP tests (test_updater_force_apply.php, test_updater_check_for_update.php);
applying a real update against a live server is out of scope here (and unsafe in
the dev stack, which is exactly what the disabled-environment guard enforces).
"""
from __future__ import annotations

import json
import time

import pytest
import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.payserver import PayserverHandle
from fixtures.browser import expand_settings_cards


def _set_config(handle: PayserverHandle, key: str, value) -> None:
    """Seed a config row the way Config::set would (non-strings JSON-encoded)."""
    now = int(time.time())
    with handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value), now, now),
        )


@pytest.fixture
def admin_page(configured: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, configured
    ctx.close()


def test_banner_shows_when_update_available(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "latest_sha": "f" * 40,
        "current_version": "0.0-old",
        "blocked": False,
        "checked_at": int(time.time()),
    })

    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
    page.wait_for_timeout(1500)

    banner = page.locator("#update-available-banner")
    assert banner.is_visible(), "banner should be visible when an update is available"
    text = banner.inner_text()
    assert "update is available" in text.lower()
    assert "9.9.9-test" in text, "banner should name the available version"
    # The security/urgency wording the user asked for.
    assert "security" in text.lower()
    assert page.locator("#btn-update-now-banner").is_visible()


def test_banner_shows_on_every_admin_view(admin_page):
    """The banner lives above the view containers, so switching to settings,
    invoices, etc. must keep it on screen — an operator who never opens the
    dashboard still sees the nudge."""
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "latest_sha": "f" * 40,
        "current_version": "0.0-old",
        "blocked": False,
        "checked_at": int(time.time()),
    })

    for view in ("settings", "invoices", "stores"):
        page.goto(f"{configured.handle.url}/admin/{view}", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible(), f"banner should be visible on the {view} view"
        assert "9.9.9-test" in banner.inner_text(), (
            f"banner on the {view} view should name the available version"
        )


def test_auto_update_toggle_persists_both_ways(admin_page):
    """The Auto-update card's opt-in toggle writes the DB-backed
    auto_update_enabled key that Updater::isAutoUpdateEnabled consults. It
    starts off (the default), saves on on change, and saves off again."""
    page, configured = admin_page

    def gate_state():
        return page.evaluate(
            """async (base) => {
                const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
                const d = await r.json();
                return { enabled: d.auto_update_enabled, source: d.auto_update_source };
            }""",
            configured.handle.url,
        )

    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)

    toggle = page.locator("#auto-update-enabled")
    slider = page.locator("#auto-update-enabled + .toggle-slider")
    assert not toggle.is_checked(), "automatic updates must default to off"
    assert gate_state() == {"enabled": False, "source": None}

    # The <input> is CSS-hidden (opacity 0); the slider is the click target.
    slider.click()
    page.wait_for_timeout(800)
    assert gate_state() == {"enabled": True, "source": "db"}, (
        "flipping the toggle on must persist the DB opt-in"
    )

    # Survives a reload (state comes from update_status, not the DOM).
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)
    toggle = page.locator("#auto-update-enabled")
    assert toggle.is_checked(), "the saved opt-in must be reflected after reload"

    page.locator("#auto-update-enabled + .toggle-slider").click()
    page.wait_for_timeout(800)
    assert gate_state() == {"enabled": False, "source": None}, (
        "flipping the toggle off must close the gate again"
    )


def test_update_email_checkbox_defaults_on(admin_page):
    """The notifications card's per-type 'update available' checkbox defaults
    ON (unlike the other per-type toggles) and persists an explicit off."""
    page, configured = admin_page
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)

    box = page.locator("#notifications-update-available")
    assert box.is_checked(), "the update email checkbox must default to on"

    # CSS-hidden checkbox; the slider is the click target.
    page.locator("#notifications-update-available + .toggle-slider").click()
    page.click("#btn-save-notifications")
    page.wait_for_timeout(800)

    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)
    assert not page.locator("#notifications-update-available").is_checked(), (
        "an explicit off must persist"
    )

    # Leave the store the way we found it.
    page.locator("#notifications-update-available + .toggle-slider").click()
    page.click("#btn-save-notifications")
    page.wait_for_timeout(800)


def test_banner_hidden_when_current(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": False,
        "channel": "main",
        "checked_at": int(time.time()),
    })

    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
    page.wait_for_timeout(1500)

    assert not page.locator("#update-available-banner").is_visible(), \
        "banner should stay hidden when the install is current"


def test_banner_hidden_when_cached_verdict_names_the_running_build(admin_page):
    """Regression: the availability verdict is cached for up to a day and the
    apply path used to leave it alone, so right after updating to 1.6 the
    banner still read "A software update is available (version 1.6)" while
    "Update now" answered "You're already on the latest version". The server
    now self-heals the cached verdict when its latest_sha is the SHA the
    install is running: update_status reports available=false and the banner
    stays hidden, with no cron check in between.

    The dev checkout normally has no BUILD_INFO (it is a release artifact), so
    this test writes one at the docroot for its duration and restores whatever
    was there before."""
    page, configured = admin_page
    from fixtures.payserver import REPO_ROOT

    running_sha = "a1b2c3d4" * 5
    build_info = REPO_ROOT / "BUILD_INFO"
    previous = build_info.read_bytes() if build_info.exists() else None
    build_info.write_text(f"COMMIT_SHA={running_sha}\nCHANNEL=main\nVERSION=1.6\n")
    try:
        _set_config(configured.handle, "updater_available", {
            "available": True,
            "channel": "main",
            "current_version": "1.5.3",
            "current_sha": "0" * 40,
            "latest_version": "1.6",
            "latest_sha": running_sha,
            "blocked": False,
            "checked_at": int(time.time()) - 3600,  # an hour old: inside the daily window
        })

        page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)

        assert not page.locator("#update-available-banner").is_visible(), (
            "banner must not advertise the build the install is already running"
        )
        status = page.evaluate(
            """async (base) => {
                const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
                return await r.json();
            }""",
            configured.handle.url,
        )
        assert status["available"]["available"] is False, status["available"]
        assert status["available"]["current_sha"] == running_sha, status["available"]
        assert status["available"]["current_version"] == "1.6", status["available"]

        # The Auto-update card agrees (it reads the same verdict).
        page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
        expand_settings_cards(page)
        page.wait_for_timeout(1500)
        assert "latest version" in page.locator("#auto-update-availability").inner_text().lower()

        # Healed verdict is persisted, so the once-per-release email notifier
        # (which reads the same key) will not nag about the running build.
        with configured.handle.db() as db:
            row = db.execute("SELECT value FROM config WHERE key = 'updater_available'").fetchone()
        assert json.loads(row["value"])["available"] is False

        # A verdict for a genuinely different SHA is still shown.
        _set_config(configured.handle, "updater_available", {
            "available": True,
            "channel": "main",
            "latest_version": "9.9.9-test",
            "latest_sha": "f" * 40,
            "current_sha": running_sha,
            "blocked": False,
            "checked_at": int(time.time()),
        })
        page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible()
        assert "9.9.9-test" in banner.inner_text()
    finally:
        if previous is None:
            build_info.unlink(missing_ok=True)
        else:
            build_info.write_bytes(previous)


def test_banner_hidden_when_cached_verdict_came_from_another_build(admin_page):
    """Regression (second report): the server was updated 1.6 → 1.6.1 by the
    1.6 build's updater, whose daily check had cached "1.6 is available"
    (computed against the 1.5.3 SHA). After the update, latest_sha (1.6) was
    not the running SHA (1.6.1) so the catch-up rule could not match, and the
    banner kept saying "version 1.6 is available" next to "Update applied
    (1.6 → 1.6.1)". A verdict whose current_sha is not the running SHA is now
    invalidated on read: update_status reports available=false with
    checked_at=0 (the dev stack's kill switch keeps the on-demand refetch a
    no-op), and the banner stays hidden."""
    page, configured = admin_page
    from fixtures.payserver import REPO_ROOT

    running_sha = "161" + "b" * 37
    build_info = REPO_ROOT / "BUILD_INFO"
    previous = build_info.read_bytes() if build_info.exists() else None
    build_info.write_text(f"COMMIT_SHA={running_sha}\nCHANNEL=main\nVERSION=1.6.1\n")
    try:
        _set_config(configured.handle, "updater_available", {
            "available": True,
            "channel": "main",
            "current_version": "1.5.3",
            "current_sha": "0" * 40,
            "latest_version": "1.6",
            "latest_sha": "16" + "a" * 38,
            "blocked": False,
            "checked_at": int(time.time()) - 600,
        })

        page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)

        assert not page.locator("#update-available-banner").is_visible(), (
            "a verdict computed by a different build must not be advertised"
        )
        status = page.evaluate(
            """async (base) => {
                const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
                return await r.json();
            }""",
            configured.handle.url,
        )
        assert status["available"]["available"] is False, status["available"]
        assert status["available"]["current_sha"] == running_sha, status["available"]
        assert status["available"]["current_version"] == "1.6.1", status["available"]
        assert status["available"]["checked_at"] == 0, status["available"]

        with configured.handle.db() as db:
            row = db.execute("SELECT value FROM config WHERE key = 'updater_available'").fetchone()
        stored = json.loads(row["value"])
        assert stored["available"] is False and stored["checked_at"] == 0, stored
    finally:
        if previous is None:
            build_info.unlink(missing_ok=True)
        else:
            build_info.write_bytes(previous)


def _update_status(page, base):
    return page.evaluate(
        """async (base) => {
            const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
            return await r.json();
        }""",
        base,
    )


def test_update_applied_outcome_retires_once_the_page_runs_the_new_build(admin_page):
    """Regression (third report): after updating 1.6.1 → 1.6.2 and reloading,
    the banner still read "Software update — Updates deliver new features and
    critical security enhancements. Please don't delay… Update applied
    (1.6.1 → 1.6.2). Reload the page to use the new version." for up to ten
    minutes (the manual_run record's display window).

    Two things fixed here:
      - The urgency blurb only shows while an update is actually available,
        never under a progress line or an outcome.
      - The "Update applied — reload" outcome is retired as soon as the page
        itself is served by the applied build (its render-time BUILD_INFO SHA
        equals updater_last_update.to_sha; VERSION fallback without a
        BUILD_INFO). Before that reload it must still show, or the operator
        never learns the update landed.
    The BUILD_INFO at the docroot is what admin.php reads at render time, so
    the test swaps it to model "old page" vs "reloaded page"."""
    page, configured = admin_page
    from fixtures.payserver import REPO_ROOT

    base = configured.handle.url
    old_sha = "0161" + "a" * 36
    new_sha = "0162" + "b" * 36
    build_info = REPO_ROOT / "BUILD_INFO"
    previous = build_info.read_bytes() if build_info.exists() else None

    def seed_outcome(to_sha: str, to_version: str) -> None:
        now = int(time.time())
        _set_config(configured.handle, "updater_available", {
            "available": False, "channel": "main", "checked_at": now,
            "current_sha": to_sha, "latest_sha": to_sha,
        })
        _set_config(configured.handle, "updater_last_update", {
            "from_version": "1.6.1", "to_version": to_version,
            "from_sha": old_sha, "to_sha": to_sha, "channel": "main", "applied_at": now - 30,
        })
        _set_config(configured.handle, "updater_manual_run", {
            "state": "success", "started_at": now - 60, "finished_at": now - 30,
            "from_version": "1.6.1", "to_version": to_version,
        })

    try:
        # --- 1. Old page (rendered by the pre-update build): outcome shown,
        # urgency blurb hidden, headline is the neutral "Software update".
        build_info.write_text(f"COMMIT_SHA={old_sha}\nCHANNEL=main\nVERSION=1.6.1\n")
        seed_outcome(new_sha, "1.6.2")
        page.goto(f"{base}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible(), "the outcome must be shown until the operator reloads"
        text = banner.inner_text()
        assert "update applied" in text.lower() and "1.6.1 → 1.6.2" in text, text
        assert "reload the page" in text.lower(), text
        assert "software update" in text.lower(), text
        assert "security" not in text.lower(), "urgency blurb must not show when nothing is available"
        assert "is available" not in text.lower(), text
        assert not page.locator("#update-available-urgency").is_visible()

        # --- 2. Reloaded page (rendered by the applied build): nothing to show.
        build_info.write_text(f"COMMIT_SHA={new_sha}\nCHANNEL=main\nVERSION=1.6.2\n")
        page.goto(f"{base}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        assert not page.locator("#update-available-banner").is_visible(), (
            "'Update applied — reload' must retire once the page runs the new build"
        )
        # The settings card's progress line is retired too (in the dev stack
        # it then shows the "disabled in this environment" note instead).
        page.goto(f"{base}/admin/settings", wait_until="networkidle")
        expand_settings_cards(page)
        page.wait_for_timeout(1500)
        card_prog = page.locator("#auto-update-progress")
        if card_prog.is_visible():
            assert "update applied" not in card_prog.inner_text().lower(), card_prog.inner_text()
            assert "reload" not in card_prog.inner_text().lower(), card_prog.inner_text()
        assert "latest version" in page.locator("#auto-update-availability").inner_text().lower()

        # --- 3. No BUILD_INFO (dev checkout): VERSION fallback.
        build_info.unlink()
        running_version = _update_status(page, base)["current_version"]
        seed_outcome("", running_version)  # applied build == the one serving the page
        page.goto(f"{base}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        assert not page.locator("#update-available-banner").is_visible(), (
            "version fallback: outcome for the running version is retired"
        )
        seed_outcome("", "9.9.9-test")  # applied build is not the one serving the page
        page.goto(f"{base}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible()
        assert "1.6.1 → 9.9.9-test" in banner.inner_text()
        assert "security" not in banner.inner_text().lower()

        # --- 4. An available update still shows the urgency blurb.
        _set_config(configured.handle, "updater_available", {
            "available": True, "channel": "main", "latest_version": "9.9.9-test",
            "latest_sha": "f" * 40, "checked_at": int(time.time()),
        })
        page.goto(f"{base}/admin/dashboard", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible()
        assert "security" in banner.inner_text().lower()
        assert page.locator("#update-available-urgency").is_visible()
    finally:
        if previous is None:
            build_info.unlink(missing_ok=True)
        else:
            build_info.write_bytes(previous)
        with configured.handle.db() as db:
            db.execute("DELETE FROM config WHERE key IN ('updater_manual_run', 'updater_last_update')")


def test_manual_update_refused_in_dev_stack(admin_page):
    """The dev/test stack sets .updater_disabled; the manual-update endpoint
    must refuse with reason 'disabled' instead of overlaying the checkout."""
    page, configured = admin_page
    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")

    # update_status should report the manual-blocked reason.
    status = page.evaluate(
        """async (base) => {
            const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
            return await r.json();
        }""",
        configured.handle.url,
    )
    assert status.get("manual_blocked") == "disabled", status

    # Starting a manual update is refused, not silently applied. Send the CSRF
    # token from the page's meta tag, same as the in-app button flow does.
    result = page.evaluate(
        """async (base) => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const r = await fetch(base + '/admin', {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                },
                body: 'action=start_manual_update',
            });
            return await r.json();
        }""",
        configured.handle.url,
    )
    assert result.get("success") is False, result
    assert result.get("error") == "disabled", result


def test_update_now_button_present_in_settings_card(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "checked_at": int(time.time()),
    })
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)

    assert page.evaluate("!!document.getElementById('btn-update-now')")
    avail = page.locator("#auto-update-availability")
    assert "9.9.9-test" in avail.inner_text()
    # Button is disabled in the dev stack (manual_blocked == 'disabled').
    assert page.evaluate("document.getElementById('btn-update-now').disabled") is True


def _channel(page, base):
    return page.evaluate(
        """async (base) => {
            const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
            return (await r.json()).channel;
        }""",
        base,
    )


def test_save_channel_button_persists(admin_page):
    """Regression: the Save-channel button posts `action` in the body via
    postWithCsrf. The earlier query-string form returned 'Unknown action', so
    the channel never changed. Drive the real button and confirm it sticks."""
    page, configured = admin_page
    base = configured.handle.url
    page.goto(f"{base}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1200)

    assert _channel(page, base) == "main"
    page.select_option("#auto-update-channel", "testing")
    page.click("#btn-save-update-channel")
    page.wait_for_timeout(800)
    assert _channel(page, base) == "testing", "Save channel button should persist the choice"

    # Reset so the stack is left on the stable channel.
    page.select_option("#auto-update-channel", "main")
    page.click("#btn-save-update-channel")
    page.wait_for_timeout(800)
    assert _channel(page, base) == "main"
