<?php
declare(strict_types=1);

function member_dashboard(PDO $pdo, array $user): void
{
    $score    = calculate_engagement_score((int) $user['user_id']);
    $category = get_engagement_category($score);
    $attendance = scalar('SELECT COUNT(*) FROM attendance WHERE user_id = ?', [$user['user_id']]);
    $progressLogs = scalar('SELECT COUNT(*) FROM progress_logs WHERE user_id = ?', [$user['user_id']]);
    $classBookings = scalar('SELECT COUNT(*) FROM class_bookings WHERE user_id = ?', [$user['user_id']]);

    $stmt = db()->prepare('SELECT fitness_tier FROM member_profiles WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $tier = (int) ($stmt->fetchColumn() ?: 1);
    $tierName = get_fitness_tier_name($tier);

    // Skeleton for member dashboard
    render_skeleton_banner();
    render_skeleton_stats(3);

    // Welcome Banner
    echo '<div class="skeleton-content animate-fade-in" style="background: linear-gradient(135deg, rgba(199,255,34,0.1) 0%, rgba(66,219,165,0.05) 100%); border: 1px solid rgba(199,255,34,0.3); border-radius: 12px; padding: 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); backdrop-filter: blur(16px);">';
    echo '<div style="flex: 1 1 250px; min-width: 0;"><div style="display:flex; align-items:center; gap: 12px; margin-bottom: 4px; flex-wrap: wrap;"><h2 style="margin: 0; font-size: 24px; color: var(--ink); max-width: 100%; word-break: break-word;">Welcome back, ' . h($user['first_name']) . '!</h2><span style="background: var(--accent, #7c5cfc); color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; box-shadow: 0 2px 10px rgba(124,92,252,0.3); display: flex; align-items: center; gap: 4px; white-space: nowrap;">' . h($tierName) . ' Tier <a href="#" onclick="event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" style="color:rgba(255,255,255,0.8); text-decoration: none;" title="How it works">&#9468;</a></span></div>';
    echo '<p style="margin: 0; color: var(--muted); font-size: 14px;">Let\'s crush today\'s fitness goals. Here\'s your progress at a glance.</p></div>';
    
    // Hero Engagement Score
    $catColor = 'var(--lime)';
    $catBg = 'rgba(199,255,34,0.15)';
    if ($category === 'Moderately Engaged') {
        $catColor = '#f59e0b';
        $catBg = 'rgba(245, 158, 11, 0.15)';
    } elseif ($category === 'At-Risk') {
        $catColor = '#ef4444';
        $catBg = 'rgba(239, 68, 68, 0.15)';
    }
    
    echo '<div onclick="document.getElementById(\'missions-section\').scrollIntoView({behavior: \'smooth\'})" style="background: var(--panel-soft); padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.05); text-align: center; cursor: pointer; transition: all 0.2s; flex: 1 1 150px;" onmouseover="this.style.transform=\'scale(1.03)\'; this.style.boxShadow=\'0 4px 16px rgba(0,0,0,0.2)\'" onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\'" title="Click to view your missions">';
    echo '<span style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 4px;">Engagement Score <a href="#" onclick="event.stopPropagation(); event.preventDefault(); document.getElementById(\'guide-modal\').showModal();" style="color:' . $catColor . '; margin-left: 4px; text-decoration: none;" title="How it works">&#9468;</a></span>';
    echo '<strong style="display: block; font-size: 32px; color: ' . $catColor . '; line-height: 1;">' . $score . '<span style="font-size: 16px; color: var(--muted);">/100</span></strong>';
    echo '<span style="display: inline-block; margin-top: 6px; font-size: 11px; background: ' . $catBg . '; color: ' . $catColor . '; padding: 2px 8px; border-radius: 12px; font-weight: bold;">' . h(ucfirst(str_replace('_', ' ', $category))) . '</span>';
    echo '<div style="margin-top: 6px; font-size: 10px; color: var(--muted); opacity: 0.7;">Click to view missions ↓</div>';
    echo '</div></div>';
    
    render_announcement_carousel(get_active_announcements('members'));

    // Animated Metric Cards
    echo '<div class="skeleton-content metrics animate-fade-in delay-1" style="margin-bottom: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">';
    $metrics = [
        'Attendance Records' => $attendance,
        'Progress Logs' => $progressLogs,
        'Class Bookings' => $classBookings,
    ];
    foreach ($metrics as $k => $v) {
        echo '<div class="metric" style="transition: transform 0.2s; cursor: default;" onmouseover="this.style.transform=\'translateY(-4px)\'" onmouseout="this.style.transform=\'translateY(0)\'">';
        echo '<span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em;">' . h($k) . '</span>';
        echo '<strong style="font-size: 28px; color: var(--ink);">' . h((string)$v) . '</strong>';
        echo '</div>';
    }
    echo '</div>';

    // Engagement Missions
    $missions = get_engagement_missions((int) $user['user_id']);
    $completedCount = count(array_filter($missions, fn($m) => $m['completed']));
    $totalMissions = count($missions);
    ?>
    <div id="missions-section" class="skeleton-content animate-fade-in delay-2" style="margin-bottom: 24px;">
        <section class="panel" style="padding: 0; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div style="padding: 20px 24px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h2 style="margin: 0; font-size: 18px; color: var(--ink);">Monthly Missions</h2>
                    <span style="font-size: 12px; background: rgba(199,255,34,0.15); color: var(--lime); padding: 3px 10px; border-radius: 12px; font-weight: 600;"><?= $completedCount ?>/<?= $totalMissions ?> Complete</span>
                </div>
                <span style="font-size: 13px; color: var(--muted);">Complete missions to boost your Engagement Score!</span>
            </div>
            <div style="padding: 8px 24px 20px;">
                <?php 
                $missionIndex = 0;
                foreach ($missions as $mission): 
                    $missionIndex++;
                    $isHidden = $missionIndex > 2;
                    
                    $pct = $mission['target'] > 0 ? min(100, round(($mission['current'] / $mission['target']) * 100)) : 0;
                    $barColor = $mission['completed'] ? '#22c55e' : 'var(--lime)';
                    $checkColor = $mission['completed'] ? '#22c55e' : 'var(--line)';
                    $checkBg = $mission['completed'] ? 'rgba(34, 197, 94, 0.15)' : 'rgba(255,255,255,0.03)';
                    ?>
                    <div class="mission-item <?= $isHidden ? 'hidden-mission' : '' ?>" style="display: <?= $isHidden ? 'none' : 'flex' ?>; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid rgba(255,255,255,0.04); <?= $mission['completed'] ? 'opacity: 0.75;' : '' ?>">
                        <!-- Check Circle -->
                        <div style="width: 38px; height: 38px; border-radius: 50%; border: 2px solid <?= $checkColor ?>; background: <?= $checkBg ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.3s;">
                            <?php if ($mission['completed']): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php else: ?>
                                <span style="font-size: 16px;"><?= $mission['icon'] ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Mission Info -->
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span style="font-weight: 600; color: var(--ink); font-size: 14px; <?= $mission['completed'] ? 'text-decoration: line-through; color: var(--muted);' : '' ?>"><?= h($mission['title']) ?></span>
                                <span style="font-size: 12px; font-weight: 600; color: <?= $mission['completed'] ? '#22c55e' : 'var(--muted)' ?>; white-space: nowrap; margin-left: 8px;">
                                    +<?= $mission['earnedPoints'] ?>/<?= $mission['maxPoints'] ?> pts
                                </span>
                            </div>
                            <div style="font-size: 12px; color: var(--muted); margin-bottom: 8px;"><?= h($mission['description']) ?></div>
                            <!-- Progress Bar -->
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.06); border-radius: 3px; overflow: hidden;">
                                    <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 3px; transition: width 0.5s ease;"></div>
                                </div>
                                <span style="font-size: 11px; color: var(--muted); font-weight: 500; white-space: nowrap;"><?= $mission['current'] ?>/<?= $mission['target'] ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (count($missions) > 2): ?>
                    <div style="text-align: center; margin-top: 16px;">
                        <button id="toggle-missions-btn" onclick="toggleMissions()" style="background: rgba(199,255,34,0.1); border: 1px solid rgba(199,255,34,0.3); color: var(--lime); font-size: 13px; font-weight: 600; cursor: pointer; padding: 6px 16px; border-radius: 20px; transition: all 0.2s;" onmouseover="this.style.background='rgba(199,255,34,0.2)'" onmouseout="this.style.background='rgba(199,255,34,0.1)'">
                            Show All Missions ↓
                        </button>
                    </div>
                    <script>
                        function toggleMissions() {
                            const hiddenMissions = document.querySelectorAll('.hidden-mission');
                            const btn = document.getElementById('toggle-missions-btn');
                            if (!hiddenMissions.length) return;
                            
                            const isCurrentlyHidden = hiddenMissions[0].style.display === 'none';
                            
                            hiddenMissions.forEach(m => {
                                m.style.display = isCurrentlyHidden ? 'flex' : 'none';
                            });
                            
                            btn.innerHTML = isCurrentlyHidden ? 'Show Less' : 'Show All Missions';
                        }
                    </script>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php

    echo '<div class="skeleton-content animate-fade-in delay-2">';
    
    $profile = db()->query('SELECT height_cm, weight_kg, primary_goal FROM member_profiles WHERE user_id = ' . (int)$user['user_id'])->fetch();
    if ($profile && ((float)$profile['height_cm'] == 0 || (float)$profile['weight_kg'] == 0)) {
        echo '<div style="background: rgba(255,165,0,0.1); border: 1px solid orange; border-radius: 12px; padding: 24px; text-align: center; margin-bottom: 24px;">';
        echo '<h3 style="color: orange; margin: 0 0 12px 0;">We need a little more info!</h3>';
        echo '<p style="color: var(--muted); margin: 0 0 16px 0;">To build your personalized workout plan, we need your accurate height and weight.</p>';
        echo '<a href="index.php?page=profile" class="btn" style="background: orange; color: #111; font-weight: bold; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;">Complete Profile</a>';
        echo '</div>';
    } else {
        render_current_workout($user['user_id'], true);
    }
    
    echo '</div>';
    
    echo '<div class="skeleton-content animate-fade-in delay-3">';
    render_exercise_recommendations((int) $user['user_id'], true);
    echo '</div>';
    
    // Recommended Gyms and Classes Section
    if (!empty($profile['primary_goal'])) {
        $recommendations = get_recommendations_by_goal(db(), $profile['primary_goal']);
        
        echo '<div class="skeleton-content animate-fade-in delay-4" style="margin-top: 24px;">';
        echo '<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:16px;">';
        echo '<h3 style="margin:0; font-size:1.2rem; color:var(--ink);">Recommended For You</h3>';
        echo '</div>';
        
        echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 16px;">';
        
        // Classes
        if (!empty($recommendations['classes'])) {
            echo '<div class="panel" style="background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:20px;">';
            echo '<h4 style="margin:0 0 16px; color:var(--lime); display:flex; align-items:center; gap:8px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Recommended Classes</h4>';
            foreach ($recommendations['classes'] as $cls) {
                echo '<div style="margin-bottom: 16px; padding-bottom:16px; border-bottom:1px solid rgba(255,255,255,0.05);">';
                echo '<div style="font-weight:bold; font-size:1.1rem; color:var(--ink);">' . h($cls['class_name']) . '</div>';
                echo '<div style="font-size:0.9rem; color:var(--muted); margin-bottom:8px;">At ' . h($cls['gym_name']) . '</div>';
                if (!empty($cls['description'])) {
                    echo '<div style="font-size:0.85rem; color:var(--muted); line-height:1.4;">' . h($cls['description']) . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        
        // Gyms
        if (!empty($recommendations['gyms'])) {
            echo '<div class="panel" style="background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:20px;">';
            echo '<h4 style="margin:0 0 16px; color:var(--accent, #7c5cfc); display:flex; align-items:center; gap:8px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg> Recommended Gyms</h4>';
            foreach ($recommendations['gyms'] as $gym) {
                echo '<div style="margin-bottom: 16px; padding-bottom:16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">';
                echo '<div>';
                echo '<div style="font-weight:bold; font-size:1.1rem; color:var(--ink);">' . h($gym['name']) . '</div>';
                echo '<div style="font-size:0.9rem; color:var(--muted);">' . h($gym['address']) . '</div>';
                echo '</div>';
                echo '<a href="index.php?page=view_gym&gym_id=' . $gym['gym_id'] . '" class="btn btn-primary" style="padding: 6px 12px; font-size:0.85rem;">View</a>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div></div>';
    }
    
    $wAtt = (int) get_setting('engagement_weight_attendance', '40');
    $wCls = (int) get_setting('engagement_weight_classes', '20');
    $wCon = (int) get_setting('engagement_weight_consistency', '20');
    $wWrk = (int) get_setting('engagement_weight_workouts', '10');
    $wPrg = (int) get_setting('engagement_weight_progress', '10');
    $high = (int) get_setting('engagement_threshold_high', '75');
    $mod = (int) get_setting('engagement_threshold_moderate', '40');
    $mod_end = $high - 1;
    $risk_end = $mod - 1;

    // Engagement & Tiers Guide Modal
    echo <<<HTML
    <dialog id="guide-modal" class="panel animate-fade-in" style="border:1px solid rgba(255,255,255,0.1); border-radius:16px; background:var(--panel); color:var(--ink); padding:0; max-width:600px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.5); margin:auto;">
        <div style="padding:24px 24px 16px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:20px; color:var(--lime);">How it Works</h2>
            <button type="button" onclick="document.getElementById('guide-modal').close()" style="background:none; border:none; color:var(--muted); font-size:24px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div style="padding:24px; max-height:60vh; overflow-y:auto;">
            <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2" style="width:20px;height:20px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                Engagement Score (0 - 100)
            </h3>
            <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                Your Engagement Score measures how actively you use FITTRACKS over the last 30-60 days. It updates automatically based on your habits!
            </p>
            <ul style="color:var(--muted); font-size:14px; line-height:1.6; margin-bottom:24px; padding-left:20px;">
                <li><strong>{$wAtt}% Attendance:</strong> Check into the gym regularly (up to 7 visits / 30 days).</li>
                <li><strong>{$wCls}% Classes:</strong> Participate in group fitness classes (up to 4 classes / 30 days).</li>
                <li><strong>{$wCon}% Consistency:</strong> Stay active every week. We look at your weekly streaks!</li>
                <li><strong>{$wWrk}% Daily Completed Workout:</strong> Complete your scheduled exercises (up to 8 days / 30 days).</li>
                <li><strong>{$wPrg}% Progress:</strong> Log your workout progress at least once every 60 days.</li>
            </ul>
            
            <p style="color:var(--muted); font-size:14px; margin-bottom:12px; line-height:1.6;">
                <strong>Engagement Categories:</strong>
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; font-size:13px; margin-bottom: 24px;">
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid var(--lime);"><strong>{$high} - 100:</strong> Highly Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #f59e0b;"><strong>{$mod} - {$mod_end}:</strong> Moderately Engaged</div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border-left: 3px solid #ef4444;"><strong>0 - {$risk_end}:</strong> At-Risk</div>
            </div>
            
            <h3 style="color:var(--ink); margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent, #7c5cfc)" stroke-width="2" style="width:20px;height:20px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                Fitness Tiers
            </h3>
            <p style="color:var(--muted); font-size:14px; margin-bottom:16px; line-height:1.6;">
                Tiers represent your lifetime workout experience! They are completely based on completing your assigned workout plans. Every time you log all exercises in a week's plan, you earn a "Completed Week".
            </p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 1:</strong> Newbie <em>(&lt; 1 week)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 2:</strong> Iron Recruit <em>(1+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 3:</strong> Bronze Beast <em>(4+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px;"><strong>Tier 4:</strong> Silver Spartan <em>(12+ weeks)</em></div>
                <div style="background:rgba(255,255,255,0.03); padding:10px; border-radius:8px; border:1px solid rgba(199,255,34,0.3);"><strong>Tier 5:</strong> Gold Gladiator <em>(24+ weeks)</em></div>
                <div style="background:rgba(124,92,252,0.1); padding:10px; border-radius:8px; border:1px solid rgba(124,92,252,0.3); color:var(--accent, #7c5cfc); font-weight:bold;"><strong>Tier 6:</strong> Apex Legend</div>
            </div>
        </div>
        <div style="padding:16px 24px; border-top:1px solid rgba(255,255,255,0.05); text-align:right;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('guide-modal').close()">Got it</button>
        </div>
    </dialog>
HTML;
}
