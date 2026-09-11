"""E2E: the SHIPPED WordPress plugin zip installs and activates cleanly.

Every other WP test copies the plugin from live source. This one consumes the
real ``build/barebits_wordpress_plugin.zip`` artifact — the exact bytes the release
workflow publishes — and installs it the way an operator does:
``wp plugin install <zip>``. Beyond proving the flattened requires resolve, it
pins the GPL-only layout: the zip is thin WordPress glue and must never again
ship BareBits server code (includes/, vendor/, the old embedded entry points).
"""
from __future__ import annotations

from pathlib import Path

import pytest

from wordpress.conftest import onboarding_page, wp_login
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

# What the GPL plugin zip contains — nothing more, nothing less (plus assets/).
EXPECTED_FILES = [
    "barebits.php",
    "state.php",
    "installer.php",
    "onboarding.php",
    "btcpay-integration.php",
    "payment-discount.php",
    "cron-integration.php",
    "admin-menu.php",
    "gateway-guard.php",
    "uninstall.php",
    "readme.txt",
    "assets/img/barebits-gateway-logo.png",
    "assets/js/discount-classic.js",
    "assets/js/discount-blocks.js",
]

# Server code and the pre-split embedded entry points that must NEVER ship in
# the GPL zip again.
FORBIDDEN_PATHS = [
    "includes",
    "vendor",
    "setup.php",
    "admin.php",
    "api.php",
    "cron.php",
    "payment.php",
    "bootstrap.php",
    "rewrite-rules.php",
    "activation.php",
]


def _install_zip(wp: WordPressHandle, zip_path: Path):
    return wp.wp_cli("plugin", "install", str(zip_path), "--activate", check=False)


def test_shipped_zip_installs_and_activates(
    wordpress_bare: WordPressHandle, wp_plugin_zip: Path
) -> None:
    wp = wordpress_bare

    # Precondition: a bare WP has no barebits plugin at all.
    before = wp.wp_cli("plugin", "list", "--field=name")
    assert "barebits" not in before.stdout.split(), "bare WP should have no barebits plugin"

    # Install + activate the real artifact.
    res = _install_zip(wp, wp_plugin_zip)
    assert res.returncode == 0, f"plugin install failed:\n{res.stdout}\n{res.stderr}"

    # wp-cli reports it active — activation ran every __DIR__ require
    # (barebits.php -> state.php / installer.php / onboarding.php ...)
    # against the installed tree without a fatal.
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    assert "barebits" in active, f"barebits not active after install; active: {active}"

    # Header metadata parsed from the installed main file.
    version = wp.wp_cli("plugin", "get", "barebits", "--field=version").stdout.strip()
    assert version, "plugin version header empty — barebits.php header not parsed"

    # GPL-only layout: exactly the WordPress glue landed ...
    plugin_dir = wp.wp_root / "wp-content" / "plugins" / "barebits"
    for rel in EXPECTED_FILES:
        assert (plugin_dir / rel).is_file(), f"{rel} missing from the installed zip"

    # ... and no BareBits server code or embedded-mode entry point did.
    for rel in FORBIDDEN_PATHS:
        assert not (plugin_dir / rel).exists(), (
            f"{rel} must not ship in the GPL plugin zip — the server is a "
            "separate application the plugin only talks to over HTTP"
        )


def test_shipped_zip_onboarding_page_renders(
    wordpress_bare: WordPressHandle, wp_plugin_zip: Path
) -> None:
    """After installing the zip, the wp-admin BareBits page renders the
    onboarding chooser with no fatal — a runtime dispatch through the
    installed tree, beyond the load-time requires the activation check
    above covers."""
    wp = wordpress_bare
    res = _install_zip(wp, wp_plugin_zip)
    assert res.returncode == 0, f"plugin install failed:\n{res.stdout}\n{res.stderr}"

    s = wp_login(wp)
    body = onboarding_page(s, wp)
    assert "Fatal error" not in body, f"PHP fatal on the onboarding page: {body[:500]}"
    assert "already run a BareBits server" in body, body[:2000]
    assert "Install BareBits alongside WordPress" in body, body[:2000]
