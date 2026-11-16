<?php
require_once __DIR__ . '/mailer.php';

function email_delivery_settings(): array {
    $defaults=['automatic'=>1];
    try {
        $row=db()->query('SELECT automatic FROM email_delivery_settings WHERE id=1')->fetch();
        if ($row) return array_map('intval',$row);
    } catch (Throwable $e) { /* Migrations may not have been applied yet. */ }
    return $defaults;
}

function schedule_notification_email_processing(): void {
    static $scheduled=false;
    if ($scheduled || !empty($_SESSION['view_context']) || PHP_SAPI==='cli' || SENDGRID_API_KEY==='' || str_starts_with((string)($_POST['action'] ?? ''),'email_')) return;
    $scheduled=true;
    register_shutdown_function(static function(): void {
        try {
            if (!empty($_SESSION['view_context'])) return;
            if (!email_delivery_settings()['automatic']) return;
            if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            process_notification_emails(1);
            require_once __DIR__ . '/email_campaigns.php';
            process_email_campaigns(1);
        } catch (Throwable $e) { error_log('Garage64 automatic notification email unavailable.'); }
    });
}

function email_preference_labels(): array {
    return ['messages'=>'Mensagens não lidas','likes'=>'Curtidas','comments'=>'Comentários, respostas e menções','ratings'=>'Avaliações por estrelas','follows'=>'Novos seguidores','registrations'=>'Novos cadastros (superadmin)','announcements'=>'Comunicados e novidades do Garage64'];
}

function email_event_preference(string $type): string {
    return ['message'=>'messages','like'=>'likes','comment'=>'comments','reply'=>'comments','mention'=>'comments','rating'=>'ratings','follow'=>'follows','registration'=>'registrations'][$type] ?? '';
}

function queue_notification_email(int $uid, string $type, string $key, ?int $target = null, ?int $notification = null): void {
    if (!empty($_SESSION['view_context'])) return;
    if (email_event_preference($type) === '') return;
    if (in_array($type,['reply','mention'],true)) $type='comment';
    try {
        db()->prepare('INSERT IGNORE INTO email_digest_state (user_id,next_digest_at) VALUES (?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))')->execute([$uid]);
        db()->prepare('INSERT IGNORE INTO email_notification_queue (user_id,event_type,source_key,target_id,notification_id,available_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())')->execute([$uid,$type,hash('sha256',$key),$target,$notification]);
    } catch (Throwable $e) { error_log('Garage64 email queue unavailable.'); }
}

function notification_email_template(string $name, string $type, int $count): array {
    $label = email_preference_labels()[email_event_preference($type)];
    $title = $type === 'registration' ? 'Novos colecionadores no Garage64' : 'Novidades na sua garagem';
    $url = rtrim(APP_URL,'/') . ($type === 'message' ? '/admin/messages' : '/admin/notifications');
    $prefs = rtrim(APP_URL,'/') . '/admin/email-preferences';
    $safe = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $copy = $label . ': ' . $count . ($count === 1 ? ' atualização' : ' atualizações') . '. Acesse sua garagem para conferir.';
    $text = "Olá, {$name}!\n\n{$copy}\n\n{$url}\n\nPreferências de e-mail: {$prefs}";
    $html = '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><body style="margin:0;background:#0c0e12;color:#f2f4f8;font-family:Arial,sans-serif"><div style="max-width:560px;margin:auto;padding:36px 24px"><strong style="color:#f5a623;font-size:24px">Garage64</strong><h1 style="font-size:24px;margin-top:32px">'.$safe($title).'</h1><p>Olá, '.$safe($name).'!</p><p style="color:#aeb4c0;line-height:1.6">'.$safe($copy).'</p><p style="padding:20px 0"><a href="'.$safe($url).'" style="background:#f5a623;color:#0c0e12;padding:14px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Ver na minha garagem</a></p><p style="color:#aeb4c0;font-size:12px">Você recebe este aviso conforme suas preferências de conta. <a href="'.$safe($prefs).'" style="color:#f5a623">Configurar e-mails</a></p></div></body></html>';
    return ['subject'=>$title.' | '.$label,'text'=>$text,'html'=>$html];
}

function process_notification_emails(int $limit = 50, ?callable $deliver = null): array {
    require_once __DIR__ . '/email_weekly.php';
    return process_weekly_digests($limit, $deliver);
}
