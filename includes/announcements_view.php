<?php
$editable=!$campaign || $campaign['status']==='draft';
$status_names=['draft'=>'Rascunho','queued'=>'Em envio','completed'=>'Concluído','cancelled'=>'Interrompido'];
require __DIR__.'/header_admin.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<div class="campaign-heading"><div><p class="campaign-eyebrow">Comunicação com a comunidade</p><h1>Comunicados</h1></div><a href="/admin/announcements" class="md-btn"><i class="fa fa-plus" aria-hidden="true"></i>Novo comunicado</a></div>
<div class="campaign-studio">
    <aside class="campaign-history" aria-label="Comunicados anteriores"><h2>Seus comunicados</h2><button type="button" class="campaign-history-toggle" id="campaign-history-toggle" aria-expanded="false" aria-controls="campaign-history-list"><span>Comunicados anteriores (<?= count($campaigns) ?>)</span><i class="fa fa-chevron-down" aria-hidden="true"></i></button><div class="campaign-history-list" id="campaign-history-list">
        <?php if(!$campaigns): ?><p class="campaign-muted">Nenhum comunicado ainda.</p><?php endif; ?>
        <?php foreach($campaigns as $item): ?><a class="campaign-history-item <?= (int)$item['id']===$id?'is-active':'' ?>" data-campaign-history-id="<?= (int)$item['id'] ?>" href="/admin/announcements?id=<?= (int)$item['id'] ?>"><strong><?= e($item['subject']) ?></strong><span><?= e($status_names[$item['status']]) ?> · <?= e(date('d/m/Y',strtotime($item['created_at']))) ?></span></a><?php endforeach; ?>
    </div></aside>
    <section class="campaign-workspace" aria-label="Editor do comunicado">
        <div class="campaign-topline"><span class="campaign-status" id="campaign-status"><?= e($status_names[$campaign['status'] ?? 'draft']) ?></span><span class="campaign-muted"><strong id="campaign-eligible"><?= $eligible ?></strong> destinatários elegíveis</span></div>
        <form id="campaign-form">
            <?= csrf_field() ?><input type="hidden" name="id" id="campaign-id" value="<?= $id ?>">
            <label for="campaign-subject" class="campaign-label">Assunto do e-mail</label>
            <input id="campaign-subject" name="subject" class="campaign-subject" maxlength="180" value="<?= e($campaign['subject'] ?? '') ?>" placeholder="O que há de novo no Garage64?" required <?= !$editable?'readonly':'' ?>>
            <div class="campaign-paper"><div id="campaign-toolbar">
                <span class="ql-formats"><select class="ql-header" aria-label="Estilo do texto"><option selected>Texto</option><option value="1">Título</option><option value="2">Subtítulo</option></select></span>
                <span class="ql-formats"><button type="button" class="ql-bold" title="Negrito" aria-label="Negrito"></button><button type="button" class="ql-italic" title="Itálico" aria-label="Itálico"></button><button type="button" class="ql-underline" title="Sublinhado" aria-label="Sublinhado"></button></span>
                <span class="ql-formats"><button type="button" class="ql-list" value="ordered" title="Lista numerada" aria-label="Lista numerada"></button><button type="button" class="ql-list" value="bullet" title="Lista com marcadores" aria-label="Lista com marcadores"></button><select class="ql-align" aria-label="Alinhamento"></select></span>
                <span class="ql-formats"><button type="button" class="ql-link" title="Inserir link" aria-label="Inserir link"></button><button type="button" class="ql-image" title="Enviar imagem" aria-label="Enviar imagem"></button><button type="button" class="ql-clean" title="Limpar formatação" aria-label="Limpar formatação"></button></span>
            </div><div id="campaign-editor"></div></div>
            <input type="file" id="campaign-image" accept="image/jpeg,image/png,image/webp" hidden>
            <div class="campaign-actions">
                <div class="campaign-actions-secondary"><button type="button" class="md-btn" data-campaign-action="preview"><i class="fa fa-eye" aria-hidden="true"></i>Prévia</button><button type="button" class="md-btn" data-campaign-action="test"><i class="fa fa-envelope" aria-hidden="true"></i>Enviar teste para mim</button></div>
                <?php if($editable): ?><div class="campaign-actions-secondary"><button type="submit" class="md-btn"><i class="fa fa-floppy-disk" aria-hidden="true"></i>Salvar rascunho</button><button type="button" class="md-btn md-btn-primary" id="campaign-review"><i class="fa fa-paper-plane" aria-hidden="true"></i>Revisar envio</button></div><?php endif; ?>
            </div>
        </form>
        <p id="campaign-feedback" role="status" class="campaign-feedback"></p>
        <div class="campaign-audience"><i class="fa fa-users" aria-hidden="true"></i><p>Destinatários: contas ativas com e-mail confirmado e comunicados habilitados nas preferências. Contas banidas e usuários que desativaram e-mails não recebem.</p></div>
        <section class="campaign-delivery" id="campaign-delivery" <?= !$campaign || $campaign['status']==='draft'?'hidden':'' ?> aria-label="Acompanhamento do envio">
            <h2>Acompanhamento</h2><div class="campaign-metrics"><span><strong data-campaign-count="sent"><?= $counts['sent'] ?></strong> enviados</span><span><strong data-campaign-count="pending"><?= $counts['pending'] ?></strong> pendentes</span><span><strong data-campaign-count="failed"><?= $counts['failed'] ?></strong> falhas</span><span><strong data-campaign-count="skipped"><?= $counts['skipped'] ?></strong> ignorados</span></div>
            <progress id="campaign-progress" max="<?= max(1,$counts['total']) ?>" value="<?= $counts['sent']+$counts['skipped']+$counts['failed'] ?>"></progress>
            <?php if(!$campaign || $campaign['status']==='queued'): ?><div class="campaign-actions-secondary"><button class="md-btn md-btn-primary" type="button" id="campaign-continue"><i class="fa fa-play" aria-hidden="true"></i>Continuar envio</button><button class="md-btn" type="button" id="campaign-pause"><i class="fa fa-pause" aria-hidden="true"></i>Pausar nesta tela</button><button class="md-btn" type="button" id="campaign-cancel"><i class="fa fa-stop" aria-hidden="true"></i>Interromper campanha</button></div><?php endif; ?>
        </section>
    </section>
