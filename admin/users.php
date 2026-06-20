<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_superadmin();

$RESERVED_SLUGS = ['admin','register','login','logout','install','setup','sitemap','robots','mini','u','collections','assets','uploads','database','includes','api'];

// ─── POST ACTIONS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid    = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$uid) { redirect('/admin/users'); }

    if ($action === 'edit_slug') {
        $new_slug = strtolower(trim($_POST['slug'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $new_slug) || in_array($new_slug, $RESERVED_SLUGS)) {
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

// ─── LIST ─────────────────────────────────────────────────────────────────────
$users = db()->query(
    'SELECT id, username, slug, display_name, email, is_banned, is_superadmin, is_featured, created_at,
            (SELECT COUNT(*) FROM miniatures WHERE user_id = admin_users.id) AS mini_count
     FROM admin_users ORDER BY created_at DESC'
)->fetchAll();

$page_title = 'Usuários';
require_once __DIR__ . '/../includes/header_admin.php';

$flash_data = get_flash();
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <h1 class="h4 mb-0 me-auto"><i class="fa fa-users me-2 text-warning"></i>Usuários</h1>
    <span class="text-secondary small"><?= count($users) ?> cadastrado<?= count($users) !== 1 ? 's' : '' ?></span>
</div>

<?php if ($flash_data): ?>
    <div class="alert alert-<?= $flash_data['type'] === 'danger' ? 'danger' : 'success' ?> py-2"><?= e($flash_data['message']) ?></div>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-dark table-hover table-sm align-middle">
    <thead>
        <tr>
            <th>Usuário</th>
            <th>E-mail</th>
            <th>Peças</th>
            <th>Slug (URL pública)</th>
            <th>Status</th>
            <th>Cadastro</th>
            <th class="text-end">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_banned'] ? 'opacity-50' : '' ?>">
            <td>
                <span class="text-light fw-semibold"><?= e($u['username']) ?></span>
                <?php if ($u['is_superadmin']): ?>
                    <span class="badge bg-warning text-dark ms-1"><i class="fa fa-crown"></i> Super</span>
                <?php endif; ?>
                <?php if ($u['id'] == current_user_id()): ?>
                    <span class="badge bg-secondary ms-1">você</span>
                <?php endif; ?>
            </td>
            <td class="text-secondary small"><?= e($u['email']) ?></td>
            <td>
                <a href="/admin/miniatures" class="text-secondary">
                    <?= (int)$u['mini_count'] ?>
                </a>
            </td>
            <td>
                <form method="post" class="d-flex gap-1 align-items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="action" value="edit_slug">
                    <span class="text-secondary small">/u/</span>
                    <input type="text" name="slug" value="<?= e($u['slug']) ?>"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           style="width:130px;" pattern="[a-z0-9_\-]{2,30}" required>
                    <button type="submit" class="btn btn-outline-warning btn-sm" title="Salvar slug">
                        <i class="fa fa-check"></i>
                    </button>
                    <a href="/u/<?= e($u['slug']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Ver público">
                        <i class="fa fa-external-link"></i>
                    </a>
                </form>
            </td>
            <td>
                <?php if ($u['is_banned']): ?>
                    <span class="badge bg-danger">Banido</span>
                <?php else: ?>
                    <span class="badge bg-success">Ativo</span>
                <?php endif; ?>
            </td>
            <td class="text-secondary small"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td class="text-end">
                <!-- Featured toggle (any user, including self) -->
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button name="action" value="toggle_featured"
                            class="btn btn-sm <?= $u['is_featured'] ? 'btn-warning' : 'btn-outline-secondary' ?>"
                            title="<?= $u['is_featured'] ? 'Remover destaque da landing page' : 'Destacar na landing page' ?>">
                        <i class="fa fa-star"></i>
                    </button>
                </form>
                <?php if ($u['id'] != current_user_id()): ?>
                <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <?php if ($u['is_banned']): ?>
                        <button name="action" value="unban" class="btn btn-outline-success btn-sm" title="Desbanir">
                            <i class="fa fa-check"></i>
                        </button>
                    <?php else: ?>
                        <button name="action" value="ban" class="btn btn-outline-danger btn-sm"
                                title="Banir" onclick="return confirm('Banir <?= e($u['username']) ?>?')">
                            <i class="fa fa-ban"></i>
                        </button>
                    <?php endif; ?>
                    <?php if (!$u['is_superadmin']): ?>
                        <button name="action" value="make_super" class="btn btn-outline-warning btn-sm"
                                title="Promover a Super Admin" onclick="return confirm('Promover a Super Admin?')">
                            <i class="fa fa-crown"></i>
                        </button>
                    <?php else: ?>
                        <button name="action" value="rm_super" class="btn btn-outline-secondary btn-sm"
                                title="Remover Super Admin" onclick="return confirm('Remover privilégio Super Admin?')">
                            <i class="fa fa-crown"></i>
                        </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
