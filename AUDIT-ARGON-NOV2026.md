# CashuPayServer & `cashu-wallet-php` Comprehensive Audit (`AUDIT-ARGON-NOV2026`)

**Date:** November 2026  
**Auditor:** Argon (Automated Deep-Dive Security, Fund-Safety, Reliability & UX Audit)  
**Scope:**
1. **CashuPayServer** (`*.php`, `includes/**/*.php`, `api-keys/authorize.php`, `wordpress/*.php`, `assets/js/*.js`, `.htaccess`)
2. **`cashu-wallet-php` Submodule** (`cashu-wallet-php/CashuWallet.php` — all 10,312 lines)
3. **`mint-discovery` Submodule** (`mint-discovery/*.js` and browser integration in `setup.php` / `admin.php`)

**Audit Focus Areas:**
1. **Money-Losing & Fund-Safety Bugs:** Double-spends, lost proofs, stranded balances, partial payment settlement, broken recovery paths, fee miscalculations, and currency/unit conversion errors.
2. **Reliability & Concurrency:** Race conditions between web requests and background tasks, SQLite locking/transaction desynchronization, multi-mint failover gaps, and protocol edge cases.
3. **Operator & Customer User Experience ("Marta" Persona):** Ability for a non-technical shop owner to install, configure, operate, troubleshoot, and recover without SSH, cron, or command-line knowledge.

---

## Executive Summary

CashuPayServer demonstrates a thoughtful, defense-in-depth architecture in many core areas:
- **Write-ahead pending operation journals (`cashu_pending_operations`)** reserve proofs and deterministic BIP-32/NUT-13 counter ranges in SQLite before contacting a mint over the network.
- **DNS-pinning SSRF protections (`Security::resolvePublicTarget()`)** and TLS/protocol hardening (`cashupay_curl_protocol_options()`) protect `MintClient` and `WebhookSender`.
- **Fail-closed operator safety guards (`StoreSafety`)** block store deletion and mint/unit changes when funds or pending operations are present.
- **First-browser setup ownership (`SetupOwnership`)** and printable recovery kits (`RecoveryKit`) cater directly to non-technical operators.

> **Status (2026-11-08):** verified and remediated; see [Verification & Remediation](#verification--remediation-2026-11-08) below the summary matrix.

Despite these strong foundations, our line-by-line audit uncovered **36 actionable issues** across CashuPayServer, `cashu-wallet-php`, and `mint-discovery`:
- **7 Critical findings** (capable of causing direct loss of funds, accepting unpaid orders as paid, silently breaking all WooCommerce order webhooks after plugin reinstall, or stranding backup-mint funds when the primary mint is offline)
- **13 High-severity findings** (broken Greenfield API BTC amounts, fatal `ValueError` crashes on small BTC invoices, 100x overcharging on zero-decimal currencies like JPY, incomplete unit lists in `StoreSafety` allowing deletion of funded stores, 1,000-proof SQLite variable limit crashes after mint swap completion, broken V4 CBOR token encoding, and inverted Nostr mint reputation rankings)
- **16 Medium / Low-Medium findings** (concurrency races, SQLite `PDO::inTransaction()` desynchronization on PHP < 8.4, non-FPM background runner failures, and critical "Marta" UX traps during setup, WordPress integration, and withdrawals)

### Summary Matrix of Findings

| ID | Priority / Severity | Component | Title / Summary |
| :--- | :--- | :--- | :--- |
| **CPS-01** | **CRITICAL (P0)** | `includes/payment_request.php`, `CashuWallet.php` | NUT-18 Payment Request marked `paid` with 0 sats received (or wallet poisoned with unowned proofs) when `recoverPendingSwaps()` resolves failed `receive()` journal |
| **CPS-02** | **CRITICAL (P0)** | `wordpress/uninstall.php`, `wordpress/btcpay-integration.php` | Uninstalling and reinstalling the WordPress plugin leaves the WooCommerce webhook disabled (`enabled = 0`), so no orders are ever marked paid |
| **CPS-03** | **CRITICAL (P0)** | `includes/lightning_address.php`, `admin.php` | Backup-mint funds cannot be withdrawn via BOLT-11 (or estimated for fiat mints) when primary mint is offline; `manual_melt` creates duplicate melt quotes |
| **CPS-04** | **CRITICAL (P0)** | `includes/invoice.php`, `CashuWallet.php` | Partial payments / partial mint issuances settle invoices for the full amount (`MintQuote::isPaid()`, `completeIssuedInvoice()`, `recoverOrphanedInvoices()`) |
| **CW-01** | **CRITICAL (P0)** | `cashu-wallet-php/CashuWallet.php` | `replacePendingMintPlan()`, `finalizePendingMint()`, and `recoverPendingOutputs()` discard valid late mint signatures and never recover `replaced_plans` |
| **CW-02** | **CRITICAL (P0)** | `cashu-wallet-php/CashuWallet.php` | `Wallet::resolveSwapJournal()` marks untrusted `imported_secrets` as `UNSPENT` or `SPENT` in wallet storage when `$direct = false` |
| **REL-01** | **CRITICAL (P0)** | `includes/payment_request.php`, `receive.php` | Race condition between `receive.php` and `PaymentRequest::reconcileReceiving()` releases an active claim while `Wallet::receive()` is mid-flight |
| **CPS-05** | **HIGH (P1)** | `includes/api/stores.php` | Greenfield API `/payment-methods` endpoint returns raw fiat invoice amount as `BTC` (e.g. `25.00 EUR` reported as `25.00 BTC`) |
| **CPS-06** | **HIGH (P1)** | `includes/rates.php`, `includes/invoice.php` | Uncaught `ValueError` in `ExchangeRates` `bc*()` calls when small BTC amounts (`< 0.0001 BTC`) or floats format in scientific notation (`'5.0E-5'`) |
| **CPS-07** | **HIGH (P1)** | `includes/rates.php`, `includes/config.php`, `admin.php` | Zero-decimal fiat currencies (`JPY`) multiplied by 100 (`unitDecimals` hardcoded to 2); `msat` balance rounded up (`ceil`) to `sats`; same-unit sub-cent truncation |
| **CPS-08** | **HIGH (P1)** | `includes/store_safety.php`, `includes/invoice.php` | Incomplete 5-unit hardcoded list in `StoreSafety` & `scanStrandedNamespaces()` misses `gbp`, `usdc`, `btc`, `cad`, `jpy`, `aud`, `chf` — failing to block store deletion |
| **CPS-09** | **HIGH (P1)** | `includes/lightning_address.php` | `Donation::sendToDonationSink()` swaps **all** unspent proofs in the wallet instead of selecting a subset, burning wallet-wide input fees or exceeding `max_inputs` |
| **CPS-10** | **HIGH (P1)** | `includes/lightning_address.php`, `includes/background_runner.php` | `executeMelt()` records unrecovered change as "fee" and prematurely completes transfer when `changeRecoveryPending = true`; background reconciliation records `fee = 0` |
| **CPS-11** | **HIGH (P1)** | `includes/background_runner.php`, `includes/invoice.php`, `admin.php` | Token transfers from former/backup mints query the wrong mint in `check_proofs_spent`, `reconcileTokenTransfer()`, `check_transfer`, and `reclaimExportedProofs()` |
| **CW-03** | **HIGH (P1)** | `cashu-wallet-php/CashuWallet.php` | `finalizePendingSpend()` and `settlePendingSpend()` skip `storeProofs($outputProofs)` if journal row was removed or input ownership check throws |
| **CW-04** | **HIGH (P1)** | `cashu-wallet-php/CashuWallet.php` | Unchunked `IN (...)` clauses in `WalletStorage` exceed SQLite's 999-variable limit after a 1,000-proof swap succeeds at the mint, rolling back new proofs |
| **CW-05** | **HIGH (P1)** | `cashu-wallet-php/CashuWallet.php` | `CBOR::encode()` heuristic binary detection and unpadded `hex2bin()` on DLEQ scalars in `TokenSerializer::serializeV4()` produce corrupt `cashuB` tokens |
| **CW-06** | **HIGH (P1)** | `cashu-wallet-php/CashuWallet.php` | `loadRestoreKeys()` and `ensureKeysetKeys()` do not verify V2 (`01...`) keyset IDs against public keys when loaded outside `loadMint()` |
| **REL-02** | **HIGH (P1)** | `includes/background_runner.php`, `includes/security.php` | Raw `$pdo->exec('BEGIN IMMEDIATE')` desynchronizes `PDO::inTransaction()` on PHP < 8.4 |
| **REL-03** | **HIGH (P1)** | `includes/invoice.php`, `includes/background_runner.php` | `Invoice::checkPendingProofs()` and `BackgroundRunner::cleanupPendingOperations()` ignore backup mints (and `cleanupPendingOperations()` makes live HTTP calls to delete local rows) |
| **MD-01** | **HIGH (P1)** | `mint-discovery/*.js` | 100% HTTP cache miss rate (`fetchedAt` vs `lastHttpInfoFetchAt`), inverted review deduplication (oldest review overwrites newest), and 1-star scam mints ranked above 5-star mints |
| **UX-01** | **HIGH (P1)** | `wordpress/rewrite-rules.php`, `wordpress/admin-menu.php` | WordPress Plain Permalinks (`permalink_structure === ''`) cause immediate 404s on Setup, Admin, API, and Checkout with no warning or fallback |
| **UX-02** | **HIGH (P1)** | `setup.php` | Setup Step 8 hardcodes `(sats)` and `10000` auto-withdrawal threshold even when operator selected a `USD` or `EUR` mint in Step 5 |
| **UX-03** | **HIGH (P1)** | `admin.php`, `includes/config.php` | `add_backup_mint` never verifies that the backup mint supports the store's unit and allows adding the primary mint as its own backup |
| **CW-07** | **MEDIUM-HIGH (P2)** | `cashu-wallet-php/CashuWallet.php` | `Bolt11::amountSats()` splits on the first `'1'` instead of the last `'1'` (`strrpos`) and does not validate the Bech32 data boundary |
| **REL-04** | **MEDIUM-HIGH (P2)** | `includes/lightning_address.php`, `includes/rates.php` | Missing `cashupay_curl_protocol_options()` and SSRF target resolution on `Donation::postTokenToSink()` and `ExchangeRates` cURL requests |
| **CW-08** | **MEDIUM (P2)** | `cashu-wallet-php/CashuWallet.php` | Uncaught `\TypeError` exceptions on malformed mint responses or CBOR inputs (`unblindSignature`, DLEQ arrays, `loadMint`, `calculateFee`, `Transport::fromArray`) |
| **CW-09** | **MEDIUM (P2)** | `cashu-wallet-php/CashuWallet.php` | `unblindMeltChange()` verifies DLEQ proof on melt change signatures but discards it when constructing `Proof` objects |
| **CW-10** | **MEDIUM (P2)** | `cashu-wallet-php/CashuWallet.php` | `selectProofsWithFees()` includes negative-yield dust proofs on fee-bearing keysets; `getActiveKeysetId()` ignores `finalExpiry` and `inputFeePpk`; `rotateProofs()` mixes expired keysets |
| **CW-11** | **MEDIUM (P2)** | `cashu-wallet-php/CashuWallet.php` | `split()` does not sort output amounts ascending (NUT-03 privacy leak); `swap()`/`split()` lack pre-journal input denomination validation |
| **CW-12** | **LOW-MEDIUM (P3)** | `cashu-wallet-php/CashuWallet.php` | Stale in-memory `$this->counters`, non-monotonic `setCounter()`, uppercase V3 hex keyset IDs, zombie journal on already-issued quote, and URL canonicalization mismatch |
| **REL-05** | **MEDIUM (P2)** | `includes/background.php` | `Background::trigger()` silently drops all background tasks on non-FPM Apache (`mod_php`) when `base_url` is unpinned, and double-registers shutdown functions |
| **REL-06** | **MEDIUM (P2)** | `receive.php`, `includes/webhook_sender.php` | `receive.php` ignores payment request's stored `mint_url`/`unit`, omits `requiresRecovery()` check, and leaks raw `CashuException` messages; `requeueDelivery()` ignores `deleted_at` |
| **UX-04** | **MEDIUM (P2)** | `setup.php`, `includes/auth.php` | Setup Step 8 displays `"Step 8 of 5"` / `"Step 8 of 6"`, Step 6 button says `"Complete Setup"` prematurely, and Standalone setup forces immediate re-login after setting password |
| **UX-05** | **MEDIUM (P2)** | `setup.php`, `wordpress/btcpay-dependency.php` | WordPress Setup Step 7 tells Marta to `"return here"` after disconnecting/installing BTCPay, but returning redirects her away; no 1-click WooCommerce connect button exists in Admin |
| **UX-06** | **MEDIUM (P2)** | `setup.php`, `wordpress/rewrite-rules.php`, `api-keys/authorize.php`, `admin.php`, `payment.php`, `receive.php` | Operator & Customer UX polish: sysadmin shell/cron copy in `setup.php`, broken WP logged-out pairing form, Withdraw modal traps, `payment.php` post-expiry buttons, and `receive.php` raw cents display |

---

## Verification & Remediation (2026-11-08)

Every finding below was checked against the code before anything was changed. The
audit's line numbers were often stale, and several proposed fixes were unsafe as written;
where that was the case the fix was designed differently, as noted. Verdicts:
**CONFIRMED** (real as described), **PARTLY** (real, but narrower or different than
described), **FALSE** (not a bug; nothing changed).

Tests: `php tests/run.php` (59 scripts, including the `cashu-wallet-php` PHPUnit suite,
now 492 tests) passes. New regression tests: `tests/argon_2026_11_receiving.php`,
`tests/payouts_argon_2026_11.php`, `tests/setup_wizard_2026_11.php`,
`tests/wordpress_setup_2026_11.php`, `tests/payment_page_2026_11.php`, additions to
`tests/conversion.php` and `tests/api_webhooks_and_stores.php`,
`cashu-wallet-php/tests/unit/AuditArgonTest.php`, `mint-discovery/test/aggregator.test.js`.

### Money, settlement & accounting

| ID | Verdict | Resolution and reasoning |
| :--- | :--- | :--- |
| CPS-01 / CW-02 | CONFIRMED | Background recovery (`recoverPendingSwaps`, `$direct = false`) kept imported token proofs as SPENT when the swap credited nothing, and `receiptState()` read that as paid; a token refused on resubmit was kept UNSPENT (phantom balance). **Library:** `resolveSwapJournal()` now always deletes imported secrets when no outputs were signed (`$direct` removed). **Server, independently of the library version:** the swap's output plan is copied into `payment_requests.swap_plan` (schema v13) before any recovery can settle it, and a request is paid only when the plan's deterministic outputs are in the wallet. All server swap recovery goes through `Invoice::recoverSwapsForStore()`, which records plans first under the store's receive lock. |
| CPS-02 | CONFIRMED | Reconnecting WooCommerce reused the webhook row that `uninstall.php` had disabled. `cashupay_register_webhook()` now re-enables it and resets its events. |
| CPS-03 | CONFIRMED (item 3 PARTLY) | Pasted-invoice pricing, the fiat estimate and `manual_melt` all quoted at the primary mint, so with the primary down a backup-mint balance could not be withdrawn (the UI disabled the button). New `LightningAddress::quotePayingAccount()` quotes at the account that will pay. Duplicate melt quotes are harmless (unused quotes expire); the real cost was extra timeouts, and the probe quote is gone. |
| CPS-04 items 1–2 | FALSE | Proofs tagged with a quote id are written only by `finalizePendingMint()` as one complete, count- and sum-checked plan, so a partial sum cannot exist. A sum check would add only stranding risk. |
| CPS-04 item 3 | PARTLY | An honest bolt11 quote cannot be partly paid, and a lying mint still cannot issue more than was paid. The real cost was a paid-but-failing quote replanning on every tick. `MintQuote::isPaid()` now requires `amount_paid - amount_issued >= max(1, amount)`. |
| CPS-05 | CONFIRMED | Fiat amounts were labelled BTC. The response now follows BTCPay 2.x `InvoicePaymentMethodDataModel` (checked against upstream `GreenfieldInvoiceController.ToPaymentMethodModels`): amounts in BTC, `rate` = invoice currency per BTC (SAT → 100000000), and the previously missing `paymentMethodPaid`, `paymentMethodFee` and `payments[]` are now present. Their absence made the greenfield-php client's typed getters throw, so the WooCommerce plugin's `updateWCOrderPayments()` never recorded payment details. **Breaking for clients that read the old (wrong) values.** |
| CPS-06 | PARTLY | Greenfield create-invoice rejected `0.00005` with a 400 (no crash); the admin "create invoice" form crashed with a BCMath `ValueError` (500). New `ExchangeRates::normalizeDecimal()` (string-exact exponent expansion) at every entry point. Prices were never in scientific notation in practice. |
| CPS-07 | CONFIRMED (item 4 FALSE) | Zero-decimal ISO currencies (JPY, KRW, …) are now 0 decimals via `Config::ZERO_DECIMAL_UNITS`, shared by PHP and the admin JS; msat→sat uses `intdiv` (also in two admin.php spots); same-unit conversion rounds up like the cross-currency path. Scale 8 was exact; switched to `SCALE` anyway. The library's `Cashu\Unit` already treated unknown units as 0 decimals. |
| CPS-08 | CONFIRMED | New `StoreSafety::candidateUnits()` (common units plus every unit recorded in the database), also used by `scanStrandedNamespaces()`, which now tries mint URLs from history as well. An unmatched namespace with an in-flight operation now blocks store deletion. |
| CPS-09 | CONFIRMED | `Donation::sendToDonationSink()` now uses `selectProofsWithFees()` and checks only the selected proofs. |
| CPS-10 | CONFIRMED | Library: `payToLightningAddress()` propagates `changeRecoveryPending`. Server: `executeMelt()` leaves the transfer pending (plain-language detail) instead of booking the uncollected change as fee; `reconcileWithdrawal()` computes the real fee (inputs − amount − change) via `paidMeltFee()` once the melt journal closes. |
| CPS-11 | CONFIRMED | `checkProofStatesBySecrets()`, `markProofsSpent()` and `reclaimExportedProofs()` take optional `$mintUrl, $unit`; the transfer row's mint is passed from `reconcileTokenTransfer()`, `check_transfer`, `reclaim_transfer` and `export_token`. `check_proofs_spent` was rewritten on the same helpers. |

### cashu-wallet-php

| ID | Verdict | Resolution and reasoning |
| :--- | :--- | :--- |
| CW-01 | PARTLY | The race needs a definitive rejection of a plan while that plan's own request is still in flight: a narrow window, and funds stayed restorable from the seed. The audit's "store and delete regardless of plan" was **not** applied: proofs are accepted only for the journal's current plan or one recorded in its `replaced_plans`. `replaced_plans` now records `amounts`; new `recoverMintJournalOutputs()` checks replaced plans, newest first. Follow-up: replans per quote are capped at `Wallet::MAX_MINT_REPLANS` (3), because a mint refusing every plan would otherwise burn counters on every tick until outputs fall past the NUT-13 restore look-ahead. |
| CW-03 | CONFIRMED | "Store first" alone would be rolled back by the exception that follows. New `keepOutputsAndThrow()`: on a validation or ownership failure, the signed outputs are stored and committed, the journal is kept, and the call still throws. |
| CW-04 | CONFIRMED (SQLite < 3.32 only) | `IN (...)` lists chunked at 400 in the four storage methods, and in `payment_request.php` (`receiptState`, `planOutputsHeld`, `secretsKnown`). |
| CW-05 | CONFIRMED | New `Cashu\CBORBytes` marks byte strings explicitly; DLEQ scalars are left-padded to 64 hex characters; malformed hex throws instead of producing a corrupt token. |
| CW-06 | PARTLY | `loadRestoreKeys()` did no ID check; `ensureKeysetKeys()` checked only keysets listed by `/keysets`. Both now verify V2 IDs (`v2KeysetIdError()`). |
| CW-07 | FALSE | The bech32 data part cannot contain `1`, and the greedy `\d*` backtracks to the real separator on every valid invoice. |
| CW-08 | CONFIRMED | Point at infinity, malformed `dleq`, fee overflow, wrong-typed keyset/transport/payment-request fields: all raise `CashuException` now instead of a `TypeError`. |
| CW-09 | CONFIRMED | Melt change proofs keep their DLEQ (with `r`). |
| CW-10 | PARTLY | Skips dust that costs more in fees than it is worth, skips expired active keysets, rotates per keyset. Not done: a fee tie-break between keysets (no basis in the spec). |
| CW-11 | CONFIRMED | `split()` orders outputs ascending and rejects `amount <= 0`; `swap()` validates inputs and denominations before journaling. Empty outputs stay allowed (reclaiming a token whose whole value is fees). Not done: sorted journal ids (already safe: a reordered call is refused because the inputs are reserved). |
| CW-12 | PARTLY | Fixed: monotonic NUT-20 counter (`raiseCounter`), lowercase hex keyset ids, no zombie journal for an already-issued quote. Not done: `getCounter()` reading storage (an in-memory diagnostic; no allocation uses it), and canonicalising the URL in `deriveWalletId()` (would change wallet ids and orphan existing proofs; needs a planned migration). |

### Reliability, security & mint discovery

| ID | Verdict | Resolution and reasoning |
| :--- | :--- | :--- |
| REL-01 | CONFIRMED | `reconcileReceiving()` takes the store's receive lock (non-blocking), skips rows a receive is working on, and re-reads the row under the lock. |
| REL-02 | FALSE | Nothing inside the raw `BEGIN IMMEDIATE` sections calls `beginTransaction()`/`inTransaction()`; only plain queries run there. Changing them would add PHP 8.3 risk for no gain. |
| REL-03 | CONFIRMED | `checkPendingProofs()` covers every account and contacts a mint only when that account has PENDING proofs; `cleanupPendingOperations()` uses local storage only. |
| REL-04 | PARTLY | Protocol options, no redirects, TLS verification and a connect timeout added to the rate providers and the donation sink. No address check on the sink: its URL is hard-coded config, and the check would break the local test sink. |
| REL-05 | PARTLY | Double shutdown registration fixed. Not "silent": the dashboard already flags an unpinned server address with a one-click fix. Running the work in the request on mod_php was rejected, because it would hold the customer's (or WooCommerce's) request open for ~20 s. |
| REL-06 | PARTLY | `receive.php` pays at the request's own mint/unit (refusing in plain words if that mint is no longer enabled) and refuses while the wallet is being restored; deleted webhooks are no longer requeued. The remaining `CashuException` messages describe the payer's own token and stay. |
| MD-01 | CONFIRMED (5 PARTLY; "unbounded promises" FALSE) | Cache freshness key fixed (failed fetches never count as fresh); the newest review per author wins; ranking is reachable first, then a smoothed rating `(6 + sum) / (2 + n)`, then count; URLs normalised; u-tag label must be missing, empty or "cashu" (NIP-87 defines no third element, and ~74% of live tags have none); rating regex anchored. nostr-tools ends each relay after 4.4 s, so streaming does finish. Bundle rebuilt; `setup.php`'s streaming list uses the same `compareRecommendations`. |

