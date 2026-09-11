"""The golden-template WordPress fixtures (fixtures/wordpress.py).

Every WP test in the tier exercises the clone path implicitly; these tests pin
the properties the mechanism depends on:

  - two clones of one golden are fully isolated (options, DB rows),
  - a clone answers on ITS OWN port everywhere WordPress emits URLs, even
    though the golden's DB carries the golden's URL (the WP_HOME/WP_SITEURL
    constants in the regenerated wp-config.php must win),
  - cloning never mutates the golden tree,
  - the WooCommerce golden carries the configured store + product.
"""
from __future__ import annotations

import os
import shutil
import sqlite3
import uuid
from pathlib import Path

import pytest
import requests

from fixtures.wordpress import (
    WordPressHandle,
    _GOLDEN_CACHE,
    start_wordpress,
    stop_wordpress,
)
from wordpress.conftest import wp_option

pytestmark = pytest.mark.wordpress

TESTS_DIR = Path(__file__).resolve().parent.parent
SESSION_TMP = TESTS_DIR / ".tmp"


@pytest.fixture
def two_clones():
    """Two independent installs of the 'plugin' shape, torn down after."""
    handles: list[WordPressHandle] = []
    workdirs: list[Path] = []
    try:
        for _ in range(2):
            workdir = SESSION_TMP / f"wp-{uuid.uuid4().hex[:8]}"
            workdirs.append(workdir)
            handles.append(start_wordpress(workdir))
        yield handles
    finally:
        for h in handles:
            stop_wordpress(h)
        for w in workdirs:
            shutil.rmtree(w, ignore_errors=True)


def test_clones_are_isolated_and_self_addressed(two_clones) -> None:
    a, b = two_clones
    assert a.port != b.port

    # WordPress must self-identify on the clone's own port — wp-cli boots the
    # clone's wp-config.php, so WP_HOME wins over whatever URL the golden's
    # `wp core install` stored in the DB.
    assert wp_option(a, "home") == a.url
    assert wp_option(b, "home") == b.url

    # Served pages must not leak the golden's (or the sibling's) port.
    body = requests.get(a.url, timeout=30).text
    assert a.url in body
    assert b.url not in body

    # An option written in one clone is invisible in the other.
    marker = f"clone-isolation-{uuid.uuid4().hex[:8]}"
    a.wp_cli("option", "update", "barebits_test_marker", marker)
    assert wp_option(a, "barebits_test_marker") == marker
    assert wp_option(b, "barebits_test_marker") == ""


@pytest.mark.skipif(
    bool(os.environ.get("CASHUPAY_WP_NO_TEMPLATE")),
    reason="templates disabled via CASHUPAY_WP_NO_TEMPLATE",
)
def test_cloning_does_not_mutate_the_golden(two_clones) -> None:
    a, _ = two_clones
    golden_wp_root, _meta = _GOLDEN_CACHE["plugin"]

    # The clone's config rewrite must not have touched the golden's config
    # (a hardlink-style copy would corrupt it in place).
    golden_config = (golden_wp_root / "wp-config.php").read_text()
    clone_config = (a.wp_root / "wp-config.php").read_text()
    assert f"http://127.0.0.1:{a.port}" in clone_config
    assert f"http://127.0.0.1:{a.port}" not in golden_config

    # And a clone's DB write must not land in the golden's DB.
    a.wp_cli("option", "update", "barebits_golden_probe", "leaked")
    golden_db = golden_wp_root / "wp-content" / "database" / "wordpress.sqlite"
    conn = sqlite3.connect(f"file:{golden_db}?mode=ro", uri=True)
    try:
        rows = conn.execute(
            "SELECT option_value FROM wp_options WHERE option_name = ?",
            ("barebits_golden_probe",),
        ).fetchall()
    finally:
        conn.close()
    assert rows == []


def test_woocommerce_template_carries_store_and_product(woocommerce) -> None:
    wp, info = woocommerce
    assert info["product_id"] == wp.woo_product_id
    assert wp_option(wp, "woocommerce_currency") == "BTC"
    # The pre-created product must be live and priced for the checkout tests.
    got = wp.wp_cli(
        "wc", "product", "get", str(info["product_id"]),
        "--field=status", "--user=admin",
    ).stdout.strip()
    assert got == "publish"
