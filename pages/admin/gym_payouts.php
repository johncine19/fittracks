<?php
declare(strict_types=1);

function gym_payouts_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $pdo = db();
    $isPlatformAdmin = $user['role'] === 'platform_admin';
    
    $gymId = null;
    if (!$isPlatformAdmin) {
        $gymId = (int) scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ?', [$user['user_id']]);
        if (!$gymId) {
            flash('No gym found for your account.', 'danger');
            redirect('dashboard');
        }
    }

    // Handle settlement action
    if ($isPlatformAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'settle_payout') {
            $payoutId = (int) post('payout_id');
            $pdo->prepare("UPDATE gym_share_payouts SET status = 'paid' WHERE payout_id = ? AND status = 'pending'")
                ->execute([$payoutId]);
            audit_log($user['user_id'], 'settle_payout', 'gym_share_payout', (string) $payoutId);
            flash('Payout marked as settled.', 'success');
            redirect('gym_payouts');
        }
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    // Build query conditions
    $whereClause = '';
    $params = [];
    if (!$isPlatformAdmin) {
        $whereClause = ' WHERE gsp.gym_id = ? ';
        $params[] = $gymId;
    }

    // Fetch totals
    $totalEarned = (float) scalar("
        SELECT COALESCE(SUM(amount), 0) 
        FROM gym_share_payouts gsp" . $whereClause, $params
    );
    
    $totalPending = (float) scalar("
        SELECT COALESCE(SUM(amount), 0) 
        FROM gym_share_payouts gsp 
        WHERE gsp.status = 'pending'" . ($isPlatformAdmin ? '' : ' AND gsp.gym_id = ?'), 
        $isPlatformAdmin ? [] : [$gymId]
    );

    $totalPaid = (float) scalar("
        SELECT COALESCE(SUM(amount), 0) 
        FROM gym_share_payouts gsp 
        WHERE gsp.status = 'paid'" . ($isPlatformAdmin ? '' : ' AND gsp.gym_id = ?'), 
        $isPlatformAdmin ? [] : [$gymId]
    );

    // Fetch paginated transactions
    $countSql = "SELECT COUNT(*) FROM gym_share_payouts gsp" . $whereClause;
    $totalRows = (int) scalar($countSql, $params);
    $totalPages = (int) ceil($totalRows / $limit);

    $sql = "
        SELECT gsp.*, g.name AS gym_name, p.receipt_number, p.amount AS original_payment_amount, p.payment_date, mp.plan_name
        FROM gym_share_payouts gsp
        JOIN gyms g ON g.gym_id = gsp.gym_id
        JOIN payments p ON p.payment_id = gsp.payment_id
        JOIN memberships m ON m.membership_id = p.membership_id
        JOIN membership_plans mp ON mp.plan_id = m.plan_id
        " . $whereClause . "
        ORDER BY gsp.created_at DESC
        LIMIT " . $limit . " OFFSET " . $offset;
        
    $payouts = query_all($sql, $params);

    render_header('Gym Revenue Share Payouts', $user);
    ?>
    <style>
        .payouts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .payout-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .payout-card-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--lime);
            flex-shrink: 0;
        }
        .payout-card-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .payout-card-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .payout-card-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            line-height: 1.2;
        }
    </style>

    <div class="page-header">
        <h1>Shared Plans Payouts</h1>
        <p><?= $isPlatformAdmin ? 'Track and settle membership subscription revenue shares across opted-in gyms.' : 'View your gym\'s earnings and payouts from shared plans.' ?></p>
    </div>

    <!-- Metrics Cards -->
    <div class="payouts-grid">
        <div class="payout-card">
            <div class="payout-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="payout-card-info">
                <span class="payout-card-label">Total Shared Revenue</span>
                <strong class="payout-card-value" style="color: var(--lime);"><?= h(money($totalEarned)) ?></strong>
            </div>
        </div>

        <div class="payout-card">
            <div class="payout-card-icon" style="color: #f59e0b;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="payout-card-info">
                <span class="payout-card-label">Pending Settlement</span>
                <strong class="payout-card-value" style="color: #f59e0b;"><?= h(money($totalPending)) ?></strong>
            </div>
        </div>

        <div class="payout-card">
            <div class="payout-card-icon" style="color: #10b981;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="payout-card-info">
                <span class="payout-card-label">Settled Revenue</span>
                <strong class="payout-card-value" style="color: #10b981;"><?= h(money($totalPaid)) ?></strong>
            </div>
        </div>
    </div>

    <!-- Payout List Table -->
    <div class="panel">
        <p class="section-label">All Revenue Share Transactions (<?= $totalRows ?>)</p>
        <?php if (!$payouts): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <p>No payout transactions found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php if ($isPlatformAdmin): ?>
                            <th>Gym</th>
                        <?php endif; ?>
                        <th>Shared Plan</th>
                        <th>Original Amount</th>
                        <th>Your Share</th>
                        <th>Payment Date</th>
                        <th>Receipt</th>
                        <th>Status</th>
                        <?php if ($isPlatformAdmin): ?>
                            <th style="text-align:right">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payouts as $payout): 
                        $statusClass = $payout['status'] === 'paid' ? 'badge badge-active' : 'badge';
                        $statusStyle = $payout['status'] === 'pending' ? 'background:rgba(245, 158, 11, 0.15); color:#f59e0b;' : '';
                    ?>
                        <tr>
                            <?php if ($isPlatformAdmin): ?>
                                <td><strong><?= h($payout['gym_name']) ?></strong></td>
                            <?php endif; ?>
                            <td><strong><?= h($payout['plan_name']) ?></strong> <span style="font-size:10px; background: rgba(199,255,34,0.15); color: var(--lime); padding: 2px 6px; border-radius: 12px; margin-left:6px; font-weight:bold; text-transform:uppercase;">Shared</span></td>
                            <td style="color: var(--muted); font-size:13px;"><?= h(money($payout['original_payment_amount'])) ?></td>
                            <td><strong style="color: var(--lime);"><?= h(money($payout['amount'])) ?></strong></td>
                            <td style="font-size: 13px;"><?= h(date('M j, Y', strtotime($payout['payment_date']))) ?></td>
                            <td style="font-family: monospace; font-size:13px; color: var(--muted);"><?= h($payout['receipt_number']) ?></td>
                            <td><span class="<?= $statusClass ?>" style="<?= $statusStyle ?>"><?= h(ucfirst($payout['status'])) ?></span></td>
                            
                            <?php if ($isPlatformAdmin): ?>
                                <td style="text-align:right">
                                    <?php if ($payout['status'] === 'pending'): ?>
                                        <form method="post" style="margin:0; display:inline-block;" onsubmit="return confirm('Mark this shared revenue payout as settled/paid?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="settle_payout">
                                            <input type="hidden" name="payout_id" value="<?= (int) $payout['payout_id'] ?>">
                                            <button type="submit" class="btn-sm btn-primary">Settle Share</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:13px; color:var(--muted)">Settled</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination" style="margin-top: 20px; display:flex; gap: 8px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="index.php?page=gym_payouts&p=<?= $i ?>" class="button-link <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 6px 12px; min-height: unset;"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
    <?php
    render_footer();
}
