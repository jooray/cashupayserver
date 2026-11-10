"""End-to-end: opt-in anonymous usage statistics (includes/usage_stats.php).

Runs the REAL scripts/analytics-endpoint/report.php on a local php -S
(fixtures/analytics_endpoint.py) and points freshly booted payservers at it:

  - the wizard's `analytics` screen sits after `updates`, shows all three
    level previews (the exact JSON), and a level button opts in AND sends the
    first report immediately; "No thanks" stores an explicit off and nothing
    is ever sent;
  - the report that lands on the endpoint is byte-for-byte the preview the
    operator saw (modulo the install id and timestamp), carries a random v4
    install id — never the deployment id — and no mint URL / store name even
    though the store has both;
  - cron sends weekly: not due right after the first report, sent once the
    last-sent stamp is a week old, and the endpoint keeps ONE row per install;
  - the Settings card (Playwright): preview swaps with the level radio, the
    toggle persists both ways, saving with the toggle on sends a report at the
    chosen level, and turning it off stops cron sends;
  - test rigs: with the endpoint unset the default `disabled` kill switch
    means an opted-in install still never sends (cron reports 'disabled').

All payservers here are booted per test (function scope): the opt-in is
install-global config, which the shared-server fixtures forbid mutating.
"""
from __future__ import annotations

import json
import sqlite3
import time
import uuid

import pytest

from conftest import SESSION_TMP, _maybe_remove_workdir, _test_went_green
from fixtures.analytics_endpoint import (
    AnalyticsEndpoint,
    start_analytics_endpoint,
    stop_analytics_endpoint,
    usage_stats_env,
)
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard, wizard_error, wizard_heading
from fixtures.browser import expand_settings_cards

UUID_V4 = __import__("re").compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
MINT_URL = "https://mint.secret-fixture.example"
STORE_NAME = "Secret Telemetry Store"


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------


@pytest.fixture
def endpoint(request) -> AnalyticsEndpoint:
    workdir = SESSION_TMP / f"analytics-ep-{uuid.uuid4().hex[:8]}"
    ep = start_analytics_endpoint(workdir)

    def _teardown() -> None:
        if _test_went_green(request):
            stop_analytics_endpoint(ep)
        else:
            ep.stop()

    request.addfinalizer(_teardown)
    return ep


def _boot(request, ep: AnalyticsEndpoint | None, extra_env: dict | None = None) -> PayserverHandle:
    workdir = SESSION_TMP / f"payserver-us-{uuid.uuid4().hex[:8]}"
    env = dict(extra_env or {})
    if ep is not None:
        env.update(usage_stats_env(ep))
    handle = start_payserver(workdir, extra_env=env)

    def _teardown() -> None:
        stop_payserver(handle)
        _maybe_remove_workdir(workdir, _test_went_green(request))

    request.addfinalizer(_teardown)
    return handle


def _config(handle: PayserverHandle, key: str):
    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    finally:
        conn.close()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except ValueError:
        return row[0]


def _set_config(handle: PayserverHandle, key: str, value) -> None:
    now = int(time.time())
    with handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value) if not isinstance(value, str) else value, now, now),
        )


def _walk_to_analytics(handle: PayserverHandle) -> tuple[SetupWizard, str]:
    """The analytics screen is screen two: only the terms gate precedes it."""
    w = SetupWizard(handle.url)
    body = w.accept_terms()
    assert wizard_heading(body) == "Anonymous usage statistics", wizard_heading(body)
    return w, body


def _finish_wizard(w: SetupWizard) -> str:
    """From the installation-type screen to completion, declining every rail
    (no mint fixtures needed). Answering the last rail screen is what marks
    the install complete — and sends the deferred first usage report when
    the operator opted in on screen two. Returns the done-screen body."""
    w.choose_install_type("other")
    w.post(step="security", security_acknowledged="1")
    w.post(
        step="password",
        password=SetupWizard.DEFAULT_PASSWORD,
        confirm_password=SetupWizard.DEFAULT_PASSWORD,
    )
    w.skip_email()
    w.post(step="store", store_name=STORE_NAME, default_currency="sat")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    body = w.post(step="mints", mints_enabled="0")
    assert wizard_heading(body) == "Enable automatic updates?", wizard_heading(body)
    body = w.post(step="updates", auto_update="0")
    assert wizard_heading(body) == "Enable cron", wizard_heading(body)
    return w.post(step="cron")


def _previews_from_wizard(body: str) -> dict[int, dict]:
    """The three <pre class="usage-stats-preview" data-level=N> blocks, decoded."""
    import html
    import re

    out = {}
    for m in re.finditer(r'<pre class="usage-stats-preview" data-level="(\d)"[^>]*>(.*?)</pre>', body, re.S):
        out[int(m.group(1))] = json.loads(html.unescape(m.group(2)))
    return out


