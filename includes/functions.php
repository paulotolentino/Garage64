<?php
require_once __DIR__ . '/db.php';

// ─── Sanitization ────────────────────────────────────────────────────────────

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e(?string $value): string {
    return h($value ?? '');
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

function get_miniatures(array $filters = []): array {
    $where = ['1=1'];
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
        $where[] = '(m.name LIKE ? OR m.manufacturer LIKE ? OR m.model LIKE ?)';
        $s = '%' . $filters['search'] . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    if (!empty($filters['tag_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM miniature_tags mt WHERE mt.miniature_id = m.id AND mt.tag_id = ?)';
        $params[] = $filters['tag_id'];
    }

    $sql = 'SELECT m.*, c.name AS category_name,
                   p.file_path AS primary_photo
            FROM miniatures m
            LEFT JOIN categories c ON m.category_id = c.id
            LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.created_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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

    return compact('total', 'by_scale', 'by_manufacturer', 'by_category', 'by_status');
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

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };

    $dir = UPLOADS_DIR . $miniature_id . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    @chmod($dest, 0644);

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

function photo_url(?string $file_path): string {
    if (!$file_path) {
        return '/assets/img/no-photo.svg';
    }
    // Guard against path traversal: only allow id/hexname.ext patterns.
    if (!preg_match('#^\d+/[a-f0-9]+\.(jpg|jpeg|png|webp|gif)$#i', $file_path)) {
        return '/assets/img/no-photo.svg';
    }
    if (!is_file(UPLOADS_DIR . $file_path)) {
        return '/assets/img/no-photo.svg';
    }
    return '/uploads/' . $file_path;
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
