<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect('/');
}

$miniature = get_miniature($id, current_user_id() ?: null);
if (!$miniature || !$miniature['is_public']) {
    http_response_code(404);
    $page_title = 'Não encontrada';
    require_once __DIR__ . '/includes/header_public.php';
    echo '<div class="text-center py-5"><h2>Miniatura não encontrada.</h2><a href="/" class="btn btn-warning mt-3">Voltar</a></div>';
    require_once __DIR__ . '/includes/footer_public.php';
    exit;
}

$photos   = get_miniature_photos($id);
$tags     = get_miniature_tags($id);
$adjacent = get_adjacent_miniatures($id, (int) ($miniature['user_id'] ?? 0));
// Increment view counter (best-effort, ignore errors)
try {
    db()->prepare('UPDATE miniatures SET views = views + 1 WHERE id = ?')->execute([$id]);
    // Invalidate dashboard stats cache so top_viewed reflects the new count
    session_start_once();
    unset($_SESSION['stats_cache']);
} catch (Throwable $e) {}

// ─── Public rating (POST) ─────────────────────────────────────────────────────
$rating_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['public_rating'])) {
    $submitted = (int) $_POST['public_rating'];
    $ip        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (submit_public_rating($id, $submitted, $ip)) {
        $rating_msg = 'ok';
    }
    header('Location: ' . mini_url($miniature) . '#rating');
    exit;
}

// ─── Comentários (POST) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_action'])) {
    verify_csrf();
    $action = (string) $_POST['comment_action'];
    if ($action === 'create' && is_logged_in()) {
        $parent_id = (int) ($_POST['comment_parent_id'] ?? 0) ?: null;
        create_miniature_comment($id, current_user_id(), (string) ($_POST['comment_body'] ?? ''), $parent_id);
    } elseif ($action === 'pin') {
        toggle_miniature_comment_pin((int) ($_POST['comment_id'] ?? 0), current_user_id());
    } elseif ($action === 'delete') {
        delete_miniature_comment((int) ($_POST['comment_id'] ?? 0), current_user_id());
    }
    header('Location: ' . mini_url($miniature) . '#comments');
    exit;
}

// ─── Curtidas (POST) ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['like', 'unlike'], true)) {
    // Usuário deslogado é direcionado ao login ao tentar curtir.
    if (!is_logged_in()) {
        header('Location: /admin/login');
        exit;
    }
    verify_csrf();
    if ($_POST['action'] === 'like') {
        like_miniature($id, current_user_id());
        // Best-effort: notify the owner (skips self-like and duplicates internally).
        try {
            create_notification(
                (int) $miniature['user_id'], current_user_id(), 'like',
                $id, null, mini_url($miniature)
            );
        } catch (Throwable $e) { /* never block the like */ }
    } else {
        unlike_miniature($id, current_user_id());
    }
    header('Location: ' . mini_url($miniature) . '#rating');
    exit;
}

$comments = get_miniature_comments($id);

$public_rating = get_public_rating($id);
$page_title = $miniature['name'];
$body_class = 'md-page';

