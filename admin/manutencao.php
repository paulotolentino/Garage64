<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_superadmin();

// ─── AJAX BATCH ENDPOINT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch') {
    verify_csrf();
    header('Content-Type: application/json');

    set_time_limit(60);

    $limit = min(10, max(1, (int) ($_POST['limit'] ?? 5)));

    // Always fetch from top of the non-webp queue (no offset needed — converted rows drop out)
    $stmt = db()->prepare(
        "SELECT id, file_path FROM miniature_photos
         WHERE file_path NOT LIKE '%.webp'
         ORDER BY id ASC
         LIMIT ?"
    );
    $stmt->execute([$limit]);
    $photos = $stmt->fetchAll();

    $converted = 0;
    $skipped   = 0;
    $errors    = [];

    foreach ($photos as $photo) {
        $src_path = UPLOADS_DIR . $photo['file_path'];

        // File missing on disk — update DB path so it drops out of the queue
        if (!file_exists($src_path)) {
            $new_path = preg_replace('/\.[^.]+$/', '.webp', $photo['file_path']);
            db()->prepare('UPDATE miniature_photos SET file_path = ? WHERE id = ?')
                 ->execute([$new_path, $photo['id']]);
            $skipped++;
            continue;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($src_path);

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($src_path),
            'image/png'  => imagecreatefrompng($src_path),
            'image/gif'  => imagecreatefromgif($src_path),
            default      => null,
        };

        if (!$image) {
            $errors[] = basename($photo['file_path']);
            continue;
        }

        // Preserve alpha channel for PNG/GIF sources
        if (in_array($mime, ['image/png', 'image/gif'], true)) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $new_file_path = preg_replace('/\.[^.]+$/', '.webp', $photo['file_path']);
        $dest_path     = UPLOADS_DIR . $new_file_path;

        $ok = imagewebp($image, $dest_path, WEBP_QUALITY);
        imagedestroy($image);

        if (!$ok) {
            $errors[] = basename($photo['file_path']);
            continue;
        }

        @chmod($dest_path, 0644);

        db()->prepare('UPDATE miniature_photos SET file_path = ? WHERE id = ?')
             ->execute([$new_file_path, $photo['id']]);

        @unlink($src_path);
        $converted++;
    }

    $remaining = (int) db()->query(
        "SELECT COUNT(*) FROM miniature_photos WHERE file_path NOT LIKE '%.webp'"
    )->fetchColumn();

    echo json_encode([
        'converted' => $converted,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'remaining' => $remaining,
        'done'      => $remaining === 0,
    ]);
    exit;
}

