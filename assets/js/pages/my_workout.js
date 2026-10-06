/**
 * Member Workout Planner & Player Controller
 * Extracted from my_workout.php
 */

const FT_WORKOUT_CONFIG = window.WORKOUT_CONFIG || {};
const CURRENT_USER_ID = FT_WORKOUT_CONFIG.userId || 0;
const PLAN_ID = FT_WORKOUT_CONFIG.planId || 0;
const TODAY_DATE = FT_WORKOUT_CONFIG.todayDate || '';
const CSRF_TOKEN = FT_WORKOUT_CONFIG.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const CALENDAR_DATA = FT_WORKOUT_CONFIG.calendarData || {};
let selectedDate = FT_WORKOUT_CONFIG.initialSelectedDate || '';


                // Live player state
                let liveExercises = [];
                let currentExIndex = 0;
                let currentSet = 1;
                let restTimer = null;

                function selectDate(dateStr) {
                    if (!CALENDAR_DATA[dateStr]) return;
                    selectedDate = dateStr;

                    // Update calendar day selected styling
                    document.querySelectorAll('.cal-day-cell').forEach(cell => {
                        cell.classList.remove('is-selected');
                    });
                    const targetCell = document.getElementById('cal-cell-' + dateStr);
                    if (targetCell) {
                        targetCell.classList.add('is-selected');
                    }

                    renderWorkoutDetails(dateStr);
                }

                function renderWorkoutDetails(dateStr) {
                    const day = CALENDAR_DATA[dateStr];
                    const container = document.getElementById('selected-workout-card');
                    if (!day || !container) return;

                    let statusBadgeHtml = '';
                    if (day.status === 'completed') {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: rgba(45,240,165,0.15); color: #2df0a5; border: 1px solid rgba(45,240,165,0.3);">&#10003; Completed</span>';
                    } else if (day.status === 'missed') {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3);">! Missed</span>';
                    } else if (day.isToday) {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: color-mix(in srgb, var(--lime) 15%, transparent); color: var(--lime); border: 1px solid color-mix(in srgb, var(--lime) 30%, transparent);">Today\'s Workout</span>';
                    } else if (day.isFuture && day.hasWorkout) {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: var(--panel-soft); color: var(--muted); border: 1px solid var(--line);">&#9679; Scheduled</span>';
                    } else {
                        statusBadgeHtml = '<span class="workout-status-pill" style="background: var(--panel-soft); color: var(--muted); border: 1px solid var(--line);">&mdash; Rest Day</span>';
                    }

                    let actionBannerHtml = '';
                    if (day.hasWorkout) {
                        if (day.isToday) {
                            if (day.allDone) {
                                actionBannerHtml = `
                                    <div class="callout-completed">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <div>
                                            <strong style="font-size: 15px; display: block;">Workout Complete!</strong>
                                            <span style="font-size: 13px; opacity: 0.85;">You crushed all your assigned exercises for today.</span>
                                        </div>
                                    </div>
                                `;
                            } else {
                                const pendingCount = day.exercises.filter(e => !e.is_completed).length;
                                actionBannerHtml = `
                                    <div class="workout-action-banner">
                                        <button onclick="startLiveWorkoutForToday()" class="btn-live-workout">
                                            <span class="pulse-dot"></span>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                            </svg>
                                            <span>${day.completedCount > 0 ? 'Resume Live Workout' : 'Start Live Workout'} (${pendingCount} remaining)</span>
                                        </button>
                                    </div>
                                `;
                            }
                        } else if (day.isPast) {
                            if (day.allDone) {
                                actionBannerHtml = `
                                    <div class="callout-completed">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        <div>
                                            <strong>Workout Finished</strong> &bull; Completed on ${escapeHtml(day.formattedDate)}
                                        </div>
                                    </div>
                                `;
                            } else {
                                actionBannerHtml = `
                                    <div class="callout-missed">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        <div>
                                            <strong>Workout Missed</strong> &bull; Scheduled for ${escapeHtml(day.formattedDate)}
                                        </div>
                                    </div>
                                `;
                            }
                        } else if (day.isFuture) {
                            actionBannerHtml = `
                                <div class="callout-future">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <div>
                                        <strong>Upcoming Workout</strong> &bull; Unlocks on ${escapeHtml(day.formattedDate)}
                                    </div>
                                </div>
                            `;
                        }
                    }

                    // Exercises list HTML
                    let exercisesHtml = '';
                    if (!day.hasWorkout) {
                        exercisesHtml = `
                            <div class="callout-rest">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="1.75" style="margin-bottom: 12px; opacity: 0.85;">
                                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                                </svg>
                                <h4 style="font-size: 16px; color: var(--ink); margin: 0 0 6px 0;">Rest &amp; Recovery Day</h4>
                                <p style="margin: 0; font-size: 13px; max-width: 320px; margin: 0 auto;">Your muscles rebuild and recover on rest days. Stay hydrated and prioritize good sleep!</p>
                            </div>
                        `;
                    } else {
                        exercisesHtml = '<div class="exercise-list-container">';
                        day.exercises.forEach((ex, idx) => {
                            const isComp = ex.is_completed;
                            let completeBtnHtml = '';
                            if (day.isToday && !isComp) {
                                completeBtnHtml = `<button type="button" class="btn-mark-exercise" onclick="quickCompleteExercise(${ex.exercise_id})">Complete</button>`;
                            } else if (isComp) {
                                completeBtnHtml = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2df0a5" stroke-width="3" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>`;
                            }

                            exercisesHtml += `
                                <div class="exercise-item-card ${isComp ? 'is-completed' : ''}">
                                    <div class="exercise-item-info">
                                        <div class="exercise-idx-badge">${idx + 1}</div>
                                        <div class="exercise-details-wrap">
                                            <h4 class="exercise-name-title">${escapeHtml(ex.name)}</h4>
                                            <div class="exercise-specs-text">
                                                ${ex.sets} Sets &times; ${escapeHtml(ex.reps)}
                                                ${ex.target_weight_kg ? ` &bull; <strong style="color:var(--orange, #f59e0b);">${ex.target_weight_kg} kg</strong>` : ''}
                                                &bull; Rest ${ex.rest_seconds}s
                                                ${ex.tempo ? ` &bull; <span style="color:var(--lime); font-weight:600;">⚡ ${escapeHtml(ex.tempo)}</span>` : ''}
                                                ${ex.rpe ? ` &bull; <span style="color:var(--orange, #f59e0b); font-weight:600;">🔥 ${escapeHtml(ex.rpe)}</span>` : ''}
                                            </div>
                                            ${ex.notes ? `<div style="font-size:12px; color:var(--muted); margin-top:4px; font-style:italic;">"${escapeHtml(ex.notes)}"</div>` : ''}
                                        </div>
                                    </div>
                                    <div>
                                        ${completeBtnHtml}
                                    </div>
                                </div>
                            `;
                        });
                        exercisesHtml += '</div>';
                    }

                    container.innerHTML = `
                        <div>
                            <div class="workout-card-top">
                                <div class="workout-date-row">
                                    <span class="workout-date-label">${escapeHtml(day.formattedDate)}</span>
                                    ${statusBadgeHtml}
                                </div>
                                <h3 class="workout-title-text">${escapeHtml(day.focusTitle)}</h3>
                                ${day.hasWorkout ? `
                                    <div class="workout-meta-line">
                                        <span class="workout-meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                                            ${day.scheduledCount} Exercises
                                        </span>
                                        <span class="workout-meta-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            ~${day.estDurationMinutes} mins
                                        </span>
                                        <span class="workout-meta-item">
                                            <span style="color:var(--lime);font-weight:700;">${day.completedCount}/${day.scheduledCount}</span> Done
                                        </span>
                                    </div>
                                ` : ''}
                            </div>
                            ${actionBannerHtml}
                            <div style="margin-top: 14px;">
                                <h4 style="font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 10px 0;">
                                    ${day.hasWorkout ? 'Assigned Exercises' : 'Day Summary'}
                                </h4>
                                ${exercisesHtml}
                            </div>
                        </div>
                    `;
                }

                function escapeHtml(str) {
                    if (!str) return '';
                    const div = document.createElement('div');
                    div.innerText = str;
                    return div.innerHTML;
                }

                function quickCompleteExercise(exerciseId) {
                    Swal.fire({
                        title: 'Complete Exercise?',
                        text: 'Mark this exercise as finished for today?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: 'var(--lime)',
                        cancelButtonColor: 'var(--line)',
                        confirmButtonText: '<span style="color:var(--lime-btn-text, #090b10);font-weight:800;">Yes, Crushed It!</span>',
                        cancelButtonText: 'Cancel',
                        background: 'var(--panel)',
                        color: 'var(--ink)'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch('index.php?page=complete_exercise', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `csrf_token=${encodeURIComponent(CSRF_TOKEN)}&plan_id=${PLAN_ID}&exercise_id=${exerciseId}`
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.success) {
                                    // Update local state
                                    const todayData = CALENDAR_DATA[TODAY_DATE];
                                    if (todayData) {
                                        const ex = todayData.exercises.find(e => e.exercise_id === exerciseId);
                                        if (ex) ex.is_completed = true;
                                        todayData.completedCount++;
                                        if (todayData.completedCount >= todayData.scheduledCount) {
                                            todayData.allDone = true;
                                            todayData.status = 'completed';
                                            const badgeEl = document.querySelector(`#cal-cell-${TODAY_DATE} .cal-status-badge`);
                                            if (badgeEl) badgeEl.innerHTML = '<span class="cal-badge-completed" title="Workout Completed">&#10003;</span>';
                                        }
                                    }
                                    renderWorkoutDetails(selectedDate);
                                    if (window.playNotifSound) window.playNotifSound('success');
                                } else {
                                    Swal.fire('Error', data.message || 'Could not record completion.', 'error');
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                Swal.fire('Error', 'Network error occurred.', 'error');
                            });
                        }
                    });
                }

                // ============================================================
                // LIVE WORKOUT PLAYER ENGINE
                // ============================================================
                let workoutElapsedSeconds = 0;
                let workoutStopwatchInterval = null;
                let totalRestSeconds = 60;
                let remainingRest = 60;
                let playerAudioMuted = false;
                let audioCtxInstance = null;
                let tierUpgradedData = null;
                const TIMER_CIRCUMFERENCE = 276.46; // 2 * Math.PI * 44

                function getPlayerAudioContext() {
                    if (!audioCtxInstance && (window.AudioContext || window.webkitAudioContext)) {
                        try {
                            audioCtxInstance = new (window.AudioContext || window.webkitAudioContext)();
                        } catch (e) {}
                    }
                    if (audioCtxInstance && audioCtxInstance.state === 'suspended') {
                        audioCtxInstance.resume().catch(() => {});
                    }
                    return audioCtxInstance;
                }

                function playPlayerTone(freq, duration, type = 'sine') {
                    if (playerAudioMuted) return;
                    try {
                        const ctx = getPlayerAudioContext();
                        if (!ctx) return;
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = type;
                        osc.frequency.setValueAtTime(freq, ctx.currentTime);
                        gain.gain.setValueAtTime(0.001, ctx.currentTime);
                        gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(ctx.currentTime);
                        osc.stop(ctx.currentTime + duration);
                    } catch (e) {}
                }

                function togglePlayerAudio() {
                    playerAudioMuted = !playerAudioMuted;
                    const onIcon = document.getElementById('player-audio-icon-on');
                    const offIcon = document.getElementById('player-audio-icon-off');
                    if (onIcon && offIcon) {
                        onIcon.style.display = playerAudioMuted ? 'none' : 'block';
                        offIcon.style.display = playerAudioMuted ? 'block' : 'none';
                    }
                }

                function formatElapsed(sec) {
                    const m = Math.floor(sec / 60);
                    const s = sec % 60;
                    if (m >= 60) {
                        const h = Math.floor(m / 60);
                        const remM = m % 60;
                        return `${h}:${remM.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                    }
                    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                }

                function startLiveWorkoutForToday() {
                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (!todayData || !todayData.hasWorkout) return;

                    liveExercises = todayData.exercises.filter(e => !e.is_completed);
                    if (liveExercises.length === 0) {
                        Swal.fire({
                            icon: 'success',
                            title: 'All Caught Up!',
                            text: 'You have completed all scheduled exercises for today. Great dedication!',
                            confirmButtonColor: 'var(--lime)'
                        });
                        return;
                    }

                    const modal = document.getElementById('workout-player-modal');
                    modal.style.display = 'flex';
                    requestAnimationFrame(() => {
                        modal.classList.add('is-active');
                    });
                    document.body.style.overflow = 'hidden';

                    // Suppress any floating background ratings while workout player is active
                    const ratingModal = document.getElementById('ft-floating-rating-modal');
                    if (ratingModal) ratingModal.style.display = 'none';

                    currentExIndex = 0;
                    currentSet = 1;
                    tierUpgradedData = null;

                    // Initialize workout chronograph
                    if (!workoutStopwatchInterval) {
                        workoutElapsedSeconds = 0;
                        const elapsedEl = document.getElementById('player-elapsed-time');
                        if (elapsedEl) elapsedEl.innerText = '00:00';
                        workoutStopwatchInterval = setInterval(() => {
                            workoutElapsedSeconds++;
                            if (elapsedEl) elapsedEl.innerText = formatElapsed(workoutElapsedSeconds);
                        }, 1000);
                    }

                    // Pre-warm audio context
                    getPlayerAudioContext();

                    renderLiveExercise();
                }

                function closeLiveWorkout() {
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) {
                        const titleEl = document.getElementById('player-confirm-title');
                        const descEl = document.getElementById('player-confirm-desc');
                        const actBtn = document.getElementById('btn-confirm-exit-action');
                        if (titleEl) titleEl.innerText = 'Pause Workout?';
                        if (descEl) descEl.innerText = 'You can resume your remaining exercises later today. Saved exercises will not be lost.';
                        if (actBtn) {
                            actBtn.innerText = 'Yes, Pause & Exit';
                            actBtn.onclick = confirmExitWorkout;
                        }
                        confirmOverlay.style.display = 'flex';
                    } else {
                        cleanupPlayerModal();
                        window.location.reload();
                    }
                }

                function cancelExitWorkout() {
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) confirmOverlay.style.display = 'none';
                }

                function confirmExitWorkout() {
                    cleanupPlayerModal();
                    window.location.reload();
                }

                function cleanupPlayerModal() {
                    const modal = document.getElementById('workout-player-modal');
                    if (modal) {
                        modal.classList.remove('is-active');
                        modal.style.display = 'none';
                    }
                    const confirmOverlay = document.getElementById('player-exit-confirm');
                    if (confirmOverlay) confirmOverlay.style.display = 'none';
                    document.body.style.overflow = '';
                    const ratingModal = document.getElementById('ft-floating-rating-modal');
                    if (ratingModal) ratingModal.style.display = '';
                    clearInterval(restTimer);
                    clearInterval(workoutStopwatchInterval);
                    workoutStopwatchInterval = null;
                }

                function renderSetChips(ex, activeSet) {
                    const container = document.getElementById('player-sets-chips');
                    if (!container) return;
                    let html = '';
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);
                    for (let s = 1; s <= totalSets; s++) {
                        let chipClass = 'set-chip';
                        let badgeIcon = `${s}`;
                        let statusText = `Set ${s}`;
                        if (s < activeSet) {
                            chipClass += ' completed';
                            badgeIcon = '✓';
                            statusText = `Set ${s} Done`;
                        } else if (s === activeSet) {
                            chipClass += ' active';
                            badgeIcon = '⚡';
                            statusText = `Set ${s} Active`;
                        }
                        html += `
                            <div class="${chipClass}">
                                <span class="set-badge-icon">${badgeIcon}</span>
                                <span class="set-label-text">${statusText}</span>
                            </div>
                        `;
                    }
                    container.innerHTML = html;
                }

                function renderLiveExercise() {
                    if (currentExIndex >= liveExercises.length) {
                        finishLiveWorkout();
                        return;
                    }

                    // Reset views
                    document.getElementById('player-main-view').style.display = 'flex';
                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-celebration-screen').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'flex';

                    const ex = liveExercises[currentExIndex];
                    const totalEx = liveExercises.length;
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);

                    // Update header and meta
                    document.getElementById('player-exercise-count').innerText = `EXERCISE ${currentExIndex + 1} OF ${totalEx}`;
                    document.getElementById('player-sets-status-summary').innerText = `Set ${currentSet} of ${totalSets}`;
                    document.getElementById('player-exercise-name').innerText = ex.name;

                    // Metric Badges
                    const metricContainer = document.getElementById('player-metric-tags');
                    let metricsHtml = `
                        <div class="player-metric-chip highlight-lime">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>${totalSets} Sets</span>
                        </div>
                        <div class="player-metric-chip">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>${escapeHtml(ex.reps)} Reps</span>
                        </div>
                    `;
                    if (ex.target_weight_kg) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-orange">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 4v16M10 4v16M6 12h4M14 4v16M18 4v16M14 12h4"/></svg>
                                <span>${ex.target_weight_kg} kg</span>
                            </div>
                        `;
                    }
                    metricsHtml += `
                        <div class="player-metric-chip">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>${ex.rest_seconds}s Rest</span>
                        </div>
                    `;
                    if (ex.tempo) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-lime">
                                <span>⚡ Tempo: ${escapeHtml(ex.tempo)}</span>
                            </div>
                        `;
                    }
                    if (ex.rpe) {
                        metricsHtml += `
                            <div class="player-metric-chip highlight-orange">
                                <span>🔥 ${escapeHtml(ex.rpe)}</span>
                            </div>
                        `;
                    }
                    metricContainer.innerHTML = metricsHtml;

                    // Notes Callout
                    const notesBox = document.getElementById('player-exercise-notes');
                    if (ex.notes && ex.notes.trim()) {
                        notesBox.style.display = 'block';
                        notesBox.innerHTML = `💬 "${escapeHtml(ex.notes.trim())}"`;
                    } else {
                        notesBox.style.display = 'none';
                    }

                    // Render interactive Set Chips
                    renderSetChips(ex, currentSet);

                    // Primary Button Text
                    const btnText = document.getElementById('btn-complete-set-text');
                    if (currentSet < totalSets) {
                        btnText.innerText = `Complete Set ${currentSet} of ${totalSets} ✓`;
                    } else {
                        btnText.innerText = `Finish Exercise & Save ✓`;
                    }

                    // Navigation Sub-buttons
                    const prevBtn = document.getElementById('btn-prev-exercise');
                    if (prevBtn) {
                        prevBtn.disabled = (currentExIndex === 0);
                    }

                    // Media loader
                    const videoEl = document.getElementById('player-animation-video');
                    const imgEl = document.getElementById('player-animation-img');
                    const fallbackDiv = document.getElementById('player-animation-fallback');

                    videoEl.style.display = 'none';
                    if (imgEl) imgEl.style.display = 'none';
                    fallbackDiv.style.display = 'none';

                    let rawUrl = (ex.animation_url || '').trim();
                    let primaryUrl = '';
                    if (rawUrl) {
                        if (rawUrl.startsWith('http://') || rawUrl.startsWith('https://')) {
                            primaryUrl = rawUrl;
                        } else if (rawUrl.startsWith('assets/')) {
                            primaryUrl = rawUrl;
                        } else if (rawUrl.startsWith('/assets/')) {
                            primaryUrl = rawUrl.substring(1);
                        } else {
                            primaryUrl = 'assets/exercise_animations/' + rawUrl;
                        }
                    }

                    const cleanName = ex.name.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_');
                    const spacedName = ex.name.toLowerCase().replace(/ /g, '_');
                    const candidateUrls = [];
                    if (primaryUrl) candidateUrls.push(primaryUrl);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.mp4`);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.gif`);
                    candidateUrls.push(`assets/exercise_animations/${cleanName}.webp`);
                    candidateUrls.push(`assets/exercise animation/${spacedName}.mp4`);
                    candidateUrls.push(`assets/exercise animation/${cleanName}.mp4`);
                    candidateUrls.push(`assets/${cleanName}.mp4`);

                    function isImageFormat(url) {
                        if (!url) return false;
                        const clean = url.split('?')[0].toLowerCase();
                        return clean.endsWith('.gif') || clean.endsWith('.webp') || clean.endsWith('.png') || clean.endsWith('.jpg') || clean.endsWith('.jpeg');
                    }

                    function loadNextCandidate(candidates) {
                        if (!candidates || candidates.length === 0) {
                            videoEl.style.display = 'none';
                            if (imgEl) imgEl.style.display = 'none';
                            fallbackDiv.style.display = 'flex';
                            animationContainer.classList.remove('has-media');
                            const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                            if (badgeEl) badgeEl.innerText = 'Technique & Form';
                            const titleEl = fallbackDiv.querySelector('strong');
                            const descEl = fallbackDiv.querySelector('span');
                            if (titleEl) titleEl.innerText = `${ex.name} Focus`;
                            if (descEl) descEl.innerText = ex.notes ? ex.notes : 'Focus on controlled breathing, posture, and steady tempo through full range of motion.';
                            return;
                        }

                        const currentUrl = candidates[0];
                        const remaining = candidates.slice(1);

                        if (isImageFormat(currentUrl)) {
                            videoEl.style.display = 'none';
                            try { videoEl.pause(); } catch (e) {}

                            if (!imgEl) {
                                loadNextCandidate(remaining);
                                return;
                            }
                            imgEl.onload = () => {
                                imgEl.style.display = 'block';
                                videoEl.style.display = 'none';
                                fallbackDiv.style.display = 'none';
                                animationContainer.classList.add('has-media');
                                const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                                if (badgeEl) badgeEl.innerText = 'Form Guide';
                            };
                            imgEl.onerror = () => {
                                imgEl.style.display = 'none';
                                loadNextCandidate(remaining);
                            };
                            imgEl.src = currentUrl;
                        } else {
                            if (imgEl) imgEl.style.display = 'none';
                            videoEl.muted = true;
                            videoEl.setAttribute('playsinline', '');
                            videoEl.setAttribute('muted', '');

                            let loaded = false;
                            const onReady = () => {
                                if (loaded) return;
                                loaded = true;
                                videoEl.style.display = 'block';
                                if (imgEl) imgEl.style.display = 'none';
                                fallbackDiv.style.display = 'none';
                                animationContainer.classList.add('has-media');
                                const badgeEl = animationContainer.querySelector('.player-stage-badge span');
                                if (badgeEl) badgeEl.innerText = 'Form Guide';
                                videoEl.play().catch(() => {});
                            };

                            videoEl.onloadeddata = onReady;
                            videoEl.oncanplay = onReady;
                            videoEl.onloadedmetadata = onReady;

                            videoEl.onerror = () => {
                                videoEl.style.display = 'none';
                                loadNextCandidate(remaining);
                            };
                            videoEl.src = currentUrl;
                            try { videoEl.load(); } catch (e) {}
                        }
                    }

                    loadNextCandidate(candidateUrls);

                    // Update Top Progress Bar
                    const progress = (currentExIndex / totalEx) * 100;
                    const fillEl = document.querySelector('.player-progress-bar .progress-fill');
                    if (fillEl) fillEl.style.width = progress + '%';
                }

                function completeSet() {
                    const ex = liveExercises[currentExIndex];
                    const totalSets = Math.max(1, parseInt(ex.sets, 10) || 1);

                    if (currentSet < totalSets) {
                        currentSet++;
                        playPlayerTone(660, 0.12, 'sine');
                        if (navigator.vibrate) navigator.vibrate([60]);
                        startRest(ex.rest_seconds || 60, 'next_set');
                    } else {
                        const btn = document.getElementById('btn-complete-set');
                        const btnText = document.getElementById('btn-complete-set-text');
                        btn.disabled = true;
                        btnText.innerText = 'Logging Exercise...';

                        fetch('index.php?page=complete_exercise', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `csrf_token=${encodeURIComponent(CSRF_TOKEN)}&plan_id=${PLAN_ID}&exercise_id=${ex.exercise_id}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            btn.disabled = false;
                            if (data && data.tier_upgraded) {
                                tierUpgradedData = data.tier_upgraded;
                            }

                            // Update local calendar dataset
                            const todayData = CALENDAR_DATA[TODAY_DATE];
                            if (todayData) {
                                const found = todayData.exercises.find(e => e.exercise_id === ex.exercise_id);
                                if (found) found.is_completed = true;
                                todayData.completedCount++;
                                if (todayData.completedCount >= todayData.scheduledCount) {
                                    todayData.allDone = true;
                                    todayData.status = 'completed';
                                    const badgeEl = document.querySelector(`#cal-cell-${TODAY_DATE} .cal-status-badge`);
                                    if (badgeEl) badgeEl.innerHTML = '<span class="cal-badge-completed" title="Workout Completed">&#10003;</span>';
                                }
                            }

                            if (window.playNotifSound) window.playNotifSound('success');
                            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);

                            currentExIndex++;
                            currentSet = 1;

                            if (currentExIndex < liveExercises.length) {
                                startRest(ex.rest_seconds || 60, 'next_exercise');
                            } else {
                                finishLiveWorkout();
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            btn.disabled = false;
                            btnText.innerText = 'Finish Exercise & Save ✓';
                            Swal.fire('Error', 'Network issue recording exercise completion.', 'error');
                        });
                    }
                }

                function startRest(seconds, mode = 'next_set') {
                    document.getElementById('player-main-view').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'none';
                    const timerScreen = document.getElementById('player-timer-screen');
                    timerScreen.style.display = 'flex';

                    // Pause video in background to save battery
                    const videoEl = document.getElementById('player-animation-video');
                    if (videoEl) {
                        try { videoEl.pause(); } catch (e) {}
                    }

                    totalRestSeconds = Math.max(5, parseInt(seconds, 10) || 60);
                    remainingRest = totalRestSeconds;

                    // Update Next Up preview card
                    const nextUpText = document.getElementById('player-next-up-text');
                    if (mode === 'next_set') {
                        const currentEx = liveExercises[currentExIndex];
                        nextUpText.innerHTML = `<strong>Set ${currentSet} of ${currentEx.sets}</strong> &bull; ${escapeHtml(currentEx.reps)} Reps ${currentEx.target_weight_kg ? `(${currentEx.target_weight_kg} kg)` : ''}`;
                    } else if (mode === 'next_exercise' && currentExIndex < liveExercises.length) {
                        const nextEx = liveExercises[currentExIndex];
                        nextUpText.innerHTML = `<strong>${escapeHtml(nextEx.name)}</strong> &bull; ${nextEx.sets} Sets &times; ${escapeHtml(nextEx.reps)}`;
                    }

                    updateRestTimerDisplay();

                    clearInterval(restTimer);
                    restTimer = setInterval(() => {
                        remainingRest--;
                        updateRestTimerDisplay();

                        // Countdown sound feedback for last 3 seconds
                        if (remainingRest === 3 || remainingRest === 2 || remainingRest === 1) {
                            playPlayerTone(880, 0.08, 'sine');
                        }

                        if (remainingRest <= 0) {
                            clearInterval(restTimer);
                            if (window.playNotifSound) {
                                window.playNotifSound('ready');
                            } else {
                                playPlayerTone(1320, 0.25, 'sine');
                            }
                            if (navigator.vibrate) navigator.vibrate([100, 60, 140]);
                            skipRest();
                        }
                    }, 1000);
                }

                function updateRestTimerDisplay() {
                    const timerText = document.getElementById('player-timer-text');
                    const ring = document.getElementById('timer-progress-ring');
                    if (timerText) timerText.innerText = Math.max(0, remainingRest);

                    if (ring && totalRestSeconds > 0) {
                        const offset = TIMER_CIRCUMFERENCE * (1 - (remainingRest / totalRestSeconds));
                        ring.style.strokeDashoffset = Math.max(0, Math.min(TIMER_CIRCUMFERENCE, offset));

                        // Dynamic warning color for final seconds
                        if (remainingRest <= 3) {
                            ring.style.stroke = '#ef4444';
                        } else if (remainingRest <= 7) {
                            ring.style.stroke = '#f59e0b';
                        } else {
                            ring.style.stroke = 'var(--lime, #27f246)';
                        }
                    }
                }

                function adjustRest(delta) {
                    remainingRest = Math.max(0, remainingRest + delta);
                    totalRestSeconds = Math.max(totalRestSeconds, remainingRest);
                    if (remainingRest <= 1) {
                        skipRest();
                    } else {
                        updateRestTimerDisplay();
                        playPlayerTone(750, 0.06, 'triangle');
                    }
                }

                function skipRest() {
                    clearInterval(restTimer);
                    renderLiveExercise();
                }

                function prevLiveExercise() {
                    if (currentExIndex > 0) {
                        currentExIndex--;
                        currentSet = 1;
                        renderLiveExercise();
                    }
                }

                function skipLiveExercise() {
                    if (currentExIndex < liveExercises.length - 1) {
                        currentExIndex++;
                        currentSet = 1;
                        renderLiveExercise();
                        playPlayerTone(660, 0.08, 'triangle');
                    } else {
                        // On last exercise, offer to finish workout session
                        const confirmOverlay = document.getElementById('player-exit-confirm');
                        if (confirmOverlay) {
                            const titleEl = document.getElementById('player-confirm-title');
                            const descEl = document.getElementById('player-confirm-desc');
                            const actBtn = document.getElementById('btn-confirm-exit-action');
                            if (titleEl) titleEl.innerText = 'Finish Workout Session?';
                            if (descEl) descEl.innerText = 'This is the last scheduled exercise for today. Complete your session?';
                            if (actBtn) {
                                actBtn.innerText = 'Finish Workout';
                                actBtn.onclick = () => {
                                    cancelExitWorkout();
                                    finishLiveWorkout();
                                };
                            }
                            confirmOverlay.style.display = 'flex';
                        } else {
                            finishLiveWorkout();
                        }
                    }
                }

                function finishLiveWorkout() {
                    clearInterval(workoutStopwatchInterval);
                    clearInterval(restTimer);

                    document.getElementById('player-main-view').style.display = 'none';
                    document.getElementById('player-timer-screen').style.display = 'none';
                    document.getElementById('player-bottom-dock').style.display = 'none';
                    const celebScreen = document.getElementById('player-celebration-screen');
                    celebScreen.style.display = 'flex';

                    const fillEl = document.querySelector('.player-progress-bar .progress-fill');
                    if (fillEl) fillEl.style.width = '100%';

                    // Compute workout summary stats
                    let totalSetsCrushed = 0;
                    liveExercises.forEach(e => {
                        totalSetsCrushed += Math.max(1, parseInt(e.sets, 10) || 1);
                    });

                    document.getElementById('celeb-time-val').innerText = formatElapsed(workoutElapsedSeconds);
                    document.getElementById('celeb-ex-val').innerText = liveExercises.length;
                    document.getElementById('celeb-sets-val').innerText = totalSetsCrushed;

                    const upgradeCard = document.getElementById('celeb-tier-upgrade-notice');
                    if (tierUpgradedData) {
                        upgradeCard.style.display = 'block';
                        upgradeCard.innerHTML = `🎉 <strong>Level Up!</strong> Promoted to <strong>${escapeHtml(tierUpgradedData.new_tier_name)}</strong>!`;
                    } else {
                        upgradeCard.style.display = 'none';
                    }

                    if (window.playNotifSound) window.playNotifSound('ready');
                    if (navigator.vibrate) navigator.vibrate([150, 80, 200]);

                    const todayData = CALENDAR_DATA[TODAY_DATE];
                    if (todayData) {
                        todayData.allDone = true;
                        todayData.status = 'completed';
                    }
                }

                function closeCelebrationAndFinish() {
                    cleanupPlayerModal();
                    window.location.reload();
                }

                // Keyboard Accessibility
                document.addEventListener('keydown', (e) => {
                    const modal = document.getElementById('workout-player-modal');
                    if (!modal || modal.style.display === 'none') return;

                    if (e.key === 'Escape') {
                        closeLiveWorkout();
                    } else if (e.code === 'Space' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT') {
                        e.preventDefault();
                        const timerScreen = document.getElementById('player-timer-screen');
                        if (timerScreen && timerScreen.style.display !== 'none') {
                            skipRest();
                        } else {
                            completeSet();
                        }
                    }
                });

                // Initial render on page load: automatically loads initialSelectedDate
                document.addEventListener('DOMContentLoaded', () => {
                    selectDate(selectedDate);
                });
