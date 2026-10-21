# CashuPayServer security audit (Opus 5.5) — 2026-10-20

## 1. Run summary

| | |
|---|---|
| Target | `cashupayserver` at `4a82d01` (v0.5.6-alpha + 2 commits). Clean worktree. Submodules `cashu-wallet-php` `7751f34` and `mint-discovery` `b1b5c07`. |
| Profile | `standard`: full repository scope, no budget cap. 3 hunter waves, a coverage critic after every wave plus a separate final-clean critic, and two independent verification passes (candidate validation, then a re-check of each final record). |
| Agents spent | 64 in total: 4 reconnaissance, 20 hunters, 4 coverage critics, 18 candidate verifiers, 18 final-record verifiers. |
| Execution boundary | Source review plus bounded local checks only. All target code ran under macOS `sandbox-exec` with no external network (loopback only where a check needed it), writes limited to per-agent scratch directories, an empty allowlisted environment, CPU/file/process limits and wall-clock timeouts. Only dummy data was used. Nothing was sent to the live site, the demo instance, real mints, LNURL servers, rate providers or the update manifest. Evidence files were copied out of scratch by a no-follow, size-bounded parent-side copier. |
| Prior runs | No machine-readable prior ledger existed, so every coverage unit is new work. The prose audits (`AUDIT.md`, 2026-09-23) and the 2026-10 audit cycle fixed in `6debd19` (v0.5.6) were used as background only. None of their findings were carried forward. Every finding below was found and reproduced fresh against `4a82d01`. |
| Out of scope / deferred | Nothing deferred. The `mint-discovery` JS library was reviewed only as rendered into setup and admin pages. Deployment configuration (nginx, PHP-FPM, wp-config, proxies) is not in the repository; where it decides the outcome, the item is filed under *Needs validation*. |
| Run note | A second audit agent working at the same time used the same default output directory (`run-1`) and overwrote three shared run files mid-run. This run moved to `run-3` and rebuilt its state from its own saved results. All of this run's evidence is under `~/security-audit-skill/cashupayserver/run-3/`. |

## 2. Security posture

The codebase is mature in the places that usually go wrong:

- Greenfield store binding and permissions, pairing allow-lists, CSRF, SQL parameterisation and output escaping all held under active testing.
- The signed kill switch, the cron and internal keys, and mint-response validation in the wallet library (DLEQ, keyset IDs, quote binding, exactly-once minting under concurrency) also held.

What remains is a set of **logic and boundary gaps**, not missing basics:

- **Public `receive.php` endpoint.** Two of the three most practical confirmed issues are here. An already-redeemed token can mark NUT-18 payment requests as paid (C3). A cheap request can burn about 12 s of CPU (C1).
- **WordPress data directory.** The data directory is not unique per site, which merges wallets across sibling installs (C5) and across multisite subsites (C6).
- **Admin password re-check.** The `add_store` re-entry into the setup wizard bypasses the fresh-password gate that protects the seed (C4).
- **Pricing.** One misbehaving rate provider can walk fiat prices down step by step (C2).
- **SSRF pins.** Several SSRF pins assume the host string PHP validates is the same host cURL connects to. One case is confirmed (C9), others need validation (N2, N6), and two outbound paths have no address policy at all (C7, N1).

No critical or high finding was confirmed. Nothing gives an anonymous attacker the seed, the funds or admin access.

## 3. Confirmed findings

| ID | Severity | Title | Boundary crossed | Observed result (local, dummy data) |
|---|---|---|---|---|
| C3 | medium | Already-redeemed ecash token marks any number of NUT-18 payment requests as paid | anonymous payer → payment-request paid state | Reposting a token that was already received turned a new 30-sat request and a 1000-sat request into "paid" while the store balance stayed unchanged |
| C4 | medium | `setup.php?mode=add_store` step 2 resets the admin password with no current password and no session revocation | admin-session holder → admin credential and seed/backup re-check | A copied session set a new password, locked out the old one, then got 200 on `reveal_seed`. The other sessions stayed valid |
| C2 | medium | Rate sanity band can be ratcheted by a single provider | primary price provider → invoice pricing | 10 in-band steps of ×1.45 were accepted with 0 cross-checks. A 100 EUR invoice fell from 166 667 to 4 057 sats |
| C1 | medium | `receive.php` verifies DLEQ for up to 1000 duplicate proofs before the spent check | anonymous internet → shared PHP workers | A 217 KB token made of one spent 2-sat proof repeated took 13.8 s of CPU per request, then got "already spent" |
| C5 | medium | WordPress sibling installs share `dirname(ABSPATH)/cashupay-data` | admin of site B → wallet of site A | Site B opened site A's DB, read its seed, passed `verifyAdminPassword('')`, and overwrote `base_url` |
| C6 | medium | WordPress multisite: one wallet for every subsite, gated by per-blog `manage_options` | subsite admin → network/main-site wallet | Blogs 1, 2 and 3 resolved to the same data directory, and every subsite admin passed `Auth::isLoggedIn()` |
| C9 | low | Setup self-probe pin bypassed with a numeric-IPv4 `Host` header | anonymous visitor (pre-setup) → server-side request to a chosen IPv4:port | `Host: 2130706433:18092` sent three ranged GETs to the unpinned port. The badge returns a reachability oracle |
| C7 | low | Admin `test_mint` skips `assertSafeMintUrl` | operator → server network policy | Returned the JSON of an internal service on 127.0.0.1, and the raw cURL error for closed ports |
| C8 | low | Pairing-page login redirects to the raw `REQUEST_URI` | anonymous link sender → operator browser (router.php deployments) | After a real login: `Location: //evil.example/api-keys/authorize?...` |

Severity notes: none of these is anonymous fund theft. The medium findings each fully defeat one explicit control (payment-request binding, the fresh-password gate, the rate band, per-site wallet isolation), or let an anonymous caller tie up workers. C5 and C6 are rated medium rather than high because they need a specific hosting layout and a site admin who is not trusted with the other site.

### C3 — Already-redeemed ecash token marks NUT-18 payment requests paid (medium)

- **Where:**
  - `includes/payment_request.php:144-172` (`receiptState`)
  - `receive.php:270`, `:290-293` and `:353-378`
  - `includes/payment_request.php:196-205` (`reconcileReceiving`)
- **Who:** an anonymous payer who has once paid or donated a token to the store.
- **Reproduction:**
  1. Donate a token D.
  2. Create request X (30 sat) and POST D for it twice. The first POST returns 400 "Token already spent." and the second returns 200, after which X is `paid`.
  3. Create request Y (1000 sat) and POST D once. It fails the amount check. The background `reconcileReceiving` then marks Y `paid` anyway.
  4. The store's balance never changes.
- **Why:** `receiptState` treats "all secrets are SPENT in our wallet" as proof that *this* claim's swap succeeded. Secrets from any earlier receive satisfy that test.
- **Impact:** the request page shows "Paid ✓", so point-of-sale use of payment requests can be tricked into releasing goods. Greenfield/Lightning invoices and existing funds are not affected, and no webhook fires.
- **Fix:** only count proof rows created at or after the claim's `receiving_since`. `storeProofs` uses `ON CONFLICT DO NOTHING`, so a row that already existed keeps its old `created_at`. Keep the existing "stay bound after a real swap" behaviour. Do not add a pre-claim "known secret" check, because it would break replay of a lost response. Add regression tests to `tests/payment_request_binding.php` for reusing a donated token and a token from another request.

### C4 — `add_store` wizard re-entry resets the admin password (medium)

- **Where:** `setup.php:39-46` (gate), `setup.php:100` (step allow-list), `setup.php:217-228` (step 2 → `Auth::setAdminPassword`).
- **Who:** anyone holding an admin session (copied cookie, unattended browser, same-origin script) without knowing the password.
- **Reproduction:**
  1. `GET setup.php?mode=add_store&step=2` to get the CSRF token.
  2. `POST step=2&mode=add_store&password=X&confirm_password=X` returns 200.
  3. The old password now fails at login with 401.
  4. `reveal_seed` with password X returns the dummy seed.
  5. `admin_password_version` is unchanged, so every session survives.
- **Impact:** this defeats the fresh-password re-check that protects `reveal_seed` and `download_backup` (the seeds and therefore the funds). It also locks the operator out.
- **Fix:**
  1. When `mode=add_store` or setup is complete, clamp the step to 4–7.
  2. Make step 2 refuse to overwrite an existing `admin_password_hash`.
  3. Regression test: step 2 in `add_store` mode leaves both the hash and the version unchanged.

### C2 — Rate band ratchets on single-source answers (medium)

- **Where:** `includes/rates.php:246-305` (`getBtcPrice`, `withinBand`, `lastKnownRate`), with `CACHE_TTL` of 300 s.
- **Who:** whoever controls the answers of the store's primary price provider (default CoinGecko). That means a compromised or faulty provider; TLS to the fixed hosts is verified.
- **Reproduction:**
  1. Stub the providers in-process and seed `rate_eur` with 60 000.
  2. Have the primary return the previous accepted rate × 1.45 on each of ten refreshes.
  3. All ten answers are accepted and the honest providers are consulted 0 times. A single ×10 jump is rejected, as intended.
  4. A 100 EUR invoice falls to 4 057 sats.
- **Impact:** fiat invoices can be underpriced without a floor, and the shop marks them paid in full.
- **Fix:**
  1. Keep a reference rate confirmed by at least two providers, separate from the cache.
  2. Accept a single-provider answer only within a narrow band of that reference; otherwise require two providers to agree.
  3. Never accept a lone answer when no reference exists.
  4. Regression test: a hostile primary that repeats ×1.45.

### C1 — DLEQ work before the spent check on public `receive.php` (medium)

- **Where:**
  - `cashu-wallet-php/CashuWallet.php:8072-8079`: the DLEQ loop runs before the NUT-07 check at `:8094-8105`.
  - `:5163`: duplicate inputs are rejected only here, at swap time.
  - `:3403`: `MAX_PROOFS` is 1000.
  - `receive.php:128`: 20 requests/min per IP.
- **Who:** an anonymous client holding one proof signed by the store's mint (even a spent one).
- **Reproduction:**
  1. Run a loopback mock mint.
  2. Repeat one spent 2-sat proof that carries a DLEQ, with an 8-hex secret, 1000 times in one token (217 444 bytes).
  3. `Wallet::receive` takes 13.8 s and only then throws "Token already spent". Cost grows linearly, about 13 ms per proof.
- **Impact:** a few IPs can saturate the PHP-FPM pool that also serves checkout. No funds or data are affected.
- **Fix:**
  1. Reject duplicate secrets/Ys right after deserialising.
  2. Run the NUT-07 state check before the DLEQ check.
  3. Cap proofs on the public path, for example at 100.

### C5 — WordPress sibling installs share one wallet (medium)

- **Where:** `wordpress/bootstrap.php:22-28`, which reuses any existing `dirname(ABSPATH)/cashupay-data`. The gate (`includes/auth.php:59-61`, `:131-137`) is `manage_options` on the current site.
- **Who:** an administrator of a WordPress site that sits in the same parent directory as a CashuPay shop. Typical layouts are cPanel addon domains and Plesk vhosts.
- **Reproduction:** with the real `bootstrap.php`, `activation.php`, `Database`, `Config` and `Auth` (WordPress API stubbed), site B resolves to site A's directory. Site B then sees `setupComplete=true` and `verifyAdminPassword('')=true`, reads `DUMMY-SEED-A`, and `base_url` is overwritten.
- **Impact:** a seed, withdrawals and token export become available across an installation boundary. Even when one person owns both sites, the ledgers are silently merged and site A's payment and webhook URLs break. The gain is real where file edits are disabled or where site-admin rights are delegated to someone else.
- **Fix:**
  1. Use a data directory specific to each install, for example keyed on a hash of ABSPATH.
  2. Write an `.owner` marker and adopt a legacy directory only when the marker matches. Handle the upgrade path by matching the stored `base_url`.
  3. Stop activation from overwriting a `base_url` that belongs to another site.

### C6 — WordPress multisite: subsite admins control one network-wide wallet (medium)

- **Where:** `wordpress/bootstrap.php:22-28` has no blog component, and nothing detects multisite or network activation (`wordpress/cashupay.php:27`). WordPress setup never sets a password (`setup.php:214`), so `verifyAdminPassword` falls back to `manage_options`.
- **Who:** an administrator of any subsite where CashuPay is loaded. This is a separate root cause from C5: fixing the sibling case by keying on ABSPATH does not fix this one.
- **Reproduction:** blog IDs 1, 2 and 3 resolve to the same data directory, both with the default path and with a wp-config constant, and `isLoggedIn()` is true for each subsite admin.
- **Impact:** a subsite admin can reveal the seed, download the backup, and melt or export the funds of another site, usually the main shop.
- **Fix:**
  1. Short term: on multisite, require `is_super_admin()` for the admin and setup routes and for the password fallback, and refuse network activation.
  2. Long term: give each blog its own data directory (`site-<id>`), keeping blog 1 on the legacy path.

### C9 — Setup self-probe pin bypass with a numeric-IPv4 Host (low)

- **Where:** `setup.php:524-563`. The `CURLOPT_CONNECT_TO` entry is keyed on the raw Host string (`:537`). libcurl canonicalises `2130706433`, `127.1` and `0x7f.1` before matching, so the entry never matches. There is no `CURLINFO_PRIMARY_IP` check afterwards.
- **Who:** an anonymous visitor to an unclaimed install whose data directory is inside the webroot, behind a vhost that accepts any Host value.
- **Observed:** the control `Host: 127.0.0.1:18092` stayed pinned. `Host: 2130706433:18092` sent three ranged GETs to the other port, and the badge showed OK.
- **Impact:** a blind GET with a fixed path, giving a three-way reachability oracle per IPv4:port. Rate-limited to 10 per minute.
- **Fix:** use the wildcard pin `'::ADDR:PORT'` and compare `CURLINFO_PRIMARY_IP`/`PORT` after each request. Optionally reject numeric Host values that are not dotted-quad.

### C7 — `test_mint` admin action skips the private-address policy (low)

- **Where:** `admin.php:2503-2510` calls `Config::testMintConnection` (`includes/config.php:608-621`), which only checks `sanitizeUrl`. Every other mint entry path calls `MintHelpers::assertSafeMintUrl` first.
- **Who:** an admin session (no UI calls the action).
- **Observed:** `assertSafeMintUrl` refused `http://127.0.0.1:18090`, but `testMintConnection` fetched `/v1/info` from it and returned the listener's JSON. A closed port returned the raw cURL error.
- **Fix:** call `assertSafeMintUrl` inside `testMintConnection` and return a generic error message.

### C8 — Pairing login redirect to `//host` (low)

- **Where:** `api-keys/authorize.php:140` (`Location: $_SERVER['REQUEST_URI']`) and `router.php:55`, which routes on `parse_url` and reads `//evil.example/...` as a host.
- **Who:** an unauthenticated sender of a crafted pairing link. Only router.php-style deployments are affected. The shipped `.htaccess` is anchored, and nginx was not tested.
- **Observed:** after a correct login the response was `302 Location: //evil.example/api-keys/authorize?...`. The normal-path control redirected to the same path.
- **Fix:** build a root-relative target from the parsed path with leading `/` and `\` stripped and exactly one `/` re-added. Do not use `Urls::pairing()`, which depends on `base_url`/Host and on `url_mode` and breaks the router deployment.

Full traces, inputs and outputs for C1–C9 are in Appendix A.

## 4. Needs validation (no severity, not confirmed)

| ID | Lead | Repository trace | Exact blocker | Bounded local next step | Safe owner check |
|---|---|---|---|---|---|
| N8 | Colliding integer map keys make `json_decode`/CBOR quadratic. Public `receive.php` is affected; `api.php` also is, but only after authentication | `receive.php:136-137`; `CashuWallet.php:3203-3212`; `api.php:170-177` | Deployed body-size cap and FPM pool size. A 1 MB body costs about 2.3–2.8 s and grows with the square of the size | Time colliding vs sequential keys at 16k/32k/64k entries; regression test that a body above the cap gets 413 before decode | Read `client_max_body_size`, `post_max_size` and `pm.max_children` (no load) |
| N6 | Webhook SSRF pin keyed on the raw `parse_url` host, while libcurl percent-decodes or IDN-converts it and resolves on its own | `includes/security.php:269,289`; `includes/webhook_sender.php:314` | Whether the production resolver answers for a `%xx` or UTF-8 name at validation time, and whether libcurl 8.12.1 decodes it | Offline authoritative DNS test zone on a VM; regression test that hosts outside LDH/IP syntax are rejected | `php -r` curl version and IDN feature, plus a `dns_get_record` check against the owner's own test zone |
| N2 | Mint URLs are vetted once, but `MintClient` connects without the vetted addresses (DNS rebinding) | `includes/mint_helpers.php:37-55`; `CashuWallet.php:3733-3761` | Real DNS rebinding cannot be produced without network access; practical reach mostly needs an `http://` mint | Test resolver that rebinds after the first answer; pin via `CURLOPT_RESOLVE` and/or check `PRIMARY_IP` | Check whether any configured mint uses `http://` and whether plain-HTTP internal services exist |
| N1 | LNURL-pay callback (chosen by the remote server) has no address policy | `CashuWallet.php:10076-10127`, `:9948-9951` | Needs an internal HTTPS endpoint with a trusted certificate; the request is blind | Loopback HTTPS with a test CA; regression test that callbacks go through `resolvePublicTarget` with a pin | Inventory internal TLS services reachable from PHP-FPM |
| N5 | Behind a proxy that sets only `X-Real-IP`, a client-supplied `X-Forwarded-For` or `Forwarded` header picks the lockout and rate-limit identity | `includes/security.php:528-567`; `includes/auth.php:114` | Whether any deployment uses such a proxy (none is shipped) | Already reproduced: 20 of 20 login attempts reserved instead of 5 | Read the proxy config; send one request with `X-Forwarded-For: 192.0.2.1` and check the dashboard's "Visitor addresses" card |
| N4 | In WordPress mode the internal cron key is sent to `site_url()`, which some wp-config files build from the Host header | `includes/background.php:63-70`; `includes/urls.php:166`; `api.php:50` | Contents of each site's `wp-config.php` and vhost catch-all behaviour | WordPress fixture with a Host-derived `WP_SITEURL` and a loopback listener | Check `wp-config.php` for `WP_SITEURL`/`WP_HOME` built from `HTTP_HOST` |
| N7 | Webhook outbox can deliver a stale `InvoiceExpired` after `InvoiceSettled` | `includes/webhook_sender.php:360-387`; `includes/invoice.php:337-365, 982` | How the receiving plugin (BTCPay for WooCommerce) handles events out of order | Delivery inversion already reproduced; next, replay the five payloads into a local copy of the plugin | Staging shop: return 503 once at expiry, pay late, and see where the order ends up |
| N3 | Every anonymous page load creates a session file in the data directory, and the app never garbage-collects it | `includes/auth.php:25-43`; `index.php:27` | Host `session.gc_probability`. Debian/Ubuntu default to 0 and clean via a cron that does not cover this path; RHEL/Alma use 1/1000, which does clean it | Already shown: with GC off, 20 requests left 20 files; with GC on, one request removed them | `php -i \| grep gc_probability`; count files in `<data>/sessions` over 48 h |

