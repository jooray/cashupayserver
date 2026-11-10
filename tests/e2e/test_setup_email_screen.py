"""Onboarding wizard: the e-mail notifications screen.

The screen collects SMTP credentials and ONLY advances once a real test
e-mail has gone out through them — a typo'd host or a refused login keeps the
operator on the screen with the server's reply, and nothing is saved. On
success it persists the SMTP settings, switches every notification kind on,
stores the notifications address, and sets the admin's password-reset
address (the same address by default, a separate one on request). Skip saves
nothing. Driven against a loopback SMTP sink (fixtures.smtp_sink), because
the point of the screen is the real round-trip.
"""
from __future__ import annotations

import sqlite3
from typing import Iterator

import pytest

from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import SetupWizard, wizard_error, wizard_heading
from fixtures.smtp_sink import SmtpSink, start_smtp_sink, stop_smtp_sink


@pytest.fixture()
def smtp_sink() -> Iterator[SmtpSink]:
    sink = start_smtp_sink()
    yield sink
    stop_smtp_sink(sink)


def _config(handle: PayserverHandle, key: str):
    import json

    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    finally:
        conn.close()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except (TypeError, ValueError):
        return row[0]


def _admin_email(handle: PayserverHandle):
    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    try:
        row = conn.execute("SELECT email FROM users WHERE username = 'admin'").fetchone()
    finally:
        conn.close()
    return row[0] if row else None


def _to_email_screen(handle: PayserverHandle) -> SetupWizard:
    w = SetupWizard(handle.url)
    w.accept_terms()
    w.decline_analytics()
    w.choose_install_type("other")
    w.post(step="security", security_acknowledged="1")
    body = w.post(
        step="password",
        password=SetupWizard.DEFAULT_PASSWORD,
        confirm_password=SetupWizard.DEFAULT_PASSWORD,
    )
    assert wizard_heading(body) == "E-mail notifications", wizard_heading(body)
    assert 'id="wizard-back-link"' in body, "the e-mail screen has a Back link"
    return w


def _smtp_fields(sink: SmtpSink, **overrides) -> dict:
    fields = dict(
        step="email",
        email_action="save",
        smtp_host=sink.host,
        smtp_port=str(sink.port),
        smtp_encryption="none",
        smtp_username=sink.username,
        smtp_password=sink.password,
        smtp_from_address="shop@example.com",
        smtp_from_name="Placeholder Shop",
        to_email="owner@example.com",
        reset_same="1",
    )
    fields.update(overrides)
    return fields


def test_password_screen_no_longer_asks_for_a_recovery_email(payserver: PayserverHandle) -> None:
    w = SetupWizard(payserver.url)
    w.accept_terms()
    w.decline_analytics()
    w.choose_install_type("other")
    body = w.post(step="security", security_acknowledged="1")
    assert wizard_heading(body) == "Create your admin password"
    assert 'name="admin_email"' not in body, "the recovery address moved to the e-mail screen"


def test_skip_saves_nothing_and_advances(payserver: PayserverHandle) -> None:
    w = _to_email_screen(payserver)
    body = w.post(step="email", email_action="skip")
    assert wizard_heading(body) == "Let's name your store", wizard_heading(body)
    assert _config(payserver, "smtp_host") is None
    assert _config(payserver, "notifications_enabled") is None
    assert _admin_email(payserver) is None


def test_validation_keeps_the_operator_on_the_screen(payserver: PayserverHandle, smtp_sink: SmtpSink) -> None:
    w = _to_email_screen(payserver)

    body = w.post(**_smtp_fields(smtp_sink, smtp_host=""))
    assert "hostname" in (wizard_error(body) or ""), wizard_error(body)
    assert wizard_heading(body) == "E-mail notifications"

    body = w.post(**_smtp_fields(smtp_sink, smtp_from_address=""))
    assert "From address" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(**_smtp_fields(smtp_sink, to_email=""))
    assert "notifications should be sent to" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(**_smtp_fields(smtp_sink, to_email="not-an-address"))
    assert wizard_error(body) is not None
    assert wizard_heading(body) == "E-mail notifications"

    body = w.post(**_smtp_fields(smtp_sink, reset_same="0", reset_email=""))
    assert "password-reset" in (wizard_error(body) or ""), wizard_error(body)

    body = w.post(**_smtp_fields(smtp_sink, smtp_port="99999"))
    assert "port" in (wizard_error(body) or "").lower(), wizard_error(body)

    # Nothing reached the sink and nothing was saved by any rejected attempt.
    assert smtp_sink.messages == []
    assert _config(payserver, "smtp_host") is None
    assert _config(payserver, "notifications_enabled") is None
    # The re-rendered form keeps the typed values (never the password).
    assert 'value="shop@example.com"' in body
    assert smtp_sink.password not in body


