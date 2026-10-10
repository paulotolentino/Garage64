<?php
// ── Banner de "momento mágico" (primeira miniatura cadastrada) ───────────────
// Incluído logo após o header nas páginas públicas (miniatura / garagem).
// Lê e limpa $_SESSION['g64_celebrate'] uma única vez, para o dono ver o
// resultado com um CTA de compartilhar sua garagem recém-criada.
if (function_exists('session_start_once')) session_start_once();

if (!empty($_SESSION['g64_celebrate']) && is_logged_in()) {
    $g64_celebrate      = $_SESSION['g64_celebrate'];
    unset($_SESSION['g64_celebrate']); // mostra só uma vez

    // Só comemora para o próprio dono (evita mostrar a visitantes).
    if (($g64_celebrate['slug'] ?? '') === current_user_slug()) {
        $celebrate_slug  = (string) $g64_celebrate['slug'];
        $celebrate_name  = (string) ($g64_celebrate['name'] ?? '');
        $celebrate_url   = rtrim(APP_URL, '/') . '/u/' . rawurlencode($celebrate_slug);
        ?>
        <div class="celebrate-banner" role="status">
            <span class="celebrate-banner__spark"><i class="fa fa-champagne-glasses"></i></span>
            <div class="celebrate-banner__body">
                <strong class="celebrate-banner__title">Sua garagem está no ar! 🎉</strong>
                <span class="celebrate-banner__text">
                    Sua primeira peça foi estacionada. Compartilhe sua garagem e chame outros colecionadores.
                </span>
            </div>
            <button type="button" class="md-btn md-btn-primary celebrate-banner__cta js-share"
                    data-share-url="<?= e($celebrate_url) ?>"
                    data-share-title="Minha garagem no <?= h(APP_NAME) ?>"
                    data-share-text="Acabei de criar minha garagem de miniaturas no <?= h(APP_NAME) ?>. Dá uma olhada!">
                <span class="js-share-label"><i class="fa fa-share-nodes"></i> Compartilhar minha garagem</span>
            </button>
        </div>
        <?php
    }
}
