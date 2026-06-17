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

<!-- Financial stats -->
<?php
$fin           = $stats['financial'];
$total_paid    = $fin['total_paid']    !== null ? (float) $fin['total_paid']    : null;
$total_est     = $fin['total_estimated'] !== null ? (float) $fin['total_estimated'] : null;
// Appreciation uses only miniatures that have BOTH prices filled
$both_paid     = $fin['both_paid']      !== null ? (float) $fin['both_paid']      : null;
$both_est      = $fin['both_estimated'] !== null ? (float) $fin['both_estimated'] : null;
$count_both    = (int) $fin['count_both'];
$appreciation  = ($both_paid && $both_est) ? $both_est - $both_paid : null;
$app_pct       = ($both_paid && $appreciation !== null) ? ($appreciation / $both_paid) * 100 : null;
?>
<div class="d-flex align-items-center mb-2 gap-2">
    <span class="text-secondary small">Valores financeiros</span>
    <button id="toggleFinancial" class="btn btn-sm btn-link text-secondary p-0" title="Mostrar/ocultar valores"
            style="font-size:1rem; line-height:1;">
        <i class="fa fa-eye" id="toggleFinancialIcon"></i>
    </button>
</div>
<div class="row g-3 mb-4" id="financialCards">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body">
                <div class="text-secondary small mb-1"><i class="fa fa-receipt me-1"></i>Valor total pago</div>
                <div class="h4 mb-0 text-light fin-value">
                    <?= $total_paid !== null
                        ? 'R$ ' . number_format($total_paid, 2, ',', '.')
                        : '<span class="text-secondary">—</span>' ?>
                </div>
                <div class="text-secondary fin-value" style="font-size:.75rem"><?= (int) $fin['count_paid'] ?> pç<?= (int)$fin['count_paid'] !== 1 ? 's' : '' ?> com preço</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body">
                <div class="text-secondary small mb-1"><i class="fa fa-chart-line me-1"></i>Valor estimado total</div>
                <div class="h4 mb-0 text-light fin-value">
                    <?= $total_est !== null
                        ? 'R$ ' . number_format($total_est, 2, ',', '.')
                        : '<span class="text-secondary">—</span>' ?>
                </div>
                <div class="text-secondary fin-value" style="font-size:.75rem"><?= (int) $fin['count_estimated'] ?> pç<?= (int)$fin['count_estimated'] !== 1 ? 's' : '' ?> com estimativa</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body">
                <div class="text-secondary small mb-1"><i class="fa fa-arrow-trend-up me-1"></i>Valorização</div>
                <?php if ($appreciation !== null): ?>
                    <div class="h4 mb-0 fin-value <?= $appreciation >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= ($appreciation >= 0 ? '+' : '-') . 'R$ ' . number_format(abs($appreciation), 2, ',', '.') ?>
                    </div>
                    <div class="text-secondary fin-value" style="font-size:.75rem">
                        <?= ($app_pct >= 0 ? '+' : '') . number_format($app_pct, 1) ?>% sobre o pago
                        · <?= $count_both ?> pç<?= $count_both !== 1 ? 's' : '' ?> com ambos os preços
                    </div>
                <?php else: ?>
                    <div class="h4 mb-0 text-secondary fin-value">—</div>
                    <div class="text-secondary fin-value" style="font-size:.75rem">preencha preço pago e estimado</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body">
                <div class="text-secondary small mb-1"><i class="fa fa-eye-slash me-1"></i>Visibilidade pública</div>
                <?php
                $pub_count = db()->query('SELECT COUNT(*) FROM miniatures WHERE is_public = 1')->fetchColumn();
                $prv_count = $stats['total'] - $pub_count;
                ?>
                <div class="h4 mb-0 text-light"><?= $pub_count ?> <small class="text-secondary fs-6">públicas</small></div>
                <div class="text-secondary" style="font-size:.75rem"><?= $prv_count ?> oculta<?= $prv_count !== 1 ? 's' : '' ?> do público</div>
            </div>
        </div>
    </div>
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
        <a href="/admin/miniatures?action=add" class="btn btn-warning">
            <i class="fa fa-plus me-1"></i>Adicionar Miniatura
        </a>
        <a href="/admin/wishlist" class="btn btn-outline-secondary ms-2">
            <i class="fa fa-heart me-1"></i>Wishlist
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
<script>
(function () {
    const KEY  = 'g64_fin_hidden';
    const btn  = document.getElementById('toggleFinancial');
    const icon = document.getElementById('toggleFinancialIcon');
    const vals = document.querySelectorAll('.fin-value');
    const BLUR = 'blur(6px)';

    function setHidden(hidden) {
        vals.forEach(el => el.style.filter = hidden ? BLUR : '');
        icon.className = hidden ? 'fa fa-eye-slash' : 'fa fa-eye';
        localStorage.setItem(KEY, hidden ? '1' : '0');
    }

    // Restore from localStorage
    setHidden(localStorage.getItem(KEY) === '1');

    btn.addEventListener('click', () => {
        setHidden(localStorage.getItem(KEY) !== '1');
    });
})();
</script>
