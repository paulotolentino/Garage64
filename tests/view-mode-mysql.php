<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$name=getenv('TEST_MYSQL_DB') ?: '';
if(!$name){echo "SKIP support lifecycle: TEST_MYSQL_DB not configured\n";exit;}
if(!str_starts_with($name,'garage64_test_'))throw new RuntimeException('Use an isolated test database.');
define('DB_NAME',$name);define('DB_HOST',getenv('TEST_MYSQL_HOST') ?: '127.0.0.1');define('DB_USER',getenv('TEST_MYSQL_USER') ?: 'root');define('DB_PASS',getenv('TEST_MYSQL_PASS') ?: '');
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/functions.php';
function check_view(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=db();$schema=file_get_contents(__DIR__.'/../database/schema.sql');
foreach(['admin_users','support_access_log'] as $table){preg_match('/CREATE TABLE IF NOT EXISTS '.$table.' \(.*?;/s',$schema,$m);$pdo->exec($m[0]);}
$ids=[];
try {
    for($i=0;$i<2;$i++){$slug='support_'.bin2hex(random_bytes(5));$pdo->prepare('INSERT INTO admin_users (username,slug,email,password_hash,is_superadmin) VALUES (?,?,?,?,?)')->execute([$slug,$slug,$slug.'@example.test',password_hash('test-password',PASSWORD_BCRYPT),$i===0?1:0]);$ids[]=(int)$pdo->lastInsertId();}
    $st=$pdo->prepare('SELECT * FROM admin_users WHERE id=?');$st->execute([$ids[0]]);$actor=$st->fetch();
    session_start_once();set_authenticated_user($actor);$_SESSION['csrf_token']=str_repeat('a',64);$_SESSION['stats_cache']=['only'=>'actor'];
    begin_view_mode('support',$ids[1],'Test support');
    check_view(current_user_id()===$ids[1] && !is_superadmin() && in_view_mode(),'Wrong support identity');
    check_view(!isset($_SESSION['stats_cache']),'Actor cache leaked into target view');
    check_view($_SESSION['csrf_token']!==str_repeat('a',64),'CSRF token was not rotated');
    check_view(!create_notification($ids[1],$ids[0],'follow',null,null,'/'),'Support emitted a notification');
    check_view(!mark_notification_read(1,$ids[1]),'Support marked a notification read');
    check_view(!record_miniature_view(['id'=>1,'user_id'=>$ids[1]]),'Support counted a visit');
    validate_view_mode();$audit=$_SESSION['view_context']['audit_id'];restore_view_actor($actor);
    check_view(current_user_id()===$ids[0] && is_superadmin() && !in_view_mode(),'Original admin not restored');
    check_view($_SESSION['stats_cache']['only']==='actor' && $_SESSION['csrf_token']===str_repeat('a',64),'Original session not restored');
    $st=$pdo->prepare('SELECT ended_at FROM support_access_log WHERE id=?');$st->execute([$audit]);check_view((bool)$st->fetchColumn(),'Audit was not closed');
    begin_view_mode('preview',0,'Test preview');check_view(current_user_id()===$ids[0] && !is_superadmin(),'Preview retained admin privilege');restore_view_actor($actor);
    echo "PASS MySQL support: target identity, cache isolation, read suppression, audit, restore and regular-user preview\n";
}finally{
    $_SESSION=[];
    foreach($ids as $uid)$pdo->prepare('DELETE FROM support_access_log WHERE actor_id=? OR target_id=?')->execute([$uid,$uid]);
    foreach($ids as $uid)$pdo->prepare('DELETE FROM admin_users WHERE id=?')->execute([$uid]);
}
