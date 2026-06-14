<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Admin') ?> — <?= h(APP_NAME) ?> Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="admin-layout">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= h(APP_URL) ?>/admin/">
            <i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?>
            <small class="text-muted ms-1 fs-6">Admin</small>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navAdmin">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navAdmin">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/"><i class="fa fa-tachometer-alt me-1"></i>Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/miniatures.php"><i class="fa fa-car me-1"></i>Miniaturas</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/wishlist.php"><i class="fa fa-heart me-1"></i>Wishlist</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/categories.php"><i class="fa fa-tags me-1"></i>Categorias</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/tags.php"><i class="fa fa-tag me-1"></i>Tags</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/" target="_blank"><i class="fa fa-eye me-1"></i>Ver público</a>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <span class="nav-link text-secondary"><i class="fa fa-user me-1"></i><?= e(admin_username()) ?></span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= h(APP_URL) ?>/admin/logout.php"><i class="fa fa-sign-out-alt me-1"></i>Sair</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container-fluid py-4">
<?php
$flash = get_flash();
if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show mx-3" role="alert">
    <?= h($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
