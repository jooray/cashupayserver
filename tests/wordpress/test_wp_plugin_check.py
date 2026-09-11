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


# The only WordPress core files plugin code may ever load, per the exception
# wordpress.org reviewers allow: a require_once of a wp-admin include whose
# function/class is consumed immediately after, behind an existence guard.
# Extending this set needs the same justification the existing entries have.
ALLOWED_CORE_INCLUDES = {
    "wp-admin/includes/file.php",
    "wp-admin/includes/plugin.php",
    "wp-admin/includes/plugin-install.php",
    "wp-admin/includes/class-wp-upgrader.php",
}


def test_no_direct_core_file_includes() -> None:
    """wordpress.org review gate (2026-09 submission feedback): plugin code
    must never include WordPress bootstrap files (wp-load.php, wp-config.php,
    wp-blog-header.php, wp-settings.php) — WordPress loads the plugin, never
    the other way around — and ABSPATH-relative includes are restricted to
    the allowlisted wp-admin includes above, each sitting directly behind a
    function_exists()/class_exists() guard so the file is loaded only when
    missing and used immediately after."""
    bootstrap = re.compile(
        r"\b(?:require|include)(?:_once)?\b[^;]*"
        r"(?:wp-load\.php|wp-config\.php|wp-blog-header\.php|wp-settings\.php)"
    )
    core_include = re.compile(
        r"\b(?:require|include)(?:_once)?\b[^;]*\bABSPATH\b[^;]*['\"]([^'\"]+)['\"]"
    )
    guard = re.compile(r"!\s*(?:function_exists|class_exists)\s*\(")
    offenders = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        lines = php.read_text().splitlines()
        for lineno, line in enumerate(lines, 1):
            if bootstrap.search(line):
                offenders.append(
                    f"{php.name}:{lineno} loads a WordPress bootstrap file: {line.strip()[:120]}"
                )
                continue
            m = core_include.search(line)
            if not m:
                continue
            if m.group(1) not in ALLOWED_CORE_INCLUDES:
                offenders.append(
                    f"{php.name}:{lineno} ABSPATH include outside the allowlist "
                    f"({m.group(1)}): {line.strip()[:120]}"
                )
                continue
            prev = lines[lineno - 2].strip() if lineno >= 2 else ""
            if not guard.search(prev):
                offenders.append(
                    f"{php.name}:{lineno} core include must sit directly inside a "
                    f"function_exists()/class_exists() guard: {line.strip()[:120]}"
                )
    assert offenders == [], (
        "direct core-file includes crept back into the plugin (wordpress.org "
        "rejects these):\n" + "\n".join(offenders)
    )


def _php_functions(src: str) -> dict[str, str]:
    """Map top-level function name -> full source text. Relies on the plugin's
    uniform style: functions declared at column 0, closing brace at column 0
    (no classes, no closures registered as hooks — asserted by the callers)."""
    funcs: dict[str, str] = {}
    for m in re.finditer(r"^function\s+([A-Za-z0-9_]+)\s*\(", src, re.M):
        end = src.find("\n}", m.start())
        funcs[m.group(1)] = src[m.start() : end + 2] if end != -1 else src[m.start() :]
    return funcs


def _hook_registrations(prefix: str) -> list[tuple[str, str, str, dict[str, str]]]:
    """All add_action('{prefix}...', 'fn') registrations across the plugin:
    [(file, hook, fn, functions-of-that-file)]. Fails if a hook with the
    prefix is registered with anything but a plain 'function_name' string —
    the nonce gates below can only audit named top-level functions."""
    rows = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        src = php.read_text()
        funcs = _php_functions(src)
        for m in re.finditer(r"add_action\(\s*['\"](" + re.escape(prefix) + r"[a-z0-9_]*)['\"]\s*,\s*(.+?)\s*[,)]", src):
            hook, callback = m.group(1), m.group(2)
            named = re.fullmatch(r"['\"]([A-Za-z0-9_]+)['\"]", callback)
            assert named, (
                f"{php.name}: {hook} registered with a non-literal callback "
                f"({callback!r}); the nonce audit needs a named function"
            )
            assert named.group(1) in funcs, (
                f"{php.name}: {hook} handler {named.group(1)}() not found as a "
                f"top-level function in the same file"
            )
            rows.append((php.name, hook, named.group(1), funcs))
    return rows


# The only admin-post handlers allowed to skip check_admin_referer(), each
# with the structural reason a WordPress nonce cannot exist there, and the
# compensating control the handler body MUST therefore contain. Extending
# this map is a review-level decision, not a convenience.
NONCE_EXEMPT_ADMIN_POST = {
    # Cross-site POST from the BareBits approval page: carries no wp-admin
    # auth cookie, so a session-bound WP nonce could never verify. The
    # compensating control is the single-use, time-boxed pairing state token
    # compared with hash_equals().
    "cashupay_handle_pairing_callback": "hash_equals(",
    # Return link minted by the BareBits setup wizard (which cannot create WP
    # nonces); idempotent state advance, gated on the admin capability.
    "cashupay_handle_provision_return": "current_user_can(",
    # nopriv twin of the above: redirects to the login screen, touches nothing.
    "cashupay_handle_provision_return_nopriv": "wp_login_url(",
}