// ─── AJAX: APPLY DB INDEXES ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_indexes') {
    verify_csrf();
    header('Content-Type: application/json');

    // Result accumulators — initialized once, before any migration block runs.
    $applied = [];
    $errors  = [];
    $skipped = [];

    // MySQL doesn't support CREATE INDEX IF NOT EXISTS — check information_schema first
    $indexes = [
        'idx_photos_miniature_primary' => ['miniature_photos', 'CREATE INDEX idx_photos_miniature_primary ON miniature_photos (miniature_id, is_primary)'],
        'idx_miniatures_created'       => ['miniatures',       'CREATE INDEX idx_miniatures_created ON miniatures (created_at)'],
        'idx_miniatures_condition'     => ['miniatures',       'CREATE INDEX idx_miniatures_condition ON miniatures (`condition`)'],
        'idx_miniatures_location'      => ['miniatures',       'CREATE INDEX idx_miniatures_location ON miniatures (location)'],
        'idx_miniatures_manufacturer'  => ['miniatures',       'CREATE INDEX idx_miniatures_manufacturer ON miniatures (manufacturer)'],
        'idx_miniatures_scale'         => ['miniatures',       'CREATE INDEX idx_miniatures_scale ON miniatures (scale)'],
        'ft_miniatures_search'         => ['miniatures',       'ALTER TABLE miniatures ADD FULLTEXT INDEX ft_miniatures_search (name, manufacturer, model)'],
        'idx_comments_parent'          => ['miniature_comments', 'CREATE INDEX idx_comments_parent ON miniature_comments (parent_id)'],
        'idx_comments_pinned'          => ['miniature_comments', 'CREATE INDEX idx_comments_pinned ON miniature_comments (miniature_id, is_pinned, created_at)'],
    ];

    // Schema migrations (column additions) — ['table', 'column', 'sql']
    $columns = [
        'is_public'        => ['miniatures',  'is_public',      "ALTER TABLE miniatures ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER emotional_rating"],
        'views'            => ['miniatures',  'views',          "ALTER TABLE miniatures ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_public"],
        'is_featured'      => ['miniatures',  'is_featured',    "ALTER TABLE miniatures ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public"],
        'sort_order'       => ['miniatures',  'sort_order',     "ALTER TABLE miniatures ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 9999 AFTER is_featured"],
        'condition'        => ['miniatures',  'condition',      "ALTER TABLE miniatures ADD COLUMN `condition` ENUM('sealed','open','no_box') NOT NULL DEFAULT 'sealed' AFTER category_id"],
        'location'         => ['miniatures',  'location',       "ALTER TABLE miniatures ADD COLUMN location ENUM('display','storage') NOT NULL DEFAULT 'storage' AFTER `condition`"],
        'mini_user_id'     => ['miniatures',  'user_id',        "ALTER TABLE miniatures ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1"],
        'cat_user_id'      => ['categories',  'user_id',        "ALTER TABLE categories ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1"],
        'tag_user_id'      => ['tags',        'user_id',        "ALTER TABLE tags ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1"],
        'wish_user_id'     => ['wishlist',    'user_id',        "ALTER TABLE wishlist ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1"],
        'au_slug'          => ['admin_users', 'slug',           "ALTER TABLE admin_users ADD COLUMN slug VARCHAR(50) NOT NULL DEFAULT ''"],
        'au_display_name'  => ['admin_users', 'display_name',   "ALTER TABLE admin_users ADD COLUMN display_name VARCHAR(100) NOT NULL DEFAULT ''"],
        'au_email'         => ['admin_users', 'email',          "ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT ''"],
        'au_is_banned'     => ['admin_users', 'is_banned',      "ALTER TABLE admin_users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0"],
        'au_is_superadmin' => ['admin_users', 'is_superadmin',  "ALTER TABLE admin_users ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0"],
        'au_bio'           => ['admin_users', 'bio',            "ALTER TABLE admin_users ADD COLUMN bio TEXT DEFAULT NULL"],
        'au_avatar'        => ['admin_users', 'avatar',         "ALTER TABLE admin_users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL"],
        'au_is_featured'   => ['admin_users', 'is_featured',    "ALTER TABLE admin_users ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0"],
        'cmt_parent_id'    => ['miniature_comments', 'parent_id', "ALTER TABLE miniature_comments ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER user_id"],
        'cmt_is_pinned'    => ['miniature_comments', 'is_pinned', "ALTER TABLE miniature_comments ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER body"],
    ];

    // Table migrations — checked via information_schema.tables
    $tables = [
        'miniature_ratings' => "CREATE TABLE miniature_ratings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            miniature_id INT UNSIGNED NOT NULL,
            ip_hash VARCHAR(64) NOT NULL,
            rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mini_ip (miniature_id, ip_hash),
            FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'miniature_comments' => "CREATE TABLE miniature_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            miniature_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comments_mini_created (miniature_id, created_at),
            KEY idx_comments_user (user_id),
            FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'notifications' => "CREATE TABLE notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NOT NULL,
            type ENUM('comment','reply','mention') NOT NULL,
            miniature_id INT UNSIGNED NOT NULL,
            comment_id INT UNSIGNED NULL,
            target_url VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notif_user_unread (user_id, is_read, created_at),
            KEY idx_notif_miniature (miniature_id),
            KEY idx_notif_comment (comment_id),
            FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY (miniature_id) REFERENCES miniatures(id) ON DELETE CASCADE,
            FOREIGN KEY (comment_id) REFERENCES miniature_comments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    $check_table = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    foreach ($tables as $tbl => $sql) {
        try {
            $check_table->execute([$tbl]);
            if ((int) $check_table->fetchColumn() > 0) { $skipped[] = 'table:' . $tbl; continue; }
            db()->exec($sql);
            $applied[] = 'table:' . $tbl;
        } catch (Throwable $e) {
            $errors[] = 'table:' . $tbl . ': ' . $e->getMessage();
        }
    }

    // ENUM expansions — always run MODIFY to ensure the ENUM includes all values
    $enum_mods = [
        'condition_no_box' => "ALTER TABLE miniatures MODIFY COLUMN `condition` ENUM('sealed','open','no_box') NOT NULL DEFAULT 'sealed'",
    ];
    $check_col_type = db()->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'miniatures' AND column_name = ?"
    );
    foreach ($enum_mods as $key => $sql) {
        try {
            $check_col_type->execute(['condition']);
            $col_type = $check_col_type->fetchColumn();
            if ($col_type && str_contains((string)$col_type, 'no_box')) {
                $skipped[] = 'enum:' . $key;
                continue;
            }
            if (!$col_type) { $skipped[] = 'enum:' . $key . ' (col missing)'; continue; }
            db()->exec($sql);
            $applied[] = 'enum:' . $key;
        } catch (Throwable $e) {
            $errors[] = 'enum:' . $key . ': ' . $e->getMessage();
        }
    }

    $check = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
    );
    $check_col = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );

    foreach ($columns as $key => [$tbl, $col_name, $sql]) {
        try {
            $check_col->execute([$tbl, $col_name]);
            if ((int) $check_col->fetchColumn() > 0) {
                $skipped[] = 'col:' . $key;
                continue;
            }
            db()->exec($sql);
            $applied[] = 'col:' . $key;
        } catch (Throwable $e) {
            $errors[] = 'col:' . $key . ': ' . $e->getMessage();
        }
    }

    // Post-column data fixes
    // 1. Set slug from username for existing users that have no slug yet
    try {
        db()->exec("UPDATE admin_users SET slug = username WHERE slug = '' OR slug IS NULL");
        $applied[] = 'data:admin_users.slug';
    } catch (Throwable $e) { $skipped[] = 'data:admin_users.slug'; }
    // 2. First user becomes superadmin
    try {
        db()->exec("UPDATE admin_users SET is_superadmin = 1 WHERE id = (SELECT MIN(id) FROM (SELECT id FROM admin_users) t)");
        $applied[] = 'data:superadmin';
    } catch (Throwable $e) { $skipped[] = 'data:superadmin'; }

    foreach ($indexes as $name => [$table, $sql]) {
        try {
            $check->execute([$table, $name]);
            if ((int) $check->fetchColumn() > 0) {
                $skipped[] = $name;
                continue;
            }
            db()->exec($sql);
            $applied[] = $name;
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
    }

    // Data migration: status → condition + location, then drop status column
    $chk_status = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'miniatures' AND column_name = 'status'"
    );
    $chk_cond = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'miniatures' AND column_name = 'condition'"
    );
    $chk_status->execute(); $has_status = (int) $chk_status->fetchColumn();
    $chk_cond->execute();   $has_cond   = (int) $chk_cond->fetchColumn();

    if ($has_status && $has_cond) {
        try {
            db()->exec("UPDATE miniatures SET `condition` = IF(status = 'open', 'open', 'sealed'), location = 'storage'");
            $applied[] = 'data:status→condition+location';
        } catch (Throwable $e) {
            $errors[] = 'data:migrate_status: ' . $e->getMessage();
        }
        try {
            db()->exec("ALTER TABLE miniatures DROP COLUMN status");
            $applied[] = 'drop:status';
        } catch (Throwable $e) {
            $errors[] = 'drop:status: ' . $e->getMessage();
        }
    } elseif (!$has_status) {
        $skipped[] = 'data:migrate_status (status column already removed)';
    } else {
        $skipped[] = 'data:migrate_status (condition column not ready yet)';
    }

    echo json_encode(['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors]);
    exit;
}

// ─── AJAX: GENERATE THUMBNAILS ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_thumbs') {
    verify_csrf();
    header('Content-Type: application/json');

    set_time_limit(60);

    $limit = min(10, max(1, (int) ($_POST['limit'] ?? 5)));

    // Fetch ALL webp originals from DB, then filter by disk state.
    // This is the only reliable way to know remaining count without a schema change —
    // the DB has no record of whether a thumb file exists on disk.
    $all_photos = db()->query(
        "SELECT id, file_path FROM miniature_photos
         WHERE file_path LIKE '%.webp'
           AND file_path NOT LIKE '%_thumb.webp'
         ORDER BY id ASC"
    )->fetchAll();

    // Keep only those whose thumb is actually missing on disk
    $needs_thumb = array_values(array_filter($all_photos, function (array $p): bool {
        $thumb = UPLOADS_DIR . preg_replace('/\.webp$/i', '_thumb.webp', $p['file_path']);
        return !file_exists($thumb);
    }));

    $remaining_before = count($needs_thumb);
    $batch            = array_slice($needs_thumb, 0, $limit);

    $generated = 0;
    $errors    = [];

    foreach ($batch as $photo) {
        $src_path   = UPLOADS_DIR . $photo['file_path'];
        $thumb_file = preg_replace('/\.webp$/i', '_thumb.webp', $photo['file_path']);
        $thumb_path = UPLOADS_DIR . $thumb_file;

        if (!file_exists($src_path)) {
            continue;
        }

        $image = imagecreatefromwebp($src_path);
        if (!$image) {
            $errors[] = basename($photo['file_path']);
            continue;
        }

        $orig_w = imagesx($image);
        $orig_h = imagesy($image);

        if ($orig_w > THUMB_WIDTH) {
            $thumb_h = (int) round($orig_h * THUMB_WIDTH / $orig_w);
            $thumb   = imagecreatetruecolor(THUMB_WIDTH, $thumb_h);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, THUMB_WIDTH, $thumb_h, $orig_w, $orig_h);
            imagewebp($thumb, $thumb_path, WEBP_QUALITY);
            @chmod($thumb_path, 0644);
            imagedestroy($thumb);
        } else {
            copy($src_path, $thumb_path);
            @chmod($thumb_path, 0644);
        }

        imagedestroy($image);
        $generated++;
    }

    // Remaining = what was missing before minus what we successfully generated
    $remaining = max(0, $remaining_before - $generated);

    echo json_encode([
        'generated' => $generated,
        'errors'    => $errors,
        'remaining' => $remaining,
        'done'      => $remaining === 0,
    ]);
    exit;
}

