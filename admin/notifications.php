<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// ─── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['notif_action'] ?? '');

    if ($action === 'read_all') {
        mark_all_notifications_read(current_user_id());
        flash('Todas as notificações foram marcadas como lidas.');
        redirect('/admin/notifications');
    }

    if ($action === 'read_one') {
        $id = (int) ($_POST['notification_id'] ?? 0);
        mark_notification_read($id, current_user_id());
        redirect('/admin/notifications');
    }
}

// ─── Open: mark a single notification read, then go to its target ────────────────
if (isset($_GET['open'])) {
    $id   = (int) $_GET['open'];
    $list = get_user_notifications(current_user_id(), 100);
    $target = null;
    foreach ($list as $n) {
        if ((int) $n['id'] === $id) { $target = $n['target_url']; break; }
    }
    if ($target !== null) {
        mark_notification_read($id, current_user_id());
        redirect($target);
    }
    redirect('/admin/notifications');
}

$notifications = get_user_notifications(current_user_id(), 50);
$unread_count  = get_unread_notifications_count(current_user_id());
$page_title    = 'Notificações';

$type_meta = [
    'comment' => ['icon' => 'fa-comment',        'label' => 'comentou na sua miniatura'],
    'reply'   => ['icon' => 'fa-reply',          'label' => 'respondeu seu comentário'],
    'mention' => ['icon' => 'fa-at',             'label' => 'mencionou você'],
];

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <h1 class="h4 mb-0 me-auto">
        <i class="fa fa-bell me-2 text-warning"></i>Notificações
        <?php if ($unread_count > 0): ?>
            <span class="badge rounded-pill bg-warning text-dark align-middle"><?= (int) $unread_count ?></span>
        <?php endif; ?>
    </h1>
    <?php if ($unread_count > 0): ?>
    <form method="post" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="notif_action" value="read_all">
        <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="fa fa-check-double me-1"></i>Marcar todas como lidas
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
    <div class="text-center text-secondary py-5">
        <i class="fa fa-bell-slash fa-2x mb-3 d-block"></i>
        Você ainda não tem notificações.
    </div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($notifications as $n): ?>
            <?php
            $type = (string) $n['type'];
            $meta = $type_meta[$type] ?? ['icon' => 'fa-bell', 'label' => 'interagiu com você'];
            $actor = $n['actor_name'] !== null && $n['actor_name'] !== ''
                ? $n['actor_name']
                : ($n['actor_username'] ?? 'Alguém');
            $is_unread = (int) $n['is_read'] === 0;
            ?>
            <div class="list-group-item list-group-item-action d-flex align-items-start gap-3 <?= $is_unread ? 'border-start border-warning border-3' : '' ?>">
                <span class="fs-5 <?= $is_unread ? 'text-warning' : 'text-secondary' ?>">
                    <i class="fa <?= h($meta['icon']) ?>"></i>
                </span>
                <a href="<?= h(APP_URL) ?>/admin/notifications?open=<?= (int) $n['id'] ?>" class="flex-grow-1 text-decoration-none text-reset">
                    <div class="<?= $is_unread ? 'fw-semibold' : '' ?>">
                        <strong><?= e($actor) ?></strong> <?= h($meta['label']) ?>
                        <?php if (!empty($n['miniature_name'])): ?>
                            — <span class="text-warning"><?= e($n['miniature_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <small class="text-secondary">
                        <i class="fa fa-clock me-1"></i><?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?>
                    </small>
                </a>
                <?php if ($is_unread): ?>
                <form method="post" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="notif_action" value="read_one">
                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Marcar como lida">
                        <i class="fa fa-check"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
