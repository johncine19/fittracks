<?php
require __DIR__ . '/core/bootstrap.php';

echo "<h1>Sync Email Test</h1>";
echo "<pre>";

$to = app_env('SMTP_FROM', 'johncinemartil596@gmail.com');
echo "Sending test verification email to: $to\n";

$brevoKey = preg_replace('/[^a-zA-Z0-9-]/', '', (string)app_env('BREVO_API_KEY'));

if (empty($brevoKey)) {
    echo "Brevo Key is empty!\n";
    exit;
}

$data = [
    'sender' => ['name' => app_env('SMTP_FROM_NAME', 'FITTRACKS'), 'email' => app_env('SMTP_FROM', 'no-reply@fittracks.com')],
    'to' => [['email' => $to, 'name' => 'John Cine']],
    'subject' => 'Verify your FITTRACKS email address',
    'htmlContent' => 'This is a test verification email.'
];

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'api-key: ' . $brevoKey,
    'content-type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "cURL Error: $curlError\n";
echo "Response: " . htmlspecialchars($response) . "\n";

echo "</pre>";
