<?php
/**
 * CashuPayServer - Submarine-swap provider abstraction.
 *
 * A swap provider routes a customer's Lightning payment to an on-chain output
 * controlled by us (the merchant) via a Taproot HTLC. The same interface is
 * designed to accommodate the reverse direction (on-chain → LN) in the future,
 * though only LN → on-chain is implemented in v1.
 *
 * Both Zeus and Boltz expose the same v2 API surface (Zeus is built on a
 * Boltz backend), so the shared protocol lives in BoltzLikeProvider; the
 * subclasses just configure base URLs.
 */

require_once __DIR__ . '/../database.php';

/**
 * Pair info returned by a provider's /v2/swap/reverse GET. Captures the
 * fee/limit shape we need to validate an invoice request before creating a
 * swap.
 */
final class SwapPairInfo {
    public function __construct(
        public readonly float $feePercent,        // e.g. 0.5 for 0.5%
        public readonly int   $lockupFeeSats,     // Boltz's miner fee on its lockup tx (deducted from receive)
        public readonly int   $claimFeeEstimateSats, // miner fee estimate for the claim tx (we pay this)
        public readonly int   $minSats,
        public readonly int   $maxSats,
        public readonly ?string $pairHash = null, // optional opaque "config version" hash
    ) {}
}

/**
 * Result of POST /v2/swap/reverse. Contains everything we need to persist
 * the swap_attempts row and verify Boltz's response was internally consistent.
 */
final class SwapCreateResult {
    public function __construct(
        public readonly string $swapId,
        public readonly string $invoice,            // BOLT11 the customer will pay
        public readonly int    $invoiceAmountSats,  // what the LN invoice charges
        public readonly int    $onchainAmountSats,  // what we'll actually receive (after fees)
        public readonly string $lockupAddress,
        public readonly string $refundPublicKeyHex, // 33-byte compressed
        public readonly int    $timeoutBlockHeight,
        public readonly string $claimLeafScript,    // raw script bytes (parsed from response)
        public readonly string $refundLeafScript,   // raw script bytes (parsed from response)
        public readonly array  $rawResponse = [],   // full decoded provider response; archived to swap_attempts.provider_response_json for forensic / recovery purposes
    ) {}
}

/**
 * Status snapshot of an in-flight swap, as returned by GET /v2/swap/{id}.
 *
 * The provider's status string is mirrored verbatim into our swap_attempts
 * row; downstream code interprets it via SwapPoller's state-transition table.
 */
final class SwapStatus {
    public function __construct(
        public readonly string $status,         // e.g. "transaction.mempool", "invoice.settled"
        public readonly ?string $lockupTxHex = null,
        public readonly ?string $preimage = null, // 32-byte hex preimage if revealed
        public readonly array $raw = [],         // full decoded response
    ) {}
}

interface SwapProvider {
    /**
     * Stable short name used in DB rows + config (e.g. "zeus", "boltz").
     */
    public function getName(): string;

    /**
     * Cheap reachability ping. Used by the factory to skip dead providers
     * at invoice-creation time. Should return quickly (<= 5s).
     */
    public function isReachable(string $network): bool;

    /**
     * Fetch the current reverse-pair info (fees, limits). May throw.
     */
    public function getReversePairInfo(string $network): SwapPairInfo;

    /**
     * Create a reverse swap.
     *
     * @param int $onchainAmountSats target amount the merchant will receive
     * @param string $claimPublicKeyHex 33-byte compressed pubkey (hex)
     * @param string $preimageHashHex 32-byte sha256(preimage) (hex)
     */
    public function createReverseSwap(
        string $network,
        int $onchainAmountSats,
        string $claimPublicKeyHex,
        string $preimageHashHex
    ): SwapCreateResult;

    /**
     * Fetch latest status. Returns null if the swap is unknown (HTTP 404).
     */
    public function getSwapStatus(string $network, string $swapId): ?SwapStatus;

    /**
     * Broadcast a signed raw transaction via the provider's /v2/chain/BTC/transaction.
     * Returns the txid. Caller may fall back to its own broadcast path on failure.
     */
    public function broadcastTx(string $network, string $rawTxHex): string;

    /**
     * Request the provider to cancel the held HTLC for a swap that we (the
     * client) have abandoned locally. Best-effort — implementations may no-op
     * if the provider does not support unilateral cancellation. Errors logged
     * by callers, never thrown to halt the cron.
     */
    public function cancelInvoice(string $network, string $swapId): void;
}
