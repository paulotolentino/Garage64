<?php
// Garage64 Configuration
// Copy this file to config.local.php and adjust values for your environment.
// If config.local.php exists it will be loaded automatically.

define('DB_HOST', 'localhost');
define('DB_NAME', 'garage64');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Garage64');
define('APP_URL', 'http://localhost');
define('UPLOADS_DIR', __DIR__ . '/../uploads/');
define('UPLOADS_URL', APP_URL . '/uploads/');

// Session name
define('SESSION_NAME', 'garage64_session');

// Allowed image types
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB

// Load local overrides if present
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
