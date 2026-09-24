<?php
declare(strict_types=1);

function render_simple_table(array $rows, array $columns): string
{
    ob_start();
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . h(ucwords(str_replace('_', ' ', $column))) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        table_empty(count($columns), 'No records yet.');
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'price' || $column === 'revenue' || $column === 'amount') $value = money($value);
            if (in_array($column, ['action', 'actions', 'progress', 'adherence', 'feedback', 'start_date', 'end_date'])) {
                echo '<td>' . (string) $value . '</td>';
            } else {
                echo '<td>' . h((string) $value) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

function render_member_form(string $context, ?array $user = null, ?array $profile = null): void
{
    ?>
    <style>
        .pm-tab-bar {
            display: flex;
            background: var(--panel-soft);
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            margin-bottom: 18px;
            border: 1px solid var(--line);
        }
        .pm-tab-btn {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.18s ease;
        }
        .pm-tab-btn:hover {
            color: var(--ink);
        }
        .pm-tab-btn.active {
            background: var(--panel);
            color: var(--ink);
            border-color: var(--line);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .pm-pane {
            display: none;
            animation: pmFadeIn 0.2s ease;
        }
        .pm-pane.active {
            display: block;
        }
        @keyframes pmFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 14px;
        }
        .pm-grid .full-span {
            grid-column: 1 / -1;
        }
        .pm-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }
        .pm-field > label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
            white-space: nowrap;
            line-height: 1.25;
            margin-bottom: 2px;
        }
        .pm-req {
            color: var(--lime);
            font-weight: 800;
            font-size: 13px;
            line-height: 1;
            margin-left: 2px;
        }
        .pm-field input, .pm-field select {
            width: 100%;
            box-sizing: border-box;
            background: var(--panel);
            border: 1.5px solid var(--line);
            color: var(--ink);
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13.5px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .pm-field select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238792ad' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 32px;
            cursor: pointer;
        }
        .pm-field input:focus, .pm-field select:focus {
            border-color: var(--lime);
            outline: none;
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.18);
        }

        /* Modal Dialog Container & Scrolling Fix */
        #physicalProfileModal:not([open]) {
            display: none !important;
        }
        #physicalProfileModal[open] {
            display: flex !important;
            flex-direction: column;
            width: min(92vw, 560px) !important;
            max-width: 560px !important;
            max-height: min(88vh, 88dvh) !important;
            overflow: hidden !important;
            box-sizing: border-box;
            margin: auto;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.6);
        }
        #physicalProfileModal .modal-header {
            flex-shrink: 0;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
        }
        #physicalProfileModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto !important;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            padding: 18px 20px 0 20px;
            display: flex;
            flex-direction: column;
        }
        .pm-form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }
        .pm-pane.active {
            display: block;
            flex: 1 0 auto;
            padding-bottom: 16px;
        }

        /* Unified Custom Select Dropdowns (No Emoji, Glassmorphic Dark Theme) */
        .custom-select-wrapper,
        .custom-goal-dropdown {
            position: relative;
            width: 100%;
        }
        .custom-select-wrapper.open,
        .custom-goal-dropdown.open {
            z-index: 60;
        }
        .custom-select-native,
        .custom-goal-native-select {
            position: absolute !important;
            opacity: 0 !important;
            pointer-events: none !important;
            width: 1px !important;
            height: 1px !important;
            margin: -1px !important;
            clip: rect(0, 0, 0, 0) !important;
        }
        .custom-select-trigger,
        .custom-goal-trigger {
            width: 100%;
            box-sizing: border-box;
            background: var(--panel);
            border: 1.5px solid var(--line);
            color: var(--ink);
            border-radius: 9px;
            padding: 9px 12px;
            font-size: 13.5px;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.18s ease;
            text-align: left;
        }
        .custom-select-trigger:hover,
        .custom-goal-trigger:hover {
            border-color: color-mix(in srgb, var(--lime) 45%, var(--line));
            background: var(--panel-soft);
        }
        .custom-select-trigger:focus-visible,
        .custom-goal-trigger:focus-visible,
        .custom-select-wrapper.open .custom-select-trigger,
        .custom-goal-dropdown.open .custom-goal-trigger {
            border-color: var(--lime);
            outline: none;
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.18);
        }
        .custom-select-trigger-content,
        .custom-goal-trigger-content {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }
        .custom-select-trigger-icon,
        .custom-goal-trigger-icon {
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: color-mix(in srgb, var(--lime) 15%, transparent);
            color: var(--lime);
            flex-shrink: 0;
        }
        .custom-select-trigger-text,
        .custom-goal-trigger-text {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .custom-select-chevron,
        .custom-goal-chevron {
            color: var(--muted);
            transition: transform 0.22s ease, color 0.22s ease;
            flex-shrink: 0;
            margin-left: 8px;
        }
        .custom-select-wrapper.open .custom-select-chevron,
        .custom-goal-dropdown.open .custom-goal-chevron {
            transform: rotate(180deg);
            color: var(--lime);
        }
        .custom-select-menu,
        .custom-goal-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 1050;
            background: var(--sidebar);
            border: 1.5px solid color-mix(in srgb, var(--lime) 30%, var(--line));
            border-radius: 12px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px) scale(0.98);
            transition: opacity 0.18s cubic-bezier(0.16, 1, 0.3, 1), transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.18s;
            pointer-events: none;
        }
        .custom-select-wrapper.open .custom-select-menu,
        .custom-goal-dropdown.open .custom-goal-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .custom-select-menu.drop-up,
        .custom-goal-menu.drop-up {
            top: auto;
            bottom: calc(100% + 6px);
            box-shadow: 0 -16px 36px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.05);
            transform: translateY(6px) scale(0.98);
        }
        .custom-select-wrapper.open .custom-select-menu.drop-up,
        .custom-goal-dropdown.open .custom-goal-menu.drop-up {
            transform: translateY(0) scale(1);
        }
        .custom-goal-search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-bottom: 1px solid var(--line);
            background: rgba(0, 0, 0, 0.14);
        }
        .custom-goal-search-box svg {
            color: var(--muted);
            flex-shrink: 0;
        }
        .custom-goal-search-box input {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 4px 0 !important;
            font-size: 12.5px !important;
            color: var(--ink) !important;
            width: 100%;
        }
        .custom-goal-search-box input:focus {
            outline: none !important;
            box-shadow: none !important;
        }
        .custom-goal-clear-btn {
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            transition: color 0.15s;
        }
        .custom-goal-clear-btn:hover {
            color: var(--ink);
        }
        .custom-select-list,
        .custom-goal-list {
            max-height: 240px;
            overflow-y: auto;
            padding: 6px;
            scroll-behavior: smooth;
        }
        .custom-goal-group {
            margin-bottom: 6px;
        }
        .custom-goal-group:last-child {
            margin-bottom: 0;
        }
        .custom-goal-group-title {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--lime);
            padding: 6px 10px 3px;
            user-select: none;
        }
        .custom-select-item,
        .custom-goal-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.12s ease;
            margin-bottom: 2px;
            border: 1px solid transparent;
            user-select: none;
        }
        .custom-select-item:hover,
        .custom-goal-item:hover,
        .custom-select-item.highlighted,
        .custom-goal-item.highlighted {
            background: color-mix(in srgb, var(--lime) 10%, transparent);
            border-color: color-mix(in srgb, var(--lime) 25%, transparent);
        }
        .custom-select-item.selected,
        .custom-goal-item.selected {
            background: color-mix(in srgb, var(--lime) 16%, transparent);
            border-color: color-mix(in srgb, var(--lime) 40%, transparent);
        }
        .custom-select-item-details,
        .custom-goal-item-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            flex: 1;
        }
        .custom-select-item-title,
        .custom-goal-item-title {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .custom-select-item-desc,
        .custom-goal-item-desc {
            font-size: 11px;
            color: var(--muted);
            white-space: normal;
            line-height: 1.3;
        }
        .custom-select-check,
        .custom-goal-check {
            color: var(--lime);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 10px;
            flex-shrink: 0;
            opacity: 0;
            transform: scale(0.6);
            transition: all 0.15s ease;
        }
        .custom-select-item.selected .custom-select-check,
        .custom-goal-item.selected .custom-goal-check {
            opacity: 1;
            transform: scale(1);
        }
        .custom-goal-empty {
            padding: 18px 12px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            display: none;
        }
        .custom-select-list::-webkit-scrollbar,
        .custom-goal-list::-webkit-scrollbar {
            width: 5px;
        }
        .custom-select-list::-webkit-scrollbar-track,
        .custom-goal-list::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-select-list::-webkit-scrollbar-thumb,
        .custom-goal-list::-webkit-scrollbar-thumb {
            background: color-mix(in srgb, var(--lime) 30%, transparent);
            border-radius: 4px;
        }

        .pm-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            gap: 10px;
        }
        #physicalProfileModal .pm-footer {
            position: sticky;
            bottom: 0;
            background: var(--panel);
            border-top: 1px solid var(--line);
            margin-top: auto;
            margin-left: -20px;
            margin-right: -20px;
            padding: 12px 20px 14px 20px;
            z-index: 30;
            box-shadow: 0 -8px 20px rgba(0, 0, 0, 0.35);
        }
        @media (max-width: 520px) {
            #physicalProfileModal[open] {
                width: 95% !important;
                max-width: 95% !important;
                max-height: 92vh !important;
                max-height: 92dvh !important;
                margin: auto;
                border-radius: 12px;
            }
            #physicalProfileModal .modal-header {
                padding: 12px 16px;
            }
            #physicalProfileModal .modal-header h3 {
                font-size: 16px;
            }
            #physicalProfileModal .modal-body {
                padding: 14px 16px 0 16px;
            }
            #physicalProfileModal .pm-footer {
                margin-left: -16px;
                margin-right: -16px;
                padding: 10px 16px 12px 16px;
            }
            .pm-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .pm-tab-bar {
                margin-bottom: 12px;
                gap: 4px;
            }
            .pm-tab-btn {
                padding: 7px 8px;
            }
            .pm-tab-btn span.tab-title {
                display: none;
            }
            .pm-field {
                gap: 4px;
                font-size: 11.5px;
            }
            .pm-field input,
            .custom-select-trigger,
            .custom-goal-trigger {
                padding: 8px 10px;
                font-size: 13px;
            }
        }
        /* Dynamic Goal Targets System */
        .pm-goal-desc-card {
            margin-top: 10px;
            padding: 11px 15px;
            border-radius: 10px;
            background: color-mix(in srgb, var(--lime) 5%, var(--panel-soft));
            border: 1px solid color-mix(in srgb, var(--lime) 20%, var(--line));
            border-left: 3.5px solid var(--lime);
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }
        .pm-goal-desc-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: color-mix(in srgb, var(--lime) 18%, transparent);
            color: var(--lime);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pm-goal-desc-text {
            font-size: 12.5px;
            color: var(--ink);
            line-height: 1.45;
            font-weight: 500;
        }
        .pm-target-section {
            background: color-mix(in srgb, var(--lime) 3%, var(--panel-soft));
            border: 1.5px solid color-mix(in srgb, var(--lime) 22%, var(--line));
            border-radius: 14px;
            padding: 16px 18px;
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.22);
            transition: all 0.2s ease;
        }
        .pm-supporting-section {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px 18px;
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.2s ease;
        }
        .pm-target-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2px;
        }
        .pm-target-section-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .pm-target-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .pm-target-badge.primary {
            background: color-mix(in srgb, var(--lime) 16%, transparent);
            color: var(--lime);
            border: 1px solid color-mix(in srgb, var(--lime) 35%, transparent);
        }
        .pm-target-badge.optional {
            background: color-mix(in srgb, var(--panel) 80%, transparent);
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .pm-target-section-desc {
            font-size: 12px;
            color: var(--muted);
            margin: -4px 0 6px 0;
            line-height: 1.45;
        }
        .pm-read-only-badge {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--panel);
            border: 1.5px solid var(--line);
            border-radius: 8px;
            padding: 9px 12px;
            min-height: 42px;
            box-sizing: border-box;
        }
        .pm-read-only-badge .val {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--ink);
        }
        .pm-read-only-badge .unit {
            font-size: 11.5px;
            color: var(--muted);
            font-weight: 600;
            background: var(--panel-soft);
            padding: 2px 7px;
            border-radius: 5px;
            border: 1px solid var(--line);
        }
        .pm-read-only-badge.highlight-lime .val {
            color: var(--lime);
        }
        .pm-input-with-unit {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .pm-input-with-unit input {
            padding-right: 46px !important;
            min-height: 42px;
            font-weight: 600;
            font-size: 14px;
        }
        .pm-input-with-unit .pm-unit {
            position: absolute;
            right: 10px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--muted);
            background: var(--panel-soft);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid var(--line);
            pointer-events: none;
        }
        .pm-field-hint {
            font-size: 11px;
            color: var(--muted);
            font-weight: 400;
            margin-top: 3px;
            line-height: 1.35;
        }
        .pm-field-error {
            font-size: 11.5px;
            color: #ef4444;
            font-weight: 600;
            margin-top: 3px;
            display: none;
        }
        /* Live Interactive PR & Target Delta Banners */
        .pm-live-delta-banner {
            margin-top: 4px;
            animation: pmFadeIn 0.25s ease;
        }
        .pm-delta-badge {
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .pm-delta-badge.positive {
            background: color-mix(in srgb, var(--lime) 10%, var(--panel-soft));
            border: 1px solid color-mix(in srgb, var(--lime) 30%, var(--line));
            color: var(--ink);
        }
        .pm-delta-badge.positive .pm-delta-title {
            font-weight: 700;
            color: var(--lime);
            font-size: 12.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pm-delta-badge.positive .pm-delta-sub {
            font-size: 11px;
            color: var(--muted);
        }
        .pm-delta-badge.warning {
            flex-direction: row;
            align-items: center;
            gap: 8px;
            background: color-mix(in srgb, #ef4444 14%, var(--panel-soft));
            border: 1px solid color-mix(in srgb, #ef4444 40%, var(--line));
            color: #fca5a5;
            font-weight: 600;
            padding: 9px 12px;
            font-size: 12px;
        }
        .pm-delta-badge.warning svg {
            flex-shrink: 0;
            color: #ef4444;
        }
        .pm-delta-bar-wrap {
            background: rgba(0, 0, 0, 0.35);
            height: 5px;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 2px;
        }
        .pm-delta-bar {
            background: var(--lime);
            height: 100%;
            border-radius: 999px;
            transition: width 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @media (max-width: 520px) {
            .pm-target-section,
            .pm-supporting-section {
                padding: 12px;
                gap: 8px;
            }
        }
    </style>

    <form method="post" class="pm-form" id="physicalProfileForm_<?= h($context) ?>" onsubmit="return handleProfileFormSubmit_<?= h($context) ?>(this, event);">
        <?= csrf_field() ?>

        <!-- Segmented Tab Header -->
        <div class="pm-tab-bar" role="tablist">
            <button type="button" class="pm-tab-btn active" id="tabBtn_<?= h($context) ?>_body" onclick="switchProfileTab_<?= h($context) ?>('body')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/></svg>
                <span class="tab-title">Body Stats</span>
            </button>
            <button type="button" class="pm-tab-btn" id="tabBtn_<?= h($context) ?>_goal" onclick="switchProfileTab_<?= h($context) ?>('goal')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                <span class="tab-title">Goal & Targets</span>
            </button>
            <button type="button" class="pm-tab-btn" id="tabBtn_<?= h($context) ?>_lifestyle" onclick="switchProfileTab_<?= h($context) ?>('lifestyle')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                <span class="tab-title">Lifestyle</span>
            </button>
        </div>

        <!-- TAB 1: BODY STATS -->
        <div class="pm-pane active" id="tabPane_<?= h($context) ?>_body">
            <div class="pm-grid">
                <?php if ($context !== 'profile'): ?>
                    <label class="pm-field">First name <input name="first_name" required value="<?= h($user['first_name'] ?? '') ?>"></label>
                    <label class="pm-field">Last name  <input name="last_name"  required value="<?= h($user['last_name']  ?? '') ?>"></label>
                    <label class="pm-field">Email      <input type="email" name="email" required value="<?= h($user['email'] ?? '') ?>"></label>
                    <label class="pm-field">Mobile Number *
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" required
                               title="Please enter exactly 11 digits" placeholder="09123456789"
                               value="<?= h($user['phone'] ?? '') ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                    </label>
                    <label class="pm-field full-span">Password   <input type="password" name="password" <?= $context === 'register' ? 'required minlength="8"' : '' ?> placeholder="<?= $context === 'register' ? 'Min. 8 characters' : 'Leave blank to keep current' ?>"></label>
                <?php endif; ?>

                <label class="pm-field">Height (cm)
                    <input name="height_cm" type="number" step="0.01" min="1"
                           <?= $context !== 'profile' ? 'required' : '' ?>
                           value="<?= h($profile['height_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Weight (kg)
                    <input name="weight_kg" type="number" step="0.01" min="1"
                           <?= $context !== 'profile' ? 'required' : '' ?>
                           value="<?= h($profile['weight_kg'] ?? '') ?>">
                </label>
                <label class="pm-field">Age
                    <input name="age" type="number" min="16" max="120"
                           class="<?= isset($errors['age']) ? 'input-error' : '' ?>"
                           value="<?= h($profile['age'] ?? '') ?>">
                </label>
                <?php
                $currentSex = $profile['biological_sex'] ?? 'male';
                $sexOptions = [
                    'male' => ['label' => 'Male', 'desc' => 'Standard body composition metrics'],
                    'female' => ['label' => 'Female', 'desc' => 'Includes hip circumference tracking']
                ];
                $selectedSexLabel = $sexOptions[$currentSex]['label'] ?? 'Male';
                ?>
                <div class="pm-field">
                    <label for="sexTrigger_<?= h($context) ?>">Biological sex</label>
                    <div class="custom-select-wrapper" id="sexDropdown_<?= h($context) ?>">
                        <select name="biological_sex" id="sexSelect_<?= h($context) ?>" class="custom-select-native" onchange="document.getElementById('hipContainer_<?= h($context) ?>').style.display = this.value === 'female' ? 'flex' : 'none';" <?= $context !== 'profile' ? 'required' : '' ?>>
                            <option value="male" <?= selected('male', $currentSex) ?>>Male</option>
                            <option value="female" <?= selected('female', $currentSex) ?>>Female</option>
                        </select>

                        <button type="button" class="custom-select-trigger" id="sexTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('sexDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="sexTriggerText_<?= h($context) ?>"><?= h($selectedSexLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($sexOptions as $val => $opt): 
                                    $isSelected = ($val === $currentSex);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h($val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('sexDropdown_<?= h($context) ?>', 'sexSelect_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <label class="pm-field">Neck (cm)
                    <input name="neck_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['neck_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Waist (cm)
                    <input name="waist_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['waist_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Chest (cm)
                    <input name="chest_cm" type="number" step="0.01" min="1" placeholder="optional"
                           value="<?= h($profile['chest_cm'] ?? '') ?>">
                </label>
                <label class="pm-field">Arm (cm)
                    <input name="arm_cm" type="number" step="0.01" min="1" placeholder="optional"
                           value="<?= h($profile['arm_cm'] ?? '') ?>">
                </label>
                <label class="pm-field full-span" id="hipContainer_<?= h($context) ?>" style="display: <?= ($profile['biological_sex'] ?? 'male') === 'female' ? 'flex' : 'none' ?>;">Hip (cm)
                    <input name="hip_cm" type="number" step="0.01" min="1"
                           value="<?= h($profile['hip_cm'] ?? '') ?>">
                </label>
            </div>
        </div>

        <!-- TAB 2: GOAL & TARGETS -->
        <?php
        $userWeight = (float)($profile['weight_kg'] ?? 0);
        $userHeight = (float)($profile['height_cm'] ?? 0);
        $userNeck   = (float)($profile['neck_cm'] ?? 0);
        $userWaist  = (float)($profile['waist_cm'] ?? 0);
        $userHip    = (float)($profile['hip_cm'] ?? 0);
        $userSex    = $profile['biological_sex'] ?? 'male';

        // Navy Formula estimate or latest progress log body fat
        $currentBfEst = null;
        if ($userHeight > 0 && $userNeck > 0 && $userWaist > 0) {
            if ($userSex !== 'female' && $userWaist > $userNeck) {
                $bfCalc = 495 / (1.0324 - 0.19077 * log10($userWaist - $userNeck) + 0.15456 * log10($userHeight)) - 450;
                if ($bfCalc >= 3 && $bfCalc <= 65) $currentBfEst = round($bfCalc, 1);
            } elseif ($userSex === 'female' && $userHip > 0 && ($userWaist + $userHip) > $userNeck) {
                $bfCalc = 495 / (1.29579 - 0.35004 * log10($userWaist + $userHip - $userNeck) + 0.22100 * log10($userHeight)) - 450;
                if ($bfCalc >= 3 && $bfCalc <= 65) $currentBfEst = round($bfCalc, 1);
            }
        }
        if (!$currentBfEst && isset($user['user_id'])) {
            try {
                $latestBf = scalar('SELECT body_fat_percent FROM progress_logs WHERE user_id = ? AND body_fat_percent IS NOT NULL ORDER BY log_date DESC LIMIT 1', [$user['user_id']]);
                if ($latestBf) $currentBfEst = round((float)$latestBf, 1);
            } catch (Throwable) {}
        }

        $strengthExercises = [
            'Bench Press'    => ['label' => 'Bench Press', 'desc' => 'Chest, anterior deltoids & triceps compound lift'],
            'Squat'          => ['label' => 'Back Squat', 'desc' => 'Quadriceps, hamstrings, glutes & core compound lift'],
            'Deadlift'       => ['label' => 'Deadlift', 'desc' => 'Posterior chain, back, traps, glutes & grip power'],
            'Overhead Press' => ['label' => 'Overhead Press', 'desc' => 'Shoulders, upper chest, triceps & core stability'],
            'Barbell Row'    => ['label' => 'Barbell Row', 'desc' => 'Upper back, lats, rhomboids & biceps thickness'],
            'Other'          => ['label' => 'Other / Custom Lift', 'desc' => 'Custom compound or accessory strength movement']
        ];
        $selectedExercise = $profile['target_exercise'] ?? 'Bench Press';
        $selectedExerciseLabel = $strengthExercises[$selectedExercise]['label'] ?? ($selectedExercise ?: 'Bench Press');

        $enduranceActivities = [
            'Running'  => ['label' => 'Running', 'desc' => 'Outdoor running, track or treadmill pace & distance'],
            'Cycling'  => ['label' => 'Cycling', 'desc' => 'Road cycling, stationary bike or spin distance'],
            'Swimming' => ['label' => 'Swimming', 'desc' => 'Lap swimming, open water aerobic stamina'],
            'Rowing'   => ['label' => 'Rowing', 'desc' => 'Ergometer or water rowing full-body endurance'],
            'Walking'  => ['label' => 'Walking / Rucking', 'desc' => 'Brisk walking, power walking or rucking targets'],
            'Other'    => ['label' => 'Other Activity', 'desc' => 'Cardio or endurance activity target']
        ];
        $selectedEnduranceActivity = $profile['endurance_activity'] ?? 'Running';
        $selectedEnduranceLabel = $enduranceActivities[$selectedEnduranceActivity]['label'] ?? ($selectedEnduranceActivity ?: 'Running');

        $weeklyWorkoutOptions = [
            2 => ['label' => '2 Workouts / Week', 'desc' => 'Light maintenance & active recovery routine'],
            3 => ['label' => '3 Workouts / Week', 'desc' => 'Classic full-body split (Great for busy schedules)'],
            4 => ['label' => '4 Workouts / Week', 'desc' => 'Upper / Lower split (Optimal balance & recovery)'],
            5 => ['label' => '5 Workouts / Week', 'desc' => 'Push / Pull / Legs or body-part split'],
            6 => ['label' => '6 Workouts / Week', 'desc' => 'High-frequency dedicated training routine'],
            7 => ['label' => '7 Workouts / Week', 'desc' => 'Daily athletic conditioning & mobility routine']
        ];
        $selectedWeeklyWorkouts = (int)($profile['weekly_workout_target'] ?? 4);
        if ($selectedWeeklyWorkouts < 1 || $selectedWeeklyWorkouts > 7) $selectedWeeklyWorkouts = 4;
        $selectedWeeklyLabel = $weeklyWorkoutOptions[$selectedWeeklyWorkouts]['label'] ?? ($selectedWeeklyWorkouts . ' workouts per week');

        $goalGroups = [
            'Primary Fitness Goals' => [
                'items' => [
                    'increasing_strength' => ['label' => 'Increasing Maximum Strength', 'desc' => 'Focus on lifting heavier weights and compound lift PRs'],
                    'building_muscle'     => ['label' => 'Building Muscle', 'desc' => 'Focus on muscle hypertrophy, lean mass & progressive overload'],
                    'losing_weight'       => ['label' => 'Losing Weight', 'desc' => 'Focus on reaching a sustainable target body weight'],
                    'reducing_body_fat'   => ['label' => 'Reducing Body Fat', 'desc' => 'Focus on reducing body-fat percentage & waistline'],
                    'improving_endurance' => ['label' => 'Improving Endurance', 'desc' => 'Focus on aerobic capacity, stamina & cardiovascular pacing'],
                    'general_fitness'     => ['label' => 'Improving General Fitness', 'desc' => 'Focus on consistent weekly workouts & overall health'],
                ]
            ],
            'Specific Targets' => [
                'items' => [
                    'Building a visible six-pack'    => ['label' => 'Building a visible six-pack', 'desc' => 'Abdominal hypertrophy, core definition & waist reduction'],
                    'Growing larger biceps and arms' => ['label' => 'Growing larger biceps and arms', 'desc' => 'Bicep peak & tricep circumference tracking'],
                    'Developing a wide chest'        => ['label' => 'Developing a wide chest', 'desc' => 'Pectoral hypertrophy & upper chest expansion'],
                    'Sculpting a V-tapered back'     => ['label' => 'Sculpting a V-tapered back', 'desc' => 'Lat width, upper back thickness & small waistline'],
                    'Shaping the lower body'         => ['label' => 'Shaping the lower body', 'desc' => 'Glutes, quadriceps & hamstring development'],
                    'Gaining lean body mass'         => ['label' => 'Gaining lean body mass', 'desc' => 'Caloric surplus with lean hypertrophy tracking'],
                    'Reaching body recomposition'    => ['label' => 'Reaching body recomposition', 'desc' => 'Concurrent muscle gain and fat loss at maintenance'],
                ]
            ],
        ];

        $currentGoalVal = (string)($profile['primary_goal'] ?? 'increasing_strength');
        $currentGoalItem = null;
        foreach ($goalGroups as $group) {
            if (isset($group['items'][$currentGoalVal])) {
                $currentGoalItem = $group['items'][$currentGoalVal];
                break;
            }
        }
        if (!$currentGoalItem) {
            $currentGoalItem = [
                'label' => !empty($currentGoalVal) ? ucwords(str_replace('_', ' ', $currentGoalVal)) : 'Select Primary Goal',
                'desc'  => ''
            ];
        }
        ?>
        <div class="pm-pane" id="tabPane_<?= h($context) ?>_goal">
            <!-- 1. Primary Goal Dropdown -->
            <div class="pm-field full-span">
                <label id="primaryGoalLabel_<?= h($context) ?>" for="primaryGoalTrigger_<?= h($context) ?>" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                    <span>Primary Goal</span>
                    <span class="muted" style="font-weight: 400; font-size: 11px;">Select your main fitness focus</span>
                </label>

                <div class="custom-select-wrapper custom-goal-dropdown" id="customGoalDropdown_<?= h($context) ?>">
                    <!-- Underlying select keeps form submit 100% intact -->
                    <select name="primary_goal" id="primaryGoalSelect_<?= h($context) ?>" class="custom-select-native custom-goal-native-select" onchange="updateTargetMetrics_<?= h($context) ?>(this.value)" <?= $context !== 'profile' ? 'required' : '' ?>>
                        <?php foreach ($goalGroups as $groupName => $group): ?>
                            <optgroup label="<?= h($groupName) ?>">
                                <?php foreach ($group['items'] as $val => $item): ?>
                                    <option value="<?= h($val) ?>" <?= selected($val, $currentGoalVal) ?>><?= h($item['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>

                    <!-- Custom dropdown trigger -->
                    <button type="button" class="custom-select-trigger custom-goal-trigger" id="primaryGoalTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('customGoalDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                        <span class="custom-select-trigger-content custom-goal-trigger-content">
                            <span class="custom-select-trigger-icon custom-goal-trigger-icon" id="primaryGoalTriggerIcon_<?= h($context) ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            </span>
                            <span class="custom-select-trigger-text custom-goal-trigger-text" id="primaryGoalTriggerText_<?= h($context) ?>"><?= h($currentGoalItem['label']) ?></span>
                        </span>
                        <svg class="custom-select-chevron custom-goal-chevron" id="primaryGoalChevron_<?= h($context) ?>" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>

                    <!-- Custom dropdown popup menu -->
                    <div class="custom-select-menu custom-goal-menu" id="primaryGoalMenu_<?= h($context) ?>" role="listbox">
                        <!-- Quick search filter -->
                        <div class="custom-goal-search-box">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="primaryGoalSearch_<?= h($context) ?>" placeholder="Type to filter goals..." oninput="filterGoalList_<?= h($context) ?>(this.value)" autocomplete="off">
                            <button type="button" class="custom-goal-clear-btn" id="primaryGoalClear_<?= h($context) ?>" onclick="clearGoalSearch_<?= h($context) ?>()" style="display: none;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                        </div>

                        <!-- Options List -->
                        <div class="custom-select-list custom-goal-list" id="primaryGoalOptionsList_<?= h($context) ?>">
                            <?php foreach ($goalGroups as $groupName => $group): ?>
                                <div class="custom-goal-group" data-group-name="<?= h(strtolower($groupName)) ?>">
                                    <div class="custom-goal-group-title">
                                        <?= h($groupName) ?>
                                    </div>
                                    <?php foreach ($group['items'] as $val => $item): 
                                        $isSelected = ($val === $currentGoalVal);
                                    ?>
                                        <div class="custom-select-item custom-goal-item <?= $isSelected ? 'selected' : '' ?>"
                                             data-value="<?= h($val) ?>"
                                             data-label="<?= h($item['label']) ?>"
                                             data-search="<?= h(strtolower($item['label'] . ' ' . $item['desc'] . ' ' . $groupName)) ?>"
                                             role="option"
                                             aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                             onclick="selectCustomOption('customGoalDropdown_<?= h($context) ?>', 'primaryGoalSelect_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($item['label'])) ?>')">
                                            <div class="custom-select-item-details custom-goal-item-details">
                                                <span class="custom-select-item-title custom-goal-item-title"><?= h($item['label']) ?></span>
                                                <span class="custom-select-item-desc custom-goal-item-desc"><?= h($item['desc']) ?></span>
                                            </div>
                                            <span class="custom-select-check custom-goal-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="custom-goal-empty" id="primaryGoalEmpty_<?= h($context) ?>">
                                No matching goals found
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Dynamic Goal Description Card -->
            <div class="pm-goal-desc-card full-span" id="goalDescCard_<?= h($context) ?>">
                <span class="pm-goal-desc-icon" id="goalDescIcon_<?= h($context) ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                </span>
                <span class="pm-goal-desc-text" id="goalDescText_<?= h($context) ?>">Focus on improving your primary fitness metrics.</span>
            </div>

            <!-- 3. Dynamic Primary Target Sections (Only relevant section shown) -->

            <!-- PRIMARY TARGET: Increasing Maximum Strength -->
            <div class="pm-target-section full-span" id="targetSec_increasing_strength_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                        Strength Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Set a measurable strength target for your primary compound lift.</p>

                <div class="pm-grid">
                    <div class="pm-field full-span">
                        <label for="strengthExerciseTrigger_<?= h($context) ?>">Target Exercise</label>
                        <div class="custom-select-wrapper" id="strengthExerciseDropdown_<?= h($context) ?>">
                            <select name="target_exercise" id="strengthExercise_<?= h($context) ?>" class="custom-select-native" onchange="updateStrengthLiveFeedback_<?= h($context) ?>()">
                                <?php foreach ($strengthExercises as $val => $opt): ?>
                                    <option value="<?= h($val) ?>" <?= selected($val, $selectedExercise) ?>><?= h($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="custom-select-trigger" id="strengthExerciseTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('strengthExerciseDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-trigger-content">
                                    <span class="custom-select-trigger-icon">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                                    </span>
                                    <span class="custom-select-trigger-text" id="strengthExerciseTriggerText_<?= h($context) ?>"><?= h($selectedExerciseLabel) ?></span>
                                </span>
                                <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>

                            <div class="custom-select-menu" role="listbox">
                                <div class="custom-select-list">
                                    <?php foreach ($strengthExercises as $val => $opt):
                                        $isSelected = ($val === $selectedExercise);
                                    ?>
                                        <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                             data-value="<?= h($val) ?>"
                                             data-label="<?= h($opt['label']) ?>"
                                             role="option"
                                             aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                             onclick="selectCustomOption('strengthExerciseDropdown_<?= h($context) ?>', 'strengthExercise_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                            <div class="custom-select-item-details">
                                                <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                                <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                            </div>
                                            <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pm-field">
                        <label for="currentStrengthMax_<?= h($context) ?>">Current Max</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.5" min="0" max="500" name="current_strength_max_kg" id="currentStrengthMax_<?= h($context) ?>" value="<?= h((string)($profile['current_strength_max_kg'] ?? '')) ?>" placeholder="e.g. 75.0" oninput="updateStrengthLiveFeedback_<?= h($context) ?>()">
                            <span class="pm-unit">kg</span>
                        </div>
                        <span class="pm-field-hint">Your current recorded maximum</span>
                    </div>

                    <div class="pm-field">
                        <label for="targetStrengthMax_<?= h($context) ?>">Target Max <span class="pm-req">*</span></label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.5" min="1" max="500" name="target_strength_max_kg" id="targetStrengthMax_<?= h($context) ?>" value="<?= h((string)($profile['target_strength_max_kg'] ?? '')) ?>" placeholder="e.g. 100.0" oninput="updateStrengthLiveFeedback_<?= h($context) ?>()">
                            <span class="pm-unit">kg</span>
                        </div>
                        <span class="pm-field-hint">Measurable personal record goal</span>
                    </div>

                    <div class="pm-live-delta-banner full-span" id="strengthDeltaBanner_<?= h($context) ?>"></div>
                    <span class="pm-field-error" id="strengthTargetError_<?= h($context) ?>">Target should be greater than your current maximum.</span>
                </div>
            </div>

            <!-- PRIMARY TARGET: Building Muscle -->
            <div class="pm-target-section full-span" id="targetSec_building_muscle_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" x2="6" y1="1" y2="4"/><line x1="10" x2="10" y1="1" y2="4"/><line x1="14" x2="14" y1="1" y2="4"/></svg>
                        Muscle Growth Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Focus on increasing muscle size, hypertrophy, and lean body mass.</p>

                <div class="pm-grid">
                    <div class="pm-field">
                        <label>Current Weight</label>
                        <div class="pm-read-only-badge">
                            <span class="val"><?= $userWeight > 0 ? h(number_format($userWeight, 1)) : '—' ?></span>
                            <span class="unit">kg</span>
                        </div>
                        <span class="pm-field-hint">From Body Stats</span>
                    </div>
                    <div class="pm-field">
                        <label>Target Weight <span class="pm-req">*</span></label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="20" max="300" class="sync-target-weight-<?= h($context) ?>" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>" placeholder="e.g. 75.0" oninput="syncTargetWeight_<?= h($context) ?>(this.value)">
                            <span class="pm-unit">kg</span>
                        </div>
                        <span class="pm-field-hint">Progressive lean mass goal</span>
                    </div>
                    <div class="pm-live-delta-banner full-span" id="muscleDeltaBanner_<?= h($context) ?>"></div>
                </div>
            </div>

            <!-- PRIMARY TARGET: Losing Weight -->
            <div class="pm-target-section full-span" id="targetSec_losing_weight_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        Weight Loss Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Focus on reaching a healthy, sustainable target body weight.</p>

                <div class="pm-grid">
                    <div class="pm-field">
                        <label>Current Weight</label>
                        <div class="pm-read-only-badge">
                            <span class="val"><?= $userWeight > 0 ? h(number_format($userWeight, 1)) : '—' ?></span>
                            <span class="unit">kg</span>
                        </div>
                        <span class="pm-field-hint">From Body Stats</span>
                    </div>
                    <div class="pm-field">
                        <label>Target Weight <span class="pm-req">*</span></label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="20" max="300" class="sync-target-weight-<?= h($context) ?>" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>" placeholder="e.g. 68.0" oninput="syncTargetWeight_<?= h($context) ?>(this.value)">
                            <span class="pm-unit">kg</span>
                        </div>
                        <span class="pm-field-hint">Realistic target scale weight</span>
                    </div>
                    <div class="pm-live-delta-banner full-span" id="weightLossDeltaBanner_<?= h($context) ?>"></div>
                </div>
            </div>

            <!-- PRIMARY TARGET: Reducing Body Fat -->
            <div class="pm-target-section full-span" id="targetSec_reducing_body_fat_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                        Body Fat Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Focus on reducing body-fat percentage while maintaining lean muscle progress.</p>

                <div class="pm-grid">
                    <div class="pm-field">
                        <label>Current Body Fat</label>
                        <div class="pm-read-only-badge highlight-lime">
                            <span class="val"><?= $currentBfEst ? h($currentBfEst) : '—' ?></span>
                            <span class="unit">%</span>
                        </div>
                        <span class="pm-field-hint">Navy estimate / Latest log</span>
                    </div>
                    <div class="pm-field">
                        <label>Target Body Fat <span class="pm-req">*</span></label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="3" max="65" class="sync-target-bf-<?= h($context) ?>" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>" placeholder="e.g. 15.0" oninput="syncTargetBf_<?= h($context) ?>(this.value)">
                            <span class="pm-unit">%</span>
                        </div>
                        <span class="pm-field-hint">Target body composition</span>
                    </div>
                    <div class="pm-live-delta-banner full-span" id="bfDeltaBanner_<?= h($context) ?>"></div>
                </div>
            </div>

            <!-- PRIMARY TARGET: Improving Endurance -->
            <div class="pm-target-section full-span" id="targetSec_improving_endurance_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        Endurance Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Focus on improving stamina, aerobic pacing, and sustained cardio performance.</p>

                <div class="pm-grid">
                    <div class="pm-field full-span">
                        <label for="enduranceActivityTrigger_<?= h($context) ?>">Activity</label>
                        <div class="custom-select-wrapper" id="enduranceActivityDropdown_<?= h($context) ?>">
                            <select name="endurance_activity" id="enduranceActivity_<?= h($context) ?>" class="custom-select-native">
                                <?php foreach ($enduranceActivities as $val => $opt): ?>
                                    <option value="<?= h($val) ?>" <?= selected($val, $selectedEnduranceActivity) ?>><?= h($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="custom-select-trigger" id="enduranceActivityTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('enduranceActivityDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-trigger-content">
                                    <span class="custom-select-trigger-icon">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                    </span>
                                    <span class="custom-select-trigger-text" id="enduranceActivityTriggerText_<?= h($context) ?>"><?= h($selectedEnduranceLabel) ?></span>
                                </span>
                                <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>

                            <div class="custom-select-menu" role="listbox">
                                <div class="custom-select-list">
                                    <?php foreach ($enduranceActivities as $val => $opt):
                                        $isSelected = ($val === $selectedEnduranceActivity);
                                    ?>
                                        <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                             data-value="<?= h($val) ?>"
                                             data-label="<?= h($opt['label']) ?>"
                                             role="option"
                                             aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                             onclick="selectCustomOption('enduranceActivityDropdown_<?= h($context) ?>', 'enduranceActivity_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                            <div class="custom-select-item-details">
                                                <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                                <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                            </div>
                                            <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pm-field">
                        <label>Target Distance</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="0.1" max="200" name="target_endurance_distance_km" value="<?= h((string)($profile['target_endurance_distance_km'] ?? '')) ?>" placeholder="e.g. 5.0">
                            <span class="pm-unit">km</span>
                        </div>
                    </div>
                    <div class="pm-field">
                        <label>Target Time</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="1" min="1" max="1440" name="target_endurance_time_mins" value="<?= h((string)($profile['target_endurance_time_mins'] ?? '')) ?>" placeholder="e.g. 30">
                            <span class="pm-unit">mins</span>
                        </div>
                    </div>
                    <div class="pm-field">
                        <label>Baseline Distance</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="0.1" max="200" name="current_endurance_distance_km" value="<?= h((string)($profile['current_endurance_distance_km'] ?? '')) ?>" placeholder="e.g. 3.0">
                            <span class="pm-unit">km</span>
                        </div>
                        <span class="pm-field-hint">Your current distance capability</span>
                    </div>
                    <div class="pm-field">
                        <label>Baseline Time</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="1" min="1" max="1440" name="current_endurance_time_mins" value="<?= h((string)($profile['current_endurance_time_mins'] ?? '')) ?>" placeholder="e.g. 25">
                            <span class="pm-unit">mins</span>
                        </div>
                        <span class="pm-field-hint">Your current duration capability</span>
                    </div>
                </div>
            </div>

            <!-- PRIMARY TARGET: Improving General Fitness -->
            <div class="pm-target-section full-span" id="targetSec_general_fitness_<?= h($context) ?>" style="display: none;">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        Workout Consistency Target
                    </span>
                    <span class="pm-target-badge primary">Primary Target</span>
                </div>
                <p class="pm-target-section-desc">Focus on building consistent exercise habits and balanced physical health.</p>

                <div class="pm-grid">
                    <div class="pm-field full-span">
                        <label for="weeklyWorkoutTrigger_<?= h($context) ?>">Workouts per Week <span class="pm-req">*</span></label>
                        <div class="custom-select-wrapper" id="weeklyWorkoutDropdown_<?= h($context) ?>">
                            <select name="weekly_workout_target" id="weeklyWorkoutTarget_<?= h($context) ?>" class="custom-select-native">
                                <?php foreach ($weeklyWorkoutOptions as $val => $opt): ?>
                                    <option value="<?= $val ?>" <?= $selectedWeeklyWorkouts === $val ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="custom-select-trigger" id="weeklyWorkoutTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('weeklyWorkoutDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-trigger-content">
                                    <span class="custom-select-trigger-icon">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    </span>
                                    <span class="custom-select-trigger-text" id="weeklyWorkoutTriggerText_<?= h($context) ?>"><?= h($selectedWeeklyLabel) ?></span>
                                </span>
                                <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>

                            <div class="custom-select-menu" role="listbox">
                                <div class="custom-select-list">
                                    <?php foreach ($weeklyWorkoutOptions as $val => $opt):
                                        $isSelected = ($val === $selectedWeeklyWorkouts);
                                    ?>
                                        <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                             data-value="<?= $val ?>"
                                             data-label="<?= h($opt['label']) ?>"
                                             role="option"
                                             aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                             onclick="selectCustomOption('weeklyWorkoutDropdown_<?= h($context) ?>', 'weeklyWorkoutTarget_<?= h($context) ?>', '<?= $val ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                            <div class="custom-select-item-details">
                                                <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                                <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                            </div>
                                            <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <span class="pm-field-hint">Aim for a sustainable weekly workout routine</span>
                    </div>
                </div>
            </div>

            <!-- 4. Supporting Targets (Optional) Section -->
            <div class="pm-supporting-section full-span" id="supportingTargetsSec_<?= h($context) ?>">
                <div class="pm-target-section-header">
                    <span class="pm-target-section-title">Supporting Targets</span>
                    <span class="pm-target-badge optional">Optional</span>
                </div>
                <p class="pm-target-section-desc">Secondary metrics to track alongside your primary goal.</p>

                <!-- Hidden inputs ensuring canonical form submission -->
                <input type="hidden" name="target_weight_kg" id="canonicalTargetWeight_<?= h($context) ?>" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>">
                <input type="hidden" name="target_body_fat_percent" id="canonicalTargetBf_<?= h($context) ?>" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>">

                <div class="pm-grid">
                    <!-- Supporting Target Weight -->
                    <div class="pm-field" id="supportWeightWrap_<?= h($context) ?>">
                        <label for="supportWeightInput_<?= h($context) ?>">Target Weight</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="20" max="300" id="supportWeightInput_<?= h($context) ?>" class="sync-target-weight-<?= h($context) ?>" value="<?= h((string)($profile['target_weight_kg'] ?? '')) ?>" placeholder="e.g. 75.0" oninput="syncTargetWeight_<?= h($context) ?>(this.value)">
                            <span class="pm-unit">kg</span>
                        </div>
                        <span class="pm-field-hint">Secondary body weight target</span>
                    </div>

                    <!-- Supporting Target Body Fat -->
                    <div class="pm-field" id="supportBfWrap_<?= h($context) ?>">
                        <label for="supportBfInput_<?= h($context) ?>">Target Body Fat</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" min="3" max="65" id="supportBfInput_<?= h($context) ?>" class="sync-target-bf-<?= h($context) ?>" value="<?= h((string)($profile['target_body_fat_percent'] ?? '')) ?>" placeholder="e.g. 15.0" oninput="syncTargetBf_<?= h($context) ?>(this.value)">
                            <span class="pm-unit">%</span>
                        </div>
                        <span class="pm-field-hint">Secondary body fat percentage</span>
                    </div>

                    <!-- Supporting Arm Target -->
                    <div class="pm-field" id="supportArmWrap_<?= h($context) ?>" style="display: none;">
                        <label>Target Arm Size</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" name="target_arm_cm" value="<?= h((string)($profile['target_arm_cm'] ?? '')) ?>" placeholder="e.g. 38.0">
                            <span class="pm-unit">cm</span>
                        </div>
                        <span class="pm-field-hint">Peak flexed measurement</span>
                    </div>

                    <!-- Supporting Chest Target -->
                    <div class="pm-field" id="supportChestWrap_<?= h($context) ?>" style="display: none;">
                        <label>Target Chest Size</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" name="target_chest_cm" value="<?= h((string)($profile['target_chest_cm'] ?? '')) ?>" placeholder="e.g. 105.0">
                            <span class="pm-unit">cm</span>
                        </div>
                        <span class="pm-field-hint">Fullest point measurement</span>
                    </div>

                    <!-- Supporting Waist Target -->
                    <div class="pm-field" id="supportWaistWrap_<?= h($context) ?>" style="display: none;">
                        <label>Target Waist Size</label>
                        <div class="pm-input-with-unit">
                            <input type="number" step="0.1" name="target_waist_cm" value="<?= h((string)($profile['target_waist_cm'] ?? '')) ?>" placeholder="e.g. 78.0">
                            <span class="pm-unit">cm</span>
                        </div>
                        <span class="pm-field-hint">Measurement at navel</span>
                    </div>

                    <!-- Additional Target Objectives Toggle -->
                    <div class="full-span" id="additionalTargetsToggles_<?= h($context) ?>" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line);">
                        <button type="button" class="btn btn-secondary" id="toggleStrengthBtn_<?= h($context) ?>" onclick="toggleOptionalStrengthTarget_<?= h($context) ?>()" style="padding: 6px 12px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>
                            <span id="toggleStrengthText_<?= h($context) ?>">Strength PR Target</span>
                        </button>
                        <button type="button" class="btn btn-secondary" id="toggleEnduranceBtn_<?= h($context) ?>" onclick="toggleOptionalEnduranceTarget_<?= h($context) ?>()" style="padding: 6px 12px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <span id="toggleEnduranceText_<?= h($context) ?>">Endurance Cardio Target</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: LIFESTYLE & DIET -->
        <div class="pm-pane" id="tabPane_<?= h($context) ?>_lifestyle">
            <div class="pm-grid">
                <!-- Activity Level Custom Dropdown -->
                <?php
                $activityOptions = [
                    'sedentary' => ['label' => 'Sedentary', 'desc' => 'Desk job, little to no regular exercise'],
                    'lightly_active' => ['label' => 'Lightly Active', 'desc' => 'Light exercise / walking 1–3 days a week'],
                    'moderately_active' => ['label' => 'Moderately Active', 'desc' => 'Moderate exercise / sports 3–5 days a week'],
                    'very_active' => ['label' => 'Very Active', 'desc' => 'Hard exercise / training 6–7 days a week'],
                    'extra_active' => ['label' => 'Extra Active', 'desc' => 'Very intense physical training or demanding job']
                ];
                $currentActivity = $profile['activity_level'] ?? 'sedentary';
                $selectedActivityLabel = $activityOptions[$currentActivity]['label'] ?? 'Sedentary';
                ?>
                <div class="pm-field">
                    <label for="activityTrigger_<?= h($context) ?>">Activity level</label>
                    <div class="custom-select-wrapper" id="activityDropdown_<?= h($context) ?>">
                        <select name="activity_level" id="activitySelect_<?= h($context) ?>" class="custom-select-native" <?= $context !== 'profile' ? 'required' : '' ?>>
                            <?php foreach ($activityOptions as $level => $opt): ?>
                                <option value="<?= h($level) ?>" <?= selected($level, $currentActivity) ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="custom-select-trigger" id="activityTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('activityDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="activityTriggerText_<?= h($context) ?>"><?= h($selectedActivityLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($activityOptions as $val => $opt): 
                                    $isSelected = ($val === $currentActivity);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h($val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('activityDropdown_<?= h($context) ?>', 'activitySelect_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dietary Restrictions Custom Dropdown -->
                <?php
                $dietOptions = [
                    'none' => ['label' => 'None', 'desc' => 'No dietary restrictions or food allergies'],
                    'vegetarian' => ['label' => 'Vegetarian', 'desc' => 'Plant-based with dairy and eggs permitted'],
                    'vegan' => ['label' => 'Vegan', 'desc' => 'Strictly plant-based, no animal products'],
                    'pescatarian' => ['label' => 'Pescatarian', 'desc' => 'Vegetarian diet plus fish and seafood'],
                    'halal' => ['label' => 'Halal', 'desc' => 'Conforms strictly to Islamic dietary rules'],
                    'gluten-free' => ['label' => 'Gluten-Free', 'desc' => 'Eliminates wheat, barley, rye and gluten'],
                    'keto' => ['label' => 'Keto', 'desc' => 'Very low carb, high healthy fats ketogenic plan'],
                    'paleo' => ['label' => 'Paleo', 'desc' => 'Whole foods, lean proteins and vegetables'],
                    'nut-allergy' => ['label' => 'Nut Allergy', 'desc' => 'Free of peanuts and tree nuts'],
                    'dairy-free' => ['label' => 'Dairy-Free', 'desc' => 'Excludes lactose, milk and dairy products']
                ];
                $currentDiet = $profile['dietary_restrictions'] ?? 'none';
                $selectedDietLabel = $dietOptions[$currentDiet]['label'] ?? 'None';
                ?>
                <div class="pm-field">
                    <label for="dietTrigger_<?= h($context) ?>">Dietary Restrictions</label>
                    <div class="custom-select-wrapper" id="dietDropdown_<?= h($context) ?>">
                        <select name="dietary_restrictions" id="dietSelect_<?= h($context) ?>" class="custom-select-native" required>
                            <?php foreach ($dietOptions as $diet => $opt): ?>
                                <option value="<?= h($diet) ?>" <?= selected($diet, $currentDiet) ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="custom-select-trigger" id="dietTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('dietDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="dietTriggerText_<?= h($context) ?>"><?= h($selectedDietLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($dietOptions as $val => $opt): 
                                    $isSelected = ($val === $currentDiet);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h($val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('dietDropdown_<?= h($context) ?>', 'dietSelect_<?= h($context) ?>', '<?= h(addslashes($val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Experience Level Custom Dropdown -->
                <?php
                $expOptions = [
                    '1' => ['label' => 'Starter', 'desc' => 'Beginner learning core movements & consistency'],
                    '2' => ['label' => 'Intermediate', 'desc' => '1–2+ years structured resistance training'],
                    '3' => ['label' => 'Advanced', 'desc' => '3+ years athletic progression & periodization']
                ];
                $currentExp = isset($profile['fitness_tier']) ? (string)(in_array((int)$profile['fitness_tier'], [1,2]) ? 1 : (in_array((int)$profile['fitness_tier'], [3,4]) ? 2 : 3)) : '1';
                $selectedExpLabel = $expOptions[$currentExp]['label'] ?? 'Starter';
                ?>
                <div class="pm-field">
                    <label for="experienceTrigger_<?= h($context) ?>">Experience level</label>
                    <div class="custom-select-wrapper" id="experienceDropdown_<?= h($context) ?>">
                        <select name="experience_level" id="experienceSelect_<?= h($context) ?>" class="custom-select-native" <?= $context !== 'profile' ? 'required' : '' ?>>
                            <?php foreach ($expOptions as $level => $opt): ?>
                                <option value="<?= h((string)$level) ?>" <?= selected((string)$level, $currentExp) ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="custom-select-trigger" id="experienceTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('experienceDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="experienceTriggerText_<?= h($context) ?>"><?= h($selectedExpLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($expOptions as $val => $opt): 
                                    $isSelected = ((string)$val === $currentExp);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h((string)$val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('experienceDropdown_<?= h($context) ?>', 'experienceSelect_<?= h($context) ?>', '<?= h(addslashes((string)$val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Session Duration Custom Dropdown -->
                <?php
                $durationOptions = [
                    '30' => ['label' => '30 mins (Quick)', 'desc' => 'Short, high-efficiency focused routine'],
                    '45' => ['label' => '45–60 mins (Standard)', 'desc' => 'Optimal balance of warmup, lifting & conditioning'],
                    '75' => ['label' => '75+ mins (Extended)', 'desc' => 'Complete bodybuilding or multi-phase training']
                ];
                $currentDurationVal = (int)($profile['preferred_duration_mins'] ?? 45);
                $currentDurationKey = $currentDurationVal <= 30 ? '30' : ($currentDurationVal <= 60 ? '45' : '75');
                $selectedDurationLabel = $durationOptions[$currentDurationKey]['label'] ?? '45–60 mins (Standard)';
                ?>
                <div class="pm-field">
                    <label for="durationTrigger_<?= h($context) ?>">Session Duration</label>
                    <div class="custom-select-wrapper" id="durationDropdown_<?= h($context) ?>">
                        <select name="preferred_duration_mins" id="durationSelect_<?= h($context) ?>" class="custom-select-native">
                            <?php foreach ($durationOptions as $mins => $opt): ?>
                                <option value="<?= h((string)$mins) ?>" <?= selected((string)$mins, $currentDurationKey) ?>><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="custom-select-trigger" id="durationTrigger_<?= h($context) ?>" onclick="toggleCustomDropdown('durationDropdown_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="durationTriggerText_<?= h($context) ?>"><?= h($selectedDurationLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($durationOptions as $val => $opt): 
                                    $isSelected = ((string)$val === $currentDurationKey);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h((string)$val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('durationDropdown_<?= h($context) ?>', 'durationSelect_<?= h($context) ?>', '<?= h(addslashes((string)$val)) ?>', '<?= h(addslashes($opt['label'])) ?>')">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Workout Frequency Custom Dropdown -->
                <div class="pm-field full-span">
                    <label for="weeklyWorkoutTrigger_lifestyle_<?= h($context) ?>">Workout Frequency</label>
                    <div class="custom-select-wrapper" id="weeklyWorkoutDropdown_lifestyle_<?= h($context) ?>">
                        <select name="weekly_workout_target" id="weeklyWorkoutTarget_lifestyle_<?= h($context) ?>" class="custom-select-native" onchange="const el = document.getElementById('weeklyWorkoutTarget_<?= h($context) ?>'); if (el) el.value = this.value;">
                            <?php foreach ($weeklyWorkoutOptions as $val => $opt): ?>
                                <option value="<?= $val ?>" <?= $selectedWeeklyWorkouts === $val ? 'selected' : '' ?>><?= h($opt['label']) ?> — <?= h($opt['desc']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="custom-select-trigger" id="weeklyWorkoutTrigger_lifestyle_<?= h($context) ?>" onclick="toggleCustomDropdown('weeklyWorkoutDropdown_lifestyle_<?= h($context) ?>', event)" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-trigger-content">
                                <span class="custom-select-trigger-icon">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </span>
                                <span class="custom-select-trigger-text" id="weeklyWorkoutTriggerText_lifestyle_<?= h($context) ?>"><?= h($selectedWeeklyLabel) ?></span>
                            </span>
                            <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>

                        <div class="custom-select-menu" role="listbox">
                            <div class="custom-select-list">
                                <?php foreach ($weeklyWorkoutOptions as $val => $opt): 
                                    $isSelected = ($val === $selectedWeeklyWorkouts);
                                ?>
                                    <div class="custom-select-item <?= $isSelected ? 'selected' : '' ?>"
                                         data-value="<?= h((string)$val) ?>"
                                         data-label="<?= h($opt['label']) ?>"
                                         role="option"
                                         aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                         onclick="selectCustomOption('weeklyWorkoutDropdown_lifestyle_<?= h($context) ?>', 'weeklyWorkoutTarget_lifestyle_<?= h($context) ?>', '<?= h(addslashes((string)$val)) ?>', '<?= h(addslashes($opt['label'])) ?>'); const syncEl = document.getElementById('weeklyWorkoutTarget_<?= h($context) ?>'); if (syncEl) syncEl.value = '<?= h(addslashes((string)$val)) ?>';">
                                        <div class="custom-select-item-details">
                                            <span class="custom-select-item-title"><?= h($opt['label']) ?></span>
                                            <span class="custom-select-item-desc"><?= h($opt['desc']) ?></span>
                                        </div>
                                        <span class="custom-select-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Persistent Action Footer -->
        <div class="pm-footer">
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary" id="pmPrevBtn_<?= h($context) ?>" onclick="navProfileTab_<?= h($context) ?>(-1)" style="display: none; padding: 7px 14px; font-size: 13px;">
                    ← Back
                </button>
                <button type="button" class="btn btn-secondary" id="pmNextBtn_<?= h($context) ?>" onclick="navProfileTab_<?= h($context) ?>(1)" style="padding: 7px 14px; font-size: 13px;">
                    Next Step →
                </button>
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 7px 20px; font-size: 13px; font-weight: 600;">
                Save Profile
            </button>
        </div>
    </form>

    <script>
    const PM_TABS_<?= h($context) ?> = ['body', 'goal', 'lifestyle'];
    let currentPmTabIndex_<?= h($context) ?> = 0;

    function switchProfileTab_<?= h($context) ?>(tabName) {
        currentPmTabIndex_<?= h($context) ?> = PM_TABS_<?= h($context) ?>.indexOf(tabName);
        if (currentPmTabIndex_<?= h($context) ?> === -1) currentPmTabIndex_<?= h($context) ?> = 0;

        PM_TABS_<?= h($context) ?>.forEach(t => {
            const btn = document.getElementById('tabBtn_<?= h($context) ?>_' + t);
            const pane = document.getElementById('tabPane_<?= h($context) ?>_' + t);
            if (btn) btn.classList.toggle('active', t === tabName);
            if (pane) pane.classList.toggle('active', t === tabName);
        });

        const prevBtn = document.getElementById('pmPrevBtn_<?= h($context) ?>');
        const nextBtn = document.getElementById('pmNextBtn_<?= h($context) ?>');
        if (prevBtn) prevBtn.style.display = currentPmTabIndex_<?= h($context) ?> > 0 ? 'inline-block' : 'none';
        if (nextBtn) nextBtn.style.display = currentPmTabIndex_<?= h($context) ?> < PM_TABS_<?= h($context) ?>.length - 1 ? 'inline-block' : 'none';

        const modalBody = document.querySelector('#physicalProfileModal .modal-body');
        if (modalBody) modalBody.scrollTop = 0;
    }

    function navProfileTab_<?= h($context) ?>(dir) {
        const nextIdx = currentPmTabIndex_<?= h($context) ?> + dir;
        if (nextIdx >= 0 && nextIdx < PM_TABS_<?= h($context) ?>.length) {
            switchProfileTab_<?= h($context) ?>(PM_TABS_<?= h($context) ?>[nextIdx]);
        }
    }

    const goalTargetConfig_<?= h($context) ?> = {
        increasing_strength: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg>',
            desc: 'Focus on progressive overload, compound lift progression, and personal record targets.',
            showSupportWeight: true,
            showSupportBf: true,
            showSupportArm: false,
            showSupportChest: false,
            showSupportWaist: false
        },
        building_muscle: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>',
            desc: 'Focus on muscle hypertrophy, lean mass development, and muscle circumference progression.',
            showSupportWeight: false,
            showSupportBf: true,
            showSupportArm: true,
            showSupportChest: true,
            showSupportWaist: true
        },
        losing_weight: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
            desc: 'Focus on reaching a healthy, sustainable scale weight with steady caloric balance.',
            showSupportWeight: false,
            showSupportBf: true,
            showSupportArm: false,
            showSupportChest: false,
            showSupportWaist: true
        },
        reducing_body_fat: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
            desc: 'Focus on reducing overall body-fat percentage while preserving lean muscle mass.',
            showSupportWeight: true,
            showSupportBf: false,
            showSupportArm: false,
            showSupportChest: false,
            showSupportWaist: true
        },
        improving_endurance: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
            desc: 'Focus on building aerobic capacity, cardiovascular stamina, and pacing benchmarks.',
            showSupportWeight: true,
            showSupportBf: true,
            showSupportArm: false,
            showSupportChest: false,
            showSupportWaist: false
        },
        general_fitness: {
            icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
            desc: 'Focus on establishing regular weekly workout habits and balanced physical conditioning.',
            showSupportWeight: true,
            showSupportBf: true,
            showSupportArm: false,
            showSupportChest: false,
            showSupportWaist: false
        }
    };

    function resolveGoalCategory_<?= h($context) ?>(goal) {
        const g = (goal || '').toLowerCase().trim();
        if (!g) return 'increasing_strength';

        if (g.includes('strength') || g.includes('powerlifting') || g.includes('heavy') || g === 'increasing_strength') {
            return 'increasing_strength';
        }
        if (g.includes('hypertrophy') || g.includes('muscle') || g.includes('mass') || g.includes('bicep') || g.includes('chest') || g.includes('back') || g.includes('lower body') || g.includes('recomposition')) {
            return 'building_muscle';
        }
        if (g.includes('losing_weight') || g.includes('weight_loss') || g.includes('lose weight') || g.includes('weight loss')) {
            return 'losing_weight';
        }
        if (g.includes('fat') || g.includes('shred') || g.includes('six-pack') || g.includes('six pack')) {
            return 'reducing_body_fat';
        }
        if (g.includes('endurance') || g.includes('cardio') || g.includes('running') || g.includes('stamina') || g.includes('cycling')) {
            return 'improving_endurance';
        }
        if (g.includes('general') || g.includes('fitness') || g.includes('health') || g.includes('wellness') || g.includes('toning')) {
            return 'general_fitness';
        }
        return 'general_fitness';
    }

    const USER_WEIGHT_<?= h($context) ?> = <?= (float)$userWeight ?>;
    const USER_BF_EST_<?= h($context) ?> = <?= (float)$currentBfEst ?>;

    function syncTargetWeight_<?= h($context) ?>(val) {
        const canonical = document.getElementById('canonicalTargetWeight_<?= h($context) ?>');
        if (canonical) canonical.value = val;
        document.querySelectorAll('.sync-target-weight-<?= h($context) ?>').forEach(el => {
            if (el.value !== val) el.value = val;
        });
        updateWeightLiveFeedback_<?= h($context) ?>(val);
    }

    function syncTargetBf_<?= h($context) ?>(val) {
        const canonical = document.getElementById('canonicalTargetBf_<?= h($context) ?>');
        if (canonical) canonical.value = val;
        document.querySelectorAll('.sync-target-bf-<?= h($context) ?>').forEach(el => {
            if (el.value !== val) el.value = val;
        });
        updateBfLiveFeedback_<?= h($context) ?>(val);
    }

    function updateStrengthLiveFeedback_<?= h($context) ?>() {
        const currentEl = document.getElementById('currentStrengthMax_<?= h($context) ?>');
        const targetEl = document.getElementById('targetStrengthMax_<?= h($context) ?>');
        const bannerEl = document.getElementById('strengthDeltaBanner_<?= h($context) ?>');
        if (!bannerEl || !targetEl || !currentEl) return;

        const cur = parseFloat(currentEl.value);
        const tgt = parseFloat(targetEl.value);

        if (isNaN(tgt) || tgt <= 0) {
            bannerEl.innerHTML = '';
            targetEl.classList.remove('has-error');
            return;
        }

        if (!isNaN(cur) && cur > 0) {
            if (tgt > cur) {
                targetEl.classList.remove('has-error');
                const diff = tgt - cur;
                const pct = ((diff / cur) * 100).toFixed(1);
                const achievePct = Math.min(100, Math.max(5, Math.round((cur / tgt) * 100)));
                bannerEl.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            +${diff.toFixed(1)} kg PR Target Increase (+${pct}%)
                        </div>
                        <div class="pm-delta-sub">Current: ${cur.toFixed(1)} kg → Goal: ${tgt.toFixed(1)} kg (${achievePct}% achieved)</div>
                        <div class="pm-delta-bar-wrap">
                            <div class="pm-delta-bar" style="width: ${achievePct}%;"></div>
                        </div>
                    </div>
                `;
            } else {
                targetEl.classList.add('has-error');
                bannerEl.innerHTML = `
                    <div class="pm-delta-badge warning">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span>Target PR (${tgt.toFixed(1)} kg) must be greater than your current recorded maximum (${cur.toFixed(1)} kg).</span>
                    </div>
                `;
            }
        } else {
            targetEl.classList.remove('has-error');
            bannerEl.innerHTML = `
                <div class="pm-delta-badge positive">
                    <div class="pm-delta-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        Target PR: ${tgt.toFixed(1)} kg
                    </div>
                    <div class="pm-delta-sub">Enter your Current Max above to calculate your strength progression delta.</div>
                </div>
            `;
        }
    }

    function updateWeightLiveFeedback_<?= h($context) ?>(val) {
        const tgt = parseFloat(val);
        const muscleBanner = document.getElementById('muscleDeltaBanner_<?= h($context) ?>');
        const lossBanner = document.getElementById('weightLossDeltaBanner_<?= h($context) ?>');
        const cur = USER_WEIGHT_<?= h($context) ?>;

        if (isNaN(tgt) || tgt <= 0 || !cur || cur <= 0) {
            if (muscleBanner) muscleBanner.innerHTML = '';
            if (lossBanner) lossBanner.innerHTML = '';
            return;
        }

        const diff = tgt - cur;
        const absDiff = Math.abs(diff).toFixed(1);
        const pct = Math.abs((diff / cur) * 100).toFixed(1);

        if (muscleBanner) {
            if (diff > 0) {
                muscleBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                            +${absDiff} kg Progressive Mass Goal (+${pct}%)
                        </div>
                        <div class="pm-delta-sub">Current Weight: ${cur.toFixed(1)} kg → Target: ${tgt.toFixed(1)} kg</div>
                    </div>
                `;
            } else if (diff < 0) {
                muscleBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">Target: ${tgt.toFixed(1)} kg (-${absDiff} kg below current)</div>
                    </div>
                `;
            } else {
                muscleBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">Maintaining current scale weight (${cur.toFixed(1)} kg) with lean recomposition</div>
                    </div>
                `;
            }
        }

        if (lossBanner) {
            if (diff < 0) {
                lossBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            -${absDiff} kg Weight Loss Target (-${pct}%)
                        </div>
                        <div class="pm-delta-sub">Current Weight: ${cur.toFixed(1)} kg → Target: ${tgt.toFixed(1)} kg</div>
                    </div>
                `;
            } else if (diff > 0) {
                lossBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">Target: ${tgt.toFixed(1)} kg (+${absDiff} kg above current)</div>
                    </div>
                `;
            } else {
                lossBanner.innerHTML = `
                    <div class="pm-delta-badge positive">
                        <div class="pm-delta-title">Maintaining current scale weight (${cur.toFixed(1)} kg)</div>
                    </div>
                `;
            }
        }
    }

    function updateBfLiveFeedback_<?= h($context) ?>(val) {
        const tgt = parseFloat(val);
        const banner = document.getElementById('bfDeltaBanner_<?= h($context) ?>');
        const cur = USER_BF_EST_<?= h($context) ?>;
        if (!banner) return;
        if (isNaN(tgt) || tgt <= 0 || !cur || cur <= 0) {
            banner.innerHTML = '';
            return;
        }

        const diff = cur - tgt;
        if (diff > 0) {
            banner.innerHTML = `
                <div class="pm-delta-badge positive">
                    <div class="pm-delta-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        -${diff.toFixed(1)}% Body Fat Reduction Target
                    </div>
                    <div class="pm-delta-sub">Current Estimate: ${cur.toFixed(1)}% → Target: ${tgt.toFixed(1)}%</div>
                </div>
            `;
        } else {
            banner.innerHTML = `
                <div class="pm-delta-badge positive">
                    <div class="pm-delta-title">Target: ${tgt.toFixed(1)}% body fat</div>
                </div>
            `;
        }
    }

    function validateStrengthTarget_<?= h($context) ?>() {
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (!selectEl) return true;
        const cat = resolveGoalCategory_<?= h($context) ?>(selectEl.value);
        const targetInput = document.getElementById('targetStrengthMax_<?= h($context) ?>');
        const currentInput = document.getElementById('currentStrengthMax_<?= h($context) ?>');

        if (cat !== 'increasing_strength' || !targetInput || !currentInput) {
            if (targetInput) targetInput.classList.remove('has-error');
            return true;
        }

        const targetVal = parseFloat(targetInput.value);
        const currentVal = parseFloat(currentInput.value);

        if (!isNaN(targetVal) && !isNaN(currentVal) && currentVal > 0 && targetVal <= currentVal) {
            targetInput.classList.add('has-error');
            return false;
        } else {
            targetInput.classList.remove('has-error');
            return true;
        }
    }

    function handleProfileFormSubmit_<?= h($context) ?>(form, e) {
        if (!validateStrengthTarget_<?= h($context) ?>()) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            switchProfileTab_<?= h($context) ?>('goal');
            const bannerEl = document.getElementById('strengthDeltaBanner_<?= h($context) ?>');
            if (bannerEl) bannerEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        const btn = form.querySelector('button[type=submit]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class=\'loader\' style=\'width:14px;height:14px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:6px;vertical-align:-2px;\'></span> Saving...';
        }
        return true;
    }

    let forceShowStrength_<?= h($context) ?> = <?= (!empty($profile['target_strength_max_kg']) || !empty($profile['current_strength_max_kg'])) ? 'true' : 'false' ?>;
    let forceShowEndurance_<?= h($context) ?> = <?= (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])) ? 'true' : 'false' ?>;

    function toggleOptionalStrengthTarget_<?= h($context) ?>() {
        forceShowStrength_<?= h($context) ?> = !forceShowStrength_<?= h($context) ?>;
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) updateTargetMetrics_<?= h($context) ?>(selectEl.value);
    }

    function toggleOptionalEnduranceTarget_<?= h($context) ?>() {
        forceShowEndurance_<?= h($context) ?> = !forceShowEndurance_<?= h($context) ?>;
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) updateTargetMetrics_<?= h($context) ?>(selectEl.value);
    }

    function updateTargetMetrics_<?= h($context) ?>(goal) {
        const cat = resolveGoalCategory_<?= h($context) ?>(goal);
        const allSections = [
            'increasing_strength',
            'building_muscle',
            'losing_weight',
            'reducing_body_fat',
            'improving_endurance',
            'general_fitness'
        ];

        allSections.forEach(s => {
            const sec = document.getElementById('targetSec_' + s + '_<?= h($context) ?>');
            if (!sec) return;
            const badge = sec.querySelector('.pm-target-badge');
            if (s === cat) {
                sec.style.display = 'block';
                if (badge) {
                    badge.className = 'pm-target-badge primary';
                    badge.textContent = 'Primary Target';
                }
            } else if (s === 'increasing_strength' && forceShowStrength_<?= h($context) ?>) {
                sec.style.display = 'block';
                if (badge) {
                    badge.className = 'pm-target-badge optional';
                    badge.textContent = 'Strength PR Target';
                }
            } else if (s === 'improving_endurance' && forceShowEndurance_<?= h($context) ?>) {
                sec.style.display = 'block';
                if (badge) {
                    badge.className = 'pm-target-badge optional';
                    badge.textContent = 'Endurance Target';
                }
            } else {
                sec.style.display = 'none';
            }
        });

        const cfg = goalTargetConfig_<?= h($context) ?>[cat] || goalTargetConfig_<?= h($context) ?>['general_fitness'];
        const descIcon = document.getElementById('goalDescIcon_<?= h($context) ?>');
        const descText = document.getElementById('goalDescText_<?= h($context) ?>');
        if (descIcon && cfg.icon) descIcon.innerHTML = cfg.icon;
        if (descText && cfg.desc) descText.textContent = cfg.desc;

        // Toggle supporting targets visibility
        const supportWeightWrap = document.getElementById('supportWeightWrap_<?= h($context) ?>');
        const supportBfWrap = document.getElementById('supportBfWrap_<?= h($context) ?>');
        const supportArmWrap = document.getElementById('supportArmWrap_<?= h($context) ?>');
        const supportChestWrap = document.getElementById('supportChestWrap_<?= h($context) ?>');
        const supportWaistWrap = document.getElementById('supportWaistWrap_<?= h($context) ?>');
        const supportingSec = document.getElementById('supportingTargetsSec_<?= h($context) ?>');

        if (supportWeightWrap) supportWeightWrap.style.display = cfg.showSupportWeight ? 'block' : 'none';
        if (supportBfWrap) supportBfWrap.style.display = cfg.showSupportBf ? 'block' : 'none';
        if (supportArmWrap) supportArmWrap.style.display = cfg.showSupportArm ? 'block' : 'none';
        if (supportChestWrap) supportChestWrap.style.display = cfg.showSupportChest ? 'block' : 'none';
        if (supportWaistWrap) supportWaistWrap.style.display = cfg.showSupportWaist ? 'block' : 'none';

        // Update additional target toggle buttons
        const toggleStrengthBtn = document.getElementById('toggleStrengthBtn_<?= h($context) ?>');
        const toggleEnduranceBtn = document.getElementById('toggleEnduranceBtn_<?= h($context) ?>');
        const toggleStrengthText = document.getElementById('toggleStrengthText_<?= h($context) ?>');
        const toggleEnduranceText = document.getElementById('toggleEnduranceText_<?= h($context) ?>');

        if (toggleStrengthBtn) {
            toggleStrengthBtn.style.display = (cat === 'increasing_strength') ? 'none' : 'inline-flex';
            if (toggleStrengthText) {
                toggleStrengthText.textContent = forceShowStrength_<?= h($context) ?> ? '✓ Strength Target Active' : '+ Add Strength PR Target';
            }
        }
        if (toggleEnduranceBtn) {
            toggleEnduranceBtn.style.display = (cat === 'improving_endurance') ? 'none' : 'inline-flex';
            if (toggleEnduranceText) {
                toggleEnduranceText.textContent = forceShowEndurance_<?= h($context) ?> ? '✓ Endurance Target Active' : '+ Add Endurance Target';
            }
        }

        if (supportingSec) supportingSec.style.display = 'block';

        updateStrengthLiveFeedback_<?= h($context) ?>();
        const weightInput = document.getElementById('canonicalTargetWeight_<?= h($context) ?>');
        if (weightInput) updateWeightLiveFeedback_<?= h($context) ?>(weightInput.value);
        const bfInput = document.getElementById('canonicalTargetBf_<?= h($context) ?>');
        if (bfInput) updateBfLiveFeedback_<?= h($context) ?>(bfInput.value);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) {
            updateTargetMetrics_<?= h($context) ?>(selectEl.value);
        }
    });
    setTimeout(function() {
        const selectEl = document.getElementById('primaryGoalSelect_<?= h($context) ?>');
        if (selectEl) {
            updateTargetMetrics_<?= h($context) ?>(selectEl.value);
        }
    }, 80);

    /* --- Unified Custom Select Dropdown Functions --- */
    function toggleCustomDropdown(dropdownId, e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const dd = document.getElementById(dropdownId);
        if (!dd) return;
        const isCurrentlyOpen = dd.classList.contains('open');

        // Close all other dropdowns first
        document.querySelectorAll('.custom-select-wrapper.open, .custom-goal-dropdown.open').forEach(el => {
            if (el !== dd) {
                el.classList.remove('open');
                const trig = el.querySelector('.custom-select-trigger, .custom-goal-trigger');
                if (trig) trig.setAttribute('aria-expanded', 'false');
            }
        });

        const isOpen = !isCurrentlyOpen;
        dd.classList.toggle('open', isOpen);
        const trigger = dd.querySelector('.custom-select-trigger, .custom-goal-trigger');
        const menu = dd.querySelector('.custom-select-menu, .custom-goal-menu');
        if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

        if (isOpen && menu && trigger) {
            const rect = trigger.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            if (spaceBelow < 260 && rect.top > 260) {
                menu.classList.add('drop-up');
            } else {
                menu.classList.remove('drop-up');
            }

            const searchInput = dd.querySelector('input[type="text"]');
            if (searchInput) {
                searchInput.value = '';
                filterGoalList_<?= h($context) ?>('');
                setTimeout(() => searchInput.focus(), 60);
            }

            const selectedItem = menu.querySelector('.custom-select-item.selected, .custom-goal-item.selected');
            if (selectedItem) {
                selectedItem.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    function selectCustomOption(dropdownId, selectId, val, label) {
        const hiddenSelect = document.getElementById(selectId);
        if (hiddenSelect) {
            hiddenSelect.value = val;
            hiddenSelect.dispatchEvent(new Event('change'));
            if (typeof hiddenSelect.onchange === 'function') {
                hiddenSelect.onchange();
            }
        }

        const dd = document.getElementById(dropdownId);
        if (dd) {
            const triggerText = dd.querySelector('.custom-select-trigger-text, .custom-goal-trigger-text');
            if (triggerText) triggerText.textContent = label;

            const items = dd.querySelectorAll('.custom-select-item, .custom-goal-item');
            items.forEach(item => {
                const isMatch = item.dataset.value === val;
                item.classList.toggle('selected', isMatch);
                item.setAttribute('aria-selected', isMatch ? 'true' : 'false');
            });

            dd.classList.remove('open');
            const trigger = dd.querySelector('.custom-select-trigger, .custom-goal-trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        }

        if (selectId.startsWith('primaryGoalSelect')) {
            updateTargetMetrics_<?= h($context) ?>(val);
        }
    }

    function filterGoalList_<?= h($context) ?>(q) {
        const query = (q || '').trim().toLowerCase();
        const clearBtn = document.getElementById('primaryGoalClear_<?= h($context) ?>');
        if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

        const groups = document.querySelectorAll('#primaryGoalOptionsList_<?= h($context) ?> .custom-goal-group');
        let totalVisible = 0;

        groups.forEach(group => {
            const items = group.querySelectorAll('.custom-select-item, .custom-goal-item');
            let groupVisible = 0;
            items.forEach(item => {
                const searchTxt = item.dataset.search || '';
                const matches = !query || searchTxt.includes(query);
                item.style.display = matches ? 'flex' : 'none';
                if (matches) groupVisible++;
            });
            group.style.display = groupVisible > 0 ? 'block' : 'none';
            totalVisible += groupVisible;
        });

        const emptyEl = document.getElementById('primaryGoalEmpty_<?= h($context) ?>');
        if (emptyEl) emptyEl.style.display = totalVisible === 0 ? 'block' : 'none';
    }

    function clearGoalSearch_<?= h($context) ?>() {
        const searchInput = document.getElementById('primaryGoalSearch_<?= h($context) ?>');
        if (searchInput) {
            searchInput.value = '';
            filterGoalList_<?= h($context) ?>('');
            searchInput.focus();
        }
    }

    // Close on click outside
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.custom-select-wrapper.open, .custom-goal-dropdown.open').forEach(dd => {
            if (!dd.contains(e.target)) {
                dd.classList.remove('open');
                const trig = dd.querySelector('.custom-select-trigger, .custom-goal-trigger');
                if (trig) trig.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // Keyboard accessibility
    document.addEventListener('keydown', function(e) {
        const openDd = document.querySelector('.custom-select-wrapper.open, .custom-goal-dropdown.open');
        if (!openDd) return;

        if (e.key === 'Escape') {
            openDd.classList.remove('open');
            const trigger = openDd.querySelector('.custom-select-trigger, .custom-goal-trigger');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
                trigger.focus();
            }
            return;
        }

        const visibleItems = Array.from(openDd.querySelectorAll('.custom-select-item, .custom-goal-item')).filter(el => el.style.display !== 'none');
        if (!visibleItems.length) return;

        let activeIdx = visibleItems.findIndex(el => el.classList.contains('highlighted'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIdx >= 0) visibleItems[activeIdx].classList.remove('highlighted');
            activeIdx = (activeIdx + 1) % visibleItems.length;
            visibleItems[activeIdx].classList.add('highlighted');
            visibleItems[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIdx >= 0) visibleItems[activeIdx].classList.remove('highlighted');
            activeIdx = (activeIdx - 1 + visibleItems.length) % visibleItems.length;
            visibleItems[activeIdx].classList.add('highlighted');
            visibleItems[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            if (activeIdx >= 0 && visibleItems[activeIdx]) {
                e.preventDefault();
                visibleItems[activeIdx].click();
            }
        }
    });
    </script>
    <?php
}

function dashboard_stat(string $label, string $value, string $subtext, string $trend, string $icon, bool $featured = false): void
{
    $isDown = str_contains($trend, '▼');
    echo '<article class="dash-stat ' . ($featured ? 'featured' : '') . '">';
    echo '<div class="stat-head"><span>' . h($label) . '</span><i>' . $icon . '</i></div>';
    echo '<strong>' . h($value) . '</strong>';
    echo '<p>' . h($subtext) . '</p>';
    echo '<em' . ($isDown ? ' class="trend-down"' : '') . '>' . h($trend) . '</em>';
    echo '</article>';
}

function render_current_workout(int $memberUserId, bool $dashboardMode = false, ?int $forcePlanId = null): void
{
    if ($forcePlanId) {
        $stmt = db()->prepare('SELECT * FROM training_plans WHERE plan_id = ?');
        $stmt->execute([$forcePlanId]);
        $plan = $stmt->fetch();
        if ($plan) {
            $memberUserId = (int) $plan['member_user_id'];
        }
    } else {
        $stmt = db()->prepare(
            'SELECT * FROM training_plans
             WHERE member_user_id = ? AND status = "active"
             ORDER BY plan_id DESC LIMIT 1'
        );
        $stmt->execute([$memberUserId]);
        $plan = $stmt->fetch();
    }

    echo '<section class="panel workout-plan-card">';
    if (!$plan) {
        echo '<h2>Your workout plan</h2>';
        $prof = member_profile($memberUserId);
        if ($prof && !empty($prof['primary_goal'])) {
            $gLabel = ucwords(str_replace('_', ' ', $prof['primary_goal']));
            echo '<p class="muted">Your personalized workout plan is being prepared for <strong>' . h($gLabel) . '</strong>. You can view recommended exercises below or speak with your trainer to activate your custom schedule.</p></section>';
        } else {
            echo '<p class="muted">Set up your physical profile to start generating your custom workout plan.</p></section>';
        }
        return;
    }

    if (!$dashboardMode) {
        echo '<h2>Your workout plan</h2>';
        $goalVal = ucwords(str_replace('_', ' ', (string) $plan['goal']));
        $statusVal = ucfirst((string) $plan['status']);
        $startedVal = date('M j, Y', strtotime((string) $plan['start_date']));
        $daysCount = workout_day_count((int) $plan['plan_id']);
        
        $statusClass = strtolower($statusVal) === 'active' ? 'status-active' : 'status-draft';
        ?>
        <div class="workout-header-grid">
            <div class="workout-header-card workout-goal-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Primary Goal</span>
                    <strong class="card-value"><?= h($goalVal) ?></strong>
                </div>
            </div>
            
            <div class="workout-header-card workout-status-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Plan Status</span>
                    <strong class="card-value"><span class="badge-pill <?= $statusClass ?>"><?= h($statusVal) ?></span></strong>
                </div>
            </div>

            <div class="workout-header-card workout-started-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Started Date</span>
                    <strong class="card-value"><?= h($startedVal) ?></strong>
                </div>
            </div>

            <div class="workout-header-card workout-days-card">
                <div class="card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                </div>
                <div class="card-info">
                    <span class="card-label">Training Schedule</span>
                    <strong class="card-value"><?= $daysCount ?> Days / Week</strong>
                </div>
            </div>
        </div>
        
        <style>
            .workout-header-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
                gap: 16px;
                margin-top: 16px;
                margin-bottom: 24px;
            }
            .workout-header-card {
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
                transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.25s ease, box-shadow 0.25s ease;
                position: relative;
                overflow: hidden;
            }
            .workout-header-card::before {
                content: '';
                position: absolute;
                top: 0; left: 0; right: 0; bottom: 0;
                background: radial-gradient(circle at 10% 20%, rgba(199, 255, 34, 0.05) 0%, transparent 50%);
                opacity: 0;
                transition: opacity 0.3s ease;
                pointer-events: none;
            }
            .workout-header-card:hover {
                transform: translateY(-4px);
                border-color: color-mix(in srgb, var(--lime) 30%, var(--line));
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2), 0 0 20px color-mix(in srgb, var(--lime) 5%, transparent);
            }
            .workout-header-card:hover::before {
                opacity: 1;
            }
            .card-icon {
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
                transition: background 0.3s ease, color 0.3s ease, border-color 0.3s ease;
            }
            .workout-header-card:hover .card-icon {
                background: color-mix(in srgb, var(--lime) 12%, transparent);
                color: var(--lime);
                border-color: color-mix(in srgb, var(--lime) 30%, transparent);
            }
            .card-info {
                display: flex;
                flex-direction: column;
                gap: 4px;
                min-width: 0;
                width: 100%;
            }
            .card-label {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--muted);
            }
            .card-value {
                font-size: 17px;
                font-weight: 800;
                color: var(--ink);
                line-height: 1.35;
                white-space: normal;
                word-wrap: break-word;
            }
            .badge-pill {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .status-active {
                background: rgba(45, 240, 165, 0.15);
                color: #2df0a5;
                border: 1px solid rgba(45, 240, 165, 0.25);
            }
            .status-draft {
                background: rgba(255, 149, 72, 0.15);
                color: var(--orange);
                border: 1px solid rgba(255, 149, 72, 0.25);
            }
        </style>
        <?php
    }

    $stmt = db()->prepare(
        'SELECT tpe.exercise_id, tpe.day_of_week, tpe.sequence_order, tpe.sets, tpe.reps, tpe.rest_seconds,
                e.name, e.category, e.muscle_group
         FROM training_plan_exercises tpe
         JOIN exercises e ON e.exercise_id = tpe.exercise_id
         WHERE tpe.plan_id = ?
         ORDER BY tpe.day_of_week, tpe.sequence_order'
    );
    $stmt->execute([(int) $plan['plan_id']]);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $day = workout_day_name((int) $row['day_of_week']);
        $grouped[$day][] = $row;
    }

    if ($dashboardMode) {
        $goalVal = ucwords(str_replace('_', ' ', (string) $plan['goal']));
        $daysArray = [
            'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7
        ];
        $todayNum = (int) date('N');
        $todayName = workout_day_name($todayNum);

        // Header
        echo '<div class="workout-dashboard-header">';
        echo '  <div class="workout-dashboard-title-group">';
        echo '    <div class="workout-dashboard-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6.5 6.5 11 11"/><path d="m21 21-1-1"/><path d="m3 3 1 1"/><path d="m18 22 4-4"/><path d="m2 6 4-4"/><path d="m3 10 7-7"/><path d="m14 21 7-7"/></svg></div>';
        echo '    <div>';
        echo '      <h2 style="margin:0; font-size: 1.25rem; font-weight:800; color: var(--ink);">Your Workout Plan</h2>';
        echo '      <div class="workout-plan-sub">';
        echo '        <span class="workout-plan-badge"><span class="workout-plan-dot"></span> ' . h($goalVal) . '</span>';
        echo '        <span class="workout-plan-badge workout-status-active"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Active</span>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '  <a href="index.php?page=my_workout" class="workout-plan-top-link"><span>View Full Routine</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></a>';
        echo '</div>';

        // 7-Day Week Strip
        echo '<div class="workout-week-strip" role="group" aria-label="Weekly Schedule">';
        foreach ($daysArray as $dName => $dNum) {
            $isToday = ($dNum === $todayNum);
            $hasWorkout = !empty($grouped[$dName]);
            $exCount = $hasWorkout ? count($grouped[$dName]) : 0;
            $shortName = substr($dName, 0, 3);
            
            $dayClass = 'week-day-col';
            if ($isToday) $dayClass .= ' is-today';
            if ($hasWorkout) $dayClass .= ' has-workout';
            else $dayClass .= ' is-rest';

            echo '<div class="' . $dayClass . '">';
            echo '  <span class="week-day-label">' . h($shortName) . '</span>';
            echo '  <div class="week-day-indicator">';
            if ($hasWorkout) {
                echo '    <span class="week-day-badge workout-badge" title="' . $exCount . ' exercises">' . $exCount . ' ex</span>';
            } else {
                echo '    <span class="week-day-badge rest-badge" title="Rest Day">Rest</span>';
            }
            echo '  </div>';
            if ($isToday) {
                echo '  <span class="week-today-pill">Today</span>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Lookahead for next workout session
        $nextDayInfo = null;
        for ($i = 1; $i <= 7; $i++) {
            $checkDayNum = (($todayNum - 1 + $i) % 7) + 1;
            $checkDayName = workout_day_name($checkDayNum);
            if (!empty($grouped[$checkDayName])) {
                $count = count($grouped[$checkDayName]);
                $sampleNames = array_slice(array_column($grouped[$checkDayName], 'name'), 0, 3);
                $nextDayInfo = [
                    'dayName' => $checkDayName,
                    'count'   => $count,
                    'names'   => implode(', ', $sampleNames) . ($count > 3 ? ', ...' : '')
                ];
                break;
            }
        }

        $todayExercises = $grouped[$todayName] ?? [];

        if (empty($todayExercises)) {
            // REST DAY
            echo '<div class="workout-rest-card">';
            echo '  <div class="workout-rest-body">';
            echo '    <div class="workout-rest-icon-wrap">';
            echo '      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/></svg>';
            echo '    </div>';
            echo '    <div class="workout-rest-content">';
            echo '      <div class="workout-rest-header-row">';
            echo '        <h4 class="workout-rest-title">Active Recovery &amp; Rest Day</h4>';
            echo '        <span class="workout-recovery-pill"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Recharge</span>';
            echo '      </div>';
            echo '      <p class="workout-rest-desc">Rest is when your muscle fibers rebuild and strength adapts. Prioritize 7–9 hours of sleep, stay well-hydrated, and hit your daily nutrition targets.</p>';
            if ($nextDayInfo) {
                echo '      <div class="workout-next-teaser">';
                echo '        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                echo '        <span><strong>Next session:</strong> ' . h($nextDayInfo['dayName']) . ' &bull; ' . (int)$nextDayInfo['count'] . ' exercises scheduled (' . h($nextDayInfo['names']) . ')</span>';
                echo '      </div>';
            }
            echo '    </div>';
            echo '  </div>';
            echo '  <div class="workout-rest-actions">';
            echo '    <a href="index.php?page=my_workout" class="btn-workout-cta btn-workout-primary"><span>View Full Workout Plan</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></a>';
            echo '    <a href="index.php?page=progress" class="btn-workout-cta btn-workout-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> <span>Log Body Stats</span></a>';
            echo '  </div>';
            echo '</div>';
        } else {
            // WORKOUT DAY
            $stmt = db()->prepare('SELECT exercise_id FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date = ?');
            $stmt->execute([$memberUserId, $plan['plan_id'], date('Y-m-d')]);
            $completedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $totalCount = count($todayExercises);
            $doneCount = count(array_intersect(array_column($todayExercises, 'exercise_id'), $completedIds));
            $pct = $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0;

            if ($doneCount >= $totalCount) {
                // ALL DONE TODAY
                echo '<div class="workout-completed-banner">';
                echo '  <div class="workout-completed-icon"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
                echo '  <div class="workout-completed-info">';
                echo '    <h4>All Done For Today!</h4>';
                echo '    <p>You\'ve crushed all ' . $totalCount . ' scheduled exercises today. Phenomenal dedication!</p>';
                echo '  </div>';
                echo '  <a href="index.php?page=my_workout" class="btn-workout-cta btn-workout-secondary"><span>View Routine</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></a>';
                echo '</div>';
            } else {
                // EXERCISES REMAINING
                echo '<div class="workout-today-active-box">';
                echo '  <div class="workout-today-bar-row">';
                echo '    <div class="workout-today-title-wrap">';
                echo '      <span class="workout-today-title">Today\'s Routine (' . h($todayName) . ')</span>';
                echo '      <span class="workout-today-progress-txt">' . $doneCount . ' of ' . $totalCount . ' completed (' . $pct . '%)</span>';
                echo '    </div>';
                echo '    <div class="workout-mini-progress"><div class="workout-mini-progress-fill" style="width:' . $pct . '%;"></div></div>';
                echo '  </div>';

                echo '  <div class="workout-exercise-cards-grid">';
                foreach ($todayExercises as $ex) {
                    $isDone = in_array($ex['exercise_id'], $completedIds);
                    $cardClass = 'workout-ex-card' . ($isDone ? ' is-done' : '');
                    echo '    <div class="' . $cardClass . '">';
                    echo '      <div class="workout-ex-card-main">';
                    echo '        <div class="workout-ex-header">';
                    echo '          <strong class="workout-ex-name">' . h($ex['name']) . '</strong>';
                    echo '          <span class="badge badge-cat badge-' . h($ex['category']) . '">' . h(ucfirst($ex['category'])) . '</span>';
                    echo '        </div>';
                    echo '        <div class="workout-ex-muscle">' . h(ucfirst($ex['muscle_group'])) . '</div>';
                    echo '        <div class="workout-ex-chips">';
                    echo '          <span class="ex-chip"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 4h12M4 9h16M4 15h16M6 20h12"/></svg> ' . (int)$ex['sets'] . ' sets</span>';
                    echo '          <span class="ex-chip"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> ' . h($ex['reps']) . '</span>';
                    if (!empty($ex['rest_seconds'])) {
                        echo '          <span class="ex-chip"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' . (int)$ex['rest_seconds'] . 's rest</span>';
                    }
                    echo '        </div>';
                    echo '      </div>';
                    echo '      <div class="workout-ex-action">';
                    if ($isDone) {
                        echo '        <span class="workout-done-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Done</span>';
                    } else {
                        echo '        <button type="button" class="btn-mark-complete" onclick="completeExercise(' . $plan['plan_id'] . ', ' . $ex['exercise_id'] . ')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Complete</button>';
                    }
                    echo '      </div>';
                    echo '    </div>';
                }
                echo '  </div>';
                echo '</div>';
            }
        }
        ?>
        <style>
            .workout-dashboard-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px;
                margin-bottom: 20px;
                padding-bottom: 14px;
                border-bottom: 1px solid var(--line);
            }
            .workout-dashboard-title-group {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .workout-dashboard-icon-wrap {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                background: color-mix(in srgb, var(--lime) 12%, transparent);
                color: var(--lime);
                border: 1px solid color-mix(in srgb, var(--lime) 25%, transparent);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .workout-plan-sub {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-top: 4px;
                flex-wrap: wrap;
            }
            .workout-plan-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                padding: 2px 8px;
                border-radius: 20px;
                background: rgba(255, 255, 255, 0.05);
                color: var(--muted);
                border: 1px solid var(--line);
            }
            .workout-plan-dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--lime);
                box-shadow: 0 0 6px var(--lime);
            }
            .workout-status-active {
                background: rgba(45, 240, 165, 0.12);
                color: #2df0a5;
                border-color: rgba(45, 240, 165, 0.25);
            }
            .workout-plan-top-link {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 12px;
                font-weight: 700;
                color: var(--lime);
                text-decoration: none;
                padding: 6px 12px;
                border-radius: 8px;
                background: color-mix(in srgb, var(--lime) 8%, transparent);
                border: 1px solid color-mix(in srgb, var(--lime) 20%, transparent);
                transition: all 0.2s ease;
            }
            .workout-plan-top-link:hover {
                background: color-mix(in srgb, var(--lime) 16%, transparent);
                border-color: var(--lime);
                transform: translateX(2px);
            }
            .workout-week-strip {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 8px;
                margin-bottom: 20px;
                position: relative;
            }
            .week-day-col {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 10px 4px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid var(--line);
                transition: all 0.2s ease;
                text-align: center;
                position: relative;
            }
            .week-day-col:hover {
                background: rgba(255, 255, 255, 0.04);
            }
            .week-day-col.is-today {
                background: color-mix(in srgb, var(--lime) 10%, transparent);
                border-color: var(--lime);
                box-shadow: 0 0 14px color-mix(in srgb, var(--lime) 20%, transparent);
            }
            .week-day-label {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--muted);
                margin-bottom: 6px;
            }
            .week-day-col.is-today .week-day-label {
                color: var(--lime);
                font-weight: 800;
            }
            .week-day-badge {
                font-size: 10px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 6px;
                white-space: nowrap;
            }
            .week-day-badge.workout-badge {
                background: rgba(99, 102, 241, 0.15);
                color: #818cf8;
                border: 1px solid rgba(99, 102, 241, 0.3);
            }
            .week-day-badge.rest-badge {
                background: rgba(255, 255, 255, 0.04);
                color: var(--muted);
                border: 1px solid var(--line);
            }
            .week-today-pill {
                position: absolute;
                bottom: -8px;
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                background: var(--lime);
                color: #090b10;
                padding: 1px 6px;
                border-radius: 10px;
                letter-spacing: 0.04em;
                white-space: nowrap;
                z-index: 2;
            }
            .workout-rest-card {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 20px;
                padding: 22px;
                border-radius: 14px;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.02) 0%, rgba(199, 255, 34, 0.03) 100%);
                border: 1px solid var(--line);
                position: relative;
                overflow: hidden;
            }
            .workout-rest-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                bottom: 0;
                width: 3px;
                background: var(--lime);
                border-radius: 3px 0 0 3px;
            }
            .workout-rest-body {
                display: flex;
                align-items: flex-start;
                gap: 16px;
                flex: 1;
                min-width: 280px;
            }
            .workout-rest-icon-wrap {
                width: 52px;
                height: 52px;
                border-radius: 14px;
                background: color-mix(in srgb, var(--lime) 12%, transparent);
                border: 1px solid color-mix(in srgb, var(--lime) 25%, transparent);
                color: var(--lime);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .workout-rest-content {
                flex: 1;
                min-width: 0;
            }
            .workout-rest-header-row {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 6px;
                flex-wrap: wrap;
            }
            .workout-rest-title {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                color: var(--ink);
            }
            .workout-recovery-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                padding: 2px 8px;
                border-radius: 12px;
                background: color-mix(in srgb, var(--teal) 12%, transparent);
                color: var(--teal);
                border: 1px solid color-mix(in srgb, var(--teal) 25%, transparent);
            }
            .workout-rest-desc {
                margin: 0 0 10px;
                font-size: 13px;
                line-height: 1.5;
                color: var(--muted);
                overflow-wrap: break-word;
            }
            .workout-next-teaser {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 12px;
                color: var(--ink);
                padding: 8px 12px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid var(--line);
                overflow-wrap: break-word;
            }
            .workout-next-teaser svg {
                color: var(--lime);
                flex-shrink: 0;
            }
            .workout-rest-actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
                flex-shrink: 0;
            }
            .btn-workout-cta {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                text-decoration: none;
                transition: all 0.2s ease;
                cursor: pointer;
                white-space: nowrap;
            }

            /* Responsive rules across mobile, tablet, and desktop */
            @media (max-width: 680px) {
                .workout-dashboard-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                    margin-bottom: 16px;
                    padding-bottom: 12px;
                }
                .workout-dashboard-title-group {
                    width: 100%;
                }
                .workout-dashboard-icon-wrap {
                    width: 36px;
                    height: 36px;
                    border-radius: 8px;
                }
                .workout-dashboard-icon-wrap svg {
                    width: 18px;
                    height: 18px;
                }
                .workout-plan-top-link {
                    width: 100%;
                    justify-content: center;
                    padding: 9px 14px;
                    box-sizing: border-box;
                    font-size: 12px;
                }
                .workout-week-strip {
                    display: flex;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    scroll-snap-type: x proximity;
                    scrollbar-width: none;
                    gap: 6px;
                    padding: 4px 2px 14px 2px;
                    margin-bottom: 16px;
                }
                .workout-week-strip::-webkit-scrollbar {
                    display: none;
                }
                .week-day-col {
                    flex: 1 0 46px;
                    min-width: 46px;
                    padding: 8px 3px 10px;
                    scroll-snap-align: start;
                }
                .week-day-label {
                    font-size: 10px;
                    margin-bottom: 4px;
                }
                .week-day-badge {
                    font-size: 9px;
                    padding: 2px 4px;
                }
                .week-today-pill {
                    bottom: -7px;
                    font-size: 8.5px;
                    padding: 1px 5px;
                }
                .workout-rest-card {
                    padding: 16px 14px;
                    gap: 14px;
                    border-radius: 12px;
                }
                .workout-rest-body {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                    min-width: 100%;
                }
                .workout-rest-icon-wrap {
                    width: 42px;
                    height: 42px;
                    border-radius: 10px;
                }
                .workout-rest-icon-wrap svg {
                    width: 22px;
                    height: 22px;
                }
                .workout-rest-title {
                    font-size: 15px;
                }
                .workout-recovery-pill {
                    font-size: 10px;
                    padding: 2px 7px;
                }
                .workout-rest-desc {
                    font-size: 12.5px;
                    line-height: 1.45;
                    margin-bottom: 8px;
                }
                .workout-next-teaser {
                    font-size: 11px;
                    padding: 8px 10px;
                    line-height: 1.4;
                    gap: 6px;
                    align-items: flex-start;
                    word-break: break-word;
                }
                .workout-next-teaser svg {
                    margin-top: 2px;
                }
                .workout-rest-actions {
                    width: 100%;
                    flex-direction: column;
                    gap: 8px;
                }
                .btn-workout-cta {
                    width: 100%;
                    justify-content: center;
                    padding: 11px 16px;
                    font-size: 13px;
                    box-sizing: border-box;
                }
                .workout-exercise-cards-grid {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }
                .workout-ex-card {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                    padding: 12px;
                }
                .workout-ex-action {
                    width: 100%;
                }
                .btn-mark-complete,
                .workout-done-badge {
                    width: 100%;
                    justify-content: center;
                    padding: 10px 14px;
                    box-sizing: border-box;
                }
                .workout-today-title-wrap {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 4px;
                }
                .workout-completed-banner {
                    flex-direction: column;
                    align-items: flex-start;
                    padding: 16px;
                    gap: 12px;
                }
                .workout-completed-banner .btn-workout-cta {
                    width: 100%;
                }
            }

            @media (min-width: 681px) and (max-width: 960px) {
                .workout-rest-card {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 16px;
                    padding: 18px 20px;
                }
                .workout-rest-actions {
                    width: 100%;
                    flex-direction: row;
                    gap: 10px;
                }
                .btn-workout-cta {
                    flex: 1;
                    min-width: 160px;
                }
            }
            .btn-workout-primary {
                background: var(--lime);
                color: #090b10 !important;
                border: 1px solid var(--lime);
                box-shadow: 0 4px 14px color-mix(in srgb, var(--lime) 25%, transparent);
            }
            .btn-workout-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px color-mix(in srgb, var(--lime) 40%, transparent);
            }
            .btn-workout-secondary {
                background: rgba(255, 255, 255, 0.04);
                color: var(--ink);
                border: 1px solid var(--line);
            }
            .btn-workout-secondary:hover {
                background: rgba(255, 255, 255, 0.08);
                border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
                color: var(--lime);
            }
            .workout-completed-banner {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px;
                border-radius: 12px;
                background: rgba(45, 240, 165, 0.08);
                border: 1px solid rgba(45, 240, 165, 0.25);
                flex-wrap: wrap;
            }
            .workout-completed-icon {
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: rgba(45, 240, 165, 0.15);
                color: #2df0a5;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .workout-completed-info {
                flex: 1;
            }
            .workout-completed-info h4 {
                margin: 0 0 4px;
                font-size: 16px;
                font-weight: 800;
                color: var(--ink);
            }
            .workout-completed-info p {
                margin: 0;
                font-size: 13px;
                color: var(--muted);
            }
            .workout-today-active-box {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .workout-today-bar-row {
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 12px 16px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid var(--line);
            }
            .workout-today-title-wrap {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }
            .workout-today-title {
                font-size: 14px;
                font-weight: 800;
                color: var(--ink);
            }
            .workout-today-progress-txt {
                font-size: 12px;
                font-weight: 700;
                color: var(--lime);
            }
            .workout-mini-progress {
                width: 100%;
                height: 6px;
                border-radius: 6px;
                background: rgba(255, 255, 255, 0.06);
                overflow: hidden;
            }
            .workout-mini-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, var(--lime), var(--teal));
                border-radius: 6px;
                transition: width 0.3s ease;
            }
            .workout-exercise-cards-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
                gap: 12px;
            }
            .workout-ex-card {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                padding: 14px;
                border-radius: 12px;
                background: var(--panel-soft);
                border: 1px solid var(--line);
                transition: all 0.2s ease;
            }
            .workout-ex-card:hover {
                border-color: color-mix(in srgb, var(--lime) 30%, var(--line));
                transform: translateY(-2px);
            }
            .workout-ex-card.is-done {
                opacity: 0.65;
                background: rgba(255, 255, 255, 0.01);
            }
            .workout-ex-card.is-done .workout-ex-name {
                text-decoration: line-through;
            }
            .workout-ex-card-main {
                flex: 1;
                min-width: 0;
            }
            .workout-ex-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 4px;
            }
            .workout-ex-name {
                font-size: 14px;
                font-weight: 700;
                color: var(--ink);
            }
            .workout-ex-muscle {
                font-size: 12px;
                color: var(--muted);
                margin-bottom: 8px;
            }
            .workout-ex-chips {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }
            .ex-chip {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                padding: 2px 7px;
                border-radius: 6px;
                background: rgba(255, 255, 255, 0.04);
                border: 1px solid var(--line);
                color: var(--muted);
            }
            .btn-mark-complete {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 7px 12px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 700;
                background: var(--lime);
                color: #090b10;
                border: none;
                cursor: pointer;
                transition: all 0.2s ease;
                flex-shrink: 0;
            }
            .btn-mark-complete:hover {
                transform: scale(1.04);
                box-shadow: 0 0 12px color-mix(in srgb, var(--lime) 35%, transparent);
            }
            .workout-done-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                font-weight: 700;
                color: var(--teal);
                padding: 4px 8px;
                border-radius: 6px;
                background: rgba(45, 240, 165, 0.1);
                border: 1px solid rgba(45, 240, 165, 0.2);
            }

            /* LIGHT MODE ADAPTATION */
            [data-theme="light"] .workout-dashboard-header {
                border-bottom-color: #e2e8f0;
            }
            [data-theme="light"] .workout-dashboard-icon-wrap {
                background: #ecfccb;
                color: #365314;
                border-color: #bef264;
            }
            [data-theme="light"] .workout-plan-badge {
                background: #f1f5f9;
                color: #334155;
                border-color: #e2e8f0;
            }
            [data-theme="light"] .workout-plan-top-link {
                background: #ecfccb;
                color: #365314;
                border-color: #bef264;
            }
            [data-theme="light"] .workout-plan-top-link:hover {
                background: #d9f99d;
                border-color: #65a30d;
            }
            [data-theme="light"] .week-day-col {
                background: #f8fafc;
                border-color: #e2e8f0;
            }
            [data-theme="light"] .week-day-col:hover {
                background: #f1f5f9;
            }
            [data-theme="light"] .week-day-col.is-today {
                background: #f0fdf4;
                border-color: #16a34a;
                box-shadow: 0 0 14px rgba(22, 163, 74, 0.18);
            }
            [data-theme="light"] .week-day-col.is-today .week-day-label {
                color: #166534;
            }
            [data-theme="light"] .week-today-pill {
                background: #16a34a;
                color: #ffffff;
            }
            [data-theme="light"] .week-day-badge.workout-badge {
                background: #eff6ff;
                color: #1d4ed8;
                border-color: #bfdbfe;
            }
            [data-theme="light"] .week-day-badge.rest-badge {
                background: #f1f5f9;
                color: #64748b;
                border-color: #e2e8f0;
            }
            [data-theme="light"] .workout-rest-card {
                background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
                border-color: #e2e8f0;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
            }
            [data-theme="light"] .workout-rest-card::before {
                background: #16a34a;
            }
            [data-theme="light"] .workout-rest-icon-wrap {
                background: #ecfccb;
                color: #365314;
                border-color: #bef264;
            }
            [data-theme="light"] .workout-recovery-pill {
                background: #ecfdf5;
                color: #047857;
                border-color: #a7f3d0;
            }
            [data-theme="light"] .workout-rest-title {
                color: #0f172a;
            }
            [data-theme="light"] .workout-rest-desc {
                color: #475569;
            }
            [data-theme="light"] .workout-next-teaser {
                background: #f8fafc;
                border-color: #e2e8f0;
                color: #1e293b;
            }
            [data-theme="light"] .workout-next-teaser svg {
                color: #16a34a;
            }
            [data-theme="light"] .btn-workout-primary {
                background: #16a34a;
                color: #ffffff !important;
                border-color: #16a34a;
                box-shadow: 0 4px 12px rgba(22, 163, 74, 0.2);
            }
            [data-theme="light"] .btn-workout-primary:hover {
                background: #15803d;
                box-shadow: 0 6px 18px rgba(22, 163, 74, 0.3);
            }
            [data-theme="light"] .btn-workout-secondary {
                background: #ffffff;
                color: #0f172a;
                border-color: #cbd5e1;
            }
            [data-theme="light"] .btn-workout-secondary:hover {
                background: #f8fafc;
                border-color: #94a3b8;
                color: #0f172a;
            }
            [data-theme="light"] .workout-today-bar-row {
                background: #f8fafc;
                border-color: #e2e8f0;
            }
            [data-theme="light"] .workout-today-title {
                color: #0f172a;
            }
            [data-theme="light"] .workout-today-progress-txt {
                color: #16a34a;
            }
            [data-theme="light"] .workout-mini-progress {
                background: #e2e8f0;
            }
            [data-theme="light"] .workout-ex-card {
                background: #ffffff;
                border-color: #e2e8f0;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
            [data-theme="light"] .workout-ex-card:hover {
                border-color: #94a3b8;
                box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
            }
            [data-theme="light"] .workout-ex-name {
                color: #0f172a;
            }
            [data-theme="light"] .ex-chip {
                background: #f1f5f9;
                color: #334155;
                border-color: #e2e8f0;
            }
            [data-theme="light"] .btn-mark-complete {
                background: #16a34a;
                color: #ffffff;
            }
        </style>
        <?php
    } else {
        $daysArray = [
            'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7
        ];
        
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
        $stmt = db()->prepare('SELECT exercise_id, completed_date FROM exercise_completions WHERE user_id = ? AND plan_id = ? AND completed_date >= ? AND completed_date <= ?');
        $stmt->execute([$memberUserId, $plan['plan_id'], $startOfWeek, $endOfWeek]);
        $completionsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completions = [];
        foreach ($completionsRaw as $c) {
            $completions[$c['completed_date']][] = $c['exercise_id'];
        }

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 20px; margin-top: 2rem;">';
        foreach ($daysArray as $dayName => $dayNum) {
            $exercises = $grouped[$dayName] ?? [];
            $dateForThisDay = date('Y-m-d', strtotime($dayName . ' this week'));
            $completedExIds = $completions[$dateForThisDay] ?? [];
            
            echo '<div class="panel" style="display: flex; flex-direction: column;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--line);">';
            echo '<h3 style="margin: 0; color: var(--lime);">' . h($dayName) . '</h3>';
            echo '</div>';
            
            echo '<div style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-height: 50px;">';
            if (empty($exercises)) {
                echo '<div class="empty-state" style="text-align: center; color: var(--muted); padding: 20px 0; font-size: 13px; font-style: italic;">Rest day. No exercises assigned.</div>';
            } else {
                foreach ($exercises as $ex) {
                    $isCompleted = in_array($ex['exercise_id'], $completedExIds);
                    echo '<div style="background: color-mix(in srgb, var(--bg) 50%, transparent); border: 1px solid var(--line); border-radius: 6px; padding: 10px;">';
                    
                    if ($isCompleted) {
                        echo '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink); text-decoration: line-through; opacity: 0.7;">' . h($ex['name']) . '</div>';
                        echo '<svg viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="3" style="width: 18px; height: 18px; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        echo '</div>';
                    } else {
                        echo '<div style="font-weight: bold; font-size: 14px; color: var(--ink);">' . h($ex['name']) . '</div>';
                    }
                    
                    echo '<div style="font-size: 12px; color: var(--muted); margin-top: 4px;' . ($isCompleted ? ' opacity: 0.7;' : '') . '">';
                    echo $ex['sets'] . ' sets &times; ' . h($ex['reps']);
                    echo '<span style="margin: 0 5px;">|</span>';
                    echo 'Rest: ' . $ex['rest_seconds'] . 's';
                    echo '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        if (!$rows) {
            echo '<p class="muted">No exercises assigned to this plan yet.</p>';
        }
    }

    if ($dashboardMode) {
        $csrfToken = csrf_token();
        echo <<<HTML
        <script>
        function completeExercise(planId, exerciseId) {
            Swal.fire({
                title: 'Completed already?',
                text: "Are you sure you want to mark this exercise as finished?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--lime-dark)',
                cancelButtonColor: 'var(--line)',
                confirmButtonText: 'Yes, I crushed it!',
                cancelButtonText: 'No',
                background: 'var(--bg)',
                color: 'var(--ink)'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('index.php?page=complete_exercise', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'plan_id=' + planId + '&exercise_id=' + exerciseId + '&csrf_token=' + encodeURIComponent('{$csrfToken}')
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (data.tier_upgraded) {
                                Swal.fire({
                                    title: 'Level Up!',
                                    text: 'You have been promoted to ' + data.tier_upgraded.new_tier_name + '!',
                                    icon: 'success',
                                    background: 'var(--bg)',
                                    color: 'var(--ink)'
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                window.location.reload();
                            }
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Failed to complete exercise.', 'error');
                    });
                }
            });
        }
        </script>
HTML;
    }

    echo '</section>';
}

function render_exercise_recommendations(int $userId, bool $compact = false): void
{
    $recs = get_exercise_recommendations($userId);
    $profile = member_profile($userId);

    echo '<section class="panel exercise-recs">';
    echo '<div class="rec-header-wrap">';
    echo '  <div>';
    echo '    <h2 style="margin:0; font-size:1.25rem; font-weight:800; color:var(--ink);">Recommended Exercises</h2>';
    echo '    <p class="muted rec-sub">Tailored to your physical profile, current activity level, and goals.</p>';
    echo '  </div>';
    if ($profile) {
        $goalLabel = ucwords(str_replace('_', ' ', $profile['primary_goal']));
        echo '  <div class="rec-goal-pill">';
        echo '    <span class="rec-goal-dot"></span>';
        echo '    <span>' . h($goalLabel) . '</span>';
        echo '  </div>';
    }
    echo '</div>';

    if (!$recs) {
        if (!$profile) {
            echo '<div style="padding: 12px 0;">';
            echo '  <p class="muted" style="margin-bottom:10px;">Set up your physical profile to unlock personalized exercise recommendations tailored to your goals.</p>';
            echo '  <a href="index.php?page=profile" class="btn btn-secondary" style="font-size:12.5px; padding:6px 14px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">Set Up Profile</a>';
            echo '</div>';
        } else {
            $goalText = !empty($profile['primary_goal']) ? ucwords(str_replace('_', ' ', $profile['primary_goal'])) : 'your selected goal';
            echo '<div style="padding: 12px 0;">';
            echo '  <p class="muted" style="margin-bottom:4px;">Custom exercise recommendations for <strong>' . h($goalText) . '</strong> are currently being prepared.</p>';
            echo '  <p class="muted" style="font-size:12px; margin:0;">Check back soon or consult your gym trainer to add exercises to your catalog.</p>';
            echo '</div>';
        }
        echo '</section>';
        return;
    }

    if ($compact) {
        // Compact card grid for dashboard
        echo '<div class="rec-grid">';
        foreach (array_slice($recs, 0, 4) as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'rec-high' : '';
            echo '<div class="rec-card ' . $priorityClass . '">';
            echo '  <div class="rec-card-head">';
            echo '    <strong class="rec-card-title">' . h($ex['name']) . '</strong>';
            echo '    <span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span>';
            echo '  </div>';
            echo '  <div class="rec-muscle-row">';
            echo '    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>';
            echo '    <span>' . h(ucfirst($ex['muscle_group'])) . '</span>';
            echo '  </div>';
            echo '  <div class="rec-params">';
            echo '    <span class="rec-chip" title="Sets"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 4h12M4 9h16M4 15h16M6 20h12"/></svg> ' . (int)$rec['sets'] . ' sets</span>';
            echo '    <span class="rec-chip" title="Reps"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> ' . h($rec['reps']) . '</span>';
            if ($rec['rest_seconds'] > 0) {
                echo '    <span class="rec-chip" title="Rest duration"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' . (int)$rec['rest_seconds'] . 's rest</span>';
            }
            echo '  </div>';
            if (!empty($rec['recommendation'])) {
                echo '  <div class="rec-card-hint" title="' . h($rec['recommendation']) . '">';
                echo '    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
                echo '    <span>' . h($rec['recommendation']) . '</span>';
                echo '  </div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="rec-footer-cta">';
        echo '  <a href="index.php?page=my_workout" class="rec-explore-btn">';
        echo '    <span>Explore All Recommendations</span>';
        echo '    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>';
        echo '  </a>';
        echo '</div>';
    } else {
        // Full table view for workout page
        echo '<div class="table-wrap"><table>';
        echo '<thead><tr><th>Exercise</th><th>Category</th><th>Muscle Group</th><th>Sets</th><th>Reps</th><th>Rest</th><th>Why Recommended</th></tr></thead>';
        echo '<tbody>';
        foreach ($recs as $rec) {
            $ex = $rec['exercise'];
            $priorityClass = $rec['priority'] === 'high' ? 'style="border-left:3px solid var(--accent);"' : '';
            echo '<tr ' . $priorityClass . '>';
            echo '<td><strong>' . h($ex['name']) . '</strong></td>';
            echo '<td><span class="badge badge-cat badge-' . h($rec['category']) . '">' . h(ucfirst($rec['category'])) . '</span></td>';
            echo '<td>' . h(ucfirst($ex['muscle_group'])) . '</td>';
            echo '<td>' . (int) $rec['sets'] . '</td>';
            echo '<td>' . h($rec['reps']) . '</td>';
            echo '<td>' . ($rec['rest_seconds'] > 0 ? (int) $rec['rest_seconds'] . 's' : '—') . '</td>';
            echo '<td class="rec-reason">' . h($rec['recommendation']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</section>';
    ?>
    <style>
        .exercise-recs {
            position: relative;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
        }
        .rec-header-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 6px;
            min-width: 0;
            width: 100%;
        }
        .rec-sub {
            font-size: 13px;
            margin: 4px 0 0 0;
            color: var(--muted);
        }
        .rec-goal-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: color-mix(in srgb, #a855f7 15%, transparent);
            color: #c084fc;
            border: 1px solid color-mix(in srgb, #a855f7 35%, transparent);
            flex-shrink: 0;
        }
        .rec-goal-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #c084fc;
            box-shadow: 0 0 6px #c084fc;
        }
        .badge-cat {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .badge-strength { background: rgba(99,102,241,0.15); color: #818cf8; border: 1px solid rgba(99,102,241,0.3); }
        .badge-cardio   { background: rgba(239,68,68,0.15);  color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .badge-core     { background: rgba(34,197,94,0.15);  color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }

        .rec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr));
            gap: 14px;
            margin-top: 14px;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .rec-card {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        .rec-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.25);
            border-color: color-mix(in srgb, var(--lime) 40%, var(--line));
        }
        .rec-card.rec-high {
            border-left: 3px solid #a855f7;
            background: linear-gradient(90deg, rgba(168, 85, 247, 0.06) 0%, var(--panel-soft) 100%);
        }
        .rec-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 6px;
            min-width: 0;
            width: 100%;
        }
        .rec-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.3;
            min-width: 0;
            flex: 1;
            word-break: break-word;
        }
        .rec-muscle-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 12px;
            min-width: 0;
        }
        .rec-muscle-row svg {
            color: var(--lime);
            flex-shrink: 0;
        }
        .rec-params {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
            min-width: 0;
            width: 100%;
        }
        .rec-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--line);
            padding: 3px 8px;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .rec-chip svg {
            color: var(--muted);
            flex-shrink: 0;
        }
        .rec-card-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--muted);
            padding-top: 8px;
            border-top: 1px solid var(--line);
            min-width: 0;
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            box-sizing: border-box;
        }
        .rec-card-hint svg {
            color: var(--lime);
            flex-shrink: 0;
        }
        .rec-card-hint span {
            min-width: 0;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
        }
        .rec-footer-cta {
            margin-top: 16px;
            display: flex;
            justify-content: flex-start;
            min-width: 0;
            width: 100%;
        }
        .rec-explore-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.04);
            color: var(--ink);
            border: 1px solid var(--line);
            transition: all 0.2s ease;
        }
        .rec-explore-btn:hover {
            background: color-mix(in srgb, var(--lime) 12%, transparent);
            border-color: var(--lime);
            color: var(--lime);
            transform: translateX(3px);
        }

        /* Responsive rules across mobile, tablet, and desktop */
        @media (max-width: 680px) {
            .rec-header-wrap {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .rec-goal-pill {
                align-self: flex-start;
                font-size: 10.5px;
                padding: 3px 9px;
            }
            .rec-footer-cta {
                width: 100%;
            }
            .rec-explore-btn {
                width: 100%;
                justify-content: center;
                padding: 11px 16px;
                box-sizing: border-box;
            }
        }
        @media (max-width: 540px) {
            .rec-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }
            .rec-card {
                padding: 14px;
            }
        }
        @media (min-width: 541px) and (max-width: 860px) {
            .rec-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }
        }

        /* LIGHT MODE ADAPTATION */
        [data-theme="light"] .rec-sub {
            color: #475569;
        }
        [data-theme="light"] .rec-goal-pill {
            background: #faf5ff;
            color: #7e22ce;
            border-color: #e9d5ff;
        }
        [data-theme="light"] .rec-goal-dot {
            background: #7e22ce;
            box-shadow: 0 0 6px #7e22ce;
        }
        [data-theme="light"] .rec-card {
            background: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }
        [data-theme="light"] .rec-card:hover {
            border-color: #94a3b8;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        [data-theme="light"] .rec-card.rec-high {
            border-left-color: #9333ea;
            background: linear-gradient(90deg, #faf5ff 0%, #ffffff 100%);
        }
        [data-theme="light"] .rec-card-title {
            color: #0f172a;
        }
        [data-theme="light"] .rec-muscle-row {
            color: #64748b;
        }
        [data-theme="light"] .rec-muscle-row svg {
            color: #16a34a;
        }
        [data-theme="light"] .rec-chip {
            background: #f1f5f9;
            color: #1e293b;
            border-color: #e2e8f0;
        }
        [data-theme="light"] .rec-chip svg {
            color: #64748b;
        }
        [data-theme="light"] .rec-card-hint {
            border-top-color: #f1f5f9;
            color: #64748b;
        }
        [data-theme="light"] .rec-card-hint svg {
            color: #16a34a;
        }
        [data-theme="light"] .rec-explore-btn {
            background: #ffffff;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        [data-theme="light"] .rec-explore-btn:hover {
            background: #f8fafc;
            border-color: #65a30d;
            color: #4d7c0f;
        }
        [data-theme="light"] .badge-strength { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        [data-theme="light"] .badge-cardio   { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        [data-theme="light"] .badge-core     { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
    </style>
    <?php
}


function workout_day_name(int $dayOfWeek): string
{
    return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dayOfWeek] ?? 'Day ' . $dayOfWeek;
}

function workout_day_count(int $planId): int
{
    return (int) scalar(
        'SELECT COUNT(DISTINCT day_of_week) FROM training_plan_exercises WHERE plan_id = ?',
        [$planId]
    );
}

function render_notification_bell(array $user, string $currentPage): void
{
    $userId = (int) $user['user_id'];
    $unread = unread_notification_count($userId);
    $items  = get_notifications($userId, 8);
    ?>
    <div class="notif-wrap" id="notif-wrap">
        <button type="button" class="notif-bell" id="notif-toggle" aria-label="Notifications" aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <?php if ($unread > 0): ?>
                <span id="notif-badge" class="notif-badge"><?= $unread > 9 ? '9+' : (int) $unread ?></span>
            <?php else: ?>
                <span id="notif-badge" class="notif-badge" style="display:none;"></span>
            <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notif-dropdown" hidden>
            <div class="notif-dropdown-head">
                <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Notifications</strong>
                    <?php if ($unread > 0): ?>
                        <span id="notif-bell-new-badge" style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:rgba(199,255,34,0.15);color:var(--lime);"><?= (int) $unread ?> new</span>
                    <?php else: ?>
                        <span id="notif-bell-new-badge" style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:rgba(199,255,34,0.15);color:var(--lime);display:none;"></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <button type="button" class="btn-sound-toggle notif-sound-btn" aria-label="Toggle notification sounds" title="Sound: Enabled (Click to mute)" style="background:transparent;border:none;color:var(--muted);cursor:pointer;padding:4px 6px;border-radius:6px;display:inline-flex;align-items:center;transition:all 0.2s;" onmouseover="this.style.color='var(--ink)'" onmouseout="this.style.color='var(--muted)'">
                        <span class="sound-toggle-icon"></span>
                    </button>
                    <form method="post" action="index.php?page=notification_action" class="notif-mark-all" id="notif-mark-all-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="notification_action" value="mark_all_read">
                        <input type="hidden" name="return_page" value="<?= h($currentPage) ?>">
                        <button type="button" id="notif-mark-all-btn" <?= $unread > 0 ? '' : 'disabled class="is-disabled"' ?>>
                            <?= $unread > 0 ? 'Mark all as read' : 'All read' ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php if (!$items): ?>
                <p class="notif-empty">No notifications yet.</p>
            <?php else: ?>
                <ul class="notif-menu">
                    <?php foreach ($items as $item):
                        $hasLink = in_array($item['type'], ['coach_message', 'class_reminder', 'renewal_reminder', 'milestone'], true);
                        $clickUrl = $hasLink ? 'index.php?page=notification_click&nid=' . (int) $item['notification_id'] : null;
                    ?>
                        <li class="<?= $item['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-menu-meta">
                                <span class="notif-type notif-type-<?= h($item['type']) ?>"><?= h(notification_type_label($item['type'])) ?></span>
                                <time><?= h(notification_time_ago($item['created_at'])) ?></time>
                            </div>
                            <?php if ($clickUrl): ?>
                                <a href="<?= h($clickUrl) ?>" class="notif-menu-link">
                                    <strong><?= h($item['title']) ?></strong>
                                    <p><?= h($item['message']) ?></p>
                                </a>
                            <?php else: ?>
                                <strong><?= h($item['title']) ?></strong>
                                <p><?= h($item['message']) ?></p>
                            <?php endif; ?>
                            <div class="notif-item-footer">
                                <?php if (!$item['is_read']): ?>
                                    <button type="button" class="notif-mark-read-btn"
                                        data-notif-id="<?= (int) $item['notification_id'] ?>"
                                        data-csrf="<?= h(csrf_token()) ?>">
                                        Mark read
                                    </button>
                                <?php else: ?>
                                    <span class="notif-read-status">✓ Read</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
    <a class="notif-view-all" href="index.php?page=notifications">View all notifications</a>
        </div>
    </div>
    <script>
    (function() {
        const bellBadge    = document.getElementById('notif-badge');
        const newBadge     = document.getElementById('notif-bell-new-badge');
        const markAllBtn   = document.getElementById('notif-mark-all-btn');
        const markAllForm  = document.getElementById('notif-mark-all-form');
        const csrfToken    = markAllForm ? markAllForm.querySelector('[name="csrf_token"]').value : '';

        function updateBadges(unreadCount) {
            // Main bell badge (header button)
            if (bellBadge) {
                if (unreadCount > 0) {
                    bellBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                    bellBadge.style.display = '';
                } else {
                    bellBadge.style.display = 'none';
                }
            }
            // Dropdown header "X new" badge
            if (newBadge) {
                if (unreadCount > 0) {
                    newBadge.textContent = unreadCount + ' new';
                    newBadge.style.display = '';
                } else {
                    newBadge.style.display = 'none';
                }
            }
            // Mark-all button state
            if (markAllBtn) {
                markAllBtn.disabled = unreadCount === 0;
                markAllBtn.textContent = unreadCount > 0 ? 'Mark all as read' : 'All read';
                markAllBtn.classList.toggle('is-disabled', unreadCount === 0);
            }
        }

        async function ajaxNotifAction(payload) {
            try {
                const body = new URLSearchParams(payload);
                const res = await fetch('index.php?page=notifications', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest',
                               'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                return await res.json();
            } catch (_) { return null; }
        }

        // ── Mark single notification read ───────────────────────────
        document.querySelectorAll('.notif-mark-read-btn[data-notif-id]').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                const notifId = btn.dataset.notifId;
                const csrf    = btn.dataset.csrf;
                const li = btn.closest('li');
                btn.disabled = true;
                btn.textContent = '...';
                const data = await ajaxNotifAction({
                    notification_action: 'mark_read',
                    notification_id: notifId,
                    csrf_token: csrf
                });
                if (data && data.success) {
                    if (li) li.classList.remove('unread');
                    const footer = btn.closest('.notif-item-footer');
                    if (footer) {
                        const readSpan = document.createElement('span');
                        readSpan.className = 'notif-read-status';
                        readSpan.textContent = '✓ Read';
                        footer.replaceChild(readSpan, btn);
                    }
                    updateBadges(data.unread_count);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Mark read';
                }
            });
        });

        // ── Mark all read ──────────────────────────────────────────
        if (markAllBtn) {
            markAllBtn.addEventListener('click', async function() {
                if (markAllBtn.disabled) return;
                markAllBtn.disabled = true;
                markAllBtn.textContent = '...';
                const data = await ajaxNotifAction({
                    notification_action: 'mark_all_read',
                    csrf_token: csrfToken
                });
                if (data && data.success) {
                    document.querySelectorAll('.notif-menu li.unread').forEach(function(li) {
                        li.classList.remove('unread');
                        const btn2 = li.querySelector('.notif-mark-read-btn');
                        if (btn2) {
                            const footer = btn2.closest('.notif-item-footer');
                            if (footer) {
                                const s = document.createElement('span');
                                s.className = 'notif-read-status';
                                s.textContent = '✓ Read';
                                footer.replaceChild(s, btn2);
                            }
                        }
                    });
                    updateBadges(0);
                } else {
                    markAllBtn.disabled = false;
                    markAllBtn.textContent = 'Mark all as read';
                }
            });
        }

        // ── 30-second badge polling ─────────────────────────────────
        let lastUnreadCount = <?= (int) $unread ?>;
        setInterval(async function() {
            if (document.hidden) return;
            const data = await ajaxNotifAction({
                notification_action: 'fetch_notifications',
                csrf_token: csrfToken
            });
            if (data && typeof data.unread === 'number') {
                if (data.unread > lastUnreadCount) {
                    if (window.playNotifSound) {
                        window.playNotifSound('chime');
                    }
                }
                lastUnreadCount = data.unread;
                updateBadges(data.unread);
            }
        }, 30000);
    })();
    </script>
    <?php
}

