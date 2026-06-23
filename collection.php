<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$slug  = trim($_GET['slug'] ?? '');
$owner = $slug ? get_user_by_slug($slug) : null;

if (!$owner) {
    http_response_code(404);
    $page_title = 'Coleção não encontrada';
    require_once __DIR__ . '/includes/header_public.php';
    echo '<div class="text-center py-5"><i class="fa fa-user-slash fa-3x text-secondary mb-3 d-block"></i>
          <h2 class="text-light">Coleção não encontrada</h2>
          <a href="/" class="btn btn-warning mt-3">Voltar ao início</a></div>';
    require_once __DIR__ . '/includes/footer_public.php';
    exit;
}

$uid = (int) $owner['id'];

// ─── Seguir / Deixar de seguir (POST) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow'], true)) {
    // Usuário deslogado é direcionado ao login ao tentar seguir.
    if (!is_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
    verify_csrf();
    $me = current_user_id();
    if ($me !== $uid) { // nunca seguir a si mesmo
        if ($_POST['action'] === 'follow') {
            follow_user($me, $uid);
            // Best-effort: notifica o colecionador seguido (sem miniatura, sem self, dedup interno).
            try {
                create_notification(
                    $uid, $me, 'follow',
                    null, null, '/u/' . current_user_slug(), $uid
                );
            } catch (Throwable $e) { /* nunca bloqueia o follow */ }
        } else {
            unfollow_user($me, $uid);
        }
    }
    header('Location: /u/' . rawurlencode($slug));
    exit;
}

$filters = [
    'manufacturer' => trim($_GET['manufacturer'] ?? ''),
    'scale'        => trim($_GET['scale'] ?? ''),
    'category_id'  => (int) ($_GET['category_id'] ?? 0) ?: null,
    'condition'    => trim($_GET['condition'] ?? ''),
    'location'     => trim($_GET['location'] ?? ''),
    'search'       => trim($_GET['search'] ?? ''),
    'tag_id'       => (int) ($_GET['tag_id'] ?? 0) ?: null,
    'sort'         => in_array($_GET['sort'] ?? '', ['name','manufacturer','year_asc','year_desc','recent']) ? $_GET['sort'] : '',
    'is_public'    => 1,
    'user_id'      => $uid,
];

$page        = max(1, (int) ($_GET['page'] ?? 1));
$per_page    = PER_PAGE;
$total       = count_miniatures($filters);
$total_pages = (int) ceil($total / $per_page);
$page        = min($page, max(1, $total_pages));

$miniatures    = get_miniatures($filters + ['page' => $page, 'per_page' => $per_page]);
$categories    = get_categories($uid);
$manufacturers = get_distinct_manufacturers($uid);
$scales        = get_distinct_scales($uid);
$tags          = get_tags($uid);

$display_name = $owner['display_name'] ?: $owner['username'];
$page_title   = 'Coleção de ' . $display_name;
$base_url     = '/u/' . e($slug);
$body_class   = 'cp-page';

