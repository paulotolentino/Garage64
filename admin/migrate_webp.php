<?php
// Show errors on this admin-only diagnostic page so we can catch issues
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

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

    // MySQL doesn't support CREATE INDEX IF NOT EXISTS — check information_schema first
    $indexes = [
        'idx_photos_miniature_primary' => ['miniature_photos', 'CREATE INDEX idx_photos_miniature_primary ON miniature_photos (miniature_id, is_primary)'],
        'idx_miniatures_created'       => ['miniatures',       'CREATE INDEX idx_miniatures_created ON miniatures (created_at)'],
        'idx_miniatures_condition'     => ['miniatures',       'CREATE INDEX idx_miniatures_condition ON miniatures (`condition`)'],
        'idx_miniatures_location'      => ['miniatures',       'CREATE INDEX idx_miniatures_location ON miniatures (location)'],
        'idx_miniatures_manufacturer'  => ['miniatures',       'CREATE INDEX idx_miniatures_manufacturer ON miniatures (manufacturer)'],
        'idx_miniatures_scale'         => ['miniatures',       'CREATE INDEX idx_miniatures_scale ON miniatures (scale)'],
        'ft_miniatures_search'         => ['miniatures',       'ALTER TABLE miniatures ADD FULLTEXT INDEX ft_miniatures_search (name, manufacturer, model)'],
    ];

    // Schema migrations (column additions) — checked via information_schema.columns
    $columns = [
        'is_public'   => "ALTER TABLE miniatures ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER emotional_rating",
        'views'       => "ALTER TABLE miniatures ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_public",
        'is_featured' => "ALTER TABLE miniatures ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public",
        'sort_order'  => "ALTER TABLE miniatures ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 9999 AFTER is_featured",
        'condition'   => "ALTER TABLE miniatures ADD COLUMN `condition` ENUM('sealed','open','no_box') NOT NULL DEFAULT 'sealed' AFTER category_id",
        'location'    => "ALTER TABLE miniatures ADD COLUMN location ENUM('display','storage') NOT NULL DEFAULT 'storage' AFTER `condition`",
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

    $applied = [];
    $errors  = [];
    $skipped = [];

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
         WHERE table_schema = DATABASE() AND table_name = 'miniatures' AND column_name = ?"
    );

    foreach ($columns as $col => $sql) {
        try {
            $check_col->execute([$col]);
            if ((int) $check_col->fetchColumn() > 0) {
                $skipped[] = 'col:' . $col;
                continue;
            }
            db()->exec($sql);
            $applied[] = 'col:' . $col;
        } catch (Throwable $e) {
            $errors[] = 'col:' . $col . ': ' . $e->getMessage();
        }
    }

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

// ─── STATUS PAGE ─────────────────────────────────────────────────────────────
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

$page_title = 'Migração WebP';
require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="d-flex align-items-center mb-4 gap-2">
    <h1 class="h4 mb-0"><i class="fa fa-images me-2 text-warning"></i>Migração para WebP</h1>
</div>

<?php if (!empty($db_error)): ?>
<div class="alert alert-danger">
    <i class="fa fa-times-circle me-2"></i>
    <strong>Erro de banco de dados:</strong> <?= h($db_error) ?>
</div>
<?php elseif (!$webp_support): ?>
<div class="alert alert-danger">
    <i class="fa fa-times-circle me-2"></i>
    <strong>WebP não suportado.</strong>
    A extensão GD do PHP não foi compilada com suporte a WebP neste servidor.
    Entre em contato com seu provedor de hospedagem para habilitar o suporte.
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card bg-dark border-secondary text-center p-3">
            <div class="h2 text-light"><?= $total ?></div>
            <div class="text-secondary small">Total de fotos</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card bg-dark border-secondary text-center p-3">
            <div class="h2 text-warning" id="stat-pending"><?= $pending ?></div>
            <div class="text-secondary small">Pendentes</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card bg-dark border-secondary text-center p-3">
            <div class="h2 text-success" id="stat-done"><?= $total - $pending ?></div>
            <div class="text-secondary small">Já em WebP</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card bg-dark border-secondary text-center p-3">
            <div class="h2 text-info"><?= WEBP_QUALITY ?></div>
            <div class="text-secondary small">Qualidade WebP</div>
        </div>
    </div>
