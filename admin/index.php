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

// ── Dados do colecionador (central "Minha garagem") ──────────────────────────
$g64_uid  = current_user_id();
$g64_name = current_user_name();
$g64_slug = current_user_slug();

// Identidade — avatar e data de entrada (best-effort)
$g64_avatar = null;
$g64_since  = null;
try {
    $st = db()->prepare('SELECT avatar, created_at FROM admin_users WHERE id = ? LIMIT 1');
    $st->execute([$g64_uid]);
    if ($row = $st->fetch()) {
        $g64_avatar = $row['avatar']     ?? null;
        $g64_since  = $row['created_at'] ?? null;
    }
} catch (\Throwable $e) { /* colunas opcionais */ }

// Contagens distintas (escopadas no usuário)
$g64_manu_count  = (int) db()->query('SELECT COUNT(DISTINCT manufacturer) FROM miniatures WHERE user_id = ' . $g64_uid)->fetchColumn();
$g64_scale_count = (int) db()->query("SELECT COUNT(DISTINCT scale) FROM miniatures WHERE scale IS NOT NULL AND scale != '' AND user_id = " . $g64_uid)->fetchColumn();

// Favoritas (is_featured) — best-effort
$g64_fav_count = 0;
try {
    $st = db()->prepare('SELECT COUNT(*) FROM miniatures WHERE user_id = ? AND is_featured = 1');
    $st->execute([$g64_uid]);
    $g64_fav_count = (int) $st->fetchColumn();
} catch (\Throwable $e) { /* coluna pode não existir */ }

// Wishlist
$g64_wish_count = count(get_wishlist('', $g64_uid));

// Últimas miniaturas adicionadas (escopadas no usuário) com foto principal
$g64_recent = [];
try {
    $st = db()->prepare(
        'SELECT m.id, m.name, m.manufacturer, m.created_at, p.file_path AS primary_photo
         FROM miniatures m
         LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
         WHERE m.user_id = ?
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 6'
    );
    $st->execute([$g64_uid]);
    $g64_recent = $st->fetchAll();
} catch (\Throwable $e) { $g64_recent = []; }

// Atividade / notificações recentes
$g64_notifs = get_user_notifications($g64_uid, 6);
$g64_unread = get_unread_notifications_count($g64_uid);
$g64_notif_meta = [
    'comment' => ['icon' => 'fa-comment', 'label' => 'comentou na sua miniatura'],
    'reply'   => ['icon' => 'fa-reply',   'label' => 'respondeu seu comentário'],
    'mention' => ['icon' => 'fa-at',      'label' => 'mencionou você'],
];

// "Mais comum" — derivado dos arrays já ordenados por total
$g64_top_manu  = $stats['by_manufacturer'][0] ?? null;
$g64_top_scale = $stats['by_scale'][0] ?? null;
$g64_top_cat   = $stats['by_category'][0] ?? null;
$g64_top_cond  = null;
foreach ($stats['by_condition'] as $c) {
    if ($g64_top_cond === null || (int) $c['total'] > (int) $g64_top_cond['total']) {
        $g64_top_cond = $c;
    }
}

// Financeiro
$fin          = $stats['financial'];
$total_paid   = $fin['total_paid']      !== null ? (float) $fin['total_paid']      : null;
$total_est    = $fin['total_estimated'] !== null ? (float) $fin['total_estimated'] : null;
$both_paid    = $fin['both_paid']       !== null ? (float) $fin['both_paid']       : null;
$both_est     = $fin['both_estimated']  !== null ? (float) $fin['both_estimated']  : null;
$count_both   = (int) $fin['count_both'];
$appreciation = ($both_paid && $both_est) ? $both_est - $both_paid : null;
$app_pct      = ($both_paid && $appreciation !== null) ? ($appreciation / $both_paid) * 100 : null;

// Visibilidade pública
$pub_stmt = db()->prepare('SELECT COUNT(*) FROM miniatures WHERE is_public = 1 AND user_id = ?');
$pub_stmt->execute([$g64_uid]);
$pub_count = (int) $pub_stmt->fetchColumn();
$prv_count = $stats['total'] - $pub_count;

require_once __DIR__ . '/../includes/header_admin.php';
?>

