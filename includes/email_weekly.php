<?php
function weekly_digest_template(string $name,array $counts): array {
    $safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $labels=email_preference_labels();$labels['messages']='Conversas com mensagens não lidas';$lines=[];$html='';
    foreach($counts as $key=>$count) {
        if($count<1)continue;
        $label=$labels[$key] ?? $key;$lines[]=$label.': '.$count;
        $html.='<tr><td style="padding:14px 0;border-bottom:1px solid #2c313b;color:#aeb4c0">'.$safe($label).'</td><td style="padding:14px 0;border-bottom:1px solid #2c313b;text-align:right;font-weight:bold;color:#f5a623">'.(int)$count.'</td></tr>';
    }
    $url=rtrim(APP_URL,'/').'/admin/';$prefs=rtrim(APP_URL,'/').'/admin/email-preferences';
    $text="Olá, {$name}!\n\nSeu resumo semanal do Garage64:\n".implode("\n",$lines)."\n\n{$url}\n\nPreferências: {$prefs}";
    return ['subject'=>'Seu resumo semanal | Garage64','text'=>$text,'html'=>'<!doctype html><html lang="pt-BR"><meta charset="utf-8"><body style="word-break:normal;word-wrap:break-word;overflow-wrap:break-word;hyphens:none;margin:0;background:#0c0e12;font-family:Arial,sans-serif;color:#f2f4f8"><div style="max-width:560px;margin:auto;padding:36px 24px"><strong style="color:#f5a623;font-size:24px">Garage64</strong><h1 style="font-size:26px;margin-top:32px">Sua semana na garagem</h1><p>Olá, '.$safe($name).'!</p><p style="color:#aeb4c0;line-height:1.6">Estas são as novidades da sua conta.</p><table role="presentation" width="100%">'.$html.'</table><p style="padding:24px 0"><a href="'.$safe($url).'" style="background:#f5a623;color:#0c0e12;text-decoration:none;padding:14px 20px;border-radius:6px;font-weight:bold">Abrir minha garagem</a></p><a href="'.$safe($prefs).'" style="font-size:12px;color:#aeb4c0">Preferências de e-mail</a></div></body></html>'];
}

function process_weekly_digests(int $limit=50,?callable $deliver=null):array {
    $pdo=db();$stats=['sent'=>0,'cancelled'=>0,'failed'=>0];
    if(!(int)$pdo->query("SELECT GET_LOCK('garage64_email_queue',0)")->fetchColumn())return $stats;
    try {
        // Existing pending activity joins the first weekly period without a mass send.
        $pdo->exec('INSERT IGNORE INTO email_digest_state (user_id,next_digest_at) SELECT DISTINCT user_id,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) FROM email_notification_queue WHERE status=\'pending\'');
        $users=$pdo->query("SELECT DISTINCT q.user_id FROM email_notification_queue q JOIN email_digest_state s ON s.user_id=q.user_id WHERE q.status='pending' AND q.available_at<=UTC_TIMESTAMP() AND s.next_digest_at<=UTC_TIMESTAMP() ORDER BY q.user_id LIMIT ".max(1,min(100,$limit)))->fetchAll();
        foreach($users as $entry) {
            $uid=(int)$entry['user_id'];
            $st=$pdo->prepare('SELECT u.*,p.enabled AS pref_enabled,p.messages,p.likes,p.comments,p.ratings,p.follows,p.registrations FROM admin_users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE u.id=?');$st->execute([$uid]);$user=$st->fetch();
            if(!$user || $user['is_banned'] || !$user['email_verified_at'] || !(int)($user['pref_enabled'] ?? 1)) {
                $pdo->prepare("UPDATE email_notification_queue SET status='cancelled' WHERE user_id=? AND status='pending'")->execute([$uid]);$stats['cancelled']++;continue;
            }
            $st=$pdo->prepare("SELECT q.*,a.is_banned AS actor_banned,a.requires_email_verification AS actor_requires,a.email_verified_at AS actor_verified,
                EXISTS(SELECT 1 FROM direct_messages d JOIN admin_users sender ON sender.id=d.sender_id WHERE d.recipient_id=q.user_id AND d.sender_id=q.target_id AND d.is_read=0 AND sender.is_banned=0) AS has_unread
                FROM email_notification_queue q LEFT JOIN notifications n ON n.id=q.notification_id LEFT JOIN admin_users a ON a.id=n.actor_user_id
                WHERE q.user_id=? AND q.status='pending' AND q.available_at<=UTC_TIMESTAMP() ORDER BY q.id");
            $st->execute([$uid]);$rows=$st->fetchAll();$ids=[];$counts=[];$conversations=[];
            foreach($rows as $row) {
                $type=$row['event_type'];$pref=email_event_preference($type);
                $valid=$pref!=='' && (int)($user[$pref] ?? 1)===1 && ($type!=='registration' || $user['is_superadmin']);
                if($type==='message')$valid=$valid && $row['has_unread'];
                elseif($row['notification_id'])$valid=$valid && $row['actor_banned']!==null && !$row['actor_banned'] && ($type==='registration' || !$row['actor_requires'] || $row['actor_verified']);
                if(!$valid) {$pdo->prepare("UPDATE email_notification_queue SET status='cancelled' WHERE id=?")->execute([$row['id']]);$stats['cancelled']++;continue;}
                $ids[]=(int)$row['id'];
                if($type==='message' && isset($conversations[$row['target_id']]))continue;
                $counts[$pref]=($counts[$pref] ?? 0)+1;
                if($type==='message')$conversations[$row['target_id']]=true;
            }
            if(!$ids) {$pdo->prepare('UPDATE email_digest_state SET next_digest_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) WHERE user_id=?')->execute([$uid]);continue;}
            $template=weekly_digest_template($user['display_name'] ?: $user['username'],$counts);
            $ok=$deliver?$deliver($user['email'],$template):send_transactional_email($user['email'],$template);
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE email_notification_queue SET attempts=attempts+1,status=IF(?=1,'sent',IF(attempts>=5,'failed','pending')),sent_at=IF(?=1,UTC_TIMESTAMP(),NULL),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE id IN ($marks)")->execute(array_merge([$ok?1:0,$ok?1:0],$ids));
            if($ok)$pdo->prepare('UPDATE email_digest_state SET last_sent_at=UTC_TIMESTAMP(),next_digest_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) WHERE user_id=?')->execute([$uid]);
            $stats[$ok?'sent':'failed']++;
        }
        return $stats;
    } finally {$pdo->query("SELECT RELEASE_LOCK('garage64_email_queue')");}
}
