<?php
/**
 * CashuPayServer — Fee-Redirect Module
 *
 * Decides, at invoice-creation time, whether an invoice's payment should be
 * pointed straight at a fee destination (dev / hosting) instead of the
 * merchant, and builds the rail destinations when it should.
 *
 * Why: fees are normally accrued and later melted out of the merchant's cashu
 * wallet by the cron (see DevFee::settleStore). That path is kept. This module
 * adds an opportunistic shortcut — when a fee is already owed in an amount at
 * least as large as the incoming invoice, the whole invoice payment is routed
 * to that fee's payee. The merchant gives up this one payment but their fee
 * debt drops by the same amount, so it nets out (the existing cron path would
 * otherwise have melted the equivalent from their wallet).
 *
 * Rules (locked in with the operator):
 *   - GATE (no overpay): a fee is only a candidate when its owed amount is
 *     >= the invoice amount in sats. The customer pays exactly one rail in
 *     full, so a fee can only claim a rail if it is owed at least that much.
 *   - PRECEDENCE: a single fee (the LARGEST owed that can cover at least one
 *     offered rail) claims every rail it natively covers on this invoice.
 *   - PER-RAIL (no all-rails requirement): the chosen fee routes the rails it
 *     covers (a LUD-21 Lightning address for the lightning rail, a
 *     network-matched xpub for the on-chain rail) to the fee payee; any
 *     offered rail it cannot cover falls through to the MERCHANT on the same
 *     invoice. So an invoice can be mixed — e.g. lightning -> dev fee,
 *     on-chain -> merchant. Whichever rail the customer actually pays decides
 *     whether this settlement is a fee payment or a merchant payment
 *     (see Invoice::railIsFeeRouted). We never disable a payment type.
 *   - Dev and hosting both participate. Dev destinations are global config;
 *     hosting's are per-store. A fee with no destination for a given rail
 *     simply can't cover that rail (that rail goes to the merchant).
 *   - We never split rails across DIFFERENT fee payees on one invoice; a
 *     single fee owns whichever rails it covers, the merchant owns the rest.
 *
 * The lightning rail reuses {@see LnUrlReceive::probeAndFetchInvoice}, which
 * requires a LUD-21 `verify` URL — without it we couldn't detect settlement
 * (we don't run an LN node), exactly as the existing lnaddress receive rail.
 *
 * PRIVACY (locked in with the operator): a fee payee learns nothing about the
 * merchant's sale. The fee bolt11 is requested with the amount ONLY — no
 * LUD-12 comment, no LUD-18 payer data, no store name, order id, item
 * description, memo or invoice id. The merchant rails may label their own
 * invoices (Strike / noffer / NWC memos); the fee rail never does. Guarded by
 * tests/php/test_fee_redirect_invoice_privacy.php — keep that test green when
 * touching probeAndFetchInvoice or this builder.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dev_fee.php';
require_once __DIR__ . '/fee_branch.php';
require_once __DIR__ . '/lnurl_receive.php';
require_once __DIR__ . '/onchain/payments.php';

class FeeRedirect {
    /**
     * Build the ordered list of redirect-fee candidates for a store, each with
     * its owed amount and configured destinations. Pure assembly from config +
     * the supplied owed math; no network calls. Exposed for unit testing.
     *
     * @param array $owed Result of DevFee::computeOwed (or an equivalent stub).
     * @return array<int, array{key:string, note:string, owed:int, lnurl:string,
     *                          xpub:string, network:string, type:string, branch:?int}>
     */
    public static function candidates(array $store, array $owed): array {
        $list = [
            [
                'key'     => 'dev',
                'note'    => FEE_NOTE_DEV,
                'owed'    => (int)($owed['dev_owed'] ?? 0),
                'lnurl'   => (string)CASHUPAY_DEV_FEE_LNURL,
                'xpub'    => (string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB,
                'network' => (string)CASHUPAY_DEV_FEE_ONCHAIN_NETWORK,
                'type'    => (string)CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE,
                // Per-install branch under the shared BareBits zpub (null until
                // allocated — buildRails tries to obtain one inline, and leaves
                // the on-chain rail to the merchant when it can't).
                'branch'  => FeeBranch::current(),
            ],
            [
                'key'     => 'hosting',
                'note'    => FEE_NOTE_HOSTING,
                'owed'    => (int)($owed['hosting_owed'] ?? 0),
                'lnurl'   => (string)($store['hosting_fee_destination'] ?? ''),
                'xpub'    => (string)($store['hosting_fee_onchain_xpub'] ?? ''),
                'network' => (string)($store['hosting_fee_onchain_network'] ?? 'mainnet'),
                'type'    => (string)($store['hosting_fee_onchain_address_type'] ?? 'P2WPKH'),
                // The hosting xpub belongs to the deployer, not BareBits: the
                // BareBits branch sequence has no meaning under it.
                'branch'  => null,
            ],
        ];

        // Largest owed first (single payment -> single destination).
        usort($list, static fn($a, $b) => $b['owed'] <=> $a['owed']);
        return $list;
    }

    /**
     * Decide how this invoice's rails should be routed. Returns null to leave
     * the invoice fully on the normal (merchant) path. Otherwise returns the
     * single chosen fee plus the subset of rails it claims; the caller routes
     * those rails to the fee payee and builds the remaining offered rails for
     * the merchant on the same invoice.
     *
     * @param string   $storeId
     * @param array    $store        Full stores row (Config::getStore).
     * @param int      $invoiceSats  Invoice amount in sats.
     * @param string[] $offeredRails Subset of ['lightning','onchain'] the store
     *                               can present.
     * @return array{
     *   note:string, fee_key:string, rails:string[], destination:string,
     *   lightning:?array{bolt11:string,verify_url:string,destination:string,amount_sats:int},
     *   onchain:?array{address:string,index:int,branch:?int,tip_height:?int,destination:string}
     * }|null
     */
    public static function decide(string $storeId, array $store, int $invoiceSats, array $offeredRails): ?array {
        if ($invoiceSats < 1 || empty($offeredRails)) {
            return null;
        }

        $owed = DevFee::computeOwed($storeId);
        $storeNetwork = (string)($store['onchain_network'] ?? 'mainnet');
        // Free trial / no revenue => every owed is 0 => nothing qualifies.
        foreach (self::candidates($store, $owed) as $cand) {
            // GATE: a fee can only claim a rail when its owed amount covers the
            // whole invoice (the customer pays one rail in full -> no overpay).
            if ($cand['owed'] < $invoiceSats) {
                continue;
            }
            // Which offered rails can this fee cover by config? Pure, no I/O.
            // Largest-owed-first: the first fee that covers >=1 rail wins, and
            // it claims only the rails it covers (the rest go to the merchant).
            $covered = self::coveredRails($cand, $offeredRails, $storeNetwork);
            if (empty($covered)) {
                continue;
            }
            // Build destinations for the covered rails. A rail that fails to
            // build at runtime (LNURL host down, derivation error) is dropped
            // and falls back to the merchant rather than aborting the redirect.
            $built = self::buildRails($cand, $store, $invoiceSats, $covered);
            if ($built === null) {
                // Every covered rail failed to build — try the next fee.
                continue;
            }
            error_log(sprintf(
                '[fee-redirect] store=%s fee=%s owed=%d invoice_sats=%d offered=%s fee_rails=%s',
                $storeId, $cand['key'], $cand['owed'], $invoiceSats,
                implode('+', $offeredRails), implode('+', $built['rails'])
            ));
            return [
                'note'        => $cand['note'],
                'fee_key'     => $cand['key'],
                'rails'       => $built['rails'],
                'destination' => $built['destination'],
                'lightning'   => $built['lightning'],
                'onchain'     => $built['onchain'],
            ];
        }
        return null;
    }

    /**
     * Pure: which of the offered rails can this candidate cover by config?
     * Lightning needs a non-empty LNURL; on-chain needs a non-empty xpub on the
     * SAME network as the paying store (the invoice is polled with the store's
     * provider, so a different-network xpub would be unwatchable). No I/O — the
     * runtime confirmation (LNURL reachable, derivation works) happens in
     * buildRails. Exposed for unit testing.
     *
     * @param string[] $offeredRails
     * @return string[] subset of $offeredRails this fee can route to itself
     */
    public static function coveredRails(array $cand, array $offeredRails, string $storeNetwork): array {
        $covered = [];
        if (in_array('lightning', $offeredRails, true) && ($cand['lnurl'] ?? '') !== '') {
            $covered[] = 'lightning';
        }
        if (in_array('onchain', $offeredRails, true)
            && ($cand['xpub'] ?? '') !== ''
            && ($cand['network'] ?? 'mainnet') === $storeNetwork) {
            $covered[] = 'onchain';
        }
        return $covered;
    }

    /**
     * Pure coverage check: can this candidate cover EVERY offered rail? Kept as
     * a convenience predicate (decide() no longer requires it — see
     * coveredRails for the per-rail subset). Exposed for unit testing.
     *
     * @param string[] $offeredRails
     */
    public static function staticallyCovers(array $cand, array $offeredRails, string $storeNetwork): bool {
        return count(self::coveredRails($cand, $offeredRails, $storeNetwork)) === count($offeredRails);
    }

    /**
     * Build fee destinations for the given rails using one fee candidate. Each
     * rail is best-effort: a rail that can't be built (LNURL host down,
     * derivation error) is simply omitted so the caller leaves it to the
     * merchant. Returns null only when NO rail could be built.
     *
     * @param string[] $railsToBuild already known to be config-covered (see coveredRails)
     * @return array{rails:string[], destination:string, lightning:?array, onchain:?array}|null
     */
    private static function buildRails(array $cand, array $store, int $invoiceSats, array $railsToBuild): ?array {
        $wantLightning = in_array('lightning', $railsToBuild, true);
        $wantOnchain   = in_array('onchain', $railsToBuild, true);

        $lightning = null;
        $onchain = null;
        $rails = [];
        $labels = [];

        if ($wantLightning && ($cand['lnurl'] ?? '') !== '') {
            // Live LUD-21 probe + bolt11 fetch for the exact amount. Null on
            // host down / no verify URL / amount out of LNURL range.
            $probe = LnUrlReceive::probeAndFetchInvoice($cand['lnurl'], $invoiceSats);
            if ($probe !== null) {
                $lightning = [
                    'bolt11'      => $probe['bolt11'],
                    'verify_url'  => $probe['verify_url'],
                    'destination' => $cand['lnurl'],
                    'amount_sats' => $invoiceSats,
                ];
                $rails[] = 'lightning';
                $labels[] = $cand['lnurl'];
            } else {
                error_log('[fee-redirect] lightning rail unavailable for '
                    . ($cand['key'] ?? '?') . ' (' . $cand['lnurl'] . '); rail falls back to merchant');
            }
        }

        if ($wantOnchain && ($cand['xpub'] ?? '') !== ''
            && $cand['network'] === (string)($store['onchain_network'] ?? 'mainnet')) {
            try {
                $branch = $cand['branch'] ?? null;
                if ($branch === null && ($cand['key'] ?? '') === 'dev') {
                    // No branch stored yet: one throttled inline attempt so an
                    // install whose cron never fired still gets its branch
                    // the first time it matters.
                    $branch = FeeBranch::ensure(['throttle' => true]);
                }
                if ($branch === null && ($cand['key'] ?? '') === 'dev') {
                    // Still none: the dev fee does NOT take the on-chain rail
                    // on this invoice. Deriving on the shared <zpub>/0/<index>
                    // chain would hand two installs the same address, which is
                    // exactly the ambiguity the branch exists to prevent — so
                    // the rail stays with the merchant and the fee keeps
                    // accruing for the cron settlement / a later redirect.
                    throw new RuntimeException('no dev-fee branch allocated yet (see cron task fee_branch)');
                }
                $alloc = OnchainPayments::allocateFeeAddress(
                    $cand['xpub'], $cand['network'], $cand['type'], $store, $branch
                );
                $onchain = [
                    'address'     => $alloc['address'],
                    'index'       => $alloc['index'],
                    'branch'      => $alloc['branch'] ?? null,
                    'tip_height'  => $alloc['tip_height'] ?? null,
                    'destination' => $alloc['address'],
                ];
                $rails[] = 'onchain';
                $labels[] = $alloc['address'];
            } catch (Throwable $e) {
                error_log('[fee-redirect] onchain rail derivation failed for '
                    . ($cand['key'] ?? '?') . ': ' . $e->getMessage()
                    . '; rail falls back to merchant');
            }
        }

        if (empty($rails)) {
            return null;
        }
        return [
            'rails'       => $rails,
            'destination' => implode(' / ', $labels),
            'lightning'   => $lightning,
            'onchain'     => $onchain,
        ];
    }
}
