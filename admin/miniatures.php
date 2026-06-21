<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$action = $_GET['action'] ?? 'list';

// ─── ROTATE PHOTO (AJAX) ─────────────────────────────────────────────────────
if ($action === 'rotate_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    header('Content-Type: application/json');
    $photo_id     = (int) ($_POST['photo_id']     ?? 0);
    $miniature_id = (int) ($_POST['miniature_id'] ?? 0);
    $degrees      = (int) ($_POST['degrees']       ?? 90);
    if (!in_array($degrees, [90, 180, 270], true)) $degrees = 90;

    $stmt = db()->prepare('SELECT file_path FROM miniature_photos WHERE id = ? AND miniature_id = ?');
    $stmt->execute([$photo_id, $miniature_id]);
    $photo = $stmt->fetch();
    if (!$photo) { echo json_encode(['ok'=>false,'error'=>'foto não encontrada']); exit; }

    $orig_path  = UPLOADS_DIR . $photo['file_path'];
    $thumb_path = UPLOADS_DIR . preg_replace('/\.webp$/i', '_thumb.webp', $photo['file_path']);

    foreach ([$orig_path, $thumb_path] as $path) {
        if (!file_exists($path)) continue;
        $img = imagecreatefromwebp($path);
        if (!$img) continue;
        // imagerotate: positive = counter-clockwise; invert for intuitive CW
        $rotated = imagerotate($img, 360 - $degrees, 0);
        imagedestroy($img);
        imagewebp($rotated, $path, WEBP_QUALITY);
        imagedestroy($rotated);
    }

    echo json_encode(['ok' => true, 'bust' => time()]);
    exit;
}

// ─── REORDER PHOTOS (AJAX) ───────────────────────────────────────────────────
if ($action === 'reorder_photos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    header('Content-Type: application/json');
    $miniature_id = (int) ($_POST['miniature_id'] ?? 0);
    $photo_ids    = array_filter(array_map('intval', $_POST['photo_ids'] ?? []));
    if ($miniature_id && $photo_ids) {
        $stmt = db()->prepare(
            'UPDATE miniature_photos SET sort_order = ? WHERE id = ? AND miniature_id = ?'
        );
        foreach (array_values($photo_ids) as $i => $pid) {
            $stmt->execute([$i, $pid, $miniature_id]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── TOGGLE FEATURED (AJAX) ──────────────────────────────────────────────────
if ($action === 'toggle_featured' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT is_featured FROM miniatures WHERE id = ?');
    $stmt->execute([$id]);
    $mini = $stmt->fetch();
    if (!$mini) { echo json_encode(['ok' => false]); exit; }
    $new = $mini['is_featured'] ? 0 : 1;
    db()->prepare('UPDATE miniatures SET is_featured = ? WHERE id = ?')->execute([$new, $id]);
    echo json_encode(['ok' => true, 'is_featured' => $new]);
    exit;
}

// ─── BULK ACTIONS ────────────────────────────────────────────────────────────
if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ids        = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $bulk_action = $_POST['bulk_action'] ?? '';
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        match ($bulk_action) {
            'make_public'   => db()->prepare("UPDATE miniatures SET is_public = 1 WHERE id IN ($placeholders)")->execute($ids),
            'make_private'  => db()->prepare("UPDATE miniatures SET is_public = 0 WHERE id IN ($placeholders)")->execute($ids),
            'feature'       => db()->prepare("UPDATE miniatures SET is_featured = 1 WHERE id IN ($placeholders)")->execute($ids),
            'unfeature'     => db()->prepare("UPDATE miniatures SET is_featured = 0 WHERE id IN ($placeholders)")->execute($ids),
            'status_open'   => db()->prepare("UPDATE miniatures SET `condition` = 'open'    WHERE id IN ($placeholders)")->execute($ids),
            'status_sealed' => db()->prepare("UPDATE miniatures SET `condition` = 'sealed'  WHERE id IN ($placeholders)")->execute($ids),
            'status_no_box' => db()->prepare("UPDATE miniatures SET `condition` = 'no_box'  WHERE id IN ($placeholders)")->execute($ids),
            'status_display'=> db()->prepare("UPDATE miniatures SET location = 'display' WHERE id IN ($placeholders)")->execute($ids),
            'status_storage'=> db()->prepare("UPDATE miniatures SET location = 'storage' WHERE id IN ($placeholders)")->execute($ids),
            default         => null,
        };
        flash(count($ids) . ' miniatura(s) atualizadas.');
    }
    $return_page = max(1, (int) ($_POST['return_page'] ?? 1));
    redirect('/admin/miniatures?page=' . $return_page);
}

// ─── DELETE ──────────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $photos = get_miniature_photos($id);
    foreach ($photos as $p) {
        $path = UPLOADS_DIR . $p['file_path'];
        if (file_exists($path)) {
            unlink($path);
        }
    }
    $dir = UPLOADS_DIR . $id . '/';
    if (is_dir($dir)) {
        @rmdir($dir);
    }
    db()->prepare('DELETE FROM miniatures WHERE id = ?')->execute([$id]);
    flash('Miniatura removida com sucesso.');
    redirect('/admin/miniatures');
}

// ─── SAVE (add/edit) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    $data = [
        'name'              => trim($_POST['name'] ?? ''),
        'manufacturer'      => trim($_POST['manufacturer'] ?? ''),
        'model'             => trim($_POST['model'] ?? '') ?: null,
        'scale'             => trim($_POST['scale'] ?? '') ?: null,
        'year'              => (int) ($_POST['year'] ?? 0) ?: null,
        'category_id'       => (int) ($_POST['category_id'] ?? 0) ?: null,
        'condition'         => in_array($_POST['condition'] ?? '', ['sealed','open','no_box']) ? $_POST['condition'] : 'sealed',
        'location'          => in_array($_POST['location'] ?? '', ['display','storage']) ? $_POST['location'] : 'storage',
        'public_description'=> trim($_POST['public_description'] ?? '') ?: null,
        'private_story'     => trim($_POST['private_story'] ?? '') ?: null,
        'private_notes'     => trim($_POST['private_notes'] ?? '') ?: null,
        'purchase_price'    => strlen(trim($_POST['purchase_price'] ?? '')) ? parse_decimal($_POST['purchase_price']) : null,
        'estimated_price'   => strlen(trim($_POST['estimated_price'] ?? '')) ? parse_decimal($_POST['estimated_price']) : null,
        'purchase_date'     => trim($_POST['purchase_date'] ?? '') ?: null,
        'purchase_location' => trim($_POST['purchase_location'] ?? '') ?: null,
        'emotional_rating'  => (int) ($_POST['emotional_rating'] ?? 0) ?: null,
        'is_public'         => isset($_POST['is_public']) ? 1 : 0,
        'is_featured'       => isset($_POST['is_featured']) ? 1 : 0,
        'sort_order'        => max(0, (int) ($_POST['sort_order'] ?? 9999)),
        'user_id'           => current_user_id(),
    ];

    if (!$data['name'] || !$data['manufacturer']) {
        flash('Nome e fabricante são obrigatórios.', 'danger');
        redirect('/admin/miniatures?action=' . ($id ? 'edit&id=' . $id : 'add'));
    }

    if ($id) {
        // Update — backtick column names to avoid reserved-word conflicts (e.g. `condition`)
        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $data['id'] = $id;
        db()->prepare("UPDATE miniatures SET $sets WHERE id = :id")->execute($data);
        $miniature_id = $id;
        flash('Miniatura atualizada com sucesso.');
    } else {
        // Insert
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $phs  = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        db()->prepare("INSERT INTO miniatures ($cols) VALUES ($phs)")->execute($data);
        $miniature_id = (int) db()->lastInsertId();
        flash('Miniatura adicionada com sucesso.');
    }

    // Tags
    db()->prepare('DELETE FROM miniature_tags WHERE miniature_id = ?')->execute([$miniature_id]);
    $tag_ids = array_filter(array_map('intval', $_POST['tags'] ?? []));
    if ($tag_ids) {
        $stmt = db()->prepare('INSERT IGNORE INTO miniature_tags (miniature_id, tag_id) VALUES (?, ?)');
        foreach ($tag_ids as $tid) {
            $stmt->execute([$miniature_id, $tid]);
        }
    }

    // Photos upload
    if (!empty($_FILES['photos']['name'][0])) {
        $chk = db()->prepare('SELECT COUNT(*) FROM miniature_photos WHERE miniature_id = ? AND is_primary = 1');
        $chk->execute([$miniature_id]);
        $has_primary = (int) $chk->fetchColumn();

        $srt = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM miniature_photos WHERE miniature_id = ?');
        $srt->execute([$miniature_id]);
        $sort = (int) $srt->fetchColumn();

        $photos = $_FILES['photos'];
        $count  = count($photos['name']);
        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name'     => $photos['name'][$i],
                'type'     => $photos['type'][$i],
                'tmp_name' => $photos['tmp_name'][$i],
                'error'    => $photos['error'][$i],
                'size'     => $photos['size'][$i],
            ];
            $path = upload_photo($file, $miniature_id);
            if ($path) {
                $is_primary = !$has_primary ? 1 : 0;
                $has_primary = 1;
                db()->prepare(
                    'INSERT INTO miniature_photos (miniature_id, file_path, is_primary, sort_order) VALUES (?, ?, ?, ?)'
                )->execute([$miniature_id, $path, $is_primary, $sort++]);
            }
        }
    }

    // Primary photo change
    if (!empty($_POST['primary_photo_id'])) {
        $photo_id = (int) $_POST['primary_photo_id'];
        db()->prepare('UPDATE miniature_photos SET is_primary = 0 WHERE miniature_id = ?')->execute([$miniature_id]);
        db()->prepare('UPDATE miniature_photos SET is_primary = 1 WHERE id = ? AND miniature_id = ?')->execute([$photo_id, $miniature_id]);
    }

    // Delete photo
    if (!empty($_POST['delete_photo_id'])) {
        delete_photo((int) $_POST['delete_photo_id'], $miniature_id);
    }

    $return_page = max(1, (int) ($_POST['return_page'] ?? 1));
    redirect('/admin/miniatures?page=' . $return_page);
}

