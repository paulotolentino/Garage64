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
        redirect('/admin/tags');
    }
    // Case-insensitive duplicate check, scoped to this user.
    $dup = db()->prepare('SELECT id FROM tags WHERE user_id = ? AND LOWER(name) = LOWER(?) AND id != ?');
    $dup->execute([$uid, $name, $id]);
    if ($dup->fetch()) {
        flash('Já existe uma tag com esse nome.', 'danger');
        redirect($id ? '/admin/tags?edit=' . $id : '/admin/tags');
    }
    if ($id) {
        db()->prepare('UPDATE tags SET name = ? WHERE id = ? AND user_id = ?')->execute([$name, $id, $uid]);
        flash('Tag atualizada.');
    } else {
        db()->prepare('INSERT INTO tags (name, user_id) VALUES (?, ?)')->execute([$name, $uid]);
        flash('Tag criada.');
    }
    redirect('/admin/tags');
}

// ─── Delete (POST + CSRF) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        db()->prepare('DELETE FROM tags WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('Tag removida.');
    }
    redirect('/admin/tags');
}

// ─── Editing target ───────────────────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM tags WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $uid]);
    $editing = $stmt->fetch() ?: null;
}

// ─── Search ───────────────────────────────────────────────────────────────────
$q = trim((string) ($_GET['q'] ?? ''));

// ─── List with usage count (inline, no functions.php change) ─────────────────
$where  = 't.user_id = ?';
$params = [$uid, $uid]; // first for subquery, second for WHERE user_id
if ($q !== '') {
    $where   .= ' AND t.name LIKE ?';
    $params[] = '%' . $q . '%';
}
$stmt = db()->prepare(
    "SELECT t.id, t.name, t.created_at,
            (SELECT COUNT(*) FROM miniature_tags mt
             INNER JOIN miniatures m ON m.id = mt.miniature_id
             WHERE mt.tag_id = t.id AND m.user_id = ?) AS use_count
     FROM tags t
     WHERE $where
     ORDER BY t.name ASC"
);
$stmt->execute($params);
$tags = $stmt->fetchAll();

$total_tags = (int) db()->query('SELECT COUNT(*) FROM tags WHERE user_id = ' . (int) $uid)->fetchColumn();

// Max usage for relative pill sizing.
$max_use = 0;
foreach ($tags as $t) { $max_use = max($max_use, (int) $t['use_count']); }

$page_title = 'Tags';
require_once __DIR__ . '/../includes/header_admin.php';

$flash_data = get_flash();
?>

<div class="org-hero dash-hero">
    <div class="org-hero-ico"><i class="fa fa-tag"></i></div>
    <div class="org-hero-text">
        <span class="lp-eyebrow">Organização</span>
        <h1 class="org-hero-title">Tags</h1>
        <p class="org-hero-sub">
            <strong><?= $total_tags ?></strong> tag<?= $total_tags !== 1 ? 's' : '' ?> para detalhar a sua coleção.
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
        <span><?= $editing ? 'Editar tag' : 'Nova tag' ?></span>
    </div>
    <form method="post" class="org-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
        <input type="text" name="name" class="amf-input org-form-input"
               placeholder="Ex.: JDM, Raridade, Edição limitada…" required autofocus
               value="<?= $editing ? e($editing['name']) : '' ?>">
        <button type="submit" class="md-btn md-btn-primary">
            <i class="fa <?= $editing ? 'fa-check' : 'fa-plus' ?>"></i><?= $editing ? 'Salvar' : 'Adicionar' ?>
        </button>
        <?php if ($editing): ?>
            <a href="<?= h(APP_URL) ?>/admin/tags" class="md-btn">Cancelar</a>
        <?php endif; ?>
    </form>
</div>

<form method="get" class="org-search" action="<?= h(APP_URL) ?>/admin/tags">
    <span class="org-search-ico"><i class="fa fa-magnifying-glass"></i></span>
    <input type="text" name="q" value="<?= e($q) ?>" class="amf-input org-search-input"
           placeholder="Buscar tag…" autocomplete="off">
    <?php if ($q !== ''): ?>
        <a href="<?= h(APP_URL) ?>/admin/tags" class="org-search-clear" title="Limpar"><i class="fa fa-xmark"></i></a>
    <?php endif; ?>
    <button type="submit" class="md-btn">Buscar</button>
</form>

<?php if (empty($tags)): ?>
    <div class="org-empty">
        <div class="org-empty-ico"><i class="fa fa-tag"></i></div>
        <p class="org-empty-title"><?= $q !== '' ? 'Nada encontrado' : 'Nenhuma tag ainda' ?></p>
        <p class="org-empty-sub">
            <?= $q !== '' ? 'Tente outro termo de busca.' : 'Crie a primeira tag no campo acima para detalhar suas miniaturas.' ?>
        </p>
        <?php if ($q !== ''): ?>
            <a href="<?= h(APP_URL) ?>/admin/tags" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar busca</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="org-tags">
        <?php foreach ($tags as $tag): ?>
            <?php
            $used = (int) $tag['use_count'];
            $level = 0;
            if ($max_use > 0 && $used > 0) {
                $ratio = $used / $max_use;
                $level = $ratio >= 0.66 ? 3 : ($ratio >= 0.33 ? 2 : 1);
            }
            ?>
            <div class="org-tag org-tag-l<?= $level ?> <?= $used === 0 ? 'is-unused' : '' ?>">
                <span class="org-tag-name"><i class="fa fa-hashtag"></i><?= e($tag['name']) ?></span>
                <span class="org-tag-count"><?= $used ?></span>
                <span class="org-tag-actions">
                    <a href="<?= h(APP_URL) ?>/admin/tags?edit=<?= (int) $tag['id'] ?>" class="org-tag-btn" title="Editar">
                        <i class="fa fa-pen"></i>
                    </a>
                    <form method="post" class="m-0"
                          onsubmit="return confirm('Remover a tag “<?= e($tag['name']) ?>”?<?= $used > 0 ? ' Ela será desvinculada de ' . $used . ' miniatura(s).' : '' ?>')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $tag['id'] ?>">
                        <button type="submit" class="org-tag-btn org-tag-btn-danger" title="Remover">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </form>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
