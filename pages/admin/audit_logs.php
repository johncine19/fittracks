<?php
declare(strict_types=1);

/**
 * Returns a punchy, non-technical, human-friendly action label.
 */
function get_friendly_audit_action(string $action): string
{
    return match (strtolower($action)) {
        'submit_application'        => 'Application',
        'approve'                   => 'Approved',
        'reject'                    => 'Rejected',
        'suspend'                   => 'Suspended',
        'create'                    => 'Created',
        'edit', 'update'            => 'Updated',
        'delete'                    => 'Deleted',
        'update_status'             => 'Status',
        'update_subscription'       => 'Subscription',
        'update_plan_pricing'       => 'Pricing',
        'checkin', 'member_checkin' => 'Check-in',
        'checkout'                  => 'Check-out',
        'qr_checkin'                => 'QR Check-in',
        'qr_checkout'               => 'QR Check-out',
        'manual_checkin'            => 'Manual In',
        'manual_checkout'           => 'Manual Out',
        'mark_paid'                 => 'Paid',
        'convert'                   => 'Converted',
        'forward'                   => 'Forwarded',
        'end'                       => 'Ended',
        default                     => ucwords(str_replace('_', ' ', $action)),
    };
}

/**
 * Returns a non-technical, human-friendly category/entity name.
 */
function get_friendly_audit_entity(string $entity): string
{
    return match (strtolower($entity)) {
        'gym_owner'                   => 'Gym Owner Application',
        'gym'                         => 'Gym',
        'user'                        => 'User Account',
        'membership'                  => 'Membership',
        'payment'                     => 'Payment',
        'attendance'                  => 'Attendance',
        'class'                       => 'Gym Class',
        'class_schedule'              => 'Class Schedule',
        'plan'                        => 'Membership Plan',
        'exercise'                    => 'Exercise',
        'commission'                  => 'Commission Payout',
        'trainer_assignment'          => 'Trainer Assignment',
        'walk_in'                     => 'Walk-in Guest',
        'platform_settings'           => 'Platform Settings',
        'platform_subscription_plans' => 'Subscription Plan',
        default                       => ucwords(str_replace('_', ' ', $entity)),
    };
}

/**
 * Formats an audit log record into intuitive, non-tech visual elements & narrative.
 */
