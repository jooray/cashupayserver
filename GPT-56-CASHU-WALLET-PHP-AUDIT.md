# cashu-wallet-php Reliability and Security Audit

**Audit date:** 2026-07-15  
**Audited revision:** `f34dc28a104b7db82de2a06b5e57232f6cbcafcd`  
**Scope:** `CashuWallet.php`, documented APIs, persistence, deterministic derivation, mint/swap/melt/receive lifecycle, restore, serialization, mint and LNURL HTTP behavior, cryptographic validation, and examples. CashuPayServer integration consequences are covered separately in `GPT-56-CASHUPAYSERVER-AUDIT.md`.

## Executive Summary

The library has several valuable foundations: secure random generation, structurally correct blind/unblind operations, persistent deterministic counters, a mint retry journal, exact mint signature cardinality checks, TLS verification for HTTPS mint calls, and transactional proof-batch insertion.

It is nevertheless **experimental and unsafe for meaningful custody**. Minting is the strongest operation. Swap and melt do not yet provide the same crash consistency:

- Swap has no pre-request journal or automatic recovery.
- Melt does not always journal or reserve inputs before sending them.
- Swap and melt finalization are split across independent SQLite writes.
- Inputs are checked but not atomically reserved, allowing concurrent use.
- Melt responses and returned signatures are not strictly or cryptographically verified.
- Storage identity omits the seed or caller-owned wallet account, causing cross-wallet state sharing.
- Fresh storage cannot be distinguished from a previously used seed that requires restore.

The correct direction is a durable operation state machine inside the library. For every money-moving protocol call, the library should atomically reserve inputs and counters, persist the exact request plan, contact the mint outside the transaction, then atomically store outputs and finalize inputs. After any ambiguous response, recovery should resume from the operation record.

## Overall Rating

| Area | Rating | Reason |
|---|---|---|
| Randomness and basic derivation | Good foundation | Uses `random_bytes()` and persisted deterministic counters |
| Minting | Promising | Durable deterministic output plan, but response verification and finalization can improve |
| Swap | Unsafe | No journal, no reservation, non-atomic finalization |
| Melt | Unsafe under timeout | Incomplete pre-request state, response checks, and finalization |
| Restore | Unsafe as a sole recovery guarantee | Incorrect counter calculation and fail-open proof-state handling |
| Storage isolation | Unsafe | Namespace is only mint URL + unit |
| Token persistence | Incomplete | Witness loss and state resurrection |
| Cryptographic validation | Insufficient | Returned signatures/DLEQ are not verified |
| Automated assurance | Insufficient | No automated tests were found |

## Critical Findings

### CWP-C1: Storage identity does not identify the seed or caller-owned wallet

**Evidence**

- `CashuWallet.php:2364-2377` derives `walletId` only from mint URL and unit.
- `CashuWallet.php:2391-2420` scopes proofs, counters, and pending operations by this ID.

**Failure scenario**

Two library consumers use different seeds in one SQLite database against the same mint and unit. Both load the same proofs and counters. Because Cashu proofs are bearer credentials, either wallet can spend the shared rows despite deriving future outputs from a different seed. Pending operations can also overwrite or be recovered under the wrong seed.

**Recommendation**

- Require an explicit immutable `accountId` in persistent-wallet construction.
- Store metadata binding `accountId` to canonical mint URL, unit, and a domain-separated non-secret seed fingerprint.
- Refuse all fund-moving operations on a metadata mismatch.
- Keep a temporary legacy constructor only where existing external consumers need migration; new code should not infer identity from URL.

### CWP-C2: Swap has no durable recovery journal

**Evidence**

- `CashuWallet.php:3902-3919` consumes deterministic counters and builds outputs.
- `CashuWallet.php:3921-3925` sends inputs to the mint.
- `CashuWallet.php:3941-3949` updates local state only after a response.
- Unlike mint at `CashuWallet.php:3605-3639`, no pending swap record stores the exact output plan.

**Failure scenario**

