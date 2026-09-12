# On-chain Bitcoin payments

CashuPayServer can accept direct on-chain Bitcoin transactions in addition to
Lightning. This page covers what's supported, how to configure it, and the
trade-offs.

## How it works

1. You provide a per-store **extended public key (xpub)** from a wallet you
   control. CashuPayServer never sees your private keys; it only derives
   *receive* addresses.
2. When an invoice is created, the server derives the next address from the
   xpub at path `m/0/N` (N increments). Each invoice gets a fresh address.
3. The Greenfield API response includes a `BTC-OnChain` payment method with
   the address, a `bitcoin:` BIP21 URI, and the amount in sats.
4. A background poller queries the configured **Esplora-compatible blockchain
   API** (mempool.space by default) for transactions paying that address.
5. The invoice transitions through the same lifecycle as Lightning invoices:
   `New` → `Processing` (first mempool sighting) → `Settled` (enough
   confirmations) → optionally `Invalid` (confirmation window expires).

Funds go **straight to your wallet**. CashuPayServer is read-only with
respect to your on-chain funds — it can derive new addresses but cannot move
existing coins.

## Supported address types

- **P2WPKH** (native segwit, `bc1q…`) — recommended; lowest fees, widest
  modern wallet support.
- **P2SH-P2WPKH** (wrapped segwit, `3…`) — for compatibility with wallets
  that still produce legacy-style addresses.

Taproot (P2TR) is **not supported in v1**. The PHP ecosystem doesn't have a
maintained, audited taproot implementation today, and BIP86 never standardized
a SLIP-32 xpub prefix for it. Re-evaluation when either changes.

## Supported xpub formats (SLIP-32)

| Prefix | Network | Implied address type |
|--------|---------|----------------------|
| `xpub` | mainnet | legacy / unspecified |
| `ypub` | mainnet | P2SH-P2WPKH (BIP49) |
| `zpub` | mainnet | P2WPKH (BIP84) |
| `tpub` | testnet / signet / regtest | legacy / unspecified |
| `upub` | testnet / signet / regtest | P2SH-P2WPKH |
| `vpub` | testnet / signet / regtest | P2WPKH |

All six formats are accepted. The actual address type used is the one you
*select* in the store config — if it mismatches the prefix (e.g. a `zpub`
configured as P2SH-P2WPKH), CashuPayServer will warn but still derive
addresses based on your selection. Most hardware wallets export `xpub`
regardless of derivation path, so this flexibility is usually what you want.

When you paste an xpub, both the setup wizard and the admin page show the
first three derived addresses for sanity-checking against your wallet's
receive screen. **Always verify these match before saving** — a mismatch
means funds will go to addresses you can't spend.

## Configuring

### From the install wizard

After configuring the Cashu mint, the wizard shows an optional **on-chain
Bitcoin** step. Paste your xpub, choose the network and address type, click
**Validate & preview first 3 addresses**, and verify they match your wallet.
You can also skip this step and configure on-chain later from the admin
panel.

### From the admin panel

In each store's settings panel, the **On-chain Bitcoin payments** card lets
you enable, modify, or disable on-chain support. The same validate/preview
flow applies. The **Test current next address** button shows what the next
allocated address will be (without consuming it), so you can confirm that
your signing wallet displays the same address at the same derivation index.

> Replacing the xpub resets the derivation counter to zero, so the new
> wallet's address derivation starts where its receive screen starts.

## On-chain payments via Strike

Stores that take Lightning payments through the **Strike API** can also have
their on-chain payments land in the same Strike account. Tick **"Also accept
on-chain payments via Strike"** — the checkbox lives in the Strike API
section of the Lightning payments settings (and the setup wizard's Strike
step), and is mirrored in the On-chain Bitcoin payments card.

How it works:

1. At checkout, the invoice's Bitcoin address is minted **in your Strike
   account** via a Strike *receive request* (a fresh address per invoice,
   never reused). The receive-request id is stored on the invoice and shown
   in the admin invoice list so you can match the payment in Strike's
   dashboard.
2. Settlement stays with the normal chain watcher: the configured Esplora /
   Bitcoin Core provider watches the Strike address and the invoice follows
   the standard `New` → `Processing` → `Settled` lifecycle, including your
   store's confirmation policy below.
3. If the Strike API is unreachable at checkout, the store's own
   xpub / static address (when configured) transparently takes over as the
   fallback address source, so on-chain never drops off the checkout. A
   store can also run Strike-only (no xpub at all) — then a Strike outage
   simply leaves that invoice Lightning-only.

