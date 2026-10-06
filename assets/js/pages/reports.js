/**
 * Reports & Analytics Controller
 * Extracted from reports.php
 */
    const chartsData = window.REPORTS_CONFIG?.chartsData || {};
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
            if (tabId === 'revenue-tab') {
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
        if (hintText && tfHints[timeframe]) {
            hintText.textContent = tfHints[timeframe];
        }

        ['daily', 'monthly', 'yearly'].forEach(tf => {
            const table = document.getElementById(type + '-table-' + tf);
            if (table) table.style.display = (tf === timeframe) ? 'block' : 'none';
        });

        const csvBtn = document.getElementById('btn-export-' + type + '-csv');
        const printBtn = document.getElementById('btn-export-' + type + '-print');
        if (csvBtn) csvBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=csv';
        if (printBtn) printBtn.href = 'index.php?page=reports&type=' + type + '&timeframe=' + timeframe + '&export=print';

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
        // Initial tab check from URL hash or storage
        let initialTab = window.location.hash ? window.location.hash.substring(1) : (sessionStorage.getItem('fittrack_reports_tab') || 'revenue-tab');
        if (!document.getElementById(initialTab)) initialTab = 'revenue-tab';

        // Global FitDropdown for Mobile Report Navigation (clean text, no icons)
        const reportTabItems = [
            { id: 'revenue-tab', label: 'Revenue & Financials' },
            { id: 'attendance-tab', label: 'Attendance & Peak Rush' },
            { id: 'walkin-tab', label: 'Walk-In Traffic' },
            { id: 'trainers-tab', label: window.REPORTS_CONFIG?.tabs?.trainersLabel || 'Trainers & Commissions' },
            { id: 'engagement-tab', label: window.REPORTS_CONFIG?.tabs?.engagementLabel || 'Member Engagement' }
        ];

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
            function getThemeColors() {
                const isLight = document.documentElement.getAttribute('data-theme') === 'light';
                return {
                    isLight: isLight,
                    textColor: isLight ? '#0f172a' : '#cbd5e1',
                    tickFont: { family: "'Inter', system-ui, -apple-system, sans-serif", size: 11, weight: '600' },
                    gridColor: isLight ? '#e2e8f0' : 'rgba(255, 255, 255, 0.08)',
                    axisLineColor: isLight ? '#94a3b8' : 'rgba(255, 255, 255, 0.20)',
                    tooltipBg: '#0f172a',
                    tooltipTitle: '#ffffff',
                    tooltipBorder: isLight ? '#334155' : 'rgba(255, 255, 255, 0.2)',
                    
                    revenue: {
                        subs: isLight ? '#4d7c0f' : '#84cc16',
                        subsHover: isLight ? '#3f6212' : '#a3e635',
                        walk: isLight ? '#0284c7' : '#0ea5e9',
                        walkHover: isLight ? '#0369a1' : '#38bdf8'
                    },
                    attendance: {
                        line: isLight ? '#7c3aed' : '#a78bfa',
                        fill: isLight ? 'rgba(124, 58, 237, 0.22)' : 'rgba(167, 139, 250, 0.15)',
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: isLight ? '#7c3aed' : '#a78bfa',
                        pointHoverBg: isLight ? '#7c3aed' : '#ffffff'
                    },
                    hourly: {
                        peak: isLight ? '#4d7c0f' : 'rgba(132, 204, 22, 0.95)',
                        peakHover: isLight ? '#3f6212' : '#a3e635',
                        normal: isLight ? 'rgba(77, 124, 15, 0.32)' : 'rgba(132, 204, 22, 0.35)',
                        normalHover: isLight ? 'rgba(77, 124, 15, 0.55)' : 'rgba(132, 204, 22, 0.60)'
                    },
                    dayOfWeek: {
                        peak: isLight ? '#7e22ce' : 'rgba(168, 85, 247, 0.95)',
                        peakHover: isLight ? '#6b21a8' : '#c084fc',
                        normal: isLight ? 'rgba(126, 34, 206, 0.30)' : 'rgba(168, 85, 247, 0.38)',
                        normalHover: isLight ? 'rgba(126, 34, 206, 0.55)' : 'rgba(168, 85, 247, 0.60)'
                    },
                    walkin: {
                        line: isLight ? '#0284c7' : '#38bdf8',
                        fill: isLight ? 'rgba(2, 132, 199, 0.22)' : 'rgba(56, 189, 248, 0.15)',
                        pointBg: isLight ? '#ffffff' : '#0f172a',
                        pointBorder: isLight ? '#0284c7' : '#38bdf8',
                        pointHoverBg: isLight ? '#0284c7' : '#ffffff'
                    },
                    engagement: {
                        colors: isLight ? ['#059669', '#d97706', '#dc2626'] : ['#10b981', '#f59e0b', '#ef4444']
                    }
                };
            }

            const initialColors = getThemeColors();
            Chart.defaults.color = initialColors.textColor;
            Chart.defaults.borderColor = initialColors.gridColor;
            
            // 1. Revenue Stacked Bar Chart
            const revCanvas = document.getElementById('revenueChart');
            if (revCanvas) {
                revenueChartInstance = new Chart(revCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartsData.revenue.monthly.labels,
                        datasets: [
                            {
                                label: 'Subscriptions',
                                data: chartsData.revenue.monthly.subscriptions,
                                backgroundColor: initialColors.revenue.subs,
                                hoverBackgroundColor: initialColors.revenue.subsHover,
                                borderRadius: 5,
                                barPercentage: 0.65
                            },
                            {
                                label: 'Walk-In Passes',
                                data: chartsData.revenue.monthly.walkins,
                                backgroundColor: initialColors.revenue.walk,
                                hoverBackgroundColor: initialColors.revenue.walkHover,
                                borderRadius: 5,
                                barPercentage: 0.65
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
                const subT = chartsData.revenue.monthly.subTotal;
                const walkT = chartsData.revenue.monthly.walkTotal;
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
                attendanceChartInstance = new Chart(attCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.attendance.daily.labels,
                        datasets: [{
                            label: 'Check-Ins',
                            data: chartsData.attendance.daily.data,
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
                                bodyColor: '#a78bfa', 
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
                                bodyColor: '#84cc16', 
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
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: '#c084fc', borderColor: initialColors.tooltipBorder, borderWidth: 1, padding: 10 }
                        }
                    }
                });
            }

            // 6. Walk-In Trend Chart
            const walkCanvas = document.getElementById('walkinChart');
            if (walkCanvas) {
                walkinChartInstance = new Chart(walkCanvas, {
                    type: 'line',
                    data: {
                        labels: chartsData.walkin.daily.labels,
                        datasets: [{
                            label: 'Walk-In Visitors',
                            data: chartsData.walkin.daily.data,
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
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipTitle, bodyColor: '#38bdf8', padding: 12, borderColor: initialColors.tooltipBorder, borderWidth: 1 }
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
                }

                // Update Hourly Bar
                if (hourlyChartInstance && hourlyChartInstance.data.datasets.length) {
                    hourlyChartInstance.data.datasets[0].backgroundColor = chartsData.hourly.labels.map(lbl => {
                        const isRush = ['6 AM', '7 AM', '8 AM', '5 PM', '6 PM', '7 PM', '8 PM'].includes(lbl);
                        return isRush ? tc.hourly.peak : tc.hourly.normal;
                    });
                    hourlyChartInstance.data.datasets[0].hoverBackgroundColor = tc.hourly.peakHover;
                }

                // Update Day of Week Bar
                if (dayOfWeekChartInstance && dayOfWeekChartInstance.data.datasets.length) {
                    const maxVal = Math.max(...chartsData.day_of_week.data);
                    dayOfWeekChartInstance.data.datasets[0].backgroundColor = chartsData.day_of_week.data.map(val => {
                        return (val === maxVal && maxVal > 0) ? tc.dayOfWeek.peak : tc.dayOfWeek.normal;
                    });
                    dayOfWeekChartInstance.data.datasets[0].hoverBackgroundColor = tc.dayOfWeek.peakHover;
                }

                // Update Walk-In Line
                if (walkinChartInstance && walkinChartInstance.data.datasets.length) {
                    walkinChartInstance.data.datasets[0].borderColor = tc.walkin.line;
                    walkinChartInstance.data.datasets[0].backgroundColor = tc.walkin.fill;
                    walkinChartInstance.data.datasets[0].pointBackgroundColor = tc.walkin.pointBg;
                    walkinChartInstance.data.datasets[0].pointBorderColor = tc.walkin.pointBorder;
                    walkinChartInstance.data.datasets[0].pointHoverBackgroundColor = tc.walkin.pointHoverBg;
                }

                // Update Engagement Doughnut
                if (engagementChartInstance && engagementChartInstance.data.datasets.length) {
                    engagementChartInstance.data.datasets[0].backgroundColor = tc.engagement.colors;
                    if (engagementChartInstance.options.plugins && engagementChartInstance.options.plugins.legend) {
                        engagementChartInstance.options.plugins.legend.labels.color = tc.textColor;
                    }
                }

                const list = [
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

                    Swal.fire({
                        width: 'min(94vw, 480px)',
                        html: `
                            <div style="text-align:left;font-family:'Inter',system-ui,sans-serif;margin-top:4px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:14px;">
                                    <div>
                                        <div style="font-size:16px;font-weight:800;color:var(--ink);">${gymName}</div>
                                        <div style="font-size:12px;color:var(--muted);margin-top:2px;">Daily Settlement &bull; <strong>${d.date_formatted}</strong></div>
                                    </div>
                                    <span style="background:rgba(132,204,22,0.12);color:var(--lime);font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;border:1px solid rgba(132,204,22,0.3);">
                                        Z-READING
                                    </span>
                                </div>

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

                                <div style="display:flex;gap:8px;margin-top:16px;">
                                    <button type="button" onclick="printEODSlip('${gymName}', '${d.date_formatted}', '${d.total_gross}', '${d.subscriptions_revenue}', '${d.subscriptions_count}', '${d.walkins_revenue}', '${d.walkins_count}', '${d.checkins_count}', '${d.expiring_tomorrow}', '${d.active_members}')" class="btn btn-secondary" style="flex:1;padding:9px;font-size:12.5px;font-weight:700;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                        Print Slip
                                    </button>
                                    <button type="button" onclick="sendEODEmail()" class="btn btn-primary" style="flex:1;padding:9px;font-size:12.5px;font-weight:700;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                        Email Owner
                                    </button>
                                </div>
                            </div>
                        `,
                        showConfirmButton: false,
                        showCloseButton: true
                    });
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Could not load EOD data', 'error');
                });
            }
        });
    };

    window.printEODSlip = function(gym, date, total, subRev, subCnt, walkRev, walkCnt, attCnt, expCnt, activeCnt) {
        const printWindow = window.open('', '_blank', 'width=420,height=600');
        if (!printWindow) {
            alert('Please allow popups to print the settlement slip.');
            return;
        }
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Daily Settlement - \${gym}</title>
                <style>
                    body { font-family: 'Courier New', Courier, monospace; width: 300px; margin: 20px auto; font-size: 13px; color: #000; }
                    .center { text-align: center; }
                    .bold { font-weight: bold; }
                    .line { border-top: 1px dashed #000; margin: 10px 0; }
                    .row { display: flex; justify-content: space-between; margin: 4px 0; }
                </style>
            </head>
            <body>
                <div class="center bold" style="font-size:16px;">\${gym}</div>
                <div class="center">DAILY SETTLEMENT (Z-READING)</div>
                <div class="center">\${date}</div>
                <div class="line"></div>
                <div class="row"><span>Subscriptions (\${subCnt}):</span><span>PHP \${parseFloat(subRev).toFixed(2)}</span></div>
                <div class="row"><span>Walk-Ins (\${walkCnt}):</span><span>PHP \${parseFloat(walkRev).toFixed(2)}</span></div>
                <div class="line"></div>
                <div class="row bold" style="font-size:15px;"><span>TOTAL GROSS:</span><span>PHP \${parseFloat(total).toFixed(2)}</span></div>
                <div class="line"></div>
                <div class="row"><span>Facility Check-Ins:</span><span>\${attCnt}</span></div>
                <div class="row"><span>Expiring Tomorrow:</span><span>\${expCnt}</span></div>
                <div class="row"><span>Active Members:</span><span>\${activeCnt}</span></div>
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
        const formData = new FormData();
        formData.append('action', 'send_eod_email');
        formData.append('csrf_token', window.REPORTS_CONFIG?.csrfToken || '');
        if (window.REPORTS_CONFIG?.selectedGymId) {
            formData.append('gym_id', window.REPORTS_CONFIG.selectedGymId);
        }

        Swal.fire({
            title: 'Sending EOD Email...',
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
                        Swal.fire({
                            icon: 'success',
                            title: 'Settlement Dispatched!',
                            text: res.message || 'Daily summary dispatched to owner email.',
                            timer: 2500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Notice', res.message || 'Could not dispatch email.', 'info');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Failed to send EOD email.', 'error');
                });
            }
        });
    };
