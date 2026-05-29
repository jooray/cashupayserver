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
5. Run `pytest`.

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
  scripts/             # run-tests.sh, download-binaries.sh
  bin/                 # cached binaries (gitignored)
  .tmp/                # per-test isolated dirs (gitignored)
```

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