// Dados de perfil que não vêm de get_user_by_slug (coluna avatar pode não existir).
$owner_avatar = null;
$owner_since  = null;
try {
    $st = db()->prepare('SELECT avatar, created_at FROM admin_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    if ($row = $st->fetch()) {
        $owner_avatar = $row['avatar'] ?? null;
        $owner_since  = $row['created_at'] ?? null;
    }
} catch (\Throwable $e) {
    try {
        $st = db()->prepare('SELECT created_at FROM admin_users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $owner_since = $st->fetchColumn() ?: null;
    } catch (\Throwable $e2) { /* ignora */ }
}

// Estatísticas da garagem (coleção pública completa, independente dos filtros).
$collection_total = count_miniatures(['user_id' => $uid, 'is_public' => 1]);
$featured_total   = 0;
try {
    $st = db()->prepare('SELECT COUNT(*) FROM miniatures WHERE user_id = ? AND is_public = 1 AND is_featured = 1');
    $st->execute([$uid]);
    $featured_total = (int) $st->fetchColumn();
} catch (\Throwable $e) { /* coluna is_featured pode não existir */ }

// Relacionamento social (seguir colecionadores — estilo Instagram).
$followers_count     = count_followers($uid);
$following_count     = count_following($uid);
$is_own_garage       = is_logged_in() && current_user_id() === $uid;
$viewer_is_following = is_logged_in() && !$is_own_garage && is_following(current_user_id(), $uid);

$has_active_filters = (bool) array_filter(array_intersect_key(
    $filters, array_flip(['manufacturer','scale','category_id','condition','location','search','tag_id'])
));

// Estado do painel de filtros persistido via query string (?filters=1).
$open_filters = $has_active_filters || !empty($_GET['filters']);
$panel_qs = [];
if ($open_filters) $panel_qs['filters'] = 1;
$clear_url = $base_url . ($panel_qs ? '?' . http_build_query($panel_qs) : '');

// Metadados sociais (Open Graph / Twitter Cards) da garagem pública.
// Imagem: 1) avatar do colecionador  2) imagem padrão (fallback no header).
$og_title       = $display_name;
$og_description = number_format($collection_total) . ' miniatura' . ($collection_total !== 1 ? 's' : '')
                . ' • ' . number_format($followers_count) . ' seguidor' . ($followers_count !== 1 ? 'es' : '')
                . ' • Coleção pública de miniaturas diecast';
$og_url         = rtrim(APP_URL, '/') . '/u/' . rawurlencode($slug);
$og_image       = $owner_avatar ? avatar_url($owner_avatar) : null;

require_once __DIR__ . '/includes/header_public.php';
?>

<!-- Identidade do colecionador (sempre visível) ───────────────────────── -->
<section class="cp-profile">
    <div class="cp-profile-avatar">
        <?php if ($owner_avatar): ?>
            <img src="<?= e(avatar_url($owner_avatar)) ?>" alt="<?= e($display_name) ?>">
        <?php else: ?>
            <span class="cp-profile-initial"><?= mb_strtoupper(mb_substr($display_name, 0, 1)) ?></span>
        <?php endif; ?>
    </div>
    <div class="cp-profile-info">
        <p class="lp-eyebrow cp-profile-eyebrow">Garagem de colecionador</p>
        <h1 class="cp-profile-name"><?= e($display_name) ?></h1>
        <div class="cp-profile-handle">
            @<?= e($slug) ?><?php if ($owner_since): ?> <span class="cp-profile-since">· na garagem desde <?= e(date('Y', strtotime($owner_since))) ?></span><?php endif; ?>
        </div>
        <?php if ($owner['bio']): ?>
            <p class="cp-profile-bio"><?= e($owner['bio']) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$is_own_garage): ?>
    <div class="cp-profile-follow">
        <?php if (is_logged_in()): ?>
            <form method="post" action="/u/<?= e($slug) ?>" class="follow-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $viewer_is_following ? 'unfollow' : 'follow' ?>">
                <?php if ($viewer_is_following): ?>
                    <button type="submit" class="follow-btn is-following" title="Deixar de seguir <?= e($display_name) ?>">
                        <span class="follow-state follow-state-default"><i class="fa fa-user-check"></i> Seguindo</span>
                        <span class="follow-state follow-state-hover"><i class="fa fa-user-xmark"></i> Deixar de seguir</span>
                    </button>
                <?php else: ?>
                    <button type="submit" class="follow-btn" title="Seguir <?= e($display_name) ?>">
                        <i class="fa fa-user-plus"></i> <span>Seguir</span>
                    </button>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <a href="/admin/login" class="follow-btn follow-cta" title="Entre para seguir este colecionador">
                <i class="fa fa-user-plus"></i> <span>Seguir</span>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<!-- Estatísticas — identidade + social + coleção (sempre visíveis) ─────── -->
<div class="cp-stats">
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($collection_total) ?></span>
        <span class="cp-stat-lbl">peça<?= $collection_total !== 1 ? 's' : '' ?></span>
    </div>
    <a class="cp-stat cp-stat-link" href="/u/<?= e($slug) ?>/seguidores">
        <span class="cp-stat-num"><?= number_format($followers_count) ?></span>
        <span class="cp-stat-lbl"><?= $followers_count === 1 ? 'seguidor' : 'seguidores' ?></span>
    </a>
    <a class="cp-stat cp-stat-link" href="/u/<?= e($slug) ?>/seguindo">
        <span class="cp-stat-num"><?= number_format($following_count) ?></span>
        <span class="cp-stat-lbl">seguindo</span>
    </a>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format(count($manufacturers)) ?></span>
        <span class="cp-stat-lbl">fabricante<?= count($manufacturers) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format(count($scales)) ?></span>
        <span class="cp-stat-lbl">escala<?= count($scales) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if ($featured_total > 0): ?>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($featured_total) ?></span>
        <span class="cp-stat-lbl">destaque<?= $featured_total !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- Toolbar de exploração (apenas filtros é colapsável) ────────────────── -->
