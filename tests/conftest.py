"""Top-level pytest fixtures.

Session-scoped: bitcoind, lnd_mint, lnd_payer, channels, mint.
Function-scoped: payserver (fresh data dir), webhook_sink.

Importing the fixtures module also forces binary download on first run.
"""
from __future__ import annotations

import os
import re
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

# Point Playwright at the bundled browser cache (mirrors run-tests.sh) so
# direct `pytest` invocations work without needing PLAYWRIGHT_BROWSERS_PATH
# in the environment.
_PW_DEFAULT_CACHE = TESTS_DIR / "bin" / "playwright-browsers"
if _PW_DEFAULT_CACHE.exists():
    os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", str(_PW_DEFAULT_CACHE))

from fixtures import binaries  # noqa: E402
from fixtures.api_client import AdminClient, GreenfieldClient  # noqa: E402
from fixtures.boltz_regtest import boltz_regtest  # noqa: E402,F401 — pytest fixture
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
from fixtures.onchain import OnchainContext, make_onchain_context  # noqa: E402,F401
from fixtures.partition import parse_split_group, partition_files  # noqa: E402
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver  # noqa: E402
from fixtures.setup_helpers import run_add_store_wizard, run_setup_wizard  # noqa: E402
from fixtures.strike_api import (  # noqa: E402
    StrikeApiServer,
    start_strike_api,
    stop_strike_api,
)
from fixtures.webhook_sink import WebhookSink, start_webhook_sink, stop_webhook_sink  # noqa: E402
from fixtures.wordpress import (  # noqa: E402
    WordPressHandle,
    install_woocommerce,
    start_wordpress,
    stop_wordpress,
)

from fixtures import webserver  # noqa: E402

DEFAULT_ADMIN_PASSWORD = "test-admin-pw-1234"
DEFAULT_STORE_NAME = "Test Store"

SESSION_TMP = TESTS_DIR / ".tmp"


# ---------------------------------------------------------------------------
# Serving backend (CASHUPAY_TEST_BACKEND=phps|apache|nginx, default phps)

def pytest_addoption(parser) -> None:
    parser.addoption(
        "--split-group",
        default=None,
        metavar="M/N",
        help="Run only the Mth of N deterministic file-level suite shards "
        "(whole files stay together; see fixtures/partition.py).",
    )


def pytest_report_header(config) -> str:
    header = f"serving backend: {webserver.current_backend()} (CASHUPAY_TEST_BACKEND)"
    spec = config.getoption("--split-group")
    if spec:
        header += f" | suite shard: {spec}"
    return header


def pytest_collection_modifyitems(config, items) -> None:
    spec = config.getoption("--split-group")
    if spec:
        group, total = parse_split_group(spec)
        weights: dict[str, int] = {}
        for item in items:
            weights[str(item.fspath)] = weights.get(str(item.fspath), 0) + 1
        keep = set(partition_files(weights, total)[group - 1])
        kept = [item for item in items if str(item.fspath) in keep]
        deselected = [item for item in items if str(item.fspath) not in keep]
        if deselected:
            config.hook.pytest_deselected(items=deselected)
            items[:] = kept

    backend = webserver.current_backend()
    if backend != "phps":
        reason = webserver.docker_unavailable_reason()
        if reason is not None:
            skip_all = pytest.mark.skip(
                reason=f"CASHUPAY_TEST_BACKEND={backend} needs docker: {reason}"
            )
            for item in items:
                item.add_marker(skip_all)
            return
        for item in items:
            if item.get_closest_marker("phps_only") is not None:
                item.add_marker(
                    pytest.mark.skip(
                        reason=f"phps_only test on the {backend} backend"
                    )
                )


# ---------------------------------------------------------------------------
# Workdir hygiene: a green test's workdir has no postmortem value, and three
# backend passes of kept workdirs don't fit most disks. Fixtures call
# _maybe_remove_workdir() in teardown; failures (and CASHUPAY_KEEP_WORKDIRS=1)
# keep everything, exactly like before.

_FAILED_MODULES: set[str] = set()


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    rep = outcome.get_result()
    setattr(item, "rep_" + rep.when, rep)
    if rep.failed:
        _FAILED_MODULES.add(str(item.fspath))


def _keep_workdirs() -> bool:
    return bool(os.environ.get("CASHUPAY_KEEP_WORKDIRS"))


def _test_went_green(request) -> bool:
    """True when this test neither failed in setup nor in the call phase.
    Fixture finalizers run during teardown, after both reports exist."""
    if _keep_workdirs():
        return False
    setup_rep = getattr(request.node, "rep_setup", None)
    call_rep = getattr(request.node, "rep_call", None)
    if setup_rep is not None and setup_rep.failed:
        return False
    return call_rep is None or not call_rep.failed


