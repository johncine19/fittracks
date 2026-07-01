<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handle_forgot_password(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = post('email');

        if (RateLimiter::tooManyAttempts('forgot:' . $email, 3, 600)) {
            flash('Too many reset requests for this email. Please wait a few minutes and try again.', 'danger');
            redirect('forgot_password');
        }
        RateLimiter::hit('forgot:' . $email, 600);

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Block password reset for unverified members
            if ($user['role'] === 'member' && empty($user['email_verified_at'])) {
                flash('Please verify your email address before resetting your password. Check your inbox or sign in to request a new verification email.', 'danger');
                redirect('forgot_password');
            }

            $token = sprintf('%06d', random_int(0, 999999));
            db()->prepare('REPLACE INTO password_resets (email, token) VALUES (?, ?)')->execute([$email, $token]);

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USER'] ?? '';
                $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

                $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset OTP';
                $mail->Body    = "Your password reset OTP is <strong>$token</strong>. Please enter this code on the reset page.";

                $mail->send();
                $_SESSION['reset_email'] = $email;
                flash('An OTP has been sent to your email address.', 'success');
                redirect('reset_password');
            } catch (Exception $e) {
                flash("Message could not be sent. Mailer Error: {$mail->ErrorInfo}", 'danger');
                redirect('forgot_password');
            }
        } else {
            flash('No account found with that email address. Please register first.', 'danger');
            redirect('forgot_password');
        }
    }

    render_header('Forgot Password');
    ?>
    <section style="padding: 40px 0;">
        <div class="gilded-container">
            <div class="gilded-header">
                <h1 class="gilded-title">FITTRACKS</h1>
                <p class="gilded-subtitle">Reset your passcode</p>
            </div>
            <form method="post" class="gilded-form">
                <?= csrf_field() ?>
                <div class="gilded-field">
                    <label>EMAIL ADDRESS</label>
                    <div class="gilded-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input type="email" name="email" required placeholder="Enter your registered email">
                    </div>
                </div>

                <button type="submit" class="gilded-btn">SEND RESET LINK <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

                <div class="gilded-footer" style="margin-top: 15px;">
                    Remember your passcode? <a href="index.php?page=login">Sign In</a>
                </div>
            </form>
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <?php
    render_footer();
}
