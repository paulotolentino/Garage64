<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Descoberta de garagens — busca + ordenação (via GET) ────────────────────
$search = trim((string) ($_GET['search'] ?? ''));
$sort   = (string) ($_GET['sort'] ?? 'featured');
if (!in_array($sort, ['featured', 'minis', 'recent', 'followers', 'name'], true)) {
    $sort = 'featured';
}

// URL atual preservando busca/ordenação (usada pelos forms de follow).
$return_qs  = http_build_query(array_filter(['search' => $search, 'sort' => $sort], static fn($v) => $v !== '' && $v !== 'featured'));
$return_url = '/collections' . ($return_qs ? '?' . $return_qs : '');

// ── Seguir / Deixar de seguir (POST local — espelha follows.php) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow'], true)) {
    if (!is_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
    verify_csrf();
    $me     = current_user_id();
    $target = (int) ($_POST['target_id'] ?? 0);
    if ($target > 0 && $me !== $target) { // nunca seguir a si mesmo
        if ($_POST['action'] === 'follow') {
            follow_user($me, $target);
            try {
                create_notification(
                    $target, $me, 'follow',
                    null, null, '/u/' . current_user_slug(), $target
                );
            } catch (Throwable $e) { /* nunca bloqueia o follow */ }
        } else {
            unfollow_user($me, $target);
        }
    }
    header('Location: ' . $return_url);
    exit;
}

$order_by = match ($sort) {
    'minis'     => 'mini_count DESC, display_name ASC',
    'recent'    => 'u.created_at DESC, mini_count DESC',
    'followers' => 'followers_count DESC, mini_count DESC, display_name ASC',
    'name'      => 'display_name ASC',
    default     => 'is_featured DESC, mini_count DESC, display_name ASC',
};

