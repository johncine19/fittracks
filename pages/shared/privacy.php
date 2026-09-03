<?php
declare(strict_types=1);

function privacy_page(): void
{
    $user = current_user();
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    render_header('Privacy Policy', $user);
?>
<div class="legal-page-container" style="max-width: 860px; margin: 40px auto; padding: 0 20px 80px;">
    <div style="margin-bottom: 24px;">
        <a href="javascript:history.back()" style="display: inline-flex; align-items: center; gap: 8px; color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s;">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back
        </a>
    </div>

    <div class="panel" style="background: var(--bg-surface, var(--bg)); border: 1px solid var(--line); border-radius: 16px; padding: clamp(24px, 5vw, 48px); box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
        <div style="border-bottom: 1px solid var(--line); padding-bottom: 24px; margin-bottom: 32px;">
            <span style="font-family: var(--font-mono, monospace); font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: var(--lime); font-weight: 600;">DATA PRIVACY & PROTECTION</span>
            <h1 style="font-size: clamp(2rem, 3.5vw, 2.75rem); font-weight: 800; color: var(--ink); margin: 8px 0 12px; letter-spacing: -0.02em;">Privacy Policy</h1>
            <p style="color: var(--muted); font-size: 14px; margin: 0;">Last updated: <?= date('F j, Y') ?></p>
        </div>

        <div class="legal-content" style="color: var(--ink); line-height: 1.7; font-size: 15px; display: flex; flex-direction: column; gap: 28px;">
            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">1. Introduction & Scope</h2>
                <p style="color: var(--muted); margin: 0;">
                    At <strong>FitTrack</strong>, your privacy and data security are paramount. This Privacy Policy describes our practices concerning the collection, use, disclosure, and safeguarding of your personal and health-related data in compliance with the <em>Philippine Data Privacy Act of 2012 (RA 10173)</em> and relevant international privacy frameworks.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">2. Information We Collect</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Personal Account Data:</strong> Name, email address, phone number, role, profile picture, and encrypted password.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Sensitive Health & Body Metrics:</strong> Height, body measurements (neck, waist, hip circumference), weight logs, estimated body fat percentage, workout logs (sets, reps, weights), and completed exercises.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Attendance & Check-In Records:</strong> Timestamped QR scans, gym visits, and class bookings.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • <strong>Gym Business Information:</strong> Gym names, addresses, business permit URLs, and valid identification documents uploaded for facility verification.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">3. How We Use Your Information</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • To personalize exercise routines, training milestones, and track body composition trends over time.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • To calculate your gym <strong>Engagement Score</strong> (0–100) and alert your trainer if activity levels decline.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • To enable real-time coaching communications between you and certified trainers assigned to your plan.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • To manage class schedules, verify memberships, process renewals, and manage turnstile QR admissions.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">4. Data Sharing & Disclosure</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Coaches & Gym Staff:</strong> Your assigned trainer and authorized gym management personnel can view your workout progress, attendance, and fitness metrics to deliver coaching services.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>No Data Selling:</strong> We do <strong>never</strong> sell, rent, or trade your personal or health data to advertisers or third-party brokers.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • <strong>Legal Compliance:</strong> Information may be disclosed if strictly required by law or lawful court subpoenas.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">5. Data Storage & Security</h2>
                <p style="color: var(--muted); margin: 0;">
                    We implement industry-standard cryptographic techniques, including bcrypt password hashing, SSL/TLS data transmission encryption, session timeouts, and rate limiting to protect your information against unauthorized access, loss, or alteration.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">6. Your Rights</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    Under applicable data protection laws, you possess the right to:
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • Access and view all your stored personal and fitness logs.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • Request correction or updates to inaccurate profile data.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • Request account deactivation or deletion of your personal records.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">7. Privacy Questions & Data Protection Officer</h2>
                <p style="color: var(--muted); margin: 0;">
                    If you have questions about this Privacy Policy or wish to exercise your privacy rights, please reach out to our team at <a href="mailto:johncinemartil596gmail.com" style="color: var(--lime); text-decoration: underline;">privacy@fittrack.com</a>.
                </p>
            </section>
        </div>
    </div>
</div>
<?php
    render_footer();
}
