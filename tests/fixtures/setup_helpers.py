"""Drive the cashupayserver onboarding wizard programmatically.

The wizard at /setup uses PHP sessions (cookies) and a slug-valued 'step'
POST field. The standalone first-run sequence is:

    terms -> security -> password -> store -> onchain -> lightning
          -> [zeroconf] -> swaps -> mints -> cron -> done

`zeroconf` only appears once the store has an on-chain receive source — an
xpub/static destination saved on `onchain`, or Strike on-chain minting
enabled on `lightning` (which is why it sits after that screen). A run that
skips both skips it too. `setup_complete` flips at the end of the `mints`
screen — `cron` and `done` are advisory and need not be walked.

`run_setup_wizard()` performs the standalone happy path: security ack, admin
password, store create, skip on-chain, skip lightning, swaps off, and a
main + backup Cashu mint. Leaves the server initialized and ready for API auth.
"""
from __future__ import annotations

import html
import re
from dataclasses import dataclass
from typing import Optional

import requests

# Keys the wizard's on-chain screen accepts, shared by every suite that drives
# it. The mainnet one is BIP44-prefixed, so the wizard infers mainnet and
# defaults the address type to P2WPKH. The testnet one is deliberately
# testnet-family: SLIP-32 cannot tell testnet, signet and regtest apart, so the
# wizard has to ask which sub-network it is.
MAINNET_XPUB = (
    "xpub69uEaVYoN1mZyMon8qwRP41YjYyevp3YxJ68ymBGV7qmXZ9rsbMy9kBZnLNPg3TLj"
    "Kd2EnMw5BtUFQCGrTVDjQok859LowMV2SEooseLCt1"
)
TESTNET_TPUB = (
    "tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQ"
    "vao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B"
)
MAINNET_ADDRESS = "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"
# Reference noffer from @shocknet/clink-sdk — decodes to a valid
# pubkey/relay/offer. Persisted by tests, never dialled.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)
# A second structurally valid noffer for multi-noffer flows (the wizard's
# "+ Add another noffer" rows). Synthetic: ClinkNoffer::encode with pubkey
# ab..ab, relay wss://relay2.example.com, offer 'second-test-offer'.
# Persisted by tests, never dialled.
SECOND_NOFFER = (
    "noffer1qqs2h2at4w46h2at4w46h2at4w46h2at4w46h2at4w46h2at4w46h2cprpmhxue69uhhy"
    "etvv9unytn90psk6urvv5hxxmmdqgghxetrdahxgtt5v4ehgtt0venx2us0var4k"
)


def wizard_error(body: str) -> str | None:
    """The validation message the wizard rendered, or None. The wizard returns
    200 with an error <div> rather than a 4xx, so this is how a failed step is
    detected.

    Entities are decoded: the messages are htmlspecialchars'd on the way out,
    so an apostrophe arrives as &#039; and a naive substring check against the
    human-readable text would never match.

    The validation div carries id="setup-error" (the scroll-into-view anchor);
    matching it optionally keeps this helper working across both markups while
    still not matching the wizard's static error boxes (missing PHP
    extensions), which style themselves inline instead.
    """
    m = re.search(r'<div class="error"(?:\s+id="setup-error")?>(.*?)</div>', body, re.S)
    return html.unescape(m.group(1).strip()) if m else None


def wizard_csrf_token(body: str) -> str | None:
    """The session's CSRF token as rendered in the wizard's hidden form field
    (every wizard POST must carry it since the setup CSRF hardening). None when
    the body is not a wizard screen (a redirect page, an error page)."""
    m = re.search(r'name="csrf_token"\s+value="([^"]+)"', body)
    return html.unescape(m.group(1)) if m else None


def wizard_heading(body: str) -> str:
    """The <h2> of the screen that was rendered — i.e. where the wizard landed.

    The onboarding copy prefixes each heading with a decorative emoji (e.g.
    "⛓️ On-chain Bitcoin payments"). Those are cosmetic and not part of the
    screen's identity, so a leading run of emoji/symbol characters and spaces is
    stripped — assertions pin the semantic heading, not the icon.
    """
    m = re.search(r"<h2[^>]*>(.*?)</h2>", body, re.S)
    if not m:
        return ""
    text = html.unescape(m.group(1).strip())
    # Drop a leading run of whitespace and non-ASCII symbol/emoji code points
    # (>= U+2000 covers the symbol, dingbat, variation-selector and emoji
    # planes) up to the first ASCII heading character.
    i = 0
    while i < len(text) and (text[i].isspace() or ord(text[i]) >= 0x2000):
        i += 1
    return text[i:].strip()