Full traces, evidence and plans are in Appendix B.

## 5. Hardening notes (not findings)

Grouped by area. Each was raised by a hunter and judged to have no reachable boundary violation on its own.

- **Sessions and auth**
  - The CSRF token is not rotated at login.
  - `session.use_strict_mode` is off, and the session ID is not regenerated on setup claim or takeover.
  - The `Secure` cookie flag trusts a client-supplied `X-Forwarded-Proto`.
  - Cookie path is `/`.
  - The login lockout is per IP only; there is no global or per-prefix limit.
  - `Auth::logout` leaves the cookie on the client.
- **API**
  - `GET /stores/{id}` and `/payment-methods` need only store binding, while BTCPay also requires `canviewstoresettings`.
  - Keys paired before the permission allow-list may still hold `*`; no migration narrows them.
  - The `checkout` JSON has no size limit.
  - `metadata` is decoded before the 16 KiB check.
  - `handleUpdateInvoiceStatus` allows `Invalid` on a Processing invoice.
  - Webhook delivery listings expose the target's HTTP status (an oracle).
  - Redelivery works for a deleted (tombstoned) webhook.
  - `enqueueWebhook` ignores a `json_encode` failure.
- **Funds**
  - `rotateKeysetProofs`/`syncProofs`, and the `update_store` mint switch, run without the `funds_<store>` lock.
  - `markProofsExported` leaves partial transitions behind on a mismatch.
  - `check_transfer` relabels reclaimed tokens.
  - Seed immutability can be bypassed in two operator-confirmed steps (clear the mint, then change the seed).
  - Unresolved quotes at a backup mint do not block `remove_backup_mint`. This was proposed as a finding and rejected as same-principal; still worth fixing.
  - The same-unit conversion path truncates fractional sats and skips the exchange fee.
- **Wallet library**
  - Quote IDs are not URL-encoded in request paths.
  - A negative `input_fee_ppk` from the mint is accepted.
  - DLEQ accepts unreduced or uppercase scalars.
  - CBOR does not tell byte strings from text strings (a non-UTF-8 secret makes `json_encode` fail silently).
  - 6-byte keyset IDs are accepted in V4 tokens.
  - Redirects are not disabled explicitly in `MintClient`.
  - The SIIT `::ffff:0:0:0/96` prefix is missing from `NON_PUBLIC_RANGES`.
- **Background, update and release**
  - Cron and internal keys stored as bare hex can be JSON-decoded into numbers (about a 1-in-a-million chance per key).
  - The internal key is sent in cleartext to an `http://` `base_url`.
  - `?key=` puts the cron key in access logs.
  - Update-manifest metadata, unlike the kill switch, is not signed.
  - Safe-mode notices never expire unless the release lists them as retired.
  - `expire_old_invoices` expires invoices without sending `InvoiceExpired`.
- **Output and edge**
  - Both CSPs allow `unsafe-inline`.
  - Inline `onclick` handlers rely on IDs being hex.
  - `isSafeAppBaseUrl` allows quote and angle-bracket characters in the path.
  - `receive.php` echoes parser and PDO exception text to anonymous callers.
  - `api-keys/index.php` lacks the entrypoint guard (benign).
  - `config.local.php` is not in `.gitignore`.
  - `download_backup` can leave a plaintext snapshot behind if the client aborts.
  - Deleting a store does not remove its proofs from the library tables.
  - The admin data-exposure probe is not pinned and counts any 200 as exposed.

## 6. Positive patterns observed

Each of these was tested actively, not only read:

- **Greenfield API**
  - Store binding goes through the real `api.php`; a key for store A got 403 on all of store B's routes.
  - The permission-implication map matches BTCPay.
  - Pairing rejects `*` and `server.*` and intersects the approved permissions with the requested ones.
  - The pairing redirect validation refuses userinfo and backslash splits.
- **Admin and setup**
  - Every admin POST needs the session plus a CSRF token compared with `hash_equals`.
  - The login lockout reserves attempts atomically and fails closed.
  - The setup claim, the recovery code and relocation are correctly gated (aside from C4).
- **Wallet library**
  - It rejects mismatched quotes, signature counts and keysets.
  - It verifies DLEQ when present and re-derives V2 keyset IDs.
  - It keeps recovery journals on ambiguous replies.
  - Minting is exactly-once under concurrent pollers.
- **Withdrawals:** the per-store funds lock and pending-withdrawal guard held, and an LNURL payee cannot make the server pay more than requested.
- **Kill switch:** the safe-mode signature is verified (BIP-340, pinned keys, d-tag, future-dating, flag re-verified on every read). Forged, re-signed and tampered notices were all ignored in the sandbox.
- **Webhooks:** outbound requests use a pinned resolve with ports 80/443 only (apart from N6), no redirects, and the target is re-checked at send time.
- **Output and routing:** escaping was consistent across the payment, receive, admin and setup pages, and the router blocklist resisted encoded, double-slash and traversal paths.

## 7. Coverage

- **Ledger:** 30 units, all closed. 13 are `covered` with no finding and 17 are `candidate`, each linked to a final record.
- **Records:** 18 in total (9 confirmed, 8 needs-validation, 1 rejected), each with an independent final disposition. No `deferred`, `blocked` or `out_of_scope` units remain.
- **Wave 1:** 23 units across 12 hunters.
- **Critic 1:** added 4 units (admin mint reconfiguration and backup mints, webhook outbox lifecycle, token parsers, setup/admin config writes) and reassigned the wildcard unit, which had stopped short.
- **Critic 2:** added 3 units (WordPress multisite, data-exposure self-probes, authenticated API body parse).
- **Critic 3 and the final-clean critic:** both returned no work (`stop: true`).
- **Rejected:** `admin.php:remove_backup_mint:cashupay_backup_mint_holdings-ignores-unresolved-quotes`. The source trace is real, but only the operator can trigger it and only the operator's own funds view is affected. It is listed only so future runs don't re-raise it as a finding.
- **Limits of this pass:**
  - It covers what a fixed set of hunters and critics found in one run, not proof that nothing else exists.
  - Deployment-side controls (nginx, FPM, wp-config, proxies) remain the deciding facts for N1–N8.
  - PHP 8.3 and libcurl 8.12.1 (production) were not tested; the local stack is PHP 8.5.11 and libcurl 8.23.0.
- **Machine-readable artefacts** (in `~/security-audit-skill/cashupayserver/run-3/`): `findings.json` and `coverage-ledger.json`, both passing the skill's validators, plus `run-metadata.json` and the promoted evidence under `agents/*/artifacts/`.

---

# Appendix A — Confirmed findings: full detail

Evidence references of the form `agents/<id>/artifacts/...` resolve under `~/security-audit-skill/cashupayserver/run-3/` (local audit output, not part of this repository). Each holds the exact command, input and output of a sandboxed run.


Derived verbatim from `findings.json`. All reproductions ran locally in a macOS sandbox-exec jail (no external network, scratch-only writes, dummy data). Nothing was sent to any deployed system.

### C1. Unauthenticated receive.php burns ~12-14 s of CPU per request by replaying one spent DLEQ proof up to 1000 times

**Severity:** medium (likelihood medium, impact medium) · **Confidence:** high  
**Fingerprint:** `cashu-wallet-php/Wallet::receive:dleq-before-nut07-duplicate-proofs`

receive.php accepts a public POST of a Cashu token for any configured store. Wallet::receive runs NUT-12 DLEQ verification on every proof that carries a blinding factor r: hash-to-curve plus pure-PHP/GMP secp256k1 scalar multiplications, about 12-14 ms per proof. This runs before the NUT-07 spent-state check and before duplicate inputs are rejected. TokenSerializer allows up to 1000 proofs in a 262144-byte token and does not deduplicate them. Without a payment-request id, receive.php goes from deserialize and the mint/unit match straight to $wallet->receive(), with no proof-count or amount gate in between. An attacker who holds one proof signed by the store's mint, such as a 2-sat proof or any already-spent token, can repeat it in one token. With an attacker-chosen 8-hex secret (the secret is hidden from the mint at blind-signing time), 1000 copies fit in 217 KB. With a normal 64-hex secret, about 880 copies fit. Each POST then holds a PHP worker for about 11-14 s before the mint answers 'Token already spent'. The 20 requests/min per-IP limit still allows roughly 250+ CPU-seconds per minute from each source IP, against the PHP-FPM pool that also serves checkout (payment.php) and the Greenfield API.

**Root cause.** Wallet::receive verifies client-supplied DLEQ proofs for every input (CashuWallet.php:8072-8079) before the cheap mint-side NUT-07 state check (8094-8105) and before duplicate-secret rejection, which only happens at swap reservation (preparePendingSpend, 5163-5165). TokenSerializer accepts up to MAX_PROOFS=1000 proofs (3403, 3543) with no deduplication. receive.php adds no smaller proof cap or CPU budget on the public path.

**Intended behaviour.** Public token submission should do work in proportion to distinct, possibly-unspent value. Duplicate inputs should be rejected right after parsing. Spent or pending proofs should be refused through one NUT-07 request before expensive local cryptography. Per-request crypto work on the unauthenticated endpoint should be capped.

**Source trace.**

1. entrypoint — `receive.php:128` (POST handler): Unauthenticated POST. The only gate before the expensive work is the 20/min per-IP rate limit.
2. propagation — `receive.php:216` (POST handler): Requires only a configured store_id, taken from the body or query, or fixed by a payment-request id.
3. propagation — `receive.php:224` (POST handler): Size cap of 262144 bytes. A 1000-proof V4 token with 8-hex secrets is 217444 bytes; with 64-hex secrets about 880 proofs fit.
4. propagation — `receive.php:230` (POST handler): TokenSerializer::deserialize accepts up to 1000 proofs and does not deduplicate.
5. propagation — `receive.php:332` (POST handler): Without a request id, $wallet->receive($tokenString) is called right after the mint/unit match and keyset load.
6. propagation — `cashu-wallet-php/CashuWallet.php:8076` (Wallet::receive): Crypto::verifyProofDleq runs for every proof that has r, including identical duplicates.
7. sink — `cashu-wallet-php/CashuWallet.php:8097` (Wallet::receive): The NUT-07 spent check that rejects the token runs only after all the DLEQ work is done.

**Evidence.**

- `cashu-wallet-php/CashuWallet.php:3403` — MAX_PROOFS = 1000 per token; MAX_TOKEN_LENGTH = 262144 at line 3400.
- `cashu-wallet-php/CashuWallet.php:8072` — The DLEQ loop over all token proofs runs before the fee, NUT-07 and swap steps.
- `cashu-wallet-php/CashuWallet.php:5163` — Duplicate inputs are refused only at swap reservation, after the DLEQ loop and the NUT-07 check.
- `cashu-wallet-php/CashuWallet.php:1222` — verifyProofDleq: hashToCurve, two scalarMult for the B'/C' reconstruction, then verifyDleq (more scalar multiplications).
- `receive.php:128` — 20 requests/min per client IP is the only bound before the expensive work.

**Conditions.**

- *authentication_level*: None; receive.php POST is public.
- *data_state*: Attacker knows a configured store_id (published receive links or NUT-18 transport URLs), or a payment request id that fixes the store.
- *third_party_dependency*: Attacker holds one proof signed by the store's mint with a full DLEQ (e,s,r), for example a 2-sat proof they minted with a short secret, or any already-spent token for that mint.
- *system_configuration*: Impact scales inversely with PHP-FPM worker count and CPU count. The default max_execution_time of 30 s does not cut off a ~14 s request. Upstream proxy body limits and timeouts are not in the repository.

