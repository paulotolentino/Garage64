<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect('/admin/');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $result = login($username, $password);
        if ($result === true) {
            redirect('/admin/');
        } elseif ($result === 'banned') {
            $error = 'Sua conta foi suspensa. Entre em contato com o administrador.';
        } else {
            $error = 'Usuário ou senha incorretos.';
        }
    } else {
        $error = 'Preencha usuário e senha.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-split">

    <!-- Branding + destaques -->
    <aside class="auth-aside">
        <div class="lp-hero-glow"></div>
        <div class="lp-hero-grid"></div>
        <div class="auth-aside-inner">
            <a href="/" class="auth-brand">
                <span class="auth-brand-icon"><i class="fa fa-warehouse"></i></span>
                <span class="auth-brand-name"><?= h(APP_NAME) ?></span>
            </a>
            <p class="lp-eyebrow">A garagem digital do colecionador</p>
            <h1 class="auth-aside-title">Bem-vindo de volta à<br><span>sua garagem</span>.</h1>
            <p class="auth-aside-sub">
                Entre para gerenciar sua coleção, registrar novas peças e acompanhar sua comunidade.
            </p>
            <ul class="auth-features">
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-layer-group"></i></span>
                    <span class="auth-feature-text"><strong>Organize sua coleção</strong>Catalogue cada miniatura com fotos e detalhes.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-share-nodes"></i></span>
                    <span class="auth-feature-text"><strong>Compartilhe sua garagem</strong>Uma vitrine pública só sua.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-book-open"></i></span>
                    <span class="auth-feature-text"><strong>Registre suas histórias</strong>Guarde a memória por trás de cada peça.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-users"></i></span>
                    <span class="auth-feature-text"><strong>Encontre colecionadores</strong>Conecte-se com quem ama o hobby.</span>
                </li>
            </ul>
        </div>
    </aside>

    <!-- Formulário -->
    <main class="auth-form-panel">
        <div class="auth-form-wrap">
            <a href="/" class="auth-brand auth-brand-mobile">
                <span class="auth-brand-icon"><i class="fa fa-warehouse"></i></span>
                <span class="auth-brand-name"><?= h(APP_NAME) ?></span>
            </a>

            <div class="auth-form-head">
                <p class="lp-eyebrow">Acessar conta</p>
                <h2 class="auth-form-title">Entrar na garagem</h2>
                <p class="auth-form-lead">Use seu usuário e senha para continuar.</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fa fa-circle-exclamation"></i><span><?= h($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <?= csrf_field() ?>
                <div class="auth-field">
                    <label for="username">Usuário</label>
                    <input type="text" id="username" name="username" class="amf-input"
                           autocomplete="username" required autofocus>
                </div>
                <div class="auth-field">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" class="amf-input"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="md-btn md-btn-primary auth-submit">
                    <i class="fa fa-right-to-bracket"></i>Entrar
                </button>
            </form>

            <div class="auth-foot">
                <span>Ainda não tem garagem?</span>
                <a href="/register" class="auth-foot-link">Criar conta</a>
            </div>
            <div class="auth-foot-alt">
                <a href="/"><i class="fa fa-arrow-left"></i>Voltar ao início</a>
            </div>
        </div>
    </main>

</div>
</body>
</html>
