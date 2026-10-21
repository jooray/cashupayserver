# Reconciliation of the 2026-10-20/21 audits (OPUS55 + DS41-Flash-1017 + MINIMAX-M31)

Three independent audits were run against `4a82d01` (v0.5.6-alpha):

- `docs/audits/SECURITY-OPUS55.md`, called "OPUS" below. Every claim in it was reproduced locally and checked by two independent verifiers.
- `docs/audits/SECURITY-DS41flash1017.md`, called "DS41". It is a static review only.
- `docs/audits/SECURITY-MINIMAXM31.md`, called "MINIMAX" below. It arrived after the first two were reconciled and was re-checked against the same working tree; see the section at the end.

For this document, every DS41 claim was re-verified by seven independent verifiers. Each one tried to refute the claim from source and a bounded sandboxed reproduction (no network, dummy data). The evidence is in `~/security-audit-skill/cashupayserver/run-3/agents/x-*/artifacts/evidence/`. OPUS claims were not re-run. They had already been reproduced twice at the same commit, and no code had changed since.

Verdict meanings:

- **confirmed**: the boundary crossing was reproduced.
- **partial**: the claim is true, but narrower or broader than stated.
- **NV**: needs validation. The decision depends on a deployment fact.
- **not a vuln**: a real behaviour with no lower-trust principal involved.
- **refuted**: the claim is false.

## Security findings