def _module_went_green(request) -> bool:
    if _keep_workdirs():
        return False
    return str(request.node.fspath) not in _FAILED_MODULES


def _maybe_remove_workdir(workdir: Path, green: bool) -> None:
    if green:
        shutil.rmtree(workdir, ignore_errors=True)


def _sweep_stale_fixture_processes() -> None:
    """Kill fixture processes (php -S, bitcoind, lnd, mint, …) orphaned by a
    previous killed run: anything whose cmdline references tests/.tmp. Runs
    before this session creates its own workdirs, so a live match is stale by
    definition — except a concurrently running suite on this same checkout;
    opt out with CASHUPAY_NO_STALE_SWEEP=1 in that case."""
    if os.environ.get("CASHUPAY_NO_STALE_SWEEP"):
        return
    needle = str(SESSION_TMP)
    me = os.getpid()
    victims: list[int] = []
    for entry in Path("/proc").iterdir():
        if not entry.name.isdigit() or int(entry.name) == me:
            continue
        try:
            cmdline = (entry / "cmdline").read_bytes().replace(b"\0", b" ").decode(
                "utf-8", "replace"
            )
        except OSError:
            continue
        if needle in cmdline:
            victims.append(int(entry.name))
    for pid in victims:
        try:
            os.kill(pid, 15)
        except OSError:
            continue
    if victims:
        deadline = time.monotonic() + 5.0
        while time.monotonic() < deadline:
            if not any(Path(f"/proc/{pid}").exists() for pid in victims):
                break
            time.sleep(0.2)
        for pid in victims:
            try:
                os.kill(pid, 9)
            except OSError:
                pass
        print(f"[conftest] swept {len(victims)} stale fixture process(es) from a previous run")


def pytest_sessionfinish(session, exitstatus) -> None:
    # A fully green session leaves nothing worth keeping: remove the shared
    # bitcoind/lnd/mint workdir and any per-test dirs that slipped past
    # fixture-level cleanup (e.g. teardown-order edge cases).
    if exitstatus == 0 and not _keep_workdirs() and SESSION_TMP.exists():
        for pattern in ("session-*", "payserver-*", "wp-*"):
            for stray in SESSION_TMP.glob(pattern):
                shutil.rmtree(stray, ignore_errors=True)


@pytest.fixture(scope="session", autouse=True)
def _webserver_session_cleanup() -> Iterator[None]:
    """Remove any containers this session's serving backend leaves behind
    (per-test stop() already handles the common case)."""
    yield
    webserver.cleanup_session_containers()


@pytest.fixture(scope="session")
def session_workdir() -> Iterator[Path]:
    SESSION_TMP.mkdir(parents=True, exist_ok=True)
    _sweep_stale_fixture_processes()
    workdir = SESSION_TMP / f"session-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True, exist_ok=True)
    yield workdir
    # Kept on disk for postmortem when the run is red; pytest_sessionfinish
    # removes it after a fully green session.


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


@pytest.fixture(scope="session")
def backup_mint(session_workdir: Path, lnd_mint: LndHandle, channels: None) -> Iterator[MintHandle]:
    """Second nutshell mint used only to satisfy the setup wizard's required
    backup-mint step. Same LND backend, isolated datadir/port."""
    handle = start_mint(session_workdir, lnd_mint, name="nutshell-backup")
    yield handle
    stop_mint(handle)


@pytest.fixture
def payserver(request) -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    yield handle
    stop_payserver(handle)
    # Kept for postmortem only when the test went red (or
    # CASHUPAY_KEEP_WORKDIRS=1); .tmp is gitignored.
    _maybe_remove_workdir(workdir, _test_went_green(request))


@pytest.fixture
def webhook_sink() -> Iterator[WebhookSink]:
    sink = start_webhook_sink()
    yield sink
    stop_webhook_sink(sink)


@pytest.fixture
def onchain(bitcoind: BitcoindHandle) -> Iterator[OnchainContext]:
    """A fresh watch-only wallet per test (so derivation indexes from the
    shared tpub don't collide across tests) + helpers for funding."""
    name = f"cashupay-watch-{uuid.uuid4().hex[:8]}"
    ctx = make_onchain_context(bitcoind, name)
    yield ctx
    # Best-effort: unload the wallet so bitcoind doesn't accumulate them
    # across a long session run.
    try:
        bitcoind.rpc("unloadwallet", name)
    except Exception:
        pass


