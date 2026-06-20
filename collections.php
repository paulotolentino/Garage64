<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$collections = get_all_collections();
$page_title  = 'Coleções';
require_once __DIR__ . '/includes/header_public.php';
?>

<div class="d-flex align-items-center mb-4">
    <h1 class="h3 mb-0 me-auto"><i class="fa fa-users me-2 text-warning"></i>Coleções</h1>
    <span class="badge bg-secondary fs-6"><?= count($collections) ?> coleção<?= count($collections) !== 1 ? 'ões' : '' ?></span>
</div>

<?php if (empty($collections)): ?>
    <div class="text-center text-secondary py-5">
        <i class="fa fa-users fa-3x mb-3 opacity-25 d-block"></i>
        <p class="h5">Nenhuma coleção pública ainda.</p>
        <a href="/register" class="btn btn-warning mt-3">Criar minha coleção</a>
    </div>
<?php else: ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
        <?php foreach ($collections as $col): ?>
            <div class="col">
                <a href="/u/<?= e($col['slug']) ?>" class="text-decoration-none">
                    <div class="card h-100 bg-dark border-secondary mini-card">
                        <div class="card-body d-flex flex-column align-items-center text-center p-3">
                            <?php if (!empty($col['avatar'])): ?>
                                <img src="<?= e(avatar_url($col['avatar'])) ?>"
                                     alt="<?= e($col['display_name'] ?: $col['slug']) ?>"
                                     class="rounded-circle mb-3 object-fit-cover"
                                     style="width:80px;height:80px;object-fit:cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center mb-3 fw-bold text-dark fs-3"
                                     style="width:80px;height:80px;flex-shrink:0;">
                                    <?= mb_strtoupper(mb_substr($col['display_name'] ?: $col['slug'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-light fw-semibold"><?= e($col['display_name'] ?: $col['slug']) ?></div>
                            <div class="text-secondary small mt-1">@<?= e($col['slug']) ?></div>
                            <?php if (!empty($col['bio'])): ?>
                                <div class="text-secondary small mt-2" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                    <?= e($col['bio']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-dark border-secondary text-center py-2">
                            <span class="text-warning fw-semibold"><?= (int)$col['mini_count'] ?></span>
                            <span class="text-secondary small"> peça<?= (int)$col['mini_count'] !== 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
