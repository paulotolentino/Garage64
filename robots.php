<?php
// TODO: quando quiser indexação pública, trocar Disallow: / por Allow: /
// e descomentar a linha do Sitemap abaixo.
require_once __DIR__ . '/includes/config.php';
header('Content-Type: text/plain');
?>
User-agent: *
Disallow: /
