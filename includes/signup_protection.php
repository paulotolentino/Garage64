<?php
function signup_request_allowed(): bool {
    if (!empty($_POST['website']) || empty($_SESSION['signup_started']) || time() - (int)$_SESSION['signup_started'] < 2) return false;
    if (TURNSTILE_SITE_KEY === '' && TURNSTILE_SECRET_KEY === '') return true;
    if (TURNSTILE_SITE_KEY === '' || TURNSTILE_SECRET_KEY === '' || !function_exists('curl_init')) return false;
    $token=(string)($_POST['cf-turnstile-response'] ?? '');
    if ($token==='' || strlen($token)>2048) return false;
    $curl=curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['secret'=>TURNSTILE_SECRET_KEY,'response'=>$token,'remoteip'=>client_ip()]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8]);
    $response=curl_exec($curl);curl_close($curl);
    $data=$response ? json_decode($response,true) : null;
    return !empty($data['success']) && ($data['action'] ?? '')==='signup' && strtolower($data['hostname'] ?? '')===strtolower((string)parse_url(APP_URL,PHP_URL_HOST));
}
