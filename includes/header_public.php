<?php if (function_exists('session_start_once')) session_start_once(); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <title><?= e($page_title ?? APP_NAME) ?> — <?= h(APP_NAME) ?></title>
    <?php
    // ── Metadados sociais (Open Graph + Twitter Cards + canonical) ───────────
    // Reaproveita as variáveis $og_* definidas pelas páginas públicas.
    $og_meta_title = !empty($og_title) ? ($og_title . ' — ' . APP_NAME) : APP_NAME;
    $og_meta_img   = !empty($og_image)
        ? $og_image
        : ((defined('OG_DEFAULT_IMAGE') && OG_DEFAULT_IMAGE !== '') ? OG_DEFAULT_IMAGE : '');
    $og_meta_url   = !empty($og_url) ? $og_url : '';
    ?>
    <meta property="og:site_name"   content="<?= h(APP_NAME) ?>">
    <meta property="og:title"       content="<?= e($og_meta_title) ?>">
    <meta property="og:type"        content="<?= e($og_type ?? 'website') ?>">
    <?php if ($og_meta_url !== ''): ?>
    <meta property="og:url"         content="<?= e($og_meta_url) ?>">
    <?php endif; ?>
    <?php if (!empty($og_description)): ?>
    <meta property="og:description" content="<?= e($og_description) ?>">
    <meta name="description"        content="<?= e($og_description) ?>">
    <?php endif; ?>
    <?php if ($og_meta_img !== ''): ?>
    <meta property="og:image"       content="<?= e($og_meta_img) ?>">
    <?php endif; ?>
    <meta name="twitter:card"        content="<?= $og_meta_img !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title"       content="<?= e($og_meta_title) ?>">
    <?php if (!empty($og_description)): ?>
    <meta name="twitter:description" content="<?= e($og_description) ?>">
    <?php endif; ?>
    <?php if ($og_meta_img !== ''): ?>
    <meta name="twitter:image"       content="<?= e($og_meta_img) ?>">
    <?php endif; ?>
    <?php if ($og_meta_url !== ''): ?>
    <link rel="canonical" href="<?= e($og_meta_url) ?>">
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon-cart.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="public-layout <?= $body_class ?? '' ?>">
<?php
// ── Public header state (best-effort logged-in info) ─────────────────────────
$g64_logged = is_logged_in();
$g64_name = $g64_slug = '';
$g64_avatar = null;
$g64_unread = 0;
if ($g64_logged) {
    $g64_slug = current_user_slug();
    $g64_name = current_user_name();
    try {
        $g64_st = db()->prepare('SELECT display_name, avatar FROM admin_users WHERE id = ? LIMIT 1');
        $g64_st->execute([current_user_id()]);
        if ($g64_row = $g64_st->fetch()) {
            if (!empty($g64_row['display_name'])) $g64_name = $g64_row['display_name'];
            if (!empty($g64_row['avatar']))       $g64_avatar = avatar_url($g64_row['avatar']);
        }
    } catch (\Throwable $e) { /* columns may be absent on legacy DBs */ }
    if ($g64_name === '') $g64_name = $g64_slug ?: 'Você';
    if (function_exists('get_unread_notifications_count')) {
        $g64_unread = (int) get_unread_notifications_count(current_user_id());
    }
}
$g64_initial = mb_strtoupper(mb_substr($g64_name !== '' ? $g64_name : ($g64_slug ?: '?'), 0, 1));
?>
<nav class="g64-public-header navbar navbar-expand-lg sticky-top">
    <div class="container">
        <a class="g64-public-header__brand navbar-brand" href="<?= h(APP_URL) ?>/">
            <img src="/assets/img/logo.svg" alt="" width="24" height="22">
            <span class="g64-public-header__brand-name"><?= h(APP_NAME) ?></span>
        </a>
        <button class="g64-public-header__toggler navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Abrir menu">
            <i class="fa fa-bars"></i>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="g64-public-header__nav navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if ($g64_logged): ?>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/community"><i class="fa fa-bullhorn me-1"></i>Mural</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/collections"><i class="fa fa-compass me-1"></i>Coleções</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/u/<?= e($g64_slug) ?>"><i class="fa fa-warehouse me-1"></i>Minha garagem</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/admin/"><i class="fa fa-gauge-high me-1"></i>Painel</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/admin/logout"><i class="fa fa-arrow-right-from-bracket me-1"></i>Sair</a>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/community"><i class="fa fa-bullhorn me-1"></i>Mural</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__link" href="/collections"><i class="fa fa-compass me-1"></i>Coleções</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__action" href="/admin/login"><i class="fa fa-right-to-bracket me-1"></i>Entrar</a>
                </li>
                <li class="nav-item">
                    <a class="g64-public-header__action g64-public-header__action--primary" href="/register"><i class="fa fa-warehouse me-1"></i>Criar conta</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container py-4">
