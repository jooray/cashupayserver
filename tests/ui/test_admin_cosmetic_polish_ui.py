"""Admin cosmetic polish: settings cards collapsed on every view entry, the
accent rollover on card headers / section toggles / sidebar nav, and the
Cashu section sitting above the Lightning-payments Save button."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui

ACCENT = "rgb(247, 147, 26)"
BLACK = "rgb(0, 0, 0)"


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _collapsed_flags(page, view: str) -> list:
    return page.eval_on_selector_all(
        f"#view-{view} .card.collapsible",
        "els => els.map(e => e.classList.contains('collapsed'))",
    )


def _style(page, selector: str, prop: str, pseudo: str = "") -> str:
    return page.evaluate(
        "([sel, prop, pseudo]) => getComputedStyle(document.querySelector(sel), pseudo || null)[prop]",
        [selector, prop, pseudo],
    )


@pytest.mark.parametrize("view", ["stores", "settings"])
def test_settings_cards_collapsed_on_view_entry(
    shared_configured: ConfiguredPayserver, page, view
) -> None:
    _login_admin(page, shared_configured)
    page.click(f'.nav-item[data-view="{view}"]')
    page.wait_for_selector(f"#view-{view}.active")

    flags = _collapsed_flags(page, view)
    assert len(flags) >= 8 and all(flags), flags
    # Only the headers are rendered while collapsed.
    first = page.locator(f"#view-{view} .card.collapsible:not(.hidden)").first
    assert first.locator("> .card-header").is_visible()
    assert first.locator("> .card-body").count() == 0 or first.locator("> .card-body").is_hidden()

    # Clicking a header opens that card; the rest stay closed.
    first.locator("> .card-header").click()
    assert "collapsed" not in (first.get_attribute("class") or "")
    assert sum(1 for f in _collapsed_flags(page, view) if not f) == 1

    # Leaving and re-entering the view closes everything again.
    page.click('.nav-item[data-view="dashboard"]')
    page.wait_for_selector("#view-dashboard.active")
    page.click(f'.nav-item[data-view="{view}"]')
    page.wait_for_selector(f"#view-{view}.active")
    assert all(_collapsed_flags(page, view)), _collapsed_flags(page, view)


def test_store_switch_keeps_open_cards(shared_configured: ConfiguredPayserver, page) -> None:
    """Re-collapsing is tied to entering the view, not to the store-settings
    reload a store switch triggers."""
    _login_admin(page, shared_configured)
    page.click('.nav-item[data-view="stores"]')
    page.wait_for_selector("#view-stores.active")
    header = page.locator("#card-lightning-payments > .card-header")
    header.click()
    assert "collapsed" not in (page.get_attribute("#card-lightning-payments", "class") or "")
    page.evaluate("async () => { await loadStoreSettings(); await loadDashboard(); }")
    assert "collapsed" not in (page.get_attribute("#card-lightning-payments", "class") or "")


def test_header_and_nav_rollover_colors(shared_configured: ConfiguredPayserver, page) -> None:
    _login_admin(page, shared_configured)
    assert page.evaluate("matchMedia('(hover: hover)').matches"), (
        "headless Chromium must report a hover-capable pointer for this test"
    )
    page.click('.nav-item[data-view="stores"]')
    page.wait_for_selector("#view-stores.active")

    # Card header: accent fill, black text, black chevron.
    sel = "#card-lightning-payments > .card-header"
    resting_bg = _style(page, sel, "backgroundColor")
    assert resting_bg != ACCENT
    page.hover(sel)
    page.wait_for_function(
        "sel => getComputedStyle(document.querySelector(sel)).backgroundColor === 'rgb(247, 147, 26)'",
        arg=sel,
    )
    assert _style(page, sel, "color") == BLACK
    assert _style(page, sel, "borderRightColor", "::after") == BLACK

    # Section toggle inside the card.
    page.click(sel)  # expand (the hover above left it collapsed)
    tog = "#ln-section-strike > .subsection-toggle"
    page.wait_for_selector(tog, state="visible")
    page.hover(tog)
    page.wait_for_function(
        "sel => getComputedStyle(document.querySelector(sel)).backgroundColor === 'rgb(247, 147, 26)'",
        arg=tog,
    )
    assert _style(page, tog, "color") == BLACK
    assert _style(page, tog, "borderRightColor", "::after") == BLACK

    # Sidebar nav: inactive and active buttons both fill on hover; the active
    # one rests as accent text on a transparent background.
    active = '.nav-item[data-view="stores"]'
    assert _style(page, active, "color") == ACCENT
    assert _style(page, active, "backgroundColor") in ("rgba(0, 0, 0, 0)", "transparent")
    for nav in ('.nav-item[data-view="dashboard"]', active):
        page.hover(nav)
        page.wait_for_function(
            "sel => getComputedStyle(document.querySelector(sel)).backgroundColor === 'rgb(247, 147, 26)'",
            arg=nav,
        )
        assert _style(page, nav, "color") == BLACK
    # Moving away restores the resting state (both properties transition, so
    # wait for the end state rather than sampling mid-fade).
    page.hover("#header-text")
    page.wait_for_function(
        "sel => { const cs = getComputedStyle(document.querySelector(sel));"
        " return cs.color === 'rgb(247, 147, 26)' && cs.backgroundColor !== 'rgb(247, 147, 26)'; }",
        arg=active,
    )


def test_cashu_section_above_save_button(shared_configured: ConfiguredPayserver, page) -> None:
    _login_admin(page, shared_configured)
    page.click('.nav-item[data-view="stores"]')
    page.wait_for_selector("#view-stores.active")
    in_order = page.evaluate(
        """() => {
            const card = document.getElementById('card-lightning-payments');
            const ids = ['ln-section-strike', 'ln-section-addresses', 'ln-section-nwc',
                         'ln-section-noffers', 'ln-section-cashu', 'btn-save-lightning-payments'];
            const els = ids.map(id => document.getElementById(id));
            if (els.some(e => !e || !card.contains(e))) return 'missing';
            for (let i = 0; i + 1 < els.length; i++) {
                if (!(els[i].compareDocumentPosition(els[i + 1]) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                    return ids[i + 1] + ' precedes ' + ids[i];
                }
            }
            return 'ok';
        }"""
    )
    assert in_order == "ok", in_order