### Operator UX

| ID | Verdict | Resolution and reasoning |
| :--- | :--- | :--- |
| UX-01 | CONFIRMED | The audit's `?cashupay_page=` fallback cannot work: the BTCPay plugin always calls `{server}/api/v1/...`, and admin.php builds `adminUrl + '?api=…'`. Instead: a plain-language notice with a "Switch to readable web addresses" button (changes nothing until pressed; verifies the API answers afterwards and gives exact manual steps if not); no redirects to pages that would 404. |
| UX-02 | PARTLY | The address probe was already sat-only. The label, default and parsing now follow the store's unit (smallest-unit storage, as `LightningAddress` reads it); a comma is refused with a clear message, because `Unit::parse` would read "20,50" as 2050. |
| UX-03 | CONFIRMED | The primary itself (normalised URL) and mints without the store's currency are refused in plain language; the store's own unit is used, not the posted one. An unreachable mint was already refused. |
| UX-04 | CONFIRMED | Step counter and "Continue" label fixed. Standalone setup now signs the browser in when the password is chosen, via new `Auth::startAdminSession()` (shared with `login()`: new session id, rotated CSRF token). |
| UX-05 | CONFIRMED | One-click "Connect my shop to CashuPay" (new `cashupay_connect_woocommerce_store()`, shared with setup step 7) in the wp-admin notice and, as a follow-up, in the CashuPay dashboard's attention card (`woocommerceStep`), where the operator lands after setup. Settings link corrected to `tab=btcpay_settings` (verified in the plugin's `GlobalSettings`). |
| UX-06 | CONFIRMED (all 8) | Relocate-data button is the main action and shell steps are folded away; no raw crontab line (WordPress: reassurance only); WordPress sign-in link on the pairing page; amountless invoices refused when pasted; withdraw modal shows the single-mint maximum; Refresh runs the background tasks; payment page uses the invoice's unit and hides pay buttons after expiry; `receive.php` shows formatted amounts and has a clipboard fallback. |

### Deployment test (2026-11-08)

Tested briefly on the cashupayserver.org demo instance (PHP 8.3.36, AlmaLinux) before
release:

- The v12 → v13 migration was rehearsed on the server's PHP 8.3 against a copy of the live
  database: no migration error, `payment_requests.swap_plan` added, `integrity_check` ok,
  a second run a no-op. Every PHP file of the build also lints clean under 8.3.
- The build was deployed (keeping `data/` and `config.local.php`, live database
  snapshotted first as `cashupay.sqlite.predeploy-argon-20261108-123849`), and the first
  requests migrated the live database as the web server user: `user_version` 13, column
  present, `quick_check` ok, no unfinished wallet operations, no SQLite sidecars left with
  the wrong owner. The main page and the admin page load; setup redirects as expected.
- The maintainer then used the demo instance and reported that it works.

This was a short smoke test, not a payment-by-payment exercise of every fix; the
regression suites above are what cover the individual findings.

Released as CashuPayServer v0.5.10-alpha, with cashu-wallet-php v0.3.0 and
mint-discovery v1.1.0.

### Known leftovers

- `mint-discovery` passes `nostrTimeout` as `{timeout}`, which nostr-tools 2.x ignores (it reads `maxWait`); the real limit is 4.4 s per relay. Left as is: fixing the plumbing would make discovery wait up to the configured 15 s, which is worse for the operator, and 4.4 s finds the mints in practice.
- `tests/setup_probe_2026_10.php` can fail under full-suite load (local `php -S` servers and short timeouts); it passes on its own and the probe code is unchanged. It was already flaky before this audit.
- `tests/rates_2026_10.php` had a one-second timing flake in its anchor-drift tolerance; the test now allows a few seconds of slack (the code was correct).

---

## Part 1: Money-Losing, Fund-Stranding & Accounting Bugs (CashuPayServer)

### [CPS-01] CRITICAL: Free-Payment / False-"Paid" Vulnerability & Wallet Poisoning on NUT-18 Payment Requests When `recoverPendingSwaps()` Resolves a Failed `receive()` Swap

- **Location:**
  - `includes/payment_request.php` lines 151–179 (`PaymentRequest::receiptState()`) and lines 212–250 (`PaymentRequest::reconcileReceiving()`)
  - `receive.php` lines 351–380
  - `cashu-wallet-php/CashuWallet.php` lines 6615–6676 (`Wallet::resolveSwapJournal()`)
- **Priority / Severity:** **CRITICAL (P0 — Direct Money Loss / Free Goods + Wallet Lockup)**
- **Detailed Problem & Failure Scenario:**
  1. A customer (or attacker) submits an ecash token to `receive.php` to pay a NUT-18 `PaymentRequest`.
  2. `PaymentRequest::claim()` (`receive.php:339`) sets `status = 'receiving'`, `receiving_since = time()`, and stores the submitted token's proof secrets in `payment_requests.input_secrets`.
  3. `receive.php` calls `$wallet->receive($tokenString)` (`receive.php:425`), which calls `Wallet::swap()` -> `WalletStorage::preparePendingSpend()` (`CashuWallet.php:5295-5306`). Because the submitted token's proofs are external (not previously in the merchant's `cashu_proofs` table), `preparePendingSpend()` **inserts those external proofs into `cashu_proofs`** with `state = 'PENDING'` and `created_at = time()` (`>= receiving_since`), and records their secrets in the `swap:<id>` journal under `$data['imported_secrets']`.
  4. Suppose the HTTP `POST /v1/swap` to the mint is interrupted or times out (or the attacker concurrently spends the same token directly at the mint so our `/v1/swap` fails after a network drop, or the PHP worker terminates before `resolveSwapJournal` completes).
  5. Next, background recovery (`BackgroundRunner::recoverWalletOperations()` -> `$wallet->recoverPendingSwaps()`, or the retry loop in `receive.php:357`) calls:
     ```php
     $outcome = $this->resolveSwapJournal($op['id'], $op['data'], false, false);
     ```
     Notice the 4th parameter: **`$direct = false`**.
  6. Inside `Wallet::resolveSwapJournal()` (`CashuWallet.php:6639-6674`), the mint reports the input proofs as `SPENT` (spent by the attacker), `recoverPendingOutputs($data)` returns `[]` (none of our blinded outputs were signed by the mint), and execution reaches lines 6661–6671:
     ```php
     $imported = array_flip($data['imported_secrets'] ?? []);
     $outcome = [];
     foreach ($states as $secret => $state) {
         if ($direct && isset($imported[$secret])) {
             $outcome[$secret] = null;
         } else {
             $outcome[$secret] = $state === ProofState::SPENT ? ProofState::SPENT : ProofState::UNSPENT;
         }
     }
     $this->storage->settlePendingSpend($id, $secrets, $outcome, [], $rejected);
     ```
     Because `$direct` is `false`, `if ($direct && isset($imported[$secret]))` is **`false`**! Instead of deleting (`null`) the unowned `imported_secrets`, `settlePendingSpend()` **keeps the attacker's external proofs in the merchant's `cashu_proofs` table and marks them `ProofState::SPENT`**!
  7. Immediately afterward, `PaymentRequest::reconcileReceiving()` (`includes/payment_request.php:228`) calls `PaymentRequest::receiptState($wallet, $request, $secrets)` (`includes/payment_request.php:151-179`):
     ```php
     $rows = $storage->getProofsBySecrets($secrets);
     $rows = array_values(array_filter($rows, fn($r) => (int)($r['created_at'] ?? 0) >= $since));
     if (count($rows) < count($secrets)) {
         return ['state' => 'released'];
     }
     $spent = array_filter($rows, fn($r) => $r['state'] === \Cashu\ProofState::SPENT);
     if (count($spent) === count($secrets)) {
         $proofs = $storage->getProofsBySecretsAsObjects($secrets);
         $amount = \Cashu\Wallet::sumProofs($proofs) - $wallet->calculateFee($proofs);
         return ['state' => 'paid', 'amount' => max(0, $amount)];
     }
     ```
  8. Because all of the attacker's input proofs are now in `cashu_proofs` with `state = 'SPENT'` and `created_at >= $since`, `receiptState()` returns `'state' => 'paid'`! `PaymentRequest::markPaid()` marks the payment request as **paid in full** even though the merchant received **0 sats**!
  9. **Second failure mode (Wallet Poisoning):** If the interrupted `receive()` swap failed because the external token had a forged/invalid signature `C` (`PROOF_VERIFICATION_FAILED` on resubmit in `recoverPendingSwaps()`, `CashuWallet.php:6565`), NUT-07 `/v1/checkstate` still reports `$state === 'UNSPENT'` (since `Y` was never spent). Because `$direct` is `false`, `resolveSwapJournal()` sets `$outcome[$secret] = ProofState::UNSPENT`! The invalid/unowned external proof is promoted into the merchant's `UNSPENT` wallet balance, inflating the displayed balance and causing every subsequent withdrawal/export that selects it to fail with `PROOF_VERIFICATION_FAILED`!
- **How to Fix:**
  1. In `cashu-wallet-php/CashuWallet.php` line 6664 (`Wallet::resolveSwapJournal()`), remove `$direct &&` so that `imported_secrets` are **always** deleted (`$outcome[$secret] = null`) whenever a swap produces no signed outputs (`$recovered === []`):
     ```php
     $imported = array_flip($data['imported_secrets'] ?? []);
     $outcome = [];
     foreach ($states as $secret => $state) {
         if (isset($imported[$secret])) {
             // External proofs imported solely for a swap that produced no signed
             // outputs were never owned by this wallet and must never remain in storage.
             $outcome[$secret] = null;
         } else {
             $outcome[$secret] = $state === ProofState::SPENT ? ProofState::SPENT : ProofState::UNSPENT;
         }
     }
     ```
  2. With this fix in place, `imported_secrets` only remain in `cashu_proofs` as `SPENT` when `finalizePendingSpend()` succeeds (which atomically stores the newly signed output proofs in the same SQLite transaction).

---

### [CPS-02] CRITICAL: Uninstalling and Reinstalling the WordPress Plugin Silently Leaves the WooCommerce Webhook Disabled (`enabled = 0`), So No Future Orders Are Marked Paid

- **Location:**
  - `wordpress/uninstall.php` lines 91–99
  - `wordpress/btcpay-integration.php` lines 122–140 (`cashupay_register_webhook()`)
