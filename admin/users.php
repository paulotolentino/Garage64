<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_superadmin();

// ─── POST ACTIONS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$uid) { redirect('/admin/users'); }

    if ($action === 'edit_slug') {
        $new_slug = strtolower(trim($_POST['slug'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $new_slug) || is_reserved_slug($new_slug)) {
            flash('Slug inválido ou reservado.', 'danger');
        } else {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE slug = ? AND id != ?');
            $chk->execute([$new_slug, $uid]);
            if ($chk->fetch()) {
                flash('Slug já está em uso.', 'danger');
            } else {
                db()->prepare('UPDATE admin_users SET slug = ? WHERE id = ?')->execute([$new_slug, $uid]);
                if ($uid === current_user_id()) {
                    $_SESSION['user_slug'] = $new_slug;
                }
                flash('Slug atualizado.');
            }
        }
    } elseif ($action === 'toggle_featured') {
        $stmt = db()->prepare('SELECT is_featured FROM admin_users WHERE id = ?');
        $stmt->execute([$uid]);
        $cur = (int)($stmt->fetchColumn() ?: 0);
        db()->prepare('UPDATE admin_users SET is_featured = ? WHERE id = ?')->execute([1 - $cur, $uid]);
        flash('Destaque atualizado.');
    } elseif ($uid !== current_user_id()) {
        match ($action) {
            'ban'        => db()->prepare('UPDATE admin_users SET is_banned = 1 WHERE id = ?')->execute([$uid]),
            'unban'      => db()->prepare('UPDATE admin_users SET is_banned = 0 WHERE id = ?')->execute([$uid]),
            'make_super' => db()->prepare('UPDATE admin_users SET is_superadmin = 1 WHERE id = ?')->execute([$uid]),
            'rm_super'   => db()->prepare('UPDATE admin_users SET is_superadmin = 0 WHERE id = ?')->execute([$uid]),
            default      => null,
        };
        flash('Usuário atualizado.');
    }
    redirect('/admin/users');
}

