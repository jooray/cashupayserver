"""scripts/desktop-smoke.php — the Windows smoke's functional driver.

On Windows CI the driver walks the packaged app's whole HTTP surface
(onboarding wizard in its desktop shape, admin session, API key, Greenfield
API). That run only happens when a release is cut or a PR touches the
package, so this test runs the very same driver against a Linux desktop-mode
instance on every ordinary suite run — a drifting wizard screen, admin
action, or API shape breaks here first, not on a Windows runner mid-release.
"""
from __future__ import annotations

import subprocess
import uuid
from pathlib import Path
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures import binaries
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
DRIVER = REPO_ROOT / "scripts" / "desktop-smoke.php"


@pytest.fixture()
def desktop_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-smokedrv-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env={"CASHUPAY_DESKTOP": "1"})
    yield handle
    stop_payserver(handle)


def _run_driver(url: str, *args: str) -> None:
    php = str(binaries.ensure(binaries.PHP)["php"])
    result = subprocess.run(
        [php, str(DRIVER), url, *args],
        capture_output=True,
        text=True,
        timeout=180,
    )
    assert result.returncode == 0, (
        f"desktop-smoke.php failed (rc={result.returncode})\n"
        f"--- stdout ---\n{result.stdout}\n--- stderr ---\n{result.stderr}"
    )
    # The driver prints one "ok - ..." line per stage; all five must have run.
    assert result.stdout.count("ok - ") == 5, result.stdout
    assert "all checks passed" in result.stdout


def test_driver_passes_against_a_desktop_mode_instance(
    desktop_payserver: PayserverHandle,
) -> None:
    _run_driver(desktop_payserver.url)


def test_driver_server_shape_passes_against_a_plain_instance(
    payserver: PayserverHandle,
) -> None:
    """The `server` shape drives the ordinary (non-desktop) wizard — security
    screen, "of 13" counter, cron screen with a crontab line. The webserver
    smoke workflow runs this same invocation against real Apache and nginx."""
    _run_driver(payserver.url, "server")
