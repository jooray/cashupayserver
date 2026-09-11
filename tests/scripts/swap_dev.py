#!/usr/bin/env python3
"""Unified dev driver for interactive submarine-swap testing.

Brings up the full single-bitcoind topology:
  - Boltz regtest stack (started via the boltz_regtest fixture)
  - Fulcrum + Electrum on the host pointed at Boltz's bitcoind
  - cashupayserver configured for swaps with the Electrum vpub
  - A Lightning channel from Electrum → Boltz's lnd-1 (so Electrum can
    pay BOLT11 invoices that route through Boltz)
  - Runs one demo swap end-to-end so you can see the on-chain UTXO appear
  - Launches the Electrum GUI for hands-on testing
  - Halts on Enter (TTY) or SIGTERM (when backgrounded)

Run from the repo root:
    python3 tests/scripts/swap_dev.py
"""
from __future__ import annotations

import os
import signal
import sys
import time
import uuid
from pathlib import Path

# Make tests/ importable so we can use the shared bring-up helpers.
TESTS_DIR = Path(__file__).resolve().parent.parent
if str(TESTS_DIR) not in sys.path:
    sys.path.insert(0, str(TESTS_DIR))

from fixtures.boltz_regtest import start_boltz_regtest, stop_boltz_regtest, _check_docker_available
from fixtures import swap_stack
from fixtures.swap_stack import (
    create_swap_invoice,
    drive_swap_to_terminal,
    fetch_invoice_row,
    fetch_swap_row,
    fund_electrum,
    open_channel_to_boltz_receiver,
    pay_bolt11,
    setup_payserver,
    start_electrum,
    start_fulcrum,
    stop_electrum,
    stop_fulcrum,
    stop_payserver,
    wait_for_lightning_route,
    electrum_bin,
)


def echo(msg: str) -> None:
    print(f"[swap-dev] {msg}", flush=True)


def launch_electrum_gui(electrum: swap_stack.ElectrumProc) -> None:
    """Stop the daemon (it holds the wallet exclusively) and launch the GUI
    against the same wallet path. Sets electrum.gui_process so cleanup
    can stop it."""
    if electrum.process and electrum.process.poll() is None:
        try:
            swap_stack.electrum_cli(electrum.datadir, "stop", timeout=10)
        except Exception:
            pass
        try:
            electrum.process.wait(timeout=10)
        except Exception:
            try:
                electrum.process.kill()
            except Exception:
                pass
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    import subprocess as _subprocess
    electrum.gui_process = _subprocess.Popen(
        [str(electrum_bin()), "--regtest", "--dir", str(electrum.datadir),
         "--wallet", str(electrum.wallet_path)],
        env=env,
        stdout=_subprocess.DEVNULL, stderr=_subprocess.DEVNULL,
    )


