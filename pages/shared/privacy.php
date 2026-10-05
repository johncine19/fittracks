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
            <p style="color: var(--muted); font-size: 14px; margin: 0;">Last updated: <?= date('F j, Y') ?> &bull; Effective Date: October 5, 2026</p>
        </div>

        <div class="legal-content" style="color: var(--ink); line-height: 1.75; font-size: 15px; display: flex; flex-direction: column; gap: 32px;">
            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">1. Introduction & Statutory Framework</h2>
                <p style="color: var(--muted); margin: 0;">
                    At <strong>FitTrack</strong> (operated under <strong>fitworks.tech</strong>), we respect your fundamental right to privacy and are committed to safeguarding your personal and health-related information. This Privacy Policy outlines our standards and procedures regarding the collection, processing, storage, disclosure, and deletion of personal data in strict adherence to the <em>Philippine Data Privacy Act of 2012 (Republic Act No. 10173)</em>, its Implementing Rules and Regulations (IRR), and recognized international privacy standards including the <em>General Data Protection Regulation (GDPR)</em>.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">2. Information We Collect</h2>
                <p style="color: var(--muted); margin-bottom: 12px;">We collect information you directly provide when registering an account, utilizing gym facilities, recording fitness progress, or managing a gym facility:</p>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li><strong>Personal Account Identifiers:</strong> Legal full name, email address, mobile phone number, assigned role (member, trainer, gym owner, administrator), encrypted password, and uploaded profile pictures.</li>
                    <li><strong>Sensitive Physical & Health Metrics:</strong> Height, body weight, anthropometric measurements (neck, waist, hip, chest, arm circumferences), estimated body fat percentage, workout history (sets, repetitions, resistance weight, RPE), dietary and macro logs, and physical training goals.</li>
                    <li><strong>Facility Attendance & Access Records:</strong> Timestamped QR code check-in and check-out logs, gym turnstile records, equipment usage queues, and group fitness class reservations.</li>
                    <li><strong>Gym Business Verification Data:</strong> For gym owners: facility trade name, physical address, local government business permits, Bureau of Internal Revenue (BIR) registration details, and government-issued identification cards submitted for facility validation.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">3. Google OAuth & Third-Party Authentication Policy</h2>
                <div style="background: rgba(132, 204, 22, 0.08); border-left: 4px solid var(--lime); padding: 16px; border-radius: 6px; margin-bottom: 12px;">
                    <strong style="color: var(--lime); display: block; margin-bottom: 4px;">Google API Services User Data Compliance:</strong>
                    <span style="font-size: 14px; color: var(--ink);">
                        FitTrack complies with the <em>Google API Services User Data Policy</em>, including the Limited Use requirements.
                    </span>
                </div>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    When you choose to register or sign in to FitTrack using your Google account (via Google Identity Services):
                </p>
                <ul style="color: var(--muted); margin: 0 0 12px; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li><strong>Scopes Requested:</strong> We request access only to basic profile information: your Google unique user identifier (<code style="background: var(--line); padding: 2px 6px; border-radius: 4px;">sub</code>), verified email address, and display name.</li>
                    <li><strong>Purpose of Processing:</strong> Google account data is processed solely for verifying your identity, authenticating your session, and establishing your user profile on FitTrack.</li>
                    <li><strong>No Data Selling or Advertising:</strong> Google user data is <strong>never</strong> sold, rented, monetized, transferred to data brokers, or utilized for serving targeted advertisements.</li>
                    <li><strong>No Unauthorized AI Training:</strong> FitTrack does not transfer Google user data to generalized third-party artificial intelligence or machine learning models without your explicit, separate consent.</li>
                    <li><strong>Revocation of Access:</strong> You can revoke FitTrack's access to your Google account at any time by visiting your <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener noreferrer" style="color: var(--lime); text-decoration: underline;">Google Account Security Permissions</a>.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">4. Processing of Sensitive Health Data & Explicit Consent</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    In compliance with <strong>Section 13 of Republic Act No. 10173</strong> and <strong>Article 9 of the GDPR</strong>, processing of physical measurements and fitness performance metrics requires explicit consent.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    By entering body metrics, workout completions, or nutritional records into FitTrack, you explicitly grant consent for FitTrack and your designated gym trainers to process this information solely to calculate body composition estimates (e.g., U.S. Navy circumference calculations), analyze engagement scores, structure customized training programs, and monitor your personal fitness trajectory.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">5. Data Sharing & Third-Party Service Providers</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">We do not trade, sell, or rent your personal data. We disclose your information only under the following controlled conditions:</p>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li><strong>Affiliated Gym Facilities & Coaches:</strong> Authorized staff and assigned trainers at your registered gym facility can view your workout activity, check-in history, and fitness benchmarks to deliver instruction and ensure member safety.</li>
                    <li><strong>Essential Infrastructure Sub-processors:</strong> We partner with trusted enterprise cloud vendors bound by strict data processing confidentiality agreements:
                        <ul style="margin-top: 6px; padding-left: 20px; display: flex; flex-direction: column; gap: 4px;">
                            <li><em>Cloudinary & ImageKit:</em> For secure, encrypted storage and optimization of profile pictures and facility documents.</li>
                            <li><em>Google Cloud Platform:</em> For OAuth 2.0 user authentication and token verification.</li>
                            <li><em>Transactional Mail Providers (SMTP / Brevo):</em> Strictly for delivering essential account alerts, password resets, and verification messages.</li>
                        </ul>
                    </li>
                    <li><strong>Legal & Statutory Mandate:</strong> Where disclosure is necessary to comply with valid legal processes, national court subpoenas, or lawful government orders.</li>
                </ul>
            </section>

            <section id="security">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">6. Data Security Measures</h2>
                <p style="color: var(--muted); margin: 0;">
                    We enforce organizational, physical, and technical controls to safeguard personal data against accidental or unlawful destruction, alteration, unauthorized disclosure, or access. These protections include <strong>TLS/SSL transport encryption</strong>, <strong>Bcrypt cryptographic password hashing</strong>, strict <strong>Content Security Policies (CSP)</strong>, <strong>CSRF token validation</strong>, automated <strong>IP rate-limiting</strong>, and restricted role-based database permissions.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">7. Data Retention & Account Deletion (Right to Erasure)</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    We retain your personal data only as long as your FitTrack account remains active or as needed to provide you with gym management and tracking services. Financial and transaction logs are retained for the minimum statutory period required by Philippine tax regulations.
                </p>
                <p style="color: var(--muted); margin: 0;">
                    You have the right to request the permanent deletion of your account and associated personal data. Upon receiving a verified deletion request via email, we will purge or anonymize your personal identifiers, Google authentication tokens, and private health logs within <strong>thirty (30) business days</strong>, subject to statutory retention obligations.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">8. Your Statutory Rights</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">Under RA 10173 and international privacy laws, you are entitled to:</p>
                <ul style="color: var(--muted); margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 6px;">
                    <li><strong>Right to be Informed:</strong> Receive clear details regarding how your information is being collected and processed.</li>
                    <li><strong>Right to Access:</strong> View and request copies of personal information held in our records.</li>
                    <li><strong>Right to Rectification:</strong> Contest and correct inaccurate or incomplete personal records.</li>
                    <li><strong>Right to Erasure or Blocking:</strong> Request withdrawal, removal, or blocking of data processed unlawfully or no longer necessary.</li>
                    <li><strong>Right to Data Portability:</strong> Obtain an electronic copy of your training and attendance logs.</li>
                </ul>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">9. Age Restriction & Protection of Minors</h2>
                <p style="color: var(--muted); margin: 0;">
                    FitTrack is intended for individuals aged <strong>18 years or older</strong>. Minors who are at least <strong>15 years of age</strong> may register and use the platform only with the verified consent and supervision of a parent or legal guardian. We do not knowingly collect personal or health information from children under 15 years old.
                </p>
            </section>

            <section>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--ink); margin-bottom: 10px;">10. Contact Information & Data Protection Officer</h2>
                <p style="color: var(--muted); margin-bottom: 8px;">
                    For questions, concerns, or to exercise your privacy rights, please contact our Data Protection Office:
                </p>
                <div style="background: var(--line); padding: 14px 18px; border-radius: 8px; font-size: 14px; color: var(--ink);">
                    <div><strong>FitTrack Data Privacy Office</strong></div>
                    <div>Email: <a href="mailto:johncinemartil596@gmail.com" style="color: var(--lime); font-weight: 600;">johncinemartil596@gmail.com</a></div>
                    <div>Domain: <a href="https://fitworks.tech" style="color: var(--lime);">https://fitworks.tech</a></div>
                    <div style="margin-top: 6px; font-size: 13px; color: var(--muted);">If you believe your privacy rights have been infringed, you also have the right to lodge a complaint with the <em>National Privacy Commission (NPC)</em> of the Philippines at <a href="https://privacy.gov.ph" target="_blank" rel="noopener noreferrer" style="color: var(--lime);">privacy.gov.ph</a>.</div>
                </div>
            </section>
        </div>
    </div>
</div>
<?php
    render_footer();
}
