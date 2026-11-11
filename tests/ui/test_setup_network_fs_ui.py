"""Data directory on a network filesystem: the wizard's safety check holds
with the warning and the admin dashboard keeps a dismissible banner.

A real NFS/SMB share is not available to the rig, so the Linux mount table
is substituted through the CASHUPAY_MOUNTS_FILE seam
(Database::detectNetworkMount): a table that describes the data directory's
own path as an nfs4 mount. The data directory sits OUTSIDE the webroot on
purpose — that is the case where the safety screen would otherwise be
skipped entirely, so its appearance here proves the network-filesystem
check gates the screen on its own.
"""
from __future__ import annotations

import shutil
import sqlite3
import tempfile
import time
import uuid
from pathlib import Path

import pytest

from conftest import SESSION_TMP
from fixtures.browser import wait_for_dashboard_settled
from fixtures.nutshell import MintHandle
from fixtures.payserver import start_payserver, stop_payserver
from fixtures.setup_helpers import run_setup_wizard

pytestmark = pytest.mark.ui


def _config_value(db_path: Path, key: str) -> str | None:
    conn = sqlite3.connect(db_path)
    try:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def test_network_fs_warning_holds_wizard_and_shows_dashboard_banner(
    mint: MintHandle, backup_mint: MintHandle, page
) -> None:
    outside = Path(tempfile.mkdtemp(prefix="cashupay-data-")).resolve()
    # Served from inside the data dir so container backends (which bind the
    # data dir at the identical path) can read it too.
    mounts = outside / "mounts.txt"
    mounts.write_text(
        "/dev/sda1 / ext4 rw,relatime 0 0\n"
        f"nas:/export {outside} nfs4 rw,vers=4.2 0 0\n"
    )
    workdir = SESSION_TMP / f"payserver-netfs-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHUPAY_DATA_DIR": str(outside), "CASHUPAY_MOUNTS_FILE": str(mounts)},
    )
    try:
        base = handle.url
        page.set_default_timeout(30000)

        # terms → analytics (decline) → installation type → safety check.
        page.goto(f"{base}/setup")
        page.check("#terms_legal")
        page.check("#terms_warranty")
        page.check("#terms_fee")
        page.click("button[type=submit]")
        page.wait_for_selector("button[name='usage_stats'][value='0']")
        page.click("button[name='usage_stats'][value='0']")
        page.wait_for_selector("#install-option-own_wallet")
        page.click("#install-option-own_wallet > summary")
        page.click("button[name='install_type'][value='own_wallet']")

        # Outside the webroot the screen would normally be skipped; the
        # network-filesystem detection alone brings it back, with evidence.
        warning = page.locator("#network-fs-warning")
        warning.wait_for(state="visible")
        text = warning.inner_text()
        assert "nfs4" in text, text
        assert str(outside) in text, text

        # The auto-advance must stay off: both probes finish well inside
        # this window, and the password screen must still not have appeared.
        page.wait_for_timeout(7000)
        assert page.locator("#password").count() == 0, "safety screen auto-advanced despite the warning"
        assert warning.is_visible()

        # "Continue anyway": acknowledge + Continue moves on as before.
        page.check("#security_acknowledged")
        page.click("#security-form button[type=submit]")
        page.wait_for_selector("#password")

        # Finish the install over HTTP (the remaining screens are covered by
        # the wizard suites) and look at the dashboard.
        result = run_setup_wizard(base, mint_url=mint.url, backup_mint_url=backup_mint.url)

        page.goto(f"{base}/admin")
        page.fill("#password-input", result.admin_password)
        page.click("#password-submit")
        wait_for_dashboard_settled(page)

        banner = page.locator("#network-fs-banner")
        banner.wait_for(state="visible")
        banner_text = banner.inner_text()
        assert "nfs4" in banner_text, banner_text
        assert str(outside) in banner_text, banner_text

        # Capture the dismissal POST so a persistence failure names the
        # server's answer rather than just "value missing". The body is read
        # by the page itself, not here: fetching an unconsumed fetch() body
        # through Playwright can block indefinitely.
        with page.expect_response(
            lambda r: r.request.method == "POST" and "admin" in r.url
            and "dismiss_network_fs_warning" in (r.request.post_data or ""),
            timeout=15000,
        ) as resp_info:
            page.click("#btn-dismiss-network-fs")
        resp = resp_info.value
        assert resp.status == 200, f"dismiss POST -> {resp.status}"
        banner.wait_for(state="hidden")

        # The dismissal is persisted against the detected evidence and
        # survives a reload. The banner hides before the POST completes, so
        # give the write a moment to land.
        db_path = outside / "cashupay.sqlite"
        dismissed = None
        for _ in range(40):
            dismissed = _config_value(db_path, "network_fs_warning_dismissed")
            if dismissed is not None:
                break
            time.sleep(0.25)
        assert dismissed == f"mount|nfs4|{outside}|wal", (
            f"dismissal not persisted: config={dismissed!r}; POST answered HTTP {resp.status}"
        )

        page.reload()
        wait_for_dashboard_settled(page)
        page.wait_for_timeout(1000)
        assert banner.is_hidden(), "dismissed banner came back after reload"
    finally:
        stop_payserver(handle)
        shutil.rmtree(outside, ignore_errors=True)
        shutil.rmtree(workdir, ignore_errors=True)
