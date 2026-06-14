<?php
/**
 * Garage64 — Web Installer
 *
 * Access /install.php in your browser to set up the application without CLI.
 * After a successful installation this page returns 403 (installed.lock exists).
 *
 * Steps performed:
 *   1. Requirements check (PHP version, extensions, folder permissions)
 *   2. Configuration form  (DB credentials, app URL, admin credentials)
 *   3. Execution           (create DB, run schema, write config.local.php, create lock)
 */

declare(strict_types=1);

$lock_file   = __DIR__ . '/installed.lock';
$schema_file = __DIR__ . '/database/schema.sql';
$config_file = __DIR__ . '/includes/config.local.php';
$uploads_dir = __DIR__ . '/uploads';

// ─── Guard: already installed ────────────────────────────────────────────────

if (file_exists($lock_file)) {
    http_response_code(403);
    installer_html('Já instalado', function (): void {
        ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="fa fa-check-circle fs-4"></i>
            <div>O Garage64 já está instalado.
                <a href="/" class="alert-link">Ir para o site</a> &nbsp;|&nbsp;
                <a href="/admin/" class="alert-link">Painel admin</a>
            </div>
        </div>
        <?php
    });
    exit;
}

// ─── Session / CSRF ──────────────────────────────────────────────────────────

session_name('garage64_installer');
session_start();

