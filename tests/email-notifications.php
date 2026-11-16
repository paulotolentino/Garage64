<?php
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/email_notifications.php';
require_once __DIR__.'/../includes/signup_protection.php';
require_once __DIR__.'/../includes/countries.php';
require_once __DIR__.'/../includes/email_weekly.php';
require_once __DIR__.'/../includes/email_campaigns.php';
function test_notice(bool $ok,string $message):void {if(!$ok) throw new RuntimeException($message);}
foreach(['registration','message','like','comment','reply','mention','rating','follow'] as $type) {
    test_notice(email_event_preference($type)!=='','Missing event preference');
    $template=notification_email_template('<script>alert(1)</script>',$type,1);
    test_notice(!str_contains($template['html'],'<script>'),'Unsafe name in notification');
    test_notice(str_contains($template['text'],'/admin/email-preferences'),'Missing preferences link');
}
test_notice(email_event_preference('newsletter')==='','Campaigns should not be enabled');
test_notice(count(signup_countries())>=240,'Incomplete country list');
test_notice((signup_countries()['BR'] ?? '')==='Brasil','Missing Brazil');
test_notice(!isset(signup_countries()['ZZ']),'Unknown country should not be accepted');
$summary=weekly_digest_template('<img onerror=alert(1)>',['likes'=>2,'comments'=>3]);
test_notice(!str_contains($summary['html'],'<img onerror'),'Unsafe digest name');
test_notice(str_contains($summary['text'],'Curtidas: 2') && str_contains($summary['text'],'Comentários'),'Digest should include multiple categories');
$safe=sanitize_campaign_html('<h2 onclick="bad()">Novidades</h2><script>alert(1)</script><a href="javascript:alert(1)">Link</a><img src="https://evil.test/a.png" onerror="bad()"><p style="text-align:center;background:url(x)"><strong>Texto</strong></p>');
test_notice(!str_contains($safe,'onclick') && !str_contains($safe,'<script') && !str_contains($safe,'javascript:') && !str_contains($safe,'evil.test') && !str_contains($safe,'background:'),'Unsafe campaign HTML');
test_notice(str_contains($safe,'<strong') && str_contains($safe,'>Texto</strong>') && str_contains($safe,'text-align:center'),'Formatting was lost');
test_notice(sanitize_campaign_html($safe)===$safe,'Sanitizer must be stable');
test_notice(str_contains(sanitize_campaign_html('<p>As&nbsp;novidades&nbsp;chegaram</p>'),'As novidades chegaram'),'Quill nonbreaking spaces must become normal spaces');
$preview=campaign_email_template('Novidades','<p>As&nbsp;novidades&nbsp;chegaram</p>');
$outbound=transactional_email_payload('example@example.test',$preview);
test_notice($outbound['content'][1]['value']===$preview['html'],'SendGrid must receive the exact HTML used by preview');
$image=rtrim(APP_URL,'/').'/uploads/campaigns/'.str_repeat('a',32).'.webp';
test_notice(str_contains(sanitize_campaign_html('<p>Imagem</p><img src="'.$image.'">'),$image),'Uploaded image rejected');
test_notice(str_contains(campaign_plain_text('<p><a href="https://garage64.online/collections">Coleções</a></p>'),'https://garage64.online/collections'),'Plain text must preserve link URLs');
$_SESSION=['signup_started'=>time()-3];$_POST=['website'=>'spam'];
test_notice(!signup_request_allowed(),'Honeypot bypassed');
$_POST=[];$_SESSION=['signup_started'=>time()];
test_notice(!signup_request_allowed(),'Fast submission allowed');
$_SESSION=[];test_notice(!signup_request_allowed(),'Missing form session accepted');
if(TURNSTILE_SITE_KEY==='' && TURNSTILE_SECRET_KEY==='') {
    $_SESSION=['signup_started'=>time()-3];test_notice(signup_request_allowed(),'Normal local signup rejected');
}
echo "PASS event mappings, weekly template, campaign sanitizer, plaintext links, countries and signup protection\n";