// ─── AJAX: RESET OPCACHE ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_opcache') {
    verify_csrf();
    header('Content-Type: application/json');

    if (!function_exists('opcache_get_status')) {
        echo json_encode(['ok' => false, 'message' => 'OPcache não está disponível nesta hospedagem.']);
        exit;
    }
    if (!function_exists('opcache_reset')) {
        echo json_encode(['ok' => false, 'message' => 'opcache_reset() foi desabilitado pela hospedagem. Troque a versão do PHP no cPanel para limpar o cache.']);
        exit;
    }

    $before = @opcache_get_status(false);
    $cached_before = $before['opcache_statistics']['num_cached_scripts'] ?? 0;

    $ok = @opcache_reset();

    echo json_encode([
        'ok'      => (bool) $ok,
        'message' => $ok
            ? "OPcache limpo com sucesso ($cached_before script(s) recarregados na próxima execução)."
            : 'opcache_reset() retornou falso — a hospedagem pode ter restringido. Troque a versão do PHP no cPanel.',
    ]);
    exit;
}


$gd_info      = function_exists('gd_info') ? gd_info() : [];
$webp_support = !empty($gd_info['WebP Support']);

try {
    $total   = (int) db()->query('SELECT COUNT(*) FROM miniature_photos')->fetchColumn();
    $pending = (int) db()->query(
        "SELECT COUNT(*) FROM miniature_photos WHERE file_path NOT LIKE '%.webp'"
    )->fetchColumn();
    // pending_thumbs must reflect disk state — DB doesn't track thumb existence
    $webp_rows = db()->query(
        "SELECT file_path FROM miniature_photos WHERE file_path LIKE '%.webp' AND file_path NOT LIKE '%_thumb.webp'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $pending_thumbs = count(array_filter($webp_rows, function (string $fp): bool {
        return !file_exists(UPLOADS_DIR . preg_replace('/\.webp$/i', '_thumb.webp', $fp));
    }));
} catch (Throwable $e) {
    $total   = 0;
    $pending = 0;
    $pending_thumbs = 0;
    $db_error = $e->getMessage();
}

