<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$collections = get_featured_collections(8);
$page_title  = APP_NAME . ' — Coleções de Miniaturas Diecast';

try {
    $lp_collectors = (int) db()->query('SELECT COUNT(*) FROM admin_users WHERE is_banned = 0')->fetchColumn();
    $lp_minis      = (int) db()->query('SELECT COUNT(*) FROM miniatures WHERE is_public = 1')->fetchColumn();
} catch (\Throwable $e) {
    $lp_collectors = 0;
    $lp_minis      = 0;
}

$body_class = 'lp-page';
require_once __DIR__ . '/includes/header_public.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────── -->
<section class="lp-hero">
    <div class="lp-hero-glow"></div>
    <p class="lp-eyebrow">Para colecionadores de diecast</p>
    <h1 class="lp-hero-title mb-3">
        Catalogue, organize<br>
        e <span>compartilhe</span><br>
        sua coleção.
    </h1>
    <p class="lp-hero-sub mb-5">
        Registre cada miniatura, controle valores, destaque as favoritas<br class="d-none d-md-inline">
        e tenha uma página pública pronta para compartilhar.
    </p>
    <div class="d-flex gap-3 flex-wrap justify-content-center">
        <a href="/register" class="btn btn-warning btn-lg px-5 fw-semibold">
            Começar grátis <i class="fa fa-arrow-right ms-2"></i>
        </a>
        <a href="/collections" class="btn lp-btn-ghost btn-lg px-4">
            Ver coleções
        </a>
    </div>
</section>

<!-- ── Stats ─────────────────────────────────────────────────────────── -->
<?php if ($lp_collectors > 1 || $lp_minis > 0): ?>
<div class="lp-stats-bar">
    <div class="d-flex justify-content-center align-items-center gap-0 flex-wrap">
        <?php if ($lp_collectors > 0): ?>
        <div class="lp-stat-item">
            <div class="lp-stat-number"><?= number_format($lp_collectors) ?></div>
            <div class="lp-stat-label">colecionador<?= $lp_collectors !== 1 ? 'es' : '' ?></div>
        </div>
        <div class="lp-stat-divider"></div>
        <?php endif; ?>
        <?php if ($lp_minis > 0): ?>
        <div class="lp-stat-item">
            <div class="lp-stat-number"><?= number_format($lp_minis) ?></div>
            <div class="lp-stat-label">miniatura<?= $lp_minis !== 1 ? 's' : '' ?> catalogada<?= $lp_minis !== 1 ? 's' : '' ?></div>
        </div>
        <div class="lp-stat-divider"></div>
        <?php endif; ?>
        <div class="lp-stat-item">
            <div class="lp-stat-number" style="font-size:1.5rem;">100% grátis</div>
            <div class="lp-stat-label">para sempre</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Features ──────────────────────────────────────────────────────── -->
<section class="py-5 my-2">
    <div class="text-center mb-5">
        <p class="lp-eyebrow">Funcionalidades</p>
        <h2 class="lp-section-title">Tudo que um colecionador precisa</h2>
        <p class="text-secondary mt-2 mx-auto" style="max-width:460px;">Uma ferramenta simples e completa para documentar sua paixão por diecast.</p>
    </div>
    <div class="row g-4">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-database"></i></div>
                <h5 class="text-light fw-semibold mb-2">Catálogo completo</h5>
                <p class="text-secondary small mb-0">Fabricante, escala, ano, condição, embalagem, valor pago, estimado e notas privadas.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-images"></i></div>
                <h5 class="text-light fw-semibold mb-2">Galeria de fotos</h5>
                <p class="text-secondary small mb-0">Múltiplas fotos por peça com geração automática de thumbnails otimizados em WebP.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-globe"></i></div>
                <h5 class="text-light fw-semibold mb-2">Página pública</h5>
                <p class="text-secondary small mb-0">Link pessoal com filtros, busca por nome e visualização em grade ou lista.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-star"></i></div>
                <h5 class="text-light fw-semibold mb-2">Destaques</h5>
                <p class="text-secondary small mb-0">Marque as peças favoritas para que apareçam sempre no topo da sua coleção.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-heart"></i></div>
                <h5 class="text-light fw-semibold mb-2">Wishlist</h5>
                <p class="text-secondary small mb-0">Gerencie as peças que deseja adquirir e converta para a coleção com um clique.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="lp-feature-card">
                <div class="lp-feature-icon"><i class="fa fa-chart-line"></i></div>
                <h5 class="text-light fw-semibold mb-2">Estatísticas</h5>
                <p class="text-secondary small mb-0">Dashboard com valorização, distribuição por fabricante, escala, condição e localização.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── Featured Collections ──────────────────────────────────────────── -->
<?php if (!empty($collections)): ?>
<section class="py-4 mb-3">
    <div class="d-flex align-items-end mb-4 gap-3">
        <div>
            <p class="lp-eyebrow mb-1">Comunidade</p>
            <h2 class="lp-section-title mb-0">Coleções em destaque</h2>
        </div>
        <a href="/collections" class="ms-auto text-secondary small text-decoration-none lp-link-arrow">
            Ver todas <i class="fa fa-arrow-right ms-1 fa-xs"></i>
        </a>
    </div>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-3">
        <?php foreach ($collections as $col): ?>
        <div class="col">
            <a href="/u/<?= e($col['slug']) ?>" class="text-decoration-none">
                <div class="lp-collection-card">
                    <?php if (!empty($col['avatar'])): ?>
                        <img src="<?= e(avatar_url($col['avatar'])) ?>"
                             alt="<?= e($col['display_name'] ?: $col['slug']) ?>"
                             class="rounded-circle mx-auto d-block mb-3"
                             style="width:64px;height:64px;object-fit:cover;border:2px solid var(--g64-border);">
                    <?php else: ?>
                        <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center mx-auto mb-3 fw-bold text-dark"
                             style="width:64px;height:64px;font-size:1.6rem;flex-shrink:0;">
                            <?= mb_strtoupper(mb_substr($col['display_name'] ?: $col['slug'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-light fw-semibold"><?= e($col['display_name'] ?: $col['slug']) ?></div>
                    <div class="text-secondary small mt-1">@<?= e($col['slug']) ?></div>
                    <div class="mt-2">
                        <span class="badge rounded-pill" style="background:rgba(240,165,0,0.12);color:var(--g64-yellow);font-size:.7rem;">
                            <?= (int)$col['mini_count'] ?> peça<?= $col['mini_count'] != 1 ? 's' : '' ?>
                        </span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Bottom CTA ────────────────────────────────────────────────────── -->
<section class="lp-cta-section">
    <p class="lp-eyebrow">Comece agora</p>
    <h2 class="lp-section-title mb-3">Sua coleção merece um lugar próprio.</h2>
    <p class="text-secondary mb-4 mx-auto" style="max-width:400px;">Crie sua conta gratuita e comece a catalogar em minutos.</p>
    <a href="/register" class="btn btn-warning btn-lg px-5 fw-semibold">
        Criar minha coleção <i class="fa fa-arrow-right ms-2"></i>
    </a>
</section>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
