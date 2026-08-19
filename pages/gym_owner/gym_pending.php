<?php
declare(strict_types=1);

function gym_pending_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
    }

    $pdo = db();
    $gym = $pdo->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    
    if (!$gym || $gym['status'] !== 'pending') {
        redirect('dashboard');
    }

    render_header('Application Pending', $user);
    ?>
    <section style="padding: 60px 0; max-width: 600px; margin: 0 auto; text-align: center;">
        <div class="auth-card" style="box-shadow: 0 8px 32px rgba(0,0,0,0.2); display: flex; flex-direction: column; align-items: center; gap: 20px;">
            <a class="brand" href="index.php" style="display: inline-flex; align-items: center; gap: 10px; text-decoration: none;">
                <div style="width:32px;height:32px;background:var(--lime);border-radius:4px;display:flex;align-items:center;justify-content:center;color:var(--bg);font-weight:900;font-size:16px;">FT</div>
                <span style="font-weight:700;font-size:1.2rem;line-height:1;letter-spacing:-0.2px;color:var(--ink);">FitTrack</span>
            </a>
            <div style="background: rgba(199,255,34,0.1); color: var(--lime); padding: 20px; border-radius: 50%; display: inline-flex; margin-top: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            
            <h1 class="auth-title" style="margin: 0;">Under Review</h1>
            <p class="auth-subtitle" style="max-width: 400px; margin: 0 auto; line-height: 1.6;">
                Your gym application has been received and is currently being reviewed by our administration team. 
                This usually takes 1-2 business days. We will notify you once your application is approved.
            </p>

            <a href="index.php?page=logout" class="btn" style="background: var(--surface); color: var(--ink); border: 1px solid var(--line); margin-top: 20px;">
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
