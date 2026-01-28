# Docker Test Environments

Two Docker setups for testing CashuPayServer with WordPress + WooCommerce. Both use SQLite (no MySQL required).

## Prerequisites

- Docker installed

## Mode 1: Standalone

CashuPayServer runs as a separate PHP app on port 8080. WordPress on port 80.

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

## Mode 2: WordPress Plugin

CashuPayServer runs as a WordPress plugin. Everything on port 80.

```bash
# Build
docker build -f docker/Dockerfile.wordpress -t cashupayserver-wordpress .

# Run
docker run -d --name cashupay-wordpress -p 80:80 cashupayserver-wordpress

# Rebuild fresh
docker stop cashupay-wordpress && docker rm cashupay-wordpress
docker build --no-cache -f docker/Dockerfile.wordpress -t cashupayserver-wordpress .
docker run -d --name cashupay-wordpress -p 80:80 cashupayserver-wordpress
```

### Setup

1. Open `http://localhost/cashupay-setup/` and complete CashuPayServer setup
2. Open `http://localhost/wp-admin` (login: `admin` / `admin`)
3. CashuPay appears in the WordPress admin menu
4. WooCommerce → Settings → Payments → BTCPay Server
5. Set Server URL to `http://localhost/cashupay-api/` and configure the API key

## Default Credentials

| Service | Username | Password |
|---------|----------|----------|
| WordPress Admin | admin | admin |
| CashuPayServer | (set during setup) | (set during setup) |

## Installed Plugins

Both images come with:
- **WooCommerce** — e-commerce functionality
- **BTCPay Greenfield for WooCommerce** — payment gateway integration
- **SQLite Database Integration** — eliminates MySQL dependency

## Persistent Data

Data is lost when the container stops. To persist data, mount volumes:

```bash
# Standalone: persist both WordPress and CashuPayServer data
docker run -d --name cashupay-standalone -p 80:80 -p 8080:8080 \
  -v wp_data:/var/www/html \
  -v cashupay_data:/opt/cashupayserver/data \
  cashupayserver-standalone

# WordPress plugin: persist WordPress (includes plugin data)
docker run -d --name cashupay-wordpress -p 80:80 \
  -v wp_data:/var/www/html \
  cashupayserver-wordpress
```

## Troubleshooting

### Container exits immediately
Check logs: `docker logs cashupay-wordpress` or run without `-d` to see startup output:
```bash
docker run --rm cashupayserver-wordpress
```

### WooCommerce not showing payment options
Ensure WooCommerce setup wizard is completed. Go to WooCommerce → Settings → Payments to enable BTCPay Server.

### CashuPayServer setup fails on database check
The data directory should be writable by www-data. Inside the container:
```bash
docker exec -it <container> ls -la /opt/cashupayserver/data/
```

### Plugin activation fails (WordPress mode)
Check WordPress debug log:
```bash
docker exec -it <container> cat /var/www/html/wp-content/debug.log
```

### Check installed PHP extensions
```bash
docker exec -it <container> php -m | grep -E 'pdo_sqlite|gmp'
```
