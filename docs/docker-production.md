# Production deployment with Docker

The repo ships a production image for operators who'd rather run a single
container than wire up PHP/Apache by hand. It's the same code as the
shared-hosting deploy described in the README — just packaged for
`docker compose` (compose file: `docker/docker-compose.yml`).

> **Heads up:** This is the BareBits fork of cashupayserver. The README's
> warnings about experimental software and "do not use with amounts you
> cannot afford to lose" apply here too.

## What's in the image

- **Base:** `php:8.3-apache` (Debian slim with mod_php).
- **Web server:** Apache 2.4 + mod_php. The in-repo `.htaccess` does all
  the routing and security-header work, so there's no separate nginx/Caddy
  config to maintain.
- **PHP extensions:** `gmp`, `pdo_sqlite`. Composer-installed at build
  time: `bitwasp/bitcoin`, `phpmailer/phpmailer`.
- **Persistent volume:** `/var/www/html`. The app source lives here so
  the in-app auto-updater can overlay files durably across restarts;
  the SQLite database, cache, and update backups live under
  `/var/www/html/data/`. Back the volume with local disk: SQLite's file
  locking is not reliable on NFS/SMB or VM shared folders, and the app
  warns in the wizard and on the dashboard when it detects one (see
  "Keep the database on a local disk" in the README). One volume per
  container — never point two containers at the same `data/`.
- **In-container cron:** A background loop runs `cron.php` every
  `CRON_INTERVAL_SECONDS` (default 60). Set to `0` if you'd rather run
  cron from an external scheduler.

## Non-goals

- **TLS termination.** The image serves HTTP on port 80 only. Put a
  reverse proxy in front (Caddy, Traefik, nginx, Cloudflare).
- **nginx + php-fpm serving.** The image is Apache/mod_php. nginx *in front
  of* this container as a reverse proxy needs nothing special; if you instead
  run the app natively under nginx + php-fpm (no Apache at all), use the
  canonical server block at [`docker/nginx-site.conf`](../docker/nginx-site.conf)
  — it is the tested equivalent of the in-repo `.htaccess` (deny rules, API
  rewrites, clean-URL front controller; url_mode detects `clean` with it, and
  degrades to `direct`/`router` without its front controller). Either way,
  verify a deployment with `scripts/webserver-smoke.sh <url> [--nginx]`.
- **WordPress / WooCommerce integration testing.** That's what the other
  two `docker/Dockerfile.*` images are for — see [DOCKER.md](DOCKER.md).
- **Multi-tenant orchestration.** This is one container per deployment.

## Quick start

From the repo root (the compose file lives in `docker/`; the build context is
the repo root and the image copies the application tree from `app/`):

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Then open `http://localhost:8080/setup.php` and complete the setup wizard.

To stop:

```bash
docker compose -f docker/docker-compose.yml down
```

To wipe everything (including the database):

```bash
docker compose -f docker/docker-compose.yml down -v
```

## Configuration

The image reads operator settings from environment variables. Anything
you don't set falls back to the in-app defaults or the admin UI.

| Variable | Default | Notes |
|----------|---------|-------|
| `TZ` | `UTC` | Standard Debian tz database name. |
| `CASHUPAY_AUTO_UPDATE_ENABLED` | unset (off) | Set to `1` to force auto-update on for the deployment; normally opt in via the admin UI or onboarding wizard instead. |
| `CRON_INTERVAL_SECONDS` | `60` | `0` disables the in-container cron loop. |
| `CASHUPAY_SMTP_*` | unset | See `user_config.example.php` for the full SMTP set. |
| `CASHUPAY_FREE_TRIAL_*` | unset | Seeded once on first migration; see `user_config.example.php`. |

Only a subset of settings are read from the environment: the variables in the
table above plus `CASHUPAY_UPDATER_DISABLED`, `CASHUPAY_DATA_DIR`,
`CASHUPAY_DEPLOYMENT_ID`, `CASHUPAY_DEV_FEE_LNURL`, `CASHUPAY_DEV_FEE_BRANCH_ENDPOINT`
(test rigs only — see `includes/fee_branch.php`),
`CASHUPAY_TRUSTED_MINTS_URL`, and `CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES`.
Any other constant documented in [`user_config.example.php`](../app/user_config.example.php)
is **not** picked up from the environment — set it as a PHP constant in
`user_config.php` or from the admin UI. Two common cases:

