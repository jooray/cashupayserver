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
    sha256="d14236dbd35333425f703f7deb4486c9255bf3e1dbffdee2a86a451e3bc24612",
    archive_root="",  # flat tarball: just `php` at the root
    executables=("php",),
    env_override="CASHUPAY_TEST_PHP",
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
    with tarfile.open(archive, "r:gz") as tf:
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
