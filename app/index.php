<?php
/**
 * CashuPayServer - Main Entry Point
 *
 * Routes to setup wizard if not configured, otherwise serves as landing page.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/urls.php';

// Check if database is initialized
if (!Database::isInitialized()) {
    // Fresh install: the schema does not exist yet, so we must NOT build the
    // redirect via Urls::setup(). In standalone mode that reads DB-backed
    // config (base_url / url_mode), which would query the not-yet-created
    // `config` table and surface an uncaught "no such table" as a 500 — the
    // bare directory URL then fails on every visit until setup.php has run
    // once. A relative redirect needs no configuration and always resolves
    // from the directory index (and through router.php's front controller).
    header('Location: setup.php');
    exit;
}

// A failed database upgrade must never look like a fresh install: say so
// plainly instead of redirecting into the wizard or an opaque 500.
if (Database::getMigrationError() !== null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database upgrade failed: " . Database::getMigrationError() . "\nFix the data directory permissions or restore the database, then reload.\n";
    exit;
}

// Check if setup is complete
if (!Config::isSetupComplete()) {
    header('Location: ' . Urls::setup());
    exit;
}

// If logged in, redirect to admin
require_once __DIR__ . '/includes/auth.php';
if (Auth::isLoggedIn()) {
    header('Location: ' . Urls::admin());
    exit;
}

// Otherwise show simple landing page with login link
$baseUrl = Config::getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BareBits</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .logo {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .tagline {
            color: #a0aec0;
            margin-bottom: 2rem;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #f7931a;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #e8820a;
        }
        .footer {
            margin-top: 3rem;
            color: #4a5568;
            font-size: 0.875rem;
        }
        .footer a {
            color: #718096;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">&#9889;</div>
        <h1>BareBits</h1>
        <p class="tagline">Lightning payments with Cashu ecash</p>
        <a href="<?= htmlspecialchars(Urls::admin()) ?>" class="btn">Admin Login</a>
        <div class="footer">
            <p>BTCPay Server compatible API for e-commerce integrations</p>
        </div>
    </div>
</body>
</html>
