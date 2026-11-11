<?php
/**
 * HTTP status helper — the single seam every endpoint sets a non-200 through.
 *
 * Historically this branched to WordPress's status_header() when core could
 * run inside the WordPress process (WP queues a literal "HTTP/1.1 200 OK"
 * status line during send_headers that http_response_code() alone does not
 * replace, so errors reached clients as 200s). Since the GPL split the server
 * never runs with WordPress loaded — the companion plugin reaches it purely
 * over HTTP, and its API bridge re-emits the replayed response's status on
 * the WordPress side itself — so plain http_response_code() is sufficient
 * and core stays entirely free of WordPress API references (a licensing
 * boundary, see LICENSE.md).
 */

if (!function_exists('cashupay_status')) {
    function cashupay_status(int $code): void {
        http_response_code($code);
    }
}