// Fotos primárias das miniaturas anterior/próxima (navegação visual) — sem alterar o banco.
$adj_photos = [];
$adj_ids = array_values(array_filter([$adjacent['prev']['id'] ?? null, $adjacent['next']['id'] ?? null]));
if ($adj_ids) {
    try {
        $in = implode(',', array_fill(0, count($adj_ids), '?'));
        $st = db()->prepare("SELECT miniature_id, file_path FROM miniature_photos
                             WHERE miniature_id IN ($in) AND is_primary = 1");
        $st->execute($adj_ids);
        foreach ($st->fetchAll() as $r) { $adj_photos[(int) $r['miniature_id']] = $r['file_path']; }
    } catch (Throwable $e) { /* opcional */ }
}

// Miniaturas relacionadas da mesma marca (reusa get_miniatures, exclui a atual).
$related = [];
if (!empty($miniature['manufacturer'])) {
    try {
        foreach (get_miniatures([
            'manufacturer' => $miniature['manufacturer'],
            'is_public'    => 1,
            'user_id'      => (int) $miniature['user_id'],
            'page'         => 1,
            'per_page'     => 9,
        ]) as $rm) {
            if ((int) $rm['id'] === $id) continue;
            $related[] = $rm;
            if (count($related) >= 4) break;
        }
    } catch (Throwable $e) { /* opcional */ }
}

// Find primary photo
$primary_photo = null;
foreach ($photos as $p) {
    if ($p['is_primary']) { $primary_photo = $p; break; }
}
if (!$primary_photo && !empty($photos)) {
    $primary_photo = $photos[0];
}

// OG meta tags
$og_title       = $miniature['name'];
$og_url         = rtrim(APP_URL, '/') . mini_url($miniature);
$og_description = $miniature['public_description']
    ? mb_strimwidth(strip_tags($miniature['public_description']), 0, 160, '…')
    : $miniature['manufacturer'] . ($miniature['scale'] ? ' · ' . $miniature['scale'] : '') . ($miniature['year'] ? ' · ' . $miniature['year'] : '');
$og_image = $primary_photo ? rtrim(APP_URL, '/') . photo_url($primary_photo['file_path']) : null;

require_once __DIR__ . '/includes/header_public.php';
?>

<nav class="md-breadcrumb" aria-label="breadcrumb">
    <a href="/">Coleção</a>
    <i class="fa fa-chevron-right"></i>
    <?php if ($miniature['manufacturer']): ?>
        <span class="md-bc-maker"><?= e($miniature['manufacturer']) ?></span>
        <i class="fa fa-chevron-right"></i>
    <?php endif; ?>
    <span class="md-bc-current"><?= e($miniature['name']) ?></span>
</nav>

<article class="md-hero">
    <!-- ── Galeria (foto protagonista) ────────────────── -->
    <div class="md-gallery">
        <?php if (!empty($photos)): ?>
            <a href="<?= e(photo_url($primary_photo['file_path'])) ?>" class="md-stage" id="mdStage"
               data-index="0" onclick="return mdStageClick(event)">
                <?php if (!empty($miniature['is_featured'])): ?>
                    <span class="md-stage-flag"><i class="fa fa-star"></i> Destaque</span>
                <?php endif; ?>
                <img src="<?= e(thumb_url($primary_photo['file_path'])) ?>"
                     data-fallback="<?= e(photo_url($primary_photo['file_path'])) ?>"
                     alt="<?= e($miniature['name']) ?>"
                     id="mdStageImg" class="md-stage-img">
                <span class="md-stage-zoom"><i class="fa fa-magnifying-glass-plus"></i></span>
                <?php if (count($photos) > 1): ?>
                    <span class="md-stage-count"><i class="fa fa-images"></i> <?= count($photos) ?></span>
                <?php endif; ?>
            </a>

            <?php if (count($photos) > 1): ?>
                <div class="md-thumbs" role="list">
                    <?php foreach ($photos as $i => $gp): ?>
                        <a href="<?= e(photo_url($gp['file_path'])) ?>"
                           class="md-thumb <?= $gp['id'] === ($primary_photo['id'] ?? 0) ? 'is-active' : '' ?>"
                           role="listitem"
                           data-index="<?= $i ?>"
                           data-thumb="<?= e(thumb_url($gp['file_path'])) ?>"
                           data-full="<?= e(photo_url($gp['file_path'])) ?>"
                           onclick="return mdThumbClick(event, this)">
                            <img src="<?= e(thumb_url($gp['file_path'])) ?>"
                                 data-fallback="<?= e(photo_url($gp['file_path'])) ?>" alt="" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="md-stage md-stage-empty">
                <i class="fa fa-car"></i>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Informações da peça ────────────────────────── -->
    <div class="md-info">

        <?php if ($miniature['manufacturer']): ?>
            <div class="md-maker"><?= e($miniature['manufacturer']) ?></div>
        <?php endif; ?>
        <h1 class="md-title"><?= e($miniature['name']) ?></h1>
        <?php if ($miniature['model']): ?>
            <div class="md-model"><?= e($miniature['model']) ?></div>
        <?php endif; ?>

        <?php if ($public_rating['count'] > 0): $avg = $public_rating['avg']; ?>
            <div class="md-hero-rating">
                <span class="md-hero-stars">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <i class="fa fa-star <?= $avg >= $s ? 'is-on' : ($avg >= $s - 0.5 ? 'is-half' : '') ?>"></i>
                    <?php endfor; ?>
                </span>
                <strong><?= number_format($avg, 1, ',', '') ?></strong>
                <span class="md-hero-rating-count"><?= $public_rating['count'] ?> avaliação<?= $public_rating['count'] !== 1 ? 'ões' : '' ?></span>
            </div>
        <?php endif; ?>

        <!-- Pílulas-chave -->
        <div class="md-keypills">
            <?php if ($miniature['scale']): ?>
                <span class="md-pill md-pill-amber"><i class="fa fa-ruler-combined"></i><?= e($miniature['scale']) ?></span>
            <?php endif; ?>
            <span class="md-pill md-cond-<?= e($miniature['condition']) ?>"><i class="fa fa-box"></i><?= h(condition_label($miniature['condition'])) ?></span>
            <span class="md-pill md-pill-soft"><i class="fa fa-<?= $miniature['location'] === 'display' ? 'eye' : 'box-archive' ?>"></i><?= h(location_label_short($miniature['location'])) ?></span>
            <?php if (!empty($miniature['is_featured'])): ?>
                <span class="md-pill md-pill-star"><i class="fa fa-star"></i>Destaque</span>
            <?php endif; ?>
        </div>

        <!-- Painéis de metadados -->
        <div class="md-specs">
            <?php if ($miniature['scale']): ?>
                <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-ruler-combined"></i></span><span class="md-spec-lbl">Escala</span><span class="md-spec-val"><?= e($miniature['scale']) ?></span></div>
            <?php endif; ?>
            <?php if ($miniature['year']): ?>
                <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-calendar"></i></span><span class="md-spec-lbl">Ano</span><span class="md-spec-val"><?= e((string) $miniature['year']) ?></span></div>
            <?php endif; ?>
            <?php if ($miniature['category_name']): ?>
                <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-tag"></i></span><span class="md-spec-lbl">Categoria</span><span class="md-spec-val"><?= e($miniature['category_name']) ?></span></div>
            <?php endif; ?>
            <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-box-open"></i></span><span class="md-spec-lbl">Condição</span><span class="md-spec-val"><?= h(condition_label($miniature['condition'])) ?></span></div>
            <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-location-dot"></i></span><span class="md-spec-lbl">Local</span><span class="md-spec-val"><?= h(location_label_short($miniature['location'])) ?></span></div>
            <?php if (!empty($photos)): ?>
                <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-images"></i></span><span class="md-spec-lbl">Fotos</span><span class="md-spec-val"><?= count($photos) ?></span></div>
            <?php endif; ?>
            <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-eye"></i></span><span class="md-spec-lbl">Vistas</span><span class="md-spec-val"><?= number_format((int) $miniature['views']) ?></span></div>
            <?php if (!empty($miniature['created_at'])): ?>
                <div class="md-spec"><span class="md-spec-ico"><i class="fa fa-clock"></i></span><span class="md-spec-lbl">Na coleção desde</span><span class="md-spec-val"><?= e(date('m/Y', strtotime($miniature['created_at']))) ?></span></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($tags)): ?>
            <div class="md-tags">
                <?php foreach ($tags as $tag): ?>
                    <a href="/?tag_id=<?= $tag['id'] ?>" class="md-tag">#<?= e($tag['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Ações -->
        <div class="md-actions">
            <a href="/" class="md-btn">
                <i class="fa fa-arrow-left"></i>Voltar à coleção
            </a>
            <button type="button" class="md-btn md-btn-primary" id="shareBtn"
                    data-url="<?= e($og_url) ?>" data-title="<?= e($miniature['name']) ?>">
                <i class="fa fa-share-nodes"></i>Compartilhar
            </button>
        </div>
    </div>
