"""Binary manager: download, verify, and cache external binaries.

Resolution order for each binary:
  1. Environment variable override (e.g. CASHUPAY_TEST_BITCOIND)
  2. tests/bin/<name>-<version>/<exe>
  3. <exe> on PATH (only if a version probe matches)
  4. Download tarball -> verify SHA-256 -> extract to tests/bin/<name>-<version>/

Failure on bad checksum is fatal; we never silently fall through.
"""
from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.request
from dataclasses import dataclass
from pathlib import Path

TESTS_DIR = Path(__file__).resolve().parent.parent
BIN_DIR = TESTS_DIR / "bin"
DOWNLOAD_CACHE = BIN_DIR / ".cache"


@dataclass(frozen=True)
class BinarySpec:
    name: str
    version: str
    url: str
    sha256: str
    archive_root: str        # top-level dir inside the tarball
    executables: tuple[str, ...]  # relative paths inside archive_root
    env_override: str        # env var name that can short-circuit lookup
    # Whether a same-named executable on $PATH may satisfy this spec when
    # tests/bin/ has no installed copy. Off for binaries whose EXACT version
    # is load-bearing: an unrelated host build silently changes what the
    # suite runs against (the explicit env_override still wins either way).
    allow_path_fallback: bool = True


BITCOIND = BinarySpec(
    name="bitcoind",
    version="28.0",
    url="https://bitcoincore.org/bin/bitcoin-core-28.0/bitcoin-28.0-x86_64-linux-gnu.tar.gz",
    sha256="7fe294b02b25b51acb8e8e0a0eb5af6bbafa7cd0c5b0e5fcbb61263104a82fbc",
    archive_root="bitcoin-28.0",
    executables=("bin/bitcoind", "bin/bitcoin-cli"),
    env_override="CASHUPAY_TEST_BITCOIND",
)

LND = BinarySpec(
    name="lnd",
    version="0.18.5-beta",
    url="https://github.com/lightningnetwork/lnd/releases/download/v0.18.5-beta/lnd-linux-amd64-v0.18.5-beta.tar.gz",
    sha256="ffffa63b28a031a330eae4db234ada1bd27003059757c1a0d3aeb0d8d7351c4f",
    archive_root="lnd-linux-amd64-v0.18.5-beta",
    executables=("lnd", "lncli"),
    env_override="CASHUPAY_TEST_LND",
)

# Static PHP build from static-php-cli (single self-contained binary, no system deps).
# Includes curl, sqlite3, gmp, json, openssl, pdo_sqlite, session — everything
# cashupayserver requires.
PHP = BinarySpec(
    name="php",
    version="8.3.31",
    url="https://dl.static-php.dev/static-php-cli/common/php-8.3.31-cli-linux-x86_64.tar.gz",
    # static-php.dev's common/ artifacts are ROLLING rebuilds of the same
    # version (like the clink release asset): the bytes churn even though
    # the PHP version doesn't. When this mismatches, download the current
    # file, sanity-check it (php -v, required extensions), re-pin from the
    # error's "got" hash. Current: build of 2026-07-01.
    sha256="4f340f035846d47d5c0eddd2064d360aa25fb29e81e1d369a644440a55c500b8",
    archive_root="",  # flat tarball: just `php` at the root
    executables=("php",),
    env_override="CASHUPAY_TEST_PHP",
    # Never substitute a host PHP: the suite is only proven against this
    # pinned build. GitHub's runners ship PHP 8.1, whose `php -S` worker mode
    # (PHP_CLI_SERVER_WORKERS, the WordPress fixture) mishandles concurrent
    # browser connections — workers accept Chromium's speculative sockets and
    # then never answer the real request, so every Playwright-vs-WordPress
    # test died on a page-load timeout while the same tests passed locally on
    # the pinned 8.3.31.
    allow_path_fallback=False,
)

FULCRUM = BinarySpec(
    name="fulcrum",
    version="2.1.1",
    url="https://github.com/cculianu/Fulcrum/releases/download/v2.1.1/Fulcrum-2.1.1-x86_64-linux.tar.gz",
    sha256="6056b97d63ee9ec5b056521ca26586b027f3dd57d00875d4950d8e77ec3f442b",
    archive_root="Fulcrum-2.1.1-x86_64-linux",
    executables=("Fulcrum",),
    env_override="CASHUPAY_TEST_FULCRUM",
)

