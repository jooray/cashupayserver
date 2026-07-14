# CashuPayServer Reliability and Security Audit

**Audit date:** 2026-07-15  
**Audited revision:** `54d4efb36aa10634b45ada38a9834cb4b09eb9fc`  
**Wallet submodule revision:** `f34dc28a104b7db82de2a06b5e57232f6cbcafcd`  
**Scope:** CashuPayServer application, setup, API, administration, invoice lifecycle, outgoing payments, webhooks, background processing, SQLite persistence, WordPress integration, packaging, and shared-hosting operation. The wallet library has a separate report in `GPT-56-CASHU-WALLET-PHP-AUDIT.md`.

## Executive Summary

CashuPayServer has a sound product direction and several meaningful safeguards. In particular, invoice minting uses an atomic claim, the wallet journals deterministic mint outputs, proofs are persisted before an invoice is settled, SQLite uses WAL and a busy timeout, API keys are hashed, and webhook requests are signed. Those choices substantially improve the happy path and some crash paths.

The current revision is nevertheless **not ready to custody meaningful production funds**. The most serious defects are:

1. Different stores using the same mint and unit share one proof, counter, and pending-operation namespace. One store can display or spend another store's bearer proofs.
2. A normal token export becomes spendable by the sender again if the recipient has not yet redeemed it. A later export can issue the same bearer proofs twice.
3. Outgoing swap and melt operations are not fully journaled, reserved, finalized, or recovered. A timeout can leave the application unable to determine whether funds moved.
4. Settlement webhooks are not written to a durable outbox in the settlement transaction. A process death can permanently suppress fulfillment notification after funds were received.
5. Standalone setup can be claimed by whoever reaches a fresh installation first, while upgrades can run new code against an old database schema.

The shared-hosting requirement is achievable without Redis, daemons, shell access, or custom PHP settings. The best design is still one PHP application and one SQLite database, but every money-moving action must be represented by durable state and resumed in short cron batches. The setup wizard should configure and verify this rather than implying that opportunistic page requests are enough.

## Overall Rating

| Area | Rating | Reason |
|---|---|---|
| Incoming invoice minting | Promising, not production-ready | Good deterministic mint journal and proof-before-settlement ordering; migration, wallet identity, and webhook gaps remain |
| Token export | Unsafe | Exported proofs can be returned to local `UNSPENT` and issued again |
| Lightning withdrawal | Unsafe under timeout/concurrency | No universal pre-request reservation/journal and no application recovery runner |
| Multi-store isolation | Unsafe | Storage identity omits store and seed |
| Webhook reliability | Insufficient | No transactional outbox |
| Setup and upgrades | Insufficient | Fresh-install takeover, weak preflight, no reliable automatic migrations |
| Shared-hosting operability | Feasible but incomplete | No bounded unified runner, health dashboard, or WAL-aware backup UI |
| Automated assurance | Insufficient | No automated test suite was found |

## Severity Definitions

- **Critical:** Normal operation or a realistic configuration can directly misdirect, duplicate, or expose funds, or permit unauthenticated control of a fresh standalone instance.
- **High:** A crash, timeout, concurrency race, upgrade, or compromised privileged integration can cause loss, false settlement, indefinite ambiguity, or a major security failure.
- **Medium:** Material reliability, security, accounting, or setup defect that usually needs an additional condition or has a manual recovery path.
- **Low:** Correctness, diagnostics, packaging, or hardening issue with limited direct impact.

## Critical Findings

### CPS-C1: Stores with the same mint and unit share one wallet namespace

**Evidence**

- `includes/invoice.php:450-474` creates a wallet with mint URL, unit, database path, and seed, but passes no store-owned storage identity.
- `cashu-wallet-php/CashuWallet.php:2364-2377` derives `walletId` only from `hash(mintUrl . ':' . unit)`.
- `cashu-wallet-php/CashuWallet.php:2391-2420` scopes proofs, counters, and pending operations by that value.
- `includes/invoice.php:698-745` independently derives the same namespace for balance and proof access.

