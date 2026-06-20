<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <title><?= e($page_title ?? APP_NAME) ?> — <?= h(APP_NAME) ?></title>
    <?php if (!empty($og_title)): ?>
    <meta property="og:site_name" content="<?= h(APP_NAME) ?>">
    <meta property="og:title"       content="<?= e($og_title) ?> — <?= h(APP_NAME) ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?= e($og_url ?? APP_URL) ?>">
    <?php if (!empty($og_description)): ?>
    <meta property="og:description" content="<?= e($og_description) ?>">
    <meta name="description"        content="<?= e($og_description) ?>">
    <?php endif; ?>
    <?php if (!empty($og_image)): ?>
    <meta property="og:image"       content="<?= e($og_image) ?>">
    <meta property="og:image:width" content="800">
    <?php endif; ?>
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="<?= h(APP_URL) ?>/assets/img/favicon-cart.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="public-layout <?= $body_class ?? '' ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= h(APP_URL) ?>/">
            <i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/collections"><i class="fa fa-users me-1"></i>Coleções</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<main class="container py-4">
