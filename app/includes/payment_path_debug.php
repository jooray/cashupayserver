<?php
/**
 * Payment-path debug labels.
 *
 * Renders a small human-readable description of which payment "rail" produced
 * each method block on the customer-facing payment page (Lightning / on-chain /
 * Cashu). Purely diagnostic — it surfaces routing decisions that are otherwise
 * invisible to an operator looking at the live payment screen.
 *
 * Visibility is double-gated by the caller: the site-wide toggle
 * (show_payment_path_debug, default OFF) AND an admin session. A non-admin payer
 * never sees these labels regardless of the toggle. This class only owns the
 * toggle read + the label strings; the admin check lives with the caller, which
 * has the session context.
 */

require_once __DIR__ . '/config.php';

class PaymentPathDebug
{
    /** Site-wide config key holding the on/off toggle (bool, default false). */
    public const CONFIG_KEY = 'show_payment_path_debug';

    /** True when the site-wide toggle is enabled. */
    public static function enabled(): bool
    {
        return Config::get(self::CONFIG_KEY, false) === true;
    }

    /**
     * Label for the Lightning method block. The bolt11 shown to the customer
     * can originate from several rails; payment_rail records which one. The
     * concrete destination (LN address, noffer string, mint URL, swap provider)
     * is appended when known.
     *
     * @param array       $invoice      The invoice row.
     * @param string|null $swapProvider Provider name for the 'swap' rail
     *                                  (from swap_attempts.provider), if any.
     */
    public static function lightningLabel(array $invoice, ?string $swapProvider = null): string
    {
        $rail = (string)($invoice['payment_rail'] ?? '');
        $dest = (string)($invoice['ln_destination'] ?? '');
        $feeRails = self::feeRails($invoice);

        switch ($rail) {
            case 'lnaddress':
                // Fee-redirect lightning rides the same 'lnaddress' rail; the
                // fee_redirect_rails CSV is the only thing that distinguishes a
                // fee-owned bolt11 from the merchant's own LN address.
                if (in_array('lightning', $feeRails, true)) {
                    return self::withDest('Fee-redirect Lightning (LNURL)', $dest);
                }
                return self::withDest('LNURL / Lightning address', $dest);

            case 'noffer':
                $relay = trim((string)($invoice['noffer_relay'] ?? ''));
                $label = self::withDest('CLINK noffer (NIP-69)', $dest);
                return $relay !== '' ? $label . ' via relay ' . $relay : $label;

            case 'nwc':
                // ln_destination is the masked connection label (never the
                // URI — it embeds the wallet secret).
                return self::withDest('Nostr Wallet Connect (NIP-47)', $dest);

            case 'strike':
                // ln_destination is the masked key label (never the API key —
                // it is the account credential).
                return self::withDest('Strike API', $dest);

            case 'swap':
                $provider = trim((string)$swapProvider);
                return $provider !== ''
                    ? 'Submarine swap via ' . $provider
                    : 'Submarine swap';

            case 'mint':
                return self::withDest('Cashu mint quote (Lightning)', (string)($invoice['mint_url'] ?? ''));

            default:
                return $rail !== '' ? 'Lightning (' . $rail . ')' : 'Lightning';
        }
    }

    /**
     * Label for the on-chain method block. Distinguishes xpub-derived (a fresh
     * address per invoice) from static single-address mode. A fee-redirect
     * on-chain rail is always xpub-derived from the fee payee's wallet, so it is
     * labelled distinctly.
     *
     * @param array       $invoice     The invoice row.
     * @param string|null $onchainMode The store's onchain_address_mode
     *                                 ('xpub' | 'static'); 'xpub' is the default.
     */
    public static function onchainLabel(array $invoice, ?string $onchainMode): string
    {
        $address = (string)($invoice['onchain_address'] ?? '');

        if (in_array('onchain', self::feeRails($invoice), true)) {
            return self::withDest('Fee-redirect on-chain (xpub-derived)', $address);
        }

        // A Strike-minted address (receive request in the merchant's Strike
        // account) overrides the store's own xpub/static source.
        if (!empty($invoice['strike_receive_request_id'])) {
            return self::withDest('On-chain (Strike receive request)', $address);
        }

        $mode = ($onchainMode === 'static') ? 'static address' : 'xpub-derived';
        return self::withDest('On-chain (' . $mode . ')', $address);
    }

    /**
     * Label for the Cashu ecash (NUT-18) method block. This block is offered
     * independently of the rail above; the request is built against the store's
     * mint without contacting it.
     */
    public static function cashuLabel(?string $mintUrl): string
    {
        return self::withDest('Cashu ecash (NUT-18)', (string)$mintUrl);
    }

    /** Parse the invoice's fee_redirect_rails CSV into a list of rail tokens. */
    private static function feeRails(array $invoice): array
    {
        $csv = trim((string)($invoice['fee_redirect_rails'] ?? ''));
        if ($csv === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($r) => $r !== ''));
    }

    /** "Label → dest" when a destination is known, else just the label. */
    private static function withDest(string $label, string $dest): string
    {
        $dest = trim($dest);
        return $dest !== '' ? $label . ' → ' . $dest : $label;
    }
}
