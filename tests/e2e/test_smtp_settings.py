"""Browser e2e for UI-editable SMTP settings (global + per-store override).

Covers the wiring end-to-end against the real admin handlers:
  - The global SMTP fields render inside the Email Notifications card.
  - Saving global SMTP persists; the password is write-only (never returned,
    field clears on reload, help reflects that one is saved).
  - Server-side validation rejects a bad port / encryption / From address.
  - A per-store SMTP override round-trips through save_store_notifications +
    the dashboard read, including password preserve-on-blank and explicit clear.

Actual SMTP delivery is intentionally NOT asserted: the e2e stack has no MTA or
SMTP server, so a real "send test" can't be verified here. Delivery transport
selection is covered by the PHP unit tests (test_email_sender_resolution.py /
test_email_sender_smtp_vs_mail.php).
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.browser import expand_settings_cards


@pytest.fixture
def admin_ctx(configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page plus the base URL and seeded store id."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, configured.handle.url, configured.store_id
    ctx.close()


def _goto_settings(page, base):
    page.goto(f"{base}/admin/settings", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(800)


def _post_action(page, body: str) -> dict:
    """Fire an admin POST action through the page's real CSRF helper."""
    return page.evaluate(
        """async (body) => {
            const r = await postWithCsrf(adminUrl, body);
            let parsed = null;
            try { parsed = await r.json(); } catch (e) { parsed = null; }
            return { status: r.status, body: parsed };
        }""",
        body,
    )


def _get_dashboard(page, store_id: str) -> dict:
    return page.evaluate(
        """async (sid) => {
            const r = await fetch(adminUrl + '?api=dashboard&store_id=' + encodeURIComponent(sid),
                                  { credentials: 'include' });
            return await r.json();
        }""",
        store_id,
    )


def test_global_smtp_fields_present(admin_ctx):
    page, base, _ = admin_ctx
    _goto_settings(page, base)

    for el in ("smtp-host", "smtp-port", "smtp-encryption", "smtp-username",
               "smtp-password", "smtp-from-address", "smtp-from-name"):
        assert page.evaluate(f"!!document.getElementById('{el}')") is True, f"missing #{el}"
    # The SMTP fields live inside the notifications card.
    assert page.evaluate(
        "document.getElementById('card-notifications').contains(document.getElementById('smtp-host'))"
    ) is True


def test_global_smtp_persists_and_password_is_write_only(admin_ctx):
    page, base, _ = admin_ctx
    _goto_settings(page, base)

    page.fill("#smtp-host", "smtp.example.com")
    page.fill("#smtp-port", "2525")
    page.fill("#smtp-username", "mailer")
    page.fill("#smtp-password", "s3cret")
    page.select_option("#smtp-encryption", "ssl")
    page.fill("#smtp-from-address", "noreply@example.com")
    page.fill("#smtp-from-name", "Shop Mailer")
    page.click("#btn-save-notifications")
    page.wait_for_timeout(600)

    # Reload and confirm the non-secret fields round-tripped.
    _goto_settings(page, base)
    assert page.input_value("#smtp-host") == "smtp.example.com"
    assert page.input_value("#smtp-port") == "2525"
    assert page.input_value("#smtp-username") == "mailer"
    assert page.input_value("#smtp-encryption") == "ssl"
    assert page.input_value("#smtp-from-address") == "noreply@example.com"
    assert page.input_value("#smtp-from-name") == "Shop Mailer"
    # Password field never repopulates; help text says one is stored.
    assert page.input_value("#smtp-password") == ""
    assert "saved" in page.text_content("#smtp-password-help").lower()

    # The settings endpoint exposes only that a password is set, never its value.
    res = _post_action(page, "action=get_notifications_settings")
    assert res["status"] == 200
    data = res["body"]
    assert data["smtpHost"] == "smtp.example.com"
    assert data["smtpPasswordSet"] is True
    assert "smtpPassword" not in data and "password" not in data


def test_global_smtp_validation_rejects_bad_input(admin_ctx):
    page, base, _ = admin_ctx
    _goto_settings(page, base)

    bad_port = _post_action(page, "action=save_notifications_settings&smtp_port=abc")
    assert bad_port["status"] == 400
    assert "port" in (bad_port["body"]["error"]).lower()

    bad_enc = _post_action(page, "action=save_notifications_settings&smtp_encryption=bogus")
    assert bad_enc["status"] == 400
    assert "encryption" in (bad_enc["body"]["error"]).lower()

    bad_from = _post_action(page, "action=save_notifications_settings&smtp_from_address=notanemail")
    assert bad_from["status"] == 400
    assert "from" in (bad_from["body"]["error"]).lower()


def test_per_store_override_round_trips(admin_ctx):
    page, base, store_id = admin_ctx
    _goto_settings(page, base)  # any admin page; we drive the store API directly

    # Save an enabled override with a password.
    save = _post_action(
        page,
        "action=save_store_notifications"
        f"&store_id={store_id}"
        "&enabled=1"
        "&email=ops@store.example.com"
        "&smtp_override_enabled=1"
        "&smtp_host=store-smtp.example.com"
        "&smtp_port=465"
        "&smtp_encryption=ssl"
        "&smtp_username=storeuser"
        "&smtp_password=storepass"
        "&smtp_from_address=from@store.example.com"
        "&smtp_from_name=Store+Mailer",
    )
    assert save["status"] == 200, save

    notif = _get_dashboard(page, store_id)["notifications"]
    assert notif["smtpOverrideEnabled"] is True
    assert notif["smtpHost"] == "store-smtp.example.com"
    assert notif["smtpPort"] == "465"
    assert notif["smtpEncryption"] == "ssl"
    assert notif["smtpFromAddress"] == "from@store.example.com"
    assert notif["smtpPasswordSet"] is True
    assert "smtpPassword" not in notif  # secret never leaves the server

    # Re-save with a blank password and no clear flag: password is preserved.
    _post_action(
        page,
        "action=save_store_notifications"
        f"&store_id={store_id}&enabled=1&smtp_override_enabled=1"
        "&smtp_host=store-smtp.example.com&smtp_password=",
    )
    assert _get_dashboard(page, store_id)["notifications"]["smtpPasswordSet"] is True

    # Explicit clear wipes it.
    _post_action(
        page,
        "action=save_store_notifications"
        f"&store_id={store_id}&enabled=1&smtp_override_enabled=1"
        "&smtp_host=store-smtp.example.com&smtp_password=&smtp_password_clear=1",
    )
    assert _get_dashboard(page, store_id)["notifications"]["smtpPasswordSet"] is False


def test_per_store_override_validation(admin_ctx):
    page, base, store_id = admin_ctx
    _goto_settings(page, base)

    res = _post_action(
        page,
        f"action=save_store_notifications&store_id={store_id}&enabled=1&smtp_port=99999",
    )
    assert res["status"] == 400
    assert "port" in (res["body"]["error"]).lower()
