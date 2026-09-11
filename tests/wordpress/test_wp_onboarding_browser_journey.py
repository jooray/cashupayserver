"""The merchant journey, end to end, in a real browser.

The requests-driven install-mode suite (test_wp_onboarding_install_mode.py)
pins the server-side behavior of every onboarding step; what it cannot see is
what a merchant's BROWSER does between those steps — form actions resolving
against the wrong URL mode, native validation blocking a submit, the wizard
iframe navigating somewhere unexpected. Both regressions this file guards
were exactly that kind of blind spot:

  - the install-mode flash said "finish the wizard, then come back here"
    while the wizard was embedded right below the notice, and
  - the wizard's on-chain screen was the first whose form posted to the
    absolute Urls::setup() — which, with no URL mode detected (a host that
    routes neither extension-less nor PATH_INFO URLs), built a
    /router.php/setup.php URL that WordPress swallowed with its themed 404,
    dumping the merchant out of setup when they clicked "Skip for now".

Two scenarios, both driven with Playwright:

  1. The full journey on a friendly (Apache-like) host: upload + activate
     the BUILT plugin zip through wp-admin, choose install-alongside, land in
     the wizard (full screen by default — exit/re-expand are exercised too),
     walk it (no password screen — the admin is pre-seeded), return through
     the wizard's own finish button (which collects the credentials with no
     manual step) — clicked while WordPress sits behind its maintenance
     screen (a wp-cron auto-update mid-onboarding, the collision a real
     merchant hit), which the handoff must wait out — then wire WooCommerce.
  2. The SAME full journey on a rewrite-hostile host (nginx-style: real
     *.php files execute, everything else falls into WordPress — Local WP's
     layout): the plugin's API bridge must keep the /api/v1 routes answering
     (no warning, and the WooCommerce wiring's webhook registration works),
     with the manual "I finished the wizard" fallback button standing in for
     the wizard's return link.
"""
from __future__ import annotations

import time

import pytest
import requests

from wordpress.conftest import wp_option
from fixtures.wordpress import (
    WP_ADMIN_PASSWORD,
    WP_ADMIN_USER,
    WordPressHandle,
    install_woocommerce,
)

pytestmark = [pytest.mark.wordpress, pytest.mark.ui]

# The wizard iframe on the provision step (see barebits_render_step_provision).
WIZARD_IFRAME = "iframe[title='BareBits setup']"


def _login(page, wp: WordPressHandle, redirect_to: str) -> None:
    page.goto(f"{wp.url}/wp-login.php?redirect_to={redirect_to}")
    page.fill("#user_login", WP_ADMIN_USER)
    page.fill("#user_pass", WP_ADMIN_PASSWORD)
    page.click("#wp-submit")


def _open_onboarding(page, wp: WordPressHandle) -> None:
    page.goto(f"{wp.url}/wp-admin/admin.php?page=barebits")
    page.wait_for_selector("h1:has-text('BareBits')")


def _choose_install_and_run(page, wp: WordPressHandle) -> str:
    """Chooser (checks shown inline) -> confirmation -> run the installer.
    Returns the flash text."""
    page.wait_for_selector("#barebits-mode-install")
    page.check("#barebits-mode-install")
    page.click("#submit")

    page.wait_for_selector("h2:has-text('Install BareBits alongside WordPress')")
    # Download + unpack of the fixture release zip; generous ceiling for CI.
    page.click("#submit")
    # Target the flash paragraph precisely: WooCommerce and friends drop their
    # own .notice boxes onto wp-admin pages.
    page.wait_for_selector(".notice p:has-text('BareBits is installed at')", timeout=180_000)
    flash = page.inner_text(".notice p:has-text('BareBits is installed at')")

    # The wizard renders right below this very notice: any "come back here"
    # choreography reads as "you are on the wrong page" and sends merchants
    # hunting for a page they are already on.
    assert "come back here" not in flash, flash
    return flash