def test_admin_post_handlers_verify_nonce_and_capability() -> None:
    """wordpress.org review gate (2026-09 submission feedback): every
    admin-post handler must contain a literal current_user_can() +
    check_admin_referer() in its own body — visible to any static scanner,
    never hidden behind a helper — unless it is on the documented exemption
    list above, in which case its compensating control must be present."""
    offenders = []
    seen = set()
    for file, hook, fn, funcs in _hook_registrations("admin_post"):
        seen.add(fn)
        body = funcs[fn]
        if fn in NONCE_EXEMPT_ADMIN_POST:
            if NONCE_EXEMPT_ADMIN_POST[fn] not in body:
                offenders.append(
                    f"{file}: {fn}() is nonce-exempt but lost its compensating "
                    f"control {NONCE_EXEMPT_ADMIN_POST[fn]!r}"
                )
            continue
        if hook.startswith("admin_post_nopriv_"):
            offenders.append(
                f"{file}: {hook} -> {fn}() — a new logged-out admin-post handler "
                "needs an explicit entry in NONCE_EXEMPT_ADMIN_POST with its "
                "compensating control"
            )
            continue
        for required in ("current_user_can(", "check_admin_referer("):
            if required not in body:
                offenders.append(f"{file}: {fn}() is missing {required}")
    stale = set(NONCE_EXEMPT_ADMIN_POST) - seen
    assert not stale, f"NONCE_EXEMPT_ADMIN_POST lists unregistered handlers: {sorted(stale)}"
    assert offenders == [], (
        "admin-post handlers without scanner-visible auth checks (wordpress.org "
        "review rejects these):\n" + "\n".join(offenders)
    )


def test_ajax_handlers_verify_nonce_and_capability() -> None:
    """Same gate for wp_ajax_* handlers: check_ajax_referer() + a capability
    check, literally in the handler body. Logged-out ajax (wp_ajax_nopriv_*)
    does not exist in this plugin; adding one must trip this test."""
    offenders = []
    for file, hook, fn, funcs in _hook_registrations("wp_ajax"):
        if hook.startswith("wp_ajax_nopriv_"):
            offenders.append(
                f"{file}: {hook} — the plugin has no logged-out ajax; adding one "
                "needs its own auth story and an update to this test"
            )
            continue
        for required in ("check_ajax_referer(", "current_user_can("):
            if required not in funcs[fn]:
                offenders.append(f"{file}: {fn}() is missing {required}")
    assert offenders == [], (
        "ajax handlers without nonce/capability checks:\n" + "\n".join(offenders)
    )


def test_no_superglobal_access_outside_functions() -> None:
    """wordpress.org review gate (2026-09 submission feedback): request input
    ($_GET/$_POST/$_REQUEST) may only be read inside hook-driven functions —
    never at file top level, where it would run on every load of every page."""
    offenders = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        src = php.read_text()
        # Blank out every top-level function body, then whatever superglobal
        # access remains is top-level code (comment lines excepted).
        stripped = src
        for body in _php_functions(src).values():
            stripped = stripped.replace(body, "")
        for lineno_text in stripped.splitlines():
            text = lineno_text.strip()
            if text.startswith(("*", "//", "#", "/*")):
                continue
            if re.search(r"\$_(GET|POST|REQUEST)\b", text):
                offenders.append(f"{php.name}: top-level input access: {text[:120]}")
    assert offenders == [], (
        "superglobals read outside functions (runs on every pageview; "
        "wordpress.org review rejects this):\n" + "\n".join(offenders)
    )


# Functions that justify a request-input read on the line where it happens:
# the WP sanitizers the plugin actually uses, plus pure existence checks
# (isset/empty guard lines never consume the value). A raw read that uses
# none of these needs an entry in RAW_INPUT_ALLOWLIST below.
INPUT_READ_APPROVED = (
    "sanitize_text_field(",
    "sanitize_key(",
    "sanitize_file_name(",
    "absint(",
    "isset(",
    "empty(",
    "array_key_exists(",
)

# The only raw request-input reads allowed without a sanitize_*() on the
# line, keyed by (file, substring that must appear on the same line — the
# compensating control). Extending this map is a review-level decision, not
# a convenience: each entry documents why WordPress's sanitizers are the
# wrong tool there and what handles the input instead.
RAW_INPUT_ALLOWLIST = {
    # The bridged query string is re-encoded pair by pair by
    # cashupay_api_bridge_query() — sanitize_text_field would corrupt
    # legitimate API parameters; percent re-encoding cannot. The call being
    # ON the access line is exactly what this gate pins.
    ("api-bridge.php", "cashupay_api_bridge_query("),
    # The bridged request body: a bounded read (the cap constant on the line
    # is the control), validated as JSON by cashupay_api_bridge_body_refusal()
    # immediately after — sanitizing bytes that must reach the API verbatim
    # would corrupt payloads.
    ("api-bridge.php", "CASHUPAY_BRIDGE_MAX_BODY_BYTES"),
}


