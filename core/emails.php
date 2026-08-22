<?php
declare(strict_types=1);

final class Emails
{
    private static function layout(string $content): string
    {
        $year = date('Y');
        $baseUrl = app_base_url();
        $logoUrl = $baseUrl . '/assets/images/fittrack-logo.png'; // Fallback logo

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #0b110e; color: #ffffff; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #0b110e; padding-bottom: 60px; }
        .main { margin: 0 auto; width: 100%; max-width: 600px; background-color: #121c17; border-radius: 8px; overflow: hidden; margin-top: 40px; border: 1px solid rgba(255, 255, 255, 0.05); }
        .header { padding: 30px; text-align: center; background-color: #18251e; border-bottom: 2px solid #c7ff22; }
        .header img { max-height: 40px; }
        .header h1 { margin: 15px 0 0 0; font-size: 24px; color: #c7ff22; letter-spacing: 1px; }
        .content { padding: 40px 30px; line-height: 1.6; font-size: 16px; color: #e2e8f0; }
        .content p { margin: 0 0 20px 0; }
        .content a { color: #c7ff22; text-decoration: none; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #c7ff22; color: #0b110e !important; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 10px; }
        .footer { text-align: center; padding: 30px; font-size: 13px; color: #64748b; background-color: #0b110e; }
        .code-block { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 4px; font-family: monospace; font-size: 20px; letter-spacing: 2px; text-align: center; margin: 20px 0; border: 1px solid rgba(199, 255, 34, 0.2); color: #c7ff22; }
    </style>
</head>
<body>
    <table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="main" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    <tr>
                        <td class="header">
                            <h1>FITTRACKS</h1>
                        </td>
                    </tr>
                    <tr>
                        <td class="content">
                            {$content}
                        </td>
                    </tr>
                </table>
                <div class="footer">
                    &copy; {$year} FITTRACKS. All rights reserved.
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    public static function sendVerification(string $email, string $firstName, string $token): void
    {
        $link = app_base_url() . '?page=verify_email&token=' . urlencode($token);
        
        $content = <<<HTML
        <p>Hi <strong>{$firstName}</strong>,</p>
        <p>Welcome to FITTRACKS! To complete your registration and secure your account, please verify your email address by clicking the button below:</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$link}" class="btn">Verify Email Address</a>
        </p>
        <p>This link will expire in 24 hours.</p>
        <p style="font-size: 14px; color: #94a3b8; margin-top: 30px;">If you didn't create an account, you can safely ignore this email.</p>
HTML;

        queue_email($email, $firstName, 'Verify your FITTRACKS email address', self::layout($content));
    }

    public static function sendPasswordReset(string $email, string $firstName, string $token): void
    {
        $content = <<<HTML
        <p>Hi <strong>{$firstName}</strong>,</p>
        <p>We received a request to reset the password for your FITTRACKS account. Here is your One-Time Password (OTP):</p>
        <div class="code-block">{$token}</div>
        <p>Please enter this code on the reset page. This code will expire in 15 minutes.</p>
        <p style="font-size: 14px; color: #94a3b8; margin-top: 30px;">If you didn't request a password reset, you can safely ignore this email. Your password will not be changed.</p>
HTML;

        queue_email($email, $firstName, 'Password Reset OTP - FITTRACKS', self::layout($content));
    }

    public static function sendAccountCreated(string $email, string $name, string $plainPassword): void
    {
        $loginUrl = app_base_url() . '?page=login';
        $content = <<<HTML
        <p>Hi <strong>{$name}</strong>,</p>
        <p>An administrator has created a new FITTRACKS account for you. Below are your temporary login credentials:</p>
        <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 0 0 10px 0;"><strong>Email:</strong> {$email}</p>
            <p style="margin: 0;"><strong>Password:</strong> {$plainPassword}</p>
        </div>
        <p>For your security, please sign in and change your password as soon as possible.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$loginUrl}" class="btn">Sign In to FITTRACKS</a>
        </p>
HTML;

        queue_email($email, $name, 'Your FITTRACKS account has been created', self::layout($content));
    }

    public static function sendPaymentConfirmation(string $email, string $name, string $planName): void
    {
        $dashboardUrl = app_base_url() . '?page=dashboard';
        $content = <<<HTML
        <p>Hi <strong>{$name}</strong>,</p>
        <p>Great news! Your payment for the <strong>{$planName}</strong> membership was successfully processed.</p>
        <p>Your membership is now <strong>ACTIVE</strong>. You can now start booking classes and communicating with trainers.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$dashboardUrl}" class="btn">Go to Dashboard</a>
        </p>
        <p>We look forward to seeing you at the gym!</p>
HTML;

        queue_email($email, $name, 'Payment Confirmation - FITTRACKS', self::layout($content));
    }

    public static function sendNewGymApplication(string $gymName, string $ownerName): void
    {
        $appUrl = app_base_url() . '?page=gym_applications';
        $content = <<<HTML
        <p>Hello Platform Admin,</p>
        <p>A new gym application has been submitted and is waiting for your review.</p>
        <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 0 0 10px 0;"><strong>Gym Name:</strong> {$gymName}</p>
            <p style="margin: 0;"><strong>Owner:</strong> {$ownerName}</p>
        </div>
        <p>Please log in to the platform admin dashboard to review the business permit and approve or reject the application.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$appUrl}" class="btn">Review Application</a>
        </p>
HTML;

        notify_admins_email('New Gym Application: ' . $gymName, self::layout($content));
    }

    public static function sendGymApplicationSubmitted(string $email, string $ownerName, string $gymName): void
    {
        $loginUrl = app_base_url() . '?page=login';
        $content = <<<HTML
        <p>Hi <strong>{$ownerName}</strong>,</p>
        <p>Thank you for submitting your gym registration for <strong>{$gymName}</strong> on FITTRACKS!</p>
        <p>We have successfully received your gym information and submitted documents (Business Permit and Valid ID). Your application is now <strong>under review</strong> by our platform administration team.</p>
        <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 4px; margin: 20px 0;">
            <p style="margin: 0 0 10px 0;"><strong>Gym Name:</strong> {$gymName}</p>
            <p style="margin: 0;"><strong>Status:</strong> Under Review (1-2 business days)</p>
        </div>
        <p>You will receive an email notification as soon as your account is approved or if additional information is required.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$loginUrl}" class="btn">Check Application Status</a>
        </p>
        <p>Thank you for partnering with FITTRACKS!</p>
HTML;

        queue_email($email, $ownerName, 'Gym Registration Being Processed - FITTRACKS', self::layout($content));
    }

    public static function sendGymApplicationApproved(string $email, string $ownerName, string $gymName): void
    {
        $dashboardUrl = app_base_url() . '?page=dashboard';
        $content = <<<HTML
        <p>Hi <strong>{$ownerName}</strong>,</p>
        <p>Congratulations! Your application for <strong>{$gymName}</strong> has been approved by the platform administration.</p>
        <p>Your Gym Owner account is now fully active. You can now log in to the platform to customize your gym profile, add memberships, and manage trainers.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{$dashboardUrl}" class="btn">Go to Dashboard</a>
        </p>
        <p>Welcome aboard!</p>
HTML;

        queue_email($email, $ownerName, 'Gym Application Approved! - FITTRACKS', self::layout($content));
    }

    public static function sendGymApplicationRejected(string $email, string $ownerName, string $gymName): void
    {
        $content = <<<HTML
        <p>Hi <strong>{$ownerName}</strong>,</p>
        <p>We regret to inform you that your application for <strong>{$gymName}</strong> has been rejected by the platform administration.</p>
        <p>This may be due to missing or invalid documentation (such as your Business Permit or Valid ID) or a failure to meet our platform requirements.</p>
        <p>If you believe this was a mistake, or if you would like to appeal this decision and provide new documentation, please contact our support team.</p>
        <p>Thank you for your interest in FITTRACKS.</p>
HTML;

        queue_email($email, $ownerName, 'Gym Application Update - FITTRACKS', self::layout($content));
    }
}
