<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Stats with 5-min session cache
$cache_key = 'stats_cache';
$cache_ttl = 300;
if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['ts']) < $cache_ttl) {
    $stats = $_SESSION[$cache_key]['data'];
} else {
    $stats = get_stats(current_user_id());
    $_SESSION[$cache_key] = ['ts' => time(), 'data' => $stats];
}
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
    <?php foreach ($stats['by_condition'] as $s): ?>
    <div class="col-6 col-sm-4 col-xl-2">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body text-center py-3 text-light">
                <div class="h3 mb-0 text-warning"><?= $s['total'] ?></div>
                <div class="text-secondary small"><?= h(condition_label($s['condition'])) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($stats['by_location'] as $s): ?>
    <div class="col-6 col-sm-4 col-xl-2">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-body text-center py-3 text-light">
                <div class="h3 mb-0 text-warning"><?= $s['total'] ?></div>
                <div class="text-secondary small"><?= h(location_label($s['location'])) ?></div>
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
                $pub_stmt = db()->prepare('SELECT COUNT(*) FROM miniatures WHERE is_public = 1 AND user_id = ?');
                $pub_stmt->execute([current_user_id()]);
                $pub_count = (int) $pub_stmt->fetchColumn();
                $prv_count = $stats['total'] - $pub_count;
                ?>
                <div class="h4 mb-0 text-light"><?= $pub_count ?> <small class="text-secondary fs-6">públicas</small></div>
                <div class="text-secondary" style="font-size:.75rem"><?= $prv_count ?> oculta<?= $prv_count !== 1 ? 's' : '' ?> do público</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- By Manufacturer — doughnut -->
    <div class="col-12 col-lg-4">        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-industry me-1 text-warning"></i>Por Fabricante <small class="text-secondary">(top 10)</small>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px;">
                <?php if (!empty($stats['by_manufacturer'])): ?>
                    <canvas id="chartManufacturer" style="max-height:220px;"></canvas>
                <?php else: ?>
                    <span class="text-secondary">Sem dados</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- By Scale — horizontal bar -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-ruler me-1 text-warning"></i>Por Escala
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px;">
                <?php if (!empty($stats['by_scale'])): ?>
                    <canvas id="chartScale" style="max-height:220px;"></canvas>
                <?php else: ?>
                    <span class="text-secondary">Sem dados</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- By Category — doughnut -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-tags me-1 text-warning"></i>Por Categoria
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px;">
                <?php if (!empty($stats['by_category'])): ?>
                    <canvas id="chartCategory" style="max-height:220px;"></canvas>
                <?php else: ?>
                    <span class="text-secondary">Sem dados</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-0">
    <!-- By Condition — doughnut -->
    <div class="col-12 col-lg-6">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-box me-1 text-warning"></i>Por Embalagem
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:200px;">
                <?php if (!empty($stats['by_condition'])): ?>
                    <canvas id="chartCondition" style="max-height:200px;"></canvas>
                <?php else: ?>
                    <span class="text-secondary">Sem dados</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- By Location — doughnut -->
    <div class="col-12 col-lg-6">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-map-pin me-1 text-warning"></i>Por Localização
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:200px;">
                <?php if (!empty($stats['by_location'])): ?>
                    <canvas id="chartLocation" style="max-height:200px;"></canvas>
                <?php else: ?>
                    <span class="text-secondary">Sem dados</span>
                <?php endif; ?>
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

<?php if (!empty($stats['top_viewed'])): ?>
<div class="row mt-4">
    <div class="col-12 col-lg-6">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary text-light">
                <i class="fa fa-fire me-1 text-warning"></i>Mais visualizadas
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush bg-dark">
                    <?php foreach ($stats['top_viewed'] as $i => $tv): ?>
                        <li class="list-group-item bg-dark border-secondary d-flex align-items-center gap-2 text-light">
                            <span class="text-warning fw-bold" style="width:20px;text-align:right;"><?= $i + 1 ?></span>
                            <span class="flex-grow-1">
                                <a href="<?= e(mini_url($tv)) ?>" class="text-light text-decoration-none"><?= e($tv['name']) ?></a>
                                <span class="text-secondary small ms-1"><?= e($tv['manufacturer']) ?></span>
                            </span>
                            <span class="badge bg-secondary"><?= $tv['views'] ?> <i class="fa fa-eye ms-1"></i></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
<script>
Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = '#2a2d3a';
const PALETTE = ['#ffc107','#e67e22','#e74c3c','#9b59b6','#3498db','#1abc9c','#2ecc71','#f39c12','#d35400','#c0392b'];

<?php if (!empty($stats['by_manufacturer'])): ?>
new Chart(document.getElementById('chartManufacturer'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($stats['by_manufacturer'], 'manufacturer')) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_manufacturer'], 'total')) ?>, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#12141c' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }, cutout: '60%' }
});
<?php endif; ?>

<?php if (!empty($stats['by_scale'])): ?>
new Chart(document.getElementById('chartScale'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($stats['by_scale'], 'scale')) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_scale'], 'total')) ?>, backgroundColor: '#ffc107', borderRadius: 4 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { stepSize: 1 }, grid: { color: '#2a2d3a' } }, y: { grid: { display: false } } }
    }
});
<?php endif; ?>

<?php if (!empty($stats['by_category'])): ?>
new Chart(document.getElementById('chartCategory'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => $r['name'] ?? 'Sem categoria', $stats['by_category'])) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_category'], 'total')) ?>, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#12141c' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }, cutout: '60%' }
});
<?php endif; ?>

<?php if (!empty($stats['by_condition'])): ?>
new Chart(document.getElementById('chartCondition'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => condition_label($r['condition']), $stats['by_condition'])) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_condition'], 'total')) ?>,
            backgroundColor: <?= json_encode(array_map(fn($r) => match($r['condition']) {
                'sealed' => '#0d6efd',
                'open'   => '#198754',
                'no_box' => '#ffc107',
                default  => '#6c757d',
            }, $stats['by_condition'])) ?>,
            borderWidth: 2, borderColor: '#12141c' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }, cutout: '60%' }
});
<?php endif; ?>

<?php if (!empty($stats['by_location'])): ?>
new Chart(document.getElementById('chartLocation'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => location_label($r['location']), $stats['by_location'])) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_location'], 'total')) ?>,
            backgroundColor: <?= json_encode(array_map(fn($r) => match($r['location']) {
                'display' => '#0dcaf0',
                'storage' => '#6c757d',
                default   => '#adb5bd',
            }, $stats['by_location'])) ?>,
            borderWidth: 2, borderColor: '#12141c' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }, cutout: '60%' }
});
<?php endif; ?>
</script>
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