**Failure scenario**

Store A and Store B use different seeds but the same mint and `sat` unit. A customer pays Store A. The resulting proofs are stored in the namespace that Store B also opens. Store B sees the balance and can export or melt Store A's bearer proofs. Both seeds also share deterministic counters and recovery records, making later recovery ambiguous.

**Recommendation**

- Introduce an immutable random `wallet_account_id` for each seed + canonical mint + unit account.
- Make the library accept this explicit account ID; do not infer ownership from a URL.
- Store a non-secret seed fingerprint, canonical mint URL, and unit alongside the account ID and block mismatches.
- Pin every invoice and outgoing operation to `wallet_account_id`.
- Immediately block creating a second store with the same legacy mint/unit namespace until migration exists.
- Do not blindly relabel existing rows. If multiple stores already share a legacy namespace, freeze outgoing operations, restore each seed separately, and reconcile proofs before assignment.

### CPS-C2: Exported tokens can be made locally spendable and exported again

**Evidence**

- `admin.php:1364-1366` marks exported proofs `PENDING` after returning the token.
- `admin.php:1138-1142` calls `Invoice::checkPendingProofs()` at the start of the next export.
- `includes/invoice.php:837-859` treats every returned state other than `SPENT` as locally `UNSPENT`; it also defaults missing state to `UNSPENT` and associates replies by position.

**Failure scenario**

The merchant exports a token and sends it to a recipient. Until the recipient redeems it, the mint correctly reports those bearer proofs as `UNSPENT`. On the next export, CashuPayServer changes the sender's local copy from `PENDING` back to `UNSPENT` and can include it in another token. Two recipients now possess valid copies and race to redeem them. One loses despite receiving a token the server presented as valid.

**Recommendation**

- Distinguish `EXPORTED` or `PENDING_OUTGOING` from a mint's NUT-07 `PENDING` state.
- Never automatically return an exported proof to the spendable pool merely because the mint says `UNSPENT`.
- Persist an export operation and the serialized token before presenting it to the user.
- Allow explicit operator actions: re-display the same token, confirm redemption, or cancel/reclaim it with a warning and a mint swap that invalidates the old token.
- Match NUT-07 results by `Y`, require exact response coverage, and classify missing/unknown responses as `UNKNOWN`, never `UNSPENT`.
- Serialize export, melt, donation, and swap using one per-wallet operation lock plus database proof reservation.

### CPS-C3: Fresh standalone installations can be claimed remotely

**Evidence**

- `setup.php:44-47` initializes storage before setup is complete.
- `setup.php:60-137` accepts setup actions and administrator password creation without authentication, a bootstrap secret, or CSRF validation.
- `setup.php:190-284` writes the seed and marks setup complete through the same public flow.
- WordPress's normal rewritten route has a `manage_options` guard, so this finding primarily affects standalone installations and direct-entry packaging mistakes.

**Failure scenario**

A user uploads the release and plans to open setup later. An Internet scanner reaches it first, posts directly to the password step, and completes setup. The attacker controls the administrator account and wallet configuration.

**Recommendation**

- Generate a one-time setup secret and require it on every setup request.
- A shared-host-friendly option is a random token in a file outside the document root, or a challenge that asks the user to create a specifically named file through File Manager.
- Add session CSRF tokens to all setup POST and AJAX actions.
- Enforce server-side step progression and bind it to one setup transaction ID.
- In WordPress packages, make every direct PHP entry point load WordPress and require `manage_options`, or refuse direct access.
- Invalidate the bootstrap token permanently after setup.

## High Findings

### CPS-H1: Pending and ambiguous melts are not recovered by the application

**Evidence**

