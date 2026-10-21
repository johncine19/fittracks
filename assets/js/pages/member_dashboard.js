/**
 * Member Dashboard Controller
 * Extracted from member/dashboard.php
 */

// Toggle Missions expansion
function toggleMissions() {
    const hiddenMissions = document.querySelectorAll('.hidden-mission');
    const btn = document.getElementById('toggle-missions-btn');
    if (!hiddenMissions.length) return;
    
    const isCurrentlyHidden = hiddenMissions[0].style.display === 'none';
    
    hiddenMissions.forEach(m => {
        m.style.display = isCurrentlyHidden ? 'flex' : 'none';
    });
    
    btn.innerHTML = isCurrentlyHidden ? 'Show Less' : 'Show All Missions';
}
window.toggleMissions = toggleMissions;

// Tab Switching & Activity Dashboard Controller
let actCurrentData = (window.MEMBER_DASHBOARD_CONFIG && window.MEMBER_DASHBOARD_CONFIG.initialData)
    ? window.MEMBER_DASHBOARD_CONFIG.initialData
    : {};
let actCurrentRange = 'day';
let actCurrentStartDate = (actCurrentData && actCurrentData.start_date)
    ? actCurrentData.start_date
    : new Date().toISOString().split('T')[0];
let actCurrentEndDate = (actCurrentData && actCurrentData.end_date)
    ? actCurrentData.end_date
    : new Date().toISOString().split('T')[0];
