<?php
require __DIR__ . '/core/bootstrap.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "<h1>Email Diagnostic Test</h1>";
echo "<pre>";

$to = app_env('SMTP_FROM', app_env('SMTP_USER', 'test@example.com'));
echo "Attempting to send an email to: " . htmlspecialchars($to) . "\n\n";

$brevoKey = preg_replace('/[^a-zA-Z0-9-]/', '', (string)app_env('BREVO_API_KEY'));
if (!empty($brevoKey)) {
    echo "Mode: Brevo REST API (HTTPS)\n";
    echo "Brevo API Key detected (" . strlen($brevoKey) . " chars)\n";
    
    $data = [
        'sender' => [
            'name' => app_env('SMTP_FROM_NAME', 'FITTRACKS'),
            'email' => app_env('SMTP_FROM', 'no-reply@fittracks.com')
        ],
        'to' => [['email' => $to, 'name' => 'Diagnostic Test User']],
        'subject' => 'FITTRACKS Brevo Email Test',
        'htmlContent' => '<p>If you are reading this, your Brevo email configuration is working perfectly on Render!</p>'
    ];
    
    echo "Sender: " . htmlspecialchars($data['sender']['email']) . "\n";
    echo "Recipient: " . htmlspecialchars($to) . "\n\n";
    
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
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    echo "HTTP Status Code: " . $httpCode . "\n";
    if ($curlErrno !== 0) {
        echo "cURL Error (" . $curlErrno . "): " . htmlspecialchars($curlError) . "\n";
    }
    echo "Response: " . htmlspecialchars($response) . "\n\n";
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "✅ SUCCESS: Brevo email sent successfully!\n";
    } else {
        echo "❌ ERROR: Brevo failed to send email. Check the response above.\n";
    }
} else {
    echo "Mode: Standard SMTP (PHPMailer)\n\n";
    try {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 3; 
        $mail->Debugoutput = 'html';
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        $user = $_ENV['SMTP_USER'] ?? '';
        $pass = $_ENV['SMTP_PASS'] ?? '';
        
        echo "SMTP Host: " . htmlspecialchars($mail->Host) . "\n";
        echo "SMTP Port: " . htmlspecialchars((string)($_ENV['SMTP_PORT'] ?? 587)) . "\n";
        echo "SMTP User: " . htmlspecialchars($user) . "\n";
        echo "SMTP Pass length: " . strlen($pass) . " characters\n\n";
        
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
        $mail->addAddress($to, 'Test User');

        $mail->isHTML(true);
        $mail->Subject = 'FITTRACKS Diagnostic Test';
        $mail->Body    = 'If you are reading this, your email configuration is working perfectly!';

        $mail->send();
        echo "\n\n✅ SUCCESS: Email has been sent successfully!\n";
    } catch (Exception $e) {
        echo "\n\n❌ ERROR: Message could not be sent. Mailer Error: {$mail->ErrorInfo}\n";
    } catch (Throwable $e) {
        echo "\n\n❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
    }
}

echo "</pre>";