- **Update channel.** Choose `main`/`testing` in the admin UI (Settings) or via
  the `CASHUPAY_UPDATE_CHANNEL` constant in `user_config.php`. There is no
  `CASHUPAY_UPDATE_CHANNEL` env var.
- **Public base URL.** Auto-detected from the request `Host` header; there is
  no `CASHUPAY_BASE_URL` env var. Put the app behind a reverse proxy that
  forwards the `Host` header (see below).

### Reverse-proxy headers

For HTTPS detection — so HTTPS-only cookies are set and generated URLs use
`https://` — the app honors the `X-Forwarded-Proto` header
(`includes/auth.php`). The public hostname is taken from the `Host` header
(there is no separate `X-Forwarded-Host` handling), so your proxy must forward
that too. Make sure both reach the container.

Caddy example:

```caddyfile
pay.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy sets `X-Forwarded-Proto` and forwards the upstream `Host` header
automatically.

## Upgrading

There are two upgrade paths and they intentionally do not collide:

1. **In-app auto-updater (opt-in).** Enable it in the admin UI
   (Settings → Auto-update), during onboarding, or force it on with
   `CASHUPAY_AUTO_UPDATE_ENABLED=1`. Every cron tick then checks the
   `CASHUPAY_UPDATE_CHANNEL` channel on GitHub. New builds overlay onto
   `/var/www/html`, preserving `data/` and `user_config.php`. No
   container restart needed. When auto-update stays off, the daily check
   still runs and surfaces new releases as an admin banner (and an email,
   if notifications are configured).
2. **Image-tag pinning (default).** Leave auto-update off and
   redeploy with a newer image tag when you want to upgrade. The
   entrypoint will **not** overwrite an existing `/var/www/html` volume,
   so you must either:
   - apply updates by hand: `docker compose -f docker/docker-compose.yml down`, mount the volume into
     an alpine container, copy in the new sources, restart; or
   - wipe the volume (`docker compose -f docker/docker-compose.yml down -v`) and lose your data.

Pick one strategy and stick with it. Mixing them — running auto-update
on top of a pinned image — works but makes debugging harder because
the running source no longer matches the image you deployed.

## Backups

Everything that needs backing up lives in the `cashupay_app` volume,
specifically under `data/`:

- `data/cashupay.sqlite` — the entire app database.
- `data/cache/` — regenerable; safe to skip.
- `data/updates/backup/` — auto-updater's rollback backups; large,
  skip if disk is tight.

Single-shot backup:

```bash
docker run --rm -v cashupay_app:/src -v "$PWD":/dest alpine \
    sh -c 'cp /src/data/cashupay.sqlite /dest/cashupay-$(date +%F).sqlite'
```

Restore is the inverse with the container stopped.

## Troubleshooting

### Container starts but `/setup.php` returns 500
Check Apache + cron output: `docker compose -f docker/docker-compose.yml logs -f cashupayserver`.
The auto-updater and cron-driven workers write to the same stream.

### `pdo_sqlite` or `gmp` missing
Confirm the build succeeded: `docker compose -f docker/docker-compose.yml exec cashupayserver php -m`
should list both. If not, rebuild without cache:
`docker compose -f docker/docker-compose.yml build --no-cache`.

### Auto-updater not running
Verify the env var is set: `docker compose -f docker/docker-compose.yml exec cashupayserver env | grep AUTO_UPDATE`.
Then check `data/updates/` for a `.lock` file — a stuck lock will skip
new ticks. Delete the lock and wait for the next cron interval.

### "Permission denied" on `data/`
Re-running the entrypoint fixes ownership:
`docker compose -f docker/docker-compose.yml restart cashupayserver`. If the problem persists,
something else is writing to the volume as a non-`www-data` user.
