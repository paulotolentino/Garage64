<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/email_campaigns.php';
require_superadmin();
header('Cache-Control: no-store');
function eligible_campaign_users():int {
    return (int)db()->query('SELECT COUNT(*) FROM admin_users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE u.is_banned=0 AND u.email_verified_at IS NOT NULL AND COALESCE(p.enabled,1)=1 AND COALESCE(p.announcements,1)=1')->fetchColumn();
}
if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();header('Content-Type: application/json; charset=utf-8');
    $pdo=db();
    try {
        $action=(string)($_POST['action'] ?? '');$id=(int)($_POST['id'] ?? 0);
        if($action==='email_campaign_upload') {
            $file=$_FILES['image'] ?? []; $tmp=$file['tmp_name'] ?? '';
            if(($file['error'] ?? 1)!==UPLOAD_ERR_OK || !is_uploaded_file($tmp) || ($file['size'] ?? 0)>5*1024*1024)throw new InvalidArgumentException('Envie uma imagem de até 5 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$size=getimagesize($tmp);
            if(!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !$size || $size[0]*$size[1]>24000000)throw new InvalidArgumentException('Imagem inválida. Use JPG, PNG ou WebP de até 24 megapixels.');
            $source=imagecreatefromstring(file_get_contents($tmp));if(!$source)throw new InvalidArgumentException('Não foi possível abrir a imagem.');
            $scale=min(1,1600/$size[0],1600/$size[1]);$w=max(1,(int)round($size[0]*$scale));$h=max(1,(int)round($size[1]*$scale));$image=imagecreatetruecolor($w,$h);
            $png=$mime==='image/png';
            if($png){imagealphablending($image,false);imagesavealpha($image,true);}else{imagefill($image,0,0,imagecolorallocate($image,255,255,255));}
            imagecopyresampled($image,$source,0,0,0,0,$w,$h,$size[0],$size[1]);
            $dir=rtrim(UPLOADS_DIR,'/').'/campaigns';if(!is_dir($dir) && !mkdir($dir,0755,true))throw new RuntimeException('Falha ao preparar a pasta de imagens.');
            $name=bin2hex(random_bytes(16)).($png?'.png':'.jpg');$ok=$png?imagepng($image,$dir.'/'.$name,6):imagejpeg($image,$dir.'/'.$name,88);imagedestroy($source);imagedestroy($image);
            if(!$ok)throw new RuntimeException('Falha ao salvar a imagem.');
            echo json_encode(['ok'=>true,'url'=>rtrim(APP_URL,'/').'/uploads/campaigns/'.$name]);exit;
        }
        if(in_array($action,['email_campaign_preview','email_campaign_save','email_campaign_test'],true)) {
            $subject=trim((string)($_POST['subject'] ?? ''));if($subject==='' || mb_strlen($subject)>180 || preg_match('/[\r\n]/',$subject))throw new InvalidArgumentException('Informe um assunto de até 180 caracteres.');
            $body=sanitize_campaign_html((string)($_POST['body_html'] ?? ''));
            if($action==='email_campaign_preview') {echo json_encode(['ok'=>true,'html'=>campaign_email_template($subject,$body)['html']]);exit;}
            if($action==='email_campaign_test') {
                $st=$pdo->prepare('SELECT email,email_verified_at FROM admin_users WHERE id=?');$st->execute([current_user_id()]);$user=$st->fetch();
                if(!$user['email_verified_at'])throw new InvalidArgumentException('Confirme seu e-mail antes de enviar um teste.');
                $bucket='campaign-test:'.current_user_id();if(rate_limit_exceeded($bucket,5,3600))throw new InvalidArgumentException('Limite de testes atingido. Aguarde uma hora.');rate_limit_hit($bucket,3600);
                if(!send_transactional_email($user['email'],campaign_email_template('[TESTE] '.$subject,$body)))throw new RuntimeException('O SendGrid não aceitou o envio. Confira a configuração e os logs.');
                echo json_encode(['ok'=>true,'message'=>'Teste aceito pelo SendGrid para '.$user['email']]);exit;
            }
            $pdo->beginTransaction();
            if($id) {
                $st=$pdo->prepare('SELECT status FROM email_campaigns WHERE id=? FOR UPDATE');$st->execute([$id]);if($st->fetchColumn()!=='draft')throw new InvalidArgumentException('Somente rascunhos podem ser editados.');
                $pdo->prepare('UPDATE email_campaigns SET subject=?,body_html=? WHERE id=?')->execute([$subject,$body,$id]);
            } else {$pdo->prepare('INSERT INTO email_campaigns (created_by,subject,body_html) VALUES (?,?,?)')->execute([current_user_id(),$subject,$body]);$id=(int)$pdo->lastInsertId();}
            $pdo->commit();echo json_encode(['ok'=>true,'id'=>$id,'eligible'=>eligible_campaign_users(),'message'=>'Rascunho salvo.']);exit;
        }
        if($action==='email_campaign_queue') {
            if(($_POST['confirm'] ?? '')!=='1')throw new InvalidArgumentException('Confirme os destinatários antes de iniciar.');
            if(SENDGRID_API_KEY==='')throw new InvalidArgumentException('Configure o SendGrid antes de iniciar o envio.');
            $pdo->beginTransaction();$st=$pdo->prepare('SELECT * FROM email_campaigns WHERE id=? FOR UPDATE');$st->execute([$id]);$campaign=$st->fetch();
            if(!$campaign || $campaign['status']!=='draft')throw new InvalidArgumentException('Este comunicado já foi iniciado ou não é um rascunho.');
            $pdo->prepare('INSERT IGNORE INTO email_campaign_recipients (campaign_id,user_id,email,available_at) SELECT ?,u.id,u.email,UTC_TIMESTAMP() FROM admin_users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE u.is_banned=0 AND u.email_verified_at IS NOT NULL AND COALESCE(p.enabled,1)=1 AND COALESCE(p.announcements,1)=1')->execute([$id]);
            if(campaign_recipient_counts($id)['total']===0)throw new InvalidArgumentException('Não há destinatários elegíveis para este comunicado.');
            $pdo->prepare("UPDATE email_campaigns SET status='queued',queued_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);$pdo->commit();
            echo json_encode(['ok'=>true,'message'=>'Envio iniciado.','counts'=>campaign_recipient_counts($id)]);exit;
        }
        if($action==='email_campaign_cancel') {
            $pdo->prepare("UPDATE email_campaigns SET status='cancelled' WHERE id=? AND status='queued'")->execute([$id]);
            echo json_encode(['ok'=>true,'message'=>'Envio interrompido. E-mails já enviados não podem ser recolhidos.']);exit;
        }
        if($action==='email_campaign_process') {echo json_encode(['ok'=>true,'result'=>process_email_campaigns(1,null,$id),'counts'=>campaign_recipient_counts($id)]);exit;}
        throw new InvalidArgumentException('Ação inválida.');
    }catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();http_response_code(422);
        echo json_encode(['ok'=>false,'message'=>$e instanceof InvalidArgumentException || $e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Não foi possível concluir. Confira as migrations e os logs.']);
    }
    exit;
}
$id=max(0,(int)($_GET['id'] ?? 0));$campaign=null;$counts=['total'=>0,'sent'=>0,'pending'=>0,'failed'=>0,'skipped'=>0];
if($id) {$st=db()->prepare('SELECT * FROM email_campaigns WHERE id=?');$st->execute([$id]);$campaign=$st->fetch() ?: null;if(!$campaign){http_response_code(404);exit('Comunicado não encontrado.');}$counts=campaign_recipient_counts($id);}
$campaigns=db()->query('SELECT id,subject,status,created_at FROM email_campaigns ORDER BY id DESC LIMIT 40')->fetchAll();
$eligible=eligible_campaign_users();$page_title='Comunicados';
require __DIR__.'/../includes/announcements_view.php';
