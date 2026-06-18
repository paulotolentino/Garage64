<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);
$page_title = 'Página não encontrada';

require_once __DIR__ . '/includes/header_public.php';
?>

<div class="text-center py-5 my-5">
    <div style="font-size:6rem;line-height:1;opacity:.15;" class="mb-3">404</div>
    <i class="fa fa-car fa-4x text-warning mb-4 d-block" style="opacity:.6;"></i>
    <h1 class="h3 text-light mb-2">Página não encontrada</h1>
    <p class="text-secondary mb-4">A miniatura que você procura não está aqui — talvez tenha escapado do expositor.</p>
    <a href="/" class="btn btn-warning">
        <i class="fa fa-arrow-left me-2"></i>Voltar à coleção
    </a>
</div>

<?php require_once __DIR__ . '/includes/footer_public.php'; ?>
