</main><!-- /admin-main -->
<footer class="admin-footer">
    <?= h(APP_NAME) ?> Admin &mdash; <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= h(APP_URL) ?>/assets/js/app.js"></script>
<script>
// ── Sidebar toggle (mobile) ───────────────────────────────────────────────────
(function () {
    var btn     = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!btn) return;

    function open()  { sidebar.classList.add('open');  overlay.classList.add('visible'); }
    function close() { sidebar.classList.remove('open'); overlay.classList.remove('visible'); }

    btn.addEventListener('click', function () {
        sidebar.classList.contains('open') ? close() : open();
    });
    overlay.addEventListener('click', close);
})();

// ── Photo drag-and-drop reorder ───────────────────────────────────────────────
(function () {
    var el = document.getElementById('sortable-photos');
    if (!el) return;

    var miniatureId = el.dataset.miniatureId;
    var csrfToken   = document.querySelector('input[name="csrf_token"]');

    Sortable.create(el, {
        animation: 150,
        ghostClass: 'opacity-25',
        onEnd: function () {
            var ids = Array.from(el.querySelectorAll('[data-photo-id]'))
                          .map(function (n) { return n.dataset.photoId; });

            var body = new URLSearchParams({
                miniature_id: miniatureId,
                csrf_token:   csrfToken ? csrfToken.value : '',
            });
            ids.forEach(function (id) { body.append('photo_ids[]', id); });

            fetch('/admin/miniatures.php?action=reorder_photos', {
                method: 'POST',
                body:   body,
            });
        },
    });
})();
// ── Photo rotation ────────────────────────────────────────────────────────────
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.rotate-btn');
        if (!btn) return;
        e.preventDefault();
        btn.disabled = true;
        var icon = btn.querySelector('i');
        if (icon) icon.classList.add('fa-spin');

        var csrfToken = document.querySelector('input[name="csrf_token"]');
        var body = new URLSearchParams({
            photo_id:     btn.dataset.photoId,
            miniature_id: btn.dataset.miniatureId,
            degrees:      90,
            csrf_token:   csrfToken ? csrfToken.value : '',
        });

        fetch('/admin/miniatures?action=rotate_photo', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    var wrap = btn.closest('[data-photo-id]');
                    var img  = wrap ? wrap.querySelector('.photo-admin-img') : null;
                    if (img) {
                        // Get the real path (strip any previous ?t= or blob:)
                        var realSrc = img.dataset.realSrc || img.src.split('?')[0];
                        img.dataset.realSrc = realSrc;
                        // Fetch as blob with cache bypass — works regardless of server cache headers
                        fetch(realSrc + '?t=' + data.bust, { cache: 'no-store' })
                            .then(function (r) { return r.blob(); })
                            .then(function (blob) {
                                var prev = img.src;
                                img.src = URL.createObjectURL(blob);
                                if (prev.startsWith('blob:')) URL.revokeObjectURL(prev);
                            });
                    }
                } else {
                    alert('Erro ao girar: ' + (data.error || 'desconhecido'));
                }
            })
            .catch(function () { alert('Erro de rede ao girar a foto.'); })
            .finally(function () {
                btn.disabled = false;
                if (icon) icon.classList.remove('fa-spin');
            });
    });
})();
</script>
</body>
</html>