if (empty($_SESSION['inst_csrf'])) {
    $_SESSION['inst_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['inst_csrf'];

// ─── Pure helper functions ───────────────────────────────────────────────────

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function verify_csrf(): void
{
    $tok = $_POST['_csrf'] ?? '';
    if (!isset($_SESSION['inst_csrf']) || !hash_equals($_SESSION['inst_csrf'], $tok)) {
        http_response_code(403);
        exit('Token CSRF inválido.');
    }
}

/** @return array{ok:bool, items:list<array{label:string,pass:bool,detail:string}>} */
function check_requirements(): array
{
    global $uploads_dir;

    $items = [];
    $all_ok = true;

    $add = function (string $label, bool $pass, string $detail) use (&$items, &$all_ok): void {
        if (!$pass) {
            $all_ok = false;
        }
        $items[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail];
    };

    $add('PHP ≥ 8.0', PHP_VERSION_ID >= 80000,
         'Versão detectada: ' . PHP_VERSION);
    $add('Extensão PDO', extension_loaded('pdo'),
         extension_loaded('pdo') ? 'Instalada' : 'Ausente — necessária para banco de dados');
    $add('Extensão PDO MySQL', extension_loaded('pdo_mysql'),
         extension_loaded('pdo_mysql') ? 'Instalada' : 'Ausente — necessária para MySQL/MariaDB');
    $add('Extensão mbstring', extension_loaded('mbstring'),
         extension_loaded('mbstring') ? 'Instalada' : 'Ausente — necessária para strings Unicode');
    $add('Extensão fileinfo', extension_loaded('fileinfo'),
         extension_loaded('fileinfo') ? 'Instalada' : 'Ausente — necessária para upload de fotos');

    $includes_writable = is_writable(__DIR__ . '/includes');
    $add('Pasta includes/ com permissão de escrita', $includes_writable,
         $includes_writable ? 'OK' : 'Chmod 755 ou dê escrita ao usuário do servidor web');

    $root_writable = is_writable(__DIR__);
    $add('Pasta raiz com permissão de escrita', $root_writable,
         $root_writable ? 'OK' : 'Necessário para criar installed.lock');

    if (!is_dir($uploads_dir)) {
        @mkdir($uploads_dir, 0750, true);
    }
    $uploads_writable = is_dir($uploads_dir) && is_writable($uploads_dir);
    $add('Pasta uploads/ com permissão de escrita', $uploads_writable,
         $uploads_writable ? 'OK' : 'Chmod 755 ou dê escrita ao usuário do servidor web');

    return ['ok' => $all_ok, 'items' => $items];
}

/**
 * Connect to MySQL without a database selected, create the target database,
 * run the schema (skipping CREATE DATABASE / USE statements), then create
 * the admin user and write config.local.php + installed.lock.
 *
 * @return list<string> Error messages; empty on success.
 */
function run_install(
    string $db_host,
    string $db_name,
    string $db_user,
    string $db_pass,
    string $app_name,
    string $app_url,
    string $admin_user,
    string $admin_pass
): array {
    global $schema_file, $config_file, $lock_file;

    // 1. Connect (no database yet)
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        return ['Não foi possível conectar ao servidor MySQL: ' . $e->getMessage()];
    }

    try {
        // 2. Create database
        $safe_db = preg_replace('/[^a-zA-Z0-9_]/', '', $db_name);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safe_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$safe_db}`");

        // 3. Run schema (skip CREATE DATABASE / USE lines)
        if (!is_readable($schema_file)) {
            return ['Arquivo de schema não encontrado: ' . $schema_file];
        }
        $sql   = file_get_contents($schema_file);
        $lines = explode("\n", $sql);
        $filtered = implode("\n", array_filter(
            $lines,
            fn (string $l): bool => !preg_match('/^\s*(CREATE DATABASE|USE\s)/i', $l)
        ));

        // Split on semicolons and execute each non-empty statement
        $statements = preg_split('/;\s*(\r?\n|$)/', $filtered, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }

        // 4. Create admin user
        $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
        $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash)
             VALUES (:u, :h)
             ON DUPLICATE KEY UPDATE password_hash = :h'
        )->execute(['u' => $admin_user, 'h' => $hash]);

        // 5. Write config.local.php
        $config_php = "<?php\n"
            . "// Generated by Garage64 web installer on " . date('Y-m-d H:i:s') . "\n\n"
            . "define('DB_HOST',    " . var_export($db_host, true) . ");\n"
            . "define('DB_NAME',    " . var_export($db_name, true) . ");\n"
            . "define('DB_USER',    " . var_export($db_user, true) . ");\n"
            . "define('DB_PASS',    " . var_export($db_pass, true) . ");\n"
            . "define('DB_CHARSET', 'utf8mb4');\n\n"
            . "define('APP_NAME', " . var_export($app_name, true) . ");\n"
            . "define('APP_URL',  " . var_export($app_url,  true) . ");\n";

        if (file_put_contents($config_file, $config_php) === false) {
            return ['Não foi possível escrever includes/config.local.php — verifique as permissões.'];
        }

        // 6. Write lock file (disables this installer)
        if (file_put_contents($lock_file, date('Y-m-d H:i:s') . "\n") === false) {
            return ['Instalação concluída, mas não foi possível criar installed.lock — o instalador permanecerá acessível.'];
        }

    } catch (Throwable $e) {
        return ['Erro durante a instalação: ' . $e->getMessage()];
    }

    return [];
}

// ─── Page output helper ──────────────────────────────────────────────────────

function installer_html(string $title, callable $body): void
{
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — Garage64 Installer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #111; color: #ddd; }
        .installer-card { max-width: 740px; margin: 3rem auto; }
        .req-icon-ok   { color: #198754; }
        .req-icon-fail { color: #dc3545; }
        .installer-header { background: #1a1a2e; border-bottom: 2px solid #ffc107; padding: 1.5rem 2rem; border-radius: .5rem .5rem 0 0; }
        .installer-body   { background: #1c1c1c; padding: 2rem; border-radius: 0 0 .5rem .5rem; }
        .form-control, .form-select { background: #2a2a2a; color: #eee; border-color: #444; }
        .form-control:focus, .form-select:focus { background: #2a2a2a; color: #fff; border-color: #ffc107; box-shadow: 0 0 0 .2rem rgba(255,193,7,.25); }
        label { color: #ccc; }
    </style>
</head>
<body>
<div class="container installer-card">
    <div class="installer-header">
        <h1 class="h4 mb-0 text-warning">
            <i class="fa fa-garage me-2"></i>Garage64
            <small class="text-secondary ms-2 fw-normal fs-6">Instalador</small>
        </h1>
    </div>
    <div class="installer-body">
        <h2 class="h5 mb-4"><?= h($title) ?></h2>
        <?php $body(); ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

// ─── Handle POST ─────────────────────────────────────────────────────────────

$errors  = [];
$success = false;
$post    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $post = [
        'db_host'     => trim($_POST['db_host']     ?? 'localhost'),
        'db_name'     => trim($_POST['db_name']     ?? 'garage64'),
        'db_user'     => trim($_POST['db_user']     ?? ''),
        'db_pass'     => $_POST['db_pass']           ?? '',
        'app_name'    => trim($_POST['app_name']    ?? 'Garage64'),
        'app_url'     => rtrim(trim($_POST['app_url'] ?? ''), '/'),
        'admin_user'  => trim($_POST['admin_user']  ?? 'admin'),
        'admin_pass'  => $_POST['admin_pass']        ?? '',
        'admin_pass2' => $_POST['admin_pass2']       ?? '',
    ];

    // Validate inputs
    if ($post['db_host'] === '') {
        $errors[] = 'Host do banco de dados é obrigatório.';
    }
    if ($post['db_name'] === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $post['db_name'])) {
        $errors[] = 'Nome do banco de dados é obrigatório e deve conter apenas letras, números e sublinhado.';
    }
    if ($post['db_user'] === '') {
        $errors[] = 'Usuário do banco de dados é obrigatório.';
    }
    if ($post['app_url'] === '') {
        $errors[] = 'URL da aplicação é obrigatória (ex.: https://meusite.com).';
    } elseif (!filter_var($post['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'URL da aplicação inválida.';
    }
    if ($post['admin_user'] === '') {
        $errors[] = 'Usuário admin é obrigatório.';
    }
    if (strlen($post['admin_pass']) < 8) {
        $errors[] = 'A senha do admin deve ter no mínimo 8 caracteres.';
    }
    if ($post['admin_pass'] !== $post['admin_pass2']) {
        $errors[] = 'As senhas do admin não conferem.';
    }

    if (empty($errors)) {
        $errors = run_install(
            $post['db_host'],
            $post['db_name'],
            $post['db_user'],
            $post['db_pass'],
            $post['app_name'],
            $post['app_url'],
            $post['admin_user'],
            $post['admin_pass']
        );
        $success = empty($errors);
    }
}

// ─── Render ──────────────────────────────────────────────────────────────────

if ($success) {
    installer_html('Instalação concluída!', function () use ($post): void {
        ?>
        <div class="alert alert-success">
            <h5><i class="fa fa-check-circle me-2"></i>Tudo pronto!</h5>
            <p class="mb-1">O Garage64 foi configurado com sucesso.</p>
            <ul class="mb-0">
                <li>Banco de dados: <strong><?= h($post['db_name']) ?></strong></li>
                <li>Admin: <strong><?= h($post['admin_user']) ?></strong></li>
            </ul>
        </div>
        <div class="d-flex gap-3 mt-4">
            <a href="/" class="btn btn-warning">
                <i class="fa fa-th me-1"></i>Ver coleção
            </a>
            <a href="/admin/" class="btn btn-outline-light">
                <i class="fa fa-lock me-1"></i>Painel admin
            </a>
        </div>
        <p class="text-secondary small mt-4">
            <i class="fa fa-info-circle me-1"></i>
            O instalador está bloqueado (<code>installed.lock</code> criado).
            Você pode excluir o arquivo <code>install.php</code> do servidor por segurança.
        </p>
        <?php
    });
    exit;
}

// Main installer page (requirements + form)
installer_html('Configuração inicial', function () use ($csrf, $errors, $post): void {
    $reqs = check_requirements();
    ?>

    <!-- ─── Requirements ─────────────────────────────────────────── -->
    <h3 class="h6 text-uppercase text-secondary mb-3">
        <i class="fa fa-list-check me-1"></i>Verificação de requisitos
    </h3>
    <table class="table table-dark table-sm mb-4 border-secondary">
        <tbody>
        <?php foreach ($reqs['items'] as $req): ?>
            <tr>
                <td class="w-50 <?= $req['pass'] ? '' : 'text-danger' ?>">
                    <i class="fa <?= $req['pass'] ? 'fa-check-circle req-icon-ok' : 'fa-times-circle req-icon-fail' ?> me-2"></i>
                    <?= h($req['label']) ?>
                </td>
                <td class="text-secondary small align-middle"><?= h($req['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!$reqs['ok']): ?>
        <div class="alert alert-warning">
            <i class="fa fa-triangle-exclamation me-2"></i>
            Corrija os itens marcados acima e recarregue esta página antes de continuar.
        </div>
        <?php return; ?>
    <?php else: ?>
        <div class="alert alert-success py-2 mb-4">
            <i class="fa fa-check-circle me-2"></i>
            Todos os requisitos foram atendidos.
        </div>
    <?php endif; ?>

    <!-- ─── Errors ───────────────────────────────────────────────── -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong><i class="fa fa-triangle-exclamation me-2"></i>Erros encontrados:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ─── Config form ──────────────────────────────────────────── -->
    <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <h3 class="h6 text-uppercase text-secondary mb-3 mt-2">
            <i class="fa fa-database me-1"></i>Banco de dados
        </h3>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="db_host">Host</label>
                <input type="text" id="db_host" name="db_host" class="form-control"
                       value="<?= h($post['db_host'] ?? 'localhost') ?>" required
                       placeholder="localhost">
                <div class="form-text text-secondary">Geralmente <code>localhost</code> ou <code>127.0.0.1</code>.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="db_name">Nome do banco</label>
                <input type="text" id="db_name" name="db_name" class="form-control"
                       value="<?= h($post['db_name'] ?? 'garage64') ?>" required
                       placeholder="garage64">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="db_user">Usuário</label>
                <input type="text" id="db_user" name="db_user" class="form-control"
                       value="<?= h($post['db_user'] ?? '') ?>" required
                       autocomplete="username">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="db_pass">Senha</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control"
                       autocomplete="new-password">
                <div class="form-text text-secondary">Deixe em branco se não houver senha.</div>
            </div>
        </div>

        <h3 class="h6 text-uppercase text-secondary mb-3">
            <i class="fa fa-globe me-1"></i>Aplicação
        </h3>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="app_name">Nome do site</label>
                <input type="text" id="app_name" name="app_name" class="form-control"
                       value="<?= h($post['app_name'] ?? 'Garage64') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="app_url">URL pública <span class="text-danger">*</span></label>
                <input type="url" id="app_url" name="app_url" class="form-control"
                       value="<?= h($post['app_url'] ?? '') ?>" required
                       placeholder="https://meusite.com">
                <div class="form-text text-secondary">Sem barra no final. Ex.: <code>https://garage64.com</code></div>
            </div>
        </div>

        <h3 class="h6 text-uppercase text-secondary mb-3">
            <i class="fa fa-user-shield me-1"></i>Usuário administrador
        </h3>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label" for="admin_user">Usuário</label>
                <input type="text" id="admin_user" name="admin_user" class="form-control"
                       value="<?= h($post['admin_user'] ?? 'admin') ?>" required
                       autocomplete="username">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="admin_pass">Senha <span class="text-danger">*</span></label>
                <input type="password" id="admin_pass" name="admin_pass" class="form-control"
                       required minlength="8" autocomplete="new-password">
                <div class="form-text text-secondary">Mínimo 8 caracteres.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="admin_pass2">Confirmar senha <span class="text-danger">*</span></label>
                <input type="password" id="admin_pass2" name="admin_pass2" class="form-control"
                       required minlength="8" autocomplete="new-password">
            </div>
        </div>

        <hr class="border-secondary">

        <button type="submit" class="btn btn-warning btn-lg">
            <i class="fa fa-rocket me-2"></i>Instalar Garage64
        </button>
        <p class="text-secondary small mt-3 mb-0">
            <i class="fa fa-info-circle me-1"></i>
            O instalador criará o banco de dados, executará o schema e salvará as configurações em
            <code>includes/config.local.php</code>. Após concluir, o acesso a esta página será bloqueado.
        </p>
    </form>
    <?php
});
