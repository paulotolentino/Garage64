<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/account_email.php';
session_start_once();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$mode = $account_mode ?? 'reset_password';
$verify = $mode === 'verify_email';
$request_mode = $mode === 'forgot_password';
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = ''; $notice = ''; $done = false; $email_sent = false; $link_invalid = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $bucket = 'account-action:' . hash('sha256', client_ip());
    if (rate_limit_exceeded($bucket, 10, 900)) {
        $error = 'Muitas tentativas. Tente novamente mais tarde.';
    } else {
        rate_limit_hit($bucket, 900);
        if ($request_mode) {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Informe um e-mail válido.';
            else {
                try {
                    $st = db()->prepare('SELECT id FROM admin_users WHERE email = ? AND is_banned = 0');
                    $st->execute([$email]);
                    if ($uid = $st->fetchColumn()) request_account_email((int) $uid, 'reset_password');
                } catch (Throwable $e) { error_log('Garage64 password recovery unavailable.'); }
                $notice = 'Se houver uma conta com este e-mail, você receberá um link para redefinir sua senha.';
            }
        } elseif ($verify && ($_POST['action'] ?? '') === 'resend') {
            require_login();
            $email_sent = request_account_email(current_user_id(), 'verify_email');
            $notice = $email_sent
                ? 'E-mail enviado. Confira sua caixa de entrada e spam.'
                : 'Não foi possível enviar agora. Se você já solicitou, aguarde alguns minutos ou confira se seu e-mail já foi confirmado.';
        } else {
            $password = (string) ($_POST['password'] ?? '');
            if (!$verify && (strlen($password) < 8 || strlen($password) > 72)) $error = 'Use uma senha de 8 a 72 bytes.';
            elseif (!$verify && $password !== ($_POST['password_confirm'] ?? '')) $error = 'As senhas não coincidem.';
            elseif (consume_account_token($token, $mode, $password)) {
                $notice = $verify ? 'E-mail confirmado com sucesso.' : 'Senha redefinida. Entre novamente com sua nova senha.';
                $done = true;
                if (!$verify && is_logged_in()) logout();
            } else {
                $error = 'Este link é inválido, expirou ou já foi utilizado.';
                $link_invalid = true;
            }
        }
    }
}
$title = $verify ? 'Confirmar e-mail' : ($request_mode ? 'Recuperar senha' : 'Nova senha');
$account_logged = is_logged_in();
$account_address = '';
$already_verified = false;
if ($verify && $account_logged) {
    try {
        $state = db()->prepare('SELECT email, email_verified_at FROM admin_users WHERE id = ?');
        $state->execute([current_user_id()]);
        if ($account = $state->fetch()) {
            $account_address = $account['email'];
            $already_verified = !empty($account['email_verified_at']);
        }
    } catch (Throwable $e) { error_log('Garage64 email state unavailable.'); }
}
require __DIR__ . '/includes/account_access_view.php';
