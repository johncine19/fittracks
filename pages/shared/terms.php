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
            <p style="color: var(--muted); font-size: 14px; margin: 0;">Last updated: <?= date('F j, Y') ?> &bull; Effective Date: October 5, 2026</p>
        </div>

        <div class="legal-content" style="color: var(--ink); line-height: 1.75; font-size: 15px; display: flex; flex-direction: column; gap: 32px;">
            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">1. Acceptance of Terms & Eligibility</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    By creating an account, accessing, or using the <strong>FitTrack</strong> software application (accessible via <strong>fitworks.tech</strong> and associated subdomains), you enter into a legally binding contract with FitTrack and agree to abide by these Terms of Service, our Privacy Policy, and all applicable laws and regulations.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    You must be at least <strong>18 years of age</strong> (or at least <strong>15 years of age</strong> with verifiable consent from a parent or legal guardian) to establish an account or purchase memberships through FitTrack.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">2. Health Disclaimer & Assumption of Risk (Liability Waiver)</h2>
                <div style="background: color-mix(in srgb, var(--danger, #ef4444) 10%, transparent); border-left: 4px solid var(--danger, #ef4444); padding: 16px; border-radius: 4px; margin-bottom: 12px;">
                    <strong style="color: var(--danger, #ef4444); display: block; margin-bottom: 4px;">Important Physical Safety & Medical Disclaimer:</strong>
                    <span style="font-size: 14px; color: var(--ink);">
                        Physical training, resistance exercise, and dietary modifications involve inherent risks of physical injury, cardiovascular distress, or property damage. You voluntarily assume all associated risks. Consult a licensed physician prior to beginning any fitness program.
                    </span>
                </div>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 6px;">
                    <li><strong>Not Medical Advice:</strong> All workout routines, training schedules, macro breakdowns, and exercise library tutorials provided by FitTrack, gym owners, or certified trainers are educational tools, not medical diagnoses or professional healthcare prescriptions.</li>
                    <li><strong>Formula Variance:</strong> Anthropometric body fat estimations (including the U.S. Navy circumference calculation) are mathematical approximations with typical margins of error (&plusmn;3% to 4%) intended solely for trend analysis.</li>
                    <li><strong>Individual Responsibility:</strong> You are solely responsible for knowing your physical limits, warming up properly, and using gym equipment safely.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">3. User Accounts, Authentication & QR Security</h2>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li><strong>Account Accuracy:</strong> You agree to provide true, accurate, and current information during registration, whether registering via email/password or third-party authentication (Google Sign-In).</li>
                    <li><strong>Credential Protection:</strong> You are responsible for safeguarding your login credentials and personal session. Notify FitTrack immediately upon discovering unauthorized account activity.</li>
                    <li><strong>Non-Transferability of QR Passes:</strong> Your digital QR check-in pass and member identity are strictly personal. Sharing, reproducing, transferring, or selling your access QR code to facilitate unauthorized admission to any partner gym facility is considered fraud and grounds for immediate termination.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">4. Subscriptions, Gym Plans & Platform Fees</h2>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li><strong>Gym Facility Subscriptions:</strong> Gym owners who utilize FitTrack to run facility operations subscribe to one of our platform tiers:
                        <ul style="margin-top: 6px; padding-left: 20px; display: flex; flex-direction: column; gap: 4px;">
                            <li><strong>Starter Tier:</strong> &#8369;499 / month</li>
                            <li><strong>Professional Tier:</strong> &#8369;999 / month</li>
                            <li><strong>Business Enterprise Tier:</strong> &#8369;1,999 / month</li>
                        </ul>
                    </li>
                    <li><strong>Platform Transaction Fee:</strong> FitTrack applies a standard <strong>1% platform transaction fee</strong> on total monthly recorded gym revenues (including processed memberships, walk-in day passes, and cash entries logged into the system).</li>
                    <li><strong>Renewal & Cancellation:</strong> Subscriptions renew automatically at the conclusion of each billing period unless cancelled through the facility subscription portal prior to the renewal date.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">5. Prohibited Conduct & Acceptable Use</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">Users agree not to:</p>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 6px;">
                    <li>Upload forged, fraudulent, or expired business permits or government identification documents.</li>
                    <li>Reverse-engineer, decompile, scrape, or extract source code or private database records from FitTrack.</li>
                    <li>Transmit malicious scripts, automated spiders, or exploit security vulnerabilities.</li>
                    <li>Harass, abuse, or send threatening communications to other members, trainers, or gym management.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">6. Intellectual Property Rights</h2>
                <p style="color: var(--muted); margin: 0;">
                    All software architecture, logos, visual designs, database schemas, workout algorithm models, and documentation comprising the FitTrack platform are the exclusive intellectual property of FitTrack. Users retain ownership of their individual progress media, while granting FitTrack a limited license to process and display such data strictly for operating the service.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">7. Disclaimer of Warranties & Limitation of Liability</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    FitTrack software is provided on an <strong>"as is" and "as available"</strong> basis without warranties of any kind, whether express or implied.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    To the fullest extent permitted by law, FitTrack and its administrators shall not be liable for any direct, indirect, incidental, punitive, or consequential damages resulting from equipment malfunctions, gym premises accidents, injuries, trainer advice, or temporary service interruptions.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">8. Governing Law & Dispute Jurisdiction</h2>
                <p style="color: var(--muted); margin: 0;">
                    These Terms of Service are governed by and construed in accordance with the <strong>laws of the Republic of the Philippines</strong>, including the <em>E-Commerce Act of 2000 (Republic Act No. 8792)</em>, the <em>Consumer Act of the Philippines (Republic Act No. 7394)</em>, and the <em>Data Privacy Act of 2012 (Republic Act No. 10173)</em>. Any judicial action or proceeding arising under these Terms shall be instituted exclusively in the proper courts of the Philippines.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">9. Modifications & Inquiries</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    We reserve the right to modify these Terms to reflect legislative changes, new features, or platform pricing updates. Notice of material revisions will be published within the application.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    For legal inquiries or questions regarding these Terms, contact us at: <a href="mailto:johncinemartil596@gmail.com" style="color: var(--lime); font-weight: 600;">johncinemartil596@gmail.com</a>.
                </p>
            </section>
        </div>
    </div>
</div>
<?php
    render_footer();
}