</div>
<div class="modal fade" id="campaignPreview" tabindex="-1" aria-labelledby="campaignPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content bg-dark text-light"><div class="modal-header"><h2 class="modal-title fs-5" id="campaignPreviewTitle">Prévia do e-mail</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="campaign-preview-sizes"><button class="md-btn" type="button" data-preview-width="100%" title="Visualização desktop"><i class="fa fa-desktop" aria-hidden="true"></i></button><button class="md-btn" type="button" data-preview-width="375px" title="Visualização celular"><i class="fa fa-mobile-screen" aria-hidden="true"></i></button></div><iframe id="campaign-preview-frame" title="Prévia do comunicado" sandbox="" referrerpolicy="no-referrer"></iframe></div></div></div>
<div class="modal fade" id="campaignConfirm" tabindex="-1" aria-labelledby="campaignConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content bg-dark text-light"><div class="modal-header"><h2 class="modal-title fs-5" id="campaignConfirmTitle">Confirmar envio</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p>O comunicado será enviado aos usuários elegíveis. O conteúdo não poderá ser editado após iniciar.</p><div class="form-check"><input type="checkbox" id="campaign-confirm-check" class="form-check-input"><label for="campaign-confirm-check" class="form-check-label">Confirmo o envio para todos os destinatários elegíveis.</label></div></div><div class="modal-footer"><button class="md-btn" type="button" data-bs-dismiss="modal">Voltar</button><button class="md-btn md-btn-primary" type="button" id="campaign-send" disabled><i class="fa fa-paper-plane" aria-hidden="true"></i>Iniciar envio</button></div></div></div></div>
<script type="application/json" id="campaign-initial"><?= json_encode(['html'=>$campaign['body_html'] ?? '<h2>Tem novidade na garagem</h2><p>Olá, colecionador!</p><p>Conte aqui o que chegou ao Garage64.</p>','editable'=>$editable],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script><script src="/assets/js/announcements.js?v=20261115" defer></script>
<?php require __DIR__.'/footer_admin.php'; ?>
