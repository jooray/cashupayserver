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
