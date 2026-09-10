<?php
/**
 * BareBits plugin — API bridge for rewrite-hostile hosts.
 *
 * The alongside install routes its Greenfield API ({install}/api/v1/...) to
 * api.php through its own .htaccess. Hosts that ignore .htaccess and support
 * no PATH_INFO (nginx with a stock WordPress config — Local WP, most managed
 * nginx hosting) only execute URLs that end in .php; every other /barebits
 * URL falls through the web server's try_files into WordPress — which is
 * exactly where this plugin runs. So when a request for the install's API
 * lands in WordPress instead of the install, this bridge catches it and
 * replays it against the install's api.php as a direct .php URL, carrying the
 * API path as a query parameter (api.php's cashupay_path transport). The
 * response is streamed back unchanged, so the canonical /api/v1 URLs work for
 * every caller — the WooCommerce gateway, this plugin, external API clients.
 *
 * On hosts where the install's rewrites work, these requests are served by
 * the install before WordPress ever sees them, and the bridge is inert.
 *
 * Pure HTTP glue in both directions: no BareBits code runs inside WordPress,
 * and the BareBits side only ever sees an ordinary API request. License:
 * GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'cashupay_maybe_bridge_api_request');

/**
 * Decide whether a request path is an API request for the alongside install
 * that fell through to WordPress, and if so which API path it carries.
 *
 * $requestPath is the path component of the incoming request URI;
 * $installUrl is the install's base URL ('' when no install exists). Returns
 * the API path to replay against the install ('/api/v1/...' or the BTCPay-
 * compatibility '/v1/...' form, exactly as requested), or null when the
 * request is not ours to answer.
 *
 * Pure (no WordPress calls) so tests/php can pin the matching without a
 * WordPress install; cashupay_maybe_bridge_api_request() is the live caller.
 */
function cashupay_api_bridge_path(string $requestPath, string $installUrl): ?string {
    if ($installUrl === '') {
        return null;
    }
    $installPath = rtrim((string) wp_parse_url($installUrl, PHP_URL_PATH), '/');
    if ($installPath === '' || !str_starts_with($requestPath, $installPath . '/')) {
        return null;
    }
    $remainder = substr($requestPath, strlen($installPath));
    // The Greenfield API lives at /api/v1/*; /v1/* is the BTCPay-compatible
    // alias the install's own .htaccess also rewrites. Nothing else under the
    // install belongs to the bridge — every other endpoint is a real .php
    // file the host serves directly.
    return preg_match('#^/(?:api/)?v1/#', $remainder) ? $remainder : null;
}

/**
 * The incoming request's Authorization header, wherever this SAPI put it.
 * Greenfield API auth travels in it, so the bridge must forward it intact.
 */
function cashupay_api_bridge_authorization(): string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            return sanitize_text_field(wp_unslash((string) $_SERVER[$key]));
        }
    }
    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return (string) $value;
            }
        }
    }
    return '';
}

/**
 * Catch an install-API request that fell through to WordPress and replay it
 * against the install's api.php. Runs on plugins_loaded — before WordPress
 * routes, canonicalizes, or 404s the request.
 *
 * No nonce, deliberately: this is machine-to-machine API traffic (the
 * WooCommerce gateway, external Greenfield API clients), not a browser form
 * — the callers have no WordPress session to bind a nonce to. Authentication
 * is the Greenfield API key in the Authorization header, checked by the
 * BareBits install the request is replayed against; the bridge itself grants
 * nothing (an unauthenticated request is replayed and comes back 401, same
 * as if the install's own rewrites had served it). It only ever proxies to
 * the plugin's OWN alongside install on this site's own origin — the target
 * is built from the stored install URL, never from request input — and the
 * response is relayed under the install's Content-Type, never rendered as
 * this site's HTML.
 */
function cashupay_maybe_bridge_api_request(): void {
    $requestPath = (string) wp_parse_url(sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''))), PHP_URL_PATH);
    // Cheap pre-check before touching options on every request.
    if (strpos($requestPath, '/v1/') === false) {
        return;
    }

    $installUrl = cashupay_install_url();
    // Only bridge for OUR alongside install, and only when that install is
    // this site's own origin — the only layout where its dead rewrites can
    // land requests here in the first place.
    if ($installUrl === '' || !cashupay_is_same_host_url($installUrl)) {
        return;
    }
    $apiPath = cashupay_api_bridge_path($requestPath, $installUrl);
    if ($apiPath === null) {
        return;
    }

    $target = $installUrl . '/api.php?cashupay_path=' . rawurlencode($apiPath);
    // Forwarded verbatim: this is the raw query string of an API request being
    // replayed against the same-origin install; sanitizing could corrupt
    // legitimate parameters. It is only ever a URL component of a server-side
    // HTTP request, never output.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        $target .= '&' . $query;
    }

    $method = strtoupper(sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
    $headers = [];
    $authorization = cashupay_api_bridge_authorization();
    if ($authorization !== '') {
        $headers['Authorization'] = $authorization;
    }
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = sanitize_text_field(wp_unslash((string) $_SERVER['CONTENT_TYPE']));
    }

    $args = [
        'method' => $method,
        'timeout' => 30,
        'redirection' => 0,
        'sslverify' => false, // proven same-origin above, like WP's own loopbacks
        'headers' => $headers,
    ];
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $args['body'] = (string) file_get_contents('php://input');
    }

    $response = wp_remote_request($target, $args);

    nocache_headers();
    if (is_wp_error($response)) {
        status_header(502);
        header('Content-Type: application/json');
        echo wp_json_encode([
            'code' => 'bridge-unreachable',
            'message' => 'Could not reach the BareBits install: ' . $response->get_error_message(),
        ]);
        exit;
    }

    status_header((int) wp_remote_retrieve_response_code($response));
    $contentType = wp_remote_retrieve_header($response, 'content-type');
    if (is_array($contentType)) {
        $contentType = (string) end($contentType);
    }
    header('Content-Type: ' . (is_string($contentType) && $contentType !== '' ? $contentType : 'application/json'));
    // Raw proxy passthrough: the install's API response body is relayed
    // byte-for-byte under its own Content-Type (JSON); it is never rendered
    // as this site's HTML, and escaping would corrupt the API payload.
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo wp_remote_retrieve_body($response);
    exit;
}
