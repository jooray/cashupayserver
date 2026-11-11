"""CashuPayServer fixture: the app served on an isolated data dir.

The serving mechanism is selected by CASHUPAY_TEST_BACKEND (see
tests/fixtures/webserver.py): php -S with a router wrapper (default), or real
Apache / nginx containers. This module owns what is payserver-specific — the
data dir, env, router wrapper, and the PayserverHandle API — and delegates the
"run an HTTP server for this docroot" part to webserver.start_php_site().
"""
from __future__ import annotations

import json
import os
import shutil
import sqlite3
import subprocess
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterator

import requests

from . import binaries, ports, webserver

REPO_ROOT = Path(__file__).resolve().parent.parent.parent  # /home/user/payserver
# The application tree (what a release zip extracts to) lives under app/;
# it is the served docroot. REPO_ROOT stays the container bind-mount root.
APP_ROOT = REPO_ROOT / "app"
TESTS_DIR = REPO_ROOT / "tests"
TMP_DIR = APP_ROOT / ".tmp"  # see conftest.SESSION_TMP: inside the served tree on purpose

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
    server: webserver.ManagedServer
    port: int
    data_dir: Path
    workdir: Path

    @property
    def process(self) -> subprocess.Popen[bytes] | None:
        """Back-compat accessor: the php -S Popen, None on container backends."""
        return self.server.popen

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
        """Hit cron.php. With `internal_key`, performs an internal self-request
        auth check; without it, reads the seeded `cron_key` from the DB
        (Database::initialize seeds it on install) and sends it as ?key=."""
        if internal_key is not None:
            params = {"internal": "1", "key": internal_key}
        else:
            with self.db() as conn:
                row = conn.execute(
                    "SELECT value FROM config WHERE key = 'cron_key'"
                ).fetchone()
            if not row:
                raise RuntimeError(
                    "cron_key not seeded — Database::initialize should set it"
                )
            raw = row["value"]
            try:
                key = json.loads(raw)
                if not isinstance(key, str):
                    key = raw
            except (json.JSONDecodeError, ValueError):
                key = raw
            params = {"key": key}
        return requests.get(f"{self.url}/cron.php", params=params, timeout=30)

    def trigger_cron_json(self, **kwargs: Any) -> dict:
        """trigger_cron + tolerant parse of the cron summary body. Cron may
        emit non-JSON preamble lines (e.g. a Donation::send HTTP warning)
        before its JSON payload, so fall back to the first '{' in the body."""
        resp = self.trigger_cron(**kwargs)
        try:
            body = resp.json()
        except ValueError:
            text = resp.text
            idx = text.find("{")
            try:
                body = json.loads(text[idx:]) if idx >= 0 else {}
            except ValueError:
                body = {}
        return body if isinstance(body, dict) else {}

    def drive_cron_until(
        self,
        predicate,
        *,
        timeout_s: float = 30.0,
        interval_s: float = 1.0,
        label: str = "",
    ) -> None:
        """Repeatedly trigger_cron() until `predicate()` is truthy.

        Correct on every backend: under single-worker php -S only our explicit
        triggers run cron, while under Apache/nginx the app's opportunistic
        background self-requests may have done (or be doing) the work already —
        either way the observable outcome is what gets asserted, not which
        cron run performed it.
        """
        deadline = time.monotonic() + timeout_s
        while True:
            self.trigger_cron()
            if predicate():
                return
            if time.monotonic() >= deadline:
                raise TimeoutError(
                    f"condition {label or predicate!r} not reached within "
                    f"{timeout_s}s of driving cron"
                )
            time.sleep(interval_s)


IPGEO_FILENAME = "dbip-country-lite.csv.gz"  # IpGeo::CSV_FILENAME
_IPGEO_CACHE = binaries.DOWNLOAD_CACHE / IPGEO_FILENAME
_IPGEO_MAX_AGE_S = 20 * 24 * 3600


def _seed_ipgeo_database(data_dir: Path) -> None:
    """Copy a cached DB-IP country CSV into a fresh data dir (best effort).

    The cache is filled once per machine from the same monthly URLs
    includes/ipgeo.php uses; any failure leaves the server to download it
    itself, exactly as before."""
    try:
        if not _IPGEO_CACHE.is_file() or time.time() - _IPGEO_CACHE.stat().st_mtime > _IPGEO_MAX_AGE_S:
            _IPGEO_CACHE.parent.mkdir(parents=True, exist_ok=True)
            tmp = _IPGEO_CACHE.with_suffix(".gz.tmp")
            for when in (time.gmtime(), time.gmtime(time.time() - 32 * 24 * 3600)):
                url = f"https://download.db-ip.com/free/dbip-country-lite-{time.strftime('%Y-%m', when)}.csv.gz"
                try:
                    r = requests.get(url, timeout=60)
                except requests.RequestException:
                    continue
                if r.status_code == 200 and len(r.content) > 200 * 1024:
                    tmp.write_bytes(r.content)
                    tmp.replace(_IPGEO_CACHE)
                    break
        if _IPGEO_CACHE.is_file():
            # copy (not copy2): a fresh mtime is what the cron's staleness
            # check reads.
            shutil.copy(_IPGEO_CACHE, data_dir / IPGEO_FILENAME)
    except Exception:  # noqa: BLE001 - never fail a test server boot over this
        pass


