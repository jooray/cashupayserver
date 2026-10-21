# CashuPayServer — Security Audit (DS41-Flash-1017)

**Date:** 2026-10-20
**Source reviewed:** `4a82d01` (v0.5.6-alpha), clean working tree
**Submodules:** `cashu-wallet-php` @ `7751f34`, `mint-discovery` @ `b1b5c07`
**Scope:** the whole standalone application, the Greenfield API, the pairing flow, the
setup wizard, the payment/receive endpoints, the outbound-request layer, secret and file
handling, and the WordPress plugin. Read-only static review.
**Method:** parallel source hunts by trust boundary (auth/session, API authz, money path,
SSRF/update chain, setup/storage/files, XSS/WP), then every candidate re-read by the
reporting auditor against the current source. No code was modified. No request was sent
to any deployed instance, no network access, no test run against live data.

This audit is independent and was written only to this file. Another agent auditing the
same repository (output under `run-1`) was not read or written.

Prior consolidated audit: `AUDIT.md` (last updated at v0.5.4/v0.5.5). Where an issue below
is already known, it is marked as a carry-forward and not counted as a new finding.

---

## Summary

| ID | Finding | Severity |
|---|---|---|
| **H-1** | Any authenticated admin session can replace the admin password through `setup.php?mode=add_store&step=2` — no current-password check, no session revocation | **High** |
| **M-1** | A payment request can be marked *paid* by replaying a token the store already spent — no new money moves | **Medium** |
| **M-2** | `base_url` pinned by the first setup claimant survives takeover; the internal cron key is then sent to that origin and the shop is told to use it | **Medium** |
| **M-3** | A half-finished store planted by a previous claimant is silently adopted by the legitimate operator, including its mint | **Medium** |
| **L-1** | "View store settings" also grants read access to every invoice, but the pairing page shows only the former | Low |
| **L-2** | `GET /stores`, `GET /stores/{id}` and `GET /stores/{id}/payment-methods` require no permission at all | Low |
| **L-3** | `action=test_mint` skips the mint SSRF gate and returns the internal service's response body | Low |
| **L-4** | Mint URLs are vetted once at save time but never pinned; a rebinding mint reaches internal hosts for the life of the install | Low |
| **L-5** | Only `safe_mode` is signed in the update manifest; `version`, `severity`, `notes` and `url` are unauthenticated | Low |
| **L-6** | Setup can be completed (and the recovery code deleted) without ever setting an admin password | Low |
| **L-7** | Pairing approval allows a loopback `redirect`; the freshly minted API key is POSTed to it | Low |
| **L-8** | Unauthenticated `GET /admin.php` discloses the absolute data-directory path | Low |
| **L-9** | Deleting or repointing a webhook does not stop already-queued deliveries to the old URL | Low |
| **L-10** | No idle session timeout; logout leaves the session cookie and ID usable | Low |
| **L-11** | Client-supplied `X-Forwarded-For` / `Forwarded` / `X-Real-IP` are believed from any private-network peer | Low |

No unauthenticated remote code execution, SQL injection, cross-tenant data access, or
direct fund-theft primitive was found in the current source. The strongest issue (H-1) is
a post-authentication escalation that turns a stolen or unattended session into permanent
control, including of the seed phrase.

---

## Confirmed findings

### H-1 — Setup wizard lets any logged-in session overwrite the admin password

**Severity: High.** It fully defeats the password barrier that protects the seed phrase and
the database backup, and it leaves every other session alive, so the takeover is silent
and permanent.

**Where:** `setup.php:44`, `setup.php:91-93`, `setup.php:183-184`, `setup.php:217-231`,
`includes/auth.php:153-156`. Contrast the correct path at `includes/auth.php:167-191`.

```php
// setup.php:44 — the completed-install gate is skipped for add_store
if ($setupComplete && $mode !== 'add_store' && ($_POST['step'] ?? '') !== '7') {
// setup.php:91-93 — the step comes straight from the request
$requestedStep = $_POST['step'] ?? $_GET['step'] ?? null;
if (is_string($requestedStep) && ctype_digit($requestedStep)) { $step = (int)$requestedStep; }
// setup.php:217-228 — password step: no current-password check, no version bump
case 2:
    Auth::setAdminPassword($password);
```

