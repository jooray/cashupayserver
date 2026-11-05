"""Browser e2e for the diagnostic report export (bottom of site settings).

Covers the UI wiring + endpoint end-to-end:
  - The Diagnostic Report card renders at the bottom of site settings.
  - "Anonymize data" is checked by default; the de-anon warning is hidden
    until the box is unchecked.
  - "Export all" downloads an anonymized JSON report (meta.anonymized == true,
    range == "all", expected sections present).
  - Unchecking the box + "Export past 30 days" downloads a de-anonymized,
    30-day report (meta.anonymized == false, range == "1m").
"""
from __future__ import annotations

import json

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards


@pytest.fixture
def admin_page(shared_server: ConfiguredPayserver, browser):
    """A logged-in admin browser page with downloads enabled.

    The diagnostics export is read-only (nothing is saved server-side), so
    these tests run against the module-scoped shared server directly."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900}, accept_downloads=True)
    ctx.request.post(
        f"{shared_server.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": shared_server.admin_password},
    )
    page = ctx.new_page()
    yield page, shared_server.handle.url
    ctx.close()


def _goto_settings(page, base):
    page.goto(f"{base}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1000)


def _read_download(download) -> dict:
    path = download.path()
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def test_diagnostics_card_present_and_default_anonymized(admin_page):
    page, base = admin_page
    _goto_settings(page, base)

    assert page.evaluate("!!document.getElementById('card-diagnostics')") is True
    # Card is the last settings card (just above the footer).
    titles = page.eval_on_selector_all(
        "#view-settings .card .card-title",
        "els => els.map(e => e.textContent.trim())",
    )
    assert titles[-1] == "Diagnostic Report"

    assert page.is_checked("#diagnostics-anonymize") is True
    assert page.is_hidden("#diagnostics-deanon-warning") is True


def test_export_all_is_anonymized(admin_page):
    page, base = admin_page
    _goto_settings(page, base)

    with page.expect_download() as dl_info:
        page.click("#btn-export-diagnostics-all")
    download = dl_info.value

    assert "anon" in download.suggested_filename
    assert download.suggested_filename.endswith(".json")

    report = _read_download(download)
    assert report["meta"]["anonymized"] is True
    assert report["meta"]["range"] == "all"
    for section in ("meta", "system", "mint_reliability", "aggregates",
                    "mint_event_log", "notification_failures",
                    "invoices", "invoice_items", "melts"):
        assert section in report, f"missing section: {section}"
    # Secrets never travel in the config blob.
    assert "cron_key" not in report["system"]["config"]


def test_export_30d_deanonymized_when_unchecked(admin_page):
    page, base = admin_page
    _goto_settings(page, base)

    page.uncheck("#diagnostics-anonymize")
    assert page.is_visible("#diagnostics-deanon-warning") is True

    with page.expect_download() as dl_info:
        page.click("#btn-export-diagnostics-30d")
    download = dl_info.value

    assert "full" in download.suggested_filename
    assert "30d" in download.suggested_filename

    report = _read_download(download)
    assert report["meta"]["anonymized"] is False
    assert report["meta"]["range"] == "1m"
    # Even a full dump never carries server secrets.
    assert "cron_key" not in report["system"]["config"]
