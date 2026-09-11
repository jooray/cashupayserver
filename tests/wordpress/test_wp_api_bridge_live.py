"""wordpress.org review gate (2026-09 submission feedback, second round),
live half for the API bridge: nothing from a bridged request is replayed raw.

The static gate (test_wp_plugin_check.py::test_request_input_reads_are_sanitized)
proves the source never reads request input bare, and the PHP unit tests pin
the pure query re-encoder and body validator pair by pair. This module proves
the refusal behavior over HTTP on the host shape the bridge exists for — a
rewrite-hostile host where the install's /api/v1 URLs fall through into
WordPress:

  - a bridged GET reaches the install's API, with or without a query string
    (the re-encoding must never break the replay)
  - a non-JSON body is refused with 400 before any replay
  - an oversized body is refused with 413
  - a nonstandard method on a provably-ours path is refused with 405

Deliberately mintless and fast: the install stays un-set-up, so its API
answers every replayed request with its own 503 service-unavailable JSON —
which is exactly the proof the replay happened (an unbridged fall-through
would be WordPress's themed 404 HTML), and every refusal above happens in the
bridge before the install is contacted at all. The one assertion that needs a
fully set-up install — a smuggled cashupay_path parameter cannot repoint the
replay to a different API endpoint — lives in test_wp_hostile_checkout.py,
which has one.
"""
from __future__ import annotations

import pytest
import requests

from wordpress.conftest import post_onboarding, wp_login
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

MAX_BODY_BYTES = 1048576  # mirrors CASHUPAY_BRIDGE_MAX_BODY_BYTES


def _assert_replayed(r: requests.Response) -> None:
    """The request provably rode the bridge into the install's api.php: the
    un-set-up install answers 503 in its own JSON error shape. WordPress
    serving the URL itself would be a themed 404 page. The bridge validates
    the upstream body as JSON and re-emits it via wp_json_encode as
    application/json (wp.org escape-late review gate) — so the reply parsing
    here is also the proof the re-encoded relay stays valid JSON."""
    assert r.status_code == 503, f"bridged request -> {r.status_code}: {r.text[:300]}"
    assert (r.headers.get("Content-Type") or "").startswith("application/json"), r.headers
    assert r.json().get("code") == "service-unavailable", r.text[:300]


def test_bridge_validates_before_replaying(wordpress_hostile_host: WordPressHandle) -> None:
    wp = wordpress_hostile_host

    # Install alongside for real — the bridge only wakes up once an install
    # is recorded. The install's own setup wizard is deliberately NOT walked
    # (see the module docstring).
    s = wp_login(wp)
    post_onboarding(s, wp, "cashupay_choose_mode", {"cashupay_mode": "install"})
    body = post_onboarding(s, wp, "cashupay_run_install")
    assert "BareBits is installed at" in body, body[:2000]

    # This URL has no .php and no rewrites serve it on this host: it provably
    # rides the bridge (the hostile-host journey test pins that routing).
    info_url = f"{wp.barebits_url}/api/v1/server/info"

    # Baseline: the bridged GET reaches the install's API.
    _assert_replayed(requests.get(info_url, timeout=60))

    # A query string survives the re-encoding: order, duplicate keys, and
    # space spellings are all legal input and must not break the replay.
    _assert_replayed(requests.get(f"{info_url}?skip=0&dup=1&dup=2&odd=a+b%20c", timeout=60))

    # A non-JSON body is refused before any replay.
    r = requests.post(info_url, data="amount=1&currency=USD", timeout=60)
    assert r.status_code == 400, f"{r.status_code}: {r.text[:300]}"
    assert r.json().get("code") == "bridge-invalid-body", r.text[:300]

    # An oversized body — even valid JSON — is refused with 413.
    r = requests.post(
        info_url, data='"' + "a" * (MAX_BODY_BYTES - 1) + '"', timeout=60
    )
    assert r.status_code == 413, f"{r.status_code}: {r.text[:300]}"
    assert r.json().get("code") == "bridge-body-too-large", r.text[:300]

    # A valid JSON body IS replayed (the install refuses it as un-set-up,
    # not the bridge): the happy path must not be over-blocked.
    _assert_replayed(requests.post(info_url, data='{"probe":true}', timeout=60))

    # A nonstandard method on a provably-ours path is refused with 405.
    # TRACE deliberately: php -S forwards it to the script (a made-up verb
    # like BREW dies as the web server's own 501 and never reaches the
    # plugin), but it is not a verb the Greenfield API speaks.
    r = requests.request("TRACE", info_url, timeout=60)
    assert r.status_code == 405, f"{r.status_code}: {r.text[:300]}"
    assert r.json().get("code") == "bridge-method-not-allowed", r.text[:300]
    assert "GET" in (r.headers.get("Allow") or ""), r.headers

    # And the bridge is still healthy after every refusal.
    _assert_replayed(requests.get(info_url, timeout=60))