@pytest.fixture
def lnurlp_server(lnd_payer: LndHandle, channels: None) -> Iterator[LnurlpServer]:
    """Mock LNURL-pay endpoint backed by lnd_payer for auto-melt tests.

    Depends on `channels` so lnd_payer is fully synced and funded before the
    first invoice request — without it add_invoice can hang on a node that is
    still coming up (seen when a test uses the mock without the mint stack)."""
    s = start_lnurlp_server(lnd_payer)
    yield s
    stop_lnurlp_server(s)


@pytest.fixture
def wordpress(request) -> Iterator[WordPressHandle]:
    """Fresh WordPress install with the cashupay plugin activated.
    Function-scoped — each test gets its own WP root + SQLite DB (a clone of
    the session's golden template; see fixtures/wordpress.py). When the test
    also requests `woocommerce`, the WooCommerce golden is cloned so the WC
    install cost is paid once per session, not per test."""
    workdir = SESSION_TMP / f"wp-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(
        workdir, with_woocommerce="woocommerce" in request.fixturenames
    )
    yield handle
    stop_wordpress(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


@pytest.fixture
def woocommerce(wordpress: WordPressHandle) -> Iterator[tuple[WordPressHandle, dict]]:
    """The `wordpress` fixture plus a live WooCommerce store and the real BTCPay
    Greenfield gateway plugin (both pinned). Yields (handle, info) where info
    carries the created product_id. The gateway is installed but not yet wired
    to BareBits — that wiring is what the checkout test exercises.

    Normally satisfied by the WooCommerce golden template (the `wordpress`
    fixture sees this fixture in request.fixturenames and clones the woo
    golden); the live install only runs with CASHUPAY_WP_NO_TEMPLATE=1."""
    if wordpress.woo_product_id is not None:
        info = {"product_id": wordpress.woo_product_id}
    else:
        info = install_woocommerce(wordpress)
    yield wordpress, info


@pytest.fixture
def strike_api() -> Iterator[StrikeApiServer]:
    """Mock Strike REST API (create/quote/read invoices) for the Strike rail."""
    s = start_strike_api()
    yield s
    stop_strike_api(s)


@pytest.fixture
def payserver_with_strike(request, strike_api: StrikeApiServer) -> Iterator[PayserverHandle]:
    """payserver fixture variant pointed at the local Strike API mock."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHUPAY_STRIKE_API_BASE": strike_api.api_base},
    )
    yield handle
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


@pytest.fixture
def payserver_with_lnurlp(request, lnurlp_server: LnurlpServer) -> Iterator[PayserverHandle]:
    """payserver fixture variant that points cashu-wallet-php at the local
    LNURL-pay mock. Used by auto-melt tests."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server.url_template},
    )
    yield handle
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


