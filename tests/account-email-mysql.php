<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('TEST_MYSQL_DB') ?: '';
if (!str_starts_with($name, 'garage64_test_')) throw new RuntimeException('Use an isolated garage64_test_ database.');
define('DB_NAME', $name);
define('DB_HOST', getenv('TEST_MYSQL_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('TEST_MYSQL_USER') ?: 'root');
define('DB_PASS', getenv('TEST_MYSQL_PASS') ?: '');
require_once __DIR__ . '/../includes/account_email.php';
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = db();
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
foreach (['admin_users', 'auth_email_tokens', 'persistent_logins'] as $table) {
    preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?;/s', $schema, $match);
    $pdo->exec($match[0]);
}
$handle = 'test_' . bin2hex(random_bytes(6));
$pdo->prepare('INSERT INTO admin_users (username,slug,email,password_hash) VALUES (?,?,?,?)')->execute([$handle,$handle,$handle.'@example.test',password_hash('original-password',PASSWORD_BCRYPT)]);
$uid = (int) $pdo->lastInsertId();
try {
    $token = bin2hex(random_bytes(32));
    $add = static function(string $purpose, int $seconds = 600) use ($pdo,$uid,$handle,$token): void {
        $pdo->prepare('INSERT INTO auth_email_tokens (token_hash,user_id,purpose,email,auth_version,expires_at) VALUES (?,?,?,?,0,?)')->execute([hash('sha256',$token),$uid,$purpose,$handle.'@example.test',gmdate('Y-m-d H:i:s',time()+$seconds)]);
    };
    $add('verify_email', -10);
    ensure(!consume_account_token($token,'verify_email'),'Expired token accepted');
    $pdo->prepare('DELETE FROM auth_email_tokens WHERE user_id=?')->execute([$uid]);
    $add('verify_email');
    ensure(!consume_account_token($token,'reset_password','new-password'),'Wrong purpose accepted');
    $pdo->prepare('UPDATE admin_users SET email=? WHERE id=?')->execute(['changed@example.test',$uid]);
    ensure(!consume_account_token($token,'verify_email'),'Old email token accepted');
    $pdo->prepare('UPDATE admin_users SET email=?,is_banned=1 WHERE id=?')->execute([$handle.'@example.test',$uid]);
    ensure(!consume_account_token($token,'verify_email'),'Banned account accepted');
    $pdo->prepare('UPDATE admin_users SET is_banned=0 WHERE id=?')->execute([$uid]);
    ensure(consume_account_token($token,'verify_email'),'Valid verification rejected');
    ensure(!consume_account_token($token,'verify_email'),'Verification token reused');
    $add('reset_password');
    $pdo->prepare('INSERT INTO persistent_logins (selector,token_hash,csrf_token,user_id,expires_at) VALUES (?,?,?,?,?)')->execute([bin2hex(random_bytes(12)),str_repeat('a',64),str_repeat('b',64),$uid,gmdate('Y-m-d H:i:s',time()+600)]);
    ensure(consume_account_token($token,'reset_password','new-password'),'Valid reset rejected');
    ensure(!consume_account_token($token,'reset_password','another-password'),'Reset token reused');
    $st=$pdo->prepare('SELECT * FROM admin_users WHERE id=?');$st->execute([$uid]);$user=$st->fetch();
    ensure(password_verify('new-password',$user['password_hash']) && (int)$user['auth_version']===1,'Password/session version unchanged');
    $st=$pdo->prepare('SELECT COUNT(*) FROM persistent_logins WHERE user_id=?');$st->execute([$uid]);
    ensure((int)$st->fetchColumn()===0,'Persistent tokens not revoked');
    echo "PASS MySQL: expiry, purpose, changed email, banned account, single use, password reset, session version and token revocation\n";
} finally { $pdo->prepare('DELETE FROM admin_users WHERE id=?')->execute([$uid]); }