// ─── LIST: search + filter + pagination (server-side, query param) ───────────
$valid_status = ['all', 'active', 'banned', 'super', 'featured'];
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, $valid_status, true)) {
    $status = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$per_page = 24;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Build WHERE
$conds  = [];
$params = [];
if ($q !== '') {
    $conds[] = '(username LIKE ? OR display_name LIKE ? OR email LIKE ? OR slug LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
switch ($status) {
    case 'active':   $conds[] = 'is_banned = 0'; break;
    case 'banned':   $conds[] = 'is_banned = 1'; break;
    case 'super':    $conds[] = 'is_superadmin = 1'; break;
    case 'featured': $conds[] = 'is_featured = 1'; break;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

// Total count for pagination
$count_stmt = db()->prepare("SELECT COUNT(*) FROM admin_users $where");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($page - 1) * $per_page;

// Page rows
$list_stmt = db()->prepare(
    "SELECT id, username, slug, display_name, email, avatar,
            is_banned, is_superadmin, is_featured, created_at,
            (SELECT COUNT(*) FROM miniatures WHERE user_id = admin_users.id) AS mini_count
     FROM admin_users $where
     ORDER BY created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$list_stmt->execute($params);
$users = $list_stmt->fetchAll();

// Global stats (independent of filters)
$stats = db()->query(
    'SELECT COUNT(*) AS total,
            SUM(is_banned = 1) AS banned,
            SUM(is_superadmin = 1) AS supers,
            SUM(is_featured = 1) AS featured
     FROM admin_users'
)->fetch();

// Filter tabs
$filter_tabs = [
    'all'      => ['label' => 'Todos',      'icon' => 'fa-users'],
    'active'   => ['label' => 'Ativos',     'icon' => 'fa-circle-check'],
    'banned'   => ['label' => 'Banidos',    'icon' => 'fa-ban'],
    'super'    => ['label' => 'Superadmin', 'icon' => 'fa-crown'],
    'featured' => ['label' => 'Destacados', 'icon' => 'fa-star'],
];

// Helper: build a URL preserving current query, overriding given keys
$users_url = static function (array $over = []) use ($q, $status, $page): string {
    $qs = array_merge(['q' => $q, 'status' => $status, 'page' => $page], $over);
    $qs = array_filter($qs, static fn($v) => $v !== '' && $v !== null);
    return APP_URL . '/admin/users' . ($qs ? '?' . http_build_query($qs) : '');
};

$page_title = 'Usuários';
require_once __DIR__ . '/../includes/header_admin.php';

$flash_data = get_flash();
?>

<div class="users-hero dash-hero">
    <div class="users-hero-ico"><i class="fa fa-users"></i></div>
    <div class="users-hero-text">
        <span class="lp-eyebrow">Administração</span>
        <h1 class="users-hero-title">Colecionadores</h1>
        <p class="users-hero-sub">
            <strong><?= (int) $stats['total'] ?></strong> cadastrado<?= (int) $stats['total'] !== 1 ? 's' : '' ?>
            · <?= (int) $stats['supers'] ?> superadmin
            · <?= (int) $stats['featured'] ?> destacado<?= (int) $stats['featured'] !== 1 ? 's' : '' ?>
            · <?= (int) $stats['banned'] ?> banido<?= (int) $stats['banned'] !== 1 ? 's' : '' ?>
        </p>
    </div>
</div>

<?php if ($flash_data): ?>
    <div class="users-alert users-alert-<?= $flash_data['type'] === 'danger' ? 'error' : 'ok' ?>">
        <i class="fa <?= $flash_data['type'] === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <?= e($flash_data['message']) ?>
    </div>
<?php endif; ?>

<div class="users-toolbar">
    <form method="get" class="users-search" action="<?= h(APP_URL) ?>/admin/users">
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <span class="users-search-ico"><i class="fa fa-magnifying-glass"></i></span>
        <input type="text" name="q" value="<?= e($q) ?>" class="amf-input users-search-input"
               placeholder="Buscar por nome, e-mail ou slug…" autocomplete="off">
        <?php if ($q !== ''): ?>
            <a href="<?= h($users_url(['q' => '', 'page' => 1])) ?>" class="users-search-clear" title="Limpar">
                <i class="fa fa-xmark"></i>
            </a>
        <?php endif; ?>
        <button type="submit" class="md-btn md-btn-primary">Buscar</button>
    </form>
    <div class="users-filters">
        <?php foreach ($filter_tabs as $key => $tab): ?>
            <a href="<?= h($users_url(['status' => $key, 'page' => 1])) ?>"
               class="users-filter <?= $status === $key ? 'is-active' : '' ?>">
                <i class="fa <?= h($tab['icon']) ?>"></i><?= h($tab['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($users)): ?>
    <div class="users-empty">
        <div class="users-empty-ico"><i class="fa fa-user-slash"></i></div>
        <p class="users-empty-title">Nenhum colecionador encontrado</p>
        <p class="users-empty-sub">Ajuste a busca ou os filtros para ver mais resultados.</p>
        <?php if ($q !== '' || $status !== 'all'): ?>
            <a href="<?= h(APP_URL) ?>/admin/users" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar filtros</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="users-grid">
        <?php foreach ($users as $u): ?>
            <?php
            $is_self = ((int) $u['id'] === current_user_id());
            $name    = $u['display_name'] !== '' ? $u['display_name'] : $u['username'];
            $avatar  = avatar_url($u['avatar'] ?? null);
            ?>
            <article class="users-card <?= $u['is_banned'] ? 'is-banned' : '' ?>">
                <header class="users-card-head">
                    <span class="users-avatar">
                        <img src="<?= h($avatar) ?>" alt="" loading="lazy">
                        <?php if ($u['is_superadmin']): ?>
                            <span class="users-avatar-badge users-badge-super" title="Superadmin"><i class="fa fa-crown"></i></span>
                        <?php elseif ($u['is_banned']): ?>
                            <span class="users-avatar-badge users-badge-banned" title="Banido"><i class="fa fa-ban"></i></span>
                        <?php endif; ?>
                    </span>
                    <div class="users-card-id">
                        <div class="users-card-name">
                            <?= e($name) ?>
                            <?php if ($is_self): ?><span class="users-tag users-tag-you">você</span><?php endif; ?>
                        </div>
                        <div class="users-card-handle">@<?= e($u['slug']) ?></div>
                        <div class="users-card-email"><?= e($u['email']) ?></div>
                    </div>
                </header>

                <div class="users-card-chips">
                    <?php if ($u['is_superadmin']): ?>
                        <span class="users-chip users-chip-super"><i class="fa fa-crown"></i>Super</span>
                    <?php endif; ?>
                    <?php if ($u['is_featured']): ?>
                        <span class="users-chip users-chip-featured"><i class="fa fa-star"></i>Destaque</span>
                    <?php endif; ?>
                    <?php if ($u['is_banned']): ?>
                        <span class="users-chip users-chip-banned"><i class="fa fa-ban"></i>Banido</span>
                    <?php else: ?>
                        <span class="users-chip users-chip-active"><i class="fa fa-circle-check"></i>Ativo</span>
                    <?php endif; ?>
                </div>

                <div class="users-card-meta">
                    <span class="users-meta-item"><i class="fa fa-car"></i><?= (int) $u['mini_count'] ?> peça<?= (int) $u['mini_count'] !== 1 ? 's' : '' ?></span>
                    <span class="users-meta-item"><i class="fa fa-calendar"></i><?= date('d/m/Y', strtotime($u['created_at'])) ?></span>
                </div>

                <details class="users-slug">
                    <summary class="users-slug-toggle"><i class="fa fa-pen"></i>Editar slug</summary>
                    <form method="post" class="users-slug-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="action" value="edit_slug">
                        <div class="users-slug-input">
                            <span class="users-slug-prefix">/u/</span>
                            <input type="text" name="slug" value="<?= e($u['slug']) ?>" class="amf-input"
                                   pattern="[a-z0-9_\-]{2,30}" required>
                        </div>
                        <button type="submit" class="md-btn md-btn-primary"><i class="fa fa-check"></i>Salvar</button>
                    </form>
                </details>

                <footer class="users-card-actions">
                    <a href="<?= h(APP_URL) ?>/u/<?= e($u['slug']) ?>" target="_blank" rel="noopener" class="md-btn">
                        <i class="fa fa-up-right-from-square"></i>Ver garagem
                    </a>
                    <form method="post" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <button name="action" value="toggle_featured"
                                class="md-btn <?= $u['is_featured'] ? 'md-btn-primary' : '' ?>"
                                title="<?= $u['is_featured'] ? 'Remover destaque da landing' : 'Destacar na landing' ?>">
                            <i class="fa fa-star"></i><?= $u['is_featured'] ? 'Destacado' : 'Destacar' ?>
                        </button>
                    </form>
                    <?php if (!$is_self): ?>
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <?php if ($u['is_banned']): ?>
                                <button name="action" value="unban" class="md-btn"><i class="fa fa-unlock"></i>Desbanir</button>
                            <?php else: ?>
                                <button name="action" value="ban" class="md-btn users-btn-danger"
                                        onclick="return confirm('Banir <?= e($u['username']) ?>?')"><i class="fa fa-ban"></i>Banir</button>
                            <?php endif; ?>
                        </form>
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <?php if ($u['is_superadmin']): ?>
                                <button name="action" value="rm_super" class="md-btn"
                                        onclick="return confirm('Remover privilégio Super Admin?')"><i class="fa fa-crown"></i>Rebaixar</button>
                            <?php else: ?>
                                <button name="action" value="make_super" class="md-btn"
                                        onclick="return confirm('Promover a Super Admin?')"><i class="fa fa-crown"></i>Promover</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <nav class="users-pagination" aria-label="Paginação">
            <a class="users-page <?= $page <= 1 ? 'is-disabled' : '' ?>"
               href="<?= $page <= 1 ? '#' : h($users_url(['page' => $page - 1])) ?>"
               <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                <i class="fa fa-chevron-left"></i>
            </a>
            <span class="users-page-info">Página <?= (int) $page ?> de <?= (int) $total_pages ?></span>
            <a class="users-page <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
               href="<?= $page >= $total_pages ? '#' : h($users_url(['page' => $page + 1])) ?>"
               <?= $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                <i class="fa fa-chevron-right"></i>
            </a>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
