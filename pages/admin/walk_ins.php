<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function walk_ins_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);
    $gym = get_user_gym($user);
    $gymId = $gym ? (int)$gym['gym_id'] : null;

    // Handle walk-in to member conversion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'convert') {
        $transactionId = (int) post('transaction_id');
        $email = trim((string) post('convert_email'));
        $firstName = mb_convert_case(trim((string) post('convert_first_name')), MB_CASE_TITLE, 'UTF-8');
        $lastName = mb_convert_case(trim((string) post('convert_last_name')), MB_CASE_TITLE, 'UTF-8');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid email address.', 'danger');
            redirect('walk_ins');
        }

        if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
            flash('A user with that email already exists.', 'danger');
            redirect('walk_ins');
        }

        // Generate a secure random password
        $plainPassword = bin2hex(random_bytes(4)) . 'A1'; // 10 chars, guaranteed letter+digit

        $pdo = db();
        $pdo->prepare(
            'INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at)
             VALUES ("member", ?, ?, ?, ?, NULL, "active", NOW())'
        )->execute([$firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT)]);
        $newUserId = (int) $pdo->lastInsertId();

        $walkInRecord = $pdo->prepare('SELECT gym_id FROM walk_in_transactions WHERE transaction_id = ?');
        $walkInRecord->execute([$transactionId]);
        $walkInRecord = $walkInRecord->fetch();
        if ($walkInRecord && $walkInRecord['gym_id']) {
            $pdo->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$walkInRecord['gym_id'], $newUserId]);
        }

        // Link walk-in record to the new member and update guest name
        $pdo->prepare('UPDATE walk_in_transactions SET converted_to_member_id = ?, guest_name = ? WHERE transaction_id = ?')
            ->execute([$newUserId, $firstName . ' ' . $lastName, $transactionId]);

        // Send credentials via PHPMailer
        $credMailSent = false;
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);
            $mail->setFrom($_ENV['SMTP_FROM'] ?? 'no-reply@fittracks.com', $_ENV['SMTP_FROM_NAME'] ?? 'FITTRACKS');
            $mail->addAddress($email, $firstName . ' ' . $lastName);
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to FITTRACKS — Your Member Account';
            $mail->Body =
                'Hi ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',<br><br>'
                . 'Great news! You have been registered as a member at <strong>FITTRACKS</strong>.<br><br>'
                . 'Your login credentials are:<br>'
                . '&bull; <strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>'
                . '&bull; <strong>Password:</strong> ' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '<br><br>'
                . 'Please sign in and change your password as soon as possible.<br><br>'
                . 'Thank you,<br>FITTRACKS Team';
            $mail->send();
            $credMailSent = true;
        } catch (PHPMailerException) {
            // Non-fatal
        }

        notify_user($newUserId, 'system', 'Welcome to FITTRACKS', 'Your member account has been created. Update your profile to get started.');

        audit_log($user['user_id'], 'convert', 'walk_in', (string) $transactionId, json_encode(['new_user_id' => $newUserId, 'email' => $email]));
        flash(
            $credMailSent
                ? 'Walk-in converted to member! Credentials sent to ' . $email . '.'
                : 'Walk-in converted to member, but the credentials email could not be sent. Password: ' . $plainPassword,
            $credMailSent ? 'success' : 'warning'
        );
        redirect('walk_ins');
    }



    // Handle new walk-in recording
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') !== 'convert') {
        $contact_info = preg_replace('/[^0-9]/', '', (string)post('contact_info'));
        if ($contact_info !== '' && strlen($contact_info) !== 11) {
            flash('Phone number must be exactly 11 digits.', 'danger');
            redirect('walk_ins');
        }
        $contact_info = $contact_info ?: 'N/A';
        $guestName = mb_convert_case(trim((string) post('guest_name')), MB_CASE_TITLE, 'UTF-8');

        $stmt = db()->prepare('INSERT INTO walk_in_transactions (gym_id, guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)');
        $stmt->execute([
            $gymId,
            $guestName,
            $contact_info,
            post('amount_paid'),
            post('payment_method') ?: 'cash',
            $user['user_id']
        ]);
        audit_log($user['user_id'], 'create', 'walk_in', (string) db()->lastInsertId(), json_encode(['guest_name' => $guestName, 'amount' => post('amount_paid')]));
        flash('Walk-in transaction recorded successfully.', 'success');
        redirect('walk_ins');
    }

    $query = 'SELECT w.*, u.first_name AS member_first, u.last_name AS member_last, u.email AS member_email
         FROM walk_in_transactions w
         LEFT JOIN users u ON u.user_id = w.converted_to_member_id';
    
    $params = [];
    if ($user['role'] === 'gym_owner' && $gymId) {
        $query .= ' WHERE w.gym_id = ?';
        $params[] = $gymId;
    }
    $query .= ' ORDER BY w.guest_name ASC';
    
    $rows = db()->prepare($query);
    $rows->execute($params);
    $rows = $rows->fetchAll();
    
    render_header('Walk-in Transactions', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Walk-in Transactions</h1>
                <p>Record visits and payments for non-members.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="document.getElementById('recordWalkInModal').showModal()">+ Record Walk-in</button>
            </div>
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
                        <input name="guest_name" placeholder="John Doe" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label>Contact Info (Optional)
                        <input name="contact_info" type="tel" pattern="[0-9]{11}" maxlength="11" title="Please enter exactly 11 digits" placeholder="09123456789">
                    </label>
                    <label>Amount Paid
                        <input name="amount_paid" type="number" step="0.01" min="0" placeholder="0.00" required>
                    </label>
                    <label>Payment Method
                        <select name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </label>
                    <button style="grid-column: 1 / -1; margin-top: 10px;">Record Transaction</button>
                </form>
            </div>
        </dialog>

        <!-- Conversion modal (reused per row via JS) -->
        <dialog id="convertModal" class="modal">
            <div class="modal-header">
                <h3>Convert to Member</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <p class="muted" style="margin-bottom:1rem;font-size:13px;">
                    Create a member account for this walk-in customer. Their login credentials will be emailed automatically.
                </p>
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="convert">
                    <input type="hidden" name="transaction_id" id="convert_transaction_id">
                    <label>First Name
                        <input name="convert_first_name" id="convert_first_name" required placeholder="First name" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label>Last Name
                        <input name="convert_last_name" id="convert_last_name" required placeholder="Last name" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </label>
                    <label style="grid-column:1/-1">Email Address
                        <input name="convert_email" type="email" required placeholder="member@example.com">
                    </label>
                    <p class="muted" style="grid-column:1/-1;font-size:12px;margin:0;">
                        A secure password will be auto-generated and sent to this email address.
                    </p>
                    <button type="submit" class="btn-primary" style="grid-column:1/-1;margin-top:10px;">Create Member Account & Send Credentials</button>
                </form>
            </div>
        </dialog>

        <?php
            $revenueQuery = 'SELECT SUM(amount_paid) FROM walk_in_transactions WHERE DATE(visit_date) = CURDATE()';
            $countQuery = 'SELECT COUNT(*) FROM walk_in_transactions WHERE DATE(visit_date) = CURDATE()';
            $statParams = [];
            
            if ($user['role'] === 'gym_owner' && $gymId) {
                $revenueQuery .= ' AND gym_id = ?';
                $countQuery .= ' AND gym_id = ?';
                $statParams[] = $gymId;
            }
            
            $todayRevenue = (float) scalar($revenueQuery, $statParams);
            $todayCount = (int) scalar($countQuery, $statParams);
        ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap:15px; margin-bottom: 24px;">
            <div style="background:var(--bg); padding:16px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:4px;">Today's Walk-in Revenue</div>
                <div style="font-size:24px; font-weight:bold; color:var(--lime);"><?= h(money($todayRevenue)) ?></div>
            </div>
            <div style="background:var(--bg); padding:16px; border-radius:8px; border:1px solid var(--line);">
                <div style="color:var(--muted); font-size:13px; margin-bottom:4px;">Today's Walk-in Visitors</div>
                <div style="font-size:24px; font-weight:bold; color:var(--ink);"><?= $todayCount ?></div>
            </div>
        </div>

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
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= h($row['guest_name']) ?></strong></td>
                        <td><?= h($row['contact_info']) ?></td>
                        <td><?= h(money($row['amount_paid'])) ?></td>
                        <td style="color:var(--muted);font-size:12px"><?= h(date('M j, Y g:i A', strtotime($row['visit_date']))) ?></td>
                        <td>
                            <?php if ($row['converted_to_member_id']): ?>
                                <span class="badge badge-active">MEMBER</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Walk-in</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$row['converted_to_member_id']): ?>
                                <?php
                                    // Try to split guest_name into first/last
                                    $nameParts = explode(' ', trim($row['guest_name']), 2);
                                    $guestFirst = $nameParts[0] ?? '';
                                    $guestLast = $nameParts[1] ?? '';
                                ?>
                                <button class="btn-sm btn-ghost"
                                    onclick="openConvertModal(<?= (int)$row['transaction_id'] ?>, <?= h(json_encode($guestFirst)) ?>, <?= h(json_encode($guestLast)) ?>)">
                                    Convert to Member
                                </button>
                            <?php else: ?>
                                <span class="muted" style="font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <script>
    function openConvertModal(transactionId, firstName, lastName) {
        document.getElementById('convert_transaction_id').value = transactionId;
        document.getElementById('convert_first_name').value = firstName;
        document.getElementById('convert_last_name').value = lastName;
        document.getElementById('convertModal').showModal();
    }
    </script>
    <?php
    render_footer();
}
