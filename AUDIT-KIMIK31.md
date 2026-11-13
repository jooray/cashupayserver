# CashuPayServer security, money-safety and UX audit — Kimi K3.1 Pro

**Date:** 2026-11-13  
**Main revision:** `60c689715be6e32480fb8e495a6676f11d60bbee`  
**cashu-wallet-php:** `7d9f262b8385bfa7c5f942c28f8629660405e58b` (v0.3.0)  
**mint-discovery:** `0c50a6bc8fbbf98528f0f02143dca2bc3f50071c` (v1.1.0)  
**Scope:** standalone server, WordPress plugin, both libraries, customer and administrator workflows.

## Executive summary

This audit was restarted at the requested new commit, not inherited from the prior revision. It reviewed public payments and NUT-18 receive, Greenfield and admin API, setup/recovery, invoice/fiat pricing and settlement, withdrawals/exports/recovery, webhooks/alerts, WordPress lifecycle, both protocol libraries, mint discovery, QR/UR codecs, migrations, data protection and release/update behavior.

**Most important work:** first revoke stale sessions after setup recovery; close melt/restore fund-state gaps; bind the approved fiat price to the actual quote; disallow cleartext transport of bearer proofs; and scope WordPress uninstall to the current site's wallet. Independently, source confirms non-atomic token reservation, unresolved-value and recovery-history blind spots, old-schema webhook breakage, false WooCommerce setup success, and missing browser recovery workflows.

### Certainty and severity

- **23 security leads remain `needs_validation`.** They have concrete source traces, independent candidate review and final source verification, but decisive runtime/deployment evidence is missing. **No CVSS-style vulnerability severity is assigned.** A/B/C below is remediation-validation priority, not severity or exploit certainty.
- **No security finding is represented as runtime-confirmed.** This does not mean no vulnerability exists. No live endpoint, production DB, real mint, public relay, Lightning payment, WordPress site or real credential was contacted. Target-controlled execution was prohibited because the required complete OS sandbox was unavailable, including reliable memory/independent-process/aggregate-disk controls and a running Docker daemon.
- **19 independently source-supported money-safety/UX findings:** **9 P1, 9 P2, 1 P3**. These are observed product-contract defects, not attacker-boundary exploit claims. P1 prioritizes meaningful payment/fund/recovery defects; P2 is important conditional or operability impact; P3 is bounded correctness hardening.
- **Tests and builds were not run.** Existing fixtures and tests were inspected, but no test is claimed to pass. A later dummy-only sandbox is required for timing, browser behavior, accepted requests, file disclosure and mint schedules. Do not test a live shop, public service or real funds.
- **19 coverage units:** 4 covered without a surviving security candidate, 15 with at least one lead; 0 planned, blocked or deferred. The final-clean critic found no additional accepted source coverage.

## Method and effort

Standard security-audit workflow: deterministic coverage units, focused hunter waves and fresh coverage critics, independent candidate refutation, source/schema validation, then final record verification. There were **79 focused review invocations** including replacements and independent money-safety/UX checks; no fixed agent budget was imposed. Both skill validators pass after corrections: **23 findings and 19 coverage units**.

No compatible prior finding/coverage ledger was available. The old wallet audit supplied narrative leads only; its old severities were not carried forward. First-party consumers were traced when library effects mattered. Standalone and WordPress shared the same core but had separately reviewed auth, routing, lifecycle and operator behavior.

## Immediate remediation order

1. **Revoke old sessions after setup recovery** (`setup.php:332-338`, `includes/setup_ownership.php:296-332`, `includes/auth.php:222,252-258`). The hash-only password update does not advance the session generation. Test the previous claimant's cookie through dashboard and wildcard API-key creation after the legitimate owner completes setup.
2. **Prevent recovery and active withdrawal races** (`cashu-wallet-php/CashuWallet.php:8011,8048,6376-6385,5424`). Add attempt leases/last-attempt grace to expiry release and make late PAID handling reassert correct input ownership. Include accepted-before-expiry/all-UNSPENT/late-PAID scheduling in regression tests.
3. **Reconcile omitted change on direct PAID too** (`CashuWallet.php:8122-8172` versus `8087,6491`). Reuse NUT-09 recovery and preserve journal/pending flag when signed-but-omitted outputs are unresolved.
4. **Complete restore against persistent requirement history** (`CashuWallet.php:9859,9891-9894,10022,10105,4763-4801`). A keyset that vanishes from the current listing must not erase an incomplete/error restore row.
5. **Bind fiat preview approval to final quote amount** (`admin.php:1508-1543`, `includes/invoice.php:138,171,212`). Do not perform an unchecked second conversion after the 2% guard.
6. **Require HTTPS for non-development mints** (`CashuWallet.php:3780,7075,8040,3894`). Swap/melt bodies include complete bearer-proof secrets. Public-address/DNS guards do not encrypt HTTP.
7. **Use one site's own wallet during WordPress uninstall** (`wordpress/uninstall.php:34-117`, `wordpress/bootstrap.php:138-168`). Never mutate foreign wallets on hash/ID coincidence, and do not lose the local repair record after a revocation failure.

## Prioritized security validation leads

Full source traces, evidence, blockers, smallest fixes and validation plans are in Section 8. “Affected” is not a statement that every installation is exploitable.

| Priority | Lead | Affected boundary | Status |
|---|---|---|---|
| **A — validate/fix early** | **Direct PAID melt can finalize with missing signed change**<br>`cashu-wallet-php:melt-direct-paid-change-journal` | Direct PAID withdrawal and change recovery<br>`cashu-wallet-php/CashuWallet.php:8048` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Expired-quote recovery can release an in-flight melt and resurrect inputs**<br>`cashu-wallet-php:melt-expiry-inflight-release` | Library and application withdrawals; concurrent in-flight melt<br>`cashu-wallet-php/CashuWallet.php:8011` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Plain-HTTP mint URLs expose bearer proofs to on-path theft**<br>`cashu-wallet-php:mint-http-cleartext-proofs` | Library and both application modes; non-loopback HTTP mint<br>`cashu-wallet-php/CashuWallet.php:3780` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Restore forgets incomplete keysets omitted by later mint listing**<br>`cashu-wallet-php:restore-vanished-keyset-readiness` | Imported-seed restore progress, funds and keyset history<br>`cashu-wallet-php/CashuWallet.php:9697` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Rate guard does not bind approved fiat price to the mint quote**<br>`commerce:admin-request-price-recheck-snapshot` | New admin fiat request and final BOLT11 amount<br>`admin.php:1508` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Existing SQLite database can leave new sidecars weakly permissioned**<br>`database:existing-db-wal-permissions` | Standalone/wallet filesystem confidentiality<br>`includes/config.php:69` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Payment health can report success while payment operations keep failing**<br>`health:payment-task-results-drop-failures` | Payment health, quote polls, readiness and shop alerts<br>`includes/invoice.php:481` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **Setup recovery leaves the previous claimant administrator session valid**<br>`setup.php:recovery-admin-session-revocation` | Standalone administrator/API-key authority after recovery<br>`includes/setup_ownership.php:172` | Needs bounded runtime/deployment evidence; source reviewed. |
| **A — validate/fix early** | **WordPress uninstall can revoke credentials in a foreign site wallet**<br>`wordpress/uninstall-foreign-wallet-credential-cleanup` | WordPress sibling/staging shops and their credentials<br>`wordpress/uninstall.php:149` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Slow pending withdrawal can head-of-line block later reconciliation**<br>`background:withdrawal-reconcile-cursor-not-persistent` | Withdrawal and token reconciliation progress<br>`includes/background_runner.php:550` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **WordPress self-cron can send its key over HTTP after a scheme mismatch**<br>`background:wp-self-cron-http-key` | WordPress mixed-scheme self-cron credential<br>`includes/background.php:132` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Sustained never-polled rows can starve other stores background lifecycle queues**<br>`invoice:null-first-global-lifecycle-queue` | Background invoice settlement/recovery between stores<br>`api.php:126` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Mint-info timeout clears before response body is consumed**<br>`mint-discovery:http-info-body-read-deadline` | Discovery completion on stalled mint responses<br>`mint-discovery/mint-info-fetcher.js:31` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Unbounded Nostr cardinality drives browser requests and persistent cache work**<br>`mint-discovery:nostr-url-cardinality-to-http-fanout` | Mint discovery browser work and cache<br>`mint-discovery/nostr-client.js:249` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Oversized mint quote drives QR work and interrupts customer polling**<br>`qr:unbounded-mint-quote-browser-work` | Customer QR work and payment-status polling<br>`cashu-wallet-php/CashuWallet.php:7437` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **IPv6 rotation bypasses the application budget on public token-receive work**<br>`receive-post:exact-ip-rate-key-mint-work` | Public receive workers/mint calls/store locks<br>`receive.php:144` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **IPv6 rotation enables persistent payment-request creation beyond intended rate budget**<br>`receive-request:exact-ip-rate-key-persistent-rows` | Public payment requests and SQLite growth<br>`receive.php:514` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Webhook responses are buffered without a byte limit**<br>`webhook-response-body-unbounded` | Background worker memory on webhook responses<br>`includes/background_runner.php:115` | Needs bounded runtime/deployment evidence; source reviewed. |
| **B — validate/fix early** | **Failed revocation deletes the only tracked WordPress credential**<br>`wordpress/uninstall.php:failed-revocation-drops-only-copy` | WordPress key revocation and reactivation<br>`wordpress/uninstall.php:67` | Needs bounded runtime/deployment evidence; source reviewed. |
| **C — validate/fix early** | **Nostr relay can bypass the 8 KiB handshake limit with one line**<br>`alerts/websocket-handshake-line-bound` | Alert worker on malicious relay handshake<br>`includes/alerts.php:203` | Needs bounded runtime/deployment evidence; source reviewed. |
| **C — validate/fix early** | **Array-shaped cron key may disclose a PHP source path**<br>`health:cron-array-key-warning` | Cron diagnostic/source-path disclosure<br>`cron.php:39` | Needs bounded runtime/deployment evidence; source reviewed. |
| **C — validate/fix early** | **Anonymous root may disclose PHP database exception traces**<br>`startup:anonymous-root:uncaught-database-exception` | Anonymous root during database startup failure<br>`index.php:16` | Needs bounded runtime/deployment evidence; source reviewed. |
| **C — validate/fix early** | **Pre-guard preflight can expose diagnostic details before direct-entry policy**<br>`startup:pre-guard-preflight-diagnostic-exposure` | Direct plugin requests before normal guard<br>`admin.php:9` | Needs bounded runtime/deployment evidence; source reviewed. |

## Money-safety and operator/customer UX summary

These independently source-checked defects follow from the project's own contracts: no lost money, orders not left undiagnosed, and no command-line/config-file requirement for the intended non-technical operator. Normal success paths may work; a particular crash/concurrency or information-lifecycle case breaks the invariant.

