"""Collapsible sections in the store-settings "Lightning payments" card:

  - Strike API keys / Lightning addresses / NWC connections / CLINK noffers
    are each their own collapsible section, collapsed by default and expanded
    on load only when they hold saved entries.
  - "Cashu automatic cashout" and "Offline Cashu acceptance" moved INSIDE the
    card as its trailing "Cashu" section (their standalone cards are gone).
    The section greys out when the store has no Cashu mint wallet, showing
    the statement "A Cashu Mint is not enabled, enable a cashu mint to
    access these settings." with an "Enable a Cashu mint" button.
  - That button opens the enable-mint dialog (modal-enable-mint): mint URL +
    unit, backed by admin action=enable_store_mint, which tests the mint,
    generates + shows the wallet seed once, clears the mintless-setup
    strict-no-mint-fallback pin, and un-greys the section.
  - The Cashu section's default expansion rule: open only when the store has
    a mint AND cashout is configured (enabled, or explicitly set on-chain).
"""
from __future__ import annotations

import json
import re
import sqlite3
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard
from fixtures import ports
from fixtures.browser import expand_settings_cards

# Reference noffer from @shocknet/clink-sdk (decodes to a valid pubkey/relay/
# offer). Used only for config persistence — never dialled in this test.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)

MINT_WARNING = "A Cashu Mint is not enabled, enable a cashu mint to access these settings."

SECTION_IDS = [
    "ln-section-strike",
    "ln-section-addresses",
    "ln-section-nwc",
    "ln-section-noffers",
    "ln-section-cashu",
]


# ------------------------------------------------------------------ stub mint


class _StubMintHandler(BaseHTTPRequestHandler):
    """Minimal Cashu mint surface: /v1/info + /v1/keysets (sat only). That is
    all enable_store_mint probes (Wallet::getSupportedUnits)."""

    def do_GET(self):  # noqa: N802 - http.server API
        if self.path == "/v1/info":
            body = {"name": "stub mint", "version": "stub/0.1", "nuts": {}}
        elif self.path == "/v1/keysets":
            body = {"keysets": [
                {"id": "00stubsat0000001", "unit": "sat", "active": True, "input_fee_ppk": 0},
            ]}
        else:
            self.send_response(404)
            self.end_headers()
            return
        payload = json.dumps(body).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *args):  # silence per-request stderr noise
        pass


