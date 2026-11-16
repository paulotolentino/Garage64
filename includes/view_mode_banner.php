<?php if(function_exists('in_view_mode') && in_view_mode()): ?>
<aside class="view-mode-banner" aria-label="Modo de visualização ativo">
    <div><strong><i class="fa fa-eye" aria-hidden="true"></i> <?= $_SESSION['view_context']['mode']==='support' ? 'Suporte: @'.e(current_user_slug()) : 'Visualizando como usuário comum' ?></strong><span>Somente leitura · Todas as abas desta sessão · Até 30 minutos</span></div>
    <form method="post" action="/admin/view-mode" data-view-mode-exit><?= csrf_field() ?><input type="hidden" name="action" value="leave"><button class="md-btn md-btn-primary" type="submit"><i class="fa fa-arrow-left" aria-hidden="true"></i>Voltar à minha conta</button></form>
</aside>
<script>
document.documentElement.dataset.readonlyMode='1';
document.addEventListener('submit',function(event){if(!event.target.matches('[data-view-mode-exit]') && event.target.method.toLowerCase()!=='get')event.preventDefault();},true);
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('form:not([data-view-mode-exit])').forEach(function(form){if(form.method.toLowerCase()==='get')return;form.querySelectorAll('input,select,textarea,button').forEach(function(el){el.disabled=true;});});});
</script>
<?php endif; ?>
