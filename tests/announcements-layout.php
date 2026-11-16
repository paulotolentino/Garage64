<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_URL','http://garage.test');define('APP_NAME','Garage64');
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function h($v){return e($v);}
function current_user_id(){return 1;}function current_user_slug(){return 'paulo';}function get_unread_notifications_count($id){return 2;}function is_superadmin(){return true;}function admin_username(){return 'Paulo';}function get_flash(){return null;}
function csrf_field(){return '<input name="csrf_token" type="hidden" value="test">';}
$_SERVER['PHP_SELF']='/admin/announcements.php';$page_title='Comunicados';$id=0;$campaign=null;$eligible=3;$counts=['total'=>0,'sent'=>0,'pending'=>0,'failed'=>0,'skipped'=>0];
$campaigns=[['id'=>11,'subject'=>'Agora sua garagem tem mensagens','status'=>'completed','created_at'=>'2026-11-15 12:00:00'],['id'=>10,'subject'=>'Novidades para colecionadores','status'=>'draft','created_at'=>'2026-11-14 12:00:00']];
require __DIR__.'/../includes/announcements_view.php';
