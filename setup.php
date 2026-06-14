#!/usr/bin/env php
<?php
/**
 * Garage64 — Admin setup tool
 * Usage: php setup.php
 *
 * Creates or resets the admin user in the database.
 * Run this once after importing the schema.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

echo "\n=== Garage64 Admin Setup ===\n\n";

echo "Usuário admin: ";
$username = trim(fgets(STDIN));
if (!$username) {
    $username = 'admin';
    echo "(usando padrão: admin)\n";
}

echo "Senha: ";
// Hide input if possible
system('stty -echo 2>/dev/null');
$password = trim(fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

if (strlen($password) < 8) {
    echo "Erro: a senha deve ter pelo menos 8 caracteres.\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (username, password_hash)
         VALUES (:username, :hash)
         ON DUPLICATE KEY UPDATE password_hash = :hash'
    );
    $stmt->execute(['username' => $username, 'hash' => $hash]);
    echo "\nAdmin '{$username}' criado/atualizado com sucesso!\n";
    echo "Acesse /admin/ no seu navegador.\n\n";
} catch (PDOException $e) {
    echo "Erro ao salvar no banco: " . $e->getMessage() . "\n";
    exit(1);
}