function format_audit_log_entry(array $row): array
{
    $action = strtolower((string)($row['action'] ?? ''));
    $entityType = strtolower((string)($row['entity_type'] ?? ''));
    $entityId = (string)($row['entity_id'] ?? '');

    // 1. Actor Name & Initials
    $actorName = trim((string)($row['admin_name'] ?? ''));
    if ($actorName === '') {
        $actorName = !empty($row['admin_user_id']) ? 'User #' . $row['admin_user_id'] : 'Automated System';
    }

    $fn = (string)($row['first_name'] ?? '');
    $ln = (string)($row['last_name'] ?? '');
    if ($fn !== '' || $ln !== '') {
        $initials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));
    } else {
        $parts = preg_split('/\s+/', $actorName);
        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? '', 0, 1));
        if ($initials === '') $initials = 'SY';
    }

    // 2. Real Role Label (Clean, concise & neutral to avoid visual clutter)
    $role = (string)($row['admin_role'] ?? '');
    $roleLabel = match ($role) {
        'platform_admin' => 'Admin',
        'gym_owner'      => 'Gym Owner',
        'trainer'        => 'Trainer',
        'member'         => 'Member',
        default          => 'Staff',
    };

    // 3. Parse Raw Details
    $details = [];
    $rawDetails = '';
    if (!empty($row['details'])) {
        $decoded = json_decode((string)$row['details'], true);
        if (is_array($decoded)) {
            $details = $decoded;
        } else {
            $rawDetails = (string)$row['details'];
        }
    }

    $friendlyAction = get_friendly_audit_action($action);
    $friendlyEntity = get_friendly_audit_entity($entityType);

    // 4. Subtle Accent Color & Icon (Gentle indicator without loud neon borders)
    $color = '#94a3b8';
    $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

    switch ($action) {
        case 'submit_application':
            $color = '#c084fc';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
            break;
        case 'approve':
            $color = '#34d399';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            break;
        case 'reject':
        case 'suspend':
        case 'delete':
        case 'end':
            $color = '#f87171';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            break;
        case 'create':
            $color = '#34d399';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';
            break;
        case 'edit':
        case 'update':
            $color = '#60a5fa';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
            break;
        case 'update_status':
            $color = '#fbbf24';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
            break;
        case 'update_subscription':
        case 'update_plan_pricing':
            $color = '#38bdf8';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
            break;
        case 'checkin':
        case 'manual_checkin':
        case 'member_checkin':
            $color = '#34d399';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>';
            break;
        case 'checkout':
        case 'manual_checkout':
            $color = '#818cf8';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
            break;
        case 'qr_checkin':
            $color = '#34d399';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
            break;
        case 'qr_checkout':
            $color = '#818cf8';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
            break;
        case 'mark_paid':
            $color = '#34d399';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><line x1="12" y1="6" x2="12" y2="18"/></svg>';
            break;
        case 'convert':
            $color = '#c084fc';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>';
            break;
        case 'forward':
            $color = '#60a5fa';
            $iconSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
            break;
    }

    // 5. Build Plain English Narrative Sentence (Clean formatting, no raw underscores)
    $actorSafe = h($actorName);
    $headline = '';

    if ($action === 'submit_application' && $entityType === 'gym_owner') {
        $headline = "<strong>{$actorSafe}</strong> submitted a gym owner application";
    } elseif ($action === 'approve') {
        $headline = "<strong>{$actorSafe}</strong> approved " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
    } elseif ($action === 'reject') {
        $headline = "<strong>{$actorSafe}</strong> rejected " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
    } elseif ($action === 'suspend') {
        $headline = "<strong>{$actorSafe}</strong> suspended " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
    } elseif ($action === 'create') {
        if ($entityType === 'user') {
            $roleDetail = !empty($details['role']) ? ucwords(str_replace('_', ' ', (string)$details['role'])) : 'User';
            $headline = "<strong>{$actorSafe}</strong> created a new <strong>{$roleDetail}</strong> account" . (!empty($details['name']) ? " for <strong>" . h($details['name']) . "</strong>" : '');
        } elseif ($entityType === 'class') {
            $cName = !empty($details['class_name']) ? h($details['class_name']) : '';
            $headline = "<strong>{$actorSafe}</strong> created class " . ($cName !== '' ? "<strong>'{$cName}'</strong>" : "#{$entityId}");
        } elseif ($entityType === 'class_schedule') {
            $headline = "<strong>{$actorSafe}</strong> scheduled a new class session";
        } elseif ($entityType === 'plan') {
            $pName = !empty($details['plan_name']) ? h($details['plan_name']) : '';
            $headline = "<strong>{$actorSafe}</strong> created membership plan " . ($pName !== '' ? "<strong>'{$pName}'</strong>" : "#{$entityId}");
        } elseif ($entityType === 'payment') {
            $amt = isset($details['amount']) ? '₱' . number_format((float)$details['amount'], 2) : '';
            $headline = "<strong>{$actorSafe}</strong> recorded a payment" . ($amt !== '' ? " of <strong>{$amt}</strong>" : '');
        } elseif ($entityType === 'walk_in') {
            $gName = !empty($details['guest_name']) ? h($details['guest_name']) : '';
            $headline = "<strong>{$actorSafe}</strong> registered walk-in guest" . ($gName !== '' ? " <strong>'{$gName}'</strong>" : '');
        } elseif ($entityType === 'exercise') {
            $eName = !empty($details['name']) ? h($details['name']) : '';
            $headline = "<strong>{$actorSafe}</strong> added exercise " . ($eName !== '' ? "<strong>'{$eName}'</strong>" : "#{$entityId}");
        } elseif ($entityType === 'trainer_assignment') {
            $headline = "<strong>{$actorSafe}</strong> assigned a trainer to a member";
        } elseif ($entityType === 'membership') {
            $headline = "<strong>{$actorSafe}</strong> registered a new gym membership";
        } else {
            $headline = "<strong>{$actorSafe}</strong> created a new " . strtolower($friendlyEntity);
        }
    } elseif ($action === 'edit' || $action === 'update') {
        if ($entityType === 'user') {
            $headline = "<strong>{$actorSafe}</strong> updated user account <strong>#{$entityId}</strong>";
        } elseif ($entityType === 'plan') {
            $pName = !empty($details['plan_name']) ? h($details['plan_name']) : '';
            $headline = "<strong>{$actorSafe}</strong> updated membership plan " . ($pName !== '' ? "<strong>'{$pName}'</strong>" : "#{$entityId}");
        } elseif ($entityType === 'class') {
            $cName = !empty($details['class_name']) ? h($details['class_name']) : '';
            $headline = "<strong>{$actorSafe}</strong> updated class " . ($cName !== '' ? "<strong>'{$cName}'</strong>" : "#{$entityId}");
        } elseif ($entityType === 'payment') {
            $headline = "<strong>{$actorSafe}</strong> updated payment <strong>#{$entityId}</strong>";
        } else {
            $headline = "<strong>{$actorSafe}</strong> updated " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
        }
    } elseif ($action === 'delete') {
        if ($entityType === 'user') {
            $r = !empty($details['role']) ? ucwords(str_replace('_', ' ', (string)$details['role'])) . ' ' : '';
            $headline = "<strong>{$actorSafe}</strong> deleted {$r}account <strong>#{$entityId}</strong>";
        } else {
            $headline = "<strong>{$actorSafe}</strong> deleted " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
        }
    } elseif ($action === 'update_status') {
        $st = !empty($details['new_status']) ? ucwords(str_replace('_', ' ', (string)$details['new_status'])) : '';
        $headline = "<strong>{$actorSafe}</strong> changed " . strtolower($friendlyEntity) . " status" . ($st !== '' ? " to <strong>{$st}</strong>" : '');
    } elseif (in_array($action, ['checkin', 'qr_checkin', 'manual_checkin', 'member_checkin'], true)) {
        $memberId = $details['user_id'] ?? null;
        $headline = $memberId ? "Member <strong>#{$memberId}</strong> checked in to gym" : "Member check-in recorded";
    } elseif (in_array($action, ['checkout', 'qr_checkout', 'manual_checkout'], true)) {
        $memberId = $details['user_id'] ?? null;
        $headline = $memberId ? "Member <strong>#{$memberId}</strong> checked out from gym" : "Member check-out recorded";
    } elseif ($action === 'mark_paid') {
        $headline = "<strong>{$actorSafe}</strong> marked commission payout <strong>#{$entityId}</strong> as paid";
    } elseif ($action === 'convert') {
        $headline = "<strong>{$actorSafe}</strong> converted walk-in guest into a registered member";
    } elseif ($action === 'update_subscription' || $action === 'update_plan_pricing') {
        $headline = "<strong>{$actorSafe}</strong> updated subscription details for " . strtolower($friendlyEntity);
    } else {
        $headline = "<strong>{$actorSafe}</strong> {$friendlyAction} " . strtolower($friendlyEntity) . ($entityId !== '' ? " <strong>#{$entityId}</strong>" : '');
    }

    // 6. Format Clean Key-Value Chips
    $chips = [];
    foreach ($details as $k => $v) {
        $kStr = (string)$k;
        if (is_bool($v)) {
            $vStr = $v ? 'Yes' : 'No';
        } elseif (is_array($v)) {
            $vStr = json_encode($v);
        } else {
            $vStr = (string)$v;
        }

        $label = ucwords(str_replace('_', ' ', $kStr));
        $valFormatted = ucwords(str_replace('_', ' ', $vStr));

        if ($kStr === 'password_changed') {
            $label = 'Password';
            $valFormatted = ($v === true || $v === '1' || $v === 1) ? 'Updated' : 'Unchanged';
        } elseif ($kStr === 'price' || $kStr === 'amount') {
            $label = ($kStr === 'price') ? 'Price' : 'Amount';
            if (is_numeric($vStr)) {
                $valFormatted = '₱' . number_format((float)$vStr, 2);
            }
        } elseif ($kStr === 'new_status' || $kStr === 'status') {
            $label = 'Status';
            $valFormatted = ucwords(str_replace('_', ' ', $vStr));
        } elseif ($kStr === 'method') {
            $label = 'Method';
            $valFormatted = ($vStr === 'qr') ? 'QR Code Scan' : ucwords(str_replace('_', ' ', $vStr));
        } elseif ($kStr === 'role') {
            $label = 'Role';
            $valFormatted = ucwords(str_replace('_', ' ', $vStr));
        } elseif ($kStr === 'start') {
            $label = 'Schedule';
            $ts = strtotime($vStr);
            if ($ts !== false) {
                $valFormatted = date('M j, Y g:i A', $ts);
            }
        } elseif ($kStr === 'user_id') {
            $label = 'User';
            $valFormatted = '#' . $vStr;
        } elseif ($kStr === 'gym_id') {
            $label = 'Gym';
            $valFormatted = '#' . $vStr;
        } elseif ($kStr === 'class_id') {
            $label = 'Class';
            $valFormatted = '#' . $vStr;
        } elseif ($kStr === 'plan_id') {
            $label = 'Plan';
            $valFormatted = '#' . $vStr;
        }

        $chips[] = [
            'key'   => $kStr,
            'label' => $label,
            'value' => $valFormatted,
        ];
    }

    // 7. Clean Formatted Dates: concise on mobile (e.g. "Sep 24 · 6:17 PM")
    $createdAtTs = strtotime((string)$row['created_at']);
    $isThisYear = (date('Y', $createdAtTs) === date('Y'));
    $friendlyTime = $isThisYear ? date('M j · g:i A', $createdAtTs) : date('M j, Y · g:i A', $createdAtTs);
    $exactTime = date('F j, Y, g:i:s A', $createdAtTs);

    return [
        'badge_label'    => $friendlyAction,
        'color'          => $color,
        'icon_svg'       => $iconSvg,
        'actor_name'     => $actorName,
        'actor_initials' => $initials,
        'role_label'     => $roleLabel,
        'friendly_time'  => $friendlyTime,
        'exact_time'     => $exactTime,
        'friendly_entity'=> $friendlyEntity,
        'entity_id'      => $entityId,
        'headline_html'  => $headline,
        'details_chips'  => $chips,
        'raw_details'    => $rawDetails,
    ];
}

