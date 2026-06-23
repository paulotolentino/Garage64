<?php
// Garage64 Configuration
// Copy this file to config.local.php and adjust values for your environment.
// If config.local.php exists it will be loaded automatically.

// Load local overrides first to avoid redefine warnings in strict environments,
// then fill any missing defaults.
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'garage64');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

// Application settings
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Garage64');
}
if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost');
}
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', __DIR__ . '/../uploads/');
}
if (!defined('UPLOADS_URL')) {
    define('UPLOADS_URL', APP_URL . '/uploads/');
}

// Session name
if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'garage64_session');
}

// Allowed image types
if (!defined('ALLOWED_IMAGE_TYPES')) {
    define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
}
if (!defined('MAX_UPLOAD_SIZE')) {
    define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 MB
}
if (!defined('WEBP_QUALITY')) {
    define('WEBP_QUALITY', 85); // 0–100; 85 is a good balance of size vs. quality
}
if (!defined('THUMB_WIDTH')) {
    define('THUMB_WIDTH', 400); // max width in px for generated thumbnails
}
if (!defined('PER_PAGE')) {
    define('PER_PAGE', 10); // items per page in the public listing
}

// Default social sharing image (Open Graph / Twitter Cards).
// Empty by default → no site-wide fallback image is emitted. To enable rich
// previews on pages without their own image (landing, collections, community),
// drop a 1200×630 raster (PNG/JPG) into assets/img/ and point this to its
// absolute URL, e.g.:
//   define('OG_DEFAULT_IMAGE', APP_URL . '/assets/img/og-default.png');
// (best set in config.local.php). SVG is intentionally avoided here because
// most social scrapers (Facebook, X/Twitter, LinkedIn) reject SVG previews.
if (!defined('OG_DEFAULT_IMAGE')) {
    define('OG_DEFAULT_IMAGE', '');
}

// ─── HTTPS detection ─────────────────────────────────────────────────────────
// Central helper reused by the session cookie hardening (secure flag) and the
// environment detection below. Conservative: only reports HTTPS when the
// request is actually served over TLS (directly or via a TLS-terminating proxy).
if (!function_exists('is_https')) {
    function is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Behind a reverse proxy / load balancer that terminates TLS.
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }
}

// ─── Environment + error policy (M2) ─────────────────────────────────────────
// APP_DEBUG controls whether errors are shown on screen. It can be forced in
// config.local.php (e.g. define('APP_DEBUG', true);). When unset, it is
// auto-detected: ON for local hosts and CLI, OFF for real domains (production).
if (!defined('APP_DEBUG')) {
    $g64_host  = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $g64_local = $g64_host === ''                       // CLI (e.g. php -l)
        || $g64_host === 'localhost'
        || $g64_host === '127.0.0.1'
        || $g64_host === '::1'
        || str_ends_with($g64_host, '.local')
        || str_ends_with($g64_host, '.test');
    define('APP_DEBUG', $g64_local);
}

// Always log errors; only display them in debug/local environments. In
// production this prevents leaking paths, SQL and stack traces to end users.
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