def _ensure_php() -> str:
    return str(binaries.ensure(binaries.PHP)["php"])


def start_payserver(
    workdir: Path,
    *,
    extra_env: dict[str, str] | None = None,
    extra_php_args: list[str] | None = None,
) -> PayserverHandle:
    php = _ensure_php()
    workdir.mkdir(parents=True, exist_ok=True)
    data_dir = workdir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)

    # includes/security.php caches rate-limit + lockout counters at
    # <app-root>/data/cache/ irrespective of CASHUPAY_DATA_DIR. Without this
    # wipe, state (especially login lockouts) bleeds across tests.
    shared_cache = APP_ROOT / "data" / "cache"
    if shared_cache.exists():
        shutil.rmtree(shared_cache, ignore_errors=True)

    # Seed the IP-geo database so the first cron pass (self-triggered by the
    # fixture's login) does not spend its single PHP worker on a multi-MB
    # download from db-ip.com — on a slow link that held the worker long
    # enough for the next fixture request to time out, and re-rendered admin
    # views under a UI test's feet. One download per machine, refreshed when
    # older than the server's own 25-day staleness rule.
    _seed_ipgeo_database(data_dir)

    # The wrapper only matters on the phps backend; the container backends
    # deliver CASHUPAY_DATA_DIR via an auto_prepend_file generated by
    # webserver.start_php_site (php -S ignores auto_prepend_file, hence the
    # wrapper in the first place).
    router_wrapper = workdir / "router-wrapper.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(router_path=str(APP_ROOT / "router.php"))
    )

    port = ports.allocate(1)[0]

    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    # Kill the auto-updater for the entire test run. Without this, a long-
    # running stack will eventually overlay the live working tree with the
    # latest channel-main build and undo any in-progress dev edits. Honoured
    # by includes/updater.php::isDisabledForTests(). We also drop a sentinel
    # file inside the data dir as belt-and-suspenders — an external curl to
    # /cron.php (a real cron set up by the operator, a stray browser tab,
    # etc.) won't inherit the env var, but it WILL see the sentinel.
    env.setdefault("CASHUPAY_UPDATER_DISABLED", "1")
    (data_dir / ".updater_disabled").write_text(
        "auto-updates disabled by tests/fixtures/payserver.py\n"
    )
    # Never ask the LIVE BareBits endpoint for a dev-fee address branch from a
    # test install (includes/fee_branch.php). Fee-branch tests override this
    # with the local xpub.php fixture via fixtures.xpub_endpoint.fee_branch_env.
    env.setdefault("CASHUPAY_DEV_FEE_BRANCH_ENDPOINT", "disabled")
    env.setdefault("CASHUPAY_USAGE_STATS_ENDPOINT", "disabled")
    # SafeHttp blocks loopback/RFC1918 destinations by default to defend
    # against tenant-supplied SSRF (webhook URLs, LNURL callbacks, etc.).
    # Every test sink — webhook receiver, LNURL mock, Esplora, the wp test
    # site — runs on 127.0.0.1, so let the test rig opt in.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    if extra_env:
        env.update(extra_env)

    # A pinned worker count applies on every backend: PHP_CLI_SERVER_WORKERS
    # under php -S, prefork MaxRequestWorkers under Apache, pm.max_children
    # under FPM. Unset means each backend's default (php -S: single worker).
    workers_env = env.get("PHP_CLI_SERVER_WORKERS")
    workers = int(workers_env) if workers_env else None

    # extra_env may override CASHUPAY_DATA_DIR (the outside-webroot tests point
    # it at a host tempdir); the define and, on container backends, an extra
    # identical-path bind mount must follow the EFFECTIVE value — the repo
    # mount only covers dirs under REPO_ROOT.
    effective_data_dir = Path(env["CASHUPAY_DATA_DIR"])
    extra_bind_dirs: tuple[Path, ...] = ()
    if not effective_data_dir.is_relative_to(REPO_ROOT):
        effective_data_dir.mkdir(parents=True, exist_ok=True)
        extra_bind_dirs = (effective_data_dir,)

    server = webserver.start_php_site(
        port=port,
        docroot=APP_ROOT,
        workdir=workdir,
        env=env,
        role="payserver",
        log_path=workdir / "php-server.log",
        prepend_defines={"CASHUPAY_DATA_DIR": str(effective_data_dir)},
        extra_bind_dirs=extra_bind_dirs,
        # e.g. ["-d", "disable_functions=gmp_init,…"] to simulate a shared
        # host whose PHP lacks an extension the bundled binary has.
        ini_overrides=webserver.parse_extra_php_args(extra_php_args),
        workers=workers,
        phps_router=router_wrapper,
        phps_cwd=APP_ROOT,
        phps_binary=php,
    )

    handle = PayserverHandle(server=server, port=port, data_dir=data_dir, workdir=workdir)
    try:
        handle.wait_ready()
    except Exception:
        stop_payserver(handle)
        raise
    return handle


def stop_payserver(handle: PayserverHandle) -> None:
    handle.server.stop(grace_s=10.0)
