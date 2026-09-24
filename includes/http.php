<?php
/**
 * CashuPayServer - small HTTP helpers shared by every outgoing request.
 */

if (!function_exists('cashupay_curl_protocol_options')) {
    /**
     * cURL options limiting a handle, and any redirect it follows, to HTTPS and HTTP.
     *
     * CURLOPT_PROTOCOLS_STR exists only on PHP 8.3+ built against libcurl 7.85+. Using it
     * unconditionally made every outgoing request fail with "Undefined constant" on PHP
     * 8.1/8.2 — which this project supports — and on 8.3 with an older system libcurl. The
     * bitmask options are the fallback; they are deprecated on new builds, so they are only
     * used when the string form is missing. Same logic as Cashu\MintClient::curlProtocolOptions(),
     * duplicated so callers here need not load the wallet library. Merge with `+`, never
     * array_merge(), which renumbers the integer option keys.
     */
    function cashupay_curl_protocol_options(): array {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            return [
                CURLOPT_PROTOCOLS_STR => 'https,http',
                CURLOPT_REDIR_PROTOCOLS_STR => 'https,http',
            ];
        }
        return [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
    }
}

if (!function_exists('cashupay_is_trusted_release_link')) {
    /**
     * A link the operator can safely be sent to "to upgrade": this project's GitHub
     * releases, or a plain path on the manifest's own host.
     *
     * Matched on the raw string, never after parsing: a prefix check on the parsed path
     * accepted /jooray/cashupayserver/../../someone/fork, which a browser normalises to
     * another repository. No dot segments, no percent-encoding, no backslashes, no
     * query or fragment tricks.
     */
    function cashupay_is_trusted_release_link(string $link, ?string $ownHost = null): bool {
        if (preg_match('#^https://github\.com/jooray/cashupayserver/releases(/tag/v[0-9A-Za-z][0-9A-Za-z.\-]{0,40})?/?$#', $link)) {
            return !str_contains($link, '..');
        }
        if ($ownHost === null || $ownHost === '') {
            return false;
        }
        if (!preg_match('#^https://([a-z0-9.\-]+)(/[A-Za-z0-9_\-./]*)?$#', $link, $m) || strtolower($m[1]) !== strtolower($ownHost)) {
            return false;
        }
        $path = $m[2] ?? '/';
        return !preg_match('#(^|/)\.{1,2}(/|$)#', $path) && !str_contains($path, '//');
    }
}