def browser_walk_to_store(
    page,
    base_url: str,
    password: str,
    *,
    install_type: str = "other",
    store_name: str | None = None,
) -> None:
    """Playwright: drive the wizard from the terms gate to the store screen
    (terms → analytics decline → installation type → safety check → password
    → e-mail skip), optionally naming the store too and landing on the first
    rail screen.

    The safety check auto-advances when every probe passes, so it is not
    clicked: the walk simply waits for the password field. When the screen is
    held (a failing probe, or a GMP-less host on the own-wallet / Cashu
    types) it ticks the acknowledgement itself, so the same helper works on
    every rig. A screen that is absent from the flow (desktop mode, data dir
    outside the web root) is skipped the same way."""
    page.goto(f"{base_url.rstrip('/')}/setup")
    page.check("#terms_legal")
    page.check("#terms_warranty")
    page.check("#terms_fee")
    page.click("button[type=submit]")

    page.wait_for_selector("button[name='usage_stats'][value='0']")
    page.click("button[name='usage_stats'][value='0']")

    page.wait_for_selector(f"#install-option-{install_type}")
    page.click(f"#install-option-{install_type} > summary")
    page.click(f"button[name='install_type'][value='{install_type}']")

    page.wait_for_selector("#password, #security_acknowledged")
    if page.locator("#security_acknowledged").count() > 0:
        # Give the auto-advance its chance (both probes have to finish);
        # fall back to acknowledging by hand when the screen is held.
        try:
            page.wait_for_selector("#password", timeout=8000)
        except Exception:
            if page.locator("#security_acknowledged").count() > 0:
                page.check("#security_acknowledged")
                page.click("#security-form button[type=submit]")
                page.wait_for_selector("#password")
    page.fill("#password", password)
    page.fill("#confirm_password", password)
    page.click("button[type=submit]")

    page.wait_for_selector("#email-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#store_name")

    if store_name is not None:
        page.fill("#store_name", store_name)
        page.click("button[type=submit]")