```php
// includes/auth.php:153-156
public static function setAdminPassword(string $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    Config::set('admin_password_hash', $hash);
}
```

**Preconditions:** the installation is complete, the attacker holds a valid admin session
(stolen cookie, shared/unattended machine, or an XSS in the admin origin), and can read the
session's CSRF token — which `setup.php` itself renders (`setup.php:336`). Note that
`admin.php`'s client-side "Lock" button (`admin.php:6604-6610`) does not end the server
session, so walking away from an unlocked dashboard is exactly this precondition.

**Attack path:**
1. With the session, `GET /setup.php?mode=add_store` → passes line 40 (session) and line 44
   (`mode === 'add_store'`), regardless of the request's step.
2. `POST /setup.php?mode=add_store` with `step=2`, the new password, and the session CSRF
   token. `$setupTokenRequired` is false because setup is complete (`setup.php:59`).
3. `case 2` stores the new hash. `admin_password_version` is untouched
   (`includes/auth.php:69-72`), so the operator's own session keeps working and nothing is
   logged out.
4. The attacker now knows the password and can pass the re-authentication gates on
   `reveal_seed` (`admin.php:833-867`) and `download_backup` (`admin.php:869-899`) — seed
   phrase and full database, i.e. all current and future funds.

**Impact:** permanent operator takeover from a session-only compromise; silent (the operator
is not logged out and no password-change notice is produced).

**Fix (smallest effective):** in `case 2`, when `Config::getAdminPasswordHash() !== null`,
require `Auth::verifyAdminPassword($current)` (rate-limited) and call
`Auth::changeAdminPassword()`, which bumps the version and rotates the session; or simply
reject `step=2` in `mode=add_store` and restrict that mode to steps 4–6. Also render the
step from server state rather than from an arbitrary POST value.

---

### M-1 — Payment-request receipt can be forged by replaying a spent token

**Severity: Medium.** Unauthenticated, repeatable, and it makes the server assert "paid"
without any money arriving. It does not touch the BTCPay invoice/order flow, but
`/receive.php` is a published payment link.

**Where:** `receive.php:270`, `receive.php:358-375`, `includes/payment_request.php:144-172`,
`includes/payment_request.php:180-218`.

```php
// receive.php:270 — the request is bound to whatever secrets the sender supplied
$claim = PaymentRequest::claim($paymentRequest, $secrets);
```
```php
// includes/payment_request.php:160-164 — "paid" means the secrets are SPENT in our wallet,
// not that this request's swap produced them
$spent = array_filter($rows, fn($r) => $r['state'] === \Cashu\ProofState::SPENT);
if (count($spent) === count($secrets)) {
    $proofs = $storage->getProofsBySecretsAsObjects($secrets);
    $amount = \Cashu\Wallet::sumProofs($proofs) - $wallet->calculateFee($proofs);
    return ['state' => 'paid', 'amount' => max(0, $amount)];
}
```
```php
// receive.php:358-375 — on failure only `released` frees the request; `paid` leaves it
// bound, and `reconcileReceiving()` (payment_request.php:197-207) later marks it paid.
if ($state['state'] === 'released') { PaymentRequest::release($paymentRequest, $secrets); }
```

**Attack path (all unauthenticated):**
1. As a customer, pay one real request to the store (or receive any token the store
   exported). Keep the token string — it contains the proof secrets; spending does not
   destroy it.
2. Those proofs are now `SPENT` in the store's `cashu_proofs` (same `wallet_id`).
3. Create a new request for the same store, for any amount up to the old token's value:
   `GET /receive.php?store_id=…&amount=…&format=json` (30/min/IP, `receive.php:695`).
4. `POST` the old token with `?request_id=<new>`. `claim()` succeeds; the amount check
   passes; `Wallet::receive()` refuses the spent token; the catch block asks
   `receiptState()`, which sees every secret `SPENT` and returns `paid`.
5. A retry of the same token hits the `same` branch (`receive.php:287-295`) and calls
   `markPaid()` immediately; otherwise the background task does it.
6. The status URL now serves `Paid ✓ (N SAT received)` for money that never arrived.

**Impact:** fabricated receipts on the merchant's Request Payment page. A merchant who
treats that page as a payment link hands over goods for free, repeatedly, using one past
payment. `payment_requests` rows are not linked to invoices, so the Greenfield/order path
is unaffected.