The mint consumes all inputs and signs outputs, but the connection drops before PHP receives the response. Inputs remain locally `UNSPENT`, returned signatures are absent, and there is no operation record from which to retry or restore the exact outputs. A full NUT-09 scan may eventually recover them, but that is not a safe normal recovery mechanism.

**Recommendation**

- Before I/O, atomically reserve inputs, reserve one counter range, and persist exact amounts, counters, blinded outputs, keysets, and input identifiers.
- Retry only the same operation plan.
- If inputs are spent, recover recorded outputs through NUT-09.
- Release inputs only after authoritative proof that the mint did not consume them.
- Keep unresolved operations indefinitely in `needs_attention` rather than deleting them.

### CWP-C3: Melt can contact the mint without a complete pre-request journal or input reservation

**Evidence**

- `CashuWallet.php:3726-3735` reads local states but does not atomically change them.
- `CashuWallet.php:3755-3790` writes a pre-request journal only when calculated change is positive.
- `CashuWallet.php:3793-3800` performs the destructive request.
- `CashuWallet.php:3834-3845` creates the no-change pending journal only after receiving a parsed `PENDING` response.

**Failure scenario**

A no-change melt pays the Lightning invoice, but the HTTP response times out. The input proofs remain locally `UNSPENT`, and no durable operation links them to the melt quote. A caller can select the same proofs again or retry through a second payment route.

Even when change exists and a journal is present, inputs are not reserved before I/O. Another process can concurrently select them.

**Recommendation**

- Journal every melt, including zero-change melts, before the request.
- Atomically compare-and-set every input from `UNSPENT` to `PENDING` in the same transaction as journal creation.
- On network, parse, or process failure, leave the operation and inputs pending.
- Reconcile exclusively by quote and proof state before returning inputs to `UNSPENT` or marking them `SPENT`.

### CWP-C4: Swap and melt finalization is not atomic

**Evidence**

- Swap marks inputs spent and then stores outputs at `CashuWallet.php:3941-3949`.
- Paid melt marks inputs spent, stores change, and deletes its journal at `CashuWallet.php:3821-3833`.
- Melt recovery repeats separate writes at `CashuWallet.php:3312-3325`.
- `storeProofs()` wraps only its own batch at `CashuWallet.php:2536-2576`.

**Failure scenario**

The mint successfully swaps a large balance. SQLite commits input state as spent, then output insertion fails because the disk is full or PHP terminates. The wallet reports the old value spent and has no replacement proofs. For swap there is no journal; for melt the journal can also be deleted at the wrong boundary.

**Recommendation**

Finalize in one SQLite transaction:

1. Validate and insert all output/change proofs.
2. Transition exactly the reserved inputs to `SPENT`.
3. Record the verified remote result.
4. Mark the operation committed.
5. Commit.

Require expected affected-row counts and catch `Throwable`, not only `Exception`, before rollback.

### CWP-C5: A fresh or mismatched database is treated as safe for deterministic generation

**Evidence**

- `CashuWallet.php:4237-4259` warns callers to restore after storage loss.
- `CashuWallet.php:4307-4327` and `4352-4363` reject only seed use without storage.
- `CashuWallet.php:4488-4491` defaults an unknown counter to zero.

**Failure scenario**

A user attaches a previously used mnemonic to a new empty database. Because storage exists, `requireSafeState()` passes and outputs begin at counter zero. Previously signed deterministic secrets can be reused. Conversely, attaching a different seed to an old database loads unrelated proofs and counters without a mismatch error.

**Recommendation**

- Add explicit `createNewWallet()` and `restoreExistingWallet()` initialization modes.
- Store seed fingerprint, account ID, canonical mint, unit, initialization state, and schema version.
- Refuse deterministic generation when storage is uninitialized, fingerprint-mismatched, or marked `recovery_required`.
- A restore failure must remain fail-closed.

## High Findings

### CWP-H1: Input proofs are not atomically reserved across processes

`CashuWallet.php:3726-3735` and `3888-3897` perform check-then-act reads. Two PHP workers can both observe `UNSPENT` and submit the same bearer inputs. Add a compare-and-set reservation and require the updated row count to equal the number of unique inputs in the same transaction that creates the operation.

