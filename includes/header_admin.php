<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Admin') ?> — <?= h(APP_NAME) ?> Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(APP_URL) ?>/assets/img/favicon-cart.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="admin-layout">

<!-- Mobile topbar -->
<header class="admin-topbar d-lg-none">
    <a class="admin-topbar-brand" href="<?= h(APP_URL) ?>/admin/">
        <i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?>
    </a>
    <button class="admin-sidebar-toggle" id="sidebarToggle" aria-label="Menu">
        <i class="fa fa-bars"></i>
    </button>
</header>

<!-- Sidebar overlay (mobile) -->
<div class="admin-sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-brand">
        <i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?>
        <small class="d-block text-secondary mt-1" style="font-size:.7rem;letter-spacing:.08em">ADMIN PANEL</small>
    </div>

    <nav class="admin-sidebar-nav">
        <?php
        $cur = basename($_SERVER['PHP_SELF']);
        $is  = fn(string $f): string => ($cur === $f ? 'active' : '');
        ?>
        <div class="admin-nav-section">Coleção</div>
        <a href="<?= h(APP_URL) ?>/admin/" class="admin-nav-link <?= $cur === 'index.php' ? 'active' : '' ?>">
            <i class="fa fa-tachometer-alt"></i>Dashboard
        </a>
        <a href="<?= h(APP_URL) ?>/admin/miniatures" class="admin-nav-link <?= $is('miniatures.php') ?>">
            <i class="fa fa-car"></i>Miniaturas
        </a>
        <a href="<?= h(APP_URL) ?>/admin/wishlist" class="admin-nav-link <?= $is('wishlist.php') ?>">
            <i class="fa fa-heart"></i>Wishlist
        </a>

        <div class="admin-nav-section">Configurações</div>
        <a href="<?= h(APP_URL) ?>/admin/categories" class="admin-nav-link <?= $is('categories.php') ?>">
            <i class="fa fa-tags"></i>Categorias
        </a>
        <a href="<?= h(APP_URL) ?>/admin/tags" class="admin-nav-link <?= $is('tags.php') ?>">
            <i class="fa fa-tag"></i>Tags
        </a>

        <div class="admin-nav-section">Ferramentas</div>
        <a href="<?= h(APP_URL) ?>/admin/migrate_webp" class="admin-nav-link <?= $is('migrate_webp.php') ?>">
            <i class="fa fa-images"></i>Migração WebP
        </a>
        <a href="<?= h(APP_URL) ?>/admin/export" class="admin-nav-link">
            <i class="fa fa-file-csv"></i>Exportar CSV
        </a>
        <a href="<?= h(APP_URL) ?>/" target="_blank" class="admin-nav-link">
            <i class="fa fa-eye"></i>Ver público
        </a>
    </nav>

    <div class="admin-sidebar-footer">
        <span class="admin-nav-user"><i class="fa fa-user-circle me-2"></i><?= e(admin_username()) ?></span>
        <a href="<?= h(APP_URL) ?>/admin/logout" class="admin-nav-link mt-1">
            <i class="fa fa-sign-out-alt"></i>Sair
        </a>
    </div>
</aside>

<!-- Main content -->
<main class="admin-main">
<?php
$flash = get_flash();
if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show mb-4" role="alert">
    <?= h($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