<!-- ═══ Hero — Minha garagem ═══════════════════════════════════════════ -->
<section class="dash-hero">
    <div class="dash-hero-id">
        <div class="dash-hero-avatar">
            <?php if ($g64_avatar): ?>
                <img src="<?= e(avatar_url($g64_avatar)) ?>" alt="<?= e($g64_name) ?>">
            <?php else: ?>
                <span class="dash-hero-initial"><?= mb_strtoupper(mb_substr($g64_name, 0, 1)) ?></span>
            <?php endif; ?>
        </div>
        <div class="dash-hero-text">
            <div class="lp-eyebrow">Minha garagem</div>
            <h1 class="dash-hero-name">Olá, <?= e($g64_name) ?></h1>
            <div class="dash-hero-handle">
                @<?= e($g64_slug) ?><?php if ($g64_since): ?> <span class="dash-hero-since">· na garagem desde <?= e(date('Y', strtotime($g64_since))) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="dash-hero-actions">
        <a href="/admin/miniatures?action=add" class="md-btn md-btn-primary"><i class="fa fa-plus"></i>Adicionar miniatura</a>
        <a href="/u/<?= e($g64_slug) ?>" target="_blank" class="md-btn"><i class="fa fa-warehouse"></i>Garagem pública</a>
        <a href="/admin/wishlist" class="md-btn"><i class="fa fa-heart"></i>Wishlist</a>
        <a href="/admin/notifications" class="md-btn dash-btn-notif">
            <i class="fa fa-bell"></i>Notificações
            <?php if ($g64_unread > 0): ?><span class="dash-notif-badge"><?= $g64_unread > 99 ? '99+' : (int) $g64_unread ?></span><?php endif; ?>
        </a>
    </div>
</section>

<!-- ═══ Seção 1 — Resumo da coleção ════════════════════════════════════ -->
<div class="cp-stats dash-summary">
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stats['total']) ?></span>
        <span class="cp-stat-lbl">miniatura<?= $stats['total'] !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($g64_manu_count) ?></span>
        <span class="cp-stat-lbl">fabricante<?= $g64_manu_count !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($g64_scale_count) ?></span>
        <span class="cp-stat-lbl">escala<?= $g64_scale_count !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($g64_fav_count) ?></span>
        <span class="cp-stat-lbl">favorita<?= $g64_fav_count !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($g64_wish_count) ?></span>
        <span class="cp-stat-lbl">wishlist</span>
    </div>
</div>

<!-- ═══ Seção 2 — Últimas miniaturas + Atividade ═══════════════════════ -->
<div class="row g-4 dash-section">
    <div class="col-12 col-xl-8">
        <div class="dash-head">
            <div>
                <div class="lp-eyebrow">Sua coleção</div>
                <h2 class="lp-section-title">Últimas miniaturas</h2>
            </div>
            <a href="/admin/miniatures" class="dash-seeall">Ver todas <i class="fa fa-arrow-right"></i></a>
        </div>
        <?php if (!empty($g64_recent)): ?>
        <div class="dash-recent-grid">
            <?php foreach ($g64_recent as $rm): ?>
            <a href="<?= e(mini_url($rm)) ?>" class="lp-recent-card">
                <img class="lp-recent-thumb" src="<?= e($rm['primary_photo'] ? photo_url($rm['primary_photo']) : '/assets/img/no-photo.svg') ?>" alt="<?= e($rm['name']) ?>" loading="lazy">
                <div class="lp-recent-body">
                    <div class="lp-recent-maker"><?= e($rm['manufacturer']) ?></div>
                    <div class="lp-recent-name"><?= e($rm['name']) ?></div>
                    <div class="lp-recent-owner"><i class="fa fa-clock"></i><?= e(date('d/m/Y', strtotime($rm['created_at']))) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="dash-empty">
            <i class="fa fa-car-side"></i>
            <p>Nenhuma miniatura ainda. <a href="/admin/miniatures?action=add">Adicione a primeira</a>.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-4">
        <div class="dash-head">
            <div>
                <div class="lp-eyebrow">Atividade</div>
                <h2 class="lp-section-title">Recentes</h2>
            </div>
            <a href="/admin/notifications" class="dash-seeall">Ver tudo <i class="fa fa-arrow-right"></i></a>
        </div>
        <div class="dash-activity">
            <?php if (!empty($g64_notifs)): ?>
                <?php foreach ($g64_notifs as $n):
                    $type  = (string) $n['type'];
                    $meta  = $g64_notif_meta[$type] ?? ['icon' => 'fa-bell', 'label' => 'interagiu com você'];
                    $actor = $n['actor_name'] ?: ('@' . $n['actor_username']);
                ?>
                <a href="<?= e($n['target_url'] ?: '/admin/notifications') ?>" class="dash-activity-item <?= $n['is_read'] ? '' : 'is-unread' ?>">
                    <span class="dash-activity-ico"><i class="fa <?= e($meta['icon']) ?>"></i></span>
                    <span class="dash-activity-body">
                        <span class="dash-activity-text"><strong><?= e($actor) ?></strong> <?= e($meta['label']) ?><?php if ($n['miniature_name']): ?> <span class="dash-activity-mini"><?= e($n['miniature_name']) ?></span><?php endif; ?></span>
                        <span class="dash-activity-time"><i class="fa fa-clock"></i><?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="dash-empty dash-empty-sm">
                    <i class="fa fa-bell-slash"></i>
                    <p>Sem atividade recente.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══ Seção 3 — Coleção em números ═══════════════════════════════════ -->