**Fix (smallest effective):** distinguish "these proofs were already ours before this
claim" from "this claim's swap credited them". Record the claim time and only accept
`paid` when the proofs became `SPENT` after `receiving_since`, or bind the request to the
swap journal/transaction it started and let only that journal mark it paid. A replay of an
old token must end as `released`, not `paid`.

---

### M-2 — A hostile `base_url` pinned during setup survives the legitimate takeover

**Severity: Medium.** The attacker must win the first setup race (or otherwise claim first),
but the payoff is a persistent redirection of the merchant's integration and a leaked
internal credential, and nothing in the UI corrects it.

**Where:** `setup.php:207-212`, `includes/setup_ownership.php:121-133`,
`includes/setup_ownership.php:141-173`, `includes/background.php:32-44`,
`includes/background.php:67-75`.

```php
// setup.php:207-212 — accepted from the POST body and never revisited once set
if (!Config::get('base_url')) {
    $detectedBaseUrl = trim(setupField('detected_base_url'));
    if ($detectedBaseUrl !== '' && Security::isSafeAppBaseUrl($detectedBaseUrl)) {
        Config::set('base_url', rtrim($detectedBaseUrl, '/'));
    }
}
```
```php
// includes/background.php:42-44 — the internal key goes to whatever base_url names
$ch = curl_init($url . '?internal=1');
curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['X-Cron-Key: ' . self::getInternalKey()], …]);
```

**Attack path:** the attacker opens a fresh install, submits step 1 with
`detected_base_url=https://attacker.example`, and stops without setting a password. The
claim idles out after 3 h (`includes/setup_ownership.php:54-55, 285-288`), or the merchant
uses the recovery code. When the merchant continues, `base_url` is already set, so their
real origin is ignored. The wizard then displays the attacker's URL as "Your Server URL";
`Urls::admin()`, `Urls::payment()`, `Urls::pairing()` and the setup's WooCommerce
configuration all use it, and every background trigger sends `X-Cron-Key` there.

**Impact:** the merchant configures their shop plugin with the attacker's URL. The attacker
then sees the plugin's API key, can answer invoice requests with their own BOLT11 (customer
pays the attacker), and receives the internal cron credential. The "Go to admin" link also
points at the attacker (password phishing). This is optional in the attack: the same
attacker could simply complete setup, but this variant is stealthy — the merchant's own
install keeps running and looks normal.

**Fix:** clear wizard-scoped state (`base_url`, `setup_created_stores`, `setup_store_id`)
when a claim is rotated (`verifyOwnershipToken`) or released as idle; and treat a
`detected_base_url` whose host differs from the request's `Host` like `save_base_url` does
(`admin.php:947-952`): require an explicit confirmation.

---

### M-3 — An abandoned store from a previous claimant is adopted by the operator

**Severity: Medium.** Same setup precondition as M-2; the result is that every payment is
issued against a mint the attacker chose.

**Where:** `setup.php:240-256`.

```php
$createdHere = in_array($existingStore['id'], $_SESSION['setup_created_stores'] ?? [], true)
    || ($_SESSION['setup_store_id'] ?? null) === $existingStore['id'];
$unfinished = empty($existingStore['seed_phrase']);
if ($unfinished && ($createdHere || !$setupComplete)) {
    $_SESSION['setup_store_id'] = $existingStore['id'];
    $step = empty($existingStore['mint_url']) ? 5 : 6;
```

**Attack path:** the attacker claims, posts `step=4&store_name=My Store` (the form's
default, `setup.php:1567-1568`), posts `step=5&mint_url=https://attacker-mint.example`, and
stops. No password was set, so the claim expires. The merchant recovers, sets a password,
submits the store form, and — because `!$setupComplete` is true during setup — is switched
into the attacker's store. If its mint is already set, the wizard jumps straight to the seed
step, where the mint is not shown.

**Impact:** the merchant's invoices are created against the attacker's mint. The attacker
controls the Lightning node and the ecash keys: they can keep the sats, issue worthless
ecash, and refuse melts, while the dashboard shows a balance. Every future payment is lost.

**Fix:** adopt an existing store only when `$createdHere`; otherwise show "a store with this
name already exists" and require a different name (or verify the store belongs to the
current claim, e.g. by a claim-scoped id). Clear `setup_created_stores` on claim rotation.

---

### L-1 — "View store settings" silently includes reading all invoices

