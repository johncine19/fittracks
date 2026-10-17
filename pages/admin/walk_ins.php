<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function walk_ins_page(): void
{
    $user = require_roles(['platform_admin', 'gym_owner', 'staff']);
    $gym = get_user_gym($user);
    $gymId = $gym ? (int)$gym['gym_id'] : null;

    // -------------------------------------------------------------
    // POST: Handle Walk-In to Member Conversion
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'convert') {
        $transactionId = (int) post('transaction_id');
        $email = strtolower(trim((string) post('convert_email')));
        $firstName = mb_convert_case(trim((string) post('convert_first_name')), MB_CASE_TITLE, 'UTF-8');
        $lastName = mb_convert_case(trim((string) post('convert_last_name')), MB_CASE_TITLE, 'UTF-8');
        $phone = preg_replace('/[^0-9]/', '', (string) post('convert_phone'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid email address.', 'danger');
            redirect('walk_ins');
        }

        if (strlen($phone) !== 11) {
            flash('Mobile number is required and must be exactly 11 digits.', 'danger');
            redirect('walk_ins');
        }

        if (scalar('SELECT user_id FROM users WHERE email = ?', [$email])) {
            flash('A user with that email already exists.', 'danger');
            redirect('walk_ins');
        }

        // Generate a secure random password
        $plainPassword = bin2hex(random_bytes(4)) . 'A1';

        $pdo = db();
        $pdo->prepare(
            'INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, email_verified_at)
             VALUES ("member", ?, ?, ?, ?, ?, "active", NOW())'
        )->execute([$firstName, $lastName, $email, password_hash($plainPassword, PASSWORD_DEFAULT), $phone]);
        $newUserId = (int) $pdo->lastInsertId();

        $walkInRecord = $pdo->prepare('SELECT gym_id FROM walk_in_transactions WHERE transaction_id = ?');
        $walkInRecord->execute([$transactionId]);
        $walkInRecord = $walkInRecord->fetch();
        if ($walkInRecord && $walkInRecord['gym_id']) {
            $pdo->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')->execute([$walkInRecord['gym_id'], $newUserId]);
        }

        // Link walk-in record to the new member
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
                : 'Walk-in converted to member, but credentials email could not be sent. Password: ' . $plainPassword,
            $credMailSent ? 'success' : 'warning'
        );
        redirect('walk_ins');
    }

    // -------------------------------------------------------------
    // POST: Record New Walk-In Transaction
    // -------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') !== 'convert') {
        $contact_info = preg_replace('/[^0-9]/', '', (string)post('contact_info'));
        if (strlen($contact_info) !== 11) {
            flash('Mobile number is required and must be exactly 11 digits.', 'danger');
            redirect('walk_ins');
        }
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

    // -------------------------------------------------------------
    // Data Query & Pagination Setup
    // -------------------------------------------------------------
    $perPage = 15;
    $page = max(1, (int)($_GET['p'] ?? 1));
    $search = trim((string)($_GET['q'] ?? ''));
    $filter = trim((string)($_GET['filter'] ?? 'all'));

    $whereClauses = [];
    $queryParams = [];

    if (($user['role'] === 'gym_owner' || $user['role'] === 'staff') && $gymId) {
        $whereClauses[] = 'w.gym_id = ?';
        $queryParams[] = $gymId;
    }

    if ($search !== '') {
        $whereClauses[] = '(w.guest_name LIKE ? OR w.contact_info LIKE ?)';
        $queryParams[] = '%' . $search . '%';
        $queryParams[] = '%' . $search . '%';
    }

    if ($filter === 'walkin') {
        $whereClauses[] = 'w.converted_to_member_id IS NULL';
    } elseif ($filter === 'converted') {
        $whereClauses[] = 'w.converted_to_member_id IS NOT NULL';
    } elseif ($filter === 'cash') {
        $whereClauses[] = 'LOWER(w.payment_method) = "cash"';
    } elseif ($filter === 'gcash') {
        $whereClauses[] = 'LOWER(w.payment_method) = "gcash"';
    }

    $whereSql = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    $totalWalkIns = (int) scalar('SELECT COUNT(*) FROM walk_in_transactions w' . $whereSql, $queryParams);
    $totalPages = max(1, (int)ceil($totalWalkIns / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $query = 'SELECT w.*, u.first_name AS member_first, u.last_name AS member_last, u.email AS member_email
         FROM walk_in_transactions w
         LEFT JOIN users u ON u.user_id = w.converted_to_member_id'
         . $whereSql . ' ORDER BY w.visit_date DESC, w.transaction_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    
    $rows = db()->prepare($query);
    $rows->execute($queryParams);
    $rows = $rows->fetchAll();
    
    $gymWalkInFee = $gymId ? (float) (scalar('SELECT walk_in_fee FROM gyms WHERE gym_id = ?', [$gymId]) ?: 100.0) : 100.0;
    $gymName = $gym['name'] ?? 'FitTracks';

    // -------------------------------------------------------------
    // Fast Telemetry Aggregates (2 single queries)
    // -------------------------------------------------------------
    $statClauses = [];
    $statParams = [];
    if (($user['role'] === 'gym_owner' || $user['role'] === 'staff') && $gymId) {
        $statClauses[] = 'gym_id = ?';
        $statParams[] = $gymId;
    }
    $statWhere = $statClauses ? ' WHERE ' . implode(' AND ', $statClauses) : '';

    // Today's metrics
    $stmtToday = db()->prepare(
        'SELECT 
            COALESCE(SUM(amount_paid), 0) AS today_rev,
            COUNT(*) AS today_cnt,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) = "cash" THEN amount_paid ELSE 0 END), 0) AS today_cash,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) = "gcash" THEN amount_paid ELSE 0 END), 0) AS today_gcash,
            COUNT(CASE WHEN converted_to_member_id IS NOT NULL THEN 1 END) AS today_conv
         FROM walk_in_transactions' . ($statWhere ? $statWhere . ' AND' : ' WHERE') . ' DATE(visit_date) = CURDATE()'
    );
    $stmtToday->execute($statParams);
    $todayStats = $stmtToday->fetch() ?: [];

    $todayRevenue   = (float) ($todayStats['today_rev'] ?? 0);
    $todayCount     = (int) ($todayStats['today_cnt'] ?? 0);
    $todayCash      = (float) ($todayStats['today_cash'] ?? 0);
    $todayGcash     = (float) ($todayStats['today_gcash'] ?? 0);
    $todayConverted = (int) ($todayStats['today_conv'] ?? 0);

    // All-time totals
    $stmtAll = db()->prepare(
        'SELECT 
            COUNT(*) AS all_cnt,
            COUNT(CASE WHEN converted_to_member_id IS NOT NULL THEN 1 END) AS all_conv
         FROM walk_in_transactions' . $statWhere
    );
    $stmtAll->execute($statParams);
    $allStats = $stmtAll->fetch() ?: [];

    $allTimeCount   = (int) ($allStats['all_cnt'] ?? 0);
    $totalConverted = (int) ($allStats['all_conv'] ?? 0);
    $convRate = $allTimeCount > 0 ? round(($totalConverted / $allTimeCount) * 100, 1) : 0;

    render_header('Walk-in Transactions', $user);
    ?>
    <link rel="stylesheet" href="<?= h(asset_url('css/pages/walk_ins.css')) ?>">

    <div class="fit-walkin-container">
        <!-- FITTRACKS ATHLETIC HERO BANNER -->
        <div class="fit-walkin-hero">
            <div class="fit-hero-content">
                <div class="fit-hero-badge-row">
                    <span class="fit-hero-meta"><?= h($gymName) ?> &bull; <?= date('l, F j, Y') ?></span>
                </div>
                <h1 class="fit-hero-title">Guest Walk-In &amp; Day Pass Terminal</h1>
                <p class="fit-hero-desc">
                    Register guest drop-ins, collect entry passes via Cash or GCash, and fast-track visitors into permanent FitTracks members.
                </p>
            </div>
            <div class="fit-hero-actions">
                <button type="button" class="fit-btn-primary" onclick="document.getElementById('recordWalkInModal').showModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span class="fit-btn-text-full">Issue Walk-In Pass</span>
                    <span class="fit-btn-text-short">Issue Pass</span>
                </button>
                <a href="index.php?page=scanner" class="fit-btn-secondary" title="Launch Turnstile Scanner">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    QR Scanner
                </a>
            </div>
        </div>

        <!-- FITTRACKS 4-CARD ATHLETIC STAT GRID (2x2 on Mobile, Clean Vector Icons) -->
        <div class="fit-stat-grid">
            <!-- Stat 1: Revenue -->
            <div class="fit-stat-card is-revenue">
                <div class="fit-stat-top">
                    <span class="fit-stat-label">Today's Walk-in Revenue</span>
                </div>
                <div class="fit-stat-number is-lime"><?= h(money($todayRevenue)) ?></div>
                <div class="fit-stat-footer">
                    <span class="fit-mini-pill" title="Cash collections today">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.3"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                        Cash: <?= h(money($todayCash)) ?>
                    </span>
                    <span class="fit-mini-pill" title="GCash collections today">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.3"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
                        GCash: <?= h(money($todayGcash)) ?>
                    </span>
                </div>
            </div>

            <!-- Stat 2: Guest Visitors -->
            <div class="fit-stat-card is-visitors">
                <div class="fit-stat-top">
                    <span class="fit-stat-label">Today's Walk-in Visitors</span>
                </div>
                <div class="fit-stat-number is-ink">
                    <?= $todayCount ?> <span class="fit-stat-unit">Pass<?= $todayCount === 1 ? '' : 'es' ?></span>
                </div>
                <div class="fit-stat-footer">
                    <span class="fit-pulse-dot"></span>
                    <span class="fit-stat-subtext">Active floor drop-ins logged today</span>
                </div>
            </div>

            <!-- Stat 3: Member Conversions -->
            <div class="fit-stat-card is-conversion">
                <div class="fit-stat-top">
                    <span class="fit-stat-label">Member Conversions</span>
                </div>
                <div class="fit-stat-number is-amber">
                    <?= $todayConverted ?> <span class="fit-stat-unit">Today</span>
                </div>
                <div class="fit-stat-footer">
                    <span class="fit-mini-pill" title="All-time walk-ins upgraded to official members">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="#f59e0b" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                        <?= $totalConverted ?> Converted (<?= $convRate ?>%)
                    </span>
                </div>
            </div>

            <!-- Stat 4: Day Tariff -->
            <div class="fit-stat-card is-tariff">
                <div class="fit-stat-top">
                    <span class="fit-stat-label">Entry Pass Fee</span>
                </div>
                <div class="fit-stat-number is-lime"><?= h(money($gymWalkInFee)) ?></div>
                <div class="fit-stat-footer">
                    <span class="fit-stat-subtext">Standard drop-in rate per session</span>
                </div>
            </div>
        </div>

        <!-- SEARCH, FILTERS & ACTION BAR -->
        <div class="fit-filter-panel">
            <form method="get" action="index.php" class="fit-search-form" id="walkInSearchForm">
                <input type="hidden" name="page" value="walk_ins">
                <input type="hidden" name="filter" value="<?= h($filter) ?>" id="filterInput">
                <div class="fit-search-input-wrap">
                    <svg class="fit-search-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" name="q" id="walkInSearchInput" value="<?= h($search) ?>" placeholder="Search guest athlete name or 11-digit phone..." autocomplete="off">
                    <?php if ($search !== ''): ?>
                        <a href="index.php?page=walk_ins&filter=<?= h($filter) ?>" class="fit-search-clear" title="Clear search">&times;</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Desktop Filter Chips -->
            <div class="fit-filter-chips">
                <button type="button" class="fit-chip <?= $filter === 'all' ? 'is-active' : '' ?>" data-filter="all">All Passes</button>
                <button type="button" class="fit-chip <?= $filter === 'walkin' ? 'is-active' : '' ?>" data-filter="walkin">Day Passes</button>
                <button type="button" class="fit-chip <?= $filter === 'converted' ? 'is-active' : '' ?>" data-filter="converted">Converted</button>
                <button type="button" class="fit-chip <?= $filter === 'cash' ? 'is-active' : '' ?>" data-filter="cash">Cash</button>
                <button type="button" class="fit-chip <?= $filter === 'gcash' ? 'is-active' : '' ?>" data-filter="gcash">GCash</button>
            </div>

            <!-- Mobile Filter Dropdown -->
            <div class="fit-filter-dropdown-wrap">
                <svg class="fit-filter-dropdown-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                <select class="fit-filter-dropdown" id="walkInFilterDropdown" aria-label="Filter walk-in transactions">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Passes (<?= $totalWalkIns ?>)</option>
                    <option value="walkin" <?= $filter === 'walkin' ? 'selected' : '' ?>>Day Passes Only</option>
                    <option value="converted" <?= $filter === 'converted' ? 'selected' : '' ?>>Converted to Member</option>
                    <option value="cash" <?= $filter === 'cash' ? 'selected' : '' ?>>Cash Payments</option>
                    <option value="gcash" <?= $filter === 'gcash' ? 'selected' : '' ?>>GCash Payments</option>
                </select>
            </div>

            <div class="fit-record-counter" id="fitRecordCounter">
                Showing <strong><?= count($rows) ?></strong> of <strong><?= $totalWalkIns ?></strong> entries
            </div>
        </div>

        <!-- TRANSACTIONS CONTAINER -->
        <section class="fit-pass-section">
            <?php if (!$rows): ?>
                <div class="fit-empty-state">
                    <div class="fit-empty-icon">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                    </div>
                    <h3>No Walk-In Passes Found</h3>
                    <p>
                        <?= $search !== '' || $filter !== 'all' ? 'No records match your active search filter.' : 'No guest walk-in transactions have been recorded yet.' ?>
                    </p>
                    <div style="display:flex; gap:10px; justify-content:center; margin-top:16px;">
                        <?php if ($search !== '' || $filter !== 'all'): ?>
                            <a href="index.php?page=walk_ins" class="fit-btn-secondary" style="font-size:13px;">Clear All Filters</a>
                        <?php endif; ?>
                        <button type="button" class="fit-btn-primary" onclick="document.getElementById('recordWalkInModal').showModal()">
                            + Issue First Walk-In Pass
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <!-- DESKTOP PASS TICKETING TABLE -->
                <div class="table-wrap walkins-desktop-table fit-table-wrap">
                    <table class="fit-table">
                        <thead>
                            <tr>
                                <th>GUEST ATHLETE</th>
                                <th>CONTACT NUMBER</th>
                                <th>AMOUNT PAID</th>
                                <th>VISIT TIMESTAMP</th>
                                <th>PAYMENT METHOD</th>
                                <th>STATUS</th>
                                <th style="text-align:right;">FRONT DESK ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="walkInTableBody">
                        <?php foreach ($rows as $row):
                            $nameParts = explode(' ', trim($row['guest_name']), 2);
                            $guestFirst = $nameParts[0] ?? '';
                            $guestLast = $nameParts[1] ?? '';
                            $initials = strtoupper(substr($guestFirst, 0, 1) . substr($guestLast, 0, 1)) ?: 'W';
                            $isMember = !empty($row['converted_to_member_id']);
                            $method = strtolower($row['payment_method'] ?? 'cash');
                            $passId = 'TK-' . str_pad((string)$row['transaction_id'], 5, '0', STR_PAD_LEFT);
                        ?>
                            <tr class="fit-table-row" data-search="<?= strtolower(h($row['guest_name'] . ' ' . $row['contact_info'] . ' ' . $method)) ?>" data-status="<?= $isMember ? 'converted' : 'walkin' ?>" data-method="<?= h($method) ?>">
                                <td>
                                    <div class="fit-guest-cell">
                                        <div class="fit-avatar <?= $isMember ? 'has-glow' : '' ?>">
                                            <?= h($initials) ?>
                                        </div>
                                        <div>
                                            <div class="fit-guest-name"><?= h($row['guest_name']) ?></div>
                                            <span class="fit-pass-id"><?= $passId ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fit-contact-cell">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                        <span><?= h($row['contact_info'] ?: 'N/A') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="fit-amount-cell"><?= h(money($row['amount_paid'])) ?></span>
                                </td>
                                <td>
                                    <div class="fit-time-cell">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                        <span><?= h(date('M j, Y • g:i A', strtotime($row['visit_date']))) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($method === 'gcash'): ?>
                                        <span class="fit-method-pill is-gcash">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
                                            GCash
                                        </span>
                                    <?php else: ?>
                                        <span class="fit-method-pill is-cash">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle></svg>
                                            Cash
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isMember): ?>
                                        <span class="fit-badge is-member" title="Registered as official member">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            MEMBER
                                        </span>
                                    <?php else: ?>
                                        <span class="fit-badge is-walkin" title="Non-member day pass">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                            DAY PASS
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if (!$isMember): ?>
                                        <button type="button" class="fit-btn-convert btn-convert-member"
                                            data-id="<?= (int)$row['transaction_id'] ?>"
                                            data-first="<?= h($guestFirst) ?>"
                                            data-last="<?= h($guestLast) ?>"
                                            data-phone="<?= h(($row['contact_info'] && $row['contact_info'] !== 'N/A') ? $row['contact_info'] : '') ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                            Convert to Member
                                        </button>
                                    <?php else: ?>
                                        <span class="fit-converted-tag">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            Joined Member
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- MOBILE PASS CARDS VIEW -->
                <div class="walkins-mobile-cards fit-mobile-cards">
                    <?php foreach ($rows as $row):
                        $nameParts = explode(' ', trim($row['guest_name']), 2);
                        $guestFirst = $nameParts[0] ?? '';
                        $guestLast = $nameParts[1] ?? '';
                        $initials = strtoupper(substr($guestFirst, 0, 1) . substr($guestLast, 0, 1)) ?: 'W';
                        $isMember = !empty($row['converted_to_member_id']);
                        $method = strtolower($row['payment_method'] ?? 'cash');
                        $passId = 'TK-' . str_pad((string)$row['transaction_id'], 5, '0', STR_PAD_LEFT);
                    ?>
                    <div class="fit-mobile-ticket <?= $isMember ? 'is-converted-ticket' : '' ?>" data-search="<?= strtolower(h($row['guest_name'] . ' ' . $row['contact_info'] . ' ' . $method)) ?>" data-status="<?= $isMember ? 'converted' : 'walkin' ?>" data-method="<?= h($method) ?>">
                        <div class="fit-ticket-head">
                            <div class="fit-ticket-guest">
                                <span class="fit-avatar <?= $isMember ? 'has-glow' : '' ?>"><?= h($initials) ?></span>
                                <div>
                                    <div class="fit-mobile-name"><?= h($row['guest_name']) ?></div>
                                    <span class="fit-pass-id"><?= $passId ?></span>
                                </div>
                            </div>
                            <?php if ($isMember): ?>
                                <span class="fit-badge is-member">MEMBER</span>
                            <?php else: ?>
                                <span class="fit-badge is-walkin">DAY PASS</span>
                            <?php endif; ?>
                        </div>

                        <div class="fit-ticket-divider"></div>

                        <div class="fit-ticket-body">
                            <div class="fit-ticket-row">
                                <span class="fit-ticket-label">Amount Paid</span>
                                <span class="fit-ticket-amount"><?= h(money($row['amount_paid'])) ?></span>
                            </div>
                            <div class="fit-ticket-row">
                                <span class="fit-ticket-label">Payment Method</span>
                                <?php if ($method === 'gcash'): ?>
                                    <span class="fit-method-pill is-gcash">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
                                        GCash
                                    </span>
                                <?php else: ?>
                                    <span class="fit-method-pill is-cash">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                                        Cash
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="fit-ticket-row">
                                <span class="fit-ticket-label">Visit Timestamp</span>
                                <span class="fit-ticket-val"><?= h(date('M j, Y • g:i A', strtotime($row['visit_date']))) ?></span>
                            </div>
                            <div class="fit-ticket-row">
                                <span class="fit-ticket-label">Contact</span>
                                <span class="fit-ticket-val"><?= h($row['contact_info'] ?: 'No phone') ?></span>
                            </div>
                        </div>

                        <?php if (!$isMember): ?>
                            <div class="fit-ticket-actions">
                                <button type="button" class="fit-btn-convert btn-convert-member" style="width:100%; justify-content:center; padding:10px 14px;"
                                    data-id="<?= (int)$row['transaction_id'] ?>"
                                    data-first="<?= h($guestFirst) ?>"
                                    data-last="<?= h($guestLast) ?>"
                                    data-phone="<?= h(($row['contact_info'] && $row['contact_info'] !== 'N/A') ? $row['contact_info'] : '') ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    Convert to Member
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- PAGINATION BAR -->
                <?php if ($totalWalkIns > 0): ?>
                    <div class="pagination-bar fit-pagination-bar">
                        <div class="pagination-info">
                            Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $perPage, $totalWalkIns) ?></strong> of <strong><?= $totalWalkIns ?></strong> entries
                        </div>
                        <?php
                            $pageUrl = 'index.php?page=walk_ins' . ($search !== '' ? '&q=' . urlencode($search) : '') . ($filter !== 'all' ? '&filter=' . urlencode($filter) : '');
                            render_pagination($page, $totalPages, $pageUrl, 'p');
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- FITTRACKS RECORD WALK-IN MODAL -->
    <dialog id="recordWalkInModal" class="modal fit-modal">
        <div class="modal-header fit-modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="fit-modal-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:18px; font-weight:800; color:var(--ink);">Issue Guest Walk-In Pass</h3>
                    <div style="font-size:12px; color:var(--muted); margin-top:2px;">Front desk drop-in registration</div>
                </div>
            </div>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body fit-modal-body">
            <div class="fit-modal-banner">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Standard Walk-in Day Fee for <strong><?= h($gymName) ?></strong> is <strong><?= h(money($gymWalkInFee)) ?></strong>.</span>
            </div>
            <form method="post" class="form fit-modal-form">
                <?= csrf_field() ?>
                <div class="fit-input-group">
                    <label>Guest Full Name *</label>
                    <div class="fit-input-wrap">
                        <svg class="fit-field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="guest_name" placeholder="e.g. John Doe" required autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                    </div>
                </div>

                <div class="fit-input-group">
                    <label>Mobile Number (11 Digits) *</label>
                    <div class="fit-input-wrap">
                        <svg class="fit-field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        <input name="contact_info" type="tel" pattern="[0-9]{11}" maxlength="11" required title="Please enter exactly 11 digits" placeholder="09123456789" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </div>
                </div>

                <div class="fit-input-group">
                    <label>Amount Paid (₱) *</label>
                    <div class="fit-input-wrap">
                        <span class="fit-field-prefix" style="color:var(--lime); font-weight:800;">₱</span>
                        <input name="amount_paid" type="number" step="0.01" min="0" value="<?= h((string)($gymWalkInFee ?? '100.00')) ?>" placeholder="0.00" required>
                    </div>
                </div>

                <div class="fit-input-group">
                    <label>Payment Method *</label>
                    <div class="fit-payment-selector">
                        <label class="fit-pay-option">
                            <input type="radio" name="payment_method" value="cash" checked>
                            <div class="fit-pay-box">
                                <span class="fit-pay-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                                </span>
                                <div class="fit-pay-text">
                                    <strong>Cash</strong>
                                    <small>Front desk cash box</small>
                                </div>
                            </div>
                        </label>
                        <label class="fit-pay-option">
                            <input type="radio" name="payment_method" value="gcash">
                            <div class="fit-pay-box">
                                <span class="fit-pay-icon">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
                                </span>
                                <div class="fit-pay-text">
                                    <strong>GCash</strong>
                                    <small>E-Wallet QR scan</small>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="fit-btn-primary fit-modal-submit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Issue Pass &amp; Record Payment
                </button>
            </form>
        </div>
    </dialog>

    <!-- FITTRACKS MEMBER CONVERSION MODAL -->
    <dialog id="convertModal" class="modal fit-modal">
        <div class="modal-header fit-modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="fit-modal-icon is-amber">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:18px; font-weight:800; color:var(--ink);">Upgrade to FitTracks Member</h3>
                    <div style="font-size:12px; color:var(--muted); margin-top:2px;">Fast member registration with auto-sent credentials</div>
                </div>
            </div>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body fit-modal-body">
            <div class="fit-modal-banner is-amber-banner">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                <span>A secure account and password will be generated automatically and dispatched to the member's email inbox.</span>
            </div>
            <form method="post" class="form fit-modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="convert">
                <input type="hidden" name="transaction_id" id="convert_transaction_id">
                
                <div class="fit-form-row-2">
                    <div class="fit-input-group">
                        <label>First Name *</label>
                        <div class="fit-input-wrap">
                            <input name="convert_first_name" id="convert_first_name" required placeholder="First name" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                        </div>
                    </div>
                    <div class="fit-input-group">
                        <label>Last Name *</label>
                        <div class="fit-input-wrap">
                            <input name="convert_last_name" id="convert_last_name" required placeholder="Last name" autocapitalize="words" style="text-transform: capitalize;" onblur="this.value = this.value.trim().replace(/\b\w/g, l => l.toUpperCase())">
                        </div>
                    </div>
                </div>

                <div class="fit-input-group">
                    <label>Email Address (For Member Sign In) *</label>
                    <div class="fit-input-wrap">
                        <svg class="fit-field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input name="convert_email" type="email" required placeholder="member@example.com" style="text-transform: lowercase;" oninput="this.value = this.value.toLowerCase()" onblur="this.value = this.value.trim().toLowerCase()">
                    </div>
                </div>

                <div class="fit-input-group">
                    <label>Mobile Number (11 Digits) *</label>
                    <div class="fit-input-wrap">
                        <svg class="fit-field-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        <input name="convert_phone" id="convert_phone" type="tel" pattern="[0-9]{11}" maxlength="11" required title="Please enter exactly 11 digits" placeholder="09123456789" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </div>
                </div>

                <button type="submit" class="fit-btn-primary fit-modal-submit is-amber-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    Create Member Account &amp; Send Credentials
                </button>
            </form>
        </div>
    </dialog>


    <script src="<?= h(asset_url('js/pages/walk_ins.js')) ?>"></script>
    <?php
    render_footer();
}
