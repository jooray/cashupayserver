"""Plugin activation smoke tests for the thin GPL plugin.

The plugin is WordPress-only glue: no BareBits server code ships inside it,
so activation must neither define the old embedded-mode constants nor
register any /barebits/* front-end routes. What it must do: register the
top-level BareBits admin page (which renders the onboarding chooser until a
server is wired), expose the installer entry point, and nag unconfigured
admins toward setup — without ever leaking that nag to the storefront.
"""
from __future__ import annotations

import pytest
import requests

from wordpress.conftest import onboarding_page, wp_login
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

CHOOSER_URL_COPY = "already run a BareBits server"
CHOOSER_INSTALL_COPY = "Install BareBits alongside WordPress"


def test_wp_install_is_reachable(wordpress: WordPressHandle) -> None:
    """The fresh WP install responds on its ephemeral port."""
    r = requests.get(wordpress.url, timeout=10)
    assert r.status_code == 200
    assert "wordpress" in r.text.lower() or "html" in r.headers.get("Content-Type", "").lower()


def test_barebits_plugin_activates_cleanly(wordpress: WordPressHandle) -> None:
    """The copied-source plugin tree is active (the fixture activates it, which
    already ran every __DIR__ require without a fatal) and its header parses."""
    result = wordpress.wp_cli("plugin", "list", "--field=name", "--status=active")
    active = result.stdout.split()
    assert "barebits" in active, f"barebits not active; active plugins: {active}"

    version = wordpress.wp_cli("plugin", "get", "barebits", "--field=version").stdout.strip()
    assert version, "plugin version header empty — barebits.php header not parsed"


def test_no_embedded_server_constants(wordpress: WordPressHandle) -> None:
    """The GPL split removed the embedded server: CASHUPAY_WORDPRESS (the old
    bootstrap.php marker) must no longer exist, while the installer entry point
    the onboarding flow calls must."""
    defined = wordpress.wp_cli(
        "eval", "var_export(defined('CASHUPAY_WORDPRESS'));"
    ).stdout.strip()
    assert defined == "false", (
        f"CASHUPAY_WORDPRESS must not be defined by the thin plugin: {defined!r}"
    )

    exists = wordpress.wp_cli(
        "eval", "var_export(function_exists('barebits_run_install'));"
    ).stdout.strip()
    assert exists == "true", "barebits_run_install (installer.php) must be loaded"


def test_plugin_uri_points_at_barebits_repo(wordpress: WordPressHandle) -> None:
    """The "Visit plugin site" link on the Plugins screen must lead to the
    BareBits fork that actually ships this plugin."""
    result = wordpress.wp_cli(
        "eval",
        "require_once ABSPATH . 'wp-admin/includes/plugin.php';"
        "echo get_plugin_data(WP_PLUGIN_DIR . '/barebits/barebits.php', false, false)['PluginURI'];",
    )
    uri = result.stdout.strip()
    assert uri == "https://github.com/BareBits/cashupayserver", uri


def test_admin_menu_is_a_top_level_section_not_under_tools(wordpress: WordPressHandle) -> None:
    """BareBits gets its own top-level sidebar section. Regression guard against
    the previous placement as a Tools submenu, which buried it."""
    snippet = (
        "require_once ABSPATH . 'wp-admin/includes/plugin.php';"
        "wp_set_current_user(1);"
        "do_action('admin_menu');"
        "global $menu, $submenu;"
        "$top = false;"
        "foreach ((array)$menu as $m) { if (($m[2] ?? '') === 'barebits') $top = true; }"
        "$underTools = false;"
        "foreach ((array)($submenu['tools.php'] ?? []) as $m) { if (($m[2] ?? '') === 'barebits') $underTools = true; }"
        "echo ($top ? '1' : '0') . '|' . ($underTools ? '1' : '0');"
    )
    top, under_tools = wordpress.wp_cli("eval", snippet).stdout.strip().split("|")
    assert top == "1", "BareBits should register a top-level admin menu page"
    assert under_tools == "0", "BareBits must no longer live under the Tools menu"


def test_admin_page_renders_the_onboarding_chooser(wordpress: WordPressHandle) -> None:
    """Until a server is wired, the BareBits wp-admin page IS the onboarding
    flow, opening on the two-way chooser (connect by URL / install alongside)."""
    s = wp_login(wordpress)
    body = onboarding_page(s, wordpress)
    assert CHOOSER_URL_COPY in body, body[:2000]
    assert CHOOSER_INSTALL_COPY in body, body[:2000]


def test_admin_page_requires_authentication(wordpress: WordPressHandle) -> None:
    """An anonymous request for the BareBits page never sees the chooser — WP
    bounces it to the login screen before the plugin renders anything."""
    r = requests.get(
        f"{wordpress.url}/wp-admin/admin.php",
        params={"page": "barebits"},
        timeout=30,
        allow_redirects=False,
    )
    assert r.status_code in (301, 302), f"expected a login redirect, got {r.status_code}"
    assert "wp-login.php" in r.headers.get("Location", ""), r.headers.get("Location")
    assert CHOOSER_URL_COPY not in r.text
    assert CHOOSER_INSTALL_COPY not in r.text


def test_unconfigured_admin_notice_invites_configuration(wordpress: WordPressHandle) -> None:
    """While unconfigured, ordinary wp-admin pages carry the "Configure
    BareBits" notice linking to the plugin's own page. /wp-admin/index.php is
    used because the fixture's php -S router only serves real files."""
    s = wp_login(wordpress)
    r = s.get(f"{wordpress.url}/wp-admin/index.php", timeout=30)
    assert r.status_code == 200, f"dashboard returned {r.status_code}"
    assert "Configure BareBits" in r.text, r.text[:400]
    assert "admin.php?page=barebits" in r.text, (
        "notice must link to the BareBits admin page"
    )


def test_admin_notice_never_leaks_to_the_storefront(wordpress: WordPressHandle) -> None:
    """The configuration nag is an admin_notices callback, so it must stay on
    wp-admin and never render on the customer-facing site."""
    r = requests.get(wordpress.url, timeout=10)
    assert "Configure BareBits" not in r.text
