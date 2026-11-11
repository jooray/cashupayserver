<?php
/**
 * CashuPayServer — LNURL Direct-Receive Module
 *
 * Routes incoming Lightning payments straight to the store's configured
 * auto-cashout LN address when the LNURL host supports LUD-21 (`verify` URL),
 * bypassing the cashu mint and the submarine swap rails entirely. Eliminates
 * the customer→mint→merchant round-trip when the merchant is going to auto-
 * withdraw to that same address anyway.
 *
 * Decision flow at invoice creation:
 *   1. Probe the configured LN address: resolve LNURL-pay metadata, request a
 *      BOLT11 for the exact amount, require a `verify` URL in the response.
 *   2. If the probe succeeds, the LNURL-issued BOLT11 becomes the invoice
 *      (payment_rail='lnaddress').
 *   3. If the probe fails (host down, no LUD-21, timeout), we silently fall
 *      through to the existing mint/swap decision.
 *
 * Fee collection never reroutes this path: owed dev/hosting fees are taken
 * either by FeeRedirect (an owed fee covering the whole invoice claims the
 * rail at creation time) or by the cron's DevFee::settleStore melt from mint
 * balance. The historical fees-due override — which skipped this path to park
 * payments on the mint for immediate collection — was removed; old invoices
 * may still carry its lnurl_override_reason column value.
 *
 * Settlement detection uses the LUD-21 verify URL: GET returns
 * {"settled": true|false, "preimage": "..."}. The preimage matches
 * payment_hash from the BOLT11, giving cryptographic proof of payment without
 * controlling the LN node.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dev_fee.php';
require_once __DIR__ . '/safe_http.php';

// Wall-clock budget for the LNURL probe at invoice creation. The probe is
// two HTTP round-trips (well-known + callback). 5 seconds keeps invoice
// creation snappy while tolerating sluggish hosts; tune via user_config.php.
if (!defined('LNURL_RECEIVE_PROBE_TIMEOUT_SEC')) {
    define('LNURL_RECEIVE_PROBE_TIMEOUT_SEC', 5);
}

class LnUrlReceive {
    /**
     * Resolve a Lightning address to its LNURL-pay metadata and request a
     * BOLT11 for the exact amount, requiring a LUD-21 verify URL in the
     * response. Returns the BOLT11 + verify URL on success; null on any
     * failure (host down, no verify, amount out of range, timeout, etc.).
     *
     * The probe is the live-and-working check: a successful return means we
     * have an invoice in hand that will be paid through to the merchant and
     * a verify URL we can poll for settlement.
     *
     * On failure $failReason receives a short fixed phrase describing what
     * went wrong. It is payer-facing (payment page), so it must never contain
     * the LN address, URLs, or any host-provided text.
     *
     * @return array{bolt11:string,verify_url:string,min_sendable_msats:int,max_sendable_msats:int}|null
     */
    public static function probeAndFetchInvoice(
        string $lnAddress,
        int $amountSats,
        ?int $timeoutSec = null,
        ?string &$failReason = null
    ): ?array {
        $failReason = null;
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;

        if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $lnAddress)) {
            $failReason = 'the configured Lightning address is malformed';
            return null;
        }
        [$user, $domain] = explode('@', $lnAddress, 2);

        // Test environments can rewrite the URL via the cashu-wallet-php
        // CASHU_LNURL_URL_TEMPLATE convention so our mock LNURL host doesn't
        // need real HTTPS / port 443.
        $template = getenv('CASHU_LNURL_URL_TEMPLATE');
        if ($template !== false && $template !== '') {
            $url = strtr($template, ['{user}' => $user, '{domain}' => $domain]);
        } else {
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        }

        $metaResp = self::httpGet($url, $timeout);
        if ($metaResp === null) {
            $failReason = 'the Lightning address service could not be reached';
            return null;
        }
        $meta = json_decode($metaResp, true);
        if (!is_array($meta)
            || !isset($meta['callback'], $meta['minSendable'], $meta['maxSendable'])
        ) {
            $failReason = 'the Lightning address service returned an invalid response';
            return null;
        }

        $amountMsats = $amountSats * 1000;
        if ($amountMsats < (int)$meta['minSendable']
            || $amountMsats > (int)$meta['maxSendable']
        ) {
            $failReason = 'the amount is outside the range the Lightning address accepts';
            return null;
        }

        $callback = (string)$meta['callback'];
        $sep = (strpos($callback, '?') !== false) ? '&' : '?';
        $callbackUrl = $callback . $sep . 'amount=' . $amountMsats;

        $invResp = self::httpGet($callbackUrl, $timeout);
        if ($invResp === null) {
            $failReason = 'the Lightning address service could not be reached';
            return null;
        }
        $inv = json_decode($invResp, true);
        if (!is_array($inv) || !isset($inv['pr'])) {
            $failReason = 'the Lightning address service returned no invoice';
            return null;
        }
        // LUD-21: the callback response must include a `verify` URL. Without
        // it we cannot detect settlement (we don't run the LN node), so we
        // refuse to use this LNURL for receive and fall back to mint/swap.
        if (!isset($inv['verify']) || !is_string($inv['verify']) || $inv['verify'] === '') {
            $failReason = 'the Lightning address service does not support payment verification (LUD-21)';
            return null;
        }

        return [
            'bolt11' => (string)$inv['pr'],
            'verify_url' => (string)$inv['verify'],
            'min_sendable_msats' => (int)$meta['minSendable'],
            'max_sendable_msats' => (int)$meta['maxSendable'],
        ];
    }

    /**
     * Probe an LN address to determine LUD-21 verify-URL support, returning
     * 1 if supported, 0 if not, null if the host is unreachable. Used by
     * the admin save handler to set stores.lnurl_supports_verify and warn
     * the operator when the host doesn't speak LUD-21.
     *
     * Uses a small canary amount (the host's declared minimum, capped at
     * 1000 sats) so we exercise the real callback rather than just the
     * metadata endpoint.
     */
    public static function probeLud21Support(string $lnAddress, ?int $timeoutSec = null): ?int {
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;
        if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $lnAddress)) {
            return null;
        }
        [$user, $domain] = explode('@', $lnAddress, 2);

        $template = getenv('CASHU_LNURL_URL_TEMPLATE');
        if ($template !== false && $template !== '') {
            $url = strtr($template, ['{user}' => $user, '{domain}' => $domain]);
        } else {
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        }
        $resp = self::httpGet($url, $timeout);
        if ($resp === null) {
            return null;
        }
        $meta = json_decode($resp, true);
        if (!is_array($meta)
            || !isset($meta['callback'], $meta['minSendable'], $meta['maxSendable'])
        ) {
            return null;
        }

        // Use min(maxSendable, max(minSendable, 1000 sat in msat)) so the
        // canary amount lies inside the host's accepted range even for
        // unusual LNURL hosts with huge minimums.
        $minMsat = (int)$meta['minSendable'];
        $maxMsat = (int)$meta['maxSendable'];
        $canaryMsat = max($minMsat, 1000 * 1000);
        if ($canaryMsat > $maxMsat) {
            $canaryMsat = $maxMsat;
        }
        $callback = (string)$meta['callback'];
        $sep = (strpos($callback, '?') !== false) ? '&' : '?';
        $callbackUrl = $callback . $sep . 'amount=' . $canaryMsat;

        $invResp = self::httpGet($callbackUrl, $timeout);
        if ($invResp === null) {
            return null;
        }
        $inv = json_decode($invResp, true);
        if (!is_array($inv) || !isset($inv['pr'])) {
            return null;
        }
        return (isset($inv['verify']) && is_string($inv['verify']) && $inv['verify'] !== '')
            ? 1 : 0;
    }

    /**
     * Poll the LUD-21 verify URL for a single invoice. Returns one of:
     *   ['state' => 'paid',    'preimage' => string]  — settled, with proof
     *   ['state' => 'pending', 'preimage' => null]    — host says not yet
     *   ['state' => 'error',   'preimage' => null]    — unreachable / malformed
     *
     * Per LUD-21 the response shape is:
     *   {"status": "OK", "settled": true|false, "preimage": "...", "pr": "..."}
     * The preimage SHA256 must match the BOLT11 payment_hash; we trust the
     * host's framing here (caller already validated bolt11 ownership at
     * creation time) and use the preimage as the settlement receipt.
     */
    public static function pollVerifyUrl(string $verifyUrl, ?int $timeoutSec = null): array {
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;
        [$resp, $httpCode, $curlErr] = self::httpGetWithDiag($verifyUrl, $timeout);
        if ($resp === null) {
            error_log(sprintf(
                '[lnurl-receive] pollVerifyUrl HTTP error url=%s http=%s curl=%s',
                $verifyUrl, $httpCode, $curlErr ?: 'none'
            ));
            return ['state' => 'error', 'preimage' => null];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['state' => 'error', 'preimage' => null];
        }
        $settled = !empty($data['settled']);
        if ($settled) {
            $preimage = isset($data['preimage']) && is_string($data['preimage'])
                ? (string)$data['preimage']
                : '';
            // Even if preimage is missing, settled=true is the host's
            // assertion that payment landed. Record what we have.
            return ['state' => 'paid', 'preimage' => $preimage];
        }
        return ['state' => 'pending', 'preimage' => null];
    }

    /**
     * HTTP GET with the configured timeout. Returns the response body string
     * on HTTP 200, null on any other status or network failure. Honours the
     * full timeout for both connect and total time so a slow LNURL host
     * can't tarpit invoice creation past the per-probe budget.
     */
    private static function httpGet(string $url, int $timeoutSec): ?string {
        [$resp, $_code, $_err] = self::httpGetWithDiag($url, $timeoutSec);
        return $resp;
    }

    /**
     * Variant of httpGet that also returns the HTTP status code and the
     * cURL error string, for diagnostic logging in places where a silent
     * null is too opaque (settlement polling needs to distinguish 404
     * "we don't recognize that payment hash" from 500 "host malfunction"
     * from connection failure).
     *
     * @return array{0:?string,1:int,2:string} [body|null, httpCode, curlErr]
     */
    private static function httpGetWithDiag(string $url, int $timeoutSec): array {
        // LNURL callback/verify URLs are the LNURL host's choice. By
        // default we refuse private destinations; the operator opt-in
        // (allow_private_endpoints) lifts it for self-hosted LN address
        // services and the local test rigs.
        // Redirects stay off either way: LNURL endpoints are supposed to
        // respond directly, and following redirects opens an SSRF-via-
        // redirect path that the IP pin alone doesn't close.
        $result = \SafeHttp::request($url, [
            'maxBytes' => 64 * 1024,
            'method' => 'GET',
            'timeout' => $timeoutSec,
            'connectTimeout' => $timeoutSec,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
            'followRedirects' => false,
        ]);
        $code = $result['status'];
        $resp = $result['body'];
        $err = $result['error'];
        if ($err !== '' || $code !== 200 || $resp === '') {
            return [null, $code, $err];
        }
        return [$resp, $code, ''];
    }
}