// ─── VIEW (detail) ───────────────────────────────────────────────────────────
if ($action === 'view') {
    $view_id      = (int) ($_GET['id'] ?? 0);
    $view_mini    = $view_id ? get_miniature($view_id) : null;
    $view_photos  = $view_id ? get_miniature_photos($view_id) : [];
    $view_tags    = $view_id ? get_miniature_tags($view_id) : [];
    if (!$view_mini) { flash('Miniatura não encontrada.', 'danger'); redirect('/admin/miniatures'); }
}

// ─── LIST ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $filters = [
        'search'       => trim($_GET['search'] ?? ''),
        'manufacturer' => trim($_GET['manufacturer'] ?? ''),
        'scale'        => trim($_GET['scale'] ?? ''),
        'category_id'  => (int) ($_GET['category_id'] ?? 0) ?: null,
        'condition'    => trim($_GET['condition'] ?? ''),
        'location'     => trim($_GET['location'] ?? ''),
        'sort'         => trim($_GET['sort'] ?? ''),
        'is_public'    => null, // admin sees all
        'user_id'      => current_user_id(),
    ];

    $admin_per_page   = PER_PAGE;
    $admin_page       = max(1, (int) ($_GET['page'] ?? 1));
    $admin_total      = count_miniatures($filters);
    $admin_total_pages = (int) ceil($admin_total / $admin_per_page);
    $admin_page       = min($admin_page, max(1, $admin_total_pages));

    $miniatures = get_miniatures($filters + ['page' => $admin_page, 'per_page' => $admin_per_page]);

    // Modo de visualização (grade padrão) e estado do painel de filtros.
    $admin_view = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
    $admin_active_filters = (bool) array_filter(array_intersect_key(
        $filters, array_flip(['search','manufacturer','scale','category_id','condition','location','sort'])
    ));
    $admin_open_filters = $admin_active_filters || !empty($_GET['filters']);

    // Resumo do hero (escopado no usuário, sem alterar functions.php).
    $admin_uid     = current_user_id();
    $admin_slug    = current_user_slug();
    $admin_pub     = 0;
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM miniatures WHERE user_id = ? AND is_public = 1');
        $st->execute([$admin_uid]);
        $admin_pub = (int) $st->fetchColumn();
    } catch (\Throwable $e) { $admin_pub = 0; }
    $admin_all   = count_miniatures(['user_id' => $admin_uid, 'is_public' => null]);
    $admin_prv   = max(0, $admin_all - $admin_pub);
    $admin_wish  = count(get_wishlist('', $admin_uid));

    // Base de querystring para paginação/links — preserva filtros + estado de UI.
    $admin_qs = [];
    foreach (['search','manufacturer','scale','category_id','condition','location','sort'] as $k) {
        if (!empty($filters[$k])) $admin_qs[$k] = $filters[$k];
    }
    if ($admin_view === 'list')   $admin_qs['view']    = 'list';
    if ($admin_open_filters)      $admin_qs['filters'] = 1;
} else {
    $filters    = [];
    $miniatures = [];
    $admin_page = 1;
    $admin_total = 0;
    $admin_total_pages = 1;
    $admin_view = 'grid';
    $admin_open_filters = false;
    $admin_qs = [];
}