- `cashu-wallet-php/CashuWallet.php:3274-3358` implements `recoverPendingMelts()`.
- `cron.php:67-230` never calls it.
- `cron.php:179-198` only deletes expired pending-operation rows.
- `includes/lightning_address.php:56-97` and `328-365` turn a pending melt into an exception but do not persist a server withdrawal record or drive reconciliation.

**Impact**

A mint can pay a Lightning invoice while the HTTP response is lost, or legitimately return `PENDING`. The server may show a generic error, permit another withdrawal attempt, and leave proofs or fee change unresolved indefinitely. A no-change melt can lack even the library journal; see the library report.

**Recommendation**

- Run melt reconciliation before new withdrawals and as the first cron task.
- Persist a server operation before requesting a melt quote: operation ID, account, destination, invoice/payment hash, quote ID, amount, selected proof identifiers, state, and timestamps.
- Report ambiguous results as `waiting_remote`, not failed or safely retryable.
- Permit only one active outgoing operation per wallet account initially.
- Never age-delete unresolved money-operation journals.

### CPS-H2: Webhook settlement has no transactional outbox

**Evidence**

- `includes/invoice.php:339-359` commits settlement before flushing a process-local webhook queue.
- `includes/invoice.php:433-500` stores that queue only in memory.
- `includes/webhook_sender.php:81-99` performs HTTP delivery before inserting the delivery row.
- `includes/webhook_sender.php:215-255` can retry only attempts that were successfully recorded.

**Impact**

If PHP exits after invoice settlement commits but before delivery is recorded, the merchant has the funds but WooCommerce never receives `InvoiceSettled`. If the receiver accepts a webhook and PHP dies before logging it, later manual recovery can duplicate it without a stable durable event record.

**Recommendation**

- Add `webhook_events` and `webhook_outbox` tables.
- Insert one unique logical event and subscribed outbox rows in the same transaction as the invoice transition.
- Claim rows with a short SQLite lease, send outside a transaction, and record attempts afterward.
- Keep one stable event ID across redeliveries and expect receiver deduplication.
- Retry for days with exponential backoff; show exhausted events as dead letters in admin.

### CPS-H3: A modify-invoice API key can settle an unpaid invoice

**Evidence**

- `includes/api/invoices.php:91-106` permits `Settled` to callers with `btcpay.store.canmodifyinvoices`.
- `includes/api/invoices.php:113-120` explicitly permits a `New` invoice to become `Settled` without checking the quote or local proofs.
- `includes/invoice.php:209-232` emits `InvoiceSettled` for that update.

**Impact**

A compromised or over-scoped e-commerce key can release goods without payment. The permission requirement lowers this from unauthenticated compromise, but it still violates settlement integrity.

**Recommendation**

- Remove manual `Settled` from the Greenfield endpoint.
- Require committed local proofs tied to the invoice quote for payment-confirmed settlement.
- If an accounting override is required, expose a separately named administrator-only status that does not emit a normal payment webhook and is recorded in an immutable audit log.

### CPS-H4: Database migrations do not reliably run on upgrade

**Evidence**

- `includes/database.php:162-302` runs migrations only from `Database::initialize()`.
- `setup.php:30-47` calls initialization only when the database lacks the existing `config` table.
- Normal API, admin, and cron startup do not run a schema-version check.
- WordPress activation calls initialization, but ordinary plugin updates need not rerun activation.

**Impact**

FTP replacement or a normal WordPress update can run new code against an old schema. For example, the invoice claim uses `processing_since`; a missing column can prevent paid invoices from being minted.

**Recommendation**

- Add a numbered `schema_migrations` ledger and required schema version constant.
- Perform a cheap version check on every entry point.
- When behind, acquire a file or SQLite migration lock, create a verified backup, and run migrations automatically.
- Block fund-moving routes with a clear maintenance page if migration fails or the database is newer than the code.
- Provide an authenticated web upgrade UI; do not require PHP CLI.

### CPS-H5: Store wallet identity can be mutated while value or invoices exist

**Evidence**

