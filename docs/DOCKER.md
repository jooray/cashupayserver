# Docker Test Environment

A Docker setup for testing CashuPayServer with WordPress + WooCommerce. Uses SQLite (no MySQL required).

> **Looking for production deploy?** This file documents the *test* image.
> For deploying CashuPayServer to a server, see
> [docker-production.md](docker-production.md) and
> `docker/docker-compose.yml`.

## Prerequisites

- Docker installed

## Standalone

CashuPayServer runs as a separate PHP app on port 8080. WordPress on port 80.

All commands run from the repository root (the build context; the Dockerfile
copies the application tree from `app/`).

```bash
# Build
docker build -f docker/Dockerfile.standalone -t cashupayserver-standalone .

# Run
docker run -d --name cashupay-standalone -p 80:80 -p 8080:8080 cashupayserver-standalone

# Rebuild fresh
docker stop cashupay-standalone && docker rm cashupay-standalone
docker build --no-cache -f docker/Dockerfile.standalone -t cashupayserver-standalone .
docker run -d --name cashupay-standalone -p 80:80 -p 8080:8080 cashupayserver-standalone
```

### Setup

1. Open `http://localhost:8080/setup.php` and complete CashuPayServer setup
2. Open `http://localhost/wp-admin` (login: `admin` / `admin`)
3. Go to WooCommerce → Settings → Payments → BTCPay Server
4. Set Server URL to `http://localhost:8080/router.php`
5. Generate an API key at `http://localhost:8080/admin.php` and enter it in the plugin settings

## Default Credentials

| Service | Username | Password |
|---------|----------|----------|
| WordPress Admin | admin | admin |
| CashuPayServer | (set during setup) | (set during setup) |

## Installed Plugins

The image comes with:
- **WooCommerce** — e-commerce functionality
- **BTCPay Greenfield for WooCommerce** — payment gateway integration
- **SQLite Database Integration** — eliminates MySQL dependency

## Persistent Data

Data is lost when the container stops. To persist data, mount volumes:

```bash
# Persist both WordPress and CashuPayServer data
docker run -d --name cashupay-standalone -p 80:80 -p 8080:8080 \
  -v wp_data:/var/www/html \
  -v cashupay_data:/opt/cashupayserver/data \
  cashupayserver-standalone
```

## Troubleshooting

### Container exits immediately
Check logs: `docker logs cashupay-standalone` or run without `-d` to see startup output:
```bash
docker run --rm cashupayserver-standalone
```

### WooCommerce not showing payment options
Ensure WooCommerce setup wizard is completed. Go to WooCommerce → Settings → Payments to enable BTCPay Server.

### CashuPayServer setup fails on database check
The data directory should be writable by www-data. Inside the container:
```bash
docker exec -it <container> ls -la /opt/cashupayserver/data/
```

### Plugin activation fails
Check WordPress debug log:
```bash
docker exec -it <container> cat /var/www/html/wp-content/debug.log
```

### Check installed PHP extensions
```bash
docker exec -it <container> php -m | grep -E 'pdo_sqlite|gmp'
```
