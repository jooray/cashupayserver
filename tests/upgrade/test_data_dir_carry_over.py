"""The Windows desktop upgrade, on Linux: a data dir written by each release
in the window is carried into the build under test.

The desktop package has no in-app updater; a merchant extracts the new
package and copies the old app\\data folder into it (windows/README). That
reduces to: the new code must boot on the old release's database, migrate
it, and keep serving the merchant — the same database half of every web
upgrade, but without any updater code in the loop, so it isolates the
migration path. scripts/windows-upgrade-smoke.ps1 runs the full package flow
on a Windows machine; this test covers the database contract on every suite
run, with payments.
"""
from __future__ import annotations

import uuid

from conftest import SESSION_TMP, _maybe_remove_workdir, _test_went_green
from fixtures.api_client import AdminClient, GreenfieldClient
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.onchain import OnchainContext
from fixtures.payserver import start_payserver, stop_payserver
from fixtures.releases import Release
from fixtures.release_server import ReleaseServer
from fixtures.upgrade_flow import (
    CANDIDATE_ENV,
    SeededInstall,
    create_ln_invoice,
    create_onchain_invoice,
    pay_both_rails,
    pay_ln_invoice,
    pay_onchain_invoice,
    seed_install,
    verify_install,
)
from upgrade.conftest import copy_data_dir


def test_old_data_dir_boots_on_candidate(
    request,
    source_release: Release,
    install_release,
    candidate_catalog: ReleaseServer,
    mint: MintHandle,
    backup_mint: MintHandle,
    onchain: OnchainContext,
    lnd_payer: LndHandle,
) -> None:
    # --- the old package, used for a while --------------------------------
    handle, install = install_release(source_release, candidate_catalog, label=f"carry-{source_release.tag}")
    seeded = seed_install(
        handle, install, source_release.tag, mint=mint, backup_mint=backup_mint, onchain=onchain,
    )
    paid_ln, _ = create_ln_invoice(seeded)
    pay_ln_invoice(seeded, lnd_payer, paid_ln)
    pending_ln, _ = create_ln_invoice(seeded)
    pending_oc, _ = create_onchain_invoice(seeded)
    pre = len(seeded.invoice_ids)

    # "Stop the app" — then copy app\data into the new package.
    handle.server.stop(grace_s=10.0)
    workdir = SESSION_TMP / f"upgrade-carry-new-{source_release.tag}-{uuid.uuid4().hex[:8]}"
    workdir.mkdir(parents=True)
    copy_data_dir(install / "data", workdir / "data")

    # --- the new package (this working tree), desktop shape -----------------
    new_handle = start_payserver(workdir, extra_env={"CASHUPAY_DESKTOP": "1", **CANDIDATE_ENV})
    try:
        admin = AdminClient(new_handle.url)
        admin.login(seeded.password)
        carried = SeededInstall(
            handle=new_handle, install=workdir, tag=f"{source_release.tag}->candidate",
            admin=admin, greenfield=GreenfieldClient(new_handle.url, seeded.api_key),
            store_id=seeded.store_id, api_key=seeded.api_key,
            product_titles=seeded.product_titles, invoice_ids=list(seeded.invoice_ids),
        )
        result = verify_install(carried, min_invoices=pre)
        assert set(seeded.invoice_ids) <= set(result["invoice_ids"]), result
        assert carried.greenfield.get_invoice(carried.store_id, paid_ln)["status"] == "Settled"

        pay_ln_invoice(carried, lnd_payer, pending_ln)
        pay_onchain_invoice(carried, onchain, pending_oc)
        pay_both_rails(carried, lnd_payer, onchain)
        assert new_handle.trigger_cron_json(), "cron pass returned no summary on the carried-over data dir"
    finally:
        stop_payserver(new_handle)
        _maybe_remove_workdir(workdir, _test_went_green(request))
