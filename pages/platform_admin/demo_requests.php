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
            $allowedStatuses = ['new', 'contacted', 'demo_scheduled', 'closed'];
            if (in_array($newStatus, $allowedStatuses, true)) {
                $stmt = $pdo->prepare('UPDATE demo_requests SET status = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$newStatus, $requestId]);
                flash('Demo request status updated to ' . ucfirst(str_replace('_', ' ', $newStatus)) . '.', 'success');
            } else {
                flash('Invalid status selected.', 'danger');
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

        /* Status Badges */
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
        .status-new {
            background: rgba(199, 255, 34, 0.15);
            color: var(--lime);
            border: 1px solid rgba(199, 255, 34, 0.3);
        }
        .status-contacted {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .status-demo_scheduled {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }
        .status-closed {
            background: rgba(148, 163, 184, 0.12);
            color: var(--muted);
            border: 1px solid rgba(148, 163, 184, 0.25);
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
                                        <div><a href="mailto:<?= h($req['work_email']) ?>" style="color:var(--lime); text-decoration:none; font-weight:500;"><?= h($req['work_email']) ?></a></div>
                                        <?php if (!empty($req['phone'])): ?>
                                            <div style="font-size:12px; color:var(--muted);"><a href="tel:<?= h($req['phone']) ?>" style="color:inherit; text-decoration:none;"><?= h($req['phone']) ?></a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:280px;">
                                        <?php if (!empty($req['message'])): ?>
                                            <span style="font-size:12.5px; color:var(--muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;" title="<?= h($req['message']) ?>">
                                                <?= h($req['message']) ?>
                                            </span>
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
                                        <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                            <select name="status" class="demo-status-pill <?= $statusCls ?>" onchange="this.form.submit()" style="cursor:pointer; outline:none; border-radius:20px; font-size:11.5px; font-weight:700;">
                                                <option value="new" <?= $req['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                                <option value="contacted" <?= $req['status'] === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                                                <option value="demo_scheduled" <?= $req['status'] === 'demo_scheduled' ? 'selected' : '' ?>>Demo Scheduled</option>
                                                <option value="closed" <?= $req['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <a href="mailto:<?= h($req['work_email']) ?>?subject=FitTracks%20Live%20Demo%20Walkthrough&body=Hi%20<?= urlencode($req['full_name']) ?>," class="btn btn-sm btn-ghost" title="Send Email" style="padding:6px 10px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-ghost" title="Edit Admin Notes" onclick="openNotesModal(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" onsubmit="return confirm('Delete this demo request?');" style="margin:0; display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-ghost" title="Delete" style="padding:6px 10px; color:#ef4444;">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                </button>
                                            </form>
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
                    $statusCls = 'status-' . $req['status'];
                    $statusLabel = ucfirst(str_replace('_', ' ', $req['status']));
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
                            <div><strong>Email:</strong> <a href="mailto:<?= h($req['work_email']) ?>" style="color:var(--lime); text-decoration:none;"><?= h($req['work_email']) ?></a></div>
                            <?php if (!empty($req['phone'])): ?>
                                <div><strong>Phone:</strong> <a href="tel:<?= h($req['phone']) ?>" style="color:inherit; text-decoration:none;"><?= h($req['phone']) ?></a></div>
                            <?php endif; ?>
                            <?php if (!empty($req['approx_members'])): ?>
                                <div><strong>Members:</strong> <?= h($req['approx_members']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:11.5px; color:var(--muted); margin-top:2px;"><?= date('M j, Y \a\t g:i A', strtotime($req['created_at'])) ?></div>
                        </div>

                        <?php if (!empty($req['message'])): ?>
                            <div style="background:rgba(255,255,255,0.03); border:1px solid var(--line); border-radius:8px; padding:10px; font-size:12.5px; color:var(--muted); line-height:1.4;">
                                <strong style="color:var(--ink); display:block; margin-bottom:2px;">Inquiry Note:</strong>
                                <?= h($req['message']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($req['admin_notes'])): ?>
                            <div style="background:rgba(199,255,34,0.04); border:1px solid rgba(199,255,34,0.2); border-radius:8px; padding:8px 10px; font-size:12px; color:var(--ink);">
                                <strong style="color:var(--lime);">Admin Notes:</strong> <?= h($req['admin_notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; pt:8px; border-top:1px solid rgba(255,255,255,0.05); margin-top:4px;">
                            <form method="post" action="index.php?page=demo_requests<?= $currentStatus !== 'all' ? '&status=' . urlencode($currentStatus) : '' ?>" style="margin:0; flex:1;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                <select name="status" class="form-control" onchange="this.form.submit()" style="height:36px; font-size:12px; border-radius:8px;">
                                    <option value="new" <?= $req['status'] === 'new' ? 'selected' : '' ?>>New</option>
                                    <option value="contacted" <?= $req['status'] === 'contacted' ? 'selected' : '' ?>>Contacted</option>
                                    <option value="demo_scheduled" <?= $req['status'] === 'demo_scheduled' ? 'selected' : '' ?>>Demo Scheduled</option>
                                    <option value="closed" <?= $req['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                                </select>
                            </form>

                            <button type="button" class="btn btn-sm btn-ghost" onclick="openNotesModal(<?= (int)$req['id'] ?>, <?= htmlspecialchars(json_encode($req['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 10px; font-size:12px;">
                                Note
                            </button>
                            <a href="mailto:<?= h($req['work_email']) ?>" class="btn btn-sm btn-lime" style="padding:6px 12px; font-size:12px;">
                                Email
                            </a>
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
    </script>

    <?php
    render_footer();
}