$opcache_available = function_exists('opcache_reset');

$page_title = 'Manutenção do sistema';
require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="maint-hero dash-hero">
    <div class="maint-hero-ico"><i class="fa fa-screwdriver-wrench"></i></div>
    <div class="maint-hero-text">
        <span class="lp-eyebrow">Painel técnico</span>
        <h1 class="maint-hero-title">Manutenção do sistema</h1>
        <p class="maint-hero-sub">Diagnóstico, otimização e migração. Algumas ações alteram o banco ou apagam arquivos — use com atenção.</p>
    </div>
</div>

<div class="maint-diag">
    <span class="maint-chip <?= empty($db_error) ? 'is-ok' : 'is-bad' ?>">
        <i class="fa <?= empty($db_error) ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>Banco de dados
    </span>
    <span class="maint-chip <?= $webp_support ? 'is-ok' : 'is-bad' ?>">
        <i class="fa <?= $webp_support ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>WebP (GD)
    </span>
    <span class="maint-chip <?= $opcache_available ? 'is-ok' : 'is-warn' ?>">
        <i class="fa <?= $opcache_available ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>OPcache
    </span>
</div>

<?php if (!empty($db_error)): ?>
<div class="maint-alert maint-alert-error">
    <i class="fa fa-circle-xmark"></i>
    <span><strong>Erro de banco de dados:</strong> <?= h($db_error) ?></span>