- `admin.php:558-601` permits direct changes to mint URL, unit, and seed phrase.
- `includes/invoice.php:310-320` uses an invoice's recorded mint URL but the store's current unit and seed.
- `includes/invoice.php:450-469` always loads current store wallet settings.

**Impact**

Changing seed or unit while a quote is paid but not minted can make deterministic recovery use the wrong wallet. Changing mint hides old balances and recovery state. Existing invoices do not pin a complete immutable wallet identity.

**Recommendation**

- Make wallet identity immutable.
- Replace edits with “create new wallet account” and “retire old account” workflows.
- Preserve old accounts indefinitely while they have proofs, unresolved quotes, or operations.
- Block retirement unless all states are reconciled and the verified balance is zero.

### CPS-H6: Outgoing operations are not serialized or atomically reserved

**Evidence**

- Manual melt has a file lock in `admin.php:864-870`, but export, donation, cron auto-melt, and library proof selection do not share one wallet-wide lock.
- `includes/lightning_address.php:123-175` can start auto-melt without a per-wallet claim.
- `admin.php:1108-1397` exports without the melt lock.
- `cron.php` has no global overlap lock.

**Impact**

Two cron calls, two browser tabs, or export plus auto-melt can select the same proofs. The mint rejects one double spend, but the loser can be left in an ambiguous state after counters, swaps, or external delivery occurred.

**Recommendation**

- Add a non-blocking global runner lease.
- Add atomic compare-and-set proof reservation in the library.
- Initially allow only one active money-moving operation per wallet account.
- Treat file locks as a secondary optimization, not the source of truth.

### CPS-H7: Donation delivery discards the only convenient token copy on failure

**Evidence**

- `includes/lightning_address.php:428-441` marks donation proofs spent locally before posting the token.
- `includes/lightning_address.php:452-483` returns no result and only logs network or HTTP failure.
- `admin.php:1233-1239` and `1313-1324` similarly mark donation proofs spent after a fire-and-forget call.

**Impact**

A five-second outage can cause the application to report success while the donation sink never received the token. The proofs may remain cryptographically recoverable, but the server hides them and does not retain a durable outgoing token for retry.

**Recommendation**

- Persist the serialized donation token and operation before delivery.
- Keep proofs in a distinct outgoing state.
- Retry with a stable idempotency key and authenticated acknowledgment.
- Let the operator re-display or reclaim an undelivered token.
- Disable automatic donation if durable delivery cannot be provided.

### CPS-H8: WordPress background processing omits critical tasks

**Evidence**

- `wordpress/cashupay.php:28-38` runs only quote polling.
- `cron.php:67-230` contains recovery, auto-melt, webhook retry, and cleanup work absent from WP-Cron.
- WP-Cron is traffic-driven and may be disabled.

**Impact**

Late paid invoices, pending melts, failed webhooks, and auto-withdrawal can remain unresolved on quiet WordPress sites even though the plugin appears configured.

**Recommendation**

- Put all tasks in one time-budgeted `BackgroundRunner` used by HTTP cron, WordPress cron, and a “Run now” button.
- Persist per-task last success and error state.
- Strongly recommend a real one-minute hosting cron and show a dashboard warning until its heartbeat is observed.

### CPS-H9: Secrets and bearer proofs are plaintext in a database that may be under web root

**Evidence**

- `includes/database.php:174-255` stores seed phrases and webhook secrets in plaintext.
- Wallet proof secrets are plaintext in `cashu_proofs`.
- `includes/database.php:34-53` defaults to an application-local `data` directory.
- `.htaccess` does not protect nginx or a host that ignores overrides; database, WAL, and SHM files contain spend credentials.

**Impact**

A web-server mistake, backup leak, shared-host neighbor, or downloadable WAL file exposes current bearer proofs and wallet recovery seeds.

**Recommendation**

