<?php
declare(strict_types=1);

function gym_onboarding_page(): void
{
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
    }

    $pdo = db();
    // Check if they already have a gym
    $gym = $pdo->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    if ($gym) {
        if ($gym['status'] === 'pending') {
            redirect('gym_pending');
        } elseif ($gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } else {
            redirect('dashboard');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $gymName = trim((string) post('gym_name'));
        $gymAddress = trim((string) post('gym_address'));
        $gymContact = trim((string) post('gym_contact_info'));

        if (!$gymName || !$gymAddress || !$gymContact) {
            flash('All fields are required.', 'danger');
        } else {
            require_once __DIR__ . '/../../core/file_handler.php';
            
            try {
                $permitFilename = null;
                if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
                    $permitFilename = FileUpload::storeBusinessPermit($_FILES['business_permit'], (int)$user['user_id']);
                } else {
                    throw new Exception('Business permit is required.');
                }

                $validIdFilename = null;
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $validIdFilename = FileUpload::storeValidId($_FILES['valid_id'], (int)$user['user_id']);
                } else {
                    throw new Exception('Valid ID is required.');
                }

                $stmt = $pdo->prepare('INSERT INTO gyms (owner_user_id, name, address, contact_info, permit_url, id_url, status) VALUES (?, ?, ?, ?, ?, ?, "pending")');
                $stmt->execute([
                    $user['user_id'],
                    $gymName,
                    $gymAddress,
                    $gymContact,
                    $permitFilename,
                    $validIdFilename
                ]);

                audit_log($user['user_id'], 'submit_application', 'gym_owner', (string)$user['user_id']);
                flash('Your gym application has been submitted successfully!', 'success');
                redirect('gym_pending');

            } catch (Exception $e) {
                flash($e->getMessage(), 'danger');
            }
        }
    }

    render_header('Gym Onboarding', $user);
    ?>
    <section style="padding: 40px 0; max-width: 600px; margin: 0 auto;">
        <div class="auth-card" style="box-shadow: 0 8px 32px rgba(0,0,0,0.2);">
            <div class="auth-card-header">
                <h1 class="auth-title">Complete Your Setup</h1>
                <p class="auth-subtitle">We need a few details about your gym before you can access the platform.</p>
            </div>

            <form method="post" enctype="multipart/form-data" class="auth-form" style="display:flex; flex-direction:column; gap:20px;">
                <?= csrf_field() ?>
                
                <div class="auth-field">
                    <label>GYM NAME</label>
                    <div class="auth-input-group">
                        <input type="text" name="gym_name" required placeholder="Enter your gym name" value="<?= h(post('gym_name')) ?>" class="form-control">
                    </div>
                </div>

                <div class="auth-field">
                    <label>GYM ADDRESS</label>
                    <div class="auth-input-group">
                        <input type="text" name="gym_address" required placeholder="Complete address" value="<?= h(post('gym_address')) ?>" class="form-control">
                    </div>
                </div>

                <div class="auth-field">
                    <label>GYM CONTACT INFO</label>
                    <div class="auth-input-group">
                        <input type="text" name="gym_contact_info" required placeholder="Phone or Email" value="<?= h(post('gym_contact_info')) ?>" class="form-control">
                    </div>
                </div>

                <div class="auth-field" style="background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; border: 1px solid var(--line);">
                    <label style="color: var(--lime);">BUSINESS PERMIT (Max 5MB)</label>
                    <p style="font-size: 13px; color: var(--muted); margin: 4px 0 12px 0;">Please upload a clear copy of your local business permit (JPG, PNG, PDF).</p>
                    <div class="auth-input-group">
                        <input type="file" name="business_permit" required accept=".pdf,.jpg,.jpeg,.png" style="padding: 8px; font-size: 14px;">
                    </div>
                </div>

                <div class="auth-field" style="background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; border: 1px solid var(--line);">
                    <label style="color: var(--lime);">VALID GOV. ID (Max 5MB)</label>
                    <p style="font-size: 13px; color: var(--muted); margin: 4px 0 12px 0;">Please upload a clear copy of your government-issued ID (JPG, PNG, PDF).</p>
                    <div class="auth-input-group">
                        <input type="file" name="valid_id" required accept=".pdf,.jpg,.jpeg,.png" style="padding: 8px; font-size: 14px;">
                    </div>
                </div>

                <button type="submit" class="auth-submit-btn" style="margin-top: 10px;">
                    SUBMIT APPLICATION <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                </button>
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