| ID / priority | Finding | Expected correction |
|---|---|---|
| **UX-A1 / P1**<br>standalone, WordPress | **Upgraded v6 databases lack columns required by webhook events** | With an enabled webhook, new invoice creation or settlement can fail on an unknown column and roll back. Customers can be unable to check out, or a paid invoice can stay unsettled with no shop notification.<br>**Fix:** Bump and repair the schema so both columns exist before webhook code runs; add safe defaults/backfill from payloads. Extend upgrade_path.php with an actual enabled-webhook invoice-create and settle path, including installations already labelled v14. |
| **UX-A2 / P1**<br>WordPress | **WooCommerce setup reports success when webhook registration failed** | Invoices can be created, but orders remain Pending payment because WooCommerce never receives settlement events. The false success suppresses clear warning and obvious retry route.<br>**Fix:** Require outer success AND webhook.success before declaring full configuration; otherwise show the concrete notification failure and keep the Connect action/store context. Dependency detection should verify an enabled webhook for the selected store and expected callback, not merely a URL. |
| **UX-A3 / P1**<br>standalone, WordPress | **Failed shop notifications have no operator-facing redelivery path** | A settled order can remain unpaid in WooCommerce indefinitely after retries exhaust. The intended non-technical operator has no way to identify which order needs action or press a native recovery button.<br>**Fix:** List each failed notification with order/reference, event, last result, attempts and exhausted/next retry. Add authenticated CSRF-protected Tell the shop again to requeue the selected delivery using the existing code, alongside repair of the shop connection; do not request another invoice. |
| **UX-A4 / P1**<br>standalone, WordPress | **Database backup download has no browser-based restore** | A non-technical operator has a valid full backup but cannot recover operational state without learning server placement and sidecar semantics. Seed protects funds, not orders and shop configuration.<br>**Fix:** Add authenticated Restore database backup to Settings/fresh setup. Verify SQLite integrity and supported schema, pause background writes, preserve current state for automatic rollback, atomically activate a validated backup while handling WAL sidecars, and show restored stores/invoices before reopening payments. |
| **UX-A7 / P1**<br>WordPress | **Readable-link repair declares success for JSON errors and unreachable self-checks** | The repair flow tells the operator to continue even though WooCommerce still cannot create invoices through an unhealthy route, ending guidance at a false result.<br>**Fix:** Return true only for HTTP 200 with decoded isCashuPayServer=true. Report wrong payload/status as unreachable and request failure as could not verify, retaining the existing permalink/.htaccess remediation in both cases. |
| **UX-F1 / P1**<br>standalone, WordPress | **Token intent and proof reservation are not atomic before donation retry** | The same spendable proof can be selected for a merchant withdrawal/export and disclosed to the donation sink. Only the first redemption succeeds, potentially losing the intended withdrawal or causing an unintended donation.<br>**Fix:** In one transaction on one PDO, insert the exact transfer and transition every associated proof to EXPORTED. Require a complete count match and rollback any partial reservation. Commit before any sink POST/browser disclosure; never hold that transaction over the network. Recovery must also require local EXPORTED ownership. |
| **UX-F3 / P1**<br>standalone, WordPress | **UNKNOWN value is invisible to deletion guards and backup reconciliation** | UNKNOWN-only backups can be removed without a holdings warning or reconciled. A former namespace can disappear from recovery, and a store can be deleted without its configured recovery account even if later verification would show value.<br>**Fix:** Build one namespace summary used by deletion guards, scanners and sync: UNSPENT, PENDING, UNKNOWN, EXPORTED and journals all represented. Include UNKNOWN as a blocker and select every account containing it for reconciliation before export or deletion; never assume zero known balance means zero value. |
| **UX-F5 / P1**<br>standalone, WordPress | **Recovery sheets omit payment-request mints and depend on prunable history** | After losing the site, an operator can have the right seed but no recorded mint reference for old payment-request funds. Historical invoice pruning can produce the same gap for mint/invoice funds.<br>**Fix:** Include payment-request mints immediately and use an append-only per-store mint/unit history for the kit and fingerprints. Payment-log retention and cleanup must not irreversibly erase the only recovery reference. |
| **UX-F7 / P1**<br>standalone, WordPress | **Store deletion and mint changes check funds without excluding new fund movements** | A payout/export can create unresolved value after the safety check but before mutation. Store deletion can cascade the store/transfer configuration while its operation remains unresolved, and the new case was not described by the earlier confirmation.<br>**Fix:** Acquire funds_<storeId> before reading store/blockers, re-read state under the lock and hold it through update/delete with try/finally release. Apply the equivalent lock or store-generation predicate anywhere invoice/payment creation is needed to keep the outstanding-payment blocker true. |
| **UX-A10 / P2**<br>standalone, WordPress | **Automatic data relocation stops being available once a wallet exists** | After real payments exist, the target operator cannot perform the browser-first remediation and may not manage file/config changes. Removing the guard without coordination would instead risk moving the active wallet mid-payment.<br>**Fix:** Add a password-confirmed admin move that pauses work, copies/reopens and validates the destination, records ownership/location, then removes source only after success. Teach bootstrap to honor the app-recorded move; offer copyable host-support text only where PHP cannot create/pin an outside folder. |
| **UX-A12 / P2**<br>standalone, WordPress | **Health can stay HTTP 200 when no store can create an invoice** | An uptime service waiting for 503 does not alert on the exact no-usable-store condition. A partially broken row can be detailed as ready even though creation fails.<br>**Fix:** Use the same local readiness fields as Config::isStoreConfigured and make zero usable stores FAIL/503 after setup. Keep individual mint/dependency outages governed by existing payment-path checks; no new network probe is required. |
| **UX-A5 / P2**<br>standalone, WordPress | **Stale background processing is absent from wp-admin attention notices** | The operator can keep using wp-admin while active payment polling, notifications and auto-payouts silently stop. There is no warning where the operator normally looks.<br>**Fix:** Add never-run/stale-heartbeat to shared problems with business-impact wording and direct link to scheduling repair. Keep an external health monitor for total scheduler failure, since the stopped process cannot notify itself. |
| **UX-A6 / P2**<br>standalone, WordPress | **Stale-runner guidance confuses a one-off run with restoring recurring work** | Payments can process once while the automatic trigger remains broken and the warning returns. The operator can also mistakenly expect an uptime-health check to perform payment work.<br>**Fix:** Add a persistent Background checks settings card, linked from the warning. Label Run once now, explain visitor/WP-Cron operation and provide actual recurring URL/key and copyable hosting/web-cron instructions when needed. Say explicitly that health monitoring observes rather than executes checks. |
| **UX-A8 / P2**<br>WordPress | **WordPress exposure probe mistakes soft-404 pages for wallet downloads** | An ordinary soft-404 can generate a false claim that anyone downloaded the wallet and cause panic/support escalation without a trustworthy diagnostic.<br>**Fix:** Reuse content-aware standalone checks: require a SQLite signature where applicable, reject HTML, and preferably compare expected local header bytes. Display inconclusive rather than exposed or safe when the response cannot identify the real file; add a soft-404 case. |
| **UX-A9 / P2**<br>standalone, WordPress | **Checkout polling hides the last known payment state on a JSON error** | A customer loses the visible payment/processing state and especially the Do not pay again reassurance, making a second payment attempt more likely during server/update errors.<br>**Fix:** Accept only successful responses with New, Processing, Settled, Expired or Invalid. On transport/HTTP/schema error preserve the previous card and add: We cannot check this payment right now. If you already paid, do not pay again; we will keep checking. |
| **UX-F2 / P2**<br>standalone, WordPress | **Stranded recovery rebuilds tokens and treats acknowledgement as redemption** | A changed export set can leave two different live tokens containing the same proofs and two recipients who think they own the same value. A mistaken acknowledgement can hide an unclaimed token from future scanners. The UI does not falsely claim mint verification; the defect is missing immutable/exported-state tracking and safe fallback handling.<br>**Fix:** Persist the exact token, ordered secrets, namespace and state atomically with reservation and always redisplay that record. Keep new value in a separate export. Distinguish optional verified NUT-07 redemption from a separately labelled, auditable offline write-off instead of silently writing SPENT. |
| **UX-F4 / P2**<br>standalone, WordPress | **Journal-only old mints are absent from stranded recovery** | A former account whose only recovery clue is an interrupted journal can be reported as having no stranded funds even though no scheduled worker will recover it. Collecting that value requires reconstructing the old account configuration manually.<br>**Fix:** Include pending-operation wallet IDs as unresolved, non-exportable stranded entries. Resolve their mint/unit/account from durable history and offer a recovery action that reattaches the seed/account for the appropriate journal recovery. Never serialize journal-reserved inputs as a recovery token. |
| **UX-F6 / P2**<br>standalone, WordPress | **Recovery sheet is marked taken before generation and delivery** | The dashboard can suppress subsequent reminders although the operator never obtained the recovery sheet, precisely when that information may be needed.<br>**Fix:** Generate the entire selected payload before changing state and only acknowledge after successful delivery. Prefer an explicit post-download operator confirmation rather than declaring completion before output. |
| **UX-A11 / P3**<br>WordPress | **CashuPay URL detection accepts unrelated prefix-sharing BTCPay paths** | In the rare same-origin prefix collision, clicking Connect can replace a real third-party BTCPay configuration without the intended refusal.<br>**Fix:** Share one normalized predicate across connect/uninstall: same host and normalized exact path, or path followed by slash only. Preserve trailing-slash normalization and reject arbitrary prefixes. |

## Standalone and WordPress effects

- **Shared code applies to both deployment modes** in invoice settlement, public receive, payouts/exports, recovery kits, webhooks, customer polling, health and QR flows. Any library or shared-code fix needs testing through both entry paths, not just the direct PHP setup.
- **Standalone-specific:** first-browser administrator recovery, preflight/root diagnostics, filesystem/data-directory protection and public cron can expose authority, configuration or paths under the described conditions. Public receive and NUT-18 have shared resource-limit concerns on all modes.
- **WordPress-specific:** uninstall can mutate another site's credentials even though normal bootstrap has an owner check; failed revocation and reactivation are distinct from reconnection; setup can falsely say shop integration is complete; pretty-link checks can produce success on invalid self-checks; mixed-scheme self-cron can expose an internal key on an unsafe network path.
- **cashu-wallet-php:** quote validation is strong in many places, but valid HTTP mint transport, direct PAID change omission, concurrent expiry release and disappearing-keyset restore are the prioritized hypotheses. Parsing/DLEQ/checkstate and transaction/restore safeguards deserve targeted regression tests rather than wholesale replacement.
- **mint-discovery:** core concerns are attacker-proportional URL work/cache and deadline ending at response headers. The CSP and server-side selection guard do not make discovery input trusted, and runtime limits are different from the separate build-provenance checks.
- **Customer UI:** the status poller accepts arbitrary JSON without `response.ok`/recognized status, causing blank/misleading cards and loss of the important “do not pay again” message. Persistent invoice polling/late-payment controls are useful and should remain.

## Positive controls worth preserving

- Store-scoped API-key permissions and child-resource binding, session rotation/expiry/CSRF, WordPress capabilities/nonces and bootstrap foreign-wallet refusal were consistently visible in the reviewed paths.
- Deterministic output planning, exact signature/response correspondence, Y-matched proof states, seed binding and monotonic/resumable restore guard many protocol and stale-response attacks. Direct paid-melt finalization now returns paid rather than blindly encouraging repeat payment.
- Webhook target/DNS validation, address pinning, no redirects, exact-payload HMAC and per-invoice ordered delivery are real controls. Missing response-byte limits must not be confused with missing SSRF defenses.
- Browser data reviewed uses safe encoding/text sinks, redirect checks or canvas rendering. No complete DOM XSS/exfiltration path was established in assigned scopes.
- Rates use positive finite values, two-provider agreement/fresh anchored fallback and exact decimal/rounding; the new defect is that final quote amount is not bound to the approved snapshot.
- Emergency update metadata uses pinned signatures/version matching without auto-install. Builds include dependency lock integrity and exclude local wallet/config artifacts.
- Background tasks have leases and budgets; donation token disclosure normally follows reservation; primary accounts do get UNKNOWN reconciliation; paid payment-request rows are retained by cleanup. The findings below preserve these distinctions.

## Detailed security validation backlog

These entries are deliberately free of unobserved severity claims. Any local validation is for a future **fully resource-limited sandbox with dummy accounts/data and loopback-only services**. Owner/deployment checks are observations on an owner-controlled staging deployment, not requests to attack a live service.

### Direct PAID melt can finalize with missing signed change

**Fingerprint:** `cashu-wallet-php:melt-direct-paid-change-journal`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Direct PAID withdrawal and change recovery

If a direct successful PAID POST omits some/all change outputs that are signed through NUT-09, the wallet accepts only the supplied subset and finalizes without restore. It deletes the journal and can report the remainder as fee, removing automatic change recovery.

**Claimed root cause:** The direct PAID branch unblinds only supplied change and treats short/empty arrays as complete, unlike lost-response and background recovery which use NUT-09 fallback and retain journals on failure.


**Source trace:**


- `entrypoint` — `cashu-wallet-php/CashuWallet.php:8048` (`direct melt POST response`): A successful direct PAID result is accepted.

- `propagation` — `cashu-wallet-php/CashuWallet.php:8129` (`direct PAID processing`): Only response change is unblinded; no NUT-09 reconciliation.

- `propagation` — `cashu-wallet-php/CashuWallet.php:5454` (`finalizePendingSpend`): Inputs become SPENT and journal is deleted.

