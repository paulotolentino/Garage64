<?php
function account_email_template(string $purpose, string $name, string $url): array {
    $verify = $purpose === 'verify_email';
    $title = $verify ? 'Confirme seu e-mail' : 'Redefina sua senha';
    $copy = $verify
        ? 'Confirme este endereço para manter sua conta Garage64 em dia.'
        : 'Recebemos um pedido para redefinir a senha da sua conta Garage64.';
    $expiry = $verify ? '24 horas' : '30 minutos';
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = "Olá, {$name}!\n\n{$copy}\n\n{$title}: {$url}\n\nEste link é de uso único e expira em {$expiry}. Se você não solicitou esta ação, ignore este e-mail.\n\nGarage64";
    $html = '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><body style="word-break:normal;word-wrap:break-word;overflow-wrap:break-word;hyphens:none;margin:0;background:#0c0e12;font-family:Arial,sans-serif;color:#f2f4f8"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 16px"><table role="presentation" width="100%" style="max-width:560px;margin:auto" cellpadding="0" cellspacing="0"><tr><td style="color:#f5a623;font-size:24px;font-weight:bold;padding-bottom:28px">Garage64</td></tr><tr><td style="font-size:24px;font-weight:bold;padding-bottom:20px">' . $safe($title) . '</td></tr><tr><td style="line-height:1.6;color:#aeb4c0">Olá, ' . $safe($name) . '!<br><br>' . $safe($copy) . '</td></tr><tr><td style="padding:28px 0"><a href="' . $safe($url) . '" style="display:inline-block;background:#f5a623;color:#0c0e12;text-decoration:none;font-weight:bold;padding:14px 22px;border-radius:6px">' . $safe($title) . '</a></td></tr><tr><td style="font-size:13px;line-height:1.6;color:#aeb4c0">Este link é de uso único e expira em ' . $expiry . '. Se você não solicitou esta ação, ignore este e-mail.<br><br>Se o botão não funcionar, acesse:<br><a style="color:#f5a623;overflow-wrap:anywhere;word-break:normal" href="' . $safe($url) . '">' . $safe($url) . '</a></td></tr></table></td></tr></table></body></html>';
    return ['subject' => $title . ' | Garage64', 'text' => $text, 'html' => $html];
}
