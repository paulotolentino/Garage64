<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_superadmin();
$q=trim((string)($_GET['q'] ?? ''));$users=[];$audit=[];$audit_ready=true;
if($q!=='') {
    $term='%'.strtr(mb_substr($q,0,100),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
    $st=db()->prepare("SELECT id,username,display_name,slug,email,is_banned FROM admin_users WHERE is_superadmin=0 AND (username LIKE ? ESCAPE '!' OR slug LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!') ORDER BY username LIMIT 25");$st->execute([$term,$term,$term,$term]);$users=$st->fetchAll();
}
try {
    $audit=db()->query('SELECT l.*,a.username AS actor_name,t.username AS target_name FROM support_access_log l JOIN admin_users a ON a.id=l.actor_id JOIN admin_users t ON t.id=l.target_id ORDER BY l.id DESC LIMIT 25')->fetchAll();
} catch(Throwable $e){$audit_ready=false;}
$page_title='Acesso de suporte';require __DIR__.'/../includes/header_admin.php';
?>
<div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-4"><div><h1 class="h3 mb-2">Acesso de suporte</h1><p class="text-secondary mb-0">Inspeção temporária de contas, sem alterações nem avisos ao usuário.</p></div><form method="post" action="/admin/view-mode" class="m-0"><?= csrf_field() ?><input type="hidden" name="mode" value="preview"><button type="submit" class="md-btn"><i class="fa fa-user"></i>Ver como usuário comum</button></form></div>
<?php if(!$audit_ready): ?><p class="alert alert-warning">Aplique índices &amp; migrations na Manutenção para habilitar o acesso de suporte e seu registro interno.</p><?php endif; ?>
<form method="get" class="d-flex gap-2 mb-4" style="max-width:680px"><label class="visually-hidden" for="support-search">Buscar usuário</label><input id="support-search" type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Nome, @usuário ou e-mail" maxlength="100" required><button type="submit" class="md-btn md-btn-primary" aria-label="Buscar usuário" title="Buscar usuário"><i class="fa fa-magnifying-glass"></i></button></form>
<?php if($q!=='' && !$users): ?><p class="text-secondary">Nenhuma conta de usuário comum encontrada.</p><?php endif; ?>
<div class="row g-3 mb-5">
<?php foreach($users as $user): ?>
    <div class="col-md-6"><article class="support-user"><div><h2 class="h6"><?= e($user['display_name'] ?: $user['username']) ?></h2><p class="mb-1 text-break">@<?= e($user['slug']) ?></p><p class="small text-secondary text-break"><?= e($user['email']) ?><?= $user['is_banned']?' · Conta banida':'' ?></p></div><form method="post" action="/admin/view-mode"><?= csrf_field() ?><input type="hidden" name="mode" value="support"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><label class="small mb-2" for="support-reason-<?= (int)$user['id'] ?>">Motivo do acesso</label><input class="form-control mb-3" id="support-reason-<?= (int)$user['id'] ?>" name="reason" maxlength="500" value="Atendimento de suporte" required><button type="submit" class="md-btn" <?= !$audit_ready?'disabled':'' ?>><i class="fa fa-headset"></i>Acessar em modo de suporte</button></form></article></div>
<?php endforeach; ?>
</div>
<h2 class="h5 mb-3">Registro interno de acessos</h2>
<?php if(!$audit): ?><p class="text-secondary">Nenhum acesso registrado.</p><?php else: ?>
<div class="table-responsive"><table class="table table-dark align-middle"><thead><tr><th>Administrador</th><th>Conta visualizada</th><th>Modo</th><th>Início (UTC)</th><th>Estado</th><th>Motivo</th></tr></thead><tbody>
<?php foreach($audit as $entry): ?><tr><td><?= e($entry['actor_name']) ?></td><td><?= e($entry['target_name']) ?></td><td><?= $entry['mode']==='preview'?'Usuário comum':'Suporte' ?></td><td class="text-nowrap"><?= e($entry['started_at']) ?></td><td><?= $entry['ended_at']?'Encerrado':(strtotime($entry['expires_at'].' UTC')<time()?'Expirado':'Ativo') ?></td><td class="text-break"><?= e($entry['reason']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php require __DIR__.'/../includes/footer_admin.php'; ?>
