"""Nutshell Cashu mint fixture.

Spawns a single nutshell mint connected to a given LND node as its BOLT11
backend. Pinned to a specific nutshell version installed in its own venv so
its (heavy) dependency tree doesn't collide with the test suite's.
"""
from __future__ import annotations

import os
import secrets
import shutil
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from . import ports
from .lnd import LndHandle

NUTSHELL_VERSION = "0.16.5"

TESTS_DIR = Path(__file__).resolve().parent.parent
NUTSHELL_VENV = TESTS_DIR / ".venv-nutshell"


@dataclass
class MintHandle:
    process: subprocess.Popen[bytes]
    datadir: Path
    port: int

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def wait_ready(self, timeout_s: float = 60.0) -> None:
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(f"{self.url}/v1/info", timeout=2) as resp:
                    if resp.status == 200:
                        return
            except (urllib.error.URLError, ConnectionError) as e:
                last = e
            time.sleep(0.3)
        raise TimeoutError(f"nutshell mint not ready after {timeout_s}s ({last})")


def _ensure_nutshell_installed() -> Path:
    """Create the nutshell venv if missing and return path to the `mint` executable."""
    mint_exe = NUTSHELL_VENV / "bin" / "mint"
    if mint_exe.is_file():
        return mint_exe
    if not NUTSHELL_VENV.exists():
        subprocess.run([sys.executable, "-m", "venv", str(NUTSHELL_VENV)], check=True)
    pip = NUTSHELL_VENV / "bin" / "pip"
    subprocess.run([str(pip), "install", "--upgrade", "pip"], check=True)
    # nutshell 0.16.5 has loose pins on transitive deps that broke after upstream
    # API removals. Constrain them back to the last working majors:
    #   - environs 9.x uses marshmallow's pre-4 `__version_info__` attribute.
    #   - slowapi uses the `fixed-window-elastic-expiry` limits strategy, removed in 4.x.
    subprocess.run(
        [
            str(pip), "install",
            f"cashu=={NUTSHELL_VERSION}",
            "marshmallow<4",
            "limits<4",
        ],
        check=True,
    )
    if not mint_exe.is_file():
        raise RuntimeError(f"nutshell `mint` executable missing at {mint_exe}")
    return mint_exe


def start_mint(workdir: Path, lnd_mint: LndHandle) -> MintHandle:
    mint_exe = _ensure_nutshell_installed()

    datadir = workdir / "nutshell"
    datadir.mkdir(parents=True, exist_ok=True)

    port = ports.allocate(1)[0]

    env = os.environ.copy()
    env.update(
        {
            "MINT_LISTEN_HOST": "127.0.0.1",
            "MINT_LISTEN_PORT": str(port),
            "MINT_BACKEND_BOLT11_SAT": "LndRestWallet",
            "MINT_LND_REST_ENDPOINT": lnd_mint.rest_url,
            "MINT_LND_REST_CERT": str(lnd_mint.tls_cert),
            "MINT_LND_REST_MACAROON": str(lnd_mint.admin_macaroon),
            # Bare path -> nutshell's legacy SQLite driver (works on 0.16.x).
            # A sqlalchemy URL ("sqlite+aiosqlite://...") triggers the SQL-introspection
            # code path which queries information_schema.tables (PostgreSQL-only).
            "MINT_DATABASE": str(datadir / "mint"),
            "MINT_PRIVATE_KEY": secrets.token_hex(32),
            "MINT_INFO_NAME": "test-mint",
            "MINT_INFO_DESCRIPTION": "regtest mint for CashuPayServer tests",
            "MINT_INFO_CONTACT": "[]",
            "MINT_MAX_PEG_IN": "100000",
            "MINT_MAX_PEG_OUT": "100000",
            "MINT_INPUT_FEE_PPK": "0",
            "DEBUG": "false",
            "LOG_LEVEL": "INFO",
            # sqlalchemy>=2's AsyncAdaptedQueuePool can't back a sync engine, which
            # nutshell uses for some queries. Disable pooling to sidestep.
            "DB_CONNECTION_POOL": "false",
        }
    )

    log = (datadir / "mint.log").open("ab")
    proc = subprocess.Popen(
        [str(mint_exe)],
        env=env,
        cwd=str(datadir),
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = MintHandle(process=proc, datadir=datadir, port=port)
    try:
        handle.wait_ready()
    except Exception:
        stop_mint(handle)
        raise
    return handle


def stop_mint(handle: MintHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()
