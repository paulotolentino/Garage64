<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Entre na sua conta.']);
    exit;
}
$messages = 0;
try {
    $st = db()->prepare('SELECT COUNT(*) FROM direct_messages WHERE recipient_id = ? AND is_read = 0');
    $st->execute([current_user_id()]);
    $messages = (int) $st->fetchColumn();
} catch (Throwable $e) { error_log($e->getMessage()); }
echo json_encode(['notifications' => get_unread_notifications_count(current_user_id()), 'messages' => $messages]);