$categories    = get_categories(current_user_id());
$tags          = get_tags(current_user_id());
$manufacturers = get_distinct_manufacturers(current_user_id());
$scales        = get_distinct_scales(current_user_id());

// ─── FORM: Add / Edit ────────────────────────────────────────────────────────
$editing   = null;
$edit_photos = [];
$edit_tags   = [];

if ($action === 'edit' && isset($_GET['id'])) {
    $editing     = get_miniature((int) $_GET['id']);
    $edit_photos = get_miniature_photos((int) $_GET['id']);
    $edit_tags   = array_column(get_miniature_tags((int) $_GET['id']), 'id');
}

$page_title = $action === 'list' ? 'Miniaturas' : ($editing ? 'Editar Miniatura' : 'Nova Miniatura');

require_once __DIR__ . '/../includes/header_admin.php';
?>

<?php if ($action === 'list'): ?>
<!-- ═══ LIST VIEW — Minha garagem ══════════════════════════════════════ -->
<?php
// Base de querystring para links de paginação (preserva filtros + estado de UI).
$pg_base = '/admin/miniatures?' . http_build_query(['action' => 'list'] + $admin_qs);

// Renderiza um card de miniatura (mesmo markup para grade e lista).
$render_item = function (array $m) use ($admin_page) {
    $is_featured = (int) ($m['is_featured'] ?? 0);
    $is_public   = (int) ($m['is_public'] ?? 0);
    $cond        = $m['condition'] ?? 'sealed';
    $loc         = $m['location'] ?? 'storage';
    $views       = (int) ($m['views'] ?? 0);
    $photos      = (int) ($m['photo_count'] ?? 0);
    $edit_url    = '/admin/miniatures?action=edit&id=' . $m['id'] . '&return_page=' . $admin_page;
    ?>
    <article class="admin-miniatures-card<?= $is_featured ? ' is-featured' : '' ?>">
        <label class="admin-miniatures-check" title="Selecionar">
            <input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= $m['id'] ?>">
        </label>
        <button type="button" class="admin-miniatures-fav toggle-featured-btn"
                data-id="<?= $m['id'] ?>" data-featured="<?= $is_featured ?>"
                title="<?= $is_featured ? 'Remover destaque' : 'Destacar' ?>">
            <i class="fa fa-star <?= $is_featured ? 'text-warning' : '' ?>"></i>
        </button>

        <a href="<?= $edit_url ?>" class="admin-miniatures-thumb">
            <img src="<?= e(thumb_url($m['primary_photo'])) ?>"
                 data-fallback="<?= e(photo_url($m['primary_photo'])) ?>"
                 alt="<?= e($m['name']) ?>" loading="lazy">
            <span class="admin-miniatures-vis admin-miniatures-vis-<?= $is_public ? 'on' : 'off' ?>">
                <i class="fa fa-<?= $is_public ? 'eye' : 'eye-slash' ?>"></i>
                <span class="admin-miniatures-vis-text"><?= $is_public ? 'Pública' : 'Privada' ?></span>
            </span>
        </a>

        <div class="admin-miniatures-body">
            <?php if (!empty($m['manufacturer'])): ?>
                <div class="admin-miniatures-maker"><?= e($m['manufacturer']) ?></div>
            <?php endif; ?>
            <h3 class="admin-miniatures-name"><?= e($m['name']) ?></h3>
            <div class="admin-miniatures-pills">
                <?php if (!empty($m['scale'])): ?>
                    <span class="md-pill"><i class="fa fa-ruler"></i><?= e($m['scale']) ?></span>
                <?php endif; ?>
                <span class="md-pill md-cond-<?= e($cond) ?>"><?= e(condition_label($cond)) ?></span>
                <span class="md-pill"><i class="fa fa-<?= $loc === 'display' ? 'lightbulb' : 'box-archive' ?>"></i><?= e(location_label_short($loc)) ?></span>
            </div>
            <div class="admin-miniatures-meta">
                <span title="Visualizações"><i class="fa fa-eye"></i><?= number_format($views) ?></span>
                <span title="Fotos"><i class="fa fa-image"></i><?= number_format($photos) ?></span>
                <?php if (!empty($m['category_name'])): ?>
                    <span title="Categoria"><i class="fa fa-tag"></i><?= e($m['category_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-miniatures-acts">
            <a href="<?= $edit_url ?>" class="admin-miniatures-act" title="Editar"><i class="fa fa-pen"></i></a>
            <a href="<?= $edit_url ?>#fotos" class="admin-miniatures-act" title="Gerenciar fotos"><i class="fa fa-images"></i></a>
            <a href="<?= e(mini_url($m)) ?>" target="_blank" class="admin-miniatures-act" title="Ver página pública"><i class="fa fa-up-right-from-square"></i></a>
            <a href="/admin/miniatures?action=delete&id=<?= $m['id'] ?>" class="admin-miniatures-act admin-miniatures-act-danger"
               title="Excluir" onclick="return confirm('Remover esta miniatura?')"><i class="fa fa-trash"></i></a>
        </div>
    </article>
    <?php
};
?>

<!-- Hero -->
<section class="dash-hero admin-miniatures-hero">
    <div class="dash-hero-id">
        <div class="dash-hero-avatar admin-miniatures-hero-icon"><i class="fa fa-car-side"></i></div>
        <div class="dash-hero-text">
            <div class="lp-eyebrow">Coleção</div>
            <h1 class="dash-hero-name">Minhas miniaturas</h1>
            <div class="dash-hero-handle">Gerencie sua coleção.</div>
        </div>
    </div>
    <div class="dash-hero-actions">
        <a href="/admin/miniatures?action=add" class="md-btn md-btn-primary"><i class="fa fa-plus"></i>Adicionar miniatura</a>
        <?php if ($admin_slug): ?>
        <a href="/u/<?= e($admin_slug) ?>" target="_blank" class="md-btn"><i class="fa fa-warehouse"></i>Minha garagem pública</a>
        <?php endif; ?>
    </div>
</section>

<!-- Resumo -->
<div class="cp-stats admin-miniatures-stats">
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($admin_all) ?></span>
        <span class="cp-stat-lbl">miniatura<?= $admin_all !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($admin_pub) ?></span>
        <span class="cp-stat-lbl">pública<?= $admin_pub !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($admin_prv) ?></span>
        <span class="cp-stat-lbl">privada<?= $admin_prv !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($admin_wish) ?></span>
        <span class="cp-stat-lbl">wishlist</span>
    </div>
</div>

<!-- Barra de ferramentas: filtros + modo de visualização -->
<div class="admin-miniatures-toolbar">
    <button type="button" class="admin-miniatures-toolbtn<?= $admin_open_filters ? ' is-open' : '' ?>" id="btnFilters"
            aria-expanded="<?= $admin_open_filters ? 'true' : 'false' ?>" aria-controls="adminFilters">
        <i class="fa fa-sliders"></i>
        <span>Busca e filtros</span>
        <i class="fa fa-chevron-down admin-miniatures-caret"></i>
    </button>
    <div class="admin-miniatures-toolbar-spacer">
        <span class="admin-miniatures-count"><?= number_format($admin_total) ?> resultado<?= $admin_total !== 1 ? 's' : '' ?></span>
        <?php
        $view_grid_qs = $admin_qs; unset($view_grid_qs['view']);
        $view_list_qs = $admin_qs; $view_list_qs['view'] = 'list';
        $view_grid_url = '/admin/miniatures?' . http_build_query(['action' => 'list'] + $view_grid_qs);
        $view_list_url = '/admin/miniatures?' . http_build_query(['action' => 'list'] + $view_list_qs);
        ?>
        <div class="admin-miniatures-viewtoggle" role="group" aria-label="Modo de visualização">
            <a href="<?= e($view_grid_url) ?>" class="admin-miniatures-viewbtn<?= $admin_view === 'grid' ? ' is-active' : '' ?>" title="Grade"><i class="fa fa-grip"></i></a>
            <a href="<?= e($view_list_url) ?>" class="admin-miniatures-viewbtn<?= $admin_view === 'list' ? ' is-active' : '' ?>" title="Lista"><i class="fa fa-list"></i></a>
        </div>
    </div>
</div>

<!-- Painel de filtros (colapsável; persiste via ?filters=1) -->
<div id="adminFilters" class="admin-miniatures-filters<?= $admin_open_filters ? ' is-open' : '' ?>">
    <form method="get" class="admin-miniatures-form">
        <input type="hidden" name="action" value="list">
        <input type="hidden" name="filters" id="hidFilters" value="1"<?= $admin_open_filters ? '' : ' disabled' ?>>
        <?php if ($admin_view === 'list'): ?><input type="hidden" name="view" value="list"><?php endif; ?>
        <div class="admin-miniatures-search">
            <i class="fa fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Buscar por nome, fabricante ou modelo..." value="<?= e($filters['search']) ?>">
        </div>
        <div class="admin-miniatures-controls">
            <select name="manufacturer" class="admin-miniatures-select">
                <option value="">Fabricante</option>
                <?php foreach ($manufacturers as $mf): ?>
                    <option value="<?= e($mf) ?>" <?= ($filters['manufacturer'] ?? '') === $mf ? 'selected' : '' ?>><?= e($mf) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="scale" class="admin-miniatures-select">
                <option value="">Escala</option>
                <?php foreach ($scales as $sc): ?>
                    <option value="<?= e($sc) ?>" <?= ($filters['scale'] ?? '') === $sc ? 'selected' : '' ?>><?= e($sc) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category_id" class="admin-miniatures-select">
                <option value="">Categoria</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= (int)($filters['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="condition" class="admin-miniatures-select">
                <option value="">Embalagem</option>
                <option value="sealed" <?= ($filters['condition'] ?? '') === 'sealed' ? 'selected' : '' ?>>Lacrada</option>
                <option value="open"   <?= ($filters['condition'] ?? '') === 'open'   ? 'selected' : '' ?>>Aberta</option>
                <option value="no_box" <?= ($filters['condition'] ?? '') === 'no_box' ? 'selected' : '' ?>>Sem caixa</option>
            </select>
            <select name="location" class="admin-miniatures-select">
                <option value="">Localização</option>
                <option value="storage" <?= ($filters['location'] ?? '') === 'storage' ? 'selected' : '' ?>>Armazenada</option>
                <option value="display" <?= ($filters['location'] ?? '') === 'display' ? 'selected' : '' ?>>Em exposição</option>
            </select>
            <select name="sort" class="admin-miniatures-select">
                <option value="" <?= ($filters['sort'] ?? '') === '' ? 'selected' : '' ?>>Mais relevantes</option>
                <option value="recent" <?= ($filters['sort'] ?? '') === 'recent' ? 'selected' : '' ?>>Mais recente</option>
                <option value="name" <?= ($filters['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Nome A–Z</option>
                <option value="manufacturer" <?= ($filters['sort'] ?? '') === 'manufacturer' ? 'selected' : '' ?>>Fabricante</option>
                <option value="year_desc" <?= ($filters['sort'] ?? '') === 'year_desc' ? 'selected' : '' ?>>Ano (novo→antigo)</option>
                <option value="year_asc" <?= ($filters['sort'] ?? '') === 'year_asc' ? 'selected' : '' ?>>Ano (antigo→novo)</option>
            </select>
            <button type="submit" class="md-btn md-btn-primary admin-miniatures-apply"><i class="fa fa-arrow-right"></i><span>Aplicar</span></button>
            <a href="/admin/miniatures" class="md-btn admin-miniatures-clear" title="Limpar"><i class="fa fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Grade / Lista -->
<form method="post" action="/admin/miniatures?action=bulk" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="return_page" value="<?= $admin_page ?>">

    <!-- Barra de ações em massa (oculta até haver seleção) -->
    <div id="bulkBar" class="admin-miniatures-bulk d-none">
        <span id="bulkCount" class="admin-miniatures-bulk-count"></span>
        <select name="bulk_action" class="admin-miniatures-select">
            <option value="">Escolher ação…</option>
            <optgroup label="Visibilidade">
                <option value="make_public">Tornar pública</option>
                <option value="make_private">Tornar privada</option>
            </optgroup>
            <optgroup label="Destaque">
                <option value="feature">Destacar</option>
                <option value="unfeature">Remover destaque</option>
            </optgroup>
            <optgroup label="Embalagem">
                <option value="status_sealed">Lacrada</option>
                <option value="status_open">Aberta</option>
                <option value="status_no_box">Sem caixa</option>
            </optgroup>
            <optgroup label="Localização">
                <option value="status_display">Em exposição</option>
                <option value="status_storage">Em armazenamento</option>
            </optgroup>
        </select>
        <button type="submit" class="md-btn md-btn-primary" onclick="return confirm('Aplicar à seleção?')">Aplicar</button>
        <button type="button" class="md-btn" onclick="clearSelection()">Cancelar</button>
    </div>

    <div class="admin-miniatures-selectall">
        <label><input type="checkbox" class="form-check-input" id="checkAll"> Selecionar todos</label>
    </div>

    <?php if (empty($miniatures)): ?>
        <div class="admin-miniatures-empty">
            <i class="fa fa-car-side"></i>
            <p class="admin-miniatures-empty-title"><?= $admin_active_filters ? 'Nenhuma miniatura encontrada' : 'Sua garagem está vazia' ?></p>
            <p class="admin-miniatures-empty-sub"><?= $admin_active_filters ? 'Tente ajustar os filtros ou limpar a busca.' : 'Comece adicionando sua primeira peça.' ?></p>
            <?php if ($admin_active_filters): ?>
                <a href="/admin/miniatures" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar filtros</a>
            <?php else: ?>
                <a href="/admin/miniatures?action=add" class="md-btn md-btn-primary"><i class="fa fa-plus"></i>Adicionar miniatura</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="admin-miniatures-<?= $admin_view === 'list' ? 'list' : 'grid' ?>">
            <?php foreach ($miniatures as $m) { $render_item($m); } ?>
        </div>
    <?php endif; ?>
</form>

<?php if ($admin_total_pages > 1): ?>
<nav class="mt-4" aria-label="Paginação">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <li class="page-item <?= $admin_page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" href="<?= e($pg_base . '&page=' . ($admin_page - 1)) ?>">&laquo;</a>
        </li>
        <?php foreach (pagination_range($admin_page, $admin_total_pages) as $p): ?>
            <?php if ($p === null): ?>
                <li class="page-item disabled"><span class="page-link bg-dark border-secondary text-secondary">&hellip;</span></li>
            <?php else: ?>
                <li class="page-item <?= $p === $admin_page ? 'active' : '' ?>">
                    <a class="page-link <?= $p === $admin_page ? 'bg-warning border-warning text-dark' : 'bg-dark border-secondary text-light' ?>"
                       href="<?= e($pg_base . '&page=' . $p) ?>"><?= $p ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $admin_page >= $admin_total_pages ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" href="<?= e($pg_base . '&page=' . ($admin_page + 1)) ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<script>
(function () {
    // ── Painel de filtros (colapso + persistência) ───────────────────────────
    const btnFilters = document.getElementById('btnFilters');
    const panel      = document.getElementById('adminFilters');
    const hidFilters = document.getElementById('hidFilters');
    if (btnFilters && panel) {
        btnFilters.addEventListener('click', () => {
            const open = panel.classList.toggle('is-open');
            btnFilters.classList.toggle('is-open', open);
            btnFilters.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (hidFilters) hidFilters.disabled = !open;
        });
    }

    // ── Featured toggle ──────────────────────────────────────────────────────
    document.querySelectorAll('.toggle-featured-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = btn.dataset.id;
            const icon = btn.querySelector('i');
            const fd   = new FormData();
            fd.append('id', id);
            fd.append('csrf_token', document.querySelector('input[name=csrf_token]')?.value ?? '');
            const res  = await fetch('/admin/miniatures?action=toggle_featured', {method:'POST', body:fd});
            const data = await res.json();
            if (data.ok) {
                btn.dataset.featured = data.is_featured;
                btn.title = data.is_featured ? 'Remover destaque' : 'Destacar';
                icon.className = 'fa fa-star ' + (data.is_featured ? 'text-warning' : '');
                btn.closest('.admin-miniatures-card')?.classList.toggle('is-featured', !!data.is_featured);
            }
        });
    });

    // ── Bulk selection ───────────────────────────────────────────────────────
    const checkAll = document.getElementById('checkAll');
    const bar      = document.getElementById('bulkBar');
    const countEl  = document.getElementById('bulkCount');

    function getChecked() {
        return [...document.querySelectorAll('.row-check:checked')];
    }
    function updateBar() {
        const checked = getChecked();
        if (checked.length > 0) {
            bar.classList.remove('d-none');
            countEl.textContent = checked.length + ' selecionada' + (checked.length !== 1 ? 's' : '');
        } else {
            bar.classList.add('d-none');
        }
    }
    function clearSelection() {
        document.querySelectorAll('.row-check').forEach(c => c.checked = false);
        if (checkAll) checkAll.checked = false;
        updateBar();
    }
    window.clearSelection = clearSelection;

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(c => c.checked = checkAll.checked);
            updateBar();
        });
    }
    document.querySelectorAll('.row-check').forEach(c => {
        c.addEventListener('change', () => {
            if (checkAll) checkAll.checked = getChecked().length === document.querySelectorAll('.row-check').length;
            updateBar();
        });
    });
})();
</script>