- **Priority / Severity:** **CRITICAL (P0 — Complete Silent Order Settlement Failure)**
- **Detailed Problem & Failure Scenario:**
  1. When Marta deactivates and deletes the CashuPay plugin in WordPress (for instance, to reinstall or update it via the WP Plugins screen), `wordpress/uninstall.php` preserves `cashupay.sqlite` (so wallet funds are not lost) and disables the WooCommerce webhook in SQLite (lines 96–99):
     ```php
     $id = is_array($wh) && isset($wh['id']) ? (string)$wh['id'] : '';
     if ($id !== '') {
         $pdo->prepare("UPDATE webhooks SET enabled = 0 WHERE id = ?")->execute([$id]);
     }
     ```
     Notice that it sets `enabled = 0`, leaves `deleted_at = NULL`, and deletes the `btcpay_gf_webhook` WP option (line 113).
  2. When Marta reinstalls CashuPay and clicks **"Configure WooCommerce"** (`cashupay_configure_btcpay_plugin()`), `cashupay_register_webhook()` (`wordpress/btcpay-integration.php` lines 123–139) checks for an existing webhook:
     ```php
     $existing = Database::fetchOne(
         "SELECT id, secret FROM webhooks WHERE store_id = ? AND url = ? AND deleted_at IS NULL",
         [$store_id, $webhookUrl]
     );

     if ($existing) {
         update_option('btcpay_gf_webhook', [
             'id' => $existing['id'],
             'url' => $webhookUrl,
             'secret' => $existing['secret'],
         ]);
         return [
             'success' => true,
             'webhook_id' => $existing['id'],
             'existing' => true,
         ];
     }
     ```
  3. Because `deleted_at` is `NULL`, `cashupay_register_webhook()` matches the disabled row and returns `'success' => true` **without setting `enabled = 1`**!
  4. When customers subsequently pay Lightning invoices at checkout, `WebhookSender::fireEvent()` (`includes/webhook_sender.php:42`) queries:
     ```sql
     SELECT * FROM webhooks WHERE store_id = ? AND enabled = 1 AND deleted_at IS NULL
     ```
  5. **Impact:** Setup says `"WooCommerce Connected!"`, customers pay real money, CashuPayServer mints the ecash tokens, and **zero webhooks are ever sent to WooCommerce**. Every order stays stuck in `"Pending payment"` forever with no error or diagnostic warning on the dashboard.
- **How to Fix:**
  In `wordpress/btcpay-integration.php` lines 123–140, always re-enable and refresh `events` when reusing an existing webhook row:
  ```php
  if ($existing) {
      Database::update(
          'webhooks',
          [
              'enabled' => 1,
              'events' => json_encode($events),
          ],
          'id = ?',
          [$existing['id']]
      );

      update_option('btcpay_gf_webhook', [
          'id' => $existing['id'],
          'url' => $webhookUrl,
          'secret' => $existing['secret'],
      ]);

      return [
          'success' => true,
          'webhook_id' => $existing['id'],
          'existing' => true,
      ];
  }
  ```

---

### [CPS-03] CRITICAL: Backup-Mint Funds Cannot Be Withdrawn via BOLT-11 Invoice (or Estimated for Fiat Mints) When Primary Mint Is Offline, and `manual_melt` Creates Duplicate Melt Quotes

- **Location:**
  - `includes/lightning_address.php` lines 594–623 (`LightningAddress::getBolt11Amount()`)
  - `admin.php` lines 1841–1875 (`case 'get_withdrawal_estimate':`)
  - `admin.php` lines 2033–2047 (`case 'manual_melt':` BOLT-11 branch)
  - `admin.php` lines 6144–6160 & 6316–6318 (`handleDestinationInput()` & `updateWithdrawInfo()`)
- **Priority / Severity:** **CRITICAL (P0 — Fund Stranding During Primary Mint Outage & Duplicate Melt Quotes)**
- **Detailed Problem & Failure Scenario:**
  Backup mints exist so a store can keep accepting payments when its primary mint goes down. Those payments accumulate in the backup mint's wallet namespace. However, three separate places in the manual withdrawal flow hardcode `Invoice::getWalletInstance($storeId)` (which **only** connects to the primary mint):
  1. **Pasting a BOLT-11 invoice disables the Withdraw button (`includes/lightning_address.php:601` & `admin.php:6149, 6316`):**
     When Marta pastes a BOLT-11 invoice into the Withdraw modal, `handleDestinationInput()` calls `action=get_bolt11_amount` -> `LightningAddress::getBolt11Amount($bolt11, $storeId)`. Line 601 calls:
     ```php
     $wallet = Invoice::getWalletInstance($storeId);
     $meltQuote = $wallet->requestMeltQuote($bolt11);
     ```
     If the primary mint is offline, this throws a network error, which `getBolt11Amount()` returns as `'meltError' => $e->getMessage()`. In `admin.php` line 6149, `if (result.meltError)` sets `bolt11MeltError = true`, disables the amount input, and at line 6316 sets `withdrawBtn.disabled = true`. **Marta cannot click Withdraw at all to withdraw her backup-mint balance!**
  2. **Fiat withdrawal estimation queries the primary mint (`admin.php:1843`):**
     `case 'get_withdrawal_estimate':` reads `$balance = Invoice::getLargestSpendableBalance($storeId)` (which may be on a backup mint), but line 1843 calls `$wallet = Invoice::getWalletInstance($storeId)` and line 1869 calls `$wallet->requestMeltQuote($bolt11)` on the primary mint.
  3. **Manual BOLT-11 withdrawal probes the primary mint and creates two melt quotes (`admin.php:2033-2047`):**
     ```php
     try {
         $probeQuote = Invoice::getWalletInstance($storeId)->requestMeltQuote($bolt11);
         $needed = $probeQuote->amount + $probeQuote->feeReserve;
     } catch (Throwable $probeError) {
         $needed = $storeUnit === 'msat' ? $invoiceAmountSats * 1000 : $invoiceAmountSats;
     }
     $account = Invoice::getFundsAccount($storeId, $needed);
     $result = LightningAddress::meltToBolt11(
         $storeId,
         $bolt11,
         null,
         $account ? ['mint_url' => $account['mint_url'], 'unit' => $account['unit']] : null
     );
     ```
     - Even when the primary mint is online and holds the funds, this calls `requestMeltQuote($bolt11)` on the primary mint at line 2035, and then `LightningAddress::meltToBolt11()` -> `executeMelt()` (`includes/lightning_address.php:267`) immediately calls `requestMeltQuote($bolt11)` **a second time** on the exact same mint for the exact same BOLT-11 invoice! Many mints/Lightning backends lock or reject duplicate melt quotes for the same BOLT-11 payment hash, and it doubles the network latency of every BOLT-11 withdrawal.
- **How to Fix:**
  1. In `LightningAddress::getBolt11Amount($bolt11, $storeId)` (`includes/lightning_address.php:594-623`), resolve the wallet account holding the store's spendable funds (`$account = Invoice::getFundsAccount($storeId, max(1, $amountSats))`) and call `Invoice::getWalletForStore($storeId, $account['mint_url'], $account['unit'])` instead of `Invoice::getWalletInstance($storeId)`.
  2. In `admin.php` `case 'get_withdrawal_estimate':` (line 1841), select `$account = Invoice::getFundsAccount($storeId, 1)` and instantiate `$wallet = Invoice::getWalletForStore($storeId, $account['mint_url'], $account['unit'])`, using `$balance = (int)$account['balance']`.
  3. In `admin.php` `case 'manual_melt':` (lines 2033–2047), avoid the duplicate `requestMeltQuote($bolt11)` call altogether: for `sat`/`msat` stores, compute `$needed` from `$invoiceAmountSats` + fee buffer (`LightningAddress::calculateFeeBuffer($invoiceAmountSats)`), or pass the already-fetched `$probeQuote` into `meltToBolt11()` so `requestMeltQuote()` is called only once on the selected `$account`.

---

### [CPS-04] CRITICAL: Partial Payment / Partial Issuance Vulnerabilities in `MintQuote::isPaid()`, `Invoice::completeIssuedInvoice()`, and `Invoice::recoverOrphanedInvoices()`

- **Location:**
  - `cashu-wallet-php/CashuWallet.php` lines 2154–2167 (`MintQuote::isPaid()`)
  - `includes/invoice.php` lines 484–514 (`Invoice::mintAndStoreTokens()`)
  - `includes/invoice.php` lines 963–1008 (`Invoice::completeIssuedInvoice()`)
  - `includes/invoice.php` lines 1022–1064 (`Invoice::recoverOrphanedInvoices()`)
- **Priority / Severity:** **CRITICAL (P0 — Settling Unpaid / Partially Paid Invoices)**
- **Detailed Problem & Failure Scenario:**
  1. In `Invoice::completeIssuedInvoice()` (`includes/invoice.php:970-978`):
     ```php
     $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
     if (!empty($proofs)) {
         self::settleAndEnqueue($invoice['id']);
         return;
     }
     ```
     And in `Invoice::recoverOrphanedInvoices()` (`includes/invoice.php:1036-1042`):
     ```php
     $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
     if (!empty($proofs)) {
         if (self::settleAndEnqueue($invoice['id'])) {
             $results['settled']++;
         }
         continue;
     }
     ```
     Neither method verifies that the sum of stored proofs for `$invoice['quote_id']` equals or covers `(int)$invoice['amount_sats']`! If even a single 1-sat proof exists for `mint_quote_id`, the invoice is marked `Settled` and the `InvoiceSettled` webhook is sent to the merchant's shop!
  2. Similarly, in `completeIssuedInvoice()` line 996, when `$restored = $wallet->recoverQuoteOutputs($invoice['quote_id'], (int)$invoice['amount_sats'])` returns non-empty `$restored`, it calls `self::settleAndEnqueue($invoice['id'])` without verifying `\Cashu\Wallet::sumProofs($restored) >= (int)$invoice['amount_sats']`.
  3. In `MintQuote::isPaid()` (`CashuWallet.php:2155-2157`):
     ```php
     if ($this->amountPaid !== null && $this->amountIssued !== null) {
         return $this->amountPaid > $this->amountIssued;
     }
     ```
     If a mint returns `amount_paid > 0` where `amount_paid < $invoice['amount_sats']` (an underpaid quote), `isPaid()` returns `true` even though `amount_paid` is less than the required quote amount.
- **How to Fix:**
  1. In `Invoice::completeIssuedInvoice()` (`includes/invoice.php:971, 996`) and `Invoice::recoverOrphanedInvoices()` (`includes/invoice.php:1037`), require the sum of proofs for `$invoice['quote_id']` to be `>= (int)$invoice['amount_sats']`:
     ```php
     $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
     $storedAmount = array_sum(array_map(fn($p) => (int)($p['amount'] ?? 0), $proofs));
     if ($storedAmount >= (int)$invoice['amount_sats']) {
         self::settleAndEnqueue($invoice['id']);
         return;
     }
     ```
  2. In `Invoice::mintAndStoreTokens()` (`includes/invoice.php:484`), check `if ($quoteStatus->amountPaid !== null && $quoteStatus->amountPaid < (int)$invoice['amount_sats'])` before attempting to mint `(int)$invoice['amount_sats']`.

---

### [CPS-05] HIGH: Greenfield API `GET /api/v1/stores/{storeId}/invoices/{invoiceId}/payment-methods` Reports Raw Fiat Invoice Amount as `BTC`

- **Location:** `includes/api/stores.php` lines 244–269 (`handleInvoicePaymentMethods()`)
- **Priority / Severity:** **HIGH (P1 — Broken Greenfield API Compatibility / 100,000x Amount Mismatch)**
- **Detailed Problem & Failure Scenario:**
  Look at `handleInvoicePaymentMethods()` in `includes/api/stores.php` lines 253–268:
  ```php
  sendJsonResponse([
      [
          'paymentMethod' => 'BTC-LightningNetwork',
          'paymentMethodId' => 'BTC-LightningNetwork',
          'cryptoCode' => 'BTC',
          'currency' => 'BTC',
          'destination' => $bolt11,
          'paymentLink' => $bolt11 !== '' ? 'lightning:' . $bolt11 : '',
          'rate' => (string)($invoice['exchange_rate'] ?? '1'),
          'paymentMethodPaid' => $settled ? (string)($invoice['amount'] ?? '0') : '0',
          'totalPaid' => $settled ? (string)($invoice['amount'] ?? '0') : '0',
          'due' => $settled ? '0' : (string)($invoice['amount'] ?? '0'),
          'amount' => (string)($invoice['amount'] ?? '0'),
          'networkFee' => '0',
          'payments' => [],
          'activated' => true,
      ],
  ]);
  ```
  `$invoice['amount']` is stored in the **invoice's request currency** (e.g. `25.00` for a €25.00 EUR order, or `2500` for a 2,500 SAT order). Returning `'currency' => 'BTC'` with `'amount' => '25.00'` tells any Greenfield API client (such as BTCPay for WooCommerce or custom storefront integrations) that the customer owes or paid **25.00 BTC** (~$2.5 million) instead of `0.00025000 BTC`!
- **How to Fix:**
  Derive the satoshi amount from the BOLT-11 invoice (`\Cashu\Bolt11::amountSats($bolt11)`) or `amount_sats` (when `mint_unit === 'sat'`) and format it as an 8-decimal BTC string:
  ```php
  $sats = $bolt11 !== '' ? \Cashu\Bolt11::amountSats($bolt11) : 0;
  if ($sats <= 0 && strtolower((string)($invoice['mint_unit'] ?? 'sat')) === 'sat') {
      $sats = (int)($invoice['amount_sats'] ?? 0);
  }
  $btcAmount = number_format($sats / 100000000, 8, '.', '');
  ```
  Then use `$btcAmount` for `'amount'`, `'due'`, `'totalPaid'`, and `'paymentMethodPaid'`.

---

### [CPS-06] HIGH: Uncaught `ValueError` in `ExchangeRates` When Small BTC Amounts (`< 0.0001 BTC`) or Floats Use Scientific Notation (`'5.0E-5'`)

- **Location:**
  - `includes/rates.php` lines 529–638 (`convertToMintUnit()`, `toBtc()`, `fromBtc()`, `toSmallestUnit()`)
  - `includes/invoice.php` lines 116–123 (`Invoice::create()`)
- **Priority / Severity:** **HIGH (P1 — Invoice Creation Crash / HTTP 500)**
- **Detailed Problem & Failure Scenario:**
  1. In PHP 8.x, `bcadd()`, `bcsub()`, `bcmul()`, `bcdiv()`, and `bccomp()` throw a fatal `ValueError: bc*(): Argument #N is not well-formed` whenever any argument is in scientific notation (e.g., `'5.0E-5'` or `'1e-5'`).
  2. In PHP, any `float` with magnitude `< 0.0001` (for example, `0.00005` BTC = 5,000 sats) is automatically formatted as `'5.0E-5'` when coerced to `string`!
  3. When a Greenfield API caller submits `{"amount": 0.00005, "currency": "BTC"}` (or `"amount": "5e-5"`):
     - `Invoice::create()` passes `$options['amount']` to `ExchangeRates::convertToMintUnit(string $amount, ...)` (`includes/invoice.php:116`), coercing `0.00005` to `'5.0E-5'`.
     - `ExchangeRates::toBtc('5.0E-5', 'BTC')` returns `'5.0E-5'`.
     - `ExchangeRates::fromBtc('5.0E-5', 'SAT')` calls `bcmul('5.0E-5', '100000000', self::SCALE)` (`includes/rates.php:601`), throwing an uncaught `ValueError` and crashing checkout with HTTP 500!
  4. The same crash occurs in `toBtc()` (`includes/rates.php:586`) and `fromBtc()` (`includes/rates.php:613`) if `(string)$btcPrice` ever formats in scientific notation.
- **How to Fix:**
  Add a decimal normalization helper in `ExchangeRates` and apply it at entry to `convertToMintUnit()`, `toBtc()`, `fromBtc()`, and `toSmallestUnit()`:
  ```php
  private static function normalizeDecimal(string|int|float $value): string {
      if (is_int($value)) {
          return (string)$value;
      }
      $str = trim((string)$value);
      if ($str === '' || !is_numeric($str)) {
          throw new InvalidArgumentException("Invalid numeric amount: {$str}");
      }
      if (stripos($str, 'e') !== false) {
          $str = rtrim(rtrim(number_format((float)$str, self::SCALE, '.', ''), '0'), '.');
          if ($str === '' || $str === '-') {
              $str = '0';
          }
      }
      return $str;
  }
  ```

---

### [CPS-07] HIGH: Zero-Decimal Fiat Currencies (`JPY`) Multiplied by 100 in `ExchangeRates` & `Config::unitDecimals`, `msat` Balance Rounded Up to `sats`, and Sub-Cent Truncation on Same-Unit Invoices

