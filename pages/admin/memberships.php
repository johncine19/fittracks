<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function memberships_page(): void
{
    $user = require_roles(['admin', 'member']);
    if ($user['role'] === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_status_id'])) {
            $membershipId = (int) post('update_status_id');
            $status = post('status');
            db()->prepare('UPDATE memberships SET status = ? WHERE membership_id = ?')->execute([$status, $membershipId]);
            
            // Also update payment status if it exists and status is active
            if ($status === 'active') {
                db()->prepare('UPDATE payments SET status = "paid" WHERE membership_id = ? AND status = "pending"')->execute([$membershipId]);
                
                $mInfo = db()->query('SELECT m.user_id, p.plan_name, u.email, u.first_name, u.last_name FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id JOIN users u ON u.user_id = m.user_id WHERE m.membership_id = ' . $membershipId)->fetch();
                if ($mInfo) {
                    notify_user((int) $mInfo['user_id'], 'system', 'Payment Received', 'Your payment for the ' . $mInfo['plan_name'] . ' membership was successful and your plan is now active!');
                    
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
                        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);

                        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'noreply@fittracks.com', 'FITTRACKS');
                        $mail->addAddress($mInfo['email'], $mInfo['first_name'] . ' ' . $mInfo['last_name']);

                        $mail->isHTML(true);
                        $mail->Subject = 'Payment Confirmation - FITTRACKS';
                        $mail->Body    = 'Hi ' . htmlspecialchars($mInfo['first_name'], ENT_QUOTES, 'UTF-8') . ',<br><br>'
                            . 'Great news! Your payment for the <strong>' . htmlspecialchars($mInfo['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong> membership was successfully processed.<br><br>'
                            . 'Your membership is now ACTIVE. We look forward to seeing you at the gym!<br><br>'
                            . 'Best,<br>FITTRACKS Team';
                        $mail->send();
                    } catch (PHPMailerException) {
                        // Silently fail if email can't be sent, user still gets in-app notification
                    }
                }
            }
            
            flash('Membership status updated.');
            redirect('memberships');
        }

        $start = new DateTime((string) post('start_date'));
        $duration = (int) scalar('SELECT duration_days FROM membership_plans WHERE plan_id = ?', [post('plan_id')]);
        $end = (clone $start)->modify('+' . $duration . ' days')->format('Y-m-d');
        $memberUserId = (int) post('user_id');
        $planId = (int) post('plan_id');
        
        db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$memberUserId, $planId, post('start_date'), $end, post('status')]);
        $membershipId = db()->lastInsertId();
        
        $plan = db()->query('SELECT plan_name, price FROM membership_plans WHERE plan_id = ' . $planId)->fetch();
        
        $receipt = 'RCPT-' . date('Ymd') . '-' . random_int(1000, 9999);
        $paymentStatus = post('status') === 'active' ? 'paid' : 'pending';
        db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$membershipId, $plan['price'], post('start_date'), 'cash', $paymentStatus, $receipt]);

        notify_user(
            $memberUserId,
            'system',
            'Membership updated',
            'Your ' . $plan['plan_name'] . ' membership is ' . post('status') . ' from ' . date('M j, Y', strtotime((string) post('start_date'))) . ' to ' . date('M j, Y', strtotime($end)) . '.'
        );
        
        if ($paymentStatus === 'paid') {
            notify_user(
                $memberUserId,
                'system',
                'Payment recorded',
                'PHP ' . number_format((float)$plan['price'], 2) . ' received for ' . $plan['plan_name'] . '. Receipt: ' . $receipt . '.'
            );
        }
        flash('Membership created.');
        redirect('memberships');
    }
    
    if ($user['role'] === 'member' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_plan_id'])) {
        $planId = (int) post('subscribe_plan_id');
        $paymentMethod = post('payment_method') === 'gcash' ? 'gcash' : 'cash';
        
        $stmt = db()->prepare('SELECT * FROM membership_plans WHERE plan_id = ?');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if ($plan) {
            $start = new DateTime();
            $end = (clone $start)->modify('+' . $plan['duration_days'] . ' days')->format('Y-m-d');
            
            db()->prepare('INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$user['user_id'], $planId, $start->format('Y-m-d'), $end, 'pending']);
            $membershipId = (int) db()->lastInsertId();
            
            $receipt = 'REQ-' . date('Ymd') . '-' . random_int(1000, 9999);
            db()->prepare('INSERT INTO payments (membership_id, amount, payment_date, payment_method, status, receipt_number) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$membershipId, $plan['price'], $start->format('Y-m-d'), $paymentMethod, 'pending', $receipt]);
                
            $admins = query_all('SELECT user_id FROM users WHERE role = "admin"');
            foreach ($admins as $admin) {
                notify_user((int) $admin['user_id'], 'system', 'New Subscription Request', $user['first_name'] . ' ' . $user['last_name'] . ' requested a ' . $plan['plan_name'] . ' membership. Payment method: ' . strtoupper($paymentMethod) . '.');
            }
            
            flash('Subscription requested. Please proceed with payment.');
            redirect('memberships');
        }
    }

    $members = db()->query('SELECT user_id, CONCAT(first_name, " ", last_name) AS name FROM users WHERE role = "member" AND status = "active" ORDER BY first_name')->fetchAll();
    $plans   = db()->query('SELECT * FROM membership_plans WHERE is_active = 1 ORDER BY price')->fetchAll();
    $where   = $user['role'] === 'member' ? 'WHERE m.user_id = ' . (int) $user['user_id'] : '';
    $rows    = db()->query('SELECT m.*, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name, u.profile_picture, p.plan_name, p.price FROM memberships m JOIN users u ON u.user_id = m.user_id JOIN membership_plans p ON p.plan_id = m.plan_id ' . $where . ' ORDER BY m.created_at DESC')->fetchAll();
    render_header('Memberships', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
                <div>
                    <div class="sk sk-title" style="width:180px;margin-bottom:8px"></div>
                    <div class="sk sk-text" style="width:280px;height:12px"></div>
                </div>
                <?php if ($user['role'] === 'admin'): ?>
                    <div class="sk sk-rect" style="width:140px;height:36px;border-radius:18px"></div>
                <?php endif; ?>
            </div>
            
            <?php if ($user['role'] === 'member'): ?>
                <div class="sk sk-title" style="width:160px;margin-bottom:12px"></div>
                <div style="display:flex;gap:24px;margin-bottom:40px;overflow:hidden">
                    <?php for($i=0;$i<3;$i++): ?>
                        <div class="sk-card" style="width:280px;flex-shrink:0;min-height:220px">
                            <div class="sk sk-title" style="width:70%;margin-bottom:6px"></div>
                            <div class="sk sk-text short" style="height:12px;margin-bottom:16px"></div>
                            <div class="sk sk-text" style="width:50%;height:24px;margin-bottom:24px"></div>
                            <div class="sk sk-text full"></div>
                            <div class="sk sk-rect" style="height:38px;border-radius:6px;margin-top:20px"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
            
            <div class="sk sk-title" style="width:180px;margin-bottom:12px"></div>
            <?php render_skeleton_table(6, 6); ?>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block">
        <div class="page-header">
            <div>
                <h1>Memberships</h1>
                <p>Manage member subscription plans and their validity periods.</p>
            </div>
            <?php if ($user['role'] === 'admin'): ?>
                <button onclick="addMembership()" class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold;">+ New Membership</button>
            <?php endif; ?>
        </div>


        
        <?php if ($user['role'] !== 'member'): 
            $expiring = db()->query('SELECT m.end_date, CONCAT(u.first_name, " ", u.last_name) AS member, u.first_name, u.last_name FROM memberships m JOIN users u ON u.user_id = m.user_id WHERE m.status = "active" AND m.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY m.end_date ASC LIMIT 5')->fetchAll();
            if ($expiring):
        ?>
        <div class="flash warning">
            <strong>Upcoming Expirations (Next 7 Days):</strong>
            <ul style="margin:5px 0 0 20px;">
                <?php foreach ($expiring as $row): ?>
                    <li><?= h($row['member']) ?> - Expires on <?= h(date('M j, Y', strtotime($row['end_date']))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; endif; ?>

        <?php if ($user['role'] === 'member'): 
            $activePlanId = null;
            foreach ($rows as $r) {
                if ($r['status'] === 'active') {
                    $activePlanId = (int)$r['plan_id'];
                    break;
                }
            }
        ?>
            <h2 style="margin-bottom: 12px;">Available Plans</h2>
            <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2.5rem;">
                <?php foreach ($plans as $plan): 
                    $isActive = $activePlanId === (int)$plan['plan_id'];
                ?>
                    <div class="panel plan-card-glow" style="width: 280px; flex-shrink: 0; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: var(--surface); <?= $isActive ? 'border: 2px solid var(--lime); box-shadow: 0 0 15px rgba(204,255,0,0.1);' : '' ?>">
                        <div>
                            <h3 style="margin: 0; font-size: 1.2rem;"><?= h($plan['plan_name']) ?></h3>
                            <p style="color: var(--muted); font-size: 0.9rem; margin-top: 4px;"><?= h($plan['duration_days']) ?> Days</p>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: bold; color: var(--lime);">
                            <?= h(money($plan['price'])) ?>
                        </div>
                        <?php if (!empty($plan['description'])): ?>
                            <p style="font-size: 0.9rem; color: var(--muted); flex: 1;"><?= nl2br(h($plan['description'])) ?></p>
                        <?php else: ?>
                            <div style="flex: 1;"></div>
                        <?php endif; ?>
                        
                        <?php if ($isActive): ?>
                            <button class="btn" style="background: transparent; color: var(--lime); font-weight: bold; width: 100%; border: 1px solid var(--lime); cursor: default; padding: 10px;" disabled>Active Plan</button>
                        <?php else: ?>
                            <button class="btn" style="background: var(--lime); color: var(--bg); font-weight: bold; width: 100%; border: none; cursor: pointer; padding: 10px;" onclick="subscribePlan(<?= (int)$plan['plan_id'] ?>, '<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars(money($plan['price']), ENT_QUOTES) ?>')">Subscribe</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <script>
            function subscribePlan(planId, planName, planPrice) {
                Swal.fire({
                    title: 'Subscribe to ' + planName,
                    html: `
                        <p style="margin-bottom: 15px;">You are about to subscribe to the <strong>${planName}</strong> plan for <strong>${planPrice}</strong>.</p>
                        <form id="subscribeForm" method="post" action="index.php?page=memberships" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="subscribe_plan_id" value="${planId}">
                            
                            <label style="display:block; color: var(--muted); font-size: 14px;">Payment Method *
                                <select name="payment_method" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                    <option value="cash" selected>Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </label>
                            
                            <p style="font-size: 13px; color: var(--muted); margin-top: 8px;">
                                Your subscription will be created as <strong>Pending</strong>. You can finalize the payment at the front desk or via GCash.
                            </p>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Confirm Subscription',
                    confirmButtonColor: 'var(--lime-dark)',
                    cancelButtonColor: 'var(--line)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                    preConfirm: () => {
                        document.getElementById('subscribeForm').submit();
                    }
                });
            }
            </script>
        <?php endif; ?>

        <h2 style="margin-bottom: 12px;">Membership records</h2>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <p>No memberships found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php if ($user['role'] !== 'member'): ?><th>Member</th><?php endif; ?>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <?php if ($user['role'] === 'admin'): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $statusClass = 'badge badge-' . $row['status'];
                ?>
                    <tr>
                        <?php if ($user['role'] !== 'member'): ?>
                        <td>
                            <div class="user-cell">
                                <?= render_avatar($row) ?>
                                <span><?= h($row['member']) ?></span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td><strong><?= h($row['plan_name']) ?></strong></td>
                        <td><?= h(money($row['price'])) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['start_date']))) ?></td>
                        <td><?= h(date('M j, Y', strtotime($row['end_date']))) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= h($row['status']) ?></span></td>
                        <?php if ($user['role'] === 'admin'): ?>
                        <td>
                            <button onclick="editStatus(<?= $row['membership_id'] ?>, '<?= h($row['status']) ?>')" class="btn btn-secondary" style="padding:4px 8px;font-size:12px;" title="Edit Status">Update</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    
    <?php if ($user['role'] === 'admin'): ?>
    <script>
        function editStatus(membershipId, currentStatus) {
            Swal.fire({
                title: 'Update Status',
                html: `
                    <form id="editStatusForm" method="post" style="text-align:left;display:flex;flex-direction:column;gap:12px;margin-top:15px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_status_id" value="${membershipId}">
                        <label style="display:block;color:var(--muted);font-size:14px;">Status
                            <select name="status" class="form-control" style="width:100%;box-sizing:border-box;">
                                <option value="pending" ${currentStatus === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="active" ${currentStatus === 'active' ? 'selected' : ''}>Active</option>
                                <option value="expired" ${currentStatus === 'expired' ? 'selected' : ''}>Expired</option>
                                <option value="cancelled" ${currentStatus === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                            </select>
                        </label>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save',
                confirmButtonColor: 'var(--lime)',
                cancelButtonColor: '#334155',
                background: '#0f172a',
                color: 'var(--ink)',
                preConfirm: () => {
                    Swal.showLoading();
                    document.getElementById('editStatusForm').submit();
                }
            });
        }
    </script>
    <?php endif; ?>

    
    <?php if ($user['role'] === 'admin'): ?>
    <script>
    function addMembership() {
        Swal.fire({
            title: 'Create Membership',
            html: `
                <form id="addMembershipForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <?= csrf_field() ?>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Member *
                        <select name="user_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['user_id'] ?>"><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <label style="display:block; color: var(--muted); font-size: 14px;">Plan *
                        <select name="plan_id" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                            <option value="">Select Plan...</option>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= (int) $plan['plan_id'] ?>"><?= h($plan['plan_name']) ?> — <?= h(money($plan['price'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Start date *
                            <input name="start_date" type="date" class="form-control" value="<?= h(date('Y-m-d')) ?>" style="width: 100%; box-sizing: border-box;" required>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 14px;">Status *
                            <select name="status" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="active">Active</option>
                                <option value="pending">Pending</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </label>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('addMembershipForm');
                if (!form.user_id.value || !form.plan_id.value || !form.start_date.value) {
                    Swal.showValidationMessage('Please fill all required fields');
                    return false;
                }
                form.submit();
            }
        });
    }
    </script>
    <?php endif; ?>
    <?php
    render_footer();
}