<?php elseif ($action === 'view'): ?>
<!-- VIEW DETAIL -->
<?php
    $m  = $view_mini;
    $primary_photo = null;
    foreach ($view_photos as $vp) { if ($vp['is_primary']) { $primary_photo = $vp; break; } }
    if (!$primary_photo && !empty($view_photos)) $primary_photo = $view_photos[0];
    $appreciation = null;
    if ($m['purchase_price'] > 0 && $m['estimated_price'] > 0) {
        $appreciation = (($m['estimated_price'] - $m['purchase_price']) / $m['purchase_price']) * 100;
    }
    $pub_rating = get_public_rating($view_id);
?>
<div class="d-flex align-items-center mb-4 gap-2 flex-wrap">
    <a href="/admin/miniatures" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i></a>
    <h1 class="h4 mb-0 ms-2 me-auto"><i class="fa fa-circle-info me-2 text-info"></i><?= e($m['name']) ?></h1>
    <a href="/admin/miniatures?action=edit&id=<?= $m['id'] ?>" class="btn btn-warning btn-sm">
        <i class="fa fa-edit me-1"></i>Editar
    </a>
    <?php if ($m['is_public']): ?>
    <a href="<?= e(mini_url($m)) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-external-link me-1"></i>Ver no site
    </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Fotos -->
    <div class="col-12 col-lg-5">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body">
                <?php if ($primary_photo): ?>
                    <img src="<?= e(photo_url($primary_photo['file_path'])) ?>"
                         alt="<?= e($m['name']) ?>"
                         class="img-fluid rounded mb-3"
                         style="width:100%;max-height:320px;object-fit:contain;background:#000;">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center rounded mb-3 text-secondary"
                         style="height:200px;background:#111;">
                        <i class="fa fa-image fa-3x opacity-25"></i>
                    </div>
                <?php endif; ?>
                <?php if (count($view_photos) > 1): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($view_photos as $vp): ?>
                            <img src="<?= e(thumb_url($vp['file_path'])) ?>"
                                 alt=""
                                 class="rounded <?= $vp['is_primary'] ? 'border border-warning border-2' : 'border border-secondary' ?>"
                                 style="width:60px;height:60px;object-fit:cover;">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Detalhes -->
    <div class="col-12 col-lg-7">
        <div class="row g-3">
            <!-- Identidade -->
            <div class="col-12">
                <div class="card bg-dark border-secondary">
                    <div class="card-header border-secondary text-warning small fw-semibold">
                        <i class="fa fa-tag me-1"></i>Identificação
                    </div>
                    <div class="card-body">
                        <div class="row g-2 small">
                            <div class="col-6"><span class="text-secondary">Fabricante</span><br><span class="text-light"><?= e($m['manufacturer']) ?></span></div>
                            <div class="col-6"><span class="text-secondary">Modelo</span><br><span class="text-light"><?= e($m['model'] ?? '—') ?></span></div>
                            <div class="col-4"><span class="text-secondary">Escala</span><br><span class="text-light"><?= e($m['scale'] ?? '—') ?></span></div>
                            <div class="col-4"><span class="text-secondary">Ano</span><br><span class="text-light"><?= $m['year'] ?? '—' ?></span></div>
                            <div class="col-4"><span class="text-secondary">Categoria</span><br><span class="text-light"><?= e($m['category_name'] ?? '—') ?></span></div>
                            <div class="col-4"><span class="text-secondary">Embalagem</span><br><?= condition_badge($m['condition'] ?? 'sealed') ?></div>
                            <div class="col-4"><span class="text-secondary">Localização</span><br><?= location_badge($m['location'] ?? 'storage') ?></div>
                            <div class="col-4">
                                <span class="text-secondary">Visibilidade</span><br>
                                <?php if ($m['is_public']): ?>
                                    <span class="badge bg-success"><i class="fa fa-eye me-1"></i>Pública</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fa fa-eye-slash me-1"></i>Privada</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-4">
                                <span class="text-secondary">Visualizações</span><br>
                                <span class="text-info fw-bold"><i class="fa fa-eye me-1"></i><?= (int)($m['views'] ?? 0) ?></span>
                            </div>
                            <div class="col-4">
                                <span class="text-secondary">Avaliação pública</span><br>
                                <?php if ($pub_rating['count'] > 0): ?>
                                    <?php for ($s=1;$s<=5;$s++): ?>
                                        <i class="fa fa-star fa-sm <?= $pub_rating['avg'] >= $s ? 'text-warning' : 'text-secondary opacity-25' ?>"></i>
                                    <?php endfor; ?>
                                    <span class="text-secondary small ms-1"><?= number_format($pub_rating['avg'],1,',','') ?> (<?= $pub_rating['count'] ?>)</span>
                                <?php else: ?>
                                    <span class="text-secondary small">Sem avaliações</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($view_tags)): ?>
                            <div class="col-12">
                                <span class="text-secondary">Tags</span><br>
                                <?php foreach ($view_tags as $t): ?>
                                    <span class="badge bg-secondary me-1"><?= e($t['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($m['emotional_rating']): ?>
                            <div class="col-12">
                                <span class="text-secondary">Avaliação</span><br>
                                <?= emotional_rating_badge((int)$m['emotional_rating']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financeiro -->
            <div class="col-12">
                <div class="card bg-dark border-secondary">
                    <div class="card-header border-secondary text-warning small fw-semibold">
                        <i class="fa fa-lock me-1"></i>Financeiro
                    </div>
                    <div class="card-body">
                        <div class="row g-2 small">
                            <div class="col-6"><span class="text-secondary">Valor pago</span><br>
                                <span class="text-light"><?= $m['purchase_price'] !== null ? 'R$ ' . number_format($m['purchase_price'],2,',','.') : '—' ?></span>
                            </div>
                            <div class="col-6"><span class="text-secondary">Valor estimado</span><br>
                                <span class="text-light"><?= $m['estimated_price'] !== null ? 'R$ ' . number_format($m['estimated_price'],2,',','.') : '—' ?></span>
                            </div>
                            <?php if ($appreciation !== null): ?>
                            <div class="col-6"><span class="text-secondary">Valorização</span><br>
                                <span class="fw-bold <?= $appreciation >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $appreciation >= 0 ? '+' : '' ?><?= number_format($appreciation, 1) ?>%
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="col-6"><span class="text-secondary">Data da compra</span><br>
                                <span class="text-light"><?= $m['purchase_date'] ? date('d/m/Y', strtotime($m['purchase_date'])) : '—' ?></span>
                            </div>
                            <?php if ($m['purchase_location']): ?>
                            <div class="col-12"><span class="text-secondary">Local da compra</span><br>
                                <span class="text-light"><?= e($m['purchase_location']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Descrições -->
    <?php if ($m['public_description'] || $m['private_story'] || $m['private_notes']): ?>
    <div class="col-12">
        <div class="row g-3">
            <?php if ($m['public_description']): ?>
            <div class="col-12 col-md-4">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-header border-secondary text-secondary small">Descrição pública</div>
                    <div class="card-body small text-light" style="white-space:pre-wrap"><?= e($m['public_description']) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($m['private_story']): ?>
            <div class="col-12 col-md-4">
                <div class="card bg-dark border-warning h-100">
                    <div class="card-header border-warning text-warning small"><i class="fa fa-lock me-1"></i>História</div>
                    <div class="card-body small text-light" style="white-space:pre-wrap"><?= e($m['private_story']) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($m['private_notes']): ?>
            <div class="col-12 col-md-4">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-header border-secondary text-secondary small"><i class="fa fa-lock me-1"></i>Notas privadas</div>
                    <div class="card-body small text-light" style="white-space:pre-wrap"><?= e($m['private_notes']) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- ADD / EDIT FORM -->
<div class="d-flex align-items-center mb-3 gap-2">
    <a href="/admin/miniatures" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i></a>
    <h1 class="h4 mb-0 ms-2">
        <?= $editing ? '<i class="fa fa-edit me-2 text-warning"></i>Editar: ' . e($editing['name']) : '<i class="fa fa-plus me-2 text-warning"></i>Nova Miniatura' ?>
    </h1>
</div>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
    <input type="hidden" name="return_page" value="<?= max(1, (int)($_GET['return_page'] ?? 1)) ?>">

    <div class="row g-4">
        <!-- Left column -->
        <div class="col-12 col-lg-8">

            <!-- Basic Info -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">Informações Básicas</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label text-secondary">Nome *</label>
                            <input type="text" name="name" class="form-control bg-dark text-light border-secondary"
                                   required value="<?= $editing ? e($editing['name']) : '' ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Fabricante *</label>
                            <input type="text" name="manufacturer" list="manufacturers-list"
                                   class="form-control bg-dark text-light border-secondary"
                                   required value="<?= $editing ? e($editing['manufacturer']) : '' ?>">
                            <datalist id="manufacturers-list">
                                <?php foreach ($manufacturers as $mfr): ?>
                                    <option value="<?= e($mfr) ?>">
                                <?php endforeach; ?>
                                <option value="Hot Wheels">
                                <option value="Mini GT">
                                <option value="Kaido House">
                                <option value="Pop Race">
                                <option value="M2 Machines">
                                <option value="Johnny Lightning">
                                <option value="Majorette">
                                <option value="Greenlight">
                                <option value="Auto World">
                                <option value="Bburago">
                            </datalist>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Modelo</label>
                            <input type="text" name="model" class="form-control bg-dark text-light border-secondary"
                                   value="<?= $editing ? e($editing['model'] ?? '') : '' ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label text-secondary">Escala</label>
                            <input type="text" name="scale" list="scales-list"
                                   class="form-control bg-dark text-light border-secondary"
                                   placeholder="1:64"
                                   value="<?= $editing ? e($editing['scale'] ?? '') : '' ?>">
                            <datalist id="scales-list">
                                <option value="1:64">
                                <option value="1:43">
                                <option value="1:18">
                                <option value="1:24">
                                <option value="1:32">
                                <?php foreach ($scales as $sc): ?>
                                    <option value="<?= e($sc) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label text-secondary">Ano</label>
                            <input type="number" name="year" class="form-control bg-dark text-light border-secondary"
                                   min="1950" max="<?= date('Y') + 1 ?>"
                                   value="<?= $editing ? e((string)($editing['year'] ?? '')) : '' ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Categoria</label>
                            <select name="category_id" class="form-select bg-dark text-light border-secondary">
                                <option value="">Sem categoria</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"
                                        <?= $editing && (int)$editing['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Embalagem</label>
                            <select name="condition" class="form-select bg-dark text-light border-secondary">
                                <option value="sealed" <?= ($editing ? $editing['condition'] : 'sealed') === 'sealed' ? 'selected' : '' ?>>Lacrada</option>
                                <option value="open"   <?= ($editing ? $editing['condition'] : 'sealed') === 'open'   ? 'selected' : '' ?>>Aberta</option>
                                <option value="no_box" <?= ($editing ? $editing['condition'] : 'sealed') === 'no_box' ? 'selected' : '' ?>>Sem caixa</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label text-secondary">Localização</label>
                            <select name="location" class="form-select bg-dark text-light border-secondary">
                                <option value="storage" <?= ($editing ? $editing['location'] : 'storage') === 'storage' ? 'selected' : '' ?>>Em armazenamento</option>
                                <option value="display" <?= ($editing ? $editing['location'] : 'storage') === 'display' ? 'selected' : '' ?>>Em exposição</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_public" id="is_public" value="1"
                                       <?= (!$editing || $editing['is_public']) ? 'checked' : '' ?>>
                                <label class="form-check-label text-secondary" for="is_public">
                                    Visível no site público
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" value="1"
                                       <?= ($editing && $editing['is_featured']) ? 'checked' : '' ?>>
                                <label class="form-check-label text-secondary" for="is_featured">
                                    <i class="fa fa-star text-warning me-1"></i>Destacar (aparece primeiro)
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label text-secondary">Ordem de exibição</label>
                            <input type="number" name="sort_order" min="0"
                                   class="form-control bg-dark text-light border-secondary"
                                   value="<?= $editing ? (int)($editing['sort_order'] ?? 9999) : 9999 ?>"
                                   title="Número menor aparece antes (0 = padrão)">
                            <small class="text-secondary">Menor número = aparece antes</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Public info -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">Informações Públicas</div>
                <div class="card-body">
                    <label class="form-label text-secondary">Descrição pública</label>
                    <textarea name="public_description" rows="3"
                              class="form-control bg-dark text-light border-secondary"><?= $editing ? e($editing['public_description'] ?? '') : '' ?></textarea>
                </div>
            </div>

            <!-- Private info -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary"><i class="fa fa-lock me-1 text-warning"></i>Informações Privadas</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary">História da peça</label>
                        <textarea name="private_story" rows="3"
                                  class="form-control bg-dark text-light border-secondary"><?= $editing ? e($editing['private_story'] ?? '') : '' ?></textarea>
                    </div>
                    <div>
                        <label class="form-label text-secondary">Observações pessoais</label>
                        <textarea name="private_notes" rows="2"
                                  class="form-control bg-dark text-light border-secondary"><?= $editing ? e($editing['private_notes'] ?? '') : '' ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Photos -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">Fotos</div>
                <div class="card-body">
                    <?php if (!empty($edit_photos)): ?>
                        <p class="text-secondary small mb-2"><i class="fa fa-grip-vertical me-1"></i>Arraste para reordenar.</p>
                        <div class="d-flex flex-wrap gap-3 mb-3" id="sortable-photos" data-miniature-id="<?= $editing['id'] ?>">
                            <?php foreach ($edit_photos as $ph): ?>
                                <div class="position-relative photo-thumb-admin" data-photo-id="<?= $ph['id'] ?>" style="cursor:grab;width:150px;">
                                    <div class="rounded overflow-hidden <?= $ph['is_primary'] ? 'border border-warning border-2' : 'border border-secondary' ?>"
                                         style="width:150px;height:150px;background:#000;display:flex;align-items:center;justify-content:center;">
                                        <img src="<?= e(photo_url($ph['file_path'])) ?>"
                                             alt=""
                                             class="photo-admin-img"
                                             style="max-width:150px;max-height:150px;object-fit:contain;pointer-events:none;display:block;">
                                    </div>
                                    <div class="d-flex gap-1 mt-1">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info flex-grow-1 rotate-btn"
                                                data-photo-id="<?= $ph['id'] ?>"
                                                data-miniature-id="<?= $editing['id'] ?>"
                                                title="Girar 90°">
                                            <i class="fa fa-rotate-right"></i>
                                        </button>
                                        <?php if ($ph['is_primary']): ?>
                                            <span class="btn btn-sm btn-warning flex-grow-1 disabled pe-none">
                                                <i class="fa fa-star"></i>
                                            </span>
                                        <?php else: ?>
                                            <button type="submit" name="primary_photo_id" value="<?= $ph['id'] ?>"
                                                    class="btn btn-sm btn-outline-warning flex-grow-1" title="Definir como principal">
                                                <i class="fa fa-star"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="submit" name="delete_photo_id" value="<?= $ph['id'] ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Remover esta foto?')" title="Remover foto">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <label class="form-label text-secondary">Adicionar fotos</label>
                    <input type="file" name="photos[]" multiple accept="image/*"
                           class="form-control bg-dark text-light border-secondary">
                    <small class="text-secondary">Múltiplos arquivos. Máximo 10 MB por foto. JPEG, PNG, WebP ou GIF.</small>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-12 col-lg-4">
            <!-- Financial -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary"><i class="fa fa-lock me-1 text-warning"></i>Financeiro</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary">Valor pago (R$)</label>
                        <input type="number" step="0.01" min="0" name="purchase_price"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing && $editing['purchase_price'] !== null ? e(number_format((float)$editing['purchase_price'], 2, '.', '')) : '' ?>">
                    </div>
                    <div>
                        <label class="form-label text-secondary">Valor estimado (R$)</label>
                        <input type="number" step="0.01" min="0" name="estimated_price"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing && $editing['estimated_price'] !== null ? e(number_format((float)$editing['estimated_price'], 2, '.', '')) : '' ?>">
                    </div>
                </div>
            </div>

            <!-- Purchase info -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">Compra</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary">Data de compra</label>
                        <input type="date" name="purchase_date"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing ? e($editing['purchase_date'] ?? '') : '' ?>">
                    </div>
                    <div>
                        <label class="form-label text-secondary">Local de compra</label>
                        <input type="text" name="purchase_location"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing ? e($editing['purchase_location'] ?? '') : '' ?>">
                    </div>
                </div>
            </div>

            <!-- Emotional rating -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary"><i class="fa fa-heart me-1 text-warning"></i>Avaliação Emocional</div>
                <div class="card-body">
                    <?php
                    $rating_opts = [
                        1 => ['fa-circle',  'secondary', 'Pouco importante'],
                        2 => ['fa-heart',   'info',      'Gosto da peça'],
                        3 => ['fa-heart',   'success',   'Muito importante'],
                        4 => ['fa-gem',     'warning',   'Especial'],
                        5 => ['fa-lock',    'danger',    'Nunca vender'],
                    ];
                    foreach ($rating_opts as $r => [$icon, $color, $rlabel]):
                        $checked = $editing && (int)$editing['emotional_rating'] === $r ? 'checked' : '';
                    ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="emotional_rating"
                                   id="rating<?= $r ?>" value="<?= $r ?>" <?= $checked ?>>
                            <label class="form-check-label" for="rating<?= $r ?>">
                                <span class="badge bg-<?= $color ?>"><i class="fa <?= $icon ?> me-1"></i><?= $rlabel ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="radio" name="emotional_rating" id="ratingNone" value=""
                               <?= !$editing || !$editing['emotional_rating'] ? 'checked' : '' ?>>
                        <label class="form-check-label text-secondary" for="ratingNone"><i class="fa fa-minus me-1"></i>Não avaliada</label>
                    </div>
                </div>
            </div>

            <!-- Tags -->
            <div class="card bg-dark border-secondary mb-4">
                <div class="card-header border-secondary">Tags</div>
                <div class="card-body" style="max-height:200px;overflow-y:auto">
                    <?php foreach ($tags as $tag): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                   name="tags[]" id="tag<?= $tag['id'] ?>" value="<?= $tag['id'] ?>"
                                   <?= in_array($tag['id'], $edit_tags) ? 'checked' : '' ?>>
                            <label class="form-check-label text-secondary" for="tag<?= $tag['id'] ?>"><?= e($tag['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-warning w-100">
                <i class="fa fa-save me-1"></i><?= $editing ? 'Salvar alterações' : 'Adicionar miniatura' ?>
            </button>
            <a href="/admin/miniatures" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
