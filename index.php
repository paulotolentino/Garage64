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
    'sort'         => in_array($_GET['sort'] ?? '', ['name','manufacturer','year_asc','year_desc']) ? $_GET['sort'] : '',
    'is_public'    => 1,
];

$page       = max(1, (int) ($_GET['page'] ?? 1));
$per_page   = PER_PAGE;
$total      = count_miniatures($filters);
$total_pages = (int) ceil($total / $per_page);
$page       = min($page, max(1, $total_pages));

$miniatures    = get_miniatures($filters + ['page' => $page, 'per_page' => $per_page]);
$categories    = get_categories();
$manufacturers = get_distinct_manufacturers();
$scales        = get_distinct_scales();
$tags          = get_tags();
$page_title    = 'Coleção';

require_once __DIR__ . '/includes/header_public.php';
?>

<div class="d-flex align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0 me-auto"><i class="fa fa-car me-2 text-warning"></i>Coleção de Miniaturas</h1>
    <span class="badge bg-secondary fs-6"><?= $total ?> peça<?= $total !== 1 ? 's' : '' ?></span>
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
            <div class="col-6 col-md-2">
                <select name="sort" class="form-select form-select-sm bg-dark text-light border-secondary">
                    <option value="" <?= $filters['sort'] === '' ? 'selected' : '' ?>>Mais recente</option>
                    <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>Nome A–Z</option>
                    <option value="manufacturer" <?= $filters['sort'] === 'manufacturer' ? 'selected' : '' ?>>Fabricante</option>
                    <option value="year_desc" <?= $filters['sort'] === 'year_desc' ? 'selected' : '' ?>>Ano (novo→antigo)</option>
                    <option value="year_asc" <?= $filters['sort'] === 'year_asc' ? 'selected' : '' ?>>Ano (antigo→novo)</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-warning btn-sm flex-grow-1"><i class="fa fa-search"></i></button>
                <a href="/" class="btn btn-outline-secondary btn-sm"><i class="fa fa-times"></i></a>
            </div>
        </div>
        <?php if (!empty($tags)):
            // Build base QS preserving all active filters except tag_id and page
            $tag_qs_base = [];
            foreach (['manufacturer','scale','category_id','status','search','sort'] as $k) {
                if (!empty($filters[$k])) $tag_qs_base[$k] = $filters[$k];
            }
            $tag_qs_str    = $tag_qs_base ? '?' . http_build_query($tag_qs_base) . '&' : '?';
            $tag_qs_clear  = $tag_qs_base ? '?' . http_build_query($tag_qs_base) : '/';
        ?>
        <div class="mt-2 d-flex flex-wrap gap-1">
            <a href="<?= $tag_qs_clear ?>" class="badge <?= !$filters['tag_id'] ? 'bg-warning text-dark' : 'bg-secondary' ?> text-decoration-none">Todas</a>
            <?php foreach ($tags as $tag): ?>
                <a href="<?= $tag_qs_str ?>tag_id=<?= $tag['id'] ?>"
                   class="badge <?= (int)($filters['tag_id'] ?? 0) === (int)$tag['id'] ? 'bg-warning text-dark' : 'bg-secondary' ?> text-decoration-none">
                    <?= e($tag['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Grid -->
<?php
$has_active_filters = array_filter(array_intersect_key(
    $filters, array_flip(['manufacturer','scale','category_id','status','search','tag_id'])
));
?>
<?php if (empty($miniatures)): ?>
    <div class="text-center text-secondary py-5">
        <i class="fa fa-car fa-3x mb-3 opacity-25 d-block"></i>
        <?php if ($has_active_filters): ?>
            <p class="h5 mb-3">Nenhuma miniatura encontrada para esses filtros.</p>
            <a href="/" class="btn btn-outline-warning btn-sm">
                <i class="fa fa-times me-1"></i>Limpar filtros
            </a>
        <?php else: ?>
            <p class="h5">A coleção ainda não tem miniaturas públicas.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
        <?php foreach ($miniatures as $mini): ?>
            <div class="col">
                <a href="<?= e(mini_url($mini)) ?>" class="text-decoration-none">
                    <div class="card h-100 mini-card bg-dark border-secondary">
                        <div class="mini-card-img-wrap position-relative">
                            <img src="<?= e(thumb_url($mini['primary_photo'])) ?>"
                                 data-fallback="<?= e(photo_url($mini['primary_photo'])) ?>"
                                 alt="<?= e($mini['name']) ?>"
                                 class="card-img-top mini-thumb"
                                 loading="lazy">
                            <?php if ((int)$mini['photo_count'] > 1): ?>
                                <span class="position-absolute bottom-0 end-0 m-1 badge bg-dark bg-opacity-75"
                                      style="font-size:.65rem;">
                                    <i class="fa fa-images me-1"></i><?= $mini['photo_count'] ?>
                                </span>
                            <?php endif; ?>
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

<?php if ($total_pages > 1):
    $qs_parts = [];
    foreach (['manufacturer','scale','category_id','status','search','tag_id','sort'] as $k) {
        if (!empty($filters[$k])) $qs_parts[$k] = $filters[$k];
    }
    $qs_base = $qs_parts ? '&' . http_build_query($qs_parts) : '';
    $pages = pagination_range($page, $total_pages);
?>
<nav class="mt-4" aria-label="Paginação">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" href="?page=<?= $page - 1 . $qs_base ?>">&laquo;</a>
        </li>
        <?php foreach ($pages as $p): ?>
            <?php if ($p === null): ?>
                <li class="page-item disabled"><span class="page-link bg-dark border-secondary text-secondary">&hellip;</span></li>
            <?php else: ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link <?= $p === $page ? 'bg-warning border-warning text-dark' : 'bg-dark border-secondary text-light' ?>"
                       href="?page=<?= $p . $qs_base ?>"><?= $p ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" href="?page=<?= $page + 1 . $qs_base ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
