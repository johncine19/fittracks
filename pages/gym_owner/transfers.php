<?php

declare(strict_types=1);

function gym_owner_transfers_page(): void
{
    $user = require_roles(['gym_owner']);
    $pdo = db();

    // Verify gym ownership
    $gym = $pdo->query("SELECT gym_id FROM gyms WHERE owner_user_id = {$user['user_id']} LIMIT 1")->fetch();
    if (!$gym) {
        flash('You must have an approved gym to access this page.', 'danger');
        redirect('dashboard');
    }
    $gymId = (int) $gym['gym_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        $transferId = (int) post('transfer_id');
        $transferType = post('transfer_type'); // 'outgoing' or 'incoming'

        $transfer = $pdo->query("SELECT * FROM member_transfers WHERE transfer_id = $transferId")->fetch();

        if ($transfer) {
            if ($transferType === 'outgoing' && $transfer['from_gym_id'] === $gymId && $transfer['status'] === 'pending_current_gym') {
                if ($action === 'approve') {
                    $pdo->prepare('UPDATE member_transfers SET status = "pending_receiving_gym" WHERE transfer_id = ?')
                        ->execute([$transferId]);
                    
                    // Notify receiving gym owner
                    $receivingGymOwner = scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [$transfer['to_gym_id']]);
                    if ($receivingGymOwner) {
                        notify_user((int) $receivingGymOwner, 'system', 'Incoming Transfer Request', 'A member has been released by their current gym and is requesting to transfer to yours. Please review in Member Transfers.');
                    }
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Update', 'Your current gym has approved your release. Waiting for the destination gym to approve.');
                    flash('Member release approved.', 'success');
                } elseif ($action === 'reject') {
                    $pdo->prepare('UPDATE member_transfers SET status = "rejected", resolved_at = NOW() WHERE transfer_id = ?')
                        ->execute([$transferId]);
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Rejected', 'Your gym transfer request was rejected by your current gym.');
                    flash('Member transfer rejected.', 'success');
                }
            } elseif ($transferType === 'incoming' && $transfer['to_gym_id'] === $gymId && $transfer['status'] === 'pending_receiving_gym') {
                if ($action === 'approve') {
                    $pdo->prepare('UPDATE member_transfers SET status = "approved", resolved_at = NOW() WHERE transfer_id = ?')
                        ->execute([$transferId]);

                    // Migrate active memberships to the new gym
                    $activeMemberships = query_all("SELECT m.membership_id FROM memberships m JOIN membership_plans p ON p.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = 'active' AND p.gym_id = ?", [$transfer['user_id'], $transfer['from_gym_id']]);
                    
                    if ($activeMemberships) {
                        // Find or create a "Transfer Plan" for the new gym
                        $transferPlan = $pdo->query("SELECT plan_id FROM membership_plans WHERE gym_id = {$transfer['to_gym_id']} AND plan_name = 'Transferred Membership'")->fetch();
                        if (!$transferPlan) {
                            $pdo->prepare("INSERT INTO membership_plans (gym_id, plan_name, duration_days, price, description, is_active) VALUES (?, 'Transferred Membership', 30, 0, 'Automatically created for transferred members', 0)")->execute([$transfer['to_gym_id']]);
                            $transferPlanId = $pdo->lastInsertId();
                        } else {
                            $transferPlanId = $transferPlan['plan_id'];
                        }

                        foreach ($activeMemberships as $m) {
                            $pdo->prepare("UPDATE memberships SET plan_id = ? WHERE membership_id = ?")->execute([$transferPlanId, $m['membership_id']]);
                        }
                    }
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Approved', 'Your gym transfer request has been fully approved! Welcome to your new gym.');
                    flash('Transfer request approved and membership migrated.', 'success');
                } elseif ($action === 'reject') {
                    $pdo->prepare('UPDATE member_transfers SET status = "rejected", resolved_at = NOW() WHERE transfer_id = ?')
                        ->execute([$transferId]);
                    notify_user((int)$transfer['user_id'], 'system', 'Transfer Rejected', 'Your gym transfer request was rejected by the destination gym.');
                    flash('Transfer request rejected.', 'success');
                }
            }
        }
        redirect('gym_owner_transfers');
    }

    $outgoing = $pdo->query("
        SELECT 
            t.*,
            u.first_name, u.last_name, u.email,
            to_gym.name as to_gym_name
        FROM member_transfers t
        JOIN users u ON u.user_id = t.user_id
        JOIN gyms to_gym ON to_gym.gym_id = t.to_gym_id
        WHERE t.from_gym_id = $gymId
        ORDER BY t.requested_at DESC
    ")->fetchAll();

    $incoming = $pdo->query("
        SELECT 
            t.*,
            u.first_name, u.last_name, u.email,
            from_gym.name as from_gym_name
        FROM member_transfers t
        JOIN users u ON u.user_id = t.user_id
        JOIN gyms from_gym ON from_gym.gym_id = t.from_gym_id
        WHERE t.to_gym_id = $gymId
        ORDER BY t.requested_at DESC
    ")->fetchAll();

    render_header('Member Transfers', $user);
?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Member Transfers</h1>
                <p>Manage incoming and outgoing member transfer requests.</p>
            </div>
        </div>

        <h2 style="margin-bottom: 15px;">Outgoing Transfers (Leaving Your Gym)</h2>
        <div class="table-container" style="margin-bottom: 40px;">
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Member</th>
                        <th>Transferring To</th>
                        <th>Status</th>
                        <th>Date Resolved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$outgoing): ?>
                        <tr><td colspan="6" class="text-center">No outgoing transfers found.</td></tr>
                    <?php else: foreach ($outgoing as $t): ?>
                        <tr>
                            <td style="color:var(--muted); font-size:13px;"><?= h(date('M j, Y g:i A', strtotime($t['requested_at']))) ?></td>
                            <td>
                                <strong><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong><br>
                                <span style="font-size:12px; color:var(--muted);"><?= h($t['email']) ?></span>
                            </td>
                            <td><?= h($t['to_gym_name']) ?></td>
                            <td>
                                <?php if ($t['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($t['status'] === 'rejected'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Rejected</span>
                                <?php elseif ($t['status'] === 'pending_receiving_gym'): ?>
                                    <span style="color:var(--lime); font-size: 13px;">Released, waiting for destination gym</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Action Required</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--muted); font-size:13px;">
                                <?= $t['resolved_at'] ? h(date('M j, Y g:i A', strtotime($t['resolved_at']))) : '—' ?>
                            </td>
                            <td>
                                <?php if ($t['status'] === 'pending_current_gym'): ?>
                                    <form method="post" style="display:inline-block; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="transfer_type" value="outgoing">
                                        <input type="hidden" name="transfer_id" value="<?= $t['transfer_id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn-sm btn-primary" style="padding: 4px 8px; font-size: 12px; background: var(--lime); color: var(--bg); border: none;" data-confirm="Approve this member's release?">Approve Release</button>
                                        <button type="submit" name="action" value="reject" class="btn-sm btn-ghost" style="padding: 4px 8px; font-size: 12px; color: var(--danger); border: none;" data-confirm="Reject this transfer request?">Reject</button>
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

        <h2 style="margin-bottom: 15px;">Incoming Transfers (Joining Your Gym)</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Member</th>
                        <th>Transferring From</th>
                        <th>Status</th>
                        <th>Date Resolved</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$incoming): ?>
                        <tr><td colspan="6" class="text-center">No incoming transfers found.</td></tr>
                    <?php else: foreach ($incoming as $t): ?>
                        <tr>
                            <td style="color:var(--muted); font-size:13px;"><?= h(date('M j, Y g:i A', strtotime($t['requested_at']))) ?></td>
                            <td>
                                <strong><?= h($t['first_name'] . ' ' . $t['last_name']) ?></strong><br>
                                <span style="font-size:12px; color:var(--muted);"><?= h($t['email']) ?></span>
                            </td>
                            <td><?= h($t['from_gym_name']) ?></td>
                            <td>
                                <?php if ($t['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                <?php elseif ($t['status'] === 'rejected'): ?>
                                    <span class="badge badge-inactive" style="color:var(--danger)">Rejected</span>
                                <?php elseif ($t['status'] === 'pending_current_gym'): ?>
                                    <span style="color:var(--muted); font-size: 13px;">Waiting for previous gym to release</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Action Required</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--muted); font-size:13px;">
                                <?= $t['resolved_at'] ? h(date('M j, Y g:i A', strtotime($t['resolved_at']))) : '—' ?>
                            </td>
                            <td>
                                <?php if ($t['status'] === 'pending_receiving_gym'): ?>
                                    <form method="post" style="display:inline-block; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="transfer_type" value="incoming">
                                        <input type="hidden" name="transfer_id" value="<?= $t['transfer_id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn-sm btn-primary" style="padding: 4px 8px; font-size: 12px; background: var(--lime); color: var(--bg); border: none;" data-confirm="Accept this member to your gym?">Accept</button>
                                        <button type="submit" name="action" value="reject" class="btn-sm btn-ghost" style="padding: 4px 8px; font-size: 12px; color: var(--danger); border: none;" data-confirm="Reject this transfer?">Reject</button>
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