### CWP-H2: Melt accepts malformed, truncated, or mismatched change before finalizing inputs

`CashuWallet.php:3802-3814` iterates any number of returned change signatures by position. `CashuWallet.php:3825-3833` then marks all inputs spent and deletes the journal whenever state says `PAID`. `recoverMeltChange()` at `3394-3415` silently skips excess entries. Validate returned entries against submitted blank outputs, keyset, amount, arithmetic, uniqueness, and protocol semantics. Keep the journal until all recoverable change is accounted for.

### CWP-H3: Mint, swap, melt, and restore trust returned signatures without cryptographic verification

Unblinding occurs at `CashuWallet.php:3659-3680`, `3802-3814`, `3927-3939`, and `4694-4705`. DLEQ data is stored but not verified. A corrupt, reordered, or invalid signature can become reported balance and fail only on later spend. Verify response association, denomination, keyset, resulting BDHKE signature, and DLEQ where supplied before persistence or journal deletion.

### CWP-H4: Returned signatures are associated primarily by array position

Mint, melt, and swap index `blindingData[$i]` without strict per-output correspondence checks. A reordered response can unblind each signature with the wrong factor. Require exact cardinality where protocol-defined, exact amount/keyset agreement, and `B_` matching where available.

### CWP-H5: `INSERT OR REPLACE` resurrects known proofs as `UNSPENT`

`CashuWallet.php:2530-2534` replaces a duplicate row and unconditionally writes `UNSPENT`. Re-importing a spent, pending, or exported proof can restore it to local balance and erase lifecycle metadata. Use `ON CONFLICT DO NOTHING` for ordinary imports, or an explicit monotonic reconciliation policy that never moves a terminal or outgoing state backward without authorization.

### CWP-H6: Conditional proof witnesses are discarded by storage

`Proof` supports a witness, but `cashu_proofs` at `CashuWallet.php:2391-2404` has no witness column, and storage/reload at `2530-2565` and `2720-2732` omits it. Persist every security-relevant proof field losslessly and add migration and round-trip tests.

### CWP-H7: Pending operation IDs can collide across wallet namespaces

`cashu_pending_operations.id` is globally primary at `CashuWallet.php:2413-2420`, while IDs such as `mint:<quote>` and `melt:<quote>` omit wallet identity. `savePendingOperation()` uses `INSERT OR REPLACE` at `2833-2847`. Two mints issuing the same quote ID can replace one another's recovery record. Use a composite `(account_id, operation_id)` primary key or globally random operation ID plus a scoped idempotency key.

### CWP-H8: Restore classifies missing or failed proof-state checks as spendable

`CashuWallet.php:4839-4872` defaults missing states to `UNSPENT` and, on any exception, retains all recovered proofs as unspent. That can inflate balance with already spent proofs. Introduce `UNKNOWN`/`RECONCILIATION_REQUIRED`, require exact `Y` coverage, and never make unknown proofs selectable.

### CWP-H9: Proof-state responses are associated by position, not `Y`

`syncProofStates()` at `CashuWallet.php:3215-3239`, public checking, and restore state filtering trust response order. A reordered or partial response updates the wrong proof. Map requested `Y` values to proofs, reject duplicate/unknown/omitted replies, and normalize only recognized states.

### CWP-H10: Restore computes the next deterministic counter from an invalid formula

`restoreBatch()` knows each matched counter at `CashuWallet.php:4662-4666` but discards it when returning proofs. `restore()` estimates the next counter at `4830-4833` from the scan boundary plus proof count. Proof count is unrelated to the highest used counter when there are gaps. Return `{proof, counter}` metadata and persist `max(existing, highestMatched + 1, highestReserved + 1)`. The current formula often over-advances rather than reuses counters, but it is not a valid recovery guarantee.

### CWP-H11: Inactive keyset fee metadata is discarded

`CashuWallet.php:3444-3457` loads only active keysets into the fee lookup, while `3527-3538` returns zero for unknown keysets. Wallets commonly retain proofs from inactive keysets. Load metadata for all unit keysets, use only an active one for outputs, and fail closed when an input fee is unknown.

