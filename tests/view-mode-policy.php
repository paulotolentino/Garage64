<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/view_mode.php';
function assert_view(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$context=['actor_id'=>1,'actor_version'=>3];
$actor=['id'=>1,'auth_version'=>3,'is_superadmin'=>1,'is_banned'=>0];
assert_view(view_actor_is_valid($actor,$context),'Valid actor rejected');
foreach(['is_superadmin'=>0,'is_banned'=>1,'auth_version'=>4,'id'=>2] as $field=>$value){$changed=$actor;$changed[$field]=$value;assert_view(!view_actor_is_valid($changed,$context),'Invalid actor accepted: '.$field);}
if(($argv[1] ?? '')==='blocked'){
    $_SESSION=['view_context'=>['actor_id'=>1]];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_FILENAME']=__DIR__.'/../admin/profile.php';$_SERVER['HTTP_X_REQUESTED_WITH']='Garage64';enforce_view_mode_readonly();echo 'UNSAFE_WRITE';exit;
}
if(($argv[1] ?? '')==='exit-route'){
    $_SESSION=['view_context'=>['actor_id'=>1]];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_FILENAME']=__DIR__.'/../admin/view-mode.php';enforce_view_mode_readonly();echo 'EXIT_ROUTE_ALLOWED';exit;
}
$blocked=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' blocked');
assert_view(!str_contains($blocked,'UNSAFE_WRITE') && str_contains($blocked,'Modo de visualiza'),'Writes not blocked');
$exit=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' exit-route');assert_view($exit==='EXIT_ROUTE_ALLOWED','Cannot leave mode');
echo "PASS support policy: actor identity, privilege revocation, ban, password version, read-only POST and exit route\n";