def _walk_wizard_in_iframe(page) -> None:
    """Drive the embedded BareBits wizard the way a merchant would, declining
    every optional rail. Works identically on friendly and hostile hosts —
    which is the point: every step form must post back to the URL the wizard
    is actually served at, never a URL-mode guess."""
    frame = page.frame_locator(WIZARD_IFRAME)

    # Terms. The first checkbox must carry BOTH agreement links.
    frame.locator("h2:has-text(\"Let's agree on a few things\")").wait_for()
    label = frame.locator("label[for='terms_legal']").inner_text()
    assert "I agree with the terms of the" in label, label
    assert "use policy" in label, label
    assert frame.locator(
        "label[for='terms_legal'] a[href='https://github.com/BareBits/cashupayserver/blob/main/LICENSE.md']"
    ).count() == 1, "license must link to LICENSE.md on GitHub"
    assert frame.locator(
        "label[for='terms_legal'] a[href='https://github.com/BareBits/cashupayserver/blob/main/USE_POLICY.md']"
    ).count() == 1, "use policy must link to USE_POLICY.md on GitHub"
    for box in ("#terms_legal", "#terms_warranty", "#terms_fee"):
        frame.locator(box).check()
    frame.locator("button[type=submit]:has-text('Continue')").click()

    # Managed install: terms lands straight on the store screen. No security
    # screen (data dir outside the web root) and — the regression — no
    # password screen: the admin account was pre-seeded from
    # CASHUPAY_ADMIN_PASSWORD_HASH, the merchant never types a BareBits
    # credential.
    frame.locator("h2:has-text(\"Let's name your store\")").wait_for()
    assert frame.locator("h2:has-text('admin password')").count() == 0
    frame.locator("input[name='store_name']").fill("Browser Journey Store")
    frame.locator("button[type=submit]").first.click()

    # On-chain screen: "Skip for now" must advance the WIZARD (to the
    # Lightning screen — no zero-conf without an on-chain rail), not bounce
    # the iframe into a WordPress "page not found". This is the exact click
    # that used to strand merchants on hosts with no URL rewriting.
    frame.locator("h2:has-text('On-chain Bitcoin payments')").wait_for()
    frame.locator("button:has-text('Skip for now')").click()
    frame.locator("h2:has-text('Lightning payments')").wait_for()

    frame.locator("button:has-text('Skip for now')").click()
    frame.locator("h2:has-text('Submarine swaps')").wait_for()
    frame.locator("button:has-text('No thanks')").click()
    frame.locator("h2:has-text('Cashu mints')").wait_for()
    frame.locator("button:has-text('No thanks, run without mints')").click()

    # Managed install: completion directly — never the crontab screen
    # (WP-cron owns the heartbeat).
    frame.locator("h2:has-text(\"You're all set\")").wait_for()


def _collect_credentials(page) -> None:
    page.click("#submit")  # "I finished the wizard — continue" (manual fallback)
    page.wait_for_selector(".notice p:has-text('Connected!')")


def _expand_wizard(page) -> None:
    """The provision step opens the embedded wizard in the full-viewport view
    BY DEFAULT (no click — everything except the admin menu + bar); the exit
    control must collapse it and the expand button must bring it back."""
    page.wait_for_selector("#barebits-wizard-shell.barebits-expanded")
    shell = page.locator("#barebits-wizard-shell")
    # position:fixed makes it viewport-sized; sanity-check it actually grew
    # past the 720px column the step normally renders in.
    box = shell.bounding_box()
    assert box is not None and box["width"] > 800, box
    page.click("#barebits-wizard-exit")
    assert "barebits-expanded" not in (shell.get_attribute("class") or "")
    # Leave it expanded for the walk — the way a merchant would use it.
    page.click("#barebits-wizard-expand")
    assert "barebits-expanded" in (shell.get_attribute("class") or "")


def _finish_wizard_via_return_during_wp_maintenance(page, wp: WordPressHandle) -> None:
    """The wizard's completion screen carries the managed-install return
    button (CASHUPAY_MANAGED_RETURN_URL): one click breaks out of the iframe
    (target=_top), the plugin collects the credentials itself, and the
    onboarding page lands on the wire step — no manual collect. Driven here
    while WordPress is mid-auto-update.

    A wp-cron run performing a core/plugin/translation auto-update drops a
    .maintenance file into the WP root; from that moment EVERY WordPress URL
    — the return endpoint included — answers the 503 "Briefly unavailable
    for scheduled maintenance" screen, while the BareBits install (not
    WordPress) keeps working. Merchants used to be dumped onto that screen
    mid-setup, right before the discount step. The completion screen's
    handoff guard must probe first, wait the maintenance window out on the
    wizard's own screen, and complete the handoff automatically once
    WordPress answers again — the post-maintenance leg is the same
    probe-then-navigate path an undisturbed merchant takes."""
    maintenance = wp.wp_root / ".maintenance"
    maintenance.write_text(f"<?php $upgrading = {int(time.time())}; ?>")
    try:
        # The flag must actually gate WordPress on this fixture, or the wait
        # below would pass vacuously.
        probe = requests.get(f"{wp.url}/wp-admin/admin-post.php", timeout=30)
        assert probe.status_code == 503, probe.status_code

        frame = page.frame_locator(WIZARD_IFRAME)
        frame.locator("#managed-return-link").click()
        # The guard holds the merchant on the completion screen with the
        # waiting notice — no navigation onto the maintenance screen.
        frame.locator("#managed-return-waiting").wait_for(state="visible")
        assert page.locator(WIZARD_IFRAME).count() == 1, "must still be on the wizard, not the maintenance screen"
    finally:
        maintenance.unlink()
    # Within one ~5s probe cycle the guard sees WordPress back and finishes
    # the handoff on its own — no reload, no extra click.
    page.wait_for_selector(".notice p:has-text('Connected!')", timeout=60_000)


