"""WordPress fixture smoke tests: plugin activates, admin menu present."""
from __future__ import annotations

import pytest

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def test_wp_install_is_reachable(wordpress: WordPressHandle) -> None:
    """The fresh WP install responds on its ephemeral port."""
    import requests
    r = requests.get(wordpress.url, timeout=10)
    assert r.status_code == 200
    assert "wordpress" in r.text.lower() or "html" in r.headers.get("Content-Type", "").lower()


def test_cashupay_plugin_is_active(wordpress: WordPressHandle) -> None:
    """wp-cli reports cashupay as an active plugin."""
    result = wordpress.wp_cli("plugin", "list", "--field=name", "--status=active")
    active = result.stdout.split()
    assert "cashupay" in active, f"cashupay not active; active plugins: {active}"


def test_cashupay_constants_defined(wordpress: WordPressHandle) -> None:
    """Plugin bootstrap.php defines CASHUPAY_WORDPRESS and CASHUPAY_PLUGIN_DIR."""
    # `wp eval` runs PHP within the WP context — including loaded plugins.
    result = wordpress.wp_cli(
        "eval",
        "echo (int)defined('CASHUPAY_WORDPRESS') . '|' . (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR : 'undef');",
    )
    flag, plugin_dir = result.stdout.strip().split("|", 1)
    assert flag == "1", f"CASHUPAY_WORDPRESS not defined: {result.stdout!r} stderr={result.stderr!r}"
    assert "cashupay" in plugin_dir, plugin_dir


def test_cashupay_data_dir_honored(wordpress: WordPressHandle) -> None:
    """wp-config.php pins CASHUPAY_DATA_DIR to the per-test isolated path."""
    result = wordpress.wp_cli("eval", "echo CASHUPAY_DATA_DIR;")
    assert str(wordpress.data_dir) == result.stdout.strip(), result.stdout
