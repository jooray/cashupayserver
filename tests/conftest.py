"""Top-level pytest fixtures.

Session-scoped: bitcoind, lnd_mint, lnd_payer, channels, mint.
Function-scoped: payserver (fresh data dir), webhook_sink.

Importing the fixtures module also forces binary download on first run.
"""
from __future__ import annotations

import shutil
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import pytest

# Make fixtures/ importable as a package without needing an editable install.
TESTS_DIR = Path(__file__).resolve().parent
if str(TESTS_DIR) not in sys.path:
    sys.path.insert(0, str(TESTS_DIR))

from fixtures import binaries  # noqa: E402
from fixtures.api_client import AdminClient, GreenfieldClient  # noqa: E402
from fixtures.browser import browser, page, playwright_instance  # noqa: E402,F401
from fixtures.bitcoind import BitcoindHandle, start_bitcoind, stop_bitcoind  # noqa: E402
from fixtures.lnd import (  # noqa: E402
    LndHandle,
    open_dual_channels,
    start_lnd,
    stop_lnd,
)
from fixtures.lnurlp_server import LnurlpServer, start_lnurlp_server, stop_lnurlp_server  # noqa: E402
from fixtures.nutshell import MintHandle, start_mint, stop_mint  # noqa: E402
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver  # noqa: E402
from fixtures.setup_helpers import run_setup_wizard  # noqa: E402
from fixtures.webhook_sink import WebhookSink, start_webhook_sink, stop_webhook_sink  # noqa: E402
from fixtures.wordpress import WordPressHandle, start_wordpress, stop_wordpress  # noqa: E402

DEFAULT_ADMIN_PASSWORD = "test-admin-pw-1234"
DEFAULT_STORE_NAME = "Test Store"

SESSION_TMP = TESTS_DIR / ".tmp"


@pytest.fixture(scope="session")
def session_workdir() -> Iterator[Path]:
    SESSION_TMP.mkdir(parents=True, exist_ok=True)
    workdir = SESSION_TMP / f"session-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True, exist_ok=True)
    yield workdir
    # Leave workdir on disk for postmortem; .tmp is gitignored.


@pytest.fixture(scope="session")
def installed_binaries() -> dict:
    return binaries.ensure_all()


@pytest.fixture(scope="session")
def bitcoind(session_workdir: Path, installed_binaries: dict) -> Iterator[BitcoindHandle]:
    handle = start_bitcoind(session_workdir)
    yield handle
    stop_bitcoind(handle)


@pytest.fixture(scope="session")
def lnd_mint(session_workdir: Path, bitcoind: BitcoindHandle, installed_binaries: dict) -> Iterator[LndHandle]:
    handle = start_lnd(session_workdir, "mint", bitcoind)
    yield handle
    stop_lnd(handle)


@pytest.fixture(scope="session")
def lnd_payer(session_workdir: Path, bitcoind: BitcoindHandle, installed_binaries: dict) -> Iterator[LndHandle]:
    handle = start_lnd(session_workdir, "payer", bitcoind)
    yield handle
    stop_lnd(handle)


@pytest.fixture(scope="session")
def channels(bitcoind: BitcoindHandle, lnd_payer: LndHandle, lnd_mint: LndHandle) -> None:
    open_dual_channels(bitcoind, lnd_payer, lnd_mint)


@pytest.fixture(scope="session")
def mint(session_workdir: Path, lnd_mint: LndHandle, channels: None) -> Iterator[MintHandle]:
    handle = start_mint(session_workdir, lnd_mint)
    yield handle
    stop_mint(handle)


@pytest.fixture
def payserver(tmp_path_factory: pytest.TempPathFactory) -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    yield handle
    stop_payserver(handle)
    # Leave workdir on disk for postmortem; .tmp is gitignored. Periodic cleanup
    # via `rm -rf tests/.tmp` is up to the developer.


@pytest.fixture
def webhook_sink() -> Iterator[WebhookSink]:
    sink = start_webhook_sink()
    yield sink
    stop_webhook_sink(sink)


@pytest.fixture
def lnurlp_server(lnd_payer: LndHandle) -> Iterator[LnurlpServer]:
    """Mock LNURL-pay endpoint backed by lnd_payer for auto-melt tests."""
    s = start_lnurlp_server(lnd_payer)
    yield s
    stop_lnurlp_server(s)


@pytest.fixture
def wordpress(request) -> Iterator[WordPressHandle]:
    """Fresh WordPress install with the cashupay plugin activated.
    Function-scoped — each test gets its own WP root + SQLite DB."""
    workdir = SESSION_TMP / f"wp-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(workdir)
    yield handle
    stop_wordpress(handle)


@pytest.fixture
def payserver_with_lnurlp(lnurlp_server: LnurlpServer) -> Iterator[PayserverHandle]:
    """payserver fixture variant that points cashu-wallet-php at the local
    LNURL-pay mock. Used by auto-melt tests."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server.url_template},
    )
    yield handle
    stop_payserver(handle)


# ---- composite fixtures: payserver with setup-wizard already walked ----


@dataclass
class ConfiguredPayserver:
    """A payserver instance with the install wizard already completed.
    Holds the handles every test usually wants in one place."""

    handle: PayserverHandle
    admin: AdminClient
    store_id: str
    admin_password: str
    api_token: str
    greenfield: GreenfieldClient


def _configure(payserver: PayserverHandle, mint: MintHandle) -> ConfiguredPayserver:
    run_setup_wizard(
        payserver.url,
        admin_password=DEFAULT_ADMIN_PASSWORD,
        store_name=DEFAULT_STORE_NAME,
        mint_url=mint.url,
        mint_unit="sat",
    )
    admin = AdminClient(payserver.url)
    admin.login(DEFAULT_ADMIN_PASSWORD)
    stores = admin.list_stores()
    assert stores, "setup wizard should have created a store"
    store_id = stores[0]["id"]
    key = admin.create_api_key(store_id, label="e2e")
    token = key.get("key") or key.get("apiKey") or key.get("token")
    assert token, f"expected api key in response, got {key}"
    return ConfiguredPayserver(
        handle=payserver,
        admin=admin,
        store_id=store_id,
        admin_password=DEFAULT_ADMIN_PASSWORD,
        api_token=token,
        greenfield=GreenfieldClient(payserver.url, token),
    )


@pytest.fixture
def configured(payserver: PayserverHandle, mint: MintHandle) -> ConfiguredPayserver:
    """Setup-wizard-walked payserver + admin client + API key + Greenfield client."""
    return _configure(payserver, mint)


@pytest.fixture
def configured_with_lnurlp(
    payserver_with_lnurlp: PayserverHandle, mint: MintHandle
) -> ConfiguredPayserver:
    """Same as `configured` but uses the LNURL-mock-aware payserver."""
    return _configure(payserver_with_lnurlp, mint)
