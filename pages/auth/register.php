<?php
declare(strict_types=1);

function handle_register(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $validator = new Validator();
        $rules = [
            'account_type' => 'required',
            'first_name' => 'required|min:1|max:100',
            'last_name'  => 'required|min:1|max:100',
            'email'      => 'required|email|max:255',
            'password'   => 'required|min:8',
        ];

        if (post('account_type') === 'gym_owner') {
            // Gym owners will fill these out in the onboarding wizard
        }

        $valid = $validator->validate($_POST, $rules);

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

                $role = (post('account_type') === 'gym_owner') ? 'gym_owner' : 'member';

                $stmt = $pdo->prepare(
                    'INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status)
                     VALUES (?, ?, ?, ?, ?, ?, "active")'
                );
                $stmt->execute([
                    $role,
                    post('first_name'),
                    post('last_name'),
                    post('email'),
                    password_hash((string) post('password'), PASSWORD_DEFAULT),
                    $phone ?: null,
                ]);
                $userId = (int) $pdo->lastInsertId();

                if ($role === 'gym_owner') {
                    require_once __DIR__ . '/../../core/file_handler.php';
                    $permitFilename = null;
                    if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
                        $permitFilename = FileUpload::storeBusinessPermit($_FILES['business_permit'], $userId);
                    } else {
                        throw new Exception('Business permit is required for gym owners.');
                    }

                    $validIdFilename = null;
                    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                        $validIdFilename = FileUpload::storeValidId($_FILES['valid_id'], $userId);
                    } else {
                        throw new Exception('Valid ID is required for gym owners.');
                    }

                    $gymStmt = $pdo->prepare(
                        'INSERT INTO gyms (owner_user_id, name, address, contact_info, business_permit_url, valid_id_url, status)
                         VALUES (?, ?, ?, ?, ?, ?, "pending")'
                    );
                    $gymStmt->execute([
                        $userId,
                        post('gym_name'),
                        post('gym_address'),
                        post('gym_contact_info'),
                        $permitFilename,
                        $validIdFilename
                    ]);
                }

                $pdo->commit();

                if ($role === 'gym_owner') {
                    $gymName = post('gym_name');
                    $ownerName = post('first_name') . ' ' . post('last_name');
                    notify_admins('system', 'New Gym Application', "A new gym application for '{$gymName}' was submitted by {$ownerName}. Please review it in the Gym Applications dashboard.");
                    
                    $emailBody = "Hello Platform Admin,<br><br>A new gym application has been submitted and is waiting for your approval.<br><br><b>Gym Name:</b> " . htmlspecialchars((string)$gymName, ENT_QUOTES) . "<br><b>Owner:</b> " . htmlspecialchars((string)$ownerName, ENT_QUOTES) . "<br><br>Please log in to the platform admin dashboard to review the business permit and approve or reject the application.<br><br>Thanks,<br>FITTRACKS System";
                    notify_admins_email('New Gym Application: ' . $gymName, $emailBody);
                }

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
            <form method="post" class="auth-form" enctype="multipart/form-data" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class=\'loader\' style=\'width:16px;height:16px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:8px;\'></span> CREATING ACCOUNT...';">
                <?= csrf_field() ?>
                
                <div class="auth-field" style="margin-bottom: 20px;">
                    <label>I AM A:</label>
                    <div style="display: flex; gap: 20px; margin-top: 10px;">
                        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer; text-transform: none; letter-spacing: normal;">
                            <input type="radio" name="account_type" value="member" checked onchange="toggleGymFields()">
                            Member
                        </label>
                        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer; text-transform: none; letter-spacing: normal;">
                            <input type="radio" name="account_type" value="gym_owner" onchange="toggleGymFields()">
                            Gym Owner
                        </label>
                    </div>
                </div>

                <script>
                    function toggleGymFields() {
                        // Registration is now streamlined. Gym owners will complete setup in the onboarding wizard.
                    }
                </script>

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
                        <input type="password" name="password" id="register_password" required minlength="8"
                               placeholder="Min. 8 characters, with a letter and a number"
                               oninput="checkPasswordStrength(this.value)">
                    </div>
                </div>

                <div class="auth-field">
                    <div style="display:flex; gap: 4px; margin-top: 4px; margin-bottom: 4px;">
                        <div id="pw-str-1" style="height: 4px; flex: 1; border-radius: 2px; background: var(--line);"></div>
                        <div id="pw-str-2" style="height: 4px; flex: 1; border-radius: 2px; background: var(--line);"></div>
                        <div id="pw-str-3" style="height: 4px; flex: 1; border-radius: 2px; background: var(--line);"></div>
                        <div id="pw-str-4" style="height: 4px; flex: 1; border-radius: 2px; background: var(--line);"></div>
                    </div>
                    <p id="pw-str-text" class="muted" style="font-size:12px;margin:0;">Must include at least one letter and one number.</p>
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
    <script>
    function checkPasswordStrength(pw) {
        const input = document.getElementById('register_password');
        let strength = 0;
        let msg = 'Must include at least one letter and one number.';
        
        if (pw.length >= 8) strength++;
        if (/[A-Za-z]/.test(pw) && /[0-9]/.test(pw)) strength++;
        if (pw.length >= 12) strength++;
        if (/[^A-Za-z0-9]/.test(pw)) strength++;
        
        if (pw.length === 0) strength = 0;

        const colors = ['var(--line)', '#ff4d5d', '#ff9548', '#facc15', 'var(--lime)'];
        const text = ['Must include at least one letter and one number.', 'Weak', 'Fair', 'Good', 'Strong'];
        
        for (let i = 1; i <= 4; i++) {
            document.getElementById('pw-str-' + i).style.background = (strength >= i) ? colors[strength] : 'var(--line)';
        }
        
        document.getElementById('pw-str-text').innerText = text[strength] || text[0];
        document.getElementById('pw-str-text').style.color = (strength > 0) ? colors[strength] : 'var(--muted)';
        
        if (strength < 2 && pw.length > 0) {
            input.setCustomValidity('Password is too weak. Please follow the guidelines.');
        } else {
            input.setCustomValidity('');
        }
    }
    </script>
    <?php
    render_footer();
}