- `sink` — `includes/lightning_address.php:375` (`executeMelt`): With changeRecoveryPending false, the caller completes using only returned change.


**Evidence and strongest visible controls:**


- `cashu-wallet-php/CashuWallet.php:8087` — Reconciled PAID path explicitly calls shared change recovery.

- `cashu-wallet-php/CashuWallet.php:6491` — Shared recovery handles empty response change using recoverPendingOutputs/NUT-09.

- `cashu-wallet-php/CashuWallet.php:8186` — unblindMeltChange rejects excess signatures but not missing signatures.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use dummy input/quote and a stub MintClient that records valid NUT-09 change but direct PAID response has no change, then only a short subset. Observe whether NUT-09 is called, journal remains/flag true, and proof/automatic recovery exists; do not contact a real mint.


**Smallest effective correction if validated:** Call the existing `recoverMeltChange()`/NUT-09 logic for direct PAID as well as reconciled PAID. Distinguish legally unused outputs from signed-but-omitted outputs; retain the journal and `changeRecoveryPending=true` until every planned signed output is resolved.


### Expired-quote recovery can release an in-flight melt and resurrect inputs

**Fingerprint:** `cashu-wallet-php:melt-expiry-inflight-release`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Library and application withdrawals; concurrent in-flight melt

A request may remain in flight up to 120 seconds while separate recovery sees expired UNPAID plus a point-in-time all-UNSPENT answer, releases inputs and deletes journal. A late PAID outcome can then leave mint-spent inputs locally UNSPENT; CashuPayServer may mark failed and allow a second payment.

**Claimed root cause:** Expiry-present branch has no active-attempt lease or last-attempt grace. It treats observed input state as proof no earlier request can execute, and missing-journal finalization stores only change without marking original inputs SPENT.


**Source trace:**


- `entrypoint` — `cashu-wallet-php/CashuWallet.php:8011` (`melt journal preparation`): Inputs and melt:<quote> journal persist before network activity.

- `propagation` — `cashu-wallet-php/CashuWallet.php:8048` (`melt POST`): Original request may stay in flight for 120 seconds.

- `propagation` — `cashu-wallet-php/CashuWallet.php:6376` (`recovery expiry rule`): Expiry alone satisfies timing when quote expiry exists; age guard applies only without expiry.

- `propagation` — `cashu-wallet-php/CashuWallet.php:6380` (`release`): All-UNSPENT checkstate permits release and journal deletion.

- `propagation` — `cashu-wallet-php/CashuWallet.php:5424` (`missing-journal finalization`): Late paid change is stored without changing former inputs.

- `sink` — `includes/lightning_address.php:321` (`consumer failure handling`): An exception/no-journal path marks transfer failed, enabling retry.


**Evidence and strongest visible controls:**


- `includes/invoice.php:2040` — Background recovery can invoke recoverPendingMelts outside the per-store withdrawal initiation lock.

- `includes/transfer.php:134` — hasPendingWithdrawal protects only pending rows; failed rows no longer block.

- `cashu-wallet-php/CashuWallet.php:6378` — 600-second journal-age guard applies only when expiry is null.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Real mint accepted-before-expiry settlement behavior differs by implementation; the local fake proves the library under this protocol-plausible schedule, not a deployed mint fact.


**Bounded local validation:** Use a deterministic fake mint: re-enter recoverPendingMelts while initial POST is in flight, return expired UNPAID and all-UNSPENT then late PAID. Inspect original input, journal, transfer and paid-before/local-response states. Test lost-response late-settlement variant. Never contact real mint.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Real mint accepted-before-expiry settlement behavior differs by implementation; the local fake proves the library under this protocol-plausible schedule, not a deployed mint fact.


**Smallest effective correction if validated:** Add an active-attempt lease and last-attempt timestamp to melt journals; use one account funds/recovery lock before expiry release. Include transport+settlement grace, and make missing-journal late PAID finalization restore the original inputs to SPENT. Regression: accepted-before-expiry → all-UNSPENT → late PAID.


### Plain-HTTP mint URLs expose bearer proofs to on-path theft

**Fingerprint:** `cashu-wallet-php:mint-http-cleartext-proofs`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Library and both application modes; non-loopback HTTP mint

The application and library accept http:// for arbitrary non-loopback mints. Swap and melt POST bodies then send complete input proof secrets without confidentiality/integrity, allowing an on-path attacker to steal/race ecash or alter traffic.

**Claimed root cause:** URL and connection policies preserve accepted cleartext HTTP. DNS/address pinning does not encrypt protocol JSON containing each proof secret.


**Source trace:**


- `entrypoint` — `cashu-wallet-php/CashuWallet.php:3780` (`canonicalizeMintUrl`): Both https and http are accepted for syntactically valid mint hosts.

- `propagation` — `receive.php:347` (`public receive`): The stored HTTP mint URL is used to construct a wallet and receive a token.

- `propagation` — `cashu-wallet-php/CashuWallet.php:7075` (`submitPreparedSwap`): The POST includes Proof::toArray inputs with id, amount, secret and C.

- `sink` — `cashu-wallet-php/CashuWallet.php:3894` (`curl_exec`): The request uses the retained HTTP scheme with no application-layer encryption.


**Evidence and strongest visible controls:**


- `cashu-wallet-php/CashuWallet.php:1860` — Proof::toArray exposes the fields needed to redeem a proof.

- `includes/security.php:359` — ConnectGuard permits HTTP as well as HTTPS; address pinning does not encrypt.

- `cashu-wallet-php/CashuWallet.php:8040` — Melt independently includes complete input proofs and change outputs.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Affected configuration needs a non-loopback HTTP mint and an on-path actor; actual settings/topology are not available.


**Bounded local validation:** Capture one dummy swap/request on an isolated loopback HTTP listener using HTTP mint.test pinned locally and dummy proofs. Verify the dummy proof secret is visible in cleartext. Probe no external host. For the fix, refuse non-loopback HTTP by default and require explicit development override.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Affected configuration needs a non-loopback HTTP mint and an on-path actor; actual settings/topology are not available.


**Smallest effective correction if validated:** Require HTTPS in `MintClient::canonicalizeMintUrl` and the two admin/setup mint gates, except an explicit local-development-only exception. Explain any existing rejected HTTP mint in business terms; never send a token to probe it.


### Restore forgets incomplete keysets omitted by later mint listing

**Fingerprint:** `cashu-wallet-php:restore-vanished-keyset-readiness`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Imported-seed restore progress, funds and keyset history

A resumable restore records required keysets, but every call reconstructs the completion plan only from current /keysets output. A previously partially scanned/failed keyset that disappears is excluded from allDone and finalization, so readiness can be marked complete while its funds remain missing.

**Claimed root cause:** Completion is evaluated against current mutable listing, not the union of the restore session persisted required keysets; finishRestore supplies only current IDs to storage.


**Source trace:**


- `entrypoint` — `cashu-wallet-php/CashuWallet.php:9697` (`restoreStep`): Bounded resumable recovery begins.

- `propagation` — `cashu-wallet-php/CashuWallet.php:9859` (`runRestore plan`): Current keyset response alone constructs required scan plan.

- `propagation` — `cashu-wallet-php/CashuWallet.php:10022` (`allDone`): Only current plan IDs are examined for completion.

- `propagation` — `cashu-wallet-php/CashuWallet.php:10105` (`finishRestore`): finalizeRecovery receives current plan IDs only.

- `sink` — `cashu-wallet-php/CashuWallet.php:4763` (`finalizeRecovery`): Only supplied IDs block readiness; vanished incomplete progress cannot.


**Evidence and strongest visible controls:**


- `cashu-wallet-php/CashuWallet.php:9894` — New IDs join session but IDs missing from current plan are not reconciled with persisted requirements.

- `cashu-wallet-php/CashuWallet.php:4801` — After narrowed checks, matching accounts are marked ready.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use a fake immutable-seed/temporary DB with K1/K2 and restore gap 2. Advance K1 only partially, remove it from next listing, finish K2. Assert K1 next_counter still incomplete while ready can become true and a previously signed K1 proof is missing. Also test last_error on vanished K1.


**Smallest effective correction if validated:** Evaluate readiness against the union of persisted restore-session required keysets and the current listing, including `last_error`. Never allow a previously required incomplete keyset to vanish merely because it is absent from the latest mint listing.


### Rate guard does not bind approved fiat price to the mint quote

**Fingerprint:** `commerce:admin-request-price-recheck-snapshot`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** New admin fiat request and final BOLT11 amount

Admin request preview checks expected mint-unit amount against one conversion, then passes original amount/currency into Invoice::create, which converts again. A provider/cache change between calls can set a materially different customer amount without the promised 2% guard.

**Claimed root cause:** The checked converted value is not an immutable pricing snapshot; the final mint quote is based on a second unchecked conversion.


**Source trace:**


- `entrypoint` — `admin.php:1508` (`create_invoice`): First conversion produces the amount used for drift comparison.

- `propagation` — `admin.php:1516` (`two-percent guard`): Only this first amount is compared with expected_amount.

- `propagation` — `admin.php:1543` (`call create`): Original amount/currency, not checked converted amount, is passed.

- `propagation` — `includes/invoice.php:138` (`Invoice::create`): A second conversion occurs.

- `sink` — `includes/invoice.php:171` (`mint quote`): The second result is used for the quote.


**Evidence and strongest visible controls:**


- `includes/rates.php:250` — Every lookup independently evaluates freshness, so the calls need not use the same rate.

- `includes/invoice.php:212` — The later conversion is persisted in the invoice.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use fixed local providers and a dummy mint. Advance from rate A to >2%-different confirmed B exactly after the guard conversion. Verify whether quote/persisted amount uses B despite an accepted A preview. Regression must pass one checked amount/snapshot or return rate_changed.


**Smallest effective correction if validated:** Pass the exact checked mint-unit amount/rate snapshot into `Invoice::create`, or recheck the final amount immediately before `requestMintQuote`. Reject any unacceptable drift with the existing `rate_changed` response.


### Existing SQLite database can leave new sidecars weakly permissioned

**Fingerprint:** `database:existing-db-wal-permissions`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Standalone/wallet filesystem confidentiality

When cashupay.sqlite already exists, Database::connect() enables WAL but skips restrictDatabaseModes(); WalletStorage repeats the same creation-only guard. If absent WAL/SHM files are recreated group/other-readable, an unprivileged local hosting account may read recent pages, potentially including seed phrases and proof secrets. Disclosure additionally requires read access through the resulting mode or ACL and execute/search access on the data directory and every ancestor. Permissive bits alone do not prove cross-UID disclosure; effective file modes, identities, ACLs, namespaces and traversal were not observed.

**Claimed root cause:** Permission hardening is conditional on initial main-file creation, not every time WAL sidecars may be created; packaged data directory lacks an enforced safe mode.


**Source trace:**


- `entrypoint` — `includes/config.php:69` (`configuration write`): An ordinary setup/admin/background write modifies SQLite.

- `propagation` — `includes/database.php:294` (`existing branch`): creating is based only on existence of main DB.

- `propagation` — `includes/database.php:300` (`WAL enablement`): WAL is enabled for both new and existing DBs.

- `propagation` — `includes/database.php:307` (`restriction skipped`): restrictDatabaseModes is called only when creating.

- `sink` — `includes/config.php:75` (`subsequent write`): Sidecars may be created with process umask after skipped restriction.


**Evidence and strongest visible controls:**


- `includes/database.php:319` — The helper covers main/WAL/SHM but does not run on the existing-main path.

- `cashu-wallet-php/CashuWallet.php:4000` — WalletStorage likewise enables WAL for an existing DB but calls restrictFileModes only when creating is true.

- `scripts/build-standalone.sh:110` — Standalone packaged data/ uses mkdir -p without explicit chmod, so effective installed mode depends on packaging/extraction.

- `wordpress/activation.php:51` — WordPress conditionally removes other access only with POSIX effective-UID support and an owner match; group access is deliberately retained.

- `docker/Dockerfile.standalone:56` — The standalone Docker image explicitly sets its data directory to 0700, providing directory-traversal containment rather than an example of the issue.


**Decisive blocker(s):**