def _login_ctx(browser, handle: PayserverHandle):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{handle.url}/admin",
        form={"action": "login", "username": "admin", "password": SetupWizard.DEFAULT_PASSWORD},
    )
    return ctx


# ---------------------------------------------------------------------------
# wizard
# ---------------------------------------------------------------------------


def test_wizard_screen_previews_every_level_and_declining_sends_nothing(request, endpoint) -> None:
    # Database::initialize seeds deployment_id from this env var on first boot.
    handle = _boot(request, endpoint, {"CASHUPAY_DEPLOYMENT_ID": "SECRET-DEPLOYMENT-7"})
    w, body = _walk_to_analytics(handle)

    # One button per level plus an explicit decline; nothing pre-selected
    # (no radio, no checked state) — the choice is a click.
    for level in (1, 2, 3):
        assert f"name=\"usage_stats\" value=\"{level}\"" in body, f"level {level} button missing"
    assert 'name="usage_stats" value="0"' in body, "decline button missing"
    assert "PRIVACY.md" in body, "the screen links the privacy policy"

    previews = _previews_from_wizard(body)
    assert sorted(previews) == [1, 2, 3], "every level shows its exact report"
    for level, p in previews.items():
        assert p["schema_version"] == 1 and p["event"] == "usage_report" and p["level"] == level
        assert p["install_id"] == "(random id, generated when you enable)", "no id exists before opting in"
        assert p["properties"]["install_kind"] == "standalone"
    assert set(previews[1]["properties"]) < set(previews[2]["properties"]) < set(previews[3]["properties"])
    # Screen two comes before the store exists, so the preview is honest
    # about that: nothing to count yet.
    assert previews[2]["properties"]["store_count"] == 0
    assert "stores_with_mint" in previews[2]["properties"]
    for forbidden in ("SECRET-DEPLOYMENT-7", STORE_NAME, "secret-fixture", "mint_url", "deployment"):
        assert forbidden not in json.dumps(previews[3]), f"preview must not contain {forbidden!r}"

    # Decline: explicit off, no id minted, nothing reaches the endpoint, on
    # to the installation type.
    body = w.post(step="analytics", usage_stats="0")
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Choose your installation type", wizard_heading(body)
    assert _config(handle, "usage_stats_enabled") is False, "declining stores an explicit false"
    assert _config(handle, "usage_stats_install_id") is None, "declining mints no identifier"
    assert _config(handle, "usage_stats_level") is None
    _finish_wizard(w)
    assert endpoint.reports() == [], "completing the wizard sends nothing for a declined install"

    # Cron agrees: off, nothing sent, even when driven.
    summary = handle.trigger_cron_json()
    assert summary.get("tasks", {}).get("usage_stats") == "off", summary.get("tasks")
    assert endpoint.reports() == [], "a declined install never reports"


def test_wizard_level_button_opts_in_and_sends_the_previewed_report(request, endpoint) -> None:
    # Database::initialize seeds deployment_id from this env var on first boot.
    handle = _boot(request, endpoint, {"CASHUPAY_DEPLOYMENT_ID": "SECRET-DEPLOYMENT-8"})
    w, body = _walk_to_analytics(handle)
    previewed = _previews_from_wizard(body)[3]

    body = w.post(step="analytics", usage_stats="3")
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Choose your installation type", wizard_heading(body)

    assert _config(handle, "usage_stats_enabled") is True
    assert _config(handle, "usage_stats_level") == 3
    install_id = _config(handle, "usage_stats_install_id")
    assert isinstance(install_id, str) and UUID_V4.match(install_id), install_id
    assert "SECRET" not in install_id

    # The first report is DEFERRED: on screen two there is nothing to report
    # yet, so the click alone sends nothing…
    time.sleep(1)
    assert endpoint.reports() == [], "opting in on screen two must not send an empty-install report"
    assert _config(handle, "usage_stats_last_attempt_at") is None

    # …and completing the install (the last rail answer) sends it.
    _finish_wizard(w)
    reports = endpoint.wait_for_reports(1, timeout_s=5)
    assert len(reports) == 1
    sent = reports[0]["payload"]
    assert reports[0]["install_id"] == install_id
    assert reports[0]["level"] == 3
    assert sent["install_id"] == install_id
    assert sent["level"] == 3
    # The same shape the operator previewed — the same property set at the
    # same level; the values moved on because the store now exists.
    assert set(sent["properties"]) == set(previewed["properties"]), "the report has the previewed shape"
    assert sent["properties"]["store_count"] == 1
    assert set(sent) == set(previewed)
    assert _config(handle, "usage_stats_last_sent_at") is not None
    assert _config(handle, "usage_stats_last_error") is None
    body_json = json.dumps(sent)
    for forbidden in ("SECRET-DEPLOYMENT-8", STORE_NAME, "secret-fixture", MINT_URL):
        assert forbidden not in body_json, f"the wire payload must not contain {forbidden!r}"
    # The receiver tags no country here (no geo table, loopback) and never stores the IP.
    assert reports[0]["country"] is None
    assert "127.0.0.1" not in json.dumps(reports[0])


