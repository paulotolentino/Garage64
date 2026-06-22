<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Keyset pagination: ?before=YYYY-MM-DD HH:MM:SS (validated; used as a bound param).
$before = null;
if (isset($_GET['before'])) {
    $b = trim((string) $_GET['before']);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $b)) {
        $before = $b;
    }
}

$per_page = 20;

// Logged-in collectors see only activity from people they follow; visitors get
// the global wall. (Use ?scope=global as an escape hatch when logged in.)
$viewer_id   = is_logged_in() ? current_user_id() : 0;
$is_logged   = $viewer_id > 0;
$want_global = $is_logged && (($_GET['scope'] ?? '') === 'global');
$feed_viewer = ($is_logged && !$want_global) ? $viewer_id : null;

// Fetch one extra row to know whether a "next" page exists.
$events   = get_community_feed($per_page + 1, $before, $feed_viewer);
$has_more = count($events) > $per_page;
if ($has_more) {
    array_pop($events);
}
$next_before = $has_more ? $events[count($events) - 1]['created_at'] : null;

// Distinguish the two "personal feed" empty states.
$following_count = ($feed_viewer !== null) ? count_following($viewer_id) : 0;

// Visual metadata per event type (icon + scoped modifier).
$feed_meta = [
    'new_miniature' => ['icon' => 'fa-car-side',  'mod' => 'new'],
    'comment'       => ['icon' => 'fa-comment',   'mod' => 'comment'],
    'reply'         => ['icon' => 'fa-reply',      'mod' => 'reply'],
    'follow'        => ['icon' => 'fa-user-plus',  'mod' => 'follow'],
];

$page_title     = 'Mural da Comunidade';
$body_class     = 'feed-page';
$og_title       = 'Mural da Comunidade';
$og_description = 'O que está rolando nas garagens dos colecionadores do ' . APP_NAME . '.';
$og_url         = APP_URL . '/community';

// Header copy adapts to audience.
$is_personal = ($feed_viewer !== null);
$hero_title  = $is_personal ? 'Mural dos Seguidos' : 'Mural da Comunidade';
$hero_sub    = $is_personal
    ? 'Novidades das garagens que você acompanha.'
    : 'O que está rolando nas garagens dos colecionadores.';

require_once __DIR__ . '/includes/header_public.php';
?>

<section class="feed-hero">
    <h1 class="feed-hero-title"><i class="fa fa-bullhorn"></i> <?= e($hero_title) ?></h1>
    <p class="feed-hero-sub"><?= e($hero_sub) ?></p>
    <?php if ($is_logged): ?>
        <?php if ($is_personal): ?>
            <a class="feed-scope-link" href="/community?scope=global"><i class="fa fa-globe"></i> Ver mural global</a>
        <?php else: ?>
            <a class="feed-scope-link" href="/community"><i class="fa fa-user-check"></i> Ver apenas quem sigo</a>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (empty($events)): ?>
    <div class="feed-empty">
        <i class="fa fa-warehouse feed-empty-ico"></i>
        <?php if ($is_personal && $following_count === 0): ?>
            <p>Sua garagem ainda está quieta. Siga outros colecionadores para ver as novidades deles por aqui.</p>
        <?php elseif ($is_personal): ?>
            <p>Os colecionadores que você segue ainda não movimentaram a garagem.</p>
        <?php else: ?>
            <p>A oficina ainda está quieta… seja o primeiro a estacionar uma miniatura ou puxar uma conversa.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="feed-list">
        <?php foreach ($events as $ev):
            $type   = (string) $ev['type'];
            $meta   = $feed_meta[$type] ?? ['icon' => 'fa-bolt', 'mod' => 'new'];

            $actor_name = ($ev['actor_display_name'] !== null && $ev['actor_display_name'] !== '')
                ? $ev['actor_display_name']
                : (string) $ev['actor_username'];
            $actor_slug = (string) $ev['actor_slug'];
            $actor_url  = '/u/' . rawurlencode($actor_slug);
            $actor_av   = avatar_url($ev['actor_avatar'] ?? null);

            // Miniature link (only for miniature-bound events).
            $mini_link = '';
            if (!empty($ev['miniature_id']) && $ev['miniature_name'] !== null) {
                $mini_link = mini_url(['id' => (int) $ev['miniature_id'], 'name' => (string) $ev['miniature_name']]);
            }

            // Target collector (only for follow).
            $target_name = ($ev['target_display_name'] !== null && $ev['target_display_name'] !== '')
                ? $ev['target_display_name']
                : (string) ($ev['target_username'] ?? '');
            $target_slug = (string) ($ev['target_slug'] ?? '');
            $target_url  = $target_slug !== '' ? '/u/' . rawurlencode($target_slug) : '';

            $actor_html  = '<a class="feed-name" href="' . e($actor_url) . '">' . e($actor_name) . '</a>';
            $mini_html   = $mini_link !== ''
                ? '<a class="feed-obj" href="' . e($mini_link) . '">' . e((string) $ev['miniature_name']) . '</a>'
                : '';
            $target_html = $target_url !== ''
                ? '<a class="feed-name" href="' . e($target_url) . '">' . e($target_name) . '</a>'
                : e($target_name);
        ?>
        <article class="feed-item feed-type-<?= e($meta['mod']) ?>">
            <a class="feed-avatar" href="<?= e($actor_url) ?>" title="<?= e($actor_name) ?>">
                <img src="<?= e($actor_av) ?>" alt="" loading="lazy">
                <span class="feed-type"><i class="fa <?= e($meta['icon']) ?>"></i></span>
            </a>
            <div class="feed-content">
                <p class="feed-line">
                    <?php if ($type === 'new_miniature'): ?>
                        <?= $actor_html ?> estacionou uma nova peça na garagem: <?= $mini_html ?>
                    <?php elseif ($type === 'comment'): ?>
                        <?= $actor_html ?> comentou na garagem de <?= $mini_html ?>
                    <?php elseif ($type === 'reply'): ?>
                        <?= $actor_html ?> respondeu uma conversa em <?= $mini_html ?>
                    <?php elseif ($type === 'follow'): ?>
                        <?= $actor_html ?> começou a acompanhar a garagem de <?= $target_html ?>
                    <?php endif; ?>
                </p>
                <span class="feed-meta"><i class="fa fa-clock"></i> <?= e(time_ago((string) $ev['created_at'])) ?></span>
            </div>
            <?php if ($mini_link !== '' && !empty($ev['miniature_photo'])): ?>
                <a class="feed-thumb" href="<?= e($mini_link) ?>" aria-hidden="true" tabindex="-1">
                    <img src="<?= e(thumb_url($ev['miniature_photo'])) ?>" alt="" loading="lazy">
                </a>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if ($next_before): ?>
        <div class="feed-load-more-wrap">
            <a class="feed-load-more" href="/community?<?= $want_global ? 'scope=global&amp;' : '' ?>before=<?= e(urlencode($next_before)) ?>">
                <i class="fa fa-arrow-down"></i> Carregar mais
            </a>
        </div>
    <?php else: ?>
        <p class="feed-end">Você chegou ao fim do mural por enquanto.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