- Target execution was prohibited because the complete required sandbox was unavailable; sidecar recreation, resulting modes and a second-UID read were not observed.

- The resulting sidecar owner/group/mode/ACL must grant the lower-trust UID read access and that UID must be able to traverse every directory ancestor. Permission bits alone prove neither an attacker-class UID nor traversal.

- No dummy sensitive seed/proof marker has been written and recovered from a readable WAL in the claimed conditions, so actual secret disclosure remains unobserved.


**Bounded local validation:** Use an owner-controlled disposable VM/container with two distinct fixture UIDs/groups, no shared supplementary groups, a fully dummy fixture tree and no system-directory changes. Close every database handle before deleting only fixture WAL/SHM. Under controlled umask 022 and traversable dummy ancestors, reopen a 0600 existing database and write unique dummy config/seed/proof markers without checkpointing. Record modes/ACLs for the tree and DB files; as the second UID confirm the main DB remains denied, then attempt bounded WAL access and recover a marker. Repeat with 0700 data as a denied-traversal control. Do not modify or test production files.


**Owner-observed staging/deployment validation:** On an owner-controlled staging copy with only dummy rows, document actual data/ancestor modes, PHP process and second-account UID/GID/group/ACL membership, umask and namespace/chroot/MAC boundaries. Test a bounded direct WAL read while the dummy main stays inaccessible, including the 0700 control. Do not infer cross-UID access from mode bits alone or touch live sidecars.


**Smallest effective correction if validated:** Enforce owner-only data-directory traversal where supported and restrict existing main/WAL/SHM access, not just initial creation. Do not assume HTTP-denial files protect another local UID. Verify exact file, owner/group, ACL and ancestor modes before recommending blanket chmod.


### Payment health can report success while payment operations keep failing

**Fingerprint:** `health:payment-task-results-drop-failures`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Payment health, quote polls, readiness and shop alerts

poll_quotes, ready_store_wallets and webhook_outbox discard per-item failures from their runner return values. Last-ok task health and the public monitor can therefore claim payments work despite stuck orders or unnotified shops.

**Claimed root cause:** Per-item exceptions are caught by lower-level methods, while wrappers return success/attempt counts; failureOf treats those values as healthy and overwrites prior failure state.


**Source trace:**


- `entrypoint` — `includes/invoice.php:481` (`Invoice::pollPendingQuotes`): Per-invoice mint/quote failures enter the poll loop.

- `propagation` — `includes/background_runner.php:111` (`poll_quotes wrapper`): After void polling returns, the wrapper unconditionally returns success.

- `propagation` — `includes/background_runner.php:245` (`runTask health`): last_ok replaces prior task failure data.

- `sink` — `includes/health.php:176` (`Health::report`): No surviving payment-path failure produces a pass.


**Evidence and strongest visible controls:**


- `includes/background_runner.php:115` — webhook_outbox reports only attempted count; includes/invoice.php:745 readiness returns only counts after caught errors.

- `includes/background_runner.php:302` — failureOf can classify error arrays but these wrappers do not supply them.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use three one-record sandboxed dummy cases: malformed quote poll, repeatedly failing local webhook, and wallet recovery that throws. With all other seeded health checks good, compare per-invoice/delivery/ready state with cron_task_health last_ok and Health report payments pass.


**Smallest effective correction if validated:** Propagate per-invoice, per-account and failed-delivery counts from the underlying methods. `failureOf` must preserve/replace classification only to reflect current failures; include still-failing underlying operations in the health payments check instead of relying solely on wrapper heartbeat.


### Setup recovery leaves the previous claimant administrator session valid

**Fingerprint:** `setup.php:recovery-admin-session-revocation`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** Standalone administrator/API-key authority after recovery

A stranger who claims a fresh standalone installation can receive its first admin session. If the owner later recovers using the setup code and chooses a new password, the old session and its CSRF token remain valid, allowing dashboard access and durable wildcard API-key creation after setup completes.

**Claimed root cause:** Recovery rotates setup ownership but the setup password path stores only a new hash, without bumping admin_password_version or revoking existing sessions.


**Source trace:**


- `entrypoint` — `includes/setup_ownership.php:172` (`Setup::verifyOwnershipToken`): A correct setup recovery code invokes assignClaim for the recovering browser.

- `propagation` — `setup.php:332` (`password step after recovery`): The owner uses setAdminPassword, which writes only the hash.

- `propagation` — `includes/auth.php:88` (`Auth::isLoggedIn`): The old session password_version still matches the unchanged global generation.

- `sink` — `admin.php:1939` (`create_api_key`): The old authenticated session can create a default wildcard key after setup is complete.


**Evidence and strongest visible controls:**


- `includes/auth.php:222` — setAdminPassword stores only the new hash; changeAdminPassword at lines 252-258 instead bumps the session generation.

- `includes/setup_ownership.php:296` — assignClaim and resetWizardState rotate claim/setup data but not other admin sessions.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** In a full local sandbox with two cookie jars and dummy mint: attacker claims setup and chooses password; owner recovers with dummy code, resets password and finishes setup. Confirm attacker password no longer logs in, then replay the original attacker session/CSRF against dashboard and create_api_key. The minimum affected result is authenticated dashboard and a raw wildcard key rather than 401.


**Smallest effective correction if validated:** Bump `admin_password_version` during recovery/initial setup password reset and revoke existing sessions; rotate only the recovering browser CSRF. Preserve first-browser setup ownership, but test the old cookie after the new store is complete.


### WordPress uninstall can revoke credentials in a foreign site wallet

**Fingerprint:** `wordpress/uninstall-foreign-wallet-credential-cleanup`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** A  
**Boundary:** WordPress sibling/staging shops and their credentials

Uninstall enumerates shared/legacy/fallback wallets and deletes matching key hashes or disables matching webhook IDs without applying bootstrap ownership checks. A cloned staging/sibling configuration can therefore revoke a different shop's integration and stop invoice/order notifications.

**Claimed root cause:** Uninstall substitutes broad candidate directory mutation for the deleting site resolved owner-checked wallet.


**Source trace:**


- `entrypoint` — `wordpress/uninstall.php:149` (`site uninstall`): Deleting one plugin invokes credential cleanup.

- `propagation` — `wordpress/uninstall.php:34` (`candidate enumeration`): Legacy/fallback paths are selected without owner marker checks.

- `propagation` — `wordpress/uninstall.php:74` (`database loop`): Every candidate SQLite is searched using deleting site credentials.

- `sink` — `wordpress/uninstall.php:97` (`webhook revoke`): A matching webhook ID is disabled in whichever DB contains it; line 88 deletes key similarly.


**Evidence and strongest visible controls:**


- `wordpress/bootstrap.php:154` — Normal resolution consults .owner before adopting legacy data.

- `wordpress/bootstrap.php:168` — Bootstrap deliberately classifies another installation legacy folder as foreign and excludes it.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Build two dummy installations A/B sharing parent; B owns legacy wallet with .owner B and A has cloned dummy key/webhook options. Uninstall only A and read both DBs. B credentials must remain unchanged; also test configured data dir and multisite cleanup.


**Smallest effective correction if validated:** Resolve the deleting site data directory with bootstrap ownership rules and mutate only that wallet. Add owner checks to legacy/fallback enumeration and tests for cloned sibling options and multisite. Never scan unrelated wallets for matching credential hashes/IDs.


### Slow pending withdrawal can head-of-line block later reconciliation

**Fingerprint:** `background:withdrawal-reconcile-cursor-not-persistent`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Withdrawal and token reconciliation progress

Withdrawal reconciliation resets its row cursor on every invocation. If the oldest withdrawal remains pending and its synchronous mint quote check repeatedly consumes more than the 15-second budget, the deadline return prevents later withdrawals and token transfers from being visited on every affected run.

**Claimed root cause:** Unlike token reconciliation, withdrawal reconciliation initializes its cursor to null on every invocation and returns on deadline without saving progress, so the same oldest pending row can consume every run's budget.


**Source trace:**


- `entrypoint` — `includes/background_runner.php:550` (`reconcileTransfers`): The withdrawal keyset cursor is reset to null on every invocation.

- `propagation` — `includes/transfer.php:241` (`getPendingPage`): Pending withdrawal rows are ordered by created_at and id, oldest first.

- `propagation` — `includes/background_runner.php:633` (`reconcileWithdrawal`): The selected row performs a synchronous checkMeltQuote before the deadline is re-evaluated.

- `propagation` — `includes/background_runner.php:648` (`reconcileWithdrawal`): A still-pending quote returns still_pending without changing the transfer row.

- `sink` — `includes/background_runner.php:557` (`deadline exit`): Expiry returns immediately without persisting withdrawal progress or visiting later rows.


**Evidence and strongest visible controls:**


- `includes/background_runner.php:548` — The reconciliation deadline is set to 15 seconds at the start of the task.

- `includes/background_runner.php:593` — The adjacent token-transfer path writes its cursor to Config; the earlier withdrawal deadline return bypasses this write.

- `cashu-wallet-php/CashuWallet.php:6018` — Wallet constructs MintClient without overriding its timeout.

- `cashu-wallet-php/CashuWallet.php:3764` — MintClient's default timeout is 30 seconds, longer than the reconciliation budget.

- `cashu-wallet-php/CashuWallet.php:3868` — The configured timeout is applied to the cURL request, so one quote check can remain synchronous beyond 15 seconds.


**Decisive blocker(s):**


- Target execution was prohibited because the complete OS sandbox was unavailable. Repeated over-budget quote checks, the first row persistently remaining pending, and zero queries for the second row across runs have not been observed.


**Bounded local validation:** On an isolated native withdrawal-test harness, using only temporary data and the loopback mock mint with allow_private_mints enabled, seed exactly two pending withdrawal rows in oldest-first order with valid quote references and no open melt journals. Delay only the oldest quote GET by 16–25 seconds but below the 30-second client timeout, then return PENDING; answer the second quote immediately and log both paths. Invoke reconcileTransfers twice. The vulnerable build should report checked=1 and still_pending=1 each time, two first-quote requests and zero second-quote requests. A fast control must check both, while a persisted-cursor fix reaches the second on the later run. Use dummy funds only and remove fixture state afterwards.


**Smallest effective correction if validated:** Persist and wrap the withdrawal cursor as token transfers already do. Fairly schedule unresolved rows instead of restarting at the oldest every run, and advance cursor only for records actually attempted. Test one slow PENDING followed by a fast PENDING across two invocations.


### WordPress self-cron can send its key over HTTP after a scheme mismatch

**Fingerprint:** `background:wp-self-cron-http-key`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** WordPress mixed-scheme self-cron credential

If a WordPress site_url and pinned base_url have the same host/port but differ HTTP versus HTTPS, the self-cron origin check accepts the downgrade and sends X-Cron-Key in cleartext, potentially exposing scheduled-work authority to an on-path actor.

**Claimed root cause:** sameOrigin deliberately compares host and explicit port but ignores scheme, while the approved URL comes independently from WordPress site_url and can be HTTP.


**Source trace:**


- `entrypoint` — `includes/background.php:132` (`selfCronUrl`): WordPress accepts Urls::cron when sameOrigin matches.

- `propagation` — `includes/background.php:145` (`sameOrigin`): Scheme is dropped from the normalized comparison.

- `propagation` — `includes/urls.php:177` (`Urls::cron`): The URL scheme comes from site_url.

- `sink` — `includes/background.php:101` (`Background::trigger`): The request sends X-Cron-Key to the accepted URL over permitted HTTP.


**Evidence and strongest visible controls:**


- `includes/background.php:109` — TLS verification cannot upgrade an approved HTTP URL.

- `tests/network_guard_2026_10.php:183` — The existing fixture explicitly accepts HTTP site_url with HTTPS pinned base_url.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- The affected scheme mismatch and whether the route leaves loopback/trusted host-local networking are deployment-specific.


**Bounded local validation:** With WordPress stubs, configure HTTPS base_url and same host/port HTTP site_url; pin to loopback, capture one plaintext self-request and inspect its dummy X-Cron-Key. Require scheme equality or upgrade to HTTPS in the regression.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. The affected scheme mismatch and whether the route leaves loopback/trusted host-local networking are deployment-specific.


