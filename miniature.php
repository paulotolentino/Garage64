<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect('/');
}

$miniature = get_miniature($id);
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
$adjacent = get_adjacent_miniatures($id);
$page_title = $miniature['name'];

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

<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/" class="text-warning">Coleção</a></li>
        <li class="breadcrumb-item active"><?= e($miniature['name']) ?></li>
    </ol>
</nav>

<div class="row g-4 g-lg-5">
    <!-- ── Photo column ─────────────────────────────────── -->
    <div class="col-12 col-md-5 col-lg-5">
        <?php if (!empty($photos)): ?>
            <div class="mini-detail-photo rounded overflow-hidden position-relative"
                 onclick="openLightbox(0)" style="cursor:zoom-in;">
                <img src="<?= e(thumb_url($primary_photo['file_path'])) ?>"
                     data-fallback="<?= e(photo_url($primary_photo['file_path'])) ?>"
                     alt="<?= e($miniature['name']) ?>"
                     class="w-100 d-block"
                     style="aspect-ratio:4/3; object-fit:cover;">
                <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75">
                    <i class="fa fa-magnifying-glass-plus"></i>
                </span>
                <?php if (count($photos) > 1): ?>
                    <span class="position-absolute bottom-0 end-0 m-2 badge bg-dark bg-opacity-75">
                        <i class="fa fa-images me-1"></i><?= count($photos) ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (count($photos) > 1): ?>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php foreach ($photos as $i => $gp): ?>
                        <img src="<?= e(thumb_url($gp['file_path'])) ?>"
                             data-fallback="<?= e(photo_url($gp['file_path'])) ?>"
                             alt=""
                             class="rounded <?= $gp['id'] === ($primary_photo['id'] ?? 0) ? 'border border-warning border-2' : 'border border-secondary' ?>"
                             style="width:68px;height:68px;object-fit:cover;cursor:pointer;transition:opacity .15s;"
                             onmouseover="this.style.opacity='.75'" onmouseout="this.style.opacity='1'"
                             onclick="openLightbox(<?= $i ?>)">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="rounded overflow-hidden bg-dark d-flex align-items-center justify-content-center"
                 style="aspect-ratio:4/3;">
                <i class="fa fa-car fa-4x text-secondary opacity-25"></i>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Info column ──────────────────────────────────── -->
    <div class="col-12 col-md-7 col-lg-7 d-flex flex-column">

        <!-- Manufacturer + status row -->
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <span class="text-warning fw-bold text-uppercase" style="letter-spacing:.06em; font-size:.8rem;">
                <?= e($miniature['manufacturer']) ?>
            </span>
            <?= status_badge($miniature['status']) ?>
        </div>

        <!-- Title -->
        <h1 class="h2 fw-bold text-light mb-1 lh-sm"><?= e($miniature['name']) ?></h1>

        <!-- Model / subtitle -->
        <?php if ($miniature['model']): ?>
            <div class="text-secondary mb-3" style="font-size:.95rem;"><?= e($miniature['model']) ?></div>
        <?php else: ?>
            <div class="mb-3"></div>
        <?php endif; ?>

        <!-- Meta pills -->
        <?php $has_meta = $miniature['scale'] || $miniature['year'] || $miniature['category_name']; ?>
        <?php if ($has_meta): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php if ($miniature['scale']): ?>
                    <span class="badge rounded-pill bg-dark border border-secondary text-light px-3 py-2">
                        <i class="fa fa-ruler-combined me-1 text-warning" style="font-size:.7rem;"></i><?= e($miniature['scale']) ?>
                    </span>
                <?php endif; ?>
                <?php if ($miniature['year']): ?>
                    <span class="badge rounded-pill bg-dark border border-secondary text-light px-3 py-2">
                        <i class="fa fa-calendar me-1 text-warning" style="font-size:.7rem;"></i><?= e((string)$miniature['year']) ?>
                    </span>
                <?php endif; ?>
                <?php if ($miniature['category_name']): ?>
                    <span class="badge rounded-pill bg-dark border border-secondary text-light px-3 py-2">
                        <i class="fa fa-tag me-1 text-warning" style="font-size:.7rem;"></i><?= e($miniature['category_name']) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Tags -->
        <?php if (!empty($tags)): ?>
            <div class="d-flex flex-wrap gap-1 mb-3">
                <?php foreach ($tags as $tag): ?>
                    <a href="/?tag_id=<?= $tag['id'] ?>"
                       class="badge bg-secondary text-decoration-none"
                       style="font-weight:400;"><?= e($tag['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Stars -->
        <?php if ($miniature['emotional_rating']): ?>
            <div class="d-flex align-items-center gap-1 mb-3">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fa fa-star fa-sm <?= $i <= (int)$miniature['emotional_rating'] ? 'text-warning' : 'text-secondary opacity-25' ?>"></i>
                <?php endfor; ?>
                <span class="text-secondary ms-1" style="font-size:.75rem;">avaliação pessoal</span>
            </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if ($miniature['public_description']): ?>
            <div class="text-light mb-4" style="line-height:1.7; font-size:.95rem;">
                <?= nl2br(e($miniature['public_description'])) ?>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="mt-auto pt-2 d-flex gap-2 flex-wrap">
            <a href="/" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-arrow-left me-1"></i>Voltar à coleção
            </a>
            <button class="btn btn-outline-secondary btn-sm" id="shareBtn"
                    data-url="<?= e($og_url) ?>" data-title="<?= e($miniature['name']) ?>">
                <i class="fa fa-share-nodes me-1"></i>Compartilhar
            </button>
        </div>
    </div>
</div>

<!-- ── Prev / Next ──────────────────────────────────────── -->
<?php if ($adjacent['prev'] || $adjacent['next']): ?>
<div class="row mt-5 pt-3 border-top border-secondary">
    <div class="col-6">
        <?php if ($adjacent['prev']): ?>
            <a href="<?= e(mini_url($adjacent['prev'])) ?>"
               class="text-decoration-none d-flex align-items-center gap-2 mini-adj-link">
                <i class="fa fa-chevron-left text-warning"></i>
                <div>
                    <div class="text-secondary" style="font-size:.65rem; letter-spacing:.08em;">ANTERIOR</div>
                    <div class="text-light small fw-semibold lh-sm"><?= e($adjacent['prev']['name']) ?></div>
                    <div class="text-warning" style="font-size:.75rem;"><?= e($adjacent['prev']['manufacturer']) ?></div>
                </div>
            </a>
        <?php endif; ?>
    </div>
    <div class="col-6 text-end">
        <?php if ($adjacent['next']): ?>
            <a href="<?= e(mini_url($adjacent['next'])) ?>"
               class="text-decoration-none d-flex align-items-center justify-content-end gap-2 mini-adj-link">
                <div>
                    <div class="text-secondary" style="font-size:.65rem; letter-spacing:.08em;">PRÓXIMA</div>
                    <div class="text-light small fw-semibold lh-sm"><?= e($adjacent['next']['name']) ?></div>
                    <div class="text-warning" style="font-size:.75rem;"><?= e($adjacent['next']['manufacturer']) ?></div>
                </div>
                <i class="fa fa-chevron-right text-warning"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
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
