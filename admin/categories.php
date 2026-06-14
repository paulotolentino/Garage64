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
        redirect('/admin/categories.php');
    }
    if ($id) {
        db()->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
        flash('Categoria atualizada.');
    } else {
        db()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        flash('Categoria criada.');
    }
    redirect('/admin/categories.php');
}

// Delete
if (isset($_GET['delete'])) {
    db()->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) $_GET['delete']]);
    flash('Categoria removida.');
    redirect('/admin/categories.php');
}

$editing    = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$categories = get_categories();
$page_title = 'Categorias';

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="row g-4">
    <div class="col-12 col-md-7">
        <h1 class="h4 mb-3"><i class="fa fa-tags me-2 text-warning"></i>Categorias</h1>
        <div class="table-responsive">
            <table class="table table-dark table-hover table-sm">
                <thead><tr><th>Nome</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="2" class="text-center text-secondary">Nenhuma categoria.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= e($cat['name']) ?></td>
                            <td class="text-end">
                                <a href="/admin/categories.php?edit=<?= $cat['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="fa fa-edit"></i></a>
                                <a href="/admin/categories.php?delete=<?= $cat['id'] ?>" class="btn btn-outline-danger btn-sm"
                                   onclick="return confirm('Remover categoria?')"><i class="fa fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12 col-md-5">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary"><?= $editing ? 'Editar categoria' : 'Nova categoria' ?></div>
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
                        <a href="/admin/categories.php" class="btn btn-outline-secondary ms-2">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