**Smallest effective correction if validated:** Require scheme equality for credential-bearing self-calls or derive the self-request scheme from the pinned secure `base_url`. Do not permit downgrade even when host/port match; compare effective host/ports with correct defaults.


### Sustained never-polled rows can starve other stores background lifecycle queues

**Fingerprint:** `invoice:null-first-global-lifecycle-queue`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Background invoice settlement/recovery between stores

A store-A-scoped integration can sustain valid last_polled_at NULL invoices without store-B authority. The active queue explicitly prioritizes NULL rows under LIMIT 10; recent and older closed recovery each independently sort last_polled_at ascending, also putting NULL first, with separate LIMIT 10 batches. Supply above the drain rate can keep paid B rows unsettled in background work. Direct B API/checkout status polling is an active/recent-closed per-invoice escape, so the starvation case requires no concurrent direct B polls.

**Claimed root cause:** Background active and closed queues have neither per-store fairness nor an aging reservation. Continuous never-polled work can monopolize each batch because NULL precedes previous work. Direct per-ID polling is a bypass only for invoices that actually receive it, not a scheduler fairness control.


**Source trace:**


- `entrypoint` — `api.php:126` (`create invoice route`): A store-scoped integration reaches bounded creation subject to general IP limits.

- `propagation` — `includes/invoice.php:205` (`Invoice::create`): New invoice has last_polled_at NULL.

- `propagation` — `includes/invoice.php:455` (`pollPendingQuotes`): Global LIMIT 10 explicitly prioritizes never-polled over previous rows.

- `propagation` — `includes/invoice.php:484` (`active paid handling`): Only selected active rows reach background PAID/ISSUED mint-and-settle handling; direct per-ID API/checkout polling bypasses this batch.

- `sink` — `includes/invoice.php:1089` (`collectClosedInvoice`): Only selected rows can reach mint/PAID/ISSUED/settle side effects.


**Evidence and strongest visible controls:**


- `includes/database.php:530` — last_polled_at defaults NULL for new invoices.

- `api.php:218` — requireStore establishes that the producer lacks victim-store authority.

- `includes/invoice.php:1081` — Recent and older closed windows have independent due predicates and LIMIT 10 selections rather than one shared batch.

- `includes/api/invoices.php:138` — An authorized GET bypasses active queues for the same store invoice, but its status handler targets only active rows.

- `payment.php:67` — Public checkout rechecks a closed invoice separately.

- `includes/invoice.php:1230` — Closed direct rechecking is limited to the recent 72-hour recovery window.

- `includes/invoice.php:450` — Background polling exists precisely so paid unminted Processing invoices need not wait for a browser visit.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Using two dummy stores and loopback mints, give B due paid New, recent Expired and older Expired quotes with old non-NULL timestamps, and issue no B direct polls during starvation tests. In at most three rounds add eleven otherwise-valid NULL UNPAID A rows to each tested lane. Run pollPendingQuotes and recoverExpiredPaidInvoices with default limit10 and assert B receives no mint calls or settlement while A fills every batch. Stop A supply and verify B settles as an eligibility control. Separately verify direct API/checkout controls for New/recent Expired B; require a fairness fix to reach B under sustained A supply with those direct controls disabled.


**Smallest effective correction if validated:** Use a fair queue: store-aware aging reservation, persistent row cursor or round-robin fairness for active and closed recovery. Keep direct API/checkout recovery intact; test paid B with sustained never-polled A and direct B polls disabled.


### Mint-info timeout clears before response body is consumed

**Fingerprint:** `mint-discovery:http-info-body-read-deadline`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Discovery completion on stalled mint responses

A discovered mint can return headers then stall its JSON body. The only AbortController timer clears when headers arrive, after which response.json has no timer or size cap, holding the batch and discovery UI open indefinitely.

**Claimed root cause:** Timeout covers only engine handoff through headers, not body streaming/parsing; Promise.all waits for every worker.


**Source trace:**


- `entrypoint` — `mint-discovery/mint-info-fetcher.js:31` (`fetch setup`): One AbortController supplies the intended timeout.

- `propagation` — `mint-discovery/mint-info-fetcher.js:42` (`headers complete`): clearTimeout runs before any body handling.

- `propagation` — `mint-discovery/mint-info-fetcher.js:53` (`body consumption`): response.json has no remaining application deadline or byte limit.

- `sink` — `mint-discovery/mint-info-fetcher.js:131` (`batch barrier`): One unfinished response prevents Promise.all workers completing.


**Evidence and strongest visible controls:**


- `mint-discovery/index.js:215` — Streaming discovery awaits batch before normal complete/cleanup.

- `admin.php:7619` — Admin waits for discover before hiding loading and rendering results.

- `assets/js/mint-discovery.bundle.js:2` — Shipped bundle contains the same clear-then-read sequence.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** In local instrumented browser load committed bundle and call fetchMintInfo once against CORS-enabled loopback HTTPS returning status/JSON prefix and stalling body. Observe still-pending after five configured timeouts, then close fixture. Repeat batch; no external network.


**Smallest effective correction if validated:** Keep timeout alive until body consumption finishes, clear it in `finally`, and separately stream/parse with byte/deadline bounds. Cancellation must abort active workers/batch and close subscription/UI state. No need to wait indefinitely on any single mint.


### Unbounded Nostr cardinality drives browser requests and persistent cache work

**Fingerprint:** `mint-discovery:nostr-url-cardinality-to-http-fanout`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Mint discovery browser work and cache

Nostr input can expand many distinct mint URLs, including multiple u tags in one review. Every distinct stale URL becomes an info request and retained state; concurrency is capped but total events/tags/requests/storage are not.

**Claimed root cause:** Discovery uses unbounded event/tag cardinality as its work queue. Per-URL dedup and 20 workers do not place any aggregate ceiling on requests or retained reviews.


**Source trace:**


- `entrypoint` — `mint-discovery/nostr-client.js:249` (`parseReviewEvent`): A review is accepted without application tag-count cap.

- `propagation` — `mint-discovery/nostr-client.js:255` (`multi-URL expansion`): Each accepted u tag yields a distinct review record.

- `propagation` — `mint-discovery/index.js:123` (`discovery queue`): Every stale aggregator URL is queued.

- `sink` — `mint-discovery/mint-info-fetcher.js:108` (`batch workers`): Workers continue until every unique URL is fetched.


**Evidence and strongest visible controls:**


- `mint-discovery/nostr-client.js:145` — Streaming subscriptions omit filter limit; non-streaming defaults still permit 5000 per class plus multi-URL expansion.

- `mint-discovery/storage.js:107` — Every supplied review can be persisted without record/byte quota.

- `admin.php:7614` — Backup discovery waits for the entire non-streaming result.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Relay event size/tag/count acceptance determines practical amplification and is outside source.


**Bounded local validation:** Use in-memory relay/fetch/IndexedDB stubs with four URLs from one review and eight announcements. Record every accepted URL reaching work/storage ledgers before any total cap. If needed view in local browser using tiny fixtures only. Never use public relays.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Relay event size/tag/count acceptance determines practical amplification and is outside source.


**Smallest effective correction if validated:** Cap accepted events/tags, distinct normalized mints, batch size and retained records before queueing work. Cache/render top candidates rather than every URL, offer explicit continuation/cancel, and provide a cache-clear recovery action. Keep relay verification but do not treat signatures as trust.


### Oversized mint quote drives QR work and interrupts customer polling

**Fingerprint:** `qr:unbounded-mint-quote-browser-work`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Customer QR work and payment-status polling

A malicious configured mint can send amount-valid quote request with huge suffix. It is stored and submitted unbounded to QR auto-version selection, which repeatedly processes input before overflow. QR construction precedes initial status timer without try/catch, so an error can stop payment status updates.

**Claimed root cause:** No BOLT11/QR field-size bound or QR exception containment exists before customer code invokes automatic qrcode-generator capacity search.


**Source trace:**


- `entrypoint` — `cashu-wallet-php/CashuWallet.php:7437` (`requestMintQuote`): Remote mint provides quote request string.

- `propagation` — `cashu-wallet-php/CashuWallet.php:7464` (`assertQuoteMatchesRequest`): BOLT11 prefix amount/unit are checked without string-length limit.

- `propagation` — `includes/invoice.php:215` (`Invoice::create`): The raw request persists on the New invoice.

- `propagation` — `payment.php:570` (`checkout QR`): The complete value reaches QRious without try/catch.

- `sink` — `assets/js/vendor/qrcode-generator.js:428` (`automatic capacity search`): Versions 1 through 39 each process the full value before overflow; payment.php:732 schedules poll only later.


**Evidence and strongest visible controls:**


- `cashu-wallet-php/CashuWallet.php:3759` — The strongest upstream bound is the whole 8MiB response envelope, not QR capacity.

- `assets/js/qr-canvas.js:43` — QRious receives the complete value with automatic version selection and no prior guard.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use isolated dummy checkout with amount-valid lnbc prefix and bounded lengths at 1,4,16,64,256KiB. Enforce low renderer CPU/memory/wall limits and stop at first overflow/resource threshold. Check pollStatus against a small control and that error fallback leaves copyable payment request/polling alive.


**Smallest effective correction if validated:** Validate BOLT11 well enough to reject an amount-valid prefix with arbitrary suffix, enforce a protocol-appropriate quote-field cap, guard QR construction, and keep copyable/open-wallet/status-poll fallback alive on QR failure.


### IPv6 rotation bypasses the application budget on public token-receive work

**Fingerprint:** `receive-post:exact-ip-rate-key-mint-work`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Public receive workers/mint calls/store locks

Anonymous token submission receives a fresh 20-request budget per exact IP. Usable addresses from one IPv6 prefix can multiply mint calls, elliptic-curve work and workers waiting up to 25 seconds on a store lock; there is no independent global/per-store admission bound.

**Claimed root cause:** Generic rate limiting uses the exact client address, not the /64 aggregation present for login, and lock/mint work has no separate concurrency or prefix budget.


**Source trace:**


- `entrypoint` — `receive.php:144` (`token POST`): Public token submission has no account credential.

- `propagation` — `receive.php:149` (`admission`): The 20/minute budget is keyed on getClientIp exact address.

- `propagation` — `receive.php:303` (`store lock`): Accepted requests can occupy workers waiting for the per-store lock.

- `sink` — `cashu-wallet-php/CashuWallet.php:8482` (`receive/swap`): Proof-state and swap work follows for accepted tokens.


**Evidence and strongest visible controls:**


- `includes/security.php:146` — Login lockout aggregates IPv6 by /64 but checkRateLimit does not.

- `includes/locks.php:35` — Lock wait polls up to its 25-second deadline without queue/concurrency guard.

- `cashu-wallet-php/CashuWallet.php:8506` — Full DLEQ data also adds per-proof elliptic-curve verification before swap.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Whether clients can present several addresses, proxy normalization, worker pool and upstream caps need owner-observed deployment facts.


**Bounded local validation:** In a two-worker fixture seed counter 20 for 2001:db8::1; send identical valid dummy token POST with that IP and then ::2. First should 429 while second reaches mock mint work. Use only tiny delays and stop after boundary observations.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Whether clients can present several addresses, proxy normalization, worker pool and upstream caps need owner-observed deployment facts.


**Smallest effective correction if validated:** Aggregate public receive/request quotas by normalized IPv6 prefix and add separate global/per-store persistent-row and work/concurrency caps. Allocate rows only after required upstream work succeeds where safe, and add count/byte ceilings with an operator-facing retry response; preserve per-request and proof limits.


### IPv6 rotation enables persistent payment-request creation beyond intended rate budget

**Fingerprint:** `receive-request:exact-ip-rate-key-persistent-rows`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Public payment requests and SQLite growth

Anyone knowing a store ID can create a fresh durable payment_request each allowed GET. Exact-IP budgets rotate by IPv6 address, there is no store/global row quota or dedup and pending rows persist seven days (paid rows intentionally longer).

**Claimed root cause:** Persistent allocation has only an exact-IP fixed-window counter; no aggregate admission or retention quota exists.


**Source trace:**


- `entrypoint` — `receive.php:514` (`request GET`): A caller supplies store_id and positive amount.

- `propagation` — `receive.php:827` (`generation rate`): 30 generations per exact getClientIp address/minute.