<div class="cp-toolbar">
    <span class="cp-toolbar-label"><i class="fa fa-warehouse"></i> Coleção de <?= e($display_name) ?></span>
    <button type="button" class="cp-toolbtn <?= $open_filters ? 'is-open' : '' ?>" id="btnFilters"
            aria-expanded="<?= $open_filters ? 'true' : 'false' ?>" aria-controls="cpFilters">
        <i class="fa fa-sliders"></i>
        <span>Filtros<?= $has_active_filters ? ' ativos' : '' ?></span>
        <i class="fa fa-chevron-down cp-tool-caret"></i>
    </button>
</div>


<!-- Filtros (colapsados; abrem expandidos se houver filtro ativo) ──────── -->
<div id="cpFilters" class="cp-collapse <?= $open_filters ? 'is-open' : '' ?>">
<!-- Barra de exploração ────────────────────────────────────────────── -->
<form method="get" class="cp-explore">
    <input type="hidden" name="filters" id="hidFilters" value="1"<?= $open_filters ? '' : ' disabled' ?>>
    <div class="cp-explore-search">
        <i class="fa fa-magnifying-glass"></i>
        <input type="search" name="search" class="cp-field cp-search-input"
               placeholder="Buscar nesta coleção..."
               value="<?= e($filters['search']) ?>">
    </div>
    <div class="cp-explore-controls">
        <select name="manufacturer" class="cp-field cp-select">
            <option value="">Fabricante</option>
            <?php foreach ($manufacturers as $m): ?>
                <option value="<?= e($m) ?>" <?= $filters['manufacturer'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="scale" class="cp-field cp-select">
            <option value="">Escala</option>
            <?php foreach ($scales as $s): ?>
                <option value="<?= e($s) ?>" <?= $filters['scale'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="category_id" class="cp-field cp-select">
            <option value="">Categoria</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= (int)($filters['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="condition" class="cp-field cp-select">
            <option value="">Embalagem</option>
            <option value="sealed" <?= ($filters['condition'] ?? '') === 'sealed' ? 'selected' : '' ?>>Lacrada</option>
            <option value="open"   <?= ($filters['condition'] ?? '') === 'open'   ? 'selected' : '' ?>>Aberta</option>
            <option value="no_box" <?= ($filters['condition'] ?? '') === 'no_box' ? 'selected' : '' ?>>Sem caixa</option>
        </select>
        <select name="location" class="cp-field cp-select">
            <option value="">Local</option>
            <option value="storage" <?= ($filters['location'] ?? '') === 'storage' ? 'selected' : '' ?>>Armazenada</option>
            <option value="display" <?= ($filters['location'] ?? '') === 'display' ? 'selected' : '' ?>>Exposição</option>
        </select>
        <select name="sort" class="cp-field cp-select">
            <option value="" <?= $filters['sort'] === '' ? 'selected' : '' ?>>Mais relevantes</option>
            <option value="recent" <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Mais recente</option>
            <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>Nome A–Z</option>
            <option value="manufacturer" <?= $filters['sort'] === 'manufacturer' ? 'selected' : '' ?>>Fabricante</option>
            <option value="year_desc" <?= $filters['sort'] === 'year_desc' ? 'selected' : '' ?>>Ano (novo→antigo)</option>
            <option value="year_asc" <?= $filters['sort'] === 'year_asc' ? 'selected' : '' ?>>Ano (antigo→novo)</option>
        </select>
        <button type="submit" class="cp-btn cp-btn-primary" title="Aplicar"><i class="fa fa-arrow-right"></i></button>
        <a href="<?= $clear_url ?>" class="cp-btn cp-btn-ghost" data-cp-nav title="Limpar"><i class="fa fa-rotate-left"></i></a>
    </div>
    <?php if (!empty($tags)):
        $tag_qs_base = $panel_qs;
        foreach (['manufacturer','scale','category_id','condition','location','search','sort'] as $k) {
            if (!empty($filters[$k])) $tag_qs_base[$k] = $filters[$k];
        }
        $tag_qs_str   = $base_url . ($tag_qs_base ? '?' . http_build_query($tag_qs_base) . '&' : '?');
        $tag_qs_clear = $base_url . ($tag_qs_base ? '?' . http_build_query($tag_qs_base) : '');
    ?>
    <div class="cp-chips">
        <a href="<?= $tag_qs_clear ?>" class="cp-chip <?= !$filters['tag_id'] ? 'is-active' : '' ?>" data-cp-nav>Todas</a>
        <?php foreach ($tags as $tag): ?>
            <a href="<?= $tag_qs_str ?>tag_id=<?= $tag['id'] ?>"
               class="cp-chip <?= (int)($filters['tag_id'] ?? 0) === (int)$tag['id'] ? 'is-active' : '' ?>" data-cp-nav>
                <?= e($tag['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>
</div><!-- /#cpFilters -->

<?php if (empty($miniatures)): ?>
    <div class="cp-empty">
        <?php if ($has_active_filters): ?>
            <i class="fa fa-filter-circle-xmark cp-empty-icon d-block"></i>
            <h2 class="cp-empty-title">Nada encontrado nesses filtros</h2>
            <p class="cp-empty-text">Tente afrouxar a busca ou explorar a coleção inteira.</p>
            <a href="<?= $clear_url ?>" class="cp-btn cp-btn-primary" data-cp-nav><i class="fa fa-rotate-left me-2"></i>Limpar filtros</a>
        <?php else: ?>
            <i class="fa fa-warehouse cp-empty-icon d-block"></i>
            <h2 class="cp-empty-title">Garagem ainda vazia</h2>
            <p class="cp-empty-text">Esta coleção ainda não tem miniaturas públicas. Volte em breve.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
    // Tags da página atual em uma única query (sem N+1, sem alterar schema).
    $cp_tags_map = [];
    $cp_ids = array_map(fn($m) => (int) $m['id'], $miniatures);
    if ($cp_ids) {
        try {
            $cp_in = implode(',', array_fill(0, count($cp_ids), '?'));
            $st = db()->prepare("SELECT mt.miniature_id, t.name
                                 FROM miniature_tags mt
                                 JOIN tags t ON t.id = mt.tag_id
                                 WHERE mt.miniature_id IN ($cp_in)
                                 ORDER BY t.name ASC");
            $st->execute($cp_ids);
            foreach ($st->fetchAll() as $r) {
                $cp_tags_map[(int) $r['miniature_id']][] = $r['name'];
            }
        } catch (\Throwable $e) { /* tags são opcionais */ }
    }
    ?>
    <div class="cp-gridbar">
        <span class="cp-gridbar-count">
            <?= number_format($total) ?> <?= $total !== 1 ? 'resultados' : 'resultado' ?><?php if ($has_active_filters): ?> · <a href="<?= $clear_url ?>" class="cp-clear" data-cp-nav>limpar filtros</a><?php endif; ?>
        </span>
        <div class="cp-viewtoggle" role="group" aria-label="Visualização">
            <button id="viewGrid" class="cp-view-btn is-active" title="Grade"><i class="fa fa-grip"></i></button>
            <button id="viewList" class="cp-view-btn" title="Lista"><i class="fa fa-list"></i></button>
        </div>
    </div>
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3 cp-grid" id="miniGrid">
        <?php $cp_i = 0; foreach ($miniatures as $mini):
            $cp_cond  = $mini['condition'] ?? 'sealed';
            $cp_mtags = array_slice($cp_tags_map[(int) $mini['id']] ?? [], 0, 2);
        ?>
            <div class="col lp-animate" style="--lp-delay:<?= number_format(($cp_i++ % 12) * 0.04, 2) ?>s">
                <a href="<?= e(mini_url($mini)) ?>" class="cp-card">
                    <div class="cp-card-photo">
                        <img src="<?= e(thumb_url($mini['primary_photo'])) ?>"
                             data-fallback="<?= e(photo_url($mini['primary_photo'])) ?>"
                             alt="<?= e($mini['name']) ?>"
                             class="cp-card-img"
                             loading="lazy">
                        <?php if (!empty($mini['is_featured'])): ?>
                            <span class="cp-card-star" title="Destaque"><i class="fa fa-star"></i></span>
                        <?php endif; ?>
                        <?php if ((int) $mini['photo_count'] > 1): ?>
                            <span class="cp-card-photos"><i class="fa fa-images"></i><?= (int) $mini['photo_count'] ?></span>
                        <?php endif; ?>
                        <?php if ((int) ($mini['likes_count'] ?? 0) > 0): ?>
                            <span class="cp-card-likes" title="Curtidas"><i class="fa fa-heart"></i><?= (int) $mini['likes_count'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cp-card-info">
                        <?php if ($mini['manufacturer']): ?>
                            <div class="cp-card-maker"><?= e($mini['manufacturer']) ?></div>
                        <?php endif; ?>
                        <div class="cp-card-name"><?= e($mini['name']) ?></div>
                        <div class="cp-card-pills">
                            <?php if ($mini['scale']): ?>
                                <span class="cp-pill cp-pill-soft"><?= e($mini['scale']) ?></span>
                            <?php endif; ?>
                            <span class="cp-pill cp-cond-<?= e($cp_cond) ?>"><?= h(condition_label($cp_cond)) ?></span>
                            <?php foreach ($cp_mtags as $tn): ?>
                                <span class="cp-pill cp-pill-tag">#<?= e($tn) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($total_pages > 1):
    $qs_parts = $panel_qs;
    foreach (['manufacturer','scale','category_id','condition','location','search','tag_id','sort'] as $k) {
        if (!empty($filters[$k])) $qs_parts[$k] = $filters[$k];
    }
    $qs_base = $qs_parts ? '?' . http_build_query($qs_parts) . '&' : '?';
    $pages   = pagination_range($page, $total_pages);
?>
<nav class="mt-4" aria-label="Paginação">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" data-cp-nav href="<?= $base_url . $qs_base ?>page=<?= $page - 1 ?>">&laquo;</a>
        </li>
        <?php foreach ($pages as $p): ?>
            <?php if ($p === null): ?>
                <li class="page-item disabled"><span class="page-link bg-dark border-secondary text-secondary">&hellip;</span></li>
            <?php else: ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link <?= $p === $page ? 'bg-warning border-warning text-dark' : 'bg-dark border-secondary text-light' ?>"
                       data-cp-nav href="<?= $base_url . $qs_base ?>page=<?= $p ?>"><?= $p ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link bg-dark border-secondary text-light" data-cp-nav href="<?= $base_url . $qs_base ?>page=<?= $page + 1 ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
<script>
(function () {
    const grid = document.getElementById('miniGrid');
    const btnGrid = document.getElementById('viewGrid');
    const btnList = document.getElementById('viewList');
    if (!grid) return;
    const KEY = 'g64_view';
    const COLS = ['row-cols-2', 'row-cols-md-3', 'row-cols-xl-4'];
    function setView(v) {
        if (v === 'list') {
            COLS.forEach(function (c) { grid.classList.remove(c); });
            grid.classList.add('view-list', 'row-cols-1');
            btnList.classList.add('is-active');
            btnGrid.classList.remove('is-active');
        } else {
            grid.classList.remove('view-list', 'row-cols-1');
            COLS.forEach(function (c) { grid.classList.add(c); });
            btnGrid.classList.add('is-active');
            btnList.classList.remove('is-active');
        }
        localStorage.setItem(KEY, v);
    }
    setView(localStorage.getItem(KEY) || 'grid');
    btnGrid.addEventListener('click', () => setView('grid'));
    btnList.addEventListener('click', () => setView('list'));
})();
</script>
<script>
(function () {
    const filtersPanel = document.getElementById('cpFilters');
    const hidFilters = document.getElementById('hidFilters');

    function isOpen(p) { return !!(p && p.classList.contains('is-open')); }

    function sync() {
        const f = isOpen(filtersPanel);
        if (hidFilters) hidFilters.disabled = !f;
        document.querySelectorAll('[data-cp-nav]').forEach(function (link) {
            try {
                const u = new URL(link.getAttribute('href'), window.location.href);
                if (f) u.searchParams.set('filters', '1'); else u.searchParams.delete('filters');
                link.setAttribute('href', u.pathname + u.search);
            } catch (e) { /* ignora links inválidos */ }
        });
    }

    function wire(btnId, panel) {
        const btn = document.getElementById(btnId);
        if (!btn || !panel) return;
        btn.addEventListener('click', function () {
            const open = panel.classList.toggle('is-open');
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            sync();
        });
    }
    wire('btnFilters', filtersPanel);
    sync();
})();
</script>
