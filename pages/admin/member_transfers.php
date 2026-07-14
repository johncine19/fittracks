<?php

declare(strict_types=1);

function member_transfers_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        $transferId = (int) post('transfer_id');

        if ($action === 'approve' || $action === 'reject') {
            $transfer = $pdo->query("SELECT * FROM member_transfers WHERE transfer_id = $transferId")->fetch();
            if ($transfer && $transfer['status'] === 'pending') {
                $pdo->prepare('UPDATE member_transfers SET status = ?, resolved_at = NOW() WHERE transfer_id = ?')
                    ->execute([$action . 'd', $transferId]); // 'approved' or 'rejected'
                
                if ($action === 'approve') {
                    // Migrate active memberships to the new gym
                    $activeMemberships = query_all("SELECT m.membership_id FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = 'active' AND p.gym_id = ?", [$transfer['user_id'], $transfer['from_gym_id']]);
                    
                    if ($activeMemberships) {
                        // Find or create a "Transfer Plan" for the new gym
                        $transferPlan = $pdo->query("SELECT plan_id FROM membership_plans WHERE gym_id = {$transfer['to_gym_id']} AND plan_name = 'Transferred Membership'")->fetch();
                        if (!$transferPlan) {
                            $pdo->prepare("INSERT INTO membership_plans (gym_id, plan_name, duration_days, price, description, is_active, plan_scope) VALUES (?, 'Transferred Membership', 30, 0, 'Automatically created for transferred members', 0, 'local')")->execute([$transfer['to_gym_id']]);
                            $transferPlanId = $pdo->lastInsertId();
                        } else {
                            $transferPlanId = $transferPlan['plan_id'];
                        }

                        foreach ($activeMemberships as $m) {
                            $pdo->prepare("UPDATE memberships SET plan_id = ? WHERE membership_id = ?")->execute([$transferPlanId, $m['membership_id']]);
                        }
                    }
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Approved', 'Your gym transfer request has been approved.');
                    
                    $gymOwner = $pdo->query("SELECT owner_user_id FROM gyms WHERE gym_id = {$transfer['to_gym_id']}")->fetch();
                    if ($gymOwner) {
                        notify_user((int)$gymOwner['owner_user_id'], 'system', 'New Member Transfer', 'A member has transferred to your gym.');
                    }
                    
                    flash('Transfer request approved and membership migrated.', 'success');
                } else {
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Rejected', 'Your gym transfer request was rejected.');
                    flash('Transfer request rejected.', 'success');
                }
            }
            redirect('member_transfers');
        }
    }

    $transfers = $pdo->query('
        SELECT 
            t.*,
            u.first_name, u.last_name, u.email,
            from_gym.name as from_gym_name,
            to_gym.name as to_gym_name
        FROM member_transfers t
        JOIN users u ON u.user_id = t.user_id
        JOIN gyms from_gym ON from_gym.gym_id = t.from_gym_id
        JOIN gyms to_gym ON to_gym.gym_id = t.to_gym_id
        ORDER BY t.requested_at DESC
    ')->fetchAll();

    render_header('Member Transfers Log', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Member Transfers Log</h1>
                <p>View the history of members transferring between gyms.</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Member</th>
                        <th>From Gym</th>
                        <th>To Gym</th>
                        <th>Status</th>
                        <th>Date Resolved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$transfers): ?>
                        <tr><td colspan="6" class="text-center">No member transfers found.</td></tr>
                    <?php else: foreach ($transfers as $t): ?>
                        <tr>
                            <td style="color:var(--muted); font-size:13px;"><?= h(date('M j, Y g:i A', strtotime($t['requested_at']))) ?></td>
                            <td>
                                <strong><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong><br>
                                <span style="font-size:12px; color:var(--muted);"><?= h($t['email']) ?></span>
                            </td>
                            <td><?= h($t['from_gym_name']) ?></td>
                            <td><?= h($t['to_gym_name']) ?></td>
                            <td>
                                <?php if ($t['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($t['status'] === 'rejected'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Rejected</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--muted); font-size:13px;">
                                <?= $t['resolved_at'] ? h(date('M j, Y g:i A', strtotime($t['resolved_at']))) : '—' ?>
                            </td>
                            <td>
                                <?php if ($t['status'] === 'pending'): ?>
                                    <form method="post" style="display:inline-block; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="transfer_id" value="<?= $t['transfer_id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn-sm btn-primary" style="padding: 4px 8px; font-size: 12px; background: var(--lime); color: var(--bg); border: none;">Approve</button>
                                        <button type="submit" name="action" value="reject" class="btn-sm btn-ghost" style="padding: 4px 8px; font-size: 12px; color: var(--danger); border: none;">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-size:12px;">Resolved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
    render_footer();
}