- `propagation` — `receive.php:844` (`create call`): Creation runs before mint loading.

- `sink` — `includes/payment_request.php:46` (`database insert`): A fresh random request ID/row is inserted with no cardinality cap.


**Evidence and strongest visible controls:**


- `includes/payment_request.php:388` — Cleanup deletes only pending rows older than 604800 seconds; paid records are retained.

- `includes/database.php:605` — The payment_requests schema has no store/global quota constraint.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Source-address assignment and external request/disk quotas are deployment-dependent.


**Bounded local validation:** Seed 30 for 2001:db8::1 and issue one request for that IP then one for ::2 against a dummy store/mint. Require first 429 and second exactly one new row; inspect retention separately on tiny fixtures, never stress disk.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Source-address assignment and external request/disk quotas are deployment-dependent.


**Smallest effective correction if validated:** Aggregate public receive/request quotas by normalized IPv6 prefix and add separate global/per-store persistent-row and work/concurrency caps. Allocate rows only after required upstream work succeeds where safe, and add count/byte ceilings with an operator-facing retry response; preserve per-request and proof limits.


### Webhook responses are buffered without a byte limit

**Fingerprint:** `webhook-response-body-unbounded`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** Background worker memory on webhook responses

A webhook receiver controls response bytes returned to a shared outbox worker. CURLOPT_RETURNTRANSFER buffers the entire unused response, so a large body within the timeout can exhaust a PHP worker; undelivered rows may be retried and interrupt other background tasks.

**Claimed root cause:** Delivery sets RETURNTRANSFER and time limits but no write callback/response-size bound before status handling or durable completion.


**Source trace:**


- `entrypoint` — `includes/background_runner.php:115` (`webhook_outbox`): Shared runner invokes deliverPending.

- `propagation` — `includes/webhook_sender.php:305` (`sendRequest options`): RETURNTRANSFER and timeouts are set with no response size limit.

- `propagation` — `includes/webhook_sender.php:326` (`curl_exec`): Response is fully buffered before error/status handling.

- `sink` — `includes/webhook_sender.php:371` (`state update`): Attempts/lease/delivery state advance only after HTTP returns; process failure leaves poison row pending.


**Evidence and strongest visible controls:**


- `includes/webhook_sender.php:340` — Response body is deliberately not persisted, making whole-body buffering unnecessary.

- `includes/background_runner.php:93` — webhook_outbox is in the shared ALWAYS_RUN path before rotating tasks.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Use loopback target and fresh dummy outbox. Return 64MiB within four seconds with enforced 32MiB PHP memory, and a 1KiB control. Record worker outcome, row attempts/lease and one subsequent harmless delivery. Expire the lease only deterministically in dummy DB; no production saturation.


**Smallest effective correction if validated:** Use a bounded `CURLOPT_WRITEFUNCTION` that discards body after a small fixed limit, causing a normal failed delivery rather than buffering the irrelevant response. Advance attempts/lease state on that failure and process other tasks.


### Failed revocation deletes the only tracked WordPress credential

**Fingerprint:** `wordpress/uninstall.php:failed-revocation-drops-only-copy`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** B  
**Boundary:** WordPress key revocation and reactivation

When the gateway URL points at this CashuPay site, an exception before the api_keys DELETE commits leaves the credential row behind while uninstall deletes the raw-key option. A party already possessing that raw key can reuse it after the preserved store and API routing are restored by reinstall/reactivation. It remains usable until an explicit same-store Connect replaces old WooCommerce keys or an administrator deletes the listed key by ID. Activation alone performs neither repair, and losing the WP raw-key option does not eliminate administrative repair.

**Claimed root cause:** urlPointsHere independently authorizes credential-option deletion even when keyFound remains false; database exceptions are logged and suppressed without aborting cleanup or preserving the raw-key association.


**Source trace:**


- `entrypoint` — `wordpress/uninstall.php:67` (`credential identification`): Uninstall reads the clear integration key and derives its lookup hash.

- `propagation` — `wordpress/uninstall.php:102` (`suppressed DB failure`): Throwable is logged and cleanup continues; a pre-DELETE failure leaves keyFound false and the row intact.

- `propagation` — `wordpress/uninstall.php:111` (`option deletion`): A matching gateway URL alone deletes the clear API-key option despite failed revocation.

- `sink` — `wordpress/uninstall.php:156` (`preserved wallet`): Wallet data is deliberately retained, preserving the key and store for reactivation.


**Evidence and strongest visible controls:**


- `wordpress/uninstall.php:88` — Key revocation occurs only inside the exception-protected database block.

- `wordpress/btcpay-integration.php:121` — The integration key receives invoice create/view/modify, store-view, and webhook-modify permissions.

- `includes/auth.php:403` — Authentication hashes the raw key and accepts a matching api_keys row joined to its store.

- `wordpress/activation.php:141` — Activation initializes the preserved installation but does not invoke WooCommerce connection or key replacement.

- `wordpress/btcpay-integration.php:61` — Explicit same-store Connect deletes old WooCommerce-application keys when database access works.

- `admin.php:929` — The authenticated dashboard lists keys with IDs and labels without requiring their raw values.

- `admin.php:1954` — Dashboard deletion revokes a selected non-internal key by row ID, providing an alternative repair.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** With one dummy store and saved dummy raw key, take a consistent database/WAL snapshot and induce a pre-DELETE SQLite failure during URL-matched uninstall. Restore the snapshot; verify the raw-key option vanished while its row survived. Reinstall/reactivate without Connect and request a same-store endpoint using the old dummy token; compare success with a bad-key 401. Separately verify same-store Connect and dashboard Delete revoke it, and a healthy-uninstall control revokes immediately.


**Smallest effective correction if validated:** Do not remove the raw-key/URL/store association when revocation fails; retain it as a repair handle or provide an explicit recoverable warning. Consider allowlisted WooCommerce keys for revocation on activation, and test activation separately from reconnect.


### Nostr relay can bypass the 8 KiB handshake limit with one line

**Fingerprint:** `alerts/websocket-handshake-line-bound`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** C  
**Boundary:** Alert worker on malicious relay handshake

A malicious/compromised configured relay can send an oversized unterminated header line. fgets() without a length buffers it fully before the next 8KiB check, risking PHP memory failure and aborting shared background work.

**Claimed root cause:** Handshake loop checks accumulated length only between reads and appends every complete unbounded line.


**Source trace:**


- `entrypoint` — `includes/alerts.php:203` (`sendNostrDm`): The publisher contacts configured third-party relays over TLS.

- `propagation` — `includes/alerts.php:397` (`handshake loop`): The length condition cannot constrain the next read.

- `propagation` — `includes/alerts.php:398` (`unbounded read`): fgets with no length reads an entire peer-controlled line.

- `sink` — `includes/alerts.php:400` (`buffer append`): The complete oversized line is appended before the guard is reevaluated.


**Evidence and strongest visible controls:**


- `includes/alerts.php:386` — TLS peer/name verification authenticates relay but does not constrain response size.

- `includes/alerts.php:391` — Six-second timeout is not an allocation bound.

- `includes/background_runner.php:189` — Runner documents that memory exhaustion is outside Throwable cleanup handling.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.


**Bounded local validation:** Loopback-only dummy TLS listener returns HTTP 101 and X-Filler plus 16KiB unterminated line. Capture whether client crosses cap/emits EVENT and bounded peak RSS, then one deliberately memory-limited case to observe worker effect. Use no real relays or saturation.


**Smallest effective correction if validated:** Read handshake incrementally with `fgets($sock, $remaining+1)` (or equivalent bounded stream read) and reject immediately once the cap is exceeded. Never buffer a line beyond the remaining header budget; regression tests should include one unterminated oversized line.


### Array-shaped cron key may disclose a PHP source path

**Fingerprint:** `health:cron-array-key-warning`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** C  
**Boundary:** Cron diagnostic/source-path disclosure

An anonymous array-valued key query reaches a direct string cast, producing a PHP array-to-string warning before authentication. With display_errors enabled, the response can include source path details before the intended 403.

**Claimed root cause:** cron.php does not require scalar query credentials and does not disable errors before casting, unlike the sibling health endpoint.


**Source trace:**


- `entrypoint` — `cron.php:39` (`key parsing`): The unauthenticated query is cast directly to string.

- `propagation` — `cron.php:59` (`authorization`): Only after the cast is the converted value compared.

- `sink` — `cron.php:61` (`response`): A displayed warning may accompany the invalid-key response.


**Evidence and strongest visible controls:**


- `health.php:54` — The health endpoint validates credentials are strings before comparison.

- `health.php:17` — Health disables displayed errors; cron has no equivalent local startup control.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- The effective display_errors and response-redaction policy for cron.php is not established by source.


**Bounded local validation:** In a dummy sandbox with display_errors=1 request cron.php with an array-valued key and inspect response bytes/path. Repeat with display_errors=0 and scalar validation; expect only intended 403.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. The effective display_errors and response-redaction policy for cron.php is not established by source.


**Smallest effective correction if validated:** Require a scalar/string cron credential before any cast, disable displayed errors in the endpoint, and return the fixed invalid-key response. Apply the same sanitization to all public credential routes.


### Anonymous root may disclose PHP database exception traces

**Fingerprint:** `startup:anonymous-root:uncaught-database-exception`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** C  
**Boundary:** Anonymous root during database startup failure

When an existing database startup/migration fails, root detects existing installation then repeats database access through Config without a sanitizer. On PHP-FPM/CGI with display_errors enabled, exception traces and absolute paths may reach anonymous visitors.

**Claimed root cause:** Root route performs second uncaught DB call after isInitialized fails closed; it does not establish error policy and mod_php-only .htaccess rule is not universal.


**Source trace:**


- `entrypoint` — `index.php:16` (`public root`): Anonymous request invokes isInitialized.

- `propagation` — `includes/database.php:263` (`getInstance`): DB startup/migration error is recorded and rethrown.

- `propagation` — `includes/database.php:415` (`isInitialized`): Existing/unreadable DB returns true rather than opening setup.

- `propagation` — `includes/config.php:48` (`Config::get`): Setup-state read retries getInstance and can throw.

- `sink` — `index.php:22` (`uncaught response`): No root exception handler sanitizes output.


**Evidence and strongest visible controls:**


- `.htaccess:77` — display_errors-off is scoped to mod_php only.

- `health.php:16` — Sibling anonymous endpoint explicitly disables displayed errors for this risk.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- Effective host display_errors and exception renderer are deployment facts.


**Bounded local validation:** Serve scratch dummy DB with config table and PRAGMA user_version=99 under display_errors=1, anonymously request root, record trace/path; repeat 0 as control.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. Effective host display_errors and exception renderer are deployment facts.


**Smallest effective correction if validated:** Catch the root setup-state/database exception and render a generic operational page; log detailed exceptions only to the intended operator log. Disable displayed error output at each anonymous standalone entrypoint rather than relying on mod_php-only configuration.


### Pre-guard preflight can expose diagnostic details before direct-entry policy

**Fingerprint:** `startup:pre-guard-preflight-diagnostic-exposure`  
**Status:** `needs_validation`; no vulnerability severity assigned  
**Validation order:** C  
**Boundary:** Direct plugin requests before normal guard

Direct plugin entry intended to receive generic 403 first runs preflight. With broken config/unwritable data dir, preflight may display exception/filesystem detail and exit before direct-entry enforcement or safe-mode handling.

**Claimed root cause:** admin/setup/index require preflight before entrypoint_guard; preflight has response-exit branches with escaped but still explicit filesystem/error detail.


**Source trace:**


- `entrypoint` — `admin.php:9` (`direct plugin entry`): Preflight loads one line before entrypoint_guard.

- `propagation` — `includes/preflight.php:52` (`preflight`): Only real WP bootstrap constants skip checks, not direct request by itself.

- `propagation` — `includes/preflight.php:121` (`writability failure`): Detail includes not writable plus selected absolute path.

- `sink` — `includes/preflight.php:44` (`diagnostic response`): Detail is escaped but displayed and helper exits before guard.


**Evidence and strongest visible controls:**


- `includes/entrypoint_guard.php:32` — Later policy intends a generic 403 for direct plugin execution.

- `includes/entrypoint_guard.php:63` — SafeMode halting also runs only after preflight returns.

