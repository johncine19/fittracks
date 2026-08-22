<?php
declare(strict_types=1);

function gym_rejected_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
    }

    $pdo = db();
    $gym = $pdo->query('SELECT gym_id, status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    
    if (!$gym || $gym['status'] !== 'rejected') {
        redirect('dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (post('action') === 'resubmit') {
            $pdo->prepare('DELETE FROM gyms WHERE gym_id = ?')->execute([$gym['gym_id']]);
            flash('You can now submit a new gym application.', 'success');
            redirect('gym_onboarding');
        }
    }

    render_header('Application Rejected', $user);
    ?>
    <section style="padding: 60px 0; max-width: 600px; margin: 0 auto; text-align: center;">
        <div class="auth-card" style="box-shadow: 0 8px 32px rgba(0,0,0,0.2); display: flex; flex-direction: column; align-items: center; gap: 20px;">
            <a class="brand" href="index.php" style="display: inline-flex; align-items: center; gap: 10px; text-decoration: none;">
                <div style="width:32px;height:32px;background:var(--lime);border-radius:4px;display:flex;align-items:center;justify-content:center;color:var(--bg);font-weight:900;font-size:16px;">FT</div>
                <span style="font-weight:700;font-size:1.2rem;line-height:1;letter-spacing:-0.2px;color:var(--ink);">FitTrack</span>
            </a>
            <div style="background: rgba(255,77,93,0.1); color: #ff4d5d; padding: 20px; border-radius: 50%; display: inline-flex; margin-top: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            
            <h1 class="auth-title" style="margin: 0; color: #ff4d5d;">Application Rejected</h1>
            <p class="auth-subtitle" style="max-width: 400px; margin: 0 auto; line-height: 1.6;">
                Unfortunately, your gym application has been rejected by the administration team. 
                This usually happens if your business permit or government ID was unclear or invalid.
            </p>

            <form method="post" action="index.php?page=gym_rejected" style="margin-top: 10px;">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="resubmit" class="btn" style="width: 100%;">
                    Submit a New Application
                </button>
            </form>

            <a href="index.php?page=logout" style="color: var(--muted); text-decoration: none; font-size: 14px; transition: color 0.2s;" onmouseover="this.style.color='var(--ink)'" onmouseout="this.style.color='var(--muted)'">
                Sign Out
            </a>
            
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <?php
    render_footer();
}