- **Location:**
  - `includes/rates.php` lines 537–560 (`convertToMintUnit()`), lines 599–638 (`fromBtc()`, `toSmallestUnit()`), and lines 774–776 (`convertMintUnitToSats()`)
  - `includes/config.php` lines 435–444 (`Config::unitDecimals()`)
  - `admin.php` lines 4616–4621 (`unitDecimals()`)
- **Priority / Severity:** **HIGH (P1 — 100x Overcharging on JPY / Spendable Balance Overstatement)**
- **Detailed Problem & Failure Scenario:**
  1. **Zero-decimal currencies (`JPY`) multiplied by 100 (`includes/rates.php:619-638`, `includes/config.php:435-444`):**
     `KrakenProvider::PAIR_MAP` (`includes/rates.php:133`), `CoinGeckoProvider`, and `Cashu\Unit::KNOWN_UNITS` (`CashuWallet.php:1647`) all support `JPY` (`jpy`). However, `Config::unitDecimals()` and `ExchangeRates::toSmallestUnit()` assume every currency other than `sat`, `sats`, `msat`, `btc` has **2 decimal places** (`* 100`):
     ```php
     public static function unitDecimals(string $unit): int {
         return match (strtolower($unit)) {
             'sat', 'sats', 'msat' => 0,
             'btc' => 8,
             default => 2,
         };
     }
     ```
     If a store uses a `jpy` mint and creates a `1000 JPY` invoice, `toSmallestUnit('1000', 'jpy')` multiplies it by `100` to `100,000`!
  2. **`convertMintUnitToSats()` rounds `msat` UP (`includes/rates.php:774-776`):**
     ```php
     if ($mintUnit === 'MSAT') {
         return (int)ceil($amountInSmallestUnit / 1000);
     }
     ```
     Converting a wallet balance of `1,500 msat` to `sats` returns `2 sats` (`2,000 msat`), which exceeds the actual spendable balance (contrast with `LightningAddress::mintAmountToSats()` at `includes/lightning_address.php:185`, which uses `intdiv($amount, 1000)`).
  3. **Same-currency fractional amounts truncated instead of rounded up (`includes/rates.php:537-540`):**
     ```php
     if ($requestCurrency === $mintUnit) {
         return self::toSmallestUnit($amount, $mintUnit);
     }
     ```
     Unlike cross-currency conversion at line 559 (`self::toSmallestUnit($mintAmount, $mintUnit, true)`), line 539 omits `$roundUp = true`, truncating fractional cents (e.g., `$10.125` -> `1012` cents instead of `1013` cents).
  4. **Precision loss in `convertMintUnitToSats()` & `convertSatsToMintUnit()` (`includes/rates.php:787, 824`):**
     Uses scale `8` instead of `self::SCALE` (`16`) in `bcmul($btcAmount, '100000000', 8)` and `bcdiv((string)$sats, '100000000', 8)`.
- **How to Fix:**
  1. In `Config::unitDecimals()`, `admin.php` `unitDecimals()`, and `ExchangeRates::toSmallestUnit()`, treat zero-decimal fiat currencies (`'jpy'`, `'krw'`) as `0` decimals (multiplier `'1'`).
  2. In `ExchangeRates::convertToMintUnit()` line 539, pass `$roundUp = true`: `return self::toSmallestUnit($amount, $mintUnit, true);`.
  3. In `ExchangeRates::convertMintUnitToSats()` line 775, use `intdiv($amountInSmallestUnit, 1000)` instead of `(int)ceil($amountInSmallestUnit / 1000)`, and use `self::SCALE` at lines 787 and 824.

---

### [CPS-08] HIGH: Incomplete `KNOWN_UNITS` in `StoreSafety` & `Invoice::scanStrandedNamespaces` Fails to Block Store Deletion or Identify Stranded Funds for `gbp`, `usdc`, `btc`, `cad`, `jpy`, `aud`, `chf`

- **Location:**
  - `includes/store_safety.php` line 24 and lines 173–197 (`StoreSafety::strandedForStore()`)
  - `includes/invoice.php` lines 1787–1815 (`Invoice::scanStrandedNamespaces()`)
- **Priority / Severity:** **HIGH (P1 — Permanent Seed & Fund Destruction on Store Deletion)**
- **Detailed Problem & Failure Scenario:**
  1. `StoreSafety::KNOWN_UNITS` (`includes/store_safety.php:24`) and `Invoice::scanStrandedNamespaces()` (`includes/invoice.php:1787`) both hardcode only 5 units:
     ```php
     private const KNOWN_UNITS = ['sat', 'usd', 'eur', 'usdt', 'msat'];
     ```
  2. Meanwhile, `Cashu\Unit::KNOWN_UNITS` (`CashuWallet.php:1647`) and `Config::validUnit()` (`includes/config.php:423`) support `'gbp'`, `'usdc'`, `'btc'`, `'cad'`, `'jpy'`, `'aud'`, `'chf'`, and any alphanumeric unit up to 16 chars.
  3. Look at what happens in `StoreSafety::strandedForStore()` (`includes/store_safety.php:173-197`) when a store previously used a `gbp` or `usdc` mint:
     - To associate a `wallet_id` namespace hash in `cashu_proofs` or `cashu_pending_operations` with `$storeId`, `strandedForStore()` computes `WalletStorage::deriveWalletId($url, $unit, $acct)` for `(candidate_urls × KNOWN_UNITS)`.
     - Because `'gbp'` and `'usdc'` are missing from `KNOWN_UNITS`, `$info = $lookup[$wid] ?? null;` evaluates to **`null`**!
     - If the store has a pending `mint` operation in `cashu_pending_operations` for that `gbp`/`usdc` mint (where `$amount === 0` in `cashu_proofs`, `$pendingOps > 0`), lines 191–195 execute:
       ```php
       if ($amount > 0) {
           $stranded[] = [...];
       }
       ```
       Because `$info` is `null` and `$amount` is `0`, `strandedForStore()` **completely ignores the pending operation**, and `StoreSafety::deletionBlockers()` **allows the store and its seed phrase to be permanently deleted**!
     - Even when `$amount > 0`, `Invoice::scanStrandedNamespaces()` cannot match the `wallet_id` hash and displays `"unknown mint — Mint not in history"`, forcing Marta to remember and manually type the old mint URL and unit!
- **How to Fix:**
  In both `StoreSafety` (`includes/store_safety.php`) and `Invoice::scanStrandedNamespaces()` (`includes/invoice.php`), include all of `\Cashu\Unit::KNOWN_UNITS` **plus** all distinct units ever recorded in the database (`SELECT DISTINCT mint_unit FROM invoices UNION SELECT DISTINCT unit FROM transfers UNION SELECT DISTINCT unit FROM store_mints UNION SELECT DISTINCT unit FROM cashu_restore_progress`):
  ```php
  $units = array_values(array_unique(array_merge(
      \Cashu\Unit::KNOWN_UNITS,
      ['usdt', 'usdc'],
      array_column(Database::fetchAll("SELECT DISTINCT LOWER(mint_unit) AS u FROM invoices WHERE mint_unit IS NOT NULL"), 'u'),
      array_column(Database::fetchAll("SELECT DISTINCT LOWER(unit) AS u FROM transfers WHERE unit IS NOT NULL"), 'u'),
      array_column(Database::fetchAll("SELECT DISTINCT LOWER(unit) AS u FROM store_mints WHERE unit IS NOT NULL"), 'u'),
      array_column(Database::fetchAll("SELECT DISTINCT LOWER(unit) AS u FROM cashu_restore_progress WHERE unit IS NOT NULL"), 'u')
  )));
  ```
  And in `StoreSafety::strandedForStore()` line 191, change `if ($amount > 0)` to `if ($amount > 0 || $pendingOps > 0)` so an unidentified namespace with a pending operation still blocks deletion when `count($stores) === 1`.

---

### [CPS-09] HIGH: `Donation::sendToDonationSink()` Swaps Every Unspent Proof in the Wallet Instead of Selecting Only What Is Needed

