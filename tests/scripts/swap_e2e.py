#!/usr/bin/env python3
"""End-to-end submarine-swap test against a real Boltz regtest backend.

Prereqs:
  - Boltz regtest stack running at http://localhost:29001 (see
    https://github.com/BoltzExchange/regtest with the docker-compose
    override that maps boltz-backend-nginx :9001 to host :29001).
  - PHP 8.3 binary available at tests/bin/php-8.3.31/php.

What it does:
  1. Fresh SQLite data dir.
  2. Initialize the cashupayserver schema.
  3. Seed config so setup-complete passes; create one store with a regtest
     tpub and onchain settings; enable submarine swaps with boltz pointed
     at the local regtest stack.
  4. Spawn PHP's built-in server.
  5. POST to the public API to create a 60,000-sat invoice (above Boltz
     regtest minimum). This drives Invoice::create through the swap path
     and persists a swap_attempts row.
  6. Pay the returned BOLT11 from Boltz's LND-1 inside the regtest stack
     (acts as the customer wallet).
  7. Trigger cron.php repeatedly until swap_attempts.status reaches a
     terminal state.
  8. Assert invoice.status == 'Settled', claim_txid populated.

Run from the repo root:
    tests/bin/php-8.3.31/php -- /dev/null     # warms the PHP binary
    python3 tests/scripts/swap_e2e.py
"""
from __future__ import annotations

import json
import os
import shutil
import signal
import sqlite3
import subprocess
import sys
import time
import uuid
from pathlib import Path

import urllib.request
import urllib.error


REPO_ROOT = Path(__file__).resolve().parent.parent.parent
APP_ROOT = REPO_ROOT / "app"  # the served application tree
PHP = REPO_ROOT / "tests" / "bin" / "php-8.3.31" / "php"
BOLTZ_REGTEST_URL = "http://localhost:29001"
BOLTZ_SCRIPTS_CONTAINER = "boltz-scripts"

# Public BIP32 mainnet test vector xpub, re-encoded with tpub version bytes so
# bitcoind regtest accepts derived addresses. Identical to TEST_TPUB in
# tests/fixtures/onchain.py.
TEST_TPUB = (
    "tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B"
)


def echo(msg: str) -> None:
    print(f"[swap-e2e] {msg}", flush=True)


def http_json(url: str, *, method: str = "GET", body: dict | None = None, headers: dict | None = None, timeout: float = 10.0) -> tuple[int, dict | str]:
    """Use curl as the transport: PHP's built-in server is known to behave
    oddly with some urllib edge cases on POST bodies. curl is boring."""
    cmd = ["curl", "-s", "-o", "/dev/stdout", "-w", "\n__HTTP_STATUS__:%{http_code}",
           "-X", method, "--max-time", str(int(timeout))]
    for k, v in {"Accept": "application/json", **(headers or {})}.items():
        cmd += ["-H", f"{k}: {v}"]
    if body is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(body)]
    cmd += [url]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 5)
    raw_out = proc.stdout
    status = 0
    if "__HTTP_STATUS__:" in raw_out:
        body_str, _, status_line = raw_out.rpartition("\n__HTTP_STATUS__:")
        status = int(status_line.strip())
    else:
        body_str = raw_out
    try:
        return status, json.loads(body_str)
    except (json.JSONDecodeError, ValueError):
        return status, body_str


def php_eval(data_dir: Path, snippet: str) -> str:
    """Run a PHP snippet with CASHUPAY_DATA_DIR pre-defined."""
    code = (
        f"define('CASHUPAY_DATA_DIR', {str(data_dir)!r});\n"
        f"require_once {str(APP_ROOT / 'includes' / 'database.php')!r};\n"
        f"require_once {str(APP_ROOT / 'includes' / 'config.php')!r};\n"
        + snippet
    )
    res = subprocess.run([str(PHP), "-r", code], capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"php failed: {res.stderr}\n---stdout---\n{res.stdout}")
    return res.stdout.strip()


def init_db(data_dir: Path) -> None:
    php_eval(data_dir, "Database::initialize(); echo 'initialized';")


def seed_setup_complete(db_path: Path) -> None:
    """Bypass the setup wizard by inserting the key cashupayserver checks
    in Config::isSetupComplete()."""
    now = int(time.time())
    conn = sqlite3.connect(str(db_path))
    try:
        cur = conn.cursor()
        kvs = [
            ("setup_complete", json.dumps(True)),
        ]
        for k, v in kvs:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        conn.commit()
    finally:
        conn.close()