def test_request_input_reads_are_sanitized() -> None:
    """wordpress.org review gate (2026-09 submission feedback, second round):
    every read of request input ($_GET/$_POST/$_REQUEST/$_SERVER/$_COOKIE/
    $_FILES, php://input) in the plugin must sanitize or validate on the very
    line it happens — or carry a documented entry in RAW_INPUT_ALLOWLIST.
    A tripwire against `$x = $_POST['foo'];` creeping back in, not a proof of
    correctness: the pure sanitizers themselves are pinned by
    tests/php/test_wp_api_bridge.php, and the refusal behavior over HTTP by
    test_wp_api_bridge_live.py."""
    token = re.compile(r"\$_(GET|POST|REQUEST|SERVER|COOKIE|FILES)\b|php://input")
    offenders = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        for lineno, line in enumerate(php.read_text().splitlines(), 1):
            text = line.strip()
            if text.startswith(("*", "//", "#", "/*")) or not token.search(text):
                continue
            if any(fn in text for fn in INPUT_READ_APPROVED):
                continue
            if any(f == php.name and snippet in text for f, snippet in RAW_INPUT_ALLOWLIST):
                continue
            offenders.append(f"{php.name}:{lineno} raw request input: {text[:120]}")
    assert offenders == [], (
        "request input read without a same-line sanitizer/validator "
        "(wordpress.org review rejects these):\n" + "\n".join(offenders)
    )


# Escaping calls that justify a variable inside an echo/print statement: the
# context-appropriate WordPress escapers the plugin uses (esc_html/esc_attr/
# esc_url and their __()/_e() translation forms via prefix match), wp_kses for
# HTML fragments, wp_json_encode for JSON responses, and integer casts.
OUTPUT_ESCAPE_APPROVED = (
    "esc_html",
    "esc_attr",
    "esc_url",
    "esc_js",
    "esc_textarea",
    "wp_kses",
    "wp_json_encode(",
    "absint(",
    "(int)",
    "number_format_i18n(",
)

# The only echo statements allowed to output a variable with no escaper,
# keyed by (file, substring that must appear in the statement — the
# compensating control). Extending this map is a review-level decision, not
# a convenience: each entry documents why escaping is the wrong tool there.
RAW_OUTPUT_ALLOWLIST = {
    # The bridge's proxy passthrough: the install's API response body is
    # relayed byte-for-byte under the install's own Content-Type (JSON),
    # never rendered as this site's HTML — escaping would corrupt the API
    # payload the WooCommerce gateway and external clients parse.
    ("api-bridge.php", "wp_remote_retrieve_body("),
}


def test_output_echoes_are_escaped() -> None:
    """wordpress.org review gate (2026-09 submission feedback, second round —
    'Escape Late'): every echo/print statement in the plugin that outputs a
    variable must carry a WordPress escaper in the statement — or a documented
    entry in RAW_OUTPUT_ALLOWLIST. A tripwire against `echo $foo;` creeping
    back in, not a proof of correctness: it checks that AN escaper appears in
    the statement, not that every concatenated part is wrapped — that remains
    a review-time judgement, like choosing the right esc_*() for the context."""
    output_token = re.compile(r"\becho\b|\bprint\b|\bprintf\b|<\?=")
    variable = re.compile(r"\$[A-Za-z_]")
    offenders = []
    for php in sorted((REPO_ROOT / "wordpress").glob("*.php")):
        lines = php.read_text().splitlines()
        i = 0
        while i < len(lines):
            # Strip //-comments (but not URLs' ://) so a comment mentioning
            # echo or a variable never trips the gate.
            text = re.sub(r"(?<!:)//.*", "", lines[i]).strip()
            i += 1
            if text.startswith(("*", "#", "/*")) or not output_token.search(text):
                continue
            # Accumulate the whole statement: echoes may wrap across lines.
            lineno = i  # 1-based line of the echo itself
            statement = text
            while ";" not in statement and i < len(lines):
                statement += " " + lines[i].strip()
                i += 1
            if not variable.search(statement):
                continue  # pure literals are fine unescaped
            if any(fn in statement for fn in OUTPUT_ESCAPE_APPROVED):
                continue
            if any(f == php.name and snippet in statement for f, snippet in RAW_OUTPUT_ALLOWLIST):
                continue
            offenders.append(f"{php.name}:{lineno} unescaped output: {statement[:120]}")
    assert offenders == [], (
        "echo/print of a variable without an esc_*()/wp_kses()/wp_json_encode() "
        "in the statement (wordpress.org review rejects these):\n"
        + "\n".join(offenders)
    )


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
