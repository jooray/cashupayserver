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
 * response — JSON by the API's contract — is validated and re-encoded through
 * wp_json_encode before it is emitted, so the canonical /api/v1 URLs work for
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

// The most a replayed request body may carry. Every real Greenfield payload
// is small JSON (an invoice create is well under 10 KB); a larger body is
// nothing the install's API could parse, so it is refused, never buffered
// whole or forwarded.
const CASHUPAY_BRIDGE_MAX_BODY_BYTES = 1048576;

// The only methods the bridge will replay — the verbs the Greenfield API
// speaks. Anything else on a provably-ours path is refused with 405.
const CASHUPAY_BRIDGE_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

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
                return sanitize_text_field((string) $value);
            }
        }
    }
    return '';
}

/**
 * Re-encode an incoming query string for the replayed request: each key and
 * value is decoded (urldecode, so +-as-space survives) and percent-encoded
 * again, preserving order, duplicate keys, and every byte of meaning — while
 * guaranteeing the result is well-formed with no character that could
 * corrupt the target URL. Two kinds of pair are dropped outright: empty
 * fragments, and any parameter that PHP's request parsing would read as
 * cashupay_path — that slot is api.php's path transport, already carrying
 * the validated API path, and an incoming duplicate must not override it.
 *
 * Pure (no WordPress calls) so tests/php can pin it;
 * cashupay_maybe_bridge_api_request() is the live caller.
 */
function cashupay_api_bridge_query(string $raw): string {
    $pairs = [];
    foreach (explode('&', $raw) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        $key = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
        // PHP normalizes parameter names before they reach $_GET: dots and
        // spaces in the segment before any bracket become underscores. Match
        // that, or "cashupay.path" (and bracketed variants) would slip past
        // the name comparison and still clobber api.php's path parameter.
        $bracket = strpos($key, '[');
        $base = strtr($bracket === false ? $key : substr($key, 0, $bracket), ['.' => '_', ' ' => '_']);
        if ($key === '' || $base === 'cashupay_path') {
            continue;
        }
        $encoded = rawurlencode($key);
        if ($eq !== false) {
            $encoded .= '=' . rawurlencode(urldecode(substr($pair, $eq + 1)));
        }
        $pairs[] = $encoded;
    }
    return implode('&', $pairs);
}

/**
 * Decide whether a bridged request body must be refused instead of replayed:
 * returns [HTTP status, error code, message], or null when the body is fine
 * to forward. The install's API parses every request body as JSON and
 * nothing else (getRequestBody() in api.php), so a valid body is empty or
 * JSON — anything else would silently degrade to an empty body over there,
 * and refusing it here with an honest error is strictly more informative.
 * A body that passes is forwarded as the ORIGINAL bytes: decode/re-encode
 * could alter number formatting inside payment amounts.
 *
 * Pure (no WordPress calls) so tests/php can pin it;
 * cashupay_maybe_bridge_api_request() is the live caller.
 */
function cashupay_api_bridge_body_refusal(string $body): ?array {
    if (strlen($body) > CASHUPAY_BRIDGE_MAX_BODY_BYTES) {
        return [413, 'bridge-body-too-large', 'The API bridge only replays request bodies up to 1 MB.'];
    }
    if ($body === '') {
        return null;
    }
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [400, 'bridge-invalid-body', 'The request body is not valid JSON — the BareBits API accepts only JSON bodies.'];
    }
    return null;
}

/**
 * Decide whether a bridged response body must be refused instead of relayed:
 * returns [HTTP status, error code, message], or null when the body is fine
 * to re-emit. The install's api.php sends Content-Type: application/json
 * unconditionally and every endpoint the bridge can reach emits JSON, so a
 * valid response body is empty or JSON — anything else (an HTML fatal-error
 * page, say) is not an API payload and must not be relayed onto this origin.
 * A body that passes is re-encoded through wp_json_encode() at the echo
 * (escape-late, in the JSON context), never emitted as the original bytes.
 *
 * Pure (no WordPress calls) so tests/php can pin it;
 * cashupay_maybe_bridge_api_request() is the live caller.
 */
function cashupay_api_bridge_response_refusal(string $body): ?array {
    if ($body === '') {
        return null;
    }
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [502, 'bridge-invalid-response', 'The BareBits install returned a non-JSON response the bridge will not relay.'];
    }
    return null;
}

/**
 * Answer a request the bridge has proven is ours but refuses to replay,
 * in the API's own error shape, and stop.
 */
function cashupay_api_bridge_refuse(int $status, string $code, string $message): void {
    nocache_headers();
    status_header($status);
    header('Content-Type: application/json');
    echo wp_json_encode(['code' => $code, 'message' => $message]);
    exit;
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
 * response is validated as JSON, re-encoded, and served as application/json,
 * never rendered as this site's HTML.
 *
 * Nothing from the request is replayed raw: the method must be a standard
 * verb (405 otherwise), the query string is percent re-encoded pair by pair
 * (cashupay_api_bridge_query), the body must be empty or valid JSON within
 * the size cap (cashupay_api_bridge_body_refusal; 400/413 otherwise), and
 * the forwarded headers pass through sanitize_text_field.
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
    // The query string is never forwarded raw: cashupay_api_bridge_query()
    // re-encodes it pair by pair (sanitize_text_field would corrupt
    // legitimate API parameters; percent re-encoding cannot) and drops any
    // attempt to smuggle a second cashupay_path into the target.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by cashupay_api_bridge_query() wrapping this read.
    $query = cashupay_api_bridge_query(wp_unslash((string) ($_SERVER['QUERY_STRING'] ?? '')));
    if ($query !== '') {
        $target .= '&' . $query;
    }

    $method = strtoupper(sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
    if (!in_array($method, CASHUPAY_BRIDGE_METHODS, true)) {
        header('Allow: ' . implode(', ', CASHUPAY_BRIDGE_METHODS));
        cashupay_api_bridge_refuse(405, 'bridge-method-not-allowed', 'The API bridge only replays standard HTTP methods.');
    }
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
        // Bounded read — one byte past the cap is enough to prove the body
        // is oversized without ever buffering more than that.
        $body = (string) file_get_contents('php://input', false, null, 0, CASHUPAY_BRIDGE_MAX_BODY_BYTES + 1);
        $refusal = cashupay_api_bridge_body_refusal($body);
        if ($refusal !== null) {
            cashupay_api_bridge_refuse($refusal[0], $refusal[1], $refusal[2]);
        }
        $args['body'] = $body;
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

    $body = (string) wp_remote_retrieve_body($response);
    $refusal = cashupay_api_bridge_response_refusal($body);
    if ($refusal !== null) {
        cashupay_api_bridge_refuse($refusal[0], $refusal[1], $refusal[2]);
    }

    status_header((int) wp_remote_retrieve_response_code($response));
    header('Content-Type: application/json');
    if ($body !== '') {
        // Escape-late, in the JSON context: the body is proven valid JSON
        // above, decoded, and re-emitted through wp_json_encode — never the
        // upstream bytes. The flags keep the re-encoding semantically
        // lossless for API payloads: slashes and Unicode stay literal, and a
        // float like 1.0 stays a float instead of collapsing to the int 1.
        echo wp_json_encode(json_decode($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
    exit;
}
