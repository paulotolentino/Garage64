<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$filters = [
    'manufacturer' => trim($_GET['manufacturer'] ?? ''),
    'scale'        => trim($_GET['scale'] ?? ''),
    'category_id'  => (int) ($_GET['category_id'] ?? 0) ?: null,
    'status'       => trim($_GET['status'] ?? ''),
    'search'       => trim($_GET['search'] ?? ''),
    'tag_id'       => (int) ($_GET['tag_id'] ?? 0) ?: null,
];

$miniatures    = get_miniatures($filters);
$categories    = get_categories();
$manufacturers = get_distinct_manufacturers();
$scales        = get_distinct_scales();
$tags          = get_tags();
$page_title    = 'Coleção';

require_once __DIR__ . '/includes/header_public.php';
?>

<div class="d-flex align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0 me-auto"><i class="fa fa-car me-2 text-warning"></i>Coleção de Miniaturas</h1>
    <span class="badge bg-secondary fs-6"><?= count($miniatures) ?> peça<?= count($miniatures) !== 1 ? 's' : '' ?></span>
</div>

<!-- Filters -->
<form method="get" class="card bg-dark border-secondary mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <input type="search" name="search" class="form-control form-control-sm bg-dark text-light border-secondary"
                       placeholder="Buscar por nome, fabricante ou modelo..."
                       value="<?= e($filters['search']) ?>">
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
                <select name="scale" class="form-select form-select-sm bg-dark text-light border-secondary">
                    <option value="">Escala</option>
                    <?php foreach ($scales as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filters['scale'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
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
            <div class="col-6 col-md-1">
                <select name="status" class="form-select form-select-sm bg-dark text-light border-secondary">
                    <option value="">Status</option>
                    <option value="open" <?= $filters['status'] === 'open' ? 'selected' : '' ?>>Aberta</option>
                    <option value="sealed" <?= $filters['status'] === 'sealed' ? 'selected' : '' ?>>Lacrada</option>
                    <option value="display" <?= $filters['status'] === 'display' ? 'selected' : '' ?>>Exposição</option>
                    <option value="storage" <?= $filters['status'] === 'storage' ? 'selected' : '' ?>>Armazenada</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-warning btn-sm flex-grow-1"><i class="fa fa-search"></i></button>
                <a href="/" class="btn btn-outline-secondary btn-sm"><i class="fa fa-times"></i></a>
            </div>
        </div>
        <?php if (!empty($tags)): ?>
        <div class="mt-2 d-flex flex-wrap gap-1">
            <a href="/" class="badge <?= !$filters['tag_id'] ? 'bg-warning text-dark' : 'bg-secondary' ?> text-decoration-none">Todas</a>
            <?php foreach ($tags as $tag): ?>
                <a href="/?tag_id=<?= $tag['id'] ?>"
                   class="badge <?= (int)($filters['tag_id'] ?? 0) === (int)$tag['id'] ? 'bg-warning text-dark' : 'bg-secondary' ?> text-decoration-none">
                    <?= e($tag['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Grid -->
<?php if (empty($miniatures)): ?>
    <div class="text-center text-secondary py-5">
        <i class="fa fa-car fa-3x mb-3 opacity-25"></i>
        <p class="h5">Nenhuma miniatura encontrada.</p>
    </div>
<?php else: ?>
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
        <?php foreach ($miniatures as $mini): ?>
            <div class="col">
                <a href="/miniature.php?id=<?= $mini['id'] ?>" class="text-decoration-none">
                    <div class="card h-100 mini-card bg-dark border-secondary">
                        <div class="mini-card-img-wrap">
                            <img src="<?= e(photo_url($mini['primary_photo'])) ?>"
                                 alt="<?= e($mini['name']) ?>"
                                 class="card-img-top mini-thumb"
                                 loading="lazy">
                        </div>
                        <div class="card-body p-2">
                            <div class="mini-manufacturer text-warning small mb-1"><?= e($mini['manufacturer']) ?></div>
                            <div class="mini-name text-light fw-semibold small"><?= e($mini['name']) ?></div>
                            <?php if ($mini['scale']): ?>
                                <div class="text-secondary" style="font-size:.75rem"><?= e($mini['scale']) ?></div>
                            <?php endif; ?>
                            <div class="mt-1"><?= status_badge($mini['status']) ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
