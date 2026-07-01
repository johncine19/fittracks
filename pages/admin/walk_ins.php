<?php
declare(strict_types=1);

function walk_ins_page(): void
{
    $user = require_roles(['admin', 'staff']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contact_info = preg_replace('/[^0-9]/', '', (string)post('contact_info'));
        if (strlen($contact_info) !== 11) {
            flash('Phone number must be exactly 11 digits.', 'danger');
            redirect('walk_ins');
        }

        $stmt = db()->prepare('INSERT INTO walk_in_transactions (guest_name, contact_info, amount_paid, visit_date, processed_by) VALUES (?, ?, ?, NOW(), ?)');
        $stmt->execute([
            post('guest_name'),
            $contact_info,
            post('amount_paid'),
            $user['user_id']
        ]);
        flash('Walk-in transaction recorded successfully.', 'success');
        redirect('walk_ins');
    }

    $rows = db()->query('SELECT * FROM walk_in_transactions ORDER BY visit_date DESC')->fetchAll();
    
    render_header('Walk-in Transactions', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Walk-in Transactions</h1>
                <p>Record visits and payments for non-members.</p>
            </div>
            <button onclick="document.getElementById('recordWalkInModal').showModal()">+ Record Walk-in</button>
        </div>

        <dialog id="recordWalkInModal" class="modal">
            <div class="modal-header">
                <h3>Record Walk-in</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <label>Guest Name
                        <input name="guest_name" placeholder="John Doe" required>
                    </label>
                    <label>Contact Info
                        <input name="contact_info" type="tel" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789" required>
                    </label>
                     <label>Email
                        <input name="email" placeholder="Email" required>
                    </label>
                    <label>Amount Paid
                        <input name="amount_paid" type="number" step="0.01" min="0" placeholder="0.00" required>
                    </label>
                    <button style="grid-column: 1 / -1; margin-top: 10px;">Record Transaction</button>
                </form>
            </div>
        </dialog>

        <?php if (!$rows): ?>
            <div class="empty-state">
                <p>No walk-in transactions found.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Guest Name</th>
                        <th>Contact Info</th>
                        <th>Amount Paid</th>
                        <th>Visit Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= h($row['guest_name']) ?></strong></td>
                        <td><?= h($row['contact_info']) ?></td>
                        <td>$<?= h($row['amount_paid']) ?></td>
                        <td style="color:var(--muted);font-size:12px"><?= h(date('M j, Y g:i A', strtotime($row['visit_date']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
