"""E2E gate: WordPress.org's Plugin Check passes on the shipped plugin zips.

The wordpress.org submission flow runs the Plugin Check plugin (PCP) against
the uploaded zip and refuses it on any ERROR. This test IS that gate, run at
PR time: both built variants — the full GitHub zip and the wordpress.org zip
(no installer.php) — are installed into a real WordPress the way an operator
would, and `wp plugin check` must come back with zero errors.

One check is deliberately tolerated: `outdated_tested_upto_header` compares
the readme's "Tested up to" against the LIVE current WordPress version, so it
starts failing the moment WordPress ships a release newer than our header — a
moving target no pin can hold. The stable-tag/version agreement it also
guards is asserted locally instead (and the build script stamps it).

The wordpress.org variant additionally pins its behavioral contract: the
installer is really absent, onboarding offers only connect-by-URL, and a
hand-crafted install-mode POST is refused.
"""
from __future__ import annotations

import re
import zipfile
from pathlib import Path

import pytest

from wordpress.conftest import onboarding_page, post_onboarding, wp_login
from fixtures.wordpress import WordPressHandle, install_plugin_check

pytestmark = pytest.mark.wordpress

REPO_ROOT = Path(__file__).resolve().parent.parent.parent

# Checks whose ERRORS are tolerated, with the reason. Everything else must be
# clean — the wordpress.org uploader rejects the zip otherwise.
TOLERATED_ERROR_CODES = {
    # Compares against the live latest WordPress release: goes stale on
    # every WP release, not on our changes. The header itself is asserted
    # sane below (a real version, >= the core this suite runs on).
    "outdated_tested_upto_header",
}


def _install_and_check(wp: WordPressHandle, zip_path: Path) -> list[tuple[str, str, str]]:
    """Install the given plugin zip + Plugin Check, run `wp plugin check`.
    Returns [(type, code, message), ...] for every reported row."""
    res = wp.wp_cli("plugin", "install", str(zip_path), "--activate", check=False)
    assert res.returncode == 0, f"plugin install failed:\n{res.stdout}\n{res.stderr}"
    install_plugin_check(wp)

    # --slug: the wordpress.org uploader checks against the slug it derives
    # from the Plugin Name, not the install folder (which stays `cashupay/`).
    # Without the override, Plugin Check falls back to the folder name and the
    # textdomain_mismatch warning the uploader emits never reproduces locally.
    res = wp.wp_cli(
        "plugin", "check", "cashupay",
        "--slug=barebits-lightning-payments-via-bitcoin", check=False,
    )
    assert res.returncode == 0, f"wp plugin check failed to run:\n{res.stdout}\n{res.stderr}"

    rows: list[tuple[str, str, str]] = []
    for line in res.stdout.splitlines():
        fields = line.split("\t")
        if len(fields) >= 5 and fields[2] in ("ERROR", "WARNING"):
            rows.append((fields[2], fields[3], fields[4]))
    return rows


def _assert_clean(rows: list[tuple[str, str, str]]) -> None:
    errors = [r for r in rows if r[0] == "ERROR" and r[1] not in TOLERATED_ERROR_CODES]
    assert errors == [], (
        "Plugin Check found submission-blocking errors:\n"
        + "\n".join(f"  {code}: {message}" for _, code, message in errors)
    )
    # Warnings don't block the uploader, but the report was fully clean when
    # this gate landed — new ones deserve a look, so surface them loudly in
    # the assertion message without failing on the tolerated-error class.
    warnings = [r for r in rows if r[0] == "WARNING"]
    assert warnings == [], (
        "Plugin Check reports new warnings (the report used to be clean; fix "
        "or annotate them):\n"
        + "\n".join(f"  {code}: {message}" for _, code, message in warnings)
    )