**Bounded local reproduction** (attacker: Anonymous internet client holding one spent ecash proof from the store's mint.).

Input:

```
cashuB token for the store's mint/unit containing 1000 copies of one spent 2-sat proof with an 8-hex secret and dleq {e,s,r} (217444 bytes), POSTed as {"store_id":"<store>","token":"cashuB..."}
```
Steps:

1. Inside tools/sbx.sh with --loopback, start tests/mock_mint_router.php on 127.0.0.1 with a scratch MOCK_MINT_STATE.
2. Use a scratch CASHUPAY_DATA_DIR wallet against the mock mint. Build one amount-2 proof signed with the mock keyset key (sha256('mock-mint-key-gen1-2')) that includes a NUT-12 DLEQ with r, and mark its Y spent in the mock state.
3. Call Wallet::receive (the call receive.php:332 makes) with tokens of 1, 100 and 1000 copies, and time each call until the exception.

**Observed result.** 8-hex secret: 1 copy 19.7 ms; 100 copies 1324.2 ms; 1000 copies (217444-byte token, under the 262144 cap) 13820.6 ms. 64-hex secret: 1 copy 19.7 ms; 100 copies 1357.6 ms; 1000 copies refused by the size cap (293444 bytes). The hunter measured 880 such copies at 11.1-15.2 s. Every accepted run ended with CashuProtocolException 'Token already spent.' Cost grows linearly at about 13 ms per proof, and all of it is spent before the spent check.

**Priority rationale.** Likelihood: Needs only a public store id and one cheap or already-spent proof from the store's mint. One request every 3 seconds per IP stays under the rate limit, and multiple IPs multiply the effect. Impact: Each request holds a PHP worker for 11-14 s of CPU. A few source IPs can exhaust a typical worker pool shared by checkout and the invoice API. No funds or data are affected, and service recovers once the traffic stops.

**Fix.** In Wallet::receive, reject duplicate secrets/Ys immediately after deserialization. Run the NUT-07 state check (one mint request) before local DLEQ verification so spent or pending proofs are refused cheaply. Cap the number of proofs accepted on the public receive.php path (for example 100 or fewer), and/or enforce a per-request crypto budget.

Sketch for `cashu-wallet-php/CashuWallet.php`:

```php
// in Wallet::receive, right after resolveShortKeysetIds():
$ys = array_map(fn(Proof $p) => strtolower($p->Y), $token->proofs);
if (count($ys) !== count(array_unique($ys))) {
    throw new CashuException('Token contains duplicate proofs');
}
// move the NUT-07 checkProofState() block above the DLEQ verification loop
```
Sketch for `receive.php`:

```php
// after TokenSerializer::deserialize():
if (count($parsed->proofs) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Token has too many proofs']);
    exit;
}
```
**Confidence.** The ordering is visible in source, and the linear per-proof cost was independently reproduced end to end in the sandbox against the loopback mock mint, using the same library call receive.php makes.


### C2. Rate sanity band can be ratcheted by a single provider, underpricing fiat invoices without bound

**Severity:** medium (likelihood low, impact high) · **Confidence:** high  
**Fingerprint:** `includes/rates.php:ExchangeRates::getBtcPrice:single-source-band-ratchet`

ExchangeRates::getBtcPrice compares each provider answer with lastKnownRate(), which is the most recently cached rate. It accepts the first answer within 50% of that rate and saves it as the new cached rate without asking any other provider. A primary provider that returns hostile or faulty values can therefore move the accepted rate by up to 1.5x on each 5-minute cache refresh, and every accepted step becomes the next reference. The cross-provider agreement check, which the code comment says guards against a hijacked response, only runs for answers outside the band. Invoice::create converts fiat invoice amounts with this rate, so after a few refreshes a 100 EUR order is priced at a small fraction of its value in sats. The shop treats such an invoice as fully paid. A single large jump (for example 10x) is correctly rejected and cross-checked. Only in-band steps compound.

**Root cause.** withinBand() measures the candidate against lastKnownRate(), the rate most recently written by saveToCache() (includes/rates.php:265-266). That rate may itself have come from a single provider. Single-source acceptances therefore compound. There is no anchor confirmed by several sources, no limit on cumulative drift, and no corroboration when the primary answers inside the band.

**Intended behaviour.** A single provider must not be able to move the invoicing price more than a bounded amount away from a rate confirmed by independent sources. A rate that moves pricing materially must agree with at least one other provider, as the comment at includes/rates.php:246-250 describes.

**Source trace.**

1. entrypoint — `includes/api/invoices.php:70` (handleCreateInvoice): A Greenfield key holder (the shop plugin, acting on any customer checkout) creates a fiat-denominated invoice.
2. propagation — `includes/invoice.php:138` (Invoice::create): The amount is converted to the mint unit through ExchangeRates::convertToMintUnit, using the store's primary/secondary provider order (default coingecko, then binance).
3. propagation — `includes/rates.php:390` (ExchangeRates::toBtc): The fiat amount is divided by getBtcPrice().
4. propagation — `includes/rates.php:251` (ExchangeRates::getBtcPrice): The reference is lastKnownRate(): the last saved rate regardless of how many sources confirmed it, up to 24 h old.
5. propagation — `includes/rates.php:265` (ExchangeRates::getBtcPrice): The first provider answer within 50% of the reference is accepted at once. The remaining providers are never asked.
6. sink — `includes/rates.php:266` (ExchangeRates::getBtcPrice -> saveToCache): The single-source rate is stored as rate_<cur> (saveToCache, line 548). It prices invoices for the next 300 s and is the reference for the next refresh, so the drift compounds.

**Evidence.**

- `includes/rates.php:246` — The comment says the band exists so that one bad or hijacked answer cannot price invoices.
- `includes/rates.php:296` — SANITY_BAND = 0.5: each step may move the rate by up to 50% of the previously accepted rate.
- `includes/rates.php:305` — lastKnownRate returns the last cached rate (up to REFERENCE_TTL 86400 s old), whatever its provenance.
- `includes/rates.php:283` — With no reference, a single answer is accepted outright.
- `includes/rates.php:188` — CACHE_TTL = 300 s, so a new single-source step can happen every five minutes, triggered by invoice creation.
- `includes/rates.php:30` — Providers fetch fixed HTTPS hosts with default curl TLS verification, so the attacker must control the provider's answers. On-path tampering is not enough.

**Conditions.**

- *third_party_dependency*: The store's primary price provider (default coingecko) returns attacker-chosen or persistently faulty values, while TLS to the fixed host is verified by default.
- *data_state*: The store prices invoices in a fiat currency (EUR, USD, ...) and converts through getBtcPrice, for example on a sat mint.
- *timing_dependency*: Rate refreshes (triggered by conversions after the 300 s cache expires) occur during the ratchet. Each refresh moves the rate by at most 1.5x.

**Bounded local reproduction** (attacker: A party that controls the answers of the operator's primary BTC price provider, while the other providers stay honest.).

Input:

```
Control: primary answers 600000 once against reference 60000 (expected rejection)
```
```
Ratchet: primary answers previous_accepted*1.45 on each of 10 refreshes (87000 ... 2465081.46); binance and kraken always answer 60000; starting rate_eur 60000
```
Steps:

1. In the sandbox scratch directory, define CASHUPAY_DATA_DIR, include cashu-wallet-php/CashuWallet.php, includes/database.php, includes/config.php and includes/rates.php, then call Database::initialize().
2. Replace ExchangeRates::$providers via reflection with in-process stubs: coingecko (hostile queue), binance and kraken (fixed 60000).
3. Seed rate_eur = {rate: 60000, timestamp: now-301}.
4. Control step: queue 600000 for the primary and call getBtcPrice('EUR','coingecko','binance').
5. Repeat 10 times: queue previous*1.45, age rate_eur to now-301, clear the Config cache, then call getBtcPrice('EUR','coingecko','binance') and convertToMintUnit('100','EUR','sat',0,'coingecko','binance').
6. Run with: ~/security-audit-skill/cashupayserver/run-3/tools/sbx.sh v-rates -- php -d opcache.enable_cli=0 harness/ratchet.php (no network, scratch-only writes).

**Observed result.** Control: the 10x answer was rejected, binance was consulted once, and the rate stayed 60000. Ratchet: all 10 in-band hostile answers were accepted, and honest providers were consulted 0 times during the ratchet. A 100 EUR invoice fell from 166667 sats (fair) to 114943 after step 1 and 4057 after step 10 (about 2.4% of value). The cached provider was coingecko. Artifact: agents/v-rates/artifacts/evidence/rate_ratchet_verify.txt

**Priority rationale.** Likelihood: It requires hostile or persistently faulty answers from the operator's primary rate provider over verified TLS to a fixed host, sustained across several refreshes. Impact: Fiat-priced invoices can be underpriced without a floor (about 31% low after one step, about 2.4% of value after 10 refreshes, roughly 50 minutes). The shop marks those underpaid orders as paid in full and ships the goods.

**Fix.** Keep the comparison anchor separate from the price cache. Update the anchor only when at least two providers agree. Accept a single provider's answer only within a narrow band of that anchor. Otherwise require agreement from two providers before saving or pricing. Stop accepting a lone answer when no anchor exists. Add a regression test where a hostile primary repeatedly returns 1.45x the previous value and honest providers return a constant, and assert that the accepted rate never leaves the narrow band around the confirmed anchor.

Sketch for `includes/rates.php`:

```php
// in getBtcPrice(): compare against a multi-source-confirmed anchor, never against the single-source cache
$anchor = self::confirmedAnchor($currency); // rate_anchor_<cur>, written only when two providers agree
$collected = [];
foreach ($order as $provider) {
    $rate = /* fetch as before, reject null/non-finite/<=0 */;
    foreach ($collected as [$earlierRate, $earlierName]) {
        if (self::withinBand($rate, $earlierRate, 0.02)) {
            self::saveToCache($currency, $earlierRate, $earlierName);
            Config::set('rate_anchor_' . $currency, ['rate' => $earlierRate, 'timestamp' => time()]);
            return $earlierRate;
        }
    }
    if ($anchor !== null && self::withinBand($rate, $anchor, 0.05)) {
        self::saveToCache($currency, $rate, $provider->getName()); // anchor NOT updated
        return $rate;
    }
    $collected[] = [$rate, $provider->getName()];
}
// no single unconfirmed answer is accepted
return self::getCached($currency, true);
```
**Confidence.** The path is short and source-visible. An independent sandboxed harness ran the unmodified ExchangeRates code with stub providers. It confirmed that the band rejects a single large jump but accepts compounding in-band steps without consulting the honest providers, and that invoice sat amounts collapse accordingly.


### C3. An already-redeemed ecash token can mark any number of NUT-18 payment requests as paid

**Severity:** medium (likelihood high, impact medium) · **Confidence:** high  
**Fingerprint:** `receive.php:PaymentRequest::receiptState:reused-spent-secrets-mark-request-paid`

receive.php binds a payment request to the posted token's secrets (PaymentRequest::claim) before anything checks that those secrets are new. If Wallet::receive then throws (NUT-07 pre-check 'Token already spent.', or the request amount check), the catch block asks PaymentRequest::receiptState what happened. receiptState only checks whether every secret has a SPENT row in the store wallet. After any successful receive, whether a donation with no request id or a payment for another request, the store wallet holds that token's input secrets as SPENT. So receiptState returns 'paid'. The catch block handles only 'pending' and 'released', so the request stays 'receiving' and bound to the old secrets. A second identical POST takes the claim()=='same' branch, receiptState returns 'paid' again, and markPaid records the request as paid with the old token's amount. With no second POST, the background task reconcileReceiving reaches the same markPaid. This works even when the request's amount is larger than the token, because a failed amount check also leaves the claim bound. The public status poll and the request page then show 'Paid ✓ (N SAT received)' although no new funds arrived. One real payment, or a donation, can be replayed against any number of new requests. The effect is limited to payment_requests state: markPaid triggers no webhook and no Greenfield invoice change.

**Root cause.** PaymentRequest::receiptState (includes/payment_request.php:144-172) treats 'every input secret is SPENT in our wallet and none is reserved' as proof that the swap for this claim succeeded. It does not tie the SPENT rows to this claim or to its receiving_since. claim() (includes/payment_request.php:99-118) binds the request to secrets the wallet already knew. The receive.php catch block (353-378) releases the claim only on 'released', so a 'paid' result from pre-existing rows keeps the request bound, and the 'same' branch (receive.php:283-296) and reconcileReceiving (includes/payment_request.php:196-205) then mark it paid.

**Intended behaviour.** A payment request should become paid only when proofs that were not in the store wallet before its claim are swapped for that request. The server should refuse secrets the wallet already holds before claim, or release the claim. Such secrets must never count as this request's receipt.

**Source trace.**

1. entrypoint — `receive.php:156` (POST handler): An anonymous POST carries the request id (body 'id' or ?request_id) and a PaymentRequestPayload or token that reuses proofs the store already redeemed.
2. propagation — `receive.php:270` (POST handler): PaymentRequest::claim sets status 'receiving' and input_secrets to the reused secrets. No earlier step checks that the secrets are new to the wallet.
3. propagation — `cashu-wallet-php/CashuWallet.php:8106` (Wallet::receive): The NUT-07 pre-check throws 'Token already spent.'. Alternatively the amount check at receive.php:319-323 throws before the swap.
4. propagation — `receive.php:362` (POST catch): receiptState returns 'paid' because the secrets are SPENT rows from the earlier receive. Only 'pending' (503) and 'released' (line 372) are handled, so the claim stays bound and the response is 400.
5. propagation — `receive.php:290` (POST 'same' branch): A second POST with the same proofs gets claim()=='same', and receiptState again returns 'paid'.
6. sink — `receive.php:293` (POST 'same' branch): PaymentRequest::markPaid sets status 'paid' and received_amount to the old token's net value. The public status poll (receive.php:892) shows 'Paid ✓'. reconcileReceiving (includes/payment_request.php:204, scheduled at includes/background_runner.php:103) reaches the same sink with no second POST.

**Evidence.**

- `includes/payment_request.php:160` — receiptState counts SPENT rows for the secrets. If all of them are SPENT, it returns 'paid' with no link to this claim or its receiving_since.
- `includes/payment_request.php:117` — claim() returns 'same' for an identical secret set on a 'receiving' request, so a retry reaches the receiptState shortcut before any amount or freshness check.
- `receive.php:372` — The catch block releases the claim only on 'released'. A 'paid' state from reused secrets leaves the request bound.
- `cashu-wallet-php/CashuWallet.php:5289` — finalizePendingSpend calls updateProofsState(inputSecrets, SPENT) for received inputs. This is what makes the reused secrets look 'paid' later.
- `cashu-wallet-php/CashuWallet.php:4770` — storeProofs inserts with ON CONFLICT(wallet_id, secret) DO NOTHING, so a pre-existing proof row keeps its original created_at. A receiving_since bound in receiptState therefore reliably excludes secrets the wallet held before the claim.
- `includes/payment_request.php:73` — markPaid only updates payment_requests (status, received_amount, result). It fires no webhook and changes no invoice, which bounds the impact to request status.

**Conditions.**

- *authentication_level*: None. receive.php POST and GET (create request, status poll) are public.
- *data_state*: The attacker holds the proofs (secrets) of a token the store wallet already received and stores as SPENT, for example a token the attacker earlier used to pay or donate to the store.
- *system_configuration*: The store's mint is reachable for the NUT-07 pre-check, or the amount check fails first. The impact depends on the operator relying on payment-request status (the request page's 'Paid ✓') to hand over goods.

**Bounded local reproduction** (attacker: Anonymous payer who has once sent a real ecash token to the store, either as a donation or as payment for an earlier request.).

Input:

```
POST receive.php?store_id=store_1 body {"mint":"<store mint>","unit":"sat","proofs":[<30-sat proofs D>]} (one real donation)
```
```
POST receive.php?request_id=<X> body {"id":"<X>","mint":"<store mint>","unit":"sat","proofs":[<same proofs D>]} sent twice
```
```
POST receive.php?request_id=<Y> (1000-sat request) with the same proofs D once, then PaymentRequest::reconcileReceiving()
```
Steps:

1. In the sandbox with a loopback mock mint (tests/mock_mint_router.php), set up store_1 and a payer wallet as in tests/payment_request_binding.php, and run the real receive.php under php -S.
2. Donate 30 sat proofs D with no request id: HTTP 200.
3. Create request X (30 sat) through GET, then POST D for X twice.
4. Create request Y (1000 sat), POST D once, then run PaymentRequest::reconcileReceiving().
5. Compare the store UNSPENT balance before and after.

**Observed result.** Donation: HTTP 200, amount 29, store balance 29. X first POST: HTTP 400 'Token already spent.', X status 'receiving'. X second POST: HTTP 200 success amount 29, X status 'paid' with received_amount 29, public status 'paid'. Y POST: HTTP 400 'This token is for 30 sat, but the request asks for 1000 sat', Y status 'receiving'. reconcileReceiving returned {checked:1, paid:1} and Y became 'paid' with received_amount 29. The store balance stayed at 29 throughout. This independently matches the hunter's request-reuse variant.

**Priority rationale.** Likelihood: Public endpoint with no authentication. One earlier real payment or donation is enough, and the replay is two plain POSTs, or one POST followed by the background task. Impact: Payment requests show as paid ('Paid ✓') with no new funds, so a merchant using receive.php requests at the point of sale can be tricked into handing over goods. No existing funds are lost. markPaid fires no webhook, and Greenfield/Lightning invoices are not affected.

**Fix.** Count only proofs this claim brought in. Pass the claim's receiving_since into PaymentRequest::receiptState and consider only cashu_proofs rows with created_at >= receiving_since. storeProofs never overwrites an existing row's created_at (ON CONFLICT DO NOTHING), so secrets the wallet held before the claim become invisible, and the state comes back 'released'. The existing catch, 'same' and reconcileReceiving branches then release the claim instead of marking the request paid. Keep the catch block's current handling: a genuine 'paid' after a completed swap must stay bound, so the background check can finish it (receive.php:343-345). Optionally, when claim() returns 'claimed' (a fresh bind only, never 'same' or 'paid'), refuse and release if any secret already has a cashu_proofs row or a reserved journal entry. Do not run that check before claim, because it would break the replay of a lost response. Add regression tests to tests/payment_request_binding.php: reuse a donated token and a token from another request for a new request; expect 4xx on both POSTs and the request back at 'pending'; reconcileReceiving must not mark it paid; and the existing lost-response retry tests must still pass.

Sketch for `includes/payment_request.php`:

```php
public static function receiptState(\Cashu\Wallet $wallet, array $secrets, int $since): array {
    // ... reserved check unchanged ...
    $rows = Database::fetchAll(
        "SELECT secret, state FROM cashu_proofs WHERE wallet_id = ? AND created_at >= ? AND secret IN ($in)",
        array_merge([$storage->getWalletId(), $since], $secrets)
    );
    // ... rest unchanged; pre-existing rows are excluded, so reused secrets yield 'released'
}
// reconcileReceiving(): self::receiptState($wallet, $secrets, (int)$request['receiving_since']);
```
Sketch for `receive.php`:

```php
// 'same' branch (line 290) and catch (line 362): read the bound claim's timestamp
$current = PaymentRequest::getById($paymentRequest['id']);
$state = PaymentRequest::receiptState($wallet, $secrets, (int)($current['receiving_since'] ?? PHP_INT_MAX));
```
**Confidence.** Every cited line was re-read in the source. An independent PoC reproduced the result end to end in the sandbox against the real receive.php and the mock mint, including a new donation variant and the background reconcile path.


### C4. Setup wizard add_store mode lets any admin session reset the admin password without the current password and without ending other sessions

**Severity:** medium (likelihood low, impact high) · **Confidence:** high  
**Fingerprint:** `setup.php:add_store-step2-setAdminPassword-bypasses-changeAdminPassword`

On a completed standalone install, setup.php lets a logged-in admin session through when mode=add_store. The step allowlist still includes step 2 (admin password), and add_store mode does not restrict the step to 4-7. GET setup.php?mode=add_store&step=2 shows the password form. A CSRF-valid POST with step=2 calls Auth::setAdminPassword directly. That skips Auth::changeAdminPassword, which checks the current password and rotates admin_password_version. So someone who holds only an admin session (a copied cookie, an unattended logged-in browser, or a script running in the admin origin) can do three things. (a) Replace the admin password with one they know. (b) Then pass the fresh-password re-checks on reveal_seed and download_backup, which exist so that an unattended dashboard is not enough to take the wallet keys. (c) Lock the operator out of new logins with the old password. Every existing session stays valid, the attacker's included, because the session generation is never bumped. The operator's surviving sessions can win the account back only through the same unguarded path.

**Root cause.** setup.php treats mode=add_store as re-entry into the full wizard step set [1,2,4,5,6,7] (setup.php:100). Step 2 (setup.php:217-228) calls Auth::setAdminPassword(), a raw hash setter (includes/auth.php:153-156), not the guarded Auth::changeAdminPassword() (includes/auth.php:167-191). A completed installation therefore has a second way to change the password, with no proof of the current password and no session-generation rotation.

**Intended behaviour.** After setup is complete, the admin password changes only through Auth::changeAdminPassword. It verifies the current password and bumps admin_password_version so that every other session ends. add_store mode accepts only the store-creation steps (4-7), and the setup password step never overwrites an existing admin_password_hash.

**Source trace.**

1. entrypoint — `setup.php:39` (top-level completed-setup gate): With setup complete, an anonymous request is redirected (lines 40-43; verified: an anonymous GET returns 302). A logged-in session with mode=add_store passes line 44 whatever step it requests.
2. propagation — `setup.php:100` (step selection): The requested step=2 is in the allowlist [1,2,4,5,6,7] in every mode. $setupTokenRequired is false because setup is complete (line 59), so the step is not forced to 1 (lines 104-106).
3. propagation — `setup.php:217` (case 2: Password setup): Checks only that the length is at least 8 and that the confirmation matches. There is no current-password check and no check that a password already exists. The only other gate is the session CSRF token (lines 62, 135-139), which the session holder already has.
4. sink — `setup.php:228` (case 2): Auth::setAdminPassword($password) overwrites admin_password_hash (includes/auth.php:153-156) and does not bump admin_password_version.

**Evidence.**

- `includes/auth.php:167` — The intended path, changeAdminPassword, verifies the current password (line 175) and bumps the password version (line 184). setup.php step 2 bypasses both.
- `includes/auth.php:69` — isLoggedIn revokes sessions only through password_version, which setAdminPassword never changes. Every existing session survives the reset: verified with a non-empty pre-existing version that stayed 'pv_initialversion000000000'.
- `admin.php:846` — reveal_seed is gated on Auth::verifyAdminPassword. After the reset, the session holder passes it with the password it set itself.
- `admin.php:879` — download_backup (a VACUUM INTO snapshot holding proofs and seeds) uses the same verifyAdminPassword gate, so it is bypassed the same way.

**Conditions.**

- *authentication_level*: The attacker controls an existing standalone admin session: a copied session cookie, an unattended logged-in browser, or a script running in the admin origin (the cookie is HttpOnly, so such a script can act but cannot exfiltrate it). The attacker does not know the admin password.
- *system_configuration*: Standalone mode (not WordPress) with setup completed and an admin password set.

**Bounded local reproduction** (attacker: Holder of an admin session cookie who does not know the admin password).

Input:

```
GET setup.php?mode=add_store&step=2 (read csrf_token from the rendered password form)
```
```
POST setup.php?mode=add_store body: step=2&mode=add_store&password=attacker-pass-1&confirm_password=attacker-pass-1&csrf_token=<token>
```
```
POST admin.php body: action=reveal_seed&store_id=store_dummy&password=attacker-pass-1&csrf_token=<admin csrf>
```
Steps:

1. Ran tools/sbx.sh v-addstore --loopback -- php -d opcache.enable_cli=0 work/poc.php (macOS sandbox-exec, loopback only, scratch-only writes). The script creates a throwaway CASHUPAY_DATA_DIR in scratch with setup_complete, admin password 'operator-pass-1', admin_password_version 'pv_initialversion000000000' and a dummy store seed. It serves the real setup.php and admin.php with the PHP built-in server on 127.0.0.1.
2. The operator logs in. Its cookie jar is copied to the attacker's jar.
3. The attacker tries reveal_seed with a guessed password, then sends the setup.php GET and POST above. The script then checks the stored hash and version, logs in from fresh jars with the old and new passwords, calls reveal_seed again from the copied session, and confirms the operator's session still works.

**Observed result.** reveal_seed with a guess: 403. An anonymous GET of setup.php?mode=add_store: 302. The copied session's GET of mode=add_store&step=2: 200 with the password form and a CSRF token. The step=2 POST: 200. The stored hash then verified attacker-pass-1 and no longer verified operator-pass-1. admin_password_version was unchanged ('pv_initialversion000000000'). A fresh login with the old password: 401. A fresh login with attacker-pass-1: 200. reveal_seed from the copied session with attacker-pass-1: 200 {"seedPhrase":"DUMMY-SEED-NOT-REAL abandon abandon"}. The operator's original session still got 200 on ?api=stores. Evidence: agents/v-addstore/artifacts/evidence/addstore-step2-reset-verify.txt

**Priority rationale.** Likelihood: Requires an existing admin session (cookie theft, an unattended browser, or same-origin script). Anonymous requests are redirected, and cross-site requests fail on the session-bound CSRF token and the SameSite=Lax cookie. Impact: Turns a session-only foothold into control of the admin password. It defeats the fresh-password re-check that guards seed reveal and full DB backup (every store's seed and stored proofs), and blocks the operator from new logins with their own password. The password-version session revocation is not triggered.

**Fix.** Once setup is complete (and always in add_store mode), reject steps 1 and 2 and clamp to 4-7. As defence in depth, make setup step 2 refuse to overwrite an existing admin_password_hash, so that any change after setup goes through Auth::changeAdminPassword. Regression test: a logged-in session GETs and POSTs setup.php?mode=add_store with step=2; the stored hash and admin_password_version must not change, and the response must not render the password form.

Sketch for `setup.php`:

```php
if (!in_array($step, [1, 2, 4, 5, 6, 7], true) || (($mode === 'add_store' || $setupComplete) && $step < 4)) {
    $step = $mode === 'add_store' ? 4 : 1;
}
...
case 2: // Password setup
    if (Config::getAdminPasswordHash() !== null) {
        throw new Exception('The admin password is already set. Change it from the dashboard settings.');
    }
```
**Confidence.** I re-read every cited line and they match the source. I independently reproduced the result end to end in the sandbox against the real setup.php and admin.php with dummy data, including with a pre-existing non-empty password version.


### C5. WordPress plugin puts sibling sites' wallets in one shared dirname(ABSPATH)/cashupay-data, so each site's admin controls the other sites' seeds and funds

**Severity:** medium (likelihood low, impact high) · **Confidence:** high  
**Fingerprint:** `wordpress/bootstrap.php:CASHUPAY_DATA_DIR:dirname-ABSPATH-shared-across-sites`

The WordPress bootstrap picks its data directory from dirname(ABSPATH) alone, and it reuses that directory whenever it already exists. Two WordPress installs whose ABSPATHs share a parent directory both resolve to <parent>/cashupay-data. Examples: cPanel addon domains under ~/public_html/<domain>/, and Plesk vhost subdirectories. When the second site activates, it opens the first site's live SQLite wallet. Setup already reads as complete, the first site's stores and seed phrases are visible, and activation overwrites the shared base_url with the second site's URL. That overwrite also breaks the first site's payment and webhook URLs, even when one person owns both sites. In WordPress mode both the admin gate (Auth::isLoggedIn) and the seed-reveal password re-check (Auth::verifyAdminPassword, when no standalone hash exists) reduce to current_user_can('manage_options') on the current site. So an administrator of site B gets full CashuPay control of site A's wallet. Standalone relocation (Database::relocateOutsideWebroot) avoids this exact collision with a random suffix; the WordPress path has no such check. Calibration: sibling installs on one hosting account usually run as the same Unix user. A site-B admin who can install or edit plugins could then already read site A's files. So the cross-installation gain matters most where file mods are disabled (DISALLOW_FILE_MODS/DISALLOW_FILE_EDIT, managed hosting) or where site-B admin rights are delegated to someone not trusted with site A.

**Root cause.** wordpress/bootstrap.php:22-28 builds CASHUPAY_DATA_DIR only from dirname(ABSPATH), which is not unique per installation. Its is_dir() test also takes an existing directory first. Nothing ties the directory or its database to the installation that created it (no ABSPATH/site_url marker check).

**Intended behaviour.** Each separate WordPress installation should use a data directory and database that only it owns. It should never silently adopt a directory created by a sibling install. When it finds one, it should fall back to a per-install location, such as one keyed on a hash of ABSPATH, or wp-content/cashupay-data, or refuse and explain why. This matches what Database::relocateOutsideWebroot does for standalone installs.

**Source trace.**

1. entrypoint — `wordpress/bootstrap.php:23` (plugin bootstrap, every request and activation of site B): $above_abspath = dirname(ABSPATH) . '/cashupay-data' gives the same path for sibling sites <parent>/siteA/ and <parent>/siteB/.
2. propagation — `wordpress/bootstrap.php:24` (plugin bootstrap): is_dir($above_abspath) is true once site A has created it, so site B defines CASHUPAY_DATA_DIR as site A's directory.
3. propagation — `includes/database.php:43` (Database::getDataDir): The CASHUPAY_DATA_DIR constant takes priority, so site B's Database opens site A's cashupay.sqlite.
4. propagation — `wordpress/activation.php:147` (cashupay_activate_unguarded): After Database::initialize() runs on the existing DB, site B's activation overwrites the shared base_url with site_url('/cashupay') of site B.
5. propagation — `wordpress/rewrite-rules.php:108` (cashupay_handle_request): /cashupay-admin/ on site B checks only site B's manage_options, then requires admin.php against the shared DB.
6. propagation — `includes/auth.php:59` (Auth::isLoggedIn): In WordPress mode, logged in means current_user_can('manage_options') on the current site.
7. sink — `admin.php:846` (reveal_seed POST action): Auth::verifyAdminPassword returns true for manage_options when no standalone hash exists (includes/auth.php:131-137). The handler then returns the seed of site A's store, which is bearer control of its funds.

**Evidence.**

- `wordpress/bootstrap.php:24` — An existing <parent>/cashupay-data is reused unconditionally, with no ownership or installation check.
- `includes/database.php:99` — Standalone control: when <parent>/cashupay-data already exists, a random suffix is chosen, so another install's folder is never adopted. The WordPress path has no such check.
- `includes/auth.php:136` — In WordPress mode the seed-reveal password gate is just current_user_can('manage_options') when no standalone password hash exists.
- `wordpress/activation.php:147` — The second site's activation rewrites the shared base_url to its own URL.

**Conditions.**

- *system_configuration*: Two or more WordPress installs run the CashuPay plugin, their ABSPATHs share a parent directory, that parent is PHP-writable (or the cashupay-data directory already exists), and CASHUPAY_DATA_DIR is not defined in wp-config.php.
- *authorization_role*: The attacker holds manage_options on a different WordPress site than the victim and is not otherwise trusted with the victim's files. In practice the gain is clearest when that admin cannot install or edit plugin code (DISALLOW_FILE_MODS/EDIT, managed hosting, delegated client sites), since same-Unix-user siblings otherwise already share file access.
- *data_state*: The victim site has completed CashuPay setup and holds a store seed and ecash.

**Bounded local reproduction** (attacker: Administrator (manage_options) of WordPress site B, which sits next to merchant site A in the same hosting parent directory. Site B's admin has no rights over site A.).

Input:

```
ABSPATH=<hosting>/siteA.example/ then ABSPATH=<hosting>/siteB.example/, each loading plugin/bootstrap.php + activation.php and running cashupay_activate_unguarded(), with current_user_can() true only for manage_options
```
Steps:

1. Lay out the plugin the way scripts/build-wordpress-plugin.sh:60-82 does (wordpress/*.php flattened, includes/, admin.php, cashu-wallet-php/CashuWallet.php + bip39-english.txt) in the sandbox scratch directory.
2. Run a harness that stubs the WordPress API functions, defines ABSPATH and WP_CONTENT_DIR for siteA.example, requires bootstrap.php and activation.php, runs cashupay_activate_unguarded(), sets setup_complete and inserts store_A with a dummy seed.
3. Run the same harness for siteB.example and print CASHUPAY_DATA_DIR, Config::isSetupComplete(), Config::getAdminPasswordHash(), Auth::isLoggedIn(), Auth::verifyAdminPassword(''), Config::getStore('store_A') and base_url.

**Observed result.** Both sites printed CASHUPAY_DATA_DIR=<hosting>/cashupay-data. For site B: setupComplete=true, adminHash=NULL, isLoggedIn=true, verifyAdminPassword('')=true, Config::getStore('store_A') seed=DUMMY-SEED-A, and base_url changed to https://siteB.example/cashupay. Only one cashupay-data directory exists under <hosting>.

**Priority rationale.** Likelihood: This needs two CashuPay WordPress installs in sibling directories without CASHUPAY_DATA_DIR set, plus an admin of one site who is not trusted with the other and cannot already read its files through plugin or theme editing. The layout is common on multi-domain shared hosting, but the trust split is not. Impact: The other installation's seed phrase is exposed, which is bearer control of its ecash, along with withdrawals, token export and store deletion. Even with a single owner, the two ledgers are silently merged and base_url is overwritten, which breaks the first site's payment and webhook URLs.

**Fix.** Make the WordPress data directory specific to each installation, and never adopt one created by another install. Derive a per-install name from a hash of ABSPATH. Write a marker file (.owner, containing ABSPATH) when the directory is created, and check it on every bootstrap. Keep using a legacy shared cashupay-data only when its marker records this ABSPATH. For upgrades of existing installs whose legacy directory has no marker yet, do not switch directories silently (that would hide the wallet from its rightful owner): adopt and mark the legacy directory only when its stored base_url matches this site's site_url('/cashupay'), and otherwise use the per-install path and show an admin notice explaining that the shared folder belongs to another site. Activation should also stop overwriting an existing base_url that points at a different site. Regression test: two ABSPATHs with the same parent must yield different CASHUPAY_DATA_DIR values, and an existing directory without a matching marker must not be used.

Sketch for `wordpress/bootstrap.php`:

```php
if (!defined('CASHUPAY_DATA_DIR')) {
    $siteKey = substr(hash('sha256', ABSPATH), 0, 12);
    $legacy  = dirname(ABSPATH) . '/cashupay-data';
    $own     = dirname(ABSPATH) . '/cashupay-data-' . $siteKey;
    if (is_dir($legacy) && @file_get_contents($legacy . '/.owner') === ABSPATH) {
        define('CASHUPAY_DATA_DIR', $legacy);   // legacy dir this install created
    } elseif (is_dir($own) || (!is_dir(WP_CONTENT_DIR . '/cashupay-data') && is_writable(dirname(ABSPATH)))) {
        define('CASHUPAY_DATA_DIR', $own);
    } else {
        define('CASHUPAY_DATA_DIR', WP_CONTENT_DIR . '/cashupay-data');
    }
}
// and in cashupay_protect_data_dir(): write ABSPATH to CASHUPAY_DATA_DIR . '/.owner' on creation;
// plus a one-time upgrade step that marks an unmarked legacy dir only when its base_url matches this site.
```
**Confidence.** Every cited line was re-read at 4a82d01. The result was reproduced twice in the sandbox (by the validator and independently by the Phase 5 verifier) with the real bootstrap.php, activation.php, Database, Config and Auth code; only WordPress API functions were stubbed. Not exercised: the full HTTP admin.php reveal_seed request.


### C6. WordPress multisite: every subsite admin controls one shared CashuPay wallet (seeds, backups, withdrawals)

**Severity:** medium (likelihood low, impact high) · **Confidence:** medium  
**Fingerprint:** `wordpress/bootstrap.php:CASHUPAY_DATA_DIR:multisite-no-blog-id-subsite-admin-manage_options`

On a WordPress multisite network the plugin picks its data directory only from values that are the same for the whole network: the wp-config CASHUPAY_DATA_DIR constant, dirname(ABSPATH)/cashupay-data, or WP_CONTENT_DIR/cashupay-data. Nothing mixes in get_current_blog_id(). Its only authorization is current_user_can('manage_options') on the current blog. When CashuPay is loaded on more than one subsite, every one of them opens the same SQLite wallet. That happens when it is network-activated, activated per site by the super admin, or activated by a subsite admin where the network allows it. A plain subsite administrator then passes the CashuPay admin gate and the seed/backup password re-check, which falls back to manage_options because WordPress setup never sets a password. They can reveal the seed, download the backup, melt funds to their own Lightning address, export ecash tokens, and change the mints, webhooks and API keys of the shop that holds the money. Subsite admins are lower-trust principals by WordPress design: they cannot install plugins or use unfiltered_html. The plugin never refuses multisite or network activation. This root cause is distinct from the confirmed sibling-install finding (wordpress/bootstrap.php:CASHUPAY_DATA_DIR:dirname-ABSPATH-shared-across-sites). That finding is about separate WordPress installs whose dirname(ABSPATH) collides. Here there is one install with one ABSPATH, so the per-install WP_CONTENT_DIR fallback and the wp-config constant (the remedy suggested at admin-menu.php:255-256) are shared as well. Keying the path per ABSPATH would fix the sibling case and leave this one open. The gate is also part of this flaw: a per-blog capability guards a network-wide resource.

**Root cause.** wordpress/bootstrap.php:22-28 defines CASHUPAY_DATA_DIR with no blog/site component, and no plugin code detects multisite or network activation (the only is_multisite() uses are notice wording in btcpay-dependency.php). Database::getDataDir (includes/database.php:43-44) uses the constant verbatim. Auth::isLoggedIn (includes/auth.php:59-61) and the verifyAdminPassword fallback (includes/auth.php:132-136) then let the per-blog capability manage_options act as the only gate to a wallet the whole network shares.

**Intended behaviour.** Either give each subsite an isolated wallet namespace (for example cashupay-data/site-<blog_id>, with blog 1 kept at the legacy path), or on multisite refuse network activation and restrict CashuPay admin/setup and the seed/backup re-check to is_super_admin(). An administrator of one subsite must never reach another subsite's or the network owner's seed and funds.

**Source trace.**

1. entrypoint — `wordpress/rewrite-rules.php:108` (cashupay_handle_request): A subsite admin opens https://subsite.example/cashupay-admin (or ?cashupay_admin=1; the rewrite rules are flushed per blog through a per-blog option at lines 46-56). The only check is current_user_can('manage_options') at line 109, which is true for their own blog.
2. propagation — `wordpress/bootstrap.php:23` (CASHUPAY_DATA_DIR selection): The data dir is dirname(ABSPATH)/cashupay-data (line 25), WP_CONTENT_DIR/cashupay-data (line 27), or a constant set in wp-config.php. All three are identical for every blog in the network. No blog ID is involved.
3. propagation — `includes/database.php:43` (Database::getDataDir): Uses CASHUPAY_DATA_DIR verbatim (rtrim only), so every subsite opens the same cashupay.sqlite with the same stores, seeds, proofs and API keys.
4. propagation — `includes/auth.php:59` (Auth::isLoggedIn): In WordPress mode this returns current_user_can('manage_options'), with no super-admin or blog binding. admin.php:783 uses it as the gate for all POST actions.
5. propagation — `includes/auth.php:132` (Auth::verifyAdminPassword): WordPress setup skips the password step (setup.php:214, step = 4), so the hash is null and the seed/backup re-check also comes down to manage_options (line 135-136).
6. sink — `admin.php:846` (reveal_seed / download_backup / manual_melt / export_token): reveal_seed (846), download_backup (879), manual_melt (1756) and export_token (1987) all run against the shared wallet, so the subsite admin gets the seed and can move the funds.

**Evidence.**

- `wordpress/bootstrap.php:24` — The branch condition uses only dirname(ABSPATH) and WP_CONTENT_DIR, which are network-wide on multisite. No get_current_blog_id().
- `includes/auth.php:61` — The WordPress gate is current_user_can('manage_options'), a per-blog capability that every subsite administrator holds.
- `includes/auth.php:136` — verifyAdminPassword falls back to manage_options when no password hash exists, which is the WordPress-mode default.
- `setup.php:214` — In WordPress mode setup jumps from step 1 to step 4 and never sets an admin password.
- `wordpress/rewrite-rules.php:117` — The setup wizard route is gated only by manage_options as well.
- `wordpress/cashupay.php:27` — The plugin header (lines 2-11) has no 'Network:' declaration. The activation hook ignores $network_wide, and nothing refuses multisite.
- `wordpress/admin-menu.php:256` — The suggested remedy is a wp-config.php define. On multisite that file is shared by every subsite, so it cannot separate them.
- `admin.php:879` — The download_backup password check goes through verifyAdminPassword, which a subsite admin passes.

**Conditions.**

- *system_configuration*: WordPress multisite with CashuPay loaded on at least two subsites: network-activated, activated per site on several sites, or activated by a subsite admin where the network enables the Plugins menu for site admins.
- *authorization_role*: The attacker is an administrator (manage_options) of a subsite where CashuPay is loaded, not a super admin.
- *data_state*: Another site's shop (typically the main site) holds funds or a seed in the shared wallet.

**Bounded local reproduction** (attacker: Administrator of one subsite of a multisite network, with no super-admin rights.).

Input:

```
sbx.sh v-multisite -- php -d opcache.enable_cli=0 h.php 1 ; ... h.php 2 ; ... h.php 3 ; ... h.php 1 wpconfig ; ... h.php 2 wpconfig  (h.php defines one ABSPATH/WP_CONTENT_DIR, stubs is_multisite()=true, get_current_blog_id()=argv[1], current_user_can('manage_options')=true, is_super_admin()=false, requires a byte-identical copy of wordpress/bootstrap.php and the repo's includes/auth.php)
```
Steps:

1. Copy wordpress/bootstrap.php unchanged into a scratch plugin directory whose includes/urls.php requires the repo's includes/urls.php. Confirm the sha256 matches.
2. In the no-network, scratch-write-only sandbox, run the harness once for each simulated blog ID (1, 2, 3), then for blogs 1 and 2 with a network-wide wp-config CASHUPAY_DATA_DIR.
3. Compare the printed CASHUPAY_DATA_DIR and Auth::isLoggedIn() across blog IDs.

**Observed result.** Blogs 1, 2 and 3 all printed CASHUPAY_DATA_DIR=<scratch>/wproot/cashupay-data isLoggedIn=true. With the wp-config constant, blogs 1 and 2 both printed <scratch>/wproot/cashupay-data-wpconfig isLoggedIn=true. Each simulated non-super-admin subsite admin resolved to the same wallet directory and passed the CashuPay admin gate. Evidence: agents/v-multisite/artifacts/evidence/multisite-verify.txt

**Priority rationale.** Likelihood: Requires a multisite network with CashuPay loaded on more than one subsite and a malicious or compromised subsite administrator. Multisite shops are a minority, but networks that hand subsite admin rights to third parties are the main reason multisite exists. Impact: Full operator control over another site's real funds: seed disclosure, backup download, melting or exporting all proofs, and repointing mints and webhooks.

**Fix.** Short term: on multisite, gate Auth::isLoggedIn(), the verifyAdminPassword fallback and the cashupay-admin/cashupay-setup routes on is_super_admin(), and refuse network activation and activation on a second subsite. Long term: namespace the data dir per blog on multisite. Blog 1 keeps the legacy path, and a wp-config base gets a site-<id> suffix appended rather than being shared. Regression test: under is_multisite() with blog_id 1 and 2, the resolved data dir must differ, and a non-super-admin of blog 2 must fail Auth::isLoggedIn().

Sketch for `wordpress/bootstrap.php`:

```php
$cashupay_blog = (function_exists('is_multisite') && is_multisite()) ? (int) get_current_blog_id() : 1;
if (!defined('CASHUPAY_DATA_DIR')) {
    $above_abspath = dirname(ABSPATH) . '/cashupay-data';
    $base = (is_dir($above_abspath) || (!is_dir(WP_CONTENT_DIR . '/cashupay-data') && is_writable(dirname(ABSPATH))))
        ? $above_abspath : WP_CONTENT_DIR . '/cashupay-data';
    define('CASHUPAY_DATA_DIR', $cashupay_blog > 1 ? $base . '/site-' . $cashupay_blog : $base);
}
// If wp-config defines CASHUPAY_DATA_DIR on multisite, Database::getDataDir() must append '/site-' . get_current_blog_id() for blogs > 1 (or refuse to run on them).
```
Sketch for `includes/auth.php`:

```php
if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
    if (function_exists('is_multisite') && is_multisite() && !is_super_admin()) {
        return false; // the wallet is network-wide until per-site isolation exists
    }
    return function_exists('current_user_can') && current_user_can('manage_options');
}
// apply the same is_super_admin() condition in the verifyAdminPassword() WordPress fallback
```
**Confidence.** The plugin side is complete in source. I reproduced it independently in the sandbox with the unmodified bootstrap.php (sha256 matches) and the repo's Auth class. The WordPress-core facts were stubbed, not run on a real multisite: one ABSPATH/WP_CONTENT_DIR/wp-config per network, manage_options granted per blog to site admins, and network activation loading the plugin on every blog. These are stable, documented core behaviour.


### C7. admin test_mint action fetches any http(s) URL, including loopback and private addresses, and returns the response JSON or the cURL error

**Severity:** low (likelihood low, impact low) · **Confidence:** high  
**Fingerprint:** `admin.php:test_mint:testMintConnection-without-assertSafeMintUrl`

The SPB-SSRF-001 fix sends operator-supplied mint URLs through MintHelpers::assertSafeMintUrl before the server fetches them. The POST action=test_mint handler in admin.php skips that check: it passes $_POST['mint_url'] straight to Config::testMintConnection, and that function only checks the scheme and format with Security::sanitizeUrl. With an authenticated operator session and a CSRF token, the server will GET <url>/v1/info on any loopback or LAN host and port. On a JSON response the decoded body comes back in 'info'. Otherwise the raw cURL or MintClient error text comes back in 'error', which tells an open port from a closed one. The handler also ignores the allow_private_mints opt-in that the policy is built around. No admin UI calls this action, but the handler can be reached directly.

**Root cause.** Config::testMintConnection (includes/config.php:608-621) checks only Security::sanitizeUrl (line 612) before MintClient->get('info') (line 617). The admin.php test_mint case (2503-2510) calls it with no MintHelpers::assertSafeMintUrl. The other server-side mint fetches do check first: admin.php:1185 (create_store, before 1202), 1317-1318, 2404-2407, and setup.php:496 through MintHelpers. The comment at config.php:609-610 says testMintConnection is 'the single point every mint-URL entry path flows through', but it does not apply the private-address policy itself.

**Intended behaviour.** Every server-side fetch of an operator-supplied mint URL should refuse hosts that resolve to non-public addresses unless allow_private_mints or CASHUPAY_ALLOW_PRIVATE_MINTS is set. Connection failures should return a generic error that does not reveal internal port state.

**Source trace.**

1. entrypoint — `admin.php:2505` (POST action=test_mint, after Auth::isLoggedIn (783) and CSRF validation (790-791)): Reads $_POST['mint_url']. The only check is that it is not empty.
2. propagation — `admin.php:2509` (case 'test_mint'): Calls Config::testMintConnection($mintUrl) with no MintHelpers::assertSafeMintUrl. The result is echoed as JSON at 2510.
3. propagation — `includes/config.php:612` (Config::testMintConnection): Security::sanitizeUrl checks only FILTER_VALIDATE_URL and an http/https scheme. Host and IP are not checked.
4. sink — `includes/config.php:617` (Config::testMintConnection): MintClient->get('info') sends GET <url>/v1/info to the supplied host and port. Line 618 returns the decoded body as 'info'. Line 620 returns the exception message, which includes the raw cURL error.

**Evidence.**

- `includes/mint_helpers.php:49` — In the same run, with allow_private_mints unset, assertSafeMintUrl('http://127.0.0.1:18090') threw 'That address points to a private or local network, not a public mint.' Artifact: agents/v-testmint/artifacts/evidence/testmint_ssrf.txt
- `includes/config.php:618` — Config::testMintConnection('http://127.0.0.1:18090') returned {success:true, info:{name:VTM-INTERNAL, marker:vtm-dummy-42}}. The loopback listener logged 'GET /v1/info Host=127.0.0.1:18090'. Artifact: agents/v-testmint/artifacts/evidence/testmint_ssrf.txt
- `includes/config.php:620` — For closed port 127.0.0.1:18091 it returned error 'HTTP request failed: Failed to connect to 127.0.0.1:18091 after 0 ms: Could not connect to server', which differs from the open-port result. Artifact: agents/v-testmint/artifacts/evidence/testmint_ssrf.txt
- `admin.php:1185` — Comparison: create_store runs assertSafeMintUrl before its testMintConnection call at 1202, as do 1317-1318 and 2404-2407. Only test_mint (2503-2509) skips it. A grep of PHP and JS finds no UI caller of action=test_mint.

**Conditions.**

- *authentication_level*: Requires an authenticated admin session (or manage_options in WordPress mode) and a valid CSRF token.
- *system_configuration*: Only matters when allow_private_mints and CASHUPAY_ALLOW_PRIVATE_MINTS are off, and when the operator is not the network owner (for example shared hosting, or a managed VPS or LAN with internal HTTP services or metadata endpoints).

**Bounded local reproduction** (attacker: An operator with an admin session who wants the server to read from, or map, internal HTTP services that the mint-URL policy is meant to block.).

Input:

```
POST admin.php action=test_mint&mint_url=http://127.0.0.1:18090&csrf_token=<token> (reproduced at function level as Config::testMintConnection('http://127.0.0.1:18090'), with control MintHelpers::assertSafeMintUrl on the same URL)
```
```
mint_url=http://127.0.0.1:18091 (closed port)
```
Steps:

1. In the sandbox (sbx.sh v-testmint --loopback), start php -S 127.0.0.1:18090 listener/mint.php, which serves dummy JSON and logs each request.
2. Run poc.php. It defines CASHUPAY_DATA_DIR in scratch, runs Database::initialize(), then calls MintHelpers::assertSafeMintUrl and Config::testMintConnection, the function admin.php:2509 calls, on 127.0.0.1:18090 and on closed port 18091.
3. Compare the refusal from assertSafeMintUrl with the testMintConnection results and the listener log.

**Observed result.** assertSafeMintUrl refused both URLs as private or local. testMintConnection fetched http://127.0.0.1:18090/v1/info, and the listener logged GET /v1/info. It returned success with the listener's full JSON in 'info'. For the closed port it returned the raw cURL 'Failed to connect ... Could not connect to server' error.

**Priority rationale.** Likelihood: Needs an authenticated operator session and a CSRF token. No UI calls the action, so it must be invoked deliberately. Impact: The operator can read JSON from internal <host>/v1/info endpoints and use error text to map open and closed ports from the server's network position. This bypasses the server-network policy the code applies to operators, but it does not touch funds, other principals, or anything outside GET /v1/info. In the single-operator design the operator usually owns the host.

**Fix.** Enforce the policy inside Config::testMintConnection by calling MintHelpers::assertSafeMintUrl there, so no caller can skip it, or at minimum add the call in the admin.php test_mint case. Return a generic connection error instead of the cURL text. Add a regression test that testMintConnection('http://127.0.0.1:1') fails with the private-network message when allow_private_mints is off.

Sketch for `includes/config.php`:

```php
public static function testMintConnection(string $mintUrl): array {
    require_once __DIR__ . '/security.php';
    require_once __DIR__ . '/mint_helpers.php';
    try {
        $mintUrl = MintHelpers::assertSafeMintUrl($mintUrl);
    } catch (\InvalidArgumentException $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
    }
    try {
        $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
        $info = $client->get('info');
        return ['success' => true, 'error' => null, 'info' => $info];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Could not reach the mint', 'info' => null];
    }
}
```
**Confidence.** The full source trace was re-read. The behaviour was reproduced independently in the sandbox with the real functions against a loopback listener, using the same-run assertSafeMintUrl refusal as a control.


### C8. Pairing-page login redirects to the raw REQUEST_URI, giving an off-site redirect via a '//host' path under router.php

**Severity:** low (likelihood low, impact low) · **Confidence:** high  
**Fingerprint:** `api-keys/authorize.php:login-success:location-request-uri`

When the operator logs in successfully on the API-key pairing page, api-keys/authorize.php sends 'Location: ' . $_SERVER['REQUEST_URI'] without checking it. Under the php -S router deployment, router.php picks the route with parse_url(REQUEST_URI, PHP_URL_PATH). parse_url reads a leading '//evil.example' as an authority and returns '/api-keys/authorize', so a link such as https://shop//evil.example/api-keys/authorize?... shows the real pairing and login page on the merchant's origin. The login form has no action attribute, so it posts back to that same URI. Once the password is accepted, the browser is sent to the network-path reference //evil.example/..., an attacker page that can pose as the next step (for example 'session expired, enter password again') to phish the admin password. The attacker gets no session or API key directly. The shipped Apache .htaccess (RewriteRule ^api-keys/authorize$) and nginx slash merging were not shown to route a '//host' path to this page.

**Root cause.** api-keys/authorize.php:140 uses the unvalidated request URI as a redirect target. router.php:55 routes on parse_url() of the raw REQUEST_URI, so a request path that begins with an authority-like '//host' prefix still reaches that redirect.

**Intended behaviour.** After login, redirect only to a same-origin target: a root-relative self-reference (single leading slash) to the pairing path plus the original query string, never a value that a browser can read as a network-path reference.

**Source trace.**

1. entrypoint — `router.php:55` (cli-server routing): $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) on '//evil.example/api-keys/authorize?...' yields '/api-keys/authorize'
2. propagation — `router.php:79` (api-keys route): '#^/api-keys/authorize$#' matches and authorize.php is required (line 80) with REQUEST_URI unchanged
3. propagation — `api-keys/authorize.php:137` (POST action=login): Auth::attemptLogin accepts the operator's password; the CSRF token comes from the same rendered page
4. sink — `api-keys/authorize.php:140` (login success): header('Location: ' . $_SERVER['REQUEST_URI']) emits 'Location: //evil.example/api-keys/authorize?...'

**Evidence.**

- `api-keys/authorize.php:140` — Raw REQUEST_URI used as the Location target after a successful login
- `router.php:55` — cli-server routing uses parse_url path of the raw REQUEST_URI, which drops a '//host' prefix
- `api-keys/authorize.php:584` — The login form has no action attribute, so it posts to the URI the attacker shaped
- `api-keys/authorize.php:252` — The sibling deny redirect is validated with Security::isSafePublicHttpUrl, which shows the intended standard
- `.htaccess:8` — The Apache rewrite is anchored (^api-keys/authorize$), so the shipped Apache config does not route the '//host' form; exposure is limited to router.php-style deployments

**Conditions.**

- *system_configuration*: router.php resolves the route from the raw REQUEST_URI: the php -S router mode documented in README.md:284/406, or any catch-all setup that hands an unnormalized request path to router.php. The shipped Apache .htaccess does not route the '//host' form; nginx slash merging was not tested.
- *user_interaction*: The operator opens an attacker-supplied pairing link (the normal BTCPay plugin pairing flow) while not logged in, and logs in on it
- *authentication_level*: The attacker is unauthenticated

**Bounded local reproduction** (attacker: Unauthenticated third party who sends the operator a crafted pairing link).

Input:

```
GET //evil.example/api-keys/authorize?applicationName=Shop&permissions=btcpay.store.canviewinvoices
```
```
POST same URI: action=login&password=<operator password>&csrf_token=<from page>
```
Steps:

1. Create a dummy installation (setup_complete=true, dummy admin password) with CASHUPAY_DATA_DIR in scratch
2. Start php -S 127.0.0.1:18791 -t <repo> with a wrapper that defines CASHUPAY_DATA_DIR and returns require router.php, inside the loopback-only sandbox
3. Run curl --path-as-is GET on the '//evil.example/api-keys/authorize?...' URL, keep the cookie jar and extract csrf_token
4. Run curl --path-as-is POST action=login with a wrong password, then with the dummy password, to the same URL and read the response headers; repeat with the normal '/api-keys/authorize' path as a control

**Observed result.** GET returned 200 with the real login form (<form method="POST"> with no action, CSRF token present). The wrong password gave 200 with no redirect. The correct dummy password gave 'HTTP/1.1 302 Found' and 'Location: //evil.example/api-keys/authorize?applicationName=Shop&permissions=btcpay.store.canviewinvoices', and the server log shows 'Admin login successful'. The control path redirected to '/api-keys/authorize?...'.

**Priority rationale.** Likelihood: Requires a router.php-style deployment that routes the raw '//host' path (php -S; the shipped Apache rules do not), and the operator logging in through a crafted link Impact: An open redirect to an attacker origin right after a genuine password prompt. It makes phishing of the admin password more convincing, but it leaks no credential, session or API key by itself.

**Fix.** Never redirect to the raw request URI. Rebuild a root-relative self-reference from the parsed request path, strip every leading '/' and '\' and re-prefix exactly one '/', then append the query string, so the target can never be a network-path reference. Do not switch to Urls::pairing(): it depends on base_url or the HTTP_HOST fallback and on url_mode, and its '/router.php/...' form does not match the cli-server route. Optionally make router.php reject request paths that begin with '//' or '/\'.

Sketch for `api-keys/authorize.php`:

```php
if ($result === 'ok') {
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $path = '/' . ltrim($path, "/\\");
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $path . ($qs !== '' ? '?' . $qs : ''));
    exit;
}
```
**Confidence.** The source trace is complete, and an independent sandboxed loopback reproduction shows the exact Location header, with a normal-path control


### C9. A numeric-IPv4 Host header bypasses the setup self-probe's SERVER_ADDR pin, giving an unauthenticated blind SSRF and port-scan oracle

**Severity:** low (likelihood medium, impact low) · **Confidence:** high  
**Fingerprint:** `setup.php:setupProbeDataExposure:connect_to-raw-host-vs-curl-normalized-ipv4`

On an unclaimed install whose data dir is inside DOCUMENT_ROOT, any visitor who GETs setup.php makes the server run setupProbeDataExposure(). The probe builds its URL from the visitor's Host header and relies on CURLOPT_CONNECT_TO to pin the connection to SERVER_ADDR:SERVER_PORT. The CONNECT_TO entry is keyed on the raw Host hostname. libcurl matches against its own canonicalized URL host, and it rewrites numeric IPv4 forms (decimal 2130706433, hex 0x7f000001, short forms 127.1 and 0x7f.1, octal 0177.0.0.1, trailing dot) into dotted-quad. So the entry does not match, and curl connects directly to the IPv4:port the attacker encoded. The server then sends three ranged GETs (<dataPath>/cashupay.sqlite, -wal, -shm, Range bytes=0-15) to that address. The step-1 badge (FAIL/OK/WARN) tells the attacker whether the target answered all three requests, returned a non-HTML 2xx body, or did not answer.

**Root cause.** setup.php:537-538 builds the CONNECT_TO match key from the unnormalized Host hostname ($hm[1]). libcurl compares CONNECT_TO entries against its canonicalized URL host, and an entry that does not match falls back silently to a normal connection to the URL host. After curl_exec nothing checks CURLINFO_PRIMARY_IP/PORT against SERVER_ADDR/SERVER_PORT, and the Host regex at :529 accepts non-canonical numeric hostnames.

**Intended behaviour.** As the doc comment at setup.php:508-509 says, the Host header only names the virtual host. Every probe connection must go to this server's own SERVER_ADDR:SERVER_PORT, whatever form the Host value takes.

**Source trace.**

1. entrypoint — `setup.php:1077` (GET setup.php step-1 render (unclaimed install)): An unauthenticated GET calls setupProbeDataExposure() when !$setupTokenRequired || !$claimedElsewhere, which holds on a fresh unclaimed install.
2. propagation — `setup.php:524` (setupProbeDataExposure): $host comes from $_SERVER['HTTP_HOST']. The regex at :529 ([A-Za-z0-9.-]+ with an optional :port) accepts 2130706433, 0x7f000001, 127.1, 0177.0.0.1 and 127.0.0.1.
3. propagation — `setup.php:537` (setupProbeDataExposure): $connectTo = $hostName:$hostPort:SERVER_ADDR:SERVER_PORT, keyed on the raw, uncanonicalized hostname.
4. propagation — `setup.php:549` (setupProbeDataExposure curl options): CURLOPT_URL is scheme://$host<dataPath>/<file>. libcurl canonicalizes the numeric IPv4 host, so the CONNECT_TO entry at :550 does not match.
5. sink — `setup.php:563` (setupProbeDataExposure curl_exec): The ranged GET goes to the attacker-encoded IPv4:port instead of SERVER_ADDR:SERVER_PORT. The per-file HTTP code and body sniffing (:564-581) decide the returned status.

**Evidence.**

- `setup.php:508` — The doc comment claims the connection is pinned to SERVER_ADDR, so the Host header 'only names the virtual host, never a destination'.
- `setup.php:529` — The Host validation regex allows non-canonical numeric IPv4 hostnames.
- `setup.php:537` — The CONNECT_TO entry is built from the raw Host hostname.
- `setup.php:550` — CURLOPT_CONNECT_TO is the only pin. There is no CURLOPT_RESOLVE and no CURLINFO_PRIMARY_IP/PRIMARY_PORT check after curl_exec at :563.
- `setup.php:1080` — $probeSettled (protected/outside) drives the OK badge at :1138 and the pre-checked acknowledgement at :1310, so the answer becomes a response oracle.
- `setup.php:1138` — The step-1 badge class FAIL/OK/WARN reflects exposed / all three answered / no answer and is returned to the unauthenticated visitor (moved from trace by parent: structural ordering fix).

**Conditions.**

- *data_state*: Fresh install whose setup is not claimed yet (or the attacker holds the claim). The data dir is inside DOCUMENT_ROOT (getDataDirHttpPath non-null), as in the default standalone layout.
- *network_routing*: Requests with an arbitrary Host header reach the CashuPayServer vhost (default vhost, direct IP access or a catch-all server_name). The probe is skipped under php -S (cli-server).
- *environmental_dependency*: libcurl canonicalizes numeric IPv4 URL hosts before CONNECT_TO matching. Observed locally on libcurl 8.23.0. The production server runs libcurl 8.12.1, which was not tested.
- *authentication_level*: No authentication. Rate limit is 10 probes per minute per Security::getClientIp, with 3 requests per probe.

**Bounded local reproduction** (attacker: Unauthenticated internet visitor of a freshly uploaded installation that has not been claimed yet.).

Input:

```
GET /setup.php HTTP/1.1
Host: 2130706433:18092


```
```
Host values verified to escape the pin against the real setup.php: 2130706433:18092, 127.1:18092, 0x7f.1:18092 (control 127.0.0.1:18092 stayed pinned)
```
Steps:

1. Inside the sandbox (--loopback), start a logging HTTP server B on 127.0.0.1:18092 that answers 404. Nothing listens on the pinned SERVER_ADDR:SERVER_PORT 127.0.0.1:18091.
2. From the CLI, include the real setup.php with CASHUPAY_DATA_DIR=<scratch>/webroot/data, DOCUMENT_ROOT=<scratch>/webroot, REQUEST_METHOD=GET, SERVER_ADDR=127.0.0.1 and SERVER_PORT=18091. Set HTTP_HOST to 127.0.0.1:18092 (control), then to 2130706433:18092, 127.1:18092 and 0x7f.1:18092, with a fresh data dir for each run.
3. Read the step-1 status badge and B's hit log after each run.

**Observed result.** Control Host 127.0.0.1:18092 gave badge WARN and no hits on B, so the pin held. With Host 2130706433:18092, 127.1:18092 and 0x7f.1:18092, B logged 'GET /data/cashupay.sqlite', '-wal' and '-shm' each time, each with Range=bytes=0-15 and Host=127.0.0.1:18092, and the page showed badge OK (curl 8.23.0, PHP 8.5.11). Evidence: agents/v-probe/artifacts/evidence/setup-probe-pin-bypass-repro.txt.

**Priority rationale.** Likelihood: No authentication and a single header are enough, but only during the pre-setup window, only where arbitrary Host values reach the app, and at most 10 probes per minute per client IP. Impact: The SSRF is blind: a GET with a fixed path suffix, a 16-byte range and no response body returned. The attacker learns a ternary answered / non-HTML-2xx / no-answer oracle per chosen IPv4:port, which allows internal service discovery and possibly hits link-local metadata endpoints with fixed paths.

**Fix.** Make the pin independent of the Host string. Use a wildcard CONNECT_TO entry ('::ADDR:PORT' matches any host and port), and after each exec require CURLINFO_PRIMARY_IP and CURLINFO_PRIMARY_PORT to equal SERVER_ADDR and SERVER_PORT, otherwise count the file as unchecked. Also reject Host hostnames that are numeric but not canonical dotted-quad. Regression test: HTTP_HOST=2130706433:<other port> must produce zero connections to any port other than SERVER_PORT.

Sketch for `setup.php`:

```php
$connectTo = '::' . (str_contains($serverAddr, ':') ? '[' . $serverAddr . ']' : $serverAddr) . ':' . $serverPort;
// ... after curl_exec($ch):
if (curl_getinfo($ch, CURLINFO_PRIMARY_IP) !== $serverAddr || (int)curl_getinfo($ch, CURLINFO_PRIMARY_PORT) !== $serverPort) {
    continue; // not our own server: this file was not checked
}
```
**Confidence.** Reproduced independently against the real setup.php in the sandbox with three numeric host forms and a canonical-host control. The only untested point is whether the production libcurl version behaves the same.

---

# Appendix B — Needs validation: full detail

Evidence references of the form `agents/<id>/artifacts/...` resolve under `~/security-audit-skill/cashupayserver/run-3/` (local audit output, not part of this repository). Each holds the exact command, input and output of a sandboxed run.


Source-grounded leads whose decisive fact lies outside the repository or the sandbox. They have **no severity** and are not confirmed vulnerabilities. Each one names the missing fact and a safe way to settle it.

### N1. LNURL-pay callback chosen by the remote Lightning-address server is fetched with no address policy, so it can target loopback, LAN or link-local HTTPS endpoints

**Fingerprint:** `cashu-wallet-php/CashuWallet.php:LightningAddress::isSafeCallbackUrl:no-address-check` · no severity (not a confirmed vulnerability)

An operator can send a manual withdrawal (admin.php manual withdrawal or the bolt11/address handler at admin.php:1683), or auto-melt can pay out (includes/lightning_address.php:80). Either way, Cashu\LightningAddress::getInvoice fetches https://<domain>/.well-known/lnurlp/<user>. It then GETs whatever 'callback' URL that third-party server returned, with the server's chosen host, port, path and query plus &amount=<msat>. isSafeCallbackUrl and fetchJson enforce only the scheme (https, or http for .onion hosts) and reject userinfo. Neither applies Security::resolvePublicTarget or isSafePublicHttpUrl, and neither pins the address with CURLOPT_RESOLVE, unlike the webhook path. So https://127.0.0.1:<port>/..., https://10.x/..., https://169.254.169.254/..., https://[::1]/ and internal DNS names are all connected to. isValid also accepts IPv4-literal domains such as user@127.0.0.1 and user@10.0.0.5, but not user@localhost. That makes the first hop to port 443 internal as well. Controls that limit the reach: redirects are off; protocols are limited to http/https; TLS verification is the cURL default (on); the body is capped at 256 KiB. The response is not sent back to the remote LNURL server. Only the cURL error, the HTTP code or the JSON 'reason'/'message' of the callback hop appear in the error text shown to the operator. Errors on the well-known hop are swallowed by resolve(). Locally verified: fetchJson opened a TCP connection to 127.0.0.1:18443 and sent a TLS ClientHello for the exact callback URL that getInvoice builds.

**Claimed root cause.** LightningAddress::isSafeCallbackUrl (cashu-wallet-php/CashuWallet.php:10076-10087) applies a scheme-only policy. LightningAddress::fetchJson (10101-10127) calls curl_init($url) and curl_exec with no resolve-and-pin public-address check (no Security::resolvePublicTarget plus CURLOPT_RESOLVE as used by includes/webhook_sender.php:210). LightningAddress::isValid (9948-9951) also accepts IP-literal domains for the well-known fetch.

**Source trace.**

1. entrypoint — `admin.php:1826` (manual withdrawal to Lightning address (operator session + CSRF)): The operator-entered destination address goes to LightningAddress::getInvoice. The lower-trust party is the remote LNURL server behind that address. Auto-melt reaches the same code via includes/lightning_address.php:80, and another admin handler via admin.php:1683.
2. propagation — `cashu-wallet-php/CashuWallet.php:9977` (LightningAddress::resolve): Builds https://{domain}/.well-known/lnurlp/{user} from an address that isValid accepted, IPv4-literal domains included.
3. propagation — `cashu-wallet-php/CashuWallet.php:9990` (LightningAddress::resolve): The callback supplied by the remote server is accepted when isSafeCallbackUrl passes, and that check is scheme-only.
4. propagation — `cashu-wallet-php/CashuWallet.php:10054` (LightningAddress::getInvoice): fetchJson($callbackUrl) with the remote server's chosen path/query plus amount (and optional comment).
5. sink — `cashu-wallet-php/CashuWallet.php:10127` (LightningAddress::fetchJson): curl_exec on curl_init($url) (line 10109) with no address check or CURLOPT_RESOLVE pin.

**Evidence.**

- `cashu-wallet-php/CashuWallet.php:10087` — Independent local run (agents/v-lnurl/artifacts/evidence/lnurl_callback_loopback.txt). isSafeCallbackUrl returned true for https://127.0.0.1:18443/internal?x=1, https://169.254.169.254/latest/meta-data/, https://10.0.0.5/admin, https://[::1]/ and https://localhost/, and false for http://127.0.0.1/.
- `cashu-wallet-php/CashuWallet.php:10127` — Same run. Invoking fetchJson with 'https://127.0.0.1:18443/internal?x=1&amount=1000' made the server connect to a loopback listener, which received a TLS ClientHello (first bytes 160301...). The call then failed with 'LNURL request failed: TLS connect error', the text getInvoice would show the operator.
- `cashu-wallet-php/CashuWallet.php:9950` — isValid('user@127.0.0.1') and isValid('user@10.0.0.5') are true and isValid('user@localhost') is false, so the well-known fetch can go to an internal IPv4 address on port 443.
- `includes/webhook_sender.php:210` — Comparable in-repo control: webhooks call Security::resolvePublicTarget and pin the resolved addresses. The LNURL path has no equivalent.

**Blockers (what source and local checks cannot settle).**

- Meaningful impact depends on the deployment and is not visible in source. TLS verification is on and redirects are off, so a request completes only against an internal HTTPS endpoint that presents a certificate valid for the callback host and trusted by the server's CA store (for example an internal DNS name with a public or corporate-CA certificate, or a TLS service on loopback/LAN). Whether such an endpoint exists on the server's network is unknown.
- The request is a blind GET from the remote attacker's point of view. Response content and error text reach only the operator who started the withdrawal, so the attacker gets no read-back and no port-scan oracle beyond timing. The attacker must also be the LNURL server (or control the domain) of an address the operator chose to pay.

**Bounded local next step.** Done for the connection step: sbx.sh v-lnurl --loopback -- bash ./run.sh showed fetchJson connecting to 127.0.0.1:18443 for a callback URL (see agents/v-lnurl/artifacts/evidence/lnurl_callback_loopback.txt). To show a full internal GET: in a harness copy, start a loopback HTTPS server with a certificate for a test name, add CURLOPT_CAINFO for that CA and CURLOPT_RESOLVE testname:port:127.0.0.1, serve a fake well-known JSON with callback https://testname:port/internal?x=1, call LightningAddress::getInvoice and record the request line. Regression test: isSafeCallbackUrl, and the well-known URL, must pass Security::resolvePublicTarget, and fetchJson must pin the returned 'resolve' entries with CURLOPT_RESOLVE. Exempt .onion hosts reached through a proxy if supported.

**Owner-observed deployment check (no audit traffic to live systems).** The owner lists the HTTPS services reachable from the server on loopback, LAN or link-local that present certificates trusted by the system CA store (including any TLS-fronted metadata or admin endpoints and internal names with public certificates), and checks whether any of them perform state changes on GET. This is read-only inventory; no requests to them are needed.

### N2. Mint URLs are vetted once with a DNS lookup, but MintClient connects without pinning the vetted addresses (DNS rebinding to private targets)

**Fingerprint:** `cashu-wallet-php/CashuWallet.php:MintClient::request:unpinned-connection-after-assertSafeMintUrl` · no severity (not a confirmed vulnerability)

MintHelpers::assertSafeMintUrl resolves the mint host through Security::resolveTarget and refuses non-public addresses. It then returns only the canonical URL. The 'resolve' pin list that resolveTarget builds is thrown away. MintClient::request sets CURLOPT_URL with no CURLOPT_RESOLVE or CURLOPT_CONNECT_TO and never checks CURLINFO_PRIMARY_IP, so cURL resolves the name again on its own. This happens right after the check (setup step 5 Wallet::getSupportedUnits, admin save paths) and on every later wallet operation built from the stored mint_url (for example Invoice checkstate at includes/invoice.php:1683, plus keys, quotes and swaps from cron and payment polling), none of which re-validate. A mint whose operator controls its DNS can answer the check with a public address and later answers with 127.0.0.1, 169.254.169.254 or a LAN address. The server then sends GET/POST <mint path>/v1/<endpoint> requests with JSON bodies to that internal address. In setup, unit names parsed from the response are shown, and cURL error text is shown. Reach is limited. Paths are fixed to /v1/*. Redirects are not followed. With https mint URLs, VERIFYPEER and VERIFYHOST (both on) block any internal service that has no valid certificate for the mint name, so the practical case needs an http:// mint URL. Correction to the hunter's evidence: the 'l%6fcalhost' run did not show a real parser differential. It worked only because a resolver seam was set to answer 93.184.216.34 for that literal. With real DNS, assertSafeMintUrl fails closed on it ('could not be found'). That run, like this verifier's own run, is a model of divergent check-time and connect-time answers.

**Claimed root cause.** Check and connection use separate DNS lookups. MintHelpers::assertSafeMintUrl (includes/mint_helpers.php:37-55) vets Security::resolveTarget()['ips'] and returns only the canonical string, dropping the 'resolve' pin entries. MintClient::request (cashu-wallet-php/CashuWallet.php:3725-3761) has no address pinning and no connect-time address check, unlike WebhookSender (includes/webhook_sender.php:314, CURLOPT_RESOLVE). Later MintClient instances built from stored mint_url get no check at all.

**Source trace.**

1. entrypoint — `setup.php:291` (setup step 5): mint_url from the setup form (pre-setup visitor or operator, CSRF/claim-bound) goes through setupCheckMintUrl -> MintHelpers::assertSafeMintUrl.
2. propagation — `includes/mint_helpers.php:37` (MintHelpers::assertSafeMintUrl): Security::resolveTarget resolves the host and the IPs are checked with isPublicIp. Line 55 returns only $canonical, so resolveTarget's 'resolve' pin list is discarded.
3. propagation — `setup.php:296` (setup step 5): Wallet::getSupportedUnits($mintUrl) (CashuWallet.php:5807-5810) creates a new MintClient and GETs 'keysets'.
4. propagation — `cashu-wallet-php/CashuWallet.php:3733` (MintClient::request): CURLOPT_URL => $url, with no CURLOPT_RESOLVE or CURLOPT_CONNECT_TO and no CURLINFO_PRIMARY_IP check.
5. sink — `cashu-wallet-php/CashuWallet.php:3761` (MintClient::request): curl_exec does its own DNS resolution and connects to whatever address the name resolves to at that moment.

**Evidence.**

- `includes/mint_helpers.php:55` — assertSafeMintUrl returns only the canonical URL. The vetted addresses and resolveTarget's 'resolve' entries (includes/security.php:256-292) are not passed on.
- `cashu-wallet-php/CashuWallet.php:3733` — The MintClient cURL option set has no pinning. A grep for CURLOPT_RESOLVE/CONNECT_TO/PRIMARY_IP finds pinning only in webhook_sender.php and setup.php's self-probe, never in MintClient.
- `cashu-wallet-php/CashuWallet.php:3761` — Local sandboxed run (loopback only, CASHUPAY_DATA_DIR in scratch). With real DNS, assertSafeMintUrl refuses http://localhost:18090 (private) and http://l%6fcalhost:18090 (unresolvable). Security::setResolverForTesting then answered the check-time lookup with 93.184.216.34 (1 seam call). Wallet::getSupportedUnits fetched GET /v1/keysets from the 127.0.0.1:18090 loopback listener (Host=localhost:18090) through cURL's own lookup, made no further seam call, and returned the listener's data.
- `includes/invoice.php:1683` — Later wallet clients are built straight from the stored store mint_url (new MintClient($store['mint_url'])) with no re-validation or pin, so a later DNS change applies to every background operation.

**Blockers (what source and local checks cannot settle).**

- Real DNS rebinding (a TTL-0 public answer at check time, then a private answer for cURL's lookup) needs a controllable authoritative or local resolver that both PHP's gethostbynamel and libcurl use. The sandbox denies external network, so the divergence was only modelled with Security::setResolverForTesting.
- Practical reach depends on deployment facts. With an https:// mint, TLS verification blocks rebinding to internal services that have no valid certificate for the mint name. Meaningful impact needs an http:// mint URL (accepted by canonicalizeMintUrl) or an internal TLS endpoint whose certificate matches the mint name, plus an internal service that reacts to GET/POST on <path>/v1/*.

**Bounded local next step.** On an isolated VM, run a test DNS server that is authoritative for rebind.test (first A answer 93.184.216.34, later answers 127.0.0.1, TTL 0) and point the system resolver at it. Start a loopback listener on 127.0.0.1:18090. Leave CASHUPAY_ALLOW_PRIVATE_MINTS and allow_private_mints unset and CASHUPAY_DATA_DIR in scratch. Call MintHelpers::assertSafeMintUrl('http://rebind.test:18090') and then Wallet::getSupportedUnits() on the result. Expected vulnerable result: the listener logs GET /v1/keysets. Regression test: MintClient takes the vetted resolve list (CURLOPT_RESOLVE) from assertSafeMintUrl/Security::resolvePublicTarget, or re-resolves and pins per request, and/or checks CURLINFO_PRIMARY_IP against Security::isPublicIp. The second (private) answer must then be refused. Also add a test that canonicalizeMintUrl rejects '%' in the host, since a pin keyed on the literal host would not match cURL's percent-decoded host.

**Owner-observed deployment check (no audit traffic to live systems).** The owner checks, read-only, whether any configured store or backup mint_url uses http://. The owner also checks whether the host or LAN runs plain-HTTP services (or TLS services with certificates a mint name could match) that act on POST/GET to /v1/* paths, and whether cloud metadata (169.254.169.254) is reachable from PHP-FPM.

### N3. Every anonymous page load leaves a session file in the wallet data directory, and nothing reclaims them when PHP's probabilistic session GC is disabled (stock Debian/Ubuntu)

**Fingerprint:** `includes/auth.php:Auth::initSession:app-session-dir-without-gc` · no severity (not a confirmed vulnerability)

Auth::initSession points PHP sessions at <data>/sessions and calls session_start() for every visitor. It is reached by anonymous, cookieless requests through index.php (Auth::isLoggedIn, no rate limit), admin.php (login page), receive.php and setup.php. With the files handler, each cookieless request creates a new sess_* file, often 0 bytes. The app never collects garbage in that directory itself. Whether the files get reclaimed depends entirely on PHP's built-in GC. When session.gc_probability > 0 (upstream php.ini default 1/1000, e.g. AlmaLinux/RHEL), PHP's GC runs on the active save path, which is the app directory, so the files are reclaimed. Debian/Ubuntu ship gc_probability=0 and clean sessions with a cron job (sessionclean) that only scans the php.ini session.save_path. On those hosts the app's directory is never cleaned. Session files would then pile up without limit, from bot traffic or deliberate flooding, on the filesystem that also holds cashupay.sqlite. Eventual inode or disk exhaustion would break wallet/DB writes. This is a slow, unauthenticated denial of service, not fund theft.

**Claimed root cause.** includes/auth.php:25-31 overrides session_save_path to an app-owned directory inside the data dir, and :43 calls session_start() for anonymous requests. No code anywhere (BackgroundRunner tasks included) calls session_gc() or sets session.gc_* for that directory. Distro cron-based cleaners don't know about the path, so on hosts with gc_probability=0 nothing removes the files.

**Source trace.**

1. entrypoint — `index.php:27` (anonymous landing page): Auth::isLoggedIn() runs for every anonymous visitor once setup is complete. It calls Auth::initSession(). No rate limit.
2. propagation — `includes/auth.php:30` (Auth::initSession): session_save_path(<data>/sessions) replaces the php.ini/distro-managed save path.
3. sink — `includes/auth.php:43` (Auth::initSession): session_start() with no session cookie creates a new sess_* file in <data>/sessions for each request. The only thing that reclaims it is PHP's probabilistic GC.

**Evidence.**

- `includes/auth.php:25` — The session dir is Database::getDataDir() . '/sessions', inside the wallet data dir next to the SQLite DB.
- `includes/background_runner.php:842` — The cleanup tasks (cleanupInvoices and the ones after it) handle invoices, pending operations and webhooks, never sessions. A repo-wide grep finds no session_gc, gc_probability or gc_maxlifetime. The only other /sessions references are copy/delete during data relocation (includes/database.php:124-147).
- `setup.php:28` — setup.php calls Auth::initSession() unconditionally. admin.php:317 and receive.php:421 also start sessions for anonymous requests.

**Blockers (what source and local checks cannot settle).**

- Whether files persist is decided by the production PHP's session.gc_probability/gc_divisor/gc_maxlifetime and by any host cron covering <CASHUPAY_DATA_DIR>/sessions. These are deployment facts outside the source. Locally verified: with gc_probability=0, files accumulate one per cookieless request. With gc_probability>0, PHP's own GC removes expired files from the app directory. So hosts using upstream defaults (e.g. RHEL/AlmaLinux 1/1000) are not affected, and Debian/Ubuntu-style gc_probability=0 hosts are. How many requests it takes to exhaust inodes or disk on a real filesystem has not been observed.

**Bounded local next step.** Run ~/security-audit-skill/cashupayserver/run-3/tools/sbx.sh v-sessgc --loopback -- bash scratch/t/run.sh. Phase A (-d session.gc_probability=0) should show exactly one new file per cookieless GET to index.php, admin.php, receive.php and setup.php, with none removed after gc_maxlifetime. Phase B (-d session.gc_probability=1 -d session.gc_divisor=1 -d session.gc_maxlifetime=1) should show expired files removed by a single request, which confirms the dependency is purely config.

**Owner-observed deployment check (no audit traffic to live systems).** On an owner-controlled install, without modifying anything, the owner runs php-fpm's effective config check (php-fpm -i or a phpinfo() diag page, deleted afterwards) to read session.gc_probability and gc_divisor, and checks whether any cron/systemd timer (e.g. Debian's phpsessionclean) covers <CASHUPAY_DATA_DIR>/sessions. The owner counts files there with ls | wc -l and rechecks after 48 h of normal traffic. Unbounded growth with gc_probability=0 confirms the issue. A stable count, or gc_probability>0, refutes it for that host.

### N4. WordPress mode sends the internal cron key to site_url(), which some wp-config setups derive from the Host header

**Fingerprint:** `includes/background.php:selfCronUrl:wordpress-site_url-trusted-for-internal-key` · no severity (not a confirmed vulnerability)

Background::trigger() sends the internal_background_key in an X-Cron-Key header to Urls::cron() + '?internal=1'. In standalone mode selfCronUrl() refuses unless base_url is pinned, because HTTP_HOST is attacker-controlled. In WordPress mode it returns site_url('/cashupay/cron') with no check, and it ignores the base_url that activation pinned. Some WordPress deployments define WP_SITEURL from $_SERVER['HTTP_HOST'] in wp-config.php (some hosting and container templates do this). On those, site_url() echoes the request's Host. An anonymous request to /cashupay/api/v1/... (api.php registers the trigger as a shutdown function before auth and the rate limit) or to /cashupay/payment/{id} (payment.php:43) with a forged Host then makes the server send its internal key to a host the attacker names, at most once per 30 s. The key goes over plain http if WP_SITEURL uses http://, or over https to any host with a valid certificate. Redirects are not followed. The whole request has a 100 ms timeout, so an IP-literal Host avoids DNS delay. With the key, the attacker can call /cashupay/cron?internal=1&budget=N whenever they like (cron.php:45-50). That gives unlimited lease-serialized background runs (mint polling, webhook delivery, auto-melt to the configured destinations) and the JSON run summary, which includes store names, mint URLs and auto-melt amounts. Funds still go only to the operator's configured destinations. A sandboxed stub run (site_url() stubbed to the Host-derived form, real CashuPay code) showed a loopback listener receiving 'GET /cashupay/cron?internal=1' with 'X-Cron-Key: <stored internal key>', even though base_url was pinned to a different origin.

**Claimed root cause.** Background::selfCronUrl() (includes/background.php:67-70) treats WordPress site_url() as a trusted origin unconditionally. Urls::cron() (includes/urls.php:164-166) returns site_url('/cashupay/cron'). site_url() can be request-derived through a WP_SITEURL constant in wp-config.php, and the code never compares it with the base_url stored at activation (wordpress/activation.php:147).

**Source trace.**

1. entrypoint — `api.php:50` (anonymous Greenfield API request (WP route /cashupay/api/v1/* via wordpress/rewrite-rules.php:93-99)): register_shutdown_function(Background::trigger) is registered for every API request before the rate limit and before any authentication; payment.php:43 is an equivalent anonymous trigger
2. propagation — `includes/background.php:26` (Background::trigger): Coalesced to once per 30 s via last_background_trigger, then selfCronUrl() is called at line 32
3. propagation — `includes/background.php:68` (Background::selfCronUrl): In WP mode it returns Urls::cron() directly, skipping the base_url requirement that standalone mode enforces at lines 71-73
4. propagation — `includes/urls.php:166` (Urls::cron): Returns site_url('/cashupay/cron'); it is request-derived when wp-config.php builds WP_SITEURL from HTTP_HOST
5. sink — `includes/background.php:44` (Background::trigger curl self-request): Sends 'X-Cron-Key: ' . internal_background_key to the resolved URL; FOLLOWLOCATION is false and TLS is verified for non-loopback hosts, but neither restricts the destination host

**Evidence.**

- `includes/background.php:63` — The docblock names WordPress site_url() as a trusted origin; that only holds when siteurl comes from the DB option or a static constant
- `cron.php:45` — With ?internal=1, a matching internal key (hash_equals at background.php:108) is accepted as a full cron caller; cron.php:77 echoes the BackgroundRunner::run() JSON
- `wordpress/activation.php:147` — Activation pins base_url = site_url('/cashupay'), but selfCronUrl ignores it in WP mode; the stub run confirmed the key goes to the Host-derived origin even with base_url pinned elsewhere
- `includes/background_runner.php:758` — The autoMelt summary returned to the cron caller includes store name/id, mint URL and amount
- `wordpress/rewrite-rules.php:26` — Canonical redirects are disabled for /cashupay/api/ paths, so the API request reaches api.php without a WordPress redirect to the canonical host

**Blockers (what source and local checks cannot settle).**

- Whether a given WordPress deployment defines WP_SITEURL (or a siteurl filter) from $_SERVER['HTTP_HOST']; wp-config.php is outside the repository, and with the default DB-stored siteurl the trace does not apply
- Whether the front web server or vhost routes requests with an arbitrary Host header to the WordPress site
- The local run stubbed site_url(); there is no real WordPress core in the sandbox to confirm end-to-end resolution through WP's option_siteurl/WP_SITEURL handling

**Bounded local next step.** In a disposable WordPress fixture (loopback only, scratch DB) whose wp-config.php has define('WP_SITEURL','http://'.$_SERVER['HTTP_HOST']), activate the CashuPay plugin, finish setup, delete the last_background_trigger config row, and start a loopback listener on a second port. Send GET /cashupay/api/v1/server/info to the WP vhost with 'Host: 127.0.0.1:<listener-port>' and check whether the listener receives 'GET /cashupay/cron?internal=1' with an X-Cron-Key equal to the stored internal_background_key. Repeat with WP_SITEURL unset (DB siteurl) and expect no capture. Fix check: in WP mode selfCronUrl() should build the URL from the stored base_url (or compare parse_url host/scheme/port of site_url() with the pinned base_url and return null on mismatch), and the forged-Host request should then produce no capture.

**Owner-observed deployment check (no audit traffic to live systems).** Without sending any traffic, the owner checks the wp-config.php of each WordPress install running the plugin (and any hosting or container template they recommend) for WP_SITEURL/WP_HOME or siteurl filters built from HTTP_HOST or X-Forwarded-Host. They also check whether the web server's default vhost passes arbitrary Host values to WordPress, for example by reviewing server_name/ServerAlias settings.

### N5. Behind a trusted proxy that sets only X-Real-IP, a client-sent X-Forwarded-For or Forwarded header picks the rate-limit and login-lockout identity

**Fingerprint:** `includes/security.php:Security::getClientIp:xff-precedence-over-x-real-ip` · no severity (not a confirmed vulnerability)

Security::getClientIp trusts forwarded headers when the direct peer is a trusted proxy: loopback, a configured trusted_proxies entry, a private range in the default 'auto' mode, or a Cloudflare edge. Once it trusts the peer, it reads X-Forwarded-For first, then RFC 7239 Forwarded, and reads X-Real-IP only when neither gives an address. Its docblock says X-Real-IP is 'a last resort for proxies that set no X-Forwarded-For'. A proxy like that also leaves alone any X-Forwarded-For or Forwarded header the client sent. The client's header therefore reaches PHP unchanged and wins over the X-Real-IP the proxy actually set, so the client chooses its own identity for every per-IP control. That covers the admin login lockout (Auth::attemptLogin -> Security::reserveLoginAttempt, which is keyed only on the IP) and the per-IP rate limits that use getClientIp (change_password, reveal_seed, backup, API, receive and setup). I reproduced this in the sandbox with peer 127.0.0.1, auto mode and a fixed X-Real-IP. Rotating X-Forwarded-For gave 20 of 20 reserved login attempts for one real client; without the header the same client got 5 of 20. A Forwarded: for= header from a 10.0.0.5 peer also beat X-Real-IP. Proxies that append to X-Forwarded-For are handled correctly: the rightmost hop wins. Peers that are not trusted, such as direct connections from public addresses, are not affected.

**Claimed root cause.** In Security::getClientIp, X-Forwarded-For and Forwarded take priority over X-Real-IP (includes/security.php:535-567). The code cannot tell which header the trusted hop actually wrote. With a proxy that sets only X-Real-IP, the headers the code prefers are the ones the client controls and the proxy never overwrites.

**Source trace.**

1. entrypoint — `admin.php:738` (POST action=login (unauthenticated)): The login handler takes the client identity from Security::getClientIp() before the password check
2. propagation — `includes/security.php:528` (Security::getClientIp): A loopback, configured or (auto mode) private peer passes isTrustedProxy (security.php:648-680), so its forwarded headers are believed
3. propagation — `includes/security.php:535` (Security::getClientIp XFF walk): X-Forwarded-For is read first, and the rightmost untrusted hop is returned. If the proxy does not append, that hop is whatever the client sent
4. propagation — `includes/security.php:564` (Security::getClientIp X-Real-IP fallback): The X-Real-IP the proxy set is read only when XFF and Forwarded (forwardedFor, line 560) give no address
5. propagation — `includes/auth.php:115` (Auth::attemptLogin): The lockout is reserved under the identifier the client chose
6. sink — `includes/security.php:66` (Security::reserveLoginAttempt): The lockout counter is keyed on login_attempts_{identifier}, so each new forged value starts a fresh budget of MAX_LOGIN_ATTEMPTS

**Evidence.**

- `includes/security.php:510` — The docblock says X-Real-IP is a last resort for proxies that set no X-Forwarded-For, so this proxy type is an intended deployment
- `includes/security.php:559` — Forwarded and then X-Real-IP are read only after the XFF walk
- `tests/security_client_ip.php:84` — A test asserts that XFF beats X-Real-IP ('X-Real-IP is only a fallback'), so this order is intended
- `includes/auth.php:114` — attemptLogin keys the lockout only on getClientIp(). Source shows no global or per-account cap
- `admin.php:811` — change_password re-auth also uses getClientIp() for its rate limit and lockout reservation

**Blockers (what source and local checks cannot settle).**

- The repository does not show whether any supported deployment puts the app behind a trusted hop (loopback, private range in auto mode, or configured) that sets X-Real-IP but neither appends to/overwrites X-Forwarded-For nor strips a client-sent Forwarded header. An example is an nginx proxy_pass front with only 'proxy_set_header X-Real-IP $remote_addr'. The README's nginx examples are direct fastcgi setups, not proxies, and the repo ships no reverse-proxy config. The code path is confirmed locally. Whether it can be exploited depends on how the deployed proxy handles headers

**Bounded local next step.** Done, and it passes: ~/security-audit-skill/cashupayserver/run-3/tools/sbx.sh v-clientip -- php -d opcache.enable_cli=0 xr.php (input and output are in agents/v-clientip/artifacts/evidence/clientip-xff-over-xrealip.txt). It sets REMOTE_ADDR=127.0.0.1, fixes HTTP_X_REAL_IP=198.51.100.7, rotates HTTP_X_FORWARDED_FOR, and calls Security::reserveLoginAttempt(getClientIp()). Result: 20/20 reserved, against 5/20 without XFF.

**Owner-observed deployment check (no audit traffic to live systems).** For each hosting pattern in use, the owner checks the front proxy config without changing anything. If PHP runs directly under nginx/Apache with php-fpm and no proxy in front, the peer is the real client and the issue does not apply. If a trusted proxy (same host, private LAN, or a configured one) forwards the requests, check whether it sets X-Real-IP without 'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for' (or an equivalent overwrite) and without clearing Forwarded. If so, send one benign request to your own instance with 'X-Forwarded-For: 192.0.2.1' while logged in, and open the dashboard 'Visitor addresses' card (describeClientIp seenAs). If it shows 192.0.2.1 instead of your real address, the per-IP login lockout and rate limits can be bypassed on that deployment. Workarounds: configure the proxy to append to or overwrite XFF, or set proxy_mode to 'direct'/'custom'.

### N6. Webhook anti-SSRF pin is keyed on the raw parse_url host; libcurl percent-decodes/IDN-converts the host, misses the pin and resolves on its own

**Fingerprint:** `includes/security.php:resolveTarget:raw-host-pin-vs-curl-decoded-host` · no severity (not a confirmed vulnerability)

An API key holder with btcpay.store.webhooks.canmodifywebhooks (handleCreateWebhook / handleUpdateWebhook) can register a webhook URL whose host is percent-encoded (for example http://x%2einternal.attacker.tld/) or non-ASCII. Security::resolveTarget resolves and vets the literal undecoded string, then returns a CURLOPT_RESOLVE entry keyed on that same string. libcurl connects to the decoded name (x.internal.attacker.tld, or its punycode form), which has no pin entry, so it does its own DNS lookup. An attacker-run zone can answer that lookup with 127.0.0.1 or another private address. The result is HMAC-signed webhook POSTs to internal services on port 80 or 443, with the target's HTTP status shown in GET .../webhooks/{id}/deliveries. Reproduced locally with libcurl 8.23.0: planDelivery('http://l%6fcalhost/internal-admin?x=1') returned ['l%6fcalhost:80:93.184.216.34'] while planDelivery('http://localhost/') returned null. With sendRequest's cURL options and the pin 'l%6fcalhost:18437:93.184.216.34', the request went to 127.0.0.1 and a loopback listener logged 'POST /internal-admin?x=1 Host=localhost:18437 Sig=sha256=test'. A control name, pinned.test, used its pin (93.184.216.34). The exploit only works if the production resolver returns a public address for the raw '%' or UTF-8 name when the URL is validated. If it returns nothing, the check fails closed. That step is not verified.

**Claimed root cause.** Security::resolveTarget (includes/security.php:269, 289) uses parse_url's undecoded host as both the name it resolves and vets (resolveHost, lines 406-407) and the CURLOPT_RESOLVE key. libcurl normalizes the host (percent-decoding, IDN to ACE) before it looks up its DNS cache, so the vetted name and the connected name differ and the pin does not apply. No host-character validation rejects '%' or non-ASCII hosts before resolution.

**Source trace.**

1. entrypoint — `includes/api/webhooks.php:25` (handleCreateWebhook): POST /stores/{storeId}/webhooks (api.php:128), after requireStore and requirePermission('btcpay.store.webhooks.canmodifywebhooks') at lines 12-13. The API-key-supplied url is checked by validateWebhookUrl -> WebhookSender::isAllowedTarget -> planDelivery. The update path is at line 109.
2. propagation — `includes/webhook_sender.php:210` (WebhookSender::planDelivery): For a non-own-origin target on port 80/443, returns Security::resolvePublicTarget($url)['resolve'], which calls resolveTarget (security.php:233).
3. propagation — `includes/security.php:269` (Security::resolveTarget): $host = $parts['host'] stays undecoded ('l%6fcalhost') and is resolved by resolveHost (gethostbynamel + dns_get_record DNS_AAAA, lines 406-407).
4. propagation — `includes/security.php:289` (Security::resolveTarget): The pin is built as $host . ':' . $port . ':' . ips, keyed on the raw string.
5. propagation — `includes/webhook_sender.php:288` (WebhookSender::sendRequest): At delivery time planDelivery runs again and returns the same pin keyed on the raw host.
6. sink — `includes/webhook_sender.php:319` (WebhookSender::sendRequest): curl_exec on curl_init($url) (line 296) with CURLOPT_RESOLVE set (line 314). libcurl decodes the host, finds no matching pin and resolves the decoded name itself.

**Evidence.**

- `includes/security.php:289` — The CURLOPT_RESOLVE key is the raw parse_url host. Re-run (agents/v-webhookpin/artifacts/evidence/webhook_pin_repro.txt): planDelivery('http://l%6fcalhost/internal-admin?x=1') = ["l%6fcalhost:80:93.184.216.34"], planDelivery('http://localhost/') = null. The validation-time lookups were ["l%6fcalhost","localhost","pinned.test"], so the raw '%' name was what got vetted.
- `includes/webhook_sender.php:314` — Using sendRequest's cURL options (POST, FOLLOWLOCATION false, http/https protocols, BTCPay-Sig header) with the pin 'l%6fcalhost:18437:93.184.216.34', libcurl 8.23.0 connected to primary_ip 127.0.0.1 (http_code 204), and the loopback listener logged 'POST /internal-admin?x=1 Host=localhost:18437 Sig=sha256=test'. Control 'pinned.test' used its pin (it failed to reach 93.184.216.34 under the sandbox network deny), so the pin works only when the names match exactly. Artifact: agents/v-webhookpin/artifacts/evidence/webhook_pin_repro.txt; hunter artifact agents/h-ssrf/artifacts/evidence/webhook_pin_bypass.txt shows the full handleCreateWebhook -> fireEvent -> deliverPending path.
- `includes/security.php:407` — The validation-time lookup includes dns_get_record($host, DNS_AAAA) on the raw name. That goes through libresolv res_search and PHP's dn_expand-based parser without glibc's res_hnok hostname checks, so it is the likely way a '%'/UTF-8 label from an attacker-run zone gets a public answer. gethostbynamel may reject such answers. This is reasoning, not observation.

**Blockers (what source and local checks cannot settle).**

- Not observed: whether the production resolver (AlmaLinux 10 glibc, PHP 8.3 gethostbynamel and dns_get_record DNS_AAAA through the configured recursive resolver) returns any address for a hostname containing '%xx' or raw UTF-8 labels. If it returns none, resolveTarget fails closed. The local sandbox has no controllable DNS, so validation-time resolution was modelled with Security::setResolverForTesting.
- Not observed: whether the production libcurl 8.12.1 percent-decodes hosts (and converts IDN with libidn2) before the CURLOPT_RESOLVE / DNS-cache lookup, as local libcurl 8.23.0 does.

**Bounded local next step.** Use a Linux VM with glibc, PHP 8.3 and libcurl 8.12.1, with an offline authoritative DNS (dnsmasq or knot test zone) as its only resolver. The zone serves 'x%2ehook.test.' AAAA 2001:4860::1 (and optionally A 93.184.216.34) and 'x.hook.test.' A 127.0.0.1. First run php -r 'var_dump(gethostbynamel("x%2ehook.test"), dns_get_record("x%2ehook.test", DNS_AAAA));'. Then, with the resolver seam unset and a loopback listener on 127.0.0.1:80, run the micro.php / run.sh scripts from agents/v-webhookpin/artifacts/evidence/webhook_pin_repro.txt with URL http://x%2ehook.test/hook and the pin from WebhookSender::planDelivery. The finding is confirmed if planDelivery returns a non-null pin and the listener receives the signed POST. Regression test: resolveTarget/planDelivery must return null for hosts that do not match ^[A-Za-z0-9.-]+$ (or a bracketed IPv6 literal). Alternatively, build the pin key from the host as curl_url normalizes it (CURLU_URLDECODE / punycode) and resolve that same name.

**Owner-observed deployment check (no audit traffic to live systems).** On the production server the owner runs php -r 'var_dump(curl_version()["version"], (curl_version()["features"] & CURL_VERSION_IDN) > 0);'. Then, against a test zone they control, they run php -r 'var_dump(gethostbynamel("x%2ehook.<their-test-zone>"), dns_get_record("x%2ehook.<their-test-zone>", DNS_AAAA));'. Neither command sends a webhook or any HTTP request. If either lookup returns a public address and curl decodes the host as above, the bypass applies in production.

### N7. Webhook outbox can deliver a stale InvoiceExpired after InvoiceSettled for the same invoice

**Fingerprint:** `includes/webhook_sender.php:claimNextDelivery:no-per-invoice-ordering-stale-expired-after-settled` · no severity (not a confirmed vulnerability)

Each outbox row has its own exponential backoff (30 s * 2^(attempts-1), capped at 3600 s). claimNextDelivery picks due rows only by next_attempt_at, then created_at, with no per-webhook or per-invoice sequence and no supersession check. Suppose an invoice expires (markExpiredInvoices enqueues InvoiceExpired with invoice.status='Expired'), the first delivery of that event fails (the shop endpoint is briefly down or returns non-2xx), and the late payment is then recovered (recoverExpiredPaidInvoices -> completeIssuedInvoice -> settleAndEnqueue, or mintAndStoreTokens). InvoiceReceivedPayment/Processing/Settled are then enqueued with next_attempt_at=now and are delivered first. The older InvoiceExpired goes out once its backoff ends. An independent sandboxed loopback reproduction showed this arrival order: Expired(503), ReceivedPayment, Processing, Settled, Expired(200, invoice.status=Expired). A receiver that applies events in arrival order without checking for regression or re-fetching the invoice could move a paid order to its expired/cancelled status. The payment is kept but the order is not fulfilled. The longer the endpoint is down, the larger the Expired row's backoff (up to 1 h), so the window for a late payment to overtake it grows. Calibration: BTCPay Server also retries failed deliveries independently of newer events, so receivers built for BTCPay may already tolerate out-of-order events. That reduces the likely impact but does not refute the inversion.

**Claimed root cause.** The outbox has no ordering or supersession rule. claimNextDelivery (ORDER BY next_attempt_at ASC, created_at ASC) lets a newer row with fewer failed attempts overtake an older row for the same webhook and invoice. The stale row's snapshotted payload (invoice.status='Expired') is still delivered after the invoice has reached a terminal Settled state that contradicts it.

**Source trace.**

1. entrypoint — `includes/invoice.php:337` (Invoice::markExpiredInvoices): A New invoice past expiration_time becomes Expired (cron/background run), and InvoiceExpired is enqueued at line 365 with a payload snapshot of status 'Expired'.
2. propagation — `includes/webhook_sender.php:360` (WebhookSender::deliverPending): A failed attempt sets next_attempt_at = now + min(3600, 30*2^(attempts-1)) (line 362) for that row only.
3. propagation — `includes/invoice.php:982` (Invoice::recoverExpiredPaidInvoices): Late payment on the Expired invoice is detected (customer pays the Lightning quote late) and settled via completeIssuedInvoice -> settleAndEnqueue (line 87) or mintAndStoreTokens. InvoiceReceivedPayment/Processing/Settled are enqueued due immediately (lines 98-100).
4. sink — `includes/webhook_sender.php:387` (WebhookSender::claimNextDelivery): Rows are claimed by next_attempt_at, then created_at, with no per-invoice sequencing. The Settled-family rows go out first, and the older InvoiceExpired row is sent to the same webhook afterwards.

**Evidence.**

- `includes/webhook_sender.php:387` — ORDER BY wd.next_attempt_at ASC, wd.created_at ASC: no per-webhook/per-invoice ordering, and no check whether a newer state superseded the row.
- `includes/webhook_sender.php:362` — Per-row exponential backoff (capped at MAX_BACKOFF=3600) lets an older failed row fall behind newer rows.
- `includes/webhook_sender.php:129` — The payload snapshots invoice.status at enqueue time, so the late InvoiceExpired delivery still carries status 'Expired'.
- `includes/invoice.php:100` — settleAndEnqueue enqueues InvoiceSettled with next_attempt_at=now and does not look at older pending rows for the same invoice.
- `includes/invoice.php:465` — mintAndStoreTokens (the recovery path for a PAID but not ISSUED quote) also enqueues InvoiceSettled immediately.

**Blockers (what source and local checks cannot settle).**

- The harm depends on the external receiver, typically the BTCPay Greenfield for WooCommerce plugin, whose source is not in this repository. The decisive unknown is whether its processWebhook applies InvoiceExpired to an order that InvoiceSettled has already moved to processing/completed, or whether it guards against status regression or re-fetches the invoice from the API. The delivery inversion itself is reproduced locally. The business impact (a paid order marked expired/cancelled) is not.

**Bounded local next step.** Done: ~/security-audit-skill/cashupayserver/run-3/tools/sbx.sh v-outbox --loopback -- bash scratch/run.sh (artifact agents/v-outbox/artifacts/evidence/outbox-order-verify.txt) reproduces the order Expired(503), ReceivedPayment, Processing, Settled, Expired(200). Next step: get a pinned release of the BTCPay Greenfield for WooCommerce plugin into an isolated directory, then read or run its webhook handler for the InvoiceExpired case with a WooCommerce order already in the processing state. Replay the five captured payloads in that order with valid BTCPay-Sig signatures and record the final order status. Use only a throwaway local WordPress/SQLite instance and no real payments.

**Owner-observed deployment check (no audit traffic to live systems).** Owner-observed check on a staging WooCommerce shop paired with a staging CashuPayServer and a test mint. Make the webhook endpoint return 503 once at the moment the invoice expires (for example with a temporary reverse-proxy rule), then pay that invoice's Lightning quote late with test funds so recoverExpiredPaidInvoices settles it. After the delayed InvoiceExpired retry arrives (30 s or more later), check whether the order ends in processing/completed or in the configured expired/cancelled status. Remove the proxy rule afterwards.

### N8. Unauthenticated receive.php parses attacker-chosen colliding map keys with quadratic CPU cost (request-body json_decode and token CBOR/JSON decode)

**Fingerprint:** `receive.php:request-body-json_decode+CBOR::decode:colliding-integer-map-keys` · no severity (not a confirmed vulnerability)

POST receive.php reads the whole php://input (receive.php:136) and runs json_decode($input, true) on it (:137). No size check runs first. MAX_TOKEN_LENGTH (262144) is applied only later, to the token field (:224, and again in TokenSerializer::deserialize at CashuWallet.php:3417). Before parsing, the endpoint needs only completed server setup and the per-IP rate limit of 20 per minute (:128). The body variant needs no valid store_id. The token variant (:230 -> deserializeV4 CBOR::decode at CashuWallet.php:3560, or deserializeV3 json_decode at :3487) also needs a configured store_id (:216), which is public on payment pages. Both decoders put attacker-chosen keys into PHP hash tables: CBOR integer keys, which CBOR::decodeValue accepts at :3209-3212, or JSON numeric-string keys, which PHP turns into integer keys. Integer keys that are multiples of the final table size all land in one bucket, so inserting n entries costs O(n^2). Earlier sandbox runs on PHP 8.5.11 reported the following. A 256,010-byte colliding cashuB token (below MAX_TOKEN_LENGTH) took 644.5 ms in TokenSerializer::deserialize, against 12.7 ms for a non-colliding token of the same size; n=16384 took 176.7 ms against 5.9 ms. json_decode of a 966,081-byte colliding body took 2344.1 ms, against 4.7 ms (2752.5 ms against 4.2 ms in a second independent run); 457 KB took 627.8 ms. Doubling the size roughly quadruples the time. The same unbounded raw-body json_decode also exists on an authenticated path: api.php getRequestBody() (api.php:170-177, json_decode at :175), which runs after Auth::requireApiAuth (api.php:261), so only API-key holders can reach that variant. No source-visible control bounds the cost of a single request. Whether this causes real shared unavailability depends on the deployed body cap and the FPM pool.

**Claimed root cause.** Untrusted JSON/CBOR maps become PHP hash tables with no cap on the number of entries and no restriction on key type. receive.php (and, behind API auth, api.php getRequestBody) reads php://input and json_decodes it with no byte limit; in receive.php this happens before the token-length check. CBOR::decode accepts integer map keys, which Cashu tokens never use, and allows up to (remaining bytes / 2) entries per map. Total input length is the only bound, and it does not bound the quadratic cost of collisions.

**Source trace.**

1. entrypoint — `receive.php:123` (POST handler): Unauthenticated POST branch, reachable once setup is complete. The per-IP rate limit (20/min) at :128 is the only gate before parsing.
2. propagation — `receive.php:136` (POST handler): The whole php://input is read with no length check.
3. propagation — `receive.php:137` (POST handler): json_decode($input, true) builds arrays from attacker-chosen keys. Numeric-string keys become colliding integer keys, so the cost is quadratic. This runs before any store_id or size check.
4. propagation — `receive.php:224` (POST handler): MAX_TOKEN_LENGTH (262144) is checked here, on the token field only, after the body has already been decoded. A store_id that passes Config::isStoreConfigured (:216) is required before this point.
5. propagation — `receive.php:230` (POST handler): TokenSerializer::deserialize($tokenString) runs on the public token.
6. propagation — `cashu-wallet-php/CashuWallet.php:3560` (TokenSerializer::deserializeV4): CBOR::decode of the base64url payload. cashuA tokens go through json_decode at :3487 instead.
7. propagation — `cashu-wallet-php/CashuWallet.php:3203` (CBOR::decodeValue MAP): The number of map entries is bounded only by remaining bytes / 2, and integer keys are accepted (:3209).
8. sink — `cashu-wallet-php/CashuWallet.php:3212` (CBOR::decodeValue MAP): $result[$key] = ... inserts each colliding key into a PHP hash table, for a total cost of O(n^2).

**Evidence.**

- `receive.php:137` — Earlier sandbox runs on PHP 8.5.11: json_decode of a 966,081-byte colliding body took 2344.1 ms, against 4.7 ms for a non-colliding body of the same size (457,606 B: 627.8 ms; 222,589 B: 155.3 ms). Matches the hunter's 2395.7 ms. A Phase 5 re-run measured 2752.5 ms against 4.2 ms (966,081 B) and 710.1 ms against 2.0 ms (457,606 B).
- `cashu-wallet-php/CashuWallet.php:3212` — Earlier sandbox run: TokenSerializer::deserialize on a 256,010-byte cashuB token (below MAX_TOKEN_LENGTH 262144) with 32000 colliding integer keys took 644.5 ms, against 12.7 ms (n=16384: 176.7 ms against 5.9 ms; n=8192: 39.5 ms against 2.8 ms). The rejection ('missing mint') comes only after the full map is built.
- `cashu-wallet-php/CashuWallet.php:3487` — The cashuA path runs json_decode on the decoded payload with the same numeric-key collision behaviour. The hunter measured 41.7 ms against 0.7 ms at n=8192; no verifier re-measured it.
- `receive.php:128` — The strongest visible control is the per-IP rate limit of 20 per minute, applied before parsing. It limits the request rate, not the CPU a single request can use. The repository contains no body cap (no post_max_size, client_max_body_size or LimitRequestBody).
- `api.php:175` — Variant on an authenticated path with the same root cause: getRequestBody() (api.php:170-177) runs json_decode on the raw php://input at :175 with no size limit. It is called at api.php:268, after Auth::requireApiAuth at :261 and the 100/min per-IP limit at :54, so only an API-key holder can reach it; the unauthenticated handleServerInfo branch (:250) does not read the body. An earlier verifier extracted the function verbatim and ran it in the sandbox (PHP 8.5.11): n=16384 colliding keys (222,589 B) took 163.09 ms, against 0.86 ms for sequential keys; n=8192 took 33.15 ms against 0.44 ms.

**Blockers (what source and local checks cannot settle).**

- The deployed request-body cap (nginx client_max_body_size, Apache LimitRequestBody, PHP post_max_size; the PHP default is 8 MB) is not in the repository. It decides whether the body variant costs about 2.3 s per request (1 MB) or, by quadratic extrapolation, minutes per request (8 MB). That longer run would be stopped only by FPM request_terminate_timeout, since json_decode's internal C loop does not reach a VM interrupt for max_execution_time.
- The PHP-FPM pool size (pm.max_children) and the server's CPU/time limits decide whether 20 requests/min per IP, or more from several IPs, cause real shared unavailability for payers and the admin. The token-only variant is capped at about 0.65 s per request by MAX_TOKEN_LENGTH.

**Bounded local next step.** Bounded sandbox fixture (php -d opcache.enable_cli=0, scratch-only CASHUPAY_DATA_DIR): build a JSON object of n keys "i*n":0 (colliding) against "i":0 (sequential) for n=16384/32768/65536 and time json_decode; build a cashuB token whose CBOR top-level map has 32000 integer keys i*32768 (total length below 262144) and time TokenSerializer::deserialize. Regression checks after a fix: (1) receive.php rejects, with 413 and before json_decode, a body longer than MAX_TOKEN_LENGTH plus a small envelope margin (check strlen of the input, or read php://input with a maxlength), and api.php getRequestBody applies a similar cap; (2) CBOR::decode rejects non-string map keys and maps with more than a small fixed number of entries (token maps have at most about 5 keys); (3) the colliding n=32000 cashuB token and a 1 MB colliding body are both refused in under 20 ms.

**Owner-observed deployment check (no audit traffic to live systems).** Owner reads, without sending any load: the nginx client_max_body_size that applies to the cashupayserver location (or the http-level default of 1 MB), PHP post_max_size and max_execution_time, and FPM pm.max_children and request_terminate_timeout. Per-request CPU at the allowed body size is about 2.3 s x (size in MB)^2. Compare that with pool capacity: with 20 requests/min per IP, sustained occupancy is about (20/60) x per-request seconds busy workers per source IP, measured against pm.max_children.
