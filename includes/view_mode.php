<?php
function in_view_mode(): bool {
    return !empty($_SESSION['view_context']);
}

function close_view_audit(): void {
    try {
        require_once __DIR__.'/db.php';
        db()->prepare('UPDATE support_access_log SET ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE id=? AND actor_id=?')->execute([$_SESSION['view_context']['audit_id'],$_SESSION['view_context']['actor_id']]);
    } catch (Throwable $e) { error_log('Garage64 support audit close failed.'); }
}

function view_actor_is_valid(array $actor,array $context):bool {
    return !empty($actor['is_superadmin']) && empty($actor['is_banned']) && (int)$actor['id']===(int)$context['actor_id'] && (int)$actor['auth_version']===(int)$context['actor_version'];
}

function restore_view_actor(array $actor):void {
    if(!view_actor_is_valid($actor,$_SESSION['view_context']))throw new InvalidArgumentException('A sessão administrativa não é mais válida.');
    close_view_audit();
    $original=$_SESSION['view_context']['original'];
    $_SESSION=$original;
    unset($_SESSION['view_context']);
    set_authenticated_user($actor);
    session_regenerate_id(true);
}

function validate_view_mode():void {
    require_once __DIR__.'/db.php';
    $context=$_SESSION['view_context'];
    try {
        $st=db()->prepare('SELECT * FROM admin_users WHERE id=?');$st->execute([$context['actor_id']]);$actor=$st->fetch();
        if(!$actor || !view_actor_is_valid($actor,$context)) {
            close_view_audit();$_SESSION=[];remember_cookie('',time()-3600);unset($_COOKIE['garage64_remember']);session_regenerate_id(true);
            header('Location: /admin/login',true,303);exit;
        }
        $st=db()->prepare('SELECT id,auth_version,is_superadmin FROM admin_users WHERE id=?');$st->execute([$context['target_id']]);$target=$st->fetch();
        if(time()>=(int)$context['expires'] || !$target || (int)$target['auth_version']!==(int)$context['target_version'] || ($context['mode']==='support' && $target['is_superadmin'])) {
            restore_view_actor($actor);header('Location: /admin/',true,303);exit;
        }
        if((int)($_SESSION['user_id'] ?? 0)!==(int)$context['target_id'])throw new RuntimeException('Invalid support identity.');
        $_SESSION['is_superadmin']=false;
    } catch(Throwable $e) {
        http_response_code(503);exit('A visualização de suporte está indisponível. Tente novamente.');
    }
}

function enforce_view_mode_readonly():void {
    if(!in_view_mode() || in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true))return;
    $script=realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if($script===realpath(__DIR__.'/../admin/view-mode.php'))return;
    http_response_code(403);
    if(($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='Garage64') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Modo de visualização: alterações não são permitidas. Volte à sua conta para editar.']);
    } else echo 'Modo de visualização: alterações não são permitidas. Use Voltar à minha conta.';
    exit;
}

function begin_view_mode(string $mode,int $target_id,string $reason):void {
    if(in_view_mode() || !in_array($mode,['preview','support'],true))throw new InvalidArgumentException('Saia da visualização atual antes de iniciar outra.');
    $pdo=db();$st=$pdo->prepare('SELECT * FROM admin_users WHERE id=? AND is_superadmin=1 AND is_banned=0');$st->execute([current_user_id()]);$actor=$st->fetch();
    if(!$actor)throw new InvalidArgumentException('Acesso exclusivo de superadmin.');
    if($mode==='preview')$target=$actor;
    else {
        $st=$pdo->prepare('SELECT * FROM admin_users WHERE id=? AND is_superadmin=0');$st->execute([$target_id]);$target=$st->fetch();
        if(!$target)throw new InvalidArgumentException('Selecione uma conta de usuário comum.');
    }
    $reason=mb_substr(trim($reason) ?: 'Visualização administrativa',0,500);
    $pdo->prepare('INSERT INTO support_access_log (actor_id,target_id,mode,reason,started_at,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')->execute([$actor['id'],$target['id'],$mode,$reason]);
    $audit=(int)$pdo->lastInsertId();$original=$_SESSION;
    $_SESSION=[];set_authenticated_user($target);$_SESSION['is_superadmin']=false;
    $_SESSION['csrf_token']=bin2hex(random_bytes(32));
    $_SESSION['view_context']=['actor_id'=>(int)$actor['id'],'actor_version'=>(int)$actor['auth_version'],'target_id'=>(int)$target['id'],'target_version'=>(int)$target['auth_version'],'mode'=>$mode,'expires'=>time()+1800,'audit_id'=>$audit,'original'=>$original];
    session_regenerate_id(true);
}
