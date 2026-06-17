/* Garage64 — app.js */

document.addEventListener('DOMContentLoaded', function () {

    // ── Broken image fallback (replaces is_file() server-side check) ─────────
    document.addEventListener('error', function (e) {
        if (e.target.tagName === 'IMG' && !e.target.dataset.fallbackApplied) {
            e.target.dataset.fallbackApplied = '1';
            // Thumbnail imgs carry data-fallback pointing to the full image.
            // Other broken imgs fall back to the placeholder.
            e.target.src = e.target.dataset.fallback || '/assets/img/no-photo.svg';
        }
    }, true);

    // ── Auto-dismiss flash alerts after 5 s ──────────
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            bsAlert.close();
        }, 5000);
    });

    // ── Image preview on file input ───────────────────
    var photoInput = document.querySelector('input[name="photos[]"]');
    if (photoInput) {
        photoInput.addEventListener('change', function () {
            var existing = document.getElementById('photo-preview-container');
            if (existing) existing.remove();

            var files = Array.from(this.files);
            if (!files.length) return;

            var container = document.createElement('div');
            container.id = 'photo-preview-container';
            container.className = 'd-flex flex-wrap gap-2 mt-2';

            files.forEach(function (file) {
                if (!file.type.startsWith('image/')) return;
                var reader = new FileReader();
                reader.onload = function (e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'rounded';
                    img.style.cssText = 'width:70px;height:70px;object-fit:cover;border:2px dashed #f0a500;';
                    container.appendChild(img);
                };
                reader.readAsDataURL(file);
            });

            photoInput.closest('.card-body').appendChild(container);
        });
    }

    // ── Confirm delete via data-confirm ───────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

});