def main() -> int:
    skip = _check_docker_available()
    if skip:
        echo(f"FATAL: {skip}")
        echo("install docker + docker-compose-v2 and ensure `sudo -n docker version` works")
        return 1

    workdir = Path("/tmp") / f"swap-dev-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir()
    echo(f"workdir = {workdir}")

    boltz = None
    fulcrum = None
    electrum = None
    payserver = None

    try:
        echo("starting Boltz regtest stack (this can take ~60-90s on first run) ...")
        boltz = start_boltz_regtest()
        echo(f"Boltz API ready: {boltz.api_url}")
        echo(f"Boltz lnd-1 pubkey: {boltz.lnd1_pubkey}")

        echo("starting Fulcrum (Electrum protocol -> Boltz bitcoind) ...")
        fulcrum = start_fulcrum(workdir, boltz)

        echo("starting Electrum daemon + creating fresh wallet ...")
        electrum = start_electrum(workdir, fulcrum.port)
        echo(f"Electrum vpub = {electrum.vpub}")

        echo("funding Electrum on-chain with 5,000,000 sats from Boltz bitcoind ...")
        fund_electrum(electrum, boltz, 5_000_000)

        echo(f"opening 300,000-sat Lightning channel directly to Boltz's cln-2 (the BOLT11 receiver) ...")
        channel_point = open_channel_to_boltz_receiver(electrum, boltz, capacity_sat=300_000)
        echo(f"channel point: {channel_point}")
        wait_for_lightning_route(electrum, boltz.cln2_pubkey, timeout=10)

        echo("starting cashupayserver + seeding swap config ...")
        # strict=False so the demo doesn't blow up if Boltz hiccups; you can
        # still test strict mode by toggling in the admin.
        payserver = setup_payserver(workdir, electrum.vpub, boltz.api_url,
                                     strict_no_mint_fallback=False)
        echo(f"payserver: {payserver.url}")

        # ---- Demo swap ----
        target_sats = 100_000
        echo(f"creating demo invoice for {target_sats} sat ...")
        invoice = create_swap_invoice(payserver, target_sats)
        invoice_id = invoice["id"]
        bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
        echo(f"invoice {invoice_id}; bolt11 {bolt11[:40]}...")

        swap_row = fetch_swap_row(payserver, invoice_id)
        if swap_row:
            echo(f"swap_attempts: id={swap_row['id']} target={swap_row['target_onchain_amount_sats']} "
                 f"invoice_amount={swap_row['invoice_amount_sats']} "
                 f"fees=lockup:{swap_row['swap_lockup_fee_sats']}+pct:{swap_row['swap_percent_fee_sats']}")
            echo(f"merchant address: {swap_row['merchant_address']}")

        echo("paying the BOLT11 via Electrum lnpay (background) ...")
        import threading
        pay_done = threading.Event()
        pay_result: dict = {}
        def _pay():
            try:
                pay_result.update(pay_bolt11(electrum, bolt11, timeout=180))
            except Exception as e:
                pay_result["error"] = str(e)
            finally:
                pay_done.set()
        t = threading.Thread(target=_pay, daemon=True)
        t.start()

        echo("driving cron until swap reaches terminal state ...")
        try:
            final = drive_swap_to_terminal(payserver, invoice_id, boltz, timeout=180)
        except TimeoutError as e:
            echo(f"WARN: {e} (continuing into GUI mode anyway)")
            final = fetch_swap_row(payserver, invoice_id) or {}

        if final.get("status") == "invoice.settled":
            echo(f"DEMO SUCCESS: invoice settled, claim_txid={final['claim_txid']}")
        else:
            echo(f"DEMO FINAL state: {final.get('status')} err={final.get('error_message')}")

        pay_done.wait(timeout=10)
        if pay_result.get("error"):
            echo(f"pay_result error: {pay_result['error']}")

        # ---- Manual mode: GUI + halt ----
        echo("launching Electrum GUI for manual play ...")
        launch_electrum_gui(electrum)
        # mine a couple confirmations so the demo UTXO is visible
        boltz.mine_blocks(2)

        # Banner
        print()
        print("=" * 72)
        print("Manual swap-dev stack ready")
        print("=" * 72)
        print(f"Bitcoin RPC:         127.0.0.1:{boltz.bitcoind_rpc_port}  (Boltz's regtest bitcoind)")
        print(f"Fulcrum:             127.0.0.1:{fulcrum.port}")
        print(f"Boltz API:           {boltz.api_url}")
        print(f"Boltz web app:       http://localhost:8080")
        print(f"Payserver:           {payserver.url}")
        print(f"Payserver admin:     {payserver.url}/admin")
        print(f"Store ID:            {payserver.store_id}")
        print(f"API token:           {payserver.api_token}")
        print(f"Electrum vpub:       {electrum.vpub}")
        print(f"Electrum GUI:        wallet at {electrum.wallet_path}")
        print(f"Workdir:             {workdir}")
        print()
        print("Demo swap result:")
        print(f"  invoice {invoice_id} -> {final.get('status')}")
        if final.get("claim_txid"):
            print(f"  claim_txid: {final['claim_txid']}")
            print(f"  merchant address (paid {target_sats} sat target): {final.get('merchant_address')}")
        print()
        print("Try more swaps:")
        print(f"  curl -X POST {payserver.url}/api/v1/stores/{payserver.store_id}/invoices \\")
        print(f"       -H 'Authorization: token {payserver.api_token}' \\")
        print(f"       -H 'Content-Type: application/json' \\")
        print(f"       -d '{{\"amount\":75000,\"currency\":\"sat\"}}'")
        print(f"  (Then `lnpay <bolt11>` from the Electrum GUI's console)")
        print()
        print("Press Ctrl-C / send SIGTERM to clean up everything.")
        print()

        if sys.stdin.isatty():
            try:
                input("Press Enter to clean up and exit ... ")
            except (EOFError, KeyboardInterrupt):
                pass
        else:
            stop_event = signal.Event() if hasattr(signal, "Event") else None  # py3.13 has signal.Event
            done = [False]
            def _handler(*_):
                done[0] = True
            signal.signal(signal.SIGTERM, _handler)
            signal.signal(signal.SIGINT, _handler)
            while not done[0]:
                signal.pause()

    finally:
        echo("cleaning up ...")
        if payserver is not None:
            stop_payserver(payserver)
        if electrum is not None:
            stop_electrum(electrum)
        if fulcrum is not None:
            stop_fulcrum(fulcrum)
        if boltz is not None:
            echo("tearing down Boltz regtest stack ...")
            stop_boltz_regtest(boltz)
    return 0


if __name__ == "__main__":
    sys.exit(main())
