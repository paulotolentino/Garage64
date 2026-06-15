<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) {
        flash('Nome é obrigatório.', 'danger');
        redirect('/admin/tags.php');
    }
    if ($id) {
        db()->prepare('UPDATE tags SET name = ? WHERE id = ?')->execute([$name, $id]);
        flash('Tag atualizada.');
    } else {
        db()->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$name]);
        flash('Tag criada.');
    }
    redirect('/admin/tags.php');
}

// Delete
if (isset($_GET['delete'])) {
    db()->prepare('DELETE FROM tags WHERE id = ?')->execute([(int) $_GET['delete']]);
    flash('Tag removida.');
    redirect('/admin/tags.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$tags       = get_tags();
$page_title = 'Tags';

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="row g-4">
    <div class="col-12 col-md-7">
        <h1 class="h4 mb-3"><i class="fa fa-tag me-2 text-warning"></i>Tags</h1>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($tags as $tag): ?>
                <div class="d-flex align-items-center badge bg-secondary gap-1 p-2">
                    <span><?= e($tag['name']) ?></span>
                    <a href="/admin/tags.php?edit=<?= $tag['id'] ?>" class="text-warning ms-1"><i class="fa fa-edit fa-xs"></i></a>
                    <a href="/admin/tags.php?delete=<?= $tag['id'] ?>" class="text-danger ms-1"
                       onclick="return confirm('Remover tag?')"><i class="fa fa-times fa-xs"></i></a>
                </div>
            <?php endforeach; ?>
            <?php if (empty($tags)): ?>
                <span class="text-secondary">Nenhuma tag cadastrada.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-md-5">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary"><?= $editing ? 'Editar tag' : 'Nova tag' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
                    <div class="mb-3">
                        <label class="form-label text-secondary">Nome *</label>
                        <input type="text" name="name" class="form-control bg-dark text-light border-secondary"
                               required autofocus value="<?= $editing ? e($editing['name']) : '' ?>">
                    </div>
                    <button type="submit" class="btn btn-warning"><?= $editing ? 'Salvar' : 'Criar' ?></button>
                    <?php if ($editing): ?>
                        <a href="/admin/tags.php" class="btn btn-outline-secondary ms-2">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
