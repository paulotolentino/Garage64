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

    if ($username && $password && login($username, $password)) {
        redirect('/admin/');
    } else {
        $error = 'Usuário ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="login-layout d-flex align-items-center justify-content-center min-vh-100 bg-dark">
<div class="login-card card bg-dark border-secondary shadow" style="width:380px; max-width:95vw;">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h1 class="h3 fw-bold text-warning mb-0"><i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?></h1>
            <small class="text-secondary">Área privada</small>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="username" class="form-label text-secondary">Usuário</label>
                <input type="text" id="username" name="username" class="form-control bg-dark text-light border-secondary"
                       autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label text-secondary">Senha</label>
                <input type="password" id="password" name="password" class="form-control bg-dark text-light border-secondary"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-warning w-100">
                <i class="fa fa-sign-in-alt me-1"></i>Entrar
            </button>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
