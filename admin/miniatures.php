<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$action = $_GET['action'] ?? 'list';

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
            'status_open'   => db()->prepare("UPDATE miniatures SET status = 'open'    WHERE id IN ($placeholders)")->execute($ids),
            'status_sealed' => db()->prepare("UPDATE miniatures SET status = 'sealed'  WHERE id IN ($placeholders)")->execute($ids),
            'status_display'=> db()->prepare("UPDATE miniatures SET status = 'display' WHERE id IN ($placeholders)")->execute($ids),
            'status_storage'=> db()->prepare("UPDATE miniatures SET status = 'storage' WHERE id IN ($placeholders)")->execute($ids),
            default         => null,
        };
        flash(count($ids) . ' miniatura(s) atualizadas.');
    }
    redirect('/admin/miniatures.php');
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
        'status'            => $_POST['status'] ?? 'sealed',
        'public_description'=> trim($_POST['public_description'] ?? '') ?: null,
        'private_story'     => trim($_POST['private_story'] ?? '') ?: null,
        'private_notes'     => trim($_POST['private_notes'] ?? '') ?: null,
        'purchase_price'    => strlen(trim($_POST['purchase_price'] ?? '')) ? parse_decimal($_POST['purchase_price']) : null,
        'estimated_price'   => strlen(trim($_POST['estimated_price'] ?? '')) ? parse_decimal($_POST['estimated_price']) : null,
        'purchase_date'     => trim($_POST['purchase_date'] ?? '') ?: null,
        'purchase_location' => trim($_POST['purchase_location'] ?? '') ?: null,
        'emotional_rating'  => (int) ($_POST['emotional_rating'] ?? 0) ?: null,
        'is_public'         => isset($_POST['is_public']) ? 1 : 0,
    ];

    if (!$data['name'] || !$data['manufacturer']) {
        flash('Nome e fabricante são obrigatórios.', 'danger');
        redirect('/admin/miniatures?action=' . ($id ? 'edit&id=' . $id : 'add'));
    }

    if ($id) {
        // Update
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $data['id'] = $id;
        db()->prepare("UPDATE miniatures SET $sets WHERE id = :id")->execute($data);
        $miniature_id = $id;
        flash('Miniatura atualizada com sucesso.');
    } else {
        // Insert
        $cols = implode(', ', array_keys($data));
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

    redirect('/admin/miniatures?action=edit&id=' . $miniature_id);
}

// ─── LIST ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $filters = [
        'search'       => trim($_GET['search'] ?? ''),
        'manufacturer' => trim($_GET['manufacturer'] ?? ''),
        'category_id'  => (int) ($_GET['category_id'] ?? 0) ?: null,
        'status'       => trim($_GET['status'] ?? ''),
        'is_public'    => null, // admin sees all
    ];

    $admin_per_page   = PER_PAGE;
    $admin_page       = max(1, (int) ($_GET['page'] ?? 1));
    $admin_total      = count_miniatures($filters);
    $admin_total_pages = (int) ceil($admin_total / $admin_per_page);
    $admin_page       = min($admin_page, max(1, $admin_total_pages));

    $miniatures = get_miniatures($filters + ['page' => $admin_page, 'per_page' => $admin_per_page]);
} else {
    $filters    = [];
    $miniatures = [];
    $admin_page = 1;
    $admin_total = 0;
    $admin_total_pages = 1;
}

$categories    = get_categories();
$tags          = get_tags();
$manufacturers = get_distinct_manufacturers();

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
<!-- LIST VIEW -->
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <h1 class="h4 mb-0 me-auto"><i class="fa fa-car me-2 text-warning"></i>Miniaturas</h1>
    <span class="text-secondary small"><?= $admin_total ?> peça<?= $admin_total !== 1 ? 's' : '' ?></span>
    <a href="/admin/miniatures?action=add" class="btn btn-warning btn-sm">
        <i class="fa fa-plus me-1"></i>Adicionar
    </a>
</div>

