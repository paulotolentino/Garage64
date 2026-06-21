<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$uid = current_user_id();

// ─── Save (create / edit) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('Nome é obrigatório.', 'danger');
        redirect('/admin/categories');
    }
    // Case-insensitive duplicate check, scoped to this user.
    $dup = db()->prepare('SELECT id FROM categories WHERE user_id = ? AND LOWER(name) = LOWER(?) AND id != ?');
    $dup->execute([$uid, $name, $id]);
    if ($dup->fetch()) {
        flash('Já existe uma categoria com esse nome.', 'danger');
        redirect($id ? '/admin/categories?edit=' . $id : '/admin/categories');
    }
    if ($id) {
        db()->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?')->execute([$name, $id, $uid]);
        flash('Categoria atualizada.');
    } else {
        db()->prepare('INSERT INTO categories (name, user_id) VALUES (?, ?)')->execute([$name, $uid]);
        flash('Categoria criada.');
    }
    redirect('/admin/categories');
}

// ─── Delete (POST + CSRF) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        db()->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('Categoria removida.');
    }
    redirect('/admin/categories');
}

// ─── Editing target ───────────────────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $uid]);
    $editing = $stmt->fetch() ?: null;
}

// ─── Search ───────────────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));

// ─── List with usage count (inline, no functions.php change) ─────────────────
$where  = 'c.user_id = ?';
$params = [$uid, $uid]; // first for subquery, second for WHERE user_id
if ($q !== '') {
    $where   .= ' AND c.name LIKE ?';
    $params[] = '%' . $q . '%';
}
$stmt = db()->prepare(
    "SELECT c.id, c.name, c.created_at,
            (SELECT COUNT(*) FROM miniatures WHERE category_id = c.id AND user_id = ?) AS use_count
     FROM categories c
     WHERE $where
     ORDER BY c.name ASC"
);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$total_cats = (int) db()->query('SELECT COUNT(*) FROM categories WHERE user_id = ' . (int) $uid)->fetchColumn();

$page_title = 'Categorias';
require_once __DIR__ . '/../includes/header_admin.php';

$flash_data = get_flash();
?>

<div class="org-hero dash-hero">
    <div class="org-hero-ico"><i class="fa fa-tags"></i></div>
    <div class="org-hero-text">
        <span class="lp-eyebrow">Organização</span>
        <h1 class="org-hero-title">Categorias</h1>
        <p class="org-hero-sub">
            <strong><?= $total_cats ?></strong> categoria<?= $total_cats !== 1 ? 's' : '' ?> para organizar a sua garagem.
        </p>
    </div>
</div>

<?php if ($flash_data): ?>
    <div class="org-alert org-alert-<?= $flash_data['type'] === 'danger' ? 'error' : 'ok' ?>">
        <i class="fa <?= $flash_data['type'] === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <?= e($flash_data['message']) ?>
    </div>
<?php endif; ?>

<div class="org-form-card">
    <div class="org-form-head">
        <i class="fa <?= $editing ? 'fa-pen' : 'fa-plus' ?>"></i>
        <span><?= $editing ? 'Editar categoria' : 'Nova categoria' ?></span>
    </div>
    <form method="post" class="org-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
        <input type="text" name="name" class="amf-input org-form-input"
               placeholder="Ex.: Esportivos, Clássicos, Picapes…" required autofocus
               value="<?= $editing ? e($editing['name']) : '' ?>">
        <button type="submit" class="md-btn md-btn-primary">
            <i class="fa <?= $editing ? 'fa-check' : 'fa-plus' ?>"></i><?= $editing ? 'Salvar' : 'Adicionar' ?>
        </button>
        <?php if ($editing): ?>
            <a href="<?= h(APP_URL) ?>/admin/categories" class="md-btn">Cancelar</a>
        <?php endif; ?>
    </form>
</div>

<form method="get" class="org-search" action="<?= h(APP_URL) ?>/admin/categories">
    <span class="org-search-ico"><i class="fa fa-magnifying-glass"></i></span>
    <input type="text" name="q" value="<?= e($q) ?>" class="amf-input org-search-input"
           placeholder="Buscar categoria…" autocomplete="off">
    <?php if ($q !== ''): ?>
        <a href="<?= h(APP_URL) ?>/admin/categories" class="org-search-clear" title="Limpar"><i class="fa fa-xmark"></i></a>
    <?php endif; ?>
    <button type="submit" class="md-btn">Buscar</button>
</form>

<?php if (empty($categories)): ?>
    <div class="org-empty">
        <div class="org-empty-ico"><i class="fa fa-tags"></i></div>
        <p class="org-empty-title"><?= $q !== '' ? 'Nada encontrado' : 'Nenhuma categoria ainda' ?></p>
        <p class="org-empty-sub">
            <?= $q !== '' ? 'Tente outro termo de busca.' : 'Crie a primeira categoria no campo acima para organizar suas miniaturas.' ?>
        </p>
        <?php if ($q !== ''): ?>
            <a href="<?= h(APP_URL) ?>/admin/categories" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar busca</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="org-grid">
        <?php foreach ($categories as $cat): ?>
            <?php $used = (int) $cat['use_count']; ?>
            <article class="org-card <?= $used === 0 ? 'is-unused' : '' ?>">
                <div class="org-card-main">
                    <span class="org-card-ico"><i class="fa fa-tag"></i></span>
                    <div class="org-card-info">
                        <div class="org-card-name"><?= e($cat['name']) ?></div>
                        <div class="org-card-count">
                            <?php if ($used > 0): ?>
                                <i class="fa fa-car"></i><?= $used ?> miniatura<?= $used !== 1 ? 's' : '' ?>
                            <?php else: ?>
                                <i class="fa fa-circle-minus"></i>sem uso
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="org-card-actions">
                    <a href="<?= h(APP_URL) ?>/admin/categories?edit=<?= (int) $cat['id'] ?>" class="org-iconbtn" title="Editar">
                        <i class="fa fa-pen"></i>
                    </a>
                    <form method="post" class="m-0"
                          onsubmit="return confirm('Remover a categoria “<?= e($cat['name']) ?>”?<?= $used > 0 ? ' ' . $used . ' miniatura(s) ficarão sem categoria.' : '' ?>')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                        <button type="submit" class="org-iconbtn org-iconbtn-danger" title="Remover">
                            <i class="fa fa-trash"></i>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