</article>

<?php if ($miniature['public_description']): ?>
<!-- ── História da peça ─────────────────────────────────── -->
<section class="md-section">
    <h2 class="md-section-title">A história desta peça</h2>
    <div class="md-story"><?= nl2br(e($miniature['public_description'])) ?></div>
</section>
<?php endif; ?>

<!-- ── Avaliação da comunidade ──────────────────────────── -->
<section class="md-section" id="rating">
    <h2 class="md-section-title">Avaliação da comunidade</h2>

    <?php
    $likes_count = (int) ($miniature['likes_count'] ?? 0);
    $user_liked  = !empty($miniature['user_liked']);
    ?>
    <div class="md-like-wrapper">
        <?php if (is_logged_in()): ?>
            <form method="post" action="<?= e(mini_url($miniature)) ?>#rating" class="md-like-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $user_liked ? 'unlike' : 'like' ?>">
                <button type="submit" class="md-like-btn <?= $user_liked ? 'md-like-active' : '' ?>"
                        aria-pressed="<?= $user_liked ? 'true' : 'false' ?>"
                        title="<?= $user_liked ? 'Remover curtida' : 'Curtir esta peça' ?>">
                    <i class="fa fa-heart"></i>
                    <span class="md-like-label"><?= $user_liked ? 'Curtido' : 'Curtir' ?></span>
                </button>
            </form>
        <?php else: ?>
            <a href="/admin/login" class="md-like-btn md-like-cta" title="Entre para curtir esta peça">
                <i class="fa fa-heart"></i>
                <span class="md-like-label">Curtir</span>
            </a>
        <?php endif; ?>
        <span class="md-like-count">
            <strong><?= number_format($likes_count) ?></strong>
            curtida<?= $likes_count !== 1 ? 's' : '' ?>
        </span>
    </div>

    <div class="md-rating">
        <?php if ($public_rating['count'] > 0): $avg = $public_rating['avg']; ?>
            <div class="md-rating-avg">
                <span class="md-rating-score"><?= number_format($avg, 1, ',', '') ?></span>
                <span class="md-rating-stars">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <i class="fa fa-star <?= $avg >= $s ? 'is-on' : ($avg >= $s - 0.5 ? 'is-half' : '') ?>"></i>
                    <?php endfor; ?>
                </span>
                <span class="md-rating-count"><?= $public_rating['count'] ?> avaliação<?= $public_rating['count'] !== 1 ? 'ões' : '' ?></span>
            </div>
        <?php else: ?>
            <div class="md-rating-empty">Ainda sem avaliações — seja o primeiro.</div>
        <?php endif; ?>
        <form method="post" action="<?= e(mini_url($miniature)) ?>#rating" class="md-rating-form">
            <span class="md-rating-label">Sua nota:</span>
            <div class="star-picker d-flex gap-1">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="submit" name="public_rating" value="<?= $s ?>"
                            class="btn p-0 border-0 bg-transparent text-warning star-rate-btn"
                            style="font-size:1.4rem;line-height:1;" title="<?= $s ?> estrela<?= $s > 1 ? 's' : '' ?>">
                        <i class="fa fa-star"></i>
                    </button>
                <?php endfor; ?>
            </div>
        </form>
    </div>
