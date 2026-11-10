<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/barebits-logo.svg">
    <img src="assets/img/barebits-logo-dark.svg" alt="BareBits" width="360">
  </picture>
</p>

BareBits makes it EASY for merchants to accept Bitcoin (lightning and on-chain) without running a full BTCPay Server instance. No Docker, no VPS, no command line. Just upload and go. **Works on all "shared" web hosting, if it can host WordPress, it can host BareBits**. Low 1% fee. Ready to install? Use our [latest release](https://github.com/BareBits/cashupayserver/releases/latest) or [have us install it for you](https://getbarebits.com) for a 2% fee. See [installation](#Installation) and [FAQ](#FAQ).

BareBits can create invoices, receive payments, and be used as a point-of-sale system or be connected to your e-commerce site. Technically speaking, it is a PHP-based Bitcoin Lightning payment gateway that implements BTCPay Server's Greenfield API. This means that any e-commerce software that works with BTCPay Server can work with BareBits including WooCommerce, Shopify, Magneto, Drupal, and more! BareBits supports a number of payment types, risk/trust levels, and capabilities depending on your intended use case.

**Are you a web developer? Re-sell this software to your customers at a custom fee rate.** Your customers pay a x% fee, payments go directly to your lightning address. Just modify the appropriate settings in the store settings ("hosting fee")

---

### Key Features
- **Any PHP hosting** - Works on $3/month shared hosting. 
- **No application or approval process** - Just download and run!
- **Self-custody** - A fully self-custody solution, or add Cashu mint for convenience.
- **Fiat conversion** via Strike gives you access to instant fiat conversion in 100+ countries
- **Multiple stores** - Each store can have it's own invoice settings, cashout addresses, etc.
- **Tip Jar** - Let customers make their own payments using a shared link. Links can be generic (any amount) or for specific products.
- **E-commerce integration** - Plugin for WooCommerce. On magneto, shopify, or another platform? Use the BTCPay plugin and point it to your BareBits URL.
- **On-chain payments** to an off-server wallet using xpub addresses or even a single address
- **Lightning payments** to your off-server wallet via LNURL lightning address, CLINK noffer, or NWC (nostr wallet connect), or Strike API. Also supports using a cashu mint (no need to manage liquidity). We suggest ([Electrum](https://electrum.org/) for self-custody setups). Multiple wallets and connection types are supported so payments gracefully fall back to whichever wallet is online + has sufficient inbound liquidity.
- **User management** - Admin users can modify store settings and products and withdraw funds, regular users can only take payments.
- **Product management** - Add commonly-used products to your store to make invoicing fast and clear
- **Receipts** E-mail receipts to your customers (optional) and collect their e-mail addresses for your newsletter
- **Private** - No telemetry, no analytics, no phone-home. [PRIVACY.md](PRIVACY.md) documents every external service the server can contact and exactly what each one sees.
- **Submarine swaps** - If your lightning destination is offline or lacks sufficient inbound liquidity, you can still accept lightning payments via submarine swaps (Boltz and Zeus). Your customer's can pay in lightning but you receive the funds on-chain. Your customer pays the swap fee.
 - **Offline** payments powered by Cashu tokens (optional), melded to lightning when back online. You choose which mints to trust tokens from.
- **Open source** - Read every line of code. Fork it, audit it yourself, and modify it to your liking. Dual-licensed MIT (pre-2026-05-30) and Modified MIT (post-2026-05-30). See [LICENSE.md](LICENSE.md) and [USE_POLICY.md](USE_POLICY.md).


## ⚠️ AS-IS SOFTWARE ⚠️

Like any software, this software may contain unforeseen bugs or issues. BareBits is designed to not have access to any funds by default (it sends them to your off-server wallet). You wouldn't want your funds sitting on a web server anyway. If you do not have a lightning wallet configured, a Cashu mint (if explicitly enabled) takes custody of smaller lightning payments until automatic withdrawal thresholds are reached. 

### Trade-offs

BareBits sits between custodial payment gateways and full self-hosting:

| Solution | Pros | Cons |
|----------|------|------|
| Custodial gateways like [OpenNode](https://opennode.com)| Easy setup | Must apply for access, can freeze funds, geographic restrictions, charges a fee |
| [BTCPay Server](https://btcpayserver.org/) | Full sovereignty | Needs VPS ($20+/mo), Docker, ongoing maintenance |
| [Bitcart](https://bitcart.ai/) | Full sovereignty, limited lightning support with no liquidity management | Needs smaller VPS ($10+/mo), Docker, ongoing maintenance |
| **BareBits** | Simple, cheap hosting. No application process, trust mint with funds until withdrawal, or go full self-custody | Relies on block explorers for on-chain payment confirmations |

### Screenshots

<p align="center">
  <img src="docs/screenshots/create-invoice.png" alt="Create an invoice from the product catalog" width="180">
  <img src="docs/screenshots/create-invoice-simple.png" alt="Create a simple invoice with just an amount" width="180">
  <img src="docs/screenshots/payment-pending.png" alt="Customer checkout offering both on-chain and Lightning" width="180">
  <img src="docs/screenshots/payment-success.png" alt="Payment complete" width="180">
</p>

## Installation Methods
<p align="center">
  <img src="docs/screenshots/Installation methods infographic_shrunk.png" alt="Installation methods graphic">
</p>

**Run a WooCommerce store?** - Just install our wordpress plugin from the [latest release](https://github.com/BareBits/cashupayserver/releases/latest). It will install BareBits alongside woocommerce automatically. 

Magneto & all other e-commerce installations -- Install BareBits standalone using the web method, then download the [BTCPay Plugin for your platform](https://docs.btcpayserver.org/FAQ/Integrations/#what-e-commerce-integrations-are-available) and point it at your BareBits URL.

## Installation

### Web Install (Any PHP Hosting)

1. **Download** the latest `barebits-v*.zip` from the [latest GitHub release](https://github.com/BareBits/cashupayserver/releases/latest)
2. **Extract** the zip file
3. **Upload** to your web hosting via FTP or file manager
4. **Open** the URL in your browser (e.g., `https://yourdomain.com/barebits/`)

### Windows desktop (point-of-sale, no hosting needed)

Run BareBits as a local point-of-sale app on a Windows 10/11 PC or tablet —
no web server, no hosting account. You DO need to have internet access for payment confirmation.

1. **Download** the latest `barebits-windows-*.zip` from the [latest GitHub release](https://github.com/BareBits/cashupayserver/releases/latest)
2. **Extract** it anywhere you have write access (Desktop, Documents — not `Program Files`)
3. **Double-click** `BareBits.bat` — your browser opens the setup wizard

The package bundles its own PHP runtime and runs only on `127.0.0.1` — nothing is reachable
from the network. See `README.txt` inside the zip for backups, updates,
and troubleshooting.

### Integration with ecommerce tools (woocommerce, magneto, etc)

BareBits integrates with most ecommerce platforms including woocommerce, magneto, drupal, and prestashop. Full list [here](https://docs.btcpayserver.org/FAQ/Integrations/#what-e-commerce-integrations-are-available).

1. Download the BTCPayServer plugin for your ecommerce platform
2. When asked to input your BTCPayServer URL, input your store API URL instead. You can find this URL in the store settings

#### WooCommerce
Have WooCommerce? Install the **BareBits** plugin in WordPress — grab the plugin zip from the [latest GitHub release](https://github.com/BareBits/cashupayserver/releases/latest) and upload it via Plugins → Add New. During onboarding, either connect an existing BareBits server by URL, or let the plugin install BareBits alongside your WordPress site automatically. Easy peasy!

## Suggested Payment Configurations
BareBits is robust payment software that can direct payments to you via many methods depending on your security and speed needs. Below are several suggested setups. No matter which setup you choose, on-chain payments will ALWAYS go to your on-chain wallet (unless you have a Strike account enabled, in which case, payments go to your Strike account). Lightning payments can take several paths depending on your needs.

### Dead simple setup with automatic USD conversion
- Get an account at [strike.me](https://strike.me) and enable USD conversion in settings. Strike works in over 100 countries and native fiat currencies.
- In the [Strike dashboard](https://dashboard.strike.me/), create an API key with only the **create invoices**, **generate invoice quotes**, **create receive requests**, and **read invoices** scopes, and paste it into the "Strike API" section of the setup wizard (or the store's Lightning payments settings). A key with just those scopes can create and verify invoices but cannot spend funds from your account.
- Note: a Strike *lightning address* (…@strike.me) can NOT be used in the LNURL box — Strike addresses don't support LUD-21 payment verification, so BareBits could never confirm a payment against one. The API key method above works fully.
- ⚠️ Strike is a custodial exchange that holds onto funds for you, which means there is risk they may take or lose them. Don't keep significant funds on exchanges.
- ⚠️ Strike does not work with all kinds of merchants
  
### Full self-custody setup (suggested, no USD conversion):
- Run an [Electrum](https://electrum.org/) wallet on your desktop computer and enable the [automatic liquidity management plugin](https://github.com/BareBits/electrum_liquidity). Keep $100 or so in the wallet to keep liquidity flowing smoothly. You can start with zero and build up gradually as payments arrive. See [How to get an LNURL, NWC connection, or CLINK Noffer](#how-to-get-an-lnurl-nwc-connection-or-clink-noffer). Find your xpub in the "wallet information" section.
- Enable submarine swaps as a fallback in case your desktop is offline or doesn't have sufficient inbound liquidity.
- Suggestion: leave "strict mode" disabled. If your electrum wallet is unavailable AND a payment would be uneconomical to do a submarine swap for, lightning payments will land in a cashu mint (custodial) and be automatically withdrawn to your Electrum wallet once you have sufficient inbound liquidity OR will be withdrawn on-chain once it's economically reasonable.
- Need USD or other fiat currency? See [our suggestions](https://getbarebits.com/help/Understanding%20Bitcoin/fiat%20conversion%20options/) for exchanges.

### On-chain Absolutist
Don't want to mess around with LNURLs or CLINK noffers? Just want everything to go to your cold on-chain wallet? No problem!
- Get an xpub or bare address from your wallet, add it to your BareBits store configuration
- Want your customers to be able to pay with lightning? Enable submarine swaps: your customers pay in lightning, you get funds on-chain
- Suggested: allow fallback to mint (strict mode disabled, the default) so smaller lightning payments are workable. Submarine swap providers won't let you make swaps < around $25. Funds will be temporarily stored in the cashu mint, then forwarded to you on-chain when fees permit.

## Payment Flow

Generally speaking, BareBits tries to offer both on-chain and lightning as payment options to all customers. On-chain payments are always enabled, lightning payments are enabled depending on configuration. Here's what that decision tree looks like:
- Is the customer paying on-chain? Send directly to merchant xpub wallet, chain address, or Strike account
- Does the merchant have a working LNURL, NWC connection, or CLINK Noffer? Present a lightning invoice using those methods.
- If submarine swaps are NOT enabled and a cashu mint is NOT enabled, do not present a lightning invoice.
- If submarine swaps ARE enabled AND the invoice amount falls within the swap provider's min/max limits, present a lightning invoice that settles directly to the merchant's on-chain wallet. When several providers are configured, the preferred (first reachable) provider is used unless another is cheaper by more than the auto-select threshold (`swaps_auto_select_threshold_pct`, default 10%).
- If submarine swaps are NOT enabled, or no provider can serve the amount, display a lightning invoice that sends payment to the cashu mint — unless strict mode is on, in which case no lightning invoice is presented. Funds are later automatically emptied from the mint to the merchant's lightning or on-chain wallet. Empty to on-chain wallet only occurs by auto-cashout once it's worth it (auto-cashout caps the swap fee at 1% of the amount by default).


## Fee Payments and Structure

The BareBits software charges a 1% fee for usage. If you are a web developer, you can sell this service to your clients and charge an additional fee on top. Fees are paid in two ways, both of which are clearly shown in the invoices and stats tabs in your BareBits installation.

 - When any invoice is generated where the invoice amount is < the fee due, the payment will be automatically redirected to the fee destination. For example, if you owe 1000 sats in fees and a customer pays a 500 sat invoice, that payment will be sent directly to BareBits developers. The Lightning invoice is requested from the fee destination with the amount only: no store name, order reference, item description, memo or invoice ID is ever sent to the fee payee. A long-lived payment request can be redirected too; its sats amount is then locked at creation (fees are owed in sats, so it is never re-quoted) and a lapsed Lightning invoice is refreshed from the same fee destination at that amount.
 - If any funds are in the cashu mint and a fee is due, those funds will be used to pay the fee

This "payment redirection" method of paying fees ensures that BareBits developers never have access to your funds or your customer's private information.

## Submarine Swaps (LN → on-chain, optional)

When enabled, BareBits can route a customer's Lightning payment through a
third-party submarine-swap provider (Zeus or Boltz) and settle the proceeds
**directly on-chain to the merchant's xpub** — without the cashu mint ever
holding the funds. This is useful for merchants who DON'T have a lightning address, 
DON'T want to trust a cashu mint, and DON'T want to manage their own liquidity. 
This can also be used as a fallback option if your lightning address is offline or
has no inbound liquidity.

The customer's experience is unchanged: they pay the same single lightning
on the checkout page.

**Pros**

- **Non-custodial.** Customer's funds never touch a mint, instead, funds go directly to your on-chain wallet
- **Provider-agnostic.** Both Boltz and Zeus are automatically enabled, Zeus is preferred but Boltz is used if significantly cheaper than Zeus.
- **Customer pays the fees.** Swap fees (provider percent + on-chain lockup  miner fee) are bundled into the LN invoice amount; the merchant receives the
  target sat amount on-chain net of fees.

**Cons**

- **Per-invoice fees.** Boltz currently charges ~0.5% + the lockup miner fee.
  Stats dashboard tracks both as a "Swap fees" line item. This means it's not advisable for smaller invoice amounts.
- **Min/max amount limits.** Boltz mainnet currently enforces 10,000 sats min
  and 5,000,000 sats max per swap; invoices outside this range can't use the
  swap rail.
- **Provider dependency.** The provider must be reachable at invoice creation
  to bake an HTLC; the cron poller must run on a regular cadence to claim the
  on-chain output before the timeout. If neither runs, the customer's LN
  payment is refunded automatically (no loss), but the merchant receives
  nothing.
- **Optional mint fallback.** By default, if all configured providers are
  unreachable or the amount is out-of-range, BareBits falls back to the
  cashu mint (if enabled). You can enable "strict mode" in settings to disable the fallback
  and reject the invoice instead — useful for operators who want to eliminate
  the mint entirely from their payment flow.


## FAQ

#### Are funds stored on the server?

No, funds are directed to your off-server wallet. The only time the server has any access to funds is if you enable a Cashu mint, which will be periodically swept to your wallet.

#### What if I want my funds in USD or other native currency, not BTC?

BareBits integrates with [Strike](https://strike.me) so your funds can be instantly converted to USD. Your customer pays in BTC, you receive the currency of your choice. If you don't want to use Strike, you can periodically sell your BTC [using a centralized or decentralized exchange](https://getbarebits.com/help/Understanding%20Bitcoin/fiat%20conversion%20options/). 

#### Can I have multiple stores in a single installation, like if I want to help onboard another merchant?

Absolutely! You can create multiple stores and can create non-admin accounts (so they can take payments but not modify settings). You can even charge a fee ("hosting fee" in store settings) for your help. Each store has its own settings so they can use separate wallets, payment preferences, etc.

#### Which wallets do you suggest? What if my wallet is offline or doesn't have enough inbound liquidity?

Any on-chain wallet will work for on-chain payments. Lightning wallets will work for lightning payments if they have NWC, NOFFER, or an LNURL function. We suggest Electrum, see [Suggested Payment Configurations](#suggested-payment-configurations)

If your wallet is offline, on-chain payments continue functioning as normal. Lightning payments require an online wallet, so if your wallet is offline, BareBits gracefully falls back to the next lightning payment option until all are exhausted and if none work, it will skip showing a lightning payment QR code to your customer. You can customize this fallback order. Options include: NWC wallets, CLINK wallets, Strike API wallets, and LNURL wallets. If none of those work, and submarine swaps are enabled, your customer can pay via LN but you receive the funds on-chain. If submarine swaps are not possible (for example it does not meet the minimum transaction size) or disabled, and a cashu mint IS enabled, funds will go to your cashu mint. 

#### What happens if BareBits shuts down?

There is no dependency on BareBits infrastructure whatsoever since this is a self-hosted software. If BareBits disappears, your software will continue to work. We have no access to or control over your server or funds.


## Requirements

Most shared web hosts will have no problem meeting these requirements

- PHP 8.0 or higher
- Extensions: `curl`, `json`, `sqlite3`, `mbstring`, and `gmp` or `bcmath`
  (`gmp` is strongly recommended — it is ~100x faster for the payment
  cryptography and is required for xpub-based on-chain wallets and submarine
  swaps; Cashu, Lightning addresses, NWC, and noffers also run on `bcmath`)
- Apache with mod_rewrite, nginx, or any PHP-capable web server

For on-chain Bitcoin payment support, the release zip ships with the required
PHP libraries (bitwasp/bitcoin et al.) under `vendor/`. Composer is only needed
if you're building from source (see Development below).

## How to get an LNURL, NWC connection, or CLINK Noffer

If you want your customers to be able to pay via lightning (lowest fees, fastest payments), you will need to generate a lightning invoice for each customer. There are three ways to automate this: LNURLs (lightning address), Nostr Wallet Connect (NWC), and CLINK Noffers. Alternatively, you can use cashu mints and submarine swaps (accept customer payments in LN, automatically send to you on-chain).

### CLINK Noffers (suggested) (self-custody)
CLINK Noffers enable you to generate lightning invoices on the fly from a desktop wallet that is left running online. This means you maintain full self-custody over your funds! You will need to manage your liquidity (make sure you have room for incoming payments), but this can be automated fairly easily. BareBits will automatically fall back to submarine swaps, cashu mints, etc if there is no liquidity available or the wallet is offline, so it's not a big deal.

Suggested setup: [Electrum wallet](https://electrum.org/) with the [CLINK plugin](https://github.com/BareBits/electrum_clink) and [Liquidity management plugin](https://github.com/BareBits/electrum_clink). Once the CLINK plugin is installed, go to the settings and generate an noffer to add to your store settings page. You only need to generate a single noffer, but we suggest generating two noffers across two relays so that payments keep flowing even if your relay goes offline.

The default settings on these plugins should work, just be sure to set the liquidity management plugin to run in automatic mode. 

### Nostr Wallet Connect (self-custody, works with many wallets)
[NWC (NIP-47)](https://nwc.dev/) lets BareBits ask your own lightning wallet to create invoices and check whether they were paid — nothing more. Many wallets can issue a connection string (Alby Hub, Coinos, lnbits, and others): create a connection, then paste the `nostr+walletconnect://...` string into your store's NWC section (or the setup wizard). The connection is tested with a 1-sat test invoice when you save (the test invoice is never paid and simply expires).

**Use a receive-only connection.** BareBits only ever calls `make_invoice` and `lookup_invoice`, but if your wallet lets you scope permissions, grant only those two — a connection that can also *pay* would let anyone who compromises your server spend from your wallet. BareBits warns you at save time if it can detect that the connection has spend permissions. The connection secret is stored server-side and never shown again in the admin UI.

### LNURL aka Lightning Address Providers (simple)
Many centralized exchanges like [Strike](https://strike.me) offer LNURLs out of the box and can offer instant USD conversion. Note that these exchanges are custodial: they hold onto funds for you. This introduces risk of theft, exchange collapse, etc so we do not suggest storing significant funds on them.

| Provider   |      USD Conversion |  Notes |
|----------|:-------------:|------:|
| [Strike](https://strike.me) |  ✅ | Use the dedicated **Strike API** method (scope-limited API key), NOT the @strike.me lightning address — Strike addresses don't support LUD-21 verification. Works in most countries, some merchant type restrictions, application process |
| [CoinOS](https://coinos.io) |    ✖️   |  Works in all countries, no restrictions, no application process, no USD conversion |
| [Rizful](https://rizful.com/) |  ✖️ |    Works in all countries, no restrictions, no application process, no USD conversion |

You can also get your own LNURL by hosting your own lightning node (rather complex, not suggested if you are not technically inclined)

### BareBits vs CashuPayServer
BareBits is a fork of [CashuPayServer](https://github.com/jooray/cashupayserver) by Juraj Bednár. BareBits adds a number of enhancements to the original CashuPayServer software including on-chain payments, accepting cashu tokens, offline payments, automatic submarine swaps, automatic updates, security enhancements, LNURL support, CLINK support, NWC support, user management, product management, a stats menu, and more. BareBits charges a **1% fee** for usage

## Security

### Database Protection

The `data/` directory contains your SQLite database with ecash tokens (real Bitcoin value). It **must** be protected from HTTP access.

**Apache**: The `.htaccess` file handles this automatically, if enabled and honored. Verify.

**nginx**: Use the shipped, tested server block at
[`docker/nginx-site.conf`](docker/nginx-site.conf) — it carries the deny rules
(`data/`, dotfiles, `*.sqlite`/`*.db` + WAL sidecars, `user_config.php`), the
API rewrites, and the clean-URL front controller that mirrors what `.htaccess`
does on Apache. nginx never reads `.htaccess`, so at minimum the deny rules
from that file are mandatory.

**Verify protection**:
```bash
curl -I https://yoursite.com/barebits/data/cashupay.sqlite
# Should return 403 Forbidden or 404 Not Found, NOT the file!
```

**Best practice**: Store data outside web root by creating `includes/config.local.php`:
```php
<?php
define('CASHUPAY_DATA_DIR', '/home/user/cashupay-data');
```

### Seed Phrase

Your seed phrase is your backup of funds stored in your Cashu mint (if enabled). Write it down and store it safely. If you lose access to your server, you can recover your funds with the seed phrase.

**Warning**: Do not import the seed phrase into another wallet you use actively. Using the same seed in multiple wallets causes coin loss.

### Resetting a lost admin password

If you're locked out of the admin dashboard there are two ways to recover, both reachable from the **Forgot password?** link on the sign-in screen.

**Option 1 — Email a reset link.** Requires two things to be set up in advance:

- A **recovery email** on the admin account. Set it during the setup wizard (the "E-mail notifications" step collects it alongside your SMTP settings; by default it is the same address notifications go to) or later under **Settings → My Account → Recovery email**.
- **Outbound email (SMTP)** configured under **Settings → Email Notifications** in the admin UI (global, with an optional per-store override). As a fallback, SMTP can also be set via the `CASHUPAY_SMTP_*` settings in `user_config.php` (see `user_config.example.php`). Without working email this option cannot deliver the link.

Click **Forgot password? → Email me a reset link**, enter the admin recovery email, and open the link that arrives. The link is valid for **one hour** and can be used **once**. (For privacy, the page always reports success regardless of whether the address matched an account.)

**Option 2 — Reset via a file on the server.** Use this when you have filesystem access (SSH / SFTP / your host's file manager) but no working email. Create an **empty** file named `reset-admin-password` inside the server's data directory:

```bash
touch data/reset-admin-password
```

If you've relocated the data directory with `CASHUPAY_DATA_DIR`, create the file there instead (e.g. `/home/user/cashupay-data/reset-admin-password`). 

Then reload the sign-in page. It detects the file and shows a **"Set a new admin password"** form. Your existing password keeps working until you complete the form; once you submit a new password the trigger file is **deleted automatically**. Only the primary `admin` account is reset. If you created the file by mistake, just delete it.

## Development

### Quick Start with PHP Built-in Server

The simplest way to run BareBits locally:

```bash
git clone --recurse-submodules https://github.com/BareBits/cashupayserver.git
cd cashupayserver
# Install PHP dependencies (bitwasp/bitcoin for on-chain payment support).
# Composer is only needed for development and at build time — the deploy zip
# bundles vendor/ so end users don't need Composer on their hosting.
php composer.phar install --no-dev --ignore-platform-reqs   # or: composer install ...
php -S localhost:8000 router.php
```

If `composer.phar` isn't checked in, fetch it once with:

```bash
curl -sS https://getcomposer.org/installer | php
```

Open http://localhost:8000 in your browser.

The `router.php` handles routing and **blocks access to sensitive directories** even without Apache/nginx configuration.

### Docker Test Environment

A Docker configuration is available for **testing only** (not production). It includes WordPress, WooCommerce, and the BTCPay plugin pre-installed with SQLite (no MySQL needed).

See [DOCKER.md](DOCKER.md) for detailed setup instructions, persistent data volumes, and troubleshooting.

#### Standalone + WordPress

```bash
git clone --recurse-submodules https://github.com/BareBits/cashupayserver.git
cd cashupayserver

docker build -f docker/Dockerfile.standalone -t cashupayserver-standalone .
docker run -p 80:80 -p 8080:8080 cashupayserver-standalone
```

This starts:
- **WordPress + WooCommerce**: http://localhost (login: admin/admin)
- **BareBits standalone**: http://localhost:8080

### Building Distribution Packages

```bash
# Build standalone zip
./scripts/build-standalone.sh
# Output: build/barebits.zip

# Build WordPress plugin
./scripts/build-wordpress-plugin.sh
# Output: build/barebits_wordpress_plugin.zip
```

### Building mint-discovery Bundle

If you modify the mint-discovery submodule:

```bash
cd mint-discovery
npm install
npm run build
cp dist/mint-discovery.bundle.js ../assets/js/
```

## Security for Development Environments

**When running directly from the git repository (not from the distribution zip), you MUST ensure your web server blocks access to sensitive files.**

The distribution zip only includes necessary files. The git repository contains test scripts, examples, and documentation that should never be web-accessible.

### What Needs Protection

| Directory/File | Contains | Risk if Exposed |
|---------------|----------|-----------------|
| `data/` | SQLite database with ecash tokens | **Critical** - Loss of funds |
| `includes/` | PHP classes | Code execution |
| `cashu-wallet-php/` | Library with test scripts | Code execution |
| `mint-discovery/` | Node.js source | Info leak |
| `scripts/` | Build scripts | Info leak |
| `docker/` | Docker configs | Info leak |

### Apache (Development)

The `.htaccess` file handles this if:
- `mod_rewrite` is enabled
- `AllowOverride All` is set

Verify it's working:
```bash
curl -I http://localhost/data/
# Should return 403 Forbidden

curl -I http://localhost/cashu-wallet-php/test-wallet.php
# Should return 403 Forbidden
```

### nginx (Development)

nginx ignores `.htaccess`. Use the canonical, tested server block shipped at
[`docker/nginx-site.conf`](docker/nginx-site.conf): it contains the deny rules
(sensitive directories, dotfiles, database files including WAL/SHM sidecars,
`user_config.php`), the `/api/v1` and `/v1` rewrites, PATH_INFO-preserving
PHP execution, and the clean-URL front controller. With its front controller
in place the setup wizard detects clean URLs, exactly as on Apache with
mod_rewrite; without it the app falls back to `direct`/`router`-style URLs
automatically — but the deny rules are not optional.

The e2e suite runs against this exact file (`tests/scripts/run-tests.sh`
with no `--backend` flag, or `CASHUPAY_TEST_BACKEND=nginx`), and the
`webserver-smoke` CI workflow asserts its deny/routing behaviour on every
change to it.

**Verify any deployment** (works for Apache and nginx):
```bash
scripts/webserver-smoke.sh https://yoursite.com          # Apache
scripts/webserver-smoke.sh https://yoursite.com --nginx  # nginx
```

### PHP Built-in Server

Using `router.php` is safe - it blocks sensitive paths:

```bash
php -S localhost:8000 router.php
```

**Do NOT use** `php -S localhost:8000` without the router - it will serve all files!

## API Reference

BareBits implements the BTCPay Server Greenfield API:

### Create Invoice
```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/invoices" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": "10.00", "currency": "EUR"}'
```

A checkout invoice expires after one hour by default. Pass
`checkout.expirationMinutes` (1 up to 129600, i.e. 90 days) to choose the
lifetime. An invoice created with that field and no `metadata.orderId` is a
**long-lived payment request**: the payment page re-sources its Lightning
invoice whenever the previous one lapses (the old one stays under watch so a
payer who already scanned it still settles the invoice), and a fiat amount is
re-quoted in sats each time the page is opened. The admin "Create invoice"
screen and the self-serve page create their invoices this way. Shop orders
(any invoice carrying `metadata.orderId`) keep the standard expiry behavior.
A long-lived request that was redirected to a fee destination (see Fee
Payments) keeps the sats amount it was created with and refreshes its
Lightning invoice from that same destination.

```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/invoices" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": "10.00", "currency": "EUR", "checkout": {"expirationMinutes": 129600}}'
```

### Get Invoice
```bash
curl "https://yoursite.com/api/v1/stores/{storeId}/invoices/{invoiceId}" \
  -H "Authorization: token YOUR_API_KEY"
```

### Webhooks

Register webhooks to receive payment notifications:

```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/webhooks" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://yourshop.com/webhook",
    "authorizedEvents": {"everything": true}
  }'
```

Webhook events: `InvoiceCreated`, `InvoiceReceivedPayment`, `InvoiceProcessing`, `InvoiceProvisional`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid`

## Troubleshooting

### "404 Not Found" on API calls

Your server may not support URL rewriting. Use the front controller URL:
```
https://yoursite.com/barebits/router.php
```

### "Forbidden" when accessing setup

Check that the web server can read PHP executables.

### Payments not detected

1. Check that your mint is online
2. Click the refresh button in your dashboard
3. Enable PHP error logging to see detailed errors

## Contributing

Contributions are welcome! Please discuss with us first prior to committing and making a PR.

---

## License and Use Policy

> **Use of this software is subject to the BareBits Use Policy. You are free
> to use and modify this software as you wish, provided you do not remove the
> fee component. See [LICENSE.md](LICENSE.md) and [USE_POLICY.md](USE_POLICY.md)
> for full terms.**

This software is **dual-licensed**:

- All commits at or prior to `5812584` (dated 2026-05-30) are licensed under
  the **MIT License**.
- All subsequent commits are licensed under the **MODIFIED MIT LICENSE**,
  Copyright © 2026 Zaphaus LLC. The Modified MIT License permits use, copy,
  modify, merge, publish, distribute, sublicense and sell *subject to* a
  built-in fee component that may not be removed.

BareBits is self-hosted payment processing software. You may download, deploy,
and modify it on your own infrastructure (subject to applicable open-source
and third-party licenses). You are solely responsible for configuration,
security hardening, key custody, compliance, and any transactions processed
through your instance. To the maximum extent permitted by law, BareBits and
its creators disclaim liability for your deployment, modifications,
integrations, and downstream use, and provide the Software "as is" with no
warranties. BareBits does not provide a hosted service unless you have a
separate written Service Agreement.
