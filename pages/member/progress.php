<?php
declare(strict_types=1);

function progress_page(): void
{
    $user = require_roles(['member', 'trainer']);
    $memberId = $user['role'] === 'trainer'
        ? (int) post('member_user_id', $_GET['member_user_id'] ?? 0)
        : (int) $user['user_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $memberId) {
        db()->prepare(
            'INSERT INTO progress_logs
             (user_id, log_date, weight_kg, body_fat_percent, chest_cm, waist_cm, hips_cm, arm_cm, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            post('log_date'),
            post('weight_kg'),
            post('body_fat_percent') ?: null,
            post('chest_cm')         ?: null,
            post('waist_cm')         ?: null,
            post('hips_cm')          ?: null,
            post('arm_cm')           ?: null,
            post('notes'),
            $user['user_id'],
        ]);
        if ($user['role'] === 'member') {
            db()->prepare('UPDATE member_profiles SET weight_kg = ? WHERE user_id = ?')
               ->execute([post('weight_kg'), $memberId]);
            generate_workout_plan($memberId);
            notify_user($memberId, 'system', 'Workout plan updated', 'Your workout plan was refreshed after logging new progress.');
            notify_user($memberId, 'milestone', 'Progress logged', 'Nice work — your latest measurements were saved.');
        }
        flash('Progress logged.', 'success');
        redirect($user['role'] === 'trainer' ? 'trainer_members' : 'progress');
    }

    // Fetch in DESC order for the table; reverse for the chart
    $rows = $memberId
        ? query_all('SELECT * FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC', [$memberId])
        : [];

    $chartLabels  = [];
    $chartWeights = [];
    foreach (array_reverse($rows) as $r) {
        $chartLabels[]  = date('M j', strtotime($r['log_date']));
        $chartWeights[] = (float) $r['weight_kg'];
    }

    render_header('Progress', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Progress logs</h1>
                <p>Track your weight, measurements, and body composition over time.</p>
            </div>
            <button onclick="logProgress()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ Log Progress</button>
        </div>



        <!-- Weight trend chart -->
        <?php if (count($chartWeights) >= 2): ?>
        <div style="margin-bottom:2rem;">
            <h2 style="margin-bottom:12px;">Weight trend</h2>
            <canvas id="weightChart" style="max-height:260px;"></canvas>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
                const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';
                const tickColor  = isDark ? '#8792ad' : '#555';
                new Chart(document.getElementById('weightChart'), {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($chartLabels, JSON_HEX_TAG) ?>,
                        datasets: [{
                            label: 'Weight (kg)',
                            data: <?= json_encode($chartWeights, JSON_HEX_TAG) ?>,
                            borderColor: '#c7ff22',
                            backgroundColor: 'rgba(199,255,34,0.08)',
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: '#c7ff22',
                            tension: 0.3,
                            fill: true,
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { grid: { color: gridColor }, ticks: { color: tickColor } },
                            x: { grid: { color: gridColor }, ticks: { color: tickColor } }
                        }
                    }
                });
            });
            </script>
        </div>
        <?php elseif ($rows): ?>
            <p class="muted" style="margin-bottom:1.5rem;">Log at least 2 entries to see your weight trend chart.</p>
        <?php endif; ?>

        <!-- History table -->
        <h2 style="margin-bottom:12px;">History</h2>
        <?= render_simple_table($rows, ['log_date', 'weight_kg', 'body_fat_percent', 'waist_cm', 'chest_cm', 'arm_cm', 'notes']) ?>
    </section>
    
    <script>
    function logProgress() {
        Swal.fire({
            title: 'Log Progress',
            html: `
                <form id="progressForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    <?php if ($user['role'] === 'trainer'): ?>
                        <input type="hidden" name="member_user_id" value="<?= (int) $memberId ?>">
                    <?php endif; ?>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Date *
                            <input name="log_date" type="date" class="form-control" required value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Weight (kg) *
                            <input name="weight_kg" type="number" step="0.01" class="form-control" placeholder="e.g. 72.5" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Body fat %
                            <input name="body_fat_percent" type="number" step="0.01" class="form-control" placeholder="optional" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Waist (cm)
                            <input name="waist_cm" type="number" step="0.01" class="form-control" placeholder="optional" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Chest (cm)
                            <input name="chest_cm" type="number" step="0.01" class="form-control" placeholder="optional" style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Arms (cm)
                            <input name="arm_cm" type="number" step="0.01" class="form-control" placeholder="optional" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Notes
                        <input name="notes" class="form-control" placeholder="Any notes about this entry" style="width: 100%; box-sizing: border-box;">
                    </label>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Log progress',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('progressForm');
                if (!form.log_date.value || !form.weight_kg.value) {
                    Swal.showValidationMessage('Please fill all required fields (Date & Weight)');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    <?php
    render_footer();
}
