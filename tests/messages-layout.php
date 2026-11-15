<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_URL', 'http://garage.test');
define('APP_NAME', 'Garage64');
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function h($v) { return e($v); }
function current_user_id() { return 1; }
function current_user_slug() { return 'paulo'; }
function get_unread_notifications_count($id) { return 2; }
function is_superadmin() { return true; }
function admin_username() { return 'Paulo'; }
function get_flash() { return null; }
function csrf_field() { return '<input type="hidden" name="csrf_token" value="test">'; }
$_SERVER['PHP_SELF'] = '/admin/messages.php';
$page_title = 'Mensagens';
$me = 1; $peer_id = 2; $ready = true; $error = '';
$peer = ['id'=>2, 'slug'=>'ana.diecast', 'username'=>'ana', 'display_name'=>'Ana Ferreira'];
$conversations = [
    ['id'=>2, 'slug'=>'ana.diecast', 'username'=>'ana', 'display_name'=>'Ana Ferreira', 'preview'=>'Essa edição ficou incrível!', 'last_at'=>'2026-11-15 15:30:00', 'unread'=>0],
    ['id'=>3, 'slug'=>'marcos', 'username'=>'marcos', 'display_name'=>'Marcos Oliveira', 'preview'=>'Você já tem o Skyline da coleção nova?', 'last_at'=>'2026-11-15 13:05:00', 'unread'=>3],
];
$messages = [
    ['id'=>1,'sender_id'=>2,'body'=>'Oi, Paulo! Vi seu Porsche na garagem. Que peça bonita.','created_at'=>'2026-11-15 15:28:00'],
    ['id'=>2,'sender_id'=>1,'body'=>'Obrigado! Foi um achado no encontro de colecionadores do fim de semana.','created_at'=>'2026-11-15 15:29:00'],
    ['id'=>3,'sender_id'=>2,'body'=>'Essa edição ficou incrível!','created_at'=>'2026-11-15 15:30:00'],
];
if (($argv[1] ?? '') === 'empty') { $peer = null; $peer_id = 0; }
require __DIR__ . '/../includes/messages_view.php';
