<?php
require_once __DIR__ . '/db.php';

// ─── Sanitization ────────────────────────────────────────────────────────────

/**
 * Parse a decimal string in either BR format ("1.234,56") or EN format ("1234.56").
 * If the string contains a comma, dots are treated as thousands separators and
 * the comma as the decimal separator. Otherwise dot is the decimal separator.
 */
function parse_decimal(string $v): float {
    $v = trim($v);
    if (str_contains($v, ',')) {
        // BR format: strip thousands dots, replace decimal comma
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return (float) $v;
}

function slugify(string $text): string {
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y'];
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/** Returns the canonical public URL for a miniature, e.g. /mini/34/pandem-datsun-620 */
function mini_url(array $miniature): string {
    return '/mini/' . (int)$miniature['id'] . '/' . slugify($miniature['name']);
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e(?string $value): string {
    return h($value ?? '');
}

/**
 * Returns an array of page numbers (int) and nulls (ellipsis markers)
 * for smart pagination. Example: [1, null, 4, 5, 6, null, 12]
 */
function pagination_range(int $current, int $total, int $delta = 2): array {
    if ($total <= 1) return [];
    $range = [];
    $left  = max(1, $current - $delta);
    $right = min($total, $current + $delta);
    if ($left > 1) {
        $range[] = 1;
        if ($left > 2) $range[] = null;
    }
    for ($i = $left; $i <= $right; $i++) $range[] = $i;
    if ($right < $total) {
        if ($right < $total - 1) $range[] = null;
        $range[] = $total;
    }
    return $range;
}

// ─── Flash messages ──────────────────────────────────────────────────────────

function flash(string $message, string $type = 'success'): void {
    session_start_once();
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): ?array {
    session_start_once();
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ─── Redirect ────────────────────────────────────────────────────────────────

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// ─── Miniatures ──────────────────────────────────────────────────────────────

function _miniatures_where(array $filters, bool $fulltext = true): array {
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['manufacturer'])) {
        $where[] = 'm.manufacturer = ?';
        $params[] = $filters['manufacturer'];
    }
    if (!empty($filters['scale'])) {
        $where[] = 'm.scale = ?';
        $params[] = $filters['scale'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 'm.category_id = ?';
        $params[] = $filters['category_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'm.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['search'])) {
        $term = $filters['search'];
        if ($fulltext && strlen($term) >= 3) {
            // FULLTEXT boolean mode: each word becomes +word* (prefix match)
            $words    = preg_split('/\s+/', trim(preg_replace('/[+\-><()"~*@]+/', ' ', $term)));
            $ft_query = implode(' ', array_map(fn($w) => '+' . $w . '*', array_filter($words)));
            $where[]  = 'MATCH(m.name, m.manufacturer, m.model) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $ft_query;
        } else {
            $where[] = '(m.name LIKE ? OR m.manufacturer LIKE ? OR m.model LIKE ?)';
            $s = '%' . $term . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
    }
    if (!empty($filters['tag_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM miniature_tags mt WHERE mt.miniature_id = m.id AND mt.tag_id = ?)';
        $params[] = $filters['tag_id'];
    }
    // By default only show public miniatures; pass is_public=null to skip the filter (admin)
    if (array_key_exists('is_public', $filters)) {
        if ($filters['is_public'] !== null) {
            $where[] = 'm.is_public = ?';
            $params[] = (int) $filters['is_public'];
        }
        // null = no filter (admin sees all)
    } else {
        // Default: public only
        $where[] = 'm.is_public = 1';
    }

    return [$where, $params];
}

function count_miniatures(array $filters = []): int {
    $use_ft = !empty($filters['search']) && strlen($filters['search']) >= 3;
    foreach ($use_ft ? [true, false] : [false] as $ft) {
        try {
            [$where, $params] = _miniatures_where($filters, $ft);
            $stmt = db()->prepare('SELECT COUNT(*) FROM miniatures m WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (!$ft) throw $e; // non-fulltext error — re-throw
        }
    }
    return 0;
}

function get_miniatures(array $filters = []): array {
    $use_ft = !empty($filters['search']) && strlen($filters['search']) >= 3;
    $per_page = max(1, (int) ($filters['per_page'] ?? PER_PAGE));
    $page     = max(1, (int) ($filters['page']     ?? 1));
    $offset   = ($page - 1) * $per_page;

    $order = match ($filters['sort'] ?? '') {
        'name'         => 'm.name ASC',
        'manufacturer' => 'm.manufacturer ASC, m.name ASC',
        'year_asc'     => 'm.year ASC, m.name ASC',
        'year_desc'    => 'm.year DESC, m.name ASC',
        default        => 'm.created_at DESC',
    };

    foreach ($use_ft ? [true, false] : [false] as $ft) {
        try {
            [$where, $params] = _miniatures_where($filters, $ft);
            $sql = 'SELECT m.*, c.name AS category_name,
                           p.file_path AS primary_photo,
                           (SELECT COUNT(*) FROM miniature_photos WHERE miniature_id = m.id) AS photo_count
                    FROM miniatures m
                    LEFT JOIN categories c ON m.category_id = c.id
                    LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY ' . $order . '
                    LIMIT ? OFFSET ?';
            $params[] = $per_page;
            $params[] = $offset;
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            if (!$ft) throw $e;
        }
    }
    return [];
}

function get_miniature(int $id): ?array {
    $stmt = db()->prepare(
        'SELECT m.*, c.name AS category_name
         FROM miniatures m
         LEFT JOIN categories c ON m.category_id = c.id
         WHERE m.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_miniature_photos(int $miniature_id): array {
    $stmt = db()->prepare(
        'SELECT * FROM miniature_photos WHERE miniature_id = ? ORDER BY is_primary DESC, sort_order ASC'
    );
    $stmt->execute([$miniature_id]);
    return $stmt->fetchAll();
}

function get_miniature_tags(int $miniature_id): array {
    $stmt = db()->prepare(
        'SELECT t.* FROM tags t
         INNER JOIN miniature_tags mt ON mt.tag_id = t.id
         WHERE mt.miniature_id = ?
         ORDER BY t.name ASC'
    );
    $stmt->execute([$miniature_id]);
    return $stmt->fetchAll();
}

function get_primary_photo(int $miniature_id): ?string {
    $stmt = db()->prepare(
        'SELECT file_path FROM miniature_photos WHERE miniature_id = ? AND is_primary = 1 LIMIT 1'
    );
    $stmt->execute([$miniature_id]);
    $row = $stmt->fetch();
    return $row ? $row['file_path'] : null;
}

// ─── Categories ──────────────────────────────────────────────────────────────

function get_categories(): array {
    return db()->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
}

// ─── Tags ────────────────────────────────────────────────────────────────────

function get_tags(): array {
    return db()->query('SELECT * FROM tags ORDER BY name ASC')->fetchAll();
}

// ─── Manufacturers / Scales (distinct from existing data) ────────────────────

function get_distinct_manufacturers(): array {
    return db()->query(
        "SELECT DISTINCT manufacturer FROM miniatures WHERE manufacturer != '' ORDER BY manufacturer ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function get_distinct_scales(): array {
    return db()->query(
        "SELECT DISTINCT scale FROM miniatures WHERE scale IS NOT NULL AND scale != '' ORDER BY scale ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ─── Dashboard stats ─────────────────────────────────────────────────────────

function get_stats(): array {
    $total = (int) db()->query('SELECT COUNT(*) FROM miniatures')->fetchColumn();

    $by_scale = db()->query(
        "SELECT scale, COUNT(*) AS total FROM miniatures
         WHERE scale IS NOT NULL AND scale != ''
         GROUP BY scale ORDER BY total DESC"
    )->fetchAll();

    $by_manufacturer = db()->query(
        "SELECT manufacturer, COUNT(*) AS total FROM miniatures
         GROUP BY manufacturer ORDER BY total DESC LIMIT 10"
    )->fetchAll();

    $by_category = db()->query(
        "SELECT c.name, COUNT(m.id) AS total
         FROM miniatures m
         LEFT JOIN categories c ON m.category_id = c.id
         GROUP BY c.name ORDER BY total DESC"
    )->fetchAll();

    $by_status = db()->query(
        "SELECT status, COUNT(*) AS total FROM miniatures GROUP BY status"
    )->fetchAll();

    $financial = db()->query(
        "SELECT
            SUM(purchase_price)                                          AS total_paid,
            SUM(estimated_price)                                         AS total_estimated,
            COUNT(purchase_price)                                        AS count_paid,
            COUNT(estimated_price)                                       AS count_estimated,
            SUM(CASE WHEN purchase_price IS NOT NULL
                      AND estimated_price IS NOT NULL
                     THEN purchase_price END)                            AS both_paid,
            SUM(CASE WHEN purchase_price IS NOT NULL
                      AND estimated_price IS NOT NULL
                     THEN estimated_price END)                           AS both_estimated,
            COUNT(CASE WHEN purchase_price IS NOT NULL
                        AND estimated_price IS NOT NULL
                       THEN 1 END)                                       AS count_both
         FROM miniatures"
    )->fetch();

    try {
        $top_viewed = db()->query(
            "SELECT id, name, manufacturer, views FROM miniatures WHERE is_public = 1 AND views > 0 ORDER BY views DESC LIMIT 5"
        )->fetchAll();
    } catch (\PDOException $e) {
        $top_viewed = [];
    }

    return compact('total', 'by_scale', 'by_manufacturer', 'by_category', 'by_status', 'financial', 'top_viewed');
}

function get_adjacent_miniatures(int $id): array {
    $db   = db();
    $stmt = $db->prepare('SELECT id, name, manufacturer FROM miniatures WHERE is_public = 1 AND id < ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $prev = $stmt->fetch() ?: null;
    $stmt = $db->prepare('SELECT id, name, manufacturer FROM miniatures WHERE is_public = 1 AND id > ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$id]);
    $next = $stmt->fetch() ?: null;
    return compact('prev', 'next');
}

// ─── Wishlist ────────────────────────────────────────────────────────────────

function get_wishlist(string $status = ''): array {
    if ($status) {
        $stmt = db()->prepare('SELECT * FROM wishlist WHERE status = ? ORDER BY created_at DESC');
        $stmt->execute([$status]);
    } else {
        $stmt = db()->query('SELECT * FROM wishlist ORDER BY created_at DESC');
    }
    return $stmt->fetchAll();
}

// ─── Photo upload ─────────────────────────────────────────────────────────────

function upload_photo(array $file, int $miniature_id): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
        return null;
    }

    $dir = UPLOADS_DIR . $miniature_id . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }

    $filename = bin2hex(random_bytes(12)) . '.webp';
    $dest = $dir . $filename;

    // Load source image into GD
    $image = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/gif'  => imagecreatefromgif($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        default      => null,
    };

    if (!$image) {
        return null;
    }

    // Preserve alpha channel for PNG/GIF sources
    if (in_array($mime, ['image/png', 'image/gif'], true)) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }

    $ok = imagewebp($image, $dest, WEBP_QUALITY);

    if (!$ok) {
        imagedestroy($image);
        return null;
    }
    @chmod($dest, 0644);

    // Generate thumbnail
    $orig_w = imagesx($image);
    $orig_h = imagesy($image);
    if ($orig_w > THUMB_WIDTH) {
        $thumb_h = (int) round($orig_h * THUMB_WIDTH / $orig_w);
        $thumb   = imagecreatetruecolor(THUMB_WIDTH, $thumb_h);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, THUMB_WIDTH, $thumb_h, $orig_w, $orig_h);
        $thumb_dest = $dir . substr($filename, 0, -5) . '_thumb.webp';
        imagewebp($thumb, $thumb_dest, WEBP_QUALITY);
        @chmod($thumb_dest, 0644);
        imagedestroy($thumb);
    } else {
        // Original already fits; symlink would waste space — just copy
        copy($dest, $dir . substr($filename, 0, -5) . '_thumb.webp');
        @chmod($dir . substr($filename, 0, -5) . '_thumb.webp', 0644);
    }

    imagedestroy($image);

    return $miniature_id . '/' . $filename;
}

function delete_photo(int $photo_id, int $miniature_id): void {
    $stmt = db()->prepare('SELECT file_path FROM miniature_photos WHERE id = ? AND miniature_id = ?');
    $stmt->execute([$photo_id, $miniature_id]);
    $photo = $stmt->fetch();
    if (!$photo) {
        return;
    }

    $path = UPLOADS_DIR . $photo['file_path'];
    if (file_exists($path)) {
        unlink($path);
    }
    // Also remove thumbnail if it exists
    $thumb_path = UPLOADS_DIR . thumb_path($photo['file_path']);
    if ($thumb_path !== $path && file_exists($thumb_path)) {
        unlink($thumb_path);
    }

    db()->prepare('DELETE FROM miniature_photos WHERE id = ?')->execute([$photo_id]);
}

// ─── Status labels ───────────────────────────────────────────────────────────

function status_label(string $status): string {
    return match ($status) {
        'open'    => 'Aberta',
        'sealed'  => 'Lacrada',
        'display' => 'Em exposição',
        'storage' => 'Em armazenamento',
        default   => ucfirst($status),
    };
}

function status_badge(string $status): string {
    $class = match ($status) {
        'open'    => 'success',
        'sealed'  => 'primary',
        'display' => 'info',
        'storage' => 'secondary',
        default   => 'light',
    };
    return '<span class="badge bg-' . $class . '">' . h(status_label($status)) . '</span>';
}

function wishlist_status_label(string $status): string {
    return match ($status) {
        'wanted'    => 'Desejada',
        'purchased' => 'Comprada',
        'cancelled' => 'Cancelada',
        default     => ucfirst($status),
    };
}

function get_public_rating(int $miniature_id): array {
    try {
        $stmt = db()->prepare(
            'SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS count
             FROM miniature_ratings WHERE miniature_id = ?'
        );
        $stmt->execute([$miniature_id]);
        $row = $stmt->fetch();
        return ['avg' => (float)($row['avg_rating'] ?? 0), 'count' => (int)($row['count'] ?? 0)];
    } catch (Throwable $e) {
        return ['avg' => 0, 'count' => 0];
    }
}

function submit_public_rating(int $miniature_id, int $rating, string $ip): bool {
    if ($rating < 1 || $rating > 5) return false;
    try {
        $ip_hash = hash('sha256', $ip);
        $stmt = db()->prepare(
            'INSERT INTO miniature_ratings (miniature_id, ip_hash, rating)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating)'
        );
        $stmt->execute([$miniature_id, $ip_hash, $rating]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function emotional_rating_label(int $rating): string {
    return match ($rating) {
        1 => 'Pouco importante',
        2 => 'Gosto da peça',
        3 => 'Muito importante',
        4 => 'Especial',
        5 => 'Nunca vender',
        default => '',
    };
}

function emotional_rating_badge(int $rating): string {
    [$icon, $color, $label] = match ($rating) {
        1 => ['fa-circle',       'secondary', 'Pouco importante'],
        2 => ['fa-heart',        'info',      'Gosto da peça'],
        3 => ['fa-heart',        'success',   'Muito importante'],
        4 => ['fa-gem',          'warning',   'Especial'],
        5 => ['fa-lock',         'danger',    'Nunca vender'],
        default => ['fa-circle', 'secondary', ''],
    };
    return '<span class="badge bg-' . $color . '"><i class="fa ' . $icon . ' me-1"></i>' . $label . '</span>';
}

function photo_url(?string $file_path): string {
    if (!$file_path) {
        return '/assets/img/no-photo.svg';
    }
    // Guard against path traversal: only allow id/hexname.ext patterns.
    if (!preg_match('#^\d+/[a-f0-9]+\.(jpg|jpeg|png|webp|gif)$#i', $file_path)) {
        return '/assets/img/no-photo.svg';
    }
    return '/uploads/' . $file_path;
}

// Returns the thumb file_path derived by convention (no DB lookup).
function thumb_path(?string $file_path): string {
    if (!$file_path || !preg_match('#^\d+/([a-f0-9]+)\.webp$#i', $file_path)) {
        return $file_path ?? '';
    }
    return preg_replace('#\.webp$#i', '_thumb.webp', $file_path);
}

// URL for the thumbnail; falls back gracefully to full image via data-fallback in JS.
function thumb_url(?string $file_path): string {
    if (!$file_path) {
        return '/assets/img/no-photo.svg';
    }
    if (!preg_match('#^\d+/[a-f0-9]+\.webp$#i', $file_path)) {
        // Non-webp or unrecognised — just use original
        return photo_url($file_path);
    }
    return '/uploads/' . thumb_path($file_path);
}

// ─── Recent miniatures ───────────────────────────────────────────────────────

function get_recent_miniatures(int $limit = 5): array {
    $stmt = db()->prepare(
        'SELECT m.*, c.name AS category_name,
                p.file_path AS primary_photo
         FROM miniatures m
         LEFT JOIN categories c ON m.category_id = c.id
         LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
         ORDER BY m.created_at DESC
         LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}
