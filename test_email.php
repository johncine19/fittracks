<?php
require __DIR__ . '/core/bootstrap.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "<h1>SMTP Email Diagnostic Test</h1>";
echo "<pre>";

$to = $_ENV['SMTP_USER'] ?? 'test@example.com';
echo "Attempting to send an email to: " . htmlspecialchars($to) . "\n\n";

try {
    $mail = new PHPMailer(true);
    
    // Enable verbose debug output
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

echo "</pre>";