Requirements: a Strike API key with the additional **Create receive
requests** scope (`partner.receive-request.create`) — enabling the option
tests the key with a real 1-sat receive request (never paid) and refuses
keys missing the scope — and a **mainnet** on-chain configuration (Strike
doesn't do testnets). Note that funds received this way are custodied by
Strike, like your Strike Lightning receipts; conversion follows your Strike
account settings.

## Confirmation policy

Two related settings per store:

- **Required confirmations** (`onchain_min_confs`). Default: 1. Set to 0 to
  accept zero-confirmation (mempool) payments — fastest but reversible by
  fee-bumping (RBF) until confirmed.
- **Confirmation window** (`onchain_confirm_timeout_sec`). Default: 86400
  (24h). Once a payment is first seen in mempool, how long the server waits
  for it to reach the required confirmation count. If the window passes
  without enough confirmations, the invoice transitions to `Invalid`.

The standard `invoice_expiration` (default 1 hour, global) still applies as
the "time to broadcast" window: if no mempool sighting happens before it
elapses, the invoice expires.

Both settings apply to every on-chain receive source, Strike-minted
addresses included. The onboarding wizard asks the zero-conf question
whenever the store ends up with any on-chain source — an xpub / static
address, or "accept on-chain payments via Strike" (which is why the screen
comes after the Lightning screen, where that option lives). In the admin
on-chain card the settings save even with no xpub / static address entered,
so a Strike-only store can change its confirmation policy there too.

## Multi-transaction totaling

If a customer sends multiple transactions paying the same invoice address,
CashuPayServer sums them. The invoice settles when the **total confirmed
amount** (transactions with at least `min_confs` confirmations) reaches the
invoice amount.

A single transaction that overpays is accepted (Settled).

## Provider (blockchain data source)

The default provider is **Esplora HTTP** — the API used by
[mempool.space](https://mempool.space) and
[blockstream.info](https://blockstream.info). It supports both confirmed
and mempool (zero-conf) transactions through a single endpoint.

### Default URLs

| Network | Default provider URL |
|---------|----------------------|
| mainnet | `https://mempool.space/api` |
| testnet | `https://mempool.space/testnet/api` |
| signet  | `https://mempool.space/signet/api` |
| regtest | (none — must be configured manually) |

### Using a self-hosted mempool.space (or your own Esplora)

You can run your own mempool.space / Esplora instance for full sovereignty
and privacy (the public API otherwise sees every address your store
generates). Set **Provider URL** in the store settings to the base URL of
your instance, e.g. `https://my-mempool.example.com/api`.

### Bitcoin Core RPC (advanced)

For users who already run a Bitcoin Core node, CashuPayServer ships a second
provider (`bitcoind-rpc`) that talks directly to Bitcoin Core via JSON-RPC.
Configure the store with `onchain_provider = bitcoind-rpc` and a provider
URL like `http://user:pass@127.0.0.1:8332/wallet/cashupay-watch`. The node
needs a separate descriptor-based watch-only wallet (`createwallet ...
disable_private_keys=true`) so CashuPayServer's `importdescriptors` calls
succeed.

The same `BitcoindRpcProvider` powers the regtest end-to-end tests, so the
provider abstraction is exercised on every CI run.

## What CashuPayServer *can't* do for on-chain

- **Move your funds.** Your private keys never enter the server. To spend
  the BTC paid to your invoices, use your wallet directly — CashuPayServer
  is a passive watcher.
- **Reorg recovery beyond what Esplora reports.** If a confirmed transaction
  gets reorged out, the next poll will see the lower confirmation count or
  absent tx and update accordingly. We don't run independent Merkle proof
  verification in v1; the data source is trusted to be honest. A
  defence-in-depth mode with a secondary provider + Merkle proofs is on the
  roadmap for v2.
- **Taproot.** See above. P2WPKH and P2SH-P2WPKH only for v1.
- **Lightning Network on-chain channel opens.** This is purely about
  receiving regular on-chain transactions to a wallet.

## Trust model

The default Esplora provider sees every address your store generates. That
means a public provider can:

- Build a list of all your addresses and their balances.
- Censor your view of payments (refuse to report them).
- Lie about confirmation counts.

It **cannot** spend your funds — those keys never leave your wallet.

For meaningful sovereignty, self-host your provider (`mempool.space` is
open source) or use the Bitcoin Core RPC provider with your own node.
