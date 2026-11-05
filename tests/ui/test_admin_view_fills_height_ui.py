"""Admin views fill the full vertical space.

Regression coverage for the layout fix where `.view.active` only grew to its
content height, leaving empty space below short pages (Settings was the
reported example).

Covered behaviours:

* List-style views (Invoices): the primary list card (`.view-fill`,
  `flex:1 1 auto`) grows so its bottom reaches the bottom of `.main`.
* Settings, short content: the version footer (`.view-footer`) is pinned to the
  bottom of the `.main` area.
* Settings, desktop with tall content: the cards scroll inside `.settings-scroll`
  while the footer stays fixed below them — always visible without scrolling,
  and the page itself does not scroll (only the card region does).
* Settings, mobile: the footer scrolls with the page and clears the fixed
  bottom nav bar when scrolled to the end (desktop scroll-region does not apply).
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui

# Tolerance: `.main` has padding-bottom (2rem desktop) plus sub-pixel rounding,
# so the pinned element's bottom sits a little above the .main box bottom.
_BOTTOM_SLACK_PX = 80


def _login(configured: ConfiguredPayserver, page) -> None:
    # Tall viewport so each view's content is shorter than the viewport and
    # there is genuine free vertical space for the fill behaviour to show.
    page.set_viewport_size({"width": 1280, "height": 2600})
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _gap_to_main_bottom(page, selector: str) -> float:
    """Pixels between the bottom of `selector` and the bottom of `.main`."""
    return page.evaluate(
        """(sel) => {
            const main = document.querySelector('.main');
            const el = document.querySelector(sel);
            if (!main || !el) return null;
            return main.getBoundingClientRect().bottom
                 - el.getBoundingClientRect().bottom;
        }""",
        selector,
    )


def test_settings_footer_pinned_to_bottom(shared_configured: ConfiguredPayserver, page) -> None:
    _login(shared_configured, page)

    page.click('.nav-item[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#view-settings.active")
    page.wait_for_selector("#view-settings .view-footer")

    gap = _gap_to_main_bottom(page, "#view-settings .view-footer")
    assert gap is not None, "settings view or footer not found"
    assert gap >= 0, f"footer overflows .main bottom by {-gap:.1f}px"
    assert gap <= _BOTTOM_SLACK_PX, (
        f"settings footer is {gap:.1f}px above the bottom of .main; expected it "
        f"pinned within {_BOTTOM_SLACK_PX}px (view not filling height)"
    )


def test_settings_footer_always_visible_on_desktop(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """Desktop: footer stays on screen even when the card list overflows.

    On a short desktop viewport the admin Settings cards are taller than the
    viewport. The cards scroll inside `.settings-scroll` while the footer stays
    fixed below it, so the footer is visible WITHOUT any scrolling. Before this
    change the footer scrolled with the content and sat below the fold.
    """
    configured = shared_configured
    # Short, wide viewport: wide => desktop layout (>=768px); short => the
    # admin card list is taller than the viewport so the scroll region engages.
    page.set_viewport_size({"width": 1280, "height": 600})
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    page.click('.nav-item[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#view-settings.active")
    page.wait_for_selector("#view-settings .settings-scroll")
    page.wait_for_selector("#view-settings .view-footer")

    geom = page.evaluate(
        """() => {
            const scroll = document.querySelector('#view-settings .settings-scroll');
            const f = document.querySelector('#view-settings .view-footer')
                        .getBoundingClientRect();
            const s = scroll.getBoundingClientRect();
            return {
                scrollHeight: scroll.scrollHeight,
                clientHeight: scroll.clientHeight,
                footTop: f.top, footBottom: f.bottom, footHeight: f.height,
                scrollBottom: s.bottom,
                pageScrollMax: document.body.scrollHeight - window.innerHeight,
                vh: window.innerHeight,
            };
        }"""
    )
    # The card region genuinely overflows (otherwise the test proves nothing).
    assert geom["scrollHeight"] > geom["clientHeight"] + 1, (
        "settings card list did not overflow its region; viewport may be too "
        f"tall (scrollHeight={geom['scrollHeight']}, clientHeight={geom['clientHeight']})"
    )
    # Footer is fully on screen with no scrolling.
    assert geom["footHeight"] > 0 and geom["footTop"] >= 0, (
        f"footer top off-screen at {geom['footTop']:.1f}px"
    )
    assert geom["footBottom"] <= geom["vh"] + 1, (
        f"footer bottom {geom['footBottom']:.1f}px below viewport {geom['vh']}px "
        f"(not visible without scrolling)"
    )
    # Footer sits below the scroll region, not overlapping it.
    assert geom["footTop"] >= geom["scrollBottom"] - 1, (
        f"footer top {geom['footTop']:.1f}px overlaps scroll region bottom "
        f"{geom['scrollBottom']:.1f}px"
    )
    # The page itself must not scroll on desktop — only the inner region does.
    assert geom["pageScrollMax"] <= 1, (
        f"page is scrollable by {geom['pageScrollMax']:.1f}px; the card region "
        f"should scroll instead of the whole page"
    )


def test_settings_footer_visible_above_mobile_nav(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """Footer must not be hidden behind the fixed bottom nav on mobile.

    On narrow viewports `.nav` is a `position:fixed` bottom bar; `.main`'s
    padding-bottom reserves room for it. Whether the pinned footer engages
    (short content) or the page scrolls (tall content), scrolling to the very
    bottom must leave the footer fully above the nav bar.
    """
    configured = shared_configured
    page.set_viewport_size({"width": 390, "height": 844})
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    page.click('.nav-item[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#view-settings.active")
    page.wait_for_selector("#view-settings .view-footer")
    # Scroll to the end so we test the worst case (footer at the bottom edge).
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")

    geom = page.evaluate(
        """() => {
            const f = document.querySelector('#view-settings .view-footer')
                        .getBoundingClientRect();
            const navEl = document.querySelector('.nav');
            const nav = navEl.getBoundingClientRect();
            const fixedBottomBar =
                getComputedStyle(navEl).position === 'fixed'
                && nav.top > window.innerHeight / 2;
            return {
                footTop: f.top, footBottom: f.bottom,
                navTop: nav.top, fixedBottomBar,
                vh: window.innerHeight,
            };
        }"""
    )
    assert geom["fixedBottomBar"], "expected a fixed bottom nav on mobile width"
    # Footer fully within the viewport...
    assert geom["footTop"] >= 0, f"footer top off-screen at {geom['footTop']:.1f}px"
    assert geom["footBottom"] <= geom["vh"] + 1, (
        f"footer bottom {geom['footBottom']:.1f}px below viewport {geom['vh']}px"
    )
    # ...and above the bottom nav bar (1px tolerance for rounding).
    assert geom["footBottom"] <= geom["navTop"] + 1, (
        f"footer bottom {geom['footBottom']:.1f}px overlaps bottom nav at "
        f"{geom['navTop']:.1f}px (footer hidden behind nav)"
    )


def test_invoices_list_card_fills_height(shared_configured: ConfiguredPayserver, page) -> None:
    _login(shared_configured, page)

    page.locator('.nav-item[data-view="invoices"]').click()
    page.wait_for_selector("#view-invoices.active")
    page.wait_for_selector("#view-invoices .view-fill")

    gap = _gap_to_main_bottom(page, "#view-invoices .view-fill")
    assert gap is not None, "invoices view or list card not found"
    assert gap <= _BOTTOM_SLACK_PX, (
        f"invoices list card stops {gap:.1f}px above the bottom of .main; "
        f"expected it to grow and fill (within {_BOTTOM_SLACK_PX}px)"
    )
