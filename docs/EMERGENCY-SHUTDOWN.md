# Emergency shutdown runbook

The nuclear option. Every CashuPayServer install on an affected version stops serving
**anything**: checkout, API, admin, setup, cron. Operators get it back only by
upgrading. Use it only when a published or actively exploited hole lets strangers take
operators' money, and a normal security release would reach them too slowly.

Design and reasoning: `includes/safe_mode.php`. Background: "A signed kill switch" in
[Ten AI models vs embargoed Core Lightning](https://juraj.bednar.io/en/blog-en/2026/09/18/ten-ai-models-vs-embargoed-core-lightning-a-case-study-of-ai-for-auditing/).

## How it works, in one paragraph

Installs fetch `https://cashupayserver.org/version.json` every six hours (unless the
operator turned the update check off). If it carries a `safe_mode` field holding a Nostr
event (kind 30078, `d` tag `cashupayserver-safe-mode`) signed by one of the two pinned
keys, and the event's version range contains the install's version, the install writes
`EMERGENCY-SHUTDOWN.json` into its data folder. From then on every entry point answers
503 with a plain explanation and a link to upgrade. Upgrading to a version outside the
range makes the flag stale and the install removes it and runs again. Nothing reachable
over the web can lift it.

Pinned keys (`SafeMode::DEFAULT_PUBKEYS`); either one is enough:

- `npub18scydh6yusrkhe67f8uz9mnxxt5znae59ctvhxxpzefkf7xwnx4q0nalja`
- `npub1m2mvvpjugwdehtaskrcl7ksvdqnnhnjur9v6g9v266nss504q7mqvlr8p9`

## Practise it (no stress, no risk)

```bash
php scripts/emergency-shutdown.php --rehearse
```

Runs the whole procedure with a throwaway key that only this process trusts, writes
`version.json.rehearsal`, never uploads. Do this once in a while so the real run is
familiar.

## The real run

0. Have the fix ready if at all possible. A notice with a *fixed* version lets operators
   recover by upgrading. A notice without one shuts down every version from the first
   affected one on, including releases published later; the release with the fix must
   then list the notice id in `SafeMode::RETIRED_NOTICES` (see below).
1. From a checkout of the current `main`:

   ```bash
   CASHUPAY_MANIFEST_SCP=USER@HOST:PATH/version.json php scripts/emergency-shutdown.php
   ```

   The tool shows what the live manifest says now, asks for the first affected version,
   the fixed version, a one-sentence reason (public, shown to every affected operator)
   and the upgrade link, shows a summary, and asks you to type `SHUTDOWN`.
2. Sign, either
   - **1** — paste the nsec (hidden prompt; or set `CASHUPAY_SAFE_MODE_SECKEY`), or
   - **2** — run the printed `nak event …` command with your nsec or an Amber
     `bunker://` URL after `--sec`, and paste the JSON it prints.

   The tool refuses anything not signed by a pinned key or not matching what you
   confirmed.
3. It writes `version.json.new` (the live manifest plus the notice), shows how installs
   will read it, and offers to upload it. Afterwards:

   ```bash
   php scripts/emergency-shutdown.php --check
   ```
4. Publish the fixed release and an advisory. Affected installs shut down at their next
   update check, within about six hours; the operators land on the upgrade link.
5. Optional, for transparency: publish the same event to Nostr relays
   (`echo '<event json>' | nak event wss://relay…`).

## Updating or withdrawing a notice

- **Fix released after a no-fix notice:** installs already shut down cannot fetch a
  revised notice, and the old range covers every later version. So the fixing release
  must add the old notice's id (printed by the tool and by `--check`) to
  `SafeMode::RETIRED_NOTICES` in `includes/safe_mode.php`; uploading it then restarts
  those installs. Then run the tool again with the fixed version, so installs that have
  not shut down yet get a bounded notice.
- **Withdraw:** `php scripts/emergency-shutdown.php --lift`. This stops *new* shutdowns
  only. Installs already shut down stay down until upgraded, or until their operator
  deletes `EMERGENCY-SHUTDOWN.json` from the data folder.

## What operators see and can do

- Every page: "Payments here are temporarily unavailable", plus a section for the
  operator: what happened, that their money is still there, and what to upload.
- WordPress: a red notice in wp-admin; the rest of the WordPress site keeps working.
- Shop plugins get Greenfield JSON `503 service-unavailable` and show the payment method
  as unavailable.
- To recover: upload the fixed version (the page says when none exists yet). Experts who have mitigated the hole otherwise can
  delete the flag file, or set `define('CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN', true);` in
  `includes/config.local.php`.
- Funds: untouched; ecash and payment records stay in the data folder (the seed phrase
  alone does not cover unpaid or unminted invoice records). Invoices paid during the
  shutdown are credited by the normal late-payment recovery after the upgrade.

## Key custody

- Keep both keys offline or in a hardware/remote signer; neither should be a daily-use
  identity.
- If one key may be compromised, release a version that pins a replacement. The worst a
  stolen key can do is shut installs down (a denial of service), never move funds, and
  operators can always recover by upgrading.
