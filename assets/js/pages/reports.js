/**
 * Reports & Analytics Controller
 * Extracted from reports.php
 */
    const chartsData = window.REPORTS_CONFIG?.chartsData || {};
    let platformSaaSChartInstance = null;
    let platformSaaSMixChartInstance = null;
    let revenueChartInstance = null;
    let revenueMixChartInstance = null;
    let attendanceChartInstance = null;
    let hourlyChartInstance = null;
    let dayOfWeekChartInstance = null;
    let walkinChartInstance = null;
    let engagementChartInstance = null;

    // Timeframe hints mapping
    const tfHints = {
        daily: 'Viewing last 14 days rolling window',
        monthly: 'Viewing last 12 months rolling run-rate',
        yearly: 'Viewing last 5 fiscal years annual trend'
    };

    window.showTab = function(tabId, updateHistory = true) {
        document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.report-tab-btn').forEach(el => el.classList.remove('active'));
        
        const target = document.getElementById(tabId);
        if (target) target.style.display = 'block';
        
        const activeBtn = document.querySelector(`.report-tab-btn[data-tab="${tabId}"]`) || 
                          document.querySelector(`.report-tab-btn[onclick*="${tabId}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        if (window.reportTabDropdown && window.reportTabDropdown.selectedItem && String(window.reportTabDropdown.selectedItem.id) !== String(tabId)) {
            window.reportTabDropdown.select(tabId, false);
        }

        if (updateHistory) {
            try {
                history.replaceState(null, '', '#' + tabId);
                sessionStorage.setItem('fittrack_reports_tab', tabId);
            } catch (e) {}
        }

        // Trigger chart resizes for smooth un-hidden rendering
        setTimeout(() => {
            if (tabId === 'platform-saas-tab') {
                if (platformSaaSChartInstance) platformSaaSChartInstance.resize();
                if (platformSaaSMixChartInstance) platformSaaSMixChartInstance.resize();
            } else if (tabId === 'revenue-tab') {
                if (revenueChartInstance) revenueChartInstance.resize();
                if (revenueMixChartInstance) revenueMixChartInstance.resize();
            } else if (tabId === 'attendance-tab') {
                if (attendanceChartInstance) attendanceChartInstance.resize();
                if (hourlyChartInstance) hourlyChartInstance.resize();
                if (dayOfWeekChartInstance) dayOfWeekChartInstance.resize();
            } else if (tabId === 'walkin-tab') {
                if (walkinChartInstance) walkinChartInstance.resize();
            } else if (tabId === 'engagement-tab') {
                if (engagementChartInstance) {
                    engagementChartInstance.resize();
                    engagementChartInstance.update();
                }
            }
        }, 60);
    };

    window.setTimeframe = function(type, timeframe) {
        document.querySelectorAll('.tf-btn-' + type).forEach(el => el.classList.remove('active'));
        const btn = document.getElementById('tf-' + type + '-' + timeframe);
        if (btn) btn.classList.add('active');

        const hintText = document.getElementById('tf-hint-text-' + type);
        if (hintText) {
            if (window.REPORTS_CONFIG?.isCustomDateRange) {
                const label = document.querySelector('.report-range-trigger-btn span')?.textContent?.trim() || 'Custom Range';
                hintText.textContent = `Viewing ${label} filtered window`;
            } else if (tfHints[timeframe]) {
                hintText.textContent = tfHints[timeframe];
            }
        }

        ['daily', 'monthly', 'yearly'].forEach(tf => {
            const table = document.getElementById(type + '-table-' + tf);
            if (table) table.style.display = (tf === timeframe) ? 'block' : 'none';
        });

        const csvBtn = document.getElementById('btn-export-' + type + '-csv');
        const printBtn = document.getElementById('btn-export-' + type + '-print');
        const urlParams = new URLSearchParams(window.location.search);
        const sDate = urlParams.get('start_date');
        const eDate = urlParams.get('end_date');
        const gymId = urlParams.get('gym_id');
        let extraParams = '';
        if (gymId) extraParams += '&gym_id=' + encodeURIComponent(gymId);
        if (sDate) extraParams += '&start_date=' + encodeURIComponent(sDate);
        if (eDate) extraParams += '&end_date=' + encodeURIComponent(eDate);

        if (csvBtn) csvBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + extraParams + '&export=csv';
        if (printBtn) printBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + extraParams + '&export=print';

        if (chartsData[type] && chartsData[type][timeframe]) {
            if (type === 'revenue') {
                const subT = chartsData.revenue[timeframe].subTotal;
                const walkT = chartsData.revenue[timeframe].walkTotal;
                const tot = subT + walkT;

                const chartWrap = document.getElementById('revenue-chart-canvas-wrap');
                const chartEmpty = document.getElementById('revenue-chart-empty-state');
                const mixWrap = document.getElementById('revenue-mix-canvas-wrap');
                const mixEmpty = document.getElementById('revenue-mix-empty-state');

                if (tot > 0) {
                    if (chartWrap) chartWrap.style.display = 'block';
                    if (chartEmpty) chartEmpty.style.display = 'none';
                    if (mixWrap) mixWrap.style.display = 'block';
                    if (mixEmpty) mixEmpty.style.display = 'none';
                    if (revenueChartInstance) revenueChartInstance.resize();
                    if (revenueMixChartInstance) revenueMixChartInstance.resize();
                } else {
                    if (chartWrap) chartWrap.style.display = 'none';
                    if (chartEmpty) chartEmpty.style.display = 'flex';
                    if (mixWrap) mixWrap.style.display = 'none';
                    if (mixEmpty) mixEmpty.style.display = 'flex';
                }

                if (revenueChartInstance) {
                    revenueChartInstance.data.labels = chartsData.revenue[timeframe].labels;
                    revenueChartInstance.data.datasets[0].data = chartsData.revenue[timeframe].subscriptions;
                    revenueChartInstance.data.datasets[1].data = chartsData.revenue[timeframe].walkins;
                    revenueChartInstance.update();
                }

                if (revenueMixChartInstance) {
                    revenueMixChartInstance.data.datasets[0].data = [subT, walkT];
                    revenueMixChartInstance.update();
                }

                const subLbl = document.getElementById('mix-sub-label');
                const walkLbl = document.getElementById('mix-walk-label');
                const totBadge = document.getElementById('mix-total-badge');
                if (subLbl) subLbl.textContent = '₱' + subT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                if (walkLbl) walkLbl.textContent = '₱' + walkT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                if (totBadge) {
                    totBadge.textContent = tot > 0 ? ('₱' + tot.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Total') : 'No Data';
                }
            } else if (type === 'attendance') {
                const tot = chartsData.attendance[timeframe].total || 0;
                const attWrap = document.getElementById('attendance-chart-canvas-wrap');
                const attEmpty = document.getElementById('attendance-chart-empty-state');

                if (tot > 0) {
                    if (attWrap) attWrap.style.display = 'block';
                    if (attEmpty) attEmpty.style.display = 'none';
                    if (attendanceChartInstance) attendanceChartInstance.resize();
                } else {
                    if (attWrap) attWrap.style.display = 'none';
                    if (attEmpty) attEmpty.style.display = 'flex';
                }

                if (attendanceChartInstance) {
                    attendanceChartInstance.data.labels = chartsData.attendance[timeframe].labels;
                    attendanceChartInstance.data.datasets[0].data = chartsData.attendance[timeframe].data;
                    attendanceChartInstance.update();
                }
            } else if (type === 'walkin') {
                const tot = chartsData.walkin[timeframe].total || 0;
                const walkWrap = document.getElementById('walkin-chart-canvas-wrap');
                const walkEmpty = document.getElementById('walkin-chart-empty-state');

                if (tot > 0) {
                    if (walkWrap) walkWrap.style.display = 'block';
                    if (walkEmpty) walkEmpty.style.display = 'none';
                    if (walkinChartInstance) walkinChartInstance.resize();
                } else {
                    if (walkWrap) walkWrap.style.display = 'none';
                    if (walkEmpty) walkEmpty.style.display = 'flex';
                }

                if (walkinChartInstance) {
                    walkinChartInstance.data.labels = chartsData.walkin[timeframe].labels;
                    walkinChartInstance.data.datasets[0].data = chartsData.walkin[timeframe].data;
                    walkinChartInstance.update();
                }
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Restore scroll position seamlessly if navigation or date filter occurred
        try {
            const savedScroll = sessionStorage.getItem('fittrack_reports_scroll');
            if (savedScroll !== null) {
                sessionStorage.removeItem('fittrack_reports_scroll');
                const targetY = parseInt(savedScroll, 10);
                if (!isNaN(targetY)) {
                    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
                    window.scrollTo(0, targetY);
                    requestAnimationFrame(() => window.scrollTo(0, targetY));
                    setTimeout(() => window.scrollTo(0, targetY), 60);
                }
            }
        } catch(e) {}

        // Initial tab check: prefer active tab button defined by current scope, fallback to hash if valid
        const activeTabBtn = document.querySelector('.report-tab-btn.active');
        const defaultTab = activeTabBtn ? (activeTabBtn.dataset.tab || 'revenue-tab') : (document.getElementById('platform-saas-tab') ? 'platform-saas-tab' : 'revenue-tab');
        let initialTab = window.location.hash ? window.location.hash.substring(1) : defaultTab;
        if (!document.getElementById(initialTab)) {
            initialTab = defaultTab;
        }

        // Global FitDropdown for Platform Admin Gym Scope (with Hybrid Live Search)
        if (typeof FitDropdown !== 'undefined' && document.getElementById('gymScopeDropdownContainer') && window.REPORTS_CONFIG?.gymScopeItems?.length) {
            let gymSearchTimer = null;
            const initialScopeItems = window.REPORTS_CONFIG.gymScopeItems;

            window.gymScopeDropdown = new FitDropdown({
                container: '#gymScopeDropdownContainer',
                name: 'gym_id',
                id: 'gymScopeSelect',
                menuAlign: 'right',
                value: String(window.REPORTS_CONFIG.gymScopeId || 'platform'),
                searchable: true,
                searchPlaceholder: 'Search gym partner or facility...',
                zIndex: 80,
                items: initialScopeItems,
                onSearch: function(q, updateBadge, setResults) {
                    clearTimeout(gymSearchTimer);
                    const query = q.trim();

                    if (!query) {
                        updateBadge('');
                        setResults(initialScopeItems);
                        return;
                    }

                    // Hybrid Live Search:
                    // 1. Client-side instant filter is executed immediately by FitDropdown
                    // 2. Debounced asynchronous search queries live database across all partner gyms
                    updateBadge('Searching...');
                    gymSearchTimer = setTimeout(() => {
                        fetch('index.php?page=reports&action=search_gyms&q=' + encodeURIComponent(query))
                            .then(res => res.json())
                            .then(data => {
                                if (data && data.success && Array.isArray(data.results)) {
                                    updateBadge(data.count + (data.count === 1 ? ' gym' : ' gyms'));
                                    setResults(data.results);
                                } else {
                                    updateBadge('');
                                }
                            })
                            .catch(err => {
                                console.warn('[FitDropdown] Gym search error:', err);
                                updateBadge('');
                            });
                    }, 200);
                },
                onChange: function(item) {
                    const id = (item && typeof item === 'object') ? item.id : item;
                    if (id && String(id) !== String(window.REPORTS_CONFIG.gymScopeId)) {
                        window.location.href = 'index.php?page=reports&gym_id=' + encodeURIComponent(id);
                    }
                }
            });
        }

        // Global FitDropdown for Mobile Report Navigation: derive cleanly from the active tab buttons rendered for this scope
        const reportTabItems = [];
        document.querySelectorAll('.report-tabs .report-tab-btn').forEach(btn => {
            const tabId = btn.getAttribute('data-tab');
            if (tabId && document.getElementById(tabId)) {
                // Clone button to strip SVG icons and extract clean text
                const clone = btn.cloneNode(true);
                clone.querySelectorAll('svg').forEach(s => s.remove());
                const label = clone.textContent.trim().replace(/\s+/g, ' ');
                reportTabItems.push({ id: tabId, label: label });
            }
        });

        if (typeof FitDropdown !== 'undefined' && document.getElementById('mobileReportTabDropdown')) {
            window.reportTabDropdown = new FitDropdown({
                container: '#mobileReportTabDropdown',
                name: 'report_tab',
                id: 'report_tab_select',
                value: initialTab,
                searchable: false,
                zIndex: 60,
                items: reportTabItems,
                onChange: function(item) {
                    // FitDropdown passes the selected item object ({id, label}), not the id string
                    const tabId = (item && typeof item === 'object') ? item.id : item;
                    if (tabId) {
                        window.showTab(tabId);
                    }
                }
            });
        }

        window.showTab(initialTab, false);

        window.addEventListener('hashchange', function() {
            if (window.location.hash) {
                const hTab = window.location.hash.substring(1);
                if (document.getElementById(hTab)) window.showTab(hTab, false);
            }
        });

        if (typeof Chart !== 'undefined') {
            function hexToRgba(color, alpha) {
                if (!color) return `rgba(199, 255, 34, ${alpha})`;
                color = color.trim();
                if (color.startsWith('rgb')) {
                    return color.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`);
                }
                const clean = color.replace('#', '');
                if (clean.length === 3) {
                    const r = parseInt(clean[0] + clean[0], 16);
                    const g = parseInt(clean[1] + clean[1], 16);
                    const b = parseInt(clean[2] + clean[2], 16);
                    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                } else if (clean.length === 6) {
                    const r = parseInt(clean.substring(0, 2), 16);
                    const g = parseInt(clean.substring(2, 4), 16);
                    const b = parseInt(clean.substring(4, 6), 16);
                    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                }
                return `color-mix(in srgb, ${color} ${Math.round(alpha * 100)}%, transparent)`;
            }

            function getThemeColors() {
                const isLight = document.documentElement.getAttribute('data-theme') === 'light';
                const computedStyle = getComputedStyle(document.documentElement);
                const themeAccent = (window.REPORTS_CONFIG && window.REPORTS_CONFIG.brandColor) || computedStyle.getPropertyValue('--lime').trim() || (isLight ? '#4d7c0f' : '#c7ff22');

                return {
                    isLight: isLight,
                    themeAccent: themeAccent,
                    textColor: isLight ? '#0f172a' : '#cbd5e1',
                    tickFont: { family: "'Inter', system-ui, -apple-system, sans-serif", size: 11, weight: '600' },
                    gridColor: isLight ? '#e2e8f0' : 'rgba(255, 255, 255, 0.08)',
                    axisLineColor: isLight ? '#94a3b8' : 'rgba(255, 255, 255, 0.20)',
                    tooltipBg: '#0f172a',
                    tooltipTitle: '#ffffff',
                    tooltipBorder: isLight ? '#334155' : 'rgba(255, 255, 255, 0.2)',
                    
                    revenue: {
                        subs: themeAccent,
                        subsHover: hexToRgba(themeAccent, 0.85),
                        walk: isLight ? '#0284c7' : '#0ea5e9',
                        walkHover: isLight ? '#0369a1' : '#38bdf8'
                    },
                    attendance: {
                        line: themeAccent,
                        fill: hexToRgba(themeAccent, isLight ? 0.22 : 0.16),
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: themeAccent,
                        pointHoverBg: themeAccent
                    },
                    hourly: {
                        peak: themeAccent,
                        peakHover: hexToRgba(themeAccent, 0.90),
                        normal: hexToRgba(themeAccent, isLight ? 0.28 : 0.32),
                        normalHover: hexToRgba(themeAccent, isLight ? 0.50 : 0.55)
                    },
                    dayOfWeek: {
                        peak: themeAccent,
                        peakHover: hexToRgba(themeAccent, 0.90),
                        normal: hexToRgba(themeAccent, isLight ? 0.28 : 0.32),
                        normalHover: hexToRgba(themeAccent, isLight ? 0.50 : 0.55)
                    },
                    walkin: {
                        line: themeAccent,
                        fill: hexToRgba(themeAccent, isLight ? 0.22 : 0.16),
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: themeAccent,
                        pointHoverBg: themeAccent
                    },
                    engagement: {
                        colors: isLight ? ['#059669', '#d97706', '#dc2626'] : ['#10b981', '#f59e0b', '#ef4444']
                    }
                };
            }

            const initialColors = getThemeColors();
            Chart.defaults.color = initialColors.textColor;
            Chart.defaults.borderColor = initialColors.gridColor;
            
            // 0. Platform SaaS Revenue Bar Chart & Plan Tier Mix
            const saasCanvas = document.getElementById('platformSaaSChart');
            if (saasCanvas && chartsData.platform_saas) {
                const saasData = chartsData.platform_saas;
                platformSaaSChartInstance = new Chart(saasCanvas, {
                    type: 'bar',
                    data: {
                        labels: saasData.labels,
                        datasets: [{
                            label: 'Platform SaaS Revenue',
                            data: saasData.values,
                            backgroundColor: initialColors.isLight ? '#4d7c0f' : '#84cc16',
                            hoverBackgroundColor: initialColors.isLight ? '#3f6212' : '#a3e635',
                            borderRadius: 6,
                            barPercentage: 0.55
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { color: initialColors.textColor, font: initialColors.tickFont }
                            },
                            y: {
                                beginAtZero: true,
                                suggestedMin: 0,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    callback: function(val) {
                                        return '₱' + Number(val).toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(ctx) {
                                        return ' SaaS Revenue: ₱' + Number(ctx.parsed.y).toLocaleString(undefined, {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        }
                    }
                });
            }

            const saasMixCanvas = document.getElementById('platformSaaSMixChart');
            if (saasMixCanvas && chartsData.platform_saas) {
                const saasData = chartsData.platform_saas;
                const tierColors = ['#38bdf8', '#a855f7', '#f59e0b', '#84cc16'];
                platformSaaSMixChartInstance = new Chart(saasMixCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: saasData.plan_labels,
                        datasets: [{
                            data: saasData.plan_values,
                            backgroundColor: tierColors.slice(0, saasData.plan_labels.length),
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                padding: 12,
                                callbacks: {
                                    label: function(ctx) {
                                        const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                        const pct = tot > 0 ? ((ctx.parsed / tot) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': ₱' + Number(ctx.parsed).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 1. Revenue Stacked Bar Chart
            const revCanvas = document.getElementById('revenueChart');
            if (revCanvas) {
                const initRevTf = window.REPORTS_CONFIG?.defaultTimeframe || 'monthly';
                revenueChartInstance = new Chart(revCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.revenue[initRevTf]?.labels || [],
                        datasets: [
                            {
                                label: 'Subscriptions',
                                data: chartsData.revenue[initRevTf]?.subscriptions || [],
                                backgroundColor: initialColors.revenue.subs,
                                hoverBackgroundColor: initialColors.revenue.subsHover,
                                borderRadius: 5,
                                barPercentage: 0.65,
                                maxBarThickness: 48
                            },
                            {
                                label: 'Walk-In Passes',
                                data: chartsData.revenue[initRevTf]?.walkins || [],
                                backgroundColor: initialColors.revenue.walk,
                                hoverBackgroundColor: initialColors.revenue.walkHover,
                                borderRadius: 5,
                                barPercentage: 0.65,
                                maxBarThickness: 48
                            }
                        ]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: { 
                            x: { 
                                stacked: true,
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont
                                }
                            },
                            y: { 
                                stacked: true,
                                beginAtZero: true,
                                suggestedMin: 0,
                                suggestedMax: 1000,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0,
                                    callback: function(val) {
                                        if (val % 1 !== 0) return '';
                                        return '₱' + Number(val).toLocaleString();
                                    }
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                padding: 14, 
                                borderColor: initialColors.tooltipBorder, 
                                borderWidth: 1,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        let val = context.parsed.y || 0;
                                        return ' ' + label + ': ₱' + val.toLocaleString(undefined, {minimumFractionDigits: 2});
                                    },
                                    footer: function(items) {
                                        let total = 0;
                                        items.forEach(function(item) { total += item.parsed.y; });
                                        return 'Total: ₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        } 
                    }
                });
            }

            // 2. Revenue Mix Donut Chart
            const mixCanvas = document.getElementById('revenueMixChart');
            if (mixCanvas) {
                const initRevTf = window.REPORTS_CONFIG?.defaultTimeframe || 'monthly';
                const subT = chartsData.revenue[initRevTf]?.subTotal || 0;
                const walkT = chartsData.revenue[initRevTf]?.walkTotal || 0;
                revenueMixChartInstance = new Chart(mixCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Subscriptions', 'Walk-Ins'],
                        datasets: [{
                            data: [subT, walkT],
                            backgroundColor: [initialColors.revenue.subs, initialColors.revenue.walk],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 12,
                                callbacks: {
                                    label: function(ctx) {
                                        const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                        const pct = tot > 0 ? ((ctx.parsed / tot) * 100).toFixed(1) : '0.0';
                                        return ' ' + ctx.label + ': ₱' + ctx.parsed.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 3. Attendance Trend Chart (High Contrast in Light & Dark Mode)
            const attCanvas = document.getElementById('attendanceChart');
            if (attCanvas) {
                const initAttTf = window.REPORTS_CONFIG?.defaultTimeframe || 'daily';
                attendanceChartInstance = new Chart(attCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.attendance[initAttTf]?.labels || [],
                        datasets: [{
                            label: 'Check-Ins',
                            data: chartsData.attendance[initAttTf]?.data || [],
                            borderColor: initialColors.attendance.line,
                            backgroundColor: initialColors.attendance.fill,
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3.5,
                            pointBackgroundColor: initialColors.attendance.pointBg,
                            pointBorderColor: initialColors.attendance.pointBorder,
                            pointBorderWidth: 2.5,
                            pointRadius: 4.5,
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: initialColors.attendance.pointHoverBg,
                            pointHoverBorderColor: '#ffffff'
                        }]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { 
                            y: { 
                                beginAtZero: true,
                                suggestedMin: 0,
                                suggestedMax: 10,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0 
                                }
                            }, 
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont 
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                bodyColor: initialColors.attendance.line, 
                                padding: 12, 
                                borderColor: initialColors.tooltipBorder, 
                                borderWidth: 1 
                            }
                        } 
                    }
                });
            }

            // 4. Hourly Rush Bar Chart with Dynamic Rush Highlight
            const hrCanvas = document.getElementById('hourlyChart');
            if (hrCanvas) {
                const hourlyColors = chartsData.hourly.labels.map(lbl => {
                    const isMorning = ['6 AM', '7 AM', '8 AM'].includes(lbl);
                    const isEvening = ['5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl);
                    return (isMorning || isEvening) ? initialColors.hourly.peak : initialColors.hourly.normal;
                });

                hourlyChartInstance = new Chart(hrCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.hourly.labels,
                        datasets: [{
                            label: 'Visits',
                            data: chartsData.hourly.data,
                            backgroundColor: hourlyColors,
                            hoverBackgroundColor: initialColors.hourly.peakHover,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0, 
                                    stepSize: 1 
                                } 
                            },
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: { family: "'Inter', system-ui, sans-serif", size: 10.5, weight: '600' } 
                                } 
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { 
                                backgroundColor: initialColors.tooltipBg, 
                                titleColor: initialColors.tooltipTitle, 
                                bodyColor: initialColors.hourly.peak, 
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    afterLabel: function(ctx) {
                                        const lbl = ctx.label;
                                        if (['6 AM', '7 AM', '8 AM', '5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl)) {
                                            return '⚡ Peak Rush Window';
                                        }
                                        return '';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 5. Day-of-Week Distribution Bar Chart with Peak Day Highlighting
            const dowCanvas = document.getElementById('dayOfWeekChart');
            if (dowCanvas) {
                const maxVal = Math.max(...chartsData.day_of_week.data);
                const dowColors = chartsData.day_of_week.data.map(val => {
                    return (val === maxVal && maxVal > 0) ? initialColors.dayOfWeek.peak : initialColors.dayOfWeek.normal;
                });

                dayOfWeekChartInstance = new Chart(dowCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.day_of_week.labels,
                        datasets: [{
                            label: 'Visits',
                            data: chartsData.day_of_week.data,
                            backgroundColor: dowColors,
                            hoverBackgroundColor: initialColors.dayOfWeek.peakHover,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 }, 
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0, 
                                    stepSize: 1 
                                } 
                            },
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: {
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: initialColors.dayOfWeek.peak, borderColor: initialColors.tooltipBorder, borderWidth: 1, padding: 10 }
                        }
                    }
                });
            }

            // 6. Walk-In Trend Chart
            const walkCanvas = document.getElementById('walkinChart');
            if (walkCanvas) {
                const initWkTf = window.REPORTS_CONFIG?.defaultTimeframe || 'daily';
                walkinChartInstance = new Chart(walkCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.walkin[initWkTf]?.labels || [],
                        datasets: [{
                            label: 'Walk-In Visitors',
                            data: chartsData.walkin[initWkTf]?.data || [],
                            borderColor: initialColors.walkin.line,
                            backgroundColor: initialColors.walkin.fill,
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3.5,
                            pointBackgroundColor: initialColors.walkin.pointBg,
                            pointBorderColor: initialColors.walkin.pointBorder,
                            pointBorderWidth: 2.5,
                            pointRadius: 4.5,
                            pointHoverRadius: 7,
                            pointHoverBackgroundColor: initialColors.walkin.pointHoverBg,
                            pointHoverBorderColor: '#ffffff'
                        }]
                    },
                    options: { 
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                suggestedMin: 0,
                                suggestedMax: 5,
                                grid: { color: initialColors.gridColor },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont,
                                    precision: 0 
                                }
                            }, 
                            x: { 
                                grid: { display: false },
                                border: { color: initialColors.axisLineColor, width: 1.5 },
                                ticks: { 
                                    color: initialColors.textColor,
                                    font: initialColors.tickFont 
                                }
                            }
                        }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: initialColors.walkin.line, padding: 12, borderColor: initialColors.tooltipBorder, borderWidth: 1 }
                        } 
                    }
                });
            }

            // 7. Engagement Churn Doughnut Chart
            const engCanvas = document.getElementById('engagementChart');
            if (engCanvas) {
                engagementChartInstance = new Chart(engCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: window.REPORTS_CONFIG?.engagement?.labels || [],
                        datasets: [{
                            data: window.REPORTS_CONFIG?.engagement?.data || [],
                            backgroundColor: initialColors.engagement.colors,
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: initialColors.textColor,
                                    padding: 14,
                                    boxWidth: 12,
                                    usePointStyle: true,
                                    font: { size: 12, weight: '600', family: "'Inter', sans-serif" }
                                }
                            },
                            tooltip: {
                                backgroundColor: initialColors.tooltipBg,
                                titleColor: initialColors.tooltipTitle,
                                borderColor: initialColors.tooltipBorder,
                                borderWidth: 1,
                                padding: 10
                            }
                        }
                    }
                });
            }

            // Dynamic theme observer for live dark/light mode switching
            function applyThemeToCharts() {
                if (typeof Chart === 'undefined') return;
                const tc = getThemeColors();
                Chart.defaults.color = tc.textColor;
                Chart.defaults.borderColor = tc.gridColor;

                // Update Platform SaaS Bar
                if (platformSaaSChartInstance && platformSaaSChartInstance.data.datasets.length) {
                    platformSaaSChartInstance.data.datasets[0].backgroundColor = tc.isLight ? '#4d7c0f' : '#84cc16';
                    platformSaaSChartInstance.data.datasets[0].hoverBackgroundColor = tc.isLight ? '#3f6212' : '#a3e635';
                }

                // Update Revenue Bar
                if (revenueChartInstance && revenueChartInstance.data.datasets.length >= 2) {
                    revenueChartInstance.data.datasets[0].backgroundColor = tc.revenue.subs;
                    revenueChartInstance.data.datasets[0].hoverBackgroundColor = tc.revenue.subsHover;
                    revenueChartInstance.data.datasets[1].backgroundColor = tc.revenue.walk;
                    revenueChartInstance.data.datasets[1].hoverBackgroundColor = tc.revenue.walkHover;
                }

                // Update Revenue Mix Donut
                if (revenueMixChartInstance && revenueMixChartInstance.data.datasets.length) {
                    revenueMixChartInstance.data.datasets[0].backgroundColor = [tc.revenue.subs, tc.revenue.walk];
                }

                // Update Attendance Line
                if (attendanceChartInstance && attendanceChartInstance.data.datasets.length) {
                    attendanceChartInstance.data.datasets[0].borderColor = tc.attendance.line;
                    attendanceChartInstance.data.datasets[0].backgroundColor = tc.attendance.fill;
                    attendanceChartInstance.data.datasets[0].pointBackgroundColor = tc.attendance.pointBg;
                    attendanceChartInstance.data.datasets[0].pointBorderColor = tc.attendance.pointBorder;
                    attendanceChartInstance.data.datasets[0].pointHoverBackgroundColor = tc.attendance.pointHoverBg;
                    if (attendanceChartInstance.options.plugins && attendanceChartInstance.options.plugins.tooltip) {
                        attendanceChartInstance.options.plugins.tooltip.bodyColor = tc.attendance.line;
                    }
                }

                // Update Hourly Bar
                if (hourlyChartInstance && hourlyChartInstance.data.datasets.length) {
                    hourlyChartInstance.data.datasets[0].backgroundColor = chartsData.hourly.labels.map(lbl => {
                        const isRush = ['6 AM', '7 AM', '8 AM', '5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl);
                        return isRush ? tc.hourly.peak : tc.hourly.normal;
                    });
                    hourlyChartInstance.data.datasets[0].hoverBackgroundColor = tc.hourly.peakHover;
                    if (hourlyChartInstance.options.plugins && hourlyChartInstance.options.plugins.tooltip) {
                        hourlyChartInstance.options.plugins.tooltip.bodyColor = tc.hourly.peak;
                    }
                }

                // Update Day of Week Bar
                if (dayOfWeekChartInstance && dayOfWeekChartInstance.data.datasets.length) {
                    const maxVal = Math.max(...chartsData.day_of_week.data);
                    dayOfWeekChartInstance.data.datasets[0].backgroundColor = chartsData.day_of_week.data.map(val => {
                        return (val === maxVal && maxVal > 0) ? tc.dayOfWeek.peak : tc.dayOfWeek.normal;
                    });
                    dayOfWeekChartInstance.data.datasets[0].hoverBackgroundColor = tc.dayOfWeek.peakHover;
                    if (dayOfWeekChartInstance.options.plugins && dayOfWeekChartInstance.options.plugins.tooltip) {
                        dayOfWeekChartInstance.options.plugins.tooltip.bodyColor = tc.dayOfWeek.peak;
                    }
                }

                // Update Walk-In Line
                if (walkinChartInstance && walkinChartInstance.data.datasets.length) {
                    walkinChartInstance.data.datasets[0].borderColor = tc.walkin.line;
                    walkinChartInstance.data.datasets[0].backgroundColor = tc.walkin.fill;
                    walkinChartInstance.data.datasets[0].pointBackgroundColor = tc.walkin.pointBg;
                    walkinChartInstance.data.datasets[0].pointBorderColor = tc.walkin.pointBorder;
                    walkinChartInstance.data.datasets[0].pointHoverBackgroundColor = tc.walkin.pointHoverBg;
                    if (walkinChartInstance.options.plugins && walkinChartInstance.options.plugins.tooltip) {
                        walkinChartInstance.options.plugins.tooltip.bodyColor = tc.walkin.line;
                    }
                }

                // Update Engagement Doughnut
                if (engagementChartInstance && engagementChartInstance.data.datasets.length) {
                    engagementChartInstance.data.datasets[0].backgroundColor = tc.engagement.colors;
                    if (engagementChartInstance.options.plugins && engagementChartInstance.options.plugins.legend) {
                        engagementChartInstance.options.plugins.legend.labels.color = tc.textColor;
                    }
                }

                const list = [
                    platformSaaSChartInstance,
                    platformSaaSMixChartInstance,
                    revenueChartInstance,
                    revenueMixChartInstance,
                    attendanceChartInstance,
                    hourlyChartInstance,
                    dayOfWeekChartInstance,
                    walkinChartInstance,
                    engagementChartInstance
                ];

                list.forEach(c => {
                    if (!c) return;
                    if (c.options && c.options.scales) {
                        if (c.options.scales.y) {
                            if (c.options.scales.y.ticks) {
                                c.options.scales.y.ticks.color = tc.textColor;
                                c.options.scales.y.ticks.font = tc.tickFont;
                            }
                            if (c.options.scales.y.grid) {
                                c.options.scales.y.grid.color = tc.gridColor;
                            }
                            if (!c.options.scales.y.border) c.options.scales.y.border = {};
                            c.options.scales.y.border.color = tc.axisLineColor;
                            c.options.scales.y.border.width = 1.5;
                        }
                        if (c.options.scales.x) {
                            if (c.options.scales.x.ticks) {
                                c.options.scales.x.ticks.color = tc.textColor;
                                c.options.scales.x.ticks.font = (c === hourlyChartInstance) 
                                    ? { family: "'Inter', system-ui, sans-serif", size: 10.5, weight: '600' }
                                    : tc.tickFont;
                            }
                            if (c.options.scales.x.grid && c.options.scales.x.grid.display) {
                                c.options.scales.x.grid.color = tc.gridColor;
                            }
                            if (!c.options.scales.x.border) c.options.scales.x.border = {};
                            c.options.scales.x.border.color = tc.axisLineColor;
                            c.options.scales.x.border.width = 1.5;
                        }
                    }
                    if (c.options && c.options.plugins && c.options.plugins.tooltip) {
                        c.options.plugins.tooltip.backgroundColor = tc.tooltipBg;
                        c.options.plugins.tooltip.titleColor = tc.tooltipTitle;
                        c.options.plugins.tooltip.borderColor = tc.tooltipBorder;
                    }
                    c.update('none');
                });
            }

            try {
                const themeObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(m) {
                        if (m.attributeName === 'data-theme') {
                            applyThemeToCharts();
                        }
                    });
                });
                themeObserver.observe(document.documentElement, { attributes: true });
            } catch (e) {}

            const themeBtn = document.getElementById('theme-toggle-btn');
            if (themeBtn) {
                themeBtn.addEventListener('click', function() {
                    setTimeout(applyThemeToCharts, 50);
                });
            }

            if (window.REPORTS_CONFIG?.defaultTimeframe) {
                const initTf = window.REPORTS_CONFIG.defaultTimeframe;
                window.setTimeframe('revenue', initTf);
                window.setTimeframe('attendance', initTf);
                window.setTimeframe('walkin', initTf);
            }
        }
    });

    function sendMemberNudge(userId, memberName) {
        Swal.fire({
            title: 'Send Check-In Reminder',
            width: 'min(94vw, 470px)',
            html: `
                <div style="text-align: left; margin-top: 6px;">
                    <!-- Recipient & Channel Card -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; margin-bottom: 14px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--ink);">${memberName}</div>
                                <div style="font-size: 11.5px; color: var(--muted);">Needs a Boost Tier</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px;">
                            <span style="font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 6px; background: rgba(132,204,22,0.12); color: var(--lime); border: 1px solid rgba(132,204,22,0.25);">In-App</span>
                            <span style="font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 6px; background: rgba(14,165,233,0.12); color: #38bdf8; border: 1px solid rgba(14,165,233,0.25);">Email</span>
                        </div>
                    </div>

                    <p style="font-size: 13px; color: var(--muted); margin: 0 0 12px; line-height: 1.5;">
                        Send an encouraging motivational nudge to help this member resume their workouts and gym activities.
                    </p>

                    <!-- Custom Message Field -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label for="swalNudgeMsg" style="font-size: 12px; font-weight: 600; color: var(--ink); margin: 0;">
                                Custom Note <span style="font-weight: 400; color: var(--muted);">(Optional)</span>
                            </label>
                            <span style="font-size: 11px; color: var(--muted);">Leave blank for default email template</span>
                        </div>
                        <textarea id="swalNudgeMsg" rows="3" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px; line-height: 1.45; resize: vertical; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='var(--line)'" placeholder="We miss you at the gym! Check out this week's classes or your workout plan to get back on track!"></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Send Nudge',
            confirmButtonColor: '#f59e0b',
            cancelButtonText: 'Cancel',
            cancelButtonColor: '#64748b',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                const msg = document.getElementById('swalNudgeMsg').value;
                const formData = new FormData();
                formData.append('action', 'send_nudge');
                formData.append('user_id', userId);
                formData.append('custom_message', msg);
                formData.append('csrf_token', window.REPORTS_CONFIG?.csrfToken || '');

                return fetch('index.php?page=reports', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to send notification.');
                    }
                    return data;
                })
                .catch(err => {
                    Swal.showValidationMessage(err.message);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then(result => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Check-In Sent',
                    text: result.value.message || `Check-in sent to ${memberName}.`,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        });
    }

    function sendNudgeAll(count) {
        Swal.fire({
            title: 'Send Bulk Check-In',
            width: 'min(94vw, 470px)',
            html: `
                <div style="text-align: left; margin-top: 6px;">
                    <!-- Recipient & Channel Card -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; margin-bottom: 14px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 13.5px; color: var(--ink);">${count} Inactive Members</div>
                                <div style="font-size: 11.5px; color: var(--muted);">Needs a Boost Tier</div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px;">
                            <span style="font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 6px; background: rgba(132,204,22,0.12); color: var(--lime); border: 1px solid rgba(132,204,22,0.25);">In-App</span>
                            <span style="font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 6px; background: rgba(14,165,233,0.12); color: #38bdf8; border: 1px solid rgba(14,165,233,0.25);">Email</span>
                        </div>
                    </div>

                    <p style="font-size: 13px; color: var(--muted); margin: 0 0 12px; line-height: 1.5;">
                        This will dispatch an encouraging check-in reminder (in-app notification and email) to all <strong>${count}</strong> members in this tier.
                    </p>

                    <!-- Custom Message Field -->
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label for="swalNudgeAllMsg" style="font-size: 12px; font-weight: 600; color: var(--ink); margin: 0;">
                                Custom Note <span style="font-weight: 400; color: var(--muted);">(Optional)</span>
                            </label>
                            <span style="font-size: 11px; color: var(--muted);">Leave blank for default email template</span>
                        </div>
                        <textarea id="swalNudgeAllMsg" rows="3" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); font-size: 13px; line-height: 1.45; resize: vertical; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='var(--line)'" placeholder="We miss you at the gym! Check out this week's classes or your workout plan to get back on track!"></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: `Send to All (${count})`,
            confirmButtonColor: '#f59e0b',
            cancelButtonText: 'Cancel',
            cancelButtonColor: '#64748b',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                const msg = document.getElementById('swalNudgeAllMsg').value;
                const formData = new FormData();
                formData.append('action', 'send_nudge');
                formData.append('all_at_risk', '1');
                formData.append('custom_message', msg);
                formData.append('csrf_token', window.REPORTS_CONFIG?.csrfToken || '');

                return fetch('index.php?page=reports', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to send notifications.');
                    }
                    return data;
                })
                .catch(err => {
                    Swal.showValidationMessage(err.message);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then(result => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Check-Ins Dispatched',
                    text: result.value.message || 'All check-ins sent successfully.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        });
    }

    window.openEODModal = function() {
        Swal.fire({
            title: 'Loading Daily Settlement...',
            text: 'Fetching real-time End-of-Day numbers',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
                const formData = new FormData();
                formData.append('action', 'get_eod_data');
                formData.append('csrf_token', window.REPORTS_CONFIG?.csrfToken || '');
        if (window.REPORTS_CONFIG?.selectedGymId) {
            formData.append('gym_id', window.REPORTS_CONFIG.selectedGymId);
        }

                fetch('index.php?page=reports', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        throw new Error(res.error || 'Failed to load settlement data.');
                    }
                    const d = res.data;
                    const g = res.gym || {};
                    const gymName = g.name || 'FitTrack Gym';

                    const dismissEODModal = () => {
                        Swal.close();
                        setTimeout(() => {
                            const activeContainer = document.querySelector('.swal2-container');
                            if (activeContainer) {
                                activeContainer.remove();
                                document.body.classList.remove('swal2-shown', 'swal2-height-auto');
                            }
                        }, 120);
                    };
                    window.dismissEODModal = dismissEODModal;

                    const isOwner = (window.REPORTS_CONFIG?.userRole === 'gym_owner');
                    const win = d.eod_window || {};
                    window._reportsEodWindow = win;

                    const isOpen = Boolean(win.is_window_open);
                    const alreadySent = Boolean(win.already_sent_today);
                    const sentBy = win.last_sent_by_name ? win.last_sent_by_name + (win.last_sent_by_role ? ` (${win.last_sent_by_role})` : '') : 'Staff';
                    const sentAt = win.last_sent_formatted || '';

                    let statusBannerHtml = '';
                    const rawOpenTime = win.window_open_time || '5:00 PM';
                    const shortOpenTime = rawOpenTime.replace(':00', '');
                    let emailBtnLabel = isOwner ? 'Email Report' : 'Email Owner';
                    let emailBtnStyle = 'flex:1;min-width:0;height:38px;padding:0 10px;font-size:12px;font-weight:700;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;white-space:nowrap;box-sizing:border-box;';

                    if (alreadySent) {
                        statusBannerHtml = `
                            <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:12px;color:#86efac;display:flex;align-items:center;gap:8px;">
                                <span style="font-size:14px;">✓</span>
                                <div><strong>EOD settlement emailed today</strong> at <strong>${sentAt}</strong> by <strong>${sentBy}</strong>.</div>
                            </div>
                        `;
                        emailBtnLabel = `Sent (${sentAt})`;
                    } else if (!isOpen) {
                        statusBannerHtml = `
                            <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:12px;color:#fcd34d;display:flex;align-items:center;gap:8px;">
                                <span style="font-size:14px;">🕒</span>
                                <div><strong>EOD Email unlocks at ${escapeHtml(rawOpenTime)}</strong> for daily closing. You can print a physical slip anytime below.</div>
                            </div>
                        `;
                        emailBtnLabel = `Email (Opens ${escapeHtml(shortOpenTime)})`;
                        emailBtnStyle += 'opacity:0.75;background:#1e293b;border-color:rgba(148,163,184,0.2);color:#94a3b8;cursor:not-allowed;';
                    }

                    Swal.fire({
                        width: 'min(94vw, 480px)',
                        customClass: {
                            popup: 'eod-settlement-modal'
                        },
                        html: `
                            <div style="text-align:left;font-family:'Inter',system-ui,sans-serif;margin-top:4px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:14px;padding-right:42px;">
                                    <div>
                                        <div style="font-size:16px;font-weight:800;color:var(--ink);">${gymName}</div>
                                        <div style="font-size:12px;color:var(--muted);margin-top:2px;">Daily Settlement &bull; <strong>${d.date_formatted}</strong></div>
                                    </div>
                                    <span class="eod-reading-badge" style="background:rgba(132,204,22,0.12);color:var(--lime);font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;border:1px solid rgba(132,204,22,0.3);">
                                        Z-READING
                                    </span>
                                </div>

                                ${statusBannerHtml}

                                <div style="background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:14px;text-align:center;margin-bottom:14px;">
                                    <div style="font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;">Total Daily Gross Revenue</div>
                                    <div style="font-size:28px;font-weight:900;color:var(--lime);margin:4px 0 2px;">₱${d.total_gross.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                                    <div style="font-size:11.5px;color:var(--muted);">${d.total_transactions} total transactions logged today</div>
                                </div>

                                <table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.6;margin-bottom:14px;">
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Memberships / Plans:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:var(--ink);">₱${d.subscriptions_revenue.toLocaleString(undefined, {minimumFractionDigits: 2})} <span style="font-size:11.5px;color:var(--muted);">(${d.subscriptions_count})</span></td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Walk-In Passes:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:var(--ink);">₱${d.walkins_revenue.toLocaleString(undefined, {minimumFractionDigits: 2})} <span style="font-size:11.5px;color:var(--muted);">(${d.walkins_count})</span></td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Facility Check-Ins:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:#38bdf8;">${d.checkins_count} check-ins</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:7px 0;color:var(--muted);">Expiring Tomorrow:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:#f59e0b;">${d.expiring_tomorrow} members</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:7px 0;color:var(--muted);">Active Enrolled Members:</td>
                                        <td style="padding:7px 0;text-align:right;font-weight:700;color:var(--ink);">${d.active_members} members</td>
                                    </tr>
                                </table>

                                <div class="eod-modal-actions" style="display:flex;gap:8px;margin-top:16px;align-items:center;">
                                    <button type="button" onclick="printEODSlip('${gymName}', '${d.date_formatted}', '${d.total_gross}', '${d.subscriptions_revenue}', '${d.subscriptions_count}', '${d.walkins_revenue}', '${d.walkins_count}', '${d.checkins_count}', '${d.expiring_tomorrow}', '${d.active_members}')" class="btn btn-secondary eod-btn-print" style="flex:1;min-width:0;height:38px;padding:0 10px;font-size:12px;font-weight:700;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;white-space:nowrap;box-sizing:border-box;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                        <span>Print Slip</span>
                                    </button>
                                    <button type="button" onclick="sendEODEmail()" class="btn btn-primary eod-btn-email" style="${emailBtnStyle}" title="${!isOpen ? `EOD email unlocks at ${rawOpenTime}` : (alreadySent ? `Already sent by ${sentBy}` : (isOwner ? 'Send settlement to my registered email' : 'Email full settlement to Gym Owner'))}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                        <span>${emailBtnLabel}</span>
                                    </button>
                                </div>
                            </div>
                        `,
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        allowEscapeKey: true,
                        didOpen: (popup) => {
                            const closeBtn = popup.querySelector('.swal2-close');
                            if (closeBtn) {
                                closeBtn.setAttribute('title', 'Close settlement report');
                                closeBtn.onclick = (e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    dismissEODModal();
                                };
                            }
                        }
                    });
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Could not load EOD data', 'error');
                });
            }
        });
    };

    // Global capture listener to ensure any swal2-close click always reliably closes the modal
    document.addEventListener('click', function(e) {
        const closeBtn = e.target && e.target.closest && e.target.closest('.swal2-close');
        if (closeBtn) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.dismissEODModal === 'function') {
                window.dismissEODModal();
            } else if (typeof Swal !== 'undefined' && typeof Swal.close === 'function') {
                Swal.close();
            }
        }
    }, true);

    window.printEODSlip = function(gym, date, total, subRev, subCnt, walkRev, walkCnt, attCnt, expCnt, activeCnt) {
        const printWindow = window.open('', '_blank', 'width=420,height=600');
        if (!printWindow) {
            alert('Please allow popups to print the settlement slip.');
            return;
        }
        const escapePrintText = value => String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[char]);
        const gymName = escapePrintText(gym);
        const reportDate = escapePrintText(date);
        const money = value => (Number.parseFloat(value) || 0).toFixed(2);
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Daily Settlement - ${gymName}</title>
                <style>
                    body { font-family: 'Courier New', Courier, monospace; width: 300px; margin: 20px auto; font-size: 13px; color: #000; }
                    .center { text-align: center; }
                    .bold { font-weight: bold; }
                    .line { border-top: 1px dashed #000; margin: 10px 0; }
                    .row { display: flex; justify-content: space-between; margin: 4px 0; }
                </style>
            </head>
            <body>
                <div class="center bold" style="font-size:16px;">${gymName}</div>
                <div class="center">DAILY SETTLEMENT (Z-READING)</div>
                <div class="center">${reportDate}</div>
                <div class="line"></div>
                <div class="row"><span>Subscriptions (${Number(subCnt) || 0}):</span><span>PHP ${money(subRev)}</span></div>
                <div class="row"><span>Walk-Ins (${Number(walkCnt) || 0}):</span><span>PHP ${money(walkRev)}</span></div>
                <div class="line"></div>
                <div class="row bold" style="font-size:15px;"><span>TOTAL GROSS:</span><span>PHP ${money(total)}</span></div>
                <div class="line"></div>
                <div class="row"><span>Facility Check-Ins:</span><span>${Number(attCnt) || 0}</span></div>
                <div class="row"><span>Expiring Tomorrow:</span><span>${Number(expCnt) || 0}</span></div>
                <div class="row"><span>Active Members:</span><span>${Number(activeCnt) || 0}</span></div>
                <div class="line"></div>
                <div class="center" style="font-size:11px; margin-top: 15px;">Official Audit Record &bull; FitTrack</div>
                <script>
                    window.onload = function() { window.print(); }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    };

    window.sendEODEmail = function() {
        const win = window._reportsEodWindow || {};
        const isOwner = (window.REPORTS_CONFIG?.userRole === 'gym_owner');

        if (!win.is_window_open) {
            const openTimeStr = win.window_open_time || '5:00 PM';
            Swal.fire({
                icon: 'info',
                title: 'EOD Window Closed',
                text: `The End-of-Day settlement email unlocks at ${openTimeStr} when daily shift closing begins. You can still print a physical slip anytime.`,
            });
            return;
        }
        if (win.already_sent_today && !isOwner) {
            const by = win.last_sent_by_name ? ` by ${win.last_sent_by_name}` : '';
            Swal.fire({
                icon: 'info',
                title: 'Already Sent Today',
                text: `The EOD settlement was already emailed today at ${win.last_sent_formatted || ''}${by}.`,
            });
            return;
        }

        const confirmTitle = isOwner ? 'Send Settlement to Your Email?' : 'Send EOD Settlement to Owner?';
        const confirmText = isOwner
            ? 'This will dispatch today’s executive Z-Reading settlement slip to your registered email.'
            : 'This will officially email the Z-Reading settlement to the gym owner with your staff shift signature.';

        Swal.fire({
            title: confirmTitle,
            text: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Send EOD Email',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (!result.isConfirmed) return;

            const formData = new FormData();
            formData.append('action', 'send_eod_email');
            formData.append('csrf_token', window.REPORTS_CONFIG?.csrfToken || '');
            if (window.REPORTS_CONFIG?.selectedGymId) {
                formData.append('gym_id', window.REPORTS_CONFIG.selectedGymId);
            }

            Swal.fire({
                title: isOwner ? 'Sending Settlement to Your Email...' : 'Sending EOD Email...',
                didOpen: () => {
                    Swal.showLoading();
                    fetch('index.php?page=reports', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            window._reportsEodWindow = {
                                ...window._reportsEodWindow,
                                already_sent_today: true,
                                last_sent_formatted: 'Just now',
                                last_sent_by_name: isOwner ? 'Owner' : 'You'
                            };
                            Swal.fire({
                                icon: 'success',
                                title: 'Settlement Dispatched!',
                                text: res.message || (isOwner ? 'Daily summary sent to your registered email.' : 'Daily summary dispatched to owner email.'),
                                timer: 2500,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire('Notice', res.error || res.message || 'Could not dispatch email.', 'info');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Error', err.message || 'Failed to send EOD email.', 'error');
                    });
                }
            });
        });
    };

    /* ----------------------------------------------------
       Reports Date Range Picker Interactive Controller
       ---------------------------------------------------- */
    window.toggleReportRangeDropdown = function(event, btn) {
        if (event) event.stopPropagation();
        const wrap = btn.closest('.report-range-picker-wrap');
        if (!wrap) return;
        const dropdown = wrap.querySelector('.report-range-dropdown');
        if (!dropdown) return;

        const isShown = dropdown.style.display !== 'none';
        window.closeAllReportRangeDropdowns();
        if (!isShown) {
            dropdown.style.display = 'block';
            btn.classList.add('is-open');
        }
    };

    window.closeAllReportRangeDropdowns = function() {
        document.querySelectorAll('.report-range-dropdown').forEach(d => d.style.display = 'none');
        document.querySelectorAll('.report-range-trigger-btn').forEach(b => b.classList.remove('is-open'));
    };

    window.formatDateYmd = function(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    window.applyReportPreset = function(preset, btn) {
        const today = new Date();
        let sStr = '';
        let eStr = window.formatDateYmd(today);

        if (preset === 'today') {
            sStr = eStr;
        } else if (preset === 'yesterday') {
            const y = new Date(today);
            y.setDate(today.getDate() - 1);
            sStr = window.formatDateYmd(y);
            eStr = sStr;
        } else if (preset === 'last7') {
            const d = new Date(today);
            d.setDate(today.getDate() - 6);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'last14') {
            const d = new Date(today);
            d.setDate(today.getDate() - 13);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'this_month') {
            const d = new Date(today.getFullYear(), today.getMonth(), 1);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'last30') {
            const d = new Date(today);
            d.setDate(today.getDate() - 29);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'last12m') {
            const d = new Date(today.getFullYear(), today.getMonth() - 11, 1);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'this_year') {
            const d = new Date(today.getFullYear(), 0, 1);
            sStr = window.formatDateYmd(d);
        } else if (preset === 'all') {
            window.clearReportDateRange();
            return;
        }

        const wrap = btn ? btn.closest('.report-range-picker-wrap') : null;
        if (wrap) {
            const sInput = wrap.querySelector('.report-start-date-input');
            const eInput = wrap.querySelector('.report-end-date-input');
            if (sInput) sInput.value = sStr;
            if (eInput) eInput.value = eStr;
        }

        window.navigateToDateRange(sStr, eStr);
    };

    window.applyCustomReportDateRange = function(btn) {
        const wrap = btn.closest('.report-range-picker-wrap');
        if (!wrap) return;
        const sInput = wrap.querySelector('.report-start-date-input');
        const eInput = wrap.querySelector('.report-end-date-input');
        if (!sInput || !eInput) return;

        let sVal = sInput.value;
        let eVal = eInput.value;
        if (!sVal && !eVal) {
            window.clearReportDateRange();
            return;
        }
        if (sVal && !eVal) eVal = sVal;
        if (!sVal && eVal) sVal = eVal;
        if (sVal > eVal) {
            const tmp = sVal; sVal = eVal; eVal = tmp;
        }

        window.navigateToDateRange(sVal, eVal);
    };

    window.clearReportDateRange = function() {
        try {
            sessionStorage.setItem('fittrack_reports_scroll', window.scrollY.toString());
        } catch(e) {}
        const url = new URL(window.location.href);
        url.searchParams.delete('start_date');
        url.searchParams.delete('end_date');
        const activeTabEl = document.querySelector('.tab-content:not([style*="display: none"])');
        const activeTab = activeTabEl?.id 
            || document.querySelector('.report-tab-btn.active')?.dataset?.tab
            || (window.location.hash ? window.location.hash.substring(1) : '')
            || 'revenue-tab';
        url.hash = '#' + activeTab;
        window.location.href = url.toString();
    };

    window.navigateToDateRange = function(startDate, endDate) {
        try {
            sessionStorage.setItem('fittrack_reports_scroll', window.scrollY.toString());
        } catch(e) {}
        const url = new URL(window.location.href);
        url.searchParams.set('start_date', startDate);
        url.searchParams.set('end_date', endDate);
        const activeTabEl = document.querySelector('.tab-content:not([style*="display: none"])');
        const activeTab = activeTabEl?.id 
            || document.querySelector('.report-tab-btn.active')?.dataset?.tab
            || (window.location.hash ? window.location.hash.substring(1) : '')
            || 'revenue-tab';
        url.hash = '#' + activeTab;
        window.location.href = url.toString();
    };

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.report-range-picker-wrap')) {
            window.closeAllReportRangeDropdowns();
        }
    });