- `admin.php:9` — Same ordering appears in setup/index entrypoints.


**Decisive blocker(s):**


- Target execution was prohibited in this source-only run because the complete required OS-enforced process, memory and aggregate-disk sandbox controls were unavailable; the decisive state transition or runtime response has not been observed.

- The web server must map direct plugin PHP URL without an upstream deny; actual mapping is not source-proven.


**Bounded local validation:** Serve plugin-shaped scratch tree with dummy read-only data directory and request admin.php directly without WordPress constants. Compare intended 403 with actual output containing dummy absolute path; repeat config-error and valid emergency flag variants.


**Owner-observed staging/deployment validation:** The owner should verify the exact deployment configuration and affected preconditions on an owner-controlled staging copy with dummy data; do not probe a live service. The web server must map direct plugin PHP URL without an upstream deny; actual mapping is not source-proven.


**Smallest effective correction if validated:** Enforce direct-plugin refusal and the safe-mode response before diagnostic rendering. Log rich preflight details privately and show generic diagnostics/action text without absolute paths. Escaping prevents HTML injection but does not make local path disclosure acceptable.


## Detailed money-safety and UX findings

These are source-supported functional findings rather than incomplete security exploits. Corrections here are meant for non-technical operators and customers; they should not send people into CLI, config files or logs when an authenticated browser workflow can safely do the work.

### UX-A1 — Upgraded v6 databases lack columns required by webhook events

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** The realistic schema-v6 fixture creates webhook_deliveries without invoice_id or event_type. Fresh schemas and WebhookSender::fireEvent require both; migrations through v14 do not add them. Invoice writes and event enqueues share a transaction.

**Impact:** With an enabled webhook, new invoice creation or settlement can fail on an unknown column and roll back. Customers can be unable to check out, or a paid invoice can stay unsettled with no shop notification.

**Smallest effective fix:** Bump and repair the schema so both columns exist before webhook code runs; add safe defaults/backfill from payloads. Extend upgrade_path.php with an actual enabled-webhook invoice-create and settle path, including installations already labelled v14.

**Source evidence:** `tests/upgrade_path.php:39-47`, `includes/database.php:549-568`, `includes/database.php:646-847`, `includes/webhook_sender.php:157-169`, `includes/invoice.php:203-230`, `includes/invoice.php:504-529`

### UX-A10 — Automatic data relocation stops being available once a wallet exists

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** Automatic relocation is setup-only and explicitly refuses a store that already has a seed. Existing-wallet WordPress operators instead receive move-then-edit-wp-config guidance. Markers and consistent-copy machinery exist, but no safe funded-wallet browser flow binds them together.

**Impact:** After real payments exist, the target operator cannot perform the browser-first remediation and may not manage file/config changes. Removing the guard without coordination would instead risk moving the active wallet mid-payment.

**Smallest effective fix:** Add a password-confirmed admin move that pauses work, copies/reopens and validates the destination, records ownership/location, then removes source only after success. Teach bootstrap to honor the app-recorded move; offer copyable host-support text only where PHP cannot create/pin an outside folder.

**Source evidence:** `setup.php:191-207`, `setup.php:1324-1367`, `includes/database.php:58-109`, `includes/database.php:135-228`, `wordpress/admin-menu.php:362-389`, `wordpress/bootstrap.php:203-243`

### UX-A11 — CashuPay URL detection accepts unrelated prefix-sharing BTCPay paths

**Priority / affected:** P3; WordPress

**Source behavior:** Configuration treats any gateway URL beginning with site_url('/cashupay') as CashuPay, including /cashupay-copy. Uninstall correctly uses normalized exact/slash-boundary matching, but connect overwrites settings using the broader predicate.

**Impact:** In the rare same-origin prefix collision, clicking Connect can replace a real third-party BTCPay configuration without the intended refusal.

**Smallest effective fix:** Share one normalized predicate across connect/uninstall: same host and normalized exact path, or path followed by slash only. Preserve trailing-slash normalization and reject arbitrary prefixes.

**Source evidence:** `wordpress/btcpay-integration.php:16-28`, `wordpress/btcpay-integration.php:74-89`, `wordpress/uninstall.php:14-24`, `wordpress/uninstall.php:59-65`

### UX-A12 — Health can stay HTTP 200 when no store can create an invoice

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** After completed setup, zero ready stores is only WARN/degraded/200. Readiness requires mint_url and seed_phrase but omits wallet_account_id, although Config::isStoreConfigured requires all three and Invoice::create refuses without it. The Settings copy promises HTTP 503 when payments cannot be taken.

**Impact:** An uptime service waiting for 503 does not alert on the exact no-usable-store condition. A partially broken row can be detailed as ready even though creation fails.

**Smallest effective fix:** Use the same local readiness fields as Config::isStoreConfigured and make zero usable stores FAIL/503 after setup. Keep individual mint/dependency outages governed by existing payment-path checks; no new network probe is required.

**Source evidence:** `includes/health.php:121-129`, `includes/health.php:192-204`, `includes/config.php:289-298`, `includes/invoice.php:121-129`, `admin.php:4461-4469`, `tests/health_endpoint.php:65-82`

### UX-A2 — WooCommerce setup reports success when webhook registration failed

**Priority / affected:** P1; WordPress

**Source behavior:** The connector writes WooCommerce settings and returns outer success=true even when nested webhook creation fails. Setup uses only that outer flag, announces full configuration and clears context. Subsequent dependency detection accepts a nonempty gateway URL and stops offering repair, although the wp-admin connect handler correctly explains nested failure.

**Impact:** Invoices can be created, but orders remain Pending payment because WooCommerce never receives settlement events. The false success suppresses clear warning and obvious retry route.

**Smallest effective fix:** Require outer success AND webhook.success before declaring full configuration; otherwise show the concrete notification failure and keep the Connect action/store context. Dependency detection should verify an enabled webhook for the selected store and expected callback, not merely a URL.

**Source evidence:** `wordpress/btcpay-integration.php:74-97`, `setup.php:2143-2156`, `setup.php:2176-2192`, `setup.php:2303-2308`, `wordpress/btcpay-dependency.php:175-196`, `wordpress/btcpay-dependency.php:321-334`

### UX-A3 — Failed shop notifications have no operator-facing redelivery path

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** The dashboard exposes only an aggregate failed-delivery count; no affected invoice/order, event, error, attempt deadline or action. Retries stop at 80 attempts. Detailed per-delivery records and redelivery already exist in the Greenfield/WebhookSender implementation but not in operator UI.

**Impact:** A settled order can remain unpaid in WooCommerce indefinitely after retries exhaust. The intended non-technical operator has no way to identify which order needs action or press a native recovery button.

**Smallest effective fix:** List each failed notification with order/reference, event, last result, attempts and exhausted/next retry. Add authenticated CSRF-protected Tell the shop again to requeue the selected delivery using the existing code, alongside repair of the shop connection; do not request another invoice.

**Source evidence:** `admin.php:306-309`, `admin.php:6033-6035`, `includes/webhook_sender.php:354-378`, `includes/webhook_sender.php:426-454`, `includes/api/webhooks.php:306-358`

### UX-A4 — Database backup download has no browser-based restore

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** The dashboard makes a password-confirmed consistent SQLite download and shows the data-folder location but no paired restore UI. It explains that seed alone cannot restore invoices, keys, webhooks, transfers or settings; upgrade recovery only says restore the data folder.

**Impact:** A non-technical operator has a valid full backup but cannot recover operational state without learning server placement and sidecar semantics. Seed protects funds, not orders and shop configuration.

**Smallest effective fix:** Add authenticated Restore database backup to Settings/fresh setup. Verify SQLite integrity and supported schema, pause background writes, preserve current state for automatic rollback, atomically activate a validated backup while handling WAL sidecars, and show restored stores/invoices before reopening payments.

**Source evidence:** `admin.php:1223-1268`, `admin.php:4273-4305`, `admin.php:7482-7505`, `admin.php:901-905`, `README.md:236-238`

### UX-A5 — Stale background processing is absent from wp-admin attention notices

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** Alerts::problems reports recorded task failures but never checks cron_last_run or heartbeat age, and wp-admin consumes that list. A runner that never runs or completely stops will not produce that native warning or reliably issue its own email/Nostr alert.

**Impact:** The operator can keep using wp-admin while active payment polling, notifications and auto-payouts silently stop. There is no warning where the operator normally looks.

**Smallest effective fix:** Add never-run/stale-heartbeat to shared problems with business-impact wording and direct link to scheduling repair. Keep an external health monitor for total scheduler failure, since the stopped process cannot notify itself.

**Source evidence:** `includes/alerts.php:32-88`, `wordpress/admin-menu.php:472-505`, `includes/background_runner.php:151-154`, `includes/background_runner.php:369-405`

### UX-A6 — Stale-runner guidance confuses a one-off run with restoring recurring work

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** The warning points to setup-only cron instructions and Run background tasks now performs one run, not recurring scheduling. Post-setup Settings exposes monitoring credentials but no actual recurring cron endpoint/key. Visitor/WP-Cron modes exist and a timed cron is not mandatory in every setup.

**Impact:** Payments can process once while the automatic trigger remains broken and the warning returns. The operator can also mistakenly expect an uptime-health check to perform payment work.

**Smallest effective fix:** Add a persistent Background checks settings card, linked from the warning. Label Run once now, explain visitor/WP-Cron operation and provide actual recurring URL/key and copyable hosting/web-cron instructions when needed. Say explicitly that health monitoring observes rather than executes checks.

**Source evidence:** `admin.php:1483-1492`, `admin.php:4051-4053`, `admin.php:4461-4485`, `admin.php:5166-5198`, `admin.php:5956-5959`, `setup.php:2052-2080`, `wordpress/activation.php:166-169`

### UX-A7 — Readable-link repair declares success for JSON errors and unreachable self-checks

**Priority / affected:** P1; WordPress

**Source behavior:** cashupay_api_reachable checks only JSON content type, not HTTP status or the expected server-info payload. It returns null on request error; the pretty-link handler treats only strict false as failure, so a JSON error or unreachable self-request can produce success.

**Impact:** The repair flow tells the operator to continue even though WooCommerce still cannot create invoices through an unhealthy route, ending guidance at a false result.

**Smallest effective fix:** Return true only for HTTP 200 with decoded isCashuPayServer=true. Report wrong payload/status as unreachable and request failure as could not verify, retaining the existing permalink/.htaccess remediation in both cases.

**Source evidence:** `wordpress/admin-menu.php:64-100`, `api.php:233-252`

### UX-A8 — WordPress exposure probe mistakes soft-404 pages for wallet downloads

**Priority / affected:** P2; WordPress

**Source behavior:** The WordPress probe treats every HTTP 200/206 for a locally existing sensitive filename as exposure without inspecting content. Hosts returning an HTML 200 for every URL therefore trigger the condition; standalone probes accept only SQLite/non-HTML file-like content.

**Impact:** An ordinary soft-404 can generate a false claim that anyone downloaded the wallet and cause panic/support escalation without a trustworthy diagnostic.

**Smallest effective fix:** Reuse content-aware standalone checks: require a SQLite signature where applicable, reject HTML, and preferably compare expected local header bytes. Display inconclusive rather than exposed or safe when the response cannot identify the real file; add a soft-404 case.

**Source evidence:** `wordpress/admin-menu.php:278-330`, `wordpress/admin-menu.php:348-389`, `setup.php:773-793`, `admin.php:532-539`

### UX-A9 — Checkout polling hides the last known payment state on a JSON error

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** pollStatus does not require response.ok or a recognized status. A parsed error object lacks status, so updateUI(undefined) hides pending, processing, settled and expired cards. Polling continues but no temporary-error reassurance is shown.

**Impact:** A customer loses the visible payment/processing state and especially the Do not pay again reassurance, making a second payment attempt more likely during server/update errors.

**Smallest effective fix:** Accept only successful responses with New, Processing, Settled, Expired or Invalid. On transport/HTTP/schema error preserve the previous card and add: We cannot check this payment right now. If you already paid, do not pay again; we will keep checking.

**Source evidence:** `payment.php:659-696`, `payment.php:699-729`, `payment.php:481-499`, `payment.php:15-21`

