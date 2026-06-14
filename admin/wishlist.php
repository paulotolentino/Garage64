<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// ─── Wishlist → Miniature conversion ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_id'])) {
    verify_csrf();
    $id = (int) $_POST['convert_id'];
    $stmt = db()->prepare('SELECT * FROM wishlist WHERE id = ?');
    $stmt->execute([$id]);
    $wish = $stmt->fetch();
    if ($wish) {
        $ins = db()->prepare(
            'INSERT INTO miniatures (name, manufacturer, scale, status, private_notes)
             VALUES (:name, :manufacturer, :scale, :status, :private_notes)'
        );
        $ins->execute([
            'name'          => $wish['name'],
            'manufacturer'  => $wish['manufacturer'] ?? '',
            'scale'         => $wish['scale'],
            'status'        => 'sealed',
            'private_notes' => $wish['notes'],
        ]);
        $mini_id = (int) db()->lastInsertId();
        db()->prepare("UPDATE wishlist SET status = 'purchased' WHERE id = ?")->execute([$id]);
        flash('Peça convertida para a coleção! Edite os detalhes agora.');
        redirect('/admin/miniatures.php?action=edit&id=' . $mini_id);
    }
}

// ─── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'manufacturer'  => trim($_POST['manufacturer'] ?? '') ?: null,
        'scale'         => trim($_POST['scale'] ?? '') ?: null,
        'target_price'  => strlen(trim($_POST['target_price'] ?? '')) ? (float) str_replace(',', '.', $_POST['target_price']) : null,
        'reference_url' => trim($_POST['reference_url'] ?? '') ?: null,
        'notes'         => trim($_POST['notes'] ?? '') ?: null,
        'status'        => $_POST['status'] ?? 'wanted',
    ];

    if (!$data['name']) {
        flash('Nome é obrigatório.', 'danger');
        redirect('/admin/wishlist.php');
    }

    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $data['id'] = $id;
        db()->prepare("UPDATE wishlist SET $sets WHERE id = :id")->execute($data);
        flash('Wishlist atualizada.');
    } else {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        db()->prepare("INSERT INTO wishlist ($cols) VALUES ($phs)")->execute($data);
        flash('Peça adicionada à wishlist.');
    }

    redirect('/admin/wishlist.php');
}

// ─── Delete ───────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    db()->prepare('DELETE FROM wishlist WHERE id = ?')->execute([(int) $_GET['delete']]);
    flash('Item removido da wishlist.');
    redirect('/admin/wishlist.php');
}

// ─── Edit ─────────────────────────────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM wishlist WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$filter_status = $_GET['status'] ?? '';
$wishlist      = get_wishlist($filter_status);
$page_title    = 'Wishlist';

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="row g-4">
    <!-- List -->
    <div class="col-12 col-lg-8">
        <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
            <h1 class="h4 mb-0 me-auto"><i class="fa fa-heart me-2 text-warning"></i>Wishlist</h1>
            <div class="btn-group btn-group-sm">
                <a href="/admin/wishlist.php" class="btn <?= !$filter_status ? 'btn-warning' : 'btn-outline-secondary' ?>">Todas</a>
                <a href="/admin/wishlist.php?status=wanted" class="btn <?= $filter_status === 'wanted' ? 'btn-warning' : 'btn-outline-secondary' ?>">Desejadas</a>
                <a href="/admin/wishlist.php?status=purchased" class="btn <?= $filter_status === 'purchased' ? 'btn-warning' : 'btn-outline-secondary' ?>">Compradas</a>
                <a href="/admin/wishlist.php?status=cancelled" class="btn <?= $filter_status === 'cancelled' ? 'btn-warning' : 'btn-outline-secondary' ?>">Canceladas</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover table-sm align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Fabricante</th>
                        <th>Escala</th>
                        <th>Preço alvo</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($wishlist)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">Nenhum item na wishlist.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($wishlist as $w): ?>
                        <tr>
                            <td>
                                <?= e($w['name']) ?>
                                <?php if ($w['reference_url']): ?>
                                    <a href="<?= e($w['reference_url']) ?>" target="_blank" class="text-secondary ms-1 small"><i class="fa fa-external-link-alt"></i></a>
                                <?php endif; ?>
                            </td>
                            <td><?= e($w['manufacturer'] ?? '—') ?></td>
                            <td><?= e($w['scale'] ?? '—') ?></td>
                            <td><?= $w['target_price'] ? 'R$ ' . number_format((float)$w['target_price'], 2, ',', '.') : '—' ?></td>
                            <td>
                                <?php $wclass = match($w['status']) { 'wanted' => 'warning', 'purchased' => 'success', 'cancelled' => 'secondary', default => 'light' }; ?>
                                <span class="badge bg-<?= $wclass ?> text-dark"><?= h(wishlist_status_label($w['status'])) ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($w['status'] === 'wanted'): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="convert_id" value="<?= $w['id'] ?>">
                                        <button type="submit" class="btn btn-outline-success btn-sm" title="Converter para coleção"
                                                onclick="return confirm('Marcar como comprada e adicionar à coleção?')">
                                            <i class="fa fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="/admin/wishlist.php?edit=<?= $w['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="fa fa-edit"></i></a>
                                <a href="/admin/wishlist.php?delete=<?= $w['id'] ?>" class="btn btn-outline-danger btn-sm"
                                   onclick="return confirm('Remover item?')"><i class="fa fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Form -->
    <div class="col-12 col-lg-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <?= $editing ? '<i class="fa fa-edit me-1 text-warning"></i>Editar item' : '<i class="fa fa-plus me-1 text-warning"></i>Adicionar à wishlist' ?>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
                    <div class="mb-3">
                        <label class="form-label text-secondary">Nome *</label>
                        <input type="text" name="name" class="form-control bg-dark text-light border-secondary"
                               required value="<?= $editing ? e($editing['name']) : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Fabricante</label>
                        <input type="text" name="manufacturer" class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing ? e($editing['manufacturer'] ?? '') : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Escala</label>
                        <input type="text" name="scale" class="form-control bg-dark text-light border-secondary"
                               placeholder="1:64" value="<?= $editing ? e($editing['scale'] ?? '') : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Preço desejado (R$)</label>
                        <input type="number" step="0.01" min="0" name="target_price"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing && $editing['target_price'] !== null ? e(number_format((float)$editing['target_price'], 2, '.', '')) : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Link de referência</label>
                        <input type="url" name="reference_url" class="form-control bg-dark text-light border-secondary"
                               value="<?= $editing ? e($editing['reference_url'] ?? '') : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Observações</label>
                        <textarea name="notes" rows="2" class="form-control bg-dark text-light border-secondary"><?= $editing ? e($editing['notes'] ?? '') : '' ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary">Status</label>
                        <select name="status" class="form-select bg-dark text-light border-secondary">
                            <option value="wanted" <?= $editing && $editing['status'] === 'wanted' ? 'selected' : (!$editing ? 'selected' : '') ?>>Desejada</option>
                            <option value="purchased" <?= $editing && $editing['status'] === 'purchased' ? 'selected' : '' ?>>Comprada</option>
                            <option value="cancelled" <?= $editing && $editing['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelada</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fa fa-save me-1"></i><?= $editing ? 'Salvar' : 'Adicionar' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="/admin/wishlist.php" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