</section>

<!-- ── Comentários ──────────────────────────────────────── -->
<?php
$comments_total = 0;
foreach ($comments as $cRoot) { $comments_total += 1 + count($cRoot['replies']); }
?>
<section class="md-section" id="comments">
    <h2 class="md-section-title">Comentários<?= $comments_total ? ' <span class="cm-counter">' . $comments_total . '</span>' : '' ?></h2>

    <?php if (is_logged_in()): ?>
        <form method="post" action="<?= e(mini_url($miniature)) ?>#comments" class="cm-form">
            <?= csrf_field() ?>
            <input type="hidden" name="comment_action" value="create">
            <textarea name="comment_body" class="cm-textarea" rows="3" maxlength="1000" required
                      placeholder="Deixe um comentário sobre esta peça…"></textarea>
            <div class="cm-form-foot">
                <span class="cm-hint"><i class="fa fa-circle-info"></i> Máx. 1000 caracteres. Sem HTML.</span>
                <button type="submit" class="cm-submit"><i class="fa fa-paper-plane"></i> Comentar</button>
            </div>
        </form>
    <?php else: ?>
        <div class="cm-login-cta">
            <i class="fa fa-comments"></i>
            <span>Entre na sua conta para participar da conversa.</span>
            <a href="/admin/login" class="cm-login-btn"><i class="fa fa-right-to-bracket"></i> Entrar</a>
        </div>
    <?php endif; ?>

    <?php if (empty($comments)): ?>
        <div class="cm-empty">
            <i class="fa fa-comment-dots"></i>
            <p>Ainda não há comentários. Seja o primeiro a comentar.</p>
        </div>
    <?php else: ?>
        <ul class="cm-list">
            <?php foreach ($comments as $c):
                $author    = $c['display_name'] ?: $c['username'];
                $can_del   = can_delete_miniature_comment($c, $miniature);
                $can_pin   = can_pin_miniature_comment($c, $miniature);
                $pinned    = !empty($c['is_pinned']);
                $root_id   = (int) $c['id'];
            ?>
                <li class="cm-item <?= $pinned ? 'cm-item-pinned' : '' ?>" id="comment-<?= $root_id ?>">
                    <div class="cm-avatar"><i class="fa fa-user"></i></div>
                    <div class="cm-body-wrap">
                        <?php if ($pinned): ?>
                            <div class="cm-pin-seal"><i class="fa fa-thumbtack"></i> Destacado pelo colecionador</div>
                        <?php endif; ?>
                        <div class="cm-head">
                            <span class="cm-author"><?= e($author) ?></span>
                            <span class="cm-date"><?= e(date('d/m/Y H:i', strtotime($c['created_at']))) ?></span>
                            <?php if ($can_pin): ?>
                                <form method="post" action="<?= e(mini_url($miniature)) ?>#comment-<?= $root_id ?>" class="cm-pin-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comment_action" value="pin">
                                    <input type="hidden" name="comment_id" value="<?= $root_id ?>">
                                    <button type="submit" class="cm-pin <?= $pinned ? 'is-on' : '' ?>"
                                            title="<?= $pinned ? 'Remover destaque' : 'Destacar comentário' ?>">
                                        <i class="fa fa-thumbtack"></i> <?= $pinned ? 'Remover destaque' : 'Destacar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($can_del): ?>
                                <form method="post" action="<?= e(mini_url($miniature)) ?>#comments" class="cm-delete-form"
                                      onsubmit="return confirm('Excluir este comentário?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comment_action" value="delete">
                                    <input type="hidden" name="comment_id" value="<?= $root_id ?>">
                                    <button type="submit" class="cm-delete" title="Excluir comentário">
                                        <i class="fa fa-trash-can"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="cm-text"><?= render_comment_body_with_mentions($c['body']) ?></div>

                        <?php if (is_logged_in()): ?>
                            <details class="cm-reply">
                                <summary class="cm-reply-toggle"><i class="fa fa-reply"></i> Responder</summary>
                                <form method="post" action="<?= e(mini_url($miniature)) ?>#comment-<?= $root_id ?>" class="cm-form cm-reply-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comment_action" value="create">
                                    <input type="hidden" name="comment_parent_id" value="<?= $root_id ?>">
                                    <textarea name="comment_body" class="cm-textarea" rows="2" maxlength="1000" required
                                              placeholder="Escreva sua resposta…"><?= '@' . e($c['slug']) . ' ' ?></textarea>
                                    <div class="cm-form-foot">
                                        <span class="cm-hint"><i class="fa fa-reply"></i> Respondendo a <?= e($author) ?></span>
                                        <button type="submit" class="cm-submit"><i class="fa fa-paper-plane"></i> Responder</button>
                                    </div>
                                </form>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($c['replies'])): ?>
                            <ul class="cm-replies">
                                <?php foreach ($c['replies'] as $r):
                                    $rauthor  = $r['display_name'] ?: $r['username'];
                                    $rcan_del = can_delete_miniature_comment($r, $miniature);
                                ?>
                                    <li class="cm-item cm-item-reply" id="comment-<?= (int) $r['id'] ?>">
                                        <div class="cm-avatar"><i class="fa fa-user"></i></div>
                                        <div class="cm-body-wrap">
                                            <div class="cm-head">
                                                <span class="cm-author"><?= e($rauthor) ?></span>
                                                <span class="cm-date"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></span>
                                                <?php if ($rcan_del): ?>
                                                    <form method="post" action="<?= e(mini_url($miniature)) ?>#comments" class="cm-delete-form"
                                                          onsubmit="return confirm('Excluir esta resposta?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="comment_action" value="delete">
                                                        <input type="hidden" name="comment_id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" class="cm-delete" title="Excluir resposta">
                                                            <i class="fa fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <div class="cm-text"><?= render_comment_body_with_mentions($r['body']) ?></div>

                                            <?php if (is_logged_in()): ?>
                                                <details class="cm-reply">
                                                    <summary class="cm-reply-toggle"><i class="fa fa-reply"></i> Responder</summary>
                                                    <form method="post" action="<?= e(mini_url($miniature)) ?>#comment-<?= $root_id ?>" class="cm-form cm-reply-form">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="comment_action" value="create">
                                                        <input type="hidden" name="comment_parent_id" value="<?= $root_id ?>">
                                                        <textarea name="comment_body" class="cm-textarea" rows="2" maxlength="1000" required
                                                                  placeholder="Escreva sua resposta…"><?= '@' . e($r['slug']) . ' ' ?></textarea>
                                                        <div class="cm-form-foot">
                                                            <span class="cm-hint"><i class="fa fa-reply"></i> Respondendo a <?= e($rauthor) ?></span>
                                                            <button type="submit" class="cm-submit"><i class="fa fa-paper-plane"></i> Responder</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if (!empty($related)): ?>