- Default to storage outside document root and make that the primary File Manager setup path.
- Verify DB, WAL, SHM, session, cache, backup, and probe files with a ranged GET; block setup on any successful 2xx response.
- Attempt `0700` directories and `0600` files, then report actual permissions if chmod is restricted.
- Encrypt seeds and long-lived secrets with an authenticated cipher using a key outside the database and document the recovery tradeoff.
- Keep in mind that at-rest encryption does not protect against live PHP compromise.

### CPS-H10: Outbound mint and LNURL requests retain SSRF exposure

**Evidence**

- `setup.php:146-165` accepts any syntactically valid HTTP(S) mint and immediately contacts it.
- `cashu-wallet-php/CashuWallet.php:5228-5321` follows redirects for Lightning Address metadata and callback requests without public-IP validation.
- Webhook validation resolves DNS separately from cURL delivery, leaving a DNS-rebinding gap.

**Impact**

Setup or a malicious Lightning Address can make the server contact loopback, private, link-local, metadata, or control-panel services. Redirects and DNS rebinding bypass simple preflight checks.

**Recommendation**

- Centralize outbound HTTP policy for mints, webhooks, LNURL, and pairing.
- Require HTTPS in production.
- Resolve A and AAAA, reject non-public addresses, pin the validated address with `CURLOPT_RESOLVE`, and revalidate each redirect or disable redirects.
- Validate the LNURL callback independently from the original domain.
- Allow localhost only under an explicit development flag.

## Medium Findings

### CPS-M1: Backup-mint funds are displayed but primary-only spend paths cannot use them

`includes/invoice.php:698-725` aggregates all configured mints into one balance, while `includes/invoice.php:734-745`, `getWalletInstance()`, export, and melt select only the primary mint. Show balances by wallet account and require a source account for withdrawal/export. Auto-melt must run independently per account.

### CPS-M2: Late-payment recovery stops after 72 hours

`includes/invoice.php:21-30` defines a fixed 72-hour grace, and `includes/invoice.php:642-655` never checks older expired quote IDs. Continue reconciling unresolved quotes with increasing backoff until the mint proves a terminal state. Never delete unresolved quote mappings by age.

### CPS-M3: Invoice and webhook state transitions can duplicate or contradict each other

`includes/invoice.php:64-71` allows any non-settled status, including `Invalid`, to become settled. `markExpiredInvoices()` selects, bulk-updates, then emits outside a unique transition record. Centralize allowed transitions, use conditional updates, and create one unique event in the same transaction.

### CPS-M4: Invoice polling occurs before store authorization

`includes/api/invoices.php:71-83` polls by invoice ID before checking that it belongs to the authenticated store. Fetch with `WHERE id = ? AND store_id = ?` first, then poll the authorized invoice.

### CPS-M5: Input and request-body validation is too permissive

`includes/api/invoices.php:21-42` accepts any `is_numeric()` amount and uncapped metadata/checkout JSON. API and receive entry points read whole bodies. Define decimal syntax, positive bounds, currency precision, supported units/currencies, metadata depth/size, pagination bounds, proof count limits, and route-specific body limits.

### CPS-M6: Rate limiting is not an atomic read-modify-write and ignores custom data storage

`includes/security.php:306-344` stores cache under the source tree and separately reads and writes files. Use an atomic SQLite UPSERT or hold one file lock across read/update/write. Put every runtime file under `Database::getDataDir()`.

### CPS-M7: Setup preflight is late and incomplete

The wizard loads database/session code before checking extensions. It accepts “GMP or BCMath,” but exchange-rate code uses BCMath unconditionally, and the wallet uses mbstring. Preflight before loading the application and require `pdo_sqlite`, `curl`, `json`, `bcmath`, and `mbstring`; treat GMP as recommended unless all code paths are verified without it. Test writable storage, lock creation, session persistence, and free space.

### CPS-M8: Setup can continue after a failed database-exposure test

