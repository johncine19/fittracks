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

    // Set peak hours range (day, week, month)
    function actSetRange(range) {
        if (!['day', 'week', 'month'].includes(range)) range = 'day';
        actCurrentRange = range;

        ['day', 'week', 'month'].forEach(r => {
            const btn = document.getElementById('act-range-' + r);
            if (btn) {
                if (r === range) btn.classList.add('active');
                else btn.classList.remove('active');
            }
        });

        const dateInput = document.querySelector('.act-date-picker-native');
        const selectedDate = dateInput ? dateInput.value : '';
        actFetchData(selectedDate, actCurrentRange);
    }

    // Quick Date Pills (Today, Yesterday)
    function actSetQuickDate(type) {
        const today = new Date();
        let targetDate = new Date(today);

        if (type === 'yesterday') {
            targetDate.setDate(today.getDate() - 1);
        }

        const yyyy = targetDate.getFullYear();
        const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
        const dd = String(targetDate.getDate()).padStart(2, '0');
        const dateStr = `${yyyy}-${mm}-${dd}`;

        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = dateStr);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + dateStr + ')');

        actFetchData(dateStr, actCurrentRange);
    }

    // Prev / Next Day navigation
    function actStepDate(offset) {
        const dateInput = document.querySelector('.act-date-picker-native');
        if (!dateInput || !dateInput.value) return;

        const parts = dateInput.value.split('-');
        if (parts.length !== 3) return;

        const current = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        current.setDate(current.getDate() + offset);

        const today = new Date();
        today.setHours(23, 59, 59, 999);
        if (current > today) return; // Prevent picking future date

        const yyyy = current.getFullYear();
        const mm = String(current.getMonth() + 1).padStart(2, '0');
        const dd = String(current.getDate()).padStart(2, '0');
        const dateStr = `${yyyy}-${mm}-${dd}`;

        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = dateStr);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + dateStr + ')');
        actFetchData(dateStr, actCurrentRange);
    }

    // When the user changes date via calendar icon picker
    function actOnDateChange(changedInput) {
        const val = changedInput ? changedInput.value : (document.querySelector('.act-date-picker-native') ? document.querySelector('.act-date-picker-native').value : '');
        if (!val) return;
        document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = val);
        document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + val + ')');
        actFetchData(val, actCurrentRange);
    }

    // Manual Refresh button
    function actReloadData(btn) {
        const dateInput = document.querySelector('.act-date-picker-native');
        const selectedDate = dateInput ? dateInput.value : '';
        actFetchData(selectedDate, actCurrentRange, btn);
    }

    // Fetch Activity & Peak Hours data via AJAX
    function actFetchData(date, range, triggerBtn) {
        document.querySelectorAll('.act-refresh-btn').forEach(b => b.classList.add('loading'));

        const params = new URLSearchParams({
            page: 'dashboard',
            action: 'attendance_activity_api',
            date: date || '',
            range: range || 'day'
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
        // 1. Date input & calendar trigger button
        if (data.date) {
            document.querySelectorAll('.act-date-picker-native').forEach(el => el.value = data.date);
            document.querySelectorAll('.act-calendar-btn').forEach(btn => btn.title = 'Select Date (' + data.date + ')');
        }

        const todayStr = (new Date()).toISOString().split('T')[0];
        const yesterdayObj = new Date();
        yesterdayObj.setDate(yesterdayObj.getDate() - 1);
        const yesterdayStr = yesterdayObj.toISOString().split('T')[0];

        const todayBtn = document.getElementById('act-btn-today');
        const yesterdayBtn = document.getElementById('act-btn-yesterday');
        if (todayBtn) {
            if (data.date === todayStr) todayBtn.classList.add('active');
            else todayBtn.classList.remove('active');
        }
        if (yesterdayBtn) {
            if (data.date === yesterdayStr) yesterdayBtn.classList.add('active');
            else yesterdayBtn.classList.remove('active');
        }

        const nextBtn = document.getElementById('act-next-day-btn');
        if (nextBtn) {
            nextBtn.disabled = (data.date >= todayStr);
        }

        // Date labels
        document.querySelectorAll('.act-date-formatted-label').forEach(el => {
            el.textContent = data.date_formatted;
        });
        const shortDateLabel = document.getElementById('act-stat-date-label');
        if (shortDateLabel && data.date) {
            const dObj = new Date(data.date + 'T00:00:00');
            shortDateLabel.textContent = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
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
                if (data.range === 'week') {
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
            if (data.range === 'week') {
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
                                    <div>
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

        // Auto-refresh timer every 60s when on today's date
        setInterval(() => {
            const activeTabBtn = document.querySelector('.member-dash-tab.active');
            const dateInput = document.getElementById('act-date-input');
            const todayStr = (new Date()).toISOString().split('T')[0];
            if (activeTabBtn && activeTabBtn.id === 'tab-btn-activity' && dateInput && dateInput.value === todayStr) {
                actFetchData(todayStr, actCurrentRange);
            }
        }, 60000);
    });
