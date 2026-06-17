</main><!-- /admin-main -->
<footer class="admin-footer">
    <?= h(APP_NAME) ?> Admin &mdash; <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
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
</script>
</body>
</html>