def test_weekly_cadence_and_one_row_per_install(request, endpoint) -> None:
    handle = _boot(request, endpoint)
    w, _ = _walk_to_analytics(handle)
    w.post(step="analytics", usage_stats="1")
    _finish_wizard(w)
    first = endpoint.wait_for_reports(1, timeout_s=5)[0]

    # Just sent: cron must not send again.
    summary = handle.trigger_cron_json()
    assert summary["tasks"]["usage_stats"] == "not_due", summary["tasks"]
    assert len(endpoint.reports()) == 1

    # Age the last-sent stamp past a week: the next tick sends, replacing the row.
    _set_config(handle, "usage_stats_last_sent_at", int(time.time()) - 8 * 86400)
    _set_config(handle, "usage_stats_last_attempt_at", int(time.time()) - 8 * 86400)
    summary = handle.trigger_cron_json()
    assert summary["tasks"]["usage_stats"] == "sent", summary["tasks"]
    reports = endpoint.reports()
    assert len(reports) == 1, "the endpoint keeps only the most recent report per install"
    assert reports[0]["install_id"] == first["install_id"]
    assert reports[0]["received_at"] >= first["received_at"]
    assert int(_config(handle, "usage_stats_last_sent_at")) >= int(time.time()) - 60

    # Endpoint down: the failure is recorded for the card, not thrown, and the
    # row upstream is untouched.
    endpoint.stop()
    _set_config(handle, "usage_stats_last_sent_at", int(time.time()) - 8 * 86400)
    _set_config(handle, "usage_stats_last_attempt_at", int(time.time()) - 8 * 86400)
    summary = handle.trigger_cron_json()
    assert str(summary["tasks"]["usage_stats"]).startswith("error: endpoint unreachable"), summary["tasks"]
    assert str(_config(handle, "usage_stats_last_error")).startswith("endpoint unreachable")
    # ...and is held for an hour rather than hammering a dead endpoint.
    summary = handle.trigger_cron_json()
    assert summary["tasks"]["usage_stats"] == "not_due", summary["tasks"]
    endpoint.start()
    assert len(endpoint.reports()) == 1


def test_rig_default_kill_switch_never_sends(request) -> None:
    """Without an explicit endpoint the fixtures' default is `disabled`: even an
    opted-in install reports nothing (and the card says so)."""
    handle = _boot(request, None)
    w, _ = _walk_to_analytics(handle)
    body = w.post(step="analytics", usage_stats="2")
    assert wizard_heading(body) == "Choose your installation type", wizard_heading(body)
    _finish_wizard(w)
    assert _config(handle, "usage_stats_enabled") is True
    assert _config(handle, "usage_stats_last_attempt_at") is None, "no send was even attempted"
    summary = handle.trigger_cron_json()
    assert summary["tasks"]["usage_stats"] == "disabled", summary["tasks"]


# ---------------------------------------------------------------------------
# admin card
# ---------------------------------------------------------------------------


