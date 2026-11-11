<?php
/**
 * CashuPayServer - Product image server
 *
 * Serves uploaded product images from data/uploads/products. Public, because
 * the payer-facing checkout page shows them — but the filename is strictly
 * validated against the pattern the upload handler mints (Product::
 * isValidImageFilename), and basename() strips any path, so there is no path
 * traversal and only real uploaded images can be read.
 */

declare(strict_types=1);


require_once __DIR__ . '/includes/http_status.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/products.php';

$f = $_GET['f'] ?? '';
$f = is_string($f) ? basename($f) : '';

if (!Product::isValidImageFilename($f)) {
    cashupay_status(404);
    exit;
}

$path = Product::uploadsDir() . '/' . $f;
if (!is_file($path)) {
    cashupay_status(404);
    exit;
}

$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
