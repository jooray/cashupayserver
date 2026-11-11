<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

// Insufficient balance
$e = new Exception('Insufficient balance. Have: 100 sat, Need: 500 sat');
assert_eq(MintReliability::KIND_INSUFFICIENT_BALANCE,
    MintReliability::classifyException($e, 'melt'),
    'insufficient balance is recognized regardless of stage');

// Lightning wallet errors raised by meltToAddress
$e = new Exception('Lightning payment pending - proofs marked as pending for recovery');
assert_eq(MintReliability::KIND_LIGHTNING_WALLET_ERROR,
    MintReliability::classifyException($e, 'melt'),
    'pending message → wallet error');

$e = new Exception('Lightning payment failed');
assert_eq(MintReliability::KIND_LIGHTNING_WALLET_ERROR,
    MintReliability::classifyException($e, 'melt'),
    'failed message → wallet error');

// getInvoice stage = LNURL resolution → wallet error regardless of message
$e = new Exception('some opaque parser error');
assert_eq(MintReliability::KIND_LIGHTNING_WALLET_ERROR,
    MintReliability::classifyException($e, 'getInvoice'),
    'getInvoice failures are wallet-side');

// Network-style messages → MINT_UNREACHABLE
$e = new Exception('Connection refused');
assert_eq(MintReliability::KIND_MINT_UNREACHABLE,
    MintReliability::classifyException($e, 'requestMeltQuote'),
    'connection refused → unreachable');

$e = new Exception('Could not resolve host: mint.example.com');
assert_eq(MintReliability::KIND_MINT_UNREACHABLE,
    MintReliability::classifyException($e, 'requestMintQuote'),
    'DNS failure → unreachable');

// Mint-stage exception with no network keyword → protocol error
$e = new Exception('Quote 12345 not found');
assert_eq(MintReliability::KIND_MINT_PROTOCOL_ERROR,
    MintReliability::classifyException($e, 'requestMeltQuote'),
    'mint stage non-network → protocol');

// Out-of-stage unknown
$e = new Exception('mystery');
assert_eq(MintReliability::KIND_UNKNOWN,
    MintReliability::classifyException($e, 'something_else'),
    'unrecognized → UNKNOWN');

echo "classification: ok\n";