The server checks only an acknowledgment checkbox, not a recorded successful probe. Create only a random probe before the real database, block continuation on exposure, test all sidecars with GET rather than HEAD alone, and make any expert override explicit and alarming.

### CPS-M9: A seed restore failure does not block setup

`setup.php:226-265` logs restore failure and continues; `setup.php:278-283` can still finish. Existing-seed setup must remain in `recovery_required` until restore and state verification succeed. Run restore as resumable cron batches so a shared-host request timeout does not force an unsafe override.

### CPS-M10: Base URL and proxy detection can produce wrong public URLs

Allow the user to confirm an editable public HTTPS base URL, test it, and store it. Do not derive security-sensitive URLs indefinitely from request headers.

### CPS-M11: Cron credentials are encouraged in query strings and self-request TLS is disabled

Use `X-Cron-Key` for cPanel/Plesk curl commands, retain query keys only for URL-only web-cron services, support key rotation, keep TLS verification enabled, and derive loopback targets from trusted configuration.

### CPS-M12: There is no complete, WAL-aware backup and restore workflow

The seed does not preserve invoices, API keys, webhook state, operations, or configuration. A live copy of only `cashupay.sqlite` can omit WAL data. Add an authenticated encrypted backup download using SQLite online backup or `VACUUM INTO`; include application/schema metadata and checksum; provide a restore validation screen.

## Low and Packaging Findings

- `cron.php:107-129` reports proof synchronization without calling `syncProofStates()`.
- `cron.php:75-87` treats `checkAutoMelt()` as one result although it returns a list.
- `cron.php:11-12` shows a six-field cron expression and overstates that cron is optional.
- Version values differ among `includes/config.php`, API server info, and the WordPress header.
- The admin references a PWA manifest but has no application-version refresh mechanism. If service-worker caching is added, automatic version detection and reload are mandatory for a financial UI.
- The WordPress release script appears to place support PHP files under `wordpress/` while the root plugin file requires them relative to itself.
- Dockerfiles reference a `templates/` directory not present in the audited tree.
- No automated test suite was found; `TESTING.md` is a manual integration guide.

## Positive Findings

- `includes/invoice.php:37-57` uses a conditional atomic claim to reduce duplicate mint calls.
- `includes/invoice.php:341-367` relies on wallet proof persistence before invoice settlement and has orphan recovery paths.
- The wallet journals deterministic mint outputs before the network request.
- SQLite is configured with WAL, foreign keys, exceptions, and a busy timeout.
- API keys are hashed rather than stored directly for external API authentication.
- Main admin POST actions use authentication and CSRF protection.
- Sessions use secure cookie settings and regenerate identifiers on login.
- Webhooks use HMAC signatures and avoid retaining arbitrary response bodies.
- Router and Apache rules attempt to block direct access to sensitive paths.
- WordPress uninstall preserves data rather than deleting bearer funds.

## Recommended Target Architecture

The project does not need a heavy queue framework. A shared-host-compatible target can use these small pieces:

1. **Wallet registry:** `wallets` and `wallet_accounts` with explicit immutable IDs, seed fingerprints, canonical mint URL, and unit.
2. **Library operation journal:** exact inputs, counter ranges, outputs, quote IDs, state, attempts, and recovery data for mint, swap, melt, and receive.
3. **Server operation table:** business intent for invoice mint, withdrawal, export, donation, receive, and restore, linked to the library operation.
4. **Webhook outbox:** logical event, destination snapshot, attempt state, lease, and retry schedule.
5. **Bounded runner:** one global lease, approximately 20-second time budget, small batches, and resumable work.
6. **Migration ledger:** numbered migrations, automatic backup, integrity checks, and fail-closed startup.
7. **Backup UI:** consistent encrypted SQLite backup plus separate seed verification.

All network calls should occur outside SQLite write transactions. Short `BEGIN IMMEDIATE` transactions should claim jobs, reserve proofs/counters, and atomically finalize local state.