# Node.js portable LTS. Ships npm in the same tarball; used to build/run the
# self-hosted cashu.me wallet for the dev iterate.py script.
NODEJS = BinarySpec(
    name="nodejs",
    version="20.20.2",
    url="https://nodejs.org/dist/latest-v20.x/node-v20.20.2-linux-x64.tar.xz",
    sha256="df770b2a6f130ed8627c9782c988fda9669fa23898329a61a871e32f965e007d",
    archive_root="node-v20.20.2-linux-x64",
    executables=("bin/node", "bin/npm", "bin/npx"),
    env_override="CASHUPAY_TEST_NODEJS",
)

ALL_SPECS = (BITCOIND, LND, PHP)


# ---------- single-file downloads (not tarballs) ----------

@dataclass(frozen=True)
class FileSpec:
    name: str
    version: str
    url: str
    sha256: str
    filename: str  # final filename inside tests/bin/<name>-<version>/
    env_override: str


WP_CLI = FileSpec(
    name="wp-cli",
    version="2.12.0",
    url="https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar",
    sha256="ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c",
    filename="wp-cli.phar",
    env_override="CASHUPAY_TEST_WP_CLI",
)

# Composer is needed at test-setup time to populate vendor/ for the on-chain
# payment support. We pin a recent stable release so behavior is reproducible
# even if the user has a different system Composer.
COMPOSER = FileSpec(
    name="composer",
    version="2.8.5",
    url="https://github.com/composer/composer/releases/download/2.8.5/composer.phar",
    sha256="9cef18212e222351aeb476b81de7b2a5383f775336474467bf5c7ccfe84ab0cc",
    filename="composer.phar",
    env_override="CASHUPAY_TEST_COMPOSER",
)

# Electrum desktop wallet (Linux AppImage). Used by the dev iterate.py script
# as the on-chain + Lightning customer wallet for manual invoice payment.
# AppImages are single self-contained executables — no extraction needed.
ELECTRUM = FileSpec(
    name="electrum",
    version="4.7.2",
    url="https://download.electrum.org/4.7.2/electrum-4.7.2-x86_64.AppImage",
    sha256="cf775fb74a182ca53041b513b49b5ffb414610057c2b6d43037f1c4e77e5065a",
    filename="electrum.AppImage",
    env_override="CASHUPAY_TEST_ELECTRUM",
)

# CLINK Electrum plugin (BareBits/electrum_clink), distributed as an external
# plugin zip on the GitHub release. The dev iterate.py rig + the noffer-receive
# e2e inject this into the extracted Electrum AppImage as an internal plugin so
# a wallet can act as a CLINK noffer service (see tests/fixtures/electrum.py).
#
# Upstream publishes immutable versioned releases (the old rolling `latest`
# tag is gone — its URL now 404s), so URL and sha256 stay valid until we
# deliberately bump the version here (or point CASHUPAY_TEST_CLINK_PLUGIN at
# a local zip).
CLINK_PLUGIN = FileSpec(
    name="electrum-clink-plugin",
    version="0.0.12",
    url="https://github.com/BareBits/electrum_clink/releases/download/v0.0.12/clink-0.0.12.zip",
    sha256="5a9685ce270995f28bffcd2b340f23284175a8caf3ebbfc61939a902f4f331ae",
    filename="clink-plugin.zip",
    env_override="CASHUPAY_TEST_CLINK_PLUGIN",
)


def ensure_file(spec: FileSpec) -> Path:
    """Download a single file (e.g. wp-cli.phar) into tests/bin/<name>-<version>/."""
    override = os.environ.get(spec.env_override)
    if override:
        p = Path(override)
        if not p.is_file():
            raise RuntimeError(f"{spec.env_override}={override} is not a file")
        return p

    install_dir = BIN_DIR / f"{spec.name}-{spec.version}"
    target = install_dir / spec.filename
    if target.is_file() and _sha256_file(target) == spec.sha256:
        return target

    install_dir.mkdir(parents=True, exist_ok=True)
    print(f"[binaries] downloading {spec.name} {spec.version} ...", file=sys.stderr)
    req = urllib.request.Request(spec.url, headers={"User-Agent": "cashupayserver-tests/1.0"})
    with tempfile.NamedTemporaryFile(dir=install_dir, delete=False, suffix=".partial") as tmp:
        with urllib.request.urlopen(req, timeout=120) as resp:
            shutil.copyfileobj(resp, tmp)
        tmp_path = Path(tmp.name)
    actual = _sha256_file(tmp_path)
    if actual != spec.sha256:
        tmp_path.unlink(missing_ok=True)
        raise RuntimeError(
            f"checksum mismatch for {spec.name} {spec.version}: "
            f"expected {spec.sha256}, got {actual}"
        )
    tmp_path.replace(target)
    # FileSpec targets are always meant to be executable (CLI phar/AppImage),
    # so make sure the bit is set even if the source server didn't.
    target.chmod(0o755)
    return target


