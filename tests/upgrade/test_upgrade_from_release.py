"""Upgrade each release in the window to the build under test, the way a
merchant's server does it: the OLD release's own updater downloads, backs up,
overlays and health-verifies the new build (its code runs the upgrade, not
ours), then the new code boots on the old release's database.

Per source release:
  1. install the published zip, serve it on the active backend, walk its
     wizard with the regtest mints, wire the regtest xpub, products, API key,
     webhook — a lived-in install
  2. pay invoices BEFORE the upgrade: two over Lightning, one on-chain, and
     leave one of each unpaid
  3. "Update now" from the admin dashboard; wait for the manual run's verdict
  4. the new build is live and healthy: BUILD_INFO advanced to the candidate,
     no rollback, no migration error, the merchant's login/store/products/API
     key/invoices all intact
  5. money still moves: the PRE-upgrade unpaid invoices settle on both rails,
     fresh invoices settle on both rails, the webhook fires
  6. the backup works: roll back, the old build is live again and still boots
     on the now-migrated database
"""
from __future__ import annotations

import pytest

from fixtures.installed_payserver import read_build_info
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.onchain import OnchainContext
from fixtures.releases import Release
from fixtures.release_server import ReleaseServer
from fixtures.upgrade_flow import (
    assert_healthy_apply,
    create_ln_invoice,
    create_onchain_invoice,
    pay_both_rails,
    pay_ln_invoice,
    pay_onchain_invoice,
    rollback_update,
    seed_install,
    trigger_manual_update,
    update_status,
    verify_install,
    wait_webhook_settled,
)
from fixtures.webhook_sink import WebhookSink


def test_upgrade_from_release(
    source_release: Release,
    install_release,
    candidate_catalog: ReleaseServer,
    candidate_info: dict[str, str],
    mint: MintHandle,
    backup_mint: MintHandle,
    onchain: OnchainContext,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
) -> None:
    handle, install = install_release(source_release, candidate_catalog)
    old_info = read_build_info(install)
    assert old_info.get("VERSION") == source_release.version, old_info
    if old_info.get("COMMIT_SHA") == candidate_info["COMMIT_SHA"]:
        # Only when the working tree is exactly the published commit (clean
        # checkout of the newest testing release): to the updater that is
        # "already current", and there is no hop to test.
        pytest.skip(f"{source_release.tag} is this exact build ({old_info['COMMIT_SHA'][:12]}); nothing to upgrade")

    # --- 1. a lived-in install on the old release --------------------------
    seeded = seed_install(
        handle, install, source_release.tag,
        mint=mint, backup_mint=backup_mint, onchain=onchain, webhook_sink=webhook_sink,
    )

    # --- 2. payments before the upgrade --------------------------------------
    ln_paid_1, _ = create_ln_invoice(seeded)
    pay_ln_invoice(seeded, lnd_payer, ln_paid_1)
    ln_paid_2, _ = create_ln_invoice(seeded)
    pay_ln_invoice(seeded, lnd_payer, ln_paid_2)
    oc_paid_1, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_paid_1)
    ln_pending, _ = create_ln_invoice(seeded)
    oc_pending, _ = create_onchain_invoice(seeded)
    pre_upgrade_invoices = len(seeded.invoice_ids)

    # --- 3. "Update now" -----------------------------------------------------
    before = update_status(seeded)
    assert before.get("manual_blocked") in (None, ""), f"updater blocked on {source_release.tag}: {before}"
    status = trigger_manual_update(seeded)
    assert_healthy_apply(
        seeded, status,
        expect_version=candidate_info["VERSION"], expect_sha=candidate_info["COMMIT_SHA"],
    )
    on_disk = read_build_info(install)
    assert on_disk.get("COMMIT_SHA") == candidate_info["COMMIT_SHA"], on_disk
    assert not (install / ".htaccess.new").exists(), ".htaccess was held back on a pristine install"

    # --- 4. the merchant's world is intact ---------------------------------
    result = verify_install(seeded, min_invoices=pre_upgrade_invoices)
    assert result["current_sha"] == candidate_info["COMMIT_SHA"], result
    assert set(seeded.invoice_ids) <= set(result["invoice_ids"]), (
        f"invoices lost across the upgrade: {set(seeded.invoice_ids) - set(result['invoice_ids'])}"
    )
    for inv_id in (ln_paid_1, ln_paid_2, oc_paid_1):
        assert seeded.greenfield.get_invoice(seeded.store_id, inv_id)["status"] == "Settled", inv_id

    # --- 5. money still moves ----------------------------------------------
    pay_ln_invoice(seeded, lnd_payer, ln_pending)
    pay_onchain_invoice(seeded, onchain, oc_pending)
    new_ln, new_oc = pay_both_rails(seeded, lnd_payer, onchain)
    wait_webhook_settled(seeded, webhook_sink, new_ln)
    cron = seeded.handle.trigger_cron_json()
    assert cron, "cron pass returned no summary after the upgrade"

    # --- 6. the backup works -------------------------------------------------
    rb = rollback_update(seeded)
    assert rb.get("success") is True, f"rollback refused: {rb}"
    restored = read_build_info(install)
    assert restored.get("COMMIT_SHA") == old_info["COMMIT_SHA"], (
        f"rollback left BUILD_INFO at {restored.get('COMMIT_SHA')}, expected {old_info['COMMIT_SHA']}"
    )
    # The old build now runs on a database the new build migrated: it must
    # still boot and serve the merchant (this is also what a bad-health
    # auto-rollback would leave behind).
    seeded.admin.login(seeded.password)
    verify_install(seeded, min_invoices=len(seeded.invoice_ids))
