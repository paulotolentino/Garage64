<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/account_email.php';
function check_account_email(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
foreach (['verify_email', 'reset_password'] as $purpose) {
    $template = account_email_template($purpose, '<img src=x onerror=alert(1)>', 'https://garage64.online/' . $purpose . '?token=abc&x=1');
    check_account_email(!str_contains($template['html'], '<img src=x'), 'Template must escape user data');
    check_account_email(str_contains($template['html'], '&amp;x=1'), 'Template must escape link attributes');
    check_account_email(str_contains($template['text'], '?token=abc&x=1'), 'Plain text link must remain usable');
}
check_account_email(!consume_account_token('invalid', 'verify_email'), 'Malformed token rejected');
check_account_email(!consume_account_token(str_repeat('a', 64), 'reset_password', 'short'), 'Weak password rejected before DB');
check_account_email(!consume_account_token(str_repeat('a', 64), 'unknown'), 'Unknown purpose rejected');
echo "PASS templates: escaped HTML, usable plaintext links; malformed tokens and weak passwords rejected\n";

// Set TEST_MYSQL_DB to an isolated database whose name starts with garage64_test_.
$test_db = getenv('TEST_MYSQL_DB');
if (!$test_db) { echo "SKIP MySQL lifecycle tests: TEST_MYSQL_DB not configured\n"; exit; }
// Constants must be configured before loading config.php; use a separate process.
if (($argv[1] ?? '') !== 'mysql') {
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/account-email-mysql.php'), $code);
    exit($code);
}
