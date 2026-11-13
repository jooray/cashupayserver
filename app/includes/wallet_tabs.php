<?php
/**
 * "Popular wallet" tabs on the customer payment page.
 *
 * Cash App, Venmo and PayPal can all send bitcoin, but each hides the
 * feature somewhere different in its app. A store can opt into an extra tab
 * per wallet (stores.wallet_tabs): the tab repeats the QR code of one of the
 * native methods — Lightning when the wallet supports it and the invoice
 * has a bolt11, on-chain otherwise — under a short note on where to find
 * the send-bitcoin screen in that app. Nothing new is payable: a wallet tab
 * is a signpost onto a rail the invoice already offers.
 *
 * The setting is per store, written by the setup wizard's wallet_tabs
 * screen and the admin "Popular wallet tabs" card, and stored as a
 * comma-separated list of the keys below in canonical order. NULL (rows
 * that predate the setting) and '' both mean "no wallet tabs".
 */

final class WalletTabs {
    public const CASHAPP = 'cashapp';
    public const VENMO = 'venmo';
    public const PAYPAL = 'paypal';

    /** Every wallet with a tab, in the order the tabs are listed. */
    public const ALL = [self::CASHAPP, self::VENMO, self::PAYPAL];

    /**
     * Per-wallet presentation and the native methods it can pay. `sources`
     * is in preference order: the first one the invoice offers is the QR
     * the tab shows. `file` names the logo under images/payment-methods/
     * (the same artwork as the "Pay with" brand row); `class` is the extra
     * pm-logo class that row applies to it; `button` labels the tab's
     * open-in-wallet button (Cash App has a universal link that opens the
     * app on a Lightning invoice — Urls::cashAppLightningUri — so its button
     * names the app; the card apps have no such hand-off and keep the
     * generic label, which opens whatever wallet the phone has).
     */
    public const BRANDS = [
        self::CASHAPP => [
            'label' => 'Cash App',
            'file' => 'cashapp.svg',
            'class' => '',
            'sources' => ['lightning', 'onchain'],
            'button' => 'Pay with Cash App',
            'note' => 'Go to the QR code icon in Cash App or tap "Pay with Cash App".',
        ],
        self::VENMO => [
            'label' => 'Venmo',
            'file' => 'venmo.svg',
            'class' => 'pm-card',
            'sources' => ['onchain'],
            'button' => 'Pay with BTC wallet',
            'note' => 'In Venmo, go to Crypto → Bitcoin → Transfer (arrow icons) → Send. '
                . 'Note that Venmo charges additional fees for transfer and may have a minimum transfer amount.',
        ],
        self::PAYPAL => [
            'label' => 'PayPal',
            'file' => 'paypal.svg',
            'class' => 'pm-card',
            'sources' => ['onchain'],
            'button' => 'Pay with BTC wallet',
            'note' => 'In PayPal app, go to Finances → Crypto → Transfer arrows.',
        ],
    ];

    public static function isKnown(string $key): bool {
        return in_array($key, self::ALL, true);
    }

    /**
     * The enabled keys encoded in a stores.wallet_tabs value, validated and
     * in canonical order. Unknown tokens, blanks and duplicates are dropped
     * so a hand-edited row can never render a tab that has no brand.
     *
     * @return string[]
     */
    public static function parse(?string $stored): array {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        $wanted = [];
        foreach (explode(',', $stored) as $token) {
            $wanted[strtolower(trim($token))] = true;
        }
        return array_values(array_filter(self::ALL, fn($k) => isset($wanted[$k])));
    }

    /**
     * The stores.wallet_tabs value for a set of keys: canonical order, no
     * unknowns, '' when nothing is enabled (an explicit "none" — distinct
     * from NULL, which the wizard reads as "not answered yet").
     *
     * @param string[] $keys
     */
    public static function serialize(array $keys): string {
        $wanted = array_fill_keys(array_map(fn($k) => strtolower(trim((string)$k)), $keys), true);
        return implode(',', array_values(array_filter(self::ALL, fn($k) => isset($wanted[$k]))));
    }

    /**
     * The wallets a store has switched on.
     *
     * @return string[]
     */
    public static function enabled(array $store): array {
        return self::parse(isset($store['wallet_tabs']) ? (string)$store['wallet_tabs'] : null);
    }

    /** Has the store ever answered the question (wizard or admin card)? */
    public static function answered(array $store): bool {
        return array_key_exists('wallet_tabs', $store) && $store['wallet_tabs'] !== null;
    }

    /**
     * Which native method a wallet tab would show for an invoice offering
     * the given rails, or null when none of the wallet's sources is offered
     * (the tab is then not rendered — a Venmo tab with no on-chain address
     * would have nothing to scan).
     */
    public static function sourceFor(string $key, bool $hasOnchain, bool $hasLightning): ?string {
        if (!self::isKnown($key)) {
            return null;
        }
        $offered = ['lightning' => $hasLightning, 'onchain' => $hasOnchain];
        foreach (self::BRANDS[$key]['sources'] as $source) {
            if (!empty($offered[$source])) {
                return $source;
            }
        }
        return null;
    }

    /**
     * The wallet tabs to render for ONE invoice: the store's enabled wallets
     * that have a payable source, as key => source, in canonical order.
     *
     * @return array<string, string>
     */
    public static function offered(array $store, bool $hasOnchain, bool $hasLightning): array {
        $out = [];
        foreach (self::enabled($store) as $key) {
            $source = self::sourceFor($key, $hasOnchain, $hasLightning);
            if ($source !== null) {
                $out[$key] = $source;
            }
        }
        return $out;
    }
}