</div>

<?php if ($pending === 0): ?>
<div class="alert alert-success">
    <i class="fa fa-check-circle me-2"></i>
    Todas as fotos já estão no formato WebP. Nenhuma migração necessária.
</div>
<?php else: ?>

<div class="card bg-dark border-secondary mb-4">
    <div class="card-body">
        <p class="mb-1">
            <strong class="text-warning"><?= $pending ?></strong> foto(s) serão convertidas para WebP.
        </p>
        <p class="text-secondary small mb-3">
            Os arquivos originais são removidos após a conversão. O processo roda em lotes de 5 para não causar timeout.
        </p>

        <div id="progress-wrap" class="mb-3 d-none">
            <div class="d-flex justify-content-between mb-1">
                <span class="small text-secondary">Progresso</span>
                <span id="progress-label" class="small text-light">0 / <?= $pending ?></span>
            </div>
            <div class="progress bg-secondary" style="height:10px">
                <div id="progress-bar" class="progress-bar bg-warning" role="progressbar" style="width:0%"></div>
            </div>
        </div>

        <div id="log" class="mb-3 bg-black rounded p-2 small font-monospace d-none"
             style="max-height:200px; overflow-y:auto; border:1px solid #333"></div>

        <div id="result-banner" class="d-none"></div>

        <button id="btn-start" class="btn btn-warning">
            <i class="fa fa-play me-1"></i>Iniciar Migração
        </button>
        <button id="btn-stop" class="btn btn-secondary ms-2 d-none">
            <i class="fa fa-stop me-1"></i>Pausar
        </button>
    </div>
</div>

