<?php
require_once __DIR__.'/mailer.php';

function sanitize_campaign_html(string $html):string {
    if(strlen($html)>200000)throw new InvalidArgumentException('O conteúdo é muito grande.');
    $document=new DOMDocument();$previous=libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    $allowed=['p','div','br','strong','b','em','i','u','s','h1','h2','h3','ul','ol','li','blockquote','a','img','span'];
    $clean=function(DOMNode $node)use(&$clean,$allowed,$document):void {
        foreach(iterator_to_array($node->childNodes) as $child) {
            // Quill exports ordinary spaces as NBSP; restore natural word wrapping.
            if($child instanceof DOMText){$child->nodeValue=str_replace(["\u{00A0}","\u{202F}"],' ',$child->nodeValue);continue;}
            if($child instanceof DOMComment){$node->removeChild($child);continue;}
            if(!($child instanceof DOMElement))continue;
            $tag=strtolower($child->tagName);
            if(!in_array($tag,$allowed,true)) {
                if(in_array($tag,['script','style','iframe','object','svg','math','form','input','button','template'],true)){$node->removeChild($child);continue;}
                $clean($child);while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);continue;
            }
            $href=$child->getAttribute('href');$src=$child->getAttribute('src');$alt=$child->getAttribute('alt');
            $styles=$child->getAttribute('style');$classes=$child->getAttribute('class');
            foreach(iterator_to_array($child->attributes) as $attribute)$child->removeAttributeNode($attribute);
            if($tag==='a' && filter_var($href,FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($href,PHP_URL_SCHEME)),['https','http'],true)){$child->setAttribute('href',$href);$child->setAttribute('rel','noopener noreferrer');$child->setAttribute('style','color:#c77f12;text-decoration:underline');}
            if($tag==='img') {
                $prefix=rtrim(APP_URL,'/').'/uploads/campaigns/';
                if(!str_starts_with($src,$prefix) || !preg_match('/^[a-f0-9]{32}\.(?:webp|jpg|png)$/D',substr($src,strlen($prefix)))){$node->removeChild($child);continue;}
                $child->setAttribute('src',$src);$child->setAttribute('alt',mb_substr($alt,0,200));$child->setAttribute('style','max-width:100%;height:auto;display:block;margin:16px auto');
            }
            if(in_array($tag,['p','div','h1','h2','h3','blockquote'],true)) {
                $align='left';
                if(preg_match('/text-align\s*:\s*(center|right|justify)/i',$styles,$m) || preg_match('/ql-align-(center|right|justify)/',$classes,$m))$align=strtolower($m[1]);
                $child->setAttribute('style','line-height:1.7;margin:0 0 16px;text-align:'.$align);
            }
            $clean($child);
            $child->setAttribute('style',$child->getAttribute('style').';white-space:normal;word-break:normal;word-wrap:break-word;overflow-wrap:break-word;hyphens:none');
        }
    };
    $body=$document->getElementsByTagName('body')->item(0);$clean($body);$result='';
    foreach($body->childNodes as $child)$result.=$document->saveHTML($child);
    if(trim(html_entity_decode(strip_tags($result)))==='' && !str_contains($result,'<img'))throw new InvalidArgumentException('Escreva o conteúdo do comunicado.');
    return $result;
}

function campaign_plain_text(string $html):string {
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    $walk=function(DOMNode $node)use(&$walk):string {
        if($node instanceof DOMText)return $node->nodeValue;
        $text='';foreach($node->childNodes as $child)$text.=$walk($child);
        if($node instanceof DOMElement) {
            $tag=strtolower($node->tagName);
            if($tag==='a' && $node->hasAttribute('href'))$text.=' ('.$node->getAttribute('href').')';
            if($tag==='img')$text.='['.($node->getAttribute('alt') ?: 'Imagem').'] '.$node->getAttribute('src');
            if(in_array($tag,['br','p','div','h1','h2','h3','li','blockquote'],true))$text.="\n\n";
        }
        return $text;
    };
    return trim($walk($doc->getElementsByTagName('body')->item(0)));
}