def _sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _probe_path(exe: str) -> Path | None:
    found = shutil.which(exe)
    return Path(found) if found else None


def _install_dir(spec: BinarySpec) -> Path:
    return BIN_DIR / f"{spec.name}-{spec.version}"


def _executable_paths(spec: BinarySpec) -> dict[str, Path]:
    base = _install_dir(spec)
    return {Path(rel).name: base / rel for rel in spec.executables}


def _is_installed(spec: BinarySpec) -> bool:
    return all(p.is_file() and os.access(p, os.X_OK) for p in _executable_paths(spec).values())


def _download(spec: BinarySpec) -> Path:
    DOWNLOAD_CACHE.mkdir(parents=True, exist_ok=True)
    archive = DOWNLOAD_CACHE / f"{spec.name}-{spec.version}.tar.gz"
    if archive.exists() and _sha256_file(archive) == spec.sha256:
        return archive
    print(f"[binaries] downloading {spec.name} {spec.version} ...", file=sys.stderr)
    req = urllib.request.Request(spec.url, headers={"User-Agent": "cashupayserver-tests/1.0"})
    with tempfile.NamedTemporaryFile(dir=DOWNLOAD_CACHE, delete=False, suffix=".partial") as tmp:
        with urllib.request.urlopen(req, timeout=120) as resp:
            shutil.copyfileobj(resp, tmp)
        tmp_path = Path(tmp.name)
    actual = _sha256_file(tmp_path)
    if actual != spec.sha256:
        tmp_path.unlink(missing_ok=True)
        raise RuntimeError(
            f"checksum mismatch for {spec.name} {spec.version}: "
            f"expected {spec.sha256}, got {actual}"
        )
    tmp_path.replace(archive)
    return archive


def _extract(spec: BinarySpec, archive: Path) -> None:
    install = _install_dir(spec)
    if install.exists():
        shutil.rmtree(install)
    install.mkdir(parents=True, exist_ok=True)
    # tarfile.open with mode "r:*" auto-detects gzip/bzip2/xz, so we can handle
    # any of the three with the same code path.
    with tarfile.open(archive, "r:*") as tf:
        if spec.archive_root:
            prefix = spec.archive_root.rstrip("/") + "/"
            for member in tf.getmembers():
                if not member.name.startswith(prefix):
                    continue
                member.name = member.name[len(prefix):]
                if not member.name:
                    continue
                tf.extract(member, install, filter="data")
        else:
            # Flat tarball — extract everything as-is.
            tf.extractall(install, filter="data")
    for rel in spec.executables:
        exe = install / rel
        if not exe.is_file():
            raise RuntimeError(f"expected {exe} after extracting {spec.name}, not found")
        exe.chmod(0o755)


def ensure(spec: BinarySpec) -> dict[str, Path]:
    """Return a {name: Path} map of executables for the given spec, installing if needed."""
    # 1. Env override
    override = os.environ.get(spec.env_override)
    if override:
        override_path = Path(override)
        if not override_path.is_file():
            raise RuntimeError(f"{spec.env_override}={override} is not a file")
        # If the override points at one executable, derive siblings from its dir
        # (e.g. lnd next to lncli in the same dir).
        return {Path(rel).name: override_path.parent / Path(rel).name for rel in spec.executables}

    # 2. Already installed in tests/bin/
    if _is_installed(spec):
        return _executable_paths(spec)

    # 3. PATH (best-effort; we don't version-check rigorously)
    if spec.allow_path_fallback:
        on_path = {Path(rel).name: _probe_path(Path(rel).name) for rel in spec.executables}
        if all(on_path.values()):
            return {k: v for k, v in on_path.items() if v is not None}

    # 4. Download + extract
    archive = _download(spec)
    _extract(spec, archive)
    if not _is_installed(spec):
        raise RuntimeError(f"{spec.name} installation incomplete after extract")
    return _executable_paths(spec)


def ensure_all() -> dict[str, dict[str, Path]]:
    """Install everything; return a nested {spec.name: {exe: Path}} map."""
    BIN_DIR.mkdir(parents=True, exist_ok=True)
    return {spec.name: ensure(spec) for spec in ALL_SPECS}