**Severity: Low.** The permission text is the operator's only informed-consent control when
approving a pairing link.

**Where:** `includes/auth.php:308-317`, `api-keys/authorize.php:45`.

```php
'btcpay.store.canviewstoresettings' => [ 'btcpay.store.canviewinvoices' ],
```
```php
'btcpay.store.canviewstoresettings' => 'View store settings',
```

A pairing link asking only for `btcpay.store.canviewstoresettings` is displayed as one line,
"View store settings"; after approval the key can call `GET /stores/{id}/invoices` and read
every invoice's amount, metadata and BOLT11. **Fix:** label it "View store settings and
invoices", or remove the implication.

---

### L-2 — Store read endpoints require no permission

**Severity: Low.** A key created with an empty permission set (a legitimate pairing choice)
can still read the store record.

**Where:** `includes/api/stores.php:13-17`, `:34-40`, `:219-227`.

`handleGetStores`, `handleGetStore` and `handleStorePaymentMethods` call `requireStore()`
but never `requirePermission()`. The exposed fields are only `id`, `name`, `created_at` and
a static payment-method description, so the blast radius is small; it is still a deviation
from the BTCPay permission the route represents. **Fix:** require
`btcpay.store.canviewstoresettings` on all three.

---

### L-3 — `action=test_mint` bypasses the mint SSRF gate

**Severity: Low.** Admin session plus CSRF token required, so it is a post-compromise tool,
not a cross-site one — but it is the only mint entry point without the gate, and it returns
the internal response body.

**Where:** `admin.php:2503-2511`, `includes/config.php:608-620`. Contrast
`admin.php:1317` and `admin.php:2404`, which call `MintHelpers::assertSafeMintUrl()`.

```php
$result = Config::testMintConnection($mintUrl);   // only sanitizeUrl() inside
…
$info = $client->get('info');                     // full body returned to the caller
```

`http://127.0.0.1:PORT/`, RFC1918 and `169.254.169.254` all pass `sanitizeUrl()`.
**Fix:** move `MintHelpers::assertSafeMintUrl()` into `Config::testMintConnection()` so every
entry point is covered at one place.

---

### L-4 — Mint URLs are checked once but never pinned

**Severity: Low.** Requires the operator to add a mint that is hostile or whose DNS is later
taken over; the mint is already trusted with the merchant's money, so the incremental impact
is internal-network reachability rather than fund loss.

**Where:** `includes/mint_helpers.php:37-55`, `cashu-wallet-php/CashuWallet.php:3727-3733`.

```php
$target = Security::resolveTarget($canonical);   // validated …
return $canonical;                                // … but only the string is kept
```
```php
$url = $this->mintUrl . '/v1/' . ltrim($path, '/');
curl_setopt_array($ch, [CURLOPT_URL => $url, …]);   // re-resolved, no CURLOPT_RESOLVE
```

