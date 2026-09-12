=== BareBits - Lightning Payments via Bitcoin ===
Contributors: barebits
Tags: bitcoin, lightning, payments, woocommerce, btcpay
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin (on-chain and Lightning) in WooCommerce through a BareBits server. No approval process, no middlemen.

== Description ==

BareBits lets your WooCommerce shop accept Bitcoin — on-chain and over the Lightning Network — with funds going straight to wallets you control. No payment processor account, no approval process, no chargebacks.

This plugin is the WordPress-side glue: during onboarding you connect your self-hosted BareBits server by entering its URL. (The plugin's full build, distributed on the project's GitHub releases page, can additionally install a BareBits server alongside WordPress for you.)

The plugin then installs and configures the "BTCPay for WooCommerce" gateway plugin, points it at your BareBits server, registers the payment webhook, and can apply an automatic checkout discount for Bitcoin payments — on both the classic and the block-based checkout, with the percentage advertised in the payment method's title.

The BareBits server itself is a separate, self-hosted application (https://github.com/BareBits/cashupayserver) with its own license. This plugin contains no BareBits server code and talks to it purely over its HTTP API.

== Frequently Asked Questions ==

= Do I need my own server? =

Yes — a BareBits server you host yourself, which is the point: your money never touches anyone else's infrastructure. Any host that can run WordPress can run BareBits, even shared hosting; set it up once and connect it here by URL. (The full plugin build from the project's GitHub releases can also install it next to your existing site for you.)

= Where does the money go? =

To wallets you control: your own Lightning address, your own on-chain wallet (xpub), or a Cashu mint of your choosing. The BareBits admin walks you through it.

= What happens if I uninstall this plugin? =

Only the WordPress-side wiring is removed. A BareBits server installed alongside WordPress keeps running, and its data directory (which holds wallet keys) is never deleted by this plugin. The record of where that server lives — including its saved admin password, which is your only way into its dashboard — also survives, so reinstalling the plugin later offers to reconnect it.

== Changelog ==

= 1.5 =
* All admin styling and scripts now load as enqueued asset files, every admin action carries explicit capability and nonce checks, request input is sanitized on read and output escaped on print, and plugin-owned names use the barebits prefix throughout — per wordpress.org plugin review feedback.
* Server-side (for installs updating the companion BareBits server): on-chain receive through a Strike account, a confirmation-policy step for every on-chain source, and a fix for background payment polling that could miss payments made after the customer closed the payment page.

= 1.4.2 =
* The plugin's text domain now matches the wordpress.org plugin slug (barebits-lightning-payments-via-bitcoin), so community language packs from translate.wordpress.org will load.

= 1.4.1 =
* The plugin now passes WordPress.org's Plugin Check with zero errors and zero warnings: escaped output everywhere, WordPress filesystem/URL APIs, sanitized request input, and readme metadata kept in sync with the plugin version by the build.
* A wordpress.org distribution variant is now built alongside the full plugin. It omits the install-alongside flow (the plugin directory's guidelines forbid plugins downloading executable code) and connects to a BareBits server by URL only; the full plugin from GitHub releases is unchanged.

= 1.4 =
* The Bitcoin checkout discount is now applied by this plugin itself instead of the third-party ELEX plugin, and works on the block-based checkout as well as the classic one. The percentage (decimals allowed) is editable on the BareBits Connection page and in the gateway's WooCommerce settings, and the payment method's title always advertises the current value.
* Pairing with an existing BareBits server no longer shows "page not found" on servers whose host ignores rewrite rules: the plugin now always opens the authorization page by its real file name (/api-keys/authorize.php).
* The install-alongside server checks now show directly on the onboarding chooser, below the two options, instead of on a separate page afterwards. When a check fails, the install option is disabled with the reason in view.
* Onboarding and Connection-page actions, the pairing approval's return to this site, and the password reveal now wait out WordPress's brief maintenance windows (auto-updates) instead of landing on the "briefly unavailable" screen with the action lost.

= 1.3.1 =
* The plugin no longer bundles the BareBits server. Onboarding now connects an existing server by URL or installs the latest stable release alongside WordPress.