- **Location:** `includes/lightning_address.php` lines 738–808 (`Donation::sendToDonationSink()`)
- **Priority / Severity:** **HIGH (P1 — Excessive Fee Burn & Swap Failure on Large Wallets)**
- **Detailed Problem & Failure Scenario:**
  Look at `Donation::sendToDonationSink()` (`includes/lightning_address.php:738-808`):
  ```php
  $proofs = $wallet->getStorage()->getProofsAsObjects(ProofState::UNSPENT);
  $balance = Wallet::sumProofs($proofs);
  if ($balance < $amount) {
      return ['success' => false, 'donated' => 0, 'error' => 'Insufficient balance for donation'];
  }
  ...
  $fee = $wallet->calculateFee($proofs);
  if ($fee > $amount) {
      return ['success' => false, 'donated' => 0, 'error' => 'Fee exceeds donation amount'];
  }
  $sendAmount = $amount - $fee;
  ...
  $result = $wallet->split($proofs, $amount);
  ```
  Unlike `prepareMeltForAccount()` (line 298) and `Invoice::exportBalance()` (`includes/invoice.php:1330`), which call `$wallet->selectProofsWithFees()`, `Donation::sendToDonationSink()` passes **every single `UNSPENT` proof in the wallet** (`$proofs`) to `calculateFee($proofs)` and `$wallet->split($proofs, $amount)`!
  - If the store has 150 unspent proofs remaining after a partial withdrawal or token export, `calculateFee($proofs)` computes the NUT-02 input fee across all 150 proofs instead of the 1–2 proofs needed for the 1% donation, and `$wallet->split($proofs, $amount)` swaps the entire wallet (or fails outright if `count($proofs)` exceeds the mint's `max_inputs` limit).
- **How to Fix:**
  Select only the proofs needed to cover `$amount` before computing the fee and splitting:
  ```php
  try {
      $selectedProofs = $wallet->selectProofsWithFees($proofs, $amount);
      $sendAmount = $amount;
  } catch (\Cashu\InsufficientBalanceException $e) {
      // Fallback: deduct fee from donation if total balance equals $amount
      $selectedProofs = $wallet->selectProofs($proofs, $amount);
      $fee = $wallet->calculateFee($selectedProofs);
      if ($fee >= $amount) {
          return ['success' => false, 'donated' => 0, 'error' => 'Fee exceeds donation amount'];
      }
      $sendAmount = $amount - $fee;
  }
  $result = $wallet->split($selectedProofs, $sendAmount);
  ```

---

### [CPS-10] HIGH: Overstated Fee & Premature Transfer Completion When `Wallet::melt()` Returns `changeRecoveryPending = true`, and `fee = 0` on Background-Reconciled Withdrawals

- **Location:**
  - `includes/lightning_address.php` lines 339–355 (`LightningAddress::executeMelt()`) and lines 544–563 (`LightningAddress::autoMeltAccount()`)
  - `includes/background_runner.php` lines 640–645 (`BackgroundRunner::reconcileWithdrawal()`)
  - `cashu-wallet-php/CashuWallet.php` lines 7808–7820, 7848–7883, 10028–10039
- **Priority / Severity:** **HIGH (P1 — Corrupt Transfer Fee Accounting & Premature Lock Release)**
- **Detailed Problem & Failure Scenario:**
  1. In `Wallet::melt()` (`CashuWallet.php:7848-7883`), once the mint reports a Lightning payment is `PAID`, `finalizePaidMelt()` catches any error during `recoverMeltChange()`, `unblindMeltChange()`, or `finalizePendingSpend()` and returns:
     `['paid' => true, 'preimage' => ..., 'change' => [], 'changeRecoveryPending' => true]`
     while keeping the `melt:<quote>` journal open in `cashu_pending_operations` so `recoverPendingMelts()` can recover the change later.
  2. In `LightningAddress::executeMelt()` (`includes/lightning_address.php:339-349`), `$result['changeRecoveryPending']` is ignored:
     ```php
     if ($result['paid']) {
         $inputAmount = Wallet::sumProofs($selectedProofs);
         $changeAmount = Wallet::sumProofs($result['change'] ?? []);
         $fee = $inputAmount - $changeAmount - $meltQuote->amount;
         Transfer::complete($transferId, $meltQuote->amount, max(0, $fee), $result['preimage'] ?? null);
     ```
     Because `$result['change']` is `[]` while change recovery is pending, `$changeAmount` is `0`, so `$fee` is calculated as `$inputAmount - $meltQuote->amount` (recording the **entire unrecovered change amount as a fee** on the transfer row!) and `Transfer::complete()` marks the transfer `completed` while its inputs are still `PENDING` in `cashu_pending_operations`.
  3. Conversely, when a pending withdrawal is settled asynchronously by `BackgroundRunner::reconcileWithdrawal()` (`includes/background_runner.php:640-645`):
     ```php
     if ($entry === null) {
         Transfer::complete($row['id'], (int)$row['amount'], (int)$row['fee'], $row['preimage'] ?? null);
     ```
     Because `$row['fee']` was initialized to `0` when the transfer was opened (`includes/lightning_address.php:304`), every background-reconciled withdrawal permanently records **`fee = 0`**!
- **How to Fix:**
  1. In `Wallet::payToLightningAddress()` (`CashuWallet.php:10028-10039`), propagate `'changeRecoveryPending' => !empty($result['changeRecoveryPending'])`.
  2. In `LightningAddress::executeMelt()`, if `!empty($result['changeRecoveryPending'])`, leave the `transfers` row in `pending` state (recording `detail = 'Payment sent; recovering change from mint'`) so `BackgroundRunner::reconcileWithdrawal()` completes it after `recoverPendingMelts()` finishes.
  3. Store the actual fee or input/change amounts when finalizing the melt journal so `reconcileWithdrawal()` can compute the true fee.

---

### [CPS-11] HIGH: Token Transfers From Former or Backup Mints Query the Wrong Mint in `check_proofs_spent`, `reconcileTokenTransfer()`, `check_transfer`, and `reclaimExportedProofs()`

- **Location:**
  - `admin.php` lines 1744–1820 (`case 'check_proofs_spent':`) and lines 1179–1208 (`case 'check_transfer':`)
  - `includes/background_runner.php` lines 700–767 (`BackgroundRunner::reconcileTokenTransfer()`)
  - `includes/invoice.php` lines 1391–1556 (`checkProofStatesBySecrets()`, `accountHoldingSecrets()`, `markProofsSpent()`, `reclaimExportedProofs()`)
- **Priority / Severity:** **HIGH (P1 — Broken Claim Detection, Infinite Donation Retries & Misleading Reclaim Status)**
- **Detailed Problem & Failure Scenario:**
  1. **`admin.php` `check_proofs_spent` (lines 1763–1785) hardcodes the primary mint:**
     When Marta exports a token (`admin.php:2235`), it exports from `$account = Invoice::getFundsAccount($storeId, $amount)` (which may be a backup mint). While the QR code modal is open, the browser polls `action=check_proofs_spent` every 5 seconds. However, `check_proofs_spent` calls `Invoice::getWalletInstance($storeId)` and `new \Cashu\MintClient($store['mint_url'])` — querying the **primary** mint instead of the backup mint! A mint returns `UNSPENT` for unknown secrets, so the QR modal never detects that the backup-mint token was claimed, and if the primary mint is offline, every poll fails.
  2. **`reconcileTokenTransfer()`, `check_transfer`, and `reclaimExportedProofs()` ignore `$row['mint_url']` and `$row['unit']`:**
     Even though every row in `transfers` stores `mint_url` and `unit`, `reconcileTokenTransfer()` (`includes/background_runner.php:706`) and `check_transfer` (`admin.php:1193`) call `Invoice::checkProofStatesBySecrets($row['store_id'], $secrets)` without passing `$row['mint_url']` or `$row['unit']`.
     - Inside `Invoice::accountHoldingSecrets()` (`includes/invoice.php:1424-1452`), it only searches `Config::getStoreWalletAccounts($storeId)` (currently enabled accounts). If the store switched primary mints or disabled a backup mint after exporting a token or creating a donation, `accountHoldingSecrets()` falls back to the **current primary mint**!
     - The new primary mint returns `UNSPENT` for all secrets from the old mint. As a result:
       - `reconcileTokenTransfer()` (`includes/background_runner.php:755`) believes a redeemed donation token is still `UNSPENT` and **re-POSTs the spent token to the donation sink on every cron run forever**!
       - `check_transfer` (`admin.php:1203`) tells Marta `"Nobody has cashed this in yet"` even if it was already redeemed!
- **How to Fix:**
  1. Replace lines 1761–1808 of `admin.php` (`case 'check_proofs_spent':`) with calls to `Invoice::checkProofStatesBySecrets($storeId, $secrets)` and `Invoice::markProofsSpent($storeId, $secrets)`.
  2. Add optional `?string $mintUrl = null, ?string $unit = null` parameters to `Invoice::checkProofStatesBySecrets()`, `Invoice::markProofsSpent()`, and `Invoice::reclaimExportedProofs()`, and pass `$row['mint_url']` and `$row['unit']` from `BackgroundRunner::reconcileTokenTransfer()`, `admin.php` `check_transfer`, and `admin.php` `reclaim_transfer`.

---

## Part 2: `cashu-wallet-php` Library Audit (`cashu-wallet-php/CashuWallet.php`)

### [CW-01] CRITICAL: `replacePendingMintPlan()`, `finalizePendingMint()`, and `recoverPendingOutputs()` Never Recover Outputs From `replaced_plans` and Reject Valid Late Mint Signatures

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 5528–5585 (`replacePendingMintPlan()`, `finalizePendingMint()`), lines 6450–6505 (`recoverPendingMints()`, `settleMintJournalByRestore()`), lines 7466–7497 (`replanRejectedMint()`), and lines 7572–7630 (`mint()`)
- **Priority / Severity:** **CRITICAL (P0 — Permanent Loss of Minted Tokens Under Concurrent / Retried Minting)**
- **Detailed Problem & Failure Scenario:**
  1. Process A calls `$wallet->mint($quoteId, $amount)`. `preparePendingSpend()` creates journal `mint:$quoteId` with Plan P1 (`counter_start = 0, amounts = [64, 32, 4]`) and sends `POST /v1/mint/bolt11`. Suppose the HTTP request stalls or takes several seconds behind a reverse proxy.
  2. Process B (or a retry) enters `replanRejectedMint()` (line 7466) before Process A's request commits at the mint:
     - At that moment, `recoverPendingOutputs(P1)` returns `[]`.
     - `replacePendingMintPlan()` (line 5528) replaces P1 with Plan P2 (`counter_start = 3, amounts = [64, 32, 4]`), moving P1 into `$plan['replaced_plans']` (lines 5539–5545):
       ```php
       $replaced[] = [
           'keyset_id' => $rejectedPlan['keyset_id'],
           'counter_start' => $rejectedPlan['counter_start'],
           'count' => $rejectedPlan['count'],
           'at' => time(),
       ];
       ```
     - Notice two fatal defects here:
       1. `replaced_plans` omits `'amounts' => $rejectedPlan['amounts']`!
       2. **Nothing in `CashuWallet.php` ever reads `replaced_plans`!**
  3. Now Process A's in-flight `POST /v1/mint/bolt11` completes at the mint, signing Plan P1 (`counter_start = 0..2`) and marking the quote `ISSUED`:
     - **If Process A receives the HTTP response:** Process A unblinds the valid `$proofs` for P1 and calls `$this->storage->finalizePendingMint($quoteId, P1, $proofs)` (line 7629). Inside `finalizePendingMint()` (line 5572), `$pending['data'] !== $plan` is `true` (because the DB now holds P2!). `finalizePendingMint()` **throws `CashuException('Mint completion does not match the pending plan')` and discards the valid unblinded `$proofs` without calling `storeProofs($proofs)`!**
     - **If Process A's HTTP response was lost and `recoverPendingMints()` runs (line 6450):** `recoverPendingMints()` sees `$quote->isIssued()` and calls `$this->recoverPendingOutputs($op['data'])`, which queries NUT-09 `/v1/restore` **only for Plan P2 (`counter_start = 3..5`)**, ignoring `$op['data']['replaced_plans']`. Because the mint signed P1 (`0..2`), `recoverPendingOutputs(P2)` returns `[]`, leaving the journal stuck forever (or deleting it in `settleMintJournalByRestore()` at line 6489).
- **How to Fix:**
  1. In `WalletStorage::replacePendingMintPlan()` (`cashu-wallet-php/CashuWallet.php:5540-5545`), include `'amounts' => array_values($rejectedPlan['amounts'] ?? [])` in `$replaced[]`.
  2. In `WalletStorage::finalizePendingMint()` (`cashu-wallet-php/CashuWallet.php:5567-5585`), **always store `$proofs` first** (`$this->storeProofs($proofs, $quoteId)` is idempotent via `ON CONFLICT DO NOTHING`) and delete the `mint:$quoteId` journal whether `$plan` matches the current plan or one of its `replaced_plans`:
     ```php
     public function finalizePendingMint(string $quoteId, array $plan, array $proofs): void {
         $id = "mint:$quoteId";
         $savepoint = $this->beginWrite();
         try {
             if (!empty($proofs)) {
                 $this->storeProofs($proofs, $quoteId);
             }
             $pending = $this->getPendingOperationById($id);
             if ($pending !== null) {
                 $this->deletePendingOperation($id);
             }
             $this->commitWrite($savepoint);
         } catch (\Throwable $e) {
             $this->rollbackWrite($savepoint);
             throw $e;
         }
     }
     ```
  3. In `Wallet::recoverPendingOutputs(array $data)` (`cashu-wallet-php/CashuWallet.php:6686`), if the active plan returns `[]` and `!empty($data['replaced_plans'])`, iterate through `$data['replaced_plans']` and attempt NUT-09 restore for each replaced plan.

---

### [CW-02] CRITICAL: `Wallet::resolveSwapJournal()` Marks Untrusted `imported_secrets` as `UNSPENT` or `SPENT` in Wallet Storage When `$direct = false`

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 6559–6573 (`recoverPendingSwaps()`) and lines 6631–6676 (`resolveSwapJournal()`)
- **Priority / Severity:** **CRITICAL (P0 — Wallet Poisoning / False Balance Inflation / Payment Request Bypass)**
- **Detailed Problem & Failure Scenario:**
  As detailed in **[CPS-01]**, when `receive($tokenString)` or `swap()` is called with external proofs not already present in `cashu_proofs`, `WalletStorage::preparePendingSpend()` (lines 5295–5306) inserts them into `cashu_proofs` as `PENDING` and records their secrets in `$data['imported_secrets']`.
  When `resolveSwapJournal()` settles a failed swap that produced zero signed outputs (`$recovered === []`), lines 6663–6670 execute:
  ```php
  foreach ($states as $secret => $state) {
      if ($direct && isset($imported[$secret])) {
          $outcome[$secret] = null;
      } else {
          $outcome[$secret] = $state === ProofState::SPENT ? ProofState::SPENT : ProofState::UNSPENT;
      }
  }
  ```
  When called from `recoverPendingSwaps()` (`$direct = false`), `if ($direct && isset($imported[$secret]))` is `false`:
  - If the external token had an invalid signature `C` (rejected by `/v1/swap` with `PROOF_VERIFICATION_FAILED`), `/v1/checkstate` still reports `UNSPENT`, so the forged external proof is promoted to **`ProofState::UNSPENT`** in `cashu_proofs`. Because `selectProofsWithFees()` sorts proofs descending by amount, a large forged proof will be selected on every subsequent swap/melt, breaking all outgoing payments from the wallet!
  - If the external token was spent by the sender elsewhere, it is marked **`ProofState::SPENT`** in `cashu_proofs`, fooling `PaymentRequest::receiptState()` into marking the payment request paid.
- **How to Fix:**
  In `Wallet::resolveSwapJournal()` (`cashu-wallet-php/CashuWallet.php:6664`), replace `if ($direct && isset($imported[$secret]))` with `if (isset($imported[$secret]))`.

---

### [CW-03] HIGH: `WalletStorage::finalizePendingSpend()` and `settlePendingSpend()` Skip `storeProofs($outputProofs)` If Journal Row Was Removed or `assertOwnsReservedInputs()` Throws

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 5341–5480 (`finalizePendingSpend()`, `settlePendingSpend()`)
- **Priority / Severity:** **HIGH (P1 — Loss of Newly Swapped Proofs or Melt Change Proofs)**
- **Detailed Problem & Failure Scenario:**
  Look at `WalletStorage::finalizePendingSpend()` (`cashu-wallet-php/CashuWallet.php:5347-5368`):
  ```php
  $operation = $this->getPendingOperationById($id);
  if ($operation === null) {
      // A completed/deleted journal no longer owns any inputs or outputs.
      $this->commitWrite($savepoint);
      return;
  }
  $inputSecrets = self::uniqueSecrets($inputSecrets);
  $this->assertOwnsReservedInputs($id, $inputSecrets);

  if (!empty($outputProofs)) {
      $this->storeProofs($outputProofs);
  }
  ```
  When `finalizePendingSpend()` or `settlePendingSpend()` is called with non-empty `$outputProofs`, the mint has **already consumed the inputs and issued valid bearer signatures** (`$outputProofs` / `$changeProofs`).
  - If the journal row `$id` was already cleaned up or deleted (`$operation === null`), `finalizePendingSpend()` returns at line 5353 **without storing `$outputProofs`**!
  - If any input proof's state in `cashu_proofs` was touched (e.g., marked `SPENT` by a concurrent state sync or admin check), `assertOwnsReservedInputs()` throws `CashuException("Pending spend no longer owns a reserved input...")` at line 5362 **before** `$this->storeProofs($outputProofs)` is reached!
- **How to Fix:**
  In both `finalizePendingSpend()` and `settlePendingSpend()`, store `$outputProofs` **first** (`if (!empty($outputProofs)) { $this->storeProofs($outputProofs); }`) before checking `$operation === null` or calling `assertOwnsReservedInputs()`. Since `storeProofs()` uses `INSERT INTO cashu_proofs ... ON CONFLICT(wallet_id, secret) DO NOTHING`, storing valid unblinded proofs in hand is always safe and idempotent.

---

### [CW-04] HIGH: Unchunked `IN (...)` Clauses in `WalletStorage` Exceed SQLite's 999 Bound-Variable Limit After a 1,000-Proof Swap Succeeds at the Mint

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 4938–5006 (`updateProofsState()`, `getProofsStatesBySecrets()`, `deleteProofs()`) and lines 5080–5117 (`getProofsBySecretsAsObjects()`)
- **Priority / Severity:** **HIGH (P1 — Rollback of Swapped Proofs After Mint Consumes Inputs)**
- **Detailed Problem & Failure Scenario:**
  1. `TokenSerializer::MAX_PROOFS` is `1000` (line 3438).
  2. When receiving or swapping a 1,000-proof token:
     - `preparePendingSpend()` succeeds because it inserts/updates proofs one at a time in a `foreach` loop (lines 5281–5309).
     - `POST /v1/swap` succeeds at the mint, spending all 1,000 input proofs and returning the new signatures.
     - `submitPreparedSwap()` unblinds the new proofs and calls `finalizePendingSpend()`, which calls `assertOwnsReservedInputs()` -> `getProofsStatesBySecrets($inputSecrets)` (1,001 SQLite bound parameters) and `updateProofsState($inputSecrets, ...)` (1,003 SQLite bound parameters).
  3. Unlike `transitionProofStates()` (line 4679, which chunks `$secrets` into batches of `400`), `getProofsStatesBySecrets()`, `updateProofsState()`, `deleteProofs()`, and `getProofsBySecretsAsObjects()` build a single `IN (?, ?, ...)` query with all 1,000 secrets at once.
  4. On SQLite builds compiled with `SQLITE_MAX_VARIABLE_NUMBER = 999`, SQLite throws `PDOException: SQLSTATE[HY000]: General error: 1 too many SQL variables`, **rolling back `storeProofs($newProofs)` after the mint has already spent the inputs**, and causing `recoverPendingSwaps()` to fail with the exact same `PDOException` on every recovery attempt!
- **How to Fix:**
  Chunk `$secrets` using `array_chunk($secrets, 400)` in `updateProofsState()`, `getProofsStatesBySecrets()`, `deleteProofs()`, and `getProofsBySecretsAsObjects()` (matching `transitionProofStates()` at line 4679).

---

### [CW-05] HIGH: Heuristic Binary Detection in `CBOR::encode()` and Unpadded `hex2bin()` on DLEQ Scalars in `TokenSerializer::serializeV4()` Produce Corrupt `cashuB` (V4) Tokens

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 3086–3092, 3303–3313 (`CBOR::encode()`, `CBOR::isBinaryString()`) and lines 3393–3399 (`TokenSerializer::serializeV4()`)
- **Priority / Severity:** **HIGH (P1 — Corrupt / Unredeemable Exported V4 Tokens)**
- **Detailed Problem & Failure Scenario:**
  1. **Unpadded `hex2bin()` on DLEQ scalars (`CashuWallet.php:3393-3399`):**
     ```php
     if ($includeDleq && $proof->dleq !== null && $proof->dleq->r !== null) {
         $p['d'] = [
             'e' => hex2bin($proof->dleq->e),
             's' => hex2bin($proof->dleq->s),
             'r' => hex2bin($proof->dleq->r)
         ];
     }
     ```
     As noted in `Crypto::verifyDleq()` (lines 1199–1202), some implementations strip leading zeros from 32-byte hex scalars, yielding a 63-char (odd-length) hex string. Passing an odd-length hex string to `hex2bin()` returns `false`, which `CBOR::encode()` serializes as a **CBOR boolean (`0xf4`)** instead of a 32-byte byte string! Any wallet (including `TokenSerializer::deserializeV4()` at line 3631) rejects the resulting `cashuB` token with `Invalid V4 token: malformed DLEQ proof`.
  2. **Heuristic `isBinaryString()` in `CBOR::encode()` (`CashuWallet.php:3086-3092, 3303-3313`):**
     `CBOR::encode()` guesses whether a PHP string is a CBOR Byte String (Major Type 2) or Text String (Major Type 3) by checking `!mb_check_encoding($value, 'UTF-8') || self::isBinaryString($value)`:
     - If an 8-byte binary keyset ID (`i`) or 32-byte binary DLEQ scalar (`e`, `s`, `r`) happens to contain only printable ASCII / valid UTF-8 bytes (no byte in `{0..8, 11..12, 14..31}`), `CBOR::encode()` encodes it as a **CBOR Text String (Major Type 3)** instead of a **CBOR Byte String (Major Type 2)**, violating NUT-00 V4 CBOR schema and causing strict wallets (`cdk`, `cashu-ts`, `nutshell`) to reject the token!
- **How to Fix:**
  1. Introduce a thin wrapper class `final class CBORBytes { public function __construct(public readonly string $bytes) {} }` and check `if ($value instanceof CBORBytes) { return self::encodeByteString($value->bytes); }` at the top of `CBOR::encode()`.
  2. In `TokenSerializer::serializeV4()`, left-pad `e`, `s`, and `r` to 64 hex chars (`str_pad($hex, 64, '0', STR_PAD_LEFT)`) before `hex2bin()`, and wrap `i`, `c`, `d.e`, `d.s`, and `d.r` in `new CBORBytes(...)`.

---

### [CW-06] HIGH: `loadRestoreKeys()` and `ensureKeysetKeys()` Do Not Verify V2 (`01...`) Keyset IDs When Keys Are Loaded Outside `loadMint()`

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 7030–7061 (`ensureKeysetKeys()`) and lines 9518–9538 (`loadRestoreKeys()`)
- **Priority / Severity:** **HIGH (P1 — Bypass of Keyset ID Authenticity Check During Seed Restore)**
- **Detailed Problem & Failure Scenario:**
  1. During `loadMint()` (lines 6978–6999), the wallet verifies that every keyset's public keys hash to its advertised keyset ID (`$expectedId = $keyset->deriveExpectedId()`), preventing a compromised or malicious mint from substituting wrong public keys under an existing keyset ID.
  2. However, `Wallet::loadRestoreKeys()` (lines 9518–9538, used during `runRestore()` across all units) only calls `Keyset::deriveKeysetId($keys)` inside `ensureKeysetKeys()` when `strlen($keysetId) === 16`, and **never** verifies V2 (`01...`, 66-char) keyset IDs!
  3. Similarly, in `Wallet::ensureKeysetKeys()` (lines 7030–7061), V2 keyset verification only runs if `$keysetId` is already present in `$this->keysets` (which only holds the wallet's single unit after `loadMint()`).
- **How to Fix:**
  In both `loadRestoreKeys()` and `ensureKeysetKeys()`, construct a `Keyset` with the metadata (`unit`, `input_fee_ppk`, `final_expiry`) from `/v1/keysets` (or `/v1/keys/{id}` response) and verify `$keyset->deriveExpectedId() === strtolower($keysetId)` before storing `$this->keys[$keysetId]`.

---

### [CW-07] MEDIUM-HIGH: `Bolt11::amountSats()` Splits on the First `'1'` Instead of the Last `'1'` and Does Not Validate the Bech32 Boundary

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 10283–10311 (`Bolt11::amountSats()`)
- **Priority / Severity:** **MEDIUM-HIGH (P2 — BOLT-11 Amount Verification Mismatch)**
- **Detailed Problem & Failure Scenario:**
  ```php
  if (!preg_match('/^ln(bcrt|tbs|bc|tb)(\d*)([munp]?)1/', $bolt11, $m)) {
      return 0;
  }
  ```
  Per BIP-173 / BOLT-11, the separator between the human-readable part (HRP) and the Bech32 data section is the **last** `'1'` in the string (`strrpos($bolt11, '1')`), not the first `'1'`. On a crafted string containing multiple `'1'` characters before the final separator (e.g., `lnbc100n1000m1...`), `preg_match` stops at the first `'1'` (`100n` = 10 sats) while a compliant BOLT-11 parser splitting on the last `'1'` parses the full HRP. Also, `(int)$sats` at line 10310 silently clamps huge numbers to `PHP_INT_MAX`.
- **How to Fix:**
  Locate the separator via `$sep = strrpos($bolt11, '1')`, verify that the data part after `$sep` consists solely of valid Bech32 characters (`qpzry9x8gf2tvdw0s3jn54khce6mua7l`) of length `>= 110`, and match `substr($bolt11, 0, $sep)` against `/^ln(bcrt|tbs|bc|tb)(?:(\d+)([munp]?))?$/`.

---

### [CW-08] MEDIUM: Uncaught `\TypeError` Exceptions on Malformed Mint Responses or CBOR Inputs Under `strict_types=1`

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 1149–1151 (`Crypto::unblindSignature()`), lines 2305–2312 & 2423–2453 (`PaymentRequest::fromArray()`, `Transport::fromArray()`), lines 6811–6824, 6863–6873, 7608–7618 (`mint()`, `submitPreparedSwap()`, `verifyChangeSignatureDleq()`), lines 6932–6939 (`loadMint()`), and lines 7133–7145 (`calculateFee()`)
- **Priority / Severity:** **MEDIUM (P2 — Uncaught `\TypeError` Crashes Instead of `CashuException`)**
- **Detailed Problem & How to Fix:**
  1. **`Crypto::unblindSignature()` (line 1150):** If a mint returns `C_ = r * A`, `Secp256k1::pointSub($C_point, $rA)` returns `null` (point at infinity), and `Secp256k1::compressPoint($C)` throws `\TypeError`.
     - *Fix:* Check `if ($C === null) { throw new CashuException('Invalid blind signature: unblinded point at infinity'); }`.
  2. **Malformed `$sig['dleq']` in `mint()`, `submitPreparedSwap()`, `verifyChangeSignatureDleq()` (lines 6814, 6864, 7609):** If a mint returns `"dleq": {}` or non-string `'e'`/`'s'`, passing `$sig['dleq']['e']` to `Crypto::verifyDleq()` or `new DLEQWallet(string $e, string $s)` throws `\TypeError`.
     - *Fix:* Check `is_array($sig['dleq']) && is_string($sig['dleq']['e'] ?? null) && is_string($sig['dleq']['s'] ?? null)` before accessing `'e'` and `'s'`.
  3. **`Wallet::calculateFee()` (line 7144):** If a malicious mint advertises a huge `input_fee_ppk`, `$totalFeePpk + 999` overflows `int` to `float`, causing `intdiv()` to throw `\TypeError`.
     - *Fix:* Cap `input_fee_ppk` in `loadMint()` (e.g., `0 <= $feePpk <= 1_000_000`).
  4. **`Transport::fromArray()` & `PaymentRequest::fromArray()` (lines 2305, 2423):** Validate that CBOR-decoded `t` and `a` are strings, `g` is an array of arrays of strings, and payment request amount `a` is a non-negative integer before passing to typed constructors.

---

### [CW-09] MEDIUM: `Wallet::unblindMeltChange()` Verifies DLEQ Proof on Melt Change Signatures But Discards It When Constructing `Proof` Objects

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 7942–7952 (`Wallet::unblindMeltChange()`)
- **Priority / Severity:** **MEDIUM (P2 — Loss of Offline DLEQ Verifiability on Melt Change Proofs)**
- **Detailed Problem & Failure Scenario:**
  `unblindMeltChange()` verifies `$sig['dleq']` via `$this->verifyChangeSignatureDleq($sig, $blindingData[$i], $pubkey)` (line 7943), but constructs `new Proof($sig['id'], $sig['amount'], $blindingData[$i]['secret'], $C)` at line 7946 without passing the 5th `$dleq` argument! Consequently, melt change proofs stored in SQLite lack DLEQ proofs (`dleq = null`) when later exported as V4 tokens.
- **How to Fix:**
  Construct and pass `$dleq` just as `mint()` and `submitPreparedSwap()` do:
  ```php
  $dleq = isset($sig['dleq']) && is_array($sig['dleq'])
      ? new DLEQWallet(
          $sig['dleq']['e'],
          $sig['dleq']['s'],
          Secp256k1::scalarToHex($blindingData[$i]['r'])
      )
      : null;
  $changeProofs[] = new Proof(
      $sig['id'],
      $sig['amount'],
      $blindingData[$i]['secret'],
      $C,
      $dleq
  );
  ```

---

### [CW-10] MEDIUM: `selectProofsWithFees()` Includes Negative-Yield Dust Proofs on Fee-Bearing Keysets; `getActiveKeysetId()` Ignores `finalExpiry` and `inputFeePpk`; `rotateProofs()` Mixes Expired Keysets

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 6758–6805 (`rotateProofs()`), lines 7066–7090 (`getActiveKeysetId()`), and lines 8411–8431 (`selectProofsWithFees()`)
- **Priority / Severity:** **MEDIUM (P2 — Unnecessary Fee Burn & Rotation Failures)**
- **Detailed Problem & How to Fix:**
  1. **`selectProofsWithFees()` (lines 8411–8431):** Sorts proofs purely by `amount` descending and appends small proofs even when a proof's marginal fee (`input_fee_ppk >= 1000` on 1-sat proofs) is greater than or equal to its amount, reducing net spendable value.
     - *Fix:* Sort by net value `($proof->amount * 1000 - $this->getInputFeePpk($proof->id))` descending and skip proofs whose net contribution is `<= 0`.
  2. **`getActiveKeysetId()` (lines 7066–7090):** Does not skip keysets whose `finalExpiry` has already passed (`$keyset->finalExpiry <= time()`) and does not prefer lower `inputFeePpk` when multiple active keysets share the same ID version.
     - *Fix:* Filter out expired keysets and break ties by lowest `$keyset->inputFeePpk`.
  3. **`rotateProofs()` (lines 6758–6805):** Batches proofs from already-expired keysets together with merely inactive keysets; if the mint rejects the expired keyset, the entire 50-proof batch fails.
     - *Fix:* Group rotation batches by keyset ID (or separate expired keysets into their own batch).

---

### [CW-11] MEDIUM: NUT-03 Output Ordering Privacy Leak in `split()` and Missing Pre-Journal Validation in `swap()` / `split()`

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 7971–8081 (`swap()`, `split()`)
- **Priority / Severity:** **MEDIUM (P2 — NUT-03 Privacy Leak & Premature Input Reservation)**
- **Detailed Problem & How to Fix:**
  1. **Unsorted outputs in `split()` (line 8057):** `$allAmounts = array_merge($sendAmounts, $keepAmounts);` sends all send outputs followed by all keep outputs without sorting ascending (`sort($allAmounts)`). This violates NUT-03 ("To preserve privacy, the wallet MUST order `outputs` by `amount` in ascending order") and reveals the exact send vs. change split to the mint. Note that `split()` separates `$sendProofs` and `$keepProofs` by matching amounts (`lines 8062-8074`), which already works regardless of output order!
     - *Fix:* Add `sort($allAmounts);` right after `$allAmounts = array_merge($sendAmounts, $keepAmounts);` at line 8057.
  2. **Pre-journal validation in `swap()` and `split()`:** Validate `$amount > 0` at the top of `split()`, and validate in `swap()` that `!empty($proofs)` and every requested output denomination exists in `$this->keys[$keysetId]` (`$this->getPublicKey($keysetId, $a)`) **before** calling `preparePendingSpend()`. Also sort a copy of `$inputSecrets` before computing `$pendingId = 'swap:' . hash('sha256', implode("\0", $sortedSecrets))` at line 7991.

---

### [CW-12] LOW-MEDIUM: Stale In-Memory `$this->counters`, Non-Monotonic `setCounter()`, Uppercase V3 Keyset IDs, Zombie Journal on Already-Issued Quote, and URL Canonicalization Mismatch

- **Location:** `cashu-wallet-php/CashuWallet.php` lines 3497, 3560–3569, 4036, 5868, 6467, 7434, 7515–7545, 8919–8955
- **Priority / Severity:** **LOW-MEDIUM (P3 — Library Hygiene & Edge-Case Reliability)**
- **Summary of Fixes:**
  1. **`getCounter()` / `getCounters()` (lines 8919–8955):** Delegate to `$this->storage->getCounter($keysetId)` / `$this->storage->getAllCounters()` when storage is attached, since `preparePendingSpend()` updates SQLite directly without updating `$this->counters`.
  2. **`recoverQuoteLockingKey()` (line 7434):** Use `$this->storage->raiseCounter(self::NUT20_COUNTER_ID, $counter + 1)` instead of `setCounter()` so the NUT-20 counter never moves backward.
  3. **`TokenSerializer::proofFromParts()` (line 3497):** Lowercase hex keyset IDs (`if (self::isHexKeysetId($keysetId)) { $keysetId = strtolower($keysetId); }`) so V3 tokens with uppercase hex keyset IDs match `$this->keysets`.
  4. **`Wallet::mint()` (lines 7515–7545):** If no `mint:$quoteId` journal exists in storage and `$quote->isIssued()` is already `true`, throw `CashuException` before calling `preparePendingSpend()` so a fresh unresolvable journal is not created.
  5. **`Wallet::__construct()` & `WalletStorage::deriveWalletId()` (lines 4036, 5868):** Use `MintClient::canonicalizeMintUrl()` (or keep a fallback lookup in `WalletStorage`) so URL case/default-port differences do not split storage namespaces.

---

## Part 3: Reliability, Concurrency, Security & `mint-discovery` Issues

### [REL-01] CRITICAL: Concurrent Race Condition Between `receive.php` and `PaymentRequest::reconcileReceiving()` Releases an Active Claim Mid-Receive

- **Location:**
  - `includes/payment_request.php` lines 212–250 (`PaymentRequest::reconcileReceiving()`)
  - `receive.php` lines 309–435
- **Priority / Severity:** **CRITICAL (P0 — Concurrent Double-Claim / Cancelled Paid Request)**
- **Detailed Problem & Failure Scenario:**
  1. When `receive.php` processes an incoming NUT-18 payment token, it acquires `cashupay_acquire_lock('receive_' . $storeId)` (line 309) and calls `PaymentRequest::claim($paymentRequest, $secrets)` (line 339), setting `status = 'receiving'` and `receiving_since = time()`.
  2. Next, `receive.php` calls `$wallet->receive($tokenString)` (line 425).
  3. Inside `Wallet::receive()` (`CashuWallet.php:8182-8213`), **before** `Wallet::swap()` writes the pending operation to `cashu_pending_operations` (`preparePendingSpend()`), `receive()` makes a live network HTTP call (`$this->checkProofState($token->proofs)`, up to 15s timeout) and verifies DLEQ proofs.
  4. If `BackgroundRunner::finishPaymentRequests()` -> `PaymentRequest::reconcileReceiving()` runs in a background worker during that network window:
     - `reconcileReceiving()` does **not** acquire `cashupay_acquire_lock('receive_' . $storeId)`!
     - It does **not** check whether `receiving_since` is only 1 second old!
     - It calls `PaymentRequest::receiptState()`, sees that the secrets are not yet in `cashu_pending_operations` or `cashu_proofs`, and immediately calls `PaymentRequest::release($request, $secrets)` — resetting the payment request back to `status = 'pending'` and `input_secrets = null` **while `receive.php` is about to call `$wallet->swap()`**!
- **How to Fix:**
  1. In `PaymentRequest::reconcileReceiving()` (`includes/payment_request.php:212-250`), only reconcile rows where `receiving_since < time() - 30` (or acquire `cashupay_acquire_lock('receive_' . $storeId, 0)` and skip the row if a `receive.php` worker currently holds the lock).

---

### [REL-02] HIGH: Raw `$pdo->exec('BEGIN IMMEDIATE')` in `BackgroundRunner` and `Security` Desynchronizes `PDO::inTransaction()` on PHP < 8.4

- **Location:**
  - `includes/background_runner.php` lines 457, 482 (`acquireLease()`, `renewLease()`)
  - `includes/security.php` lines 37, 253 (`checkRateLimit()`, `withWriteLock()`)
  - Contrast with `includes/database.php` lines 814–817, 1024–1033 (`Database::beginWriteTransaction()`)
- **Priority / Severity:** **HIGH (P1 — Transaction State Desynchronization / `PDOException` Under Concurrency)**
- **Detailed Problem & Failure Scenario:**
  As documented in `includes/database.php:814-817`, executing raw `$pdo->exec('BEGIN IMMEDIATE')` in SQLite starts a transaction at the SQLite level that PHP's `PDO::inTransaction()` does not track on PHP < 8.4. `Database::beginWriteTransaction()` was created specifically to avoid this (`$pdo->beginTransaction(); $pdo->exec('DELETE FROM config WHERE 0');`).
  However, `Security::checkRateLimit()`, `Security::withWriteLock()`, `BackgroundRunner::acquireLease()`, and `BackgroundRunner::renewLease()` still call raw `$pdo->exec('BEGIN IMMEDIATE')`. If any code inside `Security::withWriteLock()` checks `Database::inTransaction()` or calls `Database::beginTransaction()`, `$pdo->inTransaction()` returns `false` and throws `PDOException: cannot start a transaction within a transaction`.
- **How to Fix:**
  Replace raw `$pdo->exec('BEGIN IMMEDIATE')`, `$pdo->exec('COMMIT')`, and `$pdo->exec('ROLLBACK')` in `includes/security.php` and `includes/background_runner.php` with `Database::beginWriteTransaction()`, `Database::commit()`, and `Database::rollback()`.

---

### [REL-03] HIGH: Multi-Mint Gaps in `Invoice::checkPendingProofs()` and `BackgroundRunner::cleanupPendingOperations()`

- **Location:**
  - `includes/invoice.php` lines 1851–1945 (`Invoice::checkPendingProofs()`)
  - `includes/background_runner.php` lines 988–1003 (`BackgroundRunner::cleanupPendingOperations()`)
- **Priority / Severity:** **HIGH (P1 — Unreconciled Backup-Mint Pending Proofs & Unnecessary Network Calls in Cleanup)**
- **Detailed Problem & Failure Scenario:**
  1. `Invoice::checkPendingProofs($storeId)` (`includes/invoice.php:1851-1945`) only checks the store's primary mint (`Invoice::getWalletForStore($storeId)` and `new \Cashu\MintClient($store['mint_url'])`), ignoring all backup mints in `store_mints`.
  2. `BackgroundRunner::cleanupPendingOperations()` (`includes/background_runner.php:988-1003`) loops over stores and calls `Invoice::getWalletInstance($store['id'])` — which performs live HTTP requests to the primary mint (`/v1/info`, `/v1/keysets`, `/v1/keys`) just to run a local SQLite `DELETE FROM cashu_pending_operations`! If the primary mint is offline, `cleanupPendingOperations()` throws and triggers task backoff, and it never cleans backup-mint namespaces.
- **How to Fix:**
  1. In `Invoice::checkPendingProofs($storeId)`, iterate over `Config::getStoreWalletAccounts($storeId)`.
  2. In `BackgroundRunner::cleanupPendingOperations()`, instantiate `\Cashu\WalletStorage` directly (no network call) for each account in `Config::getStoreWalletAccounts($store['id'])` and call `$storage->cleanExpiredPendingOperations()`.

---

### [REL-04] MEDIUM-HIGH: Missing SSRF / Protocol Guard Options on Outbound cURL Calls in `Donation::postTokenToSink()` and `ExchangeRates` Providers

- **Location:**
  - `includes/lightning_address.php` lines 918–931 (`Donation::postTokenToSink()`)
  - `includes/rates.php` lines 76–85, 134–143, 198–207 (`CoinGeckoProvider`, `BinanceProvider`, `KrakenProvider`)
- **Priority / Severity:** **MEDIUM-HIGH (P2 — Defense-in-Depth cURL Hardening)**
- **Detailed Problem & How to Fix:**
  Unlike `MintClient`, `WebhookSender`, and `LightningAddress::fetchJson()`, `Donation::postTokenToSink()` and the three exchange rate providers in `includes/rates.php` do not merge `cashupay_curl_protocol_options()` or set `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_SSL_VERIFYPEER => true`, and `CURLOPT_CONNECTTIMEOUT => 5`. Add `+ cashupay_curl_protocol_options()` and validate `CASHUPAY_DONATION_SINK_URL` with `Security::resolvePublicTarget()`.

---

### [REL-05] MEDIUM: `Background::trigger()` Silently Drops All Background Work on Non-FPM Hosts (`mod_php`) When `base_url` Is Unpinned, and Double-Registers Shutdown Functions

- **Location:** `includes/background.php` lines 59–88 (`Background::trigger()`)
- **Priority / Severity:** **MEDIUM (P2 — Silent Background Task Failure on Shared Apache `mod_php` Hosts)**
- **Detailed Problem & Failure Scenario:**
  1. In `Background::trigger()` (`includes/background.php:65`), `Config::set('last_background_trigger', (string)$now)` is written **before** checking whether a background runner can actually be started.
  2. On traditional Apache `mod_php` shared hosting (where neither `fastcgi_finish_request` nor `litespeed_finish_request` exists, so `canFinishResponse()` is `false`), `Background::trigger()` falls back to `selfCronUrl()` (line 80).
  3. In standalone mode, until the operator manually pins `base_url`, `selfCronUrl()` returns `null` (`includes/background.php:154-156`), and `Background::trigger()` returns without running anything!
  4. Furthermore, if `Background::trigger(false)` and `Background::trigger(true)` are both called during the same FPM request, `register_shutdown_function([self::class, 'runAfterResponse'])` is registered twice because there is no static `$shutdownRegistered` guard.
- **How to Fix:**
  1. Add `private static bool $shutdownRegistered = false;` to `Background` so `register_shutdown_function()` is registered at most once per request.
  2. When `canFinishResponse()` is `false` and `selfCronUrl()` returns `null` (or loopback `fireLoopback()` fails), fall back to registering `runAfterResponse` via `register_shutdown_function()` so background tasks still run after script output completes.

---

### [REL-06] MEDIUM: `receive.php` Ignores Payment Request's Stored `mint_url`/`unit`, Omits `requiresRecovery()` Check, and Leaks Internal `CashuException` Text; `WebhookSender::requeueDelivery()` Ignores `deleted_at`

- **Location:**
  - `receive.php` lines 99–107 (`receivePublicMessage()`), line 295, and lines 313–336
  - `includes/webhook_sender.php` lines 439–451 (`WebhookSender::requeueDelivery()`)
- **Priority / Severity:** **MEDIUM (P2 — Reliability & Information Exposure)**
- **Detailed Problem & How to Fix:**
  1. **`receive.php` lines 295, 313–328:** Reads `Config::getStoreMintUnit($storeId)` and `Config::getStoreMintUrl($storeId)` instead of `$paymentRequest['unit']` and `$paymentRequest['mint_url']`. Also does not check `if ($wallet->requiresRecovery())` before `PaymentRequest::claim()`, and `receivePublicMessage()` returns raw internal `CashuException` messages to unauthenticated callers.
     - *Fix:* Use `$paymentRequest['mint_url']` and `$paymentRequest['unit']` when present, check `$wallet->requiresRecovery()` before claiming, and restrict public error messages to `ReceiveRefusal`.
  2. **`WebhookSender::requeueDelivery()` (`includes/webhook_sender.php:441-445`):** Joins `webhooks w ON w.id = d.webhook_id` without `AND w.deleted_at IS NULL AND w.enabled = 1`, allowing deliveries for deleted/disabled webhooks to be re-queued.

---

### [MD-01] HIGH: `mint-discovery` Submodule Bugs — 100% HTTP Cache Miss Rate, Inverted Review Deduplication (Oldest Review Overwrites Newest), Reputation Ranking Inversion, and Unbounded Streaming Promises

- **Location:**
  - `mint-discovery/mint-info-fetcher.js` lines 143–147 (`isCacheFresh()`)
  - `mint-discovery/aggregator.js` lines 43–60 (`addReview()`) and lines 164–191 (`getRecommendations()`)
  - `mint-discovery/index.js` lines 128–131, 182–329 (`discover()`, `discoverStreaming()`)
  - `mint-discovery/nostr-client.js` lines 191–198, 232–235 (`parseMintInfoEvent()`, `parseReviewEvent()`)
  - `mint-discovery/review-parser.js` line 28 (`parseRatingAndComment()`)
- **Priority / Severity:** **HIGH (P1 — Scam Mints Ranked Above Legitimate Mints & 100% Cache Misses)**
- **Detailed Problem & Failure Scenarios:**
  1. **100% HTTP Cache Miss Rate (`mint-info-fetcher.js:143-147` & `aggregator.js:217-224`):**
     `index.js` (lines 128–131) passes `existing = aggregator.getRecommendation(url)` to `isCacheFresh(existing, config.cacheMaxAge)`. However, `getRecommendation()` sets `lastHttpInfoFetchAt: http?.fetchedAt`, whereas `isCacheFresh()` checks `if (!cachedResult || !cachedResult.fetchedAt) return false;`. Because `existing.fetchedAt` is always `undefined`, **`isCacheFresh()` always returns `false`** and every discovery run re-fetches `/v1/info` for every mint!
  2. **Inverted Review Deduplication — Oldest Review Overwrites Newest (`aggregator.js:43-60`):**
     ```js
     // Remove older reviews from the same author (keep only latest)
     reviews = reviews.filter((r) => r.pubkey !== review.pubkey);
     reviews.push(review);
     ```
     `addReview()` never compares `review.created_at` against the existing review from `review.pubkey`! Because Nostr relays return events newest-first (`created_at` descending), `addReviews()` adds an author's newest review first and then overwrites it with their oldest review! If a user updated a `[5/5]` review to `[1/5] Rug pull, stay away!`, `aggregator.js` discards the `[1/5]` warning and keeps the old `[5/5]` rating!
  3. **Reputation Ranking Inversion (`aggregator.js:184-189`):**
     ```js
     recommendations.sort((a, b) => {
       const countDiff = b.reviewsCount - a.reviewsCount;
       if (countDiff !== 0) return countDiff;
       return (b.averageRating || 0) - (a.averageRating || 0);
     });
     ```
     Because `reviewsCount` is sorted before `averageRating` (and `reviewsCount` even includes unrated comments where `rating === null`), a scam mint with 30 `[1/5]` reviews (`averageRating = 1.0`) is displayed **above** a trusted mint with 25 `[5/5]` reviews (`averageRating = 5.0`)!
  4. **URL Normalization Split (`aggregator.js:46, 82, 110`):**
     `https://mint.example.com` and `https://mint.example.com/` are not normalized in `aggregator.js`, splitting a mint's reviews from its `/v1/info` metadata.
  5. **Short-Circuited Tag Check (`nostr-client.js:193, 233`):**
     `t[0] === "u" && (t[2] === "cashu" || t.length >= 2)` — since every `["u", url, ...]` tag has `t.length >= 2`, `t[2] === "cashu"` is dead code, so `["u", "https://...", "fedimint"]` is accepted as a Cashu mint.
  6. **Unanchored Rating Regex (`review-parser.js:28`):**
     `content.match(/\s*\[(\d)\s*\/\s*5\]\s*(.*)$/s)` lacks `^`, matching `[5/5]` in the middle of `"Scam! Used to be [5/5], now steals funds"`.
- **How to Fix:**
  1. In `mint-info-fetcher.js:144`, check `const fetchedAt = cachedResult.fetchedAt || cachedResult.lastHttpInfoFetchAt;`.
  2. In `aggregator.js:53`, normalize URLs (`url.trim().replace(/\/+$/, '')`), keep the review only if `!existing || (review.created_at || 0) >= (existing.created_at || 0)`, count only rated reviews in `reviewsCount`, and sort online mints by a Bayesian/Wilson weighted rating (or require `averageRating >= 3.5` before sorting by `reviewsCount`).
  3. Fix `nostr-client.js:193, 233` to `t[0] === "u" && t.length >= 2 && (!t[2] || t[2] === "cashu")`, anchor `review-parser.js:28` with `/^\s*\[([1-5])\s*\/\s*5\]\s*(.*)$/s`, and rebuild `assets/js/mint-discovery.bundle.js`.

---

## Part 4: Operator UX & Maintainability Audit ("Marta" Non-Technical Shop Owner Persona)

Per `AGENTS.md`:
> *"Assume a non-technical shop owner. This is the single most important design constraint in the project... installs by unzipping into a web host and opening a URL, and expects that to be all... Prefer automatic to manual... Never leave a broken state undiagnosed... No jargon in UI copy."*

Below are the concrete places where a non-technical operator hits dead ends, confusing jargon, or broken workflows.

---

### [UX-01] HIGH: WordPress Plain Permalinks (`permalink_structure === ''`) Cause Immediate 404s on Setup, Admin, API, and Checkout With Zero Explanation

- **Location:**
  - `wordpress/rewrite-rules.php` lines 33–79 (`cashupay_register_rewrite_rules()`, `cashupay_maybe_flush_rewrite_rules()`)
  - `wordpress/admin-menu.php` lines 65–138 (`cashupay_setup_notice()`, `cashupay_render_redirect_page()`)
  - `includes/urls.php` lines 60–177
- **Priority / Severity:** **HIGH (P1 — Total Dead End on Fresh WordPress Installs Using Default "Plain" Permalinks)**
- **Detailed Problem & Failure Scenario:**
  1. Many fresh WordPress installations on shared hosting default to **Plain permalinks** (`https://shop.example/?p=123`, where `get_option('permalink_structure') === ''`).
  2. When `permalink_structure === ''`, WordPress does **not** use `.htaccess` path rewrites and ignores `add_rewrite_rule('^cashupay-setup/?$', ...)` for path-based URLs.
  3. Look at `wordpress/rewrite-rules.php` line 63:
     ```php
     if ((is_array($rules) && isset($rules['^cashupay/v1/(.*)$'])) || get_option('permalink_structure') === '') {
         update_option('cashupay_rewrite_rules_version', CASHUPAY_VERSION);
     }
     ```
     The code explicitly noticed that `$rules` is empty when `permalink_structure === ''`, yet it silently marks `cashupay_rewrite_rules_version` as up to date and does nothing else!
  4. When Marta activates the plugin and clicks **"configure the plugin here"** in the WordPress admin banner (`wordpress/admin-menu.php:90`) or **Tools → CashuPay**, her browser navigates to `https://shop.example/cashupay-setup/` and hits a **WordPress 404 "Page Not Found" screen**!
- **How to Fix:**
  1. **Best Fix (Automatic Fallback in `Urls`):** In `includes/urls.php`, when `self::isWordPress() && get_option('permalink_structure') === ''`, generate query-parameter URLs that work on **all** WordPress configurations without rewrite rules:
     - `Urls::setup()` -> `home_url('/?cashupay_page=setup')`
     - `Urls::admin()` -> `home_url('/?cashupay_page=admin')`
     - `Urls::payment($id)` -> `home_url('/?cashupay_page=payment&cashupay_id=' . urlencode($id))`
     - `Urls::receive($id)` -> `home_url('/?cashupay_page=receive&cashupay_id=' . urlencode($id))`
     - `Urls::authorize()` -> `home_url('/?cashupay_page=authorize')`
     (Note: `wordpress/rewrite-rules.php` `cashupay_template_redirect()` **already** dispatches on `get_query_var('cashupay_page')`!)
  2. **Plus a 1-Click Banner for REST/Greenfield API:** Because WooCommerce BTCPay Greenfield API calls `/cashupay/api/v1/...`, show a clear admin notice in `wordpress/admin-menu.php` whenever `get_option('permalink_structure') === ''` with a one-click **"Enable Pretty Links (Permalinks)"** button that sets `permalink_structure` to `/%postname%/` and calls `flush_rewrite_rules()`.

---

### [UX-02] HIGH: Setup Step 8 ("Where should your money go?") Hardcodes `(sats)` and `10000` Threshold Even When Marta Selected a `USD` or `EUR` Mint in Step 5

- **Location:** `setup.php` lines 537–572 (Step 8 POST handler) and lines 1945–1976 (Step 8 HTML template)
- **Priority / Severity:** **HIGH (P1 — Wrong Threshold Unit & Mismatched Auto-Withdrawal Behavior)**
- **Detailed Problem & Failure Scenario:**
  1. In Step 5 of `setup.php`, Marta can choose a `USD` or `EUR` mint (`$_SESSION['setup_mint_unit']`).
  2. In Step 8 (`setup.php:1959-1961`), the form hardcodes `(sats)`:
     ```html
     <div class="form-group">
         <label for="payout_threshold">Send it when the balance reaches (sats)</label>
         <input type="number" id="payout_threshold" name="payout_threshold"
                value="<?php echo htmlspecialchars($_POST['payout_threshold'] ?? '10000'); ?>"
                min="100" step="1">
     ```
  3. In the Step 8 POST handler (`setup.php:548-564`):
     ```php
     $threshold = (int)setupField('payout_threshold', '10000');
     ...
     $check = LightningAddress::checkPayoutAddress($address, $threshold);
     ...
     Config::updateStore($storeId, [
         'lightning_address' => $address,
         'auto_melt_enabled' => 1,
         'auto_melt_threshold' => $threshold,
     ]);
     ```
  4. However, in `LightningAddress::autoMeltAccount()` (`includes/lightning_address.php:501`) and `admin.php` (`loadDashboard` line 5328 & `saveAutoMelt` line 6725), `stores.auto_melt_threshold` is stored in the **mint's smallest unit** (i.e. **cents** for a `USD` or `EUR` mint)!
     - If Marta chose a USD mint and leaves the default `10000` (which the label told her was `10000 sats` ≈ $10), `auto_melt_threshold` is saved as `10000` cents = **$100.00**!
     - Conversely, if she sees `USD` in mind and types `20` (for $20), `setup.php` rejects it with `"Choose a threshold of at least 100 sats"` (line 550), or if she types `100`, it saves `100` cents = **$1.00** while probing her Lightning address for `100 sats`!
- **How to Fix:**
  In `setup.php` Step 8, read `$mintUnit = strtolower($_SESSION['setup_mint_unit'] ?? 'sat');` and `$isFiat = in_array($mintUnit, ['usd', 'eur', 'gbp', 'cad', 'aud', 'chf'], true);`:
  - When `$isFiat` is `true`, label the input `"Send it when the balance reaches (" . strtoupper($mintUnit) . ")"` with default `value="20.00"`, `min="2.00"`, `step="0.01"`.
  - In the Step 8 POST handler, convert the submitted amount to smallest mint units (`(int)round((float)$rawThreshold * (10 ** Config::unitDecimals($mintUnit)))`) before storing in `auto_melt_threshold`, and pass `in_array($mintUnit, ['sat', 'sats'], true) ? $threshold : null` to `LightningAddress::checkPayoutAddress($address, ...)`.

---

### [UX-03] HIGH: Adding a Backup Mint (`add_backup_mint`) Never Verifies That the Backup Mint Supports the Store's Unit or That It Is Not the Primary Mint

- **Location:**
  - `admin.php` lines 2593–2630 (`case 'add_backup_mint':`) and lines 7373–7403 (`addBackupMint()`)
  - `includes/config.php` lines 479–536 (`addStoreBackupMint()`, `getStoreEnabledMints()`)
- **Priority / Severity:** **HIGH (P1 — Silent Backup Mint Failover Failure)**
- **Detailed Problem & Failure Scenario:**
  1. When Marta adds a backup mint in `admin.php` (`case 'add_backup_mint':`, lines 2593–2630), the server calls `Config::testMintConnection($mintUrl)` (which only checks that `/v1/info` responds with JSON) and then saves the backup mint with `$unit = strtolower(trim($_POST['unit'] ?? 'sat'))` (which the frontend sets to the store's `mint_unit`).
  2. It **never** checks `\Cashu\Wallet::getSupportedUnits($mintUrl)` to verify that the backup mint actually supports the store's currency unit (`sat`, `usd`, `eur`, etc.)!
  3. It also **never** checks whether `$mintUrl` is identical to the store's existing primary `mint_url` (or only differs by a trailing slash, since `Config::addStoreBackupMint()` at `includes/config.php:480` does not `rtrim($mintUrl, '/')`).
  4. If Marta adds a SAT-only mint as a backup for a EUR store (or vice versa), it is accepted as `"Backup mint added!"`, and when her primary mint goes down, every customer checkout fails at `requestMintQuote()`!
- **How to Fix:**
  In `admin.php` `case 'add_backup_mint':` (line 2593):
  ```php
  $mintUrl = rtrim(trim($_POST['mint_url'] ?? ''), '/');
  $store = Config::getStore($storeId);
  if (!$store) { ... }
  $unit = strtolower($store['mint_unit'] ?? 'sat');
  if ($mintUrl === rtrim((string)($store['mint_url'] ?? ''), '/')) {
      http_response_code(400);
      echo json_encode(['error' => 'This mint is already your store\'s primary mint.']);
      exit;
  }
  $supportedUnits = array_map('strtolower', \Cashu\Wallet::getSupportedUnits($mintUrl));
  if (!in_array($unit, $supportedUnits, true)) {
      http_response_code(400);
      echo json_encode([
          'error' => sprintf(
              'This mint does not support %s (it supports: %s). Choose a backup mint that supports %s.',
              strtoupper($unit),
              implode(', ', array_map('strtoupper', $supportedUnits)) ?: 'none',
              strtoupper($unit)
          )
      ]);
      exit;
  }
  ```

---

### [UX-04] MEDIUM: Setup Step 8 Breaks Progress Counter (`"Step 8 of 5"` / `"Step 8 of 6"`), Step 6 Button Falsely Says `"Complete Setup"`, and Standalone Setup Forces Immediate Re-Login After Setting Password

- **Location:**
  - `setup.php` lines 1152–1165 (step counter mapping), line 1896 (Step 6 button label), lines 331–333 (Step 2 password handler), and lines 569–585 (`cashupay_complete_setup()`)
  - `includes/auth.php` lines 173–183 (`Auth::setAdminPassword()`)
- **Priority / Severity:** **MEDIUM (P2 — Confusing Setup Flow & Immediate Login Wall)**
- **Detailed Problem & Failure Scenario:**
  1. **"Step 8 of 5" / "Step 8 of 6" (`setup.php:1158-1164`):**
     When Step 8 ("Where should your money go?") was added between Step 6 and Step 7, `$totalSteps` and `$stepMapping` were not updated:
     ```php
     $totalSteps = $isWpMode ? 5 : 6;
     $stepMapping = $isWpMode
         ? [1 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5] // Skip step 2 (password)
         : [1 => 1, 2 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6];
     $displayStep = $stepMapping[$step] ?? $step;
     ```
     On Step 8, `$displayStep` falls back to `8`, so the progress indicator displays **"Step 8 of 5"** (WordPress) or **"Step 8 of 6"** (Standalone)!
  2. **Step 6 Button Label (`setup.php:1896`):**
     On Step 6 (Recovery Phrase), the submit button says `"Complete Setup"`, but clicking it takes Marta to Step 8 instead of completing setup.
  3. **Standalone Setup Doesn't Log Marta In (`setup.php:331, 569` & `includes/auth.php:173`):**
     In Standalone mode, Marta creates her admin password in Step 2 (`Auth::setAdminPassword($password)`). However, `setAdminPassword()` never sets `$_SESSION['admin_logged_in'] = true`. When she finishes Step 8 and clicks **"Go to CashuPay Admin"** or **"Test Pairing Flow"** on Step 7, she is immediately blocked by the password login screen for the password she just created! Worse, if she refreshes the Step 7 page, line 70 (`if ($setupComplete && !Auth::isLoggedIn())`) redirects her away from the setup completion instructions!
- **How to Fix:**
  1. In `setup.php` lines 1158–1164:
     ```php
     $totalSteps = $isWpMode ? 6 : 7;
     $stepMapping = $isWpMode
         ? [1 => 1, 4 => 2, 5 => 3, 6 => 4, 8 => 5, 7 => 6]
         : [1 => 1, 2 => 2, 4 => 3, 5 => 4, 6 => 5, 8 => 6, 7 => 7];
     ```
  2. Change Step 6's button label (`setup.php:1896`) to `'Continue'`.
  3. In `Auth::setAdminPassword()` (`includes/auth.php:173-183`), also establish the logged-in admin session (`$_SESSION['admin_logged_in'] = true; $_SESSION['login_time'] = time(); $_SESSION['last_activity'] = time(); $_SESSION['session_epoch'] = self::sessionEpoch();`).

---

### [UX-05] MEDIUM: WordPress Setup Step 7 Tells Marta to `"return here"` After Disconnecting/Installing BTCPay, But Returning Redirects Her Away — and No 1-Click `"Connect WooCommerce"` Button Exists in Admin

- **Location:**
  - `setup.php` lines 74–77 and lines 2108–2149
  - `wordpress/btcpay-dependency.php` lines 174–188 (`cashupay_btcpay_dependency_notice()`)
- **Priority / Severity:** **MEDIUM (P2 — Broken WordPress Post-Setup WooCommerce Connection Flow)**
- **Detailed Problem & Failure Scenario:**
  1. When Marta reaches Step 7 ("Setup Complete!"), `cashupay_complete_setup()` has **already** marked `setup_complete = true` in the database.
  2. If WooCommerce or BTCPay for WooCommerce wasn't installed yet (or was connected to an old server), Step 7 (`setup.php:2132, 2145`) tells Marta:
     - *"Disconnect your existing BTCPay Server via WooCommerce -> Settings -> Payments -> BTCPay, then return here."*
  3. When Marta follows that instruction and navigates back to `/cashupay-setup/`, `setup.php` lines 74–77 execute:
     ```php
     if ($setupComplete && $mode !== 'add_store' && ($_POST['step'] ?? '') !== '7') {
         header('Location: ' . Urls::admin());
         exit;
     }
     ```
     Because returning to `/cashupay-setup/` is a `GET` request (`$_POST['step']` is empty), she is immediately redirected to `/cashupay-admin/` and **can never reach Step 7's 1-click `"Configure WooCommerce"` button again!**
  4. Once in WordPress admin, `wordpress/btcpay-dependency.php` lines 174–188 shows a banner telling her to manually copy-paste `https://.../cashupay` into BTCPay settings, click "Generate API key", and approve the connection — instead of giving her the same 1-click **"Connect WooCommerce automatically"** button (`cashupay_configure_btcpay_plugin()`) that Step 7 had!
  5. Additionally, `setup.php` lines 2134 and 2147 link to `admin.php?page=wc-settings&tab=checkout&section=btcpay_greenfield`, whereas BTCPay Greenfield v2+ places its Server URL connection settings at `admin.php?page=wc-settings&tab=btcpay_settings`.
- **How to Fix:**
  1. Add a 1-click **"Connect WooCommerce to CashuPay"** button directly inside the WordPress admin notice (`wordpress/btcpay-dependency.php:174-188`) and in `admin.php` (Store Settings in WordPress mode) that triggers `cashupay_configure_btcpay_plugin()`.
  2. Update `setup.php` lines 2134 and 2147 to link to `admin_url('admin.php?page=wc-settings&tab=btcpay_settings')`.

---

### [UX-06] MEDIUM: Operator & Customer UX Polish Across `setup.php`, `api-keys/authorize.php`, `admin.php`, `payment.php`, and `receive.php`

- **Locations & Priorities:** **MEDIUM (P2 — "Marta" Persona & Checkout Polish)**
  1. **Sysadmin Shell Commands & `config.local.php` Instructions in `setup.php` Step 1 (`setup.php:1301-1350`):**
     - Step 1 shows a green box telling Marta to run `mkdir -p /home/youruser/cashupay-data` and `chmod 750 /home/youruser/cashupay-data` and manually create `includes/config.local.php` — even when `$isOutsideWebroot` is **already true**, and even though a 1-click **"Move my data to a safe place"** button (`action=relocate_data`) is implemented right below it (lines 1340–1350, currently hidden unless `$serverProbe['status'] === 'exposed'`)!
     - *Fix:* Hide the recommendation box when `$isOutsideWebroot` is already `true`; when `$isOutsideWebroot` is `false`, offer the 1-click **"Move data folder outside web root automatically"** button (`action=relocate_data`) as the primary action, and put manual instructions behind a collapsed `<details><summary>Manual instructions (if automatic move is not supported by your host)</summary>` block.
  2. **Raw `crontab` / `curl` Command Shown on Step 7 Even in WordPress Mode (`setup.php:1993-2007`):**
     - Step 7 displays `* * * * * curl -s -H "X-Cron-Key: ..." ... >/dev/null 2>&1` even when `Urls::isWordPress()` is `true` (where WP-Cron is already registered automatically) and without explaining that CashuPayServer already runs background tasks automatically on page/API traffic.
     - *Fix:* Hide the cron box in WordPress mode (or note that WordPress runs background checks automatically), and in Standalone mode reassure Marta that payments work automatically without cron, with an optional collapsible guide for low-traffic shops.
  3. **WordPress `/cashupay/api-keys/authorize` Shows Broken Standalone Login Form When Logged Out (`wordpress/rewrite-rules.php:140-143`, `api-keys/authorize.php:165-176, 580-611`):**
     - In `wordpress/rewrite-rules.php` line 140, `case 'authorize':` does not call `cashupay_require_admin()`. If Marta opens a pairing URL while logged out of WordPress, `api-keys/authorize.php` displays a Standalone `"Admin Password"` form (`Auth::attemptLogin()`), which **always fails** in WordPress mode because `Config::getAdminPasswordHash()` is `null`!
     - *Fix:* Call `cashupay_require_admin()` in `wordpress/rewrite-rules.php` `case 'authorize':` (or redirect to `wp_login_url($_SERVER['REQUEST_URI'])` inside `api-keys/authorize.php` when `Urls::isWordPress()` is true).
  4. **Admin Withdraw Modal Invites Amount Input on Unsupported Amountless BOLT-11 Invoices (`admin.php:6171-6180`):**
     - When Marta pastes an amountless BOLT-11 invoice into the Withdraw modal, `handleDestinationInput()` enables the amount field and says `"Amountless invoice - enter amount below"`, only for `LightningAddress::meltToBolt11()` (`includes/lightning_address.php:701`) to reject it on submit with `"This invoice has no amount. Amountless invoices are not supported yet"`.
     - *Fix:* Disable the Withdraw button immediately when `!result.hasAmount` in `handleDestinationInput()` and tell Marta: `"This invoice has no amount set. Please create an invoice for a specific amount, or use a Lightning address."`
  5. **Admin Withdraw Modal Shows Multi-Mint Total Fiat Balance Instead of Single-Mint Spendable Maximum (`admin.php:7835-7843`):**
     - For fiat mints, `openModal('modal-withdraw')` displays `dashboardData?.balance` (total across all mints) instead of `dashboardData?.exportAvailable` (largest single mint's spendable balance).
     - *Fix:* Use `dashboardData?.exportAvailable` in `admin.php:7837`.
  6. **Admin Header `"Refresh"` Button Only Reloads Local SQLite Without Checking the Mint (`admin.php:568-572, 4834`):**
     - Despite the comment at `admin.php:570` (`"User can click 'Refresh' to contact mint and verify proof states"`), `#refresh-btn` only calls `loadDashboard()`, which never contacts the mint.
     - *Fix:* Have `#refresh-btn` trigger a lightweight background sync or proof/transfer check before calling `loadDashboard()`.
  7. **Customer Payment Page (`payment.php`) Uses Store's Current Unit Instead of Invoice's Unit and Leaves `"Open in Wallet"` Active After Expiry (`payment.php:117, 614-625`):**
     - Line 117 reads `$mintUnit = Config::getStoreMintUnit($invoice['store_id']);` instead of `$invoice['mint_unit'] ?? Config::getStoreMintUnit($invoice['store_id'])`.
     - In `updateTimer()` (lines 618–624), when `remaining <= 0`, the QR code dims (`opacity = 0.25`), but the `"Open in Wallet"` and `"Copy Invoice"` buttons remain active and clickable.
     - *Fix:* Read `$invoice['mint_unit']` first at line 117, and hide/disable `.actions` in `updateTimer()` when `remaining <= 0`.
  8. **Customer Payment Request Page (`receive.php`) Shows Raw Cents as Whole Dollars (`"150 USD received"`) and Throws on Copy Over HTTP (`receive.php:970-990`):**
     - When a `$1.50` (`150` cents) payment request settles, line 988 renders ``Paid ✓ (${data.receivedAmount} ${String(data.unit).toUpperCase()} received)`` -> **`Paid ✓ (150 USD received)`**!
     - `copyRequest()` (line 970) calls `navigator.clipboard.writeText(...)` without the `document.execCommand('copy')` fallback used in `payment.php:654-671`.
     - *Fix:* Format `received_amount` using `Config::unitFor($unit)->format(...)` in the JSON status response and add the `fallbackCopy()` helper to `receive.php`.

---

## Part 5: Prioritized Remediation Roadmap

### Phase 1 — Immediate P0 Fixes (Money-Losing, Order-Breaking & Fund-Stranding Bugs)
1. **`cashu-wallet-php/CashuWallet.php` (`resolveSwapJournal`, `finalizePendingMint`, `replacePendingMintPlan`, `recoverPendingOutputs`):**
   - Fix **[CPS-01 / CW-02]**: Always delete `imported_secrets` (`$outcome[$secret] = null`) when a swap produces no signed outputs, regardless of `$direct`.
   - Fix **[CW-01]**: Store `'amounts'` in `replaced_plans`, always call `storeProofs($proofs, $quoteId)` first in `finalizePendingMint()`, and check `replaced_plans` in `recoverPendingOutputs()`.
2. **`wordpress/btcpay-integration.php` (`cashupay_register_webhook`):**
   - Fix **[CPS-02]**: Re-enable (`enabled = 1`) and update `events` whenever reusing an existing webhook row on plugin re-configuration.
3. **`includes/lightning_address.php` & `admin.php` (Backup-Mint Withdrawals & Token Claim Checks):**
   - Fix **[CPS-03]** & **[CPS-11]**: Resolve the funded wallet account (`Invoice::getFundsAccount()`) instead of hardcoding `Invoice::getWalletInstance($storeId)` in `getBolt11Amount()`, `get_withdrawal_estimate`, `manual_melt`, and `check_proofs_spent`; eliminate duplicate `requestMeltQuote()` calls in `manual_melt`; pass `$row['mint_url']` and `$row['unit']` in `reconcileTokenTransfer()` and `check_transfer`.
4. **`includes/invoice.php` & `includes/payment_request.php` (Settlement & Concurrency Guards):**
   - Fix **[CPS-04]**: Verify stored/restored proof sum `>= (int)$invoice['amount_sats']` in `completeIssuedInvoice()` and `recoverOrphanedInvoices()`.
   - Fix **[REL-01]**: Acquire `receive_<storeId>` lock (or require `receiving_since < time() - 30`) in `PaymentRequest::reconcileReceiving()`.

### Phase 2 — High-Priority P1 Fixes (API Accuracy, Currency Math, Store Safety & Submodules)
1. **Currency & API Fixes (`includes/api/stores.php`, `includes/rates.php`, `includes/config.php`):**
   - Fix **[CPS-05]** (convert `/payment-methods` amounts to BTC), **[CPS-06]** (normalize scientific notation before `bc*` calls), and **[CPS-07]** (0-decimal `JPY`, `intdiv` for `msat` -> `sat`, `$roundUp = true` on same-currency `convertToMintUnit`).
2. **Fund & Wallet Storage Safety (`includes/store_safety.php`, `includes/lightning_address.php`, `CashuWallet.php`):**
   - Fix **[CPS-08]** (expand `KNOWN_UNITS` in `StoreSafety` & `scanStrandedNamespaces()`), **[CPS-09]** (use `selectProofsWithFees()` in `Donation::sendToDonationSink()`), **[CPS-10]** (handle `changeRecoveryPending` in `executeMelt()`), **[CW-03]** (store `$outputProofs` unconditionally first in `finalizePendingSpend()`/`settlePendingSpend()`), **[CW-04]** (chunk SQLite `IN (...)` queries in `WalletStorage`), **[CW-05]** (`CBORBytes` wrapper + padded DLEQ hex in `serializeV4()`), and **[CW-06]** (verify V2 keyset IDs in `loadRestoreKeys()`/`ensureKeysetKeys()`).
3. **WordPress, Setup & Mint Discovery (`wordpress/`, `setup.php`, `mint-discovery/`):**
   - Fix **[UX-01]** (WordPress Plain Permalinks fallback/banner), **[UX-02]** (fiat unit awareness in Setup Step 8), **[UX-03]** (verify supported units in `add_backup_mint`), and **[MD-01]** (`mint-discovery` cache freshness key, newest-first review deduplication, and rating-aware sorting).

### Phase 3 — P2/P3 Reliability & UX Polish
- Replace raw `$pdo->exec('BEGIN IMMEDIATE')` with `Database::beginWriteTransaction()` (**[REL-02]**).
- Extend `checkPendingProofs()` and offline `cleanupPendingOperations()` to all store wallet accounts (**[REL-03]**).
- Add `register_shutdown_function` fallback in `Background::trigger()` for non-FPM Apache hosts (**[REL-05]**).
- Complete the setup wizard, WordPress pairing, and checkout UX improvements (**[UX-04]**, **[UX-05]**, **[UX-06]**).