<!-- Filters -->
<form method="get" class="mb-3">
    <input type="hidden" name="action" value="list">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <input type="search" name="search" class="form-control form-control-sm bg-dark text-light border-secondary"
                   placeholder="Buscar..." value="<?= e($filters['search']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <select name="manufacturer" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Fabricante</option>
                <?php foreach ($manufacturers as $m): ?>
                    <option value="<?= e($m) ?>" <?= $filters['manufacturer'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="category_id" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Categoria</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= (int)($filters['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Status</option>
                <option value="open" <?= $filters['status'] === 'open' ? 'selected' : '' ?>>Aberta</option>
                <option value="sealed" <?= $filters['status'] === 'sealed' ? 'selected' : '' ?>>Lacrada</option>
                <option value="display" <?= $filters['status'] === 'display' ? 'selected' : '' ?>>Em exposição</option>
                <option value="storage" <?= $filters['status'] === 'storage' ? 'selected' : '' ?>>Em armazenamento</option>
            </select>
        </div>
        <div class="col-6 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-warning btn-sm flex-grow-1"><i class="fa fa-search"></i></button>
            <a href="/admin/miniatures" class="btn btn-outline-secondary btn-sm"><i class="fa fa-times"></i></a>
        </div>
    </div>
</form>

<div class="table-responsive">
<form method="post" action="/admin/miniatures?action=bulk" id="bulkForm">
    <?= csrf_field() ?>
    <!-- Bulk toolbar (hidden until selection) -->
    <div id="bulkBar" class="d-none mb-2 p-2 rounded border border-warning d-flex align-items-center gap-2 flex-wrap"
         style="background:rgba(255,193,7,.07)">
        <span id="bulkCount" class="text-warning fw-semibold small"></span>
        <select name="bulk_action" class="form-select form-select-sm bg-dark text-light border-secondary" style="width:auto;">
            <option value="">Escolher ação…</option>
            <optgroup label="Visibilidade">
                <option value="make_public">Tornar pública</option>
                <option value="make_private">Tornar privada</option>
            </optgroup>
            <optgroup label="Status">
                <option value="status_open">Aberta</option>
                <option value="status_sealed">Lacrada</option>
                <option value="status_display">Em exposição</option>
                <option value="status_storage">Em armazenamento</option>
            </optgroup>
        </select>
        <button type="submit" class="btn btn-warning btn-sm"
                onclick="return confirm('Aplicar à seleção?')">Aplicar</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">Cancelar</button>
    </div>
    <table class="table table-dark table-hover table-sm align-middle">
        <thead>
            <tr>
                <th style="width:36px">
                    <input type="checkbox" class="form-check-input" id="checkAll" title="Selecionar todos">
                </th>
                <th style="width:60px"></th>
                <th>Nome</th>
                <th>Fabricante</th>
                <th>Escala</th>
                <th>Status</th>
                <th>Visível</th>
                <th class="text-end">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($miniatures)): ?>
                <tr><td colspan="8" class="text-center text-secondary py-4">Nenhuma miniatura cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($miniatures as $m): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= $m['id'] ?>"></td>
                    <td>
                        <img src="<?= e(thumb_url($m['primary_photo'])) ?>"
                             data-fallback="<?= e(photo_url($m['primary_photo'])) ?>"
                             alt=""
                             style="width:50px;height:40px;object-fit:cover;border-radius:4px;">
                    </td>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['manufacturer']) ?></td>
                    <td><?= e($m['scale'] ?? '—') ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td>
                        <?php if ($m['is_public']): ?>
                            <span class="badge bg-success"><i class="fa fa-eye"></i></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="fa fa-eye-slash"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="/admin/miniatures?action=edit&id=<?= $m['id'] ?>" class="btn btn-outline-warning btn-sm">
                            <i class="fa fa-edit"></i>
                        </a>
                        <a href="<?= e(mini_url($m)) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="/admin/miniatures?action=delete&id=<?= $m['id'] ?>"
                           class="btn btn-outline-danger btn-sm"
                           onclick="return confirm('Remover esta miniatura?')">
                            <i class="fa fa-trash"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</form>
</div>

<?php if ($admin_total_pages > 1):
    $qs_parts = ['action' => 'list'];
    foreach (['search','manufacturer','category_id','status'] as $k) {
        if (!empty($filters[$k])) $qs_parts[$k] = $filters[$k];
    }
    $qs_base = '&' . http_build_query(array_diff_key($qs_parts, ['action' => '']));
?>
<nav class="mt-3" aria-label="Paginação">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <li class="page-item <?= $admin_page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light"
               href="?action=list&page=<?= $admin_page - 1 . $qs_base ?>">&laquo;</a>
        </li>
        <?php for ($p = 1; $p <= $admin_total_pages; $p++): ?>
            <li class="page-item <?= $p === $admin_page ? 'active' : '' ?>">
                <a class="page-link <?= $p === $admin_page ? 'bg-warning border-warning text-dark' : 'bg-dark border-secondary text-light' ?>"
                   href="?action=list&page=<?= $p . $qs_base ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $admin_page >= $admin_total_pages ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light"
               href="?action=list&page=<?= $admin_page + 1 . $qs_base ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<script>
(function () {
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
            bar.classList.add('d-flex');
            countEl.textContent = checked.length + ' selecionada' + (checked.length !== 1 ? 's' : '');
        } else {
            bar.classList.add('d-none');
            bar.classList.remove('d-flex');
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
                            <label class="form-label text-secondary">Status</label>
                            <select name="status" class="form-select bg-dark text-light border-secondary">
                                <?php foreach (['open' => 'Aberta', 'sealed' => 'Lacrada', 'display' => 'Em exposição', 'storage' => 'Em armazenamento'] as $val => $label): ?>
                                    <option value="<?= $val ?>"
                                        <?= ($editing ? $editing['status'] : 'sealed') === $val ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
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
                        <div class="d-flex flex-wrap gap-2 mb-3" id="sortable-photos" data-miniature-id="<?= $editing['id'] ?>">
                            <?php foreach ($edit_photos as $ph): ?>
                                <div class="position-relative photo-thumb-admin" data-photo-id="<?= $ph['id'] ?>" style="cursor:grab">
                                    <img src="<?= e(photo_url($ph['file_path'])) ?>"
                                         alt=""
                                         class="rounded <?= $ph['is_primary'] ? 'border border-warning border-2' : '' ?>"
                                         style="width:80px;height:80px;object-fit:cover;pointer-events:none;">
                                    <?php if ($ph['is_primary']): ?>
                                        <span class="badge bg-warning text-dark position-absolute bottom-0 start-0" style="font-size:.6rem">Principal</span>
                                    <?php else: ?>
                                        <button type="submit" name="primary_photo_id" value="<?= $ph['id'] ?>"
                                                class="btn btn-xs btn-outline-warning position-absolute bottom-0 start-0"
                                                style="font-size:.55rem;padding:1px 3px;" title="Definir como principal">★</button>
                                    <?php endif; ?>
                                    <button type="submit" name="delete_photo_id" value="<?= $ph['id'] ?>"
                                            class="btn btn-xs btn-danger position-absolute top-0 end-0"
                                            style="font-size:.55rem;padding:1px 4px;line-height:1;"
                                            onclick="return confirm('Remover esta foto?')" title="Remover foto">✕</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <label class="form-label text-secondary">Adicionar fotos</label>
                    <input type="file" name="photos[]" multiple accept="image/*"
                           class="form-control bg-dark text-light border-secondary">
                    <small class="text-secondary">Múltiplos arquivos. Máximo 5 MB por foto. JPEG, PNG, WebP ou GIF.</small>
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
                <div class="card-header border-secondary"><i class="fa fa-lock me-1 text-warning"></i>Avaliação Emocional</div>
                <div class="card-body">
                    <?php for ($r = 1; $r <= 5; $r++): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="emotional_rating"
                                   id="rating<?= $r ?>" value="<?= $r ?>"
                                   <?= $editing && (int)$editing['emotional_rating'] === $r ? 'checked' : '' ?>>
                            <label class="form-check-label text-secondary" for="rating<?= $r ?>">
                                <?= $r ?> — <?= h(emotional_rating_label($r)) ?>
                            </label>
                        </div>
                    <?php endfor; ?>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="radio" name="emotional_rating" id="ratingNone" value=""
                               <?= !$editing || !$editing['emotional_rating'] ? 'checked' : '' ?>>
                        <label class="form-check-label text-secondary" for="ratingNone">Não avaliada</label>
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