def _assert_dashboard_reachable(page, wp: WordPressHandle) -> None:
    """The journey is only over when the merchant can actually USE the thing:
    re-open the BareBits wp-admin page in its configured state and require the
    embedded admin dashboard to render, signed in through the SSO handoff.

    This is the regression guard for the hostile-host dashboard 404: admin.php
    used to 302 every bare request to /barebits/admin.php/dashboard — a
    PATH_INFO-style URL that stock-nginx hosts (Local WP) route into
    WordPress's themed "Page not found", stranding the operator with no way
    into the admin at all. Both journeys assert this so the friendly host
    pins the SSO handoff too.
    """
    page.goto(f"{wp.url}/wp-admin/admin.php?page=barebits")
    frame = page.frame_locator("#barebits-admin-frame")
    # Signed in automatically (SSO): the lock screen must be hidden — if the
    # iframe landed on WordPress's 404 neither selector ever appears.
    frame.locator("#lock-screen.hidden").wait_for(state="attached")
    frame.locator("#view-dashboard.active").wait_for()


def test_full_merchant_journey_in_browser(wordpress_bare_install, wp_plugin_zip, page) -> None:
    wp = wordpress_bare_install
    page.set_default_timeout(60_000)

    # WooCommerce up front so the final wiring step has something to wire.
    install_woocommerce(wp)

    # Install the BUILT plugin zip through wp-admin's upload form — the exact
    # artifact (and the exact path) a real merchant uses.
    _login(page, wp, f"{wp.url}/wp-admin/plugin-install.php?tab=upload")
    page.wait_for_selector("input[name='pluginzip']")
    page.set_input_files("input[name='pluginzip']", str(wp_plugin_zip))
    page.click("#install-plugin-submit")
    page.wait_for_selector("a:has-text('Activate Plugin')")
    page.click("a:has-text('Activate Plugin')")

    _open_onboarding(page, wp)
    flash = _choose_install_and_run(page, wp)
    # Friendly host: the post-install loopback probe must not cry wolf.
    assert "Heads up" not in flash, flash

    page.wait_for_selector(WIZARD_IFRAME)
    _expand_wizard(page)
    _walk_wizard_in_iframe(page)
    # The return handoff is driven under WordPress maintenance mode — the
    # wp-cron auto-update collision a real merchant hit. Its recovery leg IS
    # the plain probe-then-navigate handoff, so both shapes are covered.
    _finish_wizard_via_return_during_wp_maintenance(page, wp)

    # Wire WooCommerce (no discount) and land fully configured.
    page.wait_for_selector("h2:has-text('connect WooCommerce')")
    page.fill("#barebits-discount", "0")
    page.click("#submit")
    page.wait_for_selector(".notice p:has-text('WooCommerce now takes Bitcoin')")

    assert wp_option(wp, "barebits_store_id") != ""
    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_gateway_url
    assert wp_option(wp, "barebits_wired_at") != ""
    # The one-time provisioning token is spent.
    assert wp_option(wp, "barebits_provision_token") == ""

    _assert_dashboard_reachable(page, wp)


def test_full_journey_on_rewrite_hostile_host(wordpress_hostile_host, page) -> None:
    """nginx-style host (Local WP's layout): /barebits/*.php executes, every
    other /barebits URL (extension-less, PATH_INFO) falls into WordPress.
    The plugin's API bridge catches those fallen-through /api/v1 requests and
    replays them against the install's api.php — so the routing probe (which
    rides the canonical /api/v1 URL) must stay quiet, the wizard must walk
    start to finish, and the WooCommerce wiring (whose webhook registration
    goes to the install's api.php directly — see barebits_api_transport_url)
    must complete end to end."""
    wp = wordpress_hostile_host
    page.set_default_timeout(60_000)

    install_woocommerce(wp)

    _login(page, wp, f"{wp.url}/wp-admin/admin.php?page=barebits")
    page.wait_for_selector("h1:has-text('BareBits')")
    flash = _choose_install_and_run(page, wp)
    # The install-time probe goes to the install's api.php directly — a real
    # file this host executes — so even with the canonical routes only
    # answered by the bridge, loopback provably works: no warning.
    assert "Heads up" not in flash, flash

    page.wait_for_selector(WIZARD_IFRAME)
    _walk_wizard_in_iframe(page)

    # Credential collection via the manual fallback button — the path a
    # merchant takes if they never click the wizard's own return link (the
    # friendly-host journey drives that link). The wizard opens full screen
    # by default, so a merchant reaching for the fallback form must exit
    # full screen first — the form sits under the fixed overlay.
    page.click("#barebits-wizard-exit")
    _collect_credentials(page)
    assert wp_option(wp, "barebits_store_id") != ""
    assert wp_option(wp, "barebits_cron_key") != ""

    # Wire WooCommerce: registering the invoice webhook is a live Greenfield
    # call to the install (via api.php's query-path transport — one loopback
    # deep, no bridge nesting).
    page.wait_for_selector("h2:has-text('connect WooCommerce')")
    page.fill("#barebits-discount", "0")
    page.click("#submit")
    page.wait_for_selector(".notice p:has-text('WooCommerce now takes Bitcoin')")
    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_gateway_url
    assert wp_option(wp, "barebits_wired_at") != ""

    # The whole point of this host shape: the dashboard must render even
    # though every PATH_INFO URL under /barebits 404s into WordPress.
    _assert_dashboard_reachable(page, wp)
