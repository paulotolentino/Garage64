<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_login();
$labels=email_preference_labels();
if (!is_superadmin()) unset($labels['registrations']);
$keys=array_merge(['enabled'],array_keys($labels));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $values=[current_user_id()];
    foreach($keys as $key) $values[]=isset($_POST[$key])?1:0;
    $columns=implode(',',$keys);
    $updates=implode(',',array_map(static fn($key)=>$key.'=VALUES('.$key.')',$keys));
    db()->prepare('INSERT INTO email_preferences (user_id,'.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).') ON DUPLICATE KEY UPDATE '.$updates)->execute($values);
    flash('Preferências de e-mail atualizadas.');
    redirect('/admin/email-preferences');
}
$st=db()->prepare('SELECT * FROM email_preferences WHERE user_id=?');$st->execute([current_user_id()]);$prefs=$st->fetch() ?: [];
$page_title='Preferências de e-mail';
require_once __DIR__ . '/../includes/header_admin.php';
?>
<section style="max-width:680px">
    <h1 class="h3 mb-4">Preferências de e-mail</h1>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-check form-switch py-3 border-bottom"><input class="form-check-input" type="checkbox" role="switch" id="email-enabled" name="enabled" <?= ($prefs['enabled'] ?? 1)?'checked':'' ?>><label class="form-check-label fw-semibold" for="email-enabled">Receber e-mails de atividade e comunicados</label></div>
        <p class="text-secondary small mt-3">Um resumo a cada 7 dias, apenas quando houver atividade. Escolha o que incluir. Comunicados de novidades são enviados separadamente.</p>
        <h2 class="h5 mt-4">Incluir no resumo semanal</h2>
        <?php foreach($labels as $key=>$label): ?>
        <?php if($key==='announcements'): ?><h2 class="h5 mt-4">Comunicados do sistema</h2><?php endif; ?>
        <div class="form-check form-switch py-3 border-bottom"><input class="form-check-input" type="checkbox" role="switch" id="email-<?= e($key) ?>" name="<?= e($key) ?>" <?= ($prefs[$key] ?? 1)?'checked':'' ?>><label class="form-check-label" for="email-<?= e($key) ?>"><?= e($label) ?></label></div>
        <?php endforeach; ?>
        <p class="text-secondary small mt-4">Confirmação de e-mail e recuperação de senha continuam disponíveis independentemente destas preferências.</p>
        <button type="submit" class="md-btn md-btn-primary"><i class="fa fa-check"></i>Salvar preferências</button>
    </form>
</section>
<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
