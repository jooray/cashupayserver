# CashuPayServer

> **A BTCPay-compatible payment gateway that runs on any PHP hosting.**

Accept Bitcoin Lightning payments without running a full BTCPay Server instance. No Docker, no VPS, no command line. Just upload and go.

**[cashupayserver.org](https://cashupayserver.org/)**

---

## ⚠️ EXPERIMENTAL SOFTWARE - USE AT YOUR OWN RISK ⚠️

**This project is in early development and has NOT been thoroughly tested in production environments.**

- Do NOT use with amounts you cannot afford to lose
- Test with small transactions first
- The software may contain bugs that could result in loss of funds
- No warranty is provided - see the LICENSE file
- Security audits have not been performed

**You are responsible for your own funds. The developers are not liable for any losses.**

**Important:** A selected Cashu mint takes custody of your funds until you withdraw. For maximum sovereignty, run your own mint or enable auto-withdrawal to move funds immediately to your Lightning wallet.

---

## What is CashuPayServer?

CashuPayServer is a PHP-based Bitcoin Lightning payment gateway that implements BTCPay Server's Greenfield API. It uses [Cashu](https://cashu.space/) mints to handle Lightning payments, so you don't need to run your own Lightning node.

```
E-commerce (WooCommerce, etc.)
    │
    ▼ (Greenfield API)
CashuPayServer (PHP)
    │
    ▼ (Cashu Protocol)
Cashu Mint ──► Lightning Network
```

### Key Features

- **Any PHP hosting** - Works on $3/month shared hosting. If it runs WordPress, it runs this.
- **BTCPay-compatible API** - WooCommerce and other BTCPay plugins work by changing one URL.
- **No accounts or KYC** - Your store talks to the mint's public API directly.
- **Pure Lightning experience** - Customers see a normal Lightning invoice.
- **Auto-withdrawal** - Optionally send funds directly to your Lightning address.
- **Open source (MIT)** - Read every line of code. Fork it, audit it yourself.

### Trade-offs

CashuPayServer sits between custodial payment gateways and full self-hosting:

| Solution | Pros | Cons |
|----------|------|------|
| Custodial gateways | Easy setup | KYC, can freeze funds, geographic restrictions |
| BTCPay Server | Full sovereignty | Needs VPS ($20+/mo), Docker, ongoing maintenance |
| **CashuPayServer** | Simple, cheap hosting | Trust mint with funds until withdrawal |

## Requirements

- PHP 8.0 or higher
- Extensions: `curl`, `json`, `sqlite3`, `gmp`
- Apache with mod_rewrite, nginx, or any PHP-capable web server

## Installation

### Standalone (Any PHP Hosting)

1. **Download** the latest `cashupayserver.zip` from [GitHub Releases](https://github.com/jooray/cashupayserver/releases)
2. **Extract** the zip file
3. **Upload** to your web hosting via FTP or file manager
4. **Open** the URL in your browser (e.g., `https://yourdomain.com/cashupayserver/`)
5. **Follow** the setup wizard to configure your mint and password

### WordPress Plugin

1. **Download** `cashupay-wordpress.zip` from [GitHub Releases](https://github.com/jooray/cashupayserver/releases)
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. **Upload** the zip file and activate
4. Go to **Tools → CashuPay** to configure
5. Optionally integrates with the BTCPay for WooCommerce plugin if installed (configured during setup), or paste the pairing URL into BTCPay plugin settings manually

### Deployment Options

#### Option 1: Shared Hosting (No Server Configuration)

Upload files to your hosting and use the **front controller URL**:

```
Server URL: https://yoursite.com/cashupayserver/router.php
```

This works on any PHP host without server configuration. The e-commerce plugin will access URLs like:
- `https://yoursite.com/cashupayserver/router.php/api/v1/stores/.../invoices`

#### Option 2: Apache with mod_rewrite

If your host supports `.htaccess` and mod_rewrite (most do), use clean URLs:

```
Server URL: https://yoursite.com/cashupayserver
```

The included `.htaccess` handles URL rewriting automatically.

#### Option 3: nginx

Add to your server block:

```nginx
location /cashupayserver/ {
    # API routing
    location ~ ^/cashupayserver/api/v1/ {
        try_files $uri /cashupayserver/api.php$is_args$args;
    }

    # Block sensitive directories
    location ~ ^/cashupayserver/(data|includes|cashu-wallet-php)/ {
        deny all;
        return 403;
    }
}
```

Then use:
```
Server URL: https://yoursite.com/cashupayserver
```

### Connecting to WooCommerce

After installation, configure WooCommerce to use CashuPayServer:

1. Install the [BTCPay for WooCommerce](https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/) plugin
2. In WooCommerce → Settings → Payments → BTCPay, set:
   - **BTCPay Server URL**: Your CashuPayServer URL
   - Click **Connect to BTCPay** to start the pairing flow
3. Save and test with a small purchase

## Security

### Database Protection

The `data/` directory contains your SQLite database with ecash tokens (real Bitcoin value). It **must** be protected from HTTP access.

**Apache**: The `.htaccess` file handles this automatically.

**nginx**: Add `location /data/ { deny all; }` to your config.

**Verify protection**:
```bash
curl -I https://yoursite.com/cashupayserver/data/cashupay.sqlite
# Should return 403 Forbidden or 404 Not Found, NOT the file!
```

**Best practice**: Store data outside web root by creating `includes/config.local.php`:
```php
<?php
define('CASHUPAY_DATA_DIR', '/home/user/cashupay-data');
```

### Seed Phrase

Your seed phrase is your backup. Write it down and store it safely. If you lose access to your server, you can recover your funds with the seed phrase.

**Warning**: Do not import the seed phrase into another wallet you use actively. Using the same seed in multiple wallets causes coin loss.

## Development

### Quick Start with PHP Built-in Server

The simplest way to run CashuPayServer locally:

```bash
git clone --recurse-submodules https://github.com/jooray/cashupayserver.git
cd cashupayserver
php -S localhost:8000 router.php
```

Open http://localhost:8000 in your browser.

The `router.php` handles routing and **blocks access to sensitive directories** even without Apache/nginx configuration.

### Docker Test Environment

Two Docker configurations are available for **testing only** (not production). Both include WordPress, WooCommerce, and the BTCPay plugin pre-installed with SQLite (no MySQL needed).

See [DOCKER.md](DOCKER.md) for detailed setup instructions, persistent data volumes, and troubleshooting.

#### Standalone + WordPress (for testing both)

```bash
git clone --recurse-submodules https://github.com/jooray/cashupayserver.git
cd cashupayserver

docker build -f docker/Dockerfile.standalone -t cashupayserver-standalone .
docker run -p 80:80 -p 8080:8080 cashupayserver-standalone
```

This starts:
- **WordPress + WooCommerce**: http://localhost (login: admin/admin)
- **CashuPayServer standalone**: http://localhost:8080

#### WordPress Plugin Only

```bash
docker build -f docker/Dockerfile.wordpress -t cashupayserver-wordpress .
docker run -p 80:80 cashupayserver-wordpress
```

This starts:
- **WordPress with CashuPay plugin pre-installed**: http://localhost (login: admin/admin)
- Plugin available at **Tools → CashuPay**

### Building Distribution Packages

```bash
# Build standalone zip
./scripts/build-standalone.sh
# Output: build/cashupayserver.zip

# Build WordPress plugin
./scripts/build-wordpress-plugin.sh
# Output: build/cashupay-wordpress.zip
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

nginx ignores `.htaccess`. Add this to your server block:

```nginx
# Block sensitive directories
location ~ ^/(data|includes|cashu-wallet-php|mint-discovery|scripts|docker|docs|\.claude)/ {
    deny all;
    return 403;
}

# Block dotfiles
location ~ /\. {
    deny all;
}

# Block database files
location ~* \.(sqlite|db)$ {
    deny all;
}
```

### PHP Built-in Server

Using `router.php` is safe - it blocks sensitive paths:

```bash
php -S localhost:8000 router.php
```

**Do NOT use** `php -S localhost:8000` without the router - it will serve all files!

## API Reference

CashuPayServer implements the BTCPay Server Greenfield API:

### Create Invoice
```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/invoices" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": "10.00", "currency": "EUR"}'
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

Webhook events: `InvoiceCreated`, `InvoiceReceivedPayment`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid`

## Troubleshooting

### "404 Not Found" on API calls

Your server may not support URL rewriting. Use the front controller URL:
```
https://yoursite.com/cashupayserver/router.php
```

### "Forbidden" when accessing setup

Check that PHP files are executable and the web server can read them.

### Payments not detected

1. Check that your mint is online
2. Click the refresh button in your dashboard
3. Enable PHP error logging to see detailed errors

## Related Projects

- **[cashu-wallet-php](https://github.com/jooray/cashu-wallet-php)** - Standalone PHP library for Cashu protocol
- **[btcpay-greenfield-test](https://github.com/jooray/btcpay-greenfield-test)** - Minimalistic PHP page to test BTCPay Server's Greenfield API integrations
- **[BTCPay Server](https://btcpayserver.org/)** - The gold standard for self-hosted Bitcoin payments
- **[Cashu](https://cashu.space/)** - Ecash protocol for Bitcoin

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Support and Value4Value

If you like this project, I would appreciate if you contributed time, talent, or treasure.

**Time and talent** can be used in testing it out, fixing bugs, or submitting pull requests.

**Treasure** can be [donated here](https://cashupayserver.org/#donate). You can also enable optional donations when withdrawing funds - a small percentage goes back to support development.

## License

MIT License - see [LICENSE](LICENSE) file.

## Links

- **Website**: [cashupayserver.org](https://cashupayserver.org/)
- **Issues**: [GitHub Issues](https://github.com/jooray/cashupayserver/issues)
