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
    if (!empty($filters['condition'])) {
        $where[] = 'm.`condition` = ?';
        $params[] = $filters['condition'];
    }
    if (!empty($filters['location'])) {
        $where[] = 'm.location = ?';
        $params[] = $filters['location'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'm.user_id = ?';
        $params[] = (int) $filters['user_id'];
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
        'recent'       => 'm.created_at DESC',
        default        => 'm.is_featured DESC, m.sort_order ASC',
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

function get_miniature(int $id, ?int $viewer_id = null): ?array {
    $stmt = db()->prepare(
        'SELECT m.*, c.name AS category_name,
                (SELECT COUNT(*) FROM miniature_likes WHERE miniature_id = m.id) AS likes_count,
                EXISTS(SELECT 1 FROM miniature_likes WHERE miniature_id = m.id AND user_id = ?) AS user_liked
         FROM miniatures m
         LEFT JOIN categories c ON m.category_id = c.id
         WHERE m.id = ?'
    );
    $stmt->execute([$viewer_id ?? 0, $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Ownership guard: true only when the miniature exists AND belongs to $user_id.
 * Used by the admin CRUD to enforce per-collector isolation (no cross-user access).
 */
function user_owns_miniature(int $miniature_id, int $user_id): bool {
    if ($miniature_id <= 0 || $user_id <= 0) return false;
    $stmt = db()->prepare('SELECT 1 FROM miniatures WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$miniature_id, $user_id]);
    return (bool) $stmt->fetchColumn();
}

/** Adds a like (idempotent — UNIQUE constraint prevents duplicates). */
function like_miniature(int $miniature_id, int $user_id): void {
    db()->prepare(
        'INSERT IGNORE INTO miniature_likes (miniature_id, user_id) VALUES (?, ?)'
    )->execute([$miniature_id, $user_id]);
}

/** Removes a like (no-op if it doesn't exist). */
function unlike_miniature(int $miniature_id, int $user_id): void {
    db()->prepare(
        'DELETE FROM miniature_likes WHERE miniature_id = ? AND user_id = ?'
    )->execute([$miniature_id, $user_id]);
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

function get_categories(int $user_id = 0): array {
    if ($user_id > 0) {
        $stmt = db()->prepare('SELECT * FROM categories WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
}

// ─── Tags ────────────────────────────────────────────────────────────────────

function get_tags(int $user_id = 0): array {
    if ($user_id > 0) {
        $stmt = db()->prepare('SELECT * FROM tags WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM tags ORDER BY name ASC')->fetchAll();
}

// ─── Manufacturers / Scales (distinct from existing data) ────────────────────

function get_distinct_manufacturers(int $user_id = 0): array {
    if ($user_id > 0) {
        $stmt = db()->prepare("SELECT DISTINCT manufacturer FROM miniatures WHERE user_id = ? AND manufacturer != '' ORDER BY manufacturer ASC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return db()->query(
        "SELECT DISTINCT manufacturer FROM miniatures WHERE manufacturer != '' ORDER BY manufacturer ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function get_distinct_scales(int $user_id = 0): array {
    if ($user_id > 0) {
        $stmt = db()->prepare("SELECT DISTINCT scale FROM miniatures WHERE user_id = ? AND scale IS NOT NULL AND scale != '' ORDER BY scale ASC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return db()->query(
        "SELECT DISTINCT scale FROM miniatures WHERE scale IS NOT NULL AND scale != '' ORDER BY scale ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ─── Dashboard stats ─────────────────────────────────────────────────────────

function get_stats(int $user_id = 0): array {
    $uid_where = $user_id > 0 ? 'WHERE user_id = ' . $user_id : '';
    $uid_and   = $user_id > 0 ? 'AND m.user_id = ' . $user_id : '';

    $total = (int) db()->query('SELECT COUNT(*) FROM miniatures ' . $uid_where)->fetchColumn();

    $by_scale = db()->query(
        "SELECT scale, COUNT(*) AS total FROM miniatures m
         WHERE scale IS NOT NULL AND scale != '' $uid_and
         GROUP BY scale ORDER BY total DESC"
    )->fetchAll();

    $by_manufacturer = db()->query(
        "SELECT manufacturer, COUNT(*) AS total FROM miniatures
         $uid_where GROUP BY manufacturer ORDER BY total DESC LIMIT 10"
    )->fetchAll();

    $by_category = db()->query(
        "SELECT c.name, COUNT(m.id) AS total
         FROM miniatures m
         LEFT JOIN categories c ON m.category_id = c.id
         WHERE 1=1 $uid_and
         GROUP BY c.name ORDER BY total DESC"
    )->fetchAll();

    $by_condition = db()->query(
        "SELECT `condition`, COUNT(*) AS total FROM miniatures $uid_where GROUP BY `condition`"
    )->fetchAll();

    $by_location = db()->query(
        "SELECT location, COUNT(*) AS total FROM miniatures $uid_where GROUP BY location"
    )->fetchAll();

    $financial = db()->query(
        "SELECT
            SUM(purchase_price)  AS total_paid,
            SUM(estimated_price) AS total_estimated,
            COUNT(purchase_price)  AS count_paid,
            COUNT(estimated_price) AS count_estimated,
            SUM(CASE WHEN purchase_price IS NOT NULL AND estimated_price IS NOT NULL THEN purchase_price END)  AS both_paid,
            SUM(CASE WHEN purchase_price IS NOT NULL AND estimated_price IS NOT NULL THEN estimated_price END) AS both_estimated,
            COUNT(CASE WHEN purchase_price IS NOT NULL AND estimated_price IS NOT NULL THEN 1 END) AS count_both
         FROM miniatures $uid_where"
    )->fetch();

    try {
        $top_viewed = db()->query(
            "SELECT id, name, manufacturer, views FROM miniatures m
             WHERE is_public = 1 AND views > 0 $uid_and
             ORDER BY views DESC LIMIT 5"
        )->fetchAll();
    } catch (\PDOException $e) {
        $top_viewed = [];
    }

    return compact('total', 'by_scale', 'by_manufacturer', 'by_category', 'by_condition', 'by_location', 'financial', 'top_viewed');
}

function get_adjacent_miniatures(int $id, int $user_id = 0): array {
    $db    = db();
    $ucond = $user_id > 0 ? ' AND user_id = ' . $user_id : '';
    $stmt  = $db->prepare("SELECT id, name, manufacturer FROM miniatures WHERE is_public = 1 AND id < ? $ucond ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id]);
    $prev = $stmt->fetch() ?: null;
    $stmt = $db->prepare("SELECT id, name, manufacturer FROM miniatures WHERE is_public = 1 AND id > ? $ucond ORDER BY id ASC LIMIT 1");
    $stmt->execute([$id]);
    $next = $stmt->fetch() ?: null;
    return compact('prev', 'next');
}

// ─── Users ───────────────────────────────────────────────────────────────────

function get_user_by_slug(string $slug): ?array {
    $stmt = db()->prepare(
        'SELECT id, username, slug, display_name, bio
         FROM admin_users WHERE slug = ? AND is_banned = 0 LIMIT 1'
    );
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_featured_collections(int $limit = 6): array {
    try {
        return db()->query(
            "SELECT u.id, u.slug, u.display_name, u.bio, u.avatar,
                    COUNT(m.id) AS mini_count
             FROM admin_users u
             INNER JOIN miniatures m ON m.user_id = u.id AND m.is_public = 1
             WHERE u.is_banned = 0 AND u.is_featured = 1
             GROUP BY u.id
             ORDER BY mini_count DESC
             LIMIT $limit"
        )->fetchAll();
    } catch (\PDOException $e) {
        return []; // is_featured column may not exist yet
    }
}

function get_all_collections(): array {
    return db()->query(
        "SELECT u.id, u.slug, u.display_name, u.bio, u.avatar,
                COUNT(m.id) AS mini_count
         FROM admin_users u
         INNER JOIN miniatures m ON m.user_id = u.id AND m.is_public = 1
         WHERE u.is_banned = 0
         GROUP BY u.id
         ORDER BY mini_count DESC, u.display_name ASC"
    )->fetchAll();
}

function avatar_url(?string $avatar): string {
    if ($avatar) {
        return UPLOADS_URL . 'avatars/' . rawurlencode($avatar);
    }
    return APP_URL . '/assets/img/avatar-default.svg';
}

// ─── Follows (social module — Phase 7, Instagram-style one-way follow) ────────

/** Creates a follow relationship (idempotent — UNIQUE prevents duplicates; self-follow blocked). */
function follow_user(int $follower_id, int $following_id): void {
    if ($follower_id <= 0 || $following_id <= 0 || $follower_id === $following_id) return;
    db()->prepare(
        'INSERT IGNORE INTO user_follows (follower_id, following_id) VALUES (?, ?)'
    )->execute([$follower_id, $following_id]);
}

/** Removes a follow relationship (no-op if it doesn't exist). */
function unfollow_user(int $follower_id, int $following_id): void {
    if ($follower_id <= 0 || $following_id <= 0) return;
    db()->prepare(
        'DELETE FROM user_follows WHERE follower_id = ? AND following_id = ?'
    )->execute([$follower_id, $following_id]);
}

/** Whether $follower_id currently follows $following_id. */
function is_following(int $follower_id, int $following_id): bool {
    if ($follower_id <= 0 || $following_id <= 0) return false;
    $stmt = db()->prepare(
        'SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ? LIMIT 1'
    );
    $stmt->execute([$follower_id, $following_id]);
    return (bool) $stmt->fetchColumn();
}

/** How many users follow $user_id. */
function count_followers(int $user_id): int {
    if ($user_id <= 0) return 0;
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_follows WHERE following_id = ?');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

/** How many users $user_id follows. */
function count_following(int $user_id): int {
    if ($user_id <= 0) return 0;
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_follows WHERE follower_id = ?');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Collectors who follow $user_id (newest first), with public-miniature counts.
 * Excludes banned users. Never selects private columns.
 */
function get_followers(int $user_id): array {
    if ($user_id <= 0) return [];
    try {
        $stmt = db()->prepare(
            "SELECT u.id, u.username, u.slug, u.display_name, u.bio, u.avatar,
                    (SELECT COUNT(*) FROM miniatures m WHERE m.user_id = u.id AND m.is_public = 1) AS mini_count
             FROM user_follows f
             JOIN admin_users u ON u.id = f.follower_id AND u.is_banned = 0
             WHERE f.following_id = ?
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return []; // graceful: e.g. legacy DB without avatar column
    }
}

/**
 * Collectors that $user_id follows (newest first), with public-miniature counts.
 * Excludes banned users. Never selects private columns.
 */
function get_following(int $user_id): array {
    if ($user_id <= 0) return [];
    try {
        $stmt = db()->prepare(
            "SELECT u.id, u.username, u.slug, u.display_name, u.bio, u.avatar,
                    (SELECT COUNT(*) FROM miniatures m WHERE m.user_id = u.id AND m.is_public = 1) AS mini_count
             FROM user_follows f
             JOIN admin_users u ON u.id = f.following_id AND u.is_banned = 0
             WHERE f.follower_id = ?
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return [];
    }
}

// ─── Wishlist ────────────────────────────────────────────────────────────────

function get_wishlist(string $status = '', int $user_id = 0): array {
    $conds = [];
    $params = [];
    if ($user_id > 0) { $conds[] = 'user_id = ?'; $params[] = $user_id; }
    if ($status)      { $conds[] = 'status = ?';  $params[] = $status; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $stmt = db()->prepare('SELECT * FROM wishlist ' . $where . ' ORDER BY created_at DESC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ─── Photo upload ─────────────────────────────────────────────────────────────

/**
 * Read EXIF orientation from a JPEG without needing the PHP exif extension.
 * Returns 1 (no rotation) when orientation cannot be determined.
 */
function jpeg_orientation(string $path): int {
    $fh = @fopen($path, 'rb');
    if (!$fh) return 1;
    try {
        if (fread($fh, 2) !== "\xFF\xD8") return 1; // not a JPEG
        while (!feof($fh)) {
            $marker = fread($fh, 2);
            if (strlen($marker) < 2 || $marker[0] !== "\xFF") return 1;
            $seg_len = unpack('n', fread($fh, 2))[1];
            if ($seg_len < 2) return 1;
            $data = fread($fh, $seg_len - 2);
            // APP1 with Exif header
            if ($marker === "\xFF\xE1" && str_starts_with($data, "Exif\x00\x00")) {
                $tiff = substr($data, 6);
                $le   = substr($tiff, 0, 2) === 'II'; // little-endian?
                $u16  = fn($s) => $le ? unpack('v', $s)[1] : unpack('n', $s)[1];
                $u32  = fn($s) => $le ? unpack('V', $s)[1] : unpack('N', $s)[1];
                $ifd0 = $u32(substr($tiff, 4, 4));
                $n    = $u16(substr($tiff, $ifd0, 2));
                for ($i = 0; $i < $n; $i++) {
                    $e = substr($tiff, $ifd0 + 2 + $i * 12, 12);
                    if ($u16(substr($e, 0, 2)) === 0x0112) { // Orientation tag
                        return (int) $u16(substr($e, 8, 2));
                    }
                }
                return 1;
            }
        }
    } finally {
        fclose($fh);
    }
    return 1;
}

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

    // Dimension guard (anti decompression-bomb): reject by resolution BEFORE
    // handing the file to GD. A few-MB image can decode to a huge pixel matrix
    // (e.g. 20000x20000 ≈ 1.6 GB) and exhaust memory. A miniatures catalog never
    // needs more than these limits.
    $max_width  = 8000;   // px
    $max_height = 8000;   // px
    $max_pixels = 50_000_000; // 50 megapixels
    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false) {
        return null; // unreadable / not a real raster image
    }
    [$img_w, $img_h] = $dimensions;
    if ($img_w < 1 || $img_h < 1
        || $img_w > $max_width || $img_h > $max_height
        || ($img_w * $img_h) > $max_pixels) {
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

    // Fix EXIF orientation (JPEGs from phones embed rotation metadata that GD ignores).
    // Uses exif_read_data() if the extension is available, otherwise parses JPEG bytes directly.
    // Some GD builds (or iOS uploads) already apply the rotation — we detect this by checking
    // whether the loaded image dimensions match what we'd expect for the orientation tag.
    if ($mime === 'image/jpeg') {
        $has_exif_ext = function_exists('exif_read_data');
        $orientation  = $has_exif_ext
            ? (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1)
            : jpeg_orientation($file['tmp_name']);

        // Orientation 6 or 8 means the raw sensor data is rotated 90°.
        // If GD already corrected it, imagesx/imagesy will reflect the rotated (portrait) dimensions.
        // We only rotate when the image dimensions look un-corrected (wider than tall for 6/8).
        $w = imagesx($image);
        $h = imagesy($image);
        $needs_rotate = match ($orientation) {
            6, 8 => $w > $h,   // Should be portrait but loaded as landscape → needs rotation
            3    => true,      // 180° flip — always apply (no dimension hint)
            default => false,
        };

        if ($needs_rotate) {
            $angle = match ($orientation) {
                3 => 180,
                6 => 270,
                8 => 90,
            };
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }
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

// ─── Condition / Location labels & badges ───────────────────────────────────

function condition_label(string $c): string {
    return match ($c) {
        'sealed' => 'Lacrada',
        'open'   => 'Aberta',
        'no_box' => 'Sem caixa',
        default  => ucfirst($c),
    };
}

function condition_badge(string $c): string {
    $class = match ($c) {
        'sealed' => 'primary',
        'open'   => 'success',
        'no_box' => 'warning',
        default  => 'secondary',
    };
    return '<span class="badge bg-' . $class . '">' . h(condition_label($c)) . '</span>';
}

function location_label(string $l): string {
    return match ($l) {
        'display' => 'Em exposição',
        'storage' => 'Em armazenamento',
        default   => ucfirst($l),
    };
}

/** Shorter location label for compact public UI (e.g. spec cards). */
function location_label_short(string $l): string {
    return match ($l) {
        'display' => 'Exposta',
        'storage' => 'Armazenada',
        default   => location_label($l),
    };
}

function location_badge(string $l): string {
    $class = match ($l) {
        'display' => 'info',
        'storage' => 'secondary',
        default   => 'secondary',
    };
    return '<span class="badge bg-' . $class . '">' . h(location_label($l)) . '</span>';
}

/** Renders both condition and location badges for a miniature row. */
function mini_status_badges(array $mini): string {
    return condition_badge($mini['condition'] ?? 'sealed') . ' ' . location_badge($mini['location'] ?? 'storage');
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

/**
 * Human-friendly relative time in pt-BR (e.g. "há 3 horas").
 * Shared helper used by notifications and the community feed.
 */
function time_ago(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 0)    $diff = 0;
    if ($diff < 60)   return 'agora mesmo';
    $mins = (int) floor($diff / 60);
    if ($mins < 60)   return 'há ' . $mins . ($mins === 1 ? ' minuto' : ' minutos');
    $hours = (int) floor($diff / 3600);
    if ($hours < 24)  return 'há ' . $hours . ($hours === 1 ? ' hora' : ' horas');
    $days = (int) floor($diff / 86400);
    if ($days < 7)    return 'há ' . $days . ($days === 1 ? ' dia' : ' dias');
    if ($days < 30)   { $w = (int) floor($days / 7); return 'há ' . $w . ($w === 1 ? ' semana' : ' semanas'); }
    if ($days < 365)  { $mo = (int) floor($days / 30); return 'há ' . $mo . ($mo === 1 ? ' mês' : ' meses'); }
    $y = (int) floor($days / 365);
    return 'há ' . $y . ($y === 1 ? ' ano' : ' anos');
}

// ─── Recent miniatures ───────────────────────────────────────────────────────

function get_recent_miniatures(int $limit = 5): array {    $stmt = db()->prepare(
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

// ─── Community feed (social module — Phase 8, dynamic UNION ALL, no extra table) ─

/**
 * Public community activity feed.
 *
 * Built dynamically from existing tables (no feed_events table, no write hooks).
 * Keyset pagination by created_at: pass the created_at of the last item as
 * $before to fetch the next, older page. Each source is capped before the merge
 * to keep filesorts tiny. Returns display-ready rows (no N+1).
 *
 * Event types: new_miniature, comment, reply, follow. (Likes intentionally
 * excluded in V1 to keep the wall focused.)
 *
 * Audience:
 *   $viewerUserId === null → global feed (everyone) — used for logged-out visitors.
 *   $viewerUserId !== null → only activity from collectors the viewer follows
 *                            (actor must be in user_follows.following_id). The
 *                            viewer's own activity is NOT injected automatically.
 *
 * Safety: only public miniatures, only non-banned actors (and non-banned target
 * on follows); no private columns are ever selected.
 */
function get_community_feed(int $limit = 20, ?string $before = null, ?int $viewerUserId = null): array {
    $limit = max(1, min(50, $limit));
    $cap   = $limit; // integer, safe to inline into LIMIT

    $hasBefore = $before !== null && trim($before) !== '';
    $cond_m = $hasBefore ? ' AND m.created_at < ?' : '';
    $cond_c = $hasBefore ? ' AND c.created_at < ?' : '';
    $cond_f = $hasBefore ? ' AND f.created_at < ?' : '';

    // Restrict to followed collectors when a viewer is given. The actor of each
    // event (miniature owner / commenter / follower) must be someone the viewer
    // follows. Uses a positional placeholder per subquery.
    $followsOnly = $viewerUserId !== null && $viewerUserId > 0;
    $foll_m = $followsOnly ? ' AND m.user_id IN (SELECT following_id FROM user_follows WHERE follower_id = ?)' : '';
    $foll_c = $followsOnly ? ' AND c.user_id IN (SELECT following_id FROM user_follows WHERE follower_id = ?)' : '';
    $foll_f = $followsOnly ? ' AND f.follower_id IN (SELECT following_id FROM user_follows WHERE follower_id = ?)' : '';

    $sql = "SELECT * FROM (
        ( SELECT 'new_miniature' AS type, m.user_id AS actor_id, m.created_at AS created_at,
                 m.id AS miniature_id, NULL AS comment_id, NULL AS target_user_id,
                 a.display_name AS actor_display_name, a.username AS actor_username,
                 a.slug AS actor_slug, a.avatar AS actor_avatar,
                 m.name AS miniature_name, m.manufacturer AS miniature_manufacturer, p.file_path AS miniature_photo,
                 NULL AS target_display_name, NULL AS target_username, NULL AS target_slug, NULL AS target_avatar
          FROM miniatures m
          JOIN admin_users a ON a.id = m.user_id AND a.is_banned = 0
          LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
          WHERE m.is_public = 1{$cond_m}{$foll_m}
          ORDER BY m.created_at DESC LIMIT {$cap} )
        UNION ALL
        ( SELECT 'comment', c.user_id, c.created_at, c.miniature_id, c.id, NULL,
                 a.display_name, a.username, a.slug, a.avatar,
                 m.name, m.manufacturer, p.file_path,
                 NULL, NULL, NULL, NULL
          FROM miniature_comments c
          JOIN miniatures m ON m.id = c.miniature_id AND m.is_public = 1
          JOIN admin_users a ON a.id = c.user_id AND a.is_banned = 0
          LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
          WHERE c.parent_id IS NULL{$cond_c}{$foll_c}
          ORDER BY c.created_at DESC LIMIT {$cap} )
        UNION ALL
        ( SELECT 'reply', c.user_id, c.created_at, c.miniature_id, c.id, NULL,
                 a.display_name, a.username, a.slug, a.avatar,
                 m.name, m.manufacturer, p.file_path,
                 NULL, NULL, NULL, NULL
          FROM miniature_comments c
          JOIN miniatures m ON m.id = c.miniature_id AND m.is_public = 1
          JOIN admin_users a ON a.id = c.user_id AND a.is_banned = 0
          LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
          WHERE c.parent_id IS NOT NULL{$cond_c}{$foll_c}
          ORDER BY c.created_at DESC LIMIT {$cap} )
        UNION ALL
        ( SELECT 'follow', f.follower_id, f.created_at, NULL, NULL, f.following_id,
                 a.display_name, a.username, a.slug, a.avatar,
                 NULL, NULL, NULL,
                 t.display_name, t.username, t.slug, t.avatar
          FROM user_follows f
          JOIN admin_users a ON a.id = f.follower_id AND a.is_banned = 0
          JOIN admin_users t ON t.id = f.following_id AND t.is_banned = 0
          WHERE 1 = 1{$cond_f}{$foll_f}
          ORDER BY f.created_at DESC LIMIT {$cap} )
    ) feed
    ORDER BY created_at DESC
    LIMIT {$cap}";

    $params = [];
    // Placeholders are bound per subquery in textual order: [before?, viewer?]
    // for each of the 4 subqueries (miniature, comment, reply, follow).
    for ($i = 0; $i < 4; $i++) {
        if ($hasBefore)   $params[] = $before;
        if ($followsOnly) $params[] = $viewerUserId;
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return []; // graceful: never fatal a public page (e.g. legacy DB w/o avatar)
    }
}


const COMMENT_MAX_LENGTH = 1000;

/**
 * Returns root comments for a miniature, each with a `replies` array of direct
 * answers (single-level thread). Pinned roots come first, then the rest; within
 * each group the most recent comes first. Replies stay in chronological order.
 * Orphaned replies (whose root was deleted) are promoted to roots.
 */
function get_miniature_comments(int $miniature_id): array {
    try {
        $stmt = db()->prepare(
            'SELECT c.id, c.miniature_id, c.user_id, c.parent_id, c.body, c.is_pinned, c.created_at,
                    u.display_name, u.username, u.slug, u.avatar
             FROM miniature_comments c
             JOIN admin_users u ON u.id = c.user_id
             WHERE c.miniature_id = ?
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute([$miniature_id]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return []; // table/column may not exist before migration
    }

    $roots    = [];
    $children = [];
    foreach ($rows as $r) {
        if (empty($r['parent_id'])) {
            $r['replies'] = [];
            $roots[(int) $r['id']] = $r;
        } else {
            $children[(int) $r['parent_id']][] = $r;
        }
    }
    foreach ($children as $pid => $kids) {
        if (isset($roots[$pid])) {
            $roots[$pid]['replies'] = $kids;
        }
        // Orphaned replies (parent missing) are dropped, never promoted to roots.
    }

    $list = array_values($roots);
    usort($list, function (array $a, array $b): int {
        $pa = (int) ($a['is_pinned'] ?? 0);
        $pb = (int) ($b['is_pinned'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;                  // pinned first
        $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']); // newest first
        return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
    });
    return $list;
}

/** Fetches a single comment row, or null. */
function get_miniature_comment(int $comment_id): ?array {
    try {
        $stmt = db()->prepare('SELECT * FROM miniature_comments WHERE id = ? LIMIT 1');
        $stmt->execute([$comment_id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Creates a comment or a reply. Validates: logged-in user, non-empty after trim,
 * max length enforced in the application. No HTML is stored (raw text only).
 *
 * Threads are single-level: if $parent_id points to a reply, the new comment is
 * re-attached to that reply's root, never nested deeper.
 *
 * Returns true on success, false on validation/DB failure.
 */
function create_miniature_comment(int $miniature_id, int $user_id, string $body, ?int $parent_id = null): bool {
    if ($user_id <= 0) return false;
    $body = trim($body);
    if ($body === '') return false;
    if (mb_strlen($body) > COMMENT_MAX_LENGTH) return false;

    $root_parent = null;
    if ($parent_id !== null && $parent_id > 0) {
        $parent = get_miniature_comment($parent_id);
        // Parent must exist and belong to the same miniature.
        if (!$parent || (int) $parent['miniature_id'] !== $miniature_id) return false;
        // Enforce single-level threads: attach to the root, never to a reply.
        $root_parent = !empty($parent['parent_id']) ? (int) $parent['parent_id'] : (int) $parent['id'];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO miniature_comments (miniature_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)'
        );
        $ok = $stmt->execute([$miniature_id, $user_id, $body, $root_parent]);
        if (!$ok) return false;
        $comment_id = (int) db()->lastInsertId();
        // Best-effort: generate notifications. Never let this block the comment.
        try {
            create_comment_notifications($comment_id, $miniature_id, $user_id, $root_parent, $body);
        } catch (Throwable $e) { /* ignore notification failures */ }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Permission check for deleting a comment:
 * - the comment author
 * - the owner of the miniature
 * - a superadmin
 */
function can_delete_miniature_comment(array $comment, array $miniature): bool {
    $uid = current_user_id();
    if ($uid <= 0) return false;
    if ((int) $comment['user_id'] === $uid) return true;
    if ((int) ($miniature['user_id'] ?? 0) === $uid) return true;
    return is_superadmin();
}

/**
 * Deletes a comment if $deleted_by is allowed to.
 * - Deleting a root comment also deletes all of its replies (atomically).
 * - Deleting a reply removes only that reply.
 * Returns true on success, false if not found or not permitted.
 */
function delete_miniature_comment(int $comment_id, int $deleted_by): bool {
    if ($deleted_by <= 0) return false;
    $comment = get_miniature_comment($comment_id);
    if (!$comment) return false;
    $miniature = get_miniature((int) $comment['miniature_id']);
    if (!$miniature) return false;
    if (!can_delete_miniature_comment($comment, $miniature)) return false;

    $is_root = empty($comment['parent_id']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($is_root) {
            // Remove all replies first, then the root comment.
            $pdo->prepare('DELETE FROM miniature_comments WHERE parent_id = ?')->execute([$comment_id]);
        }
        $pdo->prepare('DELETE FROM miniature_comments WHERE id = ?')->execute([$comment_id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

/**
 * Permission check for pinning (highlighting) a comment:
 * - only the owner of the miniature or a superadmin
 * - only root comments can be pinned (never replies)
 */
function can_pin_miniature_comment(array $comment, array $miniature): bool {
    $uid = current_user_id();
    if ($uid <= 0) return false;
    if (!empty($comment['parent_id'])) return false; // replies are never pinnable
    if ((int) ($miniature['user_id'] ?? 0) === $uid) return true;
    return is_superadmin();
}

/**
 * Toggles the pinned flag of a root comment if $user_id is allowed to.
 * Replies cannot be pinned. Returns true on success, false otherwise.
 */
function toggle_miniature_comment_pin(int $comment_id, int $user_id): bool {
    if ($user_id <= 0) return false;
    $comment = get_miniature_comment($comment_id);
    if (!$comment) return false;
    if (!empty($comment['parent_id'])) return false; // never pin a reply
    $miniature = get_miniature((int) $comment['miniature_id']);
    if (!$miniature) return false;
    if (!can_pin_miniature_comment($comment, $miniature)) return false;
    try {
        $new = empty($comment['is_pinned']) ? 1 : 0;
        return db()->prepare('UPDATE miniature_comments SET is_pinned = ? WHERE id = ?')
                    ->execute([$new, $comment_id]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Renders a raw comment body as safe HTML, turning @slug mentions of existing,
 * non-banned users into links to their public profile (/u/{slug}).
 *
 * Security: the whole body is HTML-escaped first (no user HTML is ever trusted);
 * mention slugs are validated against the database (whitelist) and contain only
 * [a-z0-9_-], so they are safe in both the href and the link text.
 *
 * Performance: all candidate slugs are resolved in a single `WHERE slug IN (...)`
 * query — the replace callback only reads an in-memory map (no N+1).
 */
function render_comment_body_with_mentions(string $body): string {
    $pattern = '/(?<![\w@])@([a-z0-9_-]+)/i';

    // 1. Collect unique candidate slugs (lowercased) from the raw text.
    $valid = [];
    if (preg_match_all($pattern, $body, $m) && !empty($m[1])) {
        $candidates = array_values(array_unique(array_map('strtolower', $m[1])));
        try {
            $in   = implode(',', array_fill(0, count($candidates), '?'));
            $stmt = db()->prepare("SELECT slug FROM admin_users WHERE is_banned = 0 AND slug IN ($in)");
            $stmt->execute($candidates);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $s) {
                $valid[strtolower((string) $s)] = true;
            }
        } catch (Throwable $e) {
            $valid = [];
        }
    }

    // 2. Escape the entire body first — neutralises any HTML the user typed.
    $html = e($body);

    // 3. Convert only validated mentions into links (slug chars are escape-safe).
    if ($valid) {
        $html = preg_replace_callback(
            $pattern,
            function (array $mm) use ($valid): string {
                $raw  = $mm[1];
                $slug = strtolower($raw);
                if (empty($valid[$slug])) {
                    return '@' . $raw; // unknown slug — keep as plain text
                }
                return '<a href="/u/' . $slug . '" class="cm-mention">@' . $raw . '</a>';
            },
            $html
        );
    }

    // 4. Preserve line breaks.
    return nl2br($html);
}

// ─── Notifications (social module — Phase 5A) ────────────────────────────────

/**
 * Returns the distinct, existing, non-banned users mentioned (@slug) in a body.
 * Single `WHERE slug IN (...)` query — no N+1. Each row has id + slug.
 */
function get_mentioned_users_from_comment_body(string $body): array {
    if (!preg_match_all('/(?<![\w@])@([a-z0-9_-]+)/i', $body, $m) || empty($m[1])) {
        return [];
    }
    $candidates = array_values(array_unique(array_map('strtolower', $m[1])));
    try {
        $in   = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = db()->prepare("SELECT id, slug FROM admin_users WHERE is_banned = 0 AND slug IN ($in)");
        $stmt->execute($candidates);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Inserts a single notification.
 * - never notifies the actor themselves
 * - skips duplicates of the same type for the same comment + recipient
 * - 'like' / 'follow' carry no comment_id: deduped by recipient + actor + type
 * - $miniature_id is NULL for notifications not tied to a miniature (e.g. follow)
 * - $target_user_id references the subject user of a non-miniature notification
 * Returns true on insert, false otherwise.
 */
function create_notification(
    int $user_id,
    int $actor_user_id,
    string $type,
    ?int $miniature_id,
    ?int $comment_id,
    string $target_url,
    ?int $target_user_id = null
): bool {
    if ($user_id <= 0 || $actor_user_id <= 0) return false;
    if ($user_id === $actor_user_id) return false; // no self-notification
    if (!in_array($type, ['comment', 'reply', 'mention', 'like', 'follow'], true)) return false;
    try {
        // Avoid duplicate notifications of the same type for the same comment.
        if ($comment_id !== null) {
            $chk = db()->prepare(
                'SELECT 1 FROM notifications WHERE user_id = ? AND type = ? AND comment_id = ? LIMIT 1'
            );
            $chk->execute([$user_id, $type, $comment_id]);
            if ($chk->fetchColumn()) return false;
        } elseif ($type === 'like') {
            // Likes carry no comment_id: dedupe by recipient + actor + miniature,
            // so unlike→like cycles never create a second notification.
            $chk = db()->prepare(
                'SELECT 1 FROM notifications WHERE user_id = ? AND actor_user_id = ? AND type = ? AND miniature_id = ? LIMIT 1'
            );
            $chk->execute([$user_id, $actor_user_id, $type, $miniature_id]);
            if ($chk->fetchColumn()) return false;
        } elseif ($type === 'follow') {
            // Follows carry no miniature/comment: dedupe by recipient + actor + type,
            // so unfollow→follow cycles never create a second notification.
            $chk = db()->prepare(
                'SELECT 1 FROM notifications WHERE user_id = ? AND actor_user_id = ? AND type = ? LIMIT 1'
            );
            $chk->execute([$user_id, $actor_user_id, $type]);
            if ($chk->fetchColumn()) return false;
        }
        $stmt = db()->prepare(
            'INSERT INTO notifications (user_id, actor_user_id, type, miniature_id, target_user_id, comment_id, target_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        return $stmt->execute([$user_id, $actor_user_id, $type, $miniature_id, $target_user_id, $comment_id, $target_url]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Generates the notifications triggered by a newly created comment/reply:
 * - root comment  → 'comment' to the miniature owner
 * - reply         → 'reply' to the author of the root comment
 * - @slug mentions→ 'mention' to each mentioned user
 * The actor is never notified (enforced in create_notification).
 */
function create_comment_notifications(
    int $comment_id,
    int $miniature_id,
    int $actor_user_id,
    ?int $parent_id,
    string $body
): void {
    $miniature = get_miniature($miniature_id);
    if (!$miniature) return;

    $target_url = mini_url($miniature) . '#comment-' . $comment_id;

    if ($parent_id === null || $parent_id <= 0) {
        // Root comment → notify the miniature owner.
        create_notification(
            (int) $miniature['user_id'], $actor_user_id, 'comment',
            $miniature_id, $comment_id, $target_url
        );
    } else {
        // Reply → notify the author of the root comment.
        $root = get_miniature_comment($parent_id);
        if ($root) {
            create_notification(
                (int) $root['user_id'], $actor_user_id, 'reply',
                $miniature_id, $comment_id, $target_url
            );
        }
    }

    // Mentions → notify each mentioned, existing user (separate from comment/reply).
    foreach (get_mentioned_users_from_comment_body($body) as $u) {
        create_notification(
            (int) $u['id'], $actor_user_id, 'mention',
            $miniature_id, $comment_id, $target_url
        );
    }
}

/** Number of unread notifications for a user. */
function get_unread_notifications_count(int $user_id): int {
    if ($user_id <= 0) return 0;
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Returns a user's notifications (unread first, then newest first) with the
 * actor's display info and the miniature name. Single query — no N+1.
 */
function get_user_notifications(int $user_id, int $limit = 50): array {
    if ($user_id <= 0) return [];
    $limit = max(1, min(100, $limit));
    try {
        $stmt = db()->prepare(
            'SELECT n.id, n.user_id, n.actor_user_id, n.type, n.miniature_id, n.target_user_id, n.comment_id,
                    n.target_url, n.is_read, n.created_at,
                    a.display_name AS actor_name, a.username AS actor_username, a.slug AS actor_slug,
                    a.avatar AS actor_avatar,
                    m.name AS miniature_name
             FROM notifications n
             JOIN admin_users a ON a.id = n.actor_user_id
             LEFT JOIN miniatures m ON m.id = n.miniature_id
             WHERE n.user_id = ?
             ORDER BY n.is_read ASC, n.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Marks a single notification as read, scoped to its owner. */
function mark_notification_read(int $notification_id, int $user_id): bool {
    if ($notification_id <= 0 || $user_id <= 0) return false;
    try {
        return db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
        )->execute([$notification_id, $user_id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Marks all of a user's unread notifications as read. */
function mark_all_notifications_read(int $user_id): bool {
    if ($user_id <= 0) return false;
    try {
        return db()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        )->execute([$user_id]);
    } catch (Throwable $e) {
        return false;
    }
}