<!-- ── Mais da mesma marca ──────────────────────────────── -->
<section class="md-section">
    <h2 class="md-section-title">Mais de <?= e($miniature['manufacturer']) ?></h2>
    <div class="row row-cols-2 row-cols-md-4 g-3">
        <?php foreach ($related as $rm): $rc = $rm['condition'] ?? 'sealed'; ?>
            <div class="col">
                <a href="<?= e(mini_url($rm)) ?>" class="cp-card">
                    <div class="cp-card-photo">
                        <?php if (!empty($rm['primary_photo'])): ?>
                            <img src="<?= e(thumb_url($rm['primary_photo'])) ?>"
                                 data-fallback="<?= e(photo_url($rm['primary_photo'])) ?>"
                                 alt="<?= e($rm['name']) ?>" class="cp-card-img" loading="lazy">
                        <?php endif; ?>
                        <?php if (!empty($rm['is_featured'])): ?>
                            <span class="cp-card-star"><i class="fa fa-star"></i></span>
                        <?php endif; ?>
                    </div>
                    <div class="cp-card-info">
                        <?php if ($rm['manufacturer']): ?>
                            <div class="cp-card-maker"><?= e($rm['manufacturer']) ?></div>
                        <?php endif; ?>
                        <div class="cp-card-name"><?= e($rm['name']) ?></div>
                        <div class="cp-card-pills">
                            <?php if ($rm['scale']): ?>
                                <span class="cp-pill cp-pill-soft"><?= e($rm['scale']) ?></span>
                            <?php endif; ?>
                            <span class="cp-pill cp-cond-<?= e($rc) ?>"><?= h(condition_label($rc)) ?></span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Prev / Next ──────────────────────────────────────── -->
