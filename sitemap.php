<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$base = rtrim(APP_URL, '/');

// Fetch all public miniatures ordered by most recently updated
$stmt = db()->query(
    "SELECT id, updated_at FROM miniatures WHERE is_public = 1 ORDER BY updated_at DESC"
);
$miniatures = $stmt->fetchAll();

// Fetch public collector garages (users with at least one public miniature).
// lastmod = the most recent public miniature update for that collector.
try {
    $garages = db()->query(
        "SELECT u.slug, MAX(m.updated_at) AS lastmod
         FROM admin_users u
         INNER JOIN miniatures m ON m.user_id = u.id AND m.is_public = 1
         WHERE u.is_banned = 0
         GROUP BY u.id, u.slug
         ORDER BY lastmod DESC"
    )->fetchAll();
} catch (\PDOException $e) {
    $garages = [];
}

$lastmod_index = !empty($miniatures)
    ? date('Y-m-d', strtotime($miniatures[0]['updated_at']))
    : date('Y-m-d');

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex'); // sitemap itself shouldn't be indexed
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= h($base) ?>/</loc>
        <lastmod><?= $lastmod_index ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc><?= h($base) ?>/collections</loc>
        <lastmod><?= $lastmod_index ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= h($base) ?>/community</loc>
        <lastmod><?= $lastmod_index ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.6</priority>
    </url>
    <?php foreach ($garages as $g): ?>
    <url>
        <loc><?= h($base . '/u/' . rawurlencode($g['slug'])) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($g['lastmod'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    <?php endforeach; ?>
    <?php foreach ($miniatures as $m): ?>
    <url>
        <loc><?= h($base . mini_url($m)) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($m['updated_at'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <?php endforeach; ?>
</urlset>