function campaign_email_template(string $subject,string $html):array {
    $safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html=sanitize_campaign_html($html);
    $prefs=rtrim(APP_URL,'/').'/admin/email-preferences';
    $text=campaign_plain_text($html)."\n\nPreferências de e-mail: {$prefs}";
    return ['subject'=>$subject,'text'=>$text,'html'=>'<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="word-break:normal;word-wrap:break-word;overflow-wrap:break-word;hyphens:none;margin:0;background:#eeeeef;font-family:Arial,sans-serif;color:#24272c"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;width:100%"><tr><td style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:auto;background:#ffffff;table-layout:fixed;width:100%"><tr><td style="padding:28px 32px;background:#0c0e12;color:#f5a623;font-size:24px;font-weight:bold">Garage64</td></tr><tr><td style="padding:32px;font-size:15px;line-height:1.7;white-space:normal;word-break:normal;word-wrap:break-word;overflow-wrap:break-word;hyphens:none">'.$html.'</td></tr><tr><td style="padding:24px 32px;border-top:1px solid #e3e5e8;color:#6e7681;font-size:12px;line-height:1.6">Você recebe os comunicados do Garage64 conforme suas preferências.<br><a href="'.$safe($prefs).'" style="color:#4d535d">Gerenciar ou desativar comunicados</a></td></tr></table></td></tr></table></body></html>'];
}

function campaign_recipient_counts(int $id):array {
    $st=db()->prepare("SELECT COUNT(*) AS total,SUM(status='sent') AS sent,SUM(status='pending') AS pending,SUM(status='failed') AS failed,SUM(status='skipped') AS skipped FROM email_campaign_recipients WHERE campaign_id=?");$st->execute([$id]);return array_map('intval',$st->fetch());
}

function process_email_campaigns(int $limit=1,?callable $deliver=null,?int $campaign_id=null):array {
    $pdo=db();$stats=['sent'=>0,'skipped'=>0,'failed'=>0];
    if(!(int)$pdo->query("SELECT GET_LOCK('garage64_campaigns',0)")->fetchColumn())return $stats;
    try {
        $scope=$campaign_id!==null?' AND c.id='.(int)$campaign_id:'';
        $rows=$pdo->query("SELECT r.*,c.subject,c.body_html FROM email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id WHERE c.status='queued' AND r.status='pending' AND r.available_at<=UTC_TIMESTAMP()".$scope." ORDER BY c.id,r.user_id LIMIT ".max(1,min(5,$limit)))->fetchAll();
        foreach($rows as $row) {
            $st=$pdo->prepare('SELECT u.email,u.email_verified_at,u.is_banned,p.enabled,p.announcements FROM admin_users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE u.id=?');$st->execute([$row['user_id']]);$user=$st->fetch();
            if(!$user || $user['is_banned'] || !$user['email_verified_at'] || $user['email']!==$row['email'] || !(int)($user['enabled'] ?? 1) || !(int)($user['announcements'] ?? 1)) {
                $pdo->prepare("UPDATE email_campaign_recipients SET status='skipped' WHERE campaign_id=? AND user_id=?")->execute([$row['campaign_id'],$row['user_id']]);$stats['skipped']++;continue;
            }
            $template=campaign_email_template($row['subject'],$row['body_html']);
            $ok=$deliver?$deliver($user['email'],$template):send_transactional_email($user['email'],$template);
            $pdo->prepare("UPDATE email_campaign_recipients SET attempts=attempts+1,status=IF(?=1,'sent',IF(attempts>=5,'failed','pending')),sent_at=IF(?=1,UTC_TIMESTAMP(),NULL),available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE campaign_id=? AND user_id=?")->execute([$ok?1:0,$ok?1:0,$row['campaign_id'],$row['user_id']]);$stats[$ok?'sent':'failed']++;
        }
        $pdo->exec("UPDATE email_campaigns c SET status='completed' WHERE c.status='queued' AND NOT EXISTS(SELECT 1 FROM email_campaign_recipients r WHERE r.campaign_id=c.id AND r.status='pending')");
        return $stats;
    } finally {$pdo->query("SELECT RELEASE_LOCK('garage64_campaigns')");}
}
