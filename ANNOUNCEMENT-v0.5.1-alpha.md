# Release announcement — CashuPayServer v0.5.1-alpha

Pick whichever fits the platform. All are ready to paste.

---

## Short (Twitter/X, Mastodon, Nostr — ~280 chars)

CashuPayServer v0.5.1-alpha is out.

Four AI audits went over the code. The best find: every Lightning withdrawal was
quietly losing its unused routing fee, because the wallet never asked the mint to
give it back.

Accept Lightning payments on plain PHP hosting.

https://github.com/jooray/cashupayserver/releases/tag/v0.5.1-alpha

---

## Medium (Mastodon / Nostr long form / LinkedIn)

CashuPayServer v0.5.1-alpha — a security and reliability release.

CashuPayServer lets a small shop accept Lightning payments by speaking BTCPay Server's
API, so e-commerce plugins built for BTCPay work by changing one URL. No node, no
BTCPay instance — it runs on ordinary PHP shared hosting and settles through a Cashu mint.

I had four AI audits go over the server and the wallet library. This release fixes what
they found. Some highlights:

• Every Lightning withdrawal was forfeiting its unused routing fee. Cashu mints hold back
  a fee reserve and return what they don't spend — but only if the wallet gives them
  somewhere to put it. It never did.

• An exported token could be handed out twice. After an export, the mint truthfully said
  "not spent yet" (the recipient hadn't cashed it in), and the ecash went back into the
  spendable pool.

• A 1 sat donation could send a whole 32 sat note, because the check was "at least" rather
  than "exactly".

And one found the hard way, while upgrading my own instance: a paid invoice sat on
"Waiting for payment". The cause was a SQLite typing rule — numbers sort before text, and
the values were being sent as text, so "has it been 30 seconds?" always answered no. After
an invoice was checked once, it was never checked again.

Still alpha, still handling real money. Keep balances small and withdraw often.

https://github.com/jooray/cashupayserver/releases/tag/v0.5.1-alpha

---

## Long (blog post / newsletter)

**CashuPayServer v0.5.1-alpha: what four audits found**

CashuPayServer is a Lightning payment gateway that speaks BTCPay Server's Greenfield API.
Point a BTCPay e-commerce plugin at it, change the URL, and payments settle through a Cashu
mint instead of a node you have to run. It is a single PHP tree on shared hosting.

That design means it holds ecash — bearer money — in a SQLite file. So before adding
features, I had four independent AI audits (two by Claude Fable 5.1, two by GPT Astra) go
over the server and the wallet library, and spent this release fixing what they reported.

**Money was leaking, quietly.**

The worst finding was in Lightning withdrawals. When a Cashu mint pays a Lightning invoice
it holds back a fee reserve, then returns whatever it didn't spend — but only into "blank
outputs" the wallet supplies for the purpose. CashuPayServer picked ecash that exactly
covered the payment plus the reserve, which made the expected change zero, which meant no
blank outputs were sent. The mint kept the difference. On every withdrawal, including every
automatic one.

Exports had a subtler problem. After exporting a token, its ecash was marked "pending". The
next export asked the mint about pending proofs, the mint truthfully answered "not spent"
— the recipient simply hadn't cashed it in yet — and the ecash went back into the spendable
pool. The same bearer token could then be handed to a second person.

There were smaller ones in the same family: a 1 sat donation that could send a 32 sat note,
fiat conversion rounding the merchant down by a satoshi, millisatoshi mints mis-scaled by a
factor of a thousand, and withdrawals that could complete at the mint but vanish from the
books because the ledger only recorded successes.

**Then upgrading my own instance found one the audits missed.**

I deployed to the demo server, paid a 100 sat invoice, and the page sat on "Waiting for
payment" while the mint had already recorded it as paid.

The cause turned out to be a SQLite typing rule. SQLite sorts values by storage class
before value, so every number is considered smaller than every piece of text. PHP was
sending the comparison values as text. A check meant to read "has it been at least 30
seconds since we last asked the mint?" was therefore comparing a number against text, and
always answered no. After an invoice had been checked once, it was never checked again.

The bug was partly pre-existing and had been masked: the checkout page used to contact the
mint on every single poll, which covered for the broken scheduled check. This release made
the page poll less aggressively — a shared checkout link could otherwise tie up a small
server — and that removed the accidental protection.

The fix was small. The more useful change was the response to it: invoices now record what
the mint last said about them and why a check failed, and the dashboard shows it. "Why
wasn't my order marked paid?" is the first question an operator asks, and until now the
only answer lived in a PHP error log that most shared hosts don't expose.

**Security, and the trap of designing for yourself.**

The audits found real security problems: a freshly uploaded server could be claimed by
whoever opened its setup page first; mint discovery results announced over Nostr could
inject scripts into the admin; an integration key with only "create invoice" permission
could plant a `javascript:` redirect on the checkout page.

My first fix for the setup problem required reading a token file over SFTP. Which is
correct, and useless — the people running this software are shop owners, not
administrators. Many have never used SSH. So the design changed: the first browser to open
setup now claims the installation automatically, with no extra step at all. Anyone else is
refused and told plainly what happened, with a short recovery code on the server for the
real owner.

That principle is now written into the project's contributing notes, because it is the
easiest thing to forget: anything hard has to be either automated or explained very well.

**Also in this release**

Webhooks retry for about three days instead of three and a half minutes. WordPress plugin
webhooks work again — deliveries to the site's own address were being refused as "not a
public URL", so payments settled while WooCommerce orders stayed unpaid. There's a "Needs
attention" panel, a seed-phrase reveal behind a password check, and a one-click database
backup. Requirements are finally stated correctly (PHP 8.1, bcmath, mbstring; GMP optional).

Upgrading is three steps: back up from the admin, upload the files except the data folder,
open the admin. The database migrates itself, and there's now a test that walks a real
v0.4.1 database through the upgrade to prove nothing moves.

This is still alpha software handling real money. Keep balances small and withdraw often.

https://github.com/jooray/cashupayserver/releases/tag/v0.5.1-alpha