### CWP-H12: Token receive does not enforce unit equality

Receive paths around `CashuWallet.php:4052-4107` compare mint but not unit. A same-mint fiat token can be stored or swapped by a sat wallet and misreported. Require exact canonical unit equality. Cross-unit conversion must be a separate quote-based API.

### CWP-H13: Lightning Address invoices are not verified against the request

`CashuWallet.php:5282-5337` accepts the callback's BOLT11, and `5139-5165` pays the resulting melt quote without proving it represents the requested amount or LNURL metadata. A malicious callback can return a larger invoice. Decode BOLT11 with a vetted implementation and verify amount, description hash, network, and expiry. At minimum, require sat-wallet melt quote amount to equal the requested sats.

### CWP-H14: HTTP mint URLs are accepted

The mint client correctly verifies HTTPS certificates, but constructors allow `http://`. Swap and melt transmit bearer proofs over that channel. Require HTTPS by default; allow explicit loopback development only through a clearly unsafe option.

### CWP-H15: Plaintext bearer credentials and permissive filesystem creation

`CashuWallet.php:2391-2404` stores proof secrets in plaintext; `2366-2370` creates directories as `0755`; database permissions depend on umask. Attempt `0700` directories and `0600` DB/WAL/SHM files, expose diagnostics, and document storage outside web root. Optional authenticated encryption should use a key not colocated with the database.

### CWP-H16: Public counter setters are not persisted

`CashuWallet.php:4493-4499` and `4528-4534` modify only in-memory arrays although documentation presents them as restore tools. Persist monotonic updates when storage exists. Give any unsafe counter-lowering API an unmistakable name and explicit override.

### CWP-H17: Experimental NUT-18 payment-request methods are broken and unsafe to expose

`createPaymentRequest()` and parsers instantiate required-argument classes with no arguments around `CashuWallet.php:4933-5044`; `payRequest()` calls undefined `createToken()` at `5073` after a funds-moving split; HTTP delivery at `5101-5123` has no durable ambiguous-delivery lifecycle. Disable or remove these methods until one specification-compliant, journaled, tested implementation exists.

## Medium Findings

### CWP-M1: Token parsing lacks strict resource and structural limits

Base64 decoding is not consistently strict; JSON and CBOR parsing lack input size, nesting, collection, and trailing-data controls. Add strict base64, bounded body and proof counts, checked CBOR reads, depth limits, no trailing bytes, and consistent `CashuException` wrapping.

### CWP-M2: V3 multi-mint tokens are flattened under the first mint

The parser around `CashuWallet.php:2183-2195` merges proof groups while retaining one mint. Reject multiple distinct mints or expose a multi-mint structure and process each independently.

### CWP-M3: CBOR byte strings are selected by a content heuristic

The serializer around `CashuWallet.php:1845-1851` and `2025-2035` can encode printable binary as text even where V4 requires bytes. Use explicit byte-string wrapper types and official NUT-00 vectors.

### CWP-M4: Numeric overflow and float conversion are incompletely controlled

Amount parsing, `array_sum`, fee accumulation, LNURL `* 1000`, and comparator subtraction can overflow or promote to float. Validate positive integer protocol amounts under a configured maximum and use checked integer addition/multiplication and decimal-string parsing.

### CWP-M5: Quote fields and lifecycle are weakly validated

Before mint/melt, validate quote ID, unit, amount, expiry, nonnegative fee reserve, and allowed state against locally recorded metadata. Do not rely entirely on a conforming mint to reject caller mistakes.

### CWP-M6: Counter increment conflicts with nested transactions

`CashuWallet.php:2778-2797` always executes raw `BEGIN IMMEDIATE`, while the class exposes transaction methods. Replace per-counter increments with transaction-aware range reservation as part of operation preparation.

### CWP-M7: Transaction handlers catch `Exception` rather than `Throwable`

`storeProofs()` and related transaction code can miss `TypeError` or `ValueError`. Catch `Throwable`, rollback owned transactions, and rethrow a safe typed exception.