@pytest.fixture
def payserver_with_strike(request, strike_api: StrikeApiServer) -> Iterator[PayserverHandle]:
    """Fresh (not-yet-set-up) payserver pointed at the Strike API mock, for
    wizard flows that save a Strike key / enable Strike on-chain."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHUPAY_STRIKE_API_BASE": strike_api.api_base},
    )
    yield handle
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


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
    store_name: str = DEFAULT_STORE_NAME


def _configure(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> ConfiguredPayserver:
    run_setup_wizard(
        payserver.url,
        admin_password=DEFAULT_ADMIN_PASSWORD,
        store_name=DEFAULT_STORE_NAME,
        mint_url=mint.url,
        mint_unit="sat",
        backup_mint_url=backup_mint.url,
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
def configured(
    payserver: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> ConfiguredPayserver:
    """Setup-wizard-walked payserver + admin client + API key + Greenfield client."""
    return _configure(payserver, mint, backup_mint)


@pytest.fixture
def configured_with_lnurlp(
    payserver_with_lnurlp: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> ConfiguredPayserver:
    """Same as `configured` but uses the LNURL-mock-aware payserver."""
    return _configure(payserver_with_lnurlp, mint, backup_mint)


@pytest.fixture
def configured_with_strike(
    payserver_with_strike: PayserverHandle, mint: MintHandle, backup_mint: MintHandle
) -> ConfiguredPayserver:
    """Same as `configured` but uses the Strike-mock-aware payserver."""
    return _configure(payserver_with_strike, mint, backup_mint)


# ---- shared servers: one payserver per test module, one store per test ----
#
# Booting a server and walking the 9-step setup wizard for every test is the
# suite's dominant fixable overhead. Share-safe tests instead use
# `shared_configured`: the module's tests run against ONE wizard-configured
# instance, and each test gets its own store (fresh per-store wallet seed via
# the add_store wizard, so stores never collide at the mint) plus its own API
# key. Module scope — not session — so one file's contamination can never
# leak into another file.
#
# Not for tests that mutate server-global state (admin password, users table,
# global config rows, lockout/rate-limit counters, process restarts): those
# keep the function-scoped `configured`/`payserver` fixtures.


def _start_shared_server(
    request,
    mint: MintHandle,
    backup_mint: MintHandle,
    extra_env: dict[str, str] | None = None,
) -> Iterator[ConfiguredPayserver]:
    workdir = SESSION_TMP / f"payserver-shared-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env=extra_env)
    try:
        cfg = _configure(handle, mint, backup_mint)
    except Exception:
        stop_payserver(handle)
        raise
    yield cfg
    stop_payserver(handle)
    _maybe_remove_workdir(workdir, _module_went_green(request))


def _add_test_store(
    shared: ConfiguredPayserver, mint: MintHandle, backup_mint: MintHandle, request
) -> ConfiguredPayserver:
    """A per-test store (own wallet seed, own API key) on the shared server."""
    base = re.sub(r"[^A-Za-z0-9_-]+", "-", request.node.name)[:48].strip("-")
    store_name = f"{base}-{uuid.uuid4().hex[:6]}"
    run_add_store_wizard(
        shared.handle.url,
        admin_password=shared.admin_password,
        store_name=store_name,
        mint_url=mint.url,
        backup_mint_url=backup_mint.url,
    )
    stores = shared.admin.list_stores()
    matches = [s for s in stores if s["name"] == store_name]
    assert len(matches) == 1, f"expected exactly one store named {store_name!r}, got {matches}"
    store_id = matches[0]["id"]
    key = shared.admin.create_api_key(store_id, label=f"e2e-{store_name}")
    token = key.get("key") or key.get("apiKey") or key.get("token")
    assert token, f"expected api key in response, got {key}"
    return ConfiguredPayserver(
        handle=shared.handle,
        admin=shared.admin,
        store_id=store_id,
        admin_password=shared.admin_password,
        api_token=token,
        greenfield=GreenfieldClient(shared.handle.url, token),
        store_name=store_name,
    )


@pytest.fixture(scope="module")
def shared_server(
    request, mint: MintHandle, backup_mint: MintHandle
) -> Iterator[ConfiguredPayserver]:
    """One wizard-configured payserver for the whole test module."""
    yield from _start_shared_server(request, mint, backup_mint)


@pytest.fixture
def shared_configured(
    shared_server: ConfiguredPayserver, mint: MintHandle, backup_mint: MintHandle, request
) -> ConfiguredPayserver:
    """Drop-in replacement for `configured` on share-safe tests: same
    interface, but backed by the module's shared server + a per-test store."""
    return _add_test_store(shared_server, mint, backup_mint, request)


@pytest.fixture(scope="module")
def lnurlp_server_shared(lnd_payer: LndHandle, channels: None) -> Iterator[LnurlpServer]:
    """Module-scoped twin of `lnurlp_server` for shared-server modules."""
    s = start_lnurlp_server(lnd_payer)
    yield s
    stop_lnurlp_server(s)


@pytest.fixture(scope="module")
def strike_api_shared() -> Iterator[StrikeApiServer]:
    """Module-scoped twin of `strike_api` for shared-server modules."""
    s = start_strike_api()
    yield s
    stop_strike_api(s)


@pytest.fixture(scope="module")
def shared_server_lnurlp(
    request, mint: MintHandle, backup_mint: MintHandle, lnurlp_server_shared: LnurlpServer
) -> Iterator[ConfiguredPayserver]:
    """Shared server pointed at the module-scoped LNURL-pay mock."""
    yield from _start_shared_server(
        request,
        mint,
        backup_mint,
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server_shared.url_template},
    )


@pytest.fixture
def shared_configured_with_lnurlp(
    shared_server_lnurlp: ConfiguredPayserver,
    mint: MintHandle,
    backup_mint: MintHandle,
    request,
) -> ConfiguredPayserver:
    return _add_test_store(shared_server_lnurlp, mint, backup_mint, request)


@pytest.fixture(scope="module")
def shared_server_strike(
    request, mint: MintHandle, backup_mint: MintHandle, strike_api_shared: StrikeApiServer
) -> Iterator[ConfiguredPayserver]:
    """Shared server pointed at the module-scoped Strike API mock."""
    yield from _start_shared_server(
        request,
        mint,
        backup_mint,
        extra_env={"CASHUPAY_STRIKE_API_BASE": strike_api_shared.api_base},
    )


@pytest.fixture
def shared_configured_with_strike(
    shared_server_strike: ConfiguredPayserver,
    mint: MintHandle,
    backup_mint: MintHandle,
    request,
) -> ConfiguredPayserver:
    return _add_test_store(shared_server_strike, mint, backup_mint, request)
