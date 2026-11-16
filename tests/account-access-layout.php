<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf_field() { return '<input name="csrf_token" type="hidden" value="fixture">'; }
$verify = true; $request_mode = false; $done = false; $email_sent = false;
$already_verified = false; $account_logged = true; $account_address = 'paulo@garage64.online';
$title = 'Confirmar e-mail'; $error = ''; $notice = ''; $token = '';
$link_invalid = false;
$state = $argv[1] ?? 'pending';
if ($state === 'sent') { $email_sent = true; $notice = 'E-mail enviado. Confira sua caixa de entrada e spam.'; }
if ($state === 'complete') { $done = true; }
if ($state === 'invalid') { $error = 'Este link é inválido, expirou ou já foi utilizado.'; $token = 'invalid'; $link_invalid = true; }
if ($state === 'token') { $token = 'example'; }
require __DIR__ . '/../includes/account_access_view.php';
