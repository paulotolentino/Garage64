<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect('/');
}

$miniature = get_miniature($id);
if (!$miniature) {
    http_response_code(404);
    $page_title = 'Não encontrada';
    require_once __DIR__ . '/includes/header_public.php';
    echo '<div class="text-center py-5"><h2>Miniatura não encontrada.</h2><a href="/" class="btn btn-warning mt-3">Voltar</a></div>';
    require_once __DIR__ . '/includes/footer_public.php';
    exit;
}

$photos = get_miniature_photos($id);
$tags   = get_miniature_tags($id);
$page_title = $miniature['name'];

require_once __DIR__ . '/includes/header_public.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/" class="text-warning">Coleção</a></li>
        <li class="breadcrumb-item active"><?= e($miniature['name']) ?></li>
    </ol>
</nav>

<div class="row g-4">
    <!-- Photo Gallery -->
    <div class="col-12 col-md-5">
        <?php
        $primary = null;
        $gallery = [];
        foreach ($photos as $p) {
            if ($p['is_primary']) {
                $primary = $p;
            } else {
                $gallery[] = $p;
            }
        }
        if (!$primary && !empty($photos)) {
            $primary = array_shift($photos);
        }
        ?>

        <?php if ($primary): ?>
            <div class="text-center mb-3">
                <img src="<?= e(photo_url($primary['file_path'])) ?>"
                     id="mainPhoto"
                     alt="<?= e($miniature['name']) ?>"
                     class="img-fluid rounded shadow"
                     style="max-height:400px; width:100%; object-fit:cover;">
            </div>
        <?php else: ?>
            <div class="text-center mb-3">
                <img src="<?= e(photo_url(null)) ?>"
                     alt="Sem foto"
                     class="img-fluid rounded bg-dark"
                     style="max-height:400px; width:100%; object-fit:contain;">
            </div>
        <?php endif; ?>

        <?php if (!empty($gallery)): ?>
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <?php foreach ($gallery as $gp): ?>
                    <img src="<?= e(photo_url($gp['file_path'])) ?>"
                         alt=""
                         class="rounded gallery-thumb"
                         style="width:70px; height:70px; object-fit:cover; cursor:pointer;"
                         onclick="document.getElementById('mainPhoto').src=this.src">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details -->
    <div class="col-12 col-md-7">
        <div class="mb-1 text-warning fw-bold"><?= e($miniature['manufacturer']) ?></div>
        <h1 class="h2 mb-1"><?= e($miniature['name']) ?></h1>
        <?php if ($miniature['model']): ?>
            <div class="text-secondary mb-2"><?= e($miniature['model']) ?></div>
        <?php endif; ?>

        <div class="mb-3"><?= status_badge($miniature['status']) ?></div>

        <table class="table table-sm table-dark table-borderless">
            <?php if ($miniature['scale']): ?>
            <tr>
                <th class="text-secondary w-40">Escala</th>
                <td><?= e($miniature['scale']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($miniature['year']): ?>
            <tr>
                <th class="text-secondary">Ano</th>
                <td><?= e((string)$miniature['year']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($miniature['category_name']): ?>
            <tr>
                <th class="text-secondary">Categoria</th>
                <td><?= e($miniature['category_name']) ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if (!empty($tags)): ?>
            <div class="mb-3">
                <?php foreach ($tags as $tag): ?>
                    <a href="/?tag_id=<?= $tag['id'] ?>" class="badge bg-secondary text-decoration-none me-1"><?= e($tag['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($miniature['public_description']): ?>
            <div class="card bg-dark border-secondary mt-3">
                <div class="card-body">
                    <p class="card-text"><?= nl2br(e($miniature['public_description'])) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="/" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Voltar à coleção</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
