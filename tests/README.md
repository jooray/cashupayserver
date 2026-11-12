# CashuPayServer test suite

End-to-end tests for cashupayserver. Spins up bitcoind (regtest), two LND nodes,
a nutshell Cashu mint, the PHP app, and a webhook sink — all on ephemeral ports,
all cleaned up between runs.

## Requirements

- Linux x86_64
- Python 3.11+
- ~600 MB free disk (bitcoind + lnd + static PHP + Playwright Chromium)
- Internet access on first run (binary download)

PHP is fetched as a single self-contained static binary from
[static-php-cli](https://github.com/crazywhalecc/static-php-cli) — no host PHP
install is required.

## Quick start

```bash
cd tests
./scripts/run-tests.sh
```

This will:
1. Initialize the `cashu-wallet-php` git submodule if missing.
2. Create `tests/.venv/` and install Python deps from `requirements.txt`.
3. Download + verify bitcoind and LND into `tests/bin/` if not already cached.
4. Install Playwright's Chromium browser into `tests/bin/playwright-browsers/`.
5. Run `pytest` — once per serving backend (php -S, Apache, nginx; see below).

## Serving backends

The app under test is served by one of three backends, selected with
`CASHUPAY_TEST_BACKEND` (see `fixtures/webserver.py`):

| backend  | what serves the app | needs |
|----------|---------------------|-------|
| `phps`   | `php -S` + router wrapper (the default for a bare `pytest`) | nothing |
| `apache` | `php:8.3-apache` (mod_php) container, real `.htaccess` semantics | `sudo -n docker` |
| `nginx`  | nginx + php-fpm container, the canonical `docker/nginx-site.conf` | `sudo -n docker` |

- `./scripts/run-tests.sh` with **no** `--backend` flag runs the full suite on
  **all three** sequentially (the local full-coverage run, ~3h). Pass
  `--backend=phps|apache|nginx` for a single pass — CI pins `--backend=phps`
  for the full suite, covers the standalone server on real webservers with
  the fast `webserver-smoke` workflow, and runs the WooCommerce checkout
  tier (plugin zip install → wiring → guest + browser checkout → Lightning
  settle) on real Apache and nginx in the `wp-checkout` job of `tests.yml`.
- Containers run with `--network=host`, as your uid, with the repo bind-mounted
  at its own path, so URLs, data dirs, and sqlite access behave exactly as
  under `php -S`. Test images build once (content-hash tagged) on first use.
- Version drift caveat: the host-side pinned PHP is the static-cli 8.3.31
  build (`fixtures/binaries.py`); `php:8.3-apache` / `php:8.3-fpm` float
  within 8.3.x and are different builds — which is exactly the production
  reality the container backends exist to exercise.
- Backend-conditional test logic lives in `fixtures/backend.py`
  (`is_phps()`, `default_url_mode()`, ...). Under Apache/nginx the app's
  opportunistic background cron really runs, so prefer outcome-polling
  (`PayserverHandle.drive_cron_until`) over "nothing happened yet" assertions.

## Shared servers vs. fresh installs

Two families of payserver fixtures exist (`conftest.py`):

- **`shared_configured`** (plus `shared_configured_with_lnurlp` /
  `shared_configured_with_strike`, and `shared_server` for read-only tests):
  ONE payserver boots per test module and each test gets its own store —
  created through the add_store wizard, so it has its own wallet seed (no
  mint collisions) — plus its own API key. Same `ConfiguredPayserver`
  interface as `configured`; the per-test store's unique name is
  `.store_name`. This is the default for new tests: it skips a server boot
  and a 9-step wizard walk per test.
- **`configured` / `payserver`**: a fresh install per test. Required when a
  test mutates server-global state: admin password or users, global config
  rows (SMTP, updater, cron_key, update channel), failed logins (global
  lockout counter), the global API rate limiter, process restarts, unscoped
  sqlite writes, or the setup wizard itself.

Playwright note: the admin SPA auto-selects `stores[0]` unless
`localStorage.selectedStoreId` is set, so UI tests on a shared server must
pin their own store (see `add_init_script(...selectedStoreId...)` uses).

Playwright note: two admin loads re-render the per-store cards from scratch
and wipe unsaved edits made before they land — `loadStoreSettings()` (the
Stores tab, after `?api=stores`) and the un-awaited `loadDashboard()` every
save fires. Gate on them with `fixtures.browser.wait_for_store_settings_loaded`
and `wait_for_dashboard_settled` (which reads the page's
`dashboardLoadSeq`/`dashboardRenderedSeq` counters) instead of sleeping.
Also, wait for a modal to close with `wait_for_selector("#modal.visible",
state="hidden")` — a `:not(.visible)` selector with the default visible-state
wait only matches during the fade-out.

## WordPress golden templates

The WP tier used to run `wp core install` (+ plugin activations, + the whole
WooCommerce/BTCPay install for checkout tests) per test. Now the first install
of each DB-affecting shape per session — `bare` (no cashupay plugin), `plugin`
(cashupay active), `woo` (plugin + WooCommerce + BTC store + one product) — is
kept as a golden tree under `app/.tmp/wp-golden-*`, and every later test
gets a `cp -a` clone of it (`fixtures/wordpress.py`).

Safe because the only per-instance state is serve-time: each clone gets a
fresh `wp-config.php` (own port, salts, release-server constants) whose
`WP_HOME`/`WP_SITEURL` constants override every URL the golden's DB stores,
plus its own router wrapper/vhost. `emulate_rewrites`, `php_workers`,
`release_api_base`, `release_channel` never touch the tree or DB, so all
fixture variants share the same three goldens. Escape hatch:
`CASHUPAY_WP_NO_TEMPLATE=1` restores the per-test real install; the template
contract itself is pinned by `wordpress/test_wp_template_clone.py`.

### SQLite drop-in driver

The fixture's `wp-config.php` turns on the sqlite-database-integration
plugin's newer "AST" driver (`WP_SQLITE_AST_DRIVER`). The legacy translator
driver wraps every statement in a deferred `BEGIN`; once another `php -S`
worker has committed, a connection that read first cannot upgrade to a write
and SQLite fails it with "database is locked" without consulting the busy
timeout. WooCommerce saves the cart session from a shutdown hook in exactly
that shape, which is where the empty-cart checkout flakes came from. The AST
driver opens writes with `BEGIN IMMEDIATE` and queues on the busy timeout
instead. `CASHUPAY_WP_SQLITE_AST_DRIVER=0` restores the legacy driver and
`CASHUPAY_WP_SQLITE_JOURNAL_MODE=WAL` sets the journal mode, both for A/B runs
only (WAL measured *worse* with the legacy driver). Pinned by
`wordpress/test_wp_fixture_sqlite_driver.py`.

## Upgrade tier (published-release upgrade matrix)

`upgrade/` installs REAL past releases from GitHub and upgrades each one to the
build under test, on the active backend, with payments. It is part of the
local suite only — CI passes `--skip-upgrade-tier` (`tests.yml`), because the
tier reaches GitHub and runs for several minutes per release.

The window is resolved at collection time from the releases API
(`fixtures/releases.py`): the five newest stable releases plus the newest
testing prerelease (older testing prereleases are pruned by the release
workflow, so only one exists). Zips are cached forever under
`tests/bin/.cache/releases/<tag>/`; the listing for an hour, with a stale
fallback when GitHub is unreachable.

| Test | What it proves |
|------|----------------|
| `test_upgrade_from_release[tag]` | the OLD release's own updater ("Update now") downloads, backs up, overlays and health-verifies the candidate; the merchant's login, store, products, API key and invoices survive; pre-upgrade unpaid invoices and fresh ones settle on Lightning and on-chain; the webhook fires; rolling back restores the old build, which still boots on the migrated database |
| `test_testing_to_main_and_back` | from the newest stable, via the cron line (`update.php`, auto-update on): testing → candidate, main → the stable again (older code on a newer database), testing → candidate, each hop healthy and paying on both rails |
| `test_old_data_dir_boots_on_candidate[tag]` | the Windows upgrade contract on Linux: a data dir written by the old release is carried into the build under test (served by the regular fixture in desktop shape) and keeps serving and settling |

The candidate is the standalone zip of the working tree (`scripts/build-standalone.sh`,
built once per session; `CASHUPAY_STANDALONE_ZIP` points at a prebuilt one). The
old install's updater is pointed at a local GitHub-shaped release server
(`fixtures/release_server.py::start_release_catalog`): releases that know
`CASHUPAY_RELEASE_API_BASE` take it from the environment, older ones get the one
default line of their test seam rewritten (`fixtures/installed_payserver.py`).
Seeding and verification go through `scripts/upgrade-smoke.php`, which answers
whatever wizard screen the installed release renders next and fails loudly on a
screen it does not know — add the screen there when the wizard grows one.

```bash
# one release, php -S
CASHUPAY_UPGRADE_FROM=v1.8 pytest upgrade/ -v
# the whole window on Apache
CASHUPAY_TEST_BACKEND=apache pytest upgrade/ -v
# window size
CASHUPAY_UPGRADE_STABLE_COUNT=3 pytest upgrade/
```

The Windows package half runs on a Windows machine:
`pwsh scripts/windows-upgrade-smoke.ps1 -Zip build/barebits-windows.zip`
extracts each old Windows package, seeds it, carries `app\data` into a fresh
copy of the candidate package and verifies it (no payments: the regtest stack
has no Windows fixtures).

## Workdir cleanup

A test that passes deletes its workdir in teardown; failures keep theirs for
postmortem, and a fully green session removes the shared stack dir too (the
`wp-golden-*` template dirs are removed with it). Set
`CASHUPAY_KEEP_WORKDIRS=1` to keep everything. `run-tests.sh` also checks
free disk/RAM before each pytest pass and refuses to start below 4G disk /
2G available RAM (warns below 10G / 4G) — `CASHUPAY_SKIP_PREFLIGHT=1`
bypasses. Every run ends with pytest's `--durations` ranking of the slowest
tests and fixture setups, so future speedup work starts from data. At session start, orphaned
fixture processes from previously killed runs (anything with `app/.tmp` in
its cmdline) are swept — set `CASHUPAY_NO_STALE_SWEEP=1` when deliberately
running two suites concurrently from this checkout.

## CI sharding

`pytest --split-group=M/N` runs the Mth of N deterministic file-level shards
(`fixtures/partition.py`; whole files stay together because of the
module-scoped shared servers). CI runs 3 shards in parallel; the shard count
lives in `.github/workflows/tests.yml` (matrix AND --split-group must match).

## Layout

```
tests/
  conftest.py          # top-level fixtures + plugin glue
  pytest.ini           # pytest config + marker registry
  requirements.txt     # pinned Python deps
  fixtures/            # subprocess managers (bitcoind, lnd, mint, payserver, …)
  e2e/                 # API-level end-to-end tests
  ui/                  # Playwright-driven browser tests
  wordpress/           # WordPress plugin tests (wp-cli + sqlite drop-in)
  upgrade/             # published-release upgrade matrix (local suite only)
  scripts/             # run-tests.sh, download-binaries.sh
  bin/                 # cached binaries (gitignored)
```

Per-test isolated dirs live in `app/.tmp/` (gitignored): inside the served
application tree on purpose, so a fixture server's data dir sits under the web
root like a shared-hosting install (wizard security screen, deny rules on real
files). See `SESSION_TMP` in `conftest.py`.

## Pinned versions

See `fixtures/binaries.py` for the canonical manifest. At time of writing:

| Component | Version |
|-----------|---------|
| Bitcoin Core | 28.0 |
| LND | 0.18.5-beta |
| PHP (static) | 8.3.31 |
| Nutshell | 0.16.5 |
| Playwright Chromium | bundled with playwright 1.49.1 |

## Selecting a subset

```bash
# Just the API-level e2e tests
pytest e2e/

# Skip slow tests (LN ops, failover)
pytest -m "not slow"

# Run a single test verbosely
pytest e2e/test_invoice_payment.py -v
```

## Bring-your-own binaries

If `bitcoind` / `lnd` are already on `PATH` and version-compatible, the binary
manager uses them and skips download. Override via env:

```bash
CASHUPAY_TEST_BITCOIND=/usr/local/bin/bitcoind \
CASHUPAY_TEST_LND=/usr/local/bin/lnd \
pytest e2e/
```
