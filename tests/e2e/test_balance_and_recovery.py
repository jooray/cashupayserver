"""Local proof-based balance and proof persistence after settlement."""
from __future__ import annotations

import time

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle


INVOICE_AMOUNT_SAT = 1500


def _settle_an_invoice(configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> str:
    """Create + pay + wait for settle. Returns invoice ID."""
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(amount_sat), currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        got = configured.greenfield.get_invoice(configured.store_id, invoice["id"])
        if got["status"] == "Settled":
            return invoice["id"]
        time.sleep(0.3)
    raise AssertionError(f"invoice {invoice['id']} did not settle")


def test_local_balance_matches_settled_amount(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    _settle_an_invoice(configured, lnd_payer, INVOICE_AMOUNT_SAT)

    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    body = r.json()
    # Local balance reads from cached proofs only — no mint contact required.
    assert body["balance"] == INVOICE_AMOUNT_SAT, body


def test_proofs_persisted_locally_after_settle(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    _settle_an_invoice(configured, lnd_payer, INVOICE_AMOUNT_SAT)

    with configured.handle.db() as db:
        proof_tables = db.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%proof%'"
        ).fetchall()
        assert proof_tables, "no proof storage tables found"
        # WalletStorage::initializeSchema creates the proofs table; count rows.
        total_proofs = 0
        for t in proof_tables:
            name = t["name"]
            try:
                row = db.execute(f"SELECT COUNT(*) AS c FROM {name}").fetchone()
                total_proofs += row["c"]
            except Exception:
                pass
        assert total_proofs > 0, "no proofs stored after settle"


def test_subsequent_invoice_adds_to_balance(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    _settle_an_invoice(configured, lnd_payer, 1000)
    _settle_an_invoice(configured, lnd_payer, 500)

    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    assert r.json()["balance"] == 1500