<?php if ($adjacent['prev'] || $adjacent['next']): ?>
<nav class="md-nav" aria-label="Navegação entre miniaturas">
    <div>
        <?php if ($adjacent['prev']): $pp = $adj_photos[(int) $adjacent['prev']['id']] ?? null; ?>
            <a href="<?= e(mini_url($adjacent['prev'])) ?>" class="md-nav-card md-nav-prev">
                <div class="md-nav-thumb">
                    <?php if ($pp): ?>
                        <img src="<?= e(thumb_url($pp)) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i class="fa fa-car"></i>
                    <?php endif; ?>
                </div>
                <div class="md-nav-body">
                    <div class="md-nav-dir"><i class="fa fa-chevron-left"></i> Anterior</div>
                    <div class="md-nav-name"><?= e($adjacent['prev']['name']) ?></div>
                    <div class="md-nav-maker"><?= e($adjacent['prev']['manufacturer']) ?></div>
                </div>
            </a>
        <?php endif; ?>
    </div>
    <div>
        <?php if ($adjacent['next']): $np = $adj_photos[(int) $adjacent['next']['id']] ?? null; ?>
            <a href="<?= e(mini_url($adjacent['next'])) ?>" class="md-nav-card md-nav-next">
                <div class="md-nav-thumb">
                    <?php if ($np): ?>
                        <img src="<?= e(thumb_url($np)) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i class="fa fa-car"></i>
                    <?php endif; ?>
                </div>
                <div class="md-nav-body">
                    <div class="md-nav-dir">Próxima <i class="fa fa-chevron-right"></i></div>
                    <div class="md-nav-name"><?= e($adjacent['next']['name']) ?></div>
                    <div class="md-nav-maker"><?= e($adjacent['next']['manufacturer']) ?></div>
                </div>
            </a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<!-- Lightbox Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:min(92vw,960px)">
        <div class="modal-content bg-dark border-0">
            <div class="modal-body p-0 position-relative text-center" style="background:#000;">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2 z-3"
                        data-bs-dismiss="modal" aria-label="Fechar"></button>
                <button id="lbPrev" class="btn btn-dark opacity-75 position-absolute start-0 top-50 translate-middle-y z-3 ms-1 rounded-circle"
                        style="width:40px;height:40px;" onclick="lbGo(-1)">
                    <i class="fa fa-chevron-left"></i>
                </button>
                <img id="lbImg" src="" alt="" class="img-fluid d-block mx-auto"
                     style="max-height:88vh; object-fit:contain;">
                <button id="lbNext" class="btn btn-dark opacity-75 position-absolute end-0 top-50 translate-middle-y z-3 me-1 rounded-circle"
                        style="width:40px;height:40px;" onclick="lbGo(1)">
                    <i class="fa fa-chevron-right"></i>
                </button>
                <div id="lbCounter" class="position-absolute bottom-0 start-50 translate-middle-x mb-2 text-white small
                     bg-dark bg-opacity-60 px-2 py-1 rounded"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
