<?php
// ── Card "Convidar amigos" (painel do colecionador) ─────────────────────────
// Gera um link de indicação (/register?ref={slug}); quem se cadastrar por ele
// passa a seguir o convidante automaticamente. Requer usuário logado.
// Espera opcionalmente $g64_slug já definido; senão resolve pelo usuário atual.
if (!function_exists('current_user_slug')) return;

$invite_slug = isset($g64_slug) && $g64_slug !== '' ? $g64_slug : current_user_slug();
if ($invite_slug === '') return;

$invite_link = rtrim(APP_URL, '/') . '/register?ref=' . rawurlencode($invite_slug);
$invite_msg  = 'Montei minha garagem de miniaturas no ' . APP_NAME . '. Crie a sua e vamos trocar figurinhas: ' . $invite_link;
$invite_wa   = 'https://wa.me/?text=' . rawurlencode($invite_msg);
?>
<section class="invite-card">
    <div class="invite-card-head">
        <span class="invite-card-ico"><i class="fa fa-user-plus"></i></span>
        <div class="invite-card-head-text">
            <h2 class="invite-card-title">Convide colecionadores</h2>
            <p class="invite-card-sub">Compartilhe seu link. Quem entrar por ele já começa te seguindo.</p>
        </div>
    </div>
    <div class="invite-card-linkrow">
        <input type="text" class="invite-card-input amf-input" id="inviteLink"
               value="<?= e($invite_link) ?>" readonly aria-label="Seu link de convite">
        <button type="button" class="md-btn md-btn-primary invite-card-copy js-share"
                data-share-url="<?= e($invite_link) ?>"
                data-share-title="Crie sua garagem no <?= h(APP_NAME) ?>"
                data-share-text="<?= e($invite_msg) ?>">
            <span class="js-share-label"><i class="fa fa-share-nodes"></i> Compartilhar</span>
        </button>
        <a href="<?= e($invite_wa) ?>" target="_blank" rel="noopener"
           class="md-btn invite-card-wa" title="Convidar pelo WhatsApp">
            <i class="fa-brands fa-whatsapp"></i><span>WhatsApp</span>
        </a>
    </div>
</section>
