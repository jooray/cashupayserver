"""WordPress fixture for testing the CashuPay plugin.

Uses wp-cli + the static PHP binary to stand up a fresh SQLite-backed WP
install per test, with the plugin tree symlinked from the repo so any
local change is exercised. Same `php -S` mechanic as the standalone
payserver fixture — front controller through a tiny wrapper that
defines CASHUPAY_DATA_DIR from the env.
"""
from __future__ import annotations

import os
import shutil
import signal
import sqlite3
import subprocess
import time
import urllib.request
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import requests

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
TESTS_DIR = REPO_ROOT / "tests"
BIN_DIR = TESTS_DIR / "bin"

WP_VERSION = "6.6.2"
WP_TARBALL_URL = f"https://wordpress.org/wordpress-{WP_VERSION}.tar.gz"
WP_TARBALL_SHA256 = ""  # populated lazily via wp.org checksums; see _wp_core_path
WP_TARBALL_CACHE = BIN_DIR / f"wordpress-{WP_VERSION}"

# Use the WP.org plugin distribution — the GitHub release with the same
# version number ships the *new* wp-pdo-mysql-on-sqlite plugin which doesn't
# include the db.copy drop-in WordPress core actually needs.
SQLITE_DB_URL = "https://downloads.wordpress.org/plugin/sqlite-database-integration.2.2.23.zip"
SQLITE_DB_SHA256 = "44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e"
SQLITE_DB_CACHE = BIN_DIR / "sqlite-database-integration-2.2.23"

WP_ADMIN_USER = "admin"
WP_ADMIN_PASSWORD = "wp-admin-test-pw"
WP_ADMIN_EMAIL = "admin@example.test"
WP_SITE_TITLE = "CashuPay Test"