<?php if (!empty($photos)): ?>
<script>
const lbPhotos = <?= json_encode(array_values(array_map(fn($p) => [
    'full' => photo_url($p['file_path']),
    'alt'  => $miniature['name'],
], $photos))) ?>;
let lbCurrent = 0;
const lbModal = new bootstrap.Modal(document.getElementById('photoModal'));
const lbImg   = document.getElementById('lbImg');
const lbPrev  = document.getElementById('lbPrev');
const lbNext  = document.getElementById('lbNext');
const lbCtr   = document.getElementById('lbCounter');

function openLightbox(index) {
    lbCurrent = index;
    _lbRender();
    lbModal.show();
}
function lbGo(dir) {
    lbCurrent = Math.max(0, Math.min(lbPhotos.length - 1, lbCurrent + dir));
    _lbRender();
}
function _lbRender() {
    const p = lbPhotos[lbCurrent];
    lbImg.src = p.full;
    lbImg.alt = p.alt;
    lbPrev.style.display = lbCurrent > 0 ? '' : 'none';
    lbNext.style.display = lbCurrent < lbPhotos.length - 1 ? '' : 'none';
    lbCtr.textContent = lbPhotos.length > 1 ? `${lbCurrent + 1} / ${lbPhotos.length}` : '';
}
document.addEventListener('keydown', ev => {
    if (!document.getElementById('photoModal').classList.contains('show')) return;
    if (ev.key === 'ArrowLeft')  lbGo(-1);
    if (ev.key === 'ArrowRight') lbGo(1);
    if (ev.key === 'Escape') lbModal.hide();
});
// Touch swipe support
let _tsX = null;
document.getElementById('lbImg').addEventListener('touchstart', e => { _tsX = e.touches[0].clientX; }, { passive: true });
document.getElementById('lbImg').addEventListener('touchend',   e => {
    if (_tsX === null) return;
    const dx = e.changedTouches[0].clientX - _tsX;
    if (Math.abs(dx) > 40) lbGo(dx < 0 ? 1 : -1);
    _tsX = null;
}, { passive: true });

// ── Galeria: troca da foto principal (acessível sem JS via href) ──
(function () {
    const stage = document.getElementById('mdStage');
    const stageImg = document.getElementById('mdStageImg');
    if (!stage || !stageImg) return;
    const thumbs = document.querySelectorAll('.md-thumb');
    window.mdThumbClick = function (ev, el) {
        ev.preventDefault();
        stageImg.src = el.dataset.thumb;
        stageImg.setAttribute('data-fallback', el.dataset.full);
        stage.setAttribute('data-index', el.dataset.index);
        stage.setAttribute('href', el.dataset.full);
        thumbs.forEach(function (t) { t.classList.remove('is-active'); });
        el.classList.add('is-active');
        return false;
    };
    window.mdStageClick = function (ev) {
        if (typeof openLightbox === 'function') {
            ev.preventDefault();
            openLightbox(parseInt(stage.getAttribute('data-index') || '0', 10));
            return false;
        }
        return true;
    };
})();
</script>
<?php endif; ?>
<script>
// Share button
const shareBtn = document.getElementById('shareBtn');
if (shareBtn) {
    shareBtn.addEventListener('click', async () => {
        const url   = shareBtn.dataset.url;
        const title = shareBtn.dataset.title;
        if (navigator.share) {
            await navigator.share({ title, url }).catch(() => {});
        } else {
            await navigator.clipboard.writeText(url).catch(() => {});
            shareBtn.innerHTML = '<i class="fa fa-check me-1"></i>Link copiado!';
            setTimeout(() => { shareBtn.innerHTML = '<i class="fa fa-share-nodes me-1"></i>Compartilhar'; }, 2000);
        }
    });
}
</script>
