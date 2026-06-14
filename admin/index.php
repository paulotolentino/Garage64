<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$stats      = get_stats();
$page_title = 'Dashboard';

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card bg-dark border-warning h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="fa fa-car fa-2x text-warning"></i>
                <div>
                    <div class="h2 mb-0 text-warning"><?= $stats['total'] ?></div>
                    <div class="text-secondary small">Total de miniaturas</div>
                </div>
            </div>
        </div>
    </div>
    <?php foreach ($stats['by_status'] as $s): ?>
    <div class="col-6 col-sm-4 col-xl-2">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body text-center py-3 text-light">
                <div class="h3 mb-0 text-warning"><?= $s['total'] ?></div>
                <div class="text-secondary small"><?= h(status_label($s['status'])) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- By Manufacturer -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-industry me-1 text-warning"></i>Por Fabricante <small class="text-secondary">(top 10)</small>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush bg-dark">
                    <?php foreach ($stats['by_manufacturer'] as $row): ?>
                        <li class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center text-light">
                            <span><?= e($row['manufacturer']) ?></span>
                            <span class="badge bg-warning text-dark"><?= $row['total'] ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($stats['by_manufacturer'])): ?>
                        <li class="list-group-item bg-dark border-secondary text-secondary">Sem dados</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- By Scale -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-ruler me-1 text-warning"></i>Por Escala
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush bg-dark">
                    <?php foreach ($stats['by_scale'] as $row): ?>
                        <li class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center text-light">
                            <span><?= e($row['scale']) ?></span>
                            <span class="badge bg-warning text-dark"><?= $row['total'] ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($stats['by_scale'])): ?>
                        <li class="list-group-item bg-dark border-secondary text-secondary">Sem dados</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- By Category -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-tags me-1 text-warning"></i>Por Categoria
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush bg-dark">
                    <?php foreach ($stats['by_category'] as $row): ?>
                        <li class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center text-light">
                            <span><?= e($row['name'] ?? 'Sem categoria') ?></span>
                            <span class="badge bg-warning text-dark"><?= $row['total'] ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($stats['by_category'])): ?>
                        <li class="list-group-item bg-dark border-secondary text-secondary">Sem dados</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col">
        <a href="/admin/miniatures.php?action=add" class="btn btn-warning">
            <i class="fa fa-plus me-1"></i>Adicionar Miniatura
        </a>
        <a href="/admin/wishlist.php" class="btn btn-outline-secondary ms-2">
            <i class="fa fa-heart me-1"></i>Wishlist
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