class SetupWizard:
    """A cookie-carrying session pointed at one payserver's /setup.

    Drives the wizard over plain HTTP. Screens are slugs, so each call names
    the screen it is answering and the returned body is whatever screen the
    wizard advanced to.
    """

    DEFAULT_PASSWORD = "onboarding-test-pw"

    def __init__(
        self,
        base_url: str,
        *,
        mode: str = "",
        setup_path: str = "/setup.php",
        session: requests.Session | None = None,
    ) -> None:
        # setup_path differs by deployment: standalone serves /setup.php,
        # WordPress rewrites /cashupay-setup/ to the same file. `session` lets
        # a caller supply a jar that is already authenticated (WordPress mode
        # gates the wizard on current_user_can('manage_options')).
        self.url = f"{base_url.rstrip('/')}{setup_path}"
        self.mode = mode
        self.s = session or requests.Session()
        # Priming GET starts the session and initialises the schema. In
        # add_store mode it also clears any store id left over from a previous
        # wizard run, which is what makes add_store add rather than rename.
        # It also mints the session's CSRF token, which every POST must carry.
        r = self.s.get(self.url, params={"mode": mode} if mode else None, timeout=30)
        self.csrf_token: str | None = wizard_csrf_token(r.text)

    def post(self, **data) -> str:
        if self.mode:
            data.setdefault("mode", self.mode)
        if self.csrf_token and "csrf_token" not in data:
            data["csrf_token"] = self.csrf_token
        r = self.s.post(self.url, data=data, timeout=60, allow_redirects=False)
        assert r.status_code < 400, f"{data.get('step')} -> {r.status_code}: {r.text[:300]}"
        self._remember_token(r.text)
        return r.text

    def get(self, step: str, **params) -> str:
        if self.mode:
            params.setdefault("mode", self.mode)
        r = self.s.get(self.url, params={"step": step, **params}, timeout=15, allow_redirects=False)
        assert r.status_code < 400, f"GET {step} -> {r.status_code}"
        self._remember_token(r.text)
        return r.text

    def _remember_token(self, body: str) -> None:
        """The token is per session and stable, but a screen rendered after a
        session rotation (e.g. login) carries the fresh one."""
        tok = wizard_csrf_token(body)
        if tok:
            self.csrf_token = tok

    def login(self, base_url: str, password: str | None = None) -> None:
        """Authenticate the same session against admin. Required before
        add_store mode, which is admin-gated."""
        r = self.s.post(
            f"{base_url.rstrip('/')}/admin",
            data={
                "action": "login",
                "username": "admin",
                "password": password or self.DEFAULT_PASSWORD,
            },
            timeout=15,
        )
        assert r.status_code == 200 and r.json().get("success") is True, r.text
        # The login answer carries the session's CSRF token; the wizard's
        # tail screens (updates/cron/done) on a configured install are
        # admin-only POSTs that never render a form to lift it from.
        tok = r.json().get("csrfToken")
        if tok:
            self.csrf_token = tok

    def accept_terms(self) -> str:
        """Tick every terms-of-service box — the first-run gate before security.
        Three are mandatory: legal use, as-is/no-warranty, and the incoming-fee
        acknowledgement."""
        return self.post(step="terms", terms_legal="1", terms_warranty="1", terms_fee="1")

    def decline_analytics(self) -> str:
        """Screen two: decline the anonymous usage-statistics opt-in."""
        return self.post(step="analytics", usage_stats="0")

    def choose_install_type(self, install_type: str = "other") -> str:
        """Screen three: pick an installation type (SetupFlow::INSTALL_TYPES).
        'other' keeps every rail screen, which is what most walkers want."""
        return self.post(step="install_type", install_type=install_type)

    def skip_email(self) -> str:
        """The e-mail notifications screen (after the password): skip it —
        the e2e stack has no SMTP server to send the verification mail through."""
        return self.post(step="email", email_action="skip")

    def through_store(
        self,
        name: str = "Wizard Store",
        default_currency: str = "sat",
        install_type: str = "other",
    ) -> str:
        """terms → analytics decline → installation type → security ack →
        password → e-mail skip → store name, landing on the first rail screen
        (on-chain, or the Strike screen for install_type='strike').

        The security POST is harmless when that screen is skipped (data dir
        outside the web root, or desktop mode): an unexpected step simply
        re-renders without advancing, and the next POST names its own step."""
        self.accept_terms()
        self.decline_analytics()
        self.choose_install_type(install_type)
        self.post(step="security", security_acknowledged="1")
        self.post(
            step="password",
            password=self.DEFAULT_PASSWORD,
            confirm_password=self.DEFAULT_PASSWORD,
        )
        self.skip_email()
        return self.post(step="store", store_name=name, default_currency=default_currency)


@dataclass
class SetupResult:
    admin_password: str
    store_name: str
    mint_url: str
    mint_unit: str
    seed_phrase: Optional[str]
    session: requests.Session