### CWP-M8: BIP-39 normalization and extension documentation are incomplete

Mnemonic handling around `CashuWallet.php:1073-1083` does not perform full NFKD normalization, including passphrases, and mbstring is used without being a documented hard requirement. Implement BIP-39 normalization and explicitly check required extensions or provide tested fallbacks.

### CWP-M9: Keyset IDs and public keys are not fully cross-validated

The library has keyset-ID derivation logic but accepts downloaded keys without confirming that compatible IDs derive from that material or validating all points up front. Validate canonical secp256k1 points, denominations, and modern keyset IDs before activation.

### CWP-M10: Generic pending-operation cleanup can delete recovery evidence

`CashuWallet.php:2921-2935` deletes by timestamp without operation-specific reconciliation. Money-operation journals should never be blindly cleaned. Archive committed operations; retain unresolved ones until a definitive result or explicit operator resolution.

### CWP-M11: Custom secp256k1 implementation increases assurance risk

Secret-dependent scalar multiplication is pure-PHP, variable-time textbook arithmetic, and point parsing needs stricter canonical checks. Prefer a reviewed `libsecp256k1` binding where deployable. Because commodity hosting may not offer it, retain a pure-PHP fallback only with extensive known-answer, malformed-point, and differential tests, and clearly document the side-channel limits.

## Low Findings

- BIP-32 invalid-child derivation throws rather than advancing to the next index as specified.
- Proof states are arbitrary strings without a database `CHECK` constraint or enum validation.
- Mint URL canonicalization is incomplete, which can create duplicate namespaces or false token-mint mismatches.
- Mnemonic and derived secret material remain in ordinary PHP strings; minimize retention and use `sodium_memzero()` where meaningful.
- Documentation and implementation defaults are out of sync in restore and example behavior.

## Positive Findings

- `random_bytes()` is used for mnemonic entropy, secrets, scalars, and identifiers.
- Random scalar generation uses rejection sampling and excludes invalid values.
- Blind and unblind formulas are structurally correct.
- Mint persists deterministic counter/output recovery data before contacting the mint.
- Mint rejects a response whose signature count differs from the output count.
- HTTPS mint calls explicitly verify certificate chains and hostnames.
- Empty and invalid JSON HTTP responses are treated as errors.
- Proof batches are inserted transactionally.
- Counter increment uses `BEGIN IMMEDIATE`, improving concurrency over a plain read/update.
- Restore never intentionally lowers an already persisted counter.
- Wallet debug and serialization hooks reduce accidental mnemonic disclosure.
- Mnemonic generation requires configured storage.
- Prepared SQL statements are used for data values.

## Recommended Library Architecture

### Explicit account identity

The application, not the mint URL, should own identity:

```php
$wallet = new Wallet(
    mintUrl: $mintUrl,
    unit: $unit,
    dbPath: $dbPath,
    accountId: $accountId
);
```

Persist metadata binding that account to seed fingerprint, canonical mint, unit, and schema version.

### Durable operation state machine

Use one operation row for mint, swap, melt, and receive:

| State | Meaning |
|---|---|
| `prepared` | Exact outputs/counters are stored and inputs are reserved |
| `submitted_unknown` | Request may have reached the mint; no safe assumption is possible |
| `remote_succeeded` | Success is known or outputs were restored; local finalization remains |
| `committed` | Outputs and input states are atomically finalized |
| `failed_final` | Mint state proves inputs were not consumed |
| `needs_attention` | Automatic reconciliation cannot decide safely |

The operation should hold account ID, stable idempotency key, quote ID, input secrets/Ys, output amounts, keysets, reserved counter range, blinded messages, attempts, response summary, and errors.

### Preparation transaction

1. `BEGIN IMMEDIATE`.
2. Verify account metadata and seed fingerprint.
3. Reserve a contiguous counter range.
4. Atomically transition selected inputs from `UNSPENT` to `PENDING` with `reserved_by`.
5. Insert the complete operation plan.
6. Commit.

Contact the mint only after commit.

### Finalization transaction

