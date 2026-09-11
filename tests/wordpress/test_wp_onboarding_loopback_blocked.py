"""The genuinely loopback-blocked host: install succeeds, accurate warning.

Some hosts really do stop a site from making HTTP requests to its own URL
(an egress firewall, a hosting "loopback" restriction). The post-install
probe must tell that host apart from the rewrite-hostile-but-working ones
(covered by the tight-pool and browser-journey tests, which must see NO
warning) and say so plainly: the install itself lands fine and setup can
continue, but payments need loopback — ask the host.

The block is simulated at the exact layer real blocks manifest for the
plugin: a pre_http_request filter fails every request whose target is the
site's own origin (host AND port — the fixture release server shares
127.0.0.1 and must stay reachable), the WP-HTTP equivalent of a firewall
eating the connection.
"""
from __future__ import annotations

import pytest

from wordpress.conftest import post_onboarding, wp_login

pytestmark = pytest.mark.wordpress

BLOCK_MU_PLUGIN = """<?php
add_filter('pre_http_request', function ($pre, $args, $url) {
    $target = parse_url((string) $url);
    $self = parse_url(site_url('/'));
    $port = function ($parts) {
        return $parts['port'] ?? ((($parts['scheme'] ?? '') === 'https') ? 443 : 80);
    };
    if (is_array($target)
            && strtolower($target['host'] ?? '') === strtolower($self['host'] ?? '')
            && $port($target) === $port($self)) {
        return new WP_Error('http_request_failed',
            'cURL error 28: Operation timed out (simulated loopback block)');
    }
    return $pre;
}, 10, 3);
"""


def test_install_warns_accurately_when_loopback_is_blocked(wordpress_install_mode) -> None:
    wp = wordpress_install_mode
    mu = wp.wp_root / "wp-content" / "mu-plugins"
    mu.mkdir(parents=True, exist_ok=True)
    (mu / "barebits-test-loopback-block.php").write_text(BLOCK_MU_PLUGIN)

    s = wp_login(wp)
    post_onboarding(s, wp, "barebits_choose_mode", {"barebits_mode": "install"})
    body = post_onboarding(s, wp, "barebits_run_install")

    # The install itself lands (the release download is not loopback traffic)...
    assert "BareBits is installed at" in body, body[:2000]
    # ...and the warning names the proven condition, not a routing guess.
    assert "cannot make HTTP requests to its own URL" in body, body[:2000]
    assert "loopback" in body, body[:2000]
    # Setup is explicitly not dead-ended: the wizard step renders below.
    assert "Finish the BareBits setup" in body, body[:2000]
