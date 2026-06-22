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

// Recém-adicionadas pela comunidade (públicas, mais recentes primeiro).
try {
    $stmt = db()->prepare(
        "SELECT m.id, m.name, m.manufacturer, m.scale,
                p.file_path AS primary_photo,
                u.slug AS owner_slug, u.display_name AS owner_name
         FROM miniatures m
         LEFT JOIN miniature_photos p ON p.miniature_id = m.id AND p.is_primary = 1
         LEFT JOIN admin_users u ON u.id = m.user_id
         WHERE m.is_public = 1
         ORDER BY m.created_at DESC
         LIMIT 12"
    );
    $stmt->execute();
    $lp_recent = $stmt->fetchAll();
} catch (\Throwable $e) {
    $lp_recent = [];
}
// Vitrine do hero: somente as recém-adicionadas que realmente têm foto.
$lp_showcase = array_values(array_filter($lp_recent, fn($m) => !empty($m['primary_photo'])));
// IDs das que serão exibidas no painel do hero (até 4), para não repetir abaixo.
$lp_showcase_ids = array_map(fn($m) => (int) $m['id'], array_slice($lp_showcase, 0, 4));
// "Últimas miniaturas" exibe apenas o que NÃO está no painel do hero.
$lp_recent_rest = array_values(array_filter($lp_recent, fn($m) => !in_array((int) $m['id'], $lp_showcase_ids, true)));

$body_class = 'lp-page';

// Metadados sociais (Open Graph / Twitter Cards) da landing.
$og_title       = 'Sua garagem de miniaturas na internet';
$og_description = 'Catalogue, organize e compartilhe sua coleção de miniaturas com outros colecionadores.';
$og_url         = rtrim(APP_URL, '/') . '/';

require_once __DIR__ . '/includes/header_public.php';
?>

<!-- ── Hero (2 colunas) ─────────────────────────────────────────────── -->
<section class="lp-hero">
    <div class="lp-hero-glow"></div>
    <div class="lp-hero-grid"></div>
    <div class="lp-hero-inner">
        <!-- Esquerda: mensagem -->
        <div class="lp-hero-copy">
            <p class="lp-eyebrow">A garagem digital do colecionador</p>
            <h1 class="lp-hero-title mb-3">
                Sua garagem de<br>
                miniaturas <span>na internet</span>.
            </h1>
            <p class="lp-hero-sub mb-4">
                Catalogue cada peça, organize a coleção inteira e mostre suas joias diecast numa vitrine pública feita pra colecionador.
            </p>
            <div class="d-flex gap-3 flex-wrap justify-content-center justify-content-lg-start">
                <a href="/register" class="btn btn-warning btn-lg px-5 fw-semibold">
                    Montar minha garagem <i class="fa fa-arrow-right ms-2"></i>
                </a>
                <a href="/collections" class="btn lp-btn-ghost btn-lg px-4">
                    Explorar coleções
                </a>
            </div>
            <p class="mt-4 mb-0 lp-hero-signin">
                Já tem garagem? <a href="/admin/login" class="text-warning text-decoration-none fw-semibold">Entrar</a>
            </p>
        </div>

        <!-- Direita: painel "Garagem em destaque" -->
        <div class="lp-garage-panel">
            <div class="lp-garage-panel-head">
                <span class="lp-garage-label">Garagem em destaque</span>
                <span class="lp-garage-live"><span class="lp-garage-dot"></span>ao vivo</span>
            </div>

            <div class="lp-garage-bigstat">
                <?php if ($lp_minis > 0): ?>
                    <span class="lp-garage-stat-big"><?= number_format($lp_minis) ?></span>
                    <span class="lp-garage-stat-cap">miniatura<?= $lp_minis !== 1 ? 's' : '' ?> estacionada<?= $lp_minis !== 1 ? 's' : '' ?></span>
                <?php else: ?>
                    <span class="lp-garage-stat-big">Sua coleção</span>
                    <span class="lp-garage-stat-cap">começa aqui</span>
                <?php endif; ?>
            </div>

            <?php $lp_panel = array_slice($lp_showcase, 0, 4); ?>
            <div class="lp-garage-grid">
                <?php foreach ($lp_panel as $sc): ?>
                <a href="<?= e(mini_url($sc)) ?>" class="lp-garage-tile">
                    <img src="<?= e(thumb_url($sc['primary_photo'])) ?>"
                         data-fallback="<?= e(photo_url($sc['primary_photo'])) ?>"
                         alt="<?= e($sc['name']) ?>" loading="lazy">
                    <span class="lp-garage-tile-name"><?= e($sc['name']) ?></span>
                </a>
                <?php endforeach; ?>
                <?php for ($i = count($lp_panel); $i < 4; $i++): ?>
                <div class="lp-garage-tile lp-garage-tile-empty"><i class="fa fa-car-side"></i></div>
                <?php endfor; ?>
            </div>

            <div class="lp-garage-foot">
                <?php if ($lp_collectors > 0): ?>
                <div class="lp-garage-foot-item">
                    <span class="lp-garage-foot-num"><?= number_format($lp_collectors) ?></span>
                    <span class="lp-garage-foot-lbl">colecionador<?= $lp_collectors !== 1 ? 'es' : '' ?></span>
                </div>
                <?php endif; ?>
                <div class="lp-garage-foot-item">
                    <span class="lp-garage-foot-num">100%</span>
                    <span class="lp-garage-foot-lbl">grátis</span>
                </div>
                <div class="lp-garage-foot-item">
                    <span class="lp-garage-foot-num"><i class="fa fa-bolt"></i></span>
                    <span class="lp-garage-foot-lbl">em minutos</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Explore garagens reais (coleções + últimas miniaturas) ───────── -->