| # | OPUS | DS41 | Reconciled verdict | Severity | Notes |
|---|---|---|---|---|---|
| 1 | C4 | H-1 | confirmed | **medium** | `setup.php?mode=add_store&step=2` resets the admin password. DS41 said high. Both cross-verifiers rate it medium: the attacker already needs a live admin session, and `export_token` already gives that session the current balance. The new gain is persistence and the seed (future funds). |
| 2 | C3 | M-1 | confirmed | medium | A spent or donated token can be replayed to mark NUT-18 payment requests paid. |
| 3 | — | **M-2** | confirmed (new to OPUS) | medium | The `base_url` that the first claimant pins at setup step 1 survives an idle-release or recovery-code takeover. It then drives the checkout links, the pairing and cron URLs shown in the wizard, and `X-Cron-Key`. If the attacker adds a `//host` path, `Urls::admin()` also becomes protocol-relative (an open redirect). DS41 overstated one point: with a plain hostile origin, "Go to admin" stays path-only. |
| 4 | — | **M-3** | confirmed (new to OPUS) | medium | During first setup, an unfinished store left by a previous claimant (with that claimant's mint) is adopted by name. The default name "My Store" makes a collision likely, and the seed step never shows the mint. |
| 5 | C2 | — | confirmed | medium | A single price provider can ratchet the rate step by step and underprice fiat invoices. |
| 6 | C1 | — | confirmed | medium | Public `receive.php` runs about 13 s of CPU per request (1000 copies of one spent DLEQ proof). |
| 7 | C5 | — | confirmed | medium | WordPress sibling installs share `dirname(ABSPATH)/cashupay-data`. |
| 8 | C6 | — | confirmed | medium | WordPress multisite: every subsite admin controls one shared wallet. |
| 9 | — | **N-1** | partial: source defect confirmed | medium (deployment-dependent) | The setup data-exposure probe calls a directory "protected" when all three files answer 404, 3xx or 5xx. It maps the URL as filesystem path minus `DOCUMENT_ROOT`. In nginx `alias`, Apache `Alias` and `~userdir` layouts that probes the wrong URL, or reports "outside", while the real URL serves `cashupay.sqlite`. The dashboard re-check has the same mapping. The underlying "data dir is in the webroot by default" point is a known carry-forward. |
| 10 | N5 | L-11 | confirmed: DS41 is broader | low | In the default `auto` mode, any loopback, RFC1918 or CGNAT (100.64/10) peer is trusted as a proxy. A client connecting directly from the LAN or a VPN therefore chooses its own lockout and rate-limit identity with `X-Forwarded-For`, `Forwarded` or `X-Real-IP` (20 of 20 attempts reserved instead of 5). Lockout buckets are not aggregated per IPv6 /64, which also affects public IPv6 clients. OPUS N5 (a proxy that sets only `X-Real-IP`) is a sub-case. Operators are never told. |
| 11 | C9 | — | confirmed | low | A numeric-IPv4 Host header bypasses the setup self-probe's `CONNECT_TO` pin (blind SSRF/port oracle before setup). |
| 12 | C7 | L-3 | confirmed | low | `test_mint` skips `assertSafeMintUrl`. |
| 13 | C8 | — | confirmed | low | The pairing login redirects to the raw `REQUEST_URI` (`//host`) under router.php. |
| 14 | — | **L-1** | partial | low | `canviewstoresettings` implies `canviewinvoices` (a deliberate BTCPay copy, with a test). The pairing label "View store settings" hides that the key can read every invoice and its buyer metadata. Reproduced. |
| 15 | — | **L-8** | confirmed | low | Unauthenticated `GET /admin.php` returns the whole SPA shell, including the absolute data-directory path (`admin.php:3812`). |
| 16 | — | **L-9** | confirmed | low | Deleting or repointing a webhook (DELETE, or PUT with a new URL or secret) does not cancel queued deliveries. They still go to the old URL, signed with the old secret, for up to 80 attempts. |
| 17 | — | **L-10** | partial | low | Confirmed: there is no idle timeout (only a 24 h absolute one), `logout` does not clear the cookie, and `use_strict_mode` is off. Refuted: that the session ID is still usable after logout (`session_destroy` removes it), and fixation at login (`session_regenerate_id(true)` runs at login). |
| 18 | (hardening) | **N-4** | NV: mechanism reproduced | low | The setup claim does not regenerate the session ID, and strict mode is off. A planted `cashupay_session` cookie (sibling-subdomain cookie tossing, or HTTP injection without HSTS) gets the victim's claim. Reproduced with a fixed session ID. |
| 19 | (hardening) | **L-5** | partial | low | The update manifest signs only `safe_mode`. A compromised manifest host can fake the "Security update available" text and notes, or set `min_supported`. Links are narrower than DS41 says: the dashboard links only to GitHub, and only the Settings footer may link to a path on the manifest host. |
| 20 | — | Obs 1 | confirmed (correctness, not a boundary) | low | A `New` invoice is expired locally without asking the mint, so a payment made just before expiry gives `InvoiceExpired` followed by `InvoiceSettled`. Reproduced on all three expiry paths. |
| 21 | — | Obs 2 | **mostly refuted** | low (residual gaps) | DS41 says the quote is not checked against the request. `assertQuoteMatchesRequest` does reject a wrong amount, a wrong unit and a mismatched sat BOLT11. The real remaining gaps are an absent or zero `amount`, an absent `unit`, an amountless or `lightning:`-prefixed BOLT11, and no BOLT11 check for non-sat units. The carry-forward row "library quote validation still absent" is refuted. |
| 22 | (hardening) | Obs 4 | partial | low | The deliberate NUT-18 fee leniency is larger than "a few units". The payer chooses the proof count (up to 1000), so a request can be paid up to `fee_ppk/1000` short (10–14 % at 100 ppk in tests). |
| 23 | N1 | — | NV | — | The LNURL-pay callback has no address policy. |
| 24 | N2 | L-4 | NV: source defect agreed | — (DS41: low) | Mint connections are never pinned to the vetted addresses (DNS rebinding). Both audits agree on the code. OPUS keeps it NV because practical reach needs an `http://` mint or an internal TLS endpoint. |
| 25 | N6 | — | NV | — | The webhook pin is keyed on the raw `parse_url` host, which differs from libcurl's decoded or IDN host. |
| 26 | N4 | — | NV | — | WordPress: the internal cron key is sent to `site_url()`, which some wp-config setups derive from the Host header. |
| 27 | N8 | N-2 | NV: same root cause | — | Request bodies have no size cap, and colliding JSON/CBOR integer keys cost quadratic CPU. DS41's memory angle was confirmed separately: `post_max_size` does not cap `php://input` (an 8 MB body was decoded, 72 MB peak). |
| 28 | N7 | Obs 3 | NV: correctness | — | The webhook outbox can deliver a stale `InvoiceExpired` or `InvoiceProcessing` after `InvoiceSettled`. DS41's "WooCommerce does not guard ordering" cannot be checked from this repository. |
| 29 | N3 | — | NV | — | Session files are never garbage-collected on hosts where `session.gc_probability` is 0. |

## Not vulnerabilities (behaviour confirmed, no boundary crossed)

| DS41 | Verdict |
|---|---|
| **L-2** | Store read endpoints need no permission. True, but the only exposure is the key's own store id and name and a fixed payment-method entry, which the key holder already has. Informational. One detail is wrong: `handleGetStores` filters by the key's store and does not call `requireStore`. |
| **L-6** | Setup can finish without an admin password. Reproduced: it leaves an install nobody can log into, and even `CASHUPAY_SETUP_TOKEN` cannot recover it. Only the claim holder, who could set any password anyway, can cause it. This is a real robustness bug, not a security issue. |
| **L-7** | The pairing approval allows a loopback redirect. The key goes to the operator's own browser, not to a server-side request. Any public host is already allowed behind the same warning. Local WooCommerce development legitimately uses localhost. Not a vulnerability. |
| **N-3** | The numbers are correct (about 200 s of outbox work against a 360 s lease, and no `set_time_limit`). A killed run blocks for at most 360 s, after which the lease expires. Reliability only. |
| Hardening: Cache-Control | False for `admin.php`: PHP's session `nocache` limiter sends `no-store`. True for `payment.php`. |
| Hardening: dashboard / export_info GET | True. Idempotent or throttled, and the only outbound call goes to the operator's own mint. Negligible. |
| Hardening: `webhook_allow_private_targets` | True: one global switch, off by default. Hardening only. |
| Hardening: raw exception text in `receive.php` | True, and broader than DS41 says: any `Throwable` (PDO, TypeError) is echoed, not only mint text. |
| OPUS rejected: `remove_backup_mint` ignores open quotes | Rejected: operator-only, and only the operator's own view is affected. |

## Carry-forward table in DS41

| DS41 row | Re-verified |
|---|---|
| P1-3 paid-after-cancel fixed | correct |
| P2-6 update-link pinning fixed | correct (nit: `$` accepts a trailing newline) |
| "Library quote amount/unit validation still absent" | **refuted** (see #21) |
| P1-10 webhook ordering still present | correct (#28) |

## Remediation status (uncommitted working tree, 2026-10-21)

Every security finding (#1–#29) is fixed or mitigated in the working tree. So are L-6, the hardening items for `receive.php` error text and `payment.php` Cache-Control, and `expire_old_invoices`. An independent review of the full diff found seven regressions in the first pass, and all seven are fixed. `php tests/run.php` passes 44 of 44, including the library's PHPUnit suite. New regression tests are in `tests/*_2026_10.php` and `cashu-wallet-php/tests/unit/AuditRun3HardeningTest.php`.

| # | Fix (main file) |
|---|---|
| 1 C4/H-1 | `setup.php`: after setup, and in `add_store` mode, only steps 4–7 are allowed. Step 2 never overwrites an existing password. |
| L-6 | `setup.php`: no store steps run (and setup cannot complete) without an admin password. |
| 2 C3/M-1 | `includes/payment_request.php`: `receiptState` counts only proofs stored at or after the claim. A fresh claim on secrets the wallet already holds is released. |
| 3 M-2, 4 M-3, 18 N-4 | `includes/setup_ownership.php`: a newly assigned claim regenerates the session ID and clears `base_url`, `url_mode`, seedless stores and the wizard session. Adoption by name only for stores created in the current session. Step 1 asks for confirmation before pinning another host. |
| 5 C2 | `includes/rates.php`: a reference rate is set only when two providers agree, and a single provider is accepted only within 5 % of it. Currencies that only one provider quotes, or where one of two is down, are priced as confirmed BTC/USD × an exchange rate whose reference moves at most 2 % a day. |
| 6 C1 | Library: duplicate proofs are refused and the NUT-07 spent check runs before DLEQ. `receive.php`: at most 100 proofs per token. |
| 7 C5, 8 C6 | `wordpress/bootstrap.php`: a data folder per install and per blog, with an `.owner` marker. A legacy folder is adopted only when its stored address matches this site, and multisite upgrades keep the shop's existing wallet. Multisite admin access requires a super admin. Activation no longer overwrites another site's `base_url`. |
| 9 N-1 | `setup.php` and `admin.php`: the exposure probes also test the URL-space path. A file counts as protected only if every candidate URL refuses it, and 3xx/5xx results are inconclusive. |
| 10 L-11/N5 | `includes/security.php`: lockouts are grouped per IPv6 /64. CGNAT is no longer trusted as a proxy. Login attempts through one proxy with changing forwarded addresses are counted and logged. They are deliberately not refused, because that would let anyone lock out the operator behind a real reverse proxy. |
| 11 C9 | `setup.php`: the probe uses the wildcard `CONNECT_TO` and checks the address it actually reached. |
| 12 C7 | `includes/config.php`: `testMintConnection` enforces `assertSafeMintUrl` and returns generic errors. |
| 13 C8 | `api-keys/authorize.php` redirects to a root-relative path. `router.php` refuses `//` and `/\` paths. |
| 14 L-1 | Pairing label: "View store settings, and read all invoices …". |
| L-2 | Store read endpoints require `canviewstoresettings`. The WooCommerce and auto-config keys still work. |
| 15 L-8 | The data-folder path comes from an authenticated API. `admin.php` sends `Cache-Control: no-store`. |
| 16 L-9 | Webhook delete, or a change of URL, secret or enabled state, cancels queued deliveries. Redelivery skips deleted webhooks. |
| 17 L-10 | 60 min idle timeout, which the dashboard's background refresh does not extend. Strict session mode. Logout expires the cookie. |
| 19 L-5 | Update links point only to GitHub releases. The full manifest is still not signed, which would need release-tooling work. |
| 20 Obs 1 | An invoice expires only after the mint confirms it is unpaid, or one hour after expiry. `expire_old_invoices` sends `InvoiceExpired`. |
| 21 Obs 2 | Library: the quote must carry the amount, and the BOLT11 (with any `lightning:` prefix stripped) must have a matching amount for sat, or a non-zero amount for other units. |
| 22 Obs 4 | `receive.php`: the fee shortfall is limited to the fee of the minimal proof split. |
| 23 N1, 24 N2 | Library `ConnectGuard` and the app policy (`includes/mint_helpers.php`): mint and LNURL connections are pinned to vetted public addresses. Private mints are allowed only when configured. |
| 25 N6 | `resolveTarget` refuses non-LDH and non-canonical hosts, so the pin key equals libcurl's host. SIIT prefix added. |
| 26 N4 | WordPress self-cron sends the key only when `site_url()` matches the pinned `base_url` (host and port). |
| 27 N8/N-2 | `receive.php` and `api.php` cap the body before decoding. Library CBOR refuses non-string keys and more than 64 entries. |
| 28 N7 | `claimNextDelivery` keeps per-invoice order per webhook. |
| 29 N3 | A background task deletes `sess_*` files older than 25 h. |

## Third audit: MINIMAX-M31

MINIMAX was run against the clean `4a82d01` tree, so its findings were re-checked against
this working tree before the release. Eight were already closed by the fixes above:
`4.11`, `4.12`, `4.14`, `4.16`, `4.17`, `4.19`, `4.21` and `4.27`. Four were partly
mitigated: `4.4`, `4.6`, `4.22` and `4.26`. The other sixteen were still present. All 28
are now fixed or mitigated, with the residuals listed below. Two MINIMAX findings were
rated one step lower than it rated them after checking the actual impact: the WordPress
re-authentication gap (`4.1`) needs an already-hijacked administrator session, and the
auto-withdrawal rate (`4.5`) sizes a payout to the operator's own address, where the mint's
quote — not the price provider — sets the actual rate.

| MINIMAX | Status |
|---|---|
| 4.1 WordPress seed/backup prompt not checked | Fixed: `Auth::verifyAdminPassword` checks the signed-in user's WordPress password; the prompt says "WordPress password". |
| 4.2 Interrupted backup leaves a database copy | Fixed: shutdown hook, a two-hour sweep, and the exposure probes now look for `backup-*.sqlite`. |
| 4.3 Trusted proxies accept `0.0.0.0/0` | Fixed: ranges narrower than /8 (IPv4) or /32 (IPv6) are refused and ignored at read time; leaving "Only these proxies" clears the list. |
| 4.4 Public token costs ~10 s CPU | Fixed: 100-proof cap, duplicate refusal, NUT-07 before DLEQ, and DLEQ scalars must be exactly 64 hex characters (also closes N1). |
| 4.5 Auto-withdrawal rate unverified, no fee | Mitigated: the conversion fee is applied in the payout direction and the rate plus converted sats are recorded. The mint's melt quote remains the authority; a wrong provider rate changes how much is converted, not the rate received. |
| 4.6 Single provider ratchets the invoice rate | Fixed for the ratchet and poisoned reference (two-provider anchor). Residuals: ±5% single-provider authority for up to 24 h, and the first rate for a one-provider currency is trusted once. |
| 4.7 No address-independent sign-in budget | Fixed: 20 granted password attempts per five minutes across all addresses, cleared by a successful sign-in. |
| 4.8 Expiry sweep exhausts memory | Fixed: bounded batches, each in its own write transaction; a shutdown hook releases the cron lease after a fatal error. |
| 4.9 Stranded export hands out a second live token | Fixed: proofs are reserved as EXPORTED, a re-export shows the same token, the balance excludes it, and "mark recovered" writes off exactly that export. |
| 4.10 Orphan recovery unbounded | Fixed: `LIMIT`, a five-minute per-invoice cooldown, and `Throwable` per row. |
| 4.11 Mint check discards the resolved address | Already fixed by `ConnectGuard`; verified on every mint request. |
| 4.12 Payment request marked paid by replay | Already fixed by the claim-time receipt filter. |
| 4.13 Release built from the working tree | Fixed: both build scripts refuse a dirty tree or submodule, check `CASHUPAY_RELEASE_TAG`, and write `SHA256SUMS.commit`. |
| 4.14 `add_store` resets the admin password | Already fixed by the step clamp. |
| 4.15 Re-configuring WooCommerce leaves the old key valid | Fixed: the previous key is revoked, failed attempts delete the key they minted, and one live key remains. |
| 4.16 WordPress siblings share a wallet | Already fixed by the per-install data folder. |
| 4.17 Multisite subsite admin controls the wallet | Already fixed; only a super admin passes. |
| 4.18 Transfer check rewrites a reclaimed export | Fixed: terminal rows are immutable; the handler refuses a finished transfer. |
| 4.19 Anonymous page leaks the data path | Already fixed; the path is behind `?api=data_folder`. |
| 4.20 "Mark recovered" writes off a late payment | Fixed: the export id, not the newest list, decides what is written off. |
| 4.21 Pairing redirect to another origin | Already fixed by the router and the root-relative redirect. |
| 4.22 API body and checkout unbounded | Already fixed for the body (64 KiB); the stored `checkout` object is now capped at 4 KiB. |
| 4.23 Donation retries after donations are off | Fixed: pending donations are not re-posted when `CASHUPAY_DONATION_PERCENT` is zero. |
| 4.24 Seed replaceable when the namespace is empty | Fixed: a store's existing recovery phrase cannot be replaced, whatever the namespace state. |
| 4.25 Late payment recovery drops the quote too early | Fixed: the quote id is dropped only seven days after the mint quote expired, with a status-guarded update. |
| 4.26 LNURL/.onion not address-checked | Fixed: the `.onion` exemption is removed; both LNURL hops are resolved and pinned. |
| 4.27 `test_mint` skips the mint gate | Already fixed inside `Config::testMintConnection`. |
| 4.28 Uninstall leaves API key and webhook secret | Fixed: the uninstall routine resolves each site's database, revokes the key, disables the webhook, then removes the options. |
| N1 DLEQ `r` unbounded on BCMath | Fixed by the exact 64-hex check and a 130-character backstop in `BigInt::fromHex`. |
| N2 Manifest fetched over plain HTTP | Fixed: a non-HTTPS endpoint is refused (loopback excepted for tests) and the dashboard says why. |
| N3 Publisher republishes an unauthenticated manifest | Fixed: HTTPS only, no redirects, fields validated and limited to the ones installs read, and a failed live fetch aborts unless `--allow-missing-manifest` is given. |
| N4 Docker context carries local config and data | Fixed: added `.dockerignore`; `data/`, `*.local.php` and `data-location.txt` never enter the context. |
| N5 WordPress `Update URI` names an unbound channel | Fixed: the header points at this project's GitHub releases. |

Known residuals:

- USD prices need two providers to agree at least once a day. If two of the three USD sources are unavailable for over a day, pricing stops, with a plain message.
- A brand-new install trusts the first exchange rate for a currency that only one provider quotes.
- The update manifest's version and notes fields are still unsigned.
- A WordPress user who signs in through SSO or an application password has no WordPress password to re-enter, so they cannot show the seed phrase or download a backup. A normal administrator account can.
- Auto-withdrawal still sizes the payout from the price provider; the mint's melt quote is the authority on what the conversion actually costs. A wrong provider rate changes how much of the balance is converted, not the rate received.
- The address-independent sign-in budget can be spent by anyone, pausing password sign-in for everyone for up to five minutes. That is the standard trade-off for a global limit and is why it is small.
- Tokens exported as "stranded funds" before this release are still marked UNSPENT until the next export; that export reserves them, so no new second live token is created.
- Both release scripts refuse to build when the tree has any untracked file (including local scratch files). `CASHUPAY_ALLOW_DIRTY_BUILD=1` is for throwaway builds only.