def test_settings_card_toggle_level_and_preview(request, endpoint, browser) -> None:
    handle = _boot(request, endpoint)
    w, _ = _walk_to_analytics(handle)
    w.post(step="analytics", usage_stats="0")  # start from an explicit decline
    _finish_wizard(w)

    ctx = _login_ctx(browser, handle)
    try:
        page = ctx.new_page()
        page.goto(f"{handle.url}/admin/settings", wait_until="networkidle")
        expand_settings_cards(page)
        page.wait_for_selector("#card-usage-stats")
        page.wait_for_function(
            "() => document.getElementById('usage-stats-preview').textContent.includes('usage_report')"
        )

        toggle = page.locator("#usage-stats-enabled")
        slider = page.locator("#usage-stats-enabled + .toggle-slider")
        assert not toggle.is_checked(), "declined on the wizard -> off"
        status = page.locator("#usage-stats-status").inner_text()
        assert "Off" in status and "nothing is sent" in status, status

        # Preview follows the radio: level 1 shows no store_count, level 3 does.
        page.check(".usage-stats-level[value='1']")
        pre = page.locator("#usage-stats-preview").inner_text()
        p1 = json.loads(pre)
        assert p1["level"] == 1 and "store_count" not in p1["properties"]
        assert p1["install_id"] == "(random id, generated when you enable)"
        page.check(".usage-stats-level[value='3']")
        p3 = json.loads(page.locator("#usage-stats-preview").inner_text())
        assert p3["level"] == 3 and "settled_30d_mint_sats" in p3["properties"]
        assert STORE_NAME not in json.dumps(p3) and MINT_URL not in json.dumps(p3)
        # Nothing was saved or sent by merely browsing the previews.
        assert _config(handle, "usage_stats_enabled") is False
        assert endpoint.reports() == []

        # Enable with level 3: saved, first report sent at level 3.
        page.check(".usage-stats-level[value='2']")
        slider.click()
        page.wait_for_function(
            "() => document.getElementById('usage-stats-status').textContent.includes('Last sent')"
        )
        assert _config(handle, "usage_stats_enabled") is True
        assert _config(handle, "usage_stats_level") == 2
        reports = endpoint.wait_for_reports(1, timeout_s=5)
        assert reports[0]["level"] == 2
        install_id = reports[0]["install_id"]
        assert UUID_V4.match(install_id)
        status = page.locator("#usage-stats-status").inner_text()
        assert "every 7 days" in status and install_id in status, status
        # The preview now shows the real id.
        assert json.loads(page.locator("#usage-stats-preview").inner_text())["install_id"] == install_id

        # Changing the level while on re-sends at the new level (same row).
        page.check(".usage-stats-level[value='3']")
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            reps = endpoint.reports()
            if reps and reps[0]["level"] == 3:
                break
            time.sleep(0.3)
        reps = endpoint.reports()
        assert len(reps) == 1 and reps[0]["level"] == 3 and reps[0]["install_id"] == install_id
        assert _config(handle, "usage_stats_level") == 3

        # Survives a reload (state comes from the API, not the DOM).
        page.goto(f"{handle.url}/admin/settings", wait_until="networkidle")
        expand_settings_cards(page)
        page.wait_for_function(
            "() => document.getElementById('usage-stats-status').textContent.includes('Last sent')"
        )
        assert page.locator("#usage-stats-enabled").is_checked()
        assert page.locator(".usage-stats-level[value='3']").is_checked()

        # Off again: stored false, cron stops, the id and the upstream row stay.
        page.locator("#usage-stats-enabled + .toggle-slider").click()
        page.wait_for_function(
            "() => document.getElementById('usage-stats-status').textContent.startsWith('Off')"
        )
        assert _config(handle, "usage_stats_enabled") is False
        assert _config(handle, "usage_stats_install_id") == install_id
        _set_config(handle, "usage_stats_last_sent_at", int(time.time()) - 8 * 86400)
        summary = handle.trigger_cron_json()
        assert summary["tasks"]["usage_stats"] == "off", summary["tasks"]
        assert len(endpoint.reports()) == 1, "opting out deletes nothing server-side"
    finally:
        ctx.close()


def test_settings_api_refuses_a_bad_level_and_needs_admin(request, endpoint) -> None:
    handle = _boot(request, endpoint)
    w, _ = _walk_to_analytics(handle)
    w.post(step="analytics", usage_stats="0")
    _finish_wizard(w)

    import requests

    s = requests.Session()
    r = s.post(f"{handle.url}/admin", data={"action": "login", "username": "admin", "password": SetupWizard.DEFAULT_PASSWORD}, timeout=15)
    csrf = r.json()["csrfToken"]
    st = s.get(f"{handle.url}/admin?api=usage_stats_status", timeout=15).json()
    assert st["enabled"] is False and st["answered"] is True and st["level"] is None
    assert sorted(st["previews"]) == ["1", "2", "3"]
    assert st["endpoint"] == endpoint.url and st["disabled"] is False

    r = s.post(f"{handle.url}/admin", data={"action": "save_usage_stats", "enabled": "1", "csrf_token": csrf}, timeout=15)
    assert r.status_code == 400, r.text
    assert "Choose how much to share" in r.json()["error"]
    r = s.post(f"{handle.url}/admin", data={"action": "save_usage_stats", "enabled": "1", "level": "9", "csrf_token": csrf}, timeout=15)
    assert r.status_code == 400, r.text
    assert _config(handle, "usage_stats_enabled") is False, "a refused save writes nothing"
    assert endpoint.reports() == []

    r = s.post(f"{handle.url}/admin", data={"action": "save_usage_stats", "enabled": "1", "level": "1", "csrf_token": csrf}, timeout=15)
    assert r.status_code == 200, r.text
    d = r.json()
    assert d["success"] is True and d["sent"] is True and d["level"] == 1 and d["last_error"] is None
    assert endpoint.wait_for_reports(1, timeout_s=5)[0]["level"] == 1

    # Anonymous callers get neither the status nor the save.
    anon = requests.get(f"{handle.url}/admin?api=usage_stats_status", timeout=15, allow_redirects=False)
    assert anon.status_code in (302, 401, 403), anon.status_code
