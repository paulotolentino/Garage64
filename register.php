<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('/admin/');
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $password     = $_POST['password'] ?? '';
    $password2    = $_POST['password2'] ?? '';

    if ($password !== $password2) {
        $error = 'As senhas não coincidem.';
    } else {
        $result = register_user($username, $email, $password, $display_name);
        if ($result === true) {
            $success = true;
        } else {
            $error = $result;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar conta — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/style.css">
</head>
<body class="login-layout d-flex align-items-center justify-content-center min-vh-100 bg-dark">
<div class="login-card card bg-dark border-secondary shadow" style="width:420px; max-width:95vw;">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h1 class="h3 fw-bold text-warning mb-0"><i class="fa fa-garage me-2"></i><?= h(APP_NAME) ?></h1>
            <small class="text-secondary">Criar minha coleção</small>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success text-center">
                <i class="fa fa-check-circle me-2"></i>Conta criada com sucesso!<br>
                <a href="/admin/login" class="btn btn-warning btn-sm mt-2">Fazer login</a>
            </div>
        <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="username" class="form-label text-secondary">Usuário <small>(será sua URL pública)</small></label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><?= h(APP_URL) ?>/</span>
                    <input type="text" id="username" name="username"
                           class="form-control bg-dark text-light border-secondary"
                           pattern="[a-zA-Z0-9_\-]{3,30}"
                           title="3-30 caracteres: letras, números, _ ou -"
                           value="<?= e($_POST['username'] ?? '') ?>"
                           required autofocus>
                </div>
                <small class="text-secondary">3–30 caracteres. Letras, números, _ e -.</small>
            </div>
            <div class="mb-3">
                <label for="display_name" class="form-label text-secondary">Nome de exibição</label>
                <input type="text" id="display_name" name="display_name"
                       class="form-control bg-dark text-light border-secondary"
                       value="<?= e($_POST['display_name'] ?? '') ?>"
                       placeholder="Como seu nome aparecerá publicamente">
            </div>
            <div class="mb-3">
                <label for="email" class="form-label text-secondary">E-mail</label>
                <input type="email" id="email" name="email"
                       class="form-control bg-dark text-light border-secondary"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       autocomplete="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label text-secondary">Senha</label>
                <input type="password" id="password" name="password"
                       class="form-control bg-dark text-light border-secondary"
                       minlength="8" autocomplete="new-password" required>
                <small class="text-secondary">Mínimo 8 caracteres.</small>
            </div>
            <div class="mb-4">
                <label for="password2" class="form-label text-secondary">Confirmar senha</label>
                <input type="password" id="password2" name="password2"
                       class="form-control bg-dark text-light border-secondary"
                       minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-warning w-100">
                <i class="fa fa-user-plus me-2"></i>Criar conta
            </button>
        </form>
        <?php endif; ?>

        <hr class="border-secondary my-3">
        <div class="text-center">
            <a href="/admin/login" class="text-secondary small">Já tem conta? Entrar</a>
            &nbsp;&middot;&nbsp;
            <a href="/" class="text-secondary small">Voltar ao início</a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
