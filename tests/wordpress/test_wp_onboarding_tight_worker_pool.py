"""Onboarding on a rewrite-hostile host with a tight PHP worker pool.

Local WP (and managed nginx hosting like it) combines two constraints: no
.htaccess rewrites — so the alongside install's canonical /api/v1 URLs fall
through into WordPress and are replayed by the plugin's API bridge — and a
small fixed per-site PHP worker pool that always has some of its workers
taken by unrelated traffic (admin-ajax heartbeats, asset requests, WP-cron).
Every canonical-URL call the plugin makes to its own install then needs
THREE simultaneous PHP requests on the same site (the wp-admin request
making the call, the bridged WordPress request, api.php); when fewer than
three workers are free the innermost request starves and the call dies as a
bare cURL 28 timeout. That was the exact failure merchants hit at "Last
step: connect WooCommerce" on Local WP.

The fix routes the plugin's own Greenfield calls to the install's api.php
directly (the query-path transport — see barebits_api_transport_url), one
loopback deep. This test recreates the starved host deterministically:
PHP_CLI_SERVER_WORKERS=2 yields three serving processes (the master accepts
too), and one of them is pinned for the whole test by a long-running
request — leaving exactly TWO free workers, one short of what the bridge
chain needs. Onboarding must complete end to end anyway, including the
WooCommerce wiring's webhook registration.

The install step's probe is part of the same story: it used to ride the
canonical /api/v1 route, starve on exactly this pool shape, and warn that
the site "is blocked from making HTTP requests to its own URL" — a false
alarm (loopback works fine here, as the rest of the flow proves). The probe
now goes to api.php directly, one loopback deep, so on this host it must
SUCCEED and the install step must show no warning at all.
"""
from __future__ import annotations

import json
import threading
import time

import pytest
import requests

from wordpress.conftest import post_onboarding, wp_login, wp_option
from fixtures.setup_helpers import SetupWizard, wizard_heading
from fixtures.wordpress import WordPressHandle, install_woocommerce

pytestmark = pytest.mark.wordpress

PIN_SECONDS = 600


def _pin_one_worker(wp: WordPressHandle) -> None:
    """Occupy one PHP worker for the rest of the test with a request to a
    dropped-in sleeper script (sleep() burns no CPU, so max_execution_time
    never interrupts it). Waits until the sleeper has provably STARTED — a
    pin that is still in the listen queue would not constrain anything."""
    marker = wp.workdir / "pin-started"
    (wp.wp_root / "barebits-test-pin.php").write_text(
        "<?php touch(" + repr(str(marker)) + "); sleep(" + str(PIN_SECONDS) + ");\n"
    )
    def _hold() -> None:
        try:
            requests.get(f"{wp.url}/barebits-test-pin.php", timeout=PIN_SECONDS + 30)
        except requests.RequestException:
            pass  # server torn down mid-sleep at test end — expected

    threading.Thread(target=_hold, daemon=True).start()
    deadline = time.monotonic() + 30
    while not marker.exists():
        assert time.monotonic() < deadline, "worker pin never started"
        time.sleep(0.1)


def _walk_wizard_declining_everything(wp: WordPressHandle) -> None:
    """The managed install's wizard, declining every optional rail (no mint
    fixtures needed). Real *.php files execute on this host, so setup.php is
    served directly — the wizard itself never nests loopback requests."""
    wiz = SetupWizard(wp.url, setup_path="/barebits/setup.php")
    body = wiz.accept_terms()
    assert "store" in wizard_heading(body).lower()
    wiz.post(step="store", store_name="Tight Pool Store", default_currency="sat")
    wiz.post(step="onchain", onchain_action="skip")
    wiz.post(step="lightning", lightning_action="skip")
    wiz.post(step="swaps", swaps_enabled="0")
    body = wiz.post(step="mints", mints_enabled="0")
    heading = wizard_heading(body)
    assert "cron" not in heading.lower(), heading


def test_onboarding_completes_on_a_starved_worker_pool(wordpress_hostile_tight_pool) -> None:
    wp = wordpress_hostile_tight_pool
    _pin_one_worker(wp)
    s = wp_login(wp)

    post_onboarding(s, wp, "barebits_choose_mode", {"barebits_mode": "install"})
    body = post_onboarding(s, wp, "barebits_run_install")
    assert "BareBits is installed at" in body, body[:2000]
    # Loopback works on this host — only the canonical /api/v1 bridge chain
    # starves. The probe goes to api.php directly (one loopback deep, one
    # free worker suffices), so it must succeed and the old false alarm
    # ("blocked from making HTTP requests to its own URL") must be gone.
    # Both warning flashes carry "Heads up"; "loopback" pins the unreachable
    # one's wording specifically. A bare "blocked" substring is off limits:
    # the same response embeds the wizard iframe's JavaScript, whose
    # sessionStorage comment tripped it.
    assert "Heads up" not in body, body[:2000]
    assert "loopback" not in body, body[:2000]

    _walk_wizard_declining_everything(wp)

    body = post_onboarding(s, wp, "barebits_collect_provision")
    assert "Connected!" in body, body[:2000]
    store_id = wp_option(wp, "barebits_store_id")
    assert store_id

    # The step that used to die with "Wiring failed: cURL error 28" on Local
    # WP: webhook registration is a live Greenfield call to the install, and
    # on this starved pool it only survives via the direct api.php transport
    # (two simultaneous requests, not the bridge chain's three).
    install_woocommerce(wp)
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": "0"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]

    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_gateway_url
    assert wp_option(wp, "barebits_wired_at") != ""
    # The webhook really landed on the server, secret shared with the gateway.
    webhook_opt = json.loads(
        wp.wp_cli("option", "get", "btcpay_gf_webhook", "--format=json").stdout.strip()
    )
    with wp.db() as db:
        row = db.execute(
            "SELECT secret, enabled FROM webhooks WHERE id = ?", (webhook_opt["id"],)
        ).fetchone()
    assert row is not None and row["enabled"] == 1
    assert row["secret"] == webhook_opt["secret"]
