<?php
// Legacy URL — redirected to new canonical URL
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_superadmin();
header('Location: /admin/manutencao', true, 301);
exit;