@dataclass
class WordPressHandle:
    process: subprocess.Popen[bytes]
    port: int
    wp_root: Path
    data_dir: Path
    workdir: Path
    php_exe: Path
    wp_cli_phar: Path

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def cashupay_admin_url(self) -> str:
        return f"{self.url}/wp-content/plugins/cashupay/admin.php"

    @property
    def db_path(self) -> Path:
        return self.data_dir / "cashupay.sqlite"

    @contextmanager
    def db(self) -> Iterator[sqlite3.Connection]:
        conn = sqlite3.connect(self.db_path, isolation_level=None)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()

    def wp_cli(self, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        """Run a wp-cli command against this WP install."""
        cmd = [
            str(self.php_exe),
            str(self.wp_cli_phar),
            f"--path={self.wp_root}",
            "--allow-root",
            *args,
        ]
        env = os.environ.copy()
        env["CASHUPAY_DATA_DIR"] = str(self.data_dir)
        result = subprocess.run(cmd, env=env, capture_output=True, text=True)
        if check and result.returncode != 0:
            raise RuntimeError(
                f"wp-cli failed ({result.returncode}) for {args}\n"
                f"stdout: {result.stdout}\n"
                f"stderr: {result.stderr}"
            )
        return result

    def wait_ready(self, timeout_s: float = 30.0) -> None:
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                requests.get(self.url, timeout=2)
                return
            except requests.RequestException as e:
                last = e
            time.sleep(0.2)
        raise TimeoutError(f"WordPress not ready after {timeout_s}s ({last})")


# ---------- one-time downloads ----------

def _download_to(url: str, dest: Path, *, expected_sha256: str | None = None) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    if dest.exists() and expected_sha256 and _sha256(dest) == expected_sha256:
        return
    import tempfile
    print(f"[wp] downloading {dest.name} ...")
    req = urllib.request.Request(url, headers={"User-Agent": "cashupayserver-tests/1.0"})
    with tempfile.NamedTemporaryFile(dir=dest.parent, delete=False, suffix=".partial") as tmp:
        with urllib.request.urlopen(req, timeout=120) as resp:
            shutil.copyfileobj(resp, tmp)
        tmp_path = Path(tmp.name)
    if expected_sha256:
        actual = _sha256(tmp_path)
        if actual != expected_sha256:
            tmp_path.unlink(missing_ok=True)
            raise RuntimeError(f"sha256 mismatch for {dest.name}: expected {expected_sha256} got {actual}")
    tmp_path.replace(dest)


def _sha256(path: Path) -> str:
    import hashlib
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _ensure_wp_core() -> Path:
    """Download + extract WordPress core if not already cached. Returns the
    directory containing the core (with wp-includes/, wp-admin/, index.php)."""
    if (WP_TARBALL_CACHE / "wordpress" / "wp-includes" / "version.php").is_file():
        return WP_TARBALL_CACHE / "wordpress"
    WP_TARBALL_CACHE.mkdir(parents=True, exist_ok=True)
    tarball = WP_TARBALL_CACHE / f"wordpress-{WP_VERSION}.tar.gz"
    _download_to(WP_TARBALL_URL, tarball)  # checksum-less; wp.org doesn't publish a stable manifest at a static URL
    import tarfile
    with tarfile.open(tarball, "r:gz") as tf:
        tf.extractall(WP_TARBALL_CACHE, filter="data")
    return WP_TARBALL_CACHE / "wordpress"


def _ensure_sqlite_plugin() -> Path:
    """Download + extract sqlite-database-integration. Returns the plugin dir."""
    extracted = SQLITE_DB_CACHE / "sqlite-database-integration"
    if (extracted / "db.copy").is_file():
        return extracted
    SQLITE_DB_CACHE.mkdir(parents=True, exist_ok=True)
    # Wipe any stale extraction (e.g. from the GitHub tarball layout).
    if SQLITE_DB_CACHE.exists():
        for child in SQLITE_DB_CACHE.iterdir():
            if child.is_dir():
                shutil.rmtree(child)
    archive = SQLITE_DB_CACHE / "plugin.zip"
    _download_to(SQLITE_DB_URL, archive, expected_sha256=SQLITE_DB_SHA256)
    import zipfile
    with zipfile.ZipFile(archive) as zf:
        zf.extractall(SQLITE_DB_CACHE)
    if not (extracted / "db.copy").is_file():
        raise RuntimeError(f"sqlite-database-integration extracted but db.copy missing at {extracted}")
    return extracted


# ---------- plugin tree assembly ----------

# Mirrors what scripts/build-wordpress-plugin.sh produces but via symlinks
# so source edits are reflected without rebuilding.
# Top-level repo entries to symlink as subdirs/files of the plugin.
_PLUGIN_SUBPATHS = (
    "includes",
    "admin.php",
    "setup.php",
    "api.php",
    "payment.php",
    "receive.php",
    "cron.php",
    "router.php",
    "api-keys",
    "assets",
    "images",
    "favicon.ico",
    "manifest.json",
)


def _assemble_plugin(plugin_dir: Path) -> None:
    """Mirror docker/Dockerfile.wordpress's plugin layout: all wordpress/*.php
    files live flat at the plugin root, with shared backend folders symlinked
    next to them.

    PHP files at the plugin root are *copies* (not symlinks) so __DIR__ resolves
    to the plugin directory at runtime; everything else is symlinked so source
    edits show up live."""
    plugin_dir.mkdir(parents=True, exist_ok=True)

    # 1. Copy every wordpress/*.php to the plugin root so __DIR__ resolution
    #    points at the plugin directory and `require __DIR__ . '/bootstrap.php'`
    #    in cashupay.php finds bootstrap.php beside it.
    for src in (REPO_ROOT / "wordpress").glob("*.php"):
        dst = plugin_dir / src.name
        if not dst.exists():
            shutil.copy(src, dst)

    # 2. Symlink the shared backend directories / single-file assets.
    for rel in _PLUGIN_SUBPATHS:
        src = REPO_ROOT / rel
        if not src.exists():
            continue
        dst = plugin_dir / rel
        if dst.is_symlink() or dst.exists():
            continue
        os.symlink(src, dst)

    # 3. cashu-wallet-php: only the two runtime files (matches Dockerfile).
    cashu_dir = plugin_dir / "cashu-wallet-php"
    cashu_dir.mkdir(exist_ok=True)
    for fname in ("CashuWallet.php", "bip39-english.txt"):
        src = REPO_ROOT / "cashu-wallet-php" / fname
        dst = cashu_dir / fname
        if src.exists() and not dst.exists():
            os.symlink(src, dst)


# ---------- fixture ----------

ROUTER_WRAPPER_TEMPLATE = """<?php
$dataDir = getenv('CASHUPAY_DATA_DIR');
if ($dataDir !== false && $dataDir !== '' && !defined('CASHUPAY_DATA_DIR')) {{
    define('CASHUPAY_DATA_DIR', $dataDir);
}}
// WordPress front controller — fall through to wp's index.php on misses.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file) && substr($uri, -4) !== '.php') {{
    return false;  // let PHP serve static assets
}}
if (substr($uri, -4) === '.php' && file_exists($file)) {{
    require $file;
    return true;
}}
require {wp_index!r};
"""


def start_wordpress(workdir: Path) -> WordPressHandle:
    php_exe = binaries.ensure(binaries.PHP)["php"]
    wp_cli_phar = binaries.ensure_file(binaries.WP_CLI)

    workdir.mkdir(parents=True, exist_ok=True)
    wp_root = workdir / "wp"
    data_dir = workdir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)

    # 1. Copy WP core into wp_root (fresh per test; isolated).
    core = _ensure_wp_core()
    if not wp_root.exists():
        shutil.copytree(core, wp_root)

    # 2. SQLite drop-in.
    sqlite_plugin = _ensure_sqlite_plugin()
    target_plugin_dir = wp_root / "wp-content" / "plugins" / "sqlite-database-integration"
    if not target_plugin_dir.exists():
        shutil.copytree(sqlite_plugin, target_plugin_dir)
    drop_in = wp_root / "wp-content" / "db.php"
    if not drop_in.exists():
        shutil.copy(sqlite_plugin / "db.copy", drop_in)

    # 3. Cashupay plugin (symlinks for live source).
    _assemble_plugin(wp_root / "wp-content" / "plugins" / "cashupay")

    # 4. wp-config.php with SQLite config + WP_HOME.
    port = ports.allocate(1)[0]
    config = wp_root / "wp-config.php"
    if not config.exists():
        config.write_text(_wp_config_php(port=port, data_dir=data_dir))

    # 5. Router wrapper.
    router_wrapper = workdir / "wp-router.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(wp_index=str(wp_root / "index.php"))
    )

    # 6. wp core install (uses the static PHP via wp-cli).
    install_env = os.environ.copy()
    install_env["CASHUPAY_DATA_DIR"] = str(data_dir)
    subprocess.run(
        [
            str(php_exe), str(wp_cli_phar),
            f"--path={wp_root}",
            "--allow-root",
            "core", "install",
            f"--url=http://127.0.0.1:{port}",
            f"--title={WP_SITE_TITLE}",
            f"--admin_user={WP_ADMIN_USER}",
            f"--admin_password={WP_ADMIN_PASSWORD}",
            f"--admin_email={WP_ADMIN_EMAIL}",
            "--skip-email",
        ],
        env=install_env,
        check=True,
        capture_output=True,
        text=True,
    )

    # 7. Activate cashupay plugin.
    subprocess.run(
        [
            str(php_exe), str(wp_cli_phar),
            f"--path={wp_root}",
            "--allow-root",
            "plugin", "activate", "cashupay",
        ],
        env=install_env,
        check=True,
        capture_output=True,
        text=True,
    )

    # 8. Spin php -S.
    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    log = (workdir / "wp-server.log").open("ab")
    proc = subprocess.Popen(
        [
            str(php_exe),
            "-S", f"127.0.0.1:{port}",
            "-t", str(wp_root),
            str(router_wrapper),
        ],
        env=env,
        cwd=str(wp_root),
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = WordPressHandle(
        process=proc,
        port=port,
        wp_root=wp_root,
        data_dir=data_dir,
        workdir=workdir,
        php_exe=php_exe,
        wp_cli_phar=wp_cli_phar,
    )
    try:
        handle.wait_ready()
    except Exception:
        stop_wordpress(handle)
        raise
    return handle


def stop_wordpress(handle: WordPressHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()


def _wp_config_php(*, port: int, data_dir: Path) -> str:
    return f"""<?php
// SQLite drop-in expects these even though they're unused.
define('DB_NAME', 'wordpress');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('DB_DIR', __DIR__ . '/wp-content/database/');
define('DB_FILE', 'wordpress.sqlite');

$table_prefix = 'wp_';

// Authentication keys — random per-fixture.
define('AUTH_KEY',         '{_rand_key()}');
define('SECURE_AUTH_KEY',  '{_rand_key()}');
define('LOGGED_IN_KEY',    '{_rand_key()}');
define('NONCE_KEY',        '{_rand_key()}');
define('AUTH_SALT',        '{_rand_key()}');
define('SECURE_AUTH_SALT', '{_rand_key()}');
define('LOGGED_IN_SALT',   '{_rand_key()}');
define('NONCE_SALT',       '{_rand_key()}');

define('WP_HOME',    'http://127.0.0.1:{port}');
define('WP_SITEURL', 'http://127.0.0.1:{port}');
define('WP_DEBUG', false);

// Force CashuPay data dir to the test's isolated location. (Guard against
// the env var also setting it via the router wrapper.)
if (!defined('CASHUPAY_DATA_DIR')) {{
    define('CASHUPAY_DATA_DIR', '{data_dir}');
}}

if (!defined('ABSPATH')) {{
    define('ABSPATH', __DIR__ . '/');
}}
require_once ABSPATH . 'wp-settings.php';
"""


def _rand_key() -> str:
    import secrets
    return secrets.token_urlsafe(48).replace("'", "x")
