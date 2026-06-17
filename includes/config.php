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
    define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
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
