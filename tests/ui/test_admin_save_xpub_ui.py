"""Bug 2 repro — saving a new xpub in store settings via the admin UI.

Drives the admin SPA: login, navigate to the per-store settings card, paste
a fresh valid xpub, click Save on-chain settings. Captures console errors,
the actual HTTP request/response, and the toast text. Also reads the SQLite
to verify whether the new xpub was actually persisted.

Run with: pytest tests/ui/test_admin_save_xpub_ui.py -v -s
"""
from __future__ import annotations

import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui

# A fresh, valid regtest tpub re-encoded from the BIP32 mainnet test vector
# (same trick fixtures/onchain.py uses, inlined so this test is self-contained).
def _xpub_to_tpub(xpub: str) -> str:
    alphabet = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"
    n = 0
    for ch in xpub:
        n = n * 58 + alphabet.index(ch)
    body = n.to_bytes((n.bit_length() + 7) // 8, "big") if n else b""
    leading = sum(1 for ch in xpub if ch == "1" and ch != alphabet[0] or False)
    # simpler: count leading '1' chars
    leading = 0
    for ch in xpub:
        if ch != "1":
            break
        leading += 1
    decoded = b"\x00" * leading + body
    assert decoded[:4].hex().lower() == "0488b21e", "expected mainnet xpub"
    new_body = bytes.fromhex("043587CF") + decoded[4:-4]  # strip old checksum, swap version
    import hashlib
    checksum = hashlib.sha256(hashlib.sha256(new_body).digest()).digest()[:4]
    payload = new_body + checksum
    num = int.from_bytes(payload, "big") if payload else 0
    out = ""
    while num > 0:
        num, rem = divmod(num, 58)
        out = alphabet[rem] + out
    pad = 0
    for b in payload:
        if b != 0:
            break
        pad += 1
    return "1" * pad + out


_MAINNET = (
    "xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2"
    "cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz"
)
FRESH_TPUB = _xpub_to_tpub(_MAINNET)


def test_save_new_xpub_replacing_existing(shared_configured: ConfiguredPayserver, page) -> None:
    """Reproduces the user's exact scenario:

    1. Store already has an on-chain xpub configured (iterate.py does this
       via direct DB write). The dashboard reports `onchain.enabled = true`.
    2. User opens admin settings, pastes a different xpub, clicks Save.
       Per saveOnchain() in admin.php, this should:
         - prompt confirm() ("Replacing the xpub will reset the counter")
         - on accept, POST action=save_onchain
         - show a success toast and refresh the dashboard
       Per the user, nothing visibly happens.

    We capture console + network + toast + DB state to surface where it
    actually breaks.
    """
    configured = shared_configured
    # --- step 0: seed the store with an initial xpub via the same DB-write
    # path iterate.py uses, so dashboardData.onchain.enabled is true at click
    # time (the precondition for the bug).
    initial_xpub = (
        "tpubD6NzVbkrYhZ4XgafMK7HGSXEHj1DTC3eUmWk7QyXz9o8VVDLfwLJaJCWX"
        "8DBzdczj2g5Z3JoXMcJ7Lj3wcgkF2nWEy3vqdwSPL3FpzaB9wL"  # any valid tpub
    )
    # Reuse the fresh one we generate to seed (it's a valid tpub from BIP32
    # vector + version swap), and use _MAINNET re-cased as the "new" one.
    initial_xpub = FRESH_TPUB
    new_xpub = _xpub_to_tpub(_MAINNET[:-2] + _MAINNET[-2:])  # same string -> same xpub
    # We need two DIFFERENT valid tpubs. Derive a second by mutating a
    # non-version byte of the decoded blob and re-checksumming.
    new_xpub = _alt_tpub(_MAINNET)

    with sqlite3.connect(configured.handle.db_path) as db:
        db.execute(
            "UPDATE stores SET onchain_xpub = ?, onchain_network = 'regtest', "
            "onchain_address_type = 'P2WPKH', onchain_next_index = 0 WHERE id = ?",
            (initial_xpub, configured.store_id),
        )
        db.commit()

    # --- step 1: instrument the page
    console_msgs: list[str] = []
    page.on("console", lambda m: console_msgs.append(f"[{m.type}] {m.text}"))
    failed_requests: list[str] = []
    page.on("requestfailed", lambda r: failed_requests.append(
        f"{r.method} {r.url} -- {r.failure}"
    ))
    save_resp: dict = {}
    def _grab(resp):
        url = resp.url
        if "/admin" not in url:
            return
        # All admin POSTs use a form body with action=...; capture the save_onchain one
        try:
            body = resp.text()
            if "save_onchain" in (resp.request.post_data or "") and "save_onchain" not in save_resp:
                save_resp.update(status=resp.status, body=body)
        except Exception as e:
            save_resp.setdefault("error", str(e))
    page.on("response", _grab)

    dialogs_seen: list[str] = []
    def _on_dialog(d):
        dialogs_seen.append(f"{d.type}: {d.message}")
        d.accept()
    page.on("dialog", _on_dialog)

    page.set_default_timeout(15000)

    # --- step 2: login + navigate to store settings
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # Pin the header selector to this test's own store — the shared server
    # auto-selects its first store, but the xpub was seeded into (and must be
    # saved back to) the per-test store.
    page.select_option("#store-select", configured.store_id)

    # Click the Stores nav item — that's the view holding the on-chain card.
    page.locator('.nav-item[data-view="stores"]').click()
    expand_settings_cards(page)
    page.wait_for_selector("#onchain-xpub", state="visible")

    # Wait until the dashboard fetch has populated the on-chain card — the
    # meta text only contains "Currently configured" once the dashboard
    # response has been rendered with onchain.enabled = true. That's also
    # the precondition for the saveOnchain() confirm dialog to fire.
    page.wait_for_function(
        "() => document.getElementById('onchain-xpub-meta')?.textContent.includes('Currently configured')"
    )

    # --- step 3: paste new xpub + click Save
    page.fill("#onchain-xpub", new_xpub)
    # network + type may have shifted; force them to the values that match
    # what iterate.py used so save_onchain isn't rejected for unrelated reasons.
    # They now live in the collapsed "Advanced" subsection, so expand it first.
    page.locator("#onchain-advanced > .subsection-toggle").click()
    page.wait_for_selector("#onchain-network", state="visible")
    page.select_option("#onchain-network", "regtest")
    page.select_option("#onchain-address-type", "P2WPKH")

    page.click("#btn-save-onchain")
    # saveOnchain() prompts a "Switch to a different xpub?" in-page modal
    # (not native confirm() — Chrome can suppress that per-tab, which was the
    # original silent-save bug this test was written to reproduce). Click
    # the modal's Continue button so the POST actually fires.
    page.wait_for_selector("#modal-onchain-confirm.visible", state="visible")
    page.click("#btn-onchain-confirm-yes")
    page.wait_for_timeout(3000)  # give the POST + toast + dashboard reload time

    # --- step 4: read final DB state
    with sqlite3.connect(configured.handle.db_path) as db:
        row = db.execute(
            "SELECT onchain_xpub FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()
    persisted = row[0] if row else None

    toasts = page.evaluate("""() => {
        return Array.from(document.querySelectorAll('.toast, .copy-toast'))
          .map(el => ({ text: el.textContent.trim(), classes: el.className }))
          .filter(t => t.text);
    }""")

    print("\n=== Bug 2 repro diagnostics ===")
    print(f"initial xpub in DB:  {initial_xpub[:20]}...{initial_xpub[-10:]}")
    print(f"new xpub typed:      {new_xpub[:20]}...{new_xpub[-10:]}")
    print(f"DB after save:       {(persisted or '')[:20]}...{(persisted or '')[-10:]}")
    print(f"changed?             {persisted != initial_xpub}")
    print(f"save_onchain resp:   {save_resp}")
    print(f"dialogs:             {dialogs_seen}")
    print(f"toasts:              {toasts}")
    print(f"console tail:        {console_msgs[-10:]}")
    print(f"failed requests:     {failed_requests}")
    print("=" * 40)

    assert persisted == new_xpub, (
        f"BUG REPRODUCED: xpub didn't change after save click. "
        f"save_resp={save_resp}, dialogs={dialogs_seen}, "
        f"console_tail={console_msgs[-5:]}"
    )


def _alt_tpub(mainnet_xpub: str) -> str:
    """Decode the mainnet xpub, replace its chain-code byte slightly, and
    re-encode as a tpub. Same xpub *prefix* (so we know it's valid) but
    produces a different base58-encoded string."""
    import hashlib
    alphabet = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"
    n = 0
    for ch in mainnet_xpub:
        n = n * 58 + alphabet.index(ch)
    body = n.to_bytes((n.bit_length() + 7) // 8, "big")
    leading = 0
    for ch in mainnet_xpub:
        if ch != "1":
            break
        leading += 1
    decoded = b"\x00" * leading + body
    # mutate a byte deep inside the chain-code region (offset 13–45)
    mutated = bytearray(decoded[:-4])
    mutated[20] ^= 0x01
    mutated[:4] = bytes.fromhex("043587CF")  # tpub version
    checksum = hashlib.sha256(hashlib.sha256(bytes(mutated)).digest()).digest()[:4]
    payload = bytes(mutated) + checksum
    num = int.from_bytes(payload, "big") if payload else 0
    out = ""
    while num > 0:
        num, rem = divmod(num, 58)
        out = alphabet[rem] + out
    pad = 0
    for b in payload:
        if b != 0:
            break
        pad += 1
    return "1" * pad + out
