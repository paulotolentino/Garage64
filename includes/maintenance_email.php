<?php
require_once __DIR__ . '/email_notifications.php';
$delivery=email_delivery_settings();
$queue_ready=true;$queue_counts=[];
try {
    $queue_counts=db()->query("SELECT SUM(status='pending') AS pending,SUM(status='sent') AS sent,SUM(status='failed') AS failed FROM email_notification_queue")->fetch();
    db()->query('SELECT id FROM email_delivery_settings LIMIT 1');
} catch(Throwable $e) {$queue_ready=false;}
?>
<section class="maint-email py-4 border-bottom mb-4" aria-labelledby="maintenance-email-title">
    <h2 class="h4" id="maintenance-email-title"><i class="fa fa-envelope me-2"></i>Avisos por e-mail</h2>
    <?php if(!$queue_ready): ?><p class="text-warning">Aplique índices &amp; migrations para habilitar os controles de envio.</p><?php endif; ?>
    <p class="text-secondary">Pendências: <strong><?= (int)($queue_counts['pending'] ?? 0) ?></strong> · Atividades notificadas: <strong><?= (int)($queue_counts['sent'] ?? 0) ?></strong> · Falhas definitivas: <strong><?= (int)($queue_counts['failed'] ?? 0) ?></strong></p>
    <form data-email-maintenance class="mb-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="email_settings">
        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="email-automatic" name="automatic" <?= $delivery['automatic']?'checked':'' ?>><label for="email-automatic" class="form-check-label">Enviar avisos automaticamente ao usar o site</label></div>
        <p class="text-secondary small">Sem acessos, os avisos aguardam o próximo acesso ou o processamento manual nesta tela.</p>
        <p class="text-secondary small">Resumo semanal: um e-mail por usuário a cada 7 dias, apenas se houver atividade elegível. Mensagens já lidas não entram. O primeiro período começa na primeira atividade; comunicados de novidades são enviados separadamente.</p>
        <a class="md-btn mb-3" href="/admin/announcements"><i class="fa fa-bullhorn"></i>Criar comunicado para os usuários</a>
        <button class="md-btn" type="submit" <?= !$queue_ready?'disabled':'' ?>><i class="fa fa-check"></i>Salvar regras de envio</button>
        <p data-email-result role="status" class="small mt-2"></p>
    </form>
    <form data-email-maintenance>
        <?= csrf_field() ?><input type="hidden" name="action" value="email_process">
        <button class="md-btn md-btn-primary" type="submit" <?= !$queue_ready?'disabled':'' ?>><i class="fa fa-paper-plane"></i>Processar próximo lote</button>
        <p class="text-secondary small mt-2">Processa um resumo semanal elegível e um destinatário de comunicado por lote. Respeita as preferências; não antecipa o período semanal. Falhas temporárias aguardam 15 minutos antes da próxima tentativa.</p>
        <p data-email-result role="status" class="small mt-2"></p>
    </form>
</section>
<script>
document.querySelectorAll('[data-email-maintenance]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button=form.querySelector('button[type=submit]');
        if(button.disabled) return;
        const body=new FormData(form);
        const result=form.querySelector('[data-email-result]');
        button.disabled=true; result.textContent='Processando…';
        try {
            const response=await fetch(location.href,{method:'POST',body});
            if(response.redirected) throw new Error('Entre novamente como superadmin.');
            const data=await response.json();
            if(!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível concluir.');
            result.textContent=data.message;
        } catch(error) {result.textContent=error.message;}
        finally {button.disabled=false;}
    });
});
</script>