def test_failed_delivery_does_not_advance_or_save(payserver: PayserverHandle, smtp_sink: SmtpSink) -> None:
    w = _to_email_screen(payserver)

    # Wrong password: the sink refuses AUTH, PHPMailer reports it, the screen
    # stays and nothing is persisted.
    body = w.post(**_smtp_fields(smtp_sink, smtp_password="wrong-password"))
    err = wizard_error(body) or ""
    assert "couldn't send a test e-mail" in err, err
    assert wizard_heading(body) == "E-mail notifications"
    assert smtp_sink.auth_attempts and smtp_sink.messages == []
    assert _config(payserver, "smtp_host") is None
    assert _config(payserver, "notifications_enabled") is None
    assert _config(payserver, "notifications_to_email") is None
    assert _admin_email(payserver) is None

    # Unreachable host: same outcome.
    body = w.post(**_smtp_fields(smtp_sink, smtp_host="smtp.invalid"))
    assert "couldn't send a test e-mail" in (wizard_error(body) or ""), wizard_error(body)
    assert _config(payserver, "smtp_host") is None


def test_successful_test_email_saves_everything_and_advances(
    payserver: PayserverHandle, smtp_sink: SmtpSink
) -> None:
    w = _to_email_screen(payserver)
    body = w.post(**_smtp_fields(smtp_sink))
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Let's name your store", wizard_heading(body)

    msgs = smtp_sink.wait_for_messages(1)
    assert len(msgs) == 1, "exactly one test e-mail went out"
    assert msgs[0].rcpt_to == ["owner@example.com"]
    assert msgs[0].mail_from == "shop@example.com"
    assert "test" in msgs[0].subject.lower()

    assert _config(payserver, "smtp_host") == smtp_sink.host
    assert str(_config(payserver, "smtp_port")) == str(smtp_sink.port)
    assert _config(payserver, "smtp_encryption") == "none"
    assert _config(payserver, "smtp_username") == smtp_sink.username
    assert _config(payserver, "smtp_password") == smtp_sink.password
    assert _config(payserver, "smtp_from_address") == "shop@example.com"
    assert _config(payserver, "smtp_from_name") == "Placeholder Shop"
    assert _config(payserver, "notifications_to_email") == "owner@example.com"
    for key in (
        "notifications_enabled",
        "notifications_invoice_paid_enabled",
        "notifications_auto_cashout_enabled",
        "notifications_update_available_enabled",
    ):
        assert _config(payserver, key) is True, key
    # Same address for password resets by default.
    assert _admin_email(payserver) == "owner@example.com"

    # Revisiting via Back prefills everything but the password, which shows
    # as saved; a blank password keeps the stored one on the next send.
    body = w.get("email")
    assert f'value="{smtp_sink.host}"' in body
    assert 'value="owner@example.com"' in body
    assert smtp_sink.password not in body
    assert "A password is saved" in body
    body = w.post(**_smtp_fields(smtp_sink, smtp_password=""))
    assert wizard_error(body) is None, wizard_error(body)
    assert len(smtp_sink.wait_for_messages(2)) == 2, "the stored password carried the second test send"


def test_separate_password_reset_address(payserver: PayserverHandle, smtp_sink: SmtpSink) -> None:
    w = _to_email_screen(payserver)
    body = w.post(**_smtp_fields(smtp_sink, reset_same="0", reset_email="admin@example.com"))
    assert wizard_error(body) is None, wizard_error(body)
    assert _config(payserver, "notifications_to_email") == "owner@example.com"
    assert _admin_email(payserver) == "admin@example.com"
    # The test e-mail goes to the notifications address, not the reset one.
    assert smtp_sink.wait_for_messages(1)[0].rcpt_to == ["owner@example.com"]