<div class="dash-section">
    <div class="lp-eyebrow">Panorama</div>
    <h2 class="lp-section-title mb-3">Sua coleção em números</h2>
    <div class="md-specs dash-insights">
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-industry"></i></span>
            <span class="md-spec-lbl">Marca mais comum</span>
            <span class="md-spec-val"><?= $g64_top_manu ? e($g64_top_manu['manufacturer']) . ' · ' . (int) $g64_top_manu['total'] : '—' ?></span>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-ruler"></i></span>
            <span class="md-spec-lbl">Escala mais comum</span>
            <span class="md-spec-val"><?= $g64_top_scale ? e($g64_top_scale['scale']) . ' · ' . (int) $g64_top_scale['total'] : '—' ?></span>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-tags"></i></span>
            <span class="md-spec-lbl">Categoria mais comum</span>
            <span class="md-spec-val"><?= $g64_top_cat ? e($g64_top_cat['name'] ?? 'Sem categoria') . ' · ' . (int) $g64_top_cat['total'] : '—' ?></span>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-box"></i></span>
            <span class="md-spec-lbl">Condição predominante</span>
            <span class="md-spec-val">
                <?php if ($g64_top_cond): ?>
                    <span class="md-pill md-cond-<?= e($g64_top_cond['condition']) ?>"><?= e(condition_label($g64_top_cond['condition'])) ?></span>
                <?php else: ?>—<?php endif; ?>
            </span>
        </div>
    </div>
</div>

<!-- ═══ Mais visualizadas ══════════════════════════════════════════════ -->
<?php if (!empty($stats['top_viewed'])): ?>
<div class="dash-section">
    <div class="lp-eyebrow">Em destaque</div>
    <h2 class="lp-section-title mb-3">Mais visualizadas</h2>
    <div class="dash-topviewed">
        <?php foreach ($stats['top_viewed'] as $i => $tv): ?>
        <a href="<?= e(mini_url($tv)) ?>" class="dash-topviewed-item">
            <span class="dash-topviewed-rank"><?= $i + 1 ?></span>
            <span class="dash-topviewed-name"><?= e($tv['name']) ?> <span class="dash-topviewed-maker"><?= e($tv['manufacturer']) ?></span></span>
            <span class="dash-topviewed-views"><?= (int) $tv['views'] ?> <i class="fa fa-eye"></i></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Seção 4 — Ações rápidas ════════════════════════════════════════ -->
<div class="dash-section">
    <div class="lp-eyebrow">Atalhos</div>
    <h2 class="lp-section-title mb-3">Ações rápidas</h2>
    <div class="row g-3">
        <?php
        $g64_actions = [
            ['/admin/miniatures?action=add', 'fa-plus',         'Adicionar miniatura',   'Cadastre uma nova peça'],
            ['/admin/miniatures',            'fa-images',        'Gerenciar fotos',       'Edite imagens das miniaturas'],
            ['/u/' . $g64_slug,              'fa-warehouse',     'Minha coleção pública', 'Veja como o público vê'],
            ['/admin/wishlist',              'fa-heart',         'Wishlist',              'Sua lista de desejos'],
            ['/admin/profile',               'fa-user-circle',   'Perfil',                'Nome, avatar e bio'],
            ['/admin/categories',            'fa-tags',          'Categorias',            'Organize sua coleção'],
        ];
        foreach ($g64_actions as $a):
            $blank = str_starts_with($a[0], '/u/') ? ' target="_blank"' : '';
        ?>
        <div class="col-12 col-sm-6 col-lg-4">
            <a href="<?= e($a[0]) ?>"<?= $blank ?> class="lp-feature-card dash-action">
                <span class="lp-feature-icon"><i class="fa <?= e($a[1]) ?>"></i></span>
                <span class="dash-action-title"><?= e($a[2]) ?></span>
                <span class="dash-action-desc"><?= e($a[3]) ?></span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ Valores (discreto) ═════════════════════════════════════════════ -->
