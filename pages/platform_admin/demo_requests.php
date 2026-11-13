<?php
declare(strict_types=1);

function platform_demo_requests_page(): void
{
    $user = require_roles(['platform_admin']);
    $pdo = db();

    // ── Handle Actions (Status Update, Notes, Delete) ───────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = trim((string) post('action'));
        $requestId = (int) post('id');

        if ($action === 'update_status' && $requestId > 0) {
            $newStatus = trim((string) post('status'));
            $nextStatusMap = [
                'new'            => 'contacted',
                'contacted'      => 'demo_scheduled',
                'demo_scheduled' => 'closed',
            ];
            $curStatus = (string) scalar('SELECT status FROM demo_requests WHERE id = ?', [$requestId]);
            if ($curStatus === 'closed') {
                flash('Closed demo requests cannot be updated further.', 'warning');
            } elseif (isset($nextStatusMap[$curStatus]) && $newStatus === $nextStatusMap[$curStatus]) {
                $stmt = $pdo->prepare('UPDATE demo_requests SET status = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$newStatus, $requestId]);
                foreach (array_keys($_SESSION) as $sk) {
                    if (str_starts_with((string)$sk, 'sidebar_badges_' . (int)$user['user_id'] . '_')) {
                        unset($_SESSION[$sk]);
                    }
                }
                flash('Demo request status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.', 'success');
            } else {
                flash('Invalid status transition.', 'danger');
            }
            redirect('demo_requests' . (!empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''));
        }

        if ($action === 'update_notes' && $requestId > 0) {
            $notes = trim((string) post('admin_notes'));
            $stmt = $pdo->prepare('UPDATE demo_requests SET admin_notes = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$notes, $requestId]);
            flash('Internal notes updated successfully.', 'success');
            redirect('demo_requests' . (!empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''));
        }

        if ($action === 'delete' && $requestId > 0) {
            $stmt = $pdo->prepare('DELETE FROM demo_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            foreach (array_keys($_SESSION) as $sk) {
                if (str_starts_with((string)$sk, 'sidebar_badges_' . (int)$user['user_id'] . '_')) {
                    unset($_SESSION[$sk]);
                }
            }
            flash('Demo request removed.', 'info');
            redirect('demo_requests' . (!empty($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''));
        }
    }

    // ── Filter & Search Parameters ──────────────────────────────────
    $currentStatus = trim((string) ($_GET['status'] ?? 'all'));
    $q = trim((string) ($_GET['q'] ?? ''));

    // Count by status
    $counts = [
        'all'            => (int) scalar('SELECT COUNT(*) FROM demo_requests'),
        'new'            => (int) scalar("SELECT COUNT(*) FROM demo_requests WHERE status = 'new'"),
        'contacted'      => (int) scalar("SELECT COUNT(*) FROM demo_requests WHERE status = 'contacted'"),
        'demo_scheduled' => (int) scalar("SELECT COUNT(*) FROM demo_requests WHERE status = 'demo_scheduled'"),
        'closed'         => (int) scalar("SELECT COUNT(*) FROM demo_requests WHERE status = 'closed'"),
    ];

    $whereClauses = [];
    $params = [];

    if ($currentStatus !== 'all' && in_array($currentStatus, ['new', 'contacted', 'demo_scheduled', 'closed'], true)) {
        $whereClauses[] = 'status = ?';
        $params[] = $currentStatus;
    }

    if ($q !== '') {
        $whereClauses[] = '(full_name LIKE ? OR work_email LIKE ? OR phone LIKE ? OR gym_name LIKE ? OR message LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    $stmt = $pdo->prepare("SELECT * FROM demo_requests {$whereSql} ORDER BY created_at DESC");
    $stmt->execute($params);
    $demoRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    render_header('Demo Requests & Inquiries', $user);
    ?>

    <style>
        .demo-req-banner {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .demo-req-title {
            margin: 0;
            color: var(--ink);
            font-size: 22px;
            font-weight: 750;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .demo-req-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13.5px;
        }

        /* KPI Cards Grid */
        .demo-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }
        .demo-kpi-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .demo-kpi-card:hover {
            border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
            transform: translateY(-2px);
        }
        .demo-kpi-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
        }
        .demo-kpi-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--ink);
            line-height: 1.2;
        }
        .demo-kpi-sub {
            font-size: 11.5px;
            color: var(--muted);
        }

        /* Filter Pills & Search */
        .demo-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .demo-tabs-pills {
            display: inline-flex;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
            flex-wrap: wrap;
        }
        .demo-nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            text-decoration: none;
            transition: all 0.18s ease;
        }
        .demo-nav-pill:hover {
            color: var(--ink);
            background: rgba(255, 255, 255, 0.04);
        }
        .demo-nav-pill.active {
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            color: var(--lime);
            font-weight: 700;
        }
        .demo-pill-badge {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            font-weight: 700;
        }
        .demo-nav-pill.active .demo-pill-badge {
            background: var(--lime);
            color: #080b0d;
        }
        .demo-badge-alert {
            background: rgba(239, 68, 68, 0.2) !important;
            color: #ef4444 !important;
        }

        /* Search Form */
        .demo-search-wrap {
            position: relative;
            min-width: 240px;
            max-width: 340px;
            flex: 1;
        }
        .demo-search-input {
            width: 100%;
            height: 40px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 12px 8px 36px;
            font-size: 13px;
            color: var(--ink);
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .demo-search-input:focus {
            border-color: var(--lime);
        }
        .demo-search-icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }

        /* Table & Cards Styling */
        .demo-table-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
        }
        .demo-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }
        .demo-table th {
            background: color-mix(in srgb, var(--panel-soft) 60%, var(--surface));
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }
        .demo-table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
            color: var(--ink);
        }
        .demo-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .demo-table tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges & Dropdown */
        .demo-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }
        select.demo-status-pill {
            cursor: pointer;
            outline: none;
            width: auto;
            max-width: 155px;
            height: 30px;
            padding: 2px 28px 2px 12px;
            box-sizing: border-box;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 750;
            line-height: 1.2;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-repeat: no-repeat;
            background-position: right 9px center;
            background-size: 10px 10px;
            transition: all 0.18s ease;
        }
        select.demo-status-pill:hover {
            filter: brightness(1.1);
        }
        select.demo-status-pill:focus {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.15);
        }
        span.demo-status-pill {
            background-image: none !important;
        }
        .status-new {
            background-color: rgba(199, 255, 34, 0.15) !important;
            color: var(--lime) !important;
            border: 1px solid rgba(199, 255, 34, 0.35) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23c7ff22' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        .status-contacted {
            background-color: rgba(245, 158, 11, 0.15) !important;
            color: #f59e0b !important;
            border: 1px solid rgba(245, 158, 11, 0.35) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23f59e0b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        .status-demo_scheduled {
            background-color: rgba(56, 189, 248, 0.15) !important;
            color: #38bdf8 !important;
            border: 1px solid rgba(56, 189, 248, 0.35) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2338bdf8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        .status-closed {
            background-color: rgba(148, 163, 184, 0.12) !important;
            color: var(--muted) !important;
            border: 1px solid rgba(148, 163, 184, 0.3) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        select.demo-status-pill option {
            background: var(--panel, #10131b) !important;
            color: var(--ink, #ffffff) !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            padding: 6px 10px !important;
        }
        [data-theme="light"] .status-new {
            background-color: #ecfccb !important;
            color: #365314 !important;
            border-color: #bef264 !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23365314' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        [data-theme="light"] .status-contacted {
            background-color: #fef3c7 !important;
            color: #92400e !important;
            border-color: #fde68a !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2392400e' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        [data-theme="light"] .status-demo_scheduled {
            background-color: #e0f2fe !important;
            color: #0369a1 !important;
            border-color: #bae6fd !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%230369a1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        [data-theme="light"] .status-closed {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-color: #cbd5e1 !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        }
        [data-theme="light"] select.demo-status-pill option {
            background: #ffffff !important;
            color: #0f172a !important;
        }

        /* Mobile Cards (hidden on desktop) */
        .demo-mobile-cards {
            display: none;
            flex-direction: column;
            gap: 14px;
        }
        .demo-req-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (max-width: 900px) {
            .demo-kpi-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
            }
        }

        @media (max-width: 768px) {
            .demo-table-card {
                display: none;
            }
            .demo-mobile-cards {
                display: flex;
            }
            .demo-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .demo-search-wrap {
                max-width: none;
            }
            .demo-tabs-pills {
                display: flex;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }
            .demo-tabs-pills::-webkit-scrollbar {
                display: none;
            }
            .demo-nav-pill {
                flex-shrink: 0;
            }
        }
    </style>

    <div>
        <!-- 1. HEADER BANNER -->
        <div class="demo-req-banner animate-fade-in">
            <div>
                <h1 class="demo-req-title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lime);"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><polygon points="10 8 15 10 10 12 10 8"/></svg>
                    Demo Requests & Inquiries
                </h1>
                <p class="demo-req-subtitle">
                    Prospective gym leads who submitted demo walkthrough requests from the landing page.
                </p>
            </div>
            <div>
                <span class="badge badge-lime" style="font-size: 12px; padding: 6px 12px;">
                    Platform Admin Hub
                </span>
            </div>
        </div>

        <!-- 2. KPI METRICS CARDS -->
        <div class="demo-kpi-grid animate-fade-in">
            <div class="demo-kpi-card">
                <span class="demo-kpi-label">Total Inquiries</span>
                <div class="demo-kpi-val"><?= $counts['all'] ?></div>
                <span class="demo-kpi-sub">All-time landing leads</span>
            </div>
            <div class="demo-kpi-card">
                <span class="demo-kpi-label">New / Actionable</span>
                <div class="demo-kpi-val" style="color:var(--lime);"><?= $counts['new'] ?></div>
                <span class="demo-kpi-sub">Awaiting response</span>
            </div>
            <div class="demo-kpi-card">
                <span class="demo-kpi-label">Demo Scheduled</span>
                <div class="demo-kpi-val" style="color:#38bdf8;"><?= $counts['demo_scheduled'] ?></div>
                <span class="demo-kpi-sub">Walkthrough arranged</span>
            </div>
            <div class="demo-kpi-card">
                <span class="demo-kpi-label">Contacted / In Progress</span>
                <div class="demo-kpi-val" style="color:#f59e0b;"><?= $counts['contacted'] ?></div>
                <span class="demo-kpi-sub"><?= $counts['closed'] ?> closed / completed</span>
            </div>
        </div>

        <!-- 3. TOOLBAR: STATUS TABS & SEARCH -->
        <div class="demo-toolbar animate-fade-in">
            <nav class="demo-tabs-pills" aria-label="Filter Demo Requests">
                <a href="index.php?page=demo_requests&status=all<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="demo-nav-pill <?= $currentStatus === 'all' ? 'active' : '' ?>">
                    <span>All</span>
                    <span class="demo-pill-badge"><?= $counts['all'] ?></span>
                </a>
                <a href="index.php?page=demo_requests&status=new<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="demo-nav-pill <?= $currentStatus === 'new' ? 'active' : '' ?>">
                    <span>New</span>
                    <span class="demo-pill-badge <?= $counts['new'] > 0 ? 'demo-badge-alert' : '' ?>"><?= $counts['new'] ?></span>
                </a>
                <a href="index.php?page=demo_requests&status=contacted<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="demo-nav-pill <?= $currentStatus === 'contacted' ? 'active' : '' ?>">
                    <span>Contacted</span>
                    <span class="demo-pill-badge"><?= $counts['contacted'] ?></span>
                </a>
                <a href="index.php?page=demo_requests&status=demo_scheduled<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="demo-nav-pill <?= $currentStatus === 'demo_scheduled' ? 'active' : '' ?>">
                    <span>Scheduled</span>
                    <span class="demo-pill-badge"><?= $counts['demo_scheduled'] ?></span>
                </a>
                <a href="index.php?page=demo_requests&status=closed<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="demo-nav-pill <?= $currentStatus === 'closed' ? 'active' : '' ?>">
                    <span>Closed</span>
                    <span class="demo-pill-badge"><?= $counts['closed'] ?></span>
                </a>
            </nav>

            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <button type="button" onclick="openEmailSettingsModal()" class="btn btn-sm btn-ghost" title="Email Settings (Choose default: Gmail / Outlook in current browser)" style="height:40px; padding:0 12px; gap:6px; display:inline-flex; align-items:center; white-space:nowrap; border:1px solid var(--line); border-radius:8px; font-size:12px; color:var(--muted); background:var(--panel);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span id="emailPrefBadge">Email: Webmail</span>
                </button>

                <form method="get" action="index.php" class="demo-search-wrap">
                    <input type="hidden" name="page" value="demo_requests">
                    <?php if ($currentStatus !== 'all'): ?>
                        <input type="hidden" name="status" value="<?= h($currentStatus) ?>">
                    <?php endif; ?>
                    <svg class="demo-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="demo-search-input" placeholder="Search name, gym, email, phone..." value="<?= h($q) ?>">
                    <?php if ($q !== ''): ?>
                        <a href="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:var(--muted); text-decoration:none; font-size:16px;">×</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 4. DESKTOP TABLE VIEW -->
        <div class="demo-table-card animate-fade-in">
            <?php if (empty($demoRequests)): ?>
                <div style="text-align:center; padding: 48px 20px; color: var(--muted);">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" style="margin-bottom: 12px; opacity:0.6;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <h4 style="margin:0 0 6px 0; color:var(--ink);">No Demo Requests Found</h4>
                    <p style="margin:0; font-size:13px;">No requests match the selected filters or search query.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="demo-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Prospect</th>
                                <th>Facility / Role</th>
                                <th>Contact Info</th>
                                <th>Inquiry Message</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demoRequests as $req): 
                                $statusCls = 'status-' . $req['status'];
                                $statusLabel = ucfirst(str_replace('_', ' ', $req['status']));
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;">
                                        <strong style="display:block; font-size:13px;"><?= date('M j, Y', strtotime($req['created_at'])) ?></strong>
                                        <small style="color:var(--muted);"><?= date('g:i A', strtotime($req['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <strong style="color:var(--ink); font-size:14px;"><?= h($req['full_name']) ?></strong>
                                        <?php if (!empty($req['role'])): ?>
                                            <div style="font-size:12px; color:var(--muted);"><?= h($req['role']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= h($req['gym_name'] ?: 'Not specified') ?></strong>
                                        <?php if (!empty($req['approx_members'])): ?>
                                            <div style="font-size:11.5px; color:var(--muted);"><?= h($req['approx_members']) ?> members</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><a href="javascript:void(0)" onclick="handleEmailClick(<?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, event)" style="color:var(--lime); text-decoration:none; font-weight:500;" title="Send Email (Current Browser)"><?= h($req['work_email']) ?></a></div>
                                        <?php if (!empty($req['phone'])): ?>
                                            <div style="font-size:12px; color:var(--muted);"><a href="tel:<?= h($req['phone']) ?>" style="color:inherit; text-decoration:none;"><?= h($req['phone']) ?></a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:280px;">
                                        <?php if (!empty($req['message'])): 
                                            $msgLen = mb_strlen($req['message']);
                                            $hasMore = ($msgLen > 60 || str_contains($req['message'], "\n"));
                                        ?>
                                            <span style="font-size:12.5px; color:var(--muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;" title="<?= h($req['message']) ?>">
                                                <?= h($req['message']) ?>
                                            </span>
                                            <?php if ($hasMore): ?>
                                                <button type="button" 
                                                        onclick="openInquiryModal(<?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?: 'No Facility specified'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode(date('M j, Y \a\t g:i A', strtotime($req['created_at']))), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['message']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>)" 
                                                        style="background:none; border:none; padding:0; margin-top:3px; color:var(--lime); text-decoration:underline; font-size:11.5px; font-weight:600; cursor:pointer; display:inline-block;"
                                                        title="Read full message">
                                                    <u>more</u>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:var(--muted); font-size:12px;">—</span>
                                        <?php endif; ?>
                                        <?php if (!empty($req['admin_notes'])): ?>
                                            <div style="margin-top:4px; font-size:11px; background:rgba(255,255,255,0.04); padding:3px 6px; border-radius:4px; color:var(--ink);">
                                                <strong>Notes:</strong> <?= h($req['admin_notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $reqStatus = (string)$req['status'];
                                            $statusLabels = [
                                                'new'            => 'New',
                                                'contacted'      => 'Contacted',
                                                'demo_scheduled' => 'Demo Scheduled',
                                                'closed'         => 'Closed',
                                            ];
                                            $nextMap = [
                                                'new'            => 'contacted',
                                                'contacted'      => 'demo_scheduled',
                                                'demo_scheduled' => 'closed',
                                            ];
                                            $nextOpt = $nextMap[$reqStatus] ?? null;
                                        ?>
                                        <?php if ($reqStatus === 'closed' || $nextOpt === null): ?>
                                            <span class="demo-status-pill status-closed" style="display:inline-flex;align-items:center;gap:4px;cursor:default;" title="Closed (Final Status)">Closed</span>
                                        <?php else: ?>
                                            <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                                <select name="status" class="demo-status-pill <?= $statusCls ?>" onchange="this.form.submit()" title="Advance Inquiry Status">
                                                    <option value="<?= h($reqStatus) ?>" selected><?= h($statusLabels[$reqStatus] ?? ucfirst($reqStatus)) ?></option>
                                                    <option value="<?= h($nextOpt) ?>"><?= h($statusLabels[$nextOpt]) ?></option>
                                                </select>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <button type="button" class="btn btn-sm btn-ghost" title="Send Email (Current Browser)" onclick="handleEmailClick(<?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, event)" style="padding:6px 10px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-ghost" title="Edit Admin Notes" onclick="openNotesModal(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-ghost" title="Delete" onclick="confirmDeleteDemoRequest(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px; color:#ef4444;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 5. MOBILE CARDS VIEW -->
        <div class="demo-mobile-cards animate-fade-in">
            <?php if (empty($demoRequests)): ?>
                <div style="text-align:center; padding:36px 16px; background:var(--panel); border:1px solid var(--line); border-radius:12px; color:var(--muted);">
                    No demo requests matching this filter.
                </div>
            <?php else: ?>
                <?php foreach ($demoRequests as $req): 
                    $reqStatus = (string)$req['status'];
                    $statusCls = 'status-' . $reqStatus;
                    $statusLabel = ucfirst(str_replace('_', ' ', $reqStatus));
                    $nextOpt = $nextMap[$reqStatus] ?? null;
                ?>
                    <article class="demo-req-card">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                            <div>
                                <strong style="font-size:15px; color:var(--ink);"><?= h($req['full_name']) ?></strong>
                                <div style="font-size:12px; color:var(--muted);"><?= h($req['role'] ?: 'Prospect') ?> • <?= h($req['gym_name'] ?: 'No Facility') ?></div>
                            </div>
                            <span class="demo-status-pill <?= $statusCls ?>"><?= $statusLabel ?></span>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
                            <div><strong>Email:</strong> <a href="javascript:void(0)" onclick="handleEmailClick(<?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, event)" style="color:var(--lime); text-decoration:none;" title="Send Email (Current Browser)"><?= h($req['work_email']) ?></a></div>
                            <?php if (!empty($req['phone'])): ?>
                                <div><strong>Phone:</strong> <a href="tel:<?= h($req['phone']) ?>" style="color:inherit; text-decoration:none;"><?= h($req['phone']) ?></a></div>
                            <?php endif; ?>
                            <?php if (!empty($req['approx_members'])): ?>
                                <div><strong>Members:</strong> <?= h($req['approx_members']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:11.5px; color:var(--muted); margin-top:2px;"><?= date('M j, Y \a\t g:i A', strtotime($req['created_at'])) ?></div>
                        </div>

                        <?php if (!empty($req['message'])): 
                            $mobLen = mb_strlen($req['message']);
                            $mobHasMore = ($mobLen > 90 || str_contains($req['message'], "\n"));
                        ?>
                            <div style="background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:8px; padding:10px; font-size:12.5px; color:var(--muted); line-height:1.4;">
                                <strong style="color:var(--ink); display:block; margin-bottom:2px;">Inquiry Note:</strong>
                                <span style="<?= $mobHasMore ? 'display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;' : '' ?>">
                                    <?= h($req['message']) ?>
                                </span>
                                <?php if ($mobHasMore): ?>
                                    <button type="button" 
                                            onclick="openInquiryModal(<?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?: 'No Facility specified'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode(date('M j, Y \a\t g:i A', strtotime($req['created_at']))), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['message']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>)" 
                                            style="background:none; border:none; padding:0; margin-top:4px; color:var(--lime); text-decoration:underline; font-size:11.5px; font-weight:600; cursor:pointer; display:inline-block;"
                                            title="Read full message">
                                        <u>more</u>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($req['admin_notes'])): ?>
                            <div style="background:rgba(199,255,34,0.04); border:1px solid rgba(199,255,34,0.2); border-radius:8px; padding:8px 10px; font-size:12px; color:var(--ink);">
                                <strong style="color:var(--lime);">Admin Notes:</strong> <?= h($req['admin_notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; pt:8px; border-top:1px solid rgba(255,255,255,0.05); margin-top:4px;">
                            <?php if ($reqStatus === 'closed' || $nextOpt === null): ?>
                                <div style="flex:1;">
                                    <span class="demo-status-pill status-closed" style="display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 12px;cursor:default;">Closed</span>
                                </div>
                            <?php else: ?>
                                <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0; flex:1;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                    <select name="status" class="form-control" onchange="this.form.submit()" style="height:36px; font-size:12px; border-radius:8px;">
                                        <option value="<?= h($reqStatus) ?>" selected><?= h($statusLabels[$reqStatus] ?? ucfirst($reqStatus)) ?></option>
                                        <option value="<?= h($nextOpt) ?>"><?= h($statusLabels[$nextOpt]) ?></option>
                                    </select>
                                </form>
                            <?php endif; ?>

                            <button type="button" class="btn btn-sm btn-ghost" onclick="openNotesModal(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px; font-size:12px;">
                                Note
                            </button>
                            <button type="button" onclick="handleEmailClick(<?= htmlspecialchars(json_encode($req['work_email']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($req['gym_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, event)" class="btn btn-sm btn-lime" style="padding:6px 12px; font-size:12px;">
                                Email
                            </button>
                            <button type="button" class="btn btn-sm btn-ghost" title="Delete" onclick="confirmDeleteDemoRequest(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['full_name']), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px; color:#ef4444; font-size:12px;">
                                Delete
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
        <div style="background:var(--panel); border:1px solid var(--line); border-radius:14px; max-width:440px; width:100%; padding:20px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:16px; color:var(--ink);">Admin Internal Notes</h3>
                <button type="button" onclick="closeNotesModal()" style="background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer;">&times;</button>
            </div>
            <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="id" id="notesModalId" value="">
                <textarea name="admin_notes" id="notesModalText" rows="4" class="form-control" placeholder="Write internal notes about this prospect, call results, or scheduled time..." style="width:100%; border-radius:8px; margin-bottom:14px; font-size:13px;"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn btn-sm btn-ghost" onclick="closeNotesModal()">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-lime">Save Notes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Plain & Clean Inquiry Message Modal -->
    <div id="inquiryModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
        <div style="background:var(--panel); border:1px solid var(--line); border-radius:14px; max-width:520px; width:100%; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
                <div>
                    <h3 style="margin:0 0 4px 0; font-size:16px; color:var(--ink);">Inquiry Message</h3>
                    <div id="inquiryModalSubtitle" style="font-size:12px; color:var(--muted);"></div>
                </div>
                <button type="button" onclick="closeInquiryModal()" style="background:none; border:none; color:var(--muted); font-size:22px; cursor:pointer; line-height:1; padding:2px 6px;">&times;</button>
            </div>
            
            <div id="inquiryModalBody" style="background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:16px; font-size:13.5px; line-height:1.6; color:var(--ink); white-space:pre-wrap; word-break:break-word; max-height:360px; overflow-y:auto; margin-bottom:16px;"></div>
            
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px;">
                <button id="inquiryModalEmailBtn" type="button" class="btn btn-sm btn-lime" style="display:inline-flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Reply via Email
                </button>
                <button type="button" class="btn btn-sm btn-ghost" onclick="closeInquiryModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Email Options Modal -->
    <div id="emailModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
        <div style="background:var(--panel); border:1px solid var(--line); border-radius:14px; max-width:480px; width:100%; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
                <div>
                    <h3 style="margin:0 0 4px 0; font-size:16px; color:var(--ink); display:flex; align-items:center; gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Send Email to Prospect
                    </h3>
                    <div id="emailModalSubtitle" style="font-size:12px; color:var(--muted);"></div>
                </div>
                <button type="button" onclick="closeEmailModal()" style="background:none; border:none; color:var(--muted); font-size:22px; cursor:pointer; line-height:1; padding:2px 6px;">&times;</button>
            </div>

            <!-- Recipient & Subject Summary Box -->
            <div id="emailModalRecipientBox" style="background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:12.5px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <span style="color:var(--muted);">To:</span>
                    <span id="emailModalTo" style="font-weight:600; color:var(--lime); word-break:break-all;"></span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:var(--muted);">Subject:</span>
                    <span style="color:var(--ink); font-weight:500;">FitTracks Live Demo Walkthrough</span>
                </div>
            </div>

            <div style="font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                Choose How to Open (Current Browser)
            </div>

            <!-- Action Options -->
            <div style="display:flex; flex-direction:column; gap:9px; margin-bottom:16px;">
                <!-- Gmail Option (Recommended) -->
                <button type="button" onclick="selectEmailProvider('gmail')" style="display:flex; align-items:center; gap:12px; width:100%; padding:12px 14px; background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:10px; color:var(--ink); cursor:pointer; text-align:left; transition:all 0.15s ease;" onmouseover="this.style.borderColor='var(--lime)'; this.style.background='rgba(190,242,100,0.06)'" onmouseout="this.style.borderColor='var(--line)'; this.style.background='rgba(255,255,255,0.03)'">
                    <div style="width:36px; height:36px; border-radius:8px; background:rgba(234, 67, 53, 0.12); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24"><path fill="#EA4335" d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:13.5px; font-weight:600; color:var(--ink); display:flex; align-items:center; gap:6px;">
                            Compose in Gmail
                            <span style="font-size:10px; background:var(--lime); color:#000; font-weight:700; padding:1px 6px; border-radius:4px;">In this browser</span>
                        </div>
                        <div style="font-size:11.5px; color:var(--muted);">Opens mail.google.com in a new tab without leaving this browser</div>
                    </div>
                </button>

                <!-- Outlook Web Option -->
                <button type="button" onclick="selectEmailProvider('outlook')" style="display:flex; align-items:center; gap:12px; width:100%; padding:12px 14px; background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:10px; color:var(--ink); cursor:pointer; text-align:left; transition:all 0.15s ease;" onmouseover="this.style.borderColor='#38bdf8'; this.style.background='rgba(56,189,248,0.06)'" onmouseout="this.style.borderColor='var(--line)'; this.style.background='rgba(255,255,255,0.03)'">
                    <div style="width:36px; height:36px; border-radius:8px; background:rgba(0, 120, 212, 0.12); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="#0078D4"><rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="#0078D4" stroke-width="2"/><path d="M3 7l9 6 9-6" stroke="#0078D4" stroke-width="2" fill="none"/></svg>
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:13.5px; font-weight:600; color:var(--ink);">Compose in Outlook Web</div>
                        <div style="font-size:11.5px; color:var(--muted);">Opens Outlook in a new tab in this browser</div>
                    </div>
                </button>

                <!-- Copy Email & System Mail Row -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:9px;">
                    <button type="button" id="copyEmailBtn" onclick="copyCurrentEmail(this)" style="display:flex; align-items:center; justify-content:center; gap:8px; padding:10px 12px; background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:10px; color:var(--ink); cursor:pointer; font-size:12.5px; font-weight:500; transition:all 0.15s ease;" onmouseover="this.style.borderColor='var(--lime)'" onmouseout="this.style.borderColor='var(--line)'">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <span>Copy Email</span>
                    </button>
                    <button type="button" onclick="selectEmailProvider('system')" style="display:flex; align-items:center; justify-content:center; gap:8px; padding:10px 12px; background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:10px; color:var(--muted); cursor:pointer; font-size:12.5px; font-weight:500; transition:all 0.15s ease;" onmouseover="this.style.borderColor='var(--muted)'; this.style.color='var(--ink)'" onmouseout="this.style.borderColor='var(--line)'; this.style.color='var(--muted)'" title="Opens the default app set in Windows (e.g. Edge or Outlook)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        <span>System App</span>
                    </button>
                </div>
            </div>

            <!-- Footer: Remember preference -->
            <div style="border-top:1px solid var(--line); padding-top:12px; display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--muted);">
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;">
                    <input type="checkbox" id="rememberEmailPref" checked style="accent-color:var(--lime); cursor:pointer;">
                    <span>Remember choice & open directly next time</span>
                </label>
                <button type="button" id="resetEmailPrefBtn" onclick="resetEmailPref()" style="display:none; background:none; border:none; color:var(--lime); cursor:pointer; font-size:11.5px; text-decoration:underline;">Reset saved</button>
            </div>
        </div>
    </div>

    <script>
        function openNotesModal(id, notes) {
            document.getElementById('notesModalId').value = id;
            document.getElementById('notesModalText').value = notes || '';
            const modal = document.getElementById('notesModal');
            modal.style.display = 'flex';
        }
        function closeNotesModal() {
            const modal = document.getElementById('notesModal');
            modal.style.display = 'none';
        }

        let currentEmailData = { email: '', name: '', gym: '' };

        function updateEmailPrefBadge() {
            const badge = document.getElementById('emailPrefBadge');
            if (!badge) return;
            const pref = localStorage.getItem('fittracks_email_provider');
            if (pref === 'gmail') {
                badge.innerHTML = 'Email: <strong style="color:var(--lime);">Gmail</strong>';
            } else if (pref === 'outlook') {
                badge.innerHTML = 'Email: <strong style="color:#38bdf8;">Outlook</strong>';
            } else if (pref === 'system') {
                badge.innerHTML = 'Email: <strong style="color:var(--ink);">System</strong>';
            } else {
                badge.innerHTML = 'Email: <strong>Webmail</strong>';
            }
        }

        function openEmailSettingsModal() {
            openEmailModal('', '', '');
        }

        function handleEmailClick(email, name, gym, event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            const savedPref = localStorage.getItem('fittracks_email_provider');
            // If Shift key is pressed, always open the chooser to allow changing settings
            if (savedPref && (!event || !event.shiftKey)) {
                launchEmailProvider(savedPref, email, name, gym);
                return;
            }
            openEmailModal(email, name, gym);
        }

        function openEmailModal(email, name, gym) {
            currentEmailData = { email: email || '', name: name || '', gym: gym || '' };
            const toEl = document.getElementById('emailModalTo');
            const subEl = document.getElementById('emailModalSubtitle');
            const boxEl = document.getElementById('emailModalRecipientBox');
            
            if (currentEmailData.email) {
                if (toEl) toEl.textContent = currentEmailData.email;
                if (subEl) subEl.textContent = (currentEmailData.name || 'Prospect') + (currentEmailData.gym ? (' • ' + currentEmailData.gym) : '');
                if (boxEl) boxEl.style.display = 'block';
            } else {
                if (boxEl) boxEl.style.display = 'none';
                if (subEl) subEl.textContent = 'Configure preferred email provider';
            }

            const saved = localStorage.getItem('fittracks_email_provider');
            const resetBtn = document.getElementById('resetEmailPrefBtn');
            if (resetBtn) {
                resetBtn.style.display = saved ? 'inline-block' : 'none';
            }
            const remBox = document.getElementById('rememberEmailPref');
            if (remBox) {
                remBox.checked = true;
            }

            const modal = document.getElementById('emailModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeEmailModal() {
            const modal = document.getElementById('emailModal');
            if (modal) modal.style.display = 'none';
        }

        function selectEmailProvider(provider) {
            const remember = document.getElementById('rememberEmailPref')?.checked;
            if (remember) {
                localStorage.setItem('fittracks_email_provider', provider);
                updateEmailPrefBadge();
            }
            const targetData = { ...currentEmailData };
            closeEmailModal();
            if (targetData.email) {
                launchEmailProvider(provider, targetData.email, targetData.name, targetData.gym);
            }
        }

        function resetEmailPref() {
            localStorage.removeItem('fittracks_email_provider');
            updateEmailPrefBadge();
            const resetBtn = document.getElementById('resetEmailPrefBtn');
            if (resetBtn) resetBtn.style.display = 'none';
        }

        function launchEmailProvider(provider, email, name, gym) {
            if (!email) return;
            const subject = 'FitTracks Live Demo Walkthrough';
            let body = 'Hi ' + (name ? name : '') + ',\n\n';
            if (gym) {
                body += 'Thank you for reaching out regarding FitTracks for ' + gym + '.\n\n';
            }

            if (provider === 'gmail') {
                const url = 'https://mail.google.com/mail/?view=cm&fs=1&to=' + encodeURIComponent(email) + '&su=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
                window.open(url, '_blank', 'noopener,noreferrer');
            } else if (provider === 'outlook') {
                const url = 'https://outlook.live.com/mail/0/deeplink/compose?to=' + encodeURIComponent(email) + '&subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
                window.open(url, '_blank', 'noopener,noreferrer');
            } else {
                window.location.href = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
            }
        }

        function copyCurrentEmail(btn) {
            if (!currentEmailData.email) return;
            const text = currentEmailData.email;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    showCopySuccess(btn);
                }).catch(() => {
                    fallbackCopy(text, btn);
                });
            } else {
                fallbackCopy(text, btn);
            }
        }

        function fallbackCopy(text, btn) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                showCopySuccess(btn);
            } catch (e) {}
            document.body.removeChild(ta);
        }

        function showCopySuccess(btn) {
            if (!btn) return;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span style="color:var(--lime); font-weight:600;">✓ Copied!</span>';
            setTimeout(() => {
                btn.innerHTML = originalText;
            }, 2000);
        }

        function openInquiryModal(name, gym, dateStr, message, email) {
            document.getElementById('inquiryModalSubtitle').textContent = (name || 'Prospect') + (gym ? (' • ' + gym) : '') + (dateStr ? (' • ' + dateStr) : '');
            document.getElementById('inquiryModalBody').textContent = message || '';
            const emailBtn = document.getElementById('inquiryModalEmailBtn');
            if (email) {
                emailBtn.style.display = 'inline-flex';
                emailBtn.onclick = function(e) {
                    handleEmailClick(email, name, gym, e);
                };
            } else {
                emailBtn.style.display = 'none';
            }
            const modal = document.getElementById('inquiryModal');
            modal.style.display = 'flex';
        }
        function closeInquiryModal() {
            const modal = document.getElementById('inquiryModal');
            modal.style.display = 'none';
        }

        document.getElementById('inquiryModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeInquiryModal();
        });
        document.getElementById('notesModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeNotesModal();
        });
        document.getElementById('emailModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeEmailModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeInquiryModal();
                closeNotesModal();
                closeEmailModal();
            }
        });

        function confirmDeleteDemoRequest(id, name) {
            const prospectName = name ? ' for <strong>' + escapeHtml(name) + '</strong>' : '';
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Delete Demo Request?',
                    html: 'Are you sure you want to delete this demo request' + prospectName + '?<br><span style="font-size:12px; color:var(--muted); margin-top:4px; display:inline-block;">This lead cannot be recovered once deleted.</span>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: 'rgba(255, 255, 255, 0.1)',
                    background: '#11141d',
                    color: '#f8fafc',
                    focusCancel: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitDeleteDemoRequest(id);
                    }
                });
            } else {
                if (confirm('Delete this demo request?')) {
                    submitDeleteDemoRequest(id);
                }
            }
        }

        function submitDeleteDemoRequest(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>';
            
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = 'csrf_token';
            csrf.value = '<?= csrf_token() ?>';
            form.appendChild(csrf);

            const action = document.createElement('input');
            action.type = 'hidden';
            action.name = 'action';
            action.value = 'delete';
            form.appendChild(action);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize email preference badge on load
        updateEmailPrefBadge();
    </script>

    <?php
    render_footer();
}