def run_setup_wizard(
    base_url: str,
    *,
    admin_password: str = "test-admin-pw-1234",
    store_name: str = "Test Store",
    mint_url: str,
    mint_unit: str = "sat",
    backup_mint_url: str,
    default_currency: str = "sat",
) -> SetupResult:
    """Walk the standalone onboarding wizard end-to-end."""
    s = requests.Session()
    setup = f"{base_url}/setup"

    # Initial GET — triggers session start and schema init, and mints the
    # session's CSRF token that every wizard POST must carry.
    primed = s.get(setup, timeout=10)
    csrf = wizard_csrf_token(primed.text)
    assert csrf, f"wizard priming GET rendered no csrf_token field: {primed.text[:300]}"

    def post(label: str, timeout: int, **data) -> requests.Response:
        data["csrf_token"] = csrf
        r = s.post(setup, data=data, timeout=timeout, allow_redirects=False)
        _assert_step_ok(r, label)
        return r

    # terms: accept the first-run terms-of-service gate (all three boxes,
    # including the incoming-fee acknowledgement).
    post("terms", 10, step="terms", terms_legal="1", terms_warranty="1", terms_fee="1")

    # analytics: decline the anonymous usage-statistics opt-in
    post("analytics", 10, step="analytics", usage_stats="0")

    # install_type: "something else" keeps every rail screen
    post("install_type", 10, step="install_type", install_type="other")

    # security: acknowledge the database-exposure check
    post("security", 10, step="security", security_acknowledged="1")

    # password: admin credentials
    post("password", 10, step="password", password=admin_password, confirm_password=admin_password)

    # email: skip — no SMTP server in the e2e stack to verify against
    post("email (skip)", 10, step="email", email_action="skip")

    # store: name + default display/quote currency
    post("store", 10, step="store", store_name=store_name, default_currency=default_currency)

    # onchain: skip, so callers needn't supply an xpub. This also drops the
    # zeroconf screen from the sequence.
    post("onchain (skip)", 15, step="onchain", onchain_action="skip")

    # lightning: skip — no LNURL address or noffer. (Swaps have no screen:
    # they default off here because this path saved no xpub.)
    post("lightning (skip)", 15, step="lightning", lightning_action="skip")

    # mints: main + backup in one submit. Completes setup; the seed phrase is
    # generated server-side here and surfaced on the `seed` screen.
    post(
        "mints",
        60,
        step="mints",
        mints_enabled="1",
        mint_url=mint_url,
        backup_mint_url=backup_mint_url,
        mint_unit=mint_unit,
    )

    return SetupResult(
        admin_password=admin_password,
        store_name=store_name,
        mint_url=mint_url,
        mint_unit=mint_unit,
        seed_phrase=None,  # generated server-side; not surfaced back via the wizard
        session=s,
    )


def run_add_store_wizard(
    base_url: str,
    *,
    admin_password: str,
    store_name: str,
    mint_url: str,
    backup_mint_url: str,
    mint_unit: str = "sat",
    default_currency: str = "sat",
) -> None:
    """Walk the admin-gated add_store wizard (store → onchain skip →
    lightning skip → mints), creating one more store on an
    already-set-up instance. Each store minted this way gets its own fresh
    wallet seed, so stores on a shared instance never collide at the mint."""
    s = requests.Session()
    r = s.post(
        f"{base_url.rstrip('/')}/admin",
        data={"action": "login", "username": "admin", "password": admin_password},
        timeout=15,
    )
    assert r.status_code == 200 and r.json().get("success") is True, (
        f"admin login before add_store failed: {r.status_code} {r.text[:200]}"
    )

    setup = f"{base_url.rstrip('/')}/setup"
    # Priming GET clears any store id left in the session from a previous
    # wizard run — what makes add_store add rather than rename — and renders
    # the session's CSRF token that every wizard POST must carry.
    primed = s.get(setup, params={"mode": "add_store"}, timeout=30)
    csrf = wizard_csrf_token(primed.text)
    assert csrf, f"add_store priming GET rendered no csrf_token field: {primed.text[:300]}"

    def post(label: str, timeout: int = 15, **data) -> None:
        data["mode"] = "add_store"
        data["csrf_token"] = csrf
        r = s.post(setup, data=data, timeout=timeout, allow_redirects=False)
        _assert_step_ok(r, f"add_store {label}")

    post("store", step="store", store_name=store_name, default_currency=default_currency)
    post("onchain (skip)", step="onchain", onchain_action="skip")
    post("lightning (skip)", step="lightning", lightning_action="skip")
    post(
        "mints",
        timeout=60,
        step="mints",
        mints_enabled="1",
        mint_url=mint_url,
        backup_mint_url=backup_mint_url,
        mint_unit=mint_unit,
    )


def _assert_step_ok(r: requests.Response, label: str) -> None:
    if r.status_code >= 400:
        raise AssertionError(f"{label} returned {r.status_code}: {r.text[:400]}")
    # The wizard renders <div class="error">…</div> on validation failure but
    # still returns 200, so surface that as a test failure with the message.
    body = r.text
    marker = '<div class="error">'
    idx = body.find(marker)
    if idx != -1:
        end = body.find("</div>", idx)
        message = body[idx + len(marker):end].strip() if end != -1 else body[idx:idx + 300]
        raise AssertionError(f"{label} returned a validation error: {message}")