$where  = ['u.is_banned = 0', 'm.is_public = 1'];
$params = [];
if ($search !== '') {
    $where[]  = '(u.display_name LIKE ? OR u.slug LIKE ? OR u.bio LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$where_sql = implode(' AND ', $where);

// Subselects de relacionamento social (não conflitam com o GROUP BY u.id).
$follows_cols = '(SELECT COUNT(*) FROM user_follows fw WHERE fw.following_id = u.id) AS followers_count,
                 (SELECT COUNT(*) FROM user_follows fw WHERE fw.follower_id = u.id) AS following_count';

// Query principal (best-effort: is_featured pode não existir em bancos antigos)
$select_main = "u.id, u.slug, u.display_name, u.bio, u.avatar, u.is_featured, u.created_at, COUNT(m.id) AS mini_count, $follows_cols";
$select_fb   = "u.id, u.slug, u.display_name, u.bio, u.avatar, 0 AS is_featured, u.created_at, COUNT(m.id) AS mini_count, $follows_cols";
try {
    $stmt = db()->prepare(
        "SELECT $select_main
         FROM admin_users u
         INNER JOIN miniatures m ON m.user_id = u.id AND m.is_public = 1
         WHERE $where_sql GROUP BY u.id ORDER BY $order_by"
    );
    $stmt->execute($params);
    $collections = $stmt->fetchAll();
} catch (\PDOException $e) {
    $order_fb = str_replace('is_featured DESC, ', '', $order_by);
    $stmt = db()->prepare(
        "SELECT $select_fb
         FROM admin_users u
         INNER JOIN miniatures m ON m.user_id = u.id AND m.is_public = 1
         WHERE $where_sql GROUP BY u.id ORDER BY $order_fb"
    );
    $stmt->execute($params);
    $collections = $stmt->fetchAll();
}

// Top marcas por usuário — uma única query (sem N+1)
$brand_map = [];
$ids = array_map('intval', array_column($collections, 'id'));
if ($ids) {
    $in    = implode(',', $ids);
    $brows = db()->query(
        "SELECT user_id, manufacturer, COUNT(*) AS c
         FROM miniatures
         WHERE is_public = 1 AND user_id IN ($in)
         GROUP BY user_id, manufacturer
         ORDER BY user_id, c DESC, manufacturer ASC"
    )->fetchAll();
    foreach ($brows as $br) {
        $uid = (int) $br['user_id'];
        if (!isset($brand_map[$uid])) {
            $brand_map[$uid] = [];
        }
        if (count($brand_map[$uid]) < 3 && $br['manufacturer'] !== null && $br['manufacturer'] !== '') {
            $brand_map[$uid][] = $br['manufacturer'];
        }
    }
}

$total         = count($collections);
$has_search    = $search !== '';
$show_featured = !$has_search && $sort === 'featured';
$featured      = $show_featured ? array_values(array_filter($collections, fn($c) => (int) $c['is_featured'] === 1)) : [];
$others        = $show_featured ? array_values(array_filter($collections, fn($c) => (int) $c['is_featured'] !== 1)) : $collections;
$others_count  = count($others);

// Quais dos listados o visitante já segue (uma única query — evita N+1).
$viewer_id        = is_logged_in() ? current_user_id() : 0;
$viewer_following = [];
if ($viewer_id > 0 && $ids) {
    $vph = implode(',', array_fill(0, count($ids), '?'));
    $vst = db()->prepare("SELECT following_id FROM user_follows WHERE follower_id = ? AND following_id IN ($vph)");
    $vst->execute(array_merge([$viewer_id], $ids));
    $viewer_following = array_map('intval', array_column($vst->fetchAll(), 'following_id'));
}

$page_title = 'Coleções';
require_once __DIR__ . '/includes/header_public.php';

// Card de colecionador (closure reutilizada nos dois grids)
$render_card = function (array $col) use ($brand_map, $viewer_id, $viewer_following, $return_url): void {
    $name    = $col['display_name'] ?: $col['slug'];
    $isFeat  = (int) ($col['is_featured'] ?? 0) === 1;
    $count   = (int) $col['mini_count'];
    $fcount  = (int) ($col['followers_count'] ?? 0);
    $brands  = $brand_map[(int) $col['id']] ?? [];
    $colId   = (int) $col['id'];
    $isSelf  = $viewer_id === $colId;
    $iFollow = in_array($colId, $viewer_following, true);
?>
<article class="collections-card<?= $isFeat ? ' is-featured' : '' ?>">
    <?php if ($isFeat): ?><span class="collections-badge"><i class="fa fa-star"></i>Destaque</span><?php endif; ?>
    <div class="collections-card-top">
        <?php if (!empty($col['avatar'])): ?>
            <img src="<?= e(avatar_url($col['avatar'])) ?>" alt="<?= e($name) ?>" class="collections-card-avatar">
        <?php else: ?>
            <span class="collections-card-avatar collections-card-initial"><?= mb_strtoupper(mb_substr($name, 0, 1)) ?></span>
        <?php endif; ?>
        <div class="collections-card-id">
            <h3 class="collections-card-name"><?= e($name) ?></h3>
            <div class="collections-card-handle">@<?= e($col['slug']) ?></div>
        </div>
    </div>
    <?php if (!empty($col['bio'])): ?>
        <p class="collections-card-bio"><?= e($col['bio']) ?></p>
    <?php endif; ?>
    <?php if (!empty($brands)): ?>
        <div class="collections-card-brands">
            <?php foreach ($brands as $b): ?>
                <span class="md-pill collections-brand"><i class="fa fa-industry"></i><?= e($b) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="collections-card-foot">
        <div class="collections-card-stats">
            <span class="collections-card-stat"><strong><?= number_format($count) ?></strong> peça<?= $count !== 1 ? 's' : '' ?></span>
            <span class="collections-card-stat"><strong><?= number_format($fcount) ?></strong> <?= $fcount === 1 ? 'seguidor' : 'seguidores' ?></span>
        </div>
        <div class="collections-card-actions">
            <?php if (!$isSelf): ?>
                <?php if ($viewer_id > 0): ?>
                    <form method="post" action="<?= e($return_url) ?>" class="follow-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target_id" value="<?= $colId ?>">
                        <input type="hidden" name="action" value="<?= $iFollow ? 'unfollow' : 'follow' ?>">
                        <?php if ($iFollow): ?>
                            <button type="submit" class="follow-btn is-following" title="Deixar de seguir <?= e($name) ?>">
                                <span class="follow-state follow-state-default"><i class="fa fa-user-check"></i> Seguindo</span>
                                <span class="follow-state follow-state-hover"><i class="fa fa-user-xmark"></i> Deixar de seguir</span>
                            </button>
                        <?php else: ?>
                            <button type="submit" class="follow-btn" title="Seguir <?= e($name) ?>">
                                <i class="fa fa-user-plus"></i> <span>Seguir</span>
                            </button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <a href="/admin/login" class="follow-btn follow-cta" title="Entre para seguir este colecionador">
                        <i class="fa fa-user-plus"></i> <span>Seguir</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="/u/<?= e($col['slug']) ?>" class="md-btn md-btn-primary collections-card-btn">Ver garagem <i class="fa fa-arrow-right"></i></a>
        </div>
    </div>
</article>
<?php
};
?>

<!-- ═══ Hero ═══════════════════════════════════════════════════════════ -->
<section class="collections-hero">
    <div class="lp-eyebrow">Comunidade Garage64</div>
    <h1 class="collections-hero-title">Explore garagens de colecionadores</h1>
    <p class="collections-hero-sub">Descubra coleções, miniaturas e histórias de quem vive o hobby diecast.</p>
    <div class="collections-hero-cta">
        <a href="/register" class="md-btn md-btn-primary"><i class="fa fa-plus"></i>Criar minha garagem</a>
        <a href="/" class="md-btn"><i class="fa fa-house"></i>Voltar para home</a>
    </div>
</section>

<!-- ═══ Busca + Ordenação ══════════════════════════════════════════════ -->
<form class="collections-toolbar" method="get" role="search">
    <div class="collections-search">
        <i class="fa fa-magnifying-glass"></i>
        <input type="search" name="search" value="<?= e($search) ?>" placeholder="Buscar por nome, @slug ou bio...">
    </div>
    <div class="collections-toolbar-controls">
        <select name="sort" class="collections-select" onchange="this.form.submit()" aria-label="Ordenar">
            <option value="featured" <?= $sort === 'featured' ? 'selected' : '' ?>>Destaque</option>
            <option value="minis"    <?= $sort === 'minis'    ? 'selected' : '' ?>>Mais miniaturas</option>
            <option value="recent"   <?= $sort === 'recent'   ? 'selected' : '' ?>>Mais recentes</option>
            <option value="followers"<?= $sort === 'followers'? ' selected' : '' ?>>Mais seguidos</option>
            <option value="name"     <?= $sort === 'name'     ? 'selected' : '' ?>>Nome</option>
        </select>
        <button type="submit" class="md-btn md-btn-primary collections-apply"><i class="fa fa-arrow-right"></i><span class="collections-apply-text">Buscar</span></button>
    </div>
</form>

<?php if ($total === 0): ?>
    <?php if ($has_search): ?>
    <div class="collections-empty">
        <i class="fa fa-magnifying-glass d-block"></i>
        <p class="collections-empty-title">Nenhuma garagem encontrada</p>
        <p class="collections-empty-sub">Não achamos resultados para “<?= e($search) ?>”.</p>
        <a href="/collections" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar busca</a>
    </div>
    <?php else: ?>
    <div class="collections-empty">
        <i class="fa fa-warehouse d-block"></i>
        <p class="collections-empty-title">Nenhuma garagem pública ainda</p>
        <p class="collections-empty-sub">Seja o primeiro a abrir sua garagem para a comunidade.</p>
        <a href="/register" class="md-btn md-btn-primary"><i class="fa fa-plus"></i>Criar minha garagem</a>
    </div>
    <?php endif; ?>
<?php else: ?>

    <?php if ($show_featured && !empty($featured)): ?>
    <!-- ═══ Coleções em destaque ═══════════════════════════════════════ -->
    <section class="collections-section">
        <div class="collections-head">
            <div>
                <div class="lp-eyebrow">Selecionadas</div>
                <h2 class="lp-section-title">Coleções em destaque</h2>
            </div>
        </div>
        <div class="collections-grid">
            <?php foreach ($featured as $c) { $render_card($c); } ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ Todas as garagens ══════════════════════════════════════════ -->
    <section class="collections-section">
        <div class="collections-head">
            <div>
                <div class="lp-eyebrow">Comunidade</div>
                <h2 class="lp-section-title"><?= $has_search ? 'Resultados' : 'Todas as garagens' ?></h2>
            </div>
            <span class="collections-count"><?= $others_count ?> garage<?= $others_count !== 1 ? 'ns' : 'm' ?></span>
        </div>
        <?php if (!empty($others)): ?>
        <div class="collections-grid">
            <?php foreach ($others as $c) { $render_card($c); } ?>
        </div>
        <?php else: ?>
        <p class="collections-muted">Todas as coleções estão em destaque.</p>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