### UX-F1 — Token intent and proof reservation are not atomic before donation retry

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** Transfer::open durably stores the pending row and bearer token before Invoice::markProofsExported transitions proofs on a separate PDO/database connection. Ordinary execution reserves before disclosure, but a crash or concurrent reconciliation can observe the already-pending donation before reservation. Donation recovery checks mint UNSPENT state but not local EXPORTED ownership.

**Impact:** The same spendable proof can be selected for a merchant withdrawal/export and disclosed to the donation sink. Only the first redemption succeeds, potentially losing the intended withdrawal or causing an unintended donation.

**Smallest effective fix:** In one transaction on one PDO, insert the exact transfer and transition every associated proof to EXPORTED. Require a complete count match and rollback any partial reservation. Commit before any sink POST/browser disclosure; never hold that transaction over the network. Recovery must also require local EXPORTED ownership.

**Source evidence:** `admin.php:2540-2568`, `admin.php:2657-2688`, `admin.php:2720-2759`, `includes/lightning_address.php:960-995`, `includes/background_runner.php:732-799`, `includes/transfer.php:48-85`, `includes/invoice.php:1641-1655`, `cashu-wallet-php/CashuWallet.php:3980-4007`, `cashu-wallet-php/CashuWallet.php:4716-4749`

### UX-F2 — Stranded recovery rebuilds tokens and treats acknowledgement as redemption

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** A recovery export promotes all currently UNSPENT proofs and serializes the complete EXPORTED set, including any pre-existing exports, rather than an immutable prior token. An explicit operator acknowledgement then changes the saved secrets to SPENT without mint-state verification or a durable transfer.

**Impact:** A changed export set can leave two different live tokens containing the same proofs and two recipients who think they own the same value. A mistaken acknowledgement can hide an unclaimed token from future scanners. The UI does not falsely claim mint verification; the defect is missing immutable/exported-state tracking and safe fallback handling.

**Smallest effective fix:** Persist the exact token, ordered secrets, namespace and state atomically with reservation and always redisplay that record. Keep new value in a separate export. Distinguish optional verified NUT-07 redemption from a separately labelled, auditable offline write-off instead of silently writing SPENT.

**Source evidence:** `includes/invoice.php:1703-1734`, `includes/invoice.php:1760-1769`, `admin.php:2978-3036`, `admin.php:3045-3068`, `admin.php:8134-8181`

### UX-F3 — UNKNOWN value is invisible to deletion guards and backup reconciliation

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** UNKNOWN is a persisted uncertainty state, and syncProofStates can reconcile it. Nevertheless, per-account deletion/removal and stranded scanners omit it, getBalance sums only UNSPENT, and background sync skips a backup whose UNSPENT balance is zero. Primary accounts are included and do not have this skip.

**Impact:** UNKNOWN-only backups can be removed without a holdings warning or reconciled. A former namespace can disappear from recovery, and a store can be deleted without its configured recovery account even if later verification would show value.

**Smallest effective fix:** Build one namespace summary used by deletion guards, scanners and sync: UNSPENT, PENDING, UNKNOWN, EXPORTED and journals all represented. Include UNKNOWN as a blocker and select every account containing it for reconciliation before export or deletion; never assume zero known balance means zero value.

**Source evidence:** `includes/store_safety.php:117-134`, `includes/store_safety.php:179-191`, `includes/store_safety.php:268-321`, `includes/invoice.php:1784-1789`, `includes/background_runner.php:922-940`, `includes/background_runner.php:960-973`, `cashu-wallet-php/CashuWallet.php:6197-6209`, `cashu-wallet-php/CashuWallet.php:9551-9655`

### UX-F4 — Journal-only old mints are absent from stranded recovery

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** The stranded scanner starts only from UNSPENT/EXPORTED proofs and never enumerates pending-operation journals. Scheduled library recovery visits accounts in existing stores only, although StoreSafety can recognize an old journal-only namespace as a deletion concern.

**Impact:** A former account whose only recovery clue is an interrupted journal can be reported as having no stranded funds even though no scheduled worker will recover it. Collecting that value requires reconstructing the old account configuration manually.

**Smallest effective fix:** Include pending-operation wallet IDs as unresolved, non-exportable stranded entries. Resolve their mint/unit/account from durable history and offer a recovery action that reattaches the seed/account for the appropriate journal recovery. Never serialize journal-reserved inputs as a recovery token.

**Source evidence:** `includes/invoice.php:1784-1887`, `includes/invoice.php:2023-2068`, `includes/store_safety.php:179-191`, `includes/store_safety.php:224-238`, `includes/background_runner.php:102-106`

### UX-F5 — Recovery sheets omit payment-request mints and depend on prunable history

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** RecoveryKit::mints reads current accounts, invoices and transfers, not payment_requests, even though those rows hold mint URL and unit and paid ones are retained. Settled invoices are pruned after 90 days even if ecash remains in that mint. No durable append-only used-mint history exists.

**Impact:** After losing the site, an operator can have the right seed but no recorded mint reference for old payment-request funds. Historical invoice pruning can produce the same gap for mint/invoice funds.

**Smallest effective fix:** Include payment-request mints immediately and use an append-only per-store mint/unit history for the kit and fingerprints. Payment-log retention and cleanup must not irreversibly erase the only recovery reference.

**Source evidence:** `includes/recovery_kit.php:22-72`, `includes/payment_request.php:38-58`, `includes/payment_request.php:388-394`, `receive.php:312-347`, `receive.php:455-472`, `includes/background_runner.php:1027-1045`

### UX-F6 — Recovery sheet is marked taken before generation and delivery

**Priority / affected:** P2; standalone, WordPress

**Source behavior:** The recovery_kit action calls markTaken before format selection, payload generation and echo. Further reads can fail and delivery is not observed before the reminder marker is stored.

**Impact:** The dashboard can suppress subsequent reminders although the operator never obtained the recovery sheet, precisely when that information may be needed.

**Smallest effective fix:** Generate the entire selected payload before changing state and only acknowledge after successful delivery. Prefer an explicit post-download operator confirmation rather than declaring completion before output.

**Source evidence:** `admin.php:1187-1221`, `includes/recovery_kit.php:55-72`, `includes/recovery_kit.php:74-129`

### UX-F7 — Store deletion and mint changes check funds without excluding new fund movements

**Priority / affected:** P1; standalone, WordPress

**Source behavior:** Dashboard delete/mint update and Greenfield delete check blockers then mutate without funds_<storeId>. Exports, withdrawals, reclaims, auto-withdrawals and keyset rotation use that lock, and backup removal correctly holds it across check and mutation.

**Impact:** A payout/export can create unresolved value after the safety check but before mutation. Store deletion can cascade the store/transfer configuration while its operation remains unresolved, and the new case was not described by the earlier confirmation.

**Smallest effective fix:** Acquire funds_<storeId> before reading store/blockers, re-read state under the lock and hold it through update/delete with try/finally release. Apply the equivalent lock or store-generation predicate anywhere invoice/payment creation is needed to keep the outstanding-payment blocker true.

**Source evidence:** `admin.php:1757-1819`, `admin.php:1892-1936`, `includes/api/stores.php:122-162`, `admin.php:2411-2420`, `admin.php:2896-2930`, `includes/lightning_address.php:399-438`, `includes/locks.php:1-28`


## Claims not elevated to findings

- Normal export/donation disclosure follows proof reservation. The defect is the crash/concurrency window after the earlier pending-row commit, not unconditional disclosure before reservation.
- Primary accounts are included in UNKNOWN reconciliation; the noted omission affects backup and former accounts.
- Paid payment-request records are retained by the cleanup path, but are omitted from recovery kits; old settled invoices separately suffer prunable recovery history.
- The stranded-recovery acknowledgement is an explicitly operator-confirmed claim, not a fake mint-verification feature. The remaining risk is immediate write-off with no immutable export/transfer state and no optional verification.
- Redisplay does not always change tokens; the affected case is changed/merged EXPORTED membership without an immutable token snapshot.
- WooCommerce's wp-admin connect-result handler does explain nested webhook failure. Setup and later dependency detection are the paths that still misreport it.
- Webhook redelivery exists at Greenfield/sender layer; the operator dashboard does not expose it. Visitor-triggered work/WP-Cron is legitimate; the warning should restore recurring trigger health, not insist on a single infrastructure choice.
- Detailed health has a real failure→503 transition. Other source problems are lost per-item failure reporting and the zero-usable-store/readiness semantics, not complete absence of monitoring logic.

## Coverage record

All semantic ledger blocks, exact source paths and owned source checks validate with the skill validator. Earlier critics uncovered invoice settlement, webhooks, alert transport, QR/UR codecs, startup preflight and discovery build provenance; all were assigned and reviewed rather than silently deferred. The final post-wave and distinct final-clean critics returned no accepted missing units or reassignments.

| Area | Result / source checks |
|---|---|
| `browser-client-side` | closed covered without a security candidate; 1 source check(s) |
| `cashu-wallet-durable-funds` | 3 validation lead(s); 7 source check(s) |
| `cashu-wallet-protocol` | 1 validation lead(s); 3 source check(s) |
| `data-deployment-release` | 3 validation lead(s); 7 source check(s) |
| `funds-withdrawal-transfer-recovery` | closed covered without a security candidate; 1 source check(s) |
| `health-background-monitoring` | 4 validation lead(s); 9 source check(s) |
| `invoice-pricing-and-currency` | 1 validation lead(s); 3 source check(s) |
| `invoice-settlement-lifecycle` | 1 validation lead(s); 3 source check(s) |
| `mint-discovery` | 2 validation lead(s); 5 source check(s) |
| `mint-discovery-build-provenance` | closed covered without a security candidate; 1 source check(s) |
| `operator-alert-transport` | 1 validation lead(s); 3 source check(s) |
| `public-payment-availability` | 2 validation lead(s); 5 source check(s) |
| `standalone-identity` | 1 validation lead(s); 3 source check(s) |
| `standalone-setup-pairing` | 1 validation lead(s); 3 source check(s) |
| `startup-preflight` | 2 validation lead(s); 5 source check(s) |
| `ux-money-safety-operability` | closed covered without a security candidate; 1 source check(s) |
| `vendor-payment-codecs` | 1 validation lead(s); 3 source check(s) |
| `webhook-api-outbox-wordpress` | 1 validation lead(s); 3 source check(s) |
| `wordpress-plugin` | 1 validation lead(s); 3 source check(s) |

## Important exclusions and remaining uncertainty

This source-only work does not certify runtime Apache/nginx rules, public client/proxy IP behavior, PHP error settings, actual POSIX file modes/UID traversal, remote mint semantics, relay limits, real browser timing or memory outcomes, WooCommerce/BTCPay plugin internals, third-party dependency internals or absence of known dependency CVEs. Release byte parity is a static comparison, not a reproducible rebuild. No dynamic production interaction was performed or suggested.

## Regression cases to require with fixes

1. Two-cookie setup recovery ending in a try to create a wildcard API key with the old session.
2. Fake-mint state matrix: direct/lost PAID, empty/short signed NUT-08 change, expiry during POST then late PAID; assert input, journal, change and consumer status each time.
3. Restore with a vanished/incomplete/errored keyset: no ready flag and no omitted historical funds/counters.
4. A→B rate transition exactly after the guard: final BOLT11 uses approved amount/snapshot or `rate_changed`.
5. Two WordPress installations with cloned credentials and owner markers; cancellation cannot touch the sibling wallet. Failed revocation, activation and explicit reconnection tested separately.
6. Upgrade a realistic v6 database with an enabled webhook and actually create/settle an invoice.
7. Crash between pending transfer/proof reservation plus a reconciling donation; no disclosure/retrial of unreserved or merchant-spendable proofs.
8. Recovery fixtures: UNKNOWN-only backup, journal-only former namespace, payment-request-only mint, and previously redisplayed token with newly arriving value.
9. Bounded queue/resource cases: one slow PENDING ahead of a fast one; continuous never-polled rows with direct-bystander-poll disabled; small bounded HTTP body/handshake/Nostr payload/QR fixtures and diagnostics.
10. Customer JSON error preserves last known payment state and “do not pay again”; WordPress soft-404 and JSON 404 never report wallet exposure or server reachability.

