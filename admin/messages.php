<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Cache-Control: no-store');
$me = current_user_id();
$peer_id = (int) ($_GET['user'] ?? 0);
$peer = null;
$error = '';
$ready = true;
try {
    $active = db()->prepare('SELECT id FROM admin_users WHERE id = ? AND is_banned = 0');
    $active->execute([$me]);
    if (!$active->fetchColumn()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search_users'])) {
        header('Content-Type: application/json; charset=utf-8');
        $query = trim((string) ($_GET['q'] ?? ''));
        $users = [];
        if (mb_strlen($query) >= 2 && mb_strlen($query) <= 100) {
            $term = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $search = db()->prepare("SELECT id, slug, display_name, username FROM admin_users
                WHERE is_banned = 0 AND id <> ? AND
                (display_name LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!' OR slug LIKE ? ESCAPE '!')
                ORDER BY display_name, username LIMIT 20");
            $search->execute([$me, $term, $term, $term]);
            $users = $search->fetchAll();
        }
        echo json_encode(['users' => $users]);
        exit;
    }
    $st = db()->prepare('SELECT id, slug, display_name, username FROM admin_users WHERE id = ? AND is_banned = 0 AND id <> ?');
    $st->execute([$peer_id, $me]);
    $peer = $st->fetch() ?: null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (($_POST['action'] ?? '') === 'mark_seen') {
            header('Content-Type: application/json; charset=utf-8');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['message_ids'] ?? [])), static fn($id) => $id > 0)));
            if (!$peer || !$ids || count($ids) > 100) {
                http_response_code(422);
                echo json_encode(['ok' => false]);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare('UPDATE direct_messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
                WHERE recipient_id = ? AND sender_id = ? AND id IN (' . $placeholders . ')')->execute(array_merge([$me, $peer_id], $ids));
            $unseen = db()->prepare('SELECT 1 FROM direct_messages WHERE recipient_id = ? AND sender_id = ? AND is_read = 0 LIMIT 1');
            $unseen->execute([$me, $peer_id]);
            if (!$unseen->fetchColumn()) db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND actor_user_id = ? AND type = 'message'")->execute([$me, $peer_id]);
            echo json_encode(['ok' => true]);
            exit;
        }
        $body = trim((string) ($_POST['body'] ?? ''));
        if (!$peer) $error = 'Colecionador indisponível.';
        elseif ($body === '' || mb_strlen($body) > 2000) $error = 'Escreva uma mensagem de até 2000 caracteres.';
        elseif (rate_limit_exceeded('message:user:' . $me, 30, 300)) $error = 'Muitas mensagens. Aguarde alguns minutos.';
        else {
            db()->prepare('INSERT INTO direct_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)')->execute([$me, $peer_id, $body]);
            rate_limit_hit('message:user:' . $me, 300);
            // One unread conversation notice, instead of one notice per message.
            try {
                $st = db()->prepare("SELECT id FROM notifications WHERE user_id = ? AND actor_user_id = ? AND type = 'message' AND is_read = 0 LIMIT 1");
                $st->execute([$peer_id, $me]);
                if (!$st->fetchColumn()) create_notification($peer_id, $me, 'message', null, null, '/admin/messages?user=' . $me, $me);
            } catch (Throwable $e) { error_log('Message notification: ' . $e->getMessage()); }
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'Garage64') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($error ? 422 : 200);
            echo json_encode(['ok' => $error === '', 'error' => $error]);
            exit;
        }
        if (!$error) redirect('/admin/messages?user=' . $peer_id);
    }
    $st = db()->prepare('SELECT u.id, u.slug, u.display_name, u.username, totals.latest, totals.unread,
        last_message.body AS preview, last_message.created_at AS last_at
        FROM (SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS peer_id,
            MAX(id) AS latest, SUM(CASE WHEN recipient_id = ? AND is_read = 0 THEN 1 ELSE 0 END) AS unread
            FROM direct_messages WHERE sender_id = ? OR recipient_id = ? GROUP BY peer_id) totals
        JOIN admin_users u ON u.id = totals.peer_id
        JOIN direct_messages last_message ON last_message.id = totals.latest
        WHERE u.is_banned = 0 ORDER BY totals.latest DESC LIMIT 100');
    $st->execute([$me, $me, $me, $me]);
    $conversations = $st->fetchAll();
    $messages = [];
    if ($peer) {
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $st = db()->prepare('SELECT id, sender_id, body, created_at, read_at FROM direct_messages
            WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
            AND (? = 0 OR id < ?)
            ORDER BY id DESC LIMIT 100');
        $st->execute([$me, $peer_id, $peer_id, $me, $before, $before]);
        $messages = array_reverse($st->fetchAll());
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    $ready = false;
    $error = 'Mensagens indisponíveis no momento. Tente novamente mais tarde.';
    $conversations = $messages = [];
    if (isset($_GET['search_users'])) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Não foi possível buscar usuários. Tente novamente.']);
        exit;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'Garage64' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}
if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($ready ? 200 : 503);
    echo json_encode(['messages' => $messages, 'me' => $me]);
    exit;
}
$page_title = 'Mensagens';
require_once __DIR__ . '/../includes/messages_view.php';
exit;