let actCurrentPreset = 'today';
window.actPeakChart = null;
let actAutoRefreshTimer = null;

    function switchMemberDashboardTab(tabId) {
        const tabs = ['today', 'activity', 'missions', 'explore'];
        if (!tabs.includes(tabId)) tabId = 'today';

        tabs.forEach(t => {
            const btn = document.getElementById('tab-btn-' + t);
            const panel = document.getElementById('panel-' + t);
            if (btn) {
                if (t === tabId) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            }
            if (panel) {
                if (t === tabId) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            }
        });

        // Save preference in localStorage & URL hash
        try {
            localStorage.setItem('fit_member_dashboard_tab', tabId);
            window.history.replaceState(null, null, '#' + tabId);
        } catch (e) {}

        // When switching to activity, initialize or resize chart & restore subtab
        if (tabId === 'activity') {
            const savedSubtab = localStorage.getItem('fit_activity_subtab') || 'roster';
            actSwitchSubTab(savedSubtab);
        }
    }

    // Switch between Daily Attendance Overview and Peak Hours Analysis sub-tabs
    function actSwitchSubTab(tab) {
        const validTabs = ['roster', 'peak'];
        if (!validTabs.includes(tab)) tab = 'roster';

        validTabs.forEach(t => {
            const btn = document.getElementById('act-subtab-' + t);
            const panel = document.getElementById('act-subpanel-' + t);
            if (btn) {
                if (t === tab) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                } else {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                }
            }
            if (panel) {
                if (t === tab) {
                    panel.classList.add('active');
                } else {
                    panel.classList.remove('active');
                }
            }
        });

        try {
            localStorage.setItem('fit_activity_subtab', tab);
        } catch (e) {}

        if (tab === 'peak') {
            setTimeout(() => {
                if (!window.actPeakChart && actCurrentData && actCurrentData.chart) {
                    initOrUpdateActivityChart(actCurrentData.chart);
                } else if (window.actPeakChart) {
                    window.actPeakChart.resize();
                }
            }, 50);
        }
    }

    // Initialize or re-render Peak Hours Chart.js
    function initOrUpdateActivityChart(chartData) {
        const canvas = document.getElementById('act-peak-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        const isLight = document.documentElement.getAttribute('data-theme') === 'light' || 
                        document.body.getAttribute('data-theme') === 'light';

        const peakBg = isLight ? '#16a34a' : '#c7ff22';
        const peakBorder = isLight ? '#15803d' : '#e6ff70';
        const normBg = isLight ? 'rgba(13, 148, 136, 0.45)' : 'rgba(45, 212, 191, 0.35)';
        const normBorder = isLight ? 'rgba(13, 148, 136, 0.8)' : 'rgba(45, 212, 191, 0.7)';
        const textColor = isLight ? '#475569' : '#94a3b8';
        const gridColor = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.06)';

        const bgColors = [];
        const borderColors = [];
        const peakIndices = chartData.peak_indices || [];

        chartData.data.forEach((val, idx) => {
            if (peakIndices.includes(idx) && val > 0) {
                bgColors.push(peakBg);
                borderColors.push(peakBorder);
            } else {
                bgColors.push(normBg);
                borderColors.push(normBorder);
            }
        });

        if (window.actPeakChart) {
            window.actPeakChart.destroy();
            window.actPeakChart = null;
        }

        const maxVal = Math.max(3, ...(chartData.data || [0]));

        window.actPeakChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Check-ins',
                    data: chartData.data,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 38
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 400 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isLight ? 'rgba(15, 23, 42, 0.95)' : 'rgba(15, 20, 30, 0.96)',
                        titleColor: isLight ? '#f8fafc' : '#ffffff',
                        bodyColor: isLight ? '#e2e8f0' : '#cbd5e1',
                        borderColor: isLight ? '#334155' : 'rgba(255, 255, 255, 0.12)',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.parsed.y;
                                const isPeak = peakIndices.includes(ctx.dataIndex) && val > 0;
                                return ' ' + val + (val === 1 ? ' member checked in' : ' members checked in') + (isPeak ? ' (★ Peak Rush)' : '');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: maxVal + 1,
                        ticks: {
                            color: textColor,
                            font: { family: "'Inter', system-ui, sans-serif", size: 11, weight: '600' },
                            precision: 0,
                            stepSize: 1
                        },
                        grid: {
                            color: gridColor,
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: textColor,
                            font: { family: "'Inter', system-ui, sans-serif", size: 11, weight: '600' },
                            maxRotation: 45,
                            minRotation: 0
                        },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Format Date object to YYYY-MM-DD
    function actFormatDateYmd(d) {
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    // Toggle Range Dropdown Popover
    function actToggleRangeDropdown(e, triggerBtn) {
        if (e) {
            e.stopPropagation();
        }
        const wrap = triggerBtn ? triggerBtn.closest('.act-range-picker-wrap') : null;
        if (!wrap) return;

        const dropdown = wrap.querySelector('.act-range-dropdown');
        if (!dropdown) return;

        const isOpen = dropdown.style.display !== 'none';

        // Close any other open dropdowns first
        document.querySelectorAll('.act-range-dropdown').forEach(d => {
            if (d !== dropdown) d.style.display = 'none';
        });
        document.querySelectorAll('.act-range-trigger-btn').forEach(b => {
            if (b !== triggerBtn) {
                b.setAttribute('aria-expanded', 'false');
                b.classList.remove('open');
            }
        });

        if (isOpen) {
            dropdown.style.display = 'none';
            triggerBtn.setAttribute('aria-expanded', 'false');
            triggerBtn.classList.remove('open');
        } else {
            dropdown.style.display = 'block';
            triggerBtn.setAttribute('aria-expanded', 'true');
            triggerBtn.classList.add('open');

            // Sync input values to current range
            const startInput = dropdown.querySelector('.act-start-date-input');
            const endInput = dropdown.querySelector('.act-end-date-input');
            if (startInput && actCurrentStartDate) startInput.value = actCurrentStartDate;
            if (endInput && actCurrentEndDate) endInput.value = actCurrentEndDate;
        }
    }

    // Close all range dropdowns
    function actCloseAllRangeDropdowns(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        document.querySelectorAll('.act-range-dropdown').forEach(d => d.style.display = 'none');
        document.querySelectorAll('.act-range-trigger-btn').forEach(b => {
            b.setAttribute('aria-expanded', 'false');
            b.classList.remove('open');
        });
    }

    // Apply Quick Preset (Today, Yesterday, Last 7 Days, Last 30 Days, This Month)
    function actApplyPreset(preset, triggerEl) {
        const today = new Date();
        let startDateStr = '';
        let endDateStr = actFormatDateYmd(today);

        if (preset === 'today') {
            startDateStr = endDateStr;
        } else if (preset === 'yesterday') {
            const yesterday = new Date(today);
            yesterday.setDate(today.getDate() - 1);
            startDateStr = actFormatDateYmd(yesterday);
            endDateStr = startDateStr;
        } else if (preset === 'week') {
            const weekAgo = new Date(today);
            weekAgo.setDate(today.getDate() - 6);
            startDateStr = actFormatDateYmd(weekAgo);
        } else if (preset === 'month') {
            const monthAgo = new Date(today);
            monthAgo.setDate(today.getDate() - 29);
            startDateStr = actFormatDateYmd(monthAgo);
        } else if (preset === 'this_month') {
            const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            startDateStr = actFormatDateYmd(firstOfMonth);
        } else {
            startDateStr = endDateStr;
            preset = 'today';
        }

        actCurrentPreset = preset;
        actCurrentStartDate = startDateStr;
        actCurrentEndDate = endDateStr;

        // Sync all date inputs across both subpanels
        document.querySelectorAll('.act-start-date-input').forEach(el => el.value = startDateStr);
        document.querySelectorAll('.act-end-date-input').forEach(el => el.value = endDateStr);

        // Highlight preset pills
        document.querySelectorAll('.act-preset-pill').forEach(pill => {
            if (pill.getAttribute('data-preset') === preset) {
                pill.classList.add('active');
            } else {
                pill.classList.remove('active');
            }
        });

        // Close dropdown
        actCloseAllRangeDropdowns();

        // Fetch data
        actFetchData(startDateStr, endDateStr, preset);
    }

    // User edits start or end date directly
    function actOnCustomDateChange(changedInput) {
        const wrap = changedInput ? changedInput.closest('.act-range-picker-wrap') : null;
        if (wrap) {
            wrap.querySelectorAll('.act-preset-pill').forEach(pill => pill.classList.remove('active'));
        }
        actCurrentPreset = 'custom';
    }

    // Apply Range button inside dropdown
    function actApplyRangeFromDropdown(btn) {
        const wrap = btn ? btn.closest('.act-range-picker-wrap') : null;
        if (!wrap) return;

        const startInput = wrap.querySelector('.act-start-date-input');
        const endInput = wrap.querySelector('.act-end-date-input');

        let sVal = startInput ? startInput.value : '';
        let eVal = endInput ? endInput.value : '';

        const todayStr = actFormatDateYmd(new Date());
        if (!sVal) sVal = eVal || todayStr;
        if (!eVal) eVal = sVal || todayStr;

        // Auto-swap if start > end
        if (sVal > eVal) {
            const temp = sVal;
            sVal = eVal;
            eVal = temp;
        }

        // Cap at today
        if (sVal > todayStr) sVal = todayStr;
        if (eVal > todayStr) eVal = todayStr;

        actCurrentStartDate = sVal;
        actCurrentEndDate = eVal;
        actCurrentPreset = 'custom';

        // Sync inputs everywhere
        document.querySelectorAll('.act-start-date-input').forEach(el => el.value = sVal);
        document.querySelectorAll('.act-end-date-input').forEach(el => el.value = eVal);

        // Update active preset pills
        document.querySelectorAll('.act-preset-pill').forEach(pill => {
            pill.classList.remove('active');
        });

        // Check if matching preset
        if (sVal === todayStr && eVal === todayStr) {
            document.querySelectorAll('.act-preset-pill[data-preset="today"]').forEach(p => p.classList.add('active'));
            actCurrentPreset = 'today';
        }

        actCloseAllRangeDropdowns();
        actFetchData(sVal, eVal, 'custom');
    }

    // Legacy helper for quick date pills (Today, Yesterday)
    function actSetQuickDate(type) {
        actApplyPreset(type);
    }

    // Manual Refresh button
    function actReloadData(btn) {
        actFetchData(actCurrentStartDate, actCurrentEndDate, actCurrentRange, btn);
    }

    // Fetch Activity & Peak Hours data via AJAX
    function actFetchData(startDate, endDate, range, triggerBtn) {
        // Handle single date or undefined args
        if (typeof startDate === 'object' && startDate !== null) {
            startDate = actCurrentStartDate;
            endDate = actCurrentEndDate;
        }
        if (!startDate && !endDate) {
            startDate = actCurrentStartDate;
            endDate = actCurrentEndDate;
        } else if (startDate && !endDate) {
            endDate = startDate;
        }

        document.querySelectorAll('.act-refresh-btn').forEach(b => b.classList.add('loading'));

        const params = new URLSearchParams({
            page: 'dashboard',
            action: 'attendance_activity_api',
            start_date: startDate || '',
            end_date: endDate || '',
            date: startDate || '',
            range: range || actCurrentRange || 'day'
        });

        fetch('index.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            actCurrentData = data;
            if (data.start_date) actCurrentStartDate = data.start_date;
            if (data.end_date) actCurrentEndDate = data.end_date;
            actUpdateUI(data);
        })
        .catch(err => {
            console.error('Failed to load attendance activity data:', err);
        })
        .finally(() => {
            document.querySelectorAll('.act-refresh-btn').forEach(b => b.classList.remove('loading'));
        });
    }

    // Update Dashboard DOM with fetched activity data
    function actUpdateUI(data) {
        // 1. Update Date range text & inputs
        const rangeText = data.date_formatted || (data.start_date ? data.start_date + (data.end_date && data.end_date !== data.start_date ? ' – ' + data.end_date : '') : 'Today');

        document.querySelectorAll('.act-range-display-text').forEach(el => {
            el.textContent = rangeText;
        });

        document.querySelectorAll('.act-date-formatted-label').forEach(el => {
            el.textContent = rangeText;
        });

        if (data.start_date) {
            document.querySelectorAll('.act-start-date-input').forEach(el => el.value = data.start_date);
        }
        if (data.end_date) {
            document.querySelectorAll('.act-end-date-input').forEach(el => el.value = data.end_date);
        }

        const todayStr = actFormatDateYmd(new Date());
        const yesterdayObj = new Date();
        yesterdayObj.setDate(yesterdayObj.getDate() - 1);
        const yesterdayStr = actFormatDateYmd(yesterdayObj);

        // Highlight preset pills if matching
        document.querySelectorAll('.act-preset-pill').forEach(pill => {
            const p = pill.getAttribute('data-preset');
            let isMatch = false;
            if (p === 'today' && data.start_date === todayStr && data.end_date === todayStr) {
                isMatch = true;
            } else if (p === 'yesterday' && data.start_date === yesterdayStr && data.end_date === yesterdayStr) {
                isMatch = true;
            }
            if (isMatch) pill.classList.add('active');
            else if (actCurrentPreset !== p) pill.classList.remove('active');
        });

        const shortDateLabel = document.getElementById('act-stat-date-label');
        if (shortDateLabel) {
            if (data.start_date === data.end_date && data.start_date) {
                const dObj = new Date(data.start_date + 'T00:00:00');
                shortDateLabel.textContent = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            } else {
                shortDateLabel.textContent = 'Custom Range';
            }
        }

        // 2. Status filter pill count badges & Peak stat
        const badgeCheckins = document.getElementById('act-badge-total-checkins');
        if (badgeCheckins && data.total_checkins !== undefined) {
            badgeCheckins.textContent = data.total_checkins;
        }

        const badgeInside = document.getElementById('act-badge-inside');
        if (badgeInside && data.currently_inside !== undefined) {
            badgeInside.textContent = data.currently_inside;
        }

        const badgeCheckouts = document.getElementById('act-badge-checkouts');
        if (badgeCheckouts && data.total_checkouts !== undefined) {
            badgeCheckouts.textContent = data.total_checkouts;
        }

        const statPeak = document.getElementById('act-stat-peakhour');
        if (statPeak && data.peak_hour_label) {
            statPeak.textContent = data.peak_hour_label;
            statPeak.title = data.peak_hour_label;
        }

        // 3. Peak Rush banner & Subtitle
        const peakBannerText = document.getElementById('act-peak-banner-text');
        if (peakBannerText) {
            if (data.peak_max_count > 0) {
                let rangePrefix = 'Peak check-ins occurred at ';
                if (data.start_date !== data.end_date) {
                    rangePrefix = 'Across the selected date range, peak rush occurred at ';
                } else if (data.range === 'week') {
                    rangePrefix = 'Over the past 7 days, peak rush occurred at ';
                } else if (data.range === 'month') {
                    rangePrefix = 'Across this month, peak rush occurred at ';
                }
                peakBannerText.innerHTML = '<strong>Peak Traffic Detected:</strong> ' + rangePrefix + '<strong>' + escapeHtml(data.peak_hour_label) + '</strong>. Plan your gym session to avoid busy hours or join the rush!';
            } else {
                peakBannerText.innerHTML = '<strong>No Check-in Activity:</strong> No attendance recorded yet for this time window.';
            }
        }

        const peakSub = document.getElementById('act-peak-panel-sub');
        if (peakSub) {
            if (data.start_date !== data.end_date) {
                peakSub.textContent = 'Hourly traffic distribution aggregated across ' + (data.date_formatted || 'selected dates');
            } else if (data.range === 'week') {
                peakSub.textContent = 'Hourly traffic distribution aggregated over the past 7 days';
            } else if (data.range === 'month') {
                peakSub.textContent = 'Hourly traffic distribution aggregated across the month';
            } else {
                peakSub.textContent = 'Traffic distribution and gym congestion patterns for ' + (data.date_formatted || 'selected date');
            }
        }

        // 4. Update Chart
        if (data.chart) {
            initOrUpdateActivityChart(data.chart);
        }

        // 5. Update Roster Table & Empty State
        const rosterContainer = document.getElementById('act-roster-container');
        const emptyState = document.getElementById('act-empty-state');
        const tbody = document.getElementById('act-roster-tbody');
        const countBadge = document.getElementById('act-roster-count');
        const subtabBadge = document.getElementById('act-subtab-badge');

        const count = data.roster ? data.roster.length : 0;
        if (countBadge) {
            countBadge.textContent = count + (count === 1 ? ' Record' : ' Records');
        }
        if (subtabBadge) {
            subtabBadge.textContent = count;
        }

        if (data.roster && data.roster.length > 0) {
            if (emptyState) emptyState.style.display = 'none';
            if (rosterContainer) rosterContainer.style.display = '';

            if (tbody) {
                let rowsHtml = '';
                data.roster.forEach(m => {
                    const avatarHtml = m.profile_picture ? 
                        `<img src="${escapeHtml(m.profile_picture)}" alt="${escapeHtml(m.name)}" class="act-avatar">` : 
                        `<div class="act-avatar">${escapeHtml((m.name || 'M').charAt(0).toUpperCase())}</div>`;

                    const statusBadgeHtml = m.is_checked_in ? 
                        `<span class="act-status-badge act-status-in"><span class="act-badge-dot"></span>Checked In</span>` : 
                        `<span class="act-status-badge act-status-out"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Checked Out</span>`;

                    const outTimeStyle = m.is_checked_in ? 'color: var(--muted); font-style: italic;' : '';

                    rowsHtml += `
                        <tr data-name="${escapeHtml((m.name || '').toLowerCase())}" data-status="${escapeHtml(m.status)}">
                            <td data-label="Member">
                                <div class="act-member-info">
                                    ${avatarHtml}
                                    <div class="act-member-meta">
                                        <div class="act-member-name">${escapeHtml(m.name)}</div>
                                        <div class="act-member-sub">${escapeHtml(m.email || '')}</div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Check-In">
                                <div class="act-time-pill">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span>${escapeHtml(m.check_in_formatted)}</span>
                                </div>
                            </td>
                            <td data-label="Check-Out">
                                <div class="act-time-pill">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span style="${outTimeStyle}">${escapeHtml(m.check_out_formatted)}</span>
                                </div>
                            </td>
                            <td data-label="Duration">
                                <span class="act-duration-pill">${escapeHtml(m.duration)}</span>
                            </td>
                            <td data-label="Status">
                                ${statusBadgeHtml}
                            </td>
                        </tr>
                    `;
                });

                rowsHtml += `
                    <tr id="act-no-match-row" style="display: none;">
                        <td colspan="5" style="text-align: center; padding: 28px; color: var(--muted);">
                            No members match your search criteria.
                        </td>
                    </tr>
                `;

                tbody.innerHTML = rowsHtml;
            }
        } else {
            if (rosterContainer) rosterContainer.style.display = 'none';
            if (emptyState) emptyState.style.display = '';
        }

        // Re-apply client filter
        actApplyFilter();
    }

    let actCurrentPage = 1;
    let actPageSize = 5;
    let actCurrentStatusFilter = 'all';

    function actSetFilterStatus(status) {
        actCurrentStatusFilter = status;
        const filterInput = document.getElementById('act-status-filter');
        if (filterInput) filterInput.value = status;

        ['all', 'checked_in', 'checked_out'].forEach(s => {
            const btn = document.getElementById('act-pill-' + s);
            if (btn) {
                if (s === status) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                } else {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                }
            }
        });

        actApplyFilter(true);
    }

    function actChangePageSize(size) {
        actPageSize = parseInt(size, 10) || 5;
        actCurrentPage = 1;
        actApplyFilter(false);
    }

    function actGoToPage(page) {
        actCurrentPage = page;
        actApplyFilter(false);
    }

    // Client-side instant filter & pagination for search input & status pills
    function actApplyFilter(resetPage = true) {
        if (resetPage) {
            actCurrentPage = 1;
        }

        const searchInput = document.getElementById('act-search-input');
        const statusSelect = document.getElementById('act-status-filter');
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const st = actCurrentStatusFilter || (statusSelect ? statusSelect.value : 'all');

        const allRows = Array.from(document.querySelectorAll('#act-roster-tbody tr[data-name]'));
        
        // 1. Identify all matching rows
        const matchingRows = allRows.filter(tr => {
            const name = tr.getAttribute('data-name') || '';
            const status = tr.getAttribute('data-status') || '';
            const matchQuery = !q || name.includes(q);
            const matchStatus = (st === 'all') || (status === st);
            return matchQuery && matchStatus;
        });

        const totalMatches = matchingRows.length;
        const totalPages = Math.max(1, Math.ceil(totalMatches / actPageSize));

        // Clamp current page
        if (actCurrentPage > totalPages) actCurrentPage = totalPages;
        if (actCurrentPage < 1) actCurrentPage = 1;

        // 2. Hide all non-matching rows and apply pagination to matching rows
        const startIndex = (actCurrentPage - 1) * actPageSize;
        const endIndex = Math.min(startIndex + actPageSize, totalMatches);

        allRows.forEach(tr => {
            tr.classList.add('act-row-hidden');
            tr.style.setProperty('display', 'none', 'important');
        });

        for (let i = startIndex; i < endIndex; i++) {
            if (matchingRows[i]) {
                matchingRows[i].classList.remove('act-row-hidden');
                matchingRows[i].style.removeProperty('display');
                matchingRows[i].style.display = '';
            }
        }

        // 3. No match message
        const noMatchRow = document.getElementById('act-no-match-row');
        if (noMatchRow) {
            const shouldShow = (allRows.length > 0 && totalMatches === 0);
            noMatchRow.style.setProperty('display', shouldShow ? 'block' : 'none', 'important');
        }

        // 4. Update pagination controls
        actRenderPagination(totalMatches, totalPages, startIndex, endIndex);
    }

    function actRenderPagination(totalMatches, totalPages, startIndex, endIndex) {
        const paginationEl = document.getElementById('act-pagination');
        if (!paginationEl) return;

        if (totalMatches === 0) {
            paginationEl.style.display = 'none';
            return;
        }

        paginationEl.style.display = 'flex';

        const startEl = document.getElementById('act-page-start');
        const endEl = document.getElementById('act-page-end');
        const totalEl = document.getElementById('act-page-total');

        if (startEl) startEl.textContent = totalMatches > 0 ? (startIndex + 1) : 0;
        if (endEl) endEl.textContent = endIndex;
        if (totalEl) totalEl.textContent = totalMatches;

        const nav = document.getElementById('act-pagination-nav');
        if (!nav) return;

        let navHtml = '';

        // Prev Button
        const prevDisabled = (actCurrentPage <= 1) ? 'disabled' : '';
        navHtml += `
            <button type="button" class="act-page-btn" onclick="actGoToPage(${actCurrentPage - 1})" ${prevDisabled} title="Previous Page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 19l-7-7 7-7"/></svg>
            </button>
        `;

        // Page Numbers
        for (let p = 1; p <= totalPages; p++) {
            if (totalPages > 7) {
                if (p !== 1 && p !== totalPages && Math.abs(p - actCurrentPage) > 1) {
                    if (p === 2 || p === totalPages - 1) {
                        navHtml += `<span class="act-page-dots">...</span>`;
                    }
                    continue;
                }
            }

            const activeClass = (p === actCurrentPage) ? 'active' : '';
            navHtml += `
                <button type="button" class="act-page-btn ${activeClass}" onclick="actGoToPage(${p})">
                    ${p}
                </button>
            `;
        }

        // Next Button
        const nextDisabled = (actCurrentPage >= totalPages) ? 'disabled' : '';
        navHtml += `
            <button type="button" class="act-page-btn" onclick="actGoToPage(${actCurrentPage + 1})" ${nextDisabled} title="Next Page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 5l7 7-7 7"/></svg>
            </button>
        `;

        nav.innerHTML = navHtml;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Restore tab from hash or localStorage on page load
    document.addEventListener('DOMContentLoaded', function() {
        let initialTab = 'today';
        const hash = window.location.hash.replace('#', '');
        const savedTab = localStorage.getItem('fit_member_dashboard_tab');

        if (['today', 'activity', 'missions', 'explore'].includes(hash)) {
            initialTab = hash;
        } else if (['today', 'activity', 'missions', 'explore'].includes(savedTab)) {
            initialTab = savedTab;
        }

        switchMemberDashboardTab(initialTab);
        actApplyFilter();

        // Close range picker popover on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.act-range-picker-wrap')) {
                actCloseAllRangeDropdowns();
            }
        });

        // Auto-refresh timer every 60s when displaying today's single date
        setInterval(() => {
            const activeTabBtn = document.querySelector('.member-dash-tab.active');
            const todayStr = actFormatDateYmd(new Date());
            if (activeTabBtn && activeTabBtn.id === 'tab-btn-activity' && actCurrentStartDate === todayStr && actCurrentEndDate === todayStr) {
                actFetchData(todayStr, todayStr, 'today');
            }
        }, 60000);
    });

    // Window Exports for HTML Event Handlers
    window.switchMemberDashboardTab = switchMemberDashboardTab;
    window.actSwitchSubTab = actSwitchSubTab;
    window.actToggleRangeDropdown = actToggleRangeDropdown;
    window.actCloseAllRangeDropdowns = actCloseAllRangeDropdowns;
    window.actApplyPreset = actApplyPreset;
    window.actOnCustomDateChange = actOnCustomDateChange;
    window.actApplyRangeFromDropdown = actApplyRangeFromDropdown;
    window.actSetQuickDate = actSetQuickDate;
    window.actReloadData = actReloadData;
    window.actFetchData = actFetchData;
    window.actSetFilterStatus = actSetFilterStatus;
    window.actChangePageSize = actChangePageSize;
    window.actGoToPage = actGoToPage;
    window.actApplyFilter = actApplyFilter;
