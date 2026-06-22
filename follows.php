<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$slug  = trim($_GET['slug'] ?? '');
$view  = (($_GET['view'] ?? '') === 'following') ? 'following' : 'followers';
$owner = $slug ? get_user_by_slug($slug) : null;

if (!$owner) {
    http_response_code(404);
    $page_title = 'Colecionador não encontrado';
    require_once __DIR__ . '/includes/header_public.php';
    echo '<div class="text-center py-5"><i class="fa fa-user-slash fa-3x text-secondary mb-3 d-block"></i>
          <h2 class="text-light">Colecionador não encontrado</h2>
          <a href="/" class="btn btn-warning mt-3">Voltar ao início</a></div>';
    require_once __DIR__ . '/includes/footer_public.php';
    exit;
}

$uid       = (int) $owner['id'];
$seg       = $view === 'following' ? 'seguindo' : 'seguidores';
$list_url  = '/u/' . rawurlencode($slug) . '/' . $seg;

// ─── Seguir / Deixar de seguir (POST local — espelha collection.php) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['follow', 'unfollow'], true)) {
    if (!is_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
    verify_csrf();
    $me     = current_user_id();
    $target = (int) ($_POST['target_id'] ?? 0);
    if ($target > 0 && $me !== $target) { // nunca seguir a si mesmo
        if ($_POST['action'] === 'follow') {
            follow_user($me, $target);
            // Best-effort: notifica o colecionador seguido (sem miniatura, sem self, dedup interno).
            try {
                create_notification(
                    $target, $me, 'follow',
                    null, null, '/u/' . current_user_slug(), $target
                );
            } catch (Throwable $e) { /* nunca bloqueia o follow */ }
        } else {
            unfollow_user($me, $target);
        }
    }
    header('Location: ' . $list_url);
    exit;
}

$display_name = $owner['display_name'] ?: $owner['username'];

$followers_count = count_followers($uid);
$following_count = count_following($uid);
$list = $view === 'following' ? get_following($uid) : get_followers($uid);

// Pré-carrega quais dos listados o visitante já segue (evita N+1).
$viewer_id        = is_logged_in() ? current_user_id() : 0;
$viewer_following = [];
if ($viewer_id > 0 && $list) {
    $ids = array_map(static fn($c) => (int) $c['id'], $list);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = db()->prepare("SELECT following_id FROM user_follows WHERE follower_id = ? AND following_id IN ($ph)");
    $st->execute(array_merge([$viewer_id], $ids));
    $viewer_following = array_map('intval', array_column($st->fetchAll(), 'following_id'));
}

$page_title = ($view === 'following' ? 'Seguindo' : 'Seguidores') . ' · ' . $display_name;
$body_class = 'follows-page';
$og_url     = APP_URL . $list_url;

require_once __DIR__ . '/includes/header_public.php';
?>

<section class="follows-head">
    <a class="follows-owner" href="/u/<?= e($slug) ?>">
        <i class="fa fa-arrow-left"></i>
        <span><?= e($display_name) ?></span>
        <small>@<?= e($slug) ?></small>
    </a>
    <nav class="follows-tabs" aria-label="Seguidores e seguindo">
        <a class="follows-tab<?= $view === 'followers' ? ' is-active' : '' ?>" href="/u/<?= e($slug) ?>/seguidores">
            <strong><?= number_format($followers_count) ?></strong> <?= $followers_count === 1 ? 'seguidor' : 'seguidores' ?>
        </a>
        <a class="follows-tab<?= $view === 'following' ? ' is-active' : '' ?>" href="/u/<?= e($slug) ?>/seguindo">
            <strong><?= number_format($following_count) ?></strong> seguindo
        </a>
    </nav>
</section>

<?php if (empty($list)): ?>
    <div class="follows-empty">
        <i class="fa fa-users-slash follows-empty-ico"></i>
        <p>
            <?php if ($view === 'following'): ?>
                <?= e($display_name) ?> ainda não segue nenhum colecionador.
            <?php else: ?>
                Nenhum colecionador segue <?= e($display_name) ?> ainda.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <ul class="follows-list">
        <?php foreach ($list as $c):
            $c_id     = (int) $c['id'];
            $c_name   = ($c['display_name'] !== null && $c['display_name'] !== '') ? $c['display_name'] : (string) $c['username'];
            $c_slug   = (string) $c['slug'];
            $c_url    = '/u/' . rawurlencode($c_slug);
            $c_av     = avatar_url($c['avatar'] ?? null);
            $c_minis  = (int) ($c['mini_count'] ?? 0);
            $c_bio    = trim((string) ($c['bio'] ?? ''));
            $is_self  = $viewer_id === $c_id;
            $i_follow = in_array($c_id, $viewer_following, true);
        ?>
        <li class="follows-item">
            <a class="follows-avatar" href="<?= e($c_url) ?>" title="<?= e($c_name) ?>">
                <img src="<?= e($c_av) ?>" alt="" loading="lazy">
            </a>
            <div class="follows-info">
                <a class="follows-name" href="<?= e($c_url) ?>"><?= e($c_name) ?></a>
                <span class="follows-handle">@<?= e($c_slug) ?> · <?= number_format($c_minis) ?> peça<?= $c_minis !== 1 ? 's' : '' ?> pública<?= $c_minis !== 1 ? 's' : '' ?></span>
                <?php if ($c_bio !== ''): ?>
                    <span class="follows-bio"><?= e(mb_strimwidth($c_bio, 0, 90, '…')) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$is_self): ?>
                <div class="follows-action">
                    <?php if ($viewer_id > 0): ?>
                        <form method="post" action="<?= e($list_url) ?>" class="follow-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="target_id" value="<?= $c_id ?>">
                            <input type="hidden" name="action" value="<?= $i_follow ? 'unfollow' : 'follow' ?>">
                            <?php if ($i_follow): ?>
                                <button type="submit" class="follow-btn is-following" title="Deixar de seguir <?= e($c_name) ?>">
                                    <span class="follow-state follow-state-default"><i class="fa fa-user-check"></i> Seguindo</span>
                                    <span class="follow-state follow-state-hover"><i class="fa fa-user-xmark"></i> Deixar de seguir</span>
                                </button>
                            <?php else: ?>
                                <button type="submit" class="follow-btn" title="Seguir <?= e($c_name) ?>">
                                    <i class="fa fa-user-plus"></i> <span>Seguir</span>
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <a href="/admin/login" class="follow-btn follow-cta" title="Entre para seguir este colecionador">
                            <i class="fa fa-user-plus"></i> <span>Seguir</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