</div>
<?php elseif (!$webp_support): ?>
<div class="maint-alert maint-alert-error">
    <i class="fa fa-circle-xmark"></i>
    <span><strong>WebP não suportado.</strong> A extensão GD do PHP não foi compilada com suporte a WebP neste servidor. Entre em contato com seu provedor de hospedagem para habilitar o suporte.</span>
</div>
<?php endif; ?>

<?php if (empty($db_error) && $webp_support): ?>
<div class="maint-stats">
    <div class="maint-stat">
        <span class="maint-stat-num"><?= $total ?></span>
        <span class="maint-stat-lbl">Total de fotos</span>
    </div>
    <div class="maint-stat">
        <span class="maint-stat-num maint-amber" id="stat-pending"><?= $pending ?></span>
        <span class="maint-stat-lbl">Pendentes</span>
    </div>
    <div class="maint-stat">
        <span class="maint-stat-num maint-green" id="stat-done"><?= $total - $pending ?></span>
        <span class="maint-stat-lbl">Já em WebP</span>
    </div>
    <div class="maint-stat">
        <span class="maint-stat-num"><?= WEBP_QUALITY ?></span>
        <span class="maint-stat-lbl">Qualidade WebP</span>
    </div>
</div>
<?php endif; ?>

<!-- ════ SAFE ZONE ════════════════════════════════════════════════════════ -->
<section class="maint-zone">
    <h2 class="maint-zone-title maint-zone-safe"><i class="fa fa-shield-halved"></i>Ações seguras</h2>

    <article class="maint-card">
        <div class="maint-card-head">
            <span class="maint-card-ico"><i class="fa fa-images"></i></span>
            <div class="maint-card-titlewrap">
                <h3 class="maint-card-title">Geração de thumbnails</h3>
                <span class="maint-badge maint-badge-safe">Seguro</span>
            </div>
        </div>
        <p class="maint-card-desc">
            <strong class="maint-amber"><?= (int) ($pending_thumbs ?? 0) ?></strong> foto(s) sem thumbnail (<?= THUMB_WIDTH ?>px). Novos uploads já geram automaticamente; gere aqui para as imagens existentes.
        </p>
        <div id="thumb-progress-wrap" class="maint-progress d-none">
            <div class="maint-progress-info"><span>Progresso</span><span id="thumb-progress-label">0 / <?= (int) ($pending_thumbs ?? 0) ?></span></div>
            <div class="maint-progress-track"><div id="thumb-progress-bar" class="maint-progress-bar" style="width:0%"></div></div>
        </div>
        <div class="maint-logwrap d-none" id="thumb-logwrap">
            <div class="maint-log-toolbar"><span><i class="fa fa-terminal"></i> Log</span><button type="button" class="maint-copy" data-log="thumb-log"><i class="fa fa-copy"></i> Copiar</button></div>
            <div id="thumb-log" class="maint-log"></div>
        </div>
        <div id="thumb-banner" class="d-none"></div>
        <div class="maint-card-foot">
            <button id="btn-thumb-start" class="md-btn md-btn-primary" <?= (int) ($pending_thumbs ?? 0) === 0 ? 'disabled' : '' ?>>
                <i class="fa fa-play"></i>Gerar thumbnails
            </button>
        </div>
    </article>

    <article class="maint-card">
        <div class="maint-card-head">
            <span class="maint-card-ico"><i class="fa fa-bolt"></i></span>
            <div class="maint-card-titlewrap">
                <h3 class="maint-card-title">Cache de código (OPcache)</h3>
                <span class="maint-badge maint-badge-safe">Seguro</span>
            </div>
        </div>
        <p class="maint-card-desc">
            O PHP guarda os arquivos <code>.php</code> em cache. Após enviar código atualizado por FTP, limpe o cache para que o código novo entre em vigor.
        </p>
        <div id="opcache-result" class="d-none"></div>
        <div class="maint-card-foot">
            <button id="btn-opcache" class="md-btn"><i class="fa fa-broom"></i>Limpar cache de código</button>
        </div>
    </article>
