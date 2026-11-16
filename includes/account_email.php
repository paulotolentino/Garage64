<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function request_account_email(int $user_id, string $purpose): bool {
    if (!in_array($purpose, ['verify_email', 'reset_password'], true)) return false;
    $bucket = 'account-mail:' . $purpose . ':' . $user_id;
    if (rate_limit_exceeded($bucket, 3, 3600)) return false;
    rate_limit_hit($bucket, 3600);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = ? AND is_banned = 0 FOR UPDATE');
        $st->execute([$user_id]);
        $user = $st->fetch();
        if (!$user || ($purpose === 'verify_email' && $user['email_verified_at'])) {
            $pdo->rollBack();
            return false;
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $pdo->prepare('DELETE FROM auth_email_tokens WHERE (user_id = ? AND purpose = ?) OR expires_at <= UTC_TIMESTAMP()')->execute([$user_id, $purpose]);
        $pdo->prepare('INSERT INTO auth_email_tokens (token_hash, user_id, purpose, email, auth_version, expires_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([
            $hash, $user_id, $purpose, $user['email'], $user['auth_version'], gmdate('Y-m-d H:i:s', time() + ($purpose === 'verify_email' ? 86400 : 1800)),
        ]);
        $pdo->commit();
        $route = $purpose === 'verify_email' ? '/verify-email' : '/reset-password';
        $url = rtrim(APP_URL, '/') . $route . '?token=' . $token;
        if (send_account_email($user['email'], $user['display_name'] ?: $user['username'], $purpose, $url)) return true;
        $pdo->prepare('DELETE FROM auth_email_tokens WHERE token_hash = ?')->execute([$hash]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Garage64 account email unavailable.');
    }
    return false;
}

function consume_account_token(string $token, string $purpose, string $password = ''): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !in_array($purpose, ['verify_email', 'reset_password'], true)) return false;
    if ($purpose === 'reset_password' && (strlen($password) < 8 || strlen($password) > 72)) return false;
    $pdo = db();
    try {
        $hash = hash('sha256', $token);
        $st = $pdo->prepare('SELECT user_id FROM auth_email_tokens WHERE token_hash = ? AND purpose = ?');
        $st->execute([$hash, $purpose]);
        $uid = $st->fetchColumn();
        if (!$uid) return false;
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = ? AND is_banned = 0 FOR UPDATE');
        $st->execute([$uid]);
        $user = $st->fetch();
        $st = $pdo->prepare('SELECT * FROM auth_email_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
        $st->execute([$hash, $purpose]);
        $row = $st->fetch();
        if (!$user || !$row || $row['email'] !== $user['email'] || (int) $row['auth_version'] !== (int) $user['auth_version']) {
            $pdo->rollBack();
            return false;
        }
        if ($purpose === 'verify_email') {
            $pdo->prepare('UPDATE admin_users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM auth_email_tokens WHERE user_id = ? AND purpose = ?')->execute([$uid, $purpose]);
        } else {
            $pdo->prepare('UPDATE admin_users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $uid]);
            $pdo->prepare('DELETE FROM persistent_logins WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM auth_email_tokens WHERE user_id = ?')->execute([$uid]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Garage64 account token unavailable.');
        return false;
    }
}
