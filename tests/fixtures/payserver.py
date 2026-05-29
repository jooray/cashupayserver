"""CashuPayServer fixture: php -S router.php on an isolated data dir.

Uses PHP's `auto_prepend_file` flag to inject a bootstrap that reads
`CASHUPAY_DATA_DIR` from the environment. This avoids modifying any
checked-in PHP source.
"""
from __future__ import annotations

import os
import shutil
import signal
import sqlite3
import subprocess
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator

import requests

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent  # /home/user/payserver
TESTS_DIR = REPO_ROOT / "tests"
TMP_DIR = TESTS_DIR / ".tmp"

# php -S's router script does NOT honor auto_prepend_file, so we wrap router.php
# in a tiny entry script. The wrapper:
#   1. defines CASHUPAY_DATA_DIR from $_ENV / getenv (per-test isolation)
#   2. requires the real router.php
#   3. propagates the router's return value (router.php returns false to let
#      php -S serve static files; require returns whatever the included file does)
ROUTER_WRAPPER_TEMPLATE = """<?php
$dataDir = getenv('CASHUPAY_DATA_DIR');
if ($dataDir !== false && $dataDir !== '' && !defined('CASHUPAY_DATA_DIR')) {{
    define('CASHUPAY_DATA_DIR', $dataDir);
}}
return require {router_path!r};
"""


@dataclass
class PayserverHandle:
    process: subprocess.Popen[bytes]
    port: int
    data_dir: Path
    workdir: Path

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def wait_ready(self, timeout_s: float = 30.0) -> None:
        """Any HTTP response means the PHP server is up. Pre-setup the API
        returns 503 ('setup not complete') which is still 'ready'."""
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                requests.get(f"{self.url}/api/v1/server/info", timeout=2, allow_redirects=False)
                return
            except requests.RequestException as e:
                last = e
            time.sleep(0.2)
        raise TimeoutError(f"payserver not ready after {timeout_s}s ({last})")

    def session(self) -> requests.Session:
        return requests.Session()

    @property
    def db_path(self) -> Path:
        return self.data_dir / "cashupay.sqlite"

    @contextmanager
    def db(self) -> Iterator[sqlite3.Connection]:
        """Open the SQLite DB for direct inspection or test-only mutation.
        Use sparingly — most assertions should go through the HTTP API."""
        conn = sqlite3.connect(self.db_path, isolation_level=None)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()

    def trigger_cron(self, *, internal_key: str | None = None) -> requests.Response:
        """Hit cron.php. If `internal_key` is None we rely on the install
        having no `cron_key` set, which is true for the test wizard."""
        if internal_key is not None:
            params = {"internal": "1", "key": internal_key}
        else:
            params = {}
        return requests.get(f"{self.url}/cron.php", params=params, timeout=30)


def _ensure_php() -> str:
    return str(binaries.ensure(binaries.PHP)["php"])


def start_payserver(workdir: Path, *, extra_env: dict[str, str] | None = None) -> PayserverHandle:
    php = _ensure_php()
    workdir.mkdir(parents=True, exist_ok=True)
    data_dir = workdir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)

    # includes/security.php caches rate-limit + lockout counters at
    # <repo-root>/data/cache/ irrespective of CASHUPAY_DATA_DIR. Without this
    # wipe, state (especially login lockouts) bleeds across tests.
    shared_cache = REPO_ROOT / "data" / "cache"
    if shared_cache.exists():
        shutil.rmtree(shared_cache, ignore_errors=True)

    router_wrapper = workdir / "router-wrapper.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(router_path=str(REPO_ROOT / "router.php"))
    )

    port = ports.allocate(1)[0]

    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    if extra_env:
        env.update(extra_env)

    log = (workdir / "php-server.log").open("ab")
    proc = subprocess.Popen(
        [
            php,
            "-S", f"127.0.0.1:{port}",
            "-t", str(REPO_ROOT),
            str(router_wrapper),
        ],
        env=env,
        cwd=str(REPO_ROOT),
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = PayserverHandle(process=proc, port=port, data_dir=data_dir, workdir=workdir)
    try:
        handle.wait_ready()
    except Exception:
        stop_payserver(handle)
        raise
    return handle


def stop_payserver(handle: PayserverHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()
