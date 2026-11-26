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
        const tabs = ['overview', 'progress', 'nutrition', 'notes'];
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
        if (['overview', 'progress', 'nutrition', 'notes'].includes(hash)) {
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
        measurements: {
            label: 'Measurements (cm)',
            chartTitle: 'Waist, Arms & Chest (cm) over time',
            color: '#38bdf8',
            bgColor: 'rgba(56, 189, 248, 0.12)',
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
    if (currentMetric === 'waist' || currentMetric === 'arm' || currentMetric === 'chest') {
        currentMetric = 'measurements';
    }
    let currentTimeframe = 'all';
    let lastFilteredChartData = rawChartData;

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getTimeframeLabel() {
        if (currentTimeframe === '1m') return 'Past 30 days';
        if (currentTimeframe === '3m') return 'Past 3 months';
        if (currentTimeframe === '6m') return 'Past 6 months';
        if (currentTimeframe === 'custom') {
            const f = document.getElementById('chart-custom-from')?.value || '';
            const t = document.getElementById('chart-custom-to')?.value || '';
            return (f && t) ? `${f} to ${t}` : 'Custom range';
        }
        return 'All recorded logs';
    }

    function getGoalDeltaColor(key, delta) {
        if (delta === null || delta === 0) return 'var(--ink)';
        const dirs = (FT_CONFIG.goalMeta && FT_CONFIG.goalMeta.directions) ? FT_CONFIG.goalMeta.directions : {};
        const dir = dirs[key] || 'neutral';
        if (dir === 'up') return delta > 0 ? '#22c55e' : '#f59e0b';
        if (dir === 'down') return delta < 0 ? '#22c55e' : '#f59e0b';
        if (dir === 'stable') return Math.abs(delta) <= 0.5 ? '#22c55e' : 'var(--ink)';
        return 'var(--ink)';
    }

    function computeSeriesDelta(data, key) {
        const pts = data.filter(d => d[key] !== null && d[key] !== undefined && Number(d[key]) > 0);
        if (pts.length < 2) return null;
        return Number((Number(pts[pts.length - 1][key]) - Number(pts[0][key])).toFixed(1));
    }

    function updateSummaryStatBoxes(filteredData) {
        const rangeNote = document.getElementById('chartSummaryRangeNote');
        if (rangeNote) {
            rangeNote.textContent = currentTimeframe === 'all' ? '' : `(${getTimeframeLabel()})`;
        }

        const wEl = document.getElementById('chartSummaryWeight');
        if (wEl) {
            const wDelta = computeSeriesDelta(filteredData, 'weight');
            wEl.textContent = wDelta !== null ? `${wDelta > 0 ? '+' : ''}${wDelta} kg` : '—';
            wEl.style.color = getGoalDeltaColor('weight', wDelta);
        }

        const bfEl = document.getElementById('chartSummaryBf');
        if (bfEl) {
            const bfDelta = computeSeriesDelta(filteredData, 'body_fat');
            bfEl.textContent = bfDelta !== null ? `${bfDelta > 0 ? '+' : ''}${bfDelta}%` : '—';
            bfEl.style.color = getGoalDeltaColor('body_fat', bfDelta);
        }

        const focusEl = document.getElementById('chartSummaryFocus');
        const focusLabelEl = document.getElementById('chartSummaryFocusLabel');
        if (focusEl && focusLabelEl) {
            const labelTxt = (focusLabelEl.textContent || '').toLowerCase();
            let key = 'bmi';
            let unit = '';
            if (labelTxt.includes('arm')) { key = 'arm'; unit = ' cm'; }
            else if (labelTxt.includes('chest')) { key = 'chest'; unit = ' cm'; }
            else if (labelTxt.includes('waist')) { key = 'waist'; unit = ' cm'; }

            const fDelta = computeSeriesDelta(filteredData, key);
            focusEl.textContent = fDelta !== null ? `${fDelta > 0 ? '+' : ''}${fDelta}${unit}` : '—';
            focusEl.style.color = getGoalDeltaColor(key, fDelta);
        }
    }

    function updateChartInsight(filteredData) {
        const banner = document.getElementById('chartInsightBanner');
        const data = Array.isArray(filteredData) ? filteredData : [];
        updateSummaryStatBoxes(data);
        if (!banner) return;

        const goalMeta = FT_CONFIG.goalMeta || {};
        const targets = FT_CONFIG.targets || {};
        const dirs = goalMeta.directions || {};
        const isMgr = !!goalMeta.isManagerView;
        const memberName = goalMeta.memberName || 'This member';
        const goalTitle = goalMeta.title || 'General Fitness';
        const rangeLabel = getTimeframeLabel();

        const whoIs = isMgr ? `${memberName} is` : 'You are';
        const whoIsLower = isMgr ? `${memberName} is` : 'you are';
        const poss = isMgr ? `${memberName}'s` : 'your';

        const fmtSigned = (v, unit = '') => `${v > 0 ? '+' : ''}${v.toFixed(1)}${unit ? ' ' + unit : ''}`;

        let tone = 'neutral';
        let badge = 'Trend';
        let text = '';

        if (currentMetric === 'measurements') {
            const ci = window.hubChartInstance;
            const specs = [
                { idx: 0, key: 'waist', name: 'Waist', target: parseFloat(targets.targetWaist || '') || 0 },
                { idx: 1, key: 'arm', name: 'Arms', target: parseFloat(targets.targetArm || '') || 0 },
                { idx: 2, key: 'chest', name: 'Chest', target: parseFloat(targets.targetChest || '') || 0 }
            ];

            const parts = [];
            let goodCount = 0;
            let badCount = 0;
            let flatCount = 0;

            specs.forEach(sp => {
                if (ci && ci.data && ci.data.datasets && ci.data.datasets[sp.idx] && !ci.isDatasetVisible(sp.idx)) {
                    return;
                }
                const pts = data.filter(d => d[sp.key] !== null && d[sp.key] !== undefined && Number(d[sp.key]) > 0);
                if (pts.length < 2) return;

                const first = Number(pts[0][sp.key]);
                const last = Number(pts[pts.length - 1][sp.key]);
                const delta = Number((last - first).toFixed(1));
                const dir = dirs[sp.key] || 'neutral';
                parts.push(`${sp.name} ${fmtSigned(delta, 'cm')} (${first.toFixed(1)} → ${last.toFixed(1)} cm)`);

                if (dir === 'down') {
                    if (delta <= -0.3) goodCount++;
                    else if (delta >= 0.4) badCount++;
                    else flatCount++;
                } else if (dir === 'up') {
                    if (delta >= 0.3) goodCount++;
                    else if (delta <= -0.4) badCount++;
                    else flatCount++;
                } else {
                    if (Math.abs(delta) < 0.3) flatCount++;
                }
            });

            if (parts.length === 0) {
                tone = 'neutral';
                badge = 'Needs More Data';
                text = `Not enough measurement logs in ${rangeLabel}. ${isMgr ? `Have ${memberName} log` : 'Log'} at least 2 entries to see circumference progress.`;
            } else {
                if (badCount > 0 && goodCount === 0) {
                    tone = 'bad';
                    badge = 'Off Track';
                    text = `${rangeLabel}: ${parts.join(', ')} — moving away from ${poss} ${goalTitle} target.`;
                } else if (goodCount > 0) {
                    tone = 'good';
                    badge = 'Progressing';
                    text = `${rangeLabel}: ${parts.join(', ')} — moving in the right direction for ${goalTitle}.`;
                } else if (flatCount > 0) {
                    tone = 'warn';
                    badge = 'Maintaining / Plateau';
                    text = `${rangeLabel}: ${parts.join(', ')} — measurements are holding steady.`;
                } else {
                    tone = 'neutral';
                    badge = 'Trend Summary';
                    text = `${rangeLabel}: ${parts.join(', ')}.`;
                }
            }
        } else {
            const cfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
            const unit = cfg.unit ? cfg.unit : '';
            const pts = data.filter(d => d[currentMetric] !== null && d[currentMetric] !== undefined && Number(d[currentMetric]) > 0);

            if (pts.length < 2) {
                tone = 'neutral';
                badge = 'Needs More Data';
                const singleNote = pts.length === 1 ? `Latest logged value is ${Number(pts[0][currentMetric]).toFixed(1)}${unit ? ' ' + unit : ''}. ` : '';
                text = `${singleNote}${rangeLabel} has fewer than 2 ${cfg.label} entries — ${isMgr ? 'at least 2 logs are needed' : 'log one more entry'} to evaluate whether ${whoIsLower} progressing.`;
            } else {
                const first = Number(pts[0][currentMetric]);
                const last = Number(pts[pts.length - 1][currentMetric]);
                const delta = Number((last - first).toFixed(1));
                const spanDays = Math.max(0, Math.round((new Date(pts[pts.length - 1].date) - new Date(pts[0].date)) / 86400000));
                const weeklyRate = spanDays >= 7 ? Number((delta / (spanDays / 7)).toFixed(2)) : null;

                if (currentMetric === 'bmi') {
                    let bmiCat = 'Normal range';
                    if (last < 18.5) bmiCat = 'Underweight';
                    else if (last <= 24.9) bmiCat = 'Normal range';
                    else if (last <= 29.9) bmiCat = 'Overweight';
                    else bmiCat = 'Obese';

                    const inNormal = last >= 18.5 && last <= 24.9;
                    const movingTowardNormal = (first > 24.9 && delta <= -0.2) || (first < 18.5 && delta >= 0.2);
                    const movingAwayFromNormal = (last > 24.9 && delta >= 0.2) || (last < 18.5 && delta <= -0.2);

                    if (inNormal && Math.abs(delta) < 0.2) {
                        tone = 'good';
                        badge = 'Healthy Range';
                    } else if (inNormal || movingTowardNormal) {
                        tone = 'good';
                        badge = 'Progressing';
                    } else if (movingAwayFromNormal) {
                        tone = 'bad';
                        badge = 'Off Track';
                    } else {
                        tone = 'warn';
                        badge = 'Plateau';
                    }
                    text = `${rangeLabel}: BMI shifted from ${first.toFixed(1)} to ${last.toFixed(1)} (${fmtSigned(delta)}), currently in the ${bmiCat} bracket.`;
                } else {
                    const dir = dirs[currentMetric] || 'neutral';
                    const noise = currentMetric === 'weight' ? 0.3 : 0.4;
                    const targetVal = currentMetric === 'weight'
                        ? (parseFloat(targets.targetWeight || '') || 0)
                        : (parseFloat(targets.targetBodyFat || '') || 0);

                    const targetHit = targetVal > 0 && ((dir === 'down' && last <= targetVal) || (dir === 'up' && last >= targetVal));
                    let paceText = '';
                    if (weeklyRate !== null) {
                        paceText = ` (~${fmtSigned(weeklyRate, unit + '/wk')})`;
                    }

                    let etaText = '';
                    if (!targetHit && targetVal > 0 && weeklyRate !== null && Math.abs(weeklyRate) >= 0.05) {
                        const rem = targetVal - last;
                        if ((rem > 0 && weeklyRate > 0) || (rem < 0 && weeklyRate < 0)) {
                            const etaWks = Math.ceil(Math.abs(rem) / Math.abs(weeklyRate));
                            if (etaWks >= 1 && etaWks <= 104) {
                                etaText = ` At this pace, ~${etaWks} wks from ${poss} ${targetVal} ${unit} target.`;
                            }
                        }
                    }

                    if (targetHit) {
                        tone = 'good';
                        badge = 'Goal Reached';
                        text = `${rangeLabel}: ${cfg.label} moved from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)}), meeting the ${targetVal} ${unit} target.`;
                    } else if (dir === 'down') {
                        if (delta <= -noise) {
                            tone = 'good';
                            badge = 'Progressing';
                            text = `${rangeLabel}: ${cfg.label} dropped from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)})${paceText} — moving toward ${poss} ${goalTitle} goal.${etaText}`;
                        } else if (delta >= noise) {
                            tone = 'bad';
                            badge = 'Off Track';
                            text = `${rangeLabel}: ${cfg.label} rose from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)})${paceText} — moving opposite to ${poss} ${goalTitle} goal.`;
                        } else {
                            tone = 'warn';
                            badge = 'Maintaining / Plateau';
                            text = `${rangeLabel}: ${cfg.label} stayed flat (${first.toFixed(1)} → ${last.toFixed(1)} ${unit}, ${fmtSigned(delta, unit)}).`;
                        }
                    } else if (dir === 'up') {
                        if (delta >= noise) {
                            tone = 'good';
                            badge = 'Progressing';
                            text = `${rangeLabel}: ${cfg.label} increased from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)})${paceText} — aligned with ${poss} ${goalTitle} goal.${etaText}`;
                        } else if (delta <= -noise) {
                            tone = 'bad';
                            badge = 'Off Track';
                            text = `${rangeLabel}: ${cfg.label} dropped from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)})${paceText} — moving opposite to ${poss} ${goalTitle} goal.`;
                        } else {
                            tone = 'warn';
                            badge = 'Maintaining / Plateau';
                            text = `${rangeLabel}: ${cfg.label} stayed flat (${first.toFixed(1)} → ${last.toFixed(1)} ${unit}, ${fmtSigned(delta, unit)}).`;
                        }
                    } else if (dir === 'stable') {
                        if (Math.abs(delta) <= 1.0) {
                            tone = 'good';
                            badge = 'Steady Scale';
                            text = `${rangeLabel}: ${cfg.label} held steady (${first.toFixed(1)} → ${last.toFixed(1)} ${unit}, ${fmtSigned(delta, unit)}) — ideal while recomposing waist/body fat.`;
                        } else {
                            tone = 'warn';
                            badge = 'Shifting';
                            text = `${rangeLabel}: ${cfg.label} shifted from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)}). Check Waist/Body Fat to confirm composition direction.`;
                        }
                    } else {
                        tone = 'neutral';
                        badge = 'Trend Summary';
                        text = `${rangeLabel}: ${cfg.label} went from ${first.toFixed(1)} to ${last.toFixed(1)} ${unit} (${fmtSigned(delta, unit)})${paceText}.`;
                    }
                }
            }
        }

        banner.className = `chart-insight-banner insight-${tone}`;
        banner.innerHTML = `<span class="insight-badge">${escHtml(badge)}</span><span class="insight-text">${escHtml(text)}</span>`;
    }

    function setChartMetric(metric) {
        if (metric === 'waist' || metric === 'arm' || metric === 'chest') {
            metric = 'measurements';
        }
        currentMetric = metric;
        ['weight', 'body_fat', 'measurements', 'bmi'].forEach(m => {
            const btn = document.getElementById('m-btn-' + m);
            if (btn) btn.classList.toggle('active', m === metric);
        });

        const indEl = document.getElementById('chartMeasurementsIndicators');
        if (indEl) {
            indEl.style.display = (metric === 'measurements') ? 'inline-flex' : 'none';
        }

        const cfg = METRIC_CONFIG[metric] || METRIC_CONFIG.weight;
        const labelEl = document.getElementById('chartActiveMetricLabel');
        if (labelEl) labelEl.textContent = cfg.chartTitle || 'Progress over time';

        renderFilteredChart();
    }

    function setChartTimeframe(tf) {
        currentTimeframe = tf;
        const customWrap = document.getElementById('chart-custom-date-wrap');
        if (customWrap && tf !== 'custom') {
            customWrap.style.display = 'none';
        }
        ['1m', '3m', '6m', 'all', 'custom'].forEach(t => {
            const btn = document.getElementById('tf-btn-' + t);
            if (btn) btn.classList.toggle('active', t === tf);
        });
        renderFilteredChart();
    }

    let fpFrom = null;
    let fpTo = null;

    function getLocalTodayStr() {
        const now = new Date();
        return [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0')
        ].join('-');
    }

    function initCustomDatePickers() {
        const fromInput = document.getElementById('chart-custom-from');
        const toInput = document.getElementById('chart-custom-to');
        if (!fromInput || !toInput || typeof flatpickr !== 'function') return;

        const todayStr = getLocalTodayStr();
        const earliestStr = rawChartData.length > 0 ? rawChartData[0].date : todayStr;

        if (!fpFrom) {
            fpFrom = flatpickr(fromInput, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'm/d/Y',
                altInputClass: 'custom-date-input',
                disableMobile: true,
                minDate: earliestStr,
                maxDate: todayStr,
                defaultDate: fromInput.value || earliestStr,
                onChange: () => onCustomFromDateChange()
            });
        }
        if (!fpTo) {
            fpTo = flatpickr(toInput, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'm/d/Y',
                altInputClass: 'custom-date-input',
                disableMobile: true,
                minDate: fromInput.value || earliestStr,
                maxDate: todayStr,
                defaultDate: todayStr,
                onChange: () => onCustomToDateChange()
            });
        }
    }

    function toggleCustomDatePicker() {
        const customWrap = document.getElementById('chart-custom-date-wrap');
        if (!customWrap) return;
        const isShowing = customWrap.style.display !== 'none';
        if (isShowing && currentTimeframe === 'custom') {
            setChartTimeframe('all');
        } else {
            customWrap.style.display = 'flex';
            currentTimeframe = 'custom';
            ['1m', '3m', '6m', 'all'].forEach(t => {
                const btn = document.getElementById('tf-btn-' + t);
                if (btn) btn.classList.remove('active');
            });
            const customBtn = document.getElementById('tf-btn-custom');
            if (customBtn) customBtn.classList.add('active');

            initCustomDatePickers();

            const fromInput = document.getElementById('chart-custom-from');
            const toInput = document.getElementById('chart-custom-to');
            const todayStr = getLocalTodayStr();
            const earliestStr = rawChartData.length > 0 ? rawChartData[0].date : todayStr;

            if (fromInput) {
                const fromVal = (!fromInput.value || fromInput.value < earliestStr || fromInput.value > todayStr)
                    ? earliestStr
                    : fromInput.value;
                if (fpFrom) {
                    fpFrom.set('minDate', earliestStr);
                    fpFrom.set('maxDate', todayStr);
                    fpFrom.setDate(fromVal, false);
                } else {
                    fromInput.value = fromVal;
                }
            }
            if (toInput) {
                const minTo = (fromInput && fromInput.value) ? fromInput.value : earliestStr;
                if (fpTo) {
                    fpTo.set('minDate', minTo);
                    fpTo.set('maxDate', todayStr);
                    fpTo.setDate(todayStr, false);
                } else {
                    toInput.value = todayStr;
                }
            }
            applyCustomDateRange();
        }
    }

    function onCustomFromDateChange() {
        const fromInput = document.getElementById('chart-custom-from');
        const toInput = document.getElementById('chart-custom-to');
        if (!fromInput || !toInput) return;

        const todayStr = getLocalTodayStr();
        const earliestStr = rawChartData.length > 0 ? rawChartData[0].date : todayStr;

        if (fromInput.value && fromInput.value < earliestStr) {
            if (fpFrom) fpFrom.setDate(earliestStr, false);
            else fromInput.value = earliestStr;
        }
        if (fromInput.value && fromInput.value > todayStr) {
            if (fpFrom) fpFrom.setDate(todayStr, false);
            else fromInput.value = todayStr;
        }
        if (fromInput.value && toInput.value && toInput.value < fromInput.value) {
            if (fpTo) fpTo.setDate(fromInput.value, false);
            else toInput.value = fromInput.value;
        }
        if (fpTo) fpTo.set('minDate', fromInput.value || earliestStr);
        applyCustomDateRange();
    }

    function onCustomToDateChange() {
        const fromInput = document.getElementById('chart-custom-from');
        const toInput = document.getElementById('chart-custom-to');
        if (!fromInput || !toInput) return;

        const todayStr = getLocalTodayStr();
        const earliestStr = rawChartData.length > 0 ? rawChartData[0].date : todayStr;

        if (toInput.value && toInput.value > todayStr) {
            if (fpTo) fpTo.setDate(todayStr, false);
            else toInput.value = todayStr;
        }
        if (toInput.value && toInput.value < earliestStr) {
            if (fpTo) fpTo.setDate(earliestStr, false);
            else toInput.value = earliestStr;
        }
        if (fromInput.value && toInput.value && fromInput.value > toInput.value) {
            if (fpFrom) fpFrom.setDate(toInput.value, false);
            else fromInput.value = toInput.value;
        }
        if (fpFrom) fpFrom.set('maxDate', toInput.value || todayStr);
        applyCustomDateRange();
    }

    function applyCustomDateRange() {
        renderFilteredChart();
    }

    window.setChartTimeframe = setChartTimeframe;
    window.toggleCustomDatePicker = toggleCustomDatePicker;
    window.applyCustomDateRange = applyCustomDateRange;
    window.onCustomFromDateChange = onCustomFromDateChange;
    window.onCustomToDateChange = onCustomToDateChange;

    // Toggle visibility of individual measurement lines (Waist, Arms, Chest)
    window.toggleMeasurementDataset = function(index) {
        if (!window.hubChartInstance || currentMetric !== 'measurements') return;
        const badge = document.getElementById('ind-btn-' + index);
        if (badge && (badge.disabled || badge.classList.contains('is-disabled'))) return;

        const ci = window.hubChartInstance;
        const meta = ci.getDatasetMeta(index);
        meta.hidden = meta.hidden === null ? !ci.data.datasets[index].hidden : null;
        ci.update();

        if (badge) {
            badge.style.opacity = meta.hidden ? '0.45' : '1';
            badge.style.filter = meta.hidden ? 'grayscale(0.7)' : 'none';
            badge.style.textDecoration = meta.hidden ? 'line-through' : 'none';
        }
        updateChartInsight(lastFilteredChartData);
    };

    function hexToRgba(hex, alpha = 1) {
        let c = hex.replace('#', '');
        if (c.length === 3) {
            c = c.split('').map(x => x + x).join('');
        }
        const num = parseInt(c, 16);
        const r = (num >> 16) & 255;
        const g = (num >> 8) & 255;
        const b = num & 255;
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function getGradient(ctx, chartArea, colorHex, topAlpha = 0.32, bottomAlpha = 0.0) {
        if (!chartArea || !ctx) return hexToRgba(colorHex, topAlpha);
        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, hexToRgba(colorHex, topAlpha));
        gradient.addColorStop(0.65, hexToRgba(colorHex, topAlpha * 0.3));
        gradient.addColorStop(1, hexToRgba(colorHex, bottomAlpha));
        return gradient;
    }

    function getDatasetsForMetric(metric, filteredData, isDark) {
        if (metric === 'measurements' || metric === 'waist' || metric === 'arm' || metric === 'chest') {
            const hasWaist = (FT_CONFIG.hasMeasurements && FT_CONFIG.hasMeasurements.waist !== undefined)
                ? FT_CONFIG.hasMeasurements.waist
                : filteredData.some(d => d.waist !== null && d.waist !== undefined && d.waist > 0);
            const hasArm = (FT_CONFIG.hasMeasurements && FT_CONFIG.hasMeasurements.arm !== undefined)
                ? FT_CONFIG.hasMeasurements.arm
                : filteredData.some(d => d.arm !== null && d.arm !== undefined && d.arm > 0);
            const hasChest = (FT_CONFIG.hasMeasurements && FT_CONFIG.hasMeasurements.chest !== undefined)
                ? FT_CONFIG.hasMeasurements.chest
                : filteredData.some(d => d.chest !== null && d.chest !== undefined && d.chest > 0);

            const waistColor = isDark ? '#38bdf8' : '#0284c7';
            const armColor = isDark ? '#c084fc' : '#9333ea';
            const chestColor = isDark ? '#fbbf24' : '#d97706';

            return [
                {
                    label: 'Waist',
                    data: filteredData.map(d => d.waist),
                    hidden: !hasWaist,
                    borderColor: waistColor,
                    glowColor: isDark ? 'rgba(56, 189, 248, 0.75)' : 'rgba(2, 132, 199, 0.28)',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 8.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: waistColor,
                    pointBorderWidth: 2.8,
                    tension: 0.38,
                    cubicInterpolationMode: 'monotone',
                    fill: false,
                    spanGaps: true
                },
                {
                    label: 'Arms',
                    data: filteredData.map(d => d.arm),
                    hidden: !hasArm,
                    borderColor: armColor,
                    glowColor: isDark ? 'rgba(192, 132, 252, 0.75)' : 'rgba(147, 51, 234, 0.28)',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 8.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: armColor,
                    pointBorderWidth: 2.8,
                    tension: 0.38,
                    cubicInterpolationMode: 'monotone',
                    fill: false,
                    spanGaps: true
                },
                {
                    label: 'Chest',
                    data: filteredData.map(d => d.chest),
                    hidden: !hasChest,
                    borderColor: chestColor,
                    glowColor: isDark ? 'rgba(251, 191, 36, 0.75)' : 'rgba(217, 119, 6, 0.28)',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 8.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: chestColor,
                    pointBorderWidth: 2.8,
                    tension: 0.38,
                    cubicInterpolationMode: 'monotone',
                    fill: false,
                    spanGaps: true
                }
            ];
        }

        const cfg = METRIC_CONFIG[metric] || METRIC_CONFIG.weight;
        return [{
            label: cfg.label,
            data: filteredData.map(d => d[metric]),
            borderColor: cfg.color,
            glowColor: hexToRgba(cfg.color, 0.8),
            backgroundColor: function(context) {
                const chart = context.chart;
                return chart ? getGradient(chart.ctx, chart.chartArea, cfg.color, 0.30, 0.0) : cfg.bgColor;
            },
            borderWidth: 3.2,
            pointRadius: 5,
            pointHoverRadius: 8.5,
            pointBackgroundColor: '#ffffff',
            pointBorderColor: cfg.color,
            pointBorderWidth: 2.8,
            tension: 0.38,
            cubicInterpolationMode: 'monotone',
            fill: true,
            spanGaps: true
        }];
    }

    function renderFilteredChart() {
        if (!window.hubChartInstance) return;

        let filtered = rawChartData;
        if (currentTimeframe === 'custom') {
            const fromVal = document.getElementById('chart-custom-from')?.value;
            const toVal = document.getElementById('chart-custom-to')?.value;
            filtered = rawChartData.filter(d => {
                if (fromVal && d.date < fromVal) return false;
                if (toVal && d.date > toVal) return false;
                return true;
            });
        } else {
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

            if (cutoffDate) {
                filtered = rawChartData.filter(d => new Date(d.date) >= cutoffDate);
            }
        }

        const countInfo = document.getElementById('chart-custom-count-info');
        if (countInfo) {
            if (currentTimeframe === 'custom') {
                const count = filtered.length;
                if (count === 0) {
                    countInfo.textContent = 'No logs in this range';
                } else {
                    countInfo.textContent = `${count} ${count === 1 ? 'log' : 'logs'} (earliest: ${filtered[0].label})`;
                }
            } else {
                countInfo.textContent = '';
            }
        }

        const labels = filtered.map(d => d.label);
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';

        lastFilteredChartData = filtered;
        window.hubChartInstance.data.labels = labels;
        window.hubChartInstance.data.datasets = getDatasetsForMetric(currentMetric, filtered, isDark);
        window.hubChartInstance.update();
        updateChartInsight(filtered);
    }

    function initHubChart() {
        const ctx = document.getElementById('hubTrendChart');
        if (!ctx) return;

        if (currentMetric === 'waist' || currentMetric === 'arm' || currentMetric === 'chest') {
            currentMetric = 'measurements';
        }
        const cfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
        const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        const gridColor  = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.04)';
        const tickColor  = isDark ? '#94a3b8' : '#64748b';

        const labels = rawChartData.map(d => d.label);

        // Ensure active button and title label match currentMetric
        ['weight', 'body_fat', 'measurements', 'bmi'].forEach(m => {
            const btn = document.getElementById('m-btn-' + m);
            if (btn) btn.classList.toggle('active', m === currentMetric);
        });
        const indEl = document.getElementById('chartMeasurementsIndicators');
        if (indEl) {
            indEl.style.display = (currentMetric === 'measurements') ? 'inline-flex' : 'none';
        }
        const labelEl = document.getElementById('chartActiveMetricLabel');
        if (labelEl) labelEl.textContent = cfg.chartTitle || 'Progress over time';

        const customPlugins = [
            // 1. Neon Line Glow Plugin
            {
                id: 'neonLineGlow',
                beforeDatasetsDraw(chart) {
                    const c = chart.ctx;
                    c.save();
                    chart.data.datasets.forEach((ds, i) => {
                        if (chart.isDatasetVisible(i) && ds.glowColor) {
                            c.shadowColor = ds.glowColor;
                            c.shadowBlur = 14;
                            c.shadowOffsetX = 0;
                            c.shadowOffsetY = 4;
                        }
                    });
                },
                afterDatasetsDraw(chart) {
                    chart.ctx.restore();
                }
            },
            // 2. Vertical Crosshair Cursor Plugin
            {
                id: 'crosshairGuide',
                afterDraw(chart) {
                    if (chart.tooltip && chart.tooltip.opacity > 0 && chart.tooltip.dataPoints && chart.tooltip.dataPoints.length) {
                        const { ctx: c, chartArea } = chart;
                        const x = chart.tooltip.caretX;
                        if (x >= chartArea.left && x <= chartArea.right) {
                            c.save();
                            c.setLineDash([4, 4]);
                            c.lineWidth = 1.4;
                            c.strokeStyle = isDark ? 'rgba(255, 255, 255, 0.25)' : 'rgba(0, 0, 0, 0.2)';
                            c.beginPath();
                            c.moveTo(x, chartArea.top);
                            c.lineTo(x, chartArea.bottom);
                            c.stroke();

                            c.fillStyle = isDark ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.5)';
                            c.beginPath();
                            c.arc(x, chartArea.bottom, 2.5, 0, Math.PI * 2);
                            c.fill();
                            c.restore();
                        }
                    }
                }
            },
            // 3. Goal Target Reference Line Plugin
            {
                id: 'targetLine',
                afterDraw(chart) {
                    const targets = FT_CONFIG.targets || {};
                    let targetVal = null;
                    let targetLabel = '';
                    let targetColor = '#22c55e';

                    if (currentMetric === 'weight' && targets.targetWeight) {
                        targetVal = parseFloat(targets.targetWeight);
                        targetLabel = '🎯 Target ' + targetVal + ' kg';
                    } else if (currentMetric === 'body_fat' && targets.targetBodyFat) {
                        targetVal = parseFloat(targets.targetBodyFat);
                        targetLabel = '🎯 Target ' + targetVal + '%';
                    } else if (currentMetric === 'measurements' && targets.targetWaist) {
                        targetVal = parseFloat(targets.targetWaist);
                        targetLabel = 'Target Waist ' + targetVal + ' cm';
                        targetColor = isDark ? '#38bdf8' : '#0284c7';
                    }

                    if (targetVal && targetVal > 0) {
                        const { ctx: c, chartArea, scales: { y } } = chart;
                        if (y && targetVal >= y.min && targetVal <= y.max) {
                            const yPos = y.getPixelForValue(targetVal);
                            c.save();
                            c.setLineDash([5, 5]);
                            c.lineWidth = 1.4;
                            c.strokeStyle = hexToRgba(targetColor, isDark ? 0.65 : 0.75);
                            c.beginPath();
                            c.moveTo(chartArea.left, yPos);
                            c.lineTo(chartArea.right, yPos);
                            c.stroke();

                            c.setLineDash([]);
                            c.font = "700 10.5px 'Plus Jakarta Sans', 'Outfit', system-ui, sans-serif";
                            const textWidth = c.measureText(targetLabel).width;
                            const padX = 7;
                            const pillW = textWidth + padX * 2;
                            const pillH = 18;
                            const pillX = chartArea.right - pillW - 6;
                            const pillY = Math.max(chartArea.top + 2, yPos - pillH - 4);

                            c.fillStyle = isDark ? 'rgba(15, 23, 42, 0.9)' : 'rgba(255, 255, 255, 0.94)';
                            c.strokeStyle = hexToRgba(targetColor, 0.45);
                            c.lineWidth = 1;
                            c.beginPath();
                            if (c.roundRect) {
                                c.roundRect(pillX, pillY, pillW, pillH, 5);
                            } else {
                                c.rect(pillX, pillY, pillW, pillH);
                            }
                            c.fill();
                            c.stroke();

                            c.fillStyle = targetColor;
                            c.textAlign = 'left';
                            c.textBaseline = 'middle';
                            c.fillText(targetLabel, pillX + padX, pillY + pillH / 2);
                            c.restore();
                        }
                    }
                }
            }
        ];

        window.hubChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: getDatasetsForMetric(currentMetric, rawChartData, isDark)
            },
            plugins: customPlugins,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(15, 23, 42, 0.94)' : 'rgba(255, 255, 255, 0.96)',
                        titleColor: isDark ? '#ffffff' : '#0f172a',
                        bodyColor: isDark ? '#cbd5e1' : '#334155',
                        borderColor: isDark ? 'rgba(255, 255, 255, 0.14)' : 'rgba(0, 0, 0, 0.1)',
                        borderWidth: 1.5,
                        cornerRadius: 12,
                        padding: 12,
                        boxPadding: 6,
                        usePointStyle: true,
                        titleFont: { size: 12, weight: '700', family: "'Outfit', 'Plus Jakarta Sans', system-ui, sans-serif" },
                        bodyFont: { size: 12, weight: '600', family: "'Plus Jakarta Sans', system-ui, sans-serif" },
                        callbacks: {
                            labelPointStyle: function() {
                                return {
                                    pointStyle: 'circle',
                                    rotation: 0
                                };
                            },
                            label: function(context) {
                                const val = context.parsed.y;
                                if (val === null || val === undefined) return null;
                                if (currentMetric === 'measurements' || currentMetric === 'waist' || currentMetric === 'arm' || currentMetric === 'chest') {
                                    return ' ' + context.dataset.label + ': ' + val + ' cm';
                                }
                                const activeCfg = METRIC_CONFIG[currentMetric] || METRIC_CONFIG.weight;
                                return ' ' + activeCfg.label + ': ' + val + (activeCfg.unit ? ' ' + activeCfg.unit : '');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        grid: {
                            color: gridColor,
                            borderDash: [5, 5]
                        },
                        border: { display: false },
                        ticks: {
                            color: tickColor,
                            font: { weight: '600', size: 11, family: "'Plus Jakarta Sans', system-ui, sans-serif" },
                            padding: 8
                        }
                    },
                    x: {
                        grid: {
                            color: gridColor,
                            borderDash: [5, 5]
                        },
                        border: { display: false },
                        ticks: {
                            color: tickColor,
                            font: { weight: '600', size: 11, family: "'Plus Jakarta Sans', system-ui, sans-serif" },
                            padding: 8
                        }
                    }
                }
            }
        });
        lastFilteredChartData = rawChartData;
        updateChartInsight(rawChartData);
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
        const list = Array.isArray(targets.strengthTargets) ? targets.strengthTargets : [];

        const unitInput = (name, val, step, placeholder, unit) => `
            <div class="ft-unit-box" style="display:flex; align-items:center; background:var(--bg); border:1px solid var(--line); border-radius:9px; height:38px; padding:0 10px; gap:6px; box-sizing:border-box;">
                <input type="number" step="${step}" name="${name}" value="${val || ''}" placeholder="${placeholder}">
                <span class="ft-unit-pill">${unit}</span>
            </div>
        `;

        Swal.fire({
            title: `<div style="font-size:18px; font-weight:800; color:var(--ink); letter-spacing:-0.01em;">${isTrainer ? 'Adjust Client Target Metrics' : 'Adjust Fitness Targets'}</div><div style="font-size:12.5px; font-weight:500; color:var(--muted); margin-top:3px;">Set body composition milestones and exercise strength PR goals.</div>`,
            width: '620px',
            html: `
                <style>
                    #targetMetricsForm .ft-unit-box input {
                        border: none !important;
                        outline: none !important;
                        box-shadow: none !important;
                        background: transparent !important;
                        margin: 0 !important;
                        padding: 0 !important;
                        height: 100% !important;
                        width: 100% !important;
                        min-width: 0 !important;
                        flex: 1 1 auto !important;
                        color: var(--ink) !important;
                        font-size: 13.5px !important;
                        font-weight: 600 !important;
                    }
                    #targetMetricsForm .ft-unit-pill {
                        font-size: 11px;
                        font-weight: 700;
                        color: var(--muted);
                        background: var(--panel-soft);
                        border: 1px solid var(--line);
                        border-radius: 5px;
                        padding: 2px 6px;
                        flex-shrink: 0;
                        white-space: nowrap;
                    }
                    #targetMetricsForm .ft-grid-2 {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 10px;
                    }
                    #targetMetricsForm .ft-grid-3 {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 10px;
                    }
                    @media (max-width: 520px) {
                        #targetMetricsForm .ft-grid-2,
                        #targetMetricsForm .ft-grid-3 {
                            grid-template-columns: 1fr;
                        }
                    }
                </style>
                <form id="targetMetricsForm" method="post" style="text-align:left; margin-top:4px;">
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="update_goal_targets">
                    ${isTrainer ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}

                    <div style="background:var(--panel-soft); border:1px solid var(--line); border-radius:12px; padding:14px; margin-bottom:14px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px;">
                            <span style="font-size:11px; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; color:var(--muted);">Body Composition &amp; Measurements</span>
                            <span style="font-size:10.5px; font-weight:700; color:var(--muted); background:var(--bg); border:1px solid var(--line); border-radius:999px; padding:2px 8px; white-space:nowrap; flex-shrink:0;">Milestones</span>
                        </div>

                        <div class="ft-grid-2" style="margin-bottom:10px;">
                            <div>
                                <label style="display:block; color:var(--ink); font-size:11.5px; font-weight:700; margin-bottom:4px;">Target Weight</label>
                                ${unitInput('target_weight_kg', targets.targetWeight, '0.1', 'e.g. 75.0', 'kg')}
                            </div>
                            <div>
                                <label style="display:block; color:var(--ink); font-size:11.5px; font-weight:700; margin-bottom:4px;">Target Body Fat</label>
                                ${unitInput('target_body_fat_percent', targets.targetBodyFat, '0.1', 'e.g. 15.0', '%')}
                            </div>
                        </div>

                        <div class="ft-grid-3">
                            <div>
                                <label style="display:block; color:var(--ink); font-size:11.5px; font-weight:700; margin-bottom:4px;">Target Waist</label>
                                ${unitInput('target_waist_cm', targets.targetWaist, '0.5', '80.0', 'cm')}
                            </div>
                            <div>
                                <label style="display:block; color:var(--ink); font-size:11.5px; font-weight:700; margin-bottom:4px;">Target Arm</label>
                                ${unitInput('target_arm_cm', targets.targetArm, '0.5', '38.0', 'cm')}
                            </div>
                            <div>
                                <label style="display:block; color:var(--ink); font-size:11.5px; font-weight:700; margin-bottom:4px;">Target Chest</label>
                                ${unitInput('target_chest_cm', targets.targetChest, '0.5', '102.0', 'cm')}
                            </div>
                        </div>
                    </div>

                    <div style="background:linear-gradient(180deg, rgba(79,70,229,0.04) 0%, var(--panel-soft) 100%); border:1px solid var(--line); border-radius:12px; padding:14px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px;">
                            <span style="font-size:11px; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; color:var(--ink);">Strength PR Targets</span>
                            <span style="font-size:10.5px; font-weight:700; color:#4f46e5; background:rgba(79,70,229,0.1); border:1px solid rgba(79,70,229,0.2); border-radius:999px; padding:2px 8px; white-space:nowrap; flex-shrink:0;">${list.length} active</span>
                        </div>

                        ${(() => {
                            if (list.length === 0) {
                                return '<div style="font-size:12px; color:var(--muted); padding:8px 10px; border-radius:8px; background:var(--bg); border:1px solid var(--line); margin-bottom:10px;">No exercise targets set yet. Add your first lift goal below.</div>';
                            }
                            return '<div style="display:flex; flex-direction:column; gap:8px; margin-bottom:12px;">' + list.map(t => {
                                const exEsc = String(t.exercise_name || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                const curVal = t.current_max_kg !== null && t.current_max_kg !== '' ? parseFloat(t.current_max_kg) : 0;
                                const startVal = t.start_max_kg !== null && t.start_max_kg !== undefined && t.start_max_kg !== '' ? parseFloat(t.start_max_kg) : curVal;
                                const tgtVal = t.target_max_kg !== null && t.target_max_kg !== '' ? parseFloat(t.target_max_kg) : 0;
                                const span = tgtVal - startVal;
                                const pct = span > 0 ? Math.min(100, Math.max(0, Math.round(((curVal - startVal) / span) * 100))) : ((tgtVal > 0 && curVal >= tgtVal) ? 100 : 0);
                                return `
                                    <div style="padding:9px 11px; border-radius:9px; background:var(--bg); border:1px solid var(--line);">
                                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:6px;">
                                            <div style="display:flex; align-items:baseline; flex-wrap:wrap; gap:4px 8px; flex:1 1 140px; min-width:0;">
                                                <span style="font-size:12.5px; font-weight:700; color:var(--ink);">${exEsc}</span>
                                                <span style="font-size:11.5px; color:var(--muted); font-weight:600; white-space:nowrap;">${curVal} kg &rarr; <strong style="color:var(--ink);">${tgtVal} kg</strong></span>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                                                <span style="font-size:11px; font-weight:800; color:#4f46e5; white-space:nowrap;">${pct}%</span>
                                                <button type="button" onclick="window.deleteStrengthTarget(${Number(t.id)})" style="border:1px solid rgba(239,68,68,0.22); background:rgba(239,68,68,0.08); color:#dc2626; font-size:11px; font-weight:700; padding:3px 8px; border-radius:6px; cursor:pointer; white-space:nowrap;">Remove</button>
                                            </div>
                                        </div>
                                        <div style="height:5px; border-radius:999px; background:var(--panel-soft); overflow:hidden;">
                                            <div style="width:${pct}%; height:100%; border-radius:999px; background:linear-gradient(90deg, #10b981, #4f46e5);"></div>
                                        </div>
                                    </div>
                                `;
                            }).join('') + '</div>';
                        })()}

                        <div style="background:var(--bg); border:1px dashed var(--line); border-radius:10px; padding:11px;">
                            <div style="font-size:11.5px; font-weight:700; color:var(--ink); margin-bottom:8px;">+ Add New Exercise Target</div>
                            <div style="margin-bottom:9px;">
                                <label style="display:block; color:var(--muted); font-size:11px; font-weight:700; margin-bottom:4px;">Exercise</label>
                                ${(() => {
                                    const presets = ['Bench Press', 'Back Squat', 'Deadlift', 'Overhead Press', 'Barbell Row', 'Leg Press'];
                                    const normKey = s => String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
                                    const existingKeys = list.map(t => normKey(t.exercise_name));
                                    const availablePreset = presets.find(ex => !existingKeys.includes(normKey(ex)));
                                    const curEx = availablePreset || '';
                                    const isCustom = !availablePreset;
                                    const opts = presets.map(ex => {
                                        const taken = existingKeys.includes(normKey(ex));
                                        return `<option value="${ex}" ${!isCustom && curEx === ex ? 'selected' : ''} ${taken ? 'disabled' : ''}>${ex}${taken ? ' (Already added)' : ''}</option>`;
                                    }).join('') + `<option value="__custom__" ${isCustom ? 'selected' : ''}>+ Custom Exercise...</option>`;
                                    return `
                                        <input type="hidden" id="targetExerciseHidden" name="target_exercise" value="${curEx}">
                                        <select id="targetExerciseSelect" onchange="const c=document.getElementById('customTargetExerciseWrap'); const ci=document.getElementById('customTargetExerciseInput'); const h=document.getElementById('targetExerciseHidden'); if(this.value==='__custom__'){c.style.display='block'; h.value=ci.value.trim();}else{c.style.display='none'; h.value=this.value;}" style="width:100%; box-sizing:border-box; border-radius:9px; height:38px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px; font-size:13px; font-weight:600;">
                                            ${opts}
                                        </select>
                                        <div id="customTargetExerciseWrap" style="display:${isCustom ? 'block' : 'none'}; margin-top:8px;">
                                            <input id="customTargetExerciseInput" type="text" value="" oninput="document.getElementById('targetExerciseHidden').value=this.value.trim();" placeholder="Enter exercise name (e.g. Barbell Curl)" style="width:100%; box-sizing:border-box; border-radius:9px; height:38px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px; font-size:13px;">
                                        </div>
                                    `;
                                })()}
                            </div>
                            <div class="ft-grid-2">
                                <div>
                                    <label style="display:block; color:var(--muted); font-size:11px; font-weight:700; margin-bottom:4px;">Current Max</label>
                                    <div class="ft-unit-box" style="display:flex; align-items:center; background:var(--panel-soft); border:1px solid var(--line); border-radius:9px; height:38px; padding:0 10px; gap:6px; box-sizing:border-box;">
                                        <input id="currentStrengthMaxInput" type="number" step="0.5" min="0" max="500" name="current_strength_max_kg" value="" placeholder="e.g. 75.0">
                                        <span class="ft-unit-pill">kg</span>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; color:var(--muted); font-size:11px; font-weight:700; margin-bottom:4px;">Target Max</label>
                                    <div class="ft-unit-box" style="display:flex; align-items:center; background:var(--panel-soft); border:1px solid var(--line); border-radius:9px; height:38px; padding:0 10px; gap:6px; box-sizing:border-box;">
                                        <input id="targetStrengthMaxInput" type="number" step="0.5" min="1" max="500" name="target_strength_max_kg" value="" placeholder="e.g. 100.0">
                                        <span class="ft-unit-pill">kg</span>
                                    </div>
                                </div>
                            </div>
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
                const tgtVal = (document.getElementById('targetStrengthMaxInput')?.value || '').trim();
                if (tgtVal !== '') {
                    const exName = (document.getElementById('targetExerciseHidden')?.value || '').trim();
                    if (!exName) {
                        Swal.showValidationMessage('Please enter an exercise name for your strength target.');
                        return false;
                    }
                    const normKey = s => String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
                    if (list.some(t => normKey(t.exercise_name) === normKey(exName))) {
                        Swal.showValidationMessage('You already have a target for "' + exName + '". Remove it first or choose another exercise.');
                        return false;
                    }
                }
                f.submit();
            }
        });
    }

    window.deleteStrengthTarget = function(targetId) {
        const csrfInput = document.querySelector('#targetMetricsForm input[name="csrf_token"]');
        if (!csrfInput) return;
        const csrfVal = csrfInput.value;
        const memberInput = document.querySelector('#targetMetricsForm input[name="member_user_id"]');
        const memberVal = memberInput ? memberInput.value : '';
        const list = (FT_CONFIG.targets && Array.isArray(FT_CONFIG.targets.strengthTargets)) ? FT_CONFIG.targets.strengthTargets : [];
        const match = list.find(t => Number(t.id) === Number(targetId));
        const exLabel = match && match.exercise_name ? String(match.exercise_name) : 'this exercise';

        Swal.fire({
            icon: 'warning',
            title: 'Remove Strength Target?',
            text: `Remove your strength PR target for "${exLabel}"?`,
            showCancelButton: true,
            confirmButtonText: 'Yes, remove it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)'
        }).then((res) => {
            if (!res.isConfirmed) {
                openTargetMetricsModal();
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const fields = {
                csrf_token: csrfVal,
                action: 'delete_strength_target',
                strength_target_id: String(targetId)
            };
            if (memberVal) {
                fields.member_user_id = memberVal;
            }
            Object.keys(fields).forEach(k => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = k;
                inp.value = fields[k];
                form.appendChild(inp);
            });
            document.body.appendChild(form);
            form.submit();
        });
    };

    function openLogStrengthPrModal() {
        const targets = FT_CONFIG.targets || {};
        const list = Array.isArray(targets.strengthTargets) ? targets.strengthTargets : [];
        if (list.length === 0) {
            openTargetMetricsModal();
            return;
        }
        const isTrainer = FT_CURRENT_USER_ROLE === 'trainer';
        const esc = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        const opts = list.map(t => {
            const cur = t.current_max_kg !== null && t.current_max_kg !== '' ? parseFloat(t.current_max_kg) : 0;
            const tgt = t.target_max_kg !== null && t.target_max_kg !== '' ? parseFloat(t.target_max_kg) : 0;
            return `<option value="${Number(t.id)}" data-cur="${cur}" data-tgt="${tgt}">${esc(t.exercise_name)} (Now: ${cur} kg &rarr; Goal: ${tgt} kg)</option>`;
        }).join('');

        Swal.fire({
            title: `<div style="font-size:18px; font-weight:800; color:var(--ink);">Log Strength PR</div><div style="font-size:12.5px; font-weight:500; color:var(--muted); margin-top:3px;">Record your latest working or 1RM weight for a configured target.</div>`,
            width: '440px',
            html: `
                <form id="logStrengthPrForm" method="post" style="text-align:left; margin-top:6px;">
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="log_strength_pr">
                    ${isTrainer ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}

                    <div style="margin-bottom:12px;">
                        <label style="display:block; color:var(--ink); font-size:12px; font-weight:700; margin-bottom:5px;">Exercise Target</label>
                        <select id="logPrTargetSelect" name="strength_target_id" onchange="const opt=this.options[this.selectedIndex]; const inp=document.getElementById('logPrWeightInput'); if(opt && inp){inp.placeholder='Current: ' + (opt.dataset.cur || '0') + ' kg';}" style="width:100%; box-sizing:border-box; border-radius:9px; height:40px; background:var(--panel-soft); color:var(--ink); border:1px solid var(--line); padding:0 10px; font-size:13px; font-weight:600;">
                            ${opts}
                        </select>
                    </div>

                    <div>
                        <label style="display:block; color:var(--ink); font-size:12px; font-weight:700; margin-bottom:5px;">New Weight Lifted (kg)</label>
                        <div style="display:flex; align-items:center; background:var(--panel-soft); border:1px solid var(--line); border-radius:9px; height:40px; padding:0 12px; gap:8px; box-sizing:border-box;">
                            <input id="logPrWeightInput" type="number" step="0.5" min="0.5" max="1000" name="lifted_kg" placeholder="Current: ${list[0].current_max_kg ?? 0} kg" style="width:100%; border:none !important; outline:none !important; box-shadow:none !important; background:transparent !important; margin:0 !important; padding:0 !important; color:var(--ink); font-size:14px; font-weight:600;">
                            <span style="font-size:11px; font-weight:700; color:var(--muted); background:var(--bg); border:1px solid var(--line); border-radius:5px; padding:2px 7px; flex-shrink:0;">kg</span>
                        </div>
                    </div>
                </form>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save PR',
            confirmButtonColor: 'var(--lime-dark)',
            cancelButtonColor: 'var(--line)',
            background: 'var(--bg)',
            color: 'var(--ink)',
            preConfirm: () => {
                const f = document.getElementById('logStrengthPrForm');
                const val = parseFloat(document.getElementById('logPrWeightInput')?.value || '');
                if (!val || val <= 0) {
                    Swal.showValidationMessage('Please enter a valid weight in kg.');
                    return false;
                }
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
        const defaultGender = (FT_DEFAULTS.gender || savedBfSettings.gender || 'male').toLowerCase() === 'female' ? 'female' : 'male';
        const genderLabel = defaultGender === 'female' ? 'Female' : 'Male';
        const defaultHeight = savedBfSettings.height || (FT_DEFAULTS.height || '');
        const defaultNeck = (savedState && savedState.neck_cm) ? savedState.neck_cm : (FT_DEFAULTS.neck || savedBfSettings.neck || '');
        const defaultWaist = (savedState && savedState.waist_cm) ? savedState.waist_cm : (FT_DEFAULTS.waist || '');
        const defaultHip = (savedState && savedState.hips_cm) ? savedState.hips_cm : (FT_DEFAULTS.hips || savedBfSettings.hip || '');
        
        Swal.fire({
            title: 'Estimate Body Fat %',
            html: `
                <div style="text-align: left; font-size: 13.5px; color: var(--muted); margin-bottom: 15px;">
                    This estimate uses the standard <strong>U.S. Navy Circumference Formula</strong>.
                </div>
                <form id="bfCalcForm" style="text-align: left; display: flex; flex-direction: column; gap: 12px;">
                    <div style="display:flex;gap:12px;">
                        <label style="display:block; flex:1; color: var(--muted); font-size: 13px;">Biological Sex
                            <input type="hidden" name="gender" value="${defaultGender}">
                            <input type="text" class="form-control" value="${genderLabel}" readonly disabled style="width: 100%; box-sizing: border-box; opacity: 0.75; cursor: not-allowed;" title="Change this in your Physical Profile">
                            <span style="display:block; font-size:11px; color:var(--muted); margin-top:3px;">Set in Physical Profile</span>
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
                            <input name="waist" type="number" step="0.1" class="form-control" placeholder="e.g. 82" value="${defaultWaist}" required style="width: 100%; box-sizing: border-box;">
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
                
                // Pre-calculation input range validation
                if (height < 100 || height > 250) {
                    Swal.showValidationMessage('Height must be between 100 and 250 cm.');
                    return false;
                }
                if (neck < 20 || neck > 60) {
                    Swal.showValidationMessage('Neck circumference must be between 20 and 60 cm.');
                    return false;
                }
                const minWaist = gender === 'male' ? 55 : 50;
                if (waist < minWaist || waist > 200) {
                    Swal.showValidationMessage(`Waist circumference must be between ${minWaist} and 200 cm. A realistic adult ${gender} waist is typically ${gender === 'male' ? '70–110' : '60–100'} cm.`);
                    return false;
                }
                if (gender === 'female') {
                    if (hip < 60 || hip > 200) {
                        Swal.showValidationMessage('Hip circumference must be between 60 and 200 cm. Measure around the widest part of your hips/buttocks (typically 80–115 cm).');
                        return false;
                    }
                    if (hip <= waist) {
                        Swal.showValidationMessage(`Hip circumference (${hip} cm) cannot be smaller than or equal to waist (${waist} cm). Please measure around the widest part of your hips/buttocks.`);
                        return false;
                    }
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

                // Validate minimum waist-neck difference for realistic results
                if (gender === 'male' && (waist - neck) < 25) {
                    Swal.showValidationMessage(`Waist minus neck (${(waist - neck).toFixed(1)} cm) is unusually small. Please double-check your waist (${waist} cm) and neck (${neck} cm) measurements — a typical male waist–neck difference is 40+ cm.`);
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
                        if (gender === 'female') {
                            Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (below 2%). Your combined waist (${waist} cm) and hips (${hip} cm) are too small compared to neck (${neck} cm). Please verify your hip measurement around the widest part of your buttocks.`);
                        } else {
                            Swal.showValidationMessage(`Calculated body fat is ${bodyFat.toFixed(1)}% (below 2%). A waist of ${waist} cm is too small compared to neck (${neck} cm) for this formula. Please check your waist circumference.`);
                        }
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
            title: '',
            width: 540,
            html: `
                <div style="text-align: left;">
                    <div style="padding-bottom:14px; margin-bottom:14px; border-bottom:1px solid var(--line);">
                        <div style="font-size:17px; font-weight:800; color:var(--ink); line-height:1.25;">Log Progress &amp; Measurements</div>
                        <div style="font-size:12px; color:var(--muted); margin-top:2px;">Track your weight, body composition, and tape measurements</div>
                    </div>

                    <form id="progressForm" method="post" style="display:flex; flex-direction:column; gap:14px;">
                        <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                        <input type="hidden" name="action" value="log_progress">
                        ${FT_CURRENT_USER_ROLE === 'trainer' ? `<input type="hidden" name="member_user_id" value="${FT_MEMBER_ID}">` : ''}

                        <div style="background:var(--panel-soft); border:1px solid var(--line); border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:10px;">
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted);">Core Metrics</div>
                            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px;">
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Date <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative; width:100%;">
                                        <input id="progressLogDateInput" name="log_date" type="text" class="form-control" required value="${defaultDate || getLocalTodayStr()}" placeholder="mm/dd/yyyy" style="width:100%; box-sizing:border-box; padding-right:34px;">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); pointer-events:none;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Weight (kg) <span style="color:#ef4444;">*</span></label>
                                    <input name="weight_kg" type="number" step="0.01" class="form-control" placeholder="e.g. 74.0" value="${defaultWeight}" required style="width:100%; box-sizing:border-box;">
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; min-width:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin:0;">Body Fat %</label>
                                    <a href="#" onclick="calculateBodyFat(event)" style="color:var(--lime); text-decoration:underline; font-weight:700; font-size:11px;">Calculate</a>
                                </div>
                                <input name="body_fat_percent" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultBf}" style="width:100%; box-sizing:border-box;">
                            </div>
                        </div>

                        <div style="background:var(--panel-soft); border:1px solid var(--line); border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted);">Body Measurements</span>
                                <span style="font-size:11px; color:var(--muted);">cm &bull; optional</span>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px;">
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Neck (cm)</label>
                                    <input name="neck_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultNeck}" style="width:100%; box-sizing:border-box;">
                                </div>
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Chest (cm)</label>
                                    <input name="chest_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultChest}" style="width:100%; box-sizing:border-box;">
                                </div>
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Waist (cm)</label>
                                    <input name="waist_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultWaist}" style="width:100%; box-sizing:border-box;">
                                </div>
                                <div style="display:flex; flex-direction:column; min-width:0;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Arms (cm)</label>
                                    <input name="arm_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultArm}" style="width:100%; box-sizing:border-box;">
                                </div>
                                <div style="display:flex; flex-direction:column; min-width:0; grid-column: 1 / -1;">
                                    <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Hips (cm)</label>
                                    <input name="hips_cm" type="number" step="0.01" class="form-control" placeholder="optional" value="${defaultHips}" style="width:100%; box-sizing:border-box;">
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; min-width:0;">
                            <label style="color:var(--muted); font-size:12px; font-weight:600; margin-bottom:4px;">Session Notes</label>
                            <input name="notes" class="form-control" placeholder="How did you feel? Energy, sleep, or PR notes..." value="${defaultNotes}" style="width:100%; box-sizing:border-box;">
                        </div>
                    </form>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10); font-weight:800;">Save Progress</span>',
            cancelButtonText: 'Cancel',
            confirmButtonColor: 'var(--lime)',
            cancelButtonColor: 'var(--panel-soft)',
            background: 'var(--panel, var(--bg))',
            color: 'var(--ink)',
            didOpen: () => {
                const htmlContainer = (typeof Swal !== 'undefined' && Swal.getHtmlContainer) ? Swal.getHtmlContainer() : null;
                if (htmlContainer) htmlContainer.style.overflow = 'visible';
                const dateEl = document.getElementById('progressLogDateInput');
                if (dateEl && typeof flatpickr === 'function') {
                    flatpickr(dateEl, {
                        dateFormat: 'Y-m-d',
                        altInput: true,
                        altFormat: 'm/d/Y',
                        altInputClass: 'form-control',
                        disableMobile: true,
                        clickOpens: true,
                        static: true,
                        maxDate: getLocalTodayStr(),
                        defaultDate: defaultDate || getLocalTodayStr(),
                        onReady: (_, __, inst) => {
                            if (inst.altInput && inst.altInput.parentNode) {
                                inst.altInput.parentNode.style.width = '100%';
                                inst.altInput.parentNode.style.display = 'block';
                            }
                            if (inst.altInput) {
                                inst.altInput.style.width = '100%';
                                inst.altInput.style.boxSizing = 'border-box';
                                inst.altInput.style.paddingRight = '34px';
                                inst.altInput.style.cursor = 'pointer';
                            }
                        },
                        onOpen: (_, __, inst) => {
                            if (inst.calendarContainer) {
                                inst.calendarContainer.style.zIndex = '20000';
                            }
                        }
                    });
                }
            },
            preConfirm: () => {
                const form = document.getElementById('progressForm');
                if (!form.log_date.value || !form.weight_kg.value) {
                    Swal.showValidationMessage('Please fill all required fields (Date & Weight)');
                    return false;
                }
                if (form.log_date.value > getLocalTodayStr()) {
                    Swal.showValidationMessage('Date cannot be in the future.');
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