</section>

<!-- ════ DANGER ZONE ══════════════════════════════════════════════════════ -->
<section class="maint-zone maint-danger">
    <h2 class="maint-zone-title maint-zone-danger"><i class="fa fa-triangle-exclamation"></i>Zona de risco</h2>
    <p class="maint-danger-note">As ações abaixo alteram a estrutura do banco de dados ou removem arquivos de forma <strong>irreversível</strong>. Marque a confirmação antes de executar.</p>

    <?php if (empty($db_error) && $webp_support): ?>
        <?php if ($pending === 0): ?>
        <article class="maint-card">
            <div class="maint-card-head">
                <span class="maint-card-ico"><i class="fa fa-file-image"></i></span>
                <div class="maint-card-titlewrap">
                    <h3 class="maint-card-title">Migração para WebP</h3>
                    <span class="maint-badge maint-badge-danger">Destrutivo</span>
                </div>
            </div>
            <div class="maint-alert maint-alert-ok">
                <i class="fa fa-circle-check"></i>
                <span>Todas as fotos já estão no formato WebP. Nenhuma migração necessária.</span>
            </div>
        </article>
        <?php else: ?>
        <article class="maint-card">
            <div class="maint-card-head">
                <span class="maint-card-ico"><i class="fa fa-file-image"></i></span>
                <div class="maint-card-titlewrap">
                    <h3 class="maint-card-title">Migração para WebP</h3>
                    <span class="maint-badge maint-badge-danger">Destrutivo</span>
                </div>
            </div>
            <p class="maint-card-desc">
                <strong class="maint-amber"><?= $pending ?></strong> foto(s) serão convertidas para WebP. <strong class="maint-red">Os arquivos originais são apagados</strong> após a conversão (irreversível). O processo roda em lotes de 5 para evitar timeout.
            </p>
            <div id="progress-wrap" class="maint-progress d-none">
                <div class="maint-progress-info"><span>Progresso</span><span id="progress-label">0 / <?= $pending ?></span></div>
                <div class="maint-progress-track"><div id="progress-bar" class="maint-progress-bar" style="width:0%"></div></div>
            </div>
            <div class="maint-logwrap d-none" id="log-wrap">
                <div class="maint-log-toolbar"><span><i class="fa fa-terminal"></i> Log</span><button type="button" class="maint-copy" data-log="log"><i class="fa fa-copy"></i> Copiar</button></div>
                <div id="log" class="maint-log"></div>
            </div>
            <div id="result-banner" class="d-none"></div>
            <label class="maint-ack">
                <input type="checkbox" id="webp-ack">
                <span>Entendo que os arquivos originais serão apagados de forma irreversível.</span>
            </label>
            <div class="maint-card-foot">
                <button id="btn-start" class="md-btn md-btn-primary" disabled><i class="fa fa-play"></i>Iniciar migração</button>
                <button id="btn-stop" class="md-btn d-none"><i class="fa fa-stop"></i>Pausar</button>
            </div>
        </article>
        <?php endif; ?>
    <?php endif; ?>

    <article class="maint-card">
        <div class="maint-card-head">
            <span class="maint-card-ico"><i class="fa fa-database"></i></span>
            <div class="maint-card-titlewrap">
                <h3 class="maint-card-title">Índices &amp; migrations do banco</h3>
                <span class="maint-badge maint-badge-warn">Altera banco</span>
            </div>
        </div>
        <p class="maint-card-desc">
            Cria índices, adiciona colunas/tabelas e aplica migrations de schema. É idempotente (só aplica o que falta), mas inclui alterações estruturais no banco.
        </p>
        <div class="maint-logwrap d-none" id="idx-logwrap">
            <div class="maint-log-toolbar"><span><i class="fa fa-terminal"></i> Log</span><button type="button" class="maint-copy" data-log="idx-log"><i class="fa fa-copy"></i> Copiar</button></div>
            <div id="idx-log" class="maint-log"></div>
        </div>
        <label class="maint-ack">
            <input type="checkbox" id="idx-ack">
            <span>Entendo que esta ação altera a estrutura do banco de dados.</span>
        </label>
        <div class="maint-card-foot">
            <button id="btn-idx" class="md-btn md-btn-primary" disabled><i class="fa fa-bolt"></i>Aplicar índices &amp; migrations</button>
        </div>
    </article>
</section>

