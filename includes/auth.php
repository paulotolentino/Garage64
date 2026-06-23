<?php
require_once __DIR__ . '/config.php';

function session_start_once(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        // Harden the session cookie (M1): block JS access (httponly), limit
        // cross-site sending (SameSite=Lax) and only allow HTTPS-only transport
        // when the request is actually served over TLS (keeps local HTTP working).
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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

    // Rate limiting: throttle brute force / credential stuffing by IP and by
    // username. Both buckets are checked *before* touching the database; a block
    // returns the same generic failure as a wrong password (no enumeration).
    // The username is hashed (sha256) so the bucket length is fixed and can never
    // overflow rate_limits.bucket VARCHAR(100), regardless of input size.
    $ip_bucket   = 'login:ip:' . client_ip();
    $user_bucket = 'login:user:' . hash('sha256', strtolower(trim($username)));
    if (rate_limit_exceeded($ip_bucket, 10, 900)
        || rate_limit_exceeded($user_bucket, 5, 900)) {
        return false;
    }

    // Use SELECT * so login works even before multi-user migration columns exist
    $stmt = db()->prepare(
        'SELECT * FROM admin_users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Count only failures, against both buckets.
        rate_limit_hit($ip_bucket, 900);
        rate_limit_hit($user_bucket, 900);
        return false;
    }
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

    // Rate limiting: cap account creation per IP (mass-signup / bot protection).
    // Checked first so a blocked client never reaches the database probes below.
    $reg_bucket = 'register:ip:' . client_ip();
    if (rate_limit_exceeded($reg_bucket, 3, 3600)) {
        return 'Não foi possível concluir o cadastro agora. Tente novamente mais tarde.';
    }

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

    // Block reserved routes/handles. Reuse the public "username taken" message so
    // we never disclose that a name is internally reserved.
    if (is_reserved_slug($slug) || is_reserved_slug($username)) {
        return 'Este nome de usuário já está em uso. Escolha outro.';
    }

    // Username/slug are PUBLIC handles (shown at /u/{slug}); revealing a clash is
    // not a privacy leak and keeps registration UX clear.
    $chk_user = db()->prepare('SELECT id FROM admin_users WHERE username = ? OR slug = ? LIMIT 1');
    $chk_user->execute([$username, $slug]);
    if ($chk_user->fetch()) {
        return 'Este nome de usuário já está em uso. Escolha outro.';
    }

    // E-mail is PRIVATE: never confirm whether an address exists. Return a generic
    // message (distinct from a thrown exception) to avoid user/e-mail enumeration.
    $chk_email = db()->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
    $chk_email->execute([$email]);
    if ($chk_email->fetch()) {
        return 'Não foi possível concluir o cadastro. Verifique os dados e tente novamente.';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    db()->prepare(
        'INSERT INTO admin_users (username, slug, display_name, email, password_hash, is_superadmin, is_banned)
         VALUES (?, ?, ?, ?, ?, 0, 0)'
    )->execute([$username, $slug, $display_name, $email, $hash]);

    // Count only successful account creations against the per-IP budget.
    rate_limit_hit($reg_bucket, 3600);
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

// ─── Reserved slugs ──────────────────────────────────────────────────────────
// Centralized so registration, the admin slug editor and the profile editor all
// share the same blocklist. Anything that collides with a public/admin route or
// a physical directory must never become a /u/{slug} handle.
function reserved_slugs(): array {
    return [
        '404', 'admin', 'api', 'assets', 'categories', 'collection', 'collections',
        'community', 'css', 'dashboard', 'database', 'export', 'follow', 'follows',
        'img', 'includes', 'index', 'install', 'js', 'login', 'logout', 'manutencao',
        'migrate_webp', 'mini', 'miniature', 'notifications', 'profile', 'register',
        'robots', 'setup', 'sitemap', 'static', 'tags', 'u', 'uploads', 'users',
        'wishlist',
    ];
}

function is_reserved_slug(string $slug): bool {
    return in_array(strtolower(trim($slug)), reserved_slugs(), true);
}

// ─── Rate limiting (fixed-window, DB-backed) ─────────────────────────────────
// Single-row counter per bucket. The window resets atomically once it expires,
// so the table stays tiny. No sessions, files or external cache involved.

/** Resolve the caller IP (Cloudflare/proxy handling is intentionally out of scope). */
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Whether $bucket has already reached $max hits inside the current $window
 * (in seconds). Read-only — does not increment. Expired windows count as zero.
 */
function rate_limit_exceeded(string $bucket, int $max, int $window): bool {
    require_once __DIR__ . '/db.php';
    $stmt = db()->prepare(
        'SELECT hits FROM rate_limits
         WHERE bucket = ? AND window_start >= (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$bucket, $window]);
    $hits = $stmt->fetchColumn();
    return $hits !== false && (int) $hits >= $max;
}

/**
 * Record one hit against $bucket. Resets the counter when the previous window
 * has expired, otherwise increments it atomically (single UPSERT).
 */
function rate_limit_hit(string $bucket, int $window): void {
    require_once __DIR__ . '/db.php';
    db()->prepare(
        'INSERT INTO rate_limits (bucket, hits, window_start)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE
            hits = IF(window_start < (NOW() - INTERVAL ? SECOND), 1, hits + 1),
            window_start = IF(window_start < (NOW() - INTERVAL ? SECOND), NOW(), window_start)'
    )->execute([$bucket, $window, $window]);

    // Opportunistic cleanup (no cron): ~1% of writes purge rows untouched for a day.
    if (random_int(1, 100) === 1) {
        try {
            db()->exec('DELETE FROM rate_limits WHERE updated_at < (NOW() - INTERVAL 1 DAY)');
        } catch (Throwable $e) { /* best-effort */ }
    }
}

