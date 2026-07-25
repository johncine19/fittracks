<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function walk_ins_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner']);

    // Handle walk-in to member conversion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'convert') {
        $transactionId = (int) post('transaction_id');
        $email = trim((string) post('convert_email'));
        $firstName = trim((string) post('convert_first_name'));
        $lastName = trim((string) post('convert_last_name'));

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

    // Handle manual member check-in
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'member_checkin') {
        db()->prepare('INSERT INTO attendance (user_id, schedule_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, NOW(), "manual", ?)')
            ->execute([post('user_id'), post('schedule_id') ?: null, $user['user_id']]);
            
        $amount = (float) post('amount_paid');
        if ($amount > 0) {
            $memberInfo = db()->query('SELECT first_name, last_name, phone FROM users WHERE user_id = ' . (int)post('user_id'))->fetch();
            $guestName = $memberInfo['first_name'] . ' ' . $memberInfo['last_name'];
            $contactInfo = $memberInfo['phone'] ?: 'N/A';
            db()->prepare('INSERT INTO walk_in_transactions (guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by, converted_to_member_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([$guestName, $contactInfo, $amount, post('payment_method'), $user['user_id'], post('user_id')]);
        }
        audit_log($user['user_id'], 'member_checkin', 'walk_in', (string) post('user_id'), json_encode(['amount' => $amount]));
        flash('Member check-in recorded successfully as attendance.', 'success');
        redirect('attendance');
    }

    // Handle new walk-in recording
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') !== 'convert' && post('action') !== 'member_checkin') {
        $contact_info = preg_replace('/[^0-9]/', '', (string)post('contact_info'));
        if ($contact_info !== '' && strlen($contact_info) !== 11) {
            flash('Phone number must be exactly 11 digits.', 'danger');
            redirect('walk_ins');
        }
        $contact_info = $contact_info ?: 'N/A';

        $stmt = db()->prepare('INSERT INTO walk_in_transactions (guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by) VALUES (?, ?, ?, ?, NOW(), ?)');
        $stmt->execute([
            post('guest_name'),
            $contact_info,
            post('amount_paid'),
            post('payment_method') ?: 'cash',
            $user['user_id']
        ]);
        audit_log($user['user_id'], 'create', 'walk_in', (string) db()->lastInsertId(), json_encode(['guest_name' => post('guest_name'), 'amount' => post('amount_paid')]));
        flash('Walk-in transaction recorded successfully.', 'success');
        redirect('walk_ins');
    }

    $rows = db()->query(
        'SELECT w.*, u.first_name AS member_first, u.last_name AS member_last, u.email AS member_email
         FROM walk_in_transactions w
         LEFT JOIN users u ON u.user_id = w.converted_to_member_id
         ORDER BY w.visit_date DESC'
    )->fetchAll();
    $activeMembers = db()->query('
        SELECT u.user_id, CONCAT(u.first_name, " ", u.last_name) AS name,
               EXISTS(SELECT 1 FROM memberships m WHERE m.user_id = u.user_id AND m.status = "active" AND m.end_date >= CURDATE()) AS has_active_membership
        FROM users u 
        WHERE u.role = "member" AND u.status = "active" 
        ORDER BY u.first_name
    ')->fetchAll();
    $todaySchedules = db()->query('SELECT s.schedule_id, CONCAT(c.class_name, " - ", DATE_FORMAT(s.start_datetime, "%h:%i %p")) AS label FROM class_schedules s JOIN classes c ON c.class_id = s.class_id WHERE DATE(s.start_datetime) = CURDATE() ORDER BY s.start_datetime')->fetchAll();
    
    render_header('Walk-in Transactions', $user);
    ?>
    <section class="panel">
        <div class="page-header">
            <div>
                <h1>Walk-in Transactions</h1>
                <p>Record visits and payments for non-members.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="document.getElementById('memberCheckInModal').showModal()" class="btn btn-secondary">Member Check-in</button>
                <button onclick="document.getElementById('recordWalkInModal').showModal()">+ Record Walk-in</button>
            </div>
        </div>

        <dialog id="memberCheckInModal" class="modal">
            <div class="modal-header">
                <h3>Member Check-in</h3>
                <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" class="form grid-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="member_checkin">
                    <label>Member
                        <select name="user_id" id="member_select" required onchange="handleMemberSelection()">
                            <option value="" data-has-membership="0">Select a member...</option>
                            <?php foreach ($activeMembers as $m): ?>
                                <option value="<?= $m['user_id'] ?>" data-has-membership="<?= $m['has_active_membership'] ? 1 : 0 ?>">
                                    <?= h($m['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div id="membership_status_badge" style="margin-bottom: 15px; font-size: 13px; grid-column: 1 / -1; display: none;"></div>
                    <label>Class Session (Optional)
                        <select name="schedule_id">
                            <option value="">None (Gym Visit)</option>
                            <?php foreach ($todaySchedules as $s): ?>
                                <option value="<?= $s['schedule_id'] ?>"><?= h($s['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div id="payment_fields_container" style="display: contents;">
                        <label>Amount Paid
                            <input name="amount_paid" id="amount_paid" type="number" step="0.01" min="0" placeholder="0.00">
                        </label>
                        <label>Payment Method
                            <select name="payment_method" id="payment_method">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </label>
                    </div>
                    <button style="grid-column: 1 / -1; margin-top: 10px; background: var(--lime); color: var(--bg);">Record Attendance</button>
                </form>
            </div>
            <script>
            function handleMemberSelection() {
                const select = document.getElementById('member_select');
                const selectedOption = select.options[select.selectedIndex];
                const hasMembership = selectedOption.getAttribute('data-has-membership') === '1';
                const badge = document.getElementById('membership_status_badge');
                const paymentFields = document.getElementById('payment_fields_container');
                const amountInput = document.getElementById('amount_paid');
                const paymentMethod = document.getElementById('payment_method');

                if (select.value === '') {
                    badge.style.display = 'none';
                    badge.innerHTML = '';
                    paymentFields.style.display = 'contents';
                    amountInput.value = '';
                    amountInput.required = false;
                    return;
                }

                badge.style.display = 'block';

                if (hasMembership) {
                    badge.innerHTML = '<span style="color: var(--lime); font-weight: bold;">✔ Active Membership</span> - No payment required.';
                    paymentFields.style.display = 'none';
                    amountInput.value = ''; // Will default to 0 on backend
                    amountInput.required = false;
                    paymentMethod.disabled = true;
                } else {
                    badge.innerHTML = '<span style="color: var(--danger); font-weight: bold;">⚠ No Active Membership</span> - Walk-in fee required.';
                    paymentFields.style.display = 'contents';
                    amountInput.required = true;
                    paymentMethod.disabled = false;
                }
            }
            </script>
        </dialog>

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
                        <input name="convert_first_name" id="convert_first_name" required placeholder="First name">
                    </label>
                    <label>Last Name
                        <input name="convert_last_name" id="convert_last_name" required placeholder="Last name">
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
            $todayRevenue = (float) scalar('SELECT SUM(amount_paid) FROM walk_in_transactions WHERE DATE(visit_date) = CURDATE()');
            $todayCount = (int) scalar('SELECT COUNT(*) FROM walk_in_transactions WHERE DATE(visit_date) = CURDATE()');
        ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px; margin-bottom: 24px;">
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
