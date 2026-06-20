<?php
require_once __DIR__ . '/config.php';

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function is_logged_in(): bool {
    session_start_once();
    return !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function current_user_id(): int {
    session_start_once();
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_slug(): string {
    session_start_once();
    return $_SESSION['user_slug'] ?? '';
}

function current_user_name(): string {
    session_start_once();
    return $_SESSION['admin_username'] ?? '';
}

function is_superadmin(): bool {
    session_start_once();
    return !empty($_SESSION['is_superadmin']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
}

function require_superadmin(): void {
    require_login();
    if (!is_superadmin()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

/** Returns true on success, 'banned' if user is banned, false on bad credentials. */
function login(string $username, string $password): bool|string {
    require_once __DIR__ . '/db.php';
    // Use SELECT * so login works even before multi-user migration columns exist
    $stmt = db()->prepare(
        'SELECT * FROM admin_users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) return false;
    if (!empty($user['is_banned'])) return 'banned';
    session_start_once();
    session_regenerate_id(true);
    $_SESSION['user_id']         = (int) $user['id'];
    $_SESSION['user_slug']       = $user['slug'] ?? $user['username'];
    $_SESSION['is_superadmin']   = (bool) ($user['is_superadmin'] ?? false);
    $_SESSION['admin_logged_in'] = true; // legacy compat
    $_SESSION['admin_username']  = $user['username'];
    return true;
}

/** Returns true on success or an error string. */
function register_user(string $username, string $email, string $password, string $display_name = ''): bool|string {
    require_once __DIR__ . '/db.php';
    $username     = trim($username);
    $email        = strtolower(trim($email));
    $display_name = trim($display_name) ?: $username;

    if (strlen($username) < 3 || strlen($username) > 30)
        return 'O usuário deve ter entre 3 e 30 caracteres.';
    if (!preg_match('/^[a-z0-9_-]+$/i', $username))
        return 'Usuário só pode conter letras, números, _ e -.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return 'E-mail inválido.';
    if (strlen($password) < 8)
        return 'A senha deve ter no mínimo 8 caracteres.';

    $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '-', $username));

    $chk = db()->prepare('SELECT id FROM admin_users WHERE username = ? OR email = ? OR slug = ? LIMIT 1');
    $chk->execute([$username, $email, $slug]);
    if ($chk->fetch()) return 'Usuário ou e-mail já cadastrado.';

    $hash = password_hash($password, PASSWORD_BCRYPT);
    db()->prepare(
        'INSERT INTO admin_users (username, slug, display_name, email, password_hash, is_superadmin, is_banned)
         VALUES (?, ?, ?, ?, ?, 0, 0)'
    )->execute([$username, $slug, $display_name, $email, $hash]);
    return true;
}

function logout(): void {
    session_start_once();
    $_SESSION = [];
    session_destroy();
}

// Legacy alias
function admin_username(): string {
    return current_user_name();
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

function csrf_token(): string {
    session_start_once();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void {
    session_start_once();
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

