<?php
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
$name=getenv('TEST_MYSQL_DB') ?: '';
if($name==='') {echo "SKIP notification queue integration: TEST_MYSQL_DB not configured\n";exit;}
if(!str_starts_with($name,'garage64_test_')) throw new RuntimeException('Use an isolated garage64_test_ database.');
define('DB_NAME',$name);define('DB_HOST',getenv('TEST_MYSQL_HOST') ?: '127.0.0.1');define('DB_USER',getenv('TEST_MYSQL_USER') ?: 'root');define('DB_PASS',getenv('TEST_MYSQL_PASS') ?: '');
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/email_notifications.php';
require_once __DIR__.'/../includes/email_campaigns.php';
function assert_queue(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$pdo=db();$schema=file_get_contents(__DIR__.'/../database/schema.sql');
foreach(['admin_users','categories','miniatures','miniature_comments','notifications','email_preferences','email_digest_state','email_campaigns','email_campaign_recipients','direct_messages','email_notification_queue'] as $table) {
    preg_match('/CREATE TABLE IF NOT EXISTS '.$table.' \(.*?;/s',$schema,$match);$pdo->exec($match[0]);
}
$users=[];$campaign_ids=[];
try {
    for($i=0;$i<2;$i++) {
        $slug='queue_'.bin2hex(random_bytes(6));
        $pdo->prepare('INSERT INTO admin_users (username,slug,email,password_hash,email_verified_at,is_superadmin) VALUES (?,?,?,?,UTC_TIMESTAMP(),1)')->execute([$slug,$slug,$slug.'@example.test',password_hash('test-password',PASSWORD_BCRYPT)]);
        $users[]=(int)$pdo->lastInsertId();
    }
    [$recipient,$sender]=$users;
    $sent=[];
    $deliver=static function($email,$template)use(&$sent):bool {$sent[]=$template;return true;};
    queue_notification_email($recipient,'message','message-1',$sender);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===0,'Digest sent before the weekly period');
    $pdo->prepare('UPDATE email_digest_state SET next_digest_at=UTC_TIMESTAMP() WHERE user_id=?')->execute([$recipient]);
    $pdo->prepare("UPDATE email_notification_queue SET available_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$recipient]);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===0,'Read/absent messages should not send');
    $pdo->prepare('INSERT INTO direct_messages (sender_id,recipient_id,body) VALUES (?,?,?)')->execute([$sender,$recipient,'Unread test']);
    queue_notification_email($recipient,'message','message-2',$sender);
    queue_notification_email($recipient,'message','message-2',$sender);
    $pdo->prepare('UPDATE email_digest_state SET next_digest_at=UTC_TIMESTAMP() WHERE user_id=?')->execute([$recipient]);
    $pdo->prepare("UPDATE email_notification_queue SET available_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$recipient]);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===1,'Unread message/dedup failed');
    queue_notification_email($recipient,'message','message-3',$sender);
    $pdo->prepare("UPDATE email_notification_queue SET available_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$recipient]);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===1,'Weekly frequency cap failed');
    $pdo->prepare("DELETE FROM email_notification_queue WHERE user_id=? AND status='pending'")->execute([$recipient]);
    $pdo->prepare('INSERT INTO email_preferences (user_id,likes) VALUES (?,0)')->execute([$recipient]);
    queue_notification_email($recipient,'like','disabled-like');
    $pdo->prepare('UPDATE email_digest_state SET next_digest_at=UTC_TIMESTAMP() WHERE user_id=?')->execute([$recipient]);
    $pdo->prepare("UPDATE email_notification_queue SET available_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$recipient]);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===1,'Disabled category sent');
    $pdo->prepare('UPDATE admin_users SET is_banned=1 WHERE id=?')->execute([$recipient]);
    queue_notification_email($recipient,'follow','banned');
    $pdo->prepare('UPDATE email_digest_state SET next_digest_at=UTC_TIMESTAMP() WHERE user_id=?')->execute([$recipient]);
    $pdo->prepare("UPDATE email_notification_queue SET available_at=UTC_TIMESTAMP() WHERE user_id=?")->execute([$recipient]);
    process_notification_emails(50,$deliver);
    assert_queue(count($sent)===1,'Banned recipient received email');
    echo "PASS MySQL queue: weekly window, read cancellation, unread delivery, deduplication, weekly cap, preferences and ban; mocked transport, no real email\n";
    $pdo->prepare('UPDATE admin_users SET is_banned=0 WHERE id=?')->execute([$recipient]);
    $pdo->prepare('UPDATE admin_users SET is_banned=1 WHERE id=?')->execute([$sender]);
    $pdo->prepare("INSERT INTO email_campaigns (created_by,subject,body_html,status) VALUES (?,'Teste','<p>Novidades</p>','queued')")->execute([$recipient]);$cid=(int)$pdo->lastInsertId();$campaign_ids[]=$cid;
    foreach($users as $uid) $pdo->prepare('INSERT INTO email_campaign_recipients (campaign_id,user_id,email,available_at) SELECT ?,id,email,UTC_TIMESTAMP() FROM admin_users WHERE id=?')->execute([$cid,$uid]);
    process_email_campaigns(5,$deliver,$cid);
    assert_queue(count($sent)===2,'Campaign should send only to the active recipient');
    $counts=campaign_recipient_counts($cid);assert_queue($counts['sent']===1 && $counts['skipped']===1,'Campaign status counts wrong');
    process_email_campaigns(5,$deliver,$cid);assert_queue(count($sent)===2,'Campaign sent twice');
    $pdo->prepare('UPDATE email_preferences SET announcements=0 WHERE user_id=?')->execute([$recipient]);
    $pdo->prepare("INSERT INTO email_campaigns (created_by,subject,body_html,status) VALUES (?,'Teste optout','<p>Novidades</p>','queued')")->execute([$recipient]);$cid=(int)$pdo->lastInsertId();$campaign_ids[]=$cid;
    $pdo->prepare('INSERT INTO email_campaign_recipients (campaign_id,user_id,email,available_at) SELECT ?,id,email,UTC_TIMESTAMP() FROM admin_users WHERE id=?')->execute([$cid,$recipient]);
    process_email_campaigns(5,$deliver,$cid);assert_queue(count($sent)===2 && campaign_recipient_counts($cid)['skipped']===1,'Opted-out recipient received campaign');
    echo "PASS MySQL campaigns: active recipients, bans, opt-out, per-recipient tracking and no resend; mocked transport\n";
} finally {
    foreach($campaign_ids as $cid)$pdo->prepare('DELETE FROM email_campaigns WHERE id=?')->execute([$cid]);
    foreach($users as $uid)$pdo->prepare('DELETE FROM admin_users WHERE id=?')->execute([$uid]);
}
