"""Drive the cashupayserver setup wizard programmatically.

The wizard at /setup uses PHP sessions (cookies) and a 'step' POST field
that walks 1 -> 2 -> 4 -> 5 (mint URL) -> 5 again (mint unit) -> 6 (seed) -> 7.

`run_setup_wizard()` performs the standalone happy-path: security ack,
admin password, store create, mint URL, mint unit, generated seed,
seed confirm. Leaves the server initialized and ready for API auth.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Optional

import requests


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
) -> SetupResult:
    """Walk the standalone setup wizard end-to-end."""
    s = requests.Session()
    setup = f"{base_url}/setup"

    # Initial GET — triggers session start and schema init.
    s.get(setup, timeout=10)

    # Step 1: security acknowledgment
    r = s.post(setup, data={"step": "1", "security_acknowledged": "1"}, timeout=10, allow_redirects=False)
    _assert_step_ok(r, "step 1 (security)")

    # Step 2: admin password
    r = s.post(
        setup,
        data={"step": "2", "password": admin_password, "confirm_password": admin_password},
        timeout=10,
        allow_redirects=False,
    )
    _assert_step_ok(r, "step 2 (password)")

    # Step 4: create store
    r = s.post(setup, data={"step": "4", "store_name": store_name}, timeout=10, allow_redirects=False)
    _assert_step_ok(r, "step 4 (store)")

    # Step 5a: submit mint URL (no unit yet); server caches available units in session
    r = s.post(setup, data={"step": "5", "mint_url": mint_url}, timeout=30, allow_redirects=False)
    _assert_step_ok(r, "step 5a (mint URL)")

    # Step 5b: submit chosen unit
    r = s.post(
        setup,
        data={"step": "5", "mint_url": mint_url, "mint_unit": mint_unit},
        timeout=30,
        allow_redirects=False,
    )
    _assert_step_ok(r, "step 5b (mint unit)")

    # Step 6a: generate seed
    r = s.post(setup, data={"step": "6", "action": "generate"}, timeout=10, allow_redirects=False)
    _assert_step_ok(r, "step 6a (generate seed)")

    # Step 6b: confirm
    r = s.post(
        setup,
        data={"step": "6", "action": "confirm", "seed_confirmed": "1"},
        timeout=30,
        allow_redirects=False,
    )
    _assert_step_ok(r, "step 6b (confirm seed)")

    return SetupResult(
        admin_password=admin_password,
        store_name=store_name,
        mint_url=mint_url,
        mint_unit=mint_unit,
        seed_phrase=None,  # generated server-side; not surfaced back via the wizard
        session=s,
    )


def _assert_step_ok(r: requests.Response, label: str) -> None:
    if r.status_code >= 400:
        raise AssertionError(f"{label} returned {r.status_code}: {r.text[:400]}")
    # The wizard renders an error <div> on validation failure but still 200s.
    # Best-effort check:
    body = r.text
    if "id=\"error\"" in body or "alert-danger" in body:
        # Some pages render an empty error container — only flag when there's content.
        if "Please" in body[:5000] or "Invalid" in body[:5000]:
            raise AssertionError(f"{label} returned a validation error page: {body[:400]}")