def _readme_headers(zip_path: Path) -> dict[str, str]:
    with zipfile.ZipFile(zip_path) as zf:
        readme = zf.read("cashupay/readme.txt").decode()
    headers = {}
    for line in readme.splitlines():
        m = re.match(r"^([A-Za-z ]+): (.+)$", line.strip())
        if m:
            headers[m.group(1)] = m.group(2).strip()
    with zipfile.ZipFile(zip_path) as zf:
        main = zf.read("cashupay/cashupay.php").decode()
    version = re.search(r"^ \* Version: (.+)$", main, re.M)
    headers["_plugin_version"] = version.group(1).strip() if version else ""
    return headers


def _assert_readme_sane(zip_path: Path, wp: WordPressHandle) -> None:
    headers = _readme_headers(zip_path)
    # The local, churn-free half of what outdated_tested_upto_header guards:
    # Stable tag must equal the plugin version (the build script stamps it),
    # and "Tested up to" must at least cover the core this suite runs on.
    assert headers.get("Stable tag") == headers["_plugin_version"], headers
    core = wp.wp_cli("core", "version").stdout.strip()
    tested = headers.get("Tested up to", "0")
    assert [int(x) for x in tested.split(".")] >= [int(x) for x in core.split(".")[:2]], (
        f"readme 'Tested up to: {tested}' is older than the WP core this suite runs ({core})"
    )


def test_no_inline_scripts_or_styles() -> None:
    """wordpress.org review gate (2026-09 submission feedback): all plugin JS
    and CSS must go through the enqueue APIs. No PHP file may print <script>
    or <style> blocks, style="…" attributes, or on*="…" event handlers — the
    enqueue-able home for all of it is the files under wordpress/assets/."""
    offenders = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        for lineno, line in enumerate(php.read_text().splitlines(), 1):
            for pattern, label in (
                (r"<script\b", "<script> tag"),
                (r"<style\b", "<style> tag"),
                (r'\bstyle="', 'style="…" attribute'),
                (r'\bon[a-z]+="', "inline event handler"),
            ):
                if re.search(pattern, line):
                    offenders.append(f"{php.name}:{lineno} {label}: {line.strip()[:120]}")
    assert offenders == [], "inline JS/CSS crept back into the plugin:\n" + "\n".join(offenders)


def test_plugin_check_full_zip(wordpress_bare: WordPressHandle, wp_plugin_zip: Path) -> None:
    rows = _install_and_check(wordpress_bare, wp_plugin_zip)
    _assert_clean(rows)
    _assert_readme_sane(wp_plugin_zip, wordpress_bare)


def test_plugin_check_wporg_zip(wordpress_bare: WordPressHandle, wp_plugin_wporg_zip: Path) -> None:
    # The layout contract first: no installer, everything else identical to
    # the full zip's expected file list.
    with zipfile.ZipFile(wp_plugin_wporg_zip) as zf:
        names = zf.namelist()
    assert "cashupay/installer.php" not in names, "installer.php must not ship to wordpress.org"
    assert "cashupay/cashupay.php" in names and "cashupay/readme.txt" in names

    rows = _install_and_check(wordpress_bare, wp_plugin_wporg_zip)
    _assert_clean(rows)
    _assert_readme_sane(wp_plugin_wporg_zip, wordpress_bare)

    wp = wordpress_bare
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    assert "cashupay" in active, f"wporg variant not active after install; active: {active}"

    # Onboarding offers only connect-by-URL: no install radio, no server-check
    # table, and the URL form still renders.
    s = wp_login(wp)
    body = onboarding_page(s, wp)
    assert 'name="cashupay_server_url"' in body, body[-1500:]
    assert "Install BareBits alongside WordPress" not in body
    assert "Server checks for installing alongside" not in body

    # The disabled radio was only ever markup — a hand-crafted install-mode
    # POST must be refused server-side too.
    after = post_onboarding(s, wp, "cashupay_choose_mode", {"cashupay_mode": "install"})
    assert "cannot install BareBits alongside" in after
    mode = wp.wp_cli("option", "get", "cashupay_mode", check=False)
    assert mode.stdout.strip() == "", f"install mode must not be stored: {mode.stdout!r}"
