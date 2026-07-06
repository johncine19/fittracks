<?php
declare(strict_types=1);

function handle_register(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $valid = $validator->validate($_POST, [
            'first_name' => 'required|min:1|max:100',
            'last_name'  => 'required|min:1|max:100',
            'email'      => 'required|email|max:255',
            'password'   => 'required|min:8',
        ]);

        if ($valid && !is_acceptable_password((string) post('password'))) {
            $valid = false;
            flash('Password must be at least 8 characters with a letter and a number, and not be too common.', 'danger');
        } elseif (!$valid) {
            flash($validator->firstError(), 'danger');
        }

        if ($valid) {
            $existing = scalar('SELECT user_id FROM users WHERE email = ?', [post('email')]);
            if ($existing) {
                $valid = false;
                flash('An account with that email already exists.', 'danger');
            }
        }



        if ($valid) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $phone = (string) post('phone');
                if ($phone !== '') {
                    $phone = preg_replace('/[^0-9]/', '', $phone);
                    if (strlen($phone) !== 11) {
                        throw new Exception('Phone number must be exactly 11 digits.');
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status)
                     VALUES ("member", ?, ?, ?, ?, ?, "active")'
                );
                $stmt->execute([
                    post('first_name'),
                    post('last_name'),
                    post('email'),
                    password_hash((string) post('password'), PASSWORD_DEFAULT),
                    $phone ?: null,
                ]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->commit();

                // Send a verification email. Login is blocked until the member verifies.
                $emailSent = false;
                try {
                    $token = create_email_verification_token($userId);
                    $emailSent = send_verification_email((string) post('email'), (string) post('first_name'), $token);
                } catch (Throwable) {
                    // ignore — user can request a resend from the login page
                }

                $msg = $emailSent
                    ? 'Account created! Please check your email (' . post('email') . ') to verify your address before signing in.'
                    : 'Account created! We could not send a verification email right now — use the resend option on the login page.';
                flash($msg, 'success');
                redirect('login');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('Registration failed: ' . $e->getMessage(), 'danger');
            }
        }
    }

    render_header('Create account');
    ?>
    <section style="padding: 40px 0;">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1 class="auth-title">FITTRACKS</h1>
                <p class="auth-subtitle">Create your account</p>
            </div>
            <form method="post" class="auth-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;\'></span> CREATING ACCOUNT...';">
                <?= csrf_field() ?>
                <div class="auth-form-row">
                    <div class="auth-field">
                        <label>FIRST NAME</label>
                        <div class="auth-input-group">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <input name="first_name" required placeholder="First name"
                                   value="<?= h(post('first_name')) ?>"
                                   oninvalid="this.setCustomValidity('Please enter your first name.')"
                                   oninput="this.setCustomValidity('')">
                        </div>
                    </div>
                    <div class="auth-field">
                        <label>LAST NAME</label>
                        <div class="auth-input-group">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <input name="last_name" required placeholder="Last name"
                                   value="<?= h(post('last_name')) ?>"
                                   oninvalid="this.setCustomValidity('Please enter your last name.')"
                                   oninput="this.setCustomValidity('')">
                        </div>
                    </div>
                </div>

                <div class="auth-field">
                    <label>EMAIL ADDRESS</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input type="email" name="email" required placeholder="Enter your email"
                               value="<?= h(post('email')) ?>"
                               oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>

                <div class="auth-field">
                    <label>PHONE <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        <input name="phone" type="tel" maxlength="11" placeholder="09xxxxxxxxx"
                               value="<?= h(post('phone')) ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </div>
                </div>

                <div class="auth-field">
                    <label>PASSWORD</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <input type="password" name="password" required minlength="8"
                               placeholder="Min. 8 characters, with a letter and a number"
                               oninput="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : '')"
                               oninvalid="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : 'Please enter a password.')">
                    </div>
                </div>

                <div class="auth-field">
                    <p class="muted" style="font-size:12px;margin:-4px 0 0;">Must include at least one letter and one number.</p>
                </div>

                <button type="submit" class="auth-submit-btn">CREATE ACCOUNT <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

                <div class="auth-form-footer" style="display: flex; flex-direction: column; gap: 10px;">
                    <div>Already have an account? <a href="index.php?page=login">Sign in</a></div>
                    <div style="margin-top: 5px;">
                        <a href="index.php" style="color: var(--muted); text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 5px; transition: color 0.2s;" onmouseover="this.style.color='var(--lime)'" onmouseout="this.style.color='var(--muted)'">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Back to Home
                        </a>
                    </div>
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