def create_store(db_path: Path) -> str:
    store_id = f"store_{uuid.uuid4().hex[:8]}"
    now = int(time.time())
    conn = sqlite3.connect(str(db_path))
    try:
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO stores (id, name, mint_unit, default_currency, created_at, "
            "onchain_xpub, onchain_address_type, onchain_network) "
            "VALUES (?, 'E2E Swap Store', 'sat', 'sat', ?, ?, 'P2WPKH', 'regtest')",
            (store_id, now, TEST_TPUB),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'e2e', ?, ?)",
            (
                "key_" + uuid.uuid4().hex[:8],
                # Match Auth::hashApiKey: sha256 of 'e2e-token'
                __import__("hashlib").sha256(b"e2e-token").hexdigest(),
                store_id,
                json.dumps(["btcpay.store.canmodifyinvoices"]),
                now,
            ),
        )
        conn.commit()
    finally:
        conn.close()
    return store_id


def configure_swaps(db_path: Path, store_id: str) -> None:
    """Enable swaps for the store. Swap settings are store-only now, so the
    enable flag / provider order / strict flag land on the store row; only the
    dev/test knobs (boltz regtest URL, cron key) remain config keys."""
    now = int(time.time())
    conn = sqlite3.connect(str(db_path))
    try:
        cur = conn.cursor()
        kvs = [
            ("swaps_boltz_regtest_url", json.dumps(BOLTZ_REGTEST_URL)),
            # Cron key needed by Background::trigger paths
            ("cron_key", json.dumps("e2e-cron-key")),
        ]
        for k, v in kvs:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        cur.execute(
            "UPDATE stores SET swaps_enabled = 1, swaps_provider_order = ?, "
            "strict_no_mint_fallback = 1 WHERE id = ?",
            (json.dumps(["boltz"]), store_id),
        )
        conn.commit()
    finally:
        conn.close()