`includes/security.php:212-214` states the rule ("code that goes on to connect should use
`resolvePublicTarget()` and pin the addresses"), and `webhook_sender.php:314` follows it; the
mint client does not. A TTL-0 mint host can answer the save-time check with a public address
and every later `/v1/info`, `/v1/keysets`, quote, swap and melt with a private one. Response
text from the internal service is partly surfaced through mint errors. **Fix:** thread the
resolved addresses from `assertSafeMintUrl()` into the wallet client (or re-vet and pin
inside `MintClient::request()`), as the webhook sender already does.

---

### L-5 — Only the shutdown notice in the update manifest is signed

**Severity: Low.** No code execution; a compromised manifest host already controls the update
story, but the kill switch is deliberately the one thing that must not be forgeable, and the
rest of the document is treated as trusted on the same fetch.

**Where:** `includes/update_check.php:226-252`, `includes/safe_mode.php:234-254`.

`SafeMode::ingest()` verifies only `$manifest['safe_mode']`; `version`, `min_supported`,
`severity`, `notes`, `released` and `url` are then consumed as-is (with shape checks). A
compromised `cashupayserver.org` (or `CASHUPAY_UPDATE_URL` host) can therefore raise a red
"Security update available: v9.9.9" banner with attacker text and an upgrade link on that
host, and can suppress a real notice by serving an older version. **Fix:** sign the whole
manifest (same pinned keys) and only act on signed fields; fold `version`/`url` into the
signed payload.

---

### L-6 — Setup can complete without an admin password

**Severity: Low.** An attacker who holds the claim can already set a password, so this is not
an escalation; its real effect is an install that nobody can log into.

**Where:** `setup.php:183-431`, `setup.php:217-231`, `setup.php:422-423`.

The step is taken from the request; only `case 2` calls `Auth::setAdminPassword()`, and
`case 6` sets `setup_complete` and deletes the recovery code without checking that a hash
exists. A merchant who jumps to step 4 (back button, edited form, resumed wizard) completes
setup with `admin_password_hash = null`; `Auth::login()` then always fails
(`includes/auth.php:80-83`), and setup refuses to reopen because it is complete. The
recovery credential is gone. **Fix:** refuse `case 6` when
`Config::getAdminPasswordHash() === null` in standalone mode, and enforce step order
server-side.

---

### L-7 — Pairing approval permits a loopback redirect

**Severity: Low.** Requires the operator to approve a crafted pairing link and a local
listener; the operator is shown the host, which is the only mitigation.

**Where:** `api-keys/authorize.php:186`, `api-keys/authorize.php:226-237`,
`includes/security.php:237-244`.

```php
if ($redirect !== null && !Security::isSafePublicHttpUrl($redirect, true)) { … }
```

The `true` exempts `127.0.0.1`/`::1` from the private-range check, and the approved key is
then POSTed to that URL. The deny path deliberately does *not* pass `true`
(`authorize.php:246-259`), with a comment explaining why. **Fix:** drop the localhost
exemption on the approve path, or require an explicit confirmation when the redirect host is
loopback/private.

---

### L-8 — Unauthenticated admin page discloses the data-directory path

**Severity: Low.** Reconnaissance only, but it names the exact directory whose exposure the
project treats as the highest-value failure mode.

**Where:** `admin.php:309-312` (the SPA is served without a login check), `admin.php:3812`.

```php
<code …><?php echo htmlspecialchars((string)(realpath(Database::getDataDir()) ?: Database::getDataDir())); ?></code>
```

`curl https://target/admin.php` without a cookie returns the absolute path, e.g.
`/home/acct/domains/x/public_html/cashupayserver/data` — which, on a server that ignores
`data/.htaccess`, is the URL of `cashupay.sqlite`, its `-wal` and `sessions/sess_*`.
**Fix:** render that line from an authenticated `?api=` response instead of in the shell.

---

### L-9 — Webhook deletion does not revoke queued deliveries

**Severity: Low.** Intentional per the code comment, but it means "delete" is not a
revocation for data already in the outbox.

**Where:** `includes/api/webhooks.php:154-161`, `includes/webhook_sender.php:160-167`.

```php
// Keep the row as a tombstone so its foreign-key-linked committed outbox events
// remain deliverable using their snapshotted URL and signing secret.
Database::update('webhooks', ['enabled' => 0, 'deleted_at' => …], 'id = ?', [$webhookId]);
```

Pending rows keep `target_url`/`signing_secret`. An operator who deletes a webhook because
the receiver is compromised still has invoice events POSTed there until delivered or the
retry budget (80 attempts) is exhausted. **Fix:** on delete/URL change, mark undelivered
rows for that webhook as cancelled (or clear `target_url`), and let the operator opt in to
"deliver what was already committed".

---

### L-10 — No idle session timeout; logout leaves the cookie and ID in place

**Severity: Low.** A stolen cookie or an abandoned browser keeps full operator authority for
the rest of the 24 h window.

**Where:** `includes/auth.php:45-51` (only `login_time`), `includes/auth.php:144-148`.

```php
public static function logout(): void {
    self::initSession();
    $_SESSION = [];
    session_destroy();      // cookie is not cleared; PHP may reuse the ID
}
```

`isLoggedIn()` never checks a last-seen time, and `session.use_strict_mode` is not enabled
anywhere. The app's own lock screen warns that the server session stays open
(`admin.php:6607-6608`). **Fix:** track `last_seen` and expire after a configurable idle
period; clear the cookie on logout (`setcookie(..., time()-3600)`), call
`session_regenerate_id(true)` and/or enable strict mode.

---

### L-11 — Forwarded headers are believed from any private-network peer

**Severity: Low.** Documented trade-off for LAN installs, but it turns the login lockout and
every per-IP rate limit into attacker-chosen buckets on those deployments.

**Where:** `includes/security.php:528-530`, `:559-567`, `:653-682`, `:609-611`;
counter key at `includes/security.php:66-68`.

```php
// security.php:559-567 — reached when the peer is trusted and X-Forwarded-For is absent
$forwarded = self::forwardedFor();                 // HTTP_FORWARDED: client-set
if ($forwarded !== null) { return $forwarded; }
$real = self::normalizeIp((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
if ($real !== null) { return $real; }
```

In `auto` mode any private/loopback peer is "trusted" (`:671-680`), so on a LAN instance, or
behind a proxy that sets only `X-Real-IP`, a client can pick its own address per request.
The lockout bucket is the full address string, so an IPv6 /64 gives 5 attempts per address
with no aggregation. **Impact:** unlimited online password guessing against the 8-character
minimum (`includes/auth.php:159`, `setup.php:221`), and the same for the recovery code, seed
and backup gates. **Fix:** believe `Forwarded`/`X-Real-IP` only in `custom` mode with
explicit proxies (or when the operator opts in); aggregate IPv6 to /64; add a per-username
and global backoff independent of the address.

---

## Needs validation (no severity)

These are source-grounded hypotheses blocked by a deployment fact that the repository cannot
establish. Per the audit rules they are not assigned a severity.

**N-1 — The data directory is inside the web root and protected only for Apache.**
Default data dir is `includes/../data` (`includes/database.php:40-52`), holding
`cashupay.sqlite` with plaintext seed phrases and spendable proofs, session files
(`includes/auth.php:25-31`) and the setup recovery code
(`includes/setup_ownership.php:204-208`). The app writes `data/.htaccess` and
`data/index.php` only (`includes/database.php:297-325`); nginx and `AllowOverride None`
ignore them. The setup probe now checks for the SQLite magic bytes and the `-wal`/`-shm`
sidecars (`setup.php:566-584`) and offers relocation, but it runs from the server to itself
and a 403 from a WAF or a 404 catch-all counts as "protected".
*Missing fact:* whether the web server applies the `data/` rule. If it does not, this is a
full compromise (seed → all funds; session files → admin; password hash; API/cron keys).
This is a carry-forward of `AUDIT.md` P1-11/P2-8, still unresolved in source.

**N-2 — Request bodies have no application-level size cap.**
`api.php:170-176` and `receive.php:136` read `php://input` unbounded; the only limits are the
web server's. A low-privilege shop key (or an anonymous sender at 20/min) could push a
large body into `json_decode`. *Missing fact:* `client_max_body_size` / `LimitRequestBody` /
`post_max_size` on the deployment. If unset, this is a cheap worker-memory DoS, not a data
compromise.

**N-3 — Long background runs may be terminated by the web server.**
`webhook_outbox` is an always-run task outside the time budget
(`includes/background_runner.php:90, 238-242`); a tick can spend up to 20 × 10 s in webhook
delivery (`includes/webhook_sender.php:31, 347-356`), while `cron.php` does not call
`set_time_limit()`. The runner lease is 360 s (`includes/background_runner.php:42`), so a
request killed mid-outbox blocks later ticks. *Missing fact:* FPM `request_terminate_timeout`
/ proxy read timeout on the deployment. On Linux, PHP's `max_execution_time` is CPU time and
does not count the curl waits, so this needs the real termination behaviour, not the default.

**N-4 — Setup claim fixation if an attacker can plant a cookie.**
`session.use_strict_mode` is not enabled and the claim is bound to session state
(`includes/setup_ownership.php:87-100, 292-298`). A sibling subdomain that can set
`Domain=example.com; name=cashupay_session` could fix the victim's session id and later
inherit the claim. *Missing fact:* whether such a cookie-setting primitive exists on the
target's domain; and PHP's `session.use_strict_mode`.

---

## Money-path and correctness observations (not security findings)

These do not cross a trust boundary but affect money or order state, and they were visible
while walking the money path.

1. **Expiry is decided locally before asking the mint.** `pollSingleQuote()` marks a `New`
   invoice `Expired` when `expiration_time < time()` and returns without calling the mint
   (`includes/invoice.php:802-805`); `markExpiredInvoices()` does the same in bulk
   (`:337-375`). Both fire `InvoiceExpired`. A customer who paid just before expiry but
   whose payment is first observed after it therefore produces `InvoiceExpired`, then
   `InvoiceSettled` when recovery finds the payment (`claimForMinting` accepts `Expired`,
   `:53-58`). The shop may cancel the order and the customer may pay twice. Asking the mint
   once before expiring, or suppressing `InvoiceExpired` when a late payment is later
   collected, removes the window.

2. **The mint's quote response is not checked against the request.**
   `Invoice::create()` stores the locally computed amount and only uses
   `$quote->quote`/`$quote->request`/`$quote->expiry` (`includes/invoice.php:171-196`). A
   buggy or hostile mint can return a quote for a smaller amount/unit; the customer pays
   the smaller BOLT11 and the invoice is still recorded at the requested amount. This is a
   known library "should-do" (`AUDIT.md`, upstream section) and remains open.

3. **Webhook event ordering is still not guaranteed** (carry-forward of `AUDIT.md` P1-10).
   `claimNextDelivery()` orders by `next_attempt_at, created_at` with no
   supersede/`NOT EXISTS(later delivered)` rule (`includes/webhook_sender.php:377-390`), so a
   retried `InvoiceProcessing` or `InvoiceExpired` can be delivered after
   `InvoiceSettled`. WooCommerce does not guard ordering, so a paid order can move back to
   on-hold or cancelled. The outbox deduplicates by event type only
   (`webhook_sender.php:158-168`).

4. **Underpayment by the input fee is deliberate but visible.** `receive.php:316-328`
   accepts a token when `gross >= requested` even if `net < requested`, logging only. A
   request can be settled for `requested − ceil(fee_ppk × proofs / 1000)`; bounded to a few
   units.

---

## Carry-forward status from `AUDIT.md`

| Prior item | Status at `4a82d01` |
|---|---|
| P1-3 paid-after-cancel never collected | **Fixed** — `claimForMinting` accepts `Invalid` (`includes/invoice.php:53-58`) |
| P1-10 webhook ordering | **Still present** — see observation 3 |
| P1-11 data-directory exposure check | **Partly improved** — magic-byte/sidecar probe and relocation exist; still deployment-dependent (N-1) |
| P2-8 seed in plaintext | **By design**, documented (`includes/config.php:159-160`); unchanged |
| Library: quote amount/unit validation | **Still absent** — see observation 2 |
| P2-6 update-check link pinning | **Fixed** — `includes/http.php:42-53` |

---

## Verified controls (coverage evidence)

These were read and found sound in the current source; they are listed so the absence of a
finding above is not mistaken for a gap in coverage.

- **Admin CSRF and auth ordering:** every state-changing POST goes through the session check
  (`admin.php:782-787`) and the `hash_equals` CSRF gate (`admin.php:789-795`); login has its
  own session-bound token (`admin.php:736-746`). `?api=` GETs are read-only except for
  benign dashboard side effects.
- **Login hardening:** attempts are reserved under `BEGIN IMMEDIATE` before
  `password_verify` (`includes/security.php:66-88`), so parallel guesses cannot overshoot;
  `session_regenerate_id(true)` on login and on password change (`includes/auth.php:95,
  188`); password version retires all sessions on a change (`:69-72, 197-218`) — the one
  path that bypasses it is H-1.
- **API authentication:** keys are looked up by full SHA-256, never stored in plaintext
  (`includes/auth.php:278-299, 386-399`); `token`/`Bearer` parsing only; per-key store scope
  enforced with `hash_equals` (`api.php:202-209`) and re-checked after every fetch
  (`includes/api/invoices.php:115-118, 138-142`; `includes/api/webhooks.php:74-77`).
- **Pairing:** permission allow-list with no `*`/server permissions, approval intersects the
  requested set, CSRF on approve/deny, reserved-label rejection, escaped POST-redirect form
  (`api-keys/authorize.php:35-89, 147-181, 277-333`). L-1/L-7 are the residual issues.
- **Webhook SSRF:** target validated at create and at delivery, one DNS answer pinned with
  `CURLOPT_RESOLVE`, no redirects, http(s) only, own-origin compared by host and effective
  port (`includes/webhook_sender.php:184-266, 284-315`; `includes/security.php:232-291`).
- **Safe mode:** pinned pubkeys only, kind/tag/`d`-tag exact, future-dated events refused,
  NIP-01 id recomputed, BIP-340 verify, flag re-verified on every read and written
  atomically (`includes/safe_mode.php:234-311`).
- **SQL:** all request values bound through `Database::query()`; no request-derived table,
  column, `ORDER BY` or `LIMIT` interpolation found (`includes/database.php:977-1044`).
- **Output escaping:** mint-, API- and customer-supplied strings are escaped or written via
  `textContent`/`dataset`; `innerHTML` is not used for stranger data; `json_encode` escapes
  `/` so `</script>` cannot break out (`admin.php:7568-7577` and the many call sites;
  `payment.php:502-507`; `receive.php:823, 837-838`). Admin CSP carries `'unsafe-inline'`,
  which is a missing second line of defence, not an injection.
- **Browser navigation:** `Security::isSafeBrowserRedirect()` rejects `javascript:`/`data:`,
  control characters, backslashes and protocol-relative paths, and is applied at render time
  as well as creation (`includes/security.php:461-488`; `payment.php:79-84`).
- **Fund-operation serialisation:** every outgoing path takes the per-store `flock`
  (`admin.php:1062, 1759, 2022`; `includes/lightning_address.php:324`), plus
  `hasPendingWithdrawal()` before a second melt (`includes/transfer.php:134`).
- **Store deletion:** balance + `StoreSafety` blockers, fail-closed if the check throws, and
  `confirmFundLoss` required to override (`includes/api/stores.php:115-155`).
- **Rate limiting:** SQLite-backed, read/increment/write under the write lock
  (`includes/security.php:19-55`), applied to login, API, receive, setup recovery and setup
  mint calls.
- **Entry points:** `includes/entrypoint_guard.php` refuses direct hits on the WordPress
  plugin build and applies baseline security headers + the shutdown check; the plugin routes
  require `manage_options` (`wordpress/rewrite-rules.php:108-122`); every plugin write is
  capability- and nonce-gated; uninstall keeps the data directory.
- **Secrets to the browser:** `cashupay_public_store()` strips `seed_phrase` and
  `internal_api_key` (`admin.php:34-44`); `?api=proofs` returns counts only; seed reveal and
  DB download require the password plus a rate limit (`admin.php:833-899`).
- **Random identifiers:** invoices `inv_` + 96 bits, stores/keys 96 bits, payment requests
  128 bits, API keys 256 bits (`includes/database.php:917-925`; `includes/auth.php:386`;
  `includes/payment_request.php:40`).

---

## Hardening notes (no boundary crossed)

- The admin CSP (`includes/security.php:789-796`) allows `'unsafe-inline'` scripts and
  inline handlers at ~40 sites; escaping is therefore the only XSS barrier. A future
  escaping slip in the admin is script execution in the session that can reveal the seed.
- Neither `admin.php` nor `payment.php` sends `Cache-Control: no-store`; the admin shell
  embeds a live CSRF token and the payment page an invoice.
- `?api=dashboard` is a GET that creates the internal API key and runs the update check
  (`admin.php:435-451`); a top-level cross-site GET can trigger it (SameSite=Lax). Low
  impact, no data returned cross-origin.
- `webhook_allow_private_targets` (`includes/webhook_sender.php:202`) disables the SSRF gate
  for *all* targets, not just one configured receiver; if it is ever enabled, a shop key can
  probe `127.0.0.1:*` and read status codes back.
- `admin.php?api=export_info` performs a mint round-trip on a GET (`admin.php:657-702`), so a
  cross-site top-level link can make the server do outbound work. Coalesced by the poll
  interval but not by the API.
- `receive.php` returns raw exception text to anonymous callers in its caught-error path
  (`receive.php:377`); the uncaught handler is generic. Mint/protocol text only.

---

## Limitations

- Static review of this repository only. `cashu-wallet-php` internals were read only at the
  HTTP boundary used by this application (client construction, token parsing, receive
  prechecks); its cryptographic core and state machine are covered by the library's own
  audit, not re-audited here.
- No runtime testing, no browser testing, and no deployment inspection. N-1 through N-4
  need the deployment fact named in each.
- The front-end bundles (`assets/js/*`, `mint-discovery/dist`) were checked for dangerous
  sinks and data flow, not audited as code.
- A clean static pass does not prove the absence of vulnerabilities; concurrent audits may
  find issues this one did not.