function render_pagination(int $page, int $totalPages, string $baseUrl, string $paramName = 'p'): void
{
    if ($totalPages <= 1) return;

    $sep = str_contains($baseUrl, '?') ? '&' : '?';

    echo '<div class="pagination">';

    // Previous
    if ($page > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page - 1)) . '" class="page-link">← Prev</a>';
    } else {
        echo '<span class="page-link disabled">← Prev</span>';
    }

    // Page numbers with ellipsis
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);

    if ($start > 1) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=1') . '" class="page-link">1</a>';
        if ($start > 2) echo '<span class="page-ellipsis">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="page-current">' . $i . '</span>';
        } else {
            echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $i) . '" class="page-link">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="page-ellipsis">…</span>';
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . $totalPages) . '" class="page-link">' . $totalPages . '</a>';
    }

    // Next
    if ($page < $totalPages) {
        echo '<a href="' . h($baseUrl . $sep . $paramName . '=' . ($page + 1)) . '" class="page-link">Next →</a>';
    } else {
        echo '<span class="page-link disabled">Next →</span>';
    }

    echo '</div>';
}

function render_registration_form(): void
{
    // Kept for backward compatibility — register.php now renders inline
    // but other callers may still reference this
    ?>
    <div class="auth-card">
        <div class="auth-card-header">
            <h1 class="auth-title">FITTRACKS</h1>
            <p class="auth-subtitle">Create your account</p>
        </div>
        <form method="post" class="auth-form" novalidate>
            <?= csrf_field() ?>
            <div class="auth-form-row">
                <div class="auth-field">
                    <label>FIRST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="first_name" required placeholder="First name"
                               oninvalid="this.setCustomValidity('Please enter your first name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
                <div class="auth-field">
                    <label>LAST NAME</label>
                    <div class="auth-input-group">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input name="last_name" required placeholder="Last name"
                               oninvalid="this.setCustomValidity('Please enter your last name.')"
                               oninput="this.setCustomValidity('')">
                    </div>
                </div>
            </div>

            <div class="auth-field">
                <label>EMAIL ADDRESS</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input type="email" name="email" required placeholder="Enter your email"
                           oninvalid="this.setCustomValidity('Please enter a valid email address.')"
                           oninput="this.setCustomValidity('')">
                </div>
            </div>

            <div class="auth-field">
                <label>MOBILE NUMBER *</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" required placeholder="09123456789"
                           title="Please enter exactly 11 digits"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)">
                </div>
            </div>

            <div class="auth-field">
                <label>PASSWORD</label>
                <div class="auth-input-group">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters"
                           oninput="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : '')"
                           oninvalid="this.setCustomValidity(this.value.length < 8 ? 'Password must be at least 8 characters.' : 'Please enter a password.')">
                </div>
            </div>

            <button type="submit" class="auth-submit-btn">CREATE ACCOUNT <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg></button>

            <div class="auth-form-footer">
                Already have an account? <a href="index.php?page=login">Sign in</a>
            </div>
        </form>
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// SKELETON LOADING HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Renders skeleton stat cards (dashboard KPI cards)
 */