function audit_logs_page(): void
{
    $user = require_roles(['platform_admin']);

    // Filters
    $filterAdmin  = $_GET['admin_id'] ?? '';
    $filterAction = $_GET['action_filter'] ?? '';
    $filterEntity = $_GET['entity_filter'] ?? '';
    $filterFrom   = $_GET['from'] ?? '';
    $filterTo     = $_GET['to'] ?? '';

    $where  = '1=1';
    $params = [];

    if ($filterAdmin !== '') {
        $where .= ' AND a.admin_user_id = ?';
        $params[] = (int) $filterAdmin;
    }
    if ($filterAction !== '') {
        $where .= ' AND a.action = ?';
        $params[] = $filterAction;
    }
    if ($filterEntity !== '') {
        $where .= ' AND a.entity_type = ?';
        $params[] = $filterEntity;
    }
    if ($filterFrom !== '') {
        $where .= ' AND a.created_at >= ?';
        $params[] = $filterFrom . ' 00:00:00';
    }
    if ($filterTo !== '') {
        $where .= ' AND a.created_at <= ?';
        $params[] = $filterTo . ' 23:59:59';
    }

    // Pagination
    $page   = max(1, (int) ($_GET['p'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total      = (int) scalar('SELECT COUNT(*) FROM admin_audit_logs a WHERE ' . $where, $params);
    $totalPages = max(1, (int) ceil($total / $limit));

    $rows = query_all(
        'SELECT a.*, 
                CONCAT(u.first_name, " ", u.last_name) AS admin_name,
                u.first_name,
                u.last_name,
                u.role AS admin_role,
                u.profile_picture AS admin_profile_picture,
                u.email AS admin_email
         FROM admin_audit_logs a
         LEFT JOIN users u ON u.user_id = a.admin_user_id
         WHERE ' . $where . '
         ORDER BY a.created_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );

    // Build URL query string for pagination
    $queryParams = [];
    if ($filterAdmin !== '') $queryParams['admin_id'] = $filterAdmin;
    if ($filterAction !== '') $queryParams['action_filter'] = $filterAction;
    if ($filterEntity !== '') $queryParams['entity_filter'] = $filterEntity;
    if ($filterFrom !== '') $queryParams['from'] = $filterFrom;
    if ($filterTo !== '') $queryParams['to'] = $filterTo;
    $paginationBaseUrl = 'index.php?page=audit_logs' . ($queryParams ? '&' . http_build_query($queryParams) : '');

    // Distinct values for filter dropdowns with friendly names
    $admins  = query_all('SELECT DISTINCT a.admin_user_id, CONCAT(u.first_name, " ", u.last_name) AS name, u.role FROM admin_audit_logs a JOIN users u ON u.user_id = a.admin_user_id ORDER BY name');
    $actions = query_all('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action');
    $entities = query_all('SELECT DISTINCT entity_type FROM admin_audit_logs ORDER BY entity_type');

    render_header('Audit Logs', $user);
    ?>
    <style>
    /* Responsive Desktop Table vs Mobile Cards */
    .audit-desktop-table {
        display: block;
    }
    .audit-mobile-cards {
        display: none;
    }

    @media (max-width: 860px) {
        .audit-desktop-table {
            display: none !important;
        }
        .audit-mobile-cards {
            display: flex !important;
            flex-direction: column;
            gap: 12px;
        }
    }

    /* Filters Form Layout - 2 per row on mobile */
    .audit-filters-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        align-items: flex-end;
        margin-bottom: 20px;
        padding: 16px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.04));
        border-radius: 12px;
        border: 1px solid var(--line);
    }

    .audit-filter-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        min-width: 0;
    }

    .audit-filter-item .form-control {
        width: 100%;
        box-sizing: border-box;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: var(--panel, #10131b);
        color: var(--ink);
        font-size: 13px;
        outline: none;
        transition: border-color 0.2s ease;
    }

    .audit-filter-item .form-control:focus {
        border-color: var(--lime);
    }

    .audit-filter-actions {
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }

    .audit-filter-actions .btn {
        flex: 1;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        text-decoration: none;
        cursor: pointer;
    }

    @media (max-width: 768px) {
        .audit-filters-form {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            padding: 12px;
            gap: 10px;
        }

        .audit-filter-item.filter-admin { order: 1; }
        .audit-filter-item.filter-action { order: 2; }
        .audit-filter-item.filter-from { order: 3; }
        .audit-filter-item.filter-to { order: 4; }
        .audit-filter-item.filter-entity { order: 5; }
        .audit-filter-actions { 
            order: 6; 
            grid-column: auto;
            margin-top: 0;
            display: flex;
            gap: 6px;
        }

        .audit-filter-item .form-control {
            font-size: 12px;
            padding: 8px 6px;
            min-width: 0;
        }

        .audit-filter-actions .btn {
            font-size: 12px;
            padding: 0 6px;
            min-width: 0;
        }
    }

    /* Structured, Consistent Mobile Audit Card */
    .audit-card-item {
        background: color-mix(in srgb, var(--panel-soft) 55%, transparent);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: border-color 0.2s ease, transform 0.15s ease;
    }

    html[data-theme="light"] .audit-card-item,
    [data-theme="light"] .audit-card-item {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }

    .audit-card-item:hover {
        border-color: color-mix(in srgb, var(--lime) 40%, transparent);
    }

    /* Card Top Bar: Action badge on left, Date on right - NEVER WRAPS */
    .audit-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: nowrap !important;
        min-width: 0;
    }

    /* Refined, Elegant Action Pill (Subtle & clean, avoids "christmas lights") */
    .audit-action-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2.5px 8px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.2px;
        border: 1px solid var(--line);
        background: var(--panel-soft, rgba(255, 255, 255, 0.04));
        color: var(--ink);
        white-space: nowrap !important;
        flex-shrink: 0;
        line-height: 1.35;
    }

    html[data-theme="light"] .audit-action-pill,
    [data-theme="light"] .audit-action-pill {
        background: #f8fafc;
        border-color: #e2e8f0;
        color: #0f172a;
    }

    .audit-action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .audit-card-time {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        color: var(--muted);
        white-space: nowrap !important;
        flex-shrink: 0;
        margin-left: auto;
    }

    /* Human Narrative Story Headline */
    .audit-card-story {
        font-size: 13.5px;
        line-height: 1.45;
        color: var(--ink);
        word-break: break-word;
    }

    .audit-card-story strong {
        color: var(--ink);
        font-weight: 700;
    }

    html[data-theme="light"] .audit-card-story strong,
    [data-theme="light"] .audit-card-story strong {
        color: #0f172a;
    }

    /* Unified Footer: Actor info & clean chips */
    .audit-card-footer {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding-top: 10px;
        border-top: 1px solid color-mix(in srgb, var(--line) 60%, transparent);
    }

    .audit-actor-profile {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .audit-actor-avatar-img,
    .audit-actor-avatar-circle {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .audit-actor-avatar-img {
        object-fit: cover;
        border: 1px solid var(--line);
    }

    .audit-actor-avatar-circle {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 700;
        background: var(--panel-soft, rgba(255, 255, 255, 0.07));
        color: var(--muted);
        border: 1px solid var(--line);
    }

    html[data-theme="light"] .audit-actor-avatar-circle,
    [data-theme="light"] .audit-actor-avatar-circle {
        background: #f1f5f9;
        color: #64748b;
        border-color: #cbd5e1;
    }

    .audit-actor-texts {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        min-width: 0;
    }

    .audit-actor-name {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--ink);
    }

    /* Clean, Subtle, Proportional Role Tag */
    .audit-role-tag {
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 0.2px;
        padding: 2px 7px;
        border-radius: 4px;
        background: var(--panel-soft, rgba(255, 255, 255, 0.05));
        color: var(--muted);
        border: 1px solid var(--line);
        display: inline-block;
        white-space: nowrap !important;
        line-height: 1.3;
    }

    html[data-theme="light"] .audit-role-tag,
    [data-theme="light"] .audit-role-tag {
        background: #f1f5f9;
        color: #64748b;
        border-color: #cbd5e1;
    }

    /* Tag & Details Chips Container */
    .audit-card-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .audit-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2.5px 7px;
        border-radius: 6px;
        background: var(--panel, #10131b);
        border: 1px solid var(--line);
        font-size: 11px;
        line-height: 1.3;
    }

    html[data-theme="light"] .audit-chip,
    [data-theme="light"] .audit-chip {
        background: #f8fafc;
        border-color: #e2e8f0;
    }

    .audit-chip-target {
        border-color: color-mix(in srgb, var(--lime) 30%, var(--line));
    }

    .audit-chip-key {
        color: var(--muted);
        font-weight: 500;
    }

    .audit-chip-val {
        color: var(--ink);
        font-weight: 600;
    }

    .audit-details-raw {
        font-size: 11px;
        color: var(--muted);
        word-break: break-word;
    }

    /* Desktop Table Styling & Compact Sizing */
    .audit-desktop-table table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .audit-desktop-table th {
        padding: 10px 12px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted);
        font-weight: 700;
        border-bottom: 1px solid var(--line);
        white-space: nowrap;
    }

    .audit-desktop-table td {
        padding: 9px 12px;
        font-size: 12.5px;
        vertical-align: middle;
        border-bottom: 1px solid color-mix(in srgb, var(--line) 50%, transparent);
    }

    .table-actor-cell {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-story-cell {
        font-size: 12.5px;
        color: var(--ink);
        line-height: 1.4;
    }

    /* Pagination Bar */
    .audit-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        margin-top: 20px;
        padding-top: 14px;
        border-top: 1px solid var(--line);
    }

    .audit-pagination-bar .pagination {
        margin-top: 0;
    }

    .audit-pagination-info {
        font-size: 13px;
        color: var(--muted);
    }

    .audit-pagination-info strong {
        color: var(--ink);
        font-weight: 600;
    }

    @media (max-width: 600px) {
        .audit-pagination-bar {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
    }
    </style>

    <section class="panel">
        <div class="page-header" style="flex-wrap: wrap; gap: 12px;">
            <div>
                <h1>Audit Logs</h1>
                <p>Track activities, submissions, and changes across the platform in plain English.</p>
            </div>
            <span class="badge badge-active"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
        </div>

        <!-- Responsive Filters Form (2 per row on mobile) -->
        <form method="get" class="audit-filters-form">
            <input type="hidden" name="page" value="audit_logs">
            
            <label class="audit-filter-item filter-admin">
                <span>Performed By</span>
                <select name="admin_id" class="form-control">
                    <option value="">All users</option>
                    <?php foreach ($admins as $a): ?>
                        <?php
                            $roleName = match($a['role'] ?? '') {
                                'platform_admin' => 'Admin',
                                'gym_owner'      => 'Gym Owner',
                                'trainer'        => 'Trainer',
                                'member'         => 'Member',
                                default          => 'Staff'
                            };
                        ?>
                        <option value="<?= (int) $a['admin_user_id'] ?>" <?= selected((string) $a['admin_user_id'], $filterAdmin) ?>>
                            <?= h($a['name']) ?> (<?= $roleName ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-action">
                <span>Action</span>
                <select name="action_filter" class="form-control">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= h($a['action']) ?>" <?= selected($a['action'], $filterAction) ?>>
                            <?= h(get_friendly_audit_action($a['action'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-entity">
                <span>Category</span>
                <select name="entity_filter" class="form-control">
                    <option value="">All categories</option>
                    <?php foreach ($entities as $e): ?>
                        <option value="<?= h($e['entity_type']) ?>" <?= selected($e['entity_type'], $filterEntity) ?>>
                            <?= h(get_friendly_audit_entity($e['entity_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="audit-filter-item filter-from">
                <span>From</span>
                <input type="date" name="from" class="form-control" value="<?= h($filterFrom) ?>">
            </label>

            <label class="audit-filter-item filter-to">
                <span>To</span>
                <input type="date" name="to" class="form-control" value="<?= h($filterTo) ?>">
            </label>

            <div class="audit-filter-actions">
                <button type="submit" class="btn btn-primary" style="background:var(--lime);color:var(--bg);font-weight:700;">Filter</button>
                <a href="index.php?page=audit_logs" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        <?php if (!$rows): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <p>No audit log entries found matching your criteria.</p>
            </div>
        <?php else: ?>
            <!-- Desktop Table View (> 860px) -->
            <div class="audit-desktop-table table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 125px;">Time</th>
                            <th style="width: 155px;">Performed By</th>
                            <th style="width: 95px;">Role</th>
                            <th style="width: 105px;">Action</th>
                            <th>What Happened</th>
                            <th style="width: 150px;">Reference</th>
                            <th style="width: 180px;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): 
                        $meta = format_audit_log_entry($row);
                    ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:12px;color:var(--muted);" title="<?= h($meta['exact_time']) ?>">
                                <?= h($meta['friendly_time']) ?>
                            </td>
                            <td>
                                <div class="table-actor-cell">
                                    <?php if (!empty($row['admin_profile_picture'])): ?>
                                        <img src="<?= h(upload_url($row['admin_profile_picture'], 'uploads')) ?>" alt="Avatar" class="audit-actor-avatar-img">
                                    <?php else: ?>
                                        <div class="audit-actor-avatar-circle">
                                            <?= h($meta['actor_initials']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <strong style="font-size:12.5px;color:var(--ink);white-space:nowrap;"><?= h($meta['actor_name']) ?></strong>
                                </div>
                            </td>
                            <td style="white-space:nowrap;">
                                <span class="audit-role-tag"><?= h($meta['role_label']) ?></span>
                            </td>
                            <td>
                                <span class="audit-action-pill">
                                    <span class="audit-action-icon" style="color:<?= $meta['color'] ?>;"><?= $meta['icon_svg'] ?></span>
                                    <span><?= h($meta['badge_label']) ?></span>
                                </span>
                            </td>
                            <td class="table-story-cell">
                                <?= $meta['headline_html'] ?>
                            </td>
                            <td style="font-size:12px;white-space:nowrap;">
                                <span style="font-weight:600;color:var(--ink);"><?= h($meta['friendly_entity']) ?></span>
                                <?php if ($meta['entity_id'] !== ''): ?>
                                    <span style="font-family:ui-monospace, monospace;color:var(--muted);">#<?= h($meta['entity_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11.5px;color:var(--muted);">
                                <?php if (!empty($meta['details_chips'])): ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                        <?php foreach (array_slice($meta['details_chips'], 0, 2) as $c): ?>
                                            <span class="audit-chip">
                                                <span class="audit-chip-key"><?= h($c['label']) ?>:</span>
                                                <span class="audit-chip-val"><?= h($c['value']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($meta['details_chips']) > 2): ?>
                                            <span class="audit-chip" title="<?= h($row['details'] ?? '') ?>">+<?= count($meta['details_chips']) - 2 ?> more</span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($meta['raw_details'] !== ''): ?>
                                    <span class="audit-details-raw" title="<?= h($meta['raw_details']) ?>"><?= h($meta['raw_details']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Consistent & Predictable Mobile Card View (<= 860px) -->
            <div class="audit-mobile-cards">
                <?php foreach ($rows as $row): 
                    $meta = format_audit_log_entry($row);
                ?>
                    <div class="audit-card-item">
                        <!-- Top Bar: Action badge on left, Timestamp on right (NEVER WRAPS) -->
                        <div class="audit-card-top">
                            <span class="audit-action-pill">
                                <span class="audit-action-icon" style="color:<?= $meta['color'] ?>;"><?= $meta['icon_svg'] ?></span>
                                <span><?= h($meta['badge_label']) ?></span>
                            </span>
                            <div class="audit-card-time" title="<?= h($meta['exact_time']) ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <span><?= h($meta['friendly_time']) ?></span>
                            </div>
                        </div>

                        <!-- Story: Plain-English Narrative -->
                        <div class="audit-card-story">
                            <?= $meta['headline_html'] ?>
                        </div>

                        <!-- Unified Footer: Performed By + Tagged Details -->
                        <div class="audit-card-footer">
                            <div class="audit-actor-profile">
                                <?php if (!empty($row['admin_profile_picture'])): ?>
                                    <img src="<?= h(upload_url($row['admin_profile_picture'], 'uploads')) ?>" alt="Avatar" class="audit-actor-avatar-img">
                                <?php else: ?>
                                    <div class="audit-actor-avatar-circle">
                                        <?= h($meta['actor_initials']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="audit-actor-texts">
                                    <span class="audit-actor-name"><?= h($meta['actor_name']) ?></span>
                                    <span class="audit-role-tag"><?= h($meta['role_label']) ?></span>
                                </div>
                            </div>

                            <div class="audit-card-tags">
                                <?php if ($meta['friendly_entity'] !== '' || $meta['entity_id'] !== ''): ?>
                                    <span class="audit-chip audit-chip-target">
                                        <span class="audit-chip-key">Target:</span>
                                        <span class="audit-chip-val"><?= h($meta['friendly_entity']) ?><?= $meta['entity_id'] !== '' ? ' #' . h($meta['entity_id']) : '' ?></span>
                                    </span>
                                <?php endif; ?>

                                <?php foreach ($meta['details_chips'] as $chip): ?>
                                    <span class="audit-chip">
                                        <span class="audit-chip-key"><?= h($chip['label']) ?>:</span>
                                        <span class="audit-chip-val"><?= h($chip['value']) ?></span>
                                    </span>
                                <?php endforeach; ?>

                                <?php if (empty($meta['details_chips']) && $meta['raw_details'] !== ''): ?>
                                    <span class="audit-details-raw"><?= h($meta['raw_details']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Bar -->
            <?php if ($total > 0): ?>
                <div class="audit-pagination-bar">
                    <div class="audit-pagination-info">
                        Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $limit, $total) ?></strong> of <strong><?= $total ?></strong> records
                    </div>
                    <?php render_pagination($page, $totalPages, $paginationBaseUrl); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    render_footer();
}
