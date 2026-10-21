# CashuPayServer v0.5.7-alpha — security update

This release fixes the findings of three independent security audits run in October 2026.
Upgrade as usual, and **keep your `data` folder exactly as it is**. There is no manual
migration and nothing to configure.

## What you may notice

- **Showing the seed phrase and downloading a backup now ask for your password again.**
  In WordPress, enter your **WordPress password** — the prompt no longer says "admin
  password" there, because WordPress has no separate one.
- **Sessions end after an hour of inactivity** (the 24-hour absolute limit stays). The
  dashboard's background refresh does not keep an idle session alive.
- **A backup download that is interrupted no longer leaves a copy behind.** Leftover
  snapshots from a crashed download are removed automatically, and the dashboard warns if
  one is ever reachable from the web.
- **Fiat prices can pause instead of trusting one source.** If only one price provider
  answers, or two disagree, checkout says so in plain words and resumes by itself. This
  is deliberate: a wrong rate used to underprice invoices.
- **Recovery exports reserve the money.** When you export stranded funds as a token, that
  amount leaves your balance immediately. Opening the same screen again shows the same
  token; "mark recovered" writes it off after you have claimed it. Money that arrives in
  between stays separate.
- **WordPress updates and uninstalls are cleaner.** Each site keeps its own wallet folder,
  and deleting the plugin now removes the API key and webhook secret it created instead of
  leaving them in the database.
- **"Visitor addresses" no longer accepts a range that covers the whole internet.** A
  saved `0.0.0.0/0` is ignored after the upgrade; enter your proxy's own address instead.
- **Sign-in has a global limit as well as a per-address one.** Twenty failed attempts in
  five minutes pause password sign-in for everyone, so rotating addresses no longer buys
  unlimited guesses. A successful sign-in clears it.

## Fixes in this release

Security and money-safety fixes include:

- WordPress: showing the seed phrase or downloading the database now requires a fresh
  WordPress password check, not just an already-open admin session.
- Payment requests can no longer be marked paid by replaying a spent or donated token.
- The setup wizard's `add_store` path can no longer reset the admin password.
- A new setup claim clears the previous claimant's server address, half-finished stores
  and wizard session, and regenerates the session ID.
- Fiat pricing requires two providers to agree, keeps a bounded reference rate, and
  refuses to price from a single unconfirmed source.
- Public token submission is bounded: at most 100 proofs per token, duplicate proofs are
  refused, DLEQ scalars have a fixed size, and the fee shortfall is limited.
- Mint and Lightning-address connections are re-checked and pinned to public addresses on
  every request; private mints remain available only when explicitly allowed.
- Webhook deliveries are cancelled when a webhook is deleted or repointed, and per-invoice
  order is preserved.
- Expired invoices are written off only after a week of the mint confirming nothing was
  paid, so a lagging mint watcher cannot make a late payment uncollectable.
- Invoice expiry and orphan recovery work in bounded batches, and a fatal error no longer
  leaves the cron lease held.
- The trusted-proxy setting rejects internet-wide ranges, and IPv6 lockouts are grouped
  per /64.
- API request bodies are capped at 64 KiB, and the stored `checkout` object at 4 KiB.
- Release builds refuse a dirty working tree, and each checksum is tied to the commit and
  tag it was built from.
- The update manifest must be fetched over HTTPS, and the emergency-shutdown publisher
  refuses to republish fields it did not author.
- The WordPress plugin's update channel points at the reviewed GitHub releases, and the
  container build context no longer includes local configuration or wallet data.

## If you use the WooCommerce integration

Re-run **Configure** once after upgrading. Older releases left the previous API key valid
when the gateway was configured again; the new code revokes it and keeps exactly one live
key. No orders or settings are touched.

## If you use a currency that only one price provider quotes

Pricing for that currency is anchored to a confirmed BTC/USD rate and moves at most 2% a
day, so a single bad answer cannot reprice invoices. On a brand-new install the first rate
for such a currency is still trusted once; check it after setup.

## Upgrading

1. In the admin, go to **Settings → Download database backup** and keep the file.
2. Upload the new files over the old ones, **except** the `data` folder.
3. Open the admin. That's it.

WordPress: **Plugins → Add New → Upload Plugin**, choose the new zip, and replace the
existing plugin. Your wallet folder and settings are kept.