<?php
$lp_latest    = array_slice($lp_recent_rest, 0, 6);
$lp_has_explore = !empty($collections) || !empty($lp_latest);
?>
<?php if ($lp_has_explore): ?>
<section class="py-4">
    <div class="text-center text-lg-start mb-4">
        <p class="lp-eyebrow mb-1">Comunidade</p>
        <h2 class="lp-section-title mb-0">Explore garagens reais</h2>
    </div>
    <div class="lp-explore">
        <!-- Garagens -->
        <div class="lp-explore-side">
            <div class="lp-explore-subhead">
                <span>Garagens</span>
                <a href="/collections" class="lp-link-arrow text-decoration-none">Ver todas <i class="fa fa-arrow-right fa-xs ms-1"></i></a>
            </div>
            <?php if (!empty($collections)): ?>
                <?php foreach (array_slice($collections, 0, 4) as $col): ?>
                <a href="/u/<?= e($col['slug']) ?>" class="lp-garage-card">
                    <?php if (!empty($col['avatar'])): ?>
                        <img src="<?= e(avatar_url($col['avatar'])) ?>" alt="<?= e($col['display_name'] ?: $col['slug']) ?>" class="lp-garage-card-avatar">
                    <?php else: ?>
                        <span class="lp-garage-card-avatar lp-garage-card-initial"><?= mb_strtoupper(mb_substr($col['display_name'] ?: $col['slug'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <span class="lp-garage-card-body">
                        <span class="lp-garage-card-name"><?= e($col['display_name'] ?: $col['slug']) ?></span>
                        <span class="lp-garage-card-meta">@<?= e($col['slug']) ?> · <?= (int)$col['mini_count'] ?> peça<?= $col['mini_count'] != 1 ? 's' : '' ?></span>
                    </span>
                    <i class="fa fa-chevron-right lp-garage-card-arrow"></i>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="lp-explore-empty">
                    <i class="fa fa-warehouse d-block"></i>
                    <p class="mb-2">As primeiras garagens públicas aparecem aqui.</p>
                    <a href="/register" class="btn btn-sm btn-warning fw-semibold">Criar a sua</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Últimas miniaturas -->
        <div class="lp-explore-main">
            <div class="lp-explore-subhead">
                <span>Últimas miniaturas</span>
            </div>
            <?php if (!empty($lp_latest)): ?>
            <div class="row row-cols-2 row-cols-md-3 g-3">
                <?php $lp_ri = 0; foreach ($lp_latest as $rm): ?>
                <div class="col lp-animate" style="--lp-delay:<?= number_format($lp_ri++ * 0.05, 2) ?>s">
                    <a href="<?= e(mini_url($rm)) ?>" class="lp-recent-card">
                        <img src="<?= e(thumb_url($rm['primary_photo'])) ?>"
                             data-fallback="<?= e(photo_url($rm['primary_photo'])) ?>"
                             alt="<?= e($rm['name']) ?>" class="lp-recent-thumb" loading="lazy">
                        <div class="lp-recent-body">
                            <?php if ($rm['manufacturer']): ?><div class="lp-recent-maker"><?= e($rm['manufacturer']) ?></div><?php endif; ?>
                            <div class="lp-recent-name"><?= e($rm['name']) ?></div>
                            <?php if (!empty($rm['owner_slug'])): ?>
                            <div class="lp-recent-owner"><i class="fa fa-user fa-xs"></i>@<?= e($rm['owner_slug']) ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="lp-explore-empty lp-explore-empty-tall">
                <i class="fa fa-camera d-block"></i>
                <p class="mb-0">As miniaturas mais recentes da comunidade aparecem aqui assim que forem publicadas.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── Por que Garage64 (band compacto) ─────────────────────────────── -->
<section class="py-4">
    <div class="lp-why-band lp-animate">
        <div class="lp-why-intro">
            <p class="lp-eyebrow mb-1">Por que Garage64</p>
            <h2 class="lp-section-title mb-2">Feito por quem coleciona</h2>
            <p class="text-secondary mb-0">Sem planilha bagunçada, sem rede social genérica. Um lugar só pra sua paixão por diecast.</p>
        </div>
        <div class="lp-why-grid">
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-warehouse"></i></div>
                <div>
                    <h5 class="lp-why-title">Tudo num lugar só</h5>
                    <p class="lp-why-text">Fabricante, escala, ano, condição e valores de cada peça.</p>
                </div>
            </div>
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-images"></i></div>
                <div>
                    <h5 class="lp-why-title">Vitrine de verdade</h5>
                    <p class="lp-why-text">Galeria de fotos por peça, em WebP, do jeito que merecem ser vistas.</p>
                </div>
            </div>
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-link"></i></div>
                <div>
                    <h5 class="lp-why-title">Sua página pública</h5>
                    <p class="lp-why-text">Um link só seu, com busca e filtros. Compartilhe onde quiser.</p>
                </div>
            </div>
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-star"></i></div>
                <div>
                    <h5 class="lp-why-title">Destaque suas joias</h5>
                    <p class="lp-why-text">Marque favoritas pra elas aparecerem sempre no topo.</p>
                </div>
            </div>
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-heart"></i></div>
                <div>
                    <h5 class="lp-why-title">Lista de desejos</h5>
                    <p class="lp-why-text">Acompanhe o que falta e mova pra garagem com um clique.</p>
                </div>
            </div>
            <div class="lp-why-item">
                <div class="lp-feature-icon"><i class="fa fa-chart-line"></i></div>
                <div>
                    <h5 class="lp-why-title">Você no controle</h5>
                    <p class="lp-why-text">Valorização, fabricantes, escalas e condição num painel.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── CTA final ────────────────────────────────────────────────────── -->
<section class="lp-cta-section lp-animate">
    <p class="lp-eyebrow">Bora montar?</p>
    <h2 class="lp-section-title mb-3">Sua garagem está vazia. Por enquanto.</h2>
    <p class="text-secondary mb-4 mx-auto" style="max-width:400px;">Crie sua conta grátis e comece a estacionar suas miniaturas hoje.</p>
    <a href="/register" class="btn btn-warning btn-lg px-5 fw-semibold">
        Montar minha garagem <i class="fa fa-arrow-right ms-2"></i>
    </a>
</section>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
