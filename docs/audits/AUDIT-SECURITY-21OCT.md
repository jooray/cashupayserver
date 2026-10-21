# CashuPayServer — unified security audit record (21 October 2026)

Three independent audits were run against **v0.5.6-alpha (`4a82d01`)** in October 2026.
This document merges their findings into one list, says which model/audit found each one,
what each audit recommended, and how it was resolved and verified in **v0.5.7-alpha**.

| Audit | Model | Report | Style |
|---|---|---|---|
| OPUS55 | Claude Opus 5.5 | `docs/audits/SECURITY-OPUS55.md` | Full dynamic audit: reconnaissance, hunters, reproductions, two verifiers per finding |
| DS41 | DeepSeek V4.1 Flash (1017) | `docs/audits/SECURITY-DS41flash1017.md` | Static source review |
| MINIMAX | MiniMax M3.1 | `docs/audits/SECURITY-MINIMAXM31.md` | Sandboxed dynamic audit with a coverage ledger |

All three worked from the same clean commit, so where they overlap the duplicate is
listed once with every audit that found it. Severity is the reconciled severity after
checking the actual boundary crossing; where an audit rated something higher, the
difference is noted.

Verification of the fixes: `php tests/run.php` → **45 suites, 0 failures** (including the
wallet library's PHPUnit suite). Findings that were not fixed are marked **Residual** or
**Not a vulnerability**, with the reason.

## 1. Authentication, setup and sessions

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| A1 | `setup.php?mode=add_store&step=2` lets an admin session reset the admin password without the current one, and without retiring sessions | OPUS C4, DS41 H-1, MINIMAX 4.14 | medium (MINIMAX: high) | Clamp `add_store` to the store steps; never run the password step on an existing install; regenerate the session on a new claim | Fixed: steps below 4 are refused for `add_store` and completed installs; step 2 refuses an existing hash (`setup.php`, test `setup_wizard_2026_10.php`) |
| A2 | A setup claim does not regenerate the session id; strict mode is off, so a planted session cookie can inherit a claim | DS41 N-4 | low | Regenerate the session id on claim; enable `session.use_strict_mode` | Fixed: `includes/setup_ownership.php` regenerates and clears wizard state; `includes/auth.php` strict mode (test `auth_session_2026_10.php`) |
| A3 | The first claimant's `base_url` survives an idle-release/recovery-code takeover, and a `//host` path makes `Urls::admin()` protocol-relative | DS41 M-2 | medium | Clear `base_url`, `url_mode` and half-finished stores on a new claim; require confirmation before pinning another host | Fixed: `includes/setup_ownership.php`, `setup.php` (test `setup_wizard_2026_10.php`) |
| A4 | An unfinished store left by a previous claimant is adopted by name (default "My Store") | DS41 M-3 | medium | Adopt only stores created in the current session; show the mint before the seed step | Fixed: adoption is session-scoped (`setup.php`) |
| A5 | No idle session timeout; logout does not clear the cookie; strict mode off | DS41 L-10 | low | 60-minute idle timeout, strict mode, expire the cookie on logout | Fixed: `includes/auth.php` (test `auth_session_2026_10.php`) |
| A6 | Setup can finish without an admin password, leaving an install nobody can log into | DS41 L-6 | robustness | Refuse to complete setup without a password | Fixed: `setup.php` step guard |
| A7 | WordPress: the "password" prompt on *Show seed phrase* / *Download backup* is not checked — the WordPress capability alone passes | MINIMAX 4.1 | medium-high (MINIMAX: high) | Require a fresh proof: the signed-in WordPress user's password | Fixed: `Auth::verifyAdminPassword` checks `wp_check_password`; the prompt says "WordPress password" (test `auth_session_2026_10.php`) |
| A8 | Admin-password lockout is per derived address only, with no global ceiling | MINIMAX 4.7 | medium | Add a small address-independent budget, cleared by a successful sign-in; keep authenticated re-auth prompts out of it | Fixed: 20 granted password attempts per 5 minutes, `Security::reserveLoginAttempt(..., true)`; test `minimax_2026_10.php` |
| A9 | Trusted proxies can name `0.0.0.0/0`, so every visitor chooses the address that keys login/rate limits | MINIMAX 4.3 | medium | Reject ranges wider than a plausible proxy; ignore already-stored wide ranges; clear the list when leaving "custom" mode | Fixed: `includes/security.php` width rules and read-time filtering; `admin.php` save clears unused list (test `minimax_2026_10.php`) |
| A10 | In automatic proxy mode any loopback/private/CGNAT peer is trusted, so a LAN/VPN client can choose its own identity; IPv6 buckets are not aggregated | OPUS N5, DS41 L-11 | low | Remove CGNAT from trusted ranges; aggregate lockouts per IPv6 /64; warn on a peer with rotating forwarded addresses (do not refuse: a real proxy is shared) | Fixed: `includes/security.php`; per-peer counter warns (test `auth_session_2026_10.php`) |
| A11 | Pairing login redirects to the raw `REQUEST_URI` (`//host`) | OPUS C8, MINIMAX 4.21 | low | Rebuild a root-relative path; refuse `//` and `/\` at the router | Fixed: `api-keys/authorize.php`, `router.php` (test `auth_session_2026_10.php`) |
| A12 | Pairing approval permits a loopback redirect | DS41 L-7 | not a vuln | — | Rejected: the key goes to the operator's own browser; local WooCommerce development needs localhost |
| A13 | `canviewstoresettings` implies `canviewinvoices`, and the pairing label hides that it can read every invoice | DS41 L-1 | low | Say what the permission really grants | Fixed: label says "View store settings, and read all invoices …" |
| A14 | Store read endpoints require no permission | DS41 L-2 | not a vuln | — | Informational: the key already knows its store id/name; one detail was wrong (`handleGetStores` filters by the key's store) |

## 2. Payments, invoices and money handling

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| B1 | A spent or donated token can be replayed to mark a NUT-18 payment request paid | OPUS C3, DS41 M-1, MINIMAX 4.12 | medium | Bind the claim to proofs stored after it, and release a fresh claim on secrets the wallet already holds | Fixed: `includes/payment_request.php` `receiptState`/`secretsKnown` (test `payment_request_binding.php`) |
| B2 | Public `receive.php` spends ~10 s of CPU per request (1000 copies of one DLEQ proof) | OPUS C1, MINIMAX 4.4 | medium | Refuse duplicates, check NUT-07 before DLEQ, cap proofs and body; bound DLEQ scalars | Fixed: library duplicate refusal + NUT-07 ordering + 64-hex bound; `receive.php` 100-proof and body caps (test `receive_endpoint.php`, `network_guard_2026_10.php`) |
| B3 | The NUT-18 fee leniency lets the payer choose the proof count and underpay by up to `fee_ppk/1000` | DS41 Obs 4 | low | Limit the shortfall to the fee of the minimal proof split | Fixed: `receive.php` |
| B4 | A `New` invoice is expired without asking the mint, so a payment just before expiry gives Expired then Settled | DS41 Obs 1 | low | Expire only after the mint confirms unpaid, or one hour past expiry | Fixed: `includes/invoice.php` `EXPIRY_CONFIRM_WINDOW` (test `invoice_expiry_2026_10.php`) |
| B5 | Late-payment recovery drops the only link to a paid mint quote on one stale reading | MINIMAX 4.25 | low | Delay the write-off, and guard the update by status | Fixed: seven-day delay after the mint quote expiry, status-guarded update (`includes/invoice.php`) |
| B6 | The stranded-funds export is read-only, so the same proofs can be handed out in a second live token and remain spendable by the app | MINIMAX 4.9 | medium | Reserve the proofs, show the same token on a re-export, and write off exactly that export | Fixed: proofs become `EXPORTED`, balance excludes them, export id binds "mark recovered" (`includes/invoice.php`, `admin.php`; test `integration_keyset_v2.php`) |
| B7 | "Mark recovered" can write off a payment that arrived after the exported token, because each export replaces the session list | MINIMAX 4.20 | low | Bind the write-off to one export id | Fixed: `admin.php` export id + `mark_stranded_recovered` |
| B8 | Re-checking a token the operator took back flips the terminal "failed" row to "completed" | MINIMAX 4.18 | low | Only pending rows may move to a terminal state | Fixed: `includes/transfer.php`, `admin.php` `check_transfer` (test `minimax_2026_10.php`) |
| B9 | Automatic withdrawal keeps re-posting a pending donation after donations are switched off | MINIMAX 4.23 | low | Honour the operator's switch in the retry loop | Fixed: `includes/background_runner.php` |
| B10 | A store's seed phrase becomes replaceable when its current wallet namespace is empty | MINIMAX 4.24 | low | Once a phrase exists, never replace it in place | Fixed: `includes/config.php` `updateStore` (test `minimax_2026_10.php`) |
| B11 | A mint quote is not checked against the request: absent/zero amount, amountless or `lightning:` BOLT11, non-sat units | DS41 Obs 2 | low (mostly refuted) | Require the amount and a matching BOLT11 | Fixed: library `assertQuoteMatchesRequest`; the report's blanket "validation absent" was refuted |
| B12 | `remove_backup_mint` ignores open quotes | OPUS (rejected) | — | — | Rejected: operator-only, and only the operator's own view is affected |
| B13 | Mint connections are never pinned to the vetted addresses (DNS rebinding) | OPUS N2, DS41 L-4, MINIMAX 4.11 | needs validation | Re-resolve and pin at connect time | Fixed: library `ConnectGuard` + `includes/mint_helpers.php`; test `network_guard_2026_10.php` |
| B14 | Automatic withdrawal sizes an irreversible payout from a third-party rate, with no exchange-fee treatment | MINIMAX 4.5 | medium | Apply the same fee direction as invoices and record the rate | Mitigated: fee applied, rate and converted sats recorded; the mint's melt quote remains the authority. Residual documented |

## 3. Pricing and conversions

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| C1 | One price provider can ratchet the invoice rate step by step and underprice fiat invoices | OPUS C2, MINIMAX 4.6 | medium | Require two providers to agree; keep a bounded reference; refuse a single unconfirmed source | Fixed: `includes/rates.php` two-provider anchor; tests `rates_2026_10.php`, `conversion.php`. Residuals: ±5 % single-provider authority for 24 h; first rate for a one-provider currency is trusted once |
| C2 | A wrong rate can freeze fiat checkout for up to 24 h | MINIMAX 4.6 | medium | Say plainly why prices are paused and resume automatically | Fixed copy: `includes/rates.php` `UNAVAILABLE_MESSAGE`; the fail-closed rule stays |

## 4. Outbound network and SSRF

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| D1 | The setup self-probe's `CONNECT_TO` pin is bypassed by a numeric-IPv4 Host header (blind SSRF/port oracle) | OPUS C9 | low | Use the wildcard `CONNECT_TO` and check the address actually reached | Fixed: `setup.php` |
| D2 | `test_mint` skips `assertSafeMintUrl` | OPUS C7, DS41 L-3, MINIMAX 4.27 | low | Enforce the policy inside the fetch function | Fixed: `Config::testMintConnection` |
| D3 | LNURL-pay resolution and its callback have no private-address policy | OPUS N1, MINIMAX 4.26 | needs validation | Re-check both hops and pin; remove the `.onion` exemption unless a Tor proxy is configured | Fixed: `ConnectGuard` for `lnurl`; `.onion` exemption removed (`includes/mint_helpers.php`; test `network_guard_2026_10.php`) |
| D4 | The webhook pin is keyed on the raw `parse_url` host, which can differ from libcurl's decoded/IDN host | OPUS N6 | needs validation | Refuse non-LDH/non-canonical hosts; add the SIIT prefix | Fixed: `includes/security.php` `resolveTarget` |
| D5 | The WordPress internal cron key is sent to a request-derived `site_url()` | OPUS N4, MINIMAX (NV) | needs validation | Send it only when `site_url()` matches the pinned `base_url` (host and port) | Fixed: `includes/background.php` (test `network_guard_2026_10.php`) |
| D6 | The webhook `allow_private_targets` switch is a single global flag | OPUS (hardening) | low | — | Accepted: operator-only, off by default |

## 5. API, webhooks, data and availability

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| E1 | No request-body size cap; an uncapped `checkout` object is stored; JSON/CBOR integer collisions cost quadratic CPU | OPUS N8, DS41 N-2, MINIMAX 4.22 | needs validation | Cap the body at the API/receive boundary; bound CBOR; cap the stored checkout | Fixed: 64 KiB body, 4 KiB checkout, CBOR limits (`api.php`, `receive.php`, library) |
| E2 | Webhook deliveries keep going to a deleted/repointed URL, signed with the old secret, for up to 80 attempts | DS41 L-9 | low | Cancel queued deliveries on delete/URL/secret/enabled change | Fixed: `includes/webhook_sender.php` |
| E3 | The webhook outbox can deliver a stale `InvoiceExpired`/`InvoiceProcessing` after `InvoiceSettled` | OPUS N7, DS41 Obs 3 | needs validation | Keep per-invoice ordering | Fixed: `claimNextDelivery` per-invoice order; WooCommerce's own ordering is outside this repo |
| E4 | Unauthenticated `GET /admin.php` returns the whole SPA shell with the absolute data-directory path | DS41 L-8, MINIMAX 4.19 | low | Render a placeholder; serve the path from an authenticated endpoint | Fixed: `admin.php` `?api=data_folder` (test `admin_shell_2026_10.php`) |
| E5 | The expiry sweep loads the entire backlog into memory; the fatal kills every background run | MINIMAX 4.8 | medium | Bounded batches; release the cron lease on fatal | Fixed: `includes/invoice.php`, `includes/background_runner.php`; test `invoice_expiry_2026_10.php` |
| E6 | `recover_orphaned` loads and re-polls every `Processing` invoice with no bound | MINIMAX 4.10 | medium | `LIMIT`, per-invoice cooldown, `Throwable` per row | Fixed: `includes/invoice.php` |
| E7 | Session files are never garbage-collected when `session.gc_probability` is 0 | OPUS N3 | needs validation | A background cleanup task | Fixed: `cleanup_sessions` (test `housekeeping_2026_10.php`) |
| E8 | The cron lease can stay held after a fatal, blocking later ticks | DS41 N-3, MINIMAX 4.8/4.10 | reliability | Release it from a shutdown hook | Fixed: `BackgroundRunner::run` |
| E9 | An interrupted backup download leaves a complete unencrypted database copy in the data folder | MINIMAX 4.2 | medium | Delete on shutdown, sweep leftovers, probe for them | Fixed: `admin.php`, `includes/background_runner.php`, exposure probes (test `minimax_2026_10.php`) |
| E10 | The raw exception text from `receive.php` is echoed to the public | DS41 (hardening) | low | Return a generic message | Fixed: `receive.php` `receivePublicMessage` |
| E11 | `payment.php` has no `Cache-Control: no-store` | DS41 (hardening) | low | Send it | Fixed: `payment.php` |
| E12 | The Greenfield API serves responses that vary only by `Authorization` with no `Cache-Control`/`Vary` | MINIMAX (NV) | — | Add `Vary: Authorization` / no-store | Fixed: `api.php` sends `Cache-Control: no-store` and `Vary: Authorization` |
| E13 | The setup data-exposure probe maps the data folder as a filesystem path minus `DOCUMENT_ROOT`, so on nginx `alias`, Apache `Alias` or `~userdir` layouts it probes the wrong URL or says "outside" while the real URL serves the database; the dashboard re-check has the same mapping | DS41 N-1 | medium (deployment-dependent) | Probe the URL-space path as well; treat 3xx/5xx as inconclusive; a file is protected only if every candidate URL refuses it | Fixed: `setup.php`, `admin.php` (`setup_probe_2026_10.php`, `admin_shell_2026_10.php`) |

## 6. WordPress

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| F1 | Sibling WordPress installs share `dirname(ABSPATH)/cashupay-data`, so one admin reads another's seed | OPUS C5, MINIMAX 4.16 | medium | One folder per install, with an `.owner` marker; adopt a legacy folder only when it names this site | Fixed: `wordpress/bootstrap.php` (test `wordpress_data_dir_2026_10.php`) |
| F2 | Multisite: every subsite admin controls one shared wallet | OPUS C6, MINIMAX 4.17 | medium | Per-site wallets and a super-admin gate | Fixed: `wordpress/bootstrap.php`, `includes/auth.php` (tests `wordpress_data_dir_2026_10.php`, `auth_session_2026_10.php`) |
| F3 | Re-configuring the WooCommerce gateway leaves the previous API key valid | MINIMAX 4.15 | medium | Revoke the previous key and any orphaned attempt key; keep one live key | Fixed: `wordpress/btcpay-integration.php`, `setup.php` |
| F4 | Deleting the plugin leaves its API key and webhook signing secret in `wp_options` | MINIMAX 4.28 | low | Revoke what the local database recognises, then delete the options; per site | Fixed: `wordpress/uninstall.php` |
| F5 | The plugin's `Update URI` names a channel no build/release step produces | MINIMAX (NV) | — | Point at the reviewed GitHub releases, or add a signed package check | Fixed: header now points at `github.com/jooray/cashupayserver/releases` |

## 7. Release, update channel and supply chain

| # | Finding | Found by | Severity | Recommended fix | Status in 0.5.7 |
|---|---|---|---|---|---|
| G1 | The release artifact is built from the working tree, not the tagged commit; the checksum attests those unbound bytes | MINIMAX 4.13 | medium | Refuse a dirty tree/submodule; require `CASHUPAY_RELEASE_TAG` to match `HEAD`; record the commit/tag with the checksum | Fixed: both build scripts + `RELEASE.md` |
| G2 | The update manifest signs only `safe_mode`; a compromised manifest host can fake the update prompt/notes and `min_supported` | DS41 L-5, OPUS (hardening) | low | Sign the whole manifest (release-tooling work) | Mitigated: links are restricted to this project's GitHub releases. Residual: version/notes/`min_supported` remain unsigned |
| G3 | The release manifest may be fetched over plain HTTP | MINIMAX (NV) | — | Require HTTPS; allow loopback only for tests | Fixed: `includes/update_check.php` + dashboard warning |
| G4 | The kill-switch publisher republishes an unauthenticated fetched manifest verbatim | MINIMAX (NV) | — | HTTPS only, no redirects, validate fields, refuse a missing live file | Fixed: `scripts/emergency-shutdown.php`, `docs/EMERGENCY-SHUTDOWN.md` |
| G5 | The upgrade chain has no integrity root independent of the publisher | MINIMAX (NV) | — | Publish a signed digest/attestation | Residual: the manifest is still unsigned; release notes carry the digest and commit |
| G6 | The container build context carries `data/` and local configuration; no `.dockerignore` | MINIMAX (NV) | — | Exclude local state/secrets from the context | Fixed: `.dockerignore` |
| G7 | A published release artifact is not bound to a reviewed tag in CI | MINIMAX (NV) | — | Add CI attestation/branch protection | Residual: process decision, documented in `RELEASE.md` |

## 8. Not vulnerabilities / accepted hardening

| Item | Found by | Verdict |
|---|---|---|
| Setup can finish without an admin password | DS41 L-6 | Real robustness bug, not a boundary crossing (only the claim holder can cause it). Fixed anyway. |
| Pairing allows a loopback redirect | DS41 L-7 | Not a vulnerability: the key goes to the operator's browser; local WooCommerce development needs localhost. |
| Cron lease numbers (200 s work vs 360 s lease) | DS41 N-3 | Reliability only; the lease now also releases on fatal. |
| `Cache-Control` on `admin.php` | DS41 hardening | False: PHP's session `nocache` limiter sends `no-store`. True for `payment.php`, now fixed. |
| Dashboard `/export_info` GET | DS41 hardening | True but idempotent/throttled; only the operator's own mint is contacted. |
| `webhook_allow_private_targets` | DS41 hardening | True; one global switch, off by default. |
| Admin array-valued parameters → TypeError 500 | MINIMAX hardening | Authenticated operator only; not fixed (one request, no state change). |
| `auto_melt_threshold` unchecked | MINIMAX hardening | Fixed: a threshold below 1 or enabling without an address is refused. |
| `isSafeAppBaseUrl` allows control characters | MINIMAX hardening | Fixed: control characters are rejected. |
| GitHub Actions pinned by mutable tag, no `permissions:` | MINIMAX hardening | Residual: CI does not touch release artifacts today. |
| `build/SHA256SUMS` is git-ignored and accumulates per build | MINIMAX hardening | By design; the release notes now carry the digest and `SHA256SUMS.commit` ties it to a commit/tag. |
| WordPress plugin update path has no package-hash check | MINIMAX hardening | Residual: needs a signed update channel; the header now names the reviewed releases. |

## 9. Verification performed for 0.5.7

- `php tests/run.php` → **45 passed, 0 failed** (includes the wallet library's PHPUnit
  suite, 471 tests / 3,873 assertions).
- New regression tests: `tests/minimax_2026_10.php`, plus additions to
  `tests/auth_session_2026_10.php`, `tests/network_guard_2026_10.php`,
  `tests/integration_keyset_v2.php`, `tests/conversion.php` and the existing
  2026-10 suites.
- `php -l` on every changed PHP file.
- Each finding was re-checked against the **working tree**, not the audit's quoted line
  numbers; the two audits that ran against the clean commit were re-verified item by item
  after the first fixes landed (see `docs/audits/RECONCILIATION-2026-10.md`).
- Manual checks: the WordPress re-auth path, the proxy width/read-time filter, the global
  sign-in budget, the stranded-export reservation, the expired-quote delay, the batched
  expiry sweep, the emergency publisher's HTTPS/field checks, and the uninstall key
  revocation.
- Not testable from this machine: the two curl hostname findings (C9/N6) on the
  production PHP 8.3/libcurl 8.12 combination; local runs used PHP 8.5/libcurl 8.23.

## 10. Residual risk after 0.5.7

- USD pricing requires two providers to agree at least once a day; if two of the three
  sources are down for over a day, fiat checkout pauses with a plain message.
- A brand-new install trusts the first exchange rate for a currency only one provider
  quotes.
- The update manifest's version, notes and `min_supported` remain unsigned (its
  `safe_mode` notice is signed and verified against pinned keys).
- A WordPress user who signs in through SSO or an application password has no password to
  re-enter, so they cannot export the seed or download a backup; a normal administrator
  account can.
- Auto-withdrawal sizes the payout from the price provider; the mint's melt quote sets
  the actual conversion cost. A wrong provider rate changes how much is converted, not
  the rate received.
- The global sign-in budget can be spent by anyone, pausing password sign-in for up to
  five minutes. This is the standard trade-off for a global limit.
- Tokens exported as "stranded funds" before this release are still `UNSPENT` until the
  next export; that export reserves them.
- The WordPress uninstaller opens each site's SQLite database read-write to revoke
  credentials; on a WAL database this can create sidecars in a folder that belongs to
  another site. It does not write outside the database it was pointed at.