1. Strictly validate and cryptographically verify every returned signature.
2. Insert every output/change proof without state regression.
3. Transition only the operation's reserved inputs to `SPENT`.
4. Mark the operation `committed`.
5. Commit.

### Recovery

- Mint: retry exact outputs or restore them when the quote is issued.
- Swap: check input states; if spent, restore exact recorded outputs; if conclusively unspent and failed, release inputs.
- Melt: poll quote; if paid, finalize inputs and recover change; if conclusively expired-unpaid, release inputs.
- Unknown or partial mint responses: keep `needs_attention`; never guess `UNSPENT`.

## Prioritized Remediation Plan

### Priority 0: Core fund safety

1. Add explicit account identity and seed fingerprint validation.
2. Journal and reserve every swap and melt before network I/O.
3. Atomically finalize inputs, outputs, change, and operation state.
4. Remove `INSERT OR REPLACE` proof-state regression.
5. Disable broken NUT-18 methods.

### Priority 1: Validation and recovery

1. Verify signatures, DLEQ, keysets, amounts, cardinality, and response association.
2. Harden melt change accounting.
3. Match proof states by `Y` and classify unknown states conservatively.
4. Fix restore to retain exact matched counters.
5. Persist witnesses and all proof metadata losslessly.

### Priority 2: Protocol and API correctness

1. Load inactive keyset fee metadata.
2. Enforce receive unit equality.
3. Validate quote lifecycle and LNURL invoices.
4. Require HTTPS and harden URL handling.
5. Add strict parser and numeric limits.

### Priority 3: Assurance and hardening

1. Build deterministic unit, integration, concurrency, and crash-injection tests.
2. Add official Cashu, BIP-39, BIP-32, NUT-13, and serialization vectors.
3. Differential-test GMP and BCMath backends.
4. Prefer a reviewed native secp256k1 implementation when available.
5. Document filesystem, backup, recovery, and side-channel assumptions honestly.

## Mandatory Test Plan

### Crash consistency

- Terminate before journal creation, after input/counter reservation, after the mint commits, after response receipt, during proof insertion, and before operation commit.
- Inject disk-full, busy, malformed-row, and process-fatal failures at every write.
- Verify conservation and automatic recovery for mint, swap, melt with change, melt without change, receive, and export-oriented split.

### Concurrency

- Multiple processes reserve counters simultaneously.
- Multiple processes select the same proofs.
- Restore runs while an active operation is prepared.
- Two mints return the same quote ID in one database.
- Nested caller transactions do not corrupt counter or proof state.

### Restore and identity

- Previously used seed with empty storage fails closed.
- Different seed with existing account fails closed.
- Sparse counters, large burned gaps, old keysets, multiple units, and pending reservations produce a safe high-water mark.
- Partial, reordered, duplicated, and unavailable `/restore` and `/checkstate` responses never create spendable unknown proofs.

### Cryptography

- Official hash-to-curve, BDHKE, DLEQ, BIP-39, BIP-32, and NUT-13 vectors.
- Reject malformed, non-canonical, off-curve, infinity, and wrong-keyset points.
- Mutate every returned signature field and require rejection before persistence.
- Compare GMP and BCMath results across randomized vectors.

### Serialization and hostile input

- Official V3/V4 vectors and witness/DLEQ SQLite round trips.
- Multi-mint V3 behavior.
- Truncated/deep/oversized CBOR, strict base64, trailing data, integer boundaries, duplicate fields, and large proof arrays.

### Mint and LNURL behavior

- Missing, extra, reordered, duplicate, wrong-amount, and wrong-keyset signatures.
- Negative/overflowing quote amounts and fees.
- LNURL invoice amount mismatch, description-hash mismatch, expiry, network mismatch, redirects, private addresses, and malformed callbacks.

## Final Assessment

The library's mint retry design demonstrates the right core idea: persist deterministic recovery information before crossing the network. That same discipline must cover swap, melt, receive, proof reservation, and local finalization before the library can safely custody funds.

Until those changes and failure-injection tests are complete, callers should treat the library as experimental and limit balances to amounts they can lose.
