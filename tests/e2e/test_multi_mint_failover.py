"""Multi-mint failover: when the primary mint is unreachable, the invoice
should mint at the next backup and record which mint actually served it."""
from __future__ import annotations

from conftest import ConfiguredPayserver
from fixtures.nutshell import MintHandle


def _add_backup_mint(configured: ConfiguredPayserver, mint_url: str, *, priority: int = 100) -> None:
    """admin.php POST action=add_backup_mint."""
    r = configured.admin._post_action(
        "add_backup_mint",
        store_id=configured.store_id,
        mint_url=mint_url,
        unit="sat",
        priority=str(priority),
    )
    assert r, f"add_backup_mint returned empty: {r}"


def _set_primary_mint_url(configured: ConfiguredPayserver, mint_url: str) -> None:
    with configured.handle.db() as db:
        db.execute("UPDATE stores SET mint_url = ? WHERE id = ?", (mint_url, configured.store_id))


def test_invoice_falls_over_to_backup_when_primary_is_dead(
    configured: ConfiguredPayserver,
    mint: MintHandle,
) -> None:
    # Add the real (working) mint as a backup before nuking primary.
    _add_backup_mint(configured, mint.url, priority=100)
    # Point primary at a TCP port that nothing is listening on.
    dead_url = "http://127.0.0.1:1"
    _set_primary_mint_url(configured, dead_url)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="500", currency="sat"
    )
    assert invoice["status"] in ("New", "Processing")
    assert invoice.get("checkout", {}).get("paymentMethods", {}).get("BTC-LightningNetwork"), invoice

    # The invoice row should record the actual mint that served it.
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT mint_url FROM invoices WHERE id = ?", (invoice["id"],)
        ).fetchone()
    assert row is not None
    assert row["mint_url"] == mint.url, f"expected backup mint {mint.url}, got {row['mint_url']}"


def test_invoice_creation_fails_when_no_mints_are_reachable(
    configured: ConfiguredPayserver,
) -> None:
    """With no backups and primary dead, the API should surface the error."""
    _set_primary_mint_url(configured, "http://127.0.0.1:1")

    import pytest
    with pytest.raises(RuntimeError, match="invoice-error"):
        configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")
