<?php
declare(strict_types=1);

function gym_selection_page(): void
{
    define('AUTH_PAGE', true);
    $user = require_roles(['member']);
    
    $gyms = db()->query('SELECT * FROM gyms WHERE status = "approved"')->fetchAll();
    
    $gymData = [];
    foreach ($gyms as $gym) {
        $classes = db()->prepare('SELECT * FROM classes WHERE gym_id = ? ORDER BY class_name ASC');
        $classes->execute([$gym['gym_id']]);
        $gym['classes'] = $classes->fetchAll();
        
        $plans = db()->prepare('SELECT * FROM membership_plans WHERE (gym_id = ? OR plan_scope = "shared") AND is_active = 1 ORDER BY price ASC');
        $plans->execute([$gym['gym_id']]);
        $gym['plans'] = $plans->fetchAll();
        
        $gymData[] = $gym;
    }
    
    $profile = member_profile((int) $user['user_id']);
    $userGoal = strtolower($profile['primary_goal'] ?? '');
    
    render_header('Select Your Gym', $user);
    ?>
    <style>
        .carousel-container {
            display: flex;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            gap: 20px;
            padding: 20px 0;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .carousel-container::-webkit-scrollbar {
            display: none;
        }
        .gym-card {
            scroll-snap-align: start;
            flex: 0 0 auto;
            width: 300px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            position: relative;
        }
        .gym-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            border-color: var(--lime);
        }
        .gym-badge {
            position: absolute;
            top: -12px;
            right: -12px;
            background: var(--lime);
            color: var(--bg);
            font-size: 11px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 20px;
            box-shadow: 0 4px 10px rgba(204,255,0,0.3);
            text-transform: uppercase;
        }
        .gym-card h3 { margin: 0 0 10px; color: var(--lime); }
        .gym-card p { margin: 0 0 5px; color: var(--muted); font-size: 14px; }
        
        #gymDetailsModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .gym-details-content {
            background: var(--surface);
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--line);
            padding: 30px;
            position: relative;
        }
        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 24px;
            cursor: pointer;
        }
        .close-btn:hover { color: var(--ink); }
    </style>

    <div class="panel animate-fade-in" style="max-width: 1000px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 40px;">
            <h1 style="font-size: 2.5rem; margin-bottom: 10px;">Find Your Perfect Gym</h1>
            <p style="color: var(--muted); font-size: 1.1rem;">
                <?php if ($userGoal): ?>
                    Based on your primary goal (<strong><?= h(ucwords(str_replace('-', ' ', $userGoal))) ?></strong>), here are the gyms available.
                <?php else: ?>
                    Select a gym to view their classes, schedules, and membership plans.
                <?php endif; ?>
            </p>
        </div>

        <?php if (empty($gymData)): ?>
            <p style="text-align: center; color: var(--muted);">No gyms are currently available on the platform.</p>
        <?php else: ?>
            <div class="carousel-container">
                <?php foreach ($gymData as $index => $gym): 
                    $isMatch = false;
                    // Basic match logic: if any class description or name mentions their goal
                    if ($userGoal) {
                        $goalKeywords = explode('-', $userGoal);
                        foreach ($gym['classes'] as $c) {
                            $text = strtolower($c['class_name'] . ' ' . $c['description']);
                            foreach ($goalKeywords as $kw) {
                                if (strlen($kw) > 3 && strpos($text, $kw) !== false) {
                                    $isMatch = true;
                                    break 2;
                                }
                            }
                        }
                    }
                ?>
                    <div class="gym-card" onclick="openGymModal(<?= (int)$index ?>)">
                        <?php if ($isMatch): ?>
                            <div class="gym-badge">Recommended</div>
                        <?php endif; ?>
                        <h3><?= h($gym['name']) ?></h3>
                        <p><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?= h($gym['address']) ?></p>
                        <p style="margin-top: 15px; color: var(--ink); font-weight: bold;"><?= count($gym['classes']) ?> Classes Offered</p>
                        <p style="color: var(--ink); font-weight: bold;"><?= count($gym['plans']) ?> Membership Plans</p>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center; margin-top: 20px; color: var(--muted); font-size: 13px;">
                <p>&larr; Swipe or scroll to see more gyms &rarr;</p>
            </div>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php?page=dashboard" style="color: var(--muted); text-decoration: underline;">Skip for now</a>
        </div>
    </div>

    <!-- The Modal -->
    <div id="gymDetailsModal">
        <div class="gym-details-content">
            <button class="close-btn" onclick="document.getElementById('gymDetailsModal').style.display='none'">&times;</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
    const gymData = <?= json_encode($gymData) ?>;
    
    function openGymModal(index) {
        const gym = gymData[index];
        const modal = document.getElementById('gymDetailsModal');
        const body = document.getElementById('modalBody');
        
        let html = `
            <h2 style="color: var(--lime); font-size: 2rem; margin: 0 0 5px;">${escapeHtml(gym.name)}</h2>
            <p style="color: var(--muted); font-size: 1.1rem; margin: 0 0 20px;">${escapeHtml(gym.address)}</p>
            <hr style="border: none; border-top: 1px solid var(--line); margin-bottom: 20px;">
        `;
        
        // Classes Section
        html += `<h3 style="margin-bottom: 15px;">Classes Offered</h3>`;
        if (gym.classes && gym.classes.length > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin-bottom: 30px;">`;
            gym.classes.forEach(c => {
                html += `
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--line); border-radius: 8px; padding: 15px;">
                        <strong style="display: block; color: var(--ink); font-size: 1.1rem; margin-bottom: 5px;">${escapeHtml(c.class_name)}</strong>
                        <div style="font-size: 0.9rem; color: var(--muted);">${escapeHtml(c.description || '')}</div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<p style="color: var(--muted); margin-bottom: 30px;">No classes currently offered.</p>`;
        }
        
        // Memberships Section
        html += `<h3 style="margin-bottom: 15px;">Membership Plans</h3>`;
        if (gym.plans && gym.plans.length > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-bottom: 30px;">`;
            gym.plans.forEach(p => {
                let scopeBadge = p.plan_scope === 'shared' ? `<span style="font-size: 10px; background: rgba(199,255,34,0.15); color: var(--lime); padding: 2px 6px; border-radius: 12px; text-transform: uppercase;">Shared</span>` : '';
                html += `
                    <div style="background: var(--surface); border: 2px solid var(--line); border-radius: 8px; padding: 20px; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <strong style="font-size: 1.2rem; color: var(--ink);">${escapeHtml(p.plan_name)}</strong>
                            ${scopeBadge}
                        </div>
                        <div style="font-size: 1.8rem; font-weight: bold; color: var(--lime); margin-bottom: 15px;">
                            $${parseFloat(p.price).toFixed(2)}
                        </div>
                        <p style="color: var(--muted); font-size: 0.9rem; flex-grow: 1; margin-bottom: 20px;">
                            ${escapeHtml(p.description || '')}<br>
                            <br>
                            Duration: ${p.duration_days} days
                        </p>
                        <form method="post" action="index.php?page=memberships">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="subscribe">
                            <input type="hidden" name="plan_id" value="${p.plan_id}">
                            <input type="hidden" name="payment_method" value="gcash">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Subscribe</button>
                        </form>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<p style="color: var(--muted);">No membership plans currently available.</p>`;
        }
        
        body.innerHTML = html;
        modal.style.display = 'flex';
    }
    
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
    </script>
    <?php
    render_footer();
}
