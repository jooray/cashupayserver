"""Regression: mint discovery returns mints from the public Nostr relays.

Background: nostr-tools v2 changed `pool.subscribeMany` to take a single
filter object (it used to accept an array). When the submodule kept passing
`[filter]`, the resulting REQ frames came out as
`["REQ","sub:1",[{"kinds":[38172]}]]` and all relays rejected them with
`"ERROR: bad req: provided filter is not an object"` — so the discovery
modal sat at "Found 0 mints" forever.

This test walks the setup wizard to step 5, opens Discover Mints, and asserts
that at least one mint appears within 25 seconds. On failure it dumps the
WebSocket frames so the next person can see whether REQ regressed.

Requires outbound WSS to the four default Nostr relays.

Run with: pytest tests/ui/test_mint_discovery_diagnostic.py -v -s
"""
from __future__ import annotations

import time

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import browser_walk_to_store

pytestmark = pytest.mark.ui


def test_mint_discovery_finds_mints(
    payserver: PayserverHandle,
    mint: MintHandle,
    page,
) -> None:
    page.set_default_timeout(15000)

    websockets: list[dict] = []
    def _on_ws(ws):
        entry = {"url": ws.url, "sent": [], "recv": []}
        websockets.append(entry)
        ws.on("framesent", lambda payload: entry["sent"].append(str(payload)[:300]))
        ws.on("framereceived", lambda payload: entry["recv"].append(str(payload)[:300]))
    page.on("websocket", _on_ws)

    console_msgs: list[str] = []
    page.on("console", lambda m: console_msgs.append(f"[{m.type}] {m.text}"))

    # Walk the onboarding wizard to the Cashu mints screen. Order:
    # terms → analytics → installation type → security → password → e-mail
    # → store → on-chain (skip) → lightning (skip) → mints. The mints screen
    # hosts the same Discover-Mints modal and the discoveredMints /
    # openMintDiscovery globals this test exercises.
    browser_walk_to_store(page, payserver.url, "wizard-test-pw", store_name="Disco Store")
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#lightning_address")
    page.click("button:has-text('Skip for now')")
    page.wait_for_selector("#mint-manual-toggle")

    # Trigger the discovery modal + acknowledge disclaimer so the "Select"
    # buttons enable (irrelevant to the bug under test, but matches the flow).
    page.evaluate("openMintDiscovery('primary')")
    page.wait_for_selector("#mint-disclaimer-checkbox")
    page.check("#mint-disclaimer-checkbox")

    # Wait up to 25s for at least one mint to land in the list. Discovery
    # streams as Nostr events arrive; on the fix branch this is typically <5s.
    deadline = time.monotonic() + 25
    count = 0
    while time.monotonic() < deadline:
        count = page.evaluate("() => discoveredMints.length")
        if count > 0:
            break
        time.sleep(0.5)

    if count == 0:
        # Dump everything we know about why discovery came back empty.
        diag_lines = []
        diag_lines.append(f"final status: {page.locator('#mint-discovery-status').inner_text()!r}")
        for w in websockets:
            sample_sent = w["sent"][:3]
            sample_recv = w["recv"][:3]
            diag_lines.append(f"WS {w['url']}: sent={sample_sent}  recv={sample_recv}")
        diag_lines.append(f"console tail: {console_msgs[-15:]}")
        pytest.fail(
            "mint discovery returned 0 mints after 25s.\n" + "\n".join(diag_lines)
        )

    # Sanity-check the REQ frame shape on at least one relay — protects
    # against re-introducing the [filter] vs filter regression.
    bad_req = []
    for w in websockets:
        for frame in w["sent"]:
            if frame.startswith('["REQ"') and ',[{' in frame:
                bad_req.append((w["url"], frame))
    assert not bad_req, (
        "Detected malformed REQ frames (filter wrapped in array — the "
        f"original bug). First offender: {bad_req[0]}"
    )