<script>
(function () {
    const CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;
    const TOTAL        = <?= $pending ?>;
    const BATCH_SIZE   = 5;

    let totalConverted = 0;
    let stopped        = false;

    const btnStart  = document.getElementById('btn-start');
    const btnStop   = document.getElementById('btn-stop');
    const bar       = document.getElementById('progress-bar');
    const label     = document.getElementById('progress-label');
    const wrap      = document.getElementById('progress-wrap');
    const log       = document.getElementById('log');
    const banner    = document.getElementById('result-banner');
    const statPend  = document.getElementById('stat-pending');
    const statDone  = document.getElementById('stat-done');

    function appendLog(msg, cls) {
        const line = document.createElement('div');
        line.className = cls || 'text-secondary';
        line.textContent = msg;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    function updateProgress(remaining) {
        const done = TOTAL - remaining;
        const pct  = TOTAL > 0 ? Math.round((done / TOTAL) * 100) : 100;
        bar.style.width = pct + '%';
        label.textContent = done + ' / ' + TOTAL;
        statPend.textContent = remaining;
        statDone.textContent = <?= $total ?> - remaining;
    }

    async function runBatch() {
        const body = new URLSearchParams({
            action:     'batch',
            limit:      BATCH_SIZE,
            csrf_token: CSRF_TOKEN,
        });
        const resp = await fetch('', { method: 'POST', body });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.json();
    }

    btnStart.addEventListener('click', async function () {
        stopped = false;
        btnStart.classList.add('d-none');
        btnStop.classList.remove('d-none');
        wrap.classList.remove('d-none');
        log.classList.remove('d-none');
        banner.classList.add('d-none');

        let remaining = TOTAL - totalConverted;

        while (!stopped && remaining > 0) {
            try {
                const data = await runBatch();
                totalConverted += data.converted;
                remaining       = data.remaining;

                if (data.converted > 0) {
                    appendLog('✓ ' + data.converted + ' foto(s) convertida(s)', 'text-success');
                }
                if (data.skipped > 0) {
                    appendLog('⚠ ' + data.skipped + ' arquivo(s) não encontrado(s) no disco', 'text-warning');
                }
                if (data.errors && data.errors.length) {
                    data.errors.forEach(function (e) {
                        appendLog('✗ Falha: ' + e, 'text-danger');
                    });
                }

                updateProgress(remaining);

                if (data.done) break;

                // Small pause between batches
                await new Promise(function (r) { setTimeout(r, 300); });
            } catch (err) {
                appendLog('Erro na requisição: ' + err.message, 'text-danger');
                break;
            }
        }

        btnStop.classList.add('d-none');
        btnStart.classList.remove('d-none');

        if (remaining === 0) {
            btnStart.disabled = true;
            banner.innerHTML =
                '<div class="alert alert-success">' +
                '<i class="fa fa-check-circle me-2"></i>' +
                '<strong>Migração concluída!</strong> ' + totalConverted + ' foto(s) convertida(s) para WebP.' +
                '</div>';
            banner.classList.remove('d-none');
            appendLog('─── Migração concluída ───', 'text-success fw-bold');
        } else if (stopped) {
            appendLog('─── Pausado. Clique em Iniciar para continuar. ───', 'text-warning');
        }
    });

    btnStop.addEventListener('click', function () { stopped = true; });
})();
</script>

<?php endif; ?>
<?php endif; ?>

<!-- ─── THUMBNAILS ────────────────────────────────────────────────────────── -->
<hr class="border-secondary mt-5">
<h2 class="h5 mb-3"><i class="fa fa-th-large me-2 text-warning"></i>Geração de Thumbnails</h2>
<div class="card bg-dark border-secondary mb-4">
    <div class="card-body">
        <p class="mb-1">
            <strong class="text-warning"><?= $pending_thumbs ?? 0 ?></strong> foto(s) sem thumbnail gerado (<?= THUMB_WIDTH ?>px).
        </p>
        <p class="text-secondary small mb-3">
            Novos uploads já geram thumbnail automaticamente.
            Clique abaixo para gerar para as imagens existentes.
        </p>
        <div id="thumb-progress-wrap" class="mb-3 d-none">
            <div class="d-flex justify-content-between mb-1">
                <span class="small text-secondary">Progresso</span>
                <span id="thumb-progress-label" class="small text-light">0 / <?= $pending_thumbs ?? 0 ?></span>
            </div>
            <div class="progress bg-secondary" style="height:10px">
                <div id="thumb-progress-bar" class="progress-bar bg-warning" role="progressbar" style="width:0%"></div>
            </div>
        </div>
        <div id="thumb-log" class="mb-3 bg-black rounded p-2 small font-monospace d-none"
             style="max-height:150px; overflow-y:auto; border:1px solid #333"></div>
        <div id="thumb-banner" class="d-none"></div>
        <button id="btn-thumb-start" class="btn btn-warning btn-sm" <?= ($pending_thumbs ?? 0) === 0 ? 'disabled' : '' ?>>
            <i class="fa fa-play me-1"></i>Gerar Thumbnails
        </button>
    </div>
</div>

<script>
(function () {
    const CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;
    const TOTAL_THUMBS = <?= (int) ($pending_thumbs ?? 0) ?>;
    const BATCH_SIZE   = 5;

    let totalGen = 0;
    let stopped  = false;

    const btn    = document.getElementById('btn-thumb-start');
    const bar    = document.getElementById('thumb-progress-bar');
    const label  = document.getElementById('thumb-progress-label');
    const wrap   = document.getElementById('thumb-progress-wrap');
    const log    = document.getElementById('thumb-log');
    const banner = document.getElementById('thumb-banner');

    function appendLog(msg, cls) {
        log.classList.remove('d-none');
        const line = document.createElement('div');
        line.className = cls || 'text-secondary';
        line.textContent = msg;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    btn.addEventListener('click', async function () {
        stopped = false;
        btn.disabled = true;
        wrap.classList.remove('d-none');
        banner.classList.add('d-none');

        let remaining = TOTAL_THUMBS - totalGen;

        while (!stopped && remaining > 0) {
            try {
                const resp = await fetch('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'gen_thumbs', limit: BATCH_SIZE, csrf_token: CSRF_TOKEN }),
                });
                const data = await resp.json();

                totalGen += data.generated;
                remaining = data.remaining;

                const done = TOTAL_THUMBS - remaining;
                const pct  = TOTAL_THUMBS > 0 ? Math.round((done / TOTAL_THUMBS) * 100) : 100;
                bar.style.width = pct + '%';
                label.textContent = done + ' / ' + TOTAL_THUMBS;

                if (data.generated > 0) {
                    appendLog('✓ ' + data.generated + ' thumbnail(s) gerado(s)', 'text-success');
                }
                if (data.errors && data.errors.length) {
                    data.errors.forEach(function (e) { appendLog('✗ ' + e, 'text-danger'); });
                }

                if (data.done) break;
                await new Promise(function (r) { setTimeout(r, 300); });
            } catch (err) {
                appendLog('Erro: ' + err.message, 'text-danger');
                break;
            }
        }

        btn.disabled = false;

        if (remaining === 0) {
            btn.disabled = true;
            banner.innerHTML =
                '<div class="alert alert-success mt-2">' +
                '<i class="fa fa-check-circle me-2"></i>' +
                '<strong>Concluído!</strong> ' + totalGen + ' thumbnail(s) gerado(s).' +
                '</div>';
            banner.classList.remove('d-none');
        }
    });
})();
</script>

<!-- ─── DB INDEXES ─────────────────────────────────────────────────────────── -->
<hr class="border-secondary mt-5">
<h2 class="h5 mb-3"><i class="fa fa-database me-2 text-warning"></i>Índices do Banco de Dados</h2>
<div class="card bg-dark border-secondary mb-4">
    <div class="card-body">
        <p class="text-secondary small mb-3">
            Aplica índices que aceleram a listagem e filtragem de miniaturas.
            Operação segura — usa <code>CREATE INDEX IF NOT EXISTS</code>.
        </p>
        <div id="idx-log" class="mb-3 bg-black rounded p-2 small font-monospace d-none"
             style="max-height:150px; overflow-y:auto; border:1px solid #333"></div>
        <button id="btn-idx" class="btn btn-outline-warning btn-sm">
            <i class="fa fa-bolt me-1"></i>Aplicar Índices
        </button>
    </div>
</div>

<script>
(function () {
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    const btnIdx     = document.getElementById('btn-idx');
    const idxLog     = document.getElementById('idx-log');

    function appendIdxLog(msg, cls) {
        idxLog.classList.remove('d-none');
        const line = document.createElement('div');
        line.className = cls || 'text-secondary';
        line.textContent = msg;
        idxLog.appendChild(line);
        idxLog.scrollTop = idxLog.scrollHeight;
    }

    btnIdx.addEventListener('click', async function () {
        btnIdx.disabled = true;
        btnIdx.textContent = 'Aplicando…';

        try {
            const resp = await fetch('', {
                method: 'POST',
                body: new URLSearchParams({ action: 'apply_indexes', csrf_token: CSRF_TOKEN }),
            });
            const data = await resp.json();

            data.applied.forEach(function (n)  { appendIdxLog('✓ Criado: ' + n, 'text-success'); });
            data.skipped.forEach(function (n)  { appendIdxLog('— Já existe: ' + n, 'text-secondary'); });
            data.errors.forEach(function (e)   { appendIdxLog('✗ ' + e, 'text-danger'); });

            if (!data.errors.length) {
                appendIdxLog('─── Concluído sem erros ───', 'text-success fw-bold');
                btnIdx.textContent = 'Índices aplicados';
            } else {
                btnIdx.disabled = false;
                btnIdx.textContent = 'Aplicar Índices';
            }
        } catch (err) {
            appendIdxLog('Erro: ' + err.message, 'text-danger');
            btnIdx.disabled = false;
            btnIdx.textContent = 'Aplicar Índices';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