@pytest.fixture()
def stub_mint() -> Iterator[str]:
    port = ports.allocate(1)[0]
    server = ThreadingHTTPServer(("127.0.0.1", port), _StubMintHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    yield f"http://127.0.0.1:{port}"
    server.shutdown()
    thread.join(timeout=5)


# ------------------------------------------------------------------- payserver


def _walk_wizard_mintless(url: str, store: str) -> None:
    w = SetupWizard(url)
    w.through_store(store)
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    w.post(step="mints", mints_enabled="0")


@pytest.fixture()
def mintless_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-lnsections-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    _walk_wizard_mintless(handle.url, "Sections Store")
    yield handle
    stop_payserver(handle)


def _admin_html(handle: PayserverHandle) -> str:
    admin = AdminClient(handle.url)
    admin.login(SetupWizard.DEFAULT_PASSWORD)
    return admin.s.get(admin._admin_url, timeout=15).text


def _store_row(handle: PayserverHandle) -> sqlite3.Row:
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        return conn.execute(
            "SELECT id, mint_url, mint_unit, seed_phrase, strict_no_mint_fallback"
            " FROM stores LIMIT 1"
        ).fetchone()


# ----------------------------------------------------------- raw-HTML contract


def test_sections_markup_order_and_default_collapsed(mintless_payserver) -> None:
    page = _admin_html(mintless_payserver)

    # All five sections render inside the Lightning payments card, in path
    # order, with the save button at the bottom of the card after the Cashu
    # section; the separate cashout / offline cards are gone.
    lp = page.index('id="card-lightning-payments"')
    positions = [page.index(f'id="{sid}"') for sid in SECTION_IDS]
    assert positions == sorted(positions), positions
    assert lp < positions[0]
    save_btn = page.index('id="btn-save-lightning-payments"')
    assert positions[4] < save_btn
    assert save_btn < page.index('id="card-store-swaps"')
    assert 'id="card-cashu-cashout"' not in page
    assert ">Offline Cashu Acceptance<" not in page

    # Markup default is collapsed for every section (JS expands non-empty
    # ones after the dashboard payload lands).
    for sid in SECTION_IDS:
        m = re.search(rf'<div class="([^"]*)" id="{sid}"', page)
        assert m, sid
        assert "collapsed" in m.group(1).split(), (sid, m.group(1))

    # Each section wraps its original group (ids unchanged for the JS).
    for sid, gid in [
        ("ln-section-strike", "auto-melt-strike-group"),
        ("ln-section-addresses", "auto-melt-address-group"),
        ("ln-section-nwc", "auto-melt-nwc-group"),
        ("ln-section-noffers", "auto-melt-noffer-group"),
    ]:
        start = page.index(f'id="{sid}"')
        nxt = SECTION_IDS[SECTION_IDS.index(sid) + 1]
        assert start < page.index(f'id="{gid}"') < page.index(f'id="{nxt}"'), gid

    # The Cashu section holds the cashout body and, inside it, the offline
    # Cashu settings (so both grey out together when there's no mint).
    cashu = page.index('id="ln-section-cashu"')
    body = page.index('id="cashu-cashout-body"')
    offline = page.index('id="offline-cashu-body"')
    onchain_card = page.index(">On-chain Bitcoin payments<")
    assert cashu < body < offline < onchain_card

    # The greyed-out notice carries the required statement + enable button,
    # and the enable-mint dialog ships in the page.
    assert MINT_WARNING in page
    assert 'id="btn-enable-cashu-mint"' in page
    assert 'id="modal-enable-mint"' in page
    assert 'id="enable-mint-url"' in page


# ------------------------------------------------------------- browser (JS)


@pytest.fixture()
def admin_page(mintless_payserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{mintless_payserver.url}/admin",
        form={
            "action": "login",
            "username": "admin",
            "password": SetupWizard.DEFAULT_PASSWORD,
        },
    )
    page = ctx.new_page()
    yield page, mintless_payserver.url
    ctx.close()


def _goto_stores(page, base) -> None:
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)


def _collapsed_map(page) -> dict[str, bool]:
    return page.evaluate(
        "ids => Object.fromEntries(ids.map(id =>"
        " [id, document.getElementById(id).classList.contains('collapsed')]))",
        SECTION_IDS,
    )


def test_all_sections_collapsed_by_default_and_toggle(admin_page) -> None:
    """A store with nothing saved starts with every section collapsed; the
    toggles expand/collapse them."""
    page, base = admin_page
    _goto_stores(page, base)

    assert all(_collapsed_map(page).values()), _collapsed_map(page)

    # Clicking a toggle expands the section; clicking again re-collapses.
    page.click("#ln-section-addresses .subsection-toggle")
    assert _collapsed_map(page)["ln-section-addresses"] is False
    assert page.is_visible("#btn-add-ln-address")
    page.click("#ln-section-addresses .subsection-toggle")
    assert _collapsed_map(page)["ln-section-addresses"] is True


def test_sections_with_saved_entries_expand_by_default(admin_page, mintless_payserver) -> None:
    """Save a noffer through the real endpoint, reload: the noffer section
    starts expanded while the still-empty ones stay collapsed."""
    page, base = admin_page
    _goto_stores(page, base)

    saved = page.evaluate(
        """async (noffer) => {
            const r = await postWithCsrf(adminUrl,
                'action=save_lightning_payments&store_id=' + encodeURIComponent(currentStoreId) +
                '&noffers[]=' + encodeURIComponent(noffer));
            return { ok: r.ok, body: await r.json() };
        }""",
        REFERENCE_NOFFER,
    )
    assert saved["ok"], saved

    _goto_stores(page, base)
    states = _collapsed_map(page)
    assert states["ln-section-noffers"] is False, states
    for sid in ("ln-section-strike", "ln-section-addresses", "ln-section-nwc", "ln-section-cashu"):
        assert states[sid] is True, states