## Prioritized Improvement Plan

### Priority 0: Stop direct fund conflicts

1. Prevent different stores from opening the same legacy mint/unit wallet namespace.
2. Stop returning exported proofs to `UNSPENT`; disable or quarantine export until its lifecycle is durable.
3. Remove API settlement of unpaid invoices.
4. Protect standalone setup with a bootstrap secret and CSRF.
5. Stop deleting unresolved invoices and pending money-operation journals.

### Priority 1: Make outgoing funds recoverable

1. Implement library pre-request journals and atomic proof reservation for every swap and melt.
2. Add server withdrawal/export records and stable idempotency keys.
3. Call recovery before new outgoing work and from every cron run.
4. Add one active-operation limit per wallet account.
5. Make donation delivery durable or disable it.

### Priority 2: Guarantee convergence

1. Add the transactional webhook outbox.
2. Reconcile unresolved mint quotes without a 72-hour cutoff.
3. Unify standalone cron and WP-Cron in a bounded runner.
4. Add visible cron, operation, webhook, and migration health.

### Priority 3: Safe setup, upgrades, and backups

1. Add early extension/filesystem/network preflight.
2. Default to an outside-webroot data directory with File Manager instructions.
3. Add versioned automatic migrations with a web recovery screen.
4. Add consistent encrypted backup and restore validation.
5. Require successful existing-seed recovery before accepting payments.

### Priority 4: Security and assurance

1. Centralize SSRF-safe outbound HTTP with DNS pinning.
2. Encrypt seeds and long-lived secrets at rest.
3. Add strict amount, JSON, body, and proof limits.
4. Build automated concurrency, crash-injection, migration, packaging, and shared-host matrices.

## Mandatory Test Plan

Before production custody, automate at least these cases:

### Conservation and isolation

- Two stores, different seeds, same mint and unit must have isolated proofs, counters, operations, and balances.
- Equivalent mint URL spellings must resolve to one intended account without moving legacy data silently.
- Primary and backup balances must each be independently spendable.
- Duplicate token import must not resurrect spent, exported, or pending proofs.

### Crash injection

- Kill PHP before and after quote creation, operation journal commit, HTTP request, HTTP response, proof storage, invoice settlement, and webhook outbox insertion.
- Inject SQLite busy, disk-full, constraint, and I/O errors at each write boundary.
- Assert that value is either spendable, durably pending, or confirmed spent with replacement output; never silently absent or spendable in two outgoing operations.

### Concurrency

- Two invoice pollers for one quote.
- Two exports, two melts, and export versus auto-melt on one account.
- Two cron invocations and WP-Cron overlap.
- Migration and normal API startup overlap.

### External ambiguity

- Mint commits swap then response times out.
- Mint pays melt then response times out, both with and without change.
- Melt remains pending for hours and later becomes paid or expired-unpaid.
- Donation or token receiver accepts a request but local response is lost.
- Webhook receiver accepts and local process dies before recording success.

### Shared hosting

- PHP 8 supported versions with required extension combinations.
- No loopback HTTP, 30-second hard timeout, no `set_time_limit`, multiple PHP workers, read-only code directory, and writable external data directory.
- Apache without rewrite using `router.php`, nginx ignoring `.htaccess`, WordPress with no traffic, and disabled WP-Cron.
- Upgrade every released schema and install both generated release packages from scratch.
- Backup while WAL has uncheckpointed writes, then restore and verify balances, counters, invoices, operations, and webhooks.

## Final Assessment

CashuPayServer has enough good foundations to improve incrementally, and its shared-hosting goal does not require abandoning PHP or SQLite. The central change is conceptual: the source of reliability must be durable, resumable state rather than long PHP requests, page traffic, or the hope that a remote response arrives exactly once.

Until wallet isolation, exported-token lifecycle, outgoing-operation recovery, migrations, and the webhook outbox are fixed and fault-tested, use should be limited to disposable test amounts.
