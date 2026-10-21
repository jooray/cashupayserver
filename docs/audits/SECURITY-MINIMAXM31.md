# Security audit — CashuPayServer

- **Target:** `/Users/juraj/projects/cashupayserver` (CashuPayServer v0.5.6-alpha, a self-hosted Cashu/Lightning payment gateway with a BTCPay Greenfield-API-compatible API)
- **Source ref:** commit `4a82d018f468f513bb2864444a42116206d0742e`, submodules `cashu-wallet-php@7751f34`, `mint-discovery@b1b5c07`. The working tree was clean when the run started; see the note below on how it changed during the run.
- **Report path:** `docs/audits/SECURITY-MINIMAXM31.md`
- **Run profile:** `standard` (5 hunting waves, 4 post-wave coverage critics + 1 final-clean critic, 25 candidate verifiers, 9 Phase-5 record reviewers)
- **Execution policy:** `sandboxed-source-and-local-only` — every local check ran inside a macOS Seatbelt sandbox with no external network (loopback only where a fixture needed it), an empty allowlisted environment, the repository mounted read-only, scratch-only writes, and CPU/size/process/wall-clock caps. No deployed endpoint, real mint, real credential or live instance was contacted at any point.
- **Source changes made:** none. This audit did not modify a single line of the target; the only file written inside the repository is this report.
- **Working-tree note:** the audited state is the committed tree at `4a82d01`. During the run a **concurrent remediation pass by another agent** modified 43 tracked files in this same working tree (it applies fixes for several of the findings below and cites the prior audits by name). Those edits are not this audit's work: every local check in this run executed inside a sandbox that denies `file-write*` outside the agent's own scratch directory, and the parent-side promotion tool writes only into the run directory. Each cited line number in this report therefore refers to commit `4a82d01`; where a fix has already landed in the working tree, the finding is still reported, because the audited commit is what a release built from `4a82d01` would contain.

---

## 1. Run summary and coverage claim

This is a **partial-coverage pass with a full evidence bar**. Every finding below was reproduced or independently re-derived inside the sandbox and then reviewed by a second, fresh agent that had not written it. No finding is reported on the strength of the original hunter's write-up alone.

**What was covered.** A deterministic coverage ledger of 52 units across 5 hunting waves, each unit bound to a named entry surface, a named trust boundary, a subsystem and an attack class, and each closed only with the source paths and checks that actually back it:

| Unit status | Count |
|---|---:|
| `covered` | 18 |
| `candidate` | 32 |
| `deferred` | 2 |
| **total units** | **52** |

Wave composition: wave 1: 31 units, wave 2: 4 units, wave 3: 6 units, wave 4: 6 units, wave 5: 5 units. 3 unit attempt(s) were archived and reopened by a coverage critic.

**Prior runs.** No compatible prior `coverage-ledger.json` or `findings.json` exists for this repository, so nothing was carried forward as a confirmed record and no prior finding suppressed any coverage. Prose-only prior audits exist in the working tree at `docs/audits/SECURITY-OPUS55.md` and `docs/audits/SECURITY-DS41flash1017.md` (both untracked, therefore not part of the audited commit); they were read as context and are not treated as evidence — this run re-derived every claim from current source, including the ones those reports had already noted (the unsigned update manifest metadata, the per-IP-only login lockout, and the multisite data-directory collision).

**Known gaps in this pass.** The final-clean coverage critic proposed five further units after the fourth wave; the three most concrete were executed as a fifth wave, and the two that remained are recorded in the ledger as `deferred` with the critic's source-backed reason - they are quoted in full in §7, and they are the first place to look for a follow-up pass. It also proposed reopening the setup-ownership unit (`setup.php#POST step` :: `includes/setup_ownership.php#claim`) **without attaching a source-backed reason**; that unit is closed with the repository's own offline fixtures (`tests/setup_wizard.php`, `tests/setup_ownership.php`) as local evidence, so the disagreement is recorded here rather than acted on. The remaining open ground is the two deferred units in §7, plus the deployment facts listed in the needs-validation table (§5), which no amount of source reading can settle.

---

## 2. Security posture

CashuPayServer's trust engineering is **better than most self-hosted PHP applications of this size**, and the report should not obscure that. The following were checked and found sound:

- **Secrets at rest.** Greenfield API keys are stored only as `sha256(raw)` digests (`includes/auth.php:386-407`); the raw value is returned once at creation. The admin password is bcrypt. CSRF tokens, session ids, the cron key, the internal background key and the setup recovery code are all generated with `random_bytes()` — there is no `rand()`, `mt_rand()` or `uniqid()` anywhere in the application.
- **Secret comparison.** Every credential comparison uses `hash_equals()`. The only exception is the API-key lookup, which compares a *digest* against an indexed column — a digest equality, not a byte comparison, so there is no timing oracle on the secret itself.
- **SQL injection.** Every query is a prepared statement with bound parameters. The only interpolated SQL fragments are table and column names taken from internal constant lists, plus `PDO::quote()`-escaped server-generated paths. A repository-wide sweep found no request-controlled value reaching a query as anything other than a bound parameter.
- **XSS.** Every server-rendered dynamic value goes through `htmlspecialchars()`; every client-side `innerHTML` sink in the admin SPA goes through the app's own `escapeHtml()` or is built with `textContent`. The one place a mint-supplied string is rendered (`json_encode` into the payment page's inline script) is safe because PHP escapes `/` by default, so `</script>` cannot break out. CSP is `script-src 'self' 'unsafe-inline'` with `frame-ancestors 'self'`; the inline script is required by the SPA's own architecture.
- **The outbound-egress gates are real.** `Security::resolvePublicTarget()` resolves a host once, judges every returned address against a private/reserved range table, and pins the connection with `CURLOPT_RESOLVE` so the checked address *is* the connected address. The webhook path uses it correctly, including a DNS-rebinding regression test. This is the strongest control in the codebase and the findings below are about the paths that do not use it.
- **Money safety.** The per-store `flock` is taken by every path that spends a store's ecash, the minting claim is an atomic conditional UPDATE, the settlement is a conditional UPDATE, the pending-withdrawal guard blocks a second withdrawal, and the wallet library journals a pending mint before it asks the mint to sign — so a crash between mint and store leaves a recoverable journal, not lost coins.
- **CSRF.** The admin surface has exactly one POST handler, one session check and one CSRF check in front of it, and the login action has its own check, so login CSRF is covered too.

**The overall picture.** The defects this run found are almost all of one family: **a control that exists is applied at the call site instead of inside the function that performs the effect**, so a sibling path silently misses it. The mint-URL anti-SSRF gate is applied in the caller, so one action skips it and the request path never re-validates a stored URL. The transfer ledger's state machine lives in the callers, so one settle action can rewrite a terminal row. The price sanity band trusts a reference that the same function wrote. The WordPress data directory is chosen by a formula that is not unique per installation. The admin HTML shell renders an operator-only value because the authentication check sits in the two branch handlers and not on the fall-through path. The single most valuable structural change for this project is therefore not a new feature but a mechanical one: **move each control into the function that performs the effect, so a new caller cannot forget it.**

---

## 3. Confirmed findings

Severity is calibrated to the demonstrated impact only; no record's overall severity exceeds its own impact statement. Likelihood reflects the conditions the record itself establishes.

| # | Severity | Finding | Boundary crossed | Observed result |
|---|---|---|---|---|
| 1 | **HIGH** | In the WordPress deployment the password prompt on 'Show seed phrase' and 'Download database backup' is not checked, so a hijacked WordPress admin session exports the wallet's master seed and the whole database | `POST action=reveal_seed (and the identical call for download_backup at admin.php:879) → POST action=reveal_seed / download_backup` | In the local WordPress-mode harness (CASHUPAY_WORDPRESS defined, stubbed current_user_can('manage_options') => true, no network): Config::getAdminPasswordHash() => NULL, Auth::isLoggedIn() => true, Auth::ver… |
| 2 | **MEDIUM** | An interrupted database-backup download leaves a complete, unencrypted copy of the wallet database in the data directory, where nothing ever removes it and the app's own exposure check never looks for it | `POST action=download_backup (authenticated admin action) → @unlink($snapshot) as the last statement before exit` | interrupted download: http=200 bytes=663396 of 10510336 in 2 s; the data directory then held backup-20261021-073726-7777b856.sqlite (10,510,336 bytes, mode 0600) and no cleanup task or exposure probe exists … |
| 3 | **MEDIUM** | The trusted-proxy setting accepts a range that covers the whole internet, so every visitor can choose the address that keys the admin lockout and the API quota | `admin.php#POST action=save_proxy_settings → includes/security.php#reserveLoginAttempt` | The handler accepted and stored trusted_proxies = ["0.0.0.0/0"] without any warning or confirmation. isTrustedProxy('203.0.113.55') became true, and getClientIp() returned the attacker's X-Real-IP 198.51.100… |
| 4 | **MEDIUM** | An unauthenticated token POST to receive.php costs ~10 s of server CPU per request (one NUT-12 DLEQ verification per proof, before every cheap rejection) while the per-IP limiter still allows 20 requests per minute | `anonymous POST /receive.php (token submission) → the CPU sink: one NUT-12 DLEQ verification per proof` | One POST of the 893-proof token: HTTP 400 {"error":"Cannot reserve duplicate input proofs"} after 10.32 s wall and 10.25 s of server CPU (PHP 8.5.11, GMP) |
| 5 | **MEDIUM** | Automatic withdrawal sizes an irreversible Lightning payout from a third-party rate the money-out path never verifies, and applies no exchange-fee treatment to it | `BackgroundRunner::tasks() - the 'auto_melt' rotating task → LightningAddress::meltToAddress() -> executeMelt() -> Cashu\Wallet::melt()` | A single provider answering 75000 against a 50000 reference was accepted and cached ('rate accepted for the payout : 75000.0', provider coingecko); with no reference a lone 500000 answer was accepted ('rate … |
| 6 | **MEDIUM** | A single price provider's answer alone sets and re-sets the invoice exchange rate: a wrong rate ratchets and underprices fiat invoices (no floor), and can freeze fiat checkout for up to 24 h | `handleCreateInvoice - Greenfield key holder creates a fiat invoice → Invoice::create - mint quote request` | accepted rate 66000.0, provider calls coingecko:1 binance:0 kraken:0, cache entry rate 66000.0 provider 'coingecko' - the in-band answer is accepted alone and is written back as the next reference |
| 7 | **MEDIUM** | The admin-password lockout has no address-independent budget: five password checks per derived address, with no global floor | `Unauthenticated admin login → Auth::login` | Independently reproduced in the sandbox with dummy data (dummy admin password, TEST-NET addresses, throwaway SQLite under the agent scratch dir; PHP 8.5.11, one password_verify measured at 0.229 s) |
| 8 | **MEDIUM** | The expiry sweep loads the entire expired-invoice backlog into memory; the resulting fatal error escapes the background runner and kills every background run | `BackgroundRunner::run() - the cron tick (external cron with the cron key, the internal self-request, WP-Cron, or the admin 'Run now' action at admin.php:1111) → Database::fetchAll() -> PDOStatement::fetchAll()` | The background run died: exit status 255 and 'PHP Fatal error: Allowed memory size of 67108864 bytes exhausted (tried to allocate 4194312 bytes) in includes/database.php on line 1010', stack trace PDOStateme… |
| 9 | **MEDIUM** | The stranded-funds export hands the same bearer proofs out in a second live token because it reserves nothing | `admin.php#POST action=export_stranded_funds → includes/invoice.php#exportNamespaceAsToken` | Export #1 returned a cashuB token for 60 sat / 4 proofs and every proof stayed UNSPENT; 'exports recorded in the transfers ledger: 0' |
| 10 | **MEDIUM** | recover_orphaned background task loads and re-polls every 'Processing' invoice with no bound, and the memory fatal it can raise leaves the cron lease held | `background task list → cron lease` | Memory: 20,000 rows -> 44,849,224 bytes (2,242.5 B/row) for the SELECT *, against 9,808,512 bytes (490.4 B/row) for the same query restricted to the five columns the loop reads over the same rows; 128 MiB th… |
| 11 | **MEDIUM** | The mint-URL check resolves the host and discards the answer, so the connection can still land on a private address | `admin.php#POST action=add_backup_mint → Cashu\MintClient::request` | gate (resolver hook): accepted -> http://localhost:18191 (the vetted address is 93.184.216.34); MintClient reached: {"name":"internal-only-service","version":"9.9.9","secret_note":"INTERNAL-DATA-ONLY-REACHAB… |
| 12 | **MEDIUM** | A stranger can mark a merchant's published payment request as paid without paying for it | `public NUT-18 endpoint, no authentication → state change of the request` | STEP 1 plain receive -> HTTP 200 {"success":true,"amount":63,"unit":"sat","proofs_count":6}, and the merchant's wallet row for the token's secret is state SPENT |
| 13 | **MEDIUM** | Published release artifact is built from the maintainer's working tree, not from the tagged commit, and the published checksum attests exactly those unbound bytes | `release procedure → release publication` | Build #1 from the clean tree produced a zip whose contents matched HEAD and the pinned submodule commit, and `shasum -a 256 -c SHA256SUMS` printed 'cashupayserver.zip: OK' |
| 14 | **MEDIUM** | The setup wizard's add_store re-entry lets any admin session reset the admin password with no current password and without retiring any session | `HTTP entry point setup.php; top-level → download_backup` | Anonymous GET setup.php?mode=add_store (&step=2) -> 302 to admin.php, password unchanged |
| 15 | **MEDIUM** | Re-configuring the WooCommerce gateway leaves the previous CashuPay API key valid for the store | `the WordPress setup wizard, reached through /cashupay-setup/ (manage_options) with a validated session CSRF token → PUT /api/v1/stores/{storeId}/webhooks/{webhookId}` | Auth::validateApiKey(first key) returned the store (store_id store_dummy_shop_1) after the second configuration; hasPermission answered ALLOWED for cancreateinvoice, canviewinvoices, canmodifyinvoices, canma… |
| 16 | **MEDIUM** | WordPress sibling installs share one wallet: the second install's administrator reads the first install's seed phrase | `CashuPay admin route, WordPress mode → admin.php, POST action=reveal_seed` | site A: CASHUPAY_DATA_DIR=<scratch>/hosting/cashupay-data, db_path=<scratch>/hosting/cashupay-data/cashupay.sqlite, base_url='https://siteA.example/cashupay', admin_password_hash=NULL, store seed_phrase='DUM… |
| 17 | **MEDIUM** | WordPress multisite: every subsite administrator controls the one shared CashuPay wallet | `CashuPay admin route on a multisite subsite → admin.php, POST action=reveal_seed` | blog 1: CASHUPAY_DATA_DIR=<scratch>/network/cashupay-data, db_path=<scratch>/network/cashupay-data/cashupay.sqlite, base_url='https://msnet.example/cashupay', admin_password_hash=NULL, store seed_phrase='DUM… |
| 18 | **LOW** | The admin 'check' action can flip a token export the operator took back into a completed delivery | `admin POST action=check_transfer (operator/admin session + CSRF, admin.php:782-795) → Transfer::update` | mock mint: http://127.0.0.1:19371 up=yes; 'funded: balance=500'; 'export: transfer=xfer_39f6c1d9afd44903e5cbeacc amount=500 status=pending proofs=6'; 'after reclaim: recovered=499 balance=499 status=failed d… |
| 19 | **LOW** | Unauthenticated admin page discloses the absolute path of the wallet data directory | `public GET /admin.php (no ?api= parameter) → response body of the anonymous admin page` | GET /admin.php answered HTTP 200 with 234171 bytes to a client that sent no cookie and no credential (the response set its own cashupay_session cookie, HttpOnly, SameSite=Lax, for the CSRF token only) |
| 20 | **LOW** | 'Mark recovered' can write off a payment that arrived after the exported token, because the session list is replaced by every re-export | `admin.php#POST action=mark_stranded_recovered → includes/invoice.php#markNamespaceRecovered` | export #1 token proofs: [secret-1,secret-2,secret-3,secret-4] (60 sat); late payment stored: secret-late (64 sat); export #2 token proofs: [secret-1,secret-2,secret-3,secret-4,secret-late] (124 sat); session… |
| 21 | **LOW** | The pairing page redirects the operator to an attacker-chosen origin immediately after they sign in | `front controller path selection (cli-server / rewrite mode) → response header` | GET '//evil.example/api-keys/authorize?...' answers HTTP 200 with the merchant's own pairing page (title 'Authorize Evil - CashuPayServer', the 'Sign In' form, a per-session CSRF token, and a session cookie … |
| 22 | **LOW** | Greenfield API applies no size bound to the request body and persists an uncapped 'checkout' object into the funds database | `api.php#getRequestBody → includes/invoice#Invoice::create` | HTTP 200 for every body size tried (32,769 / 1,048,677 / 4,194,405 / 9,437,285 bytes of request body, using a key that holds only btcpay.store.cancreateinvoice) |
| 23 | **LOW** | The unattended donation-retry loop keeps delivering a pending donation's bearer token to the third-party sink after the operator has switched donations off | `background task reconcile_transfers → outbound transfer of bearer ecash` | Donation::calculateAmount(1000) returned 0 with donations switched off, so no new donation was created, yet the stand-in sink received one POST per reconcile pass (3 passes -> 3 POSTs), each carrying the com… |
| 24 | **LOW** | The store's seed phrase becomes replaceable once the store's current wallet namespace is empty, so a mint or unit change that could not reach its new mint leaves the store's recovery key unprotected | `POST action=update_store, seed_phrase field → Database::update('stores', $updateData, 'id = ?', [$storeId])` | CASE1: with the namespace initialised, the mint change and the unit change were both REFUSED without $allowMintChange ('Wallet seed, mint, and unit are immutable after wallet initialization') and applied wit… |
| 25 | **LOW** | The late-payment recovery nulls the only link to a paid mint quote on a single, possibly stale reading of the mint's quote row, so a paid invoice can never be collected | `cron / WP-Cron / admin 'Run now' trigger of the background runner → local ledger (no remaining collection path)` | Case A: after the first recoverExpiredPaidInvoices(0) the invoice read status=Expired quote_id=NULL; the customer then paid and the mint's quote read amount_paid=21; after re-running recoverExpiredPaidInvoic… |
| 26 | **LOW** | Lightning-address resolution and the callback URL it returns are fetched with no private-address check | `admin.php#POST action=manual_melt → Cashu\LightningAddress::getInvoice` | isSafePublicHttpUrl(https://127.0.0.1:18192/lnurlp-callback) = false, isSafePublicHttpUrl(https://169.254.169.254/latest/meta-data/iam/security-credentials/) = false, isSafePublicHttpUrl(https://127.0.0.1/) … |
| 27 | **LOW** | The admin 'test_mint' action fetches an operator-supplied mint URL without the mint-URL anti-SSRF gate | `admin.php#POST action=test_mint → Cashu\MintClient::request` | gate: refused -> 'That address points to a private or local network, not a public mint |
| 28 | **LOW** | Deleting the CashuPay WordPress plugin can leave its API key and webhook signing secret in the options table | `the WordPress plugin uninstall routine (WP_UNINSTALL_PLUGIN) → the shop's webhook signing secret` | With the canonical URL the option table was emptied and no credential was left |

Severity distribution: **high** × 1, **medium** × 16, **low** × 11.

---

## 4. Confirmed findings in detail

### 4.1 In the WordPress deployment the password prompt on 'Show seed phrase' and 'Download database backup' is not checked, so a hijacked WordPress admin session exports the wallet's master seed and the whole database

**Severity:** HIGH &nbsp;·&nbsp; **Fingerprint:** `includes/auth.php:verifyAdminPassword:wordpress-branch-returns-session-authority` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:846` | The handler reads the posted password and calls Auth::verifyAdminPassword($password) as the only proof of a fresh human presence; a false answer returns 403 'Incorrect password'. |
| 2 | `propagation` | `includes/auth.php:133` | config.admin_password_hash is NULL in the WordPress deployment (the wizard's password step is skipped), so the function returns Urls::isWordPress() && function_exists('current_user_can') && current_user_can('manage_options') without ever looking at the password argument. |
| 3 | `sink` | `admin.php:862` | The store's seed_phrase is returned to the caller (admin.php:862) and, for download_backup, admin.php:887-898 writes and streams a complete VACUUM INTO copy of the whole database - every store's seed phrase, every unspent proof with its bearer secret (cashu_proofs.secret / C), every stores.internal_api_key and every webhook secret (webhooks.secret, webhook_deliveries.signing_secret). |

**Root cause.** Auth::verifyAdminPassword treats the absence of a standalone admin password hash as 'the session is the authority' and returns a capability check - Urls::isWordPress() && current_user_can('manage_options') - instead of a fresh proof of possession. The WordPress deployment has no standalone hash to check against (setup.php:214 skips the password step; admin.php:807-809 refuses change_password in WordPress mode), so the second factor silently collapses into the first. The function has no WordPress-specific branch that asks for a fresh proof (for example a re-authentication check, a WP password prompt, or a nonce tied to a just-entered WordPress password), and the caller cannot tell the two modes apart.

**Intended behaviour.** The code comment at admin.php:834-836 states the intent: the seed is the wallet, so it may be shown again, 'but only behind a fresh password check, never on the strength of an unattended logged-in dashboard'. In the WordPress deployment the equivalent of that fresh check is missing: the WordPress session's manage_options capability is the whole gate, so an unattended (or hijacked) WordPress admin session can export the seed phrase of every store and a complete copy of the wallet database. Either the deployment must supply a genuine second factor (ask the operator for the WordPress password and verify it, or use a WP re-authentication nonce) or the two modes must be distinguished explicitly, with the dashboard copy saying which authority is really required.

**Lower-trust principal and starting capability.** The attacker holds a WordPress administrator session (a stolen cookie, an XSS in wp-admin, a malicious plugin, or a shared administrator account) and can read the dashboard page to obtain the application's CSRF token; no password of any kind is needed. None: the two requests are plain POSTs to the operator's own dashboard URL.

**Conditions.**

- *(system_configuration)* The deployment is the WordPress plugin (CASHUPAY_WORDPRESS defined, wordpress/bootstrap.php:13), so config.admin_password_hash does not exist and the WordPress branch of verifyAdminPassword is taken.
- *(authentication_level)* The attacker holds a WordPress administrator session (a stolen cookie, an XSS in wp-admin, a malicious plugin, or a shared administrator account) and can read the dashboard page to obtain the application's CSRF token; no password of any kind is needed.
- *(user_interaction)* None: the two requests are plain POSTs to the operator's own dashboard URL.
- *(environmental_dependency)* The rate limit of 5 requests per minute for reveal_seed and 3 for download_backup is per derived client address; in the default proxy mode that identity is influenced by forwarded headers when the peer is a private-network address (already tracked as includes/security.php:getClientIp:auto-mode-trusted-private-peer-forwarded-headers), so even the remaining brake is soft. Conversely, where the WordPress site is hardened against file changes the data directory is not reachable for the administrator either, which is what makes this bypass the only route to the seed.

**Bounded, target-native reproduction**

```text
attacker perspective: An attacker who has taken over a WordPress administrator session (cookie theft, XSS in wp-admin, a malicious plugin) but does not know the WordPress password, and who cannot use the operator's unattended browser.

payload: GET /cashupay-admin/ (authenticated as a WordPress administrator) to read the meta[name=csrf-token] value from the dashboard HTML
payload: POST /cashupay-admin/ with body action=reveal_seed&store_id=<store id>&password=&csrf_token=<token from the page>
payload: POST /cashupay-admin/ with body action=download_backup&password=&csrf_token=<token from the page>

steps:
  - Ran tools/sbx.sh v23-w5a --loopback -- bash work/run_all_wp.sh inside the sandbox: the commit 4a82d01 tree was extracted with 'git archive' into the agent scratch, a throwaway data dir was seeded without any admin_password_hash (the WordPress state), and the repository's real admin.php was served by php -S on 127.0.0.1 with an auto_prepend that defines CASHUPAY_WORDPRESS and stubs current_user_can('manage_options') => true, i.e. a WordPress administrator session.
  - Read the dashboard CSRF token, then POST action=reveal_seed with an empty password field, and action=download_backup with an empty password field.
  - Control run: the same two requests against the standalone deployment (admin password set), served the same way.

observed:
  In the local WordPress-mode harness (CASHUPAY_WORDPRESS defined, stubbed current_user_can('manage_options') => true, no network): Config::getAdminPasswordHash() => NULL, Auth::isLoggedIn() => true, Auth::verifyAdminPassword('') => true, Auth::verifyAdminPassword('wrong') => true, Auth::verifyAdminPassword('x') => true, and Auth::changeAdminPassword throws 'This installation has no separate admin password (in WordPress, change your WordPress password instead)'. Over HTTP: POST action=reveal_seed with an empty password -> 200 {"seedPhrase":"DUMMY-SEED-NOT-REAL abandon ... about"}; the same request with a deliberately wrong password -> 200 with the same seed; POST action=download_backup with an empty password -> 200, 221184 bytes, magic 'SQLite format 3', and the dump contains the dummy seed phrase and the raw internal API key. The posted password is never compared with anything in that mode, so the gate that admin.php:846 and admin.php:879 rely on is the session capability check alone. Control in the standalone deployment with the same empty password: 403 'Incorrect password' for both reveal_seed and download_backup.
```

**Impact.** The exported seed phrase is the master key of every store's ecash: whoever holds it can spend the balance at the mint. The backup additionally contains every unspent proof with its bearer secret (cashu_proofs.secret/C), every raw stores.internal_api_key and every webhook secret (webhooks.secret, webhook_deliveries.signing_secret), so the whole shop's funds and its API credentials are taken at once. The impact is bounded only by the fact that a default WordPress administrator can usually already read the CashuPay data directory through the plugin/theme editor (wordpress/bootstrap.php:22-29 puts it outside ABSPATH where possible, but falls back to WP_CONTENT_DIR/cashupay-data inside the webroot); on a hardened site this bypass is the whole difference.

**Likelihood.** The attacker must first obtain a WordPress administrator session (cookie theft, XSS in wp-admin, a malicious or compromised plugin, a shared administrator account). That is a real and recurring precondition for a widely deployed plugin, but it is not the unauthenticated internet, and it is the same class of precondition as a stolen standalone admin session. The affected deployment is a supported, shipped product (README 'WordPress Plugin', release ZIP).

**Why this priority.** The branch is read directly from the source at commit 4a82d01, the deployment condition (no standalone hash in the WordPress wizard, and no activation or admin-menu code that sets one) is traced through setup.php, and the behaviour was reproduced locally over HTTP with a stubbed capability function: an empty and a wrong password were both accepted and the seed plus a full database copy were returned, while the standalone deployment answered 403 for the same requests.

**Smallest source fix.** Give the WordPress deployment a real second factor for the two root-secret exports instead of re-reading the session capability. Options, in order of preference: (1) require a fresh proof of the WordPress administrator's password (a re-authentication prompt, or a short-lived nonce minted only after the operator re-enters it) and check that nonce in reveal_seed and download_backup; (2) if no second factor is available in that deployment, do not offer these two actions there and say plainly in the dashboard that the WordPress session is the only authority - never imply a password check that does not happen; (3) at minimum, make Auth::verifyAdminPassword's WordPress branch explicit (a named method such as verifyFreshAdminProof()) so that no caller can mistake a capability re-read for a password proof, and add an address-independent budget for the two actions so the per-address rate limit is not the only brake (that budget is a separate change; it is named here as a requirement, not as code that already exists).

`includes/auth.php` —     /**
     * Re-check the admin password without touching the session.
     *
     * Used to gate a re-display of the wallet seed, so an unattended dashboard is not
     * enough to walk away with the keys to the money.
     *
     * In WordPress mode there is no standalone admin password, so the caller must supply
     * a fresh proof of its own (a WordPress re-authentication nonce minted after the
     * operator re-entered the WordPress password). Returning the session capability here
     * would make this check a no-op: Auth::isLoggedIn() already verified exactly that.
     */
    public static function verifyAdminPassword(string $password): bool {
        $hash = Config::getAdminPasswordHash();
        if ($hash === null) {
            if (!Urls::isWordPress()) {
                return false; // no password configured: no proof can be given
            }
            // WordPress mode: the proof is a re-authentication nonce, not the capability
            // the session already carries.
            $nonce = (string)($_POST['wp_reauth_nonce'] ?? '');
            if ($nonce === '' || !function_exists('wp_verify_nonce')) {
                return false;
            }
            return (bool)wp_verify_nonce($nonce, 'cashupay_reauth');
        }
        return password_verify($password, $hash);
    }

`admin.php` —             case 'reveal_seed':
                // The seed is the wallet. A lost note otherwise means lost funds, so it can
                // be shown again — but only behind a fresh password check, never on the
                // strength of an unattended logged-in dashboard.
                try {
                    $storeId = $_POST['store_id'] ?? '';
                    $password = (string)($_POST['password'] ?? '');

                    if (!Security::checkRateLimit('reveal_seed', Security::getClientIp(), 5)) {
                        http_response_code(429);
                        echo json_encode(['error' => 'Too many attempts. Please wait a minute.']);
                        break;
                    }
                    if (!Auth::verifyAdminPassword($password)) {
                        // Deliberately slow and vague.
                        usleep(500000);
                        http_response_code(403);
                        echo json_encode(['error' => 'Incorrect password']);
                        break;
                    }

**Supporting evidence**

- `includes/auth.php:131` — verifyAdminPassword returns 'Urls::isWordPress() && current_user_can("manage_options")' when no standalone hash exists - the session's own authority, not a fresh password proof.
- `includes/auth.php:59` — Auth::isLoggedIn() in WordPress mode is the same current_user_can('manage_options') check that admin.php:783 already applied, so the 'fresh' check adds no factor.
- `setup.php:214` — The WordPress wizard jumps from step 1 to step 4, so Auth::setAdminPassword (setup.php:228) is never called in that deployment and config.admin_password_hash never exists. Neither wordpress/activation.php nor wordpress/admin-menu.php sets one.
- `admin.php:807` — change_password refuses in WordPress mode ('In WordPress, change your WordPress password instead'), confirming there is no standalone password to check against there either.
- `admin.php:846` — reveal_seed's only gate is Auth::verifyAdminPassword; the rate limit above it (admin.php:841, 5/minute per derived address) is the only remaining brake in WordPress mode.
- `admin.php:879` — download_backup uses the same gate before writing the full database snapshot (admin.php:887-898); its own limiter allows 3 requests per minute (admin.php:874).
- `admin.php:6538` — The dashboard asks 'Re-enter your admin password to show the seed phrase' and refuses an empty one, so the operator is shown a control the server does not enforce in the WordPress deployment.
- `wordpress/rewrite-rules.php:108` — The /cashupay-admin route itself only requires current_user_can('manage_options') (line 109), the same authority the re-check repeats.

---

### 4.2 An interrupted database-backup download leaves a complete, unencrypted copy of the wallet database in the data directory, where nothing ever removes it and the app's own exposure check never looks for it

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `admin.php:download_backup:snapshot-survives-an-interrupted-response` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:869` | The action dispatch reaches the backup handler. The POST branch is gated at admin.php:782-795 by a logged-in session and a valid CSRF token, and the handler itself demands the admin password again (admin.php:879-884) and rate-limits the action, so this is an operator-only entrypoint: the operator's own backup request is what creates the copy. |
| 2 | `propagation` | `admin.php:887` | The path is Database::getDataDir() . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite' - the data directory, which is <app>/data inside the webroot by default (includes/database.php:48) or wp-content/cashupay-data in the WordPress plugin (wordpress/bootstrap.php:27). Line 888 runs VACUUM INTO, a complete WAL-consistent copy of the database, and line 889 chmods it 0600. |
| 3 | `propagation` | `admin.php:897` | The file is streamed to the client with readfile(). PHP's default is to abort the script when the peer goes away during output, so control never reaches the cleanup statement; there is no ignore_user_abort(true), no try/finally and no register_shutdown_function in the handler. This was observed directly: the marker logged before readfile() was written, the one after it was not. |
| 4 | `sink` | `admin.php:898` | The only deletion of the snapshot. When it does not run, the file stays in the data directory (mode 0600, in a 0700 directory) for as long as the installation lives, because no background task and no exposure probe ever looks at backup-*.sqlite. |

**Root cause.** The snapshot's lifetime is bound to the response instead of to the request: the cleanup (@unlink at admin.php:898) sits after readfile() on the normal path only. PHP aborts a script whose output cannot be delivered, so the unlink is never reached when the client disconnects, and the file - a complete copy of the money-bearing database - persists in the data directory with no expiry, no sweep and no exposure probe covering it.

**Intended behaviour.** The code's own comment says 'it holds spendable proofs; do not leave it lying around' (admin.php:898): the snapshot is meant to exist only for the length of the download. It should survive neither an interrupted response nor a crash, and the data directory's exposure checks should treat a leftover backup-*.sqlite as the emergency it is.

**Lower-trust principal and starting capability.** The leftover file is written by the operator's own successful backup request (admin password plus a session and CSRF token); it is the operator's action that leaves the copy, not an attacker's.

**Conditions.**

- *(system_configuration)* The data directory is inside the webroot and the web server serves it: the default <app>/data for standalone, wp-content/cashupay-data for the WordPress plugin, unless the operator moved it out or set CASHUPAY_DATA_DIR. Apache is covered by .htaccess and the front controller blocks *.sqlite; nginx and other servers that ignore .htaccess are not covered unless the operator applied the README's deny rules, and the application only probes cashupay.sqlite and its sidecars, so a store in that state is warned about the database but not about the leftover copy.
- *(timing_dependency)* The response must be interrupted: the client cancels or the connection drops, a proxy or the browser times out the transfer, PHP hits a fatal error, or the unlink fails. A completed download removes the file, so the ordinary path is safe.
- *(authentication_level)* The leftover file is written by the operator's own successful backup request (admin password plus a session and CSRF token); it is the operator's action that leaves the copy, not an attacker's.
- *(environmental_dependency)* For a remote attacker to read the leftover, the data directory must additionally be reachable over HTTP and the file name must be known or guessed (32 bits of randomness plus a one-second timestamp; no directory listing is assumed).

**Bounded, target-native reproduction**

```text
attacker perspective: A stranger on the internet against a store whose server is nginx (or any server that ignores .htaccess) without the README's deny rules, after the operator has once downloaded a database backup whose transfer was interrupted, and who can obtain or guess the leftover file name.

payload: GET /cashupayserver/data/backup-<Ymd-His>-<8 hex>.sqlite (the path the interrupted download left behind)
payload: the operator's own POST action=download_backup, whose response the client abandons mid-transfer

steps:
  - Serve the exact handler shape (VACUUM INTO -> chmod 0600 -> readfile -> unlink) over a dummy database with a loopback PHP server.
  - Request the download and abort it mid-transfer (curl --limit-rate 300k --max-time 2).
  - List the data directory: the snapshot is still there, mode 0600.
  - Open the snapshot: it is a complete copy of the wallet database (seed phrases, internal API key, webhook secrets, the bearer secret of every unspent proof).
  - Run the same request to completion: the file is gone, confirming the interruption as the cause.
  - Repeat the interrupted request with ignore_user_abort(true): the script now reaches the unlink and the file is removed - the proposed fix.

observed:
  interrupted download: http=200 bytes=663396 of 10510336 in 2 s; the data directory then held backup-20261021-073726-7777b856.sqlite (10,510,336 bytes, mode 0600) and no cleanup task or exposure probe exists for it. The instrumented run logged 'before readfile snapshot=backup-... size=10510336', then the shutdown function, but never 'after readfile' or 'after unlink': the script was terminated inside readfile() and the @unlink at admin.php:898 was never reached. A completed download (10510336 of 10510336 bytes) left the data directory empty, and the same interrupted request with ignore_user_abort(true) logged 'after readfile rv=10510336 connection_aborted=1' and 'after unlink exists=false' - the file was cleaned up.
```

**Impact.** The leftover file is a complete copy of the wallet database: every store's seed phrase (the master key for the funds), every unspent proof with its bearer secret, the raw internal API key, the webhook signing secrets, the admin password hash and the internal cron key. Anyone who obtains it can spend the store's ecash.

**Likelihood.** It needs the data directory inside the webroot on a server that ignores .htaccess (nginx without the README's manual rules) or a rewrite-mode deployment where the front controller never sees the request, an interrupted backup download, and the leftover file name; the operator's own aborted download is the trigger, and 32 bits of randomness make the name impractical to enumerate remotely.

**Why this priority.** Every cited line number was checked at commit 4a82d018 (the worktree carries unrelated uncommitted changes, so the committed state was used), the interruption was reproduced locally with the handler's own steps over a dummy database, the fate of the script was instrumented (it dies inside readfile(), before the unlink), the proposed fix was exercised (ignore_user_abort(true) makes the cleanup run), and the absence of any cleanup or exposure-probe coverage for backup-*.sqlite is a source fact.

**Smallest source fix.** Do not let the snapshot's lifetime depend on the response completing: set ignore_user_abort(true) so the script runs to the end even when the client disappears, wrap the streaming in try/finally with the unlink in the finally, and register a shutdown handler as a backstop for fatal errors. The local check shows ignore_user_abort(true) is the load-bearing part: with it the script reaches the unlink and the file is removed; without it the request ends in a bailout inside readfile() - the shutdown function still runs on that path, but the rest of the handler does not, so a shutdown handler is a usable backstop while ignore_user_abort(true) is what guarantees the normal path. Add a scheduled sweep that deletes backup-*.sqlite older than an hour (the existing clean_cache task is the natural place), and extend every web-exposure probe - the dashboard's (admin.php:338), the setup preflight (setup.php:545) and the WordPress notice (wordpress/admin-menu.php:192) - to include backup-*.sqlite and setup-token*.txt, so a leftover copy is reported rather than silently sitting there. Consider writing the snapshot outside the webroot (next to the data directory, or a temporary directory) instead of inside it.

`admin.php` —                 @set_time_limit(0);
                // The snapshot holds spendable proofs: it must not outlive the request,
                // not even when the client walks away mid-download.
                @ignore_user_abort(true);
                $snapshot = Database::getDataDir() . '/backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
                try {
                    Database::getInstance()->exec('VACUUM INTO ' . Database::getInstance()->quote($snapshot));
                    @chmod($snapshot, 0600);

                    $name = 'cashupay-backup-' . date('Ymd-His') . '.sqlite';
                    header_remove('Content-Type');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $name . '"');
                    header('Content-Length: ' . (string)filesize($snapshot));
                    header('X-Content-Type-Options: nosniff');
                    readfile($snapshot);
                } finally {
                    @unlink($snapshot); // it holds spendable proofs; do not leave it lying around
                }
                exit;

`includes/security.php` —     public static function cleanCache(): void {
        self::ensureCacheTable();
        Database::query('DELETE FROM rate_limits WHERE expires < ?', [time()]);

        // Sweep the pre-SQLite file cache away on first run after an upgrade.
        $legacyDir = __DIR__ . '/../data/cache';
        if (is_dir($legacyDir)) {
            foreach (glob($legacyDir . '/*.cache') ?: [] as $file) {
                @unlink($file);
            }
        }

        // A backup snapshot that outlived its request is a complete copy of the wallet
        // database (seed phrases and bearer proofs). Nothing should keep it around.
        foreach (glob(Database::getDataDir() . '/backup-*.sqlite') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

**Supporting evidence**

- `admin.php:887` — The snapshot is written into Database::getDataDir() - the default <app>/data inside the webroot, WP_CONTENT_DIR/cashupay-data in the WordPress plugin - with a name that mixes a timestamp and 32 bits of randomness.
- `admin.php:888` — VACUUM INTO copies the whole database, so the snapshot holds every secret the database holds (config rows: admin password hash, seed phrase, cron key; stores.internal_api_key and stores.seed_phrase; api_keys.key_hash; webhooks.secret; cashu_proofs.secret), not just the rows the operator meant to export.
- `admin.php:898` — The unlink is the last statement of the success path; there is no ignore_user_abort(true), no try/finally and no register_shutdown_function anywhere in the handler (the only ignore_user_abort(true) in admin.php is line 1109, in the unrelated run_background action).
- `includes/security.php:851` — cleanCache() - the only scheduled cleanup (background task 'clean_cache', includes/background_runner.php:132) - deletes expired rate_limits rows and legacy data/cache/*.cache files; it never removes backup-*.sqlite, and no other glob in the codebase touches backup-*.
- `admin.php:338` — checkDataDirectoryProtection probes only cashupay.sqlite, cashupay.sqlite-wal and cashupay.sqlite-shm, so a leftover backup-*.sqlite is never reported to the operator.
- `setup.php:545` — setupProbeDataExposure probes the same three file names only; the WordPress probe (wordpress/admin-menu.php:192-197) adds setup-token*.txt but not backup-*.sqlite.
- `includes/database.php:297` — The data directory's protection is an .htaccess with 'Require all denied' (plus an index.php), which nginx ignores; the README's nginx location rules (README.md:150-172, which do include a deny for the data/ location and one for \.sqlite$) are the only protection there and they are manual.
- `router.php:31` — The front controller blocks any request path matching '#/data/#i' or '#\.sqlite#i' with 403 (router.php:28-44), so a leftover snapshot is not downloadable through router.php; in rewrite mode (nginx try_files) the web server serves the file directly and never reaches the router, so this control does not cover that deployment.
- `admin.php:886` — Independent local reproduction of the handler shape (VACUUM INTO -> chmod 0600 -> readfile -> unlink) over a dummy database on a loopback PHP server: an interrupted download (curl --limit-rate 300k --max-time 2, http=200, 663,396 of 10,510,336 bytes) left backup-20261021-073726-7777b856.sqlite (10,510,336 bytes, mode 0600) in the data directory; an instrumented build logged 'before readfile' and then, after the client vanished, the shutdown function - but never the statements after readfile(), so the unlink did not run. A completed download (10,510,336 of 10,510,336 bytes) left the data directory empty, and the same interrupted request with ignore_user_abort(true) removed the file ('after readfile ... connection_aborted=1', 'after unlink exists=false').

---

### 4.3 The trusted-proxy setting accepts a range that covers the whole internet, so every visitor can choose the address that keys the admin lockout and the API quota

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `admin.php:save_proxy_settings:unbounded-trusted-proxy-range` &nbsp;·&nbsp; **Confidence:** medium

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:906` | The operator posts mode, trusted_proxies and behind_cloudflare. The action is behind the admin session and CSRF checks at admin.php:782-795. |
| 2 | `propagation` | `admin.php:919` | Each whitespace/comma separated entry is passed to Security::normalizeProxyEntry and is rejected only when that returns null. |
| 3 | `propagation` | `includes/security.php:647` | The only width check is `(int)$bits <= $max` (max 32 for IPv4, 128 for IPv6), so a prefix of 0 or 1 is a valid entry and 0.0.0.0/0 and ::/0 pass. |
| 4 | `propagation` | `admin.php:926` | Config::set('trusted_proxies', ...) stores the accepted entries in the config table (includes/config.php:69-82); no range-width check, warning or confirmation is applied. |
| 5 | `propagation` | `includes/security.php:666` | The stored range is matched against every peer, in every proxy mode except 'direct' (the mode gate is at includes/security.php:658-661), so a 0.0.0.0/0 entry makes every IPv4 peer a trusted proxy. |
| 6 | `propagation` | `includes/security.php:564` | For a trusted peer, getClientIp falls back to the client-supplied X-Real-IP (no check that the proxy set it) and uses it as the client address. |
| 7 | `propagation` | `includes/security.php:20` | The 100/min API quota is keyed as rate_{action}_{client address} (api.php:52-61), so the budget is scoped on an address the client chose. |
| 8 | `sink` | `includes/security.php:67` | The five-attempt admin login lockout is keyed as login_attempts_{client address} (includes/security.php:13, includes/auth.php:113-123, admin.php:750), so the brute-force budget is scoped on an address the client chose. |

**Root cause.** Security::normalizeProxyEntry (includes/security.php:635-650) checks only that the prefix length does not exceed the address width, and the save_proxy_settings handler (admin.php:915-927) stores every accepted entry without bounding how much of the address space it covers; a trust-widening value therefore crosses the write path with no bound, no warning and no confirmation, and Security::isTrustedProxy (includes/security.php:666-670) applies it to every peer.

**Intended behaviour.** A setting that decides which hops may speak for the visitor should only accept ranges narrow enough to be a real proxy set: a range that covers the whole address space must be refused with an explanation, and the dashboard must show how much trust the current setting grants so that a silent widening (or a stale list that stays in force after the operator switches back to "Automatic") cannot go unnoticed.

**Lower-trust principal and starting capability.** Operator/admin session with a valid per-session CSRF token: the write path itself is not reachable anonymously or cross-site (admin.php:782-795). The operator saved the setting once; afterwards no further interaction from the operator or the attacker is needed.

**Conditions.**

- *(authentication_level)* Operator/admin session with a valid per-session CSRF token: the write path itself is not reachable anonymously or cross-site (admin.php:782-795).
- *(system_configuration)* The operator has saved a trusted-proxy range covering the peer's whole address family, for example '0.0.0.0/0' for IPv4 peers or '::/0' for IPv6 peers. The field is documented as 'your hosting provider can tell you the addresses' (admin.php:3847) and providers routinely hand out those two forms, so the value is both plausible and never what the operator meant.
- *(user_interaction)* The operator saved the setting once; afterwards no further interaction from the operator or the attacker is needed.
- *(network_routing)* The attacker connects from an address in the covered family, directly, with no proxy in front of the server; the request needs only an X-Real-IP header (a request with an X-Forwarded-For header alone does not move the identity, because every hop of the chain is inside the trusted range).

**Bounded, target-native reproduction**

```text
attacker perspective: An unauthenticated remote client of the shop's server, after the operator has saved an all-addresses proxy range in the dashboard's "Visitor addresses" card.

payload: operator step: POST action=save_proxy_settings with mode=custom&trusted_proxies=0.0.0.0/0&behind_cloudflare=0 (admin session + CSRF token)
payload: attacker step: request with peer address 203.0.113.55 and header 'X-Real-IP: 198.51.100.7'
payload: attacker step: 20 consecutive such requests, each with a different X-Real-IP value
payload: control: the same requests with the default configuration (mode 'auto', no trusted range)

steps:
  - Replay the admin.php:906-934 handler body against a scratch database (dummy data only) and save the three settings exactly as the handler does.
  - For each of 20 requests set REMOTE_ADDR to a public address, rotate the X-Real-IP header and call Security::getClientIp() and then Security::reserveLoginAttempt() with the result; repeat with Security::checkRateLimit('api', <address>, 100) for 150 requests.
  - Repeat the whole run with the default configuration (mode 'auto', no trusted range) as the control.

observed:
  The handler accepted and stored trusted_proxies = ["0.0.0.0/0"] without any warning or confirmation. isTrustedProxy('203.0.113.55') became true, and getClientIp() returned the attacker's X-Real-IP 198.51.100.7 for that direct public peer. The five-attempt login lockout then reserved 20 of 20 attempts and the 100/min API quota allowed 150 of 150 requests, against 5 of 20 and 100 of 150 in the control run. With '::/0' the same happened for an IPv6 peer (20 of 20 reserved). With mode 'auto' the stored 0.0.0.0/0 still applied (isTrustedProxy true, getClientIp 198.51.100.7); only mode 'direct' ignored it. With CASHUPAY_TRUSTED_PROXIES = '0.0.0.0/0' the trust survived emptying the stored list from the dashboard.
```

**Impact.** With every peer trusted, the address that keys the five-attempt admin login lockout and every per-address quota becomes a client-chosen value: an unauthenticated remote client can make unlimited password guesses and unlimited API requests (20 of 20 login attempts and 150 of 150 API requests observed locally, against 5 of 20 and 100 of 150 without the setting).

**Likelihood.** One operator entry is needed, but the field is explicitly filled with provider-supplied addresses ('your hosting provider can tell you the addresses', admin.php:3847), providers routinely hand out 0.0.0.0/0 and ::/0, and the entry looks like an ordinary range; the dashboard gives no warning and no sign of the trust scope afterwards, and the value keeps applying after the operator switches back to 'Automatic'.

**Why this priority.** The accepted value set, the stored effect, the identity derivation and the quota bypass are all source-visible and were reproduced locally against dummy data; what remains uncertain is only how often an operator actually saves such a range, which is why the severity is medium rather than high.

**Smallest source fix.** Bound the trust the write path may grant, refuse the values that can never be a proxy set with an explanation the operator can act on, apply the same bound to the deployment constant, and show the trust scope in the dashboard instead of only the address of the current request.

`includes/security.php` — /** Widest range that may still be a real proxy set. */
private const MIN_PROXY_PREFIX_V4 = 8;
private const MIN_PROXY_PREFIX_V6 = 32;

/** Why an entry cannot be used, in words the operator can act on. */
public static function proxyEntryRejectionReason(string $entry): ?string {
    $entry = trim($entry);
    if (strtolower($entry) === 'cloudflare') {
        return null;
    }
    if (!str_contains($entry, '/')) {
        return self::normalizeIp($entry) === null
            ? "\"{$entry}\" is not an address like 203.0.113.5."
            : null;
    }
    [$ip, $bits] = explode('/', $entry, 2);
    $ip = self::normalizeIp($ip);
    if ($ip === null || !ctype_digit($bits)) {
        return "\"{$entry}\" is not an address like 203.0.113.5 or a range like 10.0.0.0/8.";
    }
    $isV6 = str_contains($ip, ':');
    $prefix = (int)$bits;
    if ($prefix > ($isV6 ? 128 : 32)) {
        return "\"{$entry}\" has more bits than an address of that kind has.";
    }
    if ($prefix < ($isV6 ? self::MIN_PROXY_PREFIX_V6 : self::MIN_PROXY_PREFIX_V4)) {
        return "\"{$entry}\" covers almost every address on the internet, so every visitor could pick "
             . "their own address for the login and request limits. Enter the addresses of your proxy "
             . "instead (for example 10.0.0.0/8).";
    }
    return null;
}

// normalizeProxyEntry() then uses the same rule:
//   if (self::proxyEntryRejectionReason($entry) !== null) { return null; }

`admin.php` — // case 'save_proxy_settings':
foreach (preg_split('/[\s,]+/', (string)($_POST['trusted_proxies'] ?? '')) as $raw) {
    if ($raw === '') {
        continue;
    }
    $reason = Security::proxyEntryRejectionReason($raw);
    if ($reason !== null) {
        throw new Exception($reason);
    }
    $entry = Security::normalizeProxyEntry($raw);
    if ($entry === null) {
        throw new Exception("\"{$raw}\" is not an address like 203.0.113.5 or a range like 10.0.0.0/8.");
    }
    $entries[] = $entry;
}
Config::set('proxy_mode', $mode);
// The list only applies in 'custom' mode, so it is cleared when the operator picks another mode.
Config::set('trusted_proxies', $mode === 'custom' ? array_values(array_unique($entries)) : []);

`includes/security.php` — /** in describeClientIp(): report the trust scope, not only the address of this request */
'trustsEveryPeer' => self::trustedProxyRangesCoverEveryPeer(),

/** Whether the configured ranges make every peer of a family a trusted proxy. */
private static function trustedProxyRangesCoverEveryPeer(): bool {
    foreach (self::trustedProxyRanges() as $range) {
        if ($range === 'cloudflare') {
            continue;
        }
        if (self::ipInRange('203.0.113.1', $range) && self::ipInRange('198.51.100.1', $range)) {
            return true;
        }
    }
    return false;
}

**Supporting evidence**

- `includes/security.php:647` — The prefix check only rejects lengths greater than the address width, so 0.0.0.0/0, 0.0.0.0/1 and ::/0 are accepted entries.
- `admin.php:919` — An operator-supplied entry is rejected only when normalizeProxyEntry returns null; there is no separate bound on how wide the range may be.
- `admin.php:926` — The accepted entries are written to the configuration as they are, without a warning, a confirmation step or a narrower set.
- `admin.php:950` — The sibling action save_base_url asks for an explicit confirmation when the new value points the server somewhere else; save_proxy_settings has no equivalent step for a value that widens the trust set to the whole internet.
- `includes/security.php:666` — The stored range is consulted for every peer in every mode except 'direct', so a wide entry also stays in force after the operator selects 'Automatic'.
- `includes/security.php:564` — X-Real-IP is returned as the client address whenever the peer is trusted and no untrusted forwarded hop was found, so a direct client behind a wide trusted range speaks for itself.
- `includes/security.php:13` — MAX_LOGIN_ATTEMPTS is 5 and the lockout is per address; that is the whole brute-force budget for the admin password.
- `includes/security.php:20` — rate_{action}_{identifier}: the client address is the only scope of every quota.
- `api.php:53` — The API quota is taken from Security::getClientIp() before authentication, so a client-chosen identity also defeats the pre-authentication quota.
- `includes/security.php:624` — describeClientIp reports the address of the current request only; it never reports how wide the trusted set is, so the operator cannot see the change they just made.
- `admin.php:5421` — The dashboard posts the proxy list on every save whatever the selected mode, so a list saved once is stored again and stays in force.
- `includes/security.php:687` — CASHUPAY_TRUSTED_PROXIES reaches the same trust decision without passing through normalizeProxyEntry, so the same unbounded range can be configured by deployment and cannot be removed from the dashboard.

---

### 4.4 An unauthenticated token POST to receive.php costs ~10 s of server CPU per request (one NUT-12 DLEQ verification per proof, before every cheap rejection) while the per-IP limiter still allows 20 requests per minute

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `cashupayserver.receive.dleq-per-proof-cost` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `receive.php:123` | The POST branch reads php://input (136) and takes store_id and token from the JSON body or the form fields (147-160). No session, API key or CSRF token is required; the principal is an anonymous visitor. |
| 2 | `propagation` | `receive.php:128` | Security::checkRateLimit('receive', Security::getClientIp(), 20) is the single gate. includes/security.php:12,19-54 implements it as a fixed 60 s window keyed by 'rate_receive_<ip>': it caps the number of requests per address per minute and knows nothing about what a request costs. |
| 3 | `propagation` | `receive.php:224` | The token is accepted as long as it is at most TokenSerializer::MAX_TOKEN_LENGTH = 262144 characters (cashu-wallet-php/CashuWallet.php:3400). Nothing here bounds the number of proofs inside it beyond MAX_PROOFS = 1000 (3403); 893 DLEQ-bearing cashuB proofs fit in 262,057 characters. |
| 4 | `propagation` | `receive.php:230` | TokenSerializer::deserialize parses the token and accepts up to 1000 proofs (cashu-wallet-php/CashuWallet.php:3543 for cashuA, 3610 for cashuB) without de-duplicating them. |
| 5 | `propagation` | `receive.php:332` | $wallet->receive($tokenString) is called with the submitted token string; the wallet deserializes it a second time (cashu-wallet-php/CashuWallet.php:8066) and resolves its keyset ids again (8069). |
| 6 | `sink` | `cashu-wallet-php/CashuWallet.php:8076` | For every proof whose DLEQ carries a non-empty blinding factor (8073-8074) Crypto::verifyProofDleq runs (1222-1249): hash_to_curve of the secret plus six secp256k1 scalar multiplications (1184-1215, 730-753). Measured 11.5 ms per proof on the GMP backend, so a token with 893 DLEQ proofs costs ~10.3 s of CPU in one request. The loop sits before the spent-state pre-check (8094) and before the duplicate-input rejection reached through swap() (8119 -> 5163). |

**Root cause.** Wallet::receive() (cashu-wallet-php/CashuWallet.php:8064) performs a per-proof cryptographic verification whose cost is proportional to the number of proofs in the token, and it does so before the two cheap rejections that would normally discard such a token (the duplicate-input check at 5163 and the NUT-07 spent-state check at 8094). Nothing de-duplicates the proofs of a token, and the only bound on their number is MAX_PROOFS / MAX_TOKEN_LENGTH, which admits ~893 DLEQ-bearing proofs. The endpoint's rate limit (receive.php:128) was written for a request that costs a mint round-trip, not for one that costs 893 secp256k1 verifications, so it bounds the request count but not the work.

**Intended behaviour.** A payment arrives in a handful of proofs and a token that repeats one proof hundreds of times is not a payment. The endpoint should reject such a token after a bounded, small amount of work (milliseconds), and the limiter should be sized for the cost of the request it protects. Today the per-request work is bounded only by the token byte length, which turns one unauthenticated POST into ten seconds of CPU.

**Lower-trust principal and starting capability.** Anonymous: receive.php's POST branch has no session, API key or CSRF requirement.

**Conditions.**

- *(authentication_level)* Anonymous: receive.php's POST branch has no session, API key or CSRF requirement.
- *(third_party_dependency)* The store's mint must issue NUT-12 blind signatures (the DLEQ fields e and s); the sending wallet then adds its own blinding factor r to the proof it publishes (cashu-wallet-php/CashuWallet.php:6785-6789), so the proofs in a received token carry dleq.r and the loop at 8074 is entered. The repository's own mock mint returns a DLEQ with every signature (tests/mock_mint_router.php:170), which is what the end-to-end check used. A mint that never returns a DLEQ proof leaves dleq.r empty and the loop is skipped, so the cost collapses to ~0.05 s.
- *(third_party_dependency)* The attacker needs the store's mint URL (published at receive.php:741) and one genuine proof from that mint; they can mint one themselves, because the mint is a public service.
- *(system_configuration)* ext-gmp installed: 11.6 ms per DLEQ verification. Without it the BCMath backend costs 339 ms per verification, so the same request is ~300 s of CPU.
- *(network_routing)* The shared resource is the PHP request-handling pool, which also serves the operator's payment pages, admin panel and webhook delivery. The pool size and max_execution_time are deployment facts and are not in the repository; the measured effect (one worker occupied for 10.3 s, ~3.4 workers busy from one address at the permitted rate) holds for any pool of that size.
- *(timing_dependency)* The 20/min budget is a fixed window, so the attacker's duty cycle is one request every three seconds; the effect stops as soon as the requests stop and needs no persistence.

**Bounded, target-native reproduction**

```text
attacker perspective: An anonymous visitor with no account and no credential for the shop. They read the store's mint URL from the public GET path of receive.php, mint one proof for themselves at that public mint (one Lightning payment, or a free test mint), and then POST a single token that repeats that proof hundreds of times.

payload: One cashuB token of 893 copies of a single NUT-12 proof from the store's mint (id = the mint's keyset id, amount = 1, secret = 64 hex chars, C = 66 hex chars, dleq = {e,s,r} of 64 hex chars each), 262,057 characters - inside the 262,144-character cap. Submitted as POST /receive.php with the body {"store_id":"<store_id>","token":"<token>"}.
payload: Fixture for the in-process check: the proof block of cashu-wallet-php/tests/fixtures/nut12-dleq.json, whose A is 0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798.

steps:
  - Start the repository's own mock mint on loopback: MOCK_MINT_STATE=<scratch>/mint.json php -S 127.0.0.1:<port> tests/mock_mint_router.php (it signs every signature with a NUT-12 DLEQ).
  - Create a scratch data dir, Database::initialize(), insert a store row pointing at that mint, and bind its wallet with Invoice::initializeWalletForStore('store_1', false, $mintUrl, 'sat').
  - Mint one proof at that mint as the attacker: requestMintQuote(1), POST /__control/pay {quote}, then $wallet->mint($quote->quote, 1); the proof carries dleq.r.
  - Build the token with TokenSerializer::serializeV4($mintUrl, 893 copies of that proof, 'sat', null, true) and POST it to the real receive.php over loopback (router: define('CASHUPAY_DATA_DIR', <scratch>); require receive.php).
  - Measure the request's wall time and the server process's own getrusage(), which a shutdown function in the router records after each request; repeat with the same token built without the blinding factor (dleq.r = null) and with a token the mint already reports as SPENT.

observed:
  One POST of the 893-proof token: HTTP 400 {"error":"Cannot reserve duplicate input proofs"} after 10.32 s wall and 10.25 s of server CPU (PHP 8.5.11, GMP). The same token with the blinding factor removed: HTTP 400 in 0.075 s wall and 0.053 s CPU, so the DLEQ loop is 99.5% of the cost. The cost is linear in the proof count: 100 proofs 1.17 s, 300 proofs 3.52 s, 600 proofs 6.95 s, 893 proofs 10.25 s (11.5 ms per proof). A token the mint already refuses as SPENT still cost 10.37 s of CPU, which places the NUT-07 check after the DLEQ loop. The per-IP limiter answered the first 20 requests and the 21st with 429, so one address may demand 20 x 10.25 s = 205 CPU-seconds and hold about 3.4 workers busy every minute. In-process, Wallet::receive() with the NUT-12 fixture reproduces the same numbers (893 proofs -> 10.38 s CPU; without dleq.r -> 0.0175 s; tampered first proof -> 0.030 s).
```

**Impact.** One request occupies a request worker for 10.3 s and burns 10.25 s of CPU, and the permitted rate lets one address keep about 3.4 workers continuously busy and demand ~205 CPU-seconds per minute; the same pool serves the shop's payment pages, admin panel and webhook delivery. No funds, keys or data are exposed and the effect stops when the requests stop.

**Likelihood.** Unauthenticated, one HTTP request every three seconds from a single address, no credentials and no persistence; the payload is one token built from a proof anyone can mint at the shop's public mint.

**Why this priority.** The cost, the ordering of the checks and the limiter's behaviour were all measured end to end against the real receive.php with the repository's own mock mint, in a sandbox with no external network; the only deployment facts left out are the worker-pool size and the big-integer backend, which change the magnitude but not the defect.

**Smallest source fix.** Make the work proportional to what a payment actually contains instead of to the token's byte size: verify each distinct proof's DLEQ once, refuse duplicate inputs before the curve work, and cap the number of proofs a received token may carry so that one request cannot cost seconds. Once a request costs milliseconds, the existing 20/min limiter is adequate.

`cashu-wallet-php/CashuWallet.php` —         // NUT-12: if a received proof carries a full DLEQ (with blinding factor),
        // wallets MUST verify it — it proves the mint actually signed this proof.
        // A token may repeat the same proof many times: verify each distinct proof
        // once, and refuse duplicate inputs before the curve work rather than after
        // it (the swap refuses them anyway, but only once every copy was verified).
        $seenProofs = [];
        $seenSecrets = [];
        foreach ($token->proofs as $proof) {
            if (isset($seenSecrets[$proof->secret])) {
                throw new CashuException('Cannot reserve duplicate input proofs');
            }
            $seenSecrets[$proof->secret] = true;
            $key = $proof->secret . '|' . $proof->id . '|' . $proof->amount . '|' . $proof->C;
            if (isset($seenProofs[$key])) {
                continue;
            }
            $seenProofs[$key] = true;
            if ($proof->dleq !== null && $proof->dleq->r !== null && $proof->dleq->r !== '') {
                $A = $this->getPublicKey($proof->id, $proof->amount);
                if (!Crypto::verifyProofDleq($proof, $A)) {
                    throw new CashuException('Received token contains an invalid DLEQ proof');
                }
            }
        }

`receive.php` —     // A payment arrives in a handful of proofs. A token carrying hundreds of them
    // is work for the server, not a payment: refuse it before the wallet spends
    // seconds verifying each proof's DLEQ (CashuWallet.php:8073).
    if (count($parsed->proofs) > 64) {
        http_response_code(400);
        echo json_encode(['error' => 'Token has too many proofs']);
        exit;
    }
    // Receive the token
    $proofs = $wallet->receive($tokenString);

**Supporting evidence**

- `cashu-wallet-php/CashuWallet.php:8073` — The DLEQ loop runs over every proof of the token before any cheap rejection; there is no de-duplication and no per-request cap on the number of verifications.
- `cashu-wallet-php/CashuWallet.php:8094` — The NUT-07 spent-state pre-check runs only after the DLEQ loop and only when the mint advertises NUT-07, so a token the mint already refuses as SPENT still pays the full DLEQ cost (measured: 10.37 s CPU).
- `cashu-wallet-php/CashuWallet.php:5163` — 'Cannot reserve duplicate input proofs' is raised inside preparePendingSpend, which the swap reaches only at 8119 - after the DLEQ loop has already verified every copy.
- `cashu-wallet-php/CashuWallet.php:3400` — MAX_TOKEN_LENGTH = 262144 is the only bound on the attacker's input; 893 cashuB proofs with full NUT-12 DLEQ fields fit in 262,057 characters, so the byte cap admits ~893 DLEQ verifications per request.
- `cashu-wallet-php/CashuWallet.php:1228` — verifyProofDleq parses the attacker-controlled blinding factor with BigInt::fromHex before the curve work, which is the entry point of the separate quadratic-parse variant.
- `receive.php:741` — The public GET path of the same endpoint returns the store's mint URL ('mint' => $mintUrl) to any anonymous visitor, so the attacker does not need to guess the mint whose keyset ids and pubkeys a token must match (receive.php:249, 8075).
- `includes/security.php:12` — RATE_LIMIT_WINDOW = 60 and the counter at 19-54 price the request count, not the request cost; the 20/min budget is unchanged by a request that became 500x more expensive than the mint round-trip it was written for.

---

### 4.5 Automatic withdrawal sizes an irreversible Lightning payout from a third-party rate the money-out path never verifies, and applies no exchange-fee treatment to it

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `includes/lightning_address.php:autoMeltAccount:payout-amount-from-unverified-rate` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `includes/background_runner.php:128` | The unattended background task list that cron.php, WP-Cron and the admin 'Run now' button execute. 'auto_melt' runs on its own, without any operator present. |
| 2 | `propagation` | `includes/background_runner.php:750` | Calls LightningAddress::checkAutoMelt() and maps its per-store entries into the task result; the task health only records 'error' strings, never the rate that was used. |
| 3 | `propagation` | `includes/lightning_address.php:297` | Selects every store with auto_melt_enabled = 1 and a non-empty auto_melt_address, takes the 'funds_<storeId>' lock and calls autoMeltAccount() for each of that store's accounts (line 344). The store's price_provider_primary/secondary are read straight from the stores row (lines 300-301). |
| 4 | `propagation` | `includes/lightning_address.php:434` | The account balance minus the donation is converted to satoshis with ExchangeRates::convertMintUnitToSats($meltAmountInMintUnit, $mintUnit, $store['price_provider_primary'], $store['price_provider_secondary']). No exchange fee is applied (the function has no such argument), the conversion truncates, and a flat 1 % buffer of at most 100 sats is subtracted at line 444. Nothing here re-checks the rate, and the call sits outside the try/catch that guards the melt. |
| 5 | `propagation` | `includes/rates.php:589` | toBtc() (rates.php:374-396) turns the fiat balance into BTC with getBtcPrice($currency, $primary, $secondary) at rates.php:390 - the same function, and therefore the same tolerance rules, that price invoices. |
| 6 | `propagation` | `includes/rates.php:265` | A provider's answer is accepted and cached when it is within SANITY_BAND (50 %, rates.php:296-302) of the last known rate; with no reference rate a lone answer is accepted unconditionally (rates.php:283-286); if nothing is accepted the stale cached rate (up to one hour old, rates.php:189, 292, 528-543) is used. The accepted value is stored under the currency key alone (rates.php:548), shared by every store and by the invoice path. |
| 7 | `propagation` | `includes/rates.php:592` | The accepted rate becomes the payout amount by truncation: (int)bcmul($btcAmount, '100000000', 0). Unlike convertToMintUnit() (rates.php:361-368), which applies the store's exchange fee and rounds up, the money-out side applies no fee and always rounds down. |
| 8 | `sink` | `includes/lightning_address.php:451` | The computed satoshi amount is paid to the operator's auto_melt_address: the address is asked for an invoice for exactly that many satoshis (line 80) and the mint pays it (line 227). The payment is irreversible, the amount was never shown to or confirmed by the operator, the transfer row (includes/database.php:508-524) stores the mint-unit quote amount with no rate, and the log line at line 476 prints the balance in mint units next to the satoshis sent. |

**Root cause.** LightningAddress::autoMeltAccount() (includes/lightning_address.php:415-448) sizes the payout with ExchangeRates::convertMintUnitToSats() (includes/rates.php:566-593) and pays the result without any money-out-specific check. convertMintUnitToSats() has no $exchangeFeePercent parameter (unlike convertToMintUnit(), rates.php:333-369), truncates its result (rates.php:592), and takes its rate from getBtcPrice(), whose acceptance rule tolerates one provider's answer within 50 % of the last known rate and any answer at all when no reference exists. autoMeltAccount() neither re-checks that rate against a second source or a staleness bound, nor applies any exchange-fee treatment, nor records the rate or the satoshi amount, nor asks the operator to confirm before paying; and the rate cache is keyed by currency alone (rates.php:548 'rate_eur'), so the rate that sizes one store's payout may have been fetched and accepted on another store's provider set or by the invoice path.

**Intended behaviour.** The automatic withdrawal should move the merchant's balance to the operator's Lightning address at a rate that is trustworthy for money-out, within a tolerance the operator has configured, and it should tell the operator what it is sending. The amount should be derived from the rate the money actually changes hands at (the mint's own melt quote, as the manual withdrawal's estimate already computes at admin.php:1696-1704) or from a rate that is fresh and inside the store's configured exchange_fee_percent tolerance; a rate that fails that test should stop the withdrawal with a plain operator-facing message instead of moving money irreversibly; and the amount in satoshis and the rate used should be visible in the transfer ledger and in the log line, which today prints the balance in mint units rather than the amount sent.

**Lower-trust principal and starting capability.** The payout runs unattended (cron.php, WP-Cron, or the admin 'Run now' button). No operator session is involved, so there is no confirmation step between the rate and the payment.

**Conditions.**

- *(system_configuration)* The store's mint unit is a fiat unit (eur, usd, ...), not sat/msat. Only then does the payout go through a rate at all; a sat-denominated store withdraws its balance without any conversion.
- *(system_configuration)* Automatic withdrawal is enabled for the store (auto_melt_enabled = 1) with a non-empty auto_melt_address, and the account balance has reached auto_melt_threshold so the cron tick withdraws it.
- *(third_party_dependency)* The price provider answers with a value that CashuPayServer's own tolerance accepts: within 50 % of the last known rate, or any value at all when there is no cached reference (cold start, or the cached rate is older than 24 h, rates.php:298-312). A provider glitch, a compromised provider response, or a market move larger than the merchant's fee tolerance all satisfy this.
- *(environmental_dependency)* The mint prices the melt near the market rate. If the mint prices more favourably than the accepted rate, the mis-sized payout does not fail; if it prices at the market, a rate 30 % low makes the melt quote exceed the balance and the withdrawal fails on every run.
- *(authorization_role)* The payout runs unattended (cron.php, WP-Cron, or the admin 'Run now' button). No operator session is involved, so there is no confirmation step between the rate and the payment.
- *(data_state)* The merchant holds a fiat-denominated ecash balance, which is the money that is being moved and the resource that is left behind at the mint when the rate is high.

**Bounded, target-native reproduction**

```text
attacker perspective: A third party the operator never chose for their withdrawals - the price API behind the store's rate provider (or anyone able to influence its answer) - returns a rate that CashuPayServer's own tolerance accepts. The merchant's fiat balance is then converted at that rate and paid out, irreversibly and unattended, to the operator's Lightning address; the operator is not shown the amount or the rate, the store's configured exchange-fee tolerance is not applied, and the ledger records neither the rate nor the satoshi amount.

payload: Stub price provider 'coingecko' returning 75000.0 EUR/BTC while the cached reference is 50000.0 (exactly +50 %, accepted by the 50 % band) - sats requested for a 10000-cent balance: 133233 instead of 199900
payload: Stub price provider 'coingecko' returning 500000.0 EUR/BTC with no cached reference rate (accepted unconditionally) - sats requested: 19900 instead of 199900, i.e. 9.95 EUR of market value for a 100.00 EUR balance
payload: Stub price provider 'coingecko' returning 35000.0 EUR/BTC (-30 %, accepted by the band) while the mint prices at 50000 - sats requested: 285614, mint quote 14281 cents against a 10000-cent balance, so the melt is refused with 'Insufficient balance'
payload: Dummy store row: mint_unit 'eur', exchange_fee_percent 2, price_provider_primary 'coingecko', price_provider_secondary 'binance', auto_melt_enabled 1, auto_melt_threshold 100

steps:
  - Place the check script in the agent scratch directory (the sandbox permits writes only there) as check-payout-rate.php; it requires the read-only target at /Users/juraj/projects/cashupayserver and defines CASHUPAY_DATA_DIR inside the scratch, so the repository's data/ directory is never touched.
  - Run it under the provided wrapper: /Users/juraj/security-audit-skill/cashupayserver/run-1/tools/sbx.sh w3b-payout -- bash -c 'php check-payout-rate.php > check-payout-rate.txt 2>&1' (macOS sandbox-exec, network denied, empty environment with HOME=TMPDIR=scratch, ulimit -t 120, ulimit -f 51200, 180 s wall clock).
  - The script replaces ExchangeRates::$providers by reflection with stub providers (the same hook tests/conversion.php uses), so no rate API, mint or LNURL server is contacted; the arithmetic is exercised through the real convertMintUnitToSats(), getBtcPrice(), convertToMintUnit() and mintAmountToSats() code paths.

observed:
  exit 0. A single provider answering 75000 against a 50000 reference was accepted and cached ('rate accepted for the payout : 75000.0', provider coingecko); with no reference a lone 500000 answer was accepted ('rate accepted as 500000.0'). For a 10000-cent balance: at the 50000 market rate the payout is 199900 sats (99.95 EUR at the market rate); at 75000 it is 133233 sats (66.62 EUR of market value, 33.38 EUR of the balance stays as ecash at the mint); at 500000 it is 19900 sats (9.95 EUR of market value, 90.05 EUR left as ecash); at 35000 it is 285614 sats, whose mint quote at the market rate is 14281 cents against the 10000-cent balance - 'INSUFFICIENT BALANCE - the automatic withdrawal fails on every run'. The invoice side can apply the store's 2 % exchange fee (1.00 USD at 30000 USD/BTC prices at 3400 sats with the fee and 3334 sats without it) while convertMintUnitToSats() takes no fee parameter - and for an invoice whose currency is the mint's own unit the fee is not applied at all, because convertToMintUnit() returns before the fee when the two units match (rates.php:349-351); the invoice side rounds 1.00 USD up to 3334 sats where the payout truncates to 3333; and the dashboard and manual-helper derivations return 200000 sats for the same balance that the automatic withdrawal actually sent as 199900. The operator's log line for that payout reads 'Auto-melt: Sent 199900 sats (~10000 eur)' - the mint-unit figure is the balance considered, not the amount sent. The transfer row for that payout records the mint's melt-quote amount in mint units - the mint's own price of the sats requested - not the balance that was considered and not the rate that sized it.
```

**Impact.** The merchant's money is not stolen outright - the mint's quote and the balance check still bound what leaves - but the payout is irreversible, unattended, sized by a number the operator never sees, and the store's configured exchange-fee tolerance is not applied (and even on the invoice side it is skipped altogether when the invoice currency is the mint's own unit). A rate 30 % low inside the accepted band makes every automatic withdrawal fail with 'Insufficient balance' (the merchant's balance stays undrawn at the mint); a rate 50 % high pays out about two thirds of the balance's market value and leaves the rest as ecash at the mint, with a log line that reads as if the whole balance was sent and a ledger row that records neither the rate nor the satoshi amount.

**Likelihood.** Needs a fiat-denominated mint with automatic withdrawal enabled and a price provider answer the code's own tolerance accepts (within 50 % of the last known rate, or any answer at all when the cached reference is missing or older than 24 h). The tolerance is the code's own design, so a provider glitch or a materially wrong market move satisfies it; the local check reproduced both the band acceptance and the no-reference acceptance.

**Why this priority.** The full source path from the background task to the Lightning payment was read, the rate acceptance rule and the payout arithmetic were reproduced offline with stubbed providers (no rate API contacted), and no other layer re-checks the rate, applies the exchange fee, confirms the amount with the operator, or records it.

**Smallest source fix.** Make the money-out path verify the rate it uses and account for it, instead of trusting the invoice-side rate verbatim. Three small changes: (1) size the automatic payout from a rate the money-out path can vouch for - the mint's own melt quote (the manual withdrawal's estimate already derives sats-per-mint-unit from it at admin.php:1696-1704), or, if the provider rate is used, require it to be fresh and within the store's configured exchange_fee_percent tolerance, and otherwise skip the withdrawal with a plain operator-facing message and no payment; (2) record the rate and the satoshi amount with the transfer and fix the log line so it names what was actually sent, so the ledger can answer 'how much left and at what price'; (3) give the balance-to-satoshis conversion one shared helper used by the dashboard, the manual path and the automatic withdrawal, and make it round in the merchant's favour on the invoice side and against it on the payout side, so the three numbers cannot drift apart.

`includes/lightning_address.php` — // autoMeltAccount(): verify the rate before an irreversible payment.
$meltAmountSats = ExchangeRates::convertMintUnitToSats(
    $meltAmountInMintUnit,
    $mintUnit,
    $store['price_provider_primary'] ?? null,
    $store['price_provider_secondary'] ?? null
);

// The payout is the merchant's money leaving for good, so the rate that sizes it has
// to be one we can vouch for: fresh, and inside the store's own exchange tolerance.
$rate = ExchangeRates::getBtcPrice(strtoupper($mintUnit), $store['price_provider_primary'] ?? null, $store['price_provider_secondary'] ?? null);
$reference = ExchangeRates::getLastKnownRate(strtoupper($mintUnit));
$tolerance = max(0.01, (float)($store['exchange_fee_percent'] ?? 0) / 100);
if ($rate === null || $reference === null || abs($rate - $reference) > $tolerance * $reference) {
    // Do not pay: tell the operator why, in their words.
    return [
        'mint_url' => $account['mint_url'],
        'success' => false,
        'error' => 'The exchange rate used to size this withdrawal was not trustworthy, so nothing was sent. '
            . 'Your money is still in your balance. Try again in a few minutes.',
    ];
}
$rateUsed = $rate;

`includes/lightning_address.php` — // autoMeltAccount(): say what was sent, and at what rate.
$transferNote = sprintf(
    'Auto-withdrawal: %d sats at %s %s/BTC',
    $meltAmountSats,
    rtrim(rtrim(number_format($rateUsed, 2, '.', ''), '0'), '.'),
    strtoupper($mintUnit)
);
error_log($transferNote . " from store {$store['name']} ({$account['mint_url']}) to {$store['auto_melt_address']}");
// and pass $transferNote as the transfer's detail so the ledger keeps the rate.

`includes/rates.php` — // convertMintUnitToSats(): round like the invoice side does, and expose the
// last known rate so the money-out path can check what it is about to use.
public static function convertMintUnitToSats(
    int $amountInSmallestUnit,
    string $mintUnit,
    ?string $primaryProvider = null,
    ?string $secondaryProvider = null
): int {
    // ... unchanged conversion ...
    $sats = (int)bcmul($btcAmount, '100000000', 0);
    // Round up, so the merchant is never short by a satoshi on the money-out side
    // either; the invoice side rounds up for the same reason (rates.php:368).
    $exact = bcmul($btcAmount, '100000000', self::SCALE);
    return self::toInt($exact, true);
}

/** The last known rate for a currency, whether or not it is still fresh. */
public static function getLastKnownRate(string $currency): ?float {
    return self::lastKnownRate(strtolower($currency));
}

**Supporting evidence**

- `includes/rates.php:566` — convertMintUnitToSats(int $amountInSmallestUnit, string $mintUnit, ?string $primaryProvider, ?string $secondaryProvider) has no exchange-fee parameter, while convertToMintUnit() at rates.php:333-369 takes $exchangeFeePercent and is called with the store's exchange_fee_percent at includes/invoice.php:133-145. The store's configured exchange-loss tolerance therefore exists only on the money-in side.
- `includes/rates.php:592` — The payout conversion truncates: return (int)bcmul($btcAmount, '100000000', 0); the invoice side rounds up (rates.php:368, toSmallestUnit(..., true)). The same money converts to 3334 sats on the invoice side and 3333 sats on the payout side.
- `includes/rates.php:283` — With no reference rate, a single provider's answer is taken as-is (rates.php:283-286); with a reference, any answer within 50 % of it is accepted (rates.php:265-268, 296-302). A lone 500000 EUR/BTC answer with no cached reference was accepted in the local check.
- `includes/rates.php:548` — The rate cache key is the currency alone ('rate_' . $currency), so the rate that sizes a payout may have been fetched and accepted by another store with a different provider set, or by the invoice path, within the five-minute cache TTL.
- `includes/lightning_address.php:434` — The payout amount is derived from that rate with no second opinion, no staleness bound, no exchange-fee tolerance and no record; the fee buffer at line 444 is a flat 1 % capped at 100 sats.
- `includes/lightning_address.php:476` — The operator-facing log line prints 'Sent {sats} sats (~{balance} {mintUnit})', where the mint-unit figure is the balance that was considered, not the value that was sent. With a rate 50 % high it reads 'Sent 133233 sats (~10000 eur)', which reads as if the whole balance left the wallet.
- `includes/database.php:508` — The transfers table has no exchange-rate column: the auto-withdrawal row records the melt quote amount in the mint's unit and the destination, so neither the rate that sized the payout nor the satoshi amount can be reconstructed from the ledger.
- `includes/invoice.php:133` — The invoice side reads the store's exchange_fee_percent and passes it to convertToMintUnit(); the money-out side never reads it, so the merchant's declared exchange-loss tolerance protects the money-in side only.
- `admin.php:534` — The dashboard's 'balance in sats' and the manual-withdrawal helper (admin.php:1968, includes/lightning_address.php:109-124) derive the same number a third way, without the fee buffer and with the invoice-side rounding; for fiat mints the manual path takes the operator's sat figure at face value (admin.php:1811-1816). Nothing ties the three derivations to each other.

---

### 4.6 A single price provider's answer alone sets and re-sets the invoice exchange rate: a wrong rate ratchets and underprices fiat invoices (no floor), and can freeze fiat checkout for up to 24 h

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `includes/rates.php:ExchangeRates::getBtcPrice:single-source-band-ratchet` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `includes/api/invoices.php:70` | The API client supplies the amount (validated as a positive decimal at :31-34) and the currency (:36-38); Invoice::create is called with both. |
| 2 | `propagation` | `includes/invoice.php:138` | convertToMintUnit is called with the client's amount and currency, the store's exchange fee and the store's primary/secondary price providers (:134-135, default coingecko then binance). |
| 3 | `propagation` | `includes/rates.php:355` | The fiat amount is divided by the accepted BTC price (toBtc, :390-395), so the accepted rate fixes the number of ecash units; there is no minimum amount check on the result. |
| 4 | `propagation` | `includes/rates.php:251` | The reference is lastKnownRate(): the last value written to rate_<cur>, up to 86400 s old, whatever provider wrote it and however many providers confirmed it. |
| 5 | `propagation` | `includes/rates.php:265` | The first provider answer within 50% of the reference is accepted at once and the function returns (:266-267); the remaining providers are never asked, so a single-source rate becomes the next reference. |
| 6 | `propagation` | `includes/rates.php:283` | With no reference, exactly one collected answer is accepted outright and cached; this is the path that lets a lone 10x-off answer become the reference. |
| 7 | `propagation` | `includes/rates.php:266` | The accepted single-source rate is stored as rate_<cur> with a fresh timestamp (:548-555): it prices the next 300 s of invoices (the cache is returned without any provider call, :224-227) and it is the reference for the next refresh, so the drift compounds. |
| 8 | `sink` | `includes/invoice.php:171` | The mint quote is requested for the converted amount; the amount is stored as invoice.amount_sats (:212) and the proofs are minted for exactly that amount (:455), so the merchant receives the amount the rate produced and the invoice is still marked paid. |

**Root cause.** The acceptance reference and the price cache are the same value. lastKnownRate() (includes/rates.php:305-312) reads rate_<cur>, the key saveToCache() (includes/rates.php:548-555) has just written from whichever single provider answered, so there is never a rate confirmed by two independent providers to measure a single answer against. The in-band branch returns before the cross-provider check (includes/rates.php:265-268), the reference-free branch accepts a lone answer (includes/rates.php:283-286), and the corroboration that does exist uses the same 50% SANITY_BAND (includes/rates.php:296, :300-302), so a rate up to 50% off can pass as agreement. The accepted value stays authoritative as both the price and the reference for up to REFERENCE_TTL (86400 s), and the price cache is global per currency (no store id in the key), so one bad answer misprices every store's fiat invoices and then blocks honest rates.

**Intended behaviour.** A rate that moves invoice pricing should be corroborated by at least one other provider, and the value a single answer is compared against should be a rate two providers confirmed - not the last value any single provider wrote. A lone provider's answer may only be used while it stays close to such a confirmed anchor, and a rate nobody corroborates must never price an invoice, neither from a provider nor from the stale cache. Note that the current code and its own regression test (tests/conversion.php:87-89 'a sane primary answer needs no second opinion' and :101-103 'a single provider with nothing to compare is taken') deliberately implement the single-source acceptance, so this is a hardening gap against a misbehaving third party rather than an accidental slip - but the code comment at includes/rates.php:246-250 states the opposite intent (one bad answer must not be able to price every invoice for minutes to an hour), and the in-band path lets exactly that happen.

**Lower-trust principal and starting capability.** The store's primary price provider (default coingecko - includes/invoice.php:134-135, admin.php:3720-3737) answers hostile or persistently faulty values, or fails so that at most one provider answers. TLS to the fixed hosts is verified by default, so an on-path attacker is not sufficient: the provider's own answers must be wrong.

**Conditions.**

- *(third_party_dependency)* The store's primary price provider (default coingecko - includes/invoice.php:134-135, admin.php:3720-3737) answers hostile or persistently faulty values, or fails so that at most one provider answers. TLS to the fixed hosts is verified by default, so an on-path attacker is not sufficient: the provider's own answers must be wrong.
- *(data_state)* The store prices invoices in a fiat currency while the mint unit differs (a sat mint is the typical case; a EUR mint taking a EUR invoice uses the same-unit branch and is unaffected), and either no fiat rate was cached in the last 86400 s (cold start) or the cached reference was itself written by that same provider (ratchet and poisoned reference).
- *(timing_dependency)* Each ratchet step needs the 300 s price cache to expire and a new invoice creation to trigger the refresh; the poisoned reference then suppresses honest rates for up to 86400 s, and the freeze of fiat pricing needs the poisoned entry to be older than STALE_TTL (3600 s) while it is still inside REFERENCE_TTL.
- *(system_configuration)* The store's primary provider is consulted first and, while its answer is in band, is the only one consulted (includes/rates.php:233-244, :265-268); the primary/secondary pair is per store and defaults to coingecko then binance, and the resulting rate_<cur> cache entry is shared by every store on the server.

**Bounded, target-native reproduction**

```text
attacker perspective: Whoever controls the answers of the operator's primary BTC price provider (default coingecko), or a provider-side fault that returns a wrong-but-plausible price, while the other providers stay honest; or an outage that leaves at most one provider answering. TLS to the fixed hosts is verified, so this is a third-party behaviour condition, not an on-path attacker.

payload: in-band acceptance: reference rate_eur = 60000 (aged 301 s); primary answers 66000 (+10%), the other two answer 60000
payload: ratchet: rate_eur seeded with the previously accepted rate aged 301 s before each call; primary answers the previously accepted rate x1.45 on each of 6 refreshes; the two other providers always answer 60000
payload: cold start: rate_eur absent; the only answering provider returns 600000
payload: poisoned reference: rate_eur = {rate: 600000, timestamp: now-301 / now-3700 / now-90000}; one provider answers the honest 60000 (and, in a control case, two providers answer 60000 / 55000)
payload: no floor: the only provider answers 1e12 EUR/BTC and a 100 EUR invoice is converted

steps:
  - In the sandbox scratch directory run: sbx.sh v15-rates -- php -d opcache.enable_cli=0 <scratch>/harness-rates.php (macOS sandbox-exec, network* denied, writes only under agents/v15-rates/scratch, empty env, ulimit -t 120, timeout 180).
  - The harness defines CASHUPAY_DATA_DIR to a fresh directory under the scratch, includes cashu-wallet-php/CashuWallet.php, includes/database.php, includes/rates.php and includes/config.php, calls Database::initialize() and then exercises the unmodified ExchangeRates code.
  - Provider HTTP calls are replaced with in-process stubs through the same ReflectionProperty seam the repository's own tests/conversion.php uses (ExchangeRates::$providers, tests/conversion.php:71-75), so no price host is contacted.
  - A. seed rate_eur = 60000 aged 301 s, stub primary 66000 / secondary 60000 / third 60000, call getBtcPrice('EUR','coingecko','binance'), print the accepted rate, the per-provider call counts and the resulting cache entry.
  - B. seed rate_eur with the previously accepted rate aged 301 s before each call, let the primary answer 1.45x it, and after each acceptance call convertToMintUnit('100','EUR','SAT',0,'coingecko','binance') to record the ecash amount of a 100 EUR invoice.
  - C. call getBtcPrice again immediately after an acceptance and compare the provider call counts, to show the fresh cache is served without consulting any provider.
  - D. set rate_eur to null with one stub answering 600000, call getBtcPrice and convertToMintUnit.
  - E. seed rate_eur = 600000 at ages 301 / 3700 / 90000 with one honest stub answering 60000, and in a control case with two honest stubs answering 60000 and 55000, then call getBtcPrice/convertToMintUnit and catch the exception.
  - F. stub a rate of 1e12 and convert 100 EUR to SAT to show the absence of a floor; convert 100 EUR to EUR (same-unit) as a control.
  - G. convert 19.995 EUR to EUR (same unit) while counting provider calls, and pass a zero amount to Cashu\Wallet::requestMintQuote to show where the zero-amount guard actually sits.
  - Artifact: agents/v15-rates/artifacts/check-rates.txt

observed:
  A. accepted rate 66000.0, provider calls coingecko:1 binance:0 kraken:0, cache entry rate 66000.0 provider 'coingecko' - the in-band answer is accepted alone and is written back as the next reference. B. ratchet over six refreshes: accepted rate = cache rate = 87000, 126150, 182917.5, 265230.375, 384584.04375, 557646.8634375; the 100 EUR invoice fell 166667 (fair at 60000) -> 114943 -> 79271 -> 54670 -> 37704 -> 26003 -> 17933 sats; binance and kraken were consulted 0 times throughout. C. the accepted 557646.8634375 was returned again with the provider call count unchanged (6 before, 6 after) - the fresh cache prices invoices for CACHE_TTL=300 s with zero provider calls. D. cold start: the lone answer 600000 was accepted (1 provider call) and 100 EUR was priced at 16667 sats. E. poisoned reference: at age 301 the honest 60000 was rejected ('more than 50% away from the last known rate 600000') and the poisoned 600000 was served (100 EUR = 16667 sats); at age 3700 the honest rate was still rejected and convertToMintUnit raised 'Prices are unavailable right now; try again in a few minutes.' (no usable exchange rate for EUR); with two answering providers the honest 60000 was accepted again even when they differed by 55000 vs 60000, i.e. the freeze needs fewer than two answering providers; at age 90000 (past REFERENCE_TTL) the honest rate was accepted and 100 EUR returned to 166667 sats. F. 100 EUR at 1e12 EUR/BTC converted to 1 sat (not 0, not refused); the same invoice on a EUR mint (same unit) returned 10000 cents with no rate involved. G. convertToMintUnit('19.995','EUR','EUR') = 1999 cents with 0 provider calls (same-unit branch, no rate involved); convertToMintUnit('0.009','EUR','EUR') = 0 cents and Cashu\Wallet::requestMintQuote refused it with 'Amount must be greater than 0' - the zero guard is in the wallet and the API, not in the pricing path, and the rounding-up in the conversion means a bad rate never produces 0.
```

**Impact.** Fiat invoices can be underpriced without a floor: the accepted rate drives the ecash amount, a 100 EUR invoice fell from 166667 to 17933 sats in six refreshes and reaches 1 sat at an extreme rate, and the shop still marks those orders paid (the proofs are minted for exactly invoice.amount_sats). A single bad answer can also stop fiat pricing altogether for up to 24 h ('Prices are unavailable right now; try again in a few minutes.'), and the poisoned rate_<cur> entry is shared by every store on the server.

**Likelihood.** The condition is a price provider that answers wrongly (hostile or faulty) while the others stay honest; TLS to the fixed hosts is verified by curl's default, so this needs the provider's own answers to be wrong, not an on-path attacker, and a single large jump is still rejected and cross-checked. The cold-start and freeze variants additionally need all but one provider to fail.

**Why this priority.** The path is short and source-visible, and a sandboxed harness ran the unmodified ExchangeRates code with stub providers: the band rejects a single large jump but accepts compounding in-band steps with zero cross-checks, accepts a lone answer when no reference exists, writes every accepted value back as the next reference, lets a poisoned reference reject the honest rate, and leaves the ecash amount without a floor. The only parts corrected against the hunter's wording are the scope of the freeze (it needs fewer than two answering providers) and the framing of the 24 h window.

**Smallest source fix.** Stop using the price cache as the acceptance reference. Keep a separate anchor that only two independent providers can write, compare a single provider's answer against that anchor with a tight band, never accept a lone uncorroborated answer (drop the reference-free branch at includes/rates.php:283-286), and never let a single-provider value become the anchor. The stale-cache fallback must only serve values that were corroborated or anchored, so a poisoned entry cannot keep pricing invoices. Regression test: stub a hostile primary that answers 1.45x the previously accepted value on every refresh while two honest providers answer a constant, and assert that the accepted rate never leaves a narrow band around the confirmed anchor and that a lone answer is refused.

`includes/rates.php` —     /** A rate two independent providers agreed on: the only value a single
     *  provider's answer may be compared against. */
    private const ANCHOR_TTL = 86400;
    /** How far a single provider's answer may sit from the confirmed anchor. */
    private const ANCHOR_BAND = 0.05;
    /** How far two providers' answers may sit from each other to count as agreement. */
    private const AGREEMENT_BAND = 0.02;

    /** The confirmed anchor: written only when two providers agreed. */
    private static function confirmedAnchor(string $currency): ?float {
        $data = Config::get('rate_anchor_' . $currency);
        if (!is_array($data) || !isset($data['rate']) || (float)$data['rate'] <= 0
            || time() - (int)($data['timestamp'] ?? 0) > self::ANCHOR_TTL) {
            return null;
        }
        return (float)$data['rate'];
    }

    private static function withinBand(float $rate, float $reference, float $band): bool {
        return abs($rate - $reference) <= $band * $reference;
    }

`includes/rates.php` —         $anchor = self::confirmedAnchor($currency);
        $collected = [];
        foreach ($order as $provider) {
            try {
                $rate = $provider->getBtcPrice($currency);
            } catch (Exception $e) {
                error_log("ExchangeRates: {$provider->getName()} failed for {$currency}: " . $e->getMessage());
                continue;
            }
            if ($rate === null || !is_finite($rate) || $rate <= 0) {
                continue;
            }
            foreach ($collected as [$earlierRate, $earlierName]) {
                if (self::withinBand($rate, $earlierRate, self::AGREEMENT_BAND)) {
                    // Two independent sources agree: this is the only value that may
                    // become the anchor every later answer is measured against.
                    self::saveToCache($currency, $earlierRate, $earlierName);
                    Config::set('rate_anchor_' . $currency, ['rate' => $earlierRate, 'timestamp' => time()]);
                    return $earlierRate;
                }
            }
            if ($anchor !== null && self::withinBand($rate, $anchor, self::ANCHOR_BAND)) {
                // Close to a corroborated rate: usable, but it never becomes the anchor.
                self::saveToCache($currency, $rate, $provider->getName());
                return $rate;
            }
            $collected[] = [$rate, $provider->getName()];
        }
        // Nothing corroborated this rate: never price an invoice from a lone answer.
        // The stale fallback may only serve a value that was corroborated or anchored,
        // so a single provider's answer can neither price nor block pricing.
        return null;

**Supporting evidence**

- `includes/rates.php:265` — if ($reference !== null && self::withinBand($rate, $reference)) { saveToCache(...); return $rate; } - the first answer within 50% of the reference is accepted and returned before the cross-provider loop at :269-275 is reached. Verified: with the primary answering 66000 against a 60000 reference, the other two providers were called 0 times.
- `includes/rates.php:266` — saveToCache($currency, $rate, $provider->getName()) writes the accepted single-source value into rate_<cur>, the same key lastKnownRate() reads; the cache entry after acceptance was rate 66000 / provider 'coingecko', i.e. the accepted value becomes the next reference.
- `includes/rates.php:305` — lastKnownRate() returns Config::get('rate_' . $currency) whenever it is a positive rate younger than REFERENCE_TTL - the last value any single provider wrote, not a value two providers confirmed. The key carries no store id, so the poisoned rate is global for that currency.
- `includes/rates.php:283` — 'Nothing to compare a single answer with: take it.' - with no reference and exactly one answering provider the lone answer is accepted and cached. Verified: the cold-start answer 600000 was accepted after one provider call and priced 100 EUR at 16667 sats instead of 166667.
- `includes/rates.php:269` — The cross-provider check only runs for answers outside the band of the self-written reference, and it calls withinBand() with the same SANITY_BAND: two answers 50% apart count as 'agreement'. Verified: two providers answering 60000 and 55000 were accepted as agreeing, and a poisoned reference was defeated by two answering providers but not by one.
- `includes/rates.php:246` — The comment states the intent that one bad answer - a decimal slip, a wrong field, a hijacked response - must not price every invoice for minutes to an hour; the in-band path accepts such an answer on its own and writes it as the reference, so the stated intent is not met for in-band answers.
- `includes/rates.php:296` — SANITY_BAND = 0.5, so one accepted step may move the rate by 50% of the previously accepted rate (1.5x up, 0.5x down); REFERENCE_TTL = 86400 at :298 keeps such a value authoritative as the reference for a day.
- `includes/rates.php:188` — CACHE_TTL = 300, so a new single-source step can be taken every five minutes, each time triggered by an invoice creation; STALE_TTL = 3600 at :189 lets the poisoned value keep pricing invoices for an hour before the fallback expires and pricing stops with 'Prices are unavailable right now; try again in a few minutes.'
- `includes/rates.php:24` — The providers are fixed HTTPS hosts (CoinGecko :24, Binance :64, Kraken :115) and the curl options never set CURLOPT_SSL_VERIFYPEER/VERIFYHOST, so TLS verification is at curl's default: an on-path attacker is not sufficient, the provider's own answers must be wrong (or all but one provider must fail).
- `includes/invoice.php:171` — The mint quote is requested for the converted amount and the proofs are minted for invoice.amount_sats (:212, :455), so the amount the rate produced is exactly what the merchant receives and what the payer is charged; the invoice is still marked paid.
- `includes/api/invoices.php:31` — The API rejects a non-positive invoice amount before any conversion, and cashu-wallet-php/CashuWallet.php:7067 refuses a zero quote - but the conversion rounds up (includes/rates.php:368, :455-461), so a badly wrong rate produces 1 unit, never 0, and neither guard fires: there is no independent floor on the ecash amount of a fiat invoice.

---

### 4.7 The admin-password lockout has no address-independent budget: five password checks per derived address, with no global floor

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `includes/security.php:reserveLoginAttempt:no-address-independent-login-budget` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:750` | POST /admin.php with action=login hands the password and the derived client identity to Auth::attemptLogin(); the identity was taken from Security::getClientIp() at admin.php:738, before any password check. The only thing required of the caller is the session-bound CSRF token that admin.php:2636 puts in the page of every anonymous visitor. |
| 2 | `propagation` | `includes/auth.php:114` | The only identity under which the attempt is reserved is the derived client address (line 114 derives it, line 115 reserves it); the function takes no account, session or credential dimension, and the product has exactly one admin credential. |
| 3 | `propagation` | `includes/security.php:67` | The counter key is login_attempts_{identifier}, built from that address, so the address is the whole accounting dimension of the anti-brute-force control. |
| 4 | `propagation` | `includes/security.php:78` | The counter for that one address is incremented and the attempt is granted, up to MAX_LOGIN_ATTEMPTS; when that address's own budget is exhausted only that address's counter is reset (security.php:74-77), so a second address starts again from zero. |
| 5 | `propagation` | `includes/security.php:20` | The generic quota key is rate_{action}_{identifier}; every other consumer (api.php:53-54, receive.php:128 and :695, setup.php:115/:153/:288/:531, admin.php:810/:841/:874, includes/setup_ownership.php:150) uses this same single dimension and adds no per-principal or global counter either. |
| 6 | `sink` | `includes/auth.php:87` | Every attempt the lockout granted runs password_verify against the admin credential hash, so the number of guesses available to a requester is five multiplied by the number of distinct addresses they can present. |

**Root cause.** The lockout counter key is built from the derived client address alone (includes/security.php:67) and the consumer adds no second dimension (includes/auth.php:113-123): the per-address counter is reset on its own schedule (includes/security.php:74-77) and clearLoginAttempts() deletes one address's row (:122-125), so the quota table holds no counter that is independent of the address. Because the product has exactly one admin credential, the missing dimension would be a constant - the cheapest possible global cap - and it is absent. The repository's own test suite asserts the per-address isolation (tests/security_login_lockout.php:57, 'another address is unaffected') but nothing anywhere asserts or implements a cross-address total.

**Intended behaviour.** After a small number of failed password checks, further password verifications against the admin credential are refused regardless of how many addresses the requests arrive from, so the guessing budget is bounded by the control rather than by the requester's supply of addresses.

**Lower-trust principal and starting capability.** None: POST /admin.php with action=login is reachable without a session; only a session-bound CSRF token is needed, which the same unauthenticated visitor obtains from the login page (admin.php:2636). None: no operator action, no click and no second factor stands between the requester and the password checks.

**Conditions.**

- *(authentication_level)* None: POST /admin.php with action=login is reachable without a session; only a session-bound CSRF token is needed, which the same unauthenticated visitor obtains from the login page (admin.php:2636).
- *(network_routing)* The requester can present more than one derived client address. Several source addresses suffice (Tor exit nodes, cloud VMs, a botnet), and a single attacker with a routed IPv6 prefix has 2^64 of them because the bucket is the full address and IPv6 addresses are not aggregated to their /64 (includes/security.php:371-384). The already-recorded case where a trusted proxy forwards a client-supplied address is a further, deployment-dependent way to reach the same state, but it is not required here.
- *(system_configuration)* The installation is set up, so an admin password hash exists (includes/auth.php:80-81), and the password is operator-chosen with only a minimum length of 8 characters (setup.php:221), which makes the attack a dictionary attack against the operator's choice.
- *(user_interaction)* None: no operator action, no click and no second factor stands between the requester and the password checks.
- *(system_configuration)* The write path of the trusted-proxy setting can remove even the need for several source addresses: admin.php save_proxy_settings accepts a range as wide as 0.0.0.0/0, which makes every peer a trusted proxy and lets a direct client choose its own address with a single header (see admin.php:save_proxy_settings:unbounded-trusted-proxy-range).

**Bounded, target-native reproduction**

```text
attacker perspective: Anonymous visitor of the admin login form. I need nothing but to reach POST /admin.php with action=login from more than one derived client address; no session, no API key and no proxy of my own is required.

payload: POST /admin.php HTTP/1.1 with body action=login&password=<guess>&csrf_token=<token from GET /admin.php>
payload: the same request repeated five times from one source address, then five times from a second source address
payload: an IPv6 client using a second address from the same routed /64

steps:
  - Run the read-only fixture inside the agent sandbox: php check-quota-identity.php, using a throwaway SQLite database under the agent scratch directory, no network and no deployed endpoint.
  - Part A calls Auth::attemptLogin('wrong-guess-N', <address>) for 20 distinct addresses and counts the outcomes; Part B lists the rate_limits table afterwards; Part C shows the counter inside one address; Part D makes a copy of the quota store read-only; Part E uses two addresses of one /64.
  - Read the recorded output in check-quota-identity.txt.

observed:
  Independently reproduced in the sandbox with dummy data (dummy admin password, TEST-NET addresses, throwaway SQLite under the agent scratch dir; PHP 8.5.11, one password_verify measured at 0.229 s). Part A: 20 distinct addresses x 7 attempts each = 140 requests produced 100 outcomes 'invalid' and 40 outcomes 'locked' - exactly five password checks per address, 20 addresses, no cap over the set (a global floor of 5 would have been exceeded by 95), in 22.99 s of wall time, which at 0.229 s per password_verify confirms all 100 were real bcrypt checks. Part B: the rate_limits table afterwards held exactly 20 rows, all of them login_attempts_<address>, sum of counters 100, largest single counter 5, and zero rows that are not address-keyed - no cross-address total exists. Part C: inside one address the counter was {"count":5,...} with the address locked out and a 278 s TTL, i.e. the per-address budget is exactly five and is refreshed every 300 s. Part E: two IPv6 addresses of one /64 (2001:db8:1:2::1 and 2001:db8:1:2::2) were granted five checks each and then locked separately, and Security::getClientIp() returned the full address 2001:db8:1:2::1, so IPv6 is not aggregated to the prefix. Part D: with a read-only copy of the quota store both checkRateLimit() and reserveLoginAttempt() returned false and logged the storage failure, so the lockout fails closed in that degraded case (note: the hunter's report that checkRateLimit() raises an uncaught PDOException there did not reproduce - it returns false).
```

**Impact.** The admin password is the only credential in the product and it gates the wallet seed phrases (admin.php:862) and every fund-moving action, so a successful guess means the attacker controls the store's money.

**Likelihood.** The login form is public and needs no session, and the requester only needs several source addresses - Tor exit nodes, cloud VMs or one routed IPv6 prefix, which gives 2^64 of them - to get past the five-per-address budget. The password still has to be guessed, so this is not a direct takeover.

**Why this priority.** The trace and every evidence line were re-read in the current source and match; the decisive observation was independently reproduced in the sandbox with dummy data (100 password checks over 20 addresses, 20 address-keyed rows, no aggregate row, two /64 addresses counted separately). The control weakness is unconditional in the source: nothing in the repository or the deployment bounds the number of distinct addresses a requester can present, and it holds with proxy_mode 'direct', where no forwarded header is trusted. The residual uncertainty is how many addresses a real attacker has, which is a likelihood input and is removed from the outcome by the proposed fix.

**Smallest source fix.** Add an address-independent budget to the login lockout. The product has exactly one admin credential, so the second dimension is a single global counter rather than a per-account table: count every attempt that is granted against one extra row (login_attempts_global) and refuse once that row's budget is used up, independently of the address the request came from. Keep the per-address counter as it is, so one noisy client still gets only five tries. Count the global row only for attempts that are actually granted, so a client already locked out at its own address cannot burn the operator's global budget, and clear it together with the per-address row on a successful login. Aggregating IPv6 addresses to their /64 prefix in the bucket key is a useful second step but does not replace the global counter. Note that lockoutEnd() currently hard-codes MAX_LOGIN_ATTEMPTS, so it must take the limit as a parameter; reusing it unchanged would cap the global budget at 5 instead of the intended 20 and lock every address out after five total attempts.

`includes/security.php` —     /** Address-independent budget: the product has one admin credential. */
    private const MAX_GLOBAL_LOGIN_ATTEMPTS = 20;
    private const GLOBAL_LOGIN_KEY = 'login_attempts_global';

    public static function reserveLoginAttempt(string $identifier): bool {
        $key = "login_attempts_{$identifier}";
        try {
            return self::withWriteLock(function () use ($key): bool {
                $data = self::getCache($key) ?? ['count' => 0, 'first_attempt' => time()];
                if (self::lockoutEnd($data, self::MAX_LOGIN_ATTEMPTS) > time()) {
                    return false;
                }
                if (($data['count'] ?? 0) >= self::MAX_LOGIN_ATTEMPTS) {
                    // The previous lockout has run out: start a fresh budget.
                    $data = ['count' => 0, 'first_attempt' => time()];
                }
                // The global budget bounds guessing however many addresses the requester
                // presents. Counted only for attempts that are actually checked, so a
                // client already locked out at its own address cannot burn it.
                $global = self::getCache(self::GLOBAL_LOGIN_KEY) ?? ['count' => 0, 'first_attempt' => time()];
                if (self::lockoutEnd($global, self::MAX_GLOBAL_LOGIN_ATTEMPTS) > time()) {
                    return false;
                }
                if ((int)($global['count'] ?? 0) >= self::MAX_GLOBAL_LOGIN_ATTEMPTS) {
                    $global = ['count' => 0, 'first_attempt' => time()];
                }
                $global['count']++;
                $global['last_attempt'] = time();
                self::setCache(self::GLOBAL_LOGIN_KEY, $global, self::LOCKOUT_DURATION);

                $data['count']++;
                $data['last_attempt'] = time();
                self::setCache($key, $data, self::LOCKOUT_DURATION);
                return true;
            });
        } catch (Throwable $e) {
            // A lockout that cannot record state must not silently allow everything.
            error_log('CashuPayServer: login attempt storage failed: ' . $e->getMessage());
            return false;
        }
    }

`includes/security.php` —     /** When a lockout ends, or 0 when the counter is below the limit. */
    private static function lockoutEnd(array $data, int $limit = self::MAX_LOGIN_ATTEMPTS): int {
        if ((int)($data['count'] ?? 0) < $limit) {
            return 0;
        }
        return (int)($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
    }

`includes/security.php` —     public static function clearLoginAttempts(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        self::deleteCache($key);
        // A successful login also ends the address-independent budget.
        self::deleteCache(self::GLOBAL_LOGIN_KEY);
    }

**Supporting evidence**

- `includes/security.php:67` — The lockout counter key is login_attempts_{identifier} and the identifier is the derived client address; there is no second component to the key.
- `includes/security.php:13` — MAX_LOGIN_ATTEMPTS is 5: the whole anti-brute-force budget for the sole admin credential is five password checks per address per window.
- `includes/security.php:14` — LOCKOUT_DURATION is 300 seconds, so those five checks are refreshed every five minutes, per address.
- `includes/security.php:74` — When an address's budget is used up the counter for that address is reset and no aggregate counter is consulted or maintained anywhere.
- `includes/security.php:122` — clearLoginAttempts() deletes one address's row only; the quota table holds no row that could carry a cross-address total.
- `includes/security.php:812` — The quota store is a single key/data table keyed by the composite key, so the accounting dimension is exactly the key and nothing aggregates it.
- `includes/security.php:371` — normalizeIp() returns the full canonical address (IPv4-mapped IPv6 unwrapped to IPv4); nothing reduces an IPv6 address to its /64 prefix, so one routed prefix yields one bucket per address.
- `includes/auth.php:87` — password_verify runs for every granted attempt, so each granted attempt is one real guess against the admin credential.
- `includes/auth.php:159` — The only strength rule on the admin credential is a minimum length of 8 characters.
- `setup.php:221` — The setup wizard enforces the same 8-character minimum and no complexity rule, so the credential is only as strong as the operator's choice.
- `api-keys/authorize.php:137` — The second public password surface (API-key pairing) reserves its attempt under the same address-derived key, so it shares the address budget and adds no second dimension.
- `admin.php:846` — The remaining address-keyed quotas (reveal_seed at :841, download_backup at :874) sit behind an authenticated session and a fresh password check, so the admin password is the only pre-authentication credential and the only one the lockout is there to protect.
- `tests/security_login_lockout.php:57` — The repository's own lockout test asserts that a second address is unaffected after the first one is locked out, confirming that per-address isolation is the designed behaviour and that no cross-address total exists anywhere in the codebase.

---

### 4.8 The expiry sweep loads the entire expired-invoice backlog into memory; the resulting fatal error escapes the background runner and kills every background run

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `invoice.markExpiredInvoices.unbounded-select-memory-exhaustion` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `cron.php:77` | Every background tick runs the shared runner. The runner's per-task error handling is 'catch (Throwable $e)' (includes/background_runner.php:227) and the run's bookkeeping (cursor, health, heartbeat) is written only after the task loop (includes/background_runner.php:262-265), so a task that kills the process takes the whole run with it. |
| 2 | `propagation` | `includes/background_runner.php:109` | The 'poll_quotes' task calls Invoice::pollPendingQuotes(). It is an always-run task, so it precedes 'webhook_outbox' and the whole rotating set: a failure here stops every task that would follow in the tick. |
| 3 | `propagation` | `includes/invoice.php:385` | pollPendingQuotes() calls self::markExpiredInvoices() before it polls any quote, so the sweep runs first and its failure precedes all payment polling in the tick. |
| 4 | `propagation` | `includes/database.php:953` | markExpiredInvoices() opens a write transaction whose first statement is the no-op write 'DELETE FROM config WHERE 0'; this takes SQLite's single writer lock immediately, and the lock is held until the commit at includes/invoice.php:368. Everything the sweep does runs inside it, so a failure anywhere in the sweep rolls the whole batch back and leaves the backlog untouched. |
| 5 | `propagation` | `includes/invoice.php:348` | The sweep selects every 'New' invoice whose expiration_time has passed: one statement, no LIMIT and no chunking, so the result set is as large as the whole expired backlog. |
| 6 | `sink` | `includes/database.php:1010` | PDOStatement::fetchAll() materialises the whole result set in PHP memory (measured 456.5 bytes per row at 170,000 rows). When the allocation crosses memory_limit PHP raises an E_ERROR, which is not a Throwable, so it is not caught by the background runner (includes/background_runner.php:227), the process dies, the open write transaction is rolled back, and the 'finally' that releases the cron lease (includes/background_runner.php:266) is skipped. |

**Root cause.** The sweep has no bound on how much work it does at once: the SELECT at includes/invoice.php:348-351 has no LIMIT, the result set is materialised in PHP memory by Database::fetchAll() (includes/database.php:1010) at a measured 456.5 bytes per row, and the whole backlog is processed inside the single write transaction opened at includes/invoice.php:343. Memory use therefore grows linearly with the backlog; when it crosses PHP's memory_limit the failure is an E_ERROR, which no 'catch (Throwable)' (includes/background_runner.php:227) can contain, so the process dies, the swept transaction is rolled back, the cron lease is not released, and the condition repeats on every tick.

**Intended behaviour.** The expiry sweep should expire invoices in bounded batches, so that its memory use and its write-lock hold are capped no matter how large the backlog is, and a task that fails must not be able to take the rest of the background run down with it: the run should record the failure, keep the heartbeat, release the lease and let the other tasks run.

**Lower-trust principal and starting capability.** No attacker privilege is required to reach the failure: it is reached by the installation's own cron once the data state exists. Creating the invoices needs an API key, but abandoned checkouts of a real shop are created by its own plugin, and the failure can also be reached by a third party who can create invoices and simply wait for the shop's cron to keep running.

**Conditions.**

- *(data_state)* About 280,000 invoices simultaneously status='New' with expiration_time in the past at PHP's default 128M limit (measured 456.5 bytes per row: 134,217,728 - 6,291,456 base = 127,926,272 bytes available, i.e. 280,000 rows). The threshold scales with memory_limit: about 133,000 rows at 64M and about 560,000 rows at 256M. The local reproduction used 170,000 rows at a 64M limit - the same defect at the documented scaled configuration - because the sandbox caps the SQLite file at 25 MiB, which allows at most ~190,000 invoice rows.
- *(system_configuration)* PHP memory_limit at or below ~128M (the PHP default; this host's default was measured as 128M). A larger limit moves the threshold up proportionally; an unlimited limit replaces the E_ERROR with an out-of-memory kill of the process, with the same effect on the run.
- *(environmental_dependency)* The backlog has to accumulate, and it only grows while the background work is not running: 'expire_old_invoices' trims unpaid invoices older than 30 days whenever the cron runs (includes/background_runner.php:135), and the opportunistic self-trigger needs a configured base_url (includes/background.php:67-75). An installation whose cron never ran (an explicitly supported shared-hosting deployment) accumulates one month - or many months - of abandoned checkouts; a store creating thousands of abandoned checkouts a day reaches the threshold in weeks.
- *(authentication_level)* No attacker privilege is required to reach the failure: it is reached by the installation's own cron once the data state exists. Creating the invoices needs an API key, but abandoned checkouts of a real shop are created by its own plugin, and the failure can also be reached by a third party who can create invoices and simply wait for the shop's cron to keep running.

**Bounded, target-native reproduction**

```text
attacker perspective: No privileged access is needed to observe or reach the failure. The trigger is a data state - a large backlog of expired unpaid invoices - reached by the installation itself when its background work has not been keeping up; the invoices are created with the shop's own API key.

payload: 170,000 invoice rows for one dummy store in a scratch SQLite database created by the application's own schema (includes/database.php:384): id inv_0..inv_169999, store_id s1, status 'New', amount '1000', currency 'sat', amount_sats 1000, quote_id q_<n>, created_at now-7200, expiration_time now-3600
payload: one dummy store with mint_url NULL (so every wallet task is a no-op and nothing reaches the network) and one enabled webhook subscribed to InvoiceExpired (id wh1, url https://example.invalid/hook)
payload: one pending webhook_deliveries row (id del_probe) so the outbox has work to do
payload: PHP 8.5.11, memory_limit 64M for the failing run (the default on this host is 128M), CASHUPAY_DATA_DIR pointing at the agent scratch directory

steps:
  - Seed the fixture: php fix_setup.php <scratch>/data_170k 170000 (creates the app schema in the scratch data dir, the dummy store, the webhook and the 170,000 expired invoices).
  - Run the real background runner: php -d memory_limit=64M fix_bg.php <scratch>/data_170k, which inserts the pending outbox row, calls BackgroundRunner::run(20) and prints a marker after the run returns.
  - Read the process exit status and the captured stdout/stderr, then read the installation state back with php fix_status.php <scratch>/data_170k (invoice status distribution, undelivered webhook_deliveries, cron_last_run, cron_lease_until, cron_task_health, cron_last_result).
  - Tick again with the lease still held, then release the lease and tick a third time, to see whether the failure repeats.
  - Control: php fix_setup.php <scratch>/data_20k 20000 and php fix_bg.php <scratch>/data_20k at the default 128M limit, where the same code path completes.
  - Memory calibration: php fix_mem.php <scratch>/data_170k measures the cost of the sweep's own fetchAll() at the default limit.

observed:
  The background run died: exit status 255 and 'PHP Fatal error: Allowed memory size of 67108864 bytes exhausted (tried to allocate 4194312 bytes) in includes/database.php on line 1010', stack trace PDOStatement->fetchAll() <- includes/invoice.php:348 Database::fetchAll <- includes/invoice.php:385 Invoice::markExpiredInvoices() <- includes/background_runner.php:109 Invoice::pollPendingQuotes() <- includes/background_runner.php:218 BackgroundRunner::{closure:BackgroundRunner::tasks():108}() <- includes/background_runner.php:239 BackgroundRunner::{closure:BackgroundRunner::run():196}('poll_quotes'). The script's own 'REACHED CODE AFTER THE BACKGROUND RUN' line was never printed and the process died in 0.57 s, so the failure was inside the task and not after it. Afterwards: invoices New = 170000 (nothing was expired - the transaction was rolled back), webhook_deliveries = 1 (only the probe row; the outbox task never ran), cron_last_run = NULL (the heartbeat was never written), cron_task_health = NULL and cron_last_result = NULL (no task result was recorded), cron_lease_until = a future timestamp (the 'finally' that releases the lease did not run). The next tick answered 'another run holds the lease' and did no work; after the lease was released the next tick died with exactly the same fatal, so the backlog can never shrink. Control at 20,000 rows and the default 128M limit: the run completed, 'REACHED CODE AFTER THE BACKGROUND RUN' was printed, all 20,000 invoices were Expired, 20,001 deliveries were enqueued, cron_last_run was written and cron_lease_until was 0. Memory calibration: the sweep's own fetchAll() of 170,000 rows needed 77,611,008 bytes (456.5 bytes/row) at the default 128M limit, which places the 128M threshold at about 280,000 rows.
```

**Impact.** The whole background run process dies on every tick, and the death is also not clean: the cron lease is not released, so the ticks that follow do no work at all for up to 360 s. The bulk expiry and its InvoiceExpired webhooks stop, the webhook outbox stops delivering, the late-payment recovery, the orphaned-invoice recovery, the automatic withdrawal, the transfer reconciliation and all cleanup tasks stop, and the heartbeat is never written, so the operator sees a shop whose orders are never expired and a dashboard that blames a missing cron job; clicking the dashboard's 'Run now' (admin.php:1111) dies the same way, because it runs the same runner. The sweep never commits, so the backlog never shrinks and no manual intervention inside the application can recover it. No ecash is destroyed and customer-facing settlement through payment.php and the Greenfield GET still works, so the loss is availability of the whole background subsystem plus the operator's misdiagnosis of it, not money.

**Likelihood.** The trigger is a data state, not a request: about 280,000 expired unpaid invoices at PHP's default 128M limit (measured 456.5 bytes per row; about 133,000 at 64M and about 560,000 at 256M). The backlog only grows while the background work is not running, because 'expire_old_invoices' trims unpaid invoices older than 30 days on every healthy tick; so the shop needs either a background-work outage of weeks to months or a very high rate of abandoned checkouts (roughly 9,000 a day for a 30-day accumulation). Once reached, the failure is permanent and self-repeating, not transient.

**Why this priority.** The defect, the fatal and its exact propagation path were independently demonstrated end to end with the real BackgroundRunner::run() and the real markExpiredInvoices() against a dummy fixture: exit status 255, the fatal in database.php:1010 with the stack trace through invoice.php:348/385 and background_runner.php:109, the sweep rolled back, the heartbeat and the task health unwritten, the lease left held, and the same fatal on the next tick. The per-row memory cost was measured directly (456.5 bytes), which places the 128M threshold at about 280,000 rows and confirms the hunter's 250,000/275,000/300,000 bracket. The only judgement not settled locally is how likely a given installation is to reach that backlog size, which depends on the shop's traffic and on whether its cron keeps up; the reproduction itself had to be scaled down to a 64M limit because the sandbox caps the SQLite file at 25 MiB.

**Smallest source fix.** Bound the sweep. Select a limited batch of candidate ids, process only that batch inside the write transaction, commit, and continue with the next batch until the batch comes back empty. That caps both the memory used and the time SQLite's writer lock is held, independently of the backlog size, and keeps the expiry and its webhook atomic per batch. As a second line of defence, one task must not be able to lose a whole tick: the background runner should catch the failure, record it in the task health, still write the heartbeat and still release the lease, and the sweep should additionally be limited so that its memory use can never approach memory_limit.

`includes/invoice.php` —     /** Invoices expired per batch. Caps the sweep's memory and its write-lock hold. */
    private const EXPIRY_BATCH = 100;

    public static function markExpiredInvoices(): int {
        $now = time();
        $count = 0;
        // Bounded batches: the whole backlog must never be selected at once, and the
        // write lock must not be held for the whole sweep. A single unbounded
        // fetchAll() of every expiring invoice exhausted the PHP memory limit and
        // killed the whole background run with an E_ERROR that no catch (Throwable)
        // could contain.
        while (true) {
            $batch = Database::fetchAll(
                "SELECT id, store_id FROM invoices
                 WHERE status = 'New' AND expiration_time < ?
                 ORDER BY id LIMIT ?",
                [$now, self::EXPIRY_BATCH]
            );
            if (empty($batch)) {
                break;
            }
            $progressed = 0;
            Database::beginWriteTransaction();
            try {
                foreach ($batch as $row) {
                    $stmt = Database::query(
                        "UPDATE invoices SET status = 'Expired' WHERE id = ? AND status = 'New'",
                        [$row['id']]
                    );
                    if ($stmt->rowCount() === 0) {
                        continue; // settled by another worker in the meantime
                    }
                    $progressed++;
                    $invoice = self::getById($row['id']);
                    if ($invoice) {
                        WebhookSender::fireEvent($row['store_id'], 'InvoiceExpired', $invoice);
                    }
                }
                Database::commit();
            } catch (Throwable $e) {
                Database::rollback();
                throw $e;
            }
            $count += $progressed;
            if ($progressed === 0) {
                break; // nothing left to expire: stop instead of looping
            }
        }
        return $count;
    }

**Supporting evidence**

- `includes/invoice.php:348` — "SELECT id, store_id FROM invoices WHERE status = 'New' AND expiration_time < ?" - no LIMIT, no chunking: the result set is the whole expired backlog, and each row costs 456.5 bytes of PHP memory in Database::fetchAll().
- `includes/database.php:1010` — Database::fetchAll() returns self::query($sql, $params)->fetchAll(), i.e. the entire result set is held in PHP memory at once. Measured: 170,000 rows -> 83,902,464 bytes in use (base 6,291,456 + 77,611,008), 456.5 bytes per row; 50,000 rows -> 27,262,976 bytes.
- `includes/database.php:953` — beginWriteTransaction() takes SQLite's writer lock with 'DELETE FROM config WHERE 0' before anything is read, so the unbounded work at includes/invoice.php:354-367 runs inside one write transaction and a failure rolls the whole sweep back.
- `includes/background_runner.php:227` — The per-task error handling is 'catch (Throwable $e)'. A PHP memory-exhaustion E_ERROR is not a Throwable: in the local run the task produced no 'error: ...' result, the process exited 255 and the code after BackgroundRunner::run() never executed.
- `includes/background_runner.php:90` — ALWAYS_RUN = update_check, recover_wallet_operations, ready_store_wallets, poll_quotes, webhook_outbox. The fatal in 'poll_quotes' therefore also prevents 'webhook_outbox' from running in that tick, and the rotating set after it.
- `includes/background_runner.php:264` — Config::set('cron_last_run', (string)time()) is the run's heartbeat; it is never reached, so the operator's dashboard shows the background task as never run (admin.php:141 computes $stale from it, admin.php:5267-5270 renders 'Background tasks have never run').
- `includes/background_runner.php:266` — The run's 'finally { self::releaseLease($lease); }' does not execute when a task dies with an E_ERROR (the error does not unwind the stack). Measured: after the fatal, cron_lease_until was still set to a future timestamp, and the next tick answered 'another run holds the lease' and did no work at all until the lease was released (LEASE_SECONDS = 360, includes/background_runner.php:42).
- `includes/background_runner.php:135` — 'expire_old_invoices' is a rotating task and is the only mechanism that bounds the 'New' backlog (invoices older than 30 days, and it emits no InvoiceExpired webhook). It never runs in a tick whose runner died, so the backlog cannot shrink. 'cleanup_invoices' (includes/background_runner.php:842) deletes only Settled invoices and unpaid ones without a quote, so it does not bound the backlog either.
- `includes/invoice.php:803` — Invoice::pollSingleQuote(): the customer-facing path (payment.php:40, includes/api/invoices.php:121) never calls the sweep; it expires one invoice inline. That is why customer payments keep being settled while the background run is dead, and why no ecash is destroyed: the sweep only updates invoice rows and inserts webhook_deliveries rows.
- `includes/background_runner.php:124` — BackgroundRunner::tasks()['expire_invoices']: the same sweep is also a rotating task, so it would hit the same fatal; but it is never reached, because the run dies earlier at 'poll_quotes'.

---

### 4.9 The stranded-funds export hands the same bearer proofs out in a second live token because it reserves nothing

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `invoice.php:exportNamespaceAsToken:unreserved-token-export` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:2544` | The operator's POST reaches the stranded-export action. The session and CSRF checks at admin.php:782-795 pass, and - unlike action=export_token at admin.php:2022 - no cashupay_acquire_lock() funds lock is taken here or anywhere below. |
| 2 | `propagation` | `admin.php:2569` | Invoice::exportNamespaceAsToken($mintUrl, $unit, $account) is called with the namespace the operator named. The only check is assertNotLiveNamespace() inside the callee, which asks whether a store still reaches the namespace - not whether it has already been exported. |
| 3 | `propagation` | `includes/invoice.php:1476` | The namespace's UNSPENT proofs are read with $storage->getProofsAsObjects(ProofState::UNSPENT). No state transition reserves them: the normal export path calls Invoice::markProofsExported() (includes/invoice.php:1425) here, this path does not, so the proofs stay spendable in the local ledger after the token exists. |
| 4 | `sink` | `includes/invoice.php:1482` | 'token' => $wallet->serializeToken($unspent) serializes those still-UNSPENT proofs into a Cashu token that is returned to the operator at admin.php:2576-2585. The bearer proofs are now in somebody's hands while the local ledger still counts them as spendable, and no transfers row (admin.php:2333) records the handover, so the token can neither be reconciled by the cron nor taken back with reclaim_transfer. |

**Root cause.** includes/invoice.php:1472-1490 (Invoice::exportNamespaceAsToken) performs a read-only serialization of a namespace's UNSPENT proofs. It omits the three controls the equivalent fund-moving path uses: (1) the per-store funds lock cashupay_acquire_lock() taken by admin.php:2022, admin.php:1062 and includes/lightning_address.php:324; (2) the UNSPENT->EXPORTED transition performed by Invoice::markProofsExported() (includes/invoice.php:1414-1429) on the normal export path, which also aborts the whole export when a proof is no longer unspent; (3) the transfers ledger row (Transfer::open) that records the handover, so the existing transfer_token / check_transfer / reclaim_transfer machinery can reconcile the token or take it back. Because none of the three is done, the local ledger cannot distinguish 'money still held' from 'money already handed to a third party in an outstanding token', and the guard that does exist - assertNotLiveNamespace (includes/invoice.php:1499-1508) - only answers whether the namespace is reachable from the current store list, not whether it has already been exported.

**Intended behaviour.** A wallet namespace's proofs are handed to a third party exactly once. The export reserves them (leaves the spendable pool), records the handover in the transfers ledger before the token is disclosed, and is serialized against concurrent outgoing operations by the per-store funds lock. Re-exporting after a lost token is safe because the token is stored and can be re-displayed (admin.php:969-990), not because the same proofs are serialized a second time.

**Lower-trust principal and starting capability.** Operator admin session with a valid per-session CSRF token (admin.php:782-795). There is no unauthenticated or API-key path to this action. Single-operator product: the same admin role owns every store, so no tenant or store boundary is crossed. The harmed principal is the person the operator hands the second token to (a claimant of the stranded funds, or a customer of the shop). The operator exports the namespace and a second export overlaps it (a second click, a second browser tab, or the re-export the UI explicitly offers for a lost token), or the namespace becomes live again while the exported token is still outstanding.

**Conditions.**

- *(authentication_level)* Operator admin session with a valid per-session CSRF token (admin.php:782-795). There is no unauthenticated or API-key path to this action.
- *(authorization_role)* Single-operator product: the same admin role owns every store, so no tenant or store boundary is crossed. The harmed principal is the person the operator hands the second token to (a claimant of the stranded funds, or a customer of the shop).
- *(data_state)* A wallet namespace holds UNSPENT proofs and is not reachable from any store's current mint list - the stranded state this screen exists for. The namespace becomes reachable again if the store's mint URL or unit is pointed back (Config::updateStore with consent) or the old mint is kept/re-added as a backup.
- *(user_interaction)* The operator exports the namespace and a second export overlaps it (a second click, a second browser tab, or the re-export the UI explicitly offers for a lost token), or the namespace becomes live again while the exported token is still outstanding.
- *(system_configuration)* A store's mint URL or mint unit was changed earlier, which is what strands the funds in the first place (admin.php:1296-1452).
- *(third_party_dependency)* The Cashu mint enforces one-time spend of a proof, so only one of the two tokens can ever be redeemed. This bounds the damage: the second token is dead, not a second payment.

**Bounded, target-native reproduction**

```text
attacker perspective: The operator of the shop (or anyone who has obtained the admin session and its CSRF token) presses 'Export as token' on the 'Recover Funds From a Previous Mint' card; the resulting token carries the same bearer proofs a previous export already carried, so the same money can be promised to a second person.

payload: dummy store id 's1', wallet_account_id 'wa_probe_account', mint https://old-mint.example, unit sat, 4 dummy proofs (4/8/16/32 sat) inserted into namespace (old-mint, sat, wa_probe_account)
payload: store mint repointed to https://new-mint.example so the namespace is stranded
payload: Invoice::exportNamespaceAsToken('https://old-mint.example', 'sat', 'wa_probe_account') called twice (equivalent to two POSTs of action=export_stranded_funds)

steps:
  - Run the scratch fixture inside the provided sandbox: sbx.sh w4d-stranded -- php stranded_probe.php (CASHUPAY_DATA_DIR inside the agent scratch directory; no mint is contacted).
  - Read the output: the proof states after export, the second token, the scan after export, and the balance once the namespace is live again.

observed:
  Export #1 returned a cashuB token for 60 sat / 4 proofs and every proof stayed UNSPENT; 'exports recorded in the transfers ledger: 0'. Export #2 returned a byte-identical token carrying the identical secrets [secret-1..secret-4]. The scan still listed amount=60 count=4 as recoverable. After the store's mint was pointed back at the old mint, Invoice::getBalance() returned 60 sat with the very same secrets that the exported token carries. No mint was contacted; the fixture used dummy proofs and a disposable database in the scratch directory.
```

**Impact.** The same bearer proofs are handed to two people, so the same money is promised twice; the mint's one-time-spend rule means only one of the two tokens can be cashed in, so the second holder (a claimant of the stranded funds, or a shop customer) receives a token that cannot be redeemed, and the operator's ledger simultaneously shows the funds as outstanding in a token and as available for withdrawal. No proof is redeemed twice and no payment is made twice, which caps the loss at the stranded amount.

**Likelihood.** Only the operator (or a holder of the admin session + CSRF token) can trigger it, but the screen itself invites the second action: the export button stays on the scan result and the UI text says the money stays saved 'so you can re-export if you lose the token', while the scan keeps showing the full amount as recoverable because the proofs are never marked exported. A single extra click, or the store's mint being pointed back at the old mint, is enough.

**Why this priority.** The trace is complete in source (admin.php:2544-2590 -> includes/invoice.php:1472-1490, with the omitted controls located at admin.php:2022, admin.php:2333-2344 and includes/invoice.php:1414-1429) and the duplicate handout was reproduced locally with dummy proofs in a disposable scratch database: two exports returned byte-identical tokens over the same secrets while the proofs stayed UNSPENT and the transfers ledger stayed empty. The bounding factor (the mint's one-time spend) is stated explicitly rather than relied upon.

**Smallest source fix.** Make the stranded export reserve its proofs and record the handover the way the normal export does. (1) In Invoice::exportNamespaceAsToken(), transition the exported proofs out of UNSPENT (EXPORTED) before the token is returned, and fail the export if the transition does not cover every proof - the same conditional transition Invoice::markProofsExported() uses. (2) Look for an outstanding export for that namespace first and return the token that was already handed out instead of serializing the proofs again, so 're-export after a lost token' means 'show me the token again', not 'a second live token'. (3) Write a transfers row (Transfer::open) holding the token and the proof secrets, so transfer_token / check_transfer / reclaim_transfer can reconcile the export or swap the proofs back, and so the cron learns when the token was redeemed. (4) Take a lock around the export (a namespace-scoped lock, cashupay_acquire_lock('stranded_'.$walletId)) so two concurrent exports cannot both serialize. Note that transfers.store_id is NOT NULL with a foreign key to stores(id) (includes/database.php:508-524), so a namespace whose owning store has been deleted needs either that store id while it exists or a dedicated stranded-exports table. Keep Invoice::assertNotLiveNamespace() exactly as it is: it is correct and is the only thing that stops a live namespace from being written off.

`includes/invoice.php` — public static function exportNamespaceAsToken(string $mintUrl, string $unit, ?string $account): array {
    self::assertNotLiveNamespace($mintUrl, $unit, $account);
    $wallet = new Wallet(rtrim($mintUrl, '/'), strtolower($unit), Database::getDbPath(), $account);
    $storage = $wallet->getStorage();
    $wid = $storage->getWalletId();

    // An export that is still outstanding owns the proofs it carried: hand back the
    // token that was already given out instead of serialising the same proofs twice.
    $outstanding = self::outstandingStrandedExport($wid);
    if ($outstanding !== null) {
        return ['token' => $outstanding['token'], 'amount' => (int)$outstanding['amount'],
                'count' => count($outstanding['secrets']), 'wallet_id' => $wid,
                'secrets' => $outstanding['secrets'], 'existing' => true];
    }

    $unspent = $storage->getProofsAsObjects(ProofState::UNSPENT);
    if (empty($unspent)) {
        return ['token' => null, 'amount' => 0, 'count' => 0, 'wallet_id' => $wid];
    }
    $secrets = array_map(fn($p) => $p->secret, $unspent);

    // Reserve before disclosing: the proofs leave the spendable pool, so no later
    // export, cron run or withdrawal can hand the same bearer proofs to someone else.
    $changed = $storage->transitionProofStates($secrets, ProofState::EXPORTED, [ProofState::UNSPENT]);
    if ($changed !== count(array_unique($secrets))) {
        throw new Exception('Some of this money was being used at the same moment. Nothing was exported; please try again.');
    }

    return [
        'token' => $wallet->serializeToken($unspent),
        'amount' => Wallet::sumProofs($unspent),
        'count' => count($unspent),
        'wallet_id' => $wid,
        'secrets' => $secrets,
    ];
}

`admin.php` — // One export at a time per namespace, and a ledger row so the handover can be
// reconciled or taken back like any other token export.
$wid = \Cashu\WalletStorage::deriveWalletId(rtrim($mintUrl, '/'), $unit, $account);
$exportLock = cashupay_acquire_lock('stranded_' . $wid);
if (!$exportLock) {
    throw new Exception('Another recovery export is in progress for this namespace. Try again in a moment.');
}
try {
    $result = Invoice::exportNamespaceAsToken($mintUrl, $unit, $account);
    if ($result['token'] === null) {
        throw new Exception('No unspent funds found in that namespace.');
    }
    if (empty($result['existing'])) {
        Transfer::open($owningStoreId, Transfer::TYPE_TOKEN_EXPORT, $result['amount'], $unit,
            null, implode(',', $result['secrets']), 'Cashu token', $result['token'], $mintUrl);
    }
    $_SESSION['stranded_export'][$result['wallet_id']] = $result['secrets'];
} finally {
    cashupay_release_lock($exportLock);
}

**Supporting evidence**

- `includes/invoice.php:1476` — $unspent = $storage->getProofsAsObjects(ProofState::UNSPENT); - the export reads the namespace's spendable proofs and the function contains no write to proof state; the docblock at includes/invoice.php:1465-1468 states the consequence: 'Read-only: it does NOT change local proof state, so a lost token can be re-exported safely.'
- `includes/invoice.php:1482` — 'token' => $wallet->serializeToken($unspent) - a pure serialization of the same proofs, so repeating the call returns the same token over the same secrets.
- `admin.php:2575` — $_SESSION['stranded_export'][$result['wallet_id']] = $result['secrets']; is the only record of the handover. No Transfer::open() row is written, so the token is not in the transfers ledger and the existing admin.php:969-990 (transfer_token), 992-1037 (check_transfer) and 1039-1101 (reclaim_transfer) machinery cannot re-display, reconcile or take it back.
- `admin.php:2022` — $exportLock = cashupay_acquire_lock('funds_' . $storeId); - the normal export path serializes its outgoing operation against concurrent withdrawals, reclaims and cron auto-withdrawals (includes/locks.php:18-29). The stranded export path takes no lock at all.
- `admin.php:2344` — Invoice::markProofsExported($storeId, $sentSecrets); - the normal export path parks the exported proofs in EXPORTED before the token is returned; the preceding comment at admin.php:2328-2330 states the invariant ('not a later export, not cron, not a withdrawal - hands the same bearer proofs to someone else'). The stranded path has no equivalent.
- `includes/invoice.php:1425` — $changed = $storage->transitionProofStates($secrets, ProofState::EXPORTED, [ProofState::UNSPENT]); - the control the stranded path omits; it also fails the whole operation when the count does not match, which is what stops a duplicate handout.
- `includes/invoice.php:1539` — Invoice::scanStrandedNamespaces() groups only state = ProofState::UNSPENT, so after a stranded export the previous-mint screen keeps listing the full amount as recoverable even though a token carrying it is already outstanding (the UI text at admin.php:7234 says so: 'The proofs stay saved until you mark them recovered, so you can re-export if you lose the token').
- `includes/invoice.php:1094` — Invoice::getAccountBalances() reads the store's spendable balance out of the same per-account storage, so once a namespace becomes reachable by a store again the exported proofs are back in Invoice::getBalance() while the token that already carries them is still outstanding.
- `tests/export_lifecycle.php:5` — The repository's own statement of the invariant: 'export marked its proofs PENDING, and the next export called checkPendingProofs() ... and returned them to the balance - so the same bearer proofs could be handed to two people.' The stranded export is the remaining path that hands them out twice.
- `includes/store_safety.php:142` — StoreSafety::strandedForStore() counts UNSPENT, PENDING and EXPORTED proofs as holdings, while Invoice::scanStrandedNamespaces() counts only UNSPENT. A stranded export that leaves its proofs UNSPENT therefore keeps the namespace in the 'still held' set of the deletion blocker, which is consistent - but it also means the exported money is indistinguishable from money that was never handed out.

---

### 4.10 recover_orphaned background task loads and re-polls every 'Processing' invoice with no bound, and the memory fatal it can raise leaves the cron lease held

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `invoice.recoverOrphanedInvoices.unbounded-processing-sweep` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `includes/background_runner.php:116` | The 'recover_orphaned' task closure calls Invoice::recoverOrphanedInvoices(). The task itself needs no credential, so it runs from cron.php, WP-Cron, the admin 'Run now' action, or any page load that calls Background::trigger() (payment.php:43). |
| 2 | `propagation` | `includes/invoice.php:53` | claimForMinting() writes status='Processing' and processing_since before the mint is asked to sign; the callers' catch blocks only record last_poll_error, so a mint that reports the quote PAID/ISSUED and then fails to sign leaves the row in 'Processing' permanently. |
| 3 | `propagation` | `includes/invoice.php:933` | The sweep itself: `SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?` - no LIMIT, no last_polled_at filter, no quote_id NOT NULL filter, unlike its two siblings in the same file (includes/invoice.php:398 and :991). |
| 4 | `propagation` | `includes/database.php:1010` | Database::fetchAll() -> PDOStatement::fetchAll() materialises the entire result set in PHP memory (2,242.5 bytes per row measured for this table and this row shape); at the default 128 MiB limit the process dies with an uncatchable E_ERROR. |
| 5 | `propagation` | `includes/invoice.php:958` | The per-row loop asks each invoice's mint about its quote (checkMintQuote() -> GET /v1/mint/quote/bolt11/<id>): one outbound HTTPS request per stuck row, on every pass, with no cooldown because last_polled_at is neither read nor written here. |
| 6 | `propagation` | `includes/background_runner.php:245` | The rotation loop checks the time budget only between tasks and renews the lease only before a task (:206), so one long recover_orphaned pass consumes the whole budget and, if it outlives LEASE_SECONDS (360), its work is repeated by the next run. |
| 7 | `sink` | `includes/background_runner.php:266` | Because the memory fatal is uncatchable, the `finally { self::releaseLease($lease); }` that would release the cron lease never runs; the lease stays held for LEASE_SECONDS = 360 s and every background run in that window is refused ('another run holds the lease'), so the payment path (poll_quotes, webhook_outbox) does not run at all. |

**Root cause.** Invoice::recoverOrphanedInvoices() selects the entire set of 'Processing' invoices with `SELECT *` and no bound, and re-asks every one of their mints on every pass with no per-invoice cooldown; the rows it sweeps are never retired, so the set only grows.

**Intended behaviour.** Recover interrupted payments without letting one stuck record, or a backlog of them, consume the worker, the operator's memory or the mint's request budget: bound the batch, respect the poll cadence the sibling sweeps already respect, and give a 'Processing' row a terminal state so the sweep converges.

**Lower-trust principal and starting capability.** None for the sweep itself: the background runner is reached by cron.php (X-Cron-Key), WP-Cron, the admin 'Run now' action, or any page load that calls Background::trigger(). Creating the invoices needs a Greenfield API key (100 requests/minute/IP); driving the per-invoice poll that turns a paid quote into a 'Processing' row needs no credential at all (payment.php?id=). The store's mint is the principal that opens the gate: only a mint that reports the quote as PAID/ISSUED causes claimForMinting() to run, and it can then refuse to sign, leaving the invoice in 'Processing'. A store-scoped API-key holder creates the invoices that populate the set.

**Conditions.**

- *(authentication_level)* None for the sweep itself: the background runner is reached by cron.php (X-Cron-Key), WP-Cron, the admin 'Run now' action, or any page load that calls Background::trigger(). Creating the invoices needs a Greenfield API key (100 requests/minute/IP); driving the per-invoice poll that turns a paid quote into a 'Processing' row needs no credential at all (payment.php?id=).
- *(authorization_role)* The store's mint is the principal that opens the gate: only a mint that reports the quote as PAID/ISSUED causes claimForMinting() to run, and it can then refuse to sign, leaving the invoice in 'Processing'. A store-scoped API-key holder creates the invoices that populate the set.
- *(system_configuration)* The store must be configured with a mint URL, a seed phrase and a wallet account id (Config::isStoreConfigured), and a background runner must be scheduled (crontab, WP-Cron, or the page-load trigger).
- *(data_state)* A backlog of invoices in status 'Processing' whose quotes the mint reported as PAID/ISSUED while the mint call failed. Nothing in the codebase retires such a row, so the set only grows.
- *(third_party_dependency)* The mint's behaviour: it reports a quote as paid and then fails or refuses to sign, or it is briefly unreachable while a customer pays.

**Bounded, target-native reproduction**

```text
attacker perspective: An untrusted mint the store points at, or a Greenfield API-key holder for that store together with such a mint: the mint's answers decide that a quote is paid, the key creates the invoices, and the per-invoice poll can be driven without any credential through payment.php?id=. The harm is paid by the operator (the background worker dies, the payment path stalls, the cron lease is held) and by the mint (one request per stuck invoice per pass, unbounded and forever).

payload: 40 dummy rows in `invoices` with status='Processing', quote_id set, created_at older than 60 s, mint_url pointing at the store's mint, plus 20,000 such rows for the memory check.
payload: a mint that answers GET /v1/mint/quote/bolt11/<id> with amount_paid = 0, or answers it as paid and then returns HTTP 500 on POST /v1/mint/bolt11 (a mint that takes the money and refuses to sign), or cannot be loaded at all.

steps:
  - Run everything inside the parent-provided sandbox: `~/security-audit-skill/cashupayserver/run-1/tools/sbx.sh w3a-orphan [--loopback] -- <command>` (no external network; the repo is read-only; writes only to the agent scratch directory; ulimit -t 120, -f 51200, wall 180 s).
  - Memory: `sbx.sh w3a-orphan -- php mem.php build` creates a scratch SQLite database with 20,000 dummy 'Processing' invoices and measures the unmodified query; `sbx.sh w3a-orphan -- php mem.php fatal 32M` calls the unmodified Invoice::recoverOrphanedInvoices() under a 32 MiB limit.
  - Lease: `sbx.sh w3a-orphan -- php lease_test.php setup` then `sbx.sh w3a-orphan -- php -d memory_limit=32M lease_test.php run` (the real BackgroundRunner::run()) then `sbx.sh w3a-orphan -- php lease_test.php probe` to read cron_lease_until from the config table, then a second `run`.
  - Fan-out: `sbx.sh w3a-orphan --loopback -- php fanout.php` starts a local counting mock mint (a disposable PHP script in the scratch directory that appends every request to a log), inserts 40 dummy 'Processing' invoices and counts the mint requests of one recoverOrphanedInvoices() pass against one recoverExpiredPaidInvoices() pass.
  - Stuck state: `sbx.sh w3a-orphan --loopback -- php stuck.php` drives one invoice from New to 'Processing' against a mint that reports the quote paid and then refuses to sign, then shows that no task retires it and that every later recovery pass re-asks the mint about it.

observed:
  Memory: 20,000 rows -> 44,849,224 bytes (2,242.5 B/row) for the SELECT *, against 9,808,512 bytes (490.4 B/row) for the same query restricted to the five columns the loop reads over the same rows; 128 MiB therefore runs out at roughly 55,000-60,000 stuck rows (about 268,000 for the sibling shape). The unmodified function died with 'PHP Fatal error: Allowed memory size of 33554432 bytes exhausted (tried to allocate 20480 bytes) in includes/database.php on line 1010 ... includes/invoice.php(933): Database::fetchAll(...)'; inside BackgroundRunner::run() the same fatal came through includes/background_runner.php(117) and the `finally` that releases the lease never ran, so cron_lease_until was still now+360 and the next background run returned {"skipped": "another run holds the lease", "tasks": []}. Fan-out: 40 stuck rows -> 40 mint quote requests in one pass and 40 more in the next (last_polled_at ignored), while the sibling recoverExpiredPaidInvoices() asked 10 per pass; a 200-day-old 'Processing' row was not removed by cleanup_invoices or expire_old_invoices and was still selected by the recover query; 40 rows against a 0.5 s mint took 20.3 s, the whole default 20 s budget; with a mint that cannot be loaded the handshake repeated per row (2 requests per stuck row, 30 s client timeout each). Stuck state: the invoice went New -> 'Processing' and stayed there through markExpiredInvoices, expire_old_invoices and cleanup_invoices, and each of three recovery passes asked the mint about it again without settling it.
```

**Impact.** Observed locally against the unmodified code: the background runner dies with an uncatchable E_ERROR inside the sweep, the cron lease stays held for 360 s and every background run in that window is refused - no quote polling, no webhook delivery, - what stops is the background backstop poll and the webhook delivery to the shop; paid orders are still settled by payment.php?id= and by the Greenfield GET invoice, and the dashboard still lists the stuck invoices, but the task-health heartbeat and the background-task panel go stale. The same pass also issues one unbounded request per stuck invoice to the store's (usually third-party) mint, on every pass, forever - the failure mode the repository's own HEAD commit 4a82d01 was written to fix. Two corrections to the hunter's wording: the per-row memory cost is 2,242.5 B/row (44,849,224 bytes over 20,000 rows, measured independently), so the 128 MiB threshold is in the high tens of thousands of rows, and the operator is not left completely in the dark.

**Likelihood.** The state is reached whenever the store's mint reports a quote as paid and then fails to sign - an ordinary mint outage or a mint that rate-limits, not an exotic condition (the repository's own HEAD commit 4a82d01 documents a shared mint starting to answer 'Rate limit exceeded'). The worst outcome, a killed worker and a stalled payment path, needs a backlog of tens of thousands of stuck rows, and growing the set quickly additionally needs a mint the attacker influences or a store key the attacker holds.

**Why this priority.** The root cause is fully visible in the source, the two bounded siblings in the same file make the missing bounds unambiguous, and all three effects (the memory fatal, the lease stall and the per-row fan-out) were reproduced deterministically inside the sandbox against the unmodified production functions with dummy data.

**Smallest source fix.** Bound the orphan-recovery sweep the way its two siblings in the same file are already bounded, give a 'Processing' row a terminal state so the sweep converges, and make the runner's lease release survive an uncatchable fatal.

`includes/invoice.php` —     /** How often the same stuck invoice may be re-asked about (seconds). */
    const ORPHAN_POLL_INTERVAL = 300;

    /** How many stuck invoices one recovery pass may look at. */
    const ORPHAN_BATCH_LIMIT = 50;

    public static function recoverOrphanedInvoices(): array {
        $recovered = [];
        $now = time();

        // Bounded like every other sweep over persisted work: a batch limit, a
        // per-invoice cooldown, and only the columns this loop reads.
        $stuck = Database::fetchAll(
            "SELECT id, store_id, mint_url, mint_unit, quote_id FROM invoices
             WHERE status = 'Processing'
               AND quote_id IS NOT NULL
               AND created_at < ?
               AND (last_polled_at IS NULL OR last_polled_at <= ?)
             ORDER BY created_at ASC
             LIMIT ?",
            [$now - 60, $now - self::ORPHAN_POLL_INTERVAL, self::ORPHAN_BATCH_LIMIT]
        );

`includes/invoice.php` —             try {
                // Record that this invoice was examined, so the next pass leaves it
                // alone until the cooldown expires (as recoverExpiredPaidInvoices does).
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                if (!$invoice['quote_id']) {
                    continue;
                }

`includes/invoice.php` —                 } elseif ($quoteStatus->expiry !== null && $quoteStatus->expiry < $now
                          && self::quoteIsDefinitelyUnpaid($quoteStatus)) {
                    // The mint says plainly that nothing was paid and the quote is
                    // over: this invoice is not recoverable any more. Leaving it in
                    // 'Processing' would re-ask the mint about it on every pass,
                    // forever, and keep the sweep's working set growing.
                    Database::update('invoices', ['status' => 'Expired'], 'id = ?', [$invoice['id']]);
                }

`includes/background_runner.php` —             'recover_orphaned' => function () {
                // Bounded work per pass: the runner's own budget is 20 s by
                // default, and a task must not be able to run past the lease.
                $count = count(Invoice::recoverOrphanedInvoices());
                return $count > 0 ? "recovered {$count}" : 'none';
            },

`includes/background_runner.php` —         // A memory-exhaustion fatal is uncatchable and skips the `finally`
        // below, which would leave the lease held for LEASE_SECONDS and stop
        // every background task - including the payment path - for that long.
        // Release it from a shutdown handler as well.
        register_shutdown_function(static function () use ($lease): void {
            self::releaseLease($lease);
        });

        try {
            // The payment path runs first and is not subject to the budget: a customer's
            // settlement must never be skipped because housekeeping ran long.

**Supporting evidence**

- `includes/invoice.php:933` — The sweep query has no LIMIT, no last_polled_at filter and no quote_id filter: `SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?`.
- `includes/invoice.php:398` — Sibling sweep pollPendingQuotes() selects the same kind of rows with `LIMIT ?` and `(last_polled_at IS NULL OR last_polled_at <= ?)` - the bound this path lacks.
- `includes/invoice.php:991` — Sibling sweep recoverExpiredPaidInvoices() likewise uses `LIMIT ?` plus a last_polled_at window, and writes last_polled_at back at :1012.
- `includes/invoice.php:53` — claimForMinting() writes status='Processing' before the mint call and nothing rolls the status back when the call fails.
- `includes/invoice.php:434` — pollPendingQuotes() catches the failed mint call and records it in last_poll_error only; the row stays 'Processing'.
- `includes/invoice.php:958` — One checkMintQuote() call per selected row: the outbound fan-out.
- `includes/invoice.php:570` — getWalletForStore() caches the wallet only after loadMint() succeeds, so an unreachable mint is re-contacted for every row.
- `includes/database.php:1010` — Database::fetchAll() materialises the whole result set via PDOStatement::fetchAll(); there is no streaming and no row cap in the helper.
- `includes/background_runner.php:116` — The recover_orphaned task wrapper: one call to Invoice::recoverOrphanedInvoices() per pass, with no per-task bound of its own.
- `includes/background_runner.php:206` — The lease is renewed only before each task, so a task that outlives LEASE_SECONDS loses the lease mid-run and its work is repeated by the next run.
- `includes/background_runner.php:245` — The time budget is checked only between tasks (`if ($i > 0 && microtime(true) - $rotationStarted > $budgetSeconds) break;`), so a single slow task can consume the entire budget.
- `includes/background_runner.php:266` — The cron lease is released only in a `finally` block, which an uncatchable memory-exhaustion E_ERROR skips.
- `includes/background_runner.php:842` — cleanup_invoices() deletes only 'Settled' and 'Expired'/'Invalid' rows without a quote; a 'Processing' row is never removed, and expire_old_invoices (:135) only touches 'New'.
- `cashu-wallet-php/CashuWallet.php:7354` — checkMintQuote() is one GET per invoice: `mint/quote/bolt11/$quoteId`.
- `cashu-wallet-php/CashuWallet.php:3735` — Each mint request carries CURLOPT_TIMEOUT 30 s and CURLOPT_CONNECTTIMEOUT 10 s, so an unreachable mint costs up to 30 s per row.
- `api.php:54` — Invoice creation by a Greenfield API-key holder is rate limited to 100 requests per minute per IP.
- `payment.php:40` — payment.php?id= polls a single invoice with no rate limit and calls Background::trigger() at :43, so the per-invoice mint poll that creates a 'Processing' row needs no credential.

---

### 4.11 The mint-URL check resolves the host and discards the answer, so the connection can still land on a private address

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `mint-url-ssrf-gate-not-bound-to-outbound-connection` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:2404` | $mintUrl = MintHelpers::assertSafeMintUrl((string)$mintUrl); - the operator-supplied mint URL enters the gate. The same call is made by update_store (admin.php:1317), create_store (admin.php:1185) and setup.php:496. |
| 2 | `propagation` | `includes/mint_helpers.php:37` | $target = Security::resolveTarget($canonical); resolves the host once; the returned 'ips' are judged by Security::isPublicIp at line 46 (44-53). |
| 3 | `propagation` | `includes/mint_helpers.php:55` | return $canonical; - only the URL string leaves the gate. The resolved addresses and the resolve entries that would pin the connection are discarded, and the stored mint URL is never re-checked afterwards. |
| 4 | `sink` | `cashu-wallet-php/CashuWallet.php:3752` | curl_setopt_array sets CURLOPT_URL and the protocol limits but no CURLOPT_RESOLVE, so curl_exec at line 3761 resolves the name again and connects wherever that second lookup points. Cashu\Wallet builds its client from the stored mint URL (CashuWallet.php:5789), and includes/invoice.php:571,594 build the wallet straight from the stored value. |

**Root cause.** assertSafeMintUrl uses Security::resolveTarget (resolve without judging) and re-implements the judgement itself, then returns the URL only, so the resolve entries that Security::resolveTarget already produced are dropped instead of being carried to the connection. The code's own rule in includes/security.php says a check says nothing about the next lookup and that code which goes on to connect must pin the addresses it returned. Because the stored mint URL is also never re-validated after it is saved, the gap is permanent for every later request to that mint.

**Intended behaviour.** The address the gate checked is the address the server connects to. A mint whose name resolves to a private address at request time is refused, exactly as the webhook path refuses one (WebhookSender::planDelivery + CURLOPT_RESOLVE, webhook_sender.php:197-217 and 313-315).

**Lower-trust principal and starting capability.** Adding or changing a mint URL needs the operator session and CSRF token; from then on the stored URL is contacted by the server on its own (invoice creation, quote polling, balance refresh, recovery) without any further operator action.

**Conditions.**

- *(authentication_level)* Adding or changing a mint URL needs the operator session and CSRF token; from then on the stored URL is contacted by the server on its own (invoice creation, quote polling, balance refresh, recovery) without any further operator action.
- *(third_party_dependency)* The attacker's DNS name has to be the one the operator configured as a mint - a mint the operator trusts with the shop's funds, or a mint the operator was talked into using. The project's own threat model lists a malicious mint as a principal that must not be able to cause SSRF.
- *(network_routing)* The name must be able to answer differently for the check and for the connection (a short-TTL record, or a name whose address changes), and the server must be able to reach the private target.
- *(data_state)* The mint URL must already be stored; the check runs only when the URL is added or changed, never on the request path.

**Bounded, target-native reproduction**

```text
attacker perspective: A third party the operator has configured as a mint (or whose DNS the attacker controls) and who can answer the check with a public address and the connection with a private one.

payload: mint URL = http://localhost:18191/ with the DNS test hook answering 93.184.216.34 for every host
payload: mint URL whose A record is 93.184.216.34 for the check and 127.0.0.1 for the connection (TTL 0)

steps:
  - Start a dummy loopback fixture: php -d opcache.enable=0 -S 127.0.0.1:18191 fixture_router.php and the same on [::1]:18191 (it answers any path with JSON and logs each request line to fixture-hits.log).
  - In a separate process, define CASHUPAY_DATA_DIR to a scratch directory, require includes/mint_helpers.php, includes/config.php, includes/webhook_sender.php and cashu-wallet-php/CashuWallet.php, call Security::setResolverForTesting(fn(string $host) => ['93.184.216.34']), then call MintHelpers::assertSafeMintUrl('http://localhost:18191/') and a Cashu\MintClient for the same URL -> get('info').
  - For the contrast, call WebhookSender::planDelivery('http://localhost:18191/') and then the private WebhookSender::sendRequest for the same URL, and read fixture-hits.log after each step.

observed:
  gate (resolver hook): accepted -> http://localhost:18191 (the vetted address is 93.184.216.34); MintClient reached: {"name":"internal-only-service","version":"9.9.9","secret_note":"INTERNAL-DATA-ONLY-REACHABLE-FROM-LOOPBACK"}; fixture hits: ["GET /v1/info host=localhost:18191"] - the address that was checked and the address that was connected to differ on the same request. The sibling webhook path, given the same URL, produced the pinned entry ['localhost:18191:93.184.216.34'] and its request never reached the fixture ('{"status_code":0,"response":"connection failed"}', no fixture hit). One correction to the original record: without the operator opt-in webhook_allow_private_targets the webhook path refuses that URL outright (planDelivery = null), because it also limits public targets to ports 80/443 (webhook_sender.php:204-209) - the mint path imposes no port limit at all, so it is strictly the weaker of the two. (Bounded run, command and limits in the artifact check-mint-url-ssrf-gate.txt.)
```

**Impact.** The server issues requests (GET for reads, POST for mint quotes and swaps) to addresses inside its own network on every mint contact, and it does so for a stored URL that is never re-validated, so the exposure is permanent. Reading the answer back needs the operator to press 'test this mint', which the missing gate on that action (see the sibling candidate) allows.

**Likelihood.** The attacker must control the DNS of a name the operator configured as a mint; short-TTL rebinding is a well-known, cheap technique, and the project itself has already been bitten by this class on the webhook path (DS41-03, SPB-SEC-005, OPUS-008 are cited in the code for exactly that reason).

**Why this priority.** The gap is visible in the current source (the gate returns the URL only, the client pins nothing), the codebase documents the rule it breaks, and the bounded local check shows the checked address and the connected address differing on the same request while the pinned sibling path behaves correctly.

**Smallest source fix.** Carry the vetted addresses to the connection, as the webhook path already does: have assertSafeMintUrl return the resolve entries and pass them into the wallet's HTTP client, which sets CURLOPT_RESOLVE. Re-resolve and re-vet on every request so a name that changes after it was saved is refused rather than followed.

`includes/mint_helpers.php` —     public static function assertSafeMintUrl(string $mintUrl): string {
        ...
        $target = Security::resolvePublicTarget($canonical);   // resolves once, judges every address
        if ($target === null) {
            throw new \InvalidArgumentException(
                'The mint\'s address could not be found. Check it for typos and try again.'
            );
        }
        return $canonical;
    }

    /**
     * The vetted addresses for a mint URL, ready to pin the connection to with
     * CURLOPT_RESOLVE. Empty for a literal IP, which needs no pinning.
     *
     * @return string[]
     */
    public static function safeMintResolve(string $mintUrl): array {
        $canonical = self::assertSafeMintUrl($mintUrl);
        return Security::resolvePublicTarget($canonical)['resolve'] ?? [];
    }

`cashu-wallet-php/CashuWallet.php` —     public function __construct(string $mintUrl, int $timeout = 30, array $resolve = [])
    {
        $this->mintUrl = self::canonicalizeMintUrl($mintUrl);
        $this->timeout = $timeout;
        $this->resolve = $resolve;   // CURLOPT_RESOLVE entries: the vetted addresses
    }

    // ... in request(), after curl_setopt_array($ch, [...] + self::curlProtocolOptions()):
    //     if (!empty($this->resolve)) {
    //         $options[CURLOPT_RESOLVE] = $this->resolve;
    //     }

**Supporting evidence**

- `includes/mint_helpers.php:55` — assertSafeMintUrl returns the canonical URL only; the vetted addresses are not returned to the caller and cannot be pinned to the connection.
- `includes/security.php:212` — The documented rule the gate breaks: 'A yes here says nothing about the *next* lookup of the same name. Code that goes on to connect should use resolvePublicTarget() and pin the addresses it returns.'
- `includes/security.php:256` — Security::resolveTarget already returns the 'resolve' entries (host:port:ip[,ip]) needed for CURLOPT_RESOLVE (line 289); the mint gate uses it and throws them away. resolvePublicTarget (line 232) is the one-shot variant the docblock points at.
- `cashu-wallet-php/CashuWallet.php:3752` — MintClient::request sets no CURLOPT_RESOLVE, so the connection's DNS answer is independent of the checked one.
- `includes/webhook_sender.php:313` — The sibling path pins: sendRequest sets CURLOPT_RESOLVE from the entries planDelivery returned (planDelivery at 197-217), so the connection uses the address that was checked.
- `tests/webhook_ssrf.php:56` — The repository's own test asserts the webhook path's pinning ('the connection is pinned to the address that was checked', 'the name was resolved exactly once') and has no equivalent for the mint path.
- `includes/invoice.php:571` — The request path builds the wallet from the stored $store['mint_url'] (also line 594) with no re-validation, so a name that changes after it was saved is followed on every invoice creation and quote poll.

---

### 4.12 A stranger can mark a merchant's published payment request as paid without paying for it

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `receive.php:payment_request.receiptState-not-bound-to-request` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `receive.php:156` | The request id is taken from the POST body or the query string of an unauthenticated request: $requestId = receiveParam($data, 'id') ?? receiveParam($_GET, 'request_id'). |
| 2 | `propagation` | `receive.php:270` | PaymentRequest::claim() binds the request to the secrets of the posted token and sets status='receiving' with input_secrets; a second, different token is refused with 409 (receive.php:275-280). |
| 3 | `propagation` | `receive.php:319` | The check 'if ($gross < $requested) throw' rejects an under-paying token before any swap happens (the swap is at receive.php:332), so the request stays in status 'receiving' while nothing was received for it. |
| 4 | `propagation` | `receive.php:362` | The catch block asks PaymentRequest::receiptState() what became of the bound secrets; the answer is 'paid' (the proofs were spent by an earlier, unrelated payment), so the claim is not released (only 'released' does that, receive.php:372-374) and the request stays in status 'receiving'. |
| 5 | `propagation` | `receive.php:290` | The retry takes the 'same' branch (receive.php:281), receiptState() again answers 'paid', and the branch reports success at receive.php:291-296 without ever comparing the received amount with the amount the request asked for. |
| 6 | `propagation` | `includes/payment_request.php:161` | receiptState() returns 'paid' when every bound secret is SPENT in cashu_proofs for this wallet - the proof ledger is global to the wallet and holds no reference to the request, so a token the merchant swapped in some earlier, unrelated payment answers 'paid' here too. |
| 7 | `sink` | `includes/payment_request.php:82` | markPaid() writes status=PAID, received_amount and the replayable result with the WHERE clause 'id = ? AND status IN (?, ?)': the amount the request asked for is not part of the condition, so any amount can be recorded as the payment for it. includes/payment_request.php:204 does the same from the background reconciler. |

**Root cause.** includes/payment_request.php:144-172 (receiptState) answers 'paid' from cashu_proofs.state = SPENT for the bound secrets, without any binding between those proofs and this request's swap (the query is filtered only by the wallet_id and the secrets), and includes/payment_request.php:73-87 (markPaid) records status=PAID and received_amount with the WHERE clause 'id = ? AND status IN (?, ?)' - the amount the request asked for is not part of the condition, so any amount can be recorded as the payment for it. receive.php:281-296 (the 'same' branch) applies that answer before the amount check at receive.php:316-328 is ever reached, and receive.php:353-378 leaves the claim in place when receiptState() says 'paid' (only 'released' frees the request), so the background task (includes/payment_request.php:180-218, markPaid at :204) completes the same transition without any further request.

**Intended behaviour.** A payment request becomes 'paid' only when a payment made for that request brought in at least the requested amount, and the retry of a lost answer replays the result of that request's own swap. A token that the merchant's wallet has already consumed for some other payment must be refused as a payment for this request.

**Lower-trust principal and starting capability.** Anonymous. Every step of the attack uses only the public /receive.php endpoint; no store, operator or API key is needed. None from the merchant. The attacker performs every step itself; the merchant only has to act on the resulting 'paid' state, or on the payment-request page that polls the public status endpoint.

**Conditions.**

- *(authentication_level)* Anonymous. Every step of the attack uses only the public /receive.php endpoint; no store, operator or API key is needed.
- *(system_configuration)* The merchant's store must be configured (mint, seed, wallet account) and its mint must be reachable; the attacker needs one token that the store's mint issued, which any wallet can obtain for a small amount.
- *(user_interaction)* None from the merchant. The attacker performs every step itself; the merchant only has to act on the resulting 'paid' state, or on the payment-request page that polls the public status endpoint.
- *(data_state)* The attacker's token must already be recorded as SPENT in the wallet of the store that owns the request, which one earlier plain payment to /receive.php achieves; the same token then works for any number of requests of that store (but not for another store of the same server - receiptState() filters cashu_proofs by wallet_id, so a token spent in store_1 leaves a store_2 request 'pending').
- *(network_routing)* The store's mint must accept the attacker's token (an ordinary public Cashu mint); the malicious mint role of the architecture summary does not apply here.

**Bounded, target-native reproduction**

```text
attacker perspective: An unauthenticated stranger who can reach the public receive endpoint of the merchant's server and who can obtain one small ecash token from the merchant's own mint.

payload: A token of 64 sat minted from the store's own mint (tests/mock_mint_router.php in the fixture; a real mint in production).
payload: GET /receive.php?store_id=<store>&amount=500&format=json  (publish the request; the id comes back)
payload: POST /receive.php  body {"store_id":"<store>","token":"<the 64 sat token>"}  (pay it once, with no request id, so the merchant's wallet records its input proofs as SPENT)
payload: POST /receive.php?request_id=<id>  body {"store_id":"<store>","token":"<the same 64 sat token>"}  (twice)

steps:
  - Run the repository's mock mint and the real receive.php on loopback in the sandbox (scratch/verify_receive_binding.php, run with sbx.sh v2-receive --loopback), with a throwaway CASHUPAY_DATA_DIR and a dummy store whose mint_url is the mock mint. Do the four requests above in order and read the payment_requests row and the public status endpoint after each. The scratch artefact check-receive-receipt-binding.txt records the whole run, plus two further variants (a 512 sat token against a 500 sat request, and the background reconciler) and a cross-store scope check; part 2 of it re-runs the repository's own fixture tests/payment_request_binding.php as a control.

observed:
  STEP 1 plain receive -> HTTP 200 {"success":true,"amount":63,"unit":"sat","proofs_count":6}, and the merchant's wallet row for the token's secret is state SPENT. STEP 2 -> HTTP 200, request id issued for amount 500. STEP 3 (post the spent token with request_id) -> HTTP 400 {"error":"This token is for 64 sat, but the request asks for 500 sat"}, and the request row is left status=receiving with input_secrets=[that secret]. STEP 4 (post it again) -> HTTP 200 {"success":true,"id":"...","amount":63,"unit":"sat"} and the request row is status=paid, received=63 although the request asked for 500 and no money arrived for it; the merchant's wallet holds only the 6 unspent proofs worth 63 sat in total, all from STEP 1. STEP 5 -> GET /receive.php?status=1&request_id=... answers {"status":"paid","receivedAmount":63,"unit":"sat"}. STEP 6 -> PaymentRequest::reconcileReceiving() answers {"checked":1,"paid":1,"released":0,"errors":0} and a second request is marked paid with the same token, with no further request. STEP 7 -> an honest customer's fresh 300 sat payment to the already-paid request is answered HTTP 200 {"success":true,"id":"...","amount":63,"unit":"sat"}, the merchant's balance is unchanged ({"c":15,"s":574} before and after), no proof row exists in the merchant's wallet for the customer's token, and the customer's token is still UNSPENT at the mint. VARIANT (512 sat token against the 500 sat request): the first post passes the amount check and the mint refuses the token (HTTP 400 {"error":"Token already spent."}), the request is left status=receiving, and the retry answers HTTP 200 {"amount":511} with the request status=paid, received=511 for a request that asked for 500. SCOPE -> the same store_1-spent token posted to a store_2 request is refused twice with HTTP 400 and the store_2 request stays status=pending (receiptState is wallet-scoped). CONTROL -> the repository's own fixture tests/payment_request_binding.php passes unchanged, including 'Underpayment is still refused, and frees the request' - a fresh 20 sat token for a 50 sat request leaves the request payable - which isolates the defect to the already-spent token.
```

**Impact.** Nothing is taken from the merchant's balance: the only money that arrives is the attacker's own earlier plain payment, credited to the store's wallet. What the defect changes is (a) the merchant-visible truth of a payment request - the request page and the public status endpoint report 'paid' for a request no payment was made for - and (b) the payment of an honest customer to that request, which is answered with the replayed success result and never swapped, so the merchant receives nothing for it while the customer is told the payment succeeded (the customer's token is still UNSPENT at the mint). No invoice, webhook or store balance is affected: nothing else in the product consumes the payment-request state, so the loss depends on the merchant or a customer acting on the false 'paid' signal.

**Likelihood.** Every step is unauthenticated and uses only the public endpoint (the 20/min POST and 30/min GET rate limits are no obstacle for a four-request attack); the attacker needs one small token from the merchant's own mint, which is then reusable for any number of requests of that store. The request id is either created by the attacker himself (GET ?store_id=..&amount=..) or is the one the merchant publishes in the QR/URL he shares.

**Why this priority.** The full path was reproduced locally with the repository's own receive.php, PaymentRequest class and mock mint in three variants (under-paying token, over-paying token, background reconciler) plus the swallowed honest payment and a cross-store scope check, and every cited line was re-read at commit 4a82d01. The repository's own fixture tests/payment_request_binding.php passes unchanged and shows that the same underpayment with a fresh token is harmless (the request stays payable), which isolates the defect to the already-spent token.

**Smallest source fix.** Bind the 'paid' answer to the request and to the amount. A payment request may only be recorded as paid for a payment that was made for it and that covered the amount it asked for: refuse a token whose input proofs the store's wallet has already consumed before the request claimed them, and never write status=PAID for a payment below the requested amount. The amount comparison must use the GROSS amount the payer sent, not the credited (net) amount, because receive.php deliberately accepts a sender that did not add the mint's input fee (receive.php:325-328) - comparing the net amount would leave that accepted case permanently unpaid.

`includes/payment_request.php` — public static function markPaid(array $request, int $receivedAmount, array $result, ?int $grossAmount = null): bool {
    $gross = $grossAmount ?? $receivedAmount;
    $changed = Database::update(
        'payment_requests',
        [
            'status' => self::STATUS_PAID,
            'received_amount' => $receivedAmount,
            'received_at' => time(),
            'result' => json_encode($result),
        ],
        // A request is never recorded as paid for less than it asked for. The
        // comparison uses the gross amount the payer sent, because a sender that
        // did not add the mint fee is still good for the full requested amount.
        'id = ? AND status IN (?, ?) AND amount <= ?',
        [$request['id'], self::STATUS_PENDING, self::STATUS_RECEIVING, $gross]
    );

    return $changed === 1;
}

`includes/payment_request.php` — if (count($spent) === count($secrets)) {
    $proofs = $storage->getProofsBySecretsAsObjects($secrets);
    $gross = \Cashu\Wallet::sumProofs($proofs);
    $amount = $gross - $wallet->calculateFee($proofs);
    // 'gross' is what the payer sent: callers compare it with the requested
    // amount the same way the POST path compares $gross with $requested.
    return ['state' => 'paid', 'amount' => max(0, $amount), 'gross' => $gross];
}

`includes/payment_request.php` — $state = self::receiptState($wallet, $secrets);
if ($state['state'] === 'paid'
    && (int)($state['gross'] ?? $state['amount']) >= (int)$request['amount']) {
    $response = [
        'success' => true,
        'id' => $request['id'],
        'amount' => $state['amount'],
        'unit' => $request['unit'],
    ];
    if (self::markPaid($request, $state['amount'], $response, (int)($state['gross'] ?? $state['amount']))) {
        $result['paid']++;
        error_log("CashuPayServer: payment request {$request['id']} paid (recovered): {$state['amount']} {$request['unit']}");
    }
} elseif ($state['state'] === 'released'
    || ($state['state'] === 'paid' && (int)($state['gross'] ?? $state['amount']) < (int)$request['amount'])) {
    self::release($request, $secrets);
    $result['released']++;
}

`receive.php` — // Before the claim: the inputs of a fresh payment are not ours yet. If our
// own ledger already records them as SPENT, this token was consumed by an
// earlier payment (a plain receive, or another request) and cannot pay this one.
if ($paymentRequest !== null && $paymentRequest['status'] === PaymentRequest::STATUS_PENDING) {
    $prior = PaymentRequest::receiptState($wallet, $secrets);
    if ($prior['state'] === 'paid') {
        throw new Exception('This token has already been used');
    }
    if ($prior['state'] === 'pending') {
        throw new Exception('This token is already being used by another payment');
    }
}

$claim = PaymentRequest::claim($paymentRequest, $secrets);

`receive.php` — $state = PaymentRequest::receiptState($wallet, $secrets);
if ($state['state'] === 'paid') {
    $gross = (int)($state['gross'] ?? $state['amount']);
    if ($gross < (int)$paymentRequest['amount']) {
        // These proofs were spent by another payment, not by this request: the
        // request stays payable and the token is refused.
        PaymentRequest::release($paymentRequest, $secrets);
        http_response_code(409);
        echo json_encode(['error' => 'This token has already been used']);
        exit;
    }
    $response = ['success' => true, 'id' => $paymentRequest['id'], 'amount' => $state['amount'], 'unit' => $unit];
    PaymentRequest::markPaid($paymentRequest, $state['amount'], $response, $gross);
    echo json_encode($response);
    exit;
}

`receive.php` — // In the catch block: a 'paid' state that does not cover the requested amount
// did not pay this request, so the claim is released and the request stays payable.
if ($state['state'] === 'released'
    || ($state['state'] === 'paid'
        && (int)($state['gross'] ?? $state['amount']) < (int)$paymentRequest['amount'])) {
    PaymentRequest::release($paymentRequest, $secrets);
}

`receive.php` — PaymentRequest::markPaid($paymentRequest, $amount, $response, $parsed->getAmount());

**Supporting evidence**

- `includes/payment_request.php:161` — if (count($spent) === count($secrets)) { return ['state' => 'paid', ...] } - the 'paid' answer is derived from cashu_proofs.state, with no request id, no swap journal and no amount comparison.
- `includes/payment_request.php:154` — SELECT secret, state FROM cashu_proofs WHERE wallet_id = ? AND secret IN (...) - the lookup is scoped to the wallet, not to the request, which is why the answer is per store and not per request.
- `includes/payment_request.php:82` — The WHERE clause of markPaid() is 'id = ? AND status IN (?, ?)': the amount the request asked for is not part of the condition, so any amount can be recorded as the payment for it.
- `receive.php:290` — The 'same' branch calls receiptState() and, on 'paid', calls markPaid() and answers success before the amount check at line 319 is ever reached.
- `receive.php:362` — In the catch block a 'paid' state neither releases the claim nor changes the answer, so the request stays in status 'receiving' and is completed later by the background task.
- `receive.php:325` — The POST path deliberately accepts a sender that did not add the mint's input fee ('accepted', $net < $requested), so the amount actually credited can be below the requested amount - any fix that compares the credited (net) amount with the requested amount would break this accepted case and leave the request unpaid.
- `cashu-wallet-php/CashuWallet.php:8064` — Wallet::receive() refuses an already-spent token (NUT-07 check at :8104-8107 throws 'Token already spent.'), which is why the second post of the attacker's token never reaches the mint; finalizePendingSpend() (at :5259, updateProofsState at :5289) records the input proofs of a successful swap as SPENT - which is why a token the merchant received earlier is later seen as SPENT by receiptState().
- `receive.php:184` — A request that is already paid replays its stored result without looking at the token, so a request marked paid by the defect also swallows the next honest customer's payment.

---

### 4.13 Published release artifact is built from the maintainer's working tree, not from the tagged commit, and the published checksum attests exactly those unbound bytes

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `release/artifact-not-bound-to-tagged-commit` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `RELEASE.md:41` | The release procedure's build step runs `scripts/build-standalone.sh` from the maintainer's checkout as step 3 of the runbook (RELEASE.md:38-52). Step 4 tags and publishes afterwards (RELEASE.md:54-71), so the bytes are produced before any tag exists, and no step asks for a clean checkout, a submodule update, or a check of any kind. |
| 2 | `propagation` | `scripts/build-standalone.sh:58` | `cp -r includes/ "$BUILD_DIR/includes/"` copies the application sources out of the current working directory. The same plain `cp` pattern repeats for assets/ (line 61), api-keys/ (63), the entry points (64), LICENSE (66), .htaccess/manifest.json/favicon.ico (67) and images/ (68). The only git call in the whole script is `git log -1 --format=%ct` on line 33, which supplies the zip timestamp: the index, HEAD's tree, a tag and `git archive` are never consulted. |
| 3 | `propagation` | `scripts/build-standalone.sh:72` | `cp cashu-wallet-php/CashuWallet.php "$BUILD_DIR/cashu-wallet-php/"` copies the ecash protocol library out of the submodule's own working tree. The submodule is a separate repository (jooray/cashu-wallet-php) whose commit is recorded only as a gitlink pointer in the release commit; the build never runs `git submodule update` and never compares the checkout with the pointer, so the shipped library can be a commit the release does not name. |
| 4 | `propagation` | `scripts/build-standalone.sh:44` | The zip's SHA-256 is computed over exactly the bytes collected above and appended to build/SHA256SUMS (lines 42-53) as `<digest>  <zipname>`. The file records a bare filename and a digest; it names no commit and no tag, so it can only ever attest 'these bytes', never 'these bytes from the audited commit'. Because the timestamp comes from HEAD's commit time (line 33), a substituted artifact is not distinguishable from a genuine build of HEAD by its metadata either. |
| 5 | `sink` | `RELEASE.md:65` | `gh release create vX.Y.Z build/cashupayserver.zip build/cashupay-wordpress.zip build/SHA256SUMS` publishes the unbound bytes, and the digest that covers them, as the assets of the tag; the operator is then told to unpack those files over the installation (RELEASE.md:95-97), so the substituted code becomes the payment server that custodies the shop's ecash, seeds, API keys and webhook secrets. |

**Root cause.** The release build treats the build machine's working tree as the source of the release artifact instead of the tagged commit: file copies come from the current directory, the submodule is taken from its own working tree rather than the pointer the release commit records, the only git call in the script derives a timestamp from HEAD, and the SHA-256 that is published with the artifact is computed over the resulting bytes and names no commit or no tag. No step of the release procedure compares the built bytes with the commit that is about to be tagged, and no step refuses to publish when the two differ.

**Intended behaviour.** A release asset should be a deterministic function of the tagged commit (and of the submodule commit that tag pins), so that auditing tag vX.Y.Z audits exactly the bytes an operator installs, and the digest published with the artifact should state what it is a digest of so that a substitution is detectable. The procedure should fail closed rather than warn when the checkout is not the state the tag names.

**Lower-trust principal and starting capability.** The operator downloads the release asset and unpacks it over the installation exactly as instructed (RELEASE.md:95-97), so whatever the asset contains becomes the payment server; the operator has no other way to learn what the bytes are.

**Conditions.**

- *(system_configuration)* The release is built from a checkout whose working tree is not identical to the commit that is about to be tagged: an uncommitted edit, a file left over from earlier work, or a build machine where the tree moved after the last commit. The release procedure neither forbids nor detects this - no clean-tree check exists in either build script.
- *(third_party_dependency)* The cashu-wallet-php submodule's working tree can sit at a commit other than the one the release commit pins, so the shipped ecash protocol library is not the one a reader of the tag sees. The submodule lives in a different repository, so its state is outside the release commit's tree. A third-party npm dependency whose install script runs during `npm ci` on the build machine is an additional way for a foreign file to land in the build tree.
- *(user_interaction)* The operator downloads the release asset and unpacks it over the installation exactly as instructed (RELEASE.md:95-97), so whatever the asset contains becomes the payment server; the operator has no other way to learn what the bytes are.

**Bounded, target-native reproduction**

```text
attacker perspective: Someone who can write into the maintainer's build checkout - a leftover local edit, an un-pushed submodule commit, a compromised tool, or a malicious npm dependency running its install script during `npm ci` on the build machine - but who cannot push to the GitHub repository and cannot publish a release. No attacker is required for the substitution to exist at all: an ordinary uncommitted edit is published silently.

payload: fixture tree: a git superproject mirroring the release layout (includes/, assets/, api-keys/, images/, the entry points, LICENSE, .htaccess, manifest.json, favicon.ico) with a real local submodule holding a dummy CashuWallet.php and bip39-english.txt; all content is a marker string
payload: state change 1: includes/config.php edited but not committed (marker DIRTY-WORKING-TREE-EDIT-NOT-IN-ANY-COMMIT)
payload: state change 2: the submodule checkout moved to a second commit while the superproject's recorded gitlink stays at the first (marker CASHU-WALLET-LIB-COMMIT-B-NOT-PINNED)
payload: command: sbx.sh v16-release -- bash check-release-binding.sh, which runs the repository's unmodified scripts/build-standalone.sh (sha256 66dd4009d189e5f3862f8d79ae4e8b4d6e3b7baf767939cdd5da29f7aa0a01e0, byte-identical in the repository and in the fixture) against the fixture; dummy data only, no network

steps:
  - Create the throwaway superproject and submodule in the agent scratch directory, commit clean copies of one source file and of the submodule's library file, and record the gitlink the release commit carries.
  - Run the repository's unmodified scripts/build-standalone.sh in the fixture; record build/SHA256SUMS and the content of build/cashupayserver.zip.
  - Edit one source file without committing it and move the submodule checkout to a second commit, leaving the recorded pointer unchanged; `git status --porcelain` and `git submodule status` show the difference (this is the check the release procedure never runs).
  - Run the same script again and compare the zip's content with `git show HEAD:includes/config.php` and with the recorded submodule pointer.
  - Run the operator's documented verification from RELEASE.md:51-52, `cd build && shasum -a 256 -c SHA256SUMS`, and grep build/SHA256SUMS for the HEAD commit id and for 'commit'/'tag'.

observed:
  Build #1 from the clean tree produced a zip whose contents matched HEAD and the pinned submodule commit, and `shasum -a 256 -c SHA256SUMS` printed 'cashupayserver.zip: OK'. After the tree moved, `git status --porcelain` showed ' M includes/config.php' and ' M cashu-wallet-php', `git submodule status` showed '+<commit B>' while the recorded gitlink was still commit A, and `git show HEAD:includes/config.php` still returned the clean content. Build #2 produced a zip containing 'DIRTY-WORKING-TREE-EDIT-NOT-IN-ANY-COMMIT' and 'CASHU-WALLET-LIB-COMMIT-B-NOT-PINNED'; the operator's documented check still printed 'cashupayserver.zip: OK'; and build/SHA256SUMS was one line, '<64 hex>  cashupayserver.zip', naming no commit and no tag. The zip's timestamps were identical in both builds, because they come from HEAD's commit time. Full transcript: agents/v16-release/artifacts/check-release-binding.txt.
```

**Impact.** The substituted bytes are published under a trusted tag, carry a valid checksum from the project's own release, and are unpacked by the operator over the installation that custodies the shop's ecash proofs, wallet seeds, API keys and webhook secrets - the same trust the operator places in the reviewed commit.

**Likelihood.** No attacker is required for the substitution to exist: the build is a local step on the maintainer's machine and the procedure contains no check that the tree matches the commit, so an ordinary uncommitted edit, a moved submodule checkout or a leftover file is published silently. Exposure of the build machine to any third-party tool - and the `npm ci` step the build itself runs - is what turns it into an attack.

**Why this priority.** Complete source trace from the documented release procedure through both build scripts to the publication command, every cited line verified in the source at commit 4a82d01, plus an independent bounded local reproduction with the repository's unmodified build script against dummy data, showing the substituted content in the artifact, a green checksum check on it, and a checksum file that names no commit and no tag. The only premise is that a release may be built from a checkout that differs from the tag, which the procedure neither checks nor forbids.

**Smallest source fix.** Make the release artifact a function of the tagged commit: refuse to build unless the checkout is exactly the state the tag names (including the submodule commit), and publish a digest that states what it is a digest of, so an auditor can tell the published bytes from the audited commit. Keep build/SHA256SUMS in strict `sha256sum -c` format so the operator's documented check keeps working, and put the commit binding in a separate file. The same guard belongs in scripts/build-wordpress-plugin.sh.

`scripts/build-standalone.sh` — # Refuse to ship bytes that are not the commit the release names.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Build error: the working tree has uncommitted changes." >&2
    echo "         Build from a clean checkout of the release commit (git checkout --detach vX.Y.Z)." >&2
    exit 1
fi
if git submodule status --recursive | grep -qv '^[ ]'; then
    echo "Build error: a submodule is not at the recorded commit." >&2
    echo "         Run: git submodule update --init --recursive" >&2
    exit 1
fi
if [ -n "${CASHUPAY_RELEASE_TAG:-}" ]; then
    tagged="$(git rev-parse "${CASHUPAY_RELEASE_TAG}^{commit}")"
    if [ "$tagged" != "$(git rev-parse HEAD)" ]; then
        echo "Build error: HEAD is not ${CASHUPAY_RELEASE_TAG}." >&2
        exit 1
    fi
fi

`scripts/build-standalone.sh` —     touch build/SHA256SUMS
    grep -v "  $zipname\$" build/SHA256SUMS > build/SHA256SUMS.tmp || true
    echo "$sum" >> build/SHA256SUMS.tmp
    LC_ALL=C sort -k2 build/SHA256SUMS.tmp > build/SHA256SUMS
    rm -f build/SHA256SUMS.tmp
    # Say what the digest is a digest of, beside it, without breaking `sha256sum -c`.
    echo "$sum  commit=$(git rev-parse HEAD)  tag=${CASHUPAY_RELEASE_TAG:-none}" >> build/SHA256SUMS.commit

`RELEASE.md` — ## 3. Build both artifacts

Tag first, then build from the tagged commit in a clean checkout, so the bytes are a
function of the tag and the digest can name it:

```bash
git tag vX.Y.Z
git checkout --detach vX.Y.Z
git submodule update --init --recursive
git status --porcelain    # must print nothing
export CASHUPAY_RELEASE_TAG=vX.Y.Z
scripts/build-standalone.sh
scripts/build-wordpress-plugin.sh
```

Publish build/SHA256SUMS together with build/SHA256SUMS.commit, which names the commit
and the tag each digest belongs to.

**Supporting evidence**

- `scripts/build-standalone.sh:33` — The only use of git in the entire script is `git log -1 --format=%ct`, to derive the zip timestamp from HEAD's commit time (not from the tag). Grepping scripts/, RELEASE.md and .github/ for `git status`, `git diff`, `git rev-parse`, `git describe`, `git archive`, `git stash` and `git submodule` returns nothing: the release path contains no clean-tree check, no submodule check and no tree-extraction step.
- `scripts/build-standalone.sh:42` — The digest block (lines 42-53) computes the checksum and rewrites build/SHA256SUMS. A produced file contains exactly one line, `<64 hex><two spaces>cashupayserver.zip`; grepping it for the HEAD commit id, and for 'commit', 'tag', 'sha256:' or 'git', returns nothing.
- `scripts/build-standalone.sh:19` — The build itself runs `npm ci` and `npm run build` in mint-discovery/ (lines 17-27), so third-party install scripts execute on the build machine during a release build. The only comparison the script makes is between the rebuilt mint-discovery bundle and the committed one (lines 22-25), and a difference there is only a warning; every other file in the build tree is never compared with the committed tree at all.
- `RELEASE.md:14` — The runbook states the invariant it never enforces: 'so two builds of the same commit give the same bytes'. That is only true for a checkout that is exactly the commit; the procedure never checks it, and no build step mentions a commit.
- `RELEASE.md:51` — The only integrity artefact offered to a downloader is 'the checksums from build/SHA256SUMS' - a file that RELEASE.md:68 publishes as an asset of the same release as the zips it covers, inside the trust domain it is meant to vouch for. The documented operator check is `shasum -a 256 -c SHA256SUMS` (RELEASE.md:52).
- `.github/workflows/tests.yml:15` — The only workflow checks out with `submodules: true` (line 18), i.e. the pinned submodule commit, and runs the test suite on that checked-out tree. The release is not built from that tree, so 'tests are green' is not evidence about the published bytes; the workflow also contains no release, artifact or attestation job and no `permissions:` block.
- `scripts/build-wordpress-plugin.sh:64` — The WordPress plugin build copies the tree the same way (includes/ at line 64, entry points at 67, api-keys/ at 68, assets/ at 71, images/ at 77, the submodule library at 81-82) and appends to the same build/SHA256SUMS accumulator, so the second published artifact has the same unbound provenance. Its only git call is the same `git log -1 --format=%ct` on line 33.

---

### 4.14 The setup wizard's add_store re-entry lets any admin session reset the admin password with no current password and without retiring any session

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `setup.php:add_store-step2-setAdminPassword-bypasses-changeAdminPassword` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `setup.php:33` | $mode is read from the query string or the body, so mode=add_store needs no form. |
| 2 | `propagation` | `setup.php:39` | $setupComplete = Database::isInitialized() && Config::isSetupComplete(). Lines 40-43 redirect an anonymous request to the dashboard; the session gate is Auth::isLoggedIn(), i.e. a standalone admin session cookie (includes/auth.php:58-74) or manage_options in WordPress mode. |
| 3 | `propagation` | `setup.php:44` | The second gate redirects every request whose mode is not 'add_store' (and whose POST step is not '7'), so a logged-in admin passes for any step in that mode. $setupTokenRequired is false on a completed install (line 59), so the ownership gate does not apply. |
| 4 | `propagation` | `setup.php:100` | The step allowlist [1,2,4,5,6,7] is the same in both modes, so step=2 (the password step) is accepted in add_store mode; $stepKey (line 90) keeps the wizard's position in the session under 'setup_step_add_store'. |
| 5 | `propagation` | `setup.php:217` | Only two checks: strlen >= 8 (221) and password === confirm (224). No current-password check, no rate limit, no login-attempt reservation. |
| 6 | `propagation` | `setup.php:228` | Auth::setAdminPassword($password) overwrites admin_password_hash (includes/auth.php:153-156) without touching admin_password_version. |
| 7 | `propagation` | `admin.php:846` | Auth::verifyAdminPassword() compares against the hash that was just rewritten, so the password the session holder chose passes the gate that was meant to stop an unattended dashboard from taking the wallet keys. |
| 8 | `sink` | `admin.php:879` | The same gate protects the full database snapshot (VACUUM INTO of the whole SQLite file, admin.php:886-899). |

**Root cause.** setup.php treats mode=add_store as re-entry into the whole first-run wizard: the step allowlist [1,2,4,5,6,7] (setup.php:100) does not depend on the mode, and the completed-install gate (setup.php:44) is skipped for that mode. Case 2 (setup.php:217-231) calls Auth::setAdminPassword(), a raw setter, instead of the guarded Auth::changeAdminPassword() (includes/auth.php:167-191), so a finished installation keeps a second password-change path with no re-authentication and no session-generation rotation.

**Intended behaviour.** After setup is complete the admin password changes only through Auth::changeAdminPassword: the current password is verified, the attempt is rate limited and counted against the login lockout, and admin_password_version is bumped so that every other session ends. add_store mode accepts only the store-creation steps (4-7), and the setup password step never overwrites an existing admin_password_hash.

**Lower-trust principal and starting capability.** The attacker controls a currently valid standalone admin session cookie (a stolen or copied cookie, an unattended logged-in browser, or a script running in the admin origin) but does not know the admin password. The session's own CSRF token is handed out by any GET of setup.php?mode=add_store.

**Conditions.**

- *(authentication_level)* The attacker controls a currently valid standalone admin session cookie (a stolen or copied cookie, an unattended logged-in browser, or a script running in the admin origin) but does not know the admin password. The session's own CSRF token is handed out by any GET of setup.php?mode=add_store.
- *(system_configuration)* Standalone mode (not WordPress), with setup completed (Config::isSetupComplete() true). In WordPress mode the same case 2 exists behind manage_options, but the impact differs.

**Bounded, target-native reproduction**

```text
attacker perspective: A holder of an admin session cookie who does not know the admin password

payload: GET setup.php?mode=add_store&step=2 (read name="csrf_token" value from the password form)
payload: POST setup.php?mode=add_store  step=2&mode=add_store&password=<chosen>&confirm_password=<chosen>&csrf_token=<token>
payload: POST admin.php  action=reveal_seed&store_id=<store>&password=<chosen>&csrf_token=<admin token>
payload: POST admin.php  action=download_backup&password=<chosen>&csrf_token=<admin token>

steps:
  - Ran tools/sbx.sh v23-w5a --loopback -- bash work/run_c1b.sh inside the sandbox: the commit 4a82d01 tree was extracted with 'git archive' into the agent scratch, a throwaway CASHUPAY_DATA_DIR was seeded (setup_complete=true, admin_password_hash = hash('operator-pass-1'), admin_password_version 'pv_initialversion000000', url_mode 'direct', one dummy store with a fake seed), and the repository's real setup.php and admin.php were served by php -S on 127.0.0.1 with an auto_prepend that only defines CASHUPAY_DATA_DIR.
  - The operator logged in through admin.php?action=login; the cookie jar was copied to a second jar (the attacker).
  - The attacker checked the guarded paths first (change_password with a wrong current password, reveal_seed and download_backup with a guessed password), then GETs setup.php?mode=add_store&step=2, POSTs step=2 with a new password, and re-checks everything.
  - Control runs: the same POST without a CSRF token and with a foreign session's token; twelve consecutive step-2 POSTs; step 1 in add_store mode on the completed install, with the config table read raw.

observed:
  Anonymous GET setup.php?mode=add_store (&step=2) -> 302 to admin.php, password unchanged. Operator session -> 200 with the Admin Password form. change_password with a wrong current password -> 400 'The current password is not correct.'; reveal_seed and download_backup with a guessed password -> 403 'Incorrect password'. Step-2 POST without a CSRF token -> 403, hash still verified 'operator-pass-1'; with a foreign session's token -> 403, hash still verified 'operator-pass-1'. Step-2 POST with the session's own token -> 200 and the page moved on to 'Create Store'; the stored hash then verified 'attacker-pass-1' and not 'operator-pass-1', while admin_password_version stayed 'pv_initialversion000000'. Fresh login with the old password -> 401 'Invalid password', with the new one -> 200. From the copied session: reveal_seed -> 200 {"seedPhrase":"DUMMY-SEED-NOT-REAL abandon ... about"}, download_backup -> 200 with 221184 bytes and the 'SQLite format 3' magic. The operator's own session still returned 200 on ?api=stores. Twelve consecutive step-2 POSTs -> twelve 200s and no row in rate_limits for that path (only reveal_seed/backup/change_password/login_attempts rows existed). After the reset the operator's change_password with the old password -> 400. Separately (work/run_c1_step1.sh), a step-1 POST in add_store mode on the completed install rewrote url_mode ('direct' -> 'router') and, with base_url empty, pinned base_url to http://attacker.example:8080 without any cross-host confirmation, whereas admin.php's save_base_url for a foreign host answers 409.
```

**Impact.** A session-only foothold becomes a persistent account takeover: the operator's password stops working for new logins, and the fresh-password gates that protect the wallet seed (admin.php:846) and the full database (admin.php:879) are satisfied by the password the session holder chose - the seeds are the money. The same unclamped step set also rewrites the pinned server address (url_mode always, base_url when empty).

**Likelihood.** The attacker needs an existing valid admin session cookie, which is the threat the project explicitly designs against (an unattended dashboard, a leaked cookie) but which is not anonymous and not cross-site: the CSRF token is per session and the cookie is SameSite=Lax. Verified by control: the same POST without a token, or with another session's token, was answered 403 and left the password untouched.

**Why this priority.** Complete source trace of setup.php:33-47, 90-102, 185-231, includes/auth.php:58-74, 131-139, 153-218 and admin.php:803-826, 833-904, every line re-read at commit 4a82d01, plus an independent end-to-end reproduction against the real setup.php and admin.php with dummy data on loopback inside the sandbox, including the negative controls (no token, foreign token, anonymous) and the rate-limit check.

**Smallest source fix.** Once setup is complete - and always in add_store mode - clamp the wizard to the store-creation steps (4-7), so the password step and the first-run security step cannot run. As defence in depth, make setup case 2 refuse to overwrite an existing admin_password_hash, so that any later password change has to go through Auth::changeAdminPassword (current password, rate limit, version bump). Regression test: a logged-in admin session GETs and POSTs setup.php?mode=add_store with step=2 and with step=1; the stored hash, admin_password_version, url_mode and base_url must be unchanged and the password form must not be rendered.

`setup.php` — // After the step is chosen (setup.php:100): on a finished installation, or in
// add_store mode, only the store-creation steps exist.
if (!in_array($step, [1, 2, 4, 5, 6, 7], true)
    || (($mode === 'add_store' || $setupComplete) && $step < 4)) {
    $step = $mode === 'add_store' ? 4 : 1;
}

            case 2: // Password setup
                if (Config::getAdminPasswordHash() !== null) {
                    throw new Exception('The admin password is already set. Change it from the dashboard settings.');
                }
                $password = setupField('password');

**Supporting evidence**

- `includes/auth.php:167` — The intended path changeAdminPassword verifies the current password (174-176) and bumps password_version (184); setup.php bypasses both.
- `includes/auth.php:69` — Session retirement relies on admin_password_version, which setAdminPassword never changes, so every existing session survives the reset (and the attacker's own session with it).
- `admin.php:810` — The guarded password change is rate limited and counted against the login lockout; the setup path has neither.
- `admin.php:803` — The guarded password change is the only other caller of Auth::setAdminPassword in the tree (includes/auth.php:183); grep over the whole tree finds only setup.php:228, auth.php:183 and two test files.
- `admin.php:4622` — The dashboard's 'Create store' button opens setup.php?mode=add_store, so the mode is a normal, documented flow and the step-2 page is one request away from it.
- `setup.php:129` — Every POST validates the per-session CSRF token (62, 130-133) and the session cookie is SameSite=Lax (includes/auth.php:39), so this path is not cross-site exploitable: the attacker must hold the session cookie itself.
- `setup.php:200` — The unclamped step set also exposes the first-run step: url_mode is rewritten from the posted detected_url_mode without any confirmation, and base_url is pinned to the posted detected_base_url when it is still empty - while admin.php's save_base_url (admin.php:936-962) answers 409 for the same foreign host.

---

### 4.15 Re-configuring the WooCommerce gateway leaves the previous CashuPay API key valid for the store

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `wordpress.btcpay-integration.superseded-woocommerce-api-key-not-revoked` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `setup.php:1829` | A POST carrying 'configure_woocommerce' reaches the WooCommerce block of the wizard. The block only acts when $error === null, i.e. when the setup CSRF token validated (setup.php:62, setup.php:129-140), and the route already required current_user_can('manage_options'). |
| 2 | `propagation` | `setup.php:1836` | Auth::createApiKey($storeId, 'WooCommerce (auto-configured)', [cancreateinvoice, canviewinvoices, canmodifyinvoices, canviewstoresettings, webhooks.canmodifywebhooks], 'woocommerce') mints a new key on every POST. |
| 3 | `propagation` | `includes/auth.php:390` | createApiKey() inserts a row holding only hash('sha256', $rawKey); the raw key is returned to the caller once and is never stored server-side, so the row can only be revoked by deleting it - and nothing in the auto-configuration path does. |
| 4 | `propagation` | `wordpress/btcpay-integration.php:46` | update_option('btcpay_gf_api_key', $api_key) replaces the live copy of the credential in the options table. The api_keys row of the key it replaced is left in place and valid, and the previous key's only cleartext copy on the site is gone, so the merchant cannot even see which one it was. |
| 5 | `propagation` | `includes/auth.php:322` | Auth::validateApiKey() resolves the superseded key to the store and Auth::hasPermission() still grants every permission it was minted with (canmodifyinvoices implies canmanageinvoicestatus via the PERMISSION_IMPLIES map at includes/auth.php:308-317), so requirePermission() lets requests carrying it through exactly as before. |
| 6 | `sink` | `includes/api/webhooks.php:129` | A request carrying the superseded key is authorised by requirePermission('btcpay.store.webhooks.canmodifywebhooks') and mutates the store. Locally observed: the call returned HTTP 200 and set the shop's own payment-notification webhook (url site_url('/?wc-api=btcpaygf_default')) to enabled=0, which stops the shop hearing about settled invoices. The same key can also invalidate a New or Processing invoice (includes/api/invoices.php:131-167) and create invoices. |

**Root cause.** cashupay_configure_btcpay_plugin() treats the gateway credential as a value to overwrite (update_option('btcpay_gf_api_key', $apiKey)) rather than as a credential to rotate: it never looks up the api_keys row of the key it replaces, so every configuration adds one more live store credential and revokes none, while the identical label leaves the merchant no way to identify the stale one.

**Intended behaviour.** Re-configuring the WooCommerce gateway replaces the store credential: the key minted by the previous configuration is revoked, exactly one 'WooCommerce (auto-configured)' key per store is live, and the dashboard can tell it apart from the dead ones. The pairing flow already implements that rule (api-keys/authorize.php:196-211: 'If same app is re-pairing, delete old key first').

**Lower-trust principal and starting capability.** The party holds a copy of an earlier 'WooCommerce (auto-configured)' key for the same store - the credential the shop's gateway used before the last configuration. The merchant configures the gateway more than once - during setup the Configure form is rendered again on every rendering of the wizard's WooCommerce section (setup.php:1900-1910), and a later POST with step=7 is accepted as well (setup.php:44-46) - so one key is superseded but not revoked.

**Conditions.**

- *(user_interaction)* The merchant configures the gateway more than once - during setup the Configure form is rendered again on every rendering of the wizard's WooCommerce section (setup.php:1900-1910), and a later POST with step=7 is accepted as well (setup.php:44-46) - so one key is superseded but not revoked.
- *(authentication_level)* The party holds a copy of an earlier 'WooCommerce (auto-configured)' key for the same store - the credential the shop's gateway used before the last configuration.
- *(data_state)* The cleartext copy of the superseded key existed in the WordPress option 'btcpay_gf_api_key' until the overwrite, while the CashuPay database keeps only its sha256 digest; the server therefore cannot re-display or revoke that key by itself, and the dashboard shows only an indistinguishable label.
- *(third_party_dependency)* Anything able to read the WordPress database keeps that copy after the overwrite: a lower-privileged plugin or theme, a SQL injection in any plugin on the site, or a database backup. CashuPay's data-protection measures (data dir isolation, exposure notices) do not cover the WordPress options table.

**Bounded, target-native reproduction**

```text
attacker perspective: A party that obtained an earlier copy of the WooCommerce gateway's CashuPay API key - for example any code with read access to the WordPress options table before the merchant re-configured the shop - replays that key against the CashuPay Greenfield API after the merchant believes the credential was replaced.

payload: Authorization: token <the key minted by the first POST of the Configure WooCommerce form>
payload: PUT /api/v1/stores/store_dummy_shop_1/webhooks/wh_<id> with body {"enabled": false}

steps:
  - In a scratch copy of the repository, define CASHUPAY_DATA_DIR to a scratch directory and stub the WordPress functions the plugin uses (ABSPATH, get_option, update_option, delete_option, site_url).
  - Initialise the schema, insert one dummy store, and perform the two steps setup.php performs for one POST of the form: Auth::createApiKey($storeId, 'WooCommerce (auto-configured)', [cancreateinvoice, canviewinvoices, canmodifyinvoices, canviewstoresettings, webhooks.canmodifywebhooks], 'woocommerce') followed by cashupay_configure_btcpay_plugin($storeId, $key).
  - Repeat the pair once more, so the first key is superseded by the second.
  - Call Auth::validateApiKey() with the first key and Auth::hasPermission() for each permission, then call handleUpdateWebhook() (with the api.php response helpers replaced by throwing stand-ins, as in tests/api_test_harness.php) using the first key's auth data, and read the webhooks row back.

observed:
  Auth::validateApiKey(first key) returned the store (store_id store_dummy_shop_1) after the second configuration; hasPermission answered ALLOWED for cancreateinvoice, canviewinvoices, canmodifyinvoices, canmanageinvoicestatus, canviewstoresettings and webhooks.canmodifywebhooks for both the superseded and the live key; handleUpdateWebhook with the superseded key returned HTTP 200 and the shop's webhook row changed from enabled=1 to enabled=0; Auth::getApiKeys() returned two rows with the identical label 'WooCommerce (auto-configured)' and identical permission sets.
```

**Impact.** The stale credential keeps store authority over the shop's payment flow: locally it disabled the shop's payment-notification webhook and it can invalidate a New or Processing invoice or create invoices. It cannot mark an invoice paid, cannot delete the store and cannot reach the wallet seed (no withdrawal or transfer endpoint is exposed to API keys), so no funds move directly.

**Likelihood.** The merchant has to configure the gateway more than once (the button is offered on every visit to the wizard's WooCommerce section) and the earlier copy of the key has to reach someone who should not have it; the WordPress options table is a much less protected place than the CashuPay data dir, where every other key is stored as a digest.

**Why this priority.** The whole chain is source-visible in this repository (setup.php, includes/auth.php, wordpress/btcpay-integration.php, includes/api/webhooks.php, admin.php) and the behaviour - a superseded key still authenticating and still authorised a store mutation - was reproduced locally against a dummy store.

**Smallest source fix.** Rotate instead of overwrite: before the new credential is stored, revoke the api_keys row of the key that is currently in the gateway options (found by hashing it), and mint the new key only after the 'a real BTCPay Server is already configured' guard has passed. Because the previous key is then deleted, exactly one 'WooCommerce (auto-configured)' key per store remains in the dashboard's list, so the live key is the one the shop is using and the stale one can no longer be mistaken for it.

`wordpress/btcpay-integration.php` — function cashupay_configure_btcpay_plugin(string $store_id): array {
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'success' => false,
            'error' => 'existing_btcpay',
            'current_url' => get_option('btcpay_gf_url', ''),
            'message' => 'A real BTCPay Server is already configured. '
                       . 'Disconnect it first via WooCommerce > Settings > Payments > BTCPay.'
        ];
    }

    // Revoke the credential the previous configuration minted. Only its digest is
    // stored, so the row is found by hashing the key the options table still holds.
    cashupay_revoke_previous_gateway_key();

    $keyResult = Auth::createApiKey(
        $store_id,
        'WooCommerce (auto-configured)',
        [
            'btcpay.store.cancreateinvoice',
            'btcpay.store.canviewinvoices',
            'btcpay.store.canmodifyinvoices',
            'btcpay.store.canviewstoresettings',
            'btcpay.store.webhooks.canmodifywebhooks',
        ],
        'woocommerce'
    );

    update_option('btcpay_gf_url', site_url('/cashupay'));
    update_option('btcpay_gf_api_key', $keyResult['key']);
    update_option('btcpay_gf_store_id', $store_id);

    $webhookResult = cashupay_register_webhook($store_id);

    return ['success' => true, 'webhook' => $webhookResult];
}

/** Delete the api_keys row of the key currently held in the gateway options. */
function cashupay_revoke_previous_gateway_key(): void {
    $previous = (string) get_option('btcpay_gf_api_key', '');
    if ($previous === '') {
        return;
    }
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';
    $row = Database::fetchOne(
        'SELECT id FROM api_keys WHERE key_hash = ?',
        [hash('sha256', $previous)]
    );
    if ($row === null) {
        return;
    }
    try {
        Auth::deleteApiKey($row['id']);
    } catch (Throwable $e) {
        error_log('CashuPayServer: could not revoke the previous WooCommerce key: ' . $e->getMessage());
    }
}

`setup.php` —                         // The key is minted inside cashupay_configure_btcpay_plugin(),
                        // after the "a real BTCPay Server is already configured" guard:
                        // minting it here left an orphan key row behind every time the
                        // configuration was refused, and left the previous key valid.
                        $configResult = cashupay_configure_btcpay_plugin($storeId);
                        $wooConfigured = $configResult['success'] ?? false;

**Supporting evidence**

- `includes/auth.php:386` — createApiKey() generates the raw key and keeps only hash('sha256', $rawKey); the raw value exists only in the return value.
- `includes/auth.php:390` — The new api_keys row is inserted. Neither createApiKey() nor any caller in the auto-configuration path deletes a previous row for the same store and application identifier; the api_keys schema has no revocation column, so deletion is the only revocation.
- `api-keys/authorize.php:196` — The sibling control for the same situation: the pairing flow deletes the previous key of the same (store, applicationIdentifier, redirectHost) before minting a new one - 'If same app is re-pairing, delete old key first (we can't return existing key since only hash is stored)'. The auto-configuration path omits this step.
- `wordpress/btcpay-integration.php:46` — update_option('btcpay_gf_api_key', $api_key) overwrites the credential in the WordPress options table; nothing revokes the key that was there before.
- `includes/auth.php:308` — PERMISSION_IMPLIES: 'btcpay.store.canmodifyinvoices' implies canviewinvoices, cancreateinvoice and canmanageinvoicestatus, so a superseded key keeps invoice-status authority in addition to the permissions it was minted with.
- `admin.php:653` — The dashboard's key list is Auth::getApiKeys($storeId) (id, store_id, label, permissions, created_at) and the renderer at admin.php:5149-5158 shows only the label, the first eight characters of the id and a Delete button. Two generations of the WooCommerce key are therefore indistinguishable, and deleting the wrong one breaks the shop's gateway.
- `setup.php:1836` — The key is minted before the 'a real BTCPay Server is already configured' guard (wordpress/btcpay-integration.php:35-43) runs, so a refused configuration also leaves an extra key row behind (locally observed: 2 rows -> 3); the raw key of that row is dropped, so the row is an orphan.

---

### 4.16 WordPress sibling installs share one wallet: the second install's administrator reads the first install's seed phrase

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `wordpress/bootstrap.php:CASHUPAY_DATA_DIR:dirname-ABSPATH-shared-across-sites` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `wordpress/rewrite-rules.php:108` | The administrator of site B opens https://siteB.example/cashupay-admin/ (or ?cashupay_admin=1, the query-var route registered at rewrite-rules.php:77 and dispatched on template_redirect at :15, which needs no per-site rewrite rules). The only check is current_user_can('manage_options') at line 109, evaluated against site B, and admin.php is then required at line 112. |
| 2 | `propagation` | `wordpress/bootstrap.php:23` | $above_abspath = dirname(ABSPATH) . '/cashupay-data'. For <parent>/siteA.example/ and <parent>/siteB.example/ the parent directory is the same, so both installs compute the same folder path. |
| 3 | `propagation` | `wordpress/bootstrap.php:24` | is_dir($above_abspath) is true once site A created the folder, so site B adopts site A's directory unconditionally. Nothing checks a marker, an ABSPATH record, the stored base_url or the setup state. |
| 4 | `propagation` | `includes/database.php:43` | CASHUPAY_DATA_DIR takes priority over every other source (line 44 uses the constant verbatim), so site B's Database opens <parent>/cashupay-data/cashupay.sqlite - site A's live wallet with its stores, seed phrases, proofs, API keys and webhooks. |
| 5 | `propagation` | `wordpress/activation.php:147` | Config::set('base_url', site_url('/cashupay')) runs unconditionally after Database::initialize() on the adopted database, so site B rewrites the shared canonical origin to site B's URL. Reproduced: base_url went from 'https://siteA.example/cashupay' to 'https://siteB.example/cashupay'. In WordPress mode the URL helpers still use site_url(), so the confirmed consequence is a corrupted/overwritten shared configuration row (and the silent undoing of a pinned base_url), not repointed payment links. |
| 6 | `propagation` | `includes/auth.php:59` | In WordPress mode being logged in is exactly current_user_can('manage_options') on the current site (admin.php:783 uses it as the gate for every POST action). The wallet the session unlocks, however, is the one the shared directory holds, i.e. site A's. |
| 7 | `propagation` | `includes/auth.php:135` | WordPress setup never stores an admin password (setup.php:214 sends the wizard from step 1 to step 4), so admin_password_hash is null and the fresh-password re-check in front of the seed returns current_user_can('manage_options') as well. Verified: admin_password_hash=NULL and Auth::verifyAdminPassword('')=true in both installs. |
| 8 | `sink` | `admin.php:862` | After passing the re-check at line 846 the handler loads the store by id (line 854) and prints its seed phrase: echo json_encode(['seedPhrase' => $store['seed_phrase']]). The store belongs to the other installation. |

**Root cause.** wordpress/bootstrap.php:22-28 computes CASHUPAY_DATA_DIR from dirname(ABSPATH), which is not unique per installation, and its is_dir() test at line 24 adopts an already existing folder without any ownership or installation check. includes/database.php:40-52 then uses the constant verbatim, so the second install opens the first install's cashupay.sqlite. The authorization side is per-site: includes/auth.php:58-62 returns current_user_can('manage_options') for the current site, and includes/auth.php:131-139 falls back to the same capability for the seed/backup password re-check because setup.php:214 skips the password step in WordPress mode, leaving admin_password_hash NULL. wordpress/activation.php:147 additionally writes Config::set('base_url', site_url('/cashupay')) unconditionally onto the adopted database.

**Intended behaviour.** Each separate WordPress installation must own a separate wallet, and must never silently adopt a folder another installation created. A directory should be bound to the installation that created it (an owner marker containing ABSPATH), activation must not overwrite a base_url that points at a different site, and an administrator of one WordPress site must never reach another site's seed, backup or funds.

**Lower-trust principal and starting capability.** The attacker is an administrator (manage_options) of the second WordPress site and is not otherwise trusted with the first site's data. The gain is clearest where plugin or theme editing is disabled for that administrator (DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT, managed hosting, delegated client sites): with file mods allowed, the same Unix account can usually read the shared file directly, so the CashuPay-specific contribution is that it hands over the other site's wallet through the dashboard, with no filesystem access and no plugin install.

**Conditions.**

- *(system_configuration)* Two or more WordPress installs run the CashuPay plugin, their ABSPATHs share a parent directory, that parent is PHP-writable (or the cashupay-data directory already exists), and CASHUPAY_DATA_DIR is not defined in wp-config.php. The common layout is public_html/<domain>/ per site on cPanel, or two site directories unpacked side by side under one vhost root.
- *(authorization_role)* The attacker is an administrator (manage_options) of the second WordPress site and is not otherwise trusted with the first site's data. The gain is clearest where plugin or theme editing is disabled for that administrator (DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT, managed hosting, delegated client sites): with file mods allowed, the same Unix account can usually read the shared file directly, so the CashuPay-specific contribution is that it hands over the other site's wallet through the dashboard, with no filesystem access and no plugin install.
- *(data_state)* The first site has completed CashuPay setup and holds a store seed and/or ecash in the shared wallet. The second install must have activated the plugin, or at least loaded it, so that its bootstrap adopts the existing folder; the exposure then persists for every later install in that parent directory.

**Bounded, target-native reproduction**

```text
attacker perspective: Administrator (manage_options) of WordPress site B, a different installation from the merchant's site A but on the same hosting account, with no rights over site A.

payload: ABSPATH=<scratch>/hosting/siteA.example/ and then ABSPATH=<scratch>/hosting/siteB.example/ - two installs with the same dirname(ABSPATH), each loading the plugin's own cashupay.php (which requires bootstrap.php and activation.php) and running cashupay_activate_unguarded()
payload: site A store row: id=store_siteA, seed_phrase='DUMMY-SEED-SITEA-not-a-real-mnemonic', mint_url=https://mint.example, mint_unit=sat, created_at=<now>
payload: POST action=reveal_seed&store_id=store_siteA&password=&csrf_token=<session token> with current_user_can('manage_options')=true for site B only and is_super_admin()=false

steps:
  - Run sbx.sh v8-wordpress -- bash <scratch>/run.sh (macOS sandbox, no external network, writes only under the agent scratch directory, 120 s CPU / 180 s wall limit). The harness copies the repository's own files into the scratch tree in the layout scripts/build-wordpress-plugin.sh / scripts/build-standalone.sh produce (wordpress/*.php flattened into the plugin root, includes/, cashu-wallet-php/CashuWallet.php, admin.php, setup.php, api.php, payment.php, receive.php, cron.php, api-keys/), stubs only the WordPress API functions (site_url, get_option, current_user_can, is_super_admin, is_multisite, get_current_blog_id, the hook and rewrite-rule functions) and then runs the repository's unmodified wordpress/cashupay.php, wordpress/bootstrap.php, wordpress/activation.php, wordpress/rewrite-rules.php, includes/database.php, includes/config.php, includes/auth.php and admin.php.
  - Phase 1 activates the plugin for site A, sets setup_complete (as setup.php:422 does) and inserts a dummy store with a dummy seed phrase. Phase 2 does the same for site B, reads the shared wallet's dashboard through the real admin.php GET ?api=dashboard, and Phase 3 posts action=reveal_seed to the real admin.php through the repository's own dispatcher (do_action('template_redirect') -> cashupay_handle_request). Phase 6 is the standalone control in the same parent directory.

observed:
  site A: CASHUPAY_DATA_DIR=<scratch>/hosting/cashupay-data, db_path=<scratch>/hosting/cashupay-data/cashupay.sqlite, base_url='https://siteA.example/cashupay', admin_password_hash=NULL, store seed_phrase='DUMMY-SEED-SITEA-not-a-real-mnemonic'. site B: CASHUPAY_DATA_DIR=<scratch>/hosting/cashupay-data (the same folder), db_path the same file, base_url_before_this_install_activates='https://siteA.example/cashupay' and base_url_after_this_install_activates='https://siteB.example/cashupay', setup_complete=true, admin_password_hash=NULL, Auth::isLoggedIn()=true, Auth::verifyAdminPassword('')=true. The real admin.php GET ?api=dashboard answered to site B's administrator with site A's store ({"stores":[{"id":"store_siteA","name":"Site A shop","mint_url":"https://mint.example",...}]}), which is how the attacker learns the store id. The real admin.php POST action=reveal_seed answered {"seedPhrase":"DUMMY-SEED-SITEA-not-a-real-mnemonic"} - site A's seed phrase, to site B's administrator. Standalone control in the same parent directory: relocateOutsideWebroot() chose cashupay-data-b27391 and did not adopt the pre-existing foreign folder, while the WordPress install in the same parent adopted it. Full output: agents/v8-wordpress/artifacts/check-wp-wallet-isolation-v8.txt.
```

**Impact.** The other installation's seed phrase is bearer control of its ecash; the same session can download the wallet backup, melt funds, export tokens and repoint mints, webhooks and API keys, because only reveal_seed and download_backup carry a second factor and that factor collapses to the same per-site capability.

**Likelihood.** Needs two CashuPay WordPress installs in sibling directories without CASHUPAY_DATA_DIR set, plus an administrator of one site who is not trusted with the other and cannot already read its files through plugin editing. The layouts that collide are real (addon domains under a shared public_html, two site directories side by side in one vhost root) but not every deployment has them; separate docroots per vhost and containers do not collide.

**Why this priority.** Every cited line was re-read at 4a82d01 and the whole chain was reproduced in the OS-enforced sandbox with the repository's own unmodified cashupay.php, bootstrap.php, activation.php, rewrite-rules.php, database.php, config.php, auth.php and admin.php; only WordPress API functions were stubbed. The decisive request went through the real dispatcher and the real reveal_seed handler, and the standalone control was exercised against the same parent directory to show the WordPress path is the outlier.

**Smallest source fix.** Give every WordPress installation a data directory it owns, and never adopt one created by another installation. Derive a per-installation folder name from a hash of ABSPATH; record an owner marker containing ABSPATH in the folder when it is created and check it on every bootstrap; adopt an unmarked legacy folder only when the base_url stored inside it matches this site's site_url('/cashupay'), so existing installs keep their wallet and every other install gets its own path. Activation must not overwrite an existing base_url that points at a different site. The hunter's proposed bootstrap snippet did not implement its own stated strategy (it would silently abandon an existing unmarked legacy wallet on upgrade); the code below implements the one-time legacy adoption it describes.

`wordpress/bootstrap.php` — if (!defined('CASHUPAY_DATA_DIR')) {
    $siteKey = substr(hash('sha256', ABSPATH), 0, 12);
    $parent  = dirname(ABSPATH);
    $legacy  = $parent . '/cashupay-data';
    $own     = $parent . '/cashupay-data-' . $siteKey;
    $remembered = get_option('cashupay_data_dir');
    if (is_string($remembered) && $remembered !== '' && is_dir($remembered)) {
        // This installation already decided where its wallet lives.
        define('CASHUPAY_DATA_DIR', $remembered);
    } elseif (is_dir($legacy) && @file_get_contents($legacy . '/.owner') === ABSPATH) {
        // A folder this installation created.
        define('CASHUPAY_DATA_DIR', $legacy);
    } elseif (is_dir($legacy) && !is_file($legacy . '/.owner')) {
        // A legacy folder from before per-installation folders. Adopt it only if it
        // holds THIS site's wallet, i.e. its stored base_url points at this site.
        define('CASHUPAY_DATA_DIR', cashupay_legacy_folder_is_ours($legacy) ? $legacy : $own);
    } elseif (is_dir($own) || (!is_dir(WP_CONTENT_DIR . '/cashupay-data') && is_writable($parent))) {
        define('CASHUPAY_DATA_DIR', $own);
    } else {
        define('CASHUPAY_DATA_DIR', WP_CONTENT_DIR . '/cashupay-data');
    }
}

/**
 * One-time check for a legacy data folder: does the wallet inside it belong to
 * this site? Reads the stored base_url read-only; the folder is adopted only on
 * a match, so a sibling install never inherits another install's wallet.
 */
function cashupay_legacy_folder_is_ours(string $legacy): bool {
    $db = $legacy . '/cashupay.sqlite';
    if (!is_file($db)) {
        return false;
    }
    try {
        $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->query("SELECT value FROM config WHERE key = 'base_url'");
        $value = $stmt ? $stmt->fetchColumn() : false;
        if (!is_string($value) || $value === '') {
            return false;
        }
        $stored = json_decode($value, true);
        if (!is_string($stored)) {
            $stored = $value;
        }
        return rtrim($stored, '/') === rtrim(site_url('/cashupay'), '/');
    } catch (Throwable $e) {
        return false;
    }
}

`wordpress/activation.php` — // Record which installation owns the wallet folder, so a sibling install never adopts it.
if (is_dir(CASHUPAY_DATA_DIR) && !is_file(CASHUPAY_DATA_DIR . '/.owner')) {
    @file_put_contents(CASHUPAY_DATA_DIR . '/.owner', ABSPATH);
}
update_option('cashupay_data_dir', CASHUPAY_DATA_DIR, false);

// Do not repoint another installation's canonical origin.
$configuredBase = trim((string) Config::get('base_url'));
$ownBase = rtrim(site_url('/cashupay'), '/');
if ($configuredBase === '' || $configuredBase === $ownBase) {
    Config::set('base_url', $ownBase);
}

**Supporting evidence**

- `wordpress/bootstrap.php:24` — An existing <parent>/cashupay-data is adopted unconditionally, with no ownership or installation check: is_dir($above_abspath) short-circuits the whole branch. Verified in the sandbox: a WordPress install whose dirname(ABSPATH) holds a pre-existing foreign cashupay-data (marked with an OWNER.txt belonging to another installation) resolved CASHUPAY_DATA_DIR to exactly that foreign folder.
- `includes/database.php:99` — Standalone control: when <parent>/cashupay-data already exists, Database::relocateOutsideWebroot chooses a random suffix (cashupay-data-<6 hex>) at line 100, so a standalone install never adopts another install's folder. Verified in the sandbox in the same parent directory: standalone chose cashupay-data-b27391 and left the foreign folder untouched, while the WordPress install in the same parent adopted the foreign folder. The WordPress path has no equivalent.
- `includes/auth.php:61` — The WordPress gate is current_user_can('manage_options'), a per-site capability, applied to a wallet that is not per-site.
- `includes/auth.php:136` — verifyAdminPassword falls back to manage_options when no password hash exists, which is the WordPress-mode default (admin_password_hash observed NULL).
- `setup.php:214` — In WordPress mode the wizard jumps from step 1 to step 4, so the standalone admin password (and its hash) is never created. admin.php:803 also refuses to set one in WordPress mode ('change your WordPress password instead').
- `wordpress/activation.php:147` — The second site's activation overwrites the shared base_url with its own site_url('/cashupay'). Observed before/after in the sandbox.
- `scripts/build-wordpress-plugin.sh:60` — The plugin layout copies wordpress/*.php into the plugin root, which is why the shared-data-directory decision is reached on every request of every install that ships the plugin.
- `admin.php:783` — Auth::isLoggedIn() is the only gate for every POST action; the money actions manual_melt (1756), export_token (1987), update_store (1296), save_base_url (936), save_auto_melt (1727), delete_store (1492) and create_api_key (1539) have no second factor at all, and reveal_seed (833) and download_backup (869) carry only the collapsed manage_options re-check.

---

### 4.17 WordPress multisite: every subsite administrator controls the one shared CashuPay wallet

**Severity:** MEDIUM &nbsp;·&nbsp; **Fingerprint:** `wordpress/bootstrap.php:CASHUPAY_DATA_DIR:multisite-no-blog-id-subsite-admin-manage_options` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `wordpress/rewrite-rules.php:108` | A subsite administrator opens https://subsite.example/?cashupay_admin=1 (or /cashupay-admin/ where the pretty rules were flushed). The only check is current_user_can('manage_options') at line 109, which is true for their own blog, and admin.php is required at line 112. The query-var route is registered on every request (rewrite-rules.php:73-83) and dispatched on template_redirect (:15), so it works on any blog where the plugin is loaded, even if that blog's rewrite rules were never flushed. |
| 2 | `propagation` | `wordpress/bootstrap.php:23` | $above_abspath = dirname(ABSPATH) . '/cashupay-data'. On multisite ABSPATH, WP_CONTENT_DIR and the wp-config constant are the same for every blog of the network, and get_current_blog_id() is never consulted (grep: 0 occurrences in the plugin). |
| 3 | `propagation` | `includes/database.php:43` | CASHUPAY_DATA_DIR is used verbatim, so blog 2 opens the same cashupay.sqlite as blog 1, with the same stores, seed phrases, proofs, API keys and webhooks. |
| 4 | `propagation` | `includes/auth.php:61` | Being logged in is current_user_can('manage_options') on the current blog. admin.php:783 uses it as the gate for every POST action, so the subsite administrator passes it. |
| 5 | `propagation` | `includes/auth.php:135` | admin_password_hash is null in WordPress mode (setup.php:214 skips the password step), so the seed and backup re-check also comes down to manage_options. |
| 6 | `sink` | `admin.php:862` | The seed phrase of the network-wide store is printed to the subsite administrator after the re-check at line 846 passes. |

**Root cause.** wordpress/bootstrap.php:22-28 defines CASHUPAY_DATA_DIR with no blog or site component, and nothing in the plugin detects multisite or refuses network activation (the plugin header in wordpress/cashupay.php:2-11 declares no Network field and register_activation_hook at wordpress/cashupay.php:27 registers a callback, cashupay_activate(), that does not accept the $network_wide flag WordPress passes, so it is ignored). includes/database.php:40-52 uses the constant verbatim, so every subsite opens the same cashupay.sqlite. The gate is then a per-blog capability: includes/auth.php:58-61 returns current_user_can('manage_options') on the current blog, and includes/auth.php:131-138 falls back to the same capability for the seed and backup re-check. The project's own prior audit already named this gap (AUDIT.md:1260: refuse network-wide activation on multisite, 'because one CASHUPAY_DATA_DIR would be shared by every site with conflicting base_url').

**Intended behaviour.** An administrator of one subsite must never reach another subsite's or the network owner's seed and funds. Either every subsite gets an isolated wallet namespace, or on multisite the plugin refuses network activation and restricts the CashuPay admin, setup and seed/backup routes to is_super_admin() until per-blog isolation exists.

**Lower-trust principal and starting capability.** The attacker is an administrator (manage_options) of a subsite where CashuPay is loaded, and is not a super admin. Subsite administrators are deliberately lower-trust principals in WordPress: by default they cannot install plugins or use unfiltered_html.

**Conditions.**

- *(system_configuration)* A WordPress multisite network with CashuPay loaded on at least two blogs: network-activated, activated per site by the super admin, or activated by a subsite administrator where the network enables the Plugins menu for site administrators. The plugin must be loaded on the attacker's own blog - that is what network activation gives, and it is what makes the shared wallet reachable without touching the merchant's blog.
- *(authorization_role)* The attacker is an administrator (manage_options) of a subsite where CashuPay is loaded, and is not a super admin. Subsite administrators are deliberately lower-trust principals in WordPress: by default they cannot install plugins or use unfiltered_html.
- *(data_state)* Another site's shop (typically the main site) holds funds or a seed phrase in the shared wallet.

**Bounded, target-native reproduction**

```text
attacker perspective: Administrator of one subsite of a multisite network, with no super-admin rights, where CashuPay is loaded on more than one blog.

payload: is_multisite()=true with get_current_blog_id()=1 for the merchant's blog and =2 for the attacker's blog, one shared ABSPATH and WP_CONTENT_DIR, is_super_admin()=false for the attacker
payload: blog 1 store row: id=store_msnet, seed_phrase='DUMMY-SEED-MSNET-not-a-real-mnemonic', mint_url=https://mint.example, mint_unit=sat, created_at=<now>
payload: POST action=reveal_seed&store_id=store_msnet&password=&csrf_token=<session token> with current_user_can('manage_options')=true for blog 2 only

steps:
  - Run sbx.sh v8-wordpress -- bash <scratch>/run.sh (macOS sandbox, no external network, writes only under the agent scratch directory, 120 s CPU / 180 s wall limit). The harness uses its own hosting root for the network so the result does not depend on the sibling-install scenario.
  - Phase 4 activates the plugin for blog 1 of the simulated network, sets setup_complete (as setup.php:422 does) and inserts a dummy store with a dummy seed phrase; phase 5 is the attacker's blog 2: it loads the plugin (network activation loads it on every blog), then posts action=reveal_seed to the real admin.php through the repository's own dispatcher.
  - Only the WordPress API functions are stubbed (site_url, get_option, current_user_can, is_super_admin, is_multisite, get_current_blog_id, the hook and rewrite-rule functions); the repository's own cashupay.php, bootstrap.php, activation.php, rewrite-rules.php, database.php, config.php, auth.php and admin.php run unmodified. The multisite stubs do not change the plugin's behaviour: is_multisite(), get_current_blog_id() and is_super_admin() are never called by the data-directory or authorization code, so the same code path runs identically for one site and for a network.

observed:
  blog 1: CASHUPAY_DATA_DIR=<scratch>/network/cashupay-data, db_path=<scratch>/network/cashupay-data/cashupay.sqlite, base_url='https://msnet.example/cashupay', admin_password_hash=NULL, store seed_phrase='DUMMY-SEED-MSNET-not-a-real-mnemonic'. blog 2 (the attacker's blog): is_multisite()=true, get_current_blog_id()=2, is_super_admin()=false, current_user_can('manage_options')=true, ABSPATH identical, CASHUPAY_DATA_DIR=<scratch>/network/cashupay-data (identical), db_path the same file, setup_complete=true, admin_password_hash=NULL, Auth::isLoggedIn()=true, Auth::verifyAdminPassword('')=true. The real admin.php POST action=reveal_seed answered {"seedPhrase":"DUMMY-SEED-MSNET-not-a-real-mnemonic"} - the network owner's seed phrase - to the subsite administrator. Full output: agents/v8-wordpress/artifacts/check-wp-wallet-isolation-v8.txt.
```

**Impact.** Full operator control over another site's real funds: seed disclosure, wallet backup download, melting or exporting all proofs, and repointing mints, webhooks and API keys - all reachable behind Auth::isLoggedIn() with the per-blog manage_options capability.

**Likelihood.** Needs a multisite network with CashuPay loaded on more than one blog and a malicious or compromised subsite administrator; multisite shops are a minority, but networks that hand subsite administration to third parties are the reason multisite exists. Network activation by the super admin is the ordinary way the plugin reaches a second blog, so the precondition is not exotic once the network exists.

**Why this priority.** The plugin side is complete in source and the shared-directory and gate behaviour was reproduced locally with the unmodified bootstrap.php, activation.php, rewrite-rules.php, database.php, config.php, auth.php and admin.php; the decisive request went through the repository's own dispatcher and the real reveal_seed handler. The WordPress-core facts are not stubbed assumptions: multisite is one installation with one wp-config.php, one ABSPATH and one wp-content, and a network-activated plugin is loaded on every blog of the network - both documented core behaviour, and the repository's own AUDIT.md:1260 and :1477 already relied on those core facts (the $network_wide activation flag and install_plugins being super-admin only on multisite). Decisively, the multisite stubs cannot change the outcome, because is_multisite(), get_current_blog_id() and is_super_admin() are never called by the data-directory or authorization code: the same source path runs identically for one site and for a network.

**Smallest source fix.** Short term, on multisite restrict the CashuPay admin and setup routes and the seed/backup re-check to is_super_admin(), and refuse network activation, so the network-wide wallet cannot be reached by a per-blog administrator. Long term, give each subsite its own wallet namespace (cashupay-data/site-<blog_id>, with blog 1 keeping the legacy path) and append the same suffix to a wp-config-supplied directory instead of sharing it. The hunter's proposed bootstrap snippet introduced a CASHUPAY_DATA_DIR_SUFFIX constant that includes/database.php never reads, and its define() would have failed on an already-defined constant; the code below applies the suffix where the path is actually consumed.

`wordpress/bootstrap.php` — if (!defined('CASHUPAY_DATA_DIR')) {
    $above_abspath = dirname(ABSPATH) . '/cashupay-data';
    $base = (is_dir($above_abspath) || (!is_dir(WP_CONTENT_DIR . '/cashupay-data') && is_writable(dirname(ABSPATH))))
        ? $above_abspath
        : WP_CONTENT_DIR . '/cashupay-data';
    // On multisite every blog of the network shares one ABSPATH, one WP_CONTENT_DIR
    // and one wp-config.php, so the folder must carry the blog id. Blog 1 keeps the
    // legacy path so an existing wallet is not moved.
    $blog = (function_exists('is_multisite') && is_multisite()) ? (int) get_current_blog_id() : 1;
    define('CASHUPAY_DATA_DIR', $blog > 1 ? $base . '/site-' . $blog : $base);
}

`includes/database.php` — public static function getDataDir(): string {
    if (self::$dataDir === null) {
        if (defined('CASHUPAY_DATA_DIR')) {
            $dir = rtrim(CASHUPAY_DATA_DIR, '/');
            // A wp-config constant is network-wide on multisite, so every blog needs its
            // own wallet namespace. Blog 1 keeps the configured path. The suffix is added
            // only once: wordpress/bootstrap.php already scopes the constant it defines,
            // and a path that already ends in it must not be doubled.
            if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS
                && function_exists('is_multisite') && is_multisite()
                && function_exists('get_current_blog_id') && (int) get_current_blog_id() > 1) {
                $suffix = '/site-' . (int) get_current_blog_id();
                if (!str_ends_with($dir, $suffix)) {
                    $dir .= $suffix;
                }
            }
            self::$dataDir = $dir;
        } elseif (($moved = self::relocatedDataDir()) !== null) {
            self::$dataDir = $moved;
        } else {
            self::$dataDir = __DIR__ . '/../data';
        }
    }
    return self::$dataDir;
}

`includes/auth.php` — public static function isLoggedIn(): bool {
    if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
        // On multisite the wallet is network-wide until per-blog isolation exists,
        // so only a super admin may enter it.
        if (function_exists('is_multisite') && is_multisite() && !is_super_admin()) {
            return false;
        }
        return function_exists('current_user_can')
            && current_user_can('manage_options');
    }

`includes/auth.php` — public static function verifyAdminPassword(string $password): bool {
    $hash = Config::getAdminPasswordHash();
    if ($hash === null) {
        // WordPress mode has no standalone password; manage_options is the gate.
        if (function_exists('is_multisite') && is_multisite() && !is_super_admin()) {
            return false;
        }
        return Urls::isWordPress() && function_exists('current_user_can')
            && current_user_can('manage_options');
    }
    return password_verify($password, $hash);
}

`wordpress/rewrite-rules.php` — function cashupay_can_administer(): bool {
    // On multisite the wallet is network-wide until per-blog isolation exists:
    // a per-blog administrator must not reach the network owner's money.
    if (function_exists('is_multisite') && is_multisite() && !is_super_admin()) {
        return false;
    }
    return current_user_can('manage_options');
}

// used for both the cashupay_admin and the cashupay_setup branch:
//   if (!cashupay_can_administer()) { wp_die('Access denied'); }

`wordpress/activation.php` — function cashupay_activate(bool $network_wide = false): void {
    if ($network_wide && function_exists('is_multisite') && is_multisite()) {
        // One wallet for the whole network is not acceptable: every subsite administrator
        // would control the network owner's money. Activate per site instead, or wait
        // for per-blog wallet namespaces.
        wp_die('CashuPay cannot be network-activated on multisite. Activate it per site.');
    }
    try {
        cashupay_activate_unguarded();
        delete_option('cashupay_activation_error');
    } catch (Throwable $e) {
        cashupay_record_startup_error('activation', $e);
    }
}

**Supporting evidence**

- `wordpress/bootstrap.php:24` — The branch condition uses only dirname(ABSPATH) and WP_CONTENT_DIR, both network-wide on multisite; get_current_blog_id() appears nowhere in the plugin's data-directory decision (grep over wordpress/, includes/, admin.php, setup.php: 0 occurrences). is_multisite() appears only three times, all in wordpress/btcpay-dependency.php for the unrelated WooCommerce dependency notice, and is_super_admin() appears nowhere.
- `includes/auth.php:61` — The WordPress gate is a per-blog capability that every subsite administrator holds, applied to a network-wide wallet.
- `setup.php:214` — The WordPress wizard jumps from step 1 to step 4 and never sets an admin password, so there is no second gate in front of the seed. admin.php:803 refuses to set one in WordPress mode.
- `wordpress/admin-menu.php:256` — The remedy the notice suggests is a wp-config.php define('CASHUPAY_DATA_DIR', ...). On multisite that file is shared by every subsite, so it cannot separate them.
- `wordpress/cashupay.php:27` — register_activation_hook(__FILE__, 'cashupay_activate') registers a zero-argument callback (wordpress/activation.php:124), so the $network_wide flag WordPress passes is ignored, and the plugin header (lines 2-11) declares no 'Network:' field, so nothing refuses network activation or activation on a second subsite.
- `AUDIT.md:1260` — The repository's own prior audit already records the multisite gap and cites WordPress core for it: 'Refuse network-wide activation on multisite (if (is_multisite() && $network_wide) wp_die(...)), because one CASHUPAY_DATA_DIR would be shared by every site with conflicting base_url.' AUDIT.md:1477 records the core capability facts it verified (install_plugins is super-admin only on multisite; DISALLOW_FILE_MODS removes it) and AUDIT.md:1618 lists the WordPress core files it consulted.

---

### 4.18 The admin 'check' action can flip a token export the operator took back into a completed delivery

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `admin.php:check_transfer:transfer-status-overwrite` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:992` | The operator's 'has this token been cashed in?' action is reached through the admin session and CSRF gate and loads the transfer row named in the request body. The only principal the admin API exposes is the authenticated operator of the store: CashuPayServer is single-operator by design, so this is a same-principal integrity defect, not a cross-principal breach. |
| 2 | `propagation` | `admin.php:995` | Transfer::getById($_POST['id']) loads the row with no status predicate (includes/transfer.php:228-230 selects 'WHERE id = ?'), so a row in status 'failed' (written by the operator's own reclaim) is indistinguishable from a pending one. |
| 3 | `propagation` | `admin.php:1011` | Invoice::checkProofStatesBySecrets() asks the mint about the exported proofs and the answer decides the outcome. After the operator took the token back, the reclaim's swap consumed those proofs at the mint (includes/invoice.php:1370-1374), so the mint answers SPENT - the same answer a genuine redemption produces. |
| 4 | `propagation` | `admin.php:1015` | 'All spent' is taken to mean 'the recipient redeemed it' and Transfer::complete($row['id'], (int)$row['amount']) is called on the row, without consulting the row's status; Invoice::markProofsSpent($row['store_id'], $secrets) is called on the same secrets. The handler answers 'This token has been cashed in. Marked as completed.' |
| 5 | `sink` | `includes/transfer.php:177` | The status is written by UPDATE transfers SET ... WHERE id = ? - the write has no 'AND status = pending' predicate, so the terminal 'failed' row the reclaim left behind is overwritten with 'completed'. The transfers.status column carries no CHECK constraint and there are no triggers (includes/database.php:508-524). |

**Root cause.** The transfer status is written without a state predicate. Transfer::update() (includes/transfer.php:171-181) runs UPDATE transfers SET ... WHERE id = ? with no 'AND status = pending' condition, so complete(), fail() and note() can move a row between terminal states. admin.php's 'check_transfer' handler (admin.php:992-1037) loads the row by id with Transfer::getById() (admin.php:995), which selects any status, and calls Transfer::complete($row['id'], (int)$row['amount']) whenever the mint reports every one of the row's secrets as SPENT (admin.php:1011-1015) - and after the operator's own reclaim the mint does report exactly that, because the reclaim's swap consumed those proofs at the mint (includes/invoice.php:1370-1374). The only thing that distinguishes 'the recipient redeemed it' from 'the operator took it back' is the row's own status, and that is the one thing the handler does not look at.

**Intended behaviour.** A transfer row moves from pending to exactly one terminal state (completed or failed) and stays there. 'check_transfer' should report what the mint says for a row that is still pending, and refuse to re-settle a row that has already been completed or failed - telling the operator the current status instead of overwriting it. The reclaim's own 'failed' row must remain 'failed'.

**Lower-trust principal and starting capability.** The caller is the authenticated operator/admin of the same store. CashuPayServer is single-operator by design, so no lower-trust principal is involved: the defect is that the shipped ledger lets a later settle action contradict an earlier terminal state, i.e. an integrity failure in the operator's own financial record rather than an unauthorized third party's action. The settle request has to reach the server after the reclaim. The shipped dashboard renders the 'Check if cashed in' button only for pending rows (admin.php:5518), so the operator's own clicks do not reach the state; the request is one POST away from any client holding the operator's own admin session and CSRF token (the operator's tooling, a future dashboard change, a browser extension), and the cron reconciler provides a racing path against a reclaim that lands in its mint round-trip window. No timing, no concurrency and no unusual configuration are needed for the deterministic path.

**Conditions.**

- *(authorization_role)* The caller is the authenticated operator/admin of the same store. CashuPayServer is single-operator by design, so no lower-trust principal is involved: the defect is that the shipped ledger lets a later settle action contradict an earlier terminal state, i.e. an integrity failure in the operator's own financial record rather than an unauthorized third party's action.
- *(data_state)* The store has a token export (type token_export or donation) whose row already reached a terminal state - in practice 'failed' after the operator used 'reclaim_transfer'. The mint must answer SPENT for the exported proofs, which is what the reclaim's own swap guarantees.
- *(user_interaction)* The settle request has to reach the server after the reclaim. The shipped dashboard renders the 'Check if cashed in' button only for pending rows (admin.php:5518), so the operator's own clicks do not reach the state; the request is one POST away from any client holding the operator's own admin session and CSRF token (the operator's tooling, a future dashboard change, a browser extension), and the cron reconciler provides a racing path against a reclaim that lands in its mint round-trip window. No timing, no concurrency and no unusual configuration are needed for the deterministic path.
- *(system_configuration)* Any install that uses the transfers ledger, which is every install: the ledger is written by every export, donation, withdrawal and reclaim.

**Bounded, target-native reproduction**

```text
attacker perspective: The authenticated operator of the store (the only principal the admin API exposes). The finding is that the shipped ledger accepts a state transition it must refuse: after the operator takes a token back, the next settle of the same row silently rewrites the store's record of outgoing money. No other principal gains anything; the observable effect is a transfers row that reports a completed delivery of money that never left the shop, while the money is still in the balance.

payload: fixture: repository mock mint on 127.0.0.1 (tests/mock_mint_router.php), scratch SQLite database via CASHUPAY_DATA_DIR, one dummy store funded with one 500 sat invoice
payload: state: Transfer::open(store, Transfer::TYPE_TOKEN_EXPORT, 500, 'sat', null, '<proof secrets>', 'Cashu token', '<token>', '<mint url>') plus Invoice::markProofsExported(), then Invoice::reclaimExportedProofs() and Transfer::fail(id, 'Taken back by the operator; the token no longer works') - the row is 'failed', the balance holds 499 sat, the mint reports the exported proofs SPENT
payload: POST /admin.php  action=login&password=<admin password>&csrf_token=<64-hex token from the dashboard meta tag>
payload: POST /admin.php  action=check_transfer&id=<the reclaimed transfer id>&csrf_token=<session token>
payload: control: POST /admin.php  action=check_transfer&id=<the same id>   (no CSRF token)

steps:
  - Run everything inside the provided sandbox: sbx.sh v6-ledger --loopback -- php ledger-overwrite-check.php (the scratch script starts the repository's mock mint and a local PHP built-in server whose router only defines CASHUPAY_DATA_DIR and then includes the unmodified admin.php; the repository is read-only and no network beyond 127.0.0.1 is reachable).
  - Create one dummy store with a seed, fund it with one 500 sat invoice through the mock mint (mint quote -> /__control/pay -> poll -> mint), and hand the balance out as a token (Transfer::open with type token_export plus Invoice::markProofsExported).
  - Take the token back exactly as admin.php's 'reclaim_transfer' does: Invoice::reclaimExportedProofs() and then Transfer::fail(). Record the row status ('failed'), the balance (499 sat) and what the mint now answers for the exported proofs (SPENT).
  - GET /admin.php to obtain the session cookie and the CSRF token from the page's meta tag, then POST action=login.
  - POST action=check_transfer with the transfer id and the CSRF token, then read the transfers row and the balance again.
  - Control: POST the same action without a CSRF token and confirm it is refused and the row is unchanged.
  - Artefact: agents/v6-ledger/artifacts/check-ledger-overwrite.txt (scratch copy: agents/v6-ledger/scratch/check-ledger-overwrite.txt).

observed:
  mock mint: http://127.0.0.1:19371 up=yes; 'funded: balance=500'; 'export: transfer=xfer_39f6c1d9afd44903e5cbeacc amount=500 status=pending proofs=6'; 'after reclaim: recovered=499 balance=499 status=failed detail=Taken back by the operator; the token no longer works'; 'mint says about the exported proofs: SPENT,SPENT,SPENT,SPENT,SPENT,SPENT'; 'sibling settle path Transfer::findPendingBySecrets() on the same row: null (status-guarded: it cannot touch a terminal row)'; 'check_transfer path Transfer::getById() on the same row: status=failed'; 'GET /admin.php -> http 200 csrf-token length=64'; 'POST action=login -> http 200 {"success":true,...}'; 'POST action=check_transfer (no csrf token) -> http 403 {"error":"Invalid CSRF token"}' with 'ledger row after the CSRF-less POST: status=failed'; 'POST action=check_transfer -> http 200 {"status":"completed","message":"This token has been cashed in. Marked as completed."}'; 'FINAL ledger row: status=completed amount=500 detail=Taken back by the operator; the token no longer works'; 'FINAL store balance: 499'; 'VERDICT: a transfer the operator took back is reported as COMPLETED'. A separate root-cause check in the same run showed 'guarded write ("id = ? AND status = pending") on a terminal row: rows_changed=0 status=failed' against 'unguarded write ("id = ?", what Transfer::update() does) on the same row: rows_changed=1 status=completed'. The whole sequence was reproduced identically in three independent sandbox runs.
```

**Impact.** No funds are lost (the reclaim succeeded and the money is in the balance - measured at 499 sat after the mint's swap fee) and no other principal gains access to anything. The harm is to the integrity of the operator's own outgoing-transfer ledger: a completed delivery of the full token amount is recorded for money that never left the shop, while the row's own detail still says the token was taken back, so the store's books show a phantom outflow the balance does not support. The dashboard's Transfers list renders the status label of that row, and no other code path reads the row's status for a money decision (the balance comes from the wallet, the reconciler only picks up pending rows), so nothing else is affected.

**Likelihood.** The shipped dashboard offers the 'check' action only for a row that is still pending (admin.php:5518), so an operator clicking through does not hit it; the deterministic trigger is a direct POST to the same admin session with the operator's own CSRF token (the operator's own tooling, a future dashboard change, or any script holding the session), and the reconciler path additionally needs the reclaim to land inside its mint round-trip window. The request itself is trivial and the state it needs (a reclaimed export) is an ordinary outcome of the reclaim feature. Verified in the sandbox: the CSRF-less control POST was refused with 403 and left the row untouched, so no unauthenticated or cross-site trigger exists.

**Why this priority.** The behaviour was reproduced end to end through the unmodified admin.php POST handler (real session cookie, real CSRF token, real mint round trip against the repository's own mint fixture) inside the sandbox, three times with identical results, and the root cause - the unguarded UPDATE - was isolated separately with and without the status predicate (rows_changed=1 unguarded versus rows_changed=0 guarded on the same terminal row). The inequivalence with the sibling settle path was confirmed empirically (findPendingBySecrets returns null for the terminal row) and in source, and no other layer re-checks the row's status. The source trace and every cited line number were re-verified at commit 4a82d01.

**Smallest source fix.** Make the transfer status a guarded state machine and make the settle actions refuse a row that already reached a terminal state. Two small changes: (a) Transfer::update() writes only while the row is still pending and reports whether it applied, so complete()/fail()/note() can no longer move a row between terminal states; (b) 'check_transfer' reports the row's current status instead of settling a row that is not pending. (a) alone already stops the deterministic path, because the reclaim's 'failed' row can then no longer be overwritten; (b) keeps the operator's screen honest. Note that every existing caller of note()/complete()/fail() acts on a row that is still pending (background_runner.php:598/669/680/682, lightning_address.php:241, admin.php:2169/2178/2285/2294 and the two findPendingBySecrets-based settle paths), so the guard does not change their behaviour.

`includes/transfer.php` —     /** Settle an operation opened with open(). Only a pending row may be settled. */
    public static function complete(
        ?string $id,
        int $amount,
        int $fee = 0,
        ?string $detail = null,
        ?string $token = null
    ): bool {
        return self::update($id, [
            'status' => self::STATUS_COMPLETED,
            'amount' => $amount,
            'fee' => $fee,
        ] + ($detail !== null ? ['detail' => mb_substr($detail, 0, 500)] : [])
          + ($token !== null ? ['token' => $token] : []));
    }

    /** Annotate a pending operation without changing its status. */
    public static function note(?string $id, string $detail): bool {
        return self::update($id, ['detail' => mb_substr($detail, 0, 500)]);
    }

    public static function fail(?string $id, ?string $detail = null): bool {
        return self::update($id, [
            'status' => self::STATUS_FAILED,
        ] + ($detail !== null ? ['detail' => mb_substr($detail, 0, 500)] : []));
    }

    /**
     * Update a row that is still pending. A row that already reached a terminal state is
     * never rewritten: a settled transfer must not be able to become 'completed' again
     * (or 'failed') after the operator took it back.
     *
     * @return bool true when the row was still pending and was updated
     */
    private static function update(?string $id, array $fields): bool {
        if ($id === null) {
            return false;
        }
        try {
            $fields['updated_at'] = Database::timestamp();
            $changed = Database::update(
                'transfers',
                $fields,
                'id = ? AND status = ?',
                [$id, self::STATUS_PENDING]
            );
            return $changed > 0;
        } catch (Throwable $e) {
            error_log('Transfer::update failed: ' . $e->getMessage());
            return false;
        }
    }

`admin.php` —                 // Only a pending transfer can still be settled. A row that was
                // already completed or failed (the operator took the token back) keeps
                // its own state: 'all spent' at the mint then means the reclaim's own
                // swap consumed the proofs, not that a recipient redeemed them.
                if ($row['status'] !== Transfer::STATUS_PENDING) {
                    echo json_encode([
                        'status' => $row['status'],
                        'message' => 'This transfer is already ' . $row['status'] . '.',
                    ]);
                    break;
                }
                if ($spent === count($secrets)) {
                    // 'All spent' is a claim that the recipient redeemed the token, so it must
                    // be proved, not assumed.
                    Transfer::complete($row['id'], (int)$row['amount']);
                    Invoice::markProofsSpent($row['store_id'], $secrets);
                    echo json_encode([
                        'status' => Transfer::STATUS_COMPLETED,
                        'message' => 'This token has been cashed in. Marked as completed.',
                    ]);
                } elseif ($spent === 0) {

**Supporting evidence**

- `includes/transfer.php:177` — Transfer::update() - reached by Transfer::complete() (145-158), Transfer::note() (161-163) and Transfer::fail() (165-169) - runs Database::update('transfers', $fields, 'id = ?', [$id]). The write has no status predicate, so neither complete() nor fail() is a guarded state transition.
- `includes/transfer.php:228` — Transfer::getById() is 'SELECT * FROM transfers WHERE id = ?' with no status filter - the row 'failed' by the reclaim is returned exactly like a pending one.
- `admin.php:1015` — case 'check_transfer' calls Transfer::complete($row['id'], (int)$row['amount']) on a row whose status it never read, and answers 'This token has been cashed in. Marked as completed.'
- `admin.php:995` — case 'check_transfer' loads the row with Transfer::getById() and never reads its status, while the sibling settle path does: Transfer::findPendingBySecrets() matches status='pending' only (includes/transfer.php:254), so 'check_proofs_spent' cannot overwrite a terminal row and 'check_transfer' can.
- `admin.php:1073` — case 'reclaim_transfer': after taking the funds_<storeId> flock and swapping the exported proofs back (admin.php:1071), the row is written with Transfer::fail($row['id'], 'Taken back by the operator; the token no longer works') - a terminal 'failed' state. The reclaim itself refuses a non-pending row (admin.php:1051).
- `includes/invoice.php:1370` — reclaimExportedProofs() swaps the exported proofs at the mint (wallet->swap(...)), which consumes them there; from that moment the mint reports them SPENT, so 'check_transfer' cannot distinguish the operator's own reclaim from a recipient's redemption.
- `admin.php:118` — cashupay_settle_redeemed_transfer() - the settle path used by 'check_proofs_spent' (admin.php:1589, 1630) - reaches Transfer::complete() only through Transfer::findPendingBySecrets(), so the sibling action is status-guarded and the two settle paths are inequivalent.
- `admin.php:5518` — The shipped dashboard computes actionable = isToken && t.status === 'pending' && t.hasToken, and renders the 'Check if cashed in' button (admin.php:5528) only for such rows. A click-through therefore never reaches the state; the trigger is the POST itself.
- `includes/background_runner.php:655` — The cron reconciler (reconcileTokenTransfer) settles the same rows with the same unguarded Transfer::complete(), but it only loads rows that are still pending (getPendingPage, background_runner.php:487 and 505 filter status='pending'), so it leaves a narrow race with a reclaim that lands inside its mint round-trip window. The deterministic path reported here does not need it.
- `includes/database.php:517` — The transfers table declares status TEXT NOT NULL DEFAULT 'completed' - a free-form column with no CHECK constraint and no triggers, so the missing predicate in Transfer::update() is the only thing that could have stopped the terminal row from being rewritten.
- `tests/mock_mint_router.php:439` — The repository's own mint fixture consumes the inputs of a swap (marks them spent), which is the NUT-02 behaviour every conforming mint has; this is why the local check reproduces the same mint answer a real mint gives after a reclaim.

---

### 4.19 Unauthenticated admin page discloses the absolute path of the wallet data directory

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `admin.php:data-dir-path-rendered-before-admin-auth` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:2623` | A request without ?api= skips the JSON dispatcher at admin.php:421 and the POST dispatcher at admin.php:728 and falls through to the SPA HTML render at admin.php:2623. No authentication state is consulted on this path, so an anonymous visitor is served the full page. |
| 2 | `propagation` | `admin.php:309` | The sole pre-render gate is the setup check: an install that is not set up is redirected to setup.php, and includes/entrypoint_guard.php:59-63 can halt the whole site on a signed emergency notice. Once setup is complete - the normal production state - the request continues to the HTML render with no authentication and no operator-only check. |
| 3 | `propagation` | `admin.php:317` | Auth::initSession() is called, and Auth::isLoggedIn() is consulted only on the right-hand side of the background-sync condition at line 318 (i.e. only to decide whether to run a sync). It is never used as a gate, so the anonymous request proceeds to the render while a session and CSRF token are minted for it. |
| 4 | `sink` | `admin.php:3812` | The Settings view of the rendered page prints 'Data folder on the server (keep it when you upgrade, copy it for a full backup):' followed by htmlspecialchars((string)(realpath(Database::getDataDir()) ?: Database::getDataDir())) - the absolute server path of the data directory, for example /home/<user>/.cashupay-db on a normal deployment. htmlspecialchars() prevents markup injection but performs no access control, so the path is served to every visitor; the view is CSS-hidden, but the value is present in the raw response body. |

**Root cause.** admin.php renders operator-only content (the data-folder path) into the HTML shell, but the HTML shell is emitted without consulting the admin authentication state. The authentication boundary is applied only inside the two branch handlers - the ?api= dispatcher (admin.php:421-430) and the POST dispatcher (admin.php:782-795) - and not on the fall-through path that renders the page (admin.php:2623 onwards), so the guard that the rest of the admin surface relies on is skipped for the plain GET.

**Intended behaviour.** The data-folder path is operator information. It should be shown only to an authenticated operator, exactly like every other value the dashboard displays, which arrives through the login-gated ?api= endpoints. The anonymous GET /admin.php should return the login screen and nothing that describes the installation's filesystem layout. The codebase already treats absolute server paths as visitor-hostile: includes/setup_ownership.php:253 relativeDataDirForDisplay() deliberately returns a relative/abstracted folder name and its comment says 'Never the absolute path, which this page would otherwise print to any visitor'; admin.php:3812 breaks that convention.

**Lower-trust principal and starting capability.** None. The request needs no session cookie, no CSRF token and no password; a plain 'GET /admin.php' from an anonymous client is enough. None. The page is returned in full to the requester; the Settings view is CSS-hidden but the path is present in the raw response body, so no click or rendering step is required to read it.

**Conditions.**

- *(system_configuration)* Setup is complete, so admin.php:309-312 does not redirect to setup.php. This is the normal production state of every live installation.
- *(authentication_level)* None. The request needs no session cookie, no CSRF token and no password; a plain 'GET /admin.php' from an anonymous client is enough.
- *(user_interaction)* None. The page is returned in full to the requester; the Settings view is CSS-hidden but the path is present in the raw response body, so no click or rendering step is required to read it.
- *(data_state)* The data directory must exist and be resolvable by realpath(), which is the case for every running installation.

**Bounded, target-native reproduction**

```text
attacker perspective: Any unauthenticated visitor who can reach the site (crawler, scanner, or a person) and issues a plain GET /admin.php. index.php:101 renders an 'Admin Login' link to this page, so it is discoverable without guessing.

payload: GET /admin.php  (no cookie, no query string, no credentials)
payload: GET /admin.php?api=stores  (no cookie) - the control case, answers HTTP 401 {"error":"Not authenticated"}

steps:
  - Create a throwaway fixture outside the repository: a scratch/prepend.php that defines CASHUPAY_DATA_DIR to a scratch-local data directory, loaded with php -d auto_prepend_file so the repository's own data/ is never read or written (CASHUPAY_DATA_DIR takes precedence over includes/data-location.txt and the default ./data, includes/database.php:40-52).
  - Populate it with the application's own Database::initialize() plus one dummy store (seed_phrase and internal_api_key are literal canary strings) and Config::set('setup_complete', true), so admin.php:309 does not redirect to setup.php. No real mint, no real credential, no real data.
  - Serve the repository read-only with 'php -d opcache.enable=0 -d auto_prepend_file=<scratch>/prepend.php -S 127.0.0.1:8127 -t <repo>' inside the sandbox (loopback only, no external network, empty env, HOME/TMPDIR=scratch, ulimit -t 120 -f 51200, timeout 180).
  - Request GET /admin.php with curl and no -b/-c cookie jar, read the response body, then request the eight ?api= read actions with the same absence of credentials and compare.

observed:
  GET /admin.php answered HTTP 200 with 234171 bytes to a client that sent no cookie and no credential (the response set its own cashupay_session cookie, HttpOnly, SameSite=Lax, for the CSRF token only). The body contained 'Data folder on the server (keep it when you upgrade, copy it for a full backup):' immediately followed by <code style="word-break: break-all;">/Users/juraj/security-audit-skill/cashupayserver/run-1/agents/v7-datadir/scratch/data</code> - the absolute path of the fixture data directory, verbatim. Response headers included Content-Security-Policy: default-src 'self'; ... frame-ancestors 'self', X-Frame-Options: SAMEORIGIN, X-Content-Type-Options: nosniff and Referrer-Policy: strict-origin-when-cross-origin. In the same run, GET /admin.php?api=stores, dashboard, api_keys, proofs, invoices, diagnostics, export_info and get_backup_mints each answered HTTP 401 {"error":"Not authenticated"}. A sweep of the anonymous body for CANARY_SEED_PHRASE, CANARY_INTERNAL_API_KEY, store_canary, mint.canary.example, wa_canary, seed_phrase, internal_api_key, admin_logged_in, password_version, quote_id and 'mnemonic' found 0 occurrences each; the only server-side values echoed into the anonymous shell are the session-bound CSRF token (admin.php:2636), the data-directory path (admin.php:3812), the public server URL (admin.php:3869), the version string and the cached update-check status. The repository's data/ directory was untouched (mtimes and sizes unchanged). Full transcript: agents/v7-datadir/artifacts/check-datadir.txt.
```

**Impact.** Only the absolute filesystem path of the data directory is disclosed - no seed phrase, no proof, no session, no API key, no invoice, and no way to read the directory through this path. It does reveal the hosting account layout and the exact location of the funds' storage, which helps aim any separate path-based or alias-misconfiguration attack; the application already probes for a web-exposed data directory on login and warns the operator (admin.php:325-369).

**Likelihood.** No precondition beyond the site being reachable and setup being complete: a plain anonymous GET /admin.php returns the path, and index.php:101 links to the page, so any crawler sees it. Reproduced end to end in the sandbox.

**Why this priority.** The trace is short and fully source-visible (one echo of realpath(Database::getDataDir()) on a path with no authentication check), every cited line number was re-read at commit 4a82d01 and matches, and the disclosure was independently reproduced in a sandboxed local run against a throwaway database: HTTP 200 with the literal absolute path for the anonymous GET, HTTP 401 for all eight ?api= reads, and a canary sweep showing no other sensitive value in the anonymous body.

**Smallest source fix.** Render the data-folder path only to an authenticated operator. The session is already initialised at admin.php:317, before the HTML is emitted, so the smallest correct change is to guard that one paragraph with Auth::isLoggedIn(). An equivalent and arguably better shape is to stop putting the path in the static HTML at all and to serve it from a new login-gated ?api= read (the same mechanism every other operator-only value already uses), filling the field from JavaScript after login. Do not weaken the path handling itself: the value is useful to the operator and is correctly htmlspecialchars()-escaped; only its audience is wrong. Note that setup.php:1162 also prints the data directory's parent folder and base name (on a relocation error only); that page redirects to /admin.php once setup is complete (setup.php:39-47), so it is not an anonymous post-setup disclosure and needs no equivalent change - but a fix that moves the path behind ?api= should leave setup.php's existing relative-path convention alone.

`admin.php` — <?php if (Auth::isLoggedIn()): ?>
                        <p class="form-help" style="margin-top: 0.75rem;">
                            Data folder on the server (keep it when you upgrade, copy it for a full backup):
                            <code style="word-break: break-all;"><?php echo htmlspecialchars((string)(realpath(Database::getDataDir()) ?: Database::getDataDir())); ?></code>
                        </p>
                    <?php endif; ?>

**Supporting evidence**

- `admin.php:421` — The ?api= dispatcher does apply the boundary: Auth::initSession() then 'if (!Auth::isLoggedIn()) { http_response_code(401); ... exit; }' before the switch, so the control exists in this file and is simply not applied to the HTML path - a sibling door to the same surface. Reproduced: all eight read actions answered HTTP 401 {"error":"Not authenticated"} with no cookie.
- `admin.php:728` — The POST dispatcher repeats the check at admin.php:782-787 (Auth::initSession() then 401) and adds the CSRF check at 789-795, so the boundary exists on both JSON branches and is missing only on the HTML fall-through.
- `includes/auth.php:58` — Auth::isLoggedIn() is the admin boundary the ?api= path uses (WordPress mode: current_user_can('manage_options'); standalone: a session flag plus a hash_equals() check of the password version). Nothing equivalent is consulted before the HTML is rendered.
- `admin.php:38` — cashupay_public_store() is the mechanism that keeps the wallet secrets out of the ?api= responses: it unsets seed_phrase, internal_api_key and internalApiKey. It confirms the intended shape of the admin read surface - operator-only values are meant to be withheld - and shows the HTML path has no equivalent.
- `includes/database.php:40` — Database::getDataDir() resolves to the CASHUPAY_DATA_DIR constant, else includes/data-location.txt, else ./data. That directory holds cashupay.sqlite (the store seed phrases and the ecash proofs, includes/database.php:161), the sessions/ subdirectory created by Auth::initSession() (includes/auth.php:25) and the backup-*.sqlite snapshots written by the download_backup action (admin.php:887).
- `includes/setup_ownership.php:253` — relativeDataDirForDisplay() returns the data folder as a relative/abstracted name and its doc comment states 'Never the absolute path, which this page would otherwise print to any visitor.' setup.php:1088 and :1129 use it. The project therefore has an established convention that absolute server paths must not be rendered for visitors; admin.php:3812 violates it.
- `includes/setup_ownership.php:265` — Setup::clearOwnershipToken() deletes the setup-token-*.txt file from the data directory, and setup.php:422-423 calls it the moment setup completes. The hunter's description lists the setup/ownership token as living in the disclosed directory; in the state where the admin page is anonymously reachable (setup complete) that file no longer exists. The disclosed directory still holds the wallet database, the session store and the backups.
- `admin.php:2636` — The same unauthenticated page also mints a session and a CSRF token for anonymous visitors, which is harmless and confirms that the page is intentionally public - the defect is what it prints, not that it is public. The sweep of the anonymous body found no seed phrase, no internal API key, no store id, no mint URL, no wallet account id, no session content and no invoice data.

---

### 4.20 'Mark recovered' can write off a payment that arrived after the exported token, because the session list is replaced by every re-export

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `admin.php:mark_stranded_recovered:session-list-overwrite` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:2592` | The operator confirms the claim; the server derives the namespace id at admin.php:2602 and reads the proof list from the session at admin.php:2603. The request contains only mint_url, unit and account - nothing that identifies which export is being confirmed. |
| 2 | `propagation` | `admin.php:2575` | $_SESSION['stranded_export'][$result['wallet_id']] = $result['secrets']; - the list is REPLACED on every export of that namespace, so the session holds only the most recent export's proofs and the earlier token's proof set is gone. |
| 3 | `propagation` | `includes/invoice.php:1488` | 'secrets' => array_map(fn($p) => $p->secret, $unspent) - the list is every UNSPENT proof of the namespace at export time, so a later export's list is a superset of an earlier one's and now also contains payments that arrived after the first token was created. |
| 4 | `sink` | `includes/invoice.php:1522` | return $storage->transitionProofStates($secrets, ProofState::SPENT, [ProofState::UNSPENT]); - every secret in the session list is written off, including the late payment, with no count check against the token that was actually claimed. |

**Root cause.** admin.php:2575 keys the proof list by namespace and replaces it on every export, so the write-back authority is per-namespace and mutable rather than per-export and immutable. admin.php:2603 looks the list up by namespace id alone and cannot tell which export the operator is confirming; includes/invoice.php:1522 then transitions every secret in that list from UNSPENT to SPENT with no check that those proofs were in the token that was actually claimed.

**Intended behaviour.** 'Mark recovered' writes off exactly the proofs that went into the token the operator confirmed claiming, and nothing else - in particular it must not write off a payment that arrived in the namespace after that token was created (admin.php:2573-2574, includes/invoice.php:1486-1488).

**Lower-trust principal and starting capability.** Operator admin session with a valid CSRF token (admin.php:782-795). The operator exports the namespace again (the previous-mint screen keeps offering the export button, and the UI text invites a re-export) before clicking 'I've claimed it - mark recovered' on the token they actually handed out.

**Conditions.**

- *(authentication_level)* Operator admin session with a valid CSRF token (admin.php:782-795).
- *(data_state)* A customer payment is collected into the stranded namespace after the operator's export, i.e. an invoice created before the store's mint or unit change is paid late and recoverExpiredPaidInvoices() mints it into the old namespace.
- *(user_interaction)* The operator exports the namespace again (the previous-mint screen keeps offering the export button, and the UI text invites a re-export) before clicking 'I've claimed it - mark recovered' on the token they actually handed out.
- *(system_configuration)* The store's mint URL or unit had been changed earlier, so the invoice's mint_url/mint_unit namespace is the stranded one.

**Bounded, target-native reproduction**

```text
attacker perspective: The operator of the shop confirms the recovery of a token they handed out; the write-back additionally wipes a payment that had just arrived, so real money that was collected becomes invisible in every balance and in the recovery screen.

payload: dummy store id 's1', wallet_account_id 'wa_probe_account', namespace (https://old-mint.example, sat, wa_probe_account) with 4 dummy proofs (4/8/16/32 sat)
payload: one late-payment proof 'secret-late' (64 sat) stored into the same namespace after export #1
payload: a second export of the same namespace (a second POST of action=export_stranded_funds), then a POST of action=mark_stranded_recovered

steps:
  - Run the scratch fixture inside the provided sandbox: sbx.sh w4d-stranded -- php stranded_writeback_probe.php (CASHUPAY_DATA_DIR inside the agent scratch directory; no mint is contacted).
  - Read the output: the session list after export #2, the 'marked N proofs' count, and the final proof states.

observed:
  export #1 token proofs: [secret-1,secret-2,secret-3,secret-4] (60 sat); late payment stored: secret-late (64 sat); export #2 token proofs: [secret-1,secret-2,secret-3,secret-4,secret-late] (124 sat); session list now holds: [secret-1,secret-2,secret-3,secret-4,secret-late]; 'mark recovered (operator confirms the token they handed out = export #1) marked 5 proofs'; 'secret-late (the late customer payment, NOT in the handed-out token #1) is now: SPENT'; 'unspent left in the namespace: 0'. No mint was contacted; the fixture used dummy proofs and a disposable database in the scratch directory.
```

**Impact.** A customer payment that was actually collected is written off locally and disappears from the recovery screen and from every balance. The ecash is still unspent at the mint and can still be redeemed from the second token, which the operator holds in the browser - so the loss materialises only if that token is discarded, and the amount at risk is the late payment rather than the whole balance.

**Likelihood.** Requires the operator to export the same namespace twice with a late payment landing in between; the screen keeps offering the export and the UI text tells the operator they may re-export, so the re-export is a normal, expected action rather than an exotic one. Only the operator can trigger it.

**Why this priority.** The overwrite is a single unconditional assignment (admin.php:2575) and the write-back reads only that entry (admin.php:2603); the effect was reproduced locally with dummy proofs and the admin.php session semantics reproduced verbatim - the late payment's proof was marked SPENT although it was never in the confirmed token, and the namespace then held 0 UNSPENT proofs so it no longer appeared in Invoice::scanStrandedNamespaces().

**Smallest source fix.** Bind the write-back to one export instead of to the namespace. Record every stranded export with its own id - a transfers row (Transfer::open with Transfer::TYPE_TOKEN_EXPORT) or a dedicated stranded_exports table keyed by wallet_id plus a per-export id - and have the mark request carry that id; resolve the proof list from the stored export, not from a mutable session entry. Then markNamespaceRecovered() writes off exactly the proofs of that export, a re-export creates a second, separately identified export instead of overwriting the first, and a late payment that arrives in the meantime keeps its own proofs and stays visible in Invoice::scanStrandedNamespaces(). As long as the session entry is kept at all, it must be keyed per export (e.g. $_SESSION['stranded_export'][$exportId]) and never replaced.

`admin.php` — // export_stranded_funds: keep one entry per export, never one per namespace.
$exportId = bin2hex(random_bytes(8));
$_SESSION['stranded_export'][$exportId] = [
    'wallet_id' => $result['wallet_id'],
    'secrets'   => $result['secrets'],
];
// ... and hand $exportId to the browser with the token.

// mark_stranded_recovered: resolve the proof list from the export the operator confirms.
$exportId = (string)($_POST['export_id'] ?? '');
$entry = $_SESSION['stranded_export'][$exportId] ?? null;
if (!is_array($entry) || $entry['wallet_id'] !== $wid) {
    throw new Exception('Create the recovery token again first, then confirm it was claimed.');
}
$n = Invoice::markNamespaceRecovered($mintUrl, $unit, $account, $entry['secrets']);
unset($_SESSION['stranded_export'][$exportId]);

`includes/invoice.php` — /**
 * Mark only the proofs of one exported token as SPENT.
 *
 * The proof list comes from the export record, not from the namespace's current
 * contents: a payment that arrived after the token was created must keep its own
 * proofs and stay visible in scanStrandedNamespaces().
 */
public static function markNamespaceRecovered(string $mintUrl, string $unit, ?string $account, array $secrets): int {
    self::assertNotLiveNamespace($mintUrl, $unit, $account);
    if (empty($secrets)) {
        return 0;
    }
    $storage = new WalletStorage(Database::getDbPath(), rtrim($mintUrl, '/'), strtolower($unit), $account);
    // Only the proofs that went into the claimed token, and only while still unspent.
    return $storage->transitionProofStates($secrets, ProofState::SPENT, [ProofState::UNSPENT]);
}

**Supporting evidence**

- `admin.php:2575` — $_SESSION['stranded_export'][$result['wallet_id']] = $result['secrets']; - an unconditional overwrite of the per-namespace entry on every export.
- `admin.php:2603` — $secrets = $_SESSION['stranded_export'][$wid] ?? null; - the write-back reads the current session list and has no way of knowing which export the operator is confirming.
- `admin.php:2573` — The comment stating the intent that is defeated: 'Remember exactly which proofs this token carries; "mark recovered" may only write those off.'
- `includes/invoice.php:1486` — '// Server-side only: which proofs this token carries, so "mark recovered" touches exactly these and not a payment that arrived afterwards.' - the intent the per-namespace overwrite breaks.
- `includes/invoice.php:1522` — The write-back transitions the whole supplied list; the number of proofs marked is returned to the operator but never compared with the size of the token that was claimed.
- `includes/invoice.php:1539` — Invoice::scanStrandedNamespaces() only counts state = UNSPENT, so once the late payment's proof is written off as SPENT the namespace no longer appears in the recovery screen, and it is in no store's balance either - the money is invisible everywhere while still redeemable at the mint.
- `includes/invoice.php:1014` — recoverExpiredPaidInvoices() builds the wallet from the invoice's own mint_url and mint_unit, so a payment that arrives late after a mint or unit change is minted into the OLD, stranded namespace - the namespace the operator has just exported.

---

### 4.21 The pairing page redirects the operator to an attacker-chosen origin immediately after they sign in

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `api-keys/authorize.php:login-redirect-raw-request-uri` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `router.php:55` | The route is chosen from parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); for a target that begins with '//host' parse_url returns the host separately and the path '/api-keys/authorize', so the request is served as the merchant's own pairing page. This branch is taken unconditionally under the built-in server (router.php:53), which is why the shipped 'php -S ... router.php' mode is affected. |
| 2 | `propagation` | `router.php:79` | '#^/api-keys/authorize$#' matches the stripped path, so api-keys/authorize.php is required (line 80) while the raw request target, authority included, is still in $_SERVER['REQUEST_URI']. |
| 3 | `propagation` | `api-keys/authorize.php:137` | Auth::attemptLogin() succeeds (the CSRF token comes from the same rendered page), so the page takes the post-login branch instead of rendering an error; with a wrong password the same request answers 200 with no redirect. |
| 4 | `sink` | `api-keys/authorize.php:140` | header('Location: ' . $_SERVER['REQUEST_URI']) emits the attacker's target verbatim, with no scheme and no destination check; for the target '//evil.example/api-keys/authorize' the header value is a protocol-relative URL. |

**Root cause.** api-keys/authorize.php:140 builds the post-login Location header from the raw request target without a scheme or destination policy, while the sibling redirects on the same page (api-keys/authorize.php:186-189 and :252-259) are validated with Security::isSafePublicHttpUrl(). The repository already carries the matching control, Security::isSafeBrowserRedirect() (includes/security.php:461), which refuses a protocol-relative '//host' target, backslashes and control characters, and which payment.php:80 uses for its own browser redirects.

**Intended behaviour.** After a successful login the pairing page returns the operator to the same page on the merchant's own origin, keeping the pairing parameters, and never sends the browser to another host.

**Lower-trust principal and starting capability.** Anonymous for the request; the redirect itself fires only after the operator successfully authenticates, so the target page is reached in an authenticated context. The operator has to open a link the attacker crafted and type the admin password on the merchant's own site; the redirect then happens without further interaction.

**Conditions.**

- *(system_configuration)* The application must be fronted by router.php in a mode that routes on the raw, un-merged request path: the repository's own 'php -S <host>:<port> router.php' (cli-server, router.php:53-55) or any catch-all rewrite that hands an un-merged path to router.php. It is NOT reachable through the shipped Apache .htaccess (the rewrite is anchored and Apache merges duplicate slashes by default), NOT through a default nginx (merge_slashes on), NOT through router.php's own PATH_INFO 'front controller' mode, and NOT in a sub-directory deployment, where a '//'-prefixed target points outside the application's path prefix.
- *(authentication_level)* Anonymous for the request; the redirect itself fires only after the operator successfully authenticates, so the target page is reached in an authenticated context.
- *(user_interaction)* The operator has to open a link the attacker crafted and type the admin password on the merchant's own site; the redirect then happens without further interaction.
- *(network_routing)* The browser, not the server, resolves the Location value, so the attacker's host only has to be reachable from the operator's browser.

**Bounded, target-native reproduction**

```text
attacker perspective: An unauthenticated stranger who can get the merchant to open a link on the merchant's own origin.

payload: Request target: //evil.example/api-keys/authorize (sent verbatim; a browser reaches it as https://<merchant-host>//evil.example/api-keys/authorize)
payload: POST body: action=login&csrf_token=<token from the rendered form>&password=<the operator's admin password>

steps:
  - Run the repository's own router.php under php -S on 127.0.0.1 inside the loopback-only sandbox, with a throwaway CASHUPAY_DATA_DIR in the agent scratch dir holding an already-set-up dummy installation (setup_complete=true, dummy admin password), and send the request target verbatim with a raw TCP socket client (curl normalises the URL).
  - GET the pairing page at '//evil.example/api-keys/authorize?applicationName=Evil%20Shop&permissions=btcpay.store.canviewinvoices&redirect=https%3A%2F%2Fevil.example%2Fcb', keep the session cookie and the CSRF token, then POST action=login with them.
  - Repeat the same flow with the ordinary target '/api-keys/authorize' as a control, with a wrong password as a second control, and with a percent-encoded CRLF target as a header-injection control.

observed:
  GET '//evil.example/api-keys/authorize?...' answers HTTP 200 with the merchant's own pairing page (title 'Authorize Evil - CashuPayServer', the 'Sign In' form, a per-session CSRF token, and a session cookie 'cashupay_session=...; path=/; HttpOnly; SameSite=Lax'); the login form carries no action attribute. The POST with the correct dummy password answers 'HTTP/1.1 302 Found' with 'Location: //evil.example/api-keys/authorize?applicationName=Evil%20Shop&permissions=btcpay.store.canviewinvoices&redirect=https%3A%2F%2Fevil.example%2Fcb' - a protocol-relative target, which RFC 3986 reference resolution against the page origin yields as https://evil.example/api-keys/authorize (host evil.example). The server log shows 'Admin login successful'. The control run with the ordinary target '/api-keys/authorize' answers 'Location: /api-keys/authorize?...', i.e. same origin. The wrong password on the authority-prefixed target answers 200 with no redirect, and a percent-encoded CRLF target does not reach the route at all (404), so the response-header-injection variant is not the issue - the cross-origin navigation is. An absolute-form request target ('GET http://evil.example/api-keys/authorize?...') is also accepted and makes the Location the absolute cross-origin URL 'http://evil.example/api-keys/authorize?...'; browsers never send that form, so it is not a browser-reachable path.
```

**Impact.** A cross-origin navigation of an authenticated operator to an attacker-chosen site, useful as a phishing step and it aborts the pairing flow. The password is posted to the merchant's own origin (the login form has no action attribute), the session cookie is HttpOnly, host-only and SameSite=Lax, and the API key is not handed over by this defect: minting one needs an explicit action=approve POST with a per-session CSRF token, a selected store and a redirect host that passed Security::isSafePublicHttpUrl() and that is shown to the operator before the key is sent.

**Likelihood.** Unauthenticated and cheap to attempt, but it needs the router.php rewrite/cli-server deployment (the repository's own 'php -S ... router.php' mode, documented in README.md under Development and under 'PHP Built-in Server'), because the shipped Apache .htaccess and a default nginx do not route an authority-prefixed target to the pairing page; and it needs a victim who opens a crafted link and signs in, with an unusual-looking URL in the address bar.

**Why this priority.** Independently reproduced with the repository's own router.php and api-keys/authorize.php inside the sandbox (302 with 'Location: //evil.example/api-keys/authorize?...'), with an ordinary-target control that stays on the origin, a wrong-password control that produces no redirect, and a percent-encoded CRLF control that 404s. The source trace for the routing (router.php:55, :79) and the sink (api-keys/authorize.php:140) is complete, and the deployment scope was pinned down by evaluating router.php's route-selection expression for both SAPI branches and by checking the anchored .htaccess rewrite.

**Smallest source fix.** Apply the destination policy the repository already uses elsewhere: rebuild the post-login target from the parsed request path plus the query string, so the target can never be a network-path reference or an absolute URL, and refuse the value with Security::isSafeBrowserRedirect() before emitting it. Optionally refuse an authority-prefixed request target in the front controller so that the request and the application agree on one URL. The parsed-path form is used instead of a hard-coded '/api-keys/authorize' because the pairing page lives at '/cashupay/api-keys/authorize' in the WordPress plugin build (wordpress/rewrite-rules.php:45) and at '/router.php/api-keys/authorize' in the router front-controller mode.

`api-keys/authorize.php` — $result = Auth::attemptLogin($password);
if ($result === 'ok') {
    // Back to the same page, on this origin only. The raw request target may name
    // another host ('//host/path'), so it is never used as a redirect target: the
    // path is rebuilt from the parsed path and the query string.
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $path = '/' . ltrim($path, "/\\");
    if ($path === '/') {
        $path = '/api-keys/authorize';
    }
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $target = $path . ($query !== '' ? '?' . $query : '');
    if (!Security::isSafeBrowserRedirect($target)) {
        $target = Security::isSafeBrowserRedirect($path) ? $path : '/api-keys/authorize';
    }
    header('Location: ' . $target);
    exit;
}

`router.php` — $requestUri = $_SERVER['REQUEST_URI'] ?? '';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// A request target that starts with '//host' is a network-path reference: parse_url()
// reads the authority off it, so the route below would match while the application
// still sees the foreign host in REQUEST_URI. Refuse it, so that the request and the
// application agree on one URL.
foreach ([$requestUri, $pathInfo] as $candidate) {
    $candidate = (string)$candidate;
    if ($candidate === '') {
        continue;
    }
    if (str_starts_with($candidate, '//') || str_starts_with($candidate, "/\\")) {
        http_response_code(400);
        die('Bad request');
    }
}

**Supporting evidence**

- `api-keys/authorize.php:140` — header('Location: ' . $_SERVER['REQUEST_URI']) - the only redirect on the page that is not passed through a destination check.
- `api-keys/authorize.php:186` — The approve branch validates its target with Security::isSafePublicHttpUrl($redirect, true); the deny branch at line 252 validates it without the loopback exemption. The post-login redirect has no counterpart check.
- `includes/security.php:461` — Security::isSafeBrowserRedirect() already implements the missing policy: it refuses a target that starts with '//', refuses backslashes and control characters, and resolves a relative candidate against a fixed origin to prove it stays on it.
- `router.php:55` — parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) is what makes an authority-prefixed target reach a same-origin route: '//evil.example/api-keys/authorize' is routed as /api-keys/authorize while the raw target keeps the authority. The other branch (line 58) uses PATH_INFO, which is why router.php's own /router.php/... 'front controller' mode is not affected.
- `api-keys/authorize.php:584` — The login form is <form method="POST"> with no action attribute, so the password is submitted to the current document URL on the merchant's own origin; nothing credential-bearing is sent to the attacker host.
- `.htaccess:8` — RewriteRule ^api-keys/authorize$ api-keys/authorize.php [L,QSA] is anchored, so the authority-prefixed path does not reach the pairing page under the shipped Apache configuration (and Apache >= 2.4.39 merges duplicate slashes by default).
- `README.md:284` — 'php -S localhost:8000 router.php' - the documented mode whose router.php branch always routes on the raw REQUEST_URI, and therefore the mode in which the sink is reachable (also README.md:406).

---

### 4.22 Greenfield API applies no size bound to the request body and persists an uncapped 'checkout' object into the funds database

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `api.php.getRequestBody.no-request-size-bound` &nbsp;·&nbsp; **Confidence:** medium

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `api.php:171` | getRequestBody() reads the entire request body with file_get_contents('php://input') - no length argument, no Content-Length check - and json_decode()s the result. It is reached from the invoice route POST /api/v1/stores/{storeId}/invoices (api.php:122) at api.php:268. |
| 2 | `propagation` | `api.php:261` | Auth::requireApiAuth() runs before the body is read; it answers 401 and exits when no valid key is presented (includes/auth.php:560-570), so only an API-key holder reaches the unbounded read. Verified: the same 4 MiB body without an Authorization header returned HTTP 401 and created no invoice. |
| 3 | `propagation` | `api.php:54` | The only request-level control is Security::checkRateLimit('api', $clientIp, 100) (includes/security.php:19-55): a per-client-IP counter over a 60 s window. It bounds the request count per minute, not the request size. |
| 4 | `propagation` | `includes/api/invoices.php:49` | The 'metadata' object is bounded at 16 KiB - the control the 'checkout' object beside it lacks. |
| 5 | `propagation` | `includes/api/invoices.php:54` | 'checkout' is validated only with is_array() (lines 54-57); no length, depth or key-count bound is applied to it, nor to the body that carried it. Only 'redirectURL' gets a content check (lines 62-66); every other key is accepted verbatim. |
| 6 | `sink` | `includes/invoice.php:221` | json_encode($checkout) is written verbatim into invoices.checkout_config, one row per invoice, in the operator's SQLite database - the same file that holds cashu_proofs, cashu_counters and the store seeds (includes/database.php:435-458; cashu-wallet-php/CashuWallet.php:3972+ creates cashu_proofs in that same database). |

**Root cause.** The request body is parsed with file_get_contents('php://input') and no length is ever checked (api.php:170-177; no 413 path exists anywhere in the repository), and the per-field validation in handleCreateInvoice bounds 'metadata' to 16 KiB (includes/api/invoices.php:49) but does not bound 'checkout' (includes/api/invoices.php:54-57), which is then persisted verbatim into invoices.checkout_config (includes/invoice.php:221).

**Intended behaviour.** The Greenfield API accepts bounded request bodies: the body is read up to a fixed maximum and refused with 413 beyond it, and every request-controlled object persisted with an invoice (metadata, checkout) is size-capped before it is stored - the way the sibling request-controlled fields already are (metadata 16 KiB, webhook url 2048 bytes, webhook secret 256 bytes).

**Lower-trust principal and starting capability.** A valid Greenfield API key for the store. Verified: a key holding only btcpay.store.cancreateinvoice was enough to create invoices with oversized bodies. The API-key holder is third-party plugin code on the merchant's site, which the permission model deliberately restricts to one store.

**Conditions.**

- *(authentication_level)* A valid Greenfield API key for the store. Verified: a key holding only btcpay.store.cancreateinvoice was enough to create invoices with oversized bodies.
- *(authorization_role)* The API-key holder is third-party plugin code on the merchant's site, which the permission model deliberately restricts to one store.
- *(system_configuration)* The per-request ceiling is whatever the SAPI and any front proxy enforce; the repository sets none (no php.ini, no .htaccess LimitRequestBody, no ini in the build scripts), and the api.php rate limit allows 100 such requests per minute per client IP (api.php:54).
- *(data_state)* The store must exist and be configured with a reachable mint and a seed: Invoice::create asks the mint for a quote before it inserts (includes/invoice.php:160-194), so no row is written when the mint cannot be reached. The rows that are written are never reaped by the 90-day cleanup (includes/background_runner.php:842-854).

**Bounded, target-native reproduction**

```text
attacker perspective: Holder of a Greenfield API key for one store (the shop plugin's key), sending oversized POST bodies to POST /api/v1/stores/{storeId}/invoices.

payload: POST /api/v1/stores/store_a/invoices with header 'Authorization: token <store key>', Content-Type: application/json, body {"amount":"10","currency":"sat","checkout":{"redirectURL":"https://shop.invalid/thanks","pad":"<32768 x 'A'>"}}
payload: the same request with pad raised to 1,048,576, 4,194,304 and 9,437,184 bytes
payload: control request: the same 1 MiB body sent as metadata instead of checkout
payload: authentication control: the same 4 MiB body sent without an Authorization header

steps:
  - Run inside the audit sandbox: sbx.sh v4-bodycap --loopback -- bash <scratch>/probe.sh. The script starts the repository's own mock mint (tests/mock_mint_router.php) and the real front controller (router.php -> api.php) on loopback, with an auto_prepend_file that points CASHUPAY_DATA_DIR at a throwaway directory in the scratch.
  - Bootstrap a dummy store whose mint_url is the mock mint, seed it, and mint one API key holding only btcpay.store.cancreateinvoice.
  - POST each prepared body with curl -X POST -H 'Authorization: token <key>' -H 'Content-Type: application/json' --data-binary @body.json http://127.0.0.1:<port>/api/v1/stores/store_a/invoices
  - Measure the persisted size with SELECT length(checkout_config), length(metadata) FROM invoices and record the byte size of the throwaway SQLite file before and after.

observed:
  HTTP 200 for every body size tried (32,769 / 1,048,677 / 4,194,405 / 9,437,285 bytes of request body, using a key that holds only btcpay.store.cancreateinvoice). The persisted invoices.checkout_config rows measured 32,825 / 1,048,633 / 4,194,361 / 9,437,241 bytes - stored verbatim, one invoice row per request. The control request, with the same 1 MiB in 'metadata', was refused with HTTP 400 {"code":"validation-error","message":"Metadata must be an object of at most 16 KiB"}. The same 4 MiB body without an Authorization header was refused with HTTP 401 {"code":"unauthenticated","message":"Authentication required. Use Authorization: token YOUR_API_KEY"} and created no invoice row (4 rows before and after). The throwaway data dir grew from 221,184 to 14,946,304 bytes over the four accepted requests. The 9,437,285-byte body exceeded the local PHP post_max_size of 8 MiB: it produced only a startup warning ('POST Content-Length of 9437285 bytes exceeds the limit of 8388608 bytes') yet was still buffered from php://input and stored in full (9,437,241 bytes); the JSON response was replaced by that warning because the headers had already been sent, so the request still succeeded. A repository-wide grep for 413 / 'Payload Too Large' / 'request-too-large' returns nothing.
```

**Impact.** Each accepted request permanently adds one row of up to the effective body ceiling to the operator's single SQLite database, which also holds the ecash proofs, the counters and the store seeds; the data dir has no quota, and the 90-day cleanup never reaps these rows (they carry a quote_id) and no endpoint deletes a single invoice, so sustained abuse fills the disk and then makes the funds database unwritable - the shop stops taking payments and cannot settle outstanding invoices. No fund loss, no cross-store disclosure and no privilege gain, and the damage is an availability one that a manual DELETE of the affected rows would undo.

**Likelihood.** Requires a valid Greenfield API key for a configured store, plus a working mint (Invoice::create asks the mint for a quote before it inserts). A well-behaved shop plugin never sends a multi-megabyte body, so this needs a malicious or faulty plugin, or a leaked key.

**Why this priority.** The missing application-level bound, the uncapped persistence and the post-authentication position of the read are fully source-visible and were independently reproduced end to end (a 9,437,241-byte checkout_config column value stored from a 9.4 MB body, and the 16 KiB metadata cap refusing the same size). What remains deployment-dependent is only the reachable magnitude: the per-request ceiling in the deployed php-fpm/nginx stack (post_max_size, client_max_body_size) and the data dir's free space are not visible in the repository, and the repository itself sets none of them.

**Smallest source fix.** Bound the request body at the API boundary before it is parsed, and bound every request-controlled object that is persisted with an invoice, the way 'metadata' already is.

`api.php` — function getRequestBody(): array {
    $maxBytes = 262144; // 256 KiB
    $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($body === false) {
        return [];
    }
    if (strlen($body) > $maxBytes) {
        errorResponse('request-too-large', 'The request body is too large', 413);
    }
    if ($body === '') {
        return [];
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

`includes/api/invoices.php` —     $checkout = $body['checkout'] ?? null;
    if ($checkout !== null) {
        if (!is_array($checkout)) {
            errorResponse('validation-error', 'Checkout must be an object');
        }
        if (strlen((string)json_encode($checkout)) > 4096) {
            errorResponse('validation-error', 'Checkout must be an object of at most 4 KiB');
        }
    }

**Supporting evidence**

- `api.php:171` — $body = file_get_contents('php://input'); - the whole body is read with no maximum, no length argument and no Content-Length check. A repository-wide grep for 413 / 'Payload Too Large' / 'request-too-large' returns nothing: there is no 413 path and no .htaccess LimitRequestBody.
- `api.php:54` — if (!Security::checkRateLimit('api', $clientIp, 100)) - the only request-level control; a per-IP counter over a 60 s window (includes/security.php:19-55) that bounds request count per minute, not request size.
- `api.php:268` — $handler($auth, $params, getRequestBody()); - the body is read here, after Auth::requireApiAuth() at api.php:261, so nothing is buffered before authentication.
- `includes/api/invoices.php:49` — Metadata is explicitly capped at 16 KiB (strlen(json_encode($metadata)) > 16384), so the handler does intend to bound request-controlled objects; the sibling 'checkout' object in the same body is not. Other request-controlled persisted fields are bounded too (includes/api/webhooks.php:171 url <= 2048, :252 secret <= 256), which makes 'checkout' the outlier.
- `includes/api/invoices.php:54` — $checkout = $body['checkout'] ?? null; followed only by an is_array() check - no size bound.
- `includes/invoice.php:221` — 'checkout_config' => $checkout ? json_encode($checkout) : null - the uncapped object is persisted with the invoice, verbatim.
- `includes/database.php:435` — The invoices table (checkout_config TEXT at line 449) is created in the same SQLite file as the stores (with their seed phrases) and, through the wallet storage, as cashu_proofs and cashu_counters - so a retained oversized row is shared operator state.
- `includes/background_runner.php:843` — cleanupInvoices() deletes only invoices older than 90 days whose status is 'Settled' or whose quote_id is empty. An invoice created by this attack is 'New'/'Expired' and always carries a quote_id, so the oversized row is never reaped, and no endpoint deletes a single invoice.

---

### 4.23 The unattended donation-retry loop keeps delivering a pending donation's bearer token to the third-party sink after the operator has switched donations off

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `donation.retryLoop.deliversAfterOptOut` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `includes/background_runner.php:481` | reconcileTransfers() is the background task that walks the pending outgoing-transfer rows of every store and calls reconcileOne() on each; it is one of the tasks BackgroundRunner::run() executes (includes/background_runner.php:102) on every cron tick, on WP-Cron, and on the admin 'Run now' action. |
| 2 | `propagation` | `includes/background_runner.php:539` | reconcileOne() routes any row whose type is one of Transfer::TOKEN_TYPES (token_export, donation) to reconcileTokenTransfer(), which is the only place a stored bearer token is delivered again. |
| 3 | `propagation` | `includes/background_runner.php:662` | The condition 'every proof secret is UNSPENT at the mint AND type === Transfer::TYPE_DONATION AND the row carries a token' decides that the donation must be delivered again. The operator's CASHUPAY_DONATION_PERCENT is not part of this condition, and there is no attempt counter, expiry or backoff on the branch. |
| 4 | `propagation` | `includes/background_runner.php:678` | Donation::postTokenToSink($row['token']) is called with the bearer token stored in the transfers row, i.e. the same token that was already handed to the sink once, on every subsequent reconcile pass. The row's status stays 'pending' and Transfer::note() overwrites its detail each time. |
| 5 | `sink` | `includes/lightning_address.php:817` | curl_init(CASHUPAY_DONATION_SINK_URL) with CURLOPT_POST and the token in a JSON body hands the merchant's bearer ecash to the hard-coded third-party host (includes/config.php:29). The POST is unauthenticated and unsigned, and the response only decides whether the row is left pending for another attempt. |

**Root cause.** The decision to deliver a pending donation is taken in includes/background_runner.php:662 from the row's type and the proof states, while the operator's donation setting lives in a constant that is only read on the creation path. The two components disagree about who owns the decision: Donation::calculateAmount() (includes/lightning_address.php:850-857) treats CASHUPAY_DONATION_PERCENT as the switch for donations, while reconcileTokenTransfer treats the row's type as the switch for delivering them. The retry loop additionally has no bound of its own, so a row that the sink never redeems is delivered again on every reconcile pass indefinitely.

**Intended behaviour.** The operator's donation setting should govern every donation the server sends, not only the ones created after it was set: a pending donation must stop being delivered to the third-party sink once the operator has switched donations off (and the merchant's ecash must stay reclaimable). An unattended retry of an outbound money transfer should also be bounded by an attempt count or an expiry rather than running for as long as the server runs.

**Lower-trust principal and starting capability.** The operator has set CASHUPAY_DONATION_PERCENT to 0 in includes/config.local.php, or otherwise expects the donation setting to cover donations that are already pending. This is the default-adjacent state for an operator who wants no donations at all.

**Conditions.**

- *(system_configuration)* The operator has set CASHUPAY_DONATION_PERCENT to 0 in includes/config.local.php, or otherwise expects the donation setting to cover donations that are already pending. This is the default-adjacent state for an operator who wants no donations at all.
- *(data_state)* A transfers row of type 'donation' is still pending and the mint still reports all of its proof secrets as UNSPENT, which is the normal state of a donation the sink has not redeemed yet.
- *(timing_dependency)* The background runner's reconcile_transfers task executes, which happens on every cron tick, on every WP-Cron run and on the admin 'Run now' action. Each execution is one more delivery.
- *(third_party_dependency)* The sink is reachable or unreachable: with a 2xx the row is left pending for the next pass anyway, and with an error the token is explicitly kept ('the token is stored and will be retried', includes/lightning_address.php:740), so the loop continues in both cases.

**Bounded, target-native reproduction**

```text
attacker perspective: No attacker action is required. The third-party donation sink only has to wait: the merchant's own server hands it the bearer token again on every background pass, even after the merchant has switched donations off. The party that benefits is the party that controls the sink host, and the party that pays is the merchant whose balance the token was split from.

payload: One pending transfers row: type 'donation', status 'pending', a non-empty token, and a reference holding the proof secrets whose mint state is UNSPENT.
payload: CASHUPAY_DONATION_PERCENT = 0 defined before the application code is loaded (the operator's opt-out).
payload: CASHUPAY_DONATION_SINK_URL pointed at a loopback HTTP server that counts POSTs, so the delivery can be observed without contacting any real host.

steps:
  - Define CASHUPAY_DATA_DIR to a scratch directory and CASHUPAY_DONATION_PERCENT = 0, then include the application code (config.php's own define() of the sink URL is unguarded, so the fixture's value wins and only produces a PHP warning).
  - Start the repository's own tests/mock_mint_router.php on loopback as the store's mint and a local PHP server that logs each POST as the stand-in sink.
  - Create a dummy store pointing at the loopback mint and insert one pending donation row carrying a made-up proof and its serialized token.
  - Call BackgroundRunner::reconcileTokenTransfer() on that row three times and count the POSTs the stand-in sink received.

observed:
  Donation::calculateAmount(1000) returned 0 with donations switched off, so no new donation was created, yet the stand-in sink received one POST per reconcile pass (3 passes -> 3 POSTs), each carrying the complete bearer token and the 'project' label, and the transfers row stayed status 'pending' with its detail overwritten to 'Re-sent to the donation sink; awaiting confirmation' on every pass. The opt-out therefore governs the creation of donations but not their delivery.
```

**Impact.** The amount at risk is 1% of one withdrawal, hard-capped at 10% by Donation::calculateAmount(), it stops as soon as the sink redeems the token or the operator reclaims it, and the row stays visible under Transfers the whole time.

**Likelihood.** It needs the specific combination of an operator who has opted out of donations and a donation row that is still pending; the recipient is the project's own sink, so no external attacker is involved and the operator can stop it by reclaiming the row.

**Why this priority.** The source trace is complete and short (the decision is one condition in one function), the operator's switch and its only consumer are both visible in the repository, and the behaviour was reproduced locally against the repository's own code with dummy data on loopback.

**Smallest source fix.** Make the delivery of an existing donation obey the same operator setting that governs creating one, and give the unattended retry a bound so a token the sink never redeems is not delivered forever. The smallest change is a guard in reconcileTokenTransfer that refuses to deliver while the operator's donation percentage is zero, telling the operator that the ecash is still theirs and can be taken back; a second, independent change records an attempt count on the row and stops after a bounded number of attempts.

`includes/background_runner.php` —         // Nobody has taken it yet. A donation is ours to keep trying; an export is simply
        // waiting for its recipient and is not a problem to be solved.
        if ($unspent === count($secrets) && $row['type'] === Transfer::TYPE_DONATION
            && !empty($row['token'])) {
            // The operator's donation setting governs every delivery, not only the
            // creation of new donations: a donation that is already pending must not
            // keep being sent to the sink after the operator has switched donations
            // off. The ecash stays EXPORTED, so the operator can still take it back.
            if (!defined('CASHUPAY_DONATION_PERCENT') || (float)CASHUPAY_DONATION_PERCENT <= 0) {
                Transfer::note(
                    $row['id'],
                    'Donations are switched off, so this donation was not sent again. '
                    . 'The ecash is still yours - you can take it back under Transfers.'
                );
                return 'still_pending';
            }


**Supporting evidence**

- `includes/config.php:26` — CASHUPAY_DONATION_PERCENT defaults to 1 and is documented as the operator's switch ('config.local.php may set 0'). It is a constant, so it is read from wherever it is consulted, not from a row or a ledger entry.
- `includes/lightning_address.php:852` — Donation::calculateAmount() returns 0 when CASHUPAY_DONATION_PERCENT is 0 or less, so the setting stops the creation of new donations only.
- `includes/background_runner.php:662` — The retry condition inspects the row type and the proof states only; CASHUPAY_DONATION_PERCENT is never read on this path.
- `includes/background_runner.php:678` — Every reconcile pass re-POSTs the stored token, and Transfer::note() on the same row overwrites the detail, so the ledger shows an endless 'Re-sent to the donation sink; awaiting confirmation' with no attempt count.
- `includes/lightning_address.php:804` — Donation::postTokenToSink() posts to the fixed CASHUPAY_DONATION_SINK_URL and returns true only on a 2xx; a false result keeps the token for the next pass, so an unreachable sink produces the same unbounded loop.
- `includes/lightning_address.php:728` — The donated proofs are moved to EXPORTED before the POST, so while the delivery is pending the money is outside the merchant's spendable balance and is only recoverable by an explicit reclaim (admin.php:1039-1101), which costs a mint swap.

---

### 4.24 The store's seed phrase becomes replaceable once the store's current wallet namespace is empty, so a mint or unit change that could not reach its new mint leaves the store's recovery key unprotected

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `includes/config.php:updateStore:seed-immutability-scoped-to-current-namespace` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:1328` | A posted seed_phrase is put into $updates and handed to Config::updateStore at admin.php:1419. The POST branch is gated at admin.php:782-795 by a logged-in session and a valid CSRF token. The dashboard's own requests (admin.php:6390 and admin.php:7268-7269) never send this field, so it is reachable only by a direct authenticated POST. |
| 2 | `propagation` | `includes/config.php:323` | The change is refused only while $old !== '' && $old !== $new && self::walletAccountIsInitialized($store); the last term looks at one namespace, so a store whose current namespace is empty passes the check and its seed is replaced. |
| 3 | `sink` | `includes/config.php:353` | The new seed phrase is written over the one that derives the store's proofs. The proofs recorded locally stay spendable, but the seed no longer derives the mint's history, so unrecorded outputs and any future restore are lost. |

**Root cause.** The immutability check is scoped to the wallet namespace the store currently points at rather than to the store: Config::walletAccountIsInitialized inspects only (mint_url, mint_unit, wallet_account_id), so the protection of the seed disappears as soon as the store points at a namespace that has no state. The same predicate drives $wouldStrand in admin.php:1355, so the warning and the confirmation requirement are lost in the same state.

**Intended behaviour.** The seed phrase of a store is never changed in place: 'The seed is never changeable in place - a different seed can't derive the existing proofs' secrets and breaks recovery' (includes/config.php:324-326). The store's recovery capability must not depend on whether the store currently points at a populated namespace; a store that has ever held funds must keep its seed, and a mint or unit change on such a store must keep asking for the operator's confirmation.

**Lower-trust principal and starting capability.** An authenticated administrator session and the dashboard's CSRF token (admin.php:782-795); the dashboard's own store-settings form never sends seed_phrase, so the field is reachable only by a direct POST (action=update_store&store_id=...&seed_phrase=...) - the same reachability the reveal_seed action (admin.php:855) already has, which is gated by the admin password. None beyond the administrator's own request. The mint or unit change that creates the state is itself a confirmed, warned operator action, so the operator has no indication that the recovery key has become replaceable.

**Conditions.**

- *(authentication_level)* An authenticated administrator session and the dashboard's CSRF token (admin.php:782-795); the dashboard's own store-settings form never sends seed_phrase, so the field is reachable only by a direct POST (action=update_store&store_id=...&seed_phrase=...) - the same reachability the reveal_seed action (admin.php:855) already has, which is gated by the admin password.
- *(data_state)* The store's current namespace is empty. The reachable ways to get there: a unit change to a unit the mint does not serve (deterministic - admin.php:1325-1327 performs no connection test for a unit change and Wallet::loadMint() then throws), a mint change where /v1/info answers but /v1/keysets fails (admin.php:1318-1321 only tests /v1/info through Config::testMintConnection), or a transient failure. A mint that is completely unreachable is refused by the admin layer before it is saved, so the state comes from the unit path or the partial-failure path. The funds are in the namespace the store left.
- *(user_interaction)* None beyond the administrator's own request. The mint or unit change that creates the state is itself a confirmed, warned operator action, so the operator has no indication that the recovery key has become replaceable.

**Bounded, target-native reproduction**

```text
attacker perspective: An administrator (or an attacker holding an administrator session and the dashboard's CSRF token) against a store whose mint or unit was changed to one the server could not use, in an installation where the admin dashboard's store settings do not expose the seed field.

payload: a store row with mint_url, mint_unit, wallet_account_id, seed_phrase and a bound seed fingerprint in namespace (mint-a, sat, acct)
payload: Config::updateStore($storeId, ['mint_url' => 'https://mint-b.example'], true) - the operator-confirmed mint change (or ['mint_unit' => 'usd'], true for the deterministic unit variant)
payload: Config::updateStore($storeId, ['seed_phrase' => <a different valid mnemonic>]) - the seed replacement

steps:
  - Create a store with a seed and bind the seed fingerprint for namespace (mint-a, sat, acct) so the wallet counts as initialised.
  - Confirm that Config::updateStore refuses a mint change without $allowMintChange and applies it with it.
  - After the change, ask Config::isStoreWalletInitialized for the store: it answers false, because the new namespace is empty.
  - Call Config::updateStore with a different seed_phrase: it returns without an exception and the stored seed has changed.
  - Read the corresponding admin.php behaviour: with the namespace empty, $wouldStrand is false, so the next mint or unit change skips the 409 mint_change_strands_funds warning and the operator's confirmation.

observed:
  CASE1: with the namespace initialised, the mint change and the unit change were both REFUSED without $allowMintChange ('Wallet seed, mint, and unit are immutable after wallet initialization') and applied with it. CASE2: after the confirmed move, Config::isStoreWalletInitialized => false and the seed change was 'ALLOWED (no exception)'; the stored seed_phrase had changed. CASE3 (deterministic unit variant): after the confirmed unit change to 'usd', Invoice::readyStoreWallet threw 'No active keysets for unit usd. Available units: sat' (the state admin.php:1435 turns into a warning), isStoreWalletInitialized => false, and the seed change was 'ALLOWED (no exception)' with the stored seed_phrase changed. The old namespace's proofs stay exportable: Invoice::exportNamespaceAsToken() reads them from the local database and needs no seed.
```

**Impact.** The proofs already recorded in the local database stay exportable - Invoice::exportNamespaceAsToken() needs no seed - so the money in the store is not taken directly; what is lost is the ability to recover anything the server had not recorded locally and to restore the wallet from its seed, and the operator gets no warning at any point. A later loss of the local database would then make the funds at the old mint unrecoverable.

**Likelihood.** It needs an authenticated administrator POST with a seed (the dashboard never sends one) on a store whose current namespace is empty - which requires an earlier mint or unit change whose target could not be used. The unit-change path makes that state deterministic rather than a race, but the actor is still an authenticated administrator (or a holder of the session and CSRF token), who can already do a great deal with the dashboard.

**Why this priority.** The behaviour was reproduced locally with dummy data and the repository's own mock mint (the namespace-scoped predicate, the accepted seed replacement, the stored seed changing), in two independent ways (a confirmed move to an unreachable mint and a deterministic confirmed unit change), and the code path that produces the empty-namespace state (admin.php:1429-1441 with includes/invoice.php:600) was read directly at the target commit.

**Smallest source fix.** Make the seed a set-once value instead of a namespace-scoped one: a store's recovery phrase may be written while it is still empty (the setup wizard does exactly that, setup.php:374, and a new store is inserted with its seed, admin.php:1238-1249) and may never be changed afterwards. That closes the hole in every state - initialised namespace, empty namespace, moved namespace - and needs no namespace enumeration. Second, keep the mint/unit consent requirement keyed on the store rather than on the current namespace: $wouldStrand should mean 'this store holds or has held funds' (a new Config::storeHasHeldFunds() helper that looks for the store's account in the namespaces it has ever used, the way Invoice::scanStrandedNamespaces() already enumerates them), not 'this store's current namespace has state', so that a second change cannot be made silently in the state a failed first change leaves behind. Third, make that state loud: admin.php should not report a successful mint change when the new namespace was never initialised (includes/invoice.php:600 could not bind a fingerprint), because that is the state in which the store's recovery key is unprotected.

`includes/config.php` —         foreach (['mint_url', 'mint_unit', 'seed_phrase'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = (string)($store[$field] ?? '');
            $new = (string)($data[$field] ?? '');
            if ($field === 'mint_url') {
                $old = rtrim($old, '/');
                $new = rtrim($new, '/');
            }
            if ($field === 'seed_phrase' && $old !== '' && $old !== $new) {
                // A recovery phrase is written once and never changed: a different phrase
                // cannot derive the secrets of the proofs this store already holds, so
                // the money at the mint it used would be unrecoverable. This holds for a
                // store whose current namespace happens to be empty as well - the funds
                // live in the namespace the store left, and the phrase is the only key
                // to them.
                throw new Exception('The recovery phrase of a store cannot be changed. Create a new store wallet instead.');
            }
            if ($old !== '' && $old !== $new && self::walletAccountIsInitialized($store)) {
                // The mint/unit MAY be changed with explicit operator consent
                // ($allowMintChange): the old mint's funds are only stranded, not
                // lost, and stay recoverable via Invoice::scanStrandedNamespaces().
                if (!$allowMintChange) {
                    throw new Exception('Wallet mint and unit are immutable after wallet initialization. Create a new store wallet instead.');
                }
            }
        }

`admin.php` —                 // Still true when the old mint is kept as a backup: the balance stays
                // tracked and recoverable, but getUnspentProofs() reads the primary
                // account only, so it does leave this store's spendable view.
                //
                // Keyed on the store, not on one namespace: a store that was moved to a
                // mint it could not reach holds its funds in the namespace it left, and
                // that namespace is still empty until the new mint answers.
                $wouldStrand = ($mintChanged || $unitChanged)
                    && (Config::isStoreWalletInitialized($storeId) || Config::storeHasHeldFunds($storeId));

**Supporting evidence**

- `includes/config.php:432` — walletAccountIsInitialized looks only at the namespace (mint_url, mint_unit, wallet_account_id) and returns false when that namespace has neither a seed fingerprint nor wallet data (line 442: $storage->getSeedFingerprint() !== null || $storage->hasWalletData()).
- `cashu-wallet-php/CashuWallet.php:3949` — deriveWalletId hashes mint_url:unit[:account:storageIdentity], so a mint or unit change moves the store to a different namespace. (Line number verified against the submodule commit 7751f34 recorded by the target; the submodule worktree carries unrelated uncommitted changes.)
- `admin.php:1355` — $wouldStrand is derived from the same namespace-scoped predicate ($mintChanged || $unitChanged) && Config::isStoreWalletInitialized($storeId), and it gates both the 409 mint_change_strands_funds warning (admin.php:1389) and the allowMintChange argument passed to Config::updateStore (admin.php:1419), so in the empty-namespace state the warning and the operator's confirmation are both skipped for a further change.
- `includes/invoice.php:600` — initializeWalletForStore calls $wallet->loadMint() before initializeForRestore, so a mint or unit that cannot be used leaves the new namespace without a fingerprint - the state in which the seed becomes replaceable. Wallet::loadMint() throws when the mint has no active keyset for the unit (cashu-wallet-php/CashuWallet.php:6859-6868).
- `admin.php:1435` — The failure to reach the new mint is caught and reported as a successful save with a warning ('The new mint is saved, but this server could not reach it to finish setting up'), so the store is left pointing at an empty namespace while the operator believes the change was applied.
- `includes/config.php:313` — Independent local reproduction with dummy data (the repository's own fixtures: tests/mock_mint_router.php, tests/mint_switch_payment.php, tests/integration_keyset_v2.php). CASE 1 - a store initialised at (mint-a, sat, acct): a seed change and a mint change without consent were both REFUSED ('Wallet seed, mint, and unit are immutable after wallet initialization'). CASE 2 - after the confirmed move to a mint that cannot be reached, Config::isStoreWalletInitialized answered false and Config::updateStore($storeId, ['seed_phrase' => <other seed>]) was accepted with no exception; the stored seed had changed. CASE 3 - a deterministic variant: a confirmed unit change to a unit the mint does not serve (admin.php:1325-1327 does not test the mint for a unit change) makes Invoice::readyStoreWallet throw 'No active keysets for unit usd', isStoreWalletInitialized answers false, and the seed replacement is again accepted with the stored seed changed.

---

### 4.25 The late-payment recovery nulls the only link to a paid mint quote on a single, possibly stale reading of the mint's quote row, so a paid invoice can never be collected

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `invoice.recoverExpiredPaidInvoices.quoteDiscard` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `includes/background_runner.php:120` | The 'recover_expired_paid' task calls Invoice::recoverExpiredPaidInvoices() (includes/background_runner.php:120-123). It is the only site that discards a quote id: pollSingleQuote and pollPendingQuotes never write invoices.quote_id, so the discard is reachable only when the background runner runs - cron.php:77, wordpress/cashupay.php:46, or the admin's 'Run now' (admin.php:1111). The task polls invoices with status IN ('Expired','Invalid') AND quote_id IS NOT NULL (includes/invoice.php:991-999). |
| 2 | `propagation` | `includes/invoice.php:1015` | $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']) reads the mint's view of the quote (cashu-wallet-php/CashuWallet.php:7352-7356). A NUT-04 mint raises amount_paid only when its own payment watcher has processed the customer's Lightning payment, so a quote that was paid can still be reported with amount_paid = 0 for a while. The library itself knows such readings can be stale: MintQuote::isStaleComparedTo() (CashuWallet.php:2114-2123) exists to stop an older observation from replacing newer quote data. |
| 3 | `propagation` | `includes/invoice.php:890` | quoteIsDefinitelyUnpaid() treats amountPaid === 0 && amountIssued === 0 (when both accounting fields are present) or a literal 'UNPAID' state as proof that nothing was paid. It is one observation of a field the mint updates asynchronously, and it is used to justify a one-way door. |
| 4 | `propagation` | `includes/invoice.php:1038` | Database::update('invoices', ['quote_id' => null], 'id = ?', [$invoice['id']]) erases the only durable link to the paid quote. The WHERE clause is the invoice id only, so it also fires for an invoice another worker has meanwhile claimed ('Processing') or settled. No log line and no last_poll_state are written, so the write-off is invisible. |
| 5 | `sink` | `includes/invoice.php:994` | After the discard no path in the application can collect the payment: recoverExpiredPaidInvoices selects only rows with quote_id IS NOT NULL (:994), pollSingleQuote returns immediately when the invoice has no quote id (:793), pollPendingQuotes requires quote_id IS NOT NULL (:401) and recoverOrphanedInvoices skips an invoice without one (:940). The store-deletion blocker no longer counts it (includes/store_safety.php:47) and cleanupInvoices() deletes the row after 90 days (includes/background_runner.php:843-851). |

**Root cause.** The decision to give up on a quote is taken from a single, non-idempotent observation of third-party state. Invoice::recoverExpiredPaidInvoices() compares the mint's quote expiry with the local clock and treats amount_paid === 0 && amount_issued === 0 (or a literal 'UNPAID' state) from quoteIsDefinitelyUnpaid() as proof that nothing was paid, and then clears invoices.quote_id with a WHERE clause of 'id = ?' only - it does not check that the invoice is still Expired/Invalid and unclaimed. The mint's quote row is not an authoritative statement about the Lightning payment: the money can have arrived while the accounting columns still read zero. The same value is used asymmetrically: a 'PAID' reading is acted on irreversibly (mint the tokens), while a 'UNPAID' reading is acted on by destroying the only recovery handle, which is the one-way door.

**Intended behaviour.** A payment that has reached the mint must always remain collectable. The quote id should only be given up when the mint's answer is authoritative for the payment, not merely for the quote's own lifetime, and it should never be erased from an invoice that is claimed or settled. Where the server does decide that a quote is finished, the decision should be visible to the operator rather than silent.

**Lower-trust principal and starting capability.** None. The payment is made by an anonymous payer at the mint; nothing on the server side is required of the payer, and the loss is discovered by the operator through a missing payment.

**Conditions.**

- *(timing_dependency)* The customer's payment must reach the mint close enough to the mint-side quote expiry that the mint's quote row still reads amount_paid = 0 when the server first polls that invoice after the expiry. The server polls an expired invoice at most once a minute inside the 72 h recovery window (recoverExpiredPaidInvoices' default minInterval is 60) and the batch limit is 10 rows per run, so the window is the cron interval plus any backlog - normally a minute, longer when the cron is infrequent or many invoices expire together. The mint's watcher must lag by more than that.
- *(third_party_dependency)* A NUT-04 mint whose payment watcher updates the quote row asynchronously from the actual Lightning payment (for example a mint whose Lightning backend is slow or restarting). The repository's own mock mint models exactly this shape: /v1/mint/quote/bolt11 returns amount_paid = 0 and amount_issued = 0 until its /__control/pay endpoint flips amount_paid to the quote amount (tests/mock_mint_router.php:181-189, :272-288).
- *(data_state)* The invoice must be Expired or Invalid with a quote id, and the mint's quote expiry must already have passed. The local invoice expiry and the mint quote expiry coincide because both are taken from the quote (includes/invoice.php:197, :214, :223), so this is the normal state of every expired invoice.
- *(authentication_level)* None. The payment is made by an anonymous payer at the mint; nothing on the server side is required of the payer, and the loss is discovered by the operator through a missing payment.
- *(environmental_dependency)* The background runner's 'recover_expired_paid' task must run at least once after the invoice expires - cron.php, WP-Cron (wordpress/cashupay.php:46) or the admin's 'Run now' (admin.php:1111). The payment page and the Greenfield GET alone cannot cause the discard: pollSingleQuote() only handles 'New' and 'Processing' invoices (includes/invoice.php:798) and never writes invoices.quote_id.

**Bounded, target-native reproduction**

```text
attacker perspective: An anonymous payer who pays a Lightning quote right around its expiry, against a mint whose payment watcher is slow to update the quote row; the payment is then held at the mint with no way for the server to claim it. Equivalently, a mint that reports a paid quote as unpaid keeps the customer's money instead of issuing ecash for it, and the server helps it by dropping the claim.

payload: tests/mock_mint_router.php (repository fixture) served on 127.0.0.1 with MOCK_MINT_STATE=<scratch>/mint.json
payload: Invoice::create('s1', ['amount' => 21, 'currency' => 'sat']) - the shop creates the invoice, the mint issues quote <id> with expiry = now + 900
payload: Database::update('invoices', ['status' => 'Expired', 'expiration_time' => time() - 10], 'id = ?', [$invoiceId]) - the invoice expires locally
payload: fixture edit of the mock mint's state file: quotes[<id>].expiry = time() - 60 - the mint-side quote expiry passes (equivalent to waiting out the 15-minute quote)
payload: Invoice::recoverExpiredPaidInvoices(0) - the recovery polls the quote while the mint still reports amount_paid = 0
payload: POST http://127.0.0.1:<port>/__control/pay {"quote": "<id>"} - the customer pays; the mint's quote now reads amount_paid = 21
payload: Invoice::recoverExpiredPaidInvoices(0); Invoice::pollSingleQuote($id, true); Invoice::pollPendingQuotes(0, 10); Invoice::recoverOrphanedInvoices() - every collection path is re-run
payload: control case: the same sequence with the mint-side quote expiry left in the future
payload: control case: the same sequence with the mint-side quote expiry passed but the mint already reporting the payment

steps:
  - Run the recorded check inside the sandbox: sbx.sh v5-quotediscard --loopback -- bash -c 'php /Users/juraj/security-audit-skill/cashupayserver/run-1/agents/v5-quotediscard/scratch/check-quote-discard-v5.php'. The check writes only to its own scratch data directory (CASHUPAY_DATA_DIR under the sandbox's TMPDIR), starts the repository's own mock mint on loopback, and uses a dummy store, a dummy seed phrase and dummy 21-sat amounts. No file in the target repository is modified. The full transcript and the check source are in the artefact check-quote-discard-v5.txt.

observed:
  Case A: after the first recoverExpiredPaidInvoices(0) the invoice read status=Expired quote_id=NULL; the customer then paid and the mint's quote read amount_paid=21; after re-running recoverExpiredPaidInvoices(0), pollSingleQuote($id, true), pollPendingQuotes(0, 10) and recoverOrphanedInvoices() the invoice was still status=Expired quote_id=NULL and Invoice::getBalance('s1') was 0, and recoverOrphanedInvoices() returned 0. The check also confirmed that the invoice's expiration_time equals the mint quote's expiry. Case A2 (the payment was already recorded at the mint and only the quote row lagged): the same discard happened and the payment was never collected. Case B (control, mint-side quote expiry still in the future): the quote id was kept, the same late payment was collected, the invoice reached status=Settled and the balance was 21. Case C (control, mint-side quote expiry passed but the mint already reports the payment): the expired invoice was minted and settled and the balance was 42, so an expired quote is still collectable and the discard is the sole cause of the loss. Case D: with another worker claiming the invoice ('Processing') during the mint round-trip, the quote id was nulled anyway and the orphan recovery could no longer finish that invoice.
```

**Impact.** When it happens the loss is permanent and silent: the customer's payment stays at the mint (locked to the store's own wallet key by NUT-20), the invoice stays Expired, no webhook fires, the shop never receives the order, the store's balance never moves, no diagnostic is produced and the row is deleted after 90 days. The amount is one invoice and the money was never minted, so the operator's ecash balance is unaffected - but a customer's money is gone for good and the shop has no way to know.

**Likelihood.** Requires the mint's payment watcher to lag the customer's payment past the server's first poll of that invoice after the quote expiry (normally about a minute, longer with an infrequent cron or a backlog of expiring invoices), and the customer to pay close enough to the expiry. A mint that updates its quote row synchronously, or a customer who pays well before the expiry, never reaches the window.

**Why this priority.** The mechanism is fully visible in the source, the one-way door is confirmed by reading every collection path, and the bounded check against the repository's own mock mint reproduces the loss, isolates the cause with two controls (the same payment is collected when the mint's quote expiry has not passed and when the mint already reports the payment), and demonstrates the status-independent write. The only assumption outside the source - that a mint's quote row can lag a payment it has already received - is the documented NUT-04 behaviour and is modelled by the repository's own fixture, which reports amount_paid = 0 until its payment endpoint is called.

**Smallest source fix.** Never destroy the only link to a payment on a single observation of the mint's quote row. Require the mint-side quote to have been expired for a long time before the link is dropped, require the invoice to still be Expired/Invalid and unclaimed at that moment (put the status in the WHERE clause so a concurrent claim or settlement is not overwritten), and leave a visible trace of the write-off so the operator can see that an invoice was written off. The strongest form keeps the quote id in a separate column (for example discarded_quote_id) so the payment stays technically collectable and the row stays auditable, instead of only shortening the window.

`includes/invoice.php` —     /**
     * How long a mint quote must have been expired before the server stops believing it
     * can still be paid.
     *
     * A NUT-04 mint raises a quote's amount_paid only when its own payment watcher has
     * processed the customer's Lightning payment, so a quote that was paid can still read
     * amount_paid = 0 for a while after the money arrived. One 'nothing was paid' answer is
     * therefore not proof, and the quote id is the only link to a payment that has not been
     * minted yet. A week of the quote being expired is long enough for any sane watcher to
     * catch up, and it still lets an old, clearly dead invoice be cleaned up.
     */
    const UNPAID_CONFIRMATION_DELAY = 7 * 24 * 3600;

    // ... in recoverExpiredPaidInvoices(), replace the else-if body:
                } elseif ($quoteStatus->expiry !== null
                          && $quoteStatus->expiry < $now - self::UNPAID_CONFIRMATION_DELAY
                          && self::quoteIsDefinitelyUnpaid($quoteStatus)
                          && in_array($invoice['status'], ['Expired', 'Invalid'], true)) {
                    // The mint's own quote has been expired for a week and the mint says
                    // plainly that nothing was paid: this claim is resolved and the row may
                    // be cleaned up by age from now on. Anything less than that plain answer
                    // (no state, PENDING, an unknown word) keeps the quote: dropping the only
                    // link to a payment is a one-way door.
                    //
                    // Only while the invoice is still unpaid and unclaimed: another worker
                    // may be minting it (status 'Processing') or may already have settled it.
                    // The status is part of the WHERE clause, so a claim that landed while
                    // this worker was waiting for the mint keeps its quote.
                    Database::update(
                        'invoices',
                        ['quote_id' => null, 'last_poll_state' => 'written off as unpaid'],
                        "id = ? AND status IN ('Expired', 'Invalid')",
                        [$invoice['id']]
                    );
                }

`includes/database.php` —     // ... in the invoices table definition and the migration that adds columns
    // (see addColumnIfMissing() usage for last_poll_state / last_poll_error):
    //     written_off_at INTEGER,
    //     write_off_reason TEXT,
    //
    // 'last_poll_state' carries the mint's own last word, so the write-off deserves its
    // own fields; the admin's stuck-invoice view (admin.php:158-166) can then list the
    // invoices that were written off, so the operator can see that a customer paid and the
    // server gave up on it.

**Supporting evidence**

- `includes/invoice.php:1031` — The else-if that discards the quote: $quoteStatus->expiry !== null && $quoteStatus->expiry < $now && self::quoteIsDefinitelyUnpaid($quoteStatus). The condition is satisfied by a quote the mint has not yet noticed as paid, which is exactly the state a lagging payment watcher produces. Because the invoice's expiration_time is the quote's own expiry (:197), this is true for every expired invoice the recovery handles.
- `includes/invoice.php:1038` — Database::update('invoices', ['quote_id' => null], 'id = ?', [$invoice['id']]) - the discard. The WHERE clause is only the invoice id, so it also fires for an invoice that another worker has meanwhile claimed ('Processing').
- `includes/invoice.php:994` — recoverExpiredPaidInvoices() selects only rows with quote_id IS NOT NULL, so after the discard the very same function can no longer look at the invoice - the loss is permanent, not a delay.
- `includes/invoice.php:793` — pollSingleQuote() returns immediately when the invoice has no quote_id, so the payment page (payment.php:40) and the Greenfield GET (includes/api/invoices.php:121) cannot collect it either.
- `includes/invoice.php:940` — recoverOrphanedInvoices() skips an invoice without a quote id, so the 'finishing interrupted payments' task cannot help either.
- `includes/invoice.php:401` — pollPendingQuotes() requires quote_id IS NOT NULL, so the normal quote poller skips the discarded invoice as well.
- `includes/background_runner.php:848` — cleanupInvoices() deletes rows older than 90 days whose status is Expired/Invalid and whose quote_id is empty, so the discarded row (and the only trace of the payment) is removed from the ledger.
- `includes/background_runner.php:120` — The 'recover_expired_paid' background task is the only caller of Invoice::recoverExpiredPaidInvoices(); it runs from cron.php, WP-Cron and the admin's 'Run now' (admin.php:1111).
- `includes/invoice.php:197` — The invoice's expiration_time is taken from the mint quote's expiry ($expiration = $quote->expiry ?? ...), so the local expiry and the mint-side quote expiry coincide and the dangerous first poll happens immediately after that moment.
- `includes/invoice.php:32` — The code's own stated expectation contradicts the discard: 'A quote paid before expiry while cron was down is mintable long afterwards; giving up at 72 hours made those sats unrecoverable for no reason. Checks continue daily until the mint gives a terminal answer.' The recovery is designed to keep looking for days, yet the quote is dropped on the first poll after the mint's quote expiry - and because the invoice's expiry IS the quote expiry, the 72 h (EXPIRY_RECOVERY_GRACE, :27) and 24 h (EXPIRY_RECOVERY_SLOW_INTERVAL, :36) re-check windows never apply to an expired invoice at all.
- `includes/store_safety.php:47` — Store deletion is blocked while invoices still carry a quote id ('invoice(s) whose Lightning quote may still be paid'); after the discard that blocker disappears, so the store can even be removed while the customer's payment is still at the mint.
- `admin.php:158` — The dashboard's stuck-invoice view only lists 'Processing' invoices older than an hour and 'New' invoices with a poll error, so an Expired invoice whose quote was discarded leaves no operator-visible trace; the discard branch writes no log line and no last_poll_state.
- `cashu-wallet-php/CashuWallet.php:2129` — MintQuote::isPaid()/isIssued() read the same amount_paid/amount_issued fields; isStaleComparedTo() at :2114 shows the library treats these readings as potentially stale, yet the server treats the zero reading as final.
- `cashu-wallet-php/CashuWallet.php:7079` — requestMintQuote() locks each quote to a deterministic pubkey of the store's wallet when the mint supports NUT-20 (the repository's mock mint enforces the signature, tests/mock_mint_router.php:314-322), so a paid-but-unminted quote can only be claimed by that store's own wallet - the discarded payment is stuck at the mint for good.
- `tests/mock_mint_router.php:181` — The repository's own fixture: POST /__control/pay marks a mint quote as paid (amount_paid = amount), while a fresh quote is created with amount_paid = 0 and amount_issued = 0 (:272-288) and expiry = time() + 900. The fixture therefore models exactly the asynchronous shape the server misreads.
- `agents/v5-quotediscard/scratch/check-quote-discard-v5.txt:1` — Independent bounded check against the repository's own mock mint (dummy store, dummy seed, 21-sat invoices). Case A: with the mint-side quote expiry passed and the mint reporting amount_paid = 0, the recovery nulled quote_id; after the payment became visible at the mint (amount_paid = 21) the invoice stayed Expired with a store balance of 0 after recoverExpiredPaidInvoices(), pollSingleQuote(), pollPendingQuotes() and recoverOrphanedInvoices() were all re-run. Case A2: with the payment already recorded at the mint but its quote row still reading 0/0 (the watcher lag), the same discard happened and the payment was never collected. Case B (control, mint-side expiry not passed): the quote id was kept and the same late payment was collected (Settled, balance 21). Case C (control, mint-side expiry passed but the mint already reports the payment): the expired invoice WAS minted and settled (balance 42), so the quote expiry itself does not block collection - the discard does. Case D: with another worker claiming the invoice ('Processing') while the recovery waited for the mint's answer, the quote id was nulled anyway and the orphan recovery could no longer finish that invoice.

---

### 4.26 Lightning-address resolution and the callback URL it returns are fetched with no private-address check

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `lnurl-lightning-address-host-not-vetted-for-private-addresses` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:1826` | LightningAddress::getInvoice($destination, $amountSats, 'CashuPayServer withdrawal'); - the destination is whatever the operator typed. The same call exists at admin.php:1683 (get_withdrawal_estimate) and, through the cron, at includes/lightning_address.php:80 (meltToAddress) reached from includes/background_runner.php:751 -> checkAutoMelt (lightning_address.php:297) -> autoMeltAccount (lightning_address.php:451) with the stored auto_melt_address. |
| 2 | `propagation` | `includes/lightning_address.php:35` | resolve() forwards to Cashu\LightningAddress::resolve, which accepts anything matching the address format (CashuWallet.php:9950) and builds https://{domain}/.well-known/lnurlp/{user} at CashuWallet.php:9977 with no address check; fetchJson (CashuWallet.php:10101) fetches it with only the scheme check at CashuWallet.php:10103. |
| 3 | `propagation` | `cashu-wallet-php/CashuWallet.php:9990` | The callback URL the LNURL server returned is accepted if isSafeCallbackUrl (CashuWallet.php:10076) says so - scheme https (or http for .onion) and no credentials - with no check of the address it names, and it is returned to the caller as $metadata['callback']. |
| 4 | `sink` | `cashu-wallet-php/CashuWallet.php:10054` | $data = self::fetchJson($callbackUrl, 15); - the server fetches the URL the third party chose. fetchJson (CashuWallet.php:10101) sets CURLOPT_FOLLOWLOCATION false and a 256 KiB cap, but no address vetting and no CURLOPT_RESOLVE; the response's 'reason' or 'message' field is then thrown as operator-visible text at CashuWallet.php:10056-10058 and relayed by admin.php:1904-1907. |

**Root cause.** The Lightning-address path in the wallet library was written with a scheme-only check (isSafeCallbackUrl) instead of the shared Security::resolvePublicTarget gate, and the well-known URL it builds from the address is not checked at all. Nothing in includes/lightning_address.php, admin.php's manual_melt / get_withdrawal_estimate, or the cron auto-withdrawal path vets the resolved address of either URL.

**Intended behaviour.** Before the server fetches a Lightning-address host or a callback URL, both are vetted with the shared gate (Security::resolvePublicTarget / Security::isSafePublicHttpUrl) so a loopback, LAN, link-local, cloud-metadata or CGNAT address is refused, and the connection is pinned to the vetted addresses - the same treatment the webhook path gets.

**Lower-trust principal and starting capability.** manual_melt and get_withdrawal_estimate need the operator session and CSRF token; the auto-withdrawal path needs the cron key, which the operator obtained by opting into auto-withdrawal to a Lightning address. The operator must ask to be paid to a Lightning address (or configure auto-withdrawal to one) - a normal shop action, not an unusual one - and after that the cron repeats it without further action.

**Conditions.**

- *(authentication_level)* manual_melt and get_withdrawal_estimate need the operator session and CSRF token; the auto-withdrawal path needs the cron key, which the operator obtained by opting into auto-withdrawal to a Lightning address.
- *(third_party_dependency)* The callback URL is chosen entirely by the LNURL/Lightning-address server, which the project's threat model lists as an untrusted remote server. The operator's typed address only names the first hop; the second hop is the third party's choice and is never shown to the operator.
- *(user_interaction)* The operator must ask to be paid to a Lightning address (or configure auto-withdrawal to one) - a normal shop action, not an unusual one - and after that the cron repeats it without further action.
- *(network_routing)* The server must be able to reach the private target, the target must present a certificate that validates for the requested name (the fetch runs with TLS verification on), and it must answer 200 with a JSON body for the relayed field to be read back.

**Bounded, target-native reproduction**

```text
attacker perspective: The LNURL/Lightning-address server the operator names (or a third party that controls the callback it returns); the operator only supplies the address to pay.

payload: Lightning address: user@127.0.0.1
payload: callback returned by that server: https://127.0.0.1:18192/lnurlp-callback
payload: callback variant: https://169.254.169.254/latest/meta-data/iam/security-credentials/

steps:
  - Make a throwaway local certificate for 127.0.0.1 and start a local HTTPS proxy (php fixture_proxy.php, port 18192) that logs every CONNECT, terminates TLS with that certificate and answers JSON; point the app's outbound https requests at it with https_proxy=http://127.0.0.1:18192 and curl.cainfo pointing at the throwaway certificate (so the app's own certificate verification still runs).
  - In a separate process run the app code with CASHUPAY_DATA_DIR in a scratch directory and call Cashu\LightningAddress::resolve('user@127.0.0.1') and Cashu\LightningAddress::getInvoice('user@127.0.0.1', 1, 'local check'), printing the proxy log and the exception text.
  - For the contrast, print Security::isSafePublicHttpUrl() for the same targets and Cashu\LightningAddress::isSafeCallbackUrl() for the same targets.

observed:
  isSafePublicHttpUrl(https://127.0.0.1:18192/lnurlp-callback) = false, isSafePublicHttpUrl(https://169.254.169.254/latest/meta-data/iam/security-credentials/) = false, isSafePublicHttpUrl(https://127.0.0.1/) = false, isSafePublicHttpUrl(http://127.0.0.1:6379/) = false - but LA::isValid('user@127.0.0.1') = true and LA::isSafeCallbackUrl returns true for the loopback callback and for the cloud-metadata https URL. End to end, LA::resolve('user@127.0.0.1') caused the server to fetch the loopback well-known URL (proxy log: 'CONNECT 127.0.0.1:443', 'TUNNEL GET /.well-known/lnurlp/user') and the callback URL that server handed back was then fetched from loopback as well ('CONNECT 127.0.0.1:18192', 'TUNNEL GET /lnurlp-callback?amount=1000'); LA::getInvoice raised 'Lightning address error: INTERNAL-DATA-ONLY-REACHABLE-FROM-LOOPBACK' - the internal response body relayed into the operator-visible text. The app's own wrapper includes/lightning_address.php::resolve behaves identically. (Bounded run, command and limits in the artifact check-lnurl-lightning-address-ssrf.txt.)
```

**Impact.** The server issues requests to addresses inside its own network chosen by a third party, but the effect is narrow: only https callbacks are fetched (plain http is refused, so the usual cloud metadata endpoint is not reachable), TLS verification still runs (so an internal service without a publicly trusted certificate for the requested name is not reached), and the response's 'reason'/'message' field is relayed into the operator-visible error, not back to the third party, who never sees the response. It does not by itself move funds, and the well-known path and the amount check limit what can be misused.

**Likelihood.** Paying a Lightning address is an ordinary shop action, and once auto-withdrawal is configured the cron repeats the resolution and the fetch on every run. The first hop only needs a dotted host the address format accepts; the second hop is entirely the third party's choice.

**Why this priority.** The whole path is in the current source, the shared gate's own docstring names Lightning-address hosts as something it must cover, and the bounded local check shows the server making both requests to loopback addresses the shared gate refuses, with the internal response body relayed into the operator-visible error.

**Smallest source fix.** Vet both URLs with the shared gate before fetching them and pin the connection to the vetted addresses. Keep the .onion exception by resolving .onion through the deployment's Tor proxy instead of exempting the address check; if there is no Tor, drop the exception.

`cashu-wallet-php/CashuWallet.php` —     /**
     * LUD-01: LNURL endpoints are HTTPS, except .onion hosts which may use HTTP.
     * The host must also be a public address: a plain-HTTP internal host, a loopback
     * address or the cloud metadata address is a way to turn an attacker-supplied
     * callback into a request from this server.
     */
    public static function isSafeCallbackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'http' && str_ends_with($host, '.onion')) {
            return true;   // .onion is resolved by the Tor proxy, never by DNS
        }
        if ($scheme !== 'https') {
            return false;
        }
        // The address the name resolves to must be public.
        $target = Security::resolvePublicTarget($url);   // the app's shared gate
        return $target !== null;
    }

`includes/lightning_address.php` —     /**
     * Resolve Lightning address to LNURL-pay metadata.
     *
     * The host in the address names the first hop and is vetted with the same gate
     * the mint URLs use, so a Lightning address cannot point the server at its own
     * private network.
     */
    public static function resolve(string $address): ?array {
        if (!CashuLightningAddress::isValid($address)) {
            return null;
        }
        [, $domain] = explode('@', $address, 2);
        require_once __DIR__ . '/security.php';
        if (Security::resolvePublicTarget('https://' . $domain) === null) {
            throw new Exception('That Lightning address points inside this server\'s own network.');
        }
        return CashuLightningAddress::resolve($address);
    }

**Supporting evidence**

- `cashu-wallet-php/CashuWallet.php:9977` — $url = "https://{$domain}/.well-known/lnurlp/" . rawurlencode($username); - the host comes from the address and is never checked against the private ranges; the address format (CashuWallet.php:9950) accepts 'user@127.0.0.1' (verified at runtime: true).
- `cashu-wallet-php/CashuWallet.php:10076` — isSafeCallbackUrl tests only the scheme and the absence of credentials; it accepts https://127.0.0.1:18192/lnurlp-callback and https://169.254.169.254/latest/meta-data/... (both verified true), and refuses file:///etc/passwd.
- `cashu-wallet-php/CashuWallet.php:10058` — throw new CashuException("Lightning address error: " . ...) - the fetched body's own 'reason'/'message' field is relayed into the message the operator sees.
- `includes/security.php:209` — The shared gate's docblock names 'Lightning-address hosts' among the URLs it is meant to protect; Security::isSafePublicHttpUrl / resolvePublicTarget (security.php:217, 232) appear nowhere in cashu-wallet-php or includes/lightning_address.php - a repository-wide grep finds them only in includes/webhook_sender.php, includes/security.php, api-keys/authorize.php and admin.php:1132.
- `includes/lightning_address.php:451` — autoMeltAccount passes the stored auto_melt_address to meltToAddress, so the cron repeats the resolution and the fetch on every background run without any operator action at that moment; admin.php:1739 only checks the address with a format regex before storing it.
- `admin.php:1907` — echo json_encode(['error' => $errorMsg]); - the exception text, including the relayed third-party field, is returned to the operator.

---

### 4.27 The admin 'test_mint' action fetches an operator-supplied mint URL without the mint-URL anti-SSRF gate

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `mint-url-ssrf-gate-bypassed-by-test-mint-action` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `admin.php:2505` | $mintUrl = $_POST['mint_url'] ?? ''; - the operator-supplied address enters the action with no address validation (empty check only, admin.php:2506). |
| 2 | `propagation` | `admin.php:2509` | Config::testMintConnection($mintUrl) is called directly and its result is echoed at admin.php:2510; MintHelpers::assertSafeMintUrl is not called anywhere on this path, unlike admin.php:1185, admin.php:1317, admin.php:2404 and setup.php:496. |
| 3 | `propagation` | `includes/config.php:612` | The only check is Security::sanitizeUrl($mintUrl) === null, which validates the http(s) scheme and says nothing about the address (includes/security.php:181-195). The success branch at includes/config.php:618 returns the fetched body as 'info'. |
| 4 | `sink` | `cashu-wallet-php/CashuWallet.php:3761` | curl_exec performs the GET to {mintUrl}/v1/info (URL built at CashuWallet.php:3727, CURLOPT_URL at 3733) against whatever host and port the operator named, with no CURLOPT_RESOLVE and no private-address refusal; the decoded body is returned to the caller as 'info' (includes/config.php:618 -> admin.php:2510). |

**Root cause.** The anti-SSRF gate lives in the caller (the action is expected to call MintHelpers::assertSafeMintUrl before Config::testMintConnection) instead of in the function that actually fetches, and one caller forgot it. Config::testMintConnection's own check is Security::sanitizeUrl, which validates the http(s) scheme and nothing about the address, so the path that skips the gate has no address control at all.

**Intended behaviour.** Every URL the server fetches on the operator's behalf is vetted by MintHelpers::assertSafeMintUrl first, so a mint address that resolves to a private, loopback, link-local, CGNAT or reserved address is refused with a message the operator can act on, exactly as the sibling actions create_store, update_store, add_backup_mint and the setup wizard do.

**Lower-trust principal and starting capability.** The action requires an authenticated operator session (admin.php:783) and a valid per-session CSRF token (admin.php:791); it is not reachable by an unauthenticated visitor or by an API key. A holder of that session and token already has strictly stronger capabilities (export_token, seed reveal, manual_melt), so the SSRF adds a marginal capability rather than a new one. The operator must submit the action with the chosen URL. No control in the current source posts 'action=test_mint', so the request has to be hand-crafted (devtools/curl) with the session cookie and CSRF token - the 'paste the mint address from a support mail' flow does not apply to this action.

**Conditions.**

- *(authentication_level)* The action requires an authenticated operator session (admin.php:783) and a valid per-session CSRF token (admin.php:791); it is not reachable by an unauthenticated visitor or by an API key. A holder of that session and token already has strictly stronger capabilities (export_token, seed reveal, manual_melt), so the SSRF adds a marginal capability rather than a new one.
- *(user_interaction)* The operator must submit the action with the chosen URL. No control in the current source posts 'action=test_mint', so the request has to be hand-crafted (devtools/curl) with the session cookie and CSRF token - the 'paste the mint address from a support mail' flow does not apply to this action.
- *(system_configuration)* No opt-in is required: the allow_private_mints setting only affects the paths that call the gate, and this path never calls it.
- *(network_routing)* The server must be able to reach the internal target and the target must answer 200 with a JSON body for the body to be returned in 'info'; the URL suffix /v1/info constrains the path but not the host or the port.

**Bounded, target-native reproduction**

```text
attacker perspective: A holder of the operator's dashboard session and CSRF token (the operator themselves, or an attacker who already has that session).

payload: mint_url = http://127.0.0.1:18191/ (a loopback address the shared gate refuses)
payload: mint_url = http://169.254.169.254/ (cloud metadata, any port)

steps:
  - Start a dummy loopback fixture that answers any path with JSON: php -d opcache.enable=0 -S 127.0.0.1:18191 fixture_router.php (it returns {"name":"internal-only-service","version":"9.9.9","secret_note":"INTERNAL-DATA-ONLY-REACHABLE-FROM-LOOPBACK"} and appends every request line to fixture-hits.log).
  - In a separate process, define CASHUPAY_DATA_DIR to a scratch directory, require includes/mint_helpers.php, includes/config.php and cashu-wallet-php/CashuWallet.php, and run: (a) MintHelpers::assertSafeMintUrl('http://127.0.0.1:18191/') and (b) Config::testMintConnection('http://127.0.0.1:18191/') - the exact function admin.php:2509 calls - printing both results.
  - Compare: the gate refuses the address; testMintConnection connects and returns the fixture's JSON in 'info'. The fixture log shows 'GET /v1/info host=127.0.0.1:18191'.

observed:
  gate: refused -> 'That address points to a private or local network, not a public mint. Use a mint on the internet (its address usually starts with https://).' / testMintConnection: {"success":true,"error":null,"info":{"name":"internal-only-service","version":"9.9.9","secret_note":"INTERNAL-DATA-ONLY-REACHABLE-FROM-LOOPBACK","seen_path":"/v1/info"}} / fixture hits: ["GET /v1/info host=127.0.0.1:18191"]. The same URL is refused by the shared gate and fetched anyway by the function the action calls, and the internal response body is handed back to the caller in the 'info' field. (Bounded run, command and limits in the artifact check-mint-url-ssrf-gate.txt.)
```

**Impact.** The server can be made to issue an HTTP GET to loopback, LAN, link-local or cloud-metadata addresses on any port, and the JSON body of the answer is returned to the caller in 'info'. That crosses from the operator's authority into the server's own network, which the project treats as a separate boundary, but the /v1/info path suffix and the JSON-only read-back limit what can be extracted.

**Likelihood.** The action needs an authenticated operator session and a per-session CSRF token, and in the current source no dashboard control posts 'action=test_mint' - the request has to be hand-crafted. The original 'the operator pastes a mint address from a support mail' scenario does not apply here, and a holder of that session and token already has strictly stronger powers (export_token, seed reveal, manual_melt).

**Why this priority.** The source path is short and complete at this commit, the missing gate is visible by a repository-wide grep of assertSafeMintUrl, and the bounded local check shows the same URL being refused by the gate and fetched anyway by the function the action calls, with the internal response body returned.

**Smallest source fix.** Put the gate in the function that fetches, so no caller can forget it: make Config::testMintConnection reject a private address itself (it already receives the URL), and have the test_mint action go through the same gate as the other actions.

`includes/config.php` —     public static function testMintConnection(string $mintUrl): array {
        // Defence in depth: the gate lives here, so every caller is vetted even if a
        // new action forgets to call it.
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
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }

`admin.php` —         case 'test_mint':
            try {
                $mintUrl = $_POST['mint_url'] ?? '';
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }
                // The server is about to fetch this address: same gate as every other
                // mint-URL path, so a private address cannot be probed from here.
                $mintUrl = MintHelpers::assertSafeMintUrl((string)$mintUrl);
                $result = Config::testMintConnection($mintUrl);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

**Supporting evidence**

- `admin.php:2509` — case 'test_mint' calls Config::testMintConnection($mintUrl) with no call to MintHelpers::assertSafeMintUrl.
- `admin.php:2404` — The sibling action add_backup_mint calls MintHelpers::assertSafeMintUrl((string)$mintUrl) before Config::testMintConnection at 2407 - the control the test_mint path is missing.
- `admin.php:1317` — The sibling action update_store calls MintHelpers::assertSafeMintUrl before Config::testMintConnection at 1318.
- `admin.php:1185` — The sibling action create_store routes the mint URL through MintHelpers::assertSafeMintUrl as well; a repository-wide grep shows assertSafeMintUrl is called from exactly these three admin actions, from setup.php:496 and from MintHelpers::testExpiry - never on the test_mint path.
- `includes/config.php:612` — Config::testMintConnection's only guard is Security::sanitizeUrl, a scheme check; the fetched JSON body is returned as 'info' at line 618.
- `includes/mint_helpers.php:26` — assertSafeMintUrl is the gate that refuses a host resolving to a private, loopback, link-local, CGNAT or reserved address (lines 37-53); its docblock at lines 13-21 states the intent ('a way to probe the server's private network and read back error text from it').
- `cashu-wallet-php/CashuWallet.php:3727` — MintClient::request builds the request URL as $this->mintUrl . '/v1/' . ltrim($path, '/') and sets no CURLOPT_RESOLVE, so the connection is not bound to any vetted address. Cashu\Wallet uses this same client (CashuWallet.php:5789).
- `includes/security.php:209` — The gate's own docblock names the class of URL it exists for: 'the server fetches an operator- or API-supplied URL (webhooks, mint URLs, Lightning-address hosts)'.
- `admin.php:17` — admin.php already requires includes/mint_helpers.php at the top, so the gate is available to the test_mint action without any new include.
- `admin.php:783` — The action sits behind Auth::initSession()/Auth::isLoggedIn() and the per-session CSRF check at admin.php:791; the CSRF token is 32 random bytes per session (includes/auth.php, generateCsrfToken/validateCsrfToken), so it cannot be guessed or supplied cross-site.

---

### 4.28 Deleting the CashuPay WordPress plugin can leave its API key and webhook signing secret in the options table

**Severity:** LOW &nbsp;·&nbsp; **Fingerprint:** `wordpress.uninstall.gateway-credentials-survive-uninstall` &nbsp;·&nbsp; **Confidence:** high

**Source location and path**

| # | Kind | Location | What happens there |
|---|---|---|---|
| 1 | `entrypoint` | `wordpress/uninstall.php:29` | WordPress deletes the plugin and runs this file, which is supposed to clean up what the plugin wrote into the shop's options. |
| 2 | `propagation` | `wordpress/uninstall.php:26` | The stored btcpay_gf_url is normalised to host+path and compared with site_url('/cashupay'). The comparison fails whenever the merchant re-pointed the gateway at their own BTCPay Server after the one-click configuration, or the site's URL changed. |
| 3 | `propagation` | `wordpress/uninstall.php:31` | delete_option() is skipped for all four options, so btcpay_gf_api_key (the CashuPay store key) and btcpay_gf_webhook (webhook id, target URL and HMAC secret) stay in the options table of a site that no longer runs CashuPay. |
| 4 | `propagation` | `includes/auth.php:278` | The uninstall deliberately does not remove the data directory (wordpress/uninstall.php:53-55), so the api_keys row behind the leftover key survives; Auth::validateApiKey() still resolves the leftover key to the store with its full permission set. |
| 5 | `sink` | `wordpress/uninstall.php:35` | The webhook secret survives in the options table together with the preserved webhooks row it matches. It is the key that signs the delivery the shop's gateway accepts (includes/webhook_sender.php:271-274, 305-310), so a reader of the WordPress database can sign a delivery the shop still verifies with it. Whether the third-party gateway then acts on such a delivery depends on that plugin, whose source is not in this repository. |

**Root cause.** wordpress/uninstall.php decides whether the gateway options belong to CashuPay by string-comparing a URL that a third-party settings page can change, instead of asking the database whether the key and the webhook are this installation's; and it only deletes the WordPress-side copies, never revoking the credential in the preserved CashuPay database.

**Intended behaviour.** Deleting the plugin ends the credentials it created: the store API key is revoked in the CashuPay database, the webhook is disabled there as well, and the WordPress-side copies are removed. The routine states this intent at wordpress/uninstall.php:34 ('The webhook signing secret CashuPay registered; useless without the store above'), but the removal of the two happens as one URL-conditional block instead of two ownership checks.

**Lower-trust principal and starting capability.** The merchant deletes the CashuPay plugin, the action that is expected to end the plugin's credentials; README.md:222 warns that deleting disconnects WooCommerce, so the merchant expects the credentials to be gone.

**Conditions.**

- *(data_state)* The stored gateway URL no longer matches the shop's own CashuPay URL on host+path: the merchant re-pointed the WooCommerce gateway at their own BTCPay Server after the one-click configuration, or the WordPress site URL changed (a domain move, a www change).
- *(user_interaction)* The merchant deletes the CashuPay plugin, the action that is expected to end the plugin's credentials; README.md:222 warns that deleting disconnects WooCommerce, so the merchant expects the credentials to be gone.
- *(third_party_dependency)* The leftover store key only authorises anything while a CashuPay server serves the preserved data directory: in WordPress mode the Greenfield API is served by the plugin itself (wordpress/cashupay.php, wordpress/rewrite-rules.php), so at the moment of the uninstall the key has no live endpoint; it becomes usable again when the plugin is reinstalled on the same data directory or another server is pointed at it. The leftover webhook secret has no such dependency - it signs deliveries to the shop's own webhook endpoint. The read access to the WordPress database is the same as before the uninstall; the amplifier is that the third-party gateway keeps verifying with the secret left in its own option, so a forged delivery would be accepted only if that plugin checks the BTCPay-Sig header - its source is not in this repository.

**Bounded, target-native reproduction**

```text
attacker perspective: A party with read access to the WordPress database (a plugin, a SQL injection, a database backup) that keeps the option table after the merchant deleted the CashuPay plugin, and therefore still finds a valid CashuPay store key and the shop's webhook signing secret.

payload: btcpay_gf_api_key option value read from wp_options after the uninstall
payload: btcpay_gf_webhook option value read from wp_options after the uninstall

steps:
  - In a scratch copy of the repository, define CASHUPAY_DATA_DIR to a scratch directory, stub the WordPress functions, initialise the schema and insert one dummy store.
  - Perform the two steps setup.php performs for one POST of the Configure WooCommerce form (Auth::createApiKey followed by cashupay_configure_btcpay_plugin) so that the options table holds btcpay_gf_url, btcpay_gf_api_key, btcpay_gf_store_id and btcpay_gf_webhook.
  - Include wordpress/uninstall.php with WP_UNINSTALL_PLUGIN defined, once with btcpay_gf_url left at the canonical CashuPay URL and once with it changed to another BTCPay Server's URL.
  - After each run, list the options and call Auth::validateApiKey() with the leftover key.

observed:
  With the canonical URL the option table was emptied and no credential was left. With the changed URL all four options survived the uninstall, the leftover btcpay_gf_api_key still authenticated against the preserved database (store_id store_dummy_shop_1, permissions cancreateinvoice, canviewinvoices, canmodifyinvoices, canviewstoresettings, webhooks.canmodifywebhooks) and the leftover webhook secret still matched the preserved webhooks row.
```

**Impact.** What survives is a valid store credential with invoice and webhook authority plus the key that signs the shop's payment notifications; the read access itself is not widened by the uninstall, and while the plugin is gone the leftover store key has no live endpoint, but the merchant's expectation that deleting the plugin ends those credentials is broken.

**Likelihood.** The trigger is a gateway URL that no longer matches, which needs the merchant to re-point the WooCommerce gateway or the site to move; and the party that can read the leftover credentials needs access to the WordPress database, which it usually had before the uninstall as well.

**Why this priority.** The condition, the conditional cleanup and the preserved database are all source-visible in wordpress/uninstall.php, and the surviving credentials were observed locally against the dummy store; only the third-party gateway's reaction to a forged delivery is outside this repository and is stated as a condition rather than claimed.

**Smallest source fix.** Decide ownership by asking the database instead of by comparing a URL: look the stored key up by its digest and the stored webhook up by its id, revoke both in the CashuPay database, and remove the WordPress-side copies whenever this installation recognises them; keep the URL and store id removal tied to the URL comparison so another server's gateway settings are not touched.

`wordpress/uninstall.php` — $btcpay_url = cashupay_uninstall_normalize_url((string) get_option('btcpay_gf_url', ''));
$cashupay_url = cashupay_uninstall_normalize_url(site_url('/cashupay'));
$url_is_ours = $btcpay_url !== '' && $cashupay_url !== ''
    && ($btcpay_url === $cashupay_url || strpos($btcpay_url, $cashupay_url . '/') === 0);

// The key and the webhook secret are ours if this installation still knows them,
// whatever URL the gateway points at now.
$ours = false;
$key = (string) get_option('btcpay_gf_api_key', '');
if ($key !== '') {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';
    $row = Database::fetchOne('SELECT id FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)]);
    if ($row !== null) {
        try {
            Auth::deleteApiKey($row['id']);
        } catch (Throwable $e) {
            error_log('CashuPayServer: uninstall could not revoke the gateway key: ' . $e->getMessage());
        }
        $ours = true;
    }
}
$webhook = get_option('btcpay_gf_webhook', null);
if (is_array($webhook) && !empty($webhook['id'])) {
    $row = Database::fetchOne('SELECT id FROM webhooks WHERE id = ?', [(string)$webhook['id']]);
    if ($row !== null) {
        Database::update('webhooks', ['enabled' => 0, 'deleted_at' => Database::timestamp()], 'id = ?', [(string)$webhook['id']]);
        $ours = true;
    }
}

if ($ours) {
    delete_option('btcpay_gf_api_key');
    // The webhook signing secret CashuPay registered; useless without the store above.
    delete_option('btcpay_gf_webhook');
}
if ($url_is_ours) {
    delete_option('btcpay_gf_url');
    delete_option('btcpay_gf_store_id');
}

**Supporting evidence**

- `wordpress/uninstall.php:29` — The removal of all four gateway options is conditional on one URL comparison, so the whole cleanup of the plugin's credentials depends on a value a third-party settings page can change after the fact.
- `wordpress/uninstall.php:34` — The comment ties the webhook secret's usefulness to the store id above, but both are removed inside the same URL-conditional block, so the pair survives together whenever the URL no longer matches.
- `wordpress/uninstall.php:53` — The data directory is deliberately kept on uninstall, so the api_keys and webhooks rows that back the leftover credentials survive; the routine revokes nothing in that database.
- `includes/webhook_sender.php:271` — The webhook signature is the standard BTCPay scheme, 'sha256=' . hash_hmac('sha256', $payload, $secret), so a surviving secret is directly usable to sign a delivery.

---

## 5. Needs validation

These are **not findings**. Each is a source-grounded boundary hypothesis whose decisive fact is outside the repository — a deployment, hosting or provider setting. They are listed so the owner can close them with a cheap, read-only check. None carries a severity.

| Title | Repository trace | Exact blocker | Bounded local next step | Safe owner-observed check |
|---|---|---|---|---|
| Greenfield API answers that vary only by the Authorization header are served with no Cache-Control and no Vary | `api.php:261 → api.php:184` | Whether a caching intermediary in front of the operator's site stores and later replays a response to a request carrying Authorization is a deployment fact. The nginx/virtual-host configuration is not in the repository - README.md:145-180 shows only the ope… | Re-run the two recorded fixtures inside the sandbox. (1) 'php bootstrap.php' builds a throwaway install with CASHUPAY_DATA_DIR = scratch/data holding dummy store_a/store_b and two dummy bearer keys… | One owner-observed fact settles it: does anything in front of the operator's site store a response to a request carrying Authorization and later replay it for the same URL? The owner should (a) rea… |
| In WordPress mode Background::trigger() sends the internal cron key to site_url(), which is request-derived when wp-config.php derives WP_SITEURL from the Host header | `api.php:50 → cron.php:45` | Whether the deployment's wp-config.php defines WP_SITEURL (or a theme/plugin 'siteurl', 'option_siteurl' or 'site_url' filter derives the site URL) from $_SERVER['HTTP_HOST'], X-Forwarded-Host or another request-derived value is a deployment fact: the repos… | Already done, non-destructively, and reproducible as agents/v9-wpcron/scratch/wpcron_probe.php (4-case matrix, artifact check-wpcron-origin.txt): real includes/background.php + includes/urls.php + … | Owner-observed only, no traffic to live systems and no configuration change. (1) Read wp-config.php of every WordPress install that runs the CashuPay plugin and note whether WP_SITEURL or WP_HOME i… |
| The DLEQ blinding factor in a token has no length bound and BigInt::fromHex() is quadratic on the BCMath backend: one proof can cost ~106 s of CPU | `receive.php:123 → cashu-wallet-php/CashuWallet.php:1228` | Whether the target deployment has ext-gmp installed. The library only suggests it (cashu-wallet-php/composer.json) and README.md calls it recommended; a shared host without it takes the BCMath path, where the same request costs 8.65 s (65,536-character r) t… | Already executed in the sandbox, repeatable with: php backends.php gmp|bcmath (per-backend fromHex timings), php bigint-quadratic.php gmp|bcmath 196608 (105,825.7 ms on BCMath versus 0.4 ms on GMP)… | On the target host, run `php -m | grep -E '^(gmp|bcmath)$'` and `php -i | grep -E 'opcache|SQLite'` and read the PHP-FPM pool configuration (pm.max_children, pm.max_execution_time). If ext-gmp is a… |
| Kill-switch publisher republishes an unauthenticated fetch of the live manifest verbatim, without validating or showing the fields it carries | `scripts/emergency-shutdown.php:149 → scripts/emergency-shutdown.php:212` | Whether the production run uses an https:// manifest URL, and whether the manifest origin behind cashupayserver.org is under the maintainer's exclusive control, is a production fact that is not observable from the repository. With the default https URL and … | Reproduce the recorded fixture run in agents/w3d-channels/artifacts/check-emergency-publisher.txt: extract fetchManifest(), writeManifest() and describe() verbatim from scripts/emergency-shutdown.p… | Owner-observed: record the exact --manifest-url and CASHUPAY_MANIFEST_SCP values used for the real kill-switch run, who controls the DNS and the host behind that origin, and whether the origin serv… |
| In the default proxy mode the rate-limit identity can be chosen by the caller | `includes/security.php:523 → includes/auth.php:87` | What peer address the deployed PHP process sees (REMOTE_ADDR) - loopback, a private range, or a public client address - is a deployment fact the repository cannot establish: the repository ships no reverse-proxy configuration (docker/Dockerfile.standalone r… | Already executed offline in the sandbox against the target commit, with a scratch-local SQLite database and dummy addresses only (artifact: agents/v12-clientip/artifacts/check-clientip-evidence.txt… | Owner-observed and non-destructive; no request is sent to any host other than the operator's own instance, and nothing is changed until the operator decides. (1) On the affected instance, while log… |
| The expiry sweep holds SQLite's single writer lock for its whole duration, so the wallet library's proof write can fail with 'database is locked' in the window after the mint already issued the proofs | `includes/invoice.php:383 → cashu-wallet-php/CashuWallet.php:3866` | The magnitude is outside the repository. The wallet-side failure needs the sweep to hold SQLite's writer lock for longer than the 15 s busy_timeout, and the sweep's per-row cost is a property of the operator's host (20.4 us/invoice with no enabled webhook a… | Two checks settle it. (1) The wallet side, isolated from the backlog size: hold the writer lock the way the sweep does - Database::beginWriteTransaction() on the application's own connection, which… | On the operator's installation, count the expiring invoices (SELECT COUNT(*) FROM invoices WHERE status = 'New' AND expiration_time < the current time) and time one cron tick's 'expire_invoices' ta… |
| The container build copies the developer's local configuration into the images and sends the whole checkout - including the wallet database - as the build context, and nothing excludes them | `docker/docker-compose.yml:12 → .gitignore:22` | Whether the build context or an image ever leaves the machine that produced it is not observable in the repository: there is no CI docker build (.github/workflows/tests.yml is the only workflow and never mentions docker), no 'docker push', no buildx, no reg… | Reproduced in the sandbox and recorded in check-docker-context.txt (promoted from the agent's scratch): (1) a find over the whole checkout returns 0 .dockerignore files and 'git ls-files' 0 tracked… | Owner-observed, on the build machines and not in the repository: (a) on every machine that runs 'docker build' for this repository, check whether includes/config.local.php exists and whether it def… |
| The operator's upgrade chain has no integrity root independent of the party that publishes the bytes | `includes/update_check.php:186 → RELEASE.md:95` | Who may create a release, or upload/replace an asset under an existing tag - organisation owners versus write-access collaborators, the type and scopes of the `gh` token used, whether release assets can be replaced under the same tag without a version bump … | Both halves are reproducible offline, and I reproduced both independently. Release side: run the unmodified scripts/build-standalone.sh against a fixture and publish, as a release asset, a SHA256SU… | Owner-observed checks, with no probing of live systems: (1) in the GitHub repository settings, record who can create releases and upload or replace assets under a tag (roles, `gh` token type and sc… |
| The release-manifest fetch that carries the signed emergency kill-switch notice is not required to use HTTPS | `includes/update_check.php:57 → includes/update_check.php:229` | The default endpoint is https://cashupayserver.org/version.json (includes/update_check.php:32) and CASHUPAY_UPDATE_URL is set only by an operator or a fork in includes/config.local.php, which is not part of the repository; no code in the repository sets it … | Already performed; artifact agents/v10-manifest/artifacts/check-manifest-transport.txt (macOS sandbox, loopback only, dummy data, CASHUPAY_DATA_DIR inside the agent scratch, repository untouched). … | Owner-observed only - the audit makes no request to any manifest host and no probe of a live installation. The operator opens the CashuPayServer dashboard: the line under the version reads 'Tell me… |
| WordPress plugin declares an update channel that no reviewed artifact is bound to, on the same origin that serves the kill-switch manifest | `wordpress/cashupay.php:5 → includes/database.php:404` | What https://cashupayserver.org/wordpress-plugin/ actually returns (status, body, whether it is a WordPress update descriptor naming a 'package') cannot be observed without contacting the deployed site, and the audited repository contains no producer for it… | Reproduce the recorded static inventory in agents/w3d-channels/artifacts/check-wordpress-update-channel.txt: grep the target commit for the Update URI URL, for the checksum handling, and for every … | Owner-observed: request https://cashupayserver.org/wordpress-plugin/ and record the status and body; if it is a plugin update descriptor, download the package it names, compute its SHA-256 and comp… |

### Greenfield API answers that vary only by the Authorization header are served with no Cache-Control and no Vary

- **Fingerprint:** `api.php-response-cache-directive-missing`
- **Claimed root cause:** The one place that could have declared the API's storage semantics does not: Security::setSecurityHeaders() (includes/security.php:755-781) is the shared response-header helper for every entry point and sets only framing, MIME-sniffing, referrer and CSP headers, and api.php does not call it at all (api.php:15-26) nor start a session, so no Cache-Control, Expires, Pragma or Vary is ever attached to a per-tenant, bearer-authenticated response and PHP's session cache limiter does not step in either. Nothing in the shipped nginx guidance (README.md:145-180), .htaccess or router.php tells a caching layer to skip /api/v1/, so the application leaves the storage decision entirely to whatever sits in front of it - while offering operators a 'behind Cloudflare' setting (admin.php:927) that puts a caching CDN there on purpose.
- **Source path:**
  - `entrypoint` `api.php:261` — Auth::requireApiAuth() selects the principal for the request. The only input that selects it is the Authorization request header; the request URL carries no credential material, so two different credentials produce two different answers for one identical URL.
  - `propagation` `includes/auth.php:261` — validateApiRequest() reads $_SERVER['HTTP_AUTHORIZATION'] and matches 'token <key>' or 'Bearer <key>'; validateApiKey() (includes/auth.php:278-299) hashes the key and resolves it to exactly one store_id and one permission set.
  - `propagation` `api.php:205` — requireStore() binds the URL's {storeId} to the key's store with hash_equals, so the same URL answers 200 for the owning key and 403 'API key is not authorized for this store' for every other key - a difference the response does not mark and a cache key cannot see.
  - `propagation` `includes/api/invoices.php:99` — handleGetInvoices() returns only the authenticated store's rows; handleGetWebhooks (includes/api/webhooks.php:57) does the same, and handleGetWebhookDeliveries (includes/api/webhooks.php:306-320) additionally returns the shop's webhook receiver response body as errorMessage. handleGetStores (includes/api/stores.php:13-29) and handleCurrentApiKey (includes/api/stores.php:168-174) return the caller's own store and the caller's own credential metadata.
  - `propagation` `includes/api/stores.php:168` — handleCurrentApiKey() answers at the fixed URL /api/v1/api-keys/current with the caller's label and the caller's store-scoped permission set, so the body at that URL is a function of the Authorization header alone.
  - `sink` `api.php:184` — jsonResponse() writes the per-tenant body and exits. api.php's own headers are only Content-Type, X-Content-Type-Options and the three CORS headers (api.php:15-26); Security::setSecurityHeaders() adds no Cache-Control/Expires/Pragma/Vary (includes/security.php:755-781); and because api.php never calls Auth::initSession() the PHP session cache limiter never appends 'Cache-Control: no-store'. The response therefore leaves the application with no declared storage semantics and no Vary.
- **Blockers:**
  - Whether a caching intermediary in front of the operator's site stores and later replays a response to a request carrying Authorization is a deployment fact. The nginx/virtual-host configuration is not in the repository - README.md:145-180 shows only the operator-facing snippet - and no Cloudflare, Varnish, CDN or host page-cache configuration exists anywhere in the tree.
  - Whether that intermediary's cache key includes the Authorization header (nginx proxy_cache_key, a Cloudflare Cache Rule or 'Cache Everything' setting, a host's full-page-cache plugin) cannot be read from the repository, so the divergent key between the two components cannot be closed from source.
  - The only cache exercised locally is a deliberately naive URL-keyed proxy written for this check; it demonstrates the mechanism (store_a's body and a 200 in place of the app's 403) but is not an operator's front end, so it cannot settle the deployment fact on its own.
- **Bounded local next step:** Re-run the two recorded fixtures inside the sandbox. (1) 'php bootstrap.php' builds a throwaway install with CASHUPAY_DATA_DIR = scratch/data holding dummy store_a/store_b and two dummy bearer keys. (2) 'php -d auto_prepend_file=prepend.php -S 127.0.0.1:8732 router.php' serves the real api.php through the app's own front controller. (3) 'curl -sS -D - -H "Authorization: token <key>"' against /api/v1/server/info, /api/v1/stores, /api/v1/stores/store_a/invoices, /api/v1/api-keys/current and /api/v1/stores/store_a/webhooks records that every API response has no Cache-Control, no Vary and no Set-Cookie, while / and /admin (which start a session) carry 'Cache-Control: no-store, no-cache, must-revalidate'. (4) Put the naive URL-keyed proxy on port 8731 in front of the app and repeat the two URLs with the two keys to observe the replay and the 403-becoming-200 substitution. Output recorded in the promoted artefacts check-headers.txt and check-proxy-replay.txt.
- **Owner-observed deployment check:** One owner-observed fact settles it: does anything in front of the operator's site store a response to a request carrying Authorization and later replay it for the same URL? The owner should (a) read the front-end configuration for the /api/v1/ location - nginx proxy_cache / proxy_cache_key / proxy_no_cache / proxy_cache_bypass, the Cloudflare Cache Rules and 'Cache Everything' setting (the dashboard's Visitor addresses -> 'Also trust Cloudflare' checkbox shows a CDN is intended there), or the host's full-page-cache settings - and note whether the cache key includes the Authorization header; and (b) as a read-only confirmation on their own install, using two API keys they created themselves for their own shop, fetch GET /api/v1/stores twice through that front end and diff the two bodies: identical bodies mean the cache is replaying one credential's answer to the other, which confirms the boundary failure; different bodies mean the front end already refuses to store or to reuse these responses and the gap is hardening only.

### In WordPress mode Background::trigger() sends the internal cron key to site_url(), which is request-derived when wp-config.php derives WP_SITEURL from the Host header

- **Fingerprint:** `background.selfCronUrl.wordpressSiteUrl`
- **Claimed root cause:** includes/background.php:67-75 treats WordPress' site_url() as a trusted origin unconditionally, and includes/urls.php:164-169 derives the cron URL from it, so the destination of the internal credential follows the request's Host header instead of the origin the operator pinned. The pinned base_url (wordpress/activation.php:147, admin.php:936-960, setup.php:204-212) is this project's own control for exactly this problem - 'background self-requests that carry the internal key have a trusted destination' (setup.php:205-206) - and it is silently not applied to the WordPress-mode cron URL, so the source-visible protection exists in one deployment mode and not in the other.
- **Source path:**
  - `entrypoint` `api.php:50` — register_shutdown_function(static fn() => Background::trigger()) is registered for every API request, before the rate limit (api.php:53-54) and long before authentication (api.php:261 Auth::requireApiAuth()); the anonymous GET /server/info route (api.php:113,249-252) therefore reaches it. PHP executes shutdown functions even when the script exits early, so 404/429/503 responses trigger it too. The WordPress rewrite rule ^cashupay/api/v1/(.*)$ (wordpress/rewrite-rules.php:34) maps the request to api.php. payment.php:43 is an equivalent anonymous trigger (needs a valid invoice id).
  - `propagation` `includes/background.php:67` — selfCronUrl() returns Urls::cron() unconditionally when Urls::isWordPress() is true (68-69) and only requires a pinned base_url in standalone mode (71-73, with the comment 'Without one, a poisoned Host header would send the internal key to whatever host the attacker named'). The WordPress branch therefore skips the very check that exists to stop a forged Host from redirecting the internal credential. Confirmed at runtime: in standalone mode with no base_url selfCronUrl() returns null and no request is made.
  - `propagation` `includes/urls.php:166` — Urls::cron() returns site_url('/cashupay/cron') in WordPress mode; the standalone branch (168) uses the pinned Config::getBaseUrl(). site_url() echoes the request's Host whenever wp-config.php defines WP_SITEURL from $_SERVER['HTTP_HOST'] (WordPress' site_url() honours that constant over the stored 'siteurl' option). The pinned base_url is never consulted in this branch - reproduced: with base_url pinned to one origin and a Host-derived WP_SITEURL, selfCronUrl() returned the Host-derived URL.
  - `propagation` `includes/background.php:44` — curl_setopt_array() sends 'X-Cron-Key: ' . self::getInternalKey() to the resolved URL (line 42 builds $url . '?internal=1'). Redirects are not followed (54) and TLS is verified for non-loopback hosts (50-51), but the destination host is unrestricted - there is no comparison with the pinned base_url. Reproduced: a loopback listener on the forged Host received 'GET /cashupay/cron?internal=1' with 'X-Cron-Key: <stored internal_background_key>'.
  - `sink` `cron.php:45` — Background::verifyInternalKey($providedKey) accepts the captured key as a full cron caller when ?internal=1 is present; Background::verifyInternalKey() has exactly one caller in the tree (cron.php:45), so the key buys nothing else. The caller chooses the run budget (cron.php:72-75, clamped to 5..300) and receives the run summary (cron.php:77), whose auto_melt entry (includes/background_runner.php:750-768) lists store name, store id, mint URL and amount for every store with auto-melt enabled (includes/lightning_address.php:299-308). The runs perform real work: mint polling, webhook delivery, auto-withdrawal to the operator's configured destinations, update check (includes/background_runner.php:99-153).
- **Blockers:**
  - Whether the deployment's wp-config.php defines WP_SITEURL (or a theme/plugin 'siteurl', 'option_siteurl' or 'site_url' filter derives the site URL) from $_SERVER['HTTP_HOST'], X-Forwarded-Host or another request-derived value is a deployment fact: the repository ships no WordPress core and no wp-config that defines it, and its own docker/entrypoint-wordpress.sh wp-config does not (it installs with a fixed --url=http://localhost). With the default DB-stored 'siteurl' option, site_url() is fixed and the trace does not apply - reproduced as case 'wp-fixed', where the key went to the operator's origin and the forged Host received nothing.
  - Whether the web server routes an arbitrary Host header to this WordPress vhost (default/catch-all server_name, no strict Host rejection) and whether WordPress' own canonical redirect bounces the forged-Host request before the plugin's template_redirect handler loads api.php are deployment and core facts that are not visible from this repository.
  - The blast radius of unlimited background runs depends on the deployment's configuration: the per-store summary only lists stores with auto-melt enabled (includes/lightning_address.php:299-308), and the outbound mint polling, webhook delivery and auto-withdrawal traffic depend on the configured mint and the operator's webhook endpoints.
  - The sandbox has no WordPress core and no network access to obtain it, so the decisive step of confirming WordPress' own resolution of the WP_SITEURL constant against get_option('siteurl') could not be executed here; the local evidence uses a stub that reproduces WP's documented constant-over-option precedence.
- **Bounded local next step:** Already done, non-destructively, and reproducible as agents/v9-wpcron/scratch/wpcron_probe.php (4-case matrix, artifact check-wpcron-origin.txt): real includes/background.php + includes/urls.php + includes/config.php, scratch SQLite DB, dummy internal key, loopback listeners only. Case wp-host (WP_SITEURL='http://'.$_SERVER['HTTP_HOST']): listener on the forged Host received 'GET /cashupay/cron?internal=1' with 'X-Cron-Key: <stored key>', the pinned base_url origin received nothing. Case wp-fixed (no constant, stored 'siteurl' option): the key went to the operator's origin, nothing to the forged Host. Case sa-nopin: selfCronUrl() = null, nothing sent. Case sa-pin: the key went to the pinned base_url origin, nothing to the forged Host. Remaining local step, if WordPress core can be placed in the sandbox: with a scratch WordPress install whose wp-config.php has define('WP_SITEURL','http://'.$_SERVER['HTTP_HOST']), activate the plugin, complete setup, delete the last_background_trigger config row, start a loopback listener on a second port, then GET /cashupay/api/v1/server/info with 'Host: 127.0.0.1:<listener-port>' and check whether the listener receives 'GET /cashupay/cron?internal=1' with an X-Cron-Key equal to the stored internal_background_key; repeat with WP_SITEURL undefined (stored siteurl option) and expect no capture. Fix check on the same fixture: with the patched selfCronUrl() the forged-Host request must produce no capture, i.e. in WordPress mode the cron URL must be built from the pinned base_url, or the scheme/host/port of site_url() must be compared with the pinned base_url and a mismatch must return null.
- **Owner-observed deployment check:** Owner-observed only, no traffic to live systems and no configuration change. (1) Read wp-config.php of every WordPress install that runs the CashuPay plugin and note whether WP_SITEURL or WP_HOME is defined and, if so, whether its value is built from $_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], X-Forwarded-Host or any other request-derived input; also check the theme and other plugins for a 'siteurl', 'option_siteurl' or 'site_url' filter that does the same. (2) Read the vhost/reverse-proxy configuration for the site: whether it is the default/catch-all server_name, whether unknown Host headers are rejected (for example an nginx host check or an Apache SetEnvIf/Reject rule), and whether a proxy pins the Host header. (3) If neither the wp-config nor any filter derives the site URL from the request, the issue does not apply to that install and no further action is needed; if it does apply, the operator can pin the address in the CashuPay dashboard, but the durable fix is in the code - in WordPress mode, build the internal self-request URL from the pinned base_url or compare scheme/host/port with it and refuse to send the key on a mismatch.

### The DLEQ blinding factor in a token has no length bound and BigInt::fromHex() is quadratic on the BCMath backend: one proof can cost ~106 s of CPU

- **Fingerprint:** `cashu-wallet-php.bigint.fromhex.unbounded-hex-quadratic`
- **Claimed root cause:** Attacker-controlled DLEQ scalar fields are parsed without a length bound, and BigInt::fromHex() converts them with an O(n^2) character loop when the BCMath backend is in use (CashuWallet.php:190-193), inside the per-proof verification that runs before every cheap rejection (1228, 8073-8076).
- **Source path:**
  - `entrypoint` `receive.php:123` — The token arrives in the JSON body; the only gate is the per-IP limiter at 128 and the byte-length check at 224 (262,144 characters).
  - `propagation` `cashu-wallet-php/CashuWallet.php:3528` — deserializeV3 takes dleq.r straight from the JSON (3528-3533) with no length check; deserializeV4 does the same for the CBOR bytes at 3595-3599 and hex-encodes them at 3599, so the parsed string can be twice the token's byte budget.
  - `propagation` `cashu-wallet-php/CashuWallet.php:8074` — Only the emptiness of r is tested, never its length, so a multi-hundred-kilobyte r reaches the verification.
  - `sink` `cashu-wallet-php/CashuWallet.php:1228` — BigInt::fromHex($proof->dleq->r) converts the string to an integer; on the BCMath backend the loop at 190-193 does one bcmul/bcadd per character on a growing number, so the cost grows with the square of the length.
- **Blockers:**
  - Whether the target deployment has ext-gmp installed. The library only suggests it (cashu-wallet-php/composer.json) and README.md calls it recommended; a shared host without it takes the BCMath path, where the same request costs 8.65 s (65,536-character r) to 105.8 s (196,608-character r) of CPU instead of 0.013 s. The documented production install requires only ext-bcmath (README.md:81-82) and ext-gmp is merely recommended (README.md:97); the repository's Docker images do install ext-gmp, but DOCKER.md marks them as test-only. The repository contains no php.ini, no pool configuration and no runtime check for ext-gmp, so the backend of a real deployment is a host fact, not a repository fact.
  - The size of the affected worker pool on the target, which decides how many concurrent 100-second requests are needed to deny the shop.
- **Bounded local next step:** Already executed in the sandbox, repeatable with: php backends.php gmp|bcmath (per-backend fromHex timings), php bigint-quadratic.php gmp|bcmath 196608 (105,825.7 ms on BCMath versus 0.4 ms on GMP), and php big-r-receive.php gmp|bcmath 65536 (one proof through Wallet::receive: 8.648 s CPU on BCMath, 0.013 s on GMP).
- **Owner-observed deployment check:** On the target host, run `php -m | grep -E '^(gmp|bcmath)$'` and `php -i | grep -E 'opcache|SQLite'` and read the PHP-FPM pool configuration (pm.max_children, pm.max_execution_time). If ext-gmp is absent, the same finding applies at the measured magnitude; if it is present, the quadratic variant does not apply and only the per-proof cost of the sibling finding does.

### Kill-switch publisher republishes an unauthenticated fetch of the live manifest verbatim, without validating or showing the fields it carries

- **Fingerprint:** `emergency_shutdown.publisher-republishes-unverified-live-manifest`
- **Claimed root cause:** The publisher treats the response of an unauthenticated, scheme- and host-unrestricted fetch as authoritative base content for a document it signs and publishes: fetchManifest() has no scheme, host, TLS or redirect check and no origin pinning, and writeManifest() validates only the size of the serialized document, so every field except safe_mode crosses from the network into the published manifest without validation and without the maintainer ever seeing it.
- **Source path:**
  - `entrypoint` `scripts/emergency-shutdown.php:149` — fetchManifest() reads the manifest URL with file_get_contents() and a plain http stream context. No scheme, host or TLS requirement is applied, and the http wrapper follows redirects to another origin by default, so the returned body is whatever answered that URL.
  - `propagation` `scripts/emergency-shutdown.php:153` — The response body is json_decode()d and returned as the base manifest array; every key it contains is kept.
  - `propagation` `scripts/emergency-shutdown.php:263` — The base manifest becomes $live, the document the publisher will write and upload.
  - `propagation` `scripts/emergency-shutdown.php:427` — Only the safe_mode field is replaced by the maintainer's own signed notice; every other key of $live survives untouched into the published document.
  - `propagation` `scripts/emergency-shutdown.php:194` — writeManifest() writes the whole array to the file that will be published; its only checks are the 16 KiB size cap and file_put_contents() success.
  - `sink` `scripts/emergency-shutdown.php:212` — upload() hands that file to scp, which replaces the live manifest that every install fetches; the read-back at line 217 only re-describes the safe_mode notice, so the other published fields are never confirmed.
- **Blockers:**
  - Whether the production run uses an https:// manifest URL, and whether the manifest origin behind cashupayserver.org is under the maintainer's exclusive control, is a production fact that is not observable from the repository. With the default https URL and TLS verification the read is authenticated, so the exposure requires a non-https --manifest-url, a redirect to another origin, or control of the origin.
  - Whether the manifest host is shared with content the maintainer does not control (shared hosting, a CDN, DNS delegation) is a deployment fact.
  - Whether a second publisher or a concurrent release publish can occur between the read at line 263 and the upload at line 212 (the tool waits through interactive questions and an externally pasted signature in between) is a production fact that decides how often the stale base is written over a newer manifest.
- **Bounded local next step:** Reproduce the recorded fixture run in agents/w3d-channels/artifacts/check-emergency-publisher.txt: extract fetchManifest(), writeManifest() and describe() verbatim from scripts/emergency-shutdown.php (token_get_all, no execution of the script's main body), point two loopback php -S fixtures at a dummy manifest, and call the extracted functions. The recorded run shows fetchManifest() accepting a plaintext http:// body, following a 302 to a different origin, and writeManifest() copying every fetched field (version, released, url, severity, min_supported, notes) verbatim while describe() shows only 'version' and the notice. A fix is validated by repeating the same steps after the change: the publisher must refuse a body it cannot attribute to the expected origin, must refuse to publish a field it did not author, and must print the fields it is about to publish.
- **Owner-observed deployment check:** Owner-observed: record the exact --manifest-url and CASHUPAY_MANIFEST_SCP values used for the real kill-switch run, who controls the DNS and the host behind that origin, and whether the origin serves anything the maintainer does not publish. Then decide whether the published manifest is under an authenticated origin, and whether the publisher must re-read the live manifest immediately before the upload and refuse to overwrite a manifest that changed.

### In the default proxy mode the rate-limit identity can be chosen by the caller

- **Fingerprint:** `includes/security.php:getClientIp:auto-mode-trusted-private-peer-forwarded-headers`
- **Claimed root cause:** includes/security.php:523-570 believes caller-supplied forwarded headers whenever the direct peer is in a trusted set, and includes/security.php:653-682 defines that set to include every loopback peer and - in the default 'auto' mode - every private-network peer. When the forwarded chain contains no untrusted entry, includes/security.php:560-567 falls back to the caller-supplied Forwarded and X-Real-IP headers rather than to the peer address. The address that comes out is then used verbatim as the key of Security::reserveLoginAttempt (includes/security.php:66-88), which Auth::attemptLogin calls at includes/auth.php:115 with the value admin.php:738 takes from getClientIp(), and of the setup recovery-code limit (includes/setup_ownership.php:150).
- **Source path:**
  - `entrypoint` `includes/security.php:523` — The client address is derived from the forwarded headers whenever the direct peer is a trusted proxy; the result is what every per-IP rate limit, the login lockout and the recovery-code limit use as its key.
  - `propagation` `includes/security.php:662` — A loopback peer is always trusted (unless proxy_mode is 'direct', checked first at lines 658-661), and in the default 'auto' mode every private-network, CGNAT and ULA peer is trusted as well (lines 671-680). The mode itself defaults to 'auto' (lines 597-600).
  - `propagation` `includes/security.php:535` — The header is split and walked right to left; the first entry that is not a trusted proxy is returned as the client address. With a trusted peer that entry is exactly what the caller sent.
  - `propagation` `includes/security.php:560` — When the chain holds no untrusted entry (for example when the only entry is a private-range client that auto mode considers a proxy, which is what a real client behind such a hop looks like), the caller's own Forwarded and X-Real-IP headers are used instead, and only then the peer address (lines 559-569).
  - `propagation` `includes/security.php:67` — The lockout is keyed on that address: 'login_attempts_' . $identifier, five attempts per bucket, five minutes of lockout. A caller that can vary the address gets a fresh budget per request.
  - `propagation` `includes/setup_ownership.php:150` — The setup recovery-code limit (10 attempts per 60 s, RECOVERY_ATTEMPTS at line 61) uses the same derived address as its key ('rate_setup_recovery_' . address), so the 40-bit recovery code (generated at line 201) is limited in an attacker-chosen bucket too.
  - `propagation` `includes/security.php:659` — The mode check precedes the loopback and private-range checks, so with proxy_mode='direct' no peer is trusted, getClientIp() returns the peer address at line 528-530 and the forwarded headers are ignored entirely.
  - `sink` `includes/auth.php:87` — The password check at Auth::attemptLogin (includes/auth.php:113-123) runs for every reserved attempt, so the number of online guesses is bounded only by the attacker's ability to vary the address the limiter keys on.
- **Blockers:**
  - What peer address the deployed PHP process sees (REMOTE_ADDR) - loopback, a private range, or a public client address - is a deployment fact the repository cannot establish: the repository ships no reverse-proxy configuration (docker/Dockerfile.standalone runs Apache with mod_php directly, so there the peer is the real client) and the live host's web-server configuration is not in the repository.
  - Whether the ingress overwrites or strips caller-supplied X-Forwarded-For, Forwarded and X-Real-IP is a proxy-configuration fact outside the repository; the code cannot tell which header the trusted hop wrote, so a proxy that only sets X-Real-IP and appends nothing leaves the caller's own X-Forwarded-For in charge.
  - The exact reachability of the install (public internet only, or also the local network) determines who can exploit it. A LAN neighbour needs no proxy at all - its own private address is a trusted peer in auto mode - but it needs the install to be reachable on a private network.
  - Whether the operator's admin password is weak enough for the residual online-guessing exposure to matter cannot be read from the repository; the enforced minimum is 8 characters (Auth::MIN_PASSWORD_LENGTH, includes/auth.php:159) and the hash is bcrypt with the default cost (includes/auth.php:154).
- **Bounded local next step:** Already executed offline in the sandbox against the target commit, with a scratch-local SQLite database and dummy addresses only (artifact: agents/v12-clientip/artifacts/check-clientip-evidence.txt). With no operator setting, Security::proxyMode() is 'auto'; Security::isTrustedProxy() is true for 127.0.0.1, ::1, 192.168.1.20, 10.1.2.3, 172.16.5.5, 100.64.0.1 and fd00::1 and false for 203.0.113.9. With REMOTE_ADDR=127.0.0.1 or 192.168.1.20, Security::getClientIp() returned the caller's 198.51.100.7 from a supplied X-Forwarded-For, 198.51.100.8 from a supplied X-Real-IP (chain with no untrusted entry) and 198.51.100.9 from a supplied 'Forwarded: for=198.51.100.9'; with the public peer 203.0.113.9 the same headers were ignored and the peer address was returned. Twenty consecutive Security::reserveLoginAttempt(Security::getClientIp()) calls with a rotating X-Forwarded-For were all reserved (20 of 20) against 5 of 20 for one fixed address, and twenty Security::checkRateLimit('setup_recovery', Security::getClientIp(), 10) calls with a rotating address were all allowed. After Config::set('proxy_mode','direct') the same loop gave 5 of 20 and getClientIp() returned the peer (127.0.0.1, 192.168.1.20) regardless of the headers; 'custom' with an empty proxy list likewise stopped trusting a private peer. Nothing outside the agent scratch directory was written.
- **Owner-observed deployment check:** Owner-observed and non-destructive; no request is sent to any host other than the operator's own instance, and nothing is changed until the operator decides. (1) On the affected instance, while logged into the dashboard, open 'admin.php?api=proxy_settings' - the read-only GET the dashboard itself calls at admin.php:5404 - and read seen.peer, seen.mode and seen.forwardedFor. If seen.peer is inside 127.0.0.0/8, ::1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 or fc00::/7, the trusted-peer condition holds on that deployment and the forwarded headers are believed; if seen.peer is the operator's own public address, the candidate does not apply there. (2) Read the front proxy's own configuration for X-Forwarded-For, Forwarded and X-Real-IP (nginx: proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for versus $remote_addr, proxy_set_header Forwarded "", proxy_set_header X-Real-IP; Apache: ProxyAddHeaders / RequestHeader). If the proxy appends to or overwrites X-Forwarded-For and does not pass a client-sent Forwarded or X-Real-IP through, the caller cannot choose the bucket and only the LAN-exposure variant remains. (3) Corroboration from the operator's own browser only: the 'Visitor addresses' card renders 'Your address, as this server sees it: <address>'; if that address is not the operator's own, the identity is being taken from a header. Do not probe third-party hosts and do not scan for open instances. (4) If the condition holds, the operator fixes it in the dashboard without a redeploy: 'Visitor addresses' -> 'Visitors connect directly' (proxy_mode=direct) when nothing proxies the site, or 'Only these proxies' (custom) listing the real proxy addresses or CIDR ranges; alternatively make the proxy overwrite X-Forwarded-For and clear Forwarded and X-Real-IP. The mode is read per request from the config table (includes/security.php:597-600), so the change takes effect immediately.

### The expiry sweep holds SQLite's single writer lock for its whole duration, so the wallet library's proof write can fail with 'database is locked' in the window after the mint already issued the proofs

- **Fingerprint:** `invoice.markExpiredInvoices.unbounded-write-transaction-blocks-wallet-proof-writes`
- **Claimed root cause:** The expiry sweep is unbounded and runs entirely inside one write transaction (includes/invoice.php:343-368, no LIMIT at :348-351), so SQLite's single writer lock is held for a time that grows with the expired-invoice backlog and with the number of enabled webhooks per store; the wallet library's second connection to the same file (cashu-wallet-php/CashuWallet.php:3859-3866) can then only wait for its 15000 ms busy_timeout before failing with 'database is locked' - in the window between the mint issuing the proofs (cashu-wallet-php/CashuWallet.php:7468) and the wallet storing them (:7535).
- **Source path:**
  - `entrypoint` `includes/invoice.php:383` — A paid invoice is driven to the mint: the quote is polled and, once the mint says PAID, mintAndStoreTokens() claims the invoice and calls Wallet::mint().
  - `propagation` `cashu-wallet-php/CashuWallet.php:7468` — submitMintRequest() returns the mint's signatures: the outputs are issued at the mint and the wallet now owns proofs it must persist.
  - `propagation` `includes/invoice.php:343` — In another process the expiry sweep opens a write transaction whose first statement is a no-op write (includes/database.php:953), taking SQLite's single writer lock for the whole unbounded sweep; the lock is held until the commit at includes/invoice.php:368.
  - `propagation` `cashu-wallet-php/CashuWallet.php:7535` — finalizePendingMint() opens a write transaction on the wallet library's own connection (beginWrite(), :5487 -> :4181-4196, the same no-op-write trick) and stores the proofs (storeProofs, :5494 -> :4760, the INSERT at :4796); that write needs the writer lock the sweep is holding.
  - `sink` `cashu-wallet-php/CashuWallet.php:3866` — The wallet connection waits 15 s for the writer lock and then fails with SQLITE_BUSY ('database is locked'), after the mint has already issued the proofs. Measured locally: 16,095 ms and 'PDOException: SQLSTATE[HY000]: General error: 5 database is locked'.
- **Blockers:**
  - The magnitude is outside the repository. The wallet-side failure needs the sweep to hold SQLite's writer lock for longer than the 15 s busy_timeout, and the sweep's per-row cost is a property of the operator's host (20.4 us/invoice with no enabled webhook and 48 us/invoice with one, on this machine's disk; a slower shared host, a cold cache, a larger database or more enabled webhooks all lower the number of invoices needed). The sandbox could not produce a real sweep that long: the SQLite file it writes is capped at 25 MiB, which allows at most ~190,000 invoice rows (134 bytes/row), far short of the ~312,000 (one webhook) to ~735,000 (no webhook) rows a 15 s hold needs here. The wallet-side behaviour under a long hold was therefore established with the application's own lock-holding pattern - Database::beginWriteTransaction() on the app's connection, i.e. exactly the writer lock the sweep takes, plus a sleep - rather than with a real multi-hundred-thousand invoice sweep.
  - Whether the installation can accumulate such a backlog is a fact outside the repository. 'expire_old_invoices' (includes/background_runner.php:135) trims unpaid invoices older than 30 days whenever the background work runs, and 'cleanup_invoices' (:842) does not delete unpaid invoices at all, so the backlog only grows on an installation whose background work is not running (no cron and no base_url for the opportunistic self-trigger, includes/background.php:67-75). The operator's invoice volume and the uptime of their cron are not observable from the source.
  - The permanent coin-stranding variant does not follow from the source. The pending-mint journal plus NUT-09 /restore recovers the issued proofs (the library's own PendingMintRecoveryTest covers the journal's recovery lifecycle and passes offline), so the consequence established here is a settlement stall. Whether the money could still be stranded additionally depends on the mint implementing NUT-09 /restore, a third-party property outside the repository.
  - The interaction with the sibling finding is host-dependent and changes the order in which the two effects appear. At PHP's default 128M the memory-exhaustion fatal of the same sweep (invoice.markExpiredInvoices.unbounded-select-memory-exhaustion) is reached at about 280,000 rows, while a 15 s lock hold with one enabled webhook needs about 312,000 rows on this host - so on a fast host the fatal comes first and the sweep never holds the lock long enough to fail a wallet write; on a slower host, with more enabled webhooks, or with a larger memory_limit, the lock-hold window comes first.
- **Bounded local next step:** Two checks settle it. (1) The wallet side, isolated from the backlog size: hold the writer lock the way the sweep does - Database::beginWriteTransaction() on the application's own connection, which takes the lock with the no-op write at includes/database.php:953 - for 20 s, and from a second process run the production WalletStorage::preparePendingSpend() / finalizePendingMint() pair with the unmodified PRAGMA busy_timeout = 15000; the write must fail with 'database is locked' after about 15 s and the journal must still be present with no proofs stored. That check was run here and produced the 16,095 ms failure. (2) The magnitude, which needs a real backlog: seed at least 350,000 expired 'New' invoices for one store with a single enabled webhook (or about 80,000 with ten enabled webhooks, which raise the per-row cost to roughly 200 us) on a host whose SQLite file is not capped at 25 MiB, start Invoice::markExpiredInvoices() in one process and, from a second process, attempt the same production finalizePendingMint(); the write must fail with 'database is locked' after about 15 s while the sweep is still in flight, and the sweep's duration must be recorded.
- **Owner-observed deployment check:** On the operator's installation, count the expiring invoices (SELECT COUNT(*) FROM invoices WHERE status = 'New' AND expiration_time < the current time) and time one cron tick's 'expire_invoices' task (the run result and the cron_last_run timestamp). If the sweep's duration approaches 15 s, the wallet's proof writes are already inside the failure window, and the operator's dashboard should show the resulting 'database is locked' poll errors on paid invoices (the poll error is recorded in invoices.last_poll_error and surfaced in the diagnostics view). Both observations are read-only apart from the expiry the tick performs anyway; do not enlarge the backlog on a production installation to make the test.

### The container build copies the developer's local configuration into the images and sends the whole checkout - including the wallet database - as the build context, and nothing excludes them

- **Fingerprint:** `release.docker-build-context-carries-wallet-data-and-local-config`
- **Claimed root cause:** The release allow-list (rm config.local.php + data-location.txt, then find -delete '*.local.php') is applied only in scripts/build-standalone.sh and scripts/build-wordpress-plugin.sh. The container build path copies the same includes/ directory wholesale, the repository has no .dockerignore so the whole checkout is the build context, and .gitignore excludes includes/data-location.txt but not includes/config.local.php.
- **Source path:**
  - `entrypoint` `docker/docker-compose.yml:12` — The build context is the whole repository checkout and there is no .dockerignore to narrow it; the standalone service repeats it at line 26.
  - `propagation` `docker/Dockerfile.wordpress:36` — The whole includes/ directory is copied into the plugin image, so a developer's config.local.php and data-location.txt are inside the image whenever the checkout has them.
  - `propagation` `docker/Dockerfile.standalone:34` — The standalone image copies the same directory wholesale, with none of the removals the release scripts perform.
  - `propagation` `includes/database.php:20` — includes/config.local.php is required and executed at boot from inside the application tree, so every constant it defines is live configuration for that image.
  - `propagation` `includes/setup_ownership.php:183` — CASHUPAY_SETUP_TOKEN carried by that file is the recovery code the installation expects, and Setup::verifyOwnershipToken() (line 141) grants setup ownership to whoever presents it.
  - `sink` `.gitignore:22` — Only includes/data-location.txt is ignored; includes/config.local.php is not, so a developer's local configuration can be committed to the public repository by an ordinary 'git add -A'.
- **Blockers:**
  - Whether the build context or an image ever leaves the machine that produced it is not observable in the repository: there is no CI docker build (.github/workflows/tests.yml is the only workflow and never mentions docker), no 'docker push', no buildx, no registry and no DOCKER_HOST, and DOCKER.md documents only local builds. With a local daemon the context stays on the machine; with a remote builder or a pushed image it does not, and the source cannot tell which happened.
  - Whether any machine that builds these images has an includes/config.local.php, and what it contains, is not observable: the file is absent from this checkout and from the entire git history, and no .dockerignore exists to keep the context narrow. Only the owner of the build machines can answer this.
  - The wallet-database half of the claim is bounded by the same unknown: data/ is in the build context but in no image, so its exposure depends entirely on the first blocker, and its contents cannot be inspected here because the repository's own data/ must not be touched.
- **Bounded local next step:** Reproduced in the sandbox and recorded in check-docker-context.txt (promoted from the agent's scratch): (1) a find over the whole checkout returns 0 .dockerignore files and 'git ls-files' 0 tracked ones; (2) the COPY sources of both Dockerfiles are resolved against a stand-in checkout that carries a dummy includes/config.local.php and a dummy data/ folder - the resulting file list shows includes/config.local.php and includes/data-location.txt inside the copied set, while the check that no COPY instruction references data/ shows the wallet database is in the context only; (3) applying the exact removal steps of scripts/build-standalone.sh:58-60 and scripts/build-wordpress-plugin.sh:64-66 to that same tree leaves no config.local.php, no data-location.txt and no *.local.php - the differential the candidate claims; (4) 'git check-ignore' shows includes/config.local.php is not ignored while includes/data-location.txt and data/cashupay.sqlite are, and 'git log --all' shows includes/config.local.php was never committed; (5) the real Setup class is exercised in a scratch data dir with a dummy token: Setup::expectedToken() returns it, Setup::verifyOwnershipToken() takes over setup ownership and the recovering browser may configure the installation, while an empty token makes every visitor count as the owner. Do not run 'docker build' to repeat this: the real checkout's data/ must not be sent anywhere, and the COPY semantics are fully described by the source lists.
- **Owner-observed deployment check:** Owner-observed, on the build machines and not in the repository: (a) on every machine that runs 'docker build' for this repository, check whether includes/config.local.php exists and whether it defines CASHUPAY_SETUP_TOKEN, CASHUPAY_SAFE_MODE_PUBKEYS, CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN, CASHUPAY_UPDATE_URL or CASHUPAY_TRUSTED_PROXIES, and whether that checkout contains a data/ folder holding a real wallet database; (b) check whether any image built from this repository was pushed to a registry, shared as a tarball, or built against a remote builder ('docker context ls', 'echo $DOCKER_HOST', CI runners) - this is the decisive fact; (c) check whether the images 'cashupayserver-standalone' / 'cashupayserver-wordpress' exist on any machine other than the one that built them and whether any of them contains includes/config.local.php ('docker image inspect', layer listing). Fix, in the repository and non-destructive: add a root .dockerignore excluding data/, .git/, build/, node_modules/, *.local.php, includes/config.local.php and includes/data-location.txt; make both Dockerfiles drop the local configuration the way the release scripts do (copy the listed application files, or remove them in the same RUN layer as the COPY); add includes/config.local.php to .gitignore; and optionally fail the image build when includes/config.local.php is present, so the gap cannot reopen silently.

### The operator's upgrade chain has no integrity root independent of the party that publishes the bytes

- **Fingerprint:** `release/upgrade-chain-has-no-independent-integrity-root`
- **Claimed root cause:** The published verification material is not independent of the publisher and the manifest does not carry any: the only digest is co-located with the artifact inside the same release, the manifest carries no digest and its update fields are unsigned, the version string is the only identity binding the announcement to the release, and an operator who follows the documented procedure never performs an integrity check. A single compromise of the release publisher or of the manifest host is therefore enough to steer an operator to attacker-chosen bytes, and nothing in the chain lets the operator or the project distinguish those bytes from the reviewed ones.
- **Source path:**
  - `entrypoint` `includes/update_check.php:186` — `UpdateCheck::fetch()` is where the remote manifest enters the install: one HTTPS GET of a static file, body capped at 16 KiB (MAX_BYTES, line 47), TLS verified, no redirects followed, and `SafeMode::ingest($data)` called on the parsed document (line 227). The manifest is the untrusted input that carries the upgrade identity, and it is the only thing that tells the operator that a release exists.
  - `propagation` `includes/update_check.php:238` — The manifest's `url` is accepted as the link the operator is invited to click while being told to upgrade; it is later rendered as 'How to upgrade' in the dashboard (admin.php:3953-3955). The strongest visible control on this step is includes/http.php:42-54, which confines it to this project's GitHub releases or a path on the manifest host - the operator cannot be steered to an arbitrary site, but nothing binds the bytes at that link to the announced version.
  - `propagation` `includes/update_check.php:236` — The fetch keeps only latest/url/severity/min_supported/notes/released. Every digest, checksum, files, artifacts and attestation field a publisher might add is discarded, and status() (lines 85-95) exposes the same six values plus the version comparison, so the dashboard has no digest to display and no way to state what the operator should verify.
  - `propagation` `version.json.example:2` — The template for the manifest the update check reads has exactly six fields - version, released, url, severity, min_supported, notes - and no digest slot; RELEASE.md:83-85 instructs the maintainer to publish exactly these fields. The manifest therefore binds the operator to a version string and a URL, not to bytes.
  - `propagation` `RELEASE.md:68` — `gh release create vX.Y.Z ... build/SHA256SUMS` publishes the checksum file next to the artifacts it covers, in the same asset store, under the same publisher, in the same transaction. The digest a reader is told to trust (RELEASE.md:51-52) is therefore inside the trust domain it is meant to vouch for.
  - `sink` `RELEASE.md:95` — The operator is told to download a backup, upload the new files over the old ones and open the admin - 'That's it'. No step asks for a digest, and the dashboard that prompted the upgrade shows none, so the bytes the operator installs are trusted purely because they came from the link.
- **Blockers:**
  - Who may create a release, or upload/replace an asset under an existing tag - organisation owners versus write-access collaborators, the type and scopes of the `gh` token used, whether release assets can be replaced under the same tag without a version bump - is a hosted GitHub fact that the repository does not contain. RELEASE.md:56 only records that a human does it with the `gh` CLI.
  - Whether the manifest host (https://cashupayserver.org/version.json) is administered by the same principal as the GitHub organisation is a deployment fact; if it is, the manifest is not a second, independent root and the chain has a single point of failure on both legs. The committed tree contains no ownership or deployment record for that host - the only such note in the working tree, CLAUDE.local.md, is untracked and therefore not part of commit 4a82d01.
  - Whether branch protection, tag protection, immutable releases, signed commits, two-factor enforcement or an attestation/signing service (GitHub artifact attestations, Sigstore) stand in front of the release is not observable in the repository.
  - Whether a real operator ever performs the checksum step is a human-behaviour fact; the documented procedure (RELEASE.md:89-97) does not include one, so the effective protection cannot be established from source.
- **Bounded local next step:** Both halves are reproducible offline, and I reproduced both independently. Release side: run the unmodified scripts/build-standalone.sh against a fixture and publish, as a release asset, a SHA256SUMS file that describes substituted bytes - the operator's documented `shasum -a 256 -c SHA256SUMS` stays green (agents/v16-release/artifacts/check-release-binding.txt). Consumer side: serve a manifest carrying sha256/digest/checksum/files/artifacts/attestation/signature from a loopback fixture (the same method the repository's own tests/update_check.php uses) and confirm that UpdateCheck::fetch() keeps only latest/url/severity/min_supported/notes/released, that UpdateCheck::status() exposes no digest, and that a manifest whose version equals the installed version yields outdated=false and no banner (agents/v16-release/artifacts/check-manifest-integrity.txt). To close the gap, add a `sha256` field to version.json.example, keep and display it in the update consumer and the dashboard, sign it with the already-pinned Nostr keys, and re-run both checks to show the digest survives the fetch and reaches the operator.
- **Owner-observed deployment check:** Owner-observed checks, with no probing of live systems: (1) in the GitHub repository settings, record who can create releases and upload or replace assets under a tag (roles, `gh` token type and scopes), whether branch and tag protection, immutable releases, two-factor or signed-commit rules are enabled, and whether any asset was ever replaced under an existing tag (release history); (2) confirm whether the same administrator publishes https://cashupayserver.org/version.json and whether that file is served from a host with an independent owner and valid TLS; (3) confirm whether an attestation or signing service is available for the release. Then decide whether the pinned Nostr key can sign the release identity (version + digest) so that the operator's check survives either a release-asset compromise or a manifest-host compromise, and record the result in RELEASE.md as the operator-facing verification step.

### The release-manifest fetch that carries the signed emergency kill-switch notice is not required to use HTTPS

- **Fingerprint:** `update_check.manifest-endpoint-transport-not-required-https`
- **Claimed root cause:** The transport requirement for the release manifest is absent rather than merely unenforced: UpdateCheck::endpoint() (includes/update_check.php:55-60) passes CASHUPAY_UPDATE_URL through unchanged and UpdateCheck::fetch() (includes/update_check.php:186-213) merges in cashupay_curl_protocol_options(), whose protocol set is 'https,http' (includes/http.php:18-29). No scheme check exists anywhere on the path, so the one request that carries the signed emergency notice can be made over plain HTTP.
- **Source path:**
  - `entrypoint` `includes/update_check.php:57` — The manifest URL enters the control as CASHUPAY_UPDATE_URL, taken from the operator's (or a fork's) includes/config.local.php, and is returned unchanged; no scheme is inspected. The default when the constant is absent is https://cashupayserver.org/version.json (line 32), and no code in the repository writes the constant.
  - `propagation` `includes/http.php:21` — The shared curl helper returns CURLOPT_PROTOCOLS_STR => 'https,http' (and CURLOPT_REDIR_PROTOCOLS_STR), with the CURLPROTO_HTTPS|CURLPROTO_HTTP bitmask as the fallback on line 26, so the allowed protocol set includes plain HTTP. It is the only protocol control on this path.
  - `propagation` `includes/update_check.php:213` — The fetch merges those protocol options into the handle and sets TLS verification (CURLOPT_SSL_VERIFYPEER true, CURLOPT_SSL_VERIFYHOST 2), no-redirect (CURLOPT_FOLLOWLOCATION false), a 3 s connect timeout, an 8 s total timeout and a 16 KiB body cap, but never checks the endpoint's scheme, so an http:// manifest is fetched and accepted. The TLS options apply only if the URL is https in the first place.
  - `propagation` `includes/update_check.php:227` — The decoded manifest is handed to the kill switch before any other field is validated. The notice is signature-checked, so an on-path attacker cannot forge one, but whether the notice is delivered at all - and therefore whether an install is shut down - is decided by whoever answers on the transport.
  - `sink` `includes/update_check.php:229` — The version, severity, notes and min_supported the operator is shown are taken from the same plaintext response (version regex-checked at :232, severity whitelisted at :242, notes truncated at :251), cached under 'update_check_state' (:125, :160) and rendered on the dashboard (admin.php:255-269, :3943-3949). A doctored response can therefore suppress the update prompt entirely.
- **Blockers:**
  - The default endpoint is https://cashupayserver.org/version.json (includes/update_check.php:32) and CASHUPAY_UPDATE_URL is set only by an operator or a fork in includes/config.local.php, which is not part of the repository; no code in the repository sets it to a non-https URL (the only non-https use is the test harness, tests/update_check.php:46-47). Whether any real installation fetches the manifest over plaintext is therefore a deployment fact that source cannot settle.
  - Whether such an installation's network path admits an on-path attacker - one who can answer for the manifest host, replace the response or drop the request - is an environment fact (hosting network, TLS-terminating proxy, ISP, local wireless) that source cannot settle.
  - Whether the update check even runs on a given installation is a per-installation setting (update_check_enabled, includes/update_check.php:52) and background-runner/cron configuration, so the set of affected installations is not knowable from the repository either.
- **Bounded local next step:** Already performed; artifact agents/v10-manifest/artifacts/check-manifest-transport.txt (macOS sandbox, loopback only, dummy data, CASHUPAY_DATA_DIR inside the agent scratch, repository untouched). A php -S fixture on 127.0.0.1 served the manifest over plain http and CASHUPAY_UPDATE_URL was pointed at it: UpdateCheck::endpoint() returned the http URL verbatim; UpdateCheck::fetch() (called through reflection) was ACCEPTED and returned latest 9.9.9, severity security and notes taken from the plaintext body; a manifest naming the running version made UpdateCheck::status() report outdated=false, so the operator would be told nothing and no task failure is recorded for a successful-looking fetch; a notice signed by a non-pinned key, and a notice claiming a pinned identity but signed by another key, were both rejected by SafeMode::verify() (null), no flag file was written and SafeMode::shutdown() stayed null; re-running with a dummy key pinned through CASHUPAY_SAFE_MODE_PUBKEYS showed the same plaintext channel delivering that genuine notice end to end (flag file written, shutdown() returns the notice), which shows the transport carries the notice and only the signature decides its authenticity. To repeat: sbx.sh v10-manifest --loopback -- php check-manifest-transport.php unpinned | selfpinned.
- **Owner-observed deployment check:** Owner-observed only - the audit makes no request to any manifest host and no probe of a live installation. The operator opens the CashuPayServer dashboard: the line under the version reads 'Tell me when a new version is released. Fetches <URL> a few times a day.' (admin.php:3960-3961 renders UpdateCheck::endpoint()). If that URL begins with https:// the installation is not affected; if it begins with http:// (or any other scheme), the kill-switch notice and the update metadata on that installation travel unauthenticated and the finding applies to it. The equivalent check is reading the CASHUPAY_UPDATE_URL line in that installation's includes/config.local.php. If any installation turns out to use a non-https endpoint, require an https:// scheme in UpdateCheck::endpoint() (refuse the fetch and say so plainly, rather than silently falling back) before the kill switch's delivery can be relied on there.

### WordPress plugin declares an update channel that no reviewed artifact is bound to, on the same origin that serves the kill-switch manifest

- **Fingerprint:** `wordpress_plugin.update-uri-channel-not-bound-to-reviewed-release`
- **Claimed root cause:** The plugin's update channel is a third origin that no part of the build, signing or release process produces or binds to the reviewed artifact, and neither the plugin nor the release process verifies the bytes of a delivered plugin package. The header was declared to protect installs from a hijacked slug, but it points neither at the reviewed GitHub release channel nor at anything signed.
- **Source path:**
  - `entrypoint` `wordpress/cashupay.php:5` — The plugin header declares Update URI: https://cashupayserver.org/wordpress-plugin/. WordPress 5.8+ uses this as the plugin's update source and installs the package that source names.
  - `propagation` `RELEASE.md:75` — The release runbook names a different channel: the plugin is not on the wordpress.org plugin directory and distribution is the GitHub release asset, installed by hand through WP Admin. The declared update channel is neither the one the runbook describes nor one that any build or release step produces.
  - `propagation` `scripts/build-wordpress-plugin.sh:48` — The reviewed artifact is build/cashupay-wordpress.zip; its SHA-256 is written to build/SHA256SUMS for the release notes only, and nothing in the plugin or the release process checks the bytes of a package that is actually delivered to an install.
  - `propagation` `wordpress/cashupay.php:52` — The plugin registers plugins_loaded, so whatever bytes the update channel delivered are executed by WordPress on every request of the whole site, with the site's PHP privileges.
  - `sink` `includes/database.php:404` — stores.seed_phrase holds the wallet seed phrase in plaintext in the SQLite database inside the plugin's data dir (wordpress/bootstrap.php:22-29), so code running as the plugin can derive the ecash and take it. The data dir's .htaccess/index.php protection (wordpress/activation.php:41-88) keeps the web away from it, not code already running inside the site.
- **Blockers:**
  - What https://cashupayserver.org/wordpress-plugin/ actually returns (status, body, whether it is a WordPress update descriptor naming a 'package') cannot be observed without contacting the deployed site, and the audited repository contains no producer for it. The sibling website source tree outside the audited repository has no matching path either, so the channel appears to be dangling today - a deployment fact, not a repository fact.
  - The site configuration that would serve that path, and who may write it, is not in the audited repository.
  - Whether operator sites run WordPress with plugin auto-updates enabled, and which WordPress version they run, are site-by-site deployment facts.
- **Bounded local next step:** Reproduce the recorded static inventory in agents/w3d-channels/artifacts/check-wordpress-update-channel.txt: grep the target commit for the Update URI URL, for the checksum handling, and for every WordPress hook able to verify an incoming plugin package (upgrader_pre_install, upgrader_source_selection, pre_set_site_transient_update_plugins, plugins_api, check_update_plugins, pre_update_site_transient_update_plugins, wp_verify_update_signature), and list the plugin's own add_action/add_filter registrations. The recorded run shows that no package-verifying hook is registered in wordpress/, that the Update URI URL occurs exactly once in the tree, that the plugin zip's SHA-256 exists only in build/SHA256SUMS and the release notes, and that the data dir with stores.seed_phrase is reachable by plugin code. A fix is validated by repeating the same steps after changing the header to the GitHub release channel (or by adding a package-hash check) and confirming the update channel is no longer left unbound to the reviewed artifact.
- **Owner-observed deployment check:** Owner-observed: request https://cashupayserver.org/wordpress-plugin/ and record the status and body; if it is a plugin update descriptor, download the package it names, compute its SHA-256 and compare it with the SHA-256 of build/cashupay-wordpress.zip published in the GitHub release's SHA256SUMS. Then check the site configuration for a location block serving that path and who may write it, and check whether any operator site has WordPress plugin auto-updates enabled.

---

## 6. Hardening notes and positive source patterns

### 6.1 Hardening notes (not findings)

These are real weaknesses with no demonstrated boundary crossing, or improvements with no demonstrated exploit. They are recorded so the owner can choose.

- The release command in RELEASE.md:70 uses the placeholder `--notes "Changelog..."`, while RELEASE.md:51 tells the maintainer to paste the checksums into the release notes. The release notes would then be the only copy of the digest outside the release asset store, so it is the one place an operator could read a digest that is not next to the artifact; making it mandatory (and putting the digest there by hand) is cheaper than any new tooling.
- build/SHA256SUMS is git-ignored (.gitignore: `build/**`), so the file the operator is told to fetch is never reviewed or committed, and it accumulates one line per build across builds (scripts/build-standalone.sh:48-52).
- `sha256sum -c SHA256SUMS` fails if the operator renames the downloaded file, because the entries are bare names such as `cashupayserver.zip`; RELEASE.md:51-52 should say which filename to expect, or the digest should be published per asset.
- The mint-discovery bundle that ships is the one rebuilt by `npm ci` at build time (scripts/build-standalone.sh:62), not the reviewed copy committed at assets/js/mint-discovery.bundle.js; the difference is only a stderr warning (scripts/build-standalone.sh:22-25). RELEASE.md:17-18 already requires the maintainer to review and commit the rebuilt bundle before releasing - making the build fail instead of warn would close the gap at no cost. (The pinned dependency tree carries no install scripts, so npm itself is not a code-injection path here.)
- The WordPress path has no integrity step at all: the plugin zip is a release asset installed through WP Admin -> Add New -> Upload Plugin, and the admin banner's own upgrade text (admin.php:260-263) tells WordPress operators to do exactly that. A per-asset digest published with the release would give that path the same check the standalone path lacks.
- .github/workflows/tests.yml pins its actions by mutable tag (actions/checkout@v4, shivammathur/setup-php@v2) and declares no `permissions:` block; the workflow never reaches the artifact today, but pinning to commit SHAs is the cheap fix for a second, CI-level supply-chain gap.
- The version string lives in three places (RELEASE.md:22-26: includes/config.php, wordpress/cashupay.php header, manifest.json) with nothing checking that they agree with the tag; scripts/emergency-shutdown.php:88 already knows how to list the tags of a version and could be reused as a release preflight.
- admin.php:790, 1120, 1298, 1730 and 1758 read request values without a string cast, so an array-valued parameter (for example address[]=x) reaches a string-typed function and raises an uncaught TypeError, giving HTTP 500. All of these sit behind the admin session check, so only the authenticated operator can reach them and the failure is confined to one request; cast the values (or reject arrays) instead.
- admin.php:1732 stores auto_melt_threshold as an unchecked (int). A zero or negative threshold makes the automatic withdrawal fire on the whole balance of any account above zero (includes/lightning_address.php:399-408) rather than at the threshold the operator chose, and a huge value silently disables the automatic withdrawal. Validate a sane positive range and require an address when enabling it.
- admin.php:936-967 accepts any http(s) URL that passes Security::isSafeAppBaseUrl (includes/security.php:437-446) for the pinned server address; the validator does not reject control characters. No response splitting was reachable from the sinks that use it (PHP's header() refuses CR/LF in a header value, and the other consumers are cURL URLs and JSON), but the validator should reject control characters outright rather than relying on the sinks.
- includes/auth.php:36-41 sets the admin session cookie with path '/' and no Domain, and marks it Secure only when HTTPS or X-Forwarded-Proto says so. In a subdirectory install the admin session cookie is therefore sent to every path of the host, and behind a TLS-terminating proxy that does not forward X-Forwarded-Proto the cookie would travel in cleartext. Both are deployment facts (web-server layout, proxy headers) that were not confirmed here.
- admin.php:992-1037 ('check_transfer') and admin.php:1562-1638 ('check_proofs_spent') settle transfer rows and proof states without taking the per-store funds_<storeId> flock that 'reclaim_transfer' (admin.php:1062), 'export_token' (admin.php:2022) and the automatic withdrawal (includes/lightning_address.php:324) take. The reported defect does not depend on this ('check_proofs_spent' matches pending rows only), but taking the same lock in the settle actions would also remove the remaining race with the cron reconciler (includes/background_runner.php:654-656), which settles the same rows.
- admin.php:1492-1537 and includes/api/stores.php:115-159: one boolean (confirm_fund_loss / confirmFundLoss) overrides both the balance check and every deletion blocker, and the delete then cascades the store's seed, invoices, webhooks, transfers and backup-mint rows away. That is the documented, explicit operator confirmation, so it is not a finding, but a louder two-step confirmation (naming the amount at risk) would fit the project's 'never make fund safety depend on the operator understanding it' rule.
- admin.php:7076 (onclick="copyApiKey('${escapeHtml(key)}')") and admin.php:6954, 6959, 6963, 7048 (${k.id}, ${storeId} interpolated into inline onclick handlers): escapeHtml() is HTML-entity escaping, so it does NOT make a value safe inside an inline event handler - the HTML parser decodes &#39; back to a quote before the JavaScript is compiled, so a value containing an apostrophe would break out of the JS string literal. Every value interpolated that way today is a server-generated hex id (Database::generateId) or a dataset read, so nothing is exploitable as written; the trap is for the next field that is not server-generated. Prefer a data-* attribute plus addEventListener, the pattern assets/js/animated-qr.js and renderDiscoveredMints() (admin.php:6780-6828) already use.
- payment.php:502 (const invoice = <?php echo json_encode($invoice['bolt11']); ?>;): json_encode() returns false for a string that is not valid UTF-8, PHP then echoes an empty string and the line becomes 'const invoice = ;', a SyntaxError that disables the whole payment-page script (no QR, no polling). A mint that returns invalid UTF-8 in the bolt11 field can therefore blank its own checkout page. It discloses nothing and the mint already controls whether that invoice can be paid, so it is not a security finding, but json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE) or an explicit json_encode(...) === false check would make the page degrade instead of die. The same shape exists in admin.php:4271-4304 and 5743 for the embedded URL constants.
- admin.php:602 (?api=invoices): only the upper bound of limit is clamped, so limit=-1 reaches SQLite as 'no limit'; the Greenfield handler clamps both ends (includes/api/invoices.php:92). The action is admin-only and returns only what the operator can already see, so there is no disclosure, but the two read paths should agree.
- admin.php:2623: the admin page is a public login screen by design, and it also mints a session and a CSRF token for anonymous visitors (admin.php:2636). Both are harmless, but every future value added to that shell should be treated as public unless it is explicitly gated, which is exactly the defect the candidate records.
- api.php's CORS policy is 'Access-Control-Allow-Origin: *' with no Access-Control-Allow-Credentials (api.php:24-26). Safe as written because the API reads no cookie; if any API handler is ever added that reads a session, the wildcard would become a credential-disclosure bug.
- GET /api/v1/server/info is intentionally unauthenticated and returns only version and time fields, and the default PHP SAPI adds 'X-Powered-By: PHP/x.y.z' to every response, disclosing the stack to anonymous callers.
- Auth::hasPermission() strips a ':storeId' scope suffix from a stored permission before comparing it, so a permission recorded as scoped to another store still grants the base permission on the key's own store. The store dimension comes from the key row's store_id, so this is not a cross-store bypass.
- apiKeyIsUnscoped() treats any '*' permission as unscoped, so a store-scoped '*' key can POST /stores and create an extra store row; the created store holds no funds and the creating key cannot act on it.
- A GET on an invoice calls Invoice::pollSingleQuote(), which can settle the invoice and fire its webhooks - a state change from a canviewinvoices key. Benign here because only the mint's quote state can trigger the settle.
- includes/api/invoices.php:78-80 returns Invoice::create()'s exception message verbatim to the API client. Locally that message disclosed the store's own mint host; it never carried the seed or a key, but a generic message plus a log reference would be safer.
- The cron key gates the endpoint, not the runner. BackgroundRunner::run() is reachable without any credential from three doors: the WordPress scheduler hook (wordpress/cashupay.php:31-48), the server's own self-request, which any anonymous API request or payment-page view provokes (api.php:50 / payment.php:43 -> includes/background.php:23-58 -> cron.php?internal=1, locally demonstrated), and the admin 'Run now' button (admin.php:1103-1112). That is not a privilege boundary, because every task's effect is decided by the operator's own configuration and by the mint's answers, the triggerer chooses no task, no budget, no destination and no amount, and the run document is only echoed by the credentialed endpoint. If the product ever wants 'only the operator's own scheduler starts background work' to be a real control, the door to close is Background::trigger(), not the WordPress hook.
- The setup wizard's 'Recommended: set up a cron job' panel (setup.php:1746-1757) presents the cron key as the thing that gates background work. In WordPress mode the plugin already runs the same task list every minute through WP-Cron, so the suggested crontab is redundant there and the key is never needed; the panel could say which mechanism already runs the tasks in the operator's deployment instead of implying the key is the gate.
- In WordPress mode the runner can start twice per minute (the 'every_minute' schedule at wordpress/activation.php:150-152 plus the API self-trigger, coalesced to 30 s by includes/background.php:14-30) instead of the one-minute crontab the operator is told to install. The extra work is bounded by the runner's own gates (per-invoice poll interval 30 s and batch 10 at includes/invoice.php:383-408, 5-minute proof sync at includes/background.php:116-120, 6-hour update check at includes/update_check.php:39, 360 s lease at includes/background_runner.php:42), so it costs some CPU and mint round-trips rather than unbounded work.
- The internal branch of cron.php returns the same run document as the external branch, so a holder of internal_background_key learns the store names, mint URLs and auto-melt amounts. That key is generated on demand (includes/background.php:91-99) and is handed to the server's own origin on every trigger, so it deserves the same handling as the external cron key; the header transport (includes/background.php:44) and the constant-time comparison (includes/background.php:108) are already right.
- includes/api/webhooks.php#validateWebhookUrl accepts a webhook URL that contains control characters (CR/LF); PHP's parse_url folds them into the path and cURL then rejects the request ('URL rejected: Bad path'), so there is no request splitting today - but the refusal comes from libcurl, not from the application. Rejecting control characters in the URL (as parseWebhookSecret already does for the secret, includes/api/webhooks.php:252) would keep the protection if the HTTP client ever changes.
- Webhook URL and signing secret are snapshotted into webhook_deliveries at enqueue time (webhook_sender.php:166), so rotating a webhook's URL or secret does not re-target deliveries that are already queued. That is the documented outbox behaviour for a deleted webhook (includes/api/webhooks.php:154-155) and the queued events predate the rotation, so it is not a leak - but an operator who rotates a compromised receiver's secret should know the backlog (up to 80 attempts over ~72 h) still goes to the old URL with the old secret.
- Config::testMintConnection discards the value of Security::sanitizeUrl and tests the original string instead (includes/config.php:612); the sanitized copy is only used as a null check. Nothing exploitable follows today because Cashu\MintClient::canonicalizeMintUrl re-parses the URL and cURL rejects malformed paths, but comparing against the sanitized value would remove the parser differential.
- The rate API clients in includes/rates.php use fixed host constants selected by a provider name (rates.php:24, 64, 115) and never a caller-supplied URL, so they are not an SSRF path; no rate provider accepts a URL from the store settings.
- scripts/build-standalone.sh and scripts/build-wordpress-plugin.sh copy the freshly rebuilt mint-discovery bundle into the artifact and only print a warning when it differs from the committed bundle, so the bytes that ship are not the reviewed bytes by construction; neither script verifies that the submodules are at the commits the superproject pins. For the release inspected here all three matched byte for byte.
- includes/auth.php:504-555 keeps the dashboard's own Greenfield key in cleartext in stores.internal_api_key while every other key is stored only as a sha256 digest. The value never leaves the server and the same database already holds the wallet seed, so this is a consistency question rather than a disclosure.
- includes/config.php:69-82 stores a string config value without JSON-encoding it and reads it back with json_decode, so a 32-character bare-hex secret (the cron token generated at cron.php:56) turns into a number in roughly one key in 1.1 million, after which the hash_equals() at cron.php:59 can never match and the background runner stops silently.
- The kill switch has no remote lift: once a notice has been ingested, the flag file keeps the install shut down until a release lists the notice id in SafeMode::RETIRED_NOTICES or an operator deletes the file by hand.
- The manifest's own metadata - version, severity, notes - is not signed, unlike the kill switch. It is only ever shown to the operator and the upgrade link is constrained to https on the manifest host or github.com/jooray/cashupayserver/releases.
- RELEASE.md tells the operator to overlay the new files on the old tree and to keep the data folder, but never says to delete files that the new release removed; assets/js/mint-ui.js, the XSS-bearing script dropped in 6debd19, therefore stays on every upgraded installation.
- .github/workflows/tests.yml uses floating action tags and declares no permissions block; the workflow runs only the test suite, holds no secrets and publishes nothing, but pinning the actions to commit SHAs and adding 'permissions: contents: read' would remove the mutable-input surface.
- cron.php still accepts the cron key in the query string (?key=), which puts a 128-bit credential in web-server and CDN access logs; setup.php:1757 recommends the X-Cron-Key header and warns about the query form, but the query form stays accepted for existing crontabs.
- Config::get() json-decodes every stored config value, so a cron or internal key that happens to consist only of digits is read back as a number; hash_equals() then compares a formatted float against the supplied string and the key never matches, silently disabling the operator's cron. Storing string values JSON-encoded (as the legacy upgrade path did, tests/upgrade_path.php:53) would remove the ambiguity.
- includes/background_runner.php renewLease() fails open: when the lease renewal cannot take the write lock (:418-423) or throws (:432-436) it returns true and the run continues although the lease may already have been taken over. Failing closed there would stop a run that can no longer prove it is the only one.
- The lease is renewed only before each task, and the always-run tasks sit outside the time budget, so a single slow task (a mint round trip in poll_quotes, a webhook delivery in webhook_outbox) can outlast LEASE_SECONDS (360 s) and let a second run take over. The money paths stay safe because the per-store funds lock and the pending-withdrawal guard serialise them, but the duplicated work and the duplicated webhook attempts are avoidable.
- cron.php does not call set_time_limit(0) although admin.php's 'Run now' does (admin.php:1110); a cron run killed by max_execution_time or by a proxy timeout therefore leaves the lease held for up to 360 s and writes no heartbeat, no cursor and no task health.
- Invoice::recoverExpiredPaidInvoices() writes quote_id = NULL with no condition on the invoice's status, so the same write can erase the link of an invoice another worker has meanwhile claimed ('Processing') or already settled. Adding status IN ('Expired','Invalid') to the WHERE clause closes that race independently of the confirmation delay.
- The same-unit conversion path of ExchangeRates::convertToMintUnit() (reached from Invoice::create) truncates instead of rounding up and skips the store's exchange fee, so a fractional same-unit invoice is undercharged; the cross-unit path rounds up and does apply the fee (includes/rates.php:349-368).
- setup.php:114 gates the 'move my data out of the website' action on a valid CSRF token and on !$claimedElsewhere but not on $setupTokenRequired, so on a fresh, unclaimed installation any visitor who has read the setup page can trigger the move (observed in the sandbox). The effect is the operation's stated purpose - the data folder ends up outside the web root - and it is refused once a store holds a seed, so no funds are at risk; gating it on $setupTokenRequired as well would keep the file move for the operator alone.
- includes/data-location.txt records the absolute server path of the relocated data folder and lives inside the web root. The .htaccess rule '^includes/' protects it under Apache, but the 'php -S ... router.php' mode and an nginx deployment without a matching rule serve it (a path disclosure only, no secret).
- PaymentRequest::cleanup() removes only rows in status 'pending'; rows in status 'paid' and 'receiving' are kept forever so that a retry can replay the answer, so the payment_requests table grows with the number of payments and with claims that never resolved. The creation side is bounded per client address by the 30/minute limit on the public GET.
- The pairing page deliberately lets the redirect target be a loopback address (Security::isSafePublicHttpUrl($redirect, true)) so that a shop running on the operator's own machine can pair; the target host is shown to the operator before the key is handed over, and the key is only released after the operator presses 'Authorize'.
- The setup recovery code is 40 bits and rate limited to 10 attempts a minute per client address; the address is derived by Security::getClientIp(), whose 'auto' mode trusts forwarded headers from a private-range or loopback peer (see the uncovered entry).
- The expiry sweep's InvoiceExpired event goes through the same durable outbox as every other event (includes/webhook_sender.php:38-52, :159-168) and the outbox has a UNIQUE index on idempotency_key (includes/database.php:607), so a repeated or interrupted sweep cannot deliver a duplicate expiry event - the sweep's only defect is that it is unbounded, not that it can double-fire.
- The 'expire_old_invoices' rotating task (includes/background_runner.php:135-141) is a single bulk UPDATE and therefore bounds the 'New' backlog to 30 days of invoice creation in a healthy installation; it needs no per-row work and does not load rows into memory. It emits no InvoiceExpired webhook, so a shop still depends on the sweep for the event.
- The wallet library's pending-mint journal is committed before the mint request (cashu-wallet-php/CashuWallet.php:5153, :5173) and finalizePendingMint() stores the proofs and deletes the journal in one transaction (:5485-5503), so a failure of the proof write is recoverable through NUT-09 /restore rather than a destruction of the proofs - the 'never lose coins' invariant is not broken by the lock contention, only the settlement timing is.
- The customer-facing paths (payment.php:40 and includes/api/invoices.php:121) call Invoice::pollSingleQuote(), not the sweep, so paid invoices keep being settled by the shop plugin's polling even when the background runner is dead; only bulk expiry, the outbox, the recovery and the cleanup tasks are lost.
- The same-unit branch of convertToMintUnit (includes/rates.php:349-351) returns before the exchange fee and before the round-up. The fee skip matches the operator-facing wording of the setting ('Fee added to currency conversions', admin.php:3739-3742), so it is not a bypass; the truncation does contradict the rule stated in the code ('rounding never makes the merchant undercharge', includes/rates.php:365-368), but costs at most one smallest unit (1 sat, 1 cent) per invoice. Already recorded in docs/audits/SECURITY-OPUS55.md:200.
- An amount below the mint's smallest unit ('0.009' EUR on a EUR mint, '0.001' sat on a sat mint) converts to 0, but Wallet::requestMintQuote refuses amount <= 0 before any mint call (cashu-wallet-php/CashuWallet.php:7067-7069), so the invoice fails closed and no zero-value invoice is ever settled.
- The invoice currency is chosen by the API client (includes/api/invoices.php:36-38) and becomes part of the price-cache key ('rate_' . strtolower($currency), includes/rates.php:305-312, :529), so every new currency code makes the server ask up to three price hosts. The number of distinct cache keys is bounded by the currencies the providers support and the request rate by api.php's 100/min/IP limit (api.php:54): third-party amplification, not unbounded growth, and no key is written unless a provider actually answered.
- The exchange_rate stored on the invoice is a second, independent getBtcPrice() call (includes/invoice.php:150) and can differ from the rate actually used for the conversion when the 300 s cache expires between the two calls; nothing downstream compares the two.
- When every provider fails, a rate up to an hour old is used silently (includes/rates.php:292, STALE_TTL at :189); the operator can see the age and the supplying provider in the dashboard (isStale(), getCachedProvider(), includes/rates.php:636-675).
- Confirmed the already-recorded header-trust default: Security::getClientIp trusts a loopback or private-range peer's forwarded headers in the default 'auto' mode (includes/security.php:671-680, walk at :536-553), so behind such a peer the client can pick the address that keys every quota. It is recorded separately as includes/security.php:getClientIp:auto-mode-trusted-private-peer-forwarded-headers (also OPUS55 N5 and DS41flash1017 L-11) and is deliberately not raised again here; the candidate above is the missing second dimension in the quota accounting, which stands on its own.
- The composition is sound in every other respect: the attempt is reserved atomically before the password check (includes/security.php:66-88, withWriteLock :150-169), the counter is monotonic inside one bucket, the two public password surfaces share one lockout key (admin.php:750 and api-keys/authorize.php:137), each action has its own quota bucket, the rate limits sit before the expensive work (api.php:54 before routing and authentication, receive.php:128 before parsing and the mint round-trip, setup.php:153 and :288 before the mint fetch, admin.php:841 and :874 before the password check), and both quota functions fail closed when the store cannot be written.
- checkRateLimit() does not fail closed the way its own comment claims: Database::getInstance() (includes/security.php:24) and BEGIN IMMEDIATE (:27) run before the try block (:29), so a storage failure escapes as an uncaught PDOException instead of the intended false (:46). reserveLoginAttempt() catches the same failure and returns false (:83-87). Not a bypass - the request does not proceed - but api.php and receive.php answer 500 rather than 429, and admin.php:822-825 echoes the raw PDO text to the browser. Smallest fix: move those two statements inside the try.
- IPv6 addresses are not aggregated: the bucket is the full address (includes/security.php:371-384 and :67), so two addresses of one routed /64 are two budgets and one IPv6 host gets five attempts per address.
- The opposite direction of the same single-dimension accounting: when the derived address collapses - a trusted peer that forwards no client address (the peer address is used, includes/security.php:569) or an unusable REMOTE_ADDR (the constant 0.0.0.0, :524-527) - every client behind it shares one budget, so one client can exhaust another's quota (the fixture: 20 of 25 'receive' requests allowed from the shared bucket, then a second client refused). Not raised as a candidate: it needs a deployment fact (a trusted peer that forwards nothing) and the consequence is a soft 429 rather than a boundary crossing.
- The API's principal is the API key, but api.php:53-54 charges the 100/min quota to the derived address only, so no API key has a quota of its own and every key behind one egress address shares one budget.
- .gitignore does not cover includes/config.local.php (or *.local.php generally), even though that is the documented place for CASHUPAY_DATA_DIR and for CASHUPAY_SETUP_TOKEN - the setup takeover credential read by includes/setup_ownership.php:183. The release scripts strip it (scripts/build-standalone.sh:59-60, scripts/build-wordpress-plugin.sh:65-66) and git log --all shows the file has never been committed, so nothing is leaked today; adding 'includes/*.local.php' would remove the chance that a developer's copy is committed.
- docker/entrypoint-standalone.sh and docker/entrypoint-wordpress.sh ship fixed WordPress credentials (admin/admin) and fixed AUTH_KEY / SECURE_AUTH_KEY / LOGGED_IN_KEY / NONCE_KEY and salts. They are local test fixtures that print the credentials on purpose, and .github/workflows/tests.yml builds no image and publishes nothing, so they cannot reach a production installation.
- api.php answers every request with Access-Control-Allow-Origin: *, including the unauthenticated GET /server/info, so any origin can read the CashuPayServer version. The wildcard is safe because the API authenticates only with the Authorization header and never with an ambient cookie, but the version disclosure to any site is unavoidable under that design.
- Security::setSecurityHeaders() (includes/security.php:755-781) sets no Strict-Transport-Security header, and the default CSP allows 'unsafe-inline' for scripts and styles. Both are documented choices; HSTS additionally depends on the deployment terminating TLS.
- Config::getBaseUrl() still falls back to the request's HTTP_HOST until an operator pins base_url, so every absolute URL the application builds before that point is derived from a request header. The paths that would carry a credential already refuse to run without a pinned base_url (includes/background.php:67-75, includes/webhook_sender.php:239-241) and the URL paths that carry no host are path-only (includes/urls.php:71-75), so no redirect target or credential destination is affected; the fallback is only visible in displayed URLs.
- Security::isSafeAppBaseUrl() accepts http://, so a pinned base_url may be cleartext and the internal cron key then travels in the X-Cron-Key header over plaintext (includes/background.php:42-44).
- api-keys/authorize.php:140 redirects to $_SERVER['REQUEST_URI'] verbatim after a successful login; under router.php a path beginning with //host is protocol-relative, so the operator's browser can be sent off-site. Recorded as an observation only - the pairing flow belongs to the peer-owned api-keys/authorize.php unit.
- includes/config.php:69-82 stores string configuration values raw rather than JSON-encoded, so a purely numeric string is read back as an integer by Config::get() (json_decode). No caller depends on that today; json_encode() for strings would remove the type drift.

### 6.2 Positive source patterns worth keeping

- `Security::resolvePublicTarget()` — resolve once, judge every address, pin the connection with `CURLOPT_RESOLVE`. The webhook path uses it correctly and `tests/webhook_ssrf.php` pins the behaviour with a DNS-rebinding regression test. This is the pattern the mint path should adopt.
- `Auth::validateCsrfToken()`, `Auth::attemptLogin()` and `Auth::reserveLoginAttempt()` all use `hash_equals()` and reserve the attempt **before** the expensive check, so parallel guesses cannot slip under the limit.
- `Transfer::findPendingBySecrets()` matches `status='pending'` only — the status-guarded settle path that the unguarded sibling (`check_transfer`) should have used.
- `Invoice::claimForMinting()` and `Invoice::markSettledOnce()` are single conditional UPDATEs, so two workers cannot mint or settle the same invoice twice.
- The pending-mint journal in the wallet library is written *before* the mint request and committed, so an issued-but-unstored output is recoverable through NUT-09 `/restore`. This is the reason the expiry-sweep lock contention is a settlement stall rather than a loss of coins.
- The emergency kill switch verifies a real BIP-340 signature against two pinned x-only keys, recomputes the NIP-01 event id in the standard way (verified byte-for-byte against `node`), and re-verifies the stored notice on every read, so the flag file in the data directory cannot be edited to change what the notice says.
- The release artifacts themselves are clean: no `config.local.php`, no wallet database, no setup token, no `.git`, no local configuration — verified by opening both published zips.

---

## 7. Coverage summary

| Status | Meaning | Count |
|---|---|---:|
| `covered` | Unit closed with source paths and checks; no candidate raised | 18 |
| `candidate` | Unit closed and raised at least one finding (independently verified) | 32 |
| `blocked` | Unit closed with partial evidence and an unresolved fact | 0 |
| `in_progress` | Unit assigned and not yet closed at the end of the run | 0 |
| `deferred` | Unit not reached | 2 |

**Final critic result.** The run took five hunting waves, because each coverage critic found real gaps and the workflow's rule is to execute them rather than to defer them. The final-clean critic (a fresh reviewer with no prior context) proposed five further units after the fourth wave; the three most concrete were executed as the fifth wave, and the two that remained are recorded in the ledger as `deferred` with the critic's source-backed reason. One further proposal - reopening the setup-ownership unit - was rejected for lack of a source-backed reason and is recorded in section 1 as an unresolved disagreement.

**The two deferred units, quoted from the final critic.** They are real coverage gaps, not dismissed concerns, and they are the first place to look for a follow-up pass:

1. **`includes/database.php#ensureCurrentSchema` - the upgrade of an existing installation.** `Database::getInstance()` runs the migration on the first database access of every request, inside one `BEGIN IMMEDIATE` transaction. The steps change money and authority: version 10 grants `btcpay.store.canviewstoresettings` to every pre-existing `api_keys` row whose `application_identifier` is `woocommerce` (`includes/database.php:697-716`); `migrateExportedProofs` reclassifies PENDING proofs as EXPORTED, changing which ecash the recovery path can see (`:762-788`); and `migrateWalletAccounts` rewrites `wallet_id` across `cashu_proofs`, `cashu_counters`, `cashu_pending_operations` and `cashu_wallet_metadata` - it moves the ecash ledger between namespaces (`:816-900`). Its three abort cases (a namespace shared by two stores, a destination namespace that already holds data, a seed fingerprint mismatch; `:855-897`) throw from inside `getInstance()`, so every entry point dies with an uncaught fatal instead of a diagnosis: `Database::getMigrationError()` captures the message but is never called anywhere in the tree.

2. **`includes/background_runner.php#rotate_keyset_proofs|sync_proofs|recover_wallet_operations`** - the keyset-rotation and proof-sync lifecycle mode. The per-store funds lock is the ledger's stated serialisation for money movement, and the money-movement unit enumerates its holders: `admin.php:1062` (reclaim), `admin.php:1759` (manual melt), `admin.php:2022` (export) and `includes/lightning_address.php:324` (auto-withdrawal). These three runner tasks are not on that list. `rotate_keyset_proofs` calls `Wallet::rotateProofs()` for every store (`includes/background_runner.php:804-833`), which selects the store's UNSPENT proofs of a retiring keyset and swaps them, spending them and paying the mint's input fee (`cashu-wallet-php/CashuWallet.php:6669-6723`); `recover_wallet_operations` completes pending melts, swaps and mints for every store and every configured account and is what decides that money arrived (`includes/invoice.php:1729-1775`); `sync_proofs` marks the store's proofs SPENT from the mint's answer (`:776-802`). What stands between these and the export path is not the per-store lock but the library's per-proof reservation, reached only after the rotation has selected its proofs outside any transaction - whether that finer control is sound for these three callers, in particular whether a rotation can consume a proof the export path has already handed to a customer as bearer money, is asserted nowhere.

**Not covered, and why it matters.** The following are named surfaces this run did not close. They are not in the findings table and must not be read as clean.

- The GitHub release *identity* chain (who may publish, whether a release asset can be replaced under an existing tag, whether the manifest host is administered independently) is a hosted-GitHub and hosting fact. It is the blocker on `release/upgrade-chain-has-no-independent-integrity-root`.
- Whether a deployment derives its WordPress site URL from the request's Host header, and whether the front proxy routes an arbitrary Host to the vhost. It is the blocker on `background.selfCronUrl.wordpressSiteUrl` and on the WordPress data-directory findings' precondition.
- What the operator's front proxy does with `X-Forwarded-For`, `Forwarded` and `X-Real-IP`. It is the blocker on `includes/security.php:getClientIp:auto-mode-trusted-private-peer-forwarded-headers`.
- The effective `post_max_size` / `memory_limit` / disk quota of a deployed installation, which sets the reachable magnitude of the API body-size and expiry-sweep findings.

---

## 8. Appendix — how to re-verify

Every finding's reproduction is target-native and runs against the repository's own code with dummy data. The patterns:

- **PHP function-level checks** — a scratch script that `define('CASHUPAY_DATA_DIR', …)` points at a throwaway SQLite database, then calls the real class methods. The repository's own fixtures (`tests/conversion.php`, `tests/security_login_lockout.php`, `tests/api_test_harness.php`) show the same pattern and are the right starting point.
- **Local HTTP fixtures** — `php -S 127.0.0.1:<port> router.php` with the same `CASHUPAY_DATA_DIR` prepend, plus the repository's own Cashu mint fixture `tests/mock_mint_router.php` (with its `/__control/pay` and `/__control/rotate` control endpoints) for anything that needs a mint.
- **Anything that reaches the network** is out of scope for a local check and is reported as needs validation instead.

Reminder when reproducing: `php tests/run.php` in full performs an outbound HTTPS GET to `cashupayserver.org/version.json` (the `update_check` task) and will fail in an offline sandbox — run the individual test files instead.

