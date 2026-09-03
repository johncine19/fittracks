<?php
declare(strict_types=1);

function terms_page(): void
{
    $user = current_user();
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    render_header('Terms of Service', $user);
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
            <span style="font-family: var(--font-mono, monospace); font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: var(--lime); font-weight: 600;">LEGAL AGREEMENT</span>
            <h1 style="font-size: clamp(2rem, 3.5vw, 2.75rem); font-weight: 800; color: var(--ink); margin: 8px 0 12px; letter-spacing: -0.02em;">Terms of Service</h1>
            <p style="color: var(--muted); font-size: 14px; margin: 0;">Last updated: <?= date('F j, Y') ?></p>
        </div>

        <div class="legal-content" style="color: var(--ink); line-height: 1.7; font-size: 15px; display: flex; flex-direction: column; gap: 28px;">
            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">1. Acceptance of Terms</h2>
                <p style="color: var(--muted); margin: 0;">
                    By creating an account, accessing, or using the <strong>FitTrack</strong> platform (web and mobile interfaces), you confirm that you have read, understood, and agreed to be bound by these Terms of Service. If you do not agree to these terms, you may not register or use FitTrack services.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">2. Health & Physical Activity Disclaimer (Liability Waiver)</h2>
                <div style="background: color-mix(in srgb, var(--danger, #ef4444) 10%, transparent); border-left: 4px solid var(--danger, #ef4444); padding: 16px; border-radius: 4px; margin-bottom: 12px;">
                    <strong style="color: var(--danger, #ef4444); display: block; margin-bottom: 4px;">Important Health Warning:</strong>
                    <span style="font-size: 14px; color: var(--ink);">
                        Engaging in physical exercise involves inherent risks of bodily injury. You should consult a physician prior to initiating any training plan or dietary regimen.
                    </span>
                </div>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Not Medical Advice:</strong> Workout templates, exercise libraries, nutritional recommendations, and coach guidance provided through FitTrack are strictly for educational and fitness informational purposes, not clinical or medical advice.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • <strong>Body Fat Estimation Disclaimer:</strong> Any body composition analysis calculated using the U.S. Navy circumference method carries a practical margin of error (&plusmn;3% to 4%) and serves only as a relative trend metric.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">3. User Accounts & Security</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • You must provide accurate, complete, and current information during registration.
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • You are solely responsible for maintaining the confidentiality of your credentials and QR attendance codes.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • Transferring, selling, or sharing individual member accounts or turnstile QR passes is strictly prohibited.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">4. Gym Owner Subscriptions & Platform Fees</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>Subscription Tiers:</strong> Gyms subscribing to FitTrack agree to the scheduled monthly fees corresponding to their selected tier:
                    <strong>Starter</strong> (&#8369;499/mo), <strong>Professional</strong> (&#8369;999/mo), or <strong>Business</strong> (&#8369;1,999/mo).
                </p>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    • <strong>1% Platform Fee:</strong> FitTrack collects a non-negotiable 1% platform transaction fee on total monthly recorded gym revenues (including online payments, walk-ins, and cash payments processed in the system).
                </p>
                <p style="color: var(--muted); margin: 0;">
                    • <strong>Billing & Renewal:</strong> Subscriptions renew automatically at the end of each billing cycle unless cancelled prior to the renewal date.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">5. Member Attendance & Facility Rules</h2>
                <p style="color: var(--muted); margin: 0;">
                    Members agree to adhere to all rules, safety protocols, and codes of conduct established by the gym facility they attend. FitTrack and partner gym facilities reserve the right to suspend access for abusive behavior, non-payment, or violation of safety guidelines.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">6. Limitation of Liability</h2>
                <p style="color: var(--muted); margin: 0;">
                    To the maximum extent permitted by applicable law, FitTrack, its officers, employees, and software providers shall not be liable for any direct, indirect, incidental, or consequential damages resulting from injuries sustained during gym activities or reliance on training routines.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">7. Contact & Inquiries</h2>
                <p style="color: var(--muted); margin: 0;">
                    For questions regarding these Terms of Service, please contact our support team at <a href="mailto:johncinemartil596@gmail.com" style="color: var(--lime); text-decoration: underline;">johncinemartil596@gmail.com</a>.
                </p>
            </section>
        </div>
    </div>
</div>
<?php
    render_footer();
}
