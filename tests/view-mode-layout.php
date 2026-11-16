<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_URL','http://garage.test');define('APP_NAME','Garage64');
require_once __DIR__.'/../includes/view_mode.php';
$_SESSION=['view_context'=>['mode'=>$argv[1] ?? 'support']];
if(($argv[1] ?? '')==='normal')$_SESSION=[];
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function h($v){return e($v);}
function current_user_id(){return in_view_mode()?42:1;}function current_user_slug(){return in_view_mode()?'colecionador':'paulo';}function current_user_name(){return current_user_slug();}function admin_username(){return current_user_name();}function get_unread_notifications_count($id){return 3;}function is_superadmin(){return !in_view_mode();}function get_flash(){return null;}function csrf_field(){return '<input type="hidden" name="csrf_token" value="test">';}
$_SERVER['PHP_SELF']='/admin/index.php';$page_title='Minha garagem';
require __DIR__.'/../includes/header_admin.php';
?>
<h1 class="h3">Minha garagem</h1><p class="text-secondary">Coleção de @<?= e(current_user_slug()) ?></p>
<form method="get" action="/admin/miniatures"><label for="filter">Buscar miniatura</label><input id="filter" name="search" class="form-control mb-3"><button class="md-btn" type="submit">Buscar</button></form>
<form method="post" action="/admin/profile" class="mt-4"><label for="name">Nome de exibição</label><input id="name" name="display_name" class="form-control mb-3" value="Colecionador"><button class="md-btn" type="submit">Salvar perfil</button></form>
<?php require __DIR__.'/../includes/footer_admin.php'; ?>