<script>
(function () {
    const CSRF = <?= json_encode(csrf_token()) ?>;

    function ts() {
        return '[' + new Date().toLocaleTimeString('pt-BR') + '] ';
    }
    function logLine(logEl, wrapId, msg, type) {
        if (wrapId) { const w = document.getElementById(wrapId); if (w) w.classList.remove('d-none'); }
        const line = document.createElement('div');
        line.className = 'maint-log-line maint-log-' + (type || 'muted');
        line.textContent = ts() + msg;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }
    function showBanner(el, ok, html) {
        el.className = 'maint-alert ' + (ok ? 'maint-alert-ok' : 'maint-alert-error');
        el.innerHTML = '<i class="fa ' + (ok ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i><span>' + html + '</span>';
        el.classList.remove('d-none');
    }

    // Copy-log buttons
    document.querySelectorAll('.maint-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            const el = document.getElementById(b.dataset.log);
            if (!el || !el.innerText) return;
            navigator.clipboard.writeText(el.innerText).then(function () {
                const html = b.innerHTML;
                b.innerHTML = '<i class="fa fa-check"></i> Copiado!';
                setTimeout(function () { b.innerHTML = html; }, 1500);
            });
        });
    });

    // Acknowledge checkboxes gate destructive buttons
    function gate(ackId, btnId) {
        const ack = document.getElementById(ackId);
        const btn = document.getElementById(btnId);
        if (!ack || !btn) return;
        ack.addEventListener('change', function () { btn.disabled = !ack.checked; });
    }
    gate('webp-ack', 'btn-start');
    gate('idx-ack', 'btn-idx');

    // ── WebP migration (destrutivo) ──────────────────────────────────
    (function () {
        const btnStart = document.getElementById('btn-start');
        if (!btnStart) return; // não renderizado (sem pendências ou indisponível)
        const btnStop  = document.getElementById('btn-stop');
        const bar      = document.getElementById('progress-bar');
        const label    = document.getElementById('progress-label');
        const wrap     = document.getElementById('progress-wrap');
        const log      = document.getElementById('log');
        const result   = document.getElementById('result-banner');
        const statPend = document.getElementById('stat-pending');
        const statDone = document.getElementById('stat-done');
        const TOTAL    = <?= (int) $pending ?>;
        const GRAND    = <?= (int) $total ?>;
        let converted = 0, stopped = false;

        function progress(remaining) {
            const done = TOTAL - remaining;
            const pct = TOTAL > 0 ? Math.round((done / TOTAL) * 100) : 100;
            bar.style.width = pct + '%';
            label.textContent = done + ' / ' + TOTAL;
            if (statPend) statPend.textContent = remaining;
            if (statDone) statDone.textContent = GRAND - remaining;
        }

        btnStart.addEventListener('click', async function () {
            if (!confirm('Converter ' + TOTAL + ' foto(s) para WebP? Os arquivos originais serão apagados de forma irreversível.')) return;
            stopped = false;
            btnStart.classList.add('d-none');
            btnStop.classList.remove('d-none');
            wrap.classList.remove('d-none');
            result.classList.add('d-none');
            logLine(log, 'log-wrap', 'Iniciando migração…', 'muted');

            let remaining = TOTAL - converted;
            while (!stopped && remaining > 0) {
                try {
                    const resp = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'batch', limit: 5, csrf_token: CSRF }) });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const data = await resp.json();
                    converted += data.converted;
                    remaining = data.remaining;
                    if (data.converted > 0) logLine(log, 'log-wrap', '✓ ' + data.converted + ' foto(s) convertida(s)', 'ok');
                    if (data.skipped > 0)   logLine(log, 'log-wrap', '⚠ ' + data.skipped + ' arquivo(s) ausente(s) no disco', 'warn');
                    if (data.errors && data.errors.length) data.errors.forEach(function (e) { logLine(log, 'log-wrap', '✗ Falha: ' + e, 'err'); });
                    progress(remaining);
                    if (data.done) break;
                    await new Promise(function (r) { setTimeout(r, 300); });
                } catch (err) {
                    logLine(log, 'log-wrap', 'Erro na requisição: ' + err.message, 'err');
                    break;
                }
            }

            btnStop.classList.add('d-none');
            btnStart.classList.remove('d-none');
            if (remaining === 0) {
                btnStart.disabled = true;
                logLine(log, 'log-wrap', '─── Migração concluída ───', 'bold');
                showBanner(result, true, '<strong>Migração concluída!</strong> ' + converted + ' foto(s) convertida(s) para WebP.');
            } else if (stopped) {
                logLine(log, 'log-wrap', '─── Pausado. Clique em Iniciar para continuar. ───', 'warn');
            }
        });
        btnStop.addEventListener('click', function () { stopped = true; });
    })();

    // ── Thumbnails (seguro) ──────────────────────────────────────────
    (function () {
        const btn = document.getElementById('btn-thumb-start');
        if (!btn) return;
        const bar    = document.getElementById('thumb-progress-bar');
        const label  = document.getElementById('thumb-progress-label');
        const wrap   = document.getElementById('thumb-progress-wrap');
        const log    = document.getElementById('thumb-log');
        const result = document.getElementById('thumb-banner');
        const TOTAL  = <?= (int) ($pending_thumbs ?? 0) ?>;
        let gen = 0, stopped = false;

        btn.addEventListener('click', async function () {
            stopped = false;
            btn.disabled = true;
            wrap.classList.remove('d-none');
            result.classList.add('d-none');
            logLine(log, 'thumb-logwrap', 'Gerando thumbnails…', 'muted');

            let remaining = TOTAL - gen;
            while (!stopped && remaining > 0) {
                try {
                    const resp = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'gen_thumbs', limit: 5, csrf_token: CSRF }) });
                    const data = await resp.json();
                    gen += data.generated;
                    remaining = data.remaining;
                    const done = TOTAL - remaining;
                    const pct = TOTAL > 0 ? Math.round((done / TOTAL) * 100) : 100;
                    bar.style.width = pct + '%';
                    label.textContent = done + ' / ' + TOTAL;
                    if (data.generated > 0) logLine(log, 'thumb-logwrap', '✓ ' + data.generated + ' thumbnail(s) gerado(s)', 'ok');
                    if (data.errors && data.errors.length) data.errors.forEach(function (e) { logLine(log, 'thumb-logwrap', '✗ ' + e, 'err'); });
                    if (data.done) break;
                    await new Promise(function (r) { setTimeout(r, 300); });
                } catch (err) {
                    logLine(log, 'thumb-logwrap', 'Erro: ' + err.message, 'err');
                    break;
                }
            }

            btn.disabled = false;
            if (remaining === 0) {
                btn.disabled = true;
                showBanner(result, true, '<strong>Concluído!</strong> ' + gen + ' thumbnail(s) gerado(s).');
            }
        });
    })();

    // ── Índices & migrations (altera banco) ──────────────────────────
    (function () {
        const btn = document.getElementById('btn-idx');
        if (!btn) return;
        const log = document.getElementById('idx-log');

        btn.addEventListener('click', async function () {
            if (!confirm('Aplicar índices e migrations? Esta ação altera a estrutura do banco de dados.')) return;
            btn.disabled = true;
            const html = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>Aplicando…';
            logLine(log, 'idx-logwrap', 'Aplicando migrations…', 'muted');
            try {
                const resp = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'apply_indexes', csrf_token: CSRF }) });
                const data = await resp.json();
                (data.applied || []).forEach(function (n) { logLine(log, 'idx-logwrap', '✓ Aplicado: ' + n, 'ok'); });
                (data.skipped || []).forEach(function (n) { logLine(log, 'idx-logwrap', '— Já existe: ' + n, 'muted'); });
                (data.errors  || []).forEach(function (e) { logLine(log, 'idx-logwrap', '✗ ' + e, 'err'); });
                if (!data.errors || !data.errors.length) {
                    logLine(log, 'idx-logwrap', '─── Concluído sem erros ───', 'bold');
                    btn.innerHTML = '<i class="fa fa-check"></i>Migrations aplicadas';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = html;
                }
            } catch (err) {
                logLine(log, 'idx-logwrap', 'Erro: ' + err.message, 'err');
                btn.disabled = false;
                btn.innerHTML = html;
            }
        });
    })();

    // ── OPcache (seguro) ─────────────────────────────────────────────
    (function () {
        const btn = document.getElementById('btn-opcache');
        if (!btn) return;
        const result = document.getElementById('opcache-result');

        btn.addEventListener('click', async function () {
            btn.disabled = true;
            const html = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>Limpando…';
            try {
                const resp = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'reset_opcache', csrf_token: CSRF }) });
                const data = await resp.json();
                showBanner(result, !!data.ok, data.message);
            } catch (err) {
                showBanner(result, false, 'Erro: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = html;
            }
        });
    })();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
