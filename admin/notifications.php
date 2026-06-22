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

// ─── Type metadata (icon + label + color modifier) ───────────────────────────
$type_meta = [
    'comment' => ['icon' => 'fa-comment', 'label' => 'comentou na sua miniatura', 'mod' => 'comment'],
    'reply'   => ['icon' => 'fa-reply',   'label' => 'respondeu seu comentário',  'mod' => 'reply'],
    'mention' => ['icon' => 'fa-at',      'label' => 'mencionou você',            'mod' => 'mention'],
    'like'    => ['icon' => 'fa-heart',   'label' => 'curtiu sua miniatura',      'mod' => 'like'],
    'follow'  => ['icon' => 'fa-user-plus','label' => 'começou a seguir você',     'mod' => 'follow'],
];

// ─── Filter (query param, no JS required) ────────────────────────────────────
$valid_filters = ['all', 'unread', 'comment', 'reply', 'mention', 'like', 'follow'];
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, $valid_filters, true)) {
    $filter = 'all';
}

$filtered = array_values(array_filter($notifications, static function (array $n) use ($filter): bool {
    if ($filter === 'all')    return true;
    if ($filter === 'unread') return (int) $n['is_read'] === 0;
    return (string) $n['type'] === $filter;
}));

// ─── Local helper: relative "time ago" (pt-BR), scoped to this page ──────────
$notif_time_ago = static function (string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 0)    $diff = 0;
    if ($diff < 60)   return 'agora mesmo';
    $mins = (int) floor($diff / 60);
    if ($mins < 60)   return 'há ' . $mins . ($mins === 1 ? ' minuto' : ' minutos');
    $hours = (int) floor($diff / 3600);
    if ($hours < 24)  return 'há ' . $hours . ($hours === 1 ? ' hora' : ' horas');
    $days = (int) floor($diff / 86400);
    if ($days < 7)    return 'há ' . $days . ($days === 1 ? ' dia' : ' dias');
    if ($days < 30)   { $w = (int) floor($days / 7); return 'há ' . $w . ($w === 1 ? ' semana' : ' semanas'); }
    if ($days < 365)  { $mo = (int) floor($days / 30); return 'há ' . $mo . ($mo === 1 ? ' mês' : ' meses'); }
    $y = (int) floor($days / 365);
    return 'há ' . $y . ($y === 1 ? ' ano' : ' anos');
};

// ─── Group filtered notifications by time bucket ─────────────────────────────
$groups = ['today' => [], 'week' => [], 'older' => []];
$today_start = strtotime('today');
$week_start  = strtotime('-7 days');
foreach ($filtered as $n) {
    $ts = strtotime($n['created_at']);
    if ($ts >= $today_start)     $groups['today'][] = $n;
    elseif ($ts >= $week_start)  $groups['week'][]  = $n;
    else                         $groups['older'][] = $n;
}
$group_labels = ['today' => 'Hoje', 'week' => 'Esta semana', 'older' => 'Mais antigas'];

// ─── Filter tabs definition ──────────────────────────────────────────────────
$filter_tabs = [
    'all'     => ['label' => 'Tudo',      'icon' => 'fa-layer-group'],
    'unread'  => ['label' => 'Não lidas', 'icon' => 'fa-circle-dot'],
    'comment' => ['label' => 'Comentários','icon' => 'fa-comment'],
    'reply'   => ['label' => 'Respostas', 'icon' => 'fa-reply'],
    'mention' => ['label' => 'Menções',   'icon' => 'fa-at'],
    'like'    => ['label' => 'Curtidas',  'icon' => 'fa-heart'],
    'follow'  => ['label' => 'Seguidores','icon' => 'fa-user-plus'],
];

require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="notif-hero dash-hero">
    <div class="notif-hero-ico">
        <i class="fa fa-bell"></i>
    </div>
    <div class="notif-hero-text">
        <span class="lp-eyebrow">Central de atividade</span>
        <h1 class="notif-hero-title">Notificações</h1>
        <p class="notif-hero-sub">
            <?php if ($unread_count > 0): ?>
                Você tem <strong><?= (int) $unread_count ?></strong> não <?= $unread_count === 1 ? 'lida' : 'lidas' ?>.
            <?php else: ?>
                Tudo em dia. Nenhuma notificação pendente.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($unread_count > 0): ?>
    <div class="notif-hero-actions">
        <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="notif_action" value="read_all">
            <button type="submit" class="md-btn">
                <i class="fa fa-check-double"></i>Marcar todas como lidas
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="notif-filters">
    <?php foreach ($filter_tabs as $key => $tab): ?>
        <a href="<?= h(APP_URL) ?>/admin/notifications?filter=<?= h($key) ?>"
           class="notif-filter <?= $filter === $key ? 'is-active' : '' ?>">
            <i class="fa <?= h($tab['icon']) ?>"></i><?= h($tab['label']) ?>
            <?php if ($key === 'unread' && $unread_count > 0): ?>
                <span class="notif-filter-count"><?= (int) $unread_count ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($filtered)): ?>
    <div class="notif-empty">
        <div class="notif-empty-ico"><i class="fa fa-bell-slash"></i></div>
        <p class="notif-empty-title">
            <?= $filter === 'all' ? 'Nenhuma notificação ainda' : 'Nada por aqui com este filtro' ?>
        </p>
        <p class="notif-empty-sub">
            <?php if ($filter === 'all'): ?>
                Quando alguém comentar, responder ou mencionar você, aparece aqui.
            <?php else: ?>
                Tente outro filtro para ver mais atividades.
            <?php endif; ?>
        </p>
        <?php if ($filter !== 'all'): ?>
            <a href="<?= h(APP_URL) ?>/admin/notifications?filter=all" class="md-btn">
                <i class="fa fa-layer-group"></i>Ver tudo
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($groups as $gkey => $items): ?>
        <?php if (empty($items)) continue; ?>
        <section class="notif-group">
            <h2 class="notif-group-title"><?= h($group_labels[$gkey]) ?></h2>
            <div class="notif-list">
                <?php foreach ($items as $n): ?>
                    <?php
                    $type  = (string) $n['type'];
                    $meta  = $type_meta[$type] ?? ['icon' => 'fa-bell', 'label' => 'interagiu com você', 'mod' => 'comment'];
                    $actor = $n['actor_name'] !== null && $n['actor_name'] !== ''
                        ? $n['actor_name']
                        : ($n['actor_username'] ?? 'Alguém');
                    $is_unread = (int) $n['is_read'] === 0;
                    $avatar    = avatar_url($n['actor_avatar'] ?? null);
                    ?>
                    <a href="<?= h(APP_URL) ?>/admin/notifications?open=<?= (int) $n['id'] ?>"
                       class="notif-item notif-type-<?= h($meta['mod']) ?> <?= $is_unread ? 'is-unread' : '' ?>">
                        <span class="notif-avatar">
                            <img src="<?= h($avatar) ?>" alt="" loading="lazy">
                            <span class="notif-badge"><i class="fa <?= h($meta['icon']) ?>"></i></span>
                        </span>
                        <span class="notif-body">
                            <span class="notif-line">
                                <strong><?= e($actor) ?></strong> <?= h($meta['label']) ?><?php if (!empty($n['miniature_name'])): ?> — <span class="notif-target"><?= e($n['miniature_name']) ?></span><?php endif; ?>
                            </span>
                            <span class="notif-time"><i class="fa fa-clock"></i><?= h($notif_time_ago($n['created_at'])) ?></span>
                        </span>
                        <?php if ($is_unread): ?>
                            <span class="notif-dot" aria-label="Não lida"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
