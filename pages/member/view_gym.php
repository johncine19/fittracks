<?php
declare(strict_types=1);

function view_gym_page(): void
{
    $user = require_roles(['member', 'platform_admin', 'gym_owner']);
    $pdo = db();
    
    $gymId = (int) ($_GET['gym_id'] ?? 0);
    if (!$gymId) {
        flash('Invalid gym ID.', 'danger');
        redirect('dashboard');
    }
    
    $gym = $pdo->prepare('SELECT * FROM gyms WHERE gym_id = ? AND status = "approved"');
    $gym->execute([$gymId]);
    $gym = $gym->fetch();
    
    if (!$gym) {
        flash('Gym not found or not active.', 'danger');
        redirect('dashboard');
    }
    
    // Fetch classes
    $classes = $pdo->prepare('SELECT * FROM classes WHERE gym_id = ? ORDER BY class_name ASC');
    $classes->execute([$gymId]);
    $classes = $classes->fetchAll();
    
    render_header($gym['name'], $user);
    ?>
    <div class="panel animate-fade-in">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid var(--line); padding-bottom:16px; margin-bottom:16px;">
            <div>
                <h2 style="margin:0; color:var(--lime);"><?= h($gym['name']) ?></h2>
                <p style="margin:4px 0 0; color:var(--muted);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?= h($gym['address']) ?></p>
                <?php if (!empty($gym['contact_info'])): ?>
                    <p style="margin:4px 0 0; color:var(--muted);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg> <?= h($gym['contact_info']) ?></p>
                <?php endif; ?>
            </div>
            <a href="index.php?page=dashboard" class="btn btn-secondary">Back to Dashboard</a>
        </div>
        
        <h3 style="color:var(--ink); margin-bottom:12px;">Classes Offered</h3>
        <?php if ($classes): ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 250px), 1fr)); gap:16px;">
                <?php foreach ($classes as $c): ?>
                    <div style="background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:16px;">
                        <div style="font-weight:bold; color:var(--ink); font-size:1.1rem; margin-bottom:4px;"><?= h($c['class_name']) ?></div>
                        <div style="color:var(--muted); font-size:0.9rem; margin-bottom:8px;">Capacity: <?= (int)$c['capacity'] ?></div>
                        <?php if (!empty($c['description'])): ?>
                            <div style="color:var(--muted); font-size:0.85rem; line-height:1.4;"><?= h($c['description']) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($user['role'] === 'member'): ?>
                            <div style="margin-top:12px;">
                                <a href="index.php?page=book_classes" class="btn btn-primary" style="padding: 6px 12px; font-size:0.85rem;">Book Class</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color:var(--muted);">This gym hasn't added any classes yet.</p>
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}