def start_payserver(data_dir: Path, port: int) -> subprocess.Popen:
    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    # Kill the auto-updater for the duration of this script. Without this
    # the updater can overlay the working tree mid-run and silently revert
    # in-progress edits. Honoured by includes/updater.php::isDisabledForTests
    # via either the env var OR the sentinel file in CASHUPAY_DATA_DIR.
    env["CASHUPAY_UPDATER_DISABLED"] = "1"
    env.setdefault("CASHUPAY_DEV_FEE_BRANCH_ENDPOINT", "disabled")
    env.setdefault("CASHUPAY_USAGE_STATS_ENDPOINT", "disabled")
    data_dir.mkdir(parents=True, exist_ok=True)
    (data_dir / ".updater_disabled").write_text(
        "auto-updates disabled by tests/scripts/swap_e2e.py\n"
    )
    # Database::getDataDir() reads from `defined('CASHUPAY_DATA_DIR')`, not
    # getenv(). PHP's CGI sees env vars but doesn't auto-define constants from
    # them. So write a tiny router wrapper that defines the constant from the
    # env var on every request, then chains to the real router.php.
    wrapper = data_dir / "router_wrapper.php"
    wrapper.write_text(
        "<?php\n"
        "$d = getenv('CASHUPAY_DATA_DIR');\n"
        "if ($d !== false && $d !== '' && !defined('CASHUPAY_DATA_DIR')) {\n"
        "    define('CASHUPAY_DATA_DIR', $d);\n"
        "}\n"
        f"return require {str(APP_ROOT / 'router.php')!r};\n"
    )
    proc = subprocess.Popen(
        [str(PHP), "-S", f"127.0.0.1:{port}", "-t", str(APP_ROOT), str(wrapper)],
        cwd=str(APP_ROOT),
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            status, _ = http_json(f"http://127.0.0.1:{port}/", timeout=2.0)
            if status:
                return proc
        except Exception:
            pass
        time.sleep(0.3)
    proc.kill()
    raise RuntimeError("payserver did not come up in 30s")


def pay_via_boltz_lnd(bolt11: str) -> dict:
    """Use Boltz regtest's lnd-1 (the "customer" node) to pay the invoice."""
    result = subprocess.run(
        ["sudo", "-n", "docker", "exec", BOLTZ_SCRIPTS_CONTAINER, "bash", "-c",
         "source /etc/profile.d/utils.sh && lncli-sim 1 payinvoice -f " + bolt11],
        capture_output=True, text=True, timeout=120,
    )
    if result.returncode != 0:
        raise RuntimeError(f"payinvoice failed: stderr={result.stderr} stdout={result.stdout}")
    return {"stdout": result.stdout, "stderr": result.stderr}


def mine_blocks(n: int) -> None:
    subprocess.run(
        ["sudo", "-n", "docker", "exec", BOLTZ_SCRIPTS_CONTAINER, "bash", "-c",
         f"source /etc/profile.d/utils.sh && bitcoin-cli-sim-client -generate {n}"],
        check=True, capture_output=True,
    )


def fetch_swap_row(db_path: Path, invoice_id: str) -> dict | None:
    conn = sqlite3.connect(str(db_path))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM swap_attempts WHERE invoice_id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def fetch_invoice_row(db_path: Path, invoice_id: str) -> dict | None:
    conn = sqlite3.connect(str(db_path))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def run_cron(payserver_url: str) -> dict:
    status, body = http_json(f"{payserver_url}/cron.php?key=e2e-cron-key", method="GET")
    return body if isinstance(body, dict) else {"raw": body, "status": status}


def main() -> int:
    workdir = Path("/tmp") / f"swap-e2e-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True)
    data_dir = workdir / "data"
    data_dir.mkdir()
    echo(f"workdir={workdir}")

    echo("initializing DB...")
    init_db(data_dir)
    db_path = data_dir / "cashupay.sqlite"
    seed_setup_complete(db_path)
    store_id = create_store(db_path)
    echo(f"created store {store_id}")
    configure_swaps(db_path, store_id)
    echo("swap config seeded")

    # Pick a free port and spawn the server
    import socket
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    echo(f"starting payserver on 127.0.0.1:{port}")
    proc = start_payserver(data_dir, port)
    base = f"http://127.0.0.1:{port}"
    echo("payserver up")

    try:
        # Boltz regtest minimum is 50,000 sats; ask for 60,000 to be safely above.
        target_sats = 60_000
        echo(f"creating invoice for {target_sats} sats...")
        status, body = http_json(
            f"{base}/api/v1/stores/{store_id}/invoices",
            method="POST",
            body={"amount": target_sats, "currency": "sat"},
            headers={"Authorization": "token e2e-token"},
        )
        if status != 200 or not isinstance(body, dict) or "id" not in body:
            echo(f"invoice creation failed: status={status} body={body}")
            return 1
        invoice_id = body["id"]
        # Public API exposes bolt11 under checkout.paymentMethods.BTC-LightningNetwork.destination.
        ln_method = (body.get("checkout", {}) or {}).get("paymentMethods", {}).get("BTC-LightningNetwork", {})
        bolt11 = ln_method.get("destination", "")
        # Fall back to fetching from the DB row if the API shape changed.
        if not bolt11:
            row = fetch_swap_row(db_path, invoice_id)
            if row:
                bolt11 = row["lightning_invoice"]
        rail = body.get("payment_rail") or body.get("paymentRail")
        echo(f"invoice id={invoice_id} payment_rail={rail!r}")
        echo(f"bolt11={bolt11[:80]}...")

        swap = fetch_swap_row(db_path, invoice_id)
        if not swap:
            echo("no swap_attempts row created — feature did not engage")
            return 1
        echo(f"swap_attempts: provider={swap['provider']} swap_id_external={swap['swap_id_external']} "
             f"lockup_address={swap['lockup_address']}")
        echo(f"  target={swap['target_onchain_amount_sats']} invoice_amount={swap['invoice_amount_sats']} "
             f"lockup_fee={swap['swap_lockup_fee_sats']} pct_fee={swap['swap_percent_fee_sats']}")

        echo("paying BOLT11 from boltz lnd-1...")
        try:
            res = pay_via_boltz_lnd(bolt11)
        except Exception as e:
            echo(f"payinvoice raised: {e}")
            # Continue — it may have started settling already.

        # Loop cron until terminal.
        deadline = time.monotonic() + 180
        terminal = {"invoice.settled", "swap.expired", "transaction.refunded", "transaction.failed",
                    "invoice.expired", "claim.confirmed", "error"}
        last_status = None
        last_invoice_status = None
        while time.monotonic() < deadline:
            cron_res = run_cron(base)
            swap_now = fetch_swap_row(db_path, invoice_id)
            inv_now = fetch_invoice_row(db_path, invoice_id)
            cur = swap_now["status"] if swap_now else "(no row)"
            inv_cur = inv_now["status"] if inv_now else "(no row)"
            if cur != last_status or inv_cur != last_invoice_status:
                echo(f"  state: swap={cur!r} invoice={inv_cur!r} claim_txid={swap_now.get('claim_txid')}")
                last_status = cur
                last_invoice_status = inv_cur
            if cur in terminal:
                break
            # Mine a block occasionally so on-chain confirms tick over.
            mine_blocks(1)
            time.sleep(2)

        swap_final = fetch_swap_row(db_path, invoice_id)
        inv_final = fetch_invoice_row(db_path, invoice_id)
        echo("---")
        echo(f"FINAL swap_attempts: {json.dumps({k:v for k,v in swap_final.items() if k not in ('claim_privkey_hex',)}, indent=2)}")
        echo(f"FINAL invoice.status={inv_final['status']}")

        ok = (inv_final["status"] == "Settled"
              and swap_final["status"] == "invoice.settled"
              and swap_final["claim_txid"])
        if ok:
            echo("PASS — swap completed successfully against real Boltz regtest")
            return 0
        echo("FAIL — final state did not match expected")
        return 2

    finally:
        echo("stopping payserver")
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


if __name__ == "__main__":
    sys.exit(main())