<div class="dash-section">
    <div class="dash-head">
        <div>
            <div class="lp-eyebrow">Investimento</div>
            <h2 class="lp-section-title">Valores</h2>
        </div>
        <button id="toggleFinancial" class="dash-eye" type="button" title="Mostrar/ocultar valores">
            <i class="fa fa-eye" id="toggleFinancialIcon"></i>
        </button>
    </div>
    <div class="md-specs dash-fin">
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-receipt"></i></span>
            <span class="md-spec-lbl">Valor total pago</span>
            <span class="md-spec-val fin-value"><?= $total_paid !== null ? 'R$ ' . number_format($total_paid, 2, ',', '.') : '—' ?></span>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-chart-line"></i></span>
            <span class="md-spec-lbl">Valor estimado</span>
            <span class="md-spec-val fin-value"><?= $total_est !== null ? 'R$ ' . number_format($total_est, 2, ',', '.') : '—' ?></span>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-arrow-trend-up"></i></span>
            <span class="md-spec-lbl">Valorização</span>
            <?php if ($appreciation !== null): ?>
                <span class="md-spec-val fin-value <?= $appreciation >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($appreciation >= 0 ? '+' : '-') . 'R$ ' . number_format(abs($appreciation), 2, ',', '.') ?> <small class="dash-fin-pct"><?= ($app_pct >= 0 ? '+' : '') . number_format($app_pct, 1) ?>%</small></span>
            <?php else: ?>
                <span class="md-spec-val fin-value">—</span>
            <?php endif; ?>
        </div>
        <div class="md-spec">
            <span class="md-spec-ico"><i class="fa fa-eye-slash"></i></span>
            <span class="md-spec-lbl">Visibilidade pública</span>
            <span class="md-spec-val"><?= $pub_count ?> <small class="dash-fin-pct">de <?= $stats['total'] ?></small></span>
        </div>
    </div>
</div>

<!-- ═══ Visão geral (gráficos discretos) ═══════════════════════════════ -->
<div class="dash-section">
    <div class="lp-eyebrow">Distribuição</div>
    <h2 class="lp-section-title mb-3">Visão geral</h2>
    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="dash-chart-card">
                <div class="dash-chart-head"><i class="fa fa-industry"></i>Por fabricante <small>(top 10)</small></div>
                <div class="dash-chart-body">
                    <?php if (!empty($stats['by_manufacturer'])): ?>
                        <canvas id="chartManufacturer"></canvas>
                    <?php else: ?>
                        <span class="dash-muted">Sem dados</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="dash-chart-card">
                <div class="dash-chart-head"><i class="fa fa-ruler"></i>Por escala</div>
                <div class="dash-chart-body">
                    <?php if (!empty($stats['by_scale'])): ?>
                        <canvas id="chartScale"></canvas>
                    <?php else: ?>
                        <span class="dash-muted">Sem dados</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
<script>
Chart.defaults.color = '#aeb4c0';
Chart.defaults.borderColor = '#2c313b';
const PALETTE = ['#f5a623','#ffc24b','#c77f12','#e67e22','#d35400','#a855f7','#3b82f6','#22c55e','#ec4899','#9ca3af'];

<?php if (!empty($stats['by_manufacturer'])): ?>
new Chart(document.getElementById('chartManufacturer'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($stats['by_manufacturer'], 'manufacturer')) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_manufacturer'], 'total')) ?>, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#14171d' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }, cutout: '62%' }
});
<?php endif; ?>

<?php if (!empty($stats['by_scale'])): ?>
new Chart(document.getElementById('chartScale'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($stats['by_scale'], 'scale')) ?>,
        datasets: [{ data: <?= json_encode(array_column($stats['by_scale'], 'total')) ?>, backgroundColor: '#f5a623', borderRadius: 4 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { stepSize: 1 }, grid: { color: '#2c313b' } }, y: { grid: { display: false } } }
    }
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
