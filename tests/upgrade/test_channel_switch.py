"""Channel switching on a live install: testing -> main -> testing.

A merchant on the newest stable flips to the testing channel and receives the
candidate build (an upgrade); flips back to main and receives the stable
release again (an older build applied over a database the newer one has
already migrated); then returns to testing. Every hop runs through the
operator's cron path — update.php with the cron key, auto-update enabled — so
this also covers the automatic apply, where test_upgrade_from_release covers
the dashboard's "Update now".

After each hop the install must be healthy (verified by the updater's own
health probe, no rollback), serve the merchant (login, store, products, API
key, invoices) and still settle payments on both rails.
"""
from __future__ import annotations

import time

import pytest

from fixtures.installed_payserver import point_updater_at, read_build_info
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.onchain import OnchainContext
from fixtures.releases import Release, build_info_from_zip, ensure_standalone_zip
from fixtures.release_server import ReleaseServer
from fixtures.upgrade_flow import (
    SeededInstall,
    create_ln_invoice,
    pay_both_rails,
    pay_ln_invoice,
    run_update_endpoint,
    seed_install,
    set_channel,
    update_status,
    verify_install,
)


def _hop(
    seeded: SeededInstall, channel: str, *, expect_sha: str, expect_version: str,
    releases_base: str,
) -> None:
    """Switch channel, let the cron line apply whatever that channel serves,
    and assert the install came up healthy on the expected build.

    With auto-update on, the app's own cron (nudged by every cron.php call
    the payment steps make) races our explicit update.php call: it may stamp
    the daily throttle a moment after set_channel cleared it ("not_due"), or
    apply the very same build first ("no_update"). Both are the automatic
    path doing its job, so the hop retries the former and accepts the latter
    when the install already runs the expected build."""
    out: dict = {}
    for attempt in range(6):
        set_channel(seeded, channel)  # also clears the daily throttle
        out = run_update_endpoint(seeded)
        assert out.get("ok") is True, f"update.php on channel {channel} errored: {out}"
        if out.get("applied") is True:
            assert out.get("result") == "healthy", f"update to {channel} not verified healthy: {out}"
            break
        if out.get("result") == "no_update" and update_status(seeded).get("current_sha") == expect_sha:
            break  # the background nudge applied it first
        assert out.get("skipped") == "not_due", f"update.php on channel {channel} did not apply: {out}"
        time.sleep(1)
    else:
        raise AssertionError(f"update.php on channel {channel} kept reporting not_due: {out}")
    # A published release's updater knows only GitHub; re-point the install
    # at the rig's release server so the NEXT hop stays inside the rig.
    point_updater_at(seeded.install, releases_base)
    status = update_status(seeded)
    assert status.get("current_sha") == expect_sha, (
        f"after switching to {channel}: running {status.get('current_sha')}, expected {expect_sha}"
    )
    assert status.get("current_version") == expect_version, status.get("current_version")
    assert not status.get("last_auto_rollback"), status.get("last_auto_rollback")
    assert status.get("pending_verify") in (None, {}), status.get("pending_verify")
    assert read_build_info(seeded.install).get("COMMIT_SHA") == expect_sha


def test_testing_to_main_and_back(
    install_release,
    switch_catalog: ReleaseServer,
    candidate_info: dict[str, str],
    newest_stable: Release,
    mint: MintHandle,
    backup_mint: MintHandle,
    onchain: OnchainContext,
    lnd_payer: LndHandle,
) -> None:
    stable_info = build_info_from_zip(ensure_standalone_zip(newest_stable))
    if stable_info["COMMIT_SHA"] == candidate_info["COMMIT_SHA"]:
        pytest.skip("the newest stable is this exact build; the channel switch would be a no-op")

    # Auto-update ON: the cron line must apply on its own, no click.
    handle, install = install_release(
        newest_stable, switch_catalog,
        extra_env={"CASHUPAY_AUTO_UPDATE_ENABLED": "1"}, label="switch",
    )
    seeded = seed_install(
        handle, install, newest_stable.tag, mint=mint, backup_mint=backup_mint, onchain=onchain,
    )
    first_ln, _ = create_ln_invoice(seeded)
    pay_ln_invoice(seeded, lnd_payer, first_ln)

    # On main, the newest stable is already current: nothing to apply.
    set_channel(seeded, "main")
    out = run_update_endpoint(seeded)
    assert out.get("ok") is True and out.get("result") == "no_update", out

    # testing -> the candidate (upgrade)
    base = switch_catalog.releases_base
    _hop(seeded, "testing", expect_sha=candidate_info["COMMIT_SHA"],
         expect_version=candidate_info["VERSION"], releases_base=base)
    verify_install(seeded, min_invoices=len(seeded.invoice_ids))
    pay_both_rails(seeded, lnd_payer, onchain)

    # main -> the stable release again (older code, newer database)
    _hop(seeded, "main", expect_sha=stable_info["COMMIT_SHA"],
         expect_version=stable_info["VERSION"], releases_base=base)
    verify_install(seeded, min_invoices=len(seeded.invoice_ids))
    pay_both_rails(seeded, lnd_payer, onchain)

    # testing -> the candidate once more
    _hop(seeded, "testing", expect_sha=candidate_info["COMMIT_SHA"],
         expect_version=candidate_info["VERSION"], releases_base=base)
    verify_install(seeded, min_invoices=len(seeded.invoice_ids))
    pay_both_rails(seeded, lnd_payer, onchain)
