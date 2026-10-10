/* Garage64 — app.js */

// Sinaliza que o JS está ativo o quanto antes (progressive enhancement).
// Sem esta classe, .lp-animate permanece visível por padrão.
document.documentElement.classList.add('js-enabled');

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

    // ── Sticky navbar: add frosted-glass class after scrolling ────────────────
    (function () {
        var nav = document.querySelector('.navbar');
        if (!nav) return;
        function onScroll() {
            nav.classList.toggle('navbar-scrolled', window.scrollY > 50);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }());

    // ── Scroll reveal: trigger lp-animate → lp-visible via IntersectionObserver
    (function () {
        var items = document.querySelectorAll('.lp-animate');
        if (!items.length) return;
        if (!('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('lp-visible'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lp-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        items.forEach(function (el) { io.observe(el); });

        // Falha-segura: garante que itens já visíveis na viewport ao carregar
        // sejam revelados mesmo que o observer não dispare.
        requestAnimationFrame(function () {
            items.forEach(function (el) {
                var r = el.getBoundingClientRect();
                if (r.top < (window.innerHeight || document.documentElement.clientHeight) && r.bottom > 0) {
                    el.classList.add('lp-visible');
                }
            });
        });
    }());

    // ── Animated counters for stat numbers ────────────────────────────────────
    (function () {
        var counters = document.querySelectorAll('.lp-stat-number[data-count]');
        if (!counters.length || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                var target = parseInt(el.dataset.count, 10);
                if (!target) return;
                io.unobserve(el);
                var current = 0;
                var steps = 40;
                var inc = target / steps;
                var timer = setInterval(function () {
                    current = Math.min(current + inc, target);
                    el.textContent = Math.floor(current).toLocaleString('pt-BR');
                    if (current >= target) clearInterval(timer);
                }, 1000 / steps);
            });
        }, { threshold: 0.5 });
        counters.forEach(function (el) { io.observe(el); });
    }());

    // ── Avatar lightbox: clicar na foto de perfil para vê-la ampliada ─────────
    (function () {
        var avatars = document.querySelectorAll('.avatar-zoomable');
        if (!avatars.length) return;

        var overlay = null;
        var lastFocused = null;

        function close() {
            if (!overlay) return;
            overlay.classList.remove('is-open');
            document.body.classList.remove('avatar-lightbox-open');
            document.removeEventListener('keydown', onKeydown);
            var el = overlay;
            overlay = null;
            setTimeout(function () { el.remove(); }, 200);
            if (lastFocused) lastFocused.focus();
        }

        function onKeydown(e) {
            if (e.key === 'Escape') close();
        }

        function open(src, alt) {
            if (!src) return;
            lastFocused = document.activeElement;

            overlay = document.createElement('div');
            overlay.className = 'avatar-lightbox';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', alt || 'Foto de perfil');

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'avatar-lightbox-close';
            closeBtn.setAttribute('aria-label', 'Fechar');
            closeBtn.innerHTML = '<i class="fa fa-xmark"></i>';

            var img = document.createElement('img');
            img.className = 'avatar-lightbox-img';
            img.src = src;
            img.alt = alt || 'Foto de perfil';

            overlay.appendChild(closeBtn);
            overlay.appendChild(img);
            document.body.appendChild(overlay);
            document.body.classList.add('avatar-lightbox-open');

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay || e.target === closeBtn || e.target.closest('.avatar-lightbox-close')) {
                    close();
                }
            });
            document.addEventListener('keydown', onKeydown);

            requestAnimationFrame(function () { overlay.classList.add('is-open'); });
            closeBtn.focus();
        }

        avatars.forEach(function (el) {
            var img = el.tagName === 'IMG' ? el : el.querySelector('img');
            if (!img) return;
            el.classList.add('avatar-zoomable-active');
            el.style.cursor = 'zoom-in';
            if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'button');
            el.setAttribute('aria-label', 'Ver foto de perfil ampliada');

            el.addEventListener('click', function () {
                open(img.currentSrc || img.src, img.alt);
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(img.currentSrc || img.src, img.alt);
                }
            });
        });
    }());

    // ── Compartilhamento fácil (Web Share API + fallback de cópia) ────────────
    // Qualquer elemento com [data-share-url] vira um botão de compartilhar.
    // Opcional: [data-share-title] e [data-share-text].
    (function () {
        function feedback(el) {
            if (!el) return;
            var label = el.querySelector('.js-share-label');
            var target = label || el;
            var prev = target.innerHTML;
            target.innerHTML = '<i class="fa fa-check"></i> Link copiado!';
            el.classList.add('is-copied');
            setTimeout(function () {
                target.innerHTML = prev;
                el.classList.remove('is-copied');
            }, 2000);
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-share-url]');
            if (!btn) return;
            e.preventDefault();

            var url = btn.dataset.shareUrl || window.location.href;
            var title = btn.dataset.shareTitle || document.title;
            var text = btn.dataset.shareText || '';

            if (navigator.share) {
                navigator.share({ title: title, text: text, url: url }).catch(function () { });
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url)
                    .then(function () { feedback(btn); })
                    .catch(function () { window.prompt('Copie o link:', url); });
            } else {
                window.prompt('Copie o link:', url);
            }
        });
    }());

});