def test_cashu_greyed_without_mint_and_enable_flow(admin_page, mintless_payserver, stub_mint) -> None:
    """The Cashu section greys out on a mintless store with the required
    statement; the Enable button walks the dialog (mint URL → seed backup)
    and the section un-greys, with the strict pin cleared in the DB."""
    page, base = admin_page
    _goto_stores(page, base)

    # Greyed + statement + working enable button despite the inert body.
    page.click("#ln-section-cashu .subsection-toggle")
    assert page.evaluate(
        "getComputedStyle(document.getElementById('cashu-cashout-body')).pointerEvents"
    ) == "none"
    warning = page.locator("#cashu-cashout-mint-warning")
    assert not warning.evaluate("el => el.classList.contains('hidden')")
    assert MINT_WARNING in re.sub(r"\s+", " ", warning.inner_text())
    # Offline Cashu settings sit inside the greyed body.
    assert page.evaluate(
        "!!document.getElementById('offline-cashu-body').closest('#cashu-cashout-body')"
    )

    # The mintless wizard pinned strict mode ON.
    assert int(_store_row(mintless_payserver)["strict_no_mint_fallback"]) == 1

    # Dialog flow: open → fill → save → one-time seed backup panel.
    page.click("#btn-enable-cashu-mint")
    page.wait_for_selector("#modal-enable-mint.visible")
    page.fill("#enable-mint-url", stub_mint)
    page.click("#btn-enable-mint-save")
    page.wait_for_selector("#enable-mint-seed", state="visible", timeout=20000)
    seed_shown = page.inner_text("#enable-mint-seed-value").strip()
    assert len(seed_shown.split()) >= 12, seed_shown
    page.click("#btn-enable-mint-done")
    page.wait_for_selector("#modal-enable-mint.visible", state="detached", timeout=5000)

    # Persisted: mint + the seed that was shown; strict pin cleared.
    row = _store_row(mintless_payserver)
    assert row["mint_url"] == stub_mint
    assert row["mint_unit"] == "sat"
    assert row["seed_phrase"] == seed_shown
    assert int(row["strict_no_mint_fallback"]) == 0

    # The section un-greys without a manual reload.
    page.wait_for_function(
        "getComputedStyle(document.getElementById('cashu-cashout-body')).pointerEvents !== 'none'",
        timeout=15000,
    )
    assert page.evaluate(
        "document.getElementById('cashu-cashout-mint-warning').classList.contains('hidden')"
    )

    # Default expansion rule: mint present but cashout still unconfigured →
    # collapsed on a fresh load; enabling cashout flips it to expanded.
    _goto_stores(page, base)
    assert _collapsed_map(page)["ln-section-cashu"] is True

    saved = page.evaluate(
        """async () => {
            const r = await postWithCsrf(adminUrl,
                'action=save_auto_melt&store_id=' + encodeURIComponent(currentStoreId) +
                '&enabled=1&threshold=2000&mode_override=0');
            return { ok: r.ok, body: await r.json() };
        }"""
    )
    assert saved["ok"], saved
    _goto_stores(page, base)
    assert _collapsed_map(page)["ln-section-cashu"] is False


def test_enable_mint_dialog_rejects_unreachable_mint(admin_page) -> None:
    """A dead mint URL surfaces the server's error in the dialog and leaves
    the store unconfigured."""
    page, base = admin_page
    _goto_stores(page, base)
    page.click("#ln-section-cashu .subsection-toggle")
    page.click("#btn-enable-cashu-mint")
    page.wait_for_selector("#modal-enable-mint.visible")
    page.fill("#enable-mint-url", "http://127.0.0.1:1/")
    page.click("#btn-enable-mint-save")
    page.wait_for_selector("#enable-mint-error:not(.hidden)", timeout=20000)
    assert "Cannot connect" in page.inner_text("#enable-mint-error")
    # Still greyed out — nothing was persisted.
    assert page.evaluate(
        "getComputedStyle(document.getElementById('cashu-cashout-body')).pointerEvents"
    ) == "none"
