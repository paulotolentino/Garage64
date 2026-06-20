</main>
<button id="scrollTop" aria-label="Voltar ao topo" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fa fa-chevron-up"></i>
</button>
<footer class="footer bg-dark text-secondary py-3 mt-5">
    <div class="container text-center small">
        &copy; <?= date('Y') ?> <?= h(APP_NAME) ?> — Coleção particular de miniaturas diecast
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(APP_URL) ?>/assets/js/app.js"></script>
<script>
// Scroll-to-top visibility
(function () {
    const btn = document.getElementById('scrollTop');
    if (!btn) return;
    window.addEventListener('scroll', () => {
        btn.classList.toggle('visible', window.scrollY > 300);
    }, { passive: true });
})();
// Skeleton → remove loading class when image loads/errors
document.querySelectorAll('.mini-thumb').forEach(img => {
    img.classList.add('loading');
    const done = () => img.classList.remove('loading');
    if (img.complete) done(); else { img.addEventListener('load', done); img.addEventListener('error', done); }
});
</script>
</body>
</html>
