<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/countries.php';

if (is_logged_in()) {
    redirect('/admin/');
}

$error   = '';
$success = false;
session_start_once();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $_SESSION['signup_started'] = time();

// ── Convite: quem indicou (via ?ref=slug ou campo oculto no POST) ────────────
// O novo colecionador passa a seguir quem o convidou (loop social), e o
// convidante recebe uma notificação de novo seguidor.
$invite_ref     = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$invite_ref     = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $invite_ref));
$inviter        = $invite_ref !== '' ? get_user_by_slug($invite_ref) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $password     = $_POST['password'] ?? '';
    $password2    = $_POST['password2'] ?? '';

    require_once __DIR__ . '/includes/signup_protection.php';
    if (!signup_request_allowed()) {
        $error = 'Não foi possível concluir o cadastro. Atualize a página e tente novamente.';
    } elseif ($password !== $password2) {
        $error = 'As senhas não coincidem.';
    } else {
        $result = register_user($username, $email, $password, $display_name, (string)($_POST['country'] ?? ''));
        if ($result === true) {
            // Auto-login: cria a sessão imediatamente após o cadastro,
            // reaproveitando o mesmo fluxo seguro do login tradicional.
            login($username, $password);
            require_once __DIR__ . '/includes/account_email.php';
            try {
                $sent = request_account_email(current_user_id(), 'verify_email');
            } catch (Throwable $e) { $sent = false; error_log('Garage64 verification email unavailable.'); }
            flash($sent ? 'Conta criada. Confira seu e-mail para confirmar o endereço.' : 'Conta criada. Solicite a confirmação de e-mail pelo painel.', $sent ? 'success' : 'warning');

            // Loop de convite: novo usuário segue quem o convidou + notifica.
            if ($inviter && (int) $inviter['id'] !== current_user_id()) {
                follow_user(current_user_id(), (int) $inviter['id']);
                try {
                    create_notification(
                        (int) $inviter['id'], current_user_id(), 'follow',
                        null, null, '/u/' . current_user_slug(), (int) $inviter['id']
                    );
                } catch (\Throwable $e) { /* nunca bloqueia o cadastro */ }
            }
            redirect('/admin/');
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
<body class="auth-body">
<div class="auth-split">

    <!-- Branding + benefícios -->
    <aside class="auth-aside">
        <div class="lp-hero-glow"></div>
        <div class="lp-hero-grid"></div>
        <div class="auth-aside-inner">
            <a href="/" class="auth-brand">
                <span class="auth-brand-icon"><i class="fa fa-warehouse"></i></span>
                <span class="auth-brand-name"><?= h(APP_NAME) ?></span>
            </a>
            <p class="lp-eyebrow">A garagem digital do colecionador</p>
            <h1 class="auth-aside-title">Crie sua garagem<br><span>de miniaturas</span>.</h1>
            <p class="auth-aside-sub">
                Em poucos minutos você monta uma vitrine pública para sua coleção diecast.
            </p>
            <ul class="auth-features">
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-layer-group"></i></span>
                    <span class="auth-feature-text"><strong>Organize sua coleção</strong>Cada peça catalogada com fotos e detalhes.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-share-nodes"></i></span>
                    <span class="auth-feature-text"><strong>Compartilhe sua garagem</strong>Um endereço público só seu.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-book-open"></i></span>
                    <span class="auth-feature-text"><strong>Registre suas histórias</strong>A memória por trás de cada miniatura.</span>
                </li>
                <li class="auth-feature">
                    <span class="auth-feature-icon"><i class="fa fa-users"></i></span>
                    <span class="auth-feature-text"><strong>Encontre colecionadores</strong>Faça parte da comunidade.</span>
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

            <?php if ($success): ?>
                <div class="auth-success">
                    <span class="auth-success-icon"><i class="fa fa-check"></i></span>
                    <h2 class="auth-form-title">Garagem criada!</h2>
                    <p class="auth-form-lead">Sua conta está pronta. Faça login para começar a montar sua coleção.</p>
                    <a href="/admin/login" class="md-btn md-btn-primary auth-submit"><i class="fa fa-right-to-bracket"></i>Fazer login</a>
                </div>
            <?php else: ?>

            <div class="auth-form-head">
                <p class="lp-eyebrow">Nova conta</p>
                <h2 class="auth-form-title">Crie sua garagem</h2>
                <p class="auth-form-lead">Leva menos de um minuto.</p>
            </div>

            <?php if ($inviter): ?>
                <div class="auth-alert auth-invite">
                    <i class="fa fa-user-plus"></i>
                    <span>Você foi convidado por <strong><?= e($inviter['display_name'] ?: $inviter['slug']) ?></strong>. Ao criar sua conta, vocês já ficam conectados.</span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-alert auth-alert-error">
                    <i class="fa fa-circle-exclamation"></i><span><?= h($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <div style="position:absolute;left:-10000px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
                <?php if (TURNSTILE_SITE_KEY !== ''): ?><div class="cf-turnstile" data-sitekey="<?= e(TURNSTILE_SITE_KEY) ?>" data-action="signup"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
                <?= csrf_field() ?>
                <?php if ($invite_ref !== ''): ?><input type="hidden" name="ref" value="<?= e($invite_ref) ?>"><?php endif; ?>
                <div class="auth-field">
                    <label for="username">Usuário <span class="auth-hint-inline">(será sua URL pública)</span></label>
                    <div class="auth-input-prefix">
                        <span class="auth-prefix"><?= h(APP_URL) ?>/</span>
                        <input type="text" id="username" name="username" class="amf-input"
                               pattern="[a-zA-Z0-9_\-]{3,30}"
                               title="3-30 caracteres: letras, números, _ ou -"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               required autofocus>
                    </div>
                    <small class="auth-help">3–30 caracteres. Letras, números, _ e -.</small>
                </div>
                <div class="auth-field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" class="amf-input"
                           value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" required>
                </div>
                <div class="auth-field">
                    <label for="display_name">Nome de exibição</label>
                    <input type="text" id="display_name" name="display_name" class="amf-input"
                           value="<?= e($_POST['display_name'] ?? '') ?>"
                           placeholder="Como seu nome aparecerá publicamente">
                </div>
                <div class="auth-field">
                    <label for="country">País onde você mora</label>
                    <select id="country" name="country" class="amf-input" autocomplete="country" required>
                        <option value="">Selecione seu país</option>
                        <?php foreach(signup_countries() as $code=>$label): ?><option value="<?= e($code) ?>" <?= ($_POST['country'] ?? '')===$code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="auth-field">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" class="amf-input"
                           minlength="8" autocomplete="new-password" required>
                    <small class="auth-help">Mínimo 8 caracteres.</small>
                </div>
                <div class="auth-field">
                    <label for="password2">Confirmar senha</label>
                    <input type="password" id="password2" name="password2" class="amf-input"
                           minlength="8" autocomplete="new-password" required>
                </div>
                <button type="submit" class="md-btn md-btn-primary auth-submit">
                    <i class="fa fa-user-plus"></i>Criar conta
                </button>
            </form>

            <div class="auth-foot">
                <span>Já tem garagem?</span>
                <a href="/admin/login" class="auth-foot-link">Entrar</a>
            </div>
            <div class="auth-foot-alt">
                <a href="/"><i class="fa fa-arrow-left"></i>Voltar ao início</a>
            </div>
            <?php endif; ?>
        </div>
    </main>

</div>
</body>
</html>
