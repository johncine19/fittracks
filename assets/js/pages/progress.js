/**
 * Member Hub & Progress Controller
 * Extracted from progress.php
 */

const FT_CONFIG = window.PROGRESS_CONFIG || {};
const FT_CSRF_TOKEN = FT_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const FT_CURRENT_USER_ROLE = FT_CONFIG.currentUserRole || '';
const FT_MEMBER_ID = FT_CONFIG.memberId || 0;
const FT_DEFAULTS = FT_CONFIG.defaults || {};
const rawChartData = FT_CONFIG.rawChartData || [];

    // Tab Switching Logic with Hash Support
    function switchHubTab(tabName) {
        const tabs = ['overview', 'workout', 'progress', 'notes'];
        tabs.forEach(t => {
            const btn = document.getElementById('hub-tab-btn-' + t);
            const pane = document.getElementById('hub-pane-' + t);
            if (t === tabName) {
                if (btn) btn.classList.add('active');
                if (pane) pane.classList.add('active');
            } else {
                if (btn) btn.classList.remove('active');
                if (pane) pane.classList.remove('active');
            }
        });
        try {
            history.replaceState(null, null, '#' + tabName);
        } catch(e) {}

        if (tabName === 'overview' && window.hubChartInstance) {
            window.hubChartInstance.resize();
        }
    }

    // Auto-select tab if URL has hash
    window.addEventListener('DOMContentLoaded', () => {
        const hash = window.location.hash.replace('#', '');
        if (['overview', 'workout', 'progress', 'notes'].includes(hash)) {
            switchHubTab(hash);
        }
        initHubChart();
    });

    // Chart.js Data & Logic
    const METRIC_CONFIG = {
        weight: {
            label: 'Weight (kg)',
            chartTitle: 'Weight (kg) over time',
            color: '#c7ff22',
            bgColor: 'rgba(199, 255, 34, 0.12)',
            unit: 'kg'
        },
        body_fat: {
            label: 'Body Fat (%)',
            chartTitle: 'Body Fat (%) over time',
            color: '#22c55e',
            bgColor: 'rgba(34, 197, 94, 0.12)',
            unit: '%'
        },
        waist: {
            label: 'Waist (cm)',
            chartTitle: 'Waist (cm) over time',
            color: '#38bdf8',
            bgColor: 'rgba(56, 189, 248, 0.12)',
            unit: 'cm'
        },
        arm: {
            label: 'Arms (cm)',
            chartTitle: 'Arms (cm) over time',
            color: '#a855f7',
            bgColor: 'rgba(168, 85, 247, 0.12)',
            unit: 'cm'
        },
        chest: {
            label: 'Chest (cm)',
            chartTitle: 'Chest (cm) over time',
            color: '#f59e0b',
            bgColor: 'rgba(245, 158, 11, 0.12)',
            unit: 'cm'
        },
        bmi: {
            label: 'BMI',
            chartTitle: 'Body Mass Index (BMI) over time',
            color: '#ec4899',
            bgColor: 'rgba(236, 72, 153, 0.12)',
            unit: ''
        }
    };

    let currentMetric = FT_CONFIG.defaultMetric || 'weight';
    let currentTimeframe = 'all';

    function setChartMetric(metric) {
        currentMetric = metric;
        ['weight', 'body_fat', 'waist', 'arm', 'chest', 'bmi'].forEach(m => {
            const btn = document.getElementById('m-btn-' + m);
            if (btn) btn.classList.toggle('active', m === metric);
        });

        const cfg = METRIC_CONFIG[metric] || METRIC_CONFIG.weight;
        const labelEl = document.getElementById('chartActiveMetricLabel');
        if (labelEl) labelEl.textContent = cfg.chartTitle || 'Progress over time';

        renderFilteredChart();
    }

    function setChartTimeframe(tf) {
        currentTimeframe = tf;
        ['1m', '3m', '6m', 'all'].forEach(t => {
            const btn = document.getElementById('tf-btn-' + t);
            if (btn) btn.classList.toggle('active', t === tf);
        });
        renderFilteredChart();
    }

    function renderFilteredChart() {
        if (!window.hubChartInstance) return;

        // Filter data by timeframe
        const now = new Date();
        let cutoffDate = null;
        if (currentTimeframe === '1m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 1);
        } else if (currentTimeframe === '3m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 3);
        } else if (currentTimeframe === '6m') {
            cutoffDate = new Date();
            cutoffDate.setMonth(now.getMonth() - 6);
        }

        const filtered = rawChartData.filter(d => {
            if (!cutoffDate) return true;
            return new Date(d.date) >= cutoffDate;
        });

        const labels = filtered.map(d => d.label);
        const dataValues = filtered.map(d => d[currentMetric]);
        const cfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';

        const ds = window.hubChartInstance.data.datasets[0];
        window.hubChartInstance.data.labels = labels;
        ds.label = cfg.label;
        ds.data = dataValues;
        ds.borderColor = cfg.color;
        ds.backgroundColor = cfg.bgColor;
        ds.pointBorderColor = cfg.color;
        ds.pointBackgroundColor = isDark ? cfg.color : '#ffffff';
        ds.spanGaps = true;
        window.hubChartInstance.update();
    }

    function initHubChart() {
        const ctx = document.getElementById('hubTrendChart');
        if (!ctx) return;

        const cfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const gridColor  = isDark ? 'rgba(255,255,255,0.06)' : '#e2e8f0';
        const tickColor  = isDark ? '#94a3b8' : '#64748b';
        const axisBorder = isDark ? 'rgba(255,255,255,0.12)' : '#cbd5e1';

        const labels = rawChartData.map(d => d.label);
        const values = rawChartData.map(d => d[currentMetric]);

        // Ensure active button and title label match currentMetric
        ['weight', 'body_fat', 'waist', 'arm', 'chest', 'bmi'].forEach(m => {
            const btn = document.getElementById('m-btn-' + m);
            if (btn) btn.classList.toggle('active', m === currentMetric);
        });
        const labelEl = document.getElementById('chartActiveMetricLabel');
        if (labelEl) labelEl.textContent = cfg.chartTitle || 'Progress over time';

        window.hubChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: cfg.label,
                    data: values,
                    borderColor: cfg.color,
                    backgroundColor: cfg.bgColor,
                    borderWidth: 2.8,
                    pointRadius: 4.5,
                    pointBackgroundColor: isDark ? cfg.color : '#ffffff',
                    pointBorderColor: cfg.color,
                    pointBorderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    spanGaps: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e2430' : '#ffffff',
                        titleColor: isDark ? '#ffffff' : '#0f172a',
                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                        borderColor: isDark ? 'rgba(255,255,255,0.1)' : '#e2e8f0',
                        borderWidth: 1,
                        padding: 10,
                        boxPadding: 4,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed.y;
                                if (val === null || val === undefined) return 'No entry';
                                const activeCfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
                                return activeCfg.label + ': ' + val + (activeCfg.unit ? ' ' + activeCfg.unit : '');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        grid: { color: gridColor },
                        border: { color: axisBorder },
                        ticks: { color: tickColor, font: { weight: '600', size: 11 } }
                    },
                    x: {
                        grid: { color: gridColor },
                        border: { color: axisBorder },
                        ticks: { color: tickColor, font: { weight: '600', size: 11 } }
                    }
                }
            }
        });
    }

    // Historical Progress Logs Filter Logic
    function filterProgressLogs(filterType) {
        // 1. Update filter buttons active state
        document.querySelectorAll('.log-filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-metric-filter') === filterType);
        });

        // 2. Filter desktop table rows
        const tableRows = document.querySelectorAll('.progress-desktop-table tbody tr:not(#log-filter-empty-row)');
        let visibleRowsCount = 0;
        tableRows.forEach(row => {
            let show = false;
            if (filterType === 'all') {
                show = true;
            } else {
                show = row.getAttribute('data-has-' + filterType) === '1';
            }
            row.style.display = show ? '' : 'none';
            if (show) visibleRowsCount++;
        });

        const emptyRow = document.getElementById('log-filter-empty-row');
        if (emptyRow) {
            emptyRow.style.display = (visibleRowsCount === 0 && tableRows.length > 0) ? '' : 'none';
        }

        // 3. Filter mobile cards
        const cards = document.querySelectorAll('.progress-mobile-cards .mobile-detail-card');
        let visibleCardsCount = 0;
        cards.forEach(card => {
            let show = false;
            if (filterType === 'all') {
                show = true;
            } else {
                show = card.getAttribute('data-has-' + filterType) === '1';
            }
            card.style.display = show ? '' : 'none';
            if (show) visibleCardsCount++;
        });

        const emptyCard = document.getElementById('log-filter-empty-card');
        if (emptyCard) {
            emptyCard.style.display = (visibleCardsCount === 0 && cards.length > 0) ? '' : 'none';
        }
    }

    // Modal: Note submission
    function openNoteModal() {
        Swal.fire({
            title: 'Add Check-In Note',
            html: `
                <form id="noteForm" method="post" style="text-align: left; margin-top: 10px;">
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="quick_note">
                    ${FT_CURRENT_USER_ROLE === 'trainer' ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}
                    <label style="display:block; color:var(--muted); font-size:13px; margin-bottom:6px;">Your Note or Feedback:</label>
                    <textarea name="note_text" class="form-control" rows="4" placeholder="Enter notes or updates..." required style="width:100%; box-sizing:border-box; border-radius:8px;"></textarea>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Note',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const f = document.getElementById('noteForm');
                if (!f.note_text.value.trim()) {
                    Swal.showValidationMessage('Please write a note.');
                    return false;
                }
                f.submit();
            }
        });
    }

    // Modal: Target Metrics & Goal Adjustment (Phase 3: Member & Trainer Alignment)
    function openTargetMetricsModal() {
        const targets = FT_CONFIG.targets || {};
        const isTrainer = FT_CURRENT_USER_ROLE === 'trainer';

        const goalOptions = [
            { val: 'Building a visible six-pack', label: 'Visible Six-Pack (Abs)' },
            { val: 'Growing larger biceps and arms', label: 'Larger Biceps & Arms' },
            { val: 'Developing a wide chest', label: 'Developing a Wide Chest' },
            { val: 'Sculpting a V-tapered back', label: 'V-Tapered Back' },
            { val: 'Shaping the lower body', label: 'Lower Body & Legs' },
            { val: 'Gaining lean body mass', label: 'Lean Body Mass' },
            { val: 'Reaching body recomposition', label: 'Body Recomposition' },
            { val: 'increasing_strength', label: 'Increasing Maximum Strength' },
            { val: 'building_muscle', label: 'Building Muscle' },
            { val: 'losing_weight', label: 'Losing Weight' },
            { val: 'reducing_body_fat', label: 'Reducing Body Fat' },
            { val: 'improving_endurance', label: 'Improving Endurance' },
            { val: 'casual', label: 'Casual / Flexible Lifestyle' },
        ];

        let goalSelectHtml = goalOptions.map(g => {
            const isSel = targets.primaryGoal === g.val ? 'selected' : '';
            return `<option value="${g.val}" ${isSel}>${g.label}</option>`;
        }).join('');

        Swal.fire({
            title: isTrainer ? 'Adjust Client Target Metrics' : 'Adjust Fitness Targets',
            width: '600px',
            html: `
                <form id="targetMetricsForm" method="post" style="text-align: left; margin-top: 10px;">
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="update_goal_targets">
                    ${isTrainer ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}

                    <div style="margin-bottom: 14px;">
                        <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:5px; text-transform:uppercase; letter-spacing:0.03em;">Primary Fitness Goal</label>
                        <select name="primary_goal" class="form-control" style="width:100%; box-sizing:border-box; border-radius:8px; height:42px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); font-weight:600; padding:0 12px;">
                            ${goalSelectHtml}
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Target Weight (kg)</label>
                            <input type="number" step="0.1" name="target_weight_kg" value="${targets.targetWeight || ''}" class="form-control" placeholder="e.g. 75.0" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Target Body Fat (%)</label>
                            <input type="number" step="0.1" name="target_body_fat_percent" value="${targets.targetBodyFat || ''}" class="form-control" placeholder="e.g. 15.0" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Target Waist (cm)</label>
                            <input type="number" step="0.5" name="target_waist_cm" value="${targets.targetWaist || ''}" class="form-control" placeholder="e.g. 80.0" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Target Arm (cm)</label>
                            <input type="number" step="0.5" name="target_arm_cm" value="${targets.targetArm || ''}" class="form-control" placeholder="e.g. 38.0" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Target Chest (cm)</label>
                            <input type="number" step="0.5" name="target_chest_cm" value="${targets.targetChest || ''}" class="form-control" placeholder="e.g. 102.0" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                        <div>
                            <label style="display:block; color:var(--muted); font-size:12px; font-weight:700; margin-bottom:4px;">Weekly Workouts Target</label>
                            <input type="number" min="1" max="7" name="weekly_workout_target" value="${targets.weeklyWorkouts || '3'}" class="form-control" placeholder="3" style="width:100%; box-sizing:border-box; border-radius:8px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px;">
                        </div>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Targets',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const f = document.getElementById('targetMetricsForm');
                f.submit();
            }
        });
    }

    // Modal: U.S. Navy Method Body Fat Calculator
    function calculateBodyFat(e) {
        if (e) e.preventDefault();
        
        const currentForm = document.getElementById('progressForm');
        let savedState = null;
        if (currentForm) {
            savedState = {
                log_date: currentForm.log_date.value,
                weight_kg: currentForm.weight_kg.value,
                body_fat_percent: currentForm.body_fat_percent.value,
                neck_cm: currentForm.neck_cm ? currentForm.neck_cm.value : '',
                waist_cm: currentForm.waist_cm.value,
                chest_cm: currentForm.chest_cm.value,
                arm_cm: currentForm.arm_cm.value,
                hips_cm: currentForm.hips_cm ? currentForm.hips_cm.value : '',
                notes: currentForm.notes.value
            };
        }
        
        const savedBfSettings = JSON.parse(localStorage.getItem('fittracks_bf_settings') || '{}');
        const defaultGender = savedBfSettings.gender || (FT_DEFAULTS.gender || 'male');
        const defaultHeight = savedBfSettings.height || (FT_DEFAULTS.height || '');
        const defaultNeck = savedBfSettings.neck || (FT_DEFAULTS.neck || '');
        const defaultHip = savedBfSettings.hip || (FT_DEFAULTS.hips || '');
        
        Swal.fire({
            title: 'Estimate Body Fat %',
            html: `
                <div style="text-align: left; font-size: 13.5px; color: var(--muted); margin-bottom: 15px;">
                    This estimate uses the standard <strong>U.S. Navy Circumference Formula</strong>.
                </div>
                <form id="bfCalcForm" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Biological Sex *
                            <select name="gender" class="form-control" style="width: 100%; box-sizing: border-box;" required>
                                <option value="male" ${defaultGender === 'male' ? 'selected' : ''}>Male</option>
                                <option value="female" ${defaultGender === 'female' ? 'selected' : ''}>Female</option>
                            </select>
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Height (cm) *
                            <input name="height" type="number" step="0.1" class="form-control" placeholder="e.g. 175" value="${defaultHeight}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Neck (cm) *
                            <input name="neck" type="number" step="0.1" class="form-control" placeholder="e.g. 38" value="${defaultNeck}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Waist (cm) *
                            <input name="waist" type="number" step="0.1" class="form-control" placeholder="e.g. 82" value="${savedState ? savedState.waist_cm : (FT_DEFAULTS.waist || '')}" required style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                    
                    <div id="hipContainer" style="display:${defaultGender === 'female' ? 'flex' : 'none'}; gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Hips (cm) *
                            <input name="hip" type="number" step="0.1" class="form-control" placeholder="Widest part (for females)" value="${defaultHip}" style="width: 100%; box-sizing: border-box;">
                        </label>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Calculate',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            didOpen: () => {
                const form = document.getElementById('bfCalcForm');
                form.gender.addEventListener('change', (e) => {
                    document.getElementById('hipContainer').style.display = e.target.value === 'female' ? 'flex' : 'none';
                });
            },
            preConfirm: () => {
                const form = document.getElementById('bfCalcForm');
                const gender = form.gender.value;
                const height = parseFloat(form.height.value);
                const neck = parseFloat(form.neck.value);
                const waist = parseFloat(form.waist.value);
                const hip = parseFloat(form.hip.value);
                
                if (!height || !neck || !waist || (gender === 'female' && !hip)) {
                    Swal.showValidationMessage('Please fill all required measurements');
                    return false;
                }
                
                localStorage.setItem('fittracks_bf_settings', JSON.stringify({ gender, height, neck, hip }));
                
                if (gender === 'male' && waist <= neck) {
                    Swal.showValidationMessage('Waist measurement must be greater than neck measurement.');
                    return false;
                }
                
                if (gender === 'female' && (waist + hip) <= neck) {
                    Swal.showValidationMessage('Waist + Hip measurement must be greater than neck measurement.');
                    return false;
                }
                
                let bodyFat = 0;
                if (gender === 'male') {
                    bodyFat = 495 / (1.0324 - 0.19077 * Math.log10(waist - neck) + 0.15456 * Math.log10(height)) - 450;
                } else {
                    bodyFat = 495 / (1.29579 - 0.35004 * Math.log10(waist + hip - neck) + 0.22100 * Math.log10(height)) - 450;
                }
                
                if (isNaN(bodyFat) || bodyFat < 2 || bodyFat > 60) {
                    if (bodyFat < 2) {
                        Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (below 2%). A waist of ${waist} cm is too small compared to neck (${neck} cm) for this formula. Please check your waist circumference.`);
                    } else if (bodyFat > 60) {
                        Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (above 60%). Please verify your measurements.`);
                    } else {
                        Swal.showValidationMessage('Invalid measurements. Please verify numbers in centimeters.');
                    }
                    return false;
                }
                
                return {
                    bf: bodyFat.toFixed(1),
                    neck: neck,
                    waist: waist,
                    hip: hip
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const res = result.value;
                if (savedState) {
                    savedState.body_fat_percent = res.bf;
                    savedState.neck_cm = res.neck;
                    savedState.waist_cm = res.waist;
                    if (res.hip) savedState.hips_cm = res.hip;
                }
                logProgress(savedState || {
                    body_fat_percent: res.bf,
                    neck_cm: res.neck,
                    waist_cm: res.waist,
                    hips_cm: res.hip
                });
            } else if (result.dismiss === Swal.DismissReason.cancel && savedState) {
                logProgress(savedState);
            }
        });
    }

    // Modal: Comprehensive Progress & Measurements Log
    function logProgress(savedState = null) {
        const defaultDate = savedState && savedState.log_date !== undefined ? savedState.log_date : (FT_DEFAULTS.date || '');
        const defaultWeight = savedState && savedState.weight_kg !== undefined ? savedState.weight_kg : (FT_DEFAULTS.weight || '');
        const defaultBf = savedState && savedState.body_fat_percent !== undefined ? savedState.body_fat_percent : (FT_DEFAULTS.bodyFat || '');
        const defaultNeck = savedState && savedState.neck_cm !== undefined ? savedState.neck_cm : (FT_DEFAULTS.neck || '');
        const defaultWaist = savedState && savedState.waist_cm !== undefined ? savedState.waist_cm : (FT_DEFAULTS.waist || '');
        const defaultChest = savedState && savedState.chest_cm !== undefined ? savedState.chest_cm : (FT_DEFAULTS.chest || '');
        const defaultArm = savedState && savedState.arm_cm !== undefined ? savedState.arm_cm : (FT_DEFAULTS.arm || '');
        const defaultHips = savedState && savedState.hips_cm !== undefined ? savedState.hips_cm : (FT_DEFAULTS.hips || '');
        const defaultNotes = savedState && savedState.notes !== undefined ? savedState.notes : '';

        Swal.fire({
            title: 'Log Progress & Measurements',
            html: `
                <form id="progressForm" method="post" style="text-align: left; display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="log_progress">
                    ${FT_CURRENT_USER_ROLE === 'trainer' ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}
                    
                    <div style="display:flex;gap:12px;">
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Date *</label>
                            <input name="log_date" type="date" class="form-control" required value="${defaultDate}" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Weight (kg) *</label>
                            <input name="weight_kg" type="number" step="0.01" class="form-control" placeholder="e.g. 74.0" value="${defaultWeight}" required style="width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:12px;align-items:flex-end;">
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; min-height:18px;">
                                <label style="color: var(--muted); font-size: 13px; font-weight:600; margin:0; white-space:nowrap;">Body Fat %</label>
                                <a href="#" onclick="calculateBodyFat(event)" style="color:var(--lime); text-decoration:none; font-weight:700; font-size:11px; white-space:nowrap; margin-left:6px;">Calculate</a>
                            </div>
                            <input name="body_fat_percent" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultBf}" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; min-height:18px;">
                                <label style="color: var(--muted); font-size: 13px; font-weight:600; margin:0; white-space:nowrap;">Neck (cm)</label>
                            </div>
                            <input name="neck_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultNeck}" style="width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Chest (cm)</label>
                            <input name="chest_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultChest}" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Waist (cm)</label>
                            <input name="waist_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultWaist}" style="width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div style="display:flex;gap:12px;">
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Arms (cm)</label>
                            <input name="arm_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultArm}" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="flex:1; display:flex; flex-direction:column; min-width:0;">
                            <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Hips (cm)</label>
                            <input name="hips_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultHips}" style="width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; min-width:0;">
                        <label style="color: var(--muted); font-size: 13px; font-weight:600; margin-bottom:5px; display:block;">Notes</label>
                        <input name="notes" class="form-control" placeholder="Any workout or energy notes" value="${defaultNotes}" style="width: 100%; box-sizing: border-box;">
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Progress',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const form = document.getElementById('progressForm');
                if (!form.log_date.value || !form.weight_kg.value) {
                    Swal.showValidationMessage('Please fill all required fields (Date & Weight)');
                    return false;
                }

                const waist = parseFloat(form.waist_cm.value);
                const chest = parseFloat(form.chest_cm.value);
                const arm = parseFloat(form.arm_cm.value);

                if (!isNaN(waist) && waist > 0 && waist < 45) {
                    Swal.showValidationMessage('Waist measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                if (!isNaN(chest) && chest > 0 && chest < 55) {
                    Swal.showValidationMessage('Chest measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                if (!isNaN(arm) && arm > 0 && arm < 18) {
                    Swal.showValidationMessage('Arm measurement seems too small. Please ensure you entered it in centimeters (cm), not inches.');
                    return false;
                }

                form.submit();
            }
        });
    }

    // Toggle Mobile Floating Action Button (FAB) Speed Dial
    function toggleHubFab(forceState) {
        const container = document.getElementById('hubFabContainer');
        const backdrop = document.getElementById('hubFabBackdrop');
        if (!container || !backdrop) return;

        const isActive = (typeof forceState === 'boolean') ? forceState : !container.classList.contains('active');
        if (isActive) {
            container.classList.add('active');
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        } else {
            container.classList.remove('active');
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            toggleHubFab(false);
        }
    });