function render_skeleton_stats(int $count = 4): void
{
    echo '<div class="skeleton-wrapper"><section class="dash-grid stats-row">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card sk-rect stat sk">';
        echo '<div class="sk sk-text short" style="margin-bottom:18px"></div>';
        echo '<div class="sk sk-title" style="height:32px;width:40%;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text short" style="height:12px;margin-top:12px"></div>';
        echo '</div>';
    }
    echo '</section></div>';
}

/**
 * Renders a skeleton table with header bar + rows
 */
function render_skeleton_table(int $cols = 6, int $rows = 8): void
{
    echo '<div class="skeleton-wrapper">';
    // Header bar
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px 0">';
    echo '<div class="sk sk-title" style="width:120px;margin:0"></div>';
    echo '<div class="sk sk-text short" style="width:60px;margin:0;height:12px"></div>';
    echo '</div>';
    // Table header
    echo '<div class="sk-table-row" style="border-bottom:2px solid var(--line)">';
    for ($c = 0; $c < $cols; $c++) {
        $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
        echo '<div class="sk sk-cell ' . $cls . '"></div>';
    }
    echo '</div>';
    // Table rows
    for ($r = 0; $r < $rows; $r++) {
        echo '<div class="sk-table-row">';
        for ($c = 0; $c < $cols; $c++) {
            $cls = $c === 0 ? 'wide' : ($c === $cols - 1 ? 'narrow' : '');
            echo '<div class="sk sk-cell ' . $cls . '"></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Renders skeleton list items with avatar + text lines
 */
function render_skeleton_list(int $rows = 5, string $title = ''): void
{
    echo '<div class="skeleton-wrapper">';
    if ($title) {
        echo '<div class="sk sk-title" style="width:160px;margin-bottom:14px"></div>';
    }
    echo '<div class="list-stack" style="gap:10px">';
    for ($i = 0; $i < $rows; $i++) {
        echo '<div class="sk-list-item">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div class="sk-list-item-lines">';
        echo '<div class="sk sk-text medium" style="margin:0"></div>';
        echo '<div class="sk sk-text short" style="margin:0;height:11px"></div>';
        echo '</div>';
        echo '<div class="sk sk-text" style="width:60px;margin:0;height:11px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a skeleton chart placeholder
 */
function render_skeleton_chart(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk-card" style="min-height:308px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:18px">';
    echo '<div><div class="sk sk-title" style="width:140px;margin-bottom:6px"></div>';
    echo '<div class="sk sk-text short" style="height:11px"></div></div>';
    echo '<div class="sk sk-text" style="width:50px;height:24px;border-radius:999px;margin:0"></div>';
    echo '</div>';
    echo '<div class="sk sk-rect chart"></div>';
    echo '</div></div>';
}

/**
 * Renders skeleton cards in a grid
 */
function render_skeleton_cards(int $count = 6): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card-grid">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-card">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:14px">';
        echo '<div class="sk sk-text" style="width:60px;height:20px;margin:0;border-radius:99px"></div>';
        echo '</div>';
        echo '<div class="sk sk-title" style="width:70%;margin-bottom:14px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '<div class="sk sk-text medium" style="height:12px"></div>';
        echo '<div class="sk sk-text full" style="height:12px;margin-top:14px"></div>';
        echo '<div style="margin-top:16px"><div class="sk sk-rect" style="height:38px;border-radius:8px"></div></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders a chat skeleton (sidebar + messages)
 */
function render_skeleton_chat(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<section class="panel wide" style="padding:0;display:flex;height:calc(100vh - 120px);min-height:500px;overflow:hidden;border:1px solid var(--line)">';
    // Sidebar
    echo '<div style="width:300px;border-right:1px solid var(--line);display:flex;flex-direction:column">';
    echo '<div style="padding:20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">';
    echo '<div class="sk sk-title" style="width:100px;margin:0"></div>';
    echo '<div class="sk sk-circle" style="width:24px;height:24px"></div>';
    echo '</div>';
    for ($i = 0; $i < 5; $i++) {
        echo '<div style="display:flex;gap:12px;padding:15px 20px;border-bottom:1px solid var(--line);align-items:center">';
        echo '<div class="sk sk-circle"></div>';
        echo '<div style="flex:1"><div class="sk sk-text medium" style="margin:0 0 6px"></div>';
        echo '<div class="sk sk-text short" style="height:11px;margin:0"></div></div>';
        echo '</div>';
    }
    echo '</div>';
    // Chat area
    echo '<div style="flex:1;display:flex;flex-direction:column">';
    echo '<div style="padding:15px 20px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center">';
    echo '<div class="sk sk-circle"></div>';
    echo '<div><div class="sk sk-text" style="width:120px;margin:0 0 4px"></div>';
    echo '<div class="sk sk-text" style="width:60px;height:11px;margin:0"></div></div>';
    echo '</div>';
    echo '<div style="flex:1;padding:20px;display:flex;flex-direction:column;gap:16px">';
    $bubbles = [
        ['left', '180px', '36px'],
        ['right', '220px', '48px'],
        ['left', '260px', '36px'],
        ['right', '140px', '36px'],
        ['left', '200px', '48px'],
        ['right', '180px', '36px'],
    ];
    foreach ($bubbles as $b) {
        echo '<div class="sk sk-chat-bubble ' . $b[0] . '" style="width:' . $b[1] . ';height:' . $b[2] . '"></div>';
    }
    echo '</div>';
    echo '<div style="padding:15px 20px;border-top:1px solid var(--line)">';
    echo '<div class="sk sk-rect" style="height:44px;border-radius:22px"></div>';
    echo '</div>';
    echo '</div>';
    echo '</section></div>';
}

/**
 * Renders skeleton notification items
 */
function render_skeleton_notifications(int $count = 5): void
{
    echo '<div class="skeleton-wrapper"><div class="notif-list">';
    for ($i = 0; $i < $count; $i++) {
        echo '<div class="sk-notif-item">';
        echo '<div style="display:flex;justify-content:space-between;margin-bottom:10px">';
        echo '<div class="sk sk-text" style="width:70px;height:20px;margin:0;border-radius:99px"></div>';
        echo '<div class="sk sk-text" style="width:50px;height:12px;margin:0"></div>';
        echo '</div>';
        echo '<div class="sk sk-text medium" style="height:15px;margin-bottom:8px"></div>';
        echo '<div class="sk sk-text full" style="height:12px"></div>';
        echo '</div>';
    }
    echo '</div></div>';
}

/**
 * Renders skeleton for profile page
 */
function render_skeleton_profile(): void
{
    echo '<div class="skeleton-wrapper"><div class="sk-card" style="text-align:center;padding:32px">';
    echo '<div class="sk sk-circle lg" style="margin:0 auto 16px"></div>';
    echo '<div class="sk sk-title" style="width:40%;margin:0 auto 8px"></div>';
    echo '<div class="sk sk-text short" style="margin:0 auto 20px"></div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:400px;margin:0 auto">';
    for ($i = 0; $i < 4; $i++) {
        echo '<div class="sk sk-rect" style="height:42px;border-radius:7px"></div>';
    }
    echo '</div></div></div>';
}

/**
 * Renders a skeleton banner (welcome section)
 */
function render_skeleton_banner(): void
{
    echo '<div class="skeleton-wrapper">';
    echo '<div class="sk sk-rect banner" style="margin-bottom:24px"></div>';
    echo '</div>';
}

