<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_templates.php';

function send_account_email(string $email, string $name, string $purpose, string $url): bool {
    return send_transactional_email($email, account_email_template($purpose, $name, $url));
}

function transactional_email_payload(string $email, array $template): array {
    return [
        'personalizations' => [['to' => [['email' => $email]]]],
        'from' => ['email' => MAIL_FROM_EMAIL, 'name' => MAIL_FROM_NAME],
        'subject' => $template['subject'],
        'content' => [['type' => 'text/plain', 'value' => $template['text']], ['type' => 'text/html', 'value' => $template['html']]],
        'tracking_settings' => ['click_tracking' => ['enable' => false, 'enable_text' => false], 'open_tracking' => ['enable' => false]],
    ];
}

function send_transactional_email(string $email, array $template): bool {
    if (SENDGRID_API_KEY === '' || !function_exists('curl_init')) {
        error_log('Garage64 email: configure SENDGRID_API_KEY and enable curl.');
        return false;
    }
    $payload = transactional_email_payload($email, $template);
    $curl = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SENDGRID_API_KEY, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false || $status !== 202) {
        // Never log the API key, token URL, recipient or response body.
        error_log('Garage64 email: SendGrid HTTP ' . $status);
        return false;
    }
    return true;
}
