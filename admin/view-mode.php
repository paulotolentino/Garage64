<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
verify_csrf();
try {
    if(($_POST['action'] ?? '')==='leave') {
        if(!in_view_mode())redirect('/admin/');
        $context=$_SESSION['view_context'];$st=db()->prepare('SELECT * FROM admin_users WHERE id=?');$st->execute([$context['actor_id']]);$actor=$st->fetch();
        if(!$actor || !view_actor_is_valid($actor,$context)){http_response_code(403);exit('Acesso negado.');}
        restore_view_actor($actor);redirect('/admin/profile');
    }
    require_superadmin();
    begin_view_mode((string)($_POST['mode'] ?? ''),(int)($_POST['user_id'] ?? 0),(string)($_POST['reason'] ?? 'Suporte administrativo'));
    redirect('/admin/');
}catch(Throwable $e){flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível iniciar. Aplique as migrations e tente novamente.','warning');redirect('/admin/support');}
