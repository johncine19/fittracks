/**
 * QR Scanner Terminal Controller
 * Extracted from scanner.php
 */
(function() {
    'use strict';

    const csrfToken = (window.SCANNER_CONFIG && window.SCANNER_CONFIG.csrfToken)
        ? window.SCANNER_CONFIG.csrfToken
        : (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        let html5QrCode = null;
        let isScannerRunning = false;
        let isScannerPaused = false;
        let isProcessing = false;
        let currentCameraId = null;
        let availableCameras = [];
        let isTorchOn = false;
        let resetTimer = null;
        const processedTokensInSession = new Set();

        // Elements
        const viewfinderWrap = document.getElementById('viewfinder-wrap');
        const standbyScreen = document.getElementById('standby-screen');
        const btnStartCamera = document.getElementById('btn-start-camera');
        const cameraSelect = document.getElementById('camera-select');
        const btnFlipCam = document.getElementById('btn-flip-cam');
        const btnToggleTorch = document.getElementById('btn-toggle-torch');
        const btnPauseScanner = document.getElementById('btn-pause-scanner');
        const btnStopScanner = document.getElementById('btn-stop-scanner');
        const qrFileInput = document.getElementById('qr-file-input');
        const topBadge = document.getElementById('viewfinder-badge-top');
        const topBadgeText = document.getElementById('viewfinder-badge-text');

        // Verification HUD Elements
        const veriCard = document.getElementById('verification-card');
        const stateIdle = document.getElementById('veri-state-idle');
        const stateProcessing = document.getElementById('veri-state-processing');
        const stateResult = document.getElementById('veri-state-result');
        const stateError = document.getElementById('veri-state-error');
        const veriTag = document.getElementById('veri-tag');
        const veriTagText = document.getElementById('veri-tag-text');
        const veriAvatarWrap = document.getElementById('veri-avatar-wrap');
        const veriMemberName = document.getElementById('veri-member-name');
        const veriRoleBadge = document.getElementById('veri-role-badge');
        const veriPlanBadge = document.getElementById('veri-plan-badge');
        const veriTimeBadge = document.getElementById('veri-time-badge');
        const veriExtraInfo = document.getElementById('veri-extra-info');
        const veriProgressBar = document.getElementById('veri-progress-bar');
        const veriErrorTitle = document.getElementById('veri-error-title');
        const veriErrorMsg = document.getElementById('veri-error-msg');

        // Stats Elements
        const statTodayCheckins = document.getElementById('stat-today-checkins');
        const statCurrentlyInside = document.getElementById('stat-currently-inside');
        const activityList = document.getElementById('activity-list');

        // Offline Resilience & IndexedDB Elements
        const netStatusBadge = document.getElementById('net-status-badge');
        const netStatusLabel = document.getElementById('net-status-label');
        const btnOfflineSync = document.getElementById('btn-offline-sync');
        const offlinePendingCount = document.getElementById('offline-pending-count');
        const rosterCacheText = document.getElementById('roster-cache-text');

        // -------------------------------------------------------------
        // FitTracks Offline-First Resilience & PWA Manager (IndexedDB)
        // -------------------------------------------------------------
        const DB_NAME = 'FitTracksTerminalDB';
        const DB_VERSION = 1;
        let dbInstance = null;
        let isSyncingOffline = false;
        let isNetworkOnline = navigator.onLine;

        function getDB() {
            return new Promise((resolve, reject) => {
                if (dbInstance) return resolve(dbInstance);
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains('roster')) {
                        const rStore = db.createObjectStore('roster', { keyPath: 'user_id' });
                        rStore.createIndex('qr_token', 'qr_token', { unique: false });
                        rStore.createIndex('name', 'name', { unique: false });
                    }
                    if (!db.objectStoreNames.contains('queue')) {
                        db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
                    }
                };
                req.onsuccess = (e) => {
                    dbInstance = e.target.result;
                    resolve(dbInstance);
                };
                req.onerror = (e) => reject(e);
            });
        }

        async function updateRosterCacheCountUI() {
            try {
                const db = await getDB();
                const tx = db.transaction('roster', 'readonly');
                const countReq = tx.objectStore('roster').count();
                countReq.onsuccess = () => {
                    if (rosterCacheText) {
                        rosterCacheText.textContent = `Cache: ${countReq.result}`;
                    }
                };
            } catch (e) {}
        }

        async function updateQueueBadgeUI() {
            try {
                const db = await getDB();
                const tx = db.transaction('queue', 'readonly');
                const countReq = tx.objectStore('queue').count();
                countReq.onsuccess = () => {
                    const cnt = countReq.result || 0;
                    if (cnt > 0) {
                        if (btnOfflineSync) {
                            btnOfflineSync.style.display = 'inline-flex';
                            if (offlinePendingCount) {
                                offlinePendingCount.textContent = `${cnt} Queued`;
                            }
                        }
                    } else {
                        if (btnOfflineSync) {
                            btnOfflineSync.style.display = 'none';
                        }
                    }
                };
            } catch (e) {}
        }

        function setNetworkStatus(online) {
            isNetworkOnline = online;
            if (netStatusBadge && netStatusLabel) {
                if (online) {
                    netStatusBadge.className = 'net-status-badge is-online';
                    netStatusLabel.textContent = 'ONLINE';
                } else {
                    netStatusBadge.className = 'net-status-badge is-offline';
                    netStatusLabel.textContent = 'OFFLINE MODE';
                }
            }
        }

        window.refreshOfflineRoster = async function(showToast = false) {
            if (!navigator.onLine) {
                if (showToast && window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Offline mode active. Using saved local database.',
                        timer: 3000,
                        showConfirmButton: false
                    });
                }
                return;
            }

            try {
                if (rosterCacheText) rosterCacheText.textContent = 'Caching...';
                const res = await fetch('index.php?page=scanner&action=get_offline_roster');
                const data = await res.json();
                if (data.success && Array.isArray(data.members)) {
                    const db = await getDB();
                    const tx = db.transaction('roster', 'readwrite');
                    const store = tx.objectStore('roster');
                    await new Promise((resClean, rejClean) => {
                        const cReq = store.clear();
                        cReq.onsuccess = resClean;
                        cReq.onerror = rejClean;
                    });
                    for (const m of data.members) {
                        store.put(m);
                    }
                    await new Promise((resComp) => { tx.oncomplete = resComp; });
                    if (rosterCacheText) {
                        rosterCacheText.textContent = `Cache: ${data.members.length}`;
                    }
                    if (showToast && window.Swal) {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `Cached ${data.members.length} members for offline check-ins!`,
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                }
            } catch (e) {
                console.warn('Failed to refresh offline roster:', e);
                updateRosterCacheCountUI();
            }
        };

        window.triggerManualSync = function() {
            syncOfflineQueue(true);
        };

        async function syncOfflineQueue(userTriggered = false) {
            if (isSyncingOffline) return;
            if (!navigator.onLine) {
                if (userTriggered && window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'warning',
                        title: 'Cannot sync while offline. Waiting for connection...',
                        timer: 3000,
                        showConfirmButton: false
                    });
                }
                return;
            }

            try {
                const db = await getDB();
                const tx = db.transaction('queue', 'readonly');
                const items = await new Promise((res, rej) => {
                    const req = tx.objectStore('queue').getAll();
                    req.onsuccess = () => res(req.result || []);
                    req.onerror = rej;
                });

                if (!items || items.length === 0) {
                    updateQueueBadgeUI();
                    if (userTriggered && window.Swal) {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'info',
                            title: 'All attendance records are up to date.',
                            timer: 2500,
                            showConfirmButton: false
                        });
                    }
                    return;
                }

                isSyncingOffline = true;
                if (btnOfflineSync) {
                    btnOfflineSync.classList.add('is-syncing');
                    if (offlinePendingCount) offlinePendingCount.textContent = `Syncing (${items.length})...`;
                }

                const payload = items.map(it => ({
                    temp_id: it.id,
                    action: it.action,
                    qr_data: it.qr_data,
                    user_id: it.user_id,
                    amount_paid: it.amount_paid || 0,
                    payment_method: it.payment_method || 'cash',
                    scanned_at: it.scanned_at
                }));

                const resp = await fetch('index.php?page=scanner', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=sync_offline_batch&batch=' + encodeURIComponent(JSON.stringify(payload)) + '&csrf_token=' + encodeURIComponent(csrfToken)
                });
                const resData = await resp.json();

                if (resData.success) {
                    const delTx = db.transaction('queue', 'readwrite');
                    const qStore = delTx.objectStore('queue');
                    for (const sId of (resData.synced_ids || [])) {
                        qStore.delete(sId);
                    }
                    await new Promise((resDel) => { delTx.oncomplete = resDel; });

                    setNetworkStatus(true);
                    updateQueueBadgeUI();

                    if (resData.stats) {
                        if (statTodayCheckins) statTodayCheckins.textContent = resData.stats.today_checkins;
                        if (statCurrentlyInside) statCurrentlyInside.textContent = resData.stats.currently_inside;
                    }

                    if (typeof pollRecentActivity === 'function') {
                        pollRecentActivity();
                    }

                    if (window.Swal) {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `⚡ Back Online! Synced ${resData.synced_count} offline record(s) with central database.`,
                            timer: 4500,
                            showConfirmButton: false
                        });
                    }
                }
            } catch (err) {
                console.warn('Sync failed (connection issue):', err);
                setNetworkStatus(false);
            } finally {
                isSyncingOffline = false;
                if (btnOfflineSync) btnOfflineSync.classList.remove('is-syncing');
                updateQueueBadgeUI();
            }
        }

        async function queueOfflineScan(scanData, method = 'qr_code', extra = {}) {
            let member = null;
            let userId = null;

            if (method === 'manual') {
                userId = parseInt(scanData, 10);
            } else if (typeof scanData === 'string' && scanData.includes(':')) {
                const parts = scanData.split(':');
                userId = parseInt(parts[0], 10);
            }

            try {
                const db = await getDB();
                if (userId) {
                    const tx = db.transaction('roster', 'readonly');
                    member = await new Promise((res) => {
                        const req = tx.objectStore('roster').get(userId);
                        req.onsuccess = () => res(req.result || null);
                        req.onerror = () => res(null);
                    });
                }
            } catch (e) {
                console.warn('Error reading offline roster:', e);
            }

            const isCheckOut = member ? !!member.is_inside : false;
            const nowIso = new Date().toISOString();

            const queueItem = {
                action: method === 'manual' ? 'manual_checkin' : 'process_qr',
                qr_data: scanData,
                user_id: userId,
                method: method,
                amount_paid: extra.amount || 0,
                payment_method: extra.method || 'cash',
                scanned_at: nowIso,
                is_checkout: isCheckOut,
                member_name: member ? member.name : (userId ? `Member #${userId}` : 'Walk-in / Guest'),
                member_role: member ? member.role : 'Member',
                member_plan: member ? (member.role === 'trainer' ? 'Certified Trainer' : 'Active Member') : 'Walk-in / Guest Pass',
                avatar_html: member ? member.avatar_html : '',
                created_at: Date.now()
            };

            try {
                const db = await getDB();
                const tx = db.transaction(['queue', 'roster'], 'readwrite');
                tx.objectStore('queue').add(queueItem);
                if (member) {
                    member.is_inside = !isCheckOut;
                    tx.objectStore('roster').put(member);
                }
                await new Promise((res) => { tx.oncomplete = res; });
            } catch (e) {
                console.error('Failed to write to offline queue:', e);
            }

            setNetworkStatus(false);
            updateQueueBadgeUI();
            displayOfflineSuccessResult(queueItem);
        }

        function displayOfflineSuccessResult(data) {
            playChime('success');

            const isCheckIn = !data.is_checkout;
            veriCard.className = 'verification-card ' + (isCheckIn ? 'state-success-in' : 'state-success-out');
            veriTag.className = 'veri-status-tag tag-offline';
            veriTagText.textContent = isCheckIn ? 'OFFLINE CHECK-IN CONFIRMED' : 'OFFLINE CHECK-OUT RECORDED';

            veriAvatarWrap.innerHTML = data.avatar_html || '';
            veriMemberName.textContent = data.member_name || 'Member';
            veriRoleBadge.textContent = data.member_role || 'Member';
            veriPlanBadge.textContent = (data.member_plan || 'Active') + ' • Offline Mode';

            const localTimeStr = new Date(data.scanned_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            veriTimeBadge.textContent = localTimeStr;

            veriExtraInfo.innerHTML = '<span style="color:#f59e0b; font-weight:700;">⚡ Saved to Local Device</span> • Will automatically sync once electricity/internet returns.';
            setHudState('result');

            // Optimistic update of local stats
            if (statTodayCheckins) {
                const cur = parseInt(statTodayCheckins.textContent, 10) || 0;
                statTodayCheckins.textContent = cur + (isCheckIn ? 1 : 0);
            }
            if (statCurrentlyInside) {
                const curInside = parseInt(statCurrentlyInside.textContent, 10) || 0;
                statCurrentlyInside.textContent = Math.max(0, curInside + (isCheckIn ? 1 : -1));
            }

            prependActivityFeed({
                avatar_html: data.avatar_html,
                name: data.member_name,
                time_formatted: localTimeStr,
                method: data.method === 'manual' ? 'Manual' : 'QR Scan',
                is_checkout: !isCheckIn,
                duration: null,
                is_offline: true
            });

            veriProgressBar.style.transition = 'none';
            veriProgressBar.style.width = '100%';
            setTimeout(() => {
                veriProgressBar.style.transition = 'width 4s linear';
                veriProgressBar.style.width = '0%';
            }, 50);

            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                isProcessing = false;
                setHudState('idle');
            }, 4000);
        }

        async function searchOfflineRoster(query) {
            manualResultsList.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--muted);">Searching offline cache...</div>';
            try {
                const db = await getDB();
                const tx = db.transaction('roster', 'readonly');
                const allMembers = await new Promise((res, rej) => {
                    const req = tx.objectStore('roster').getAll();
                    req.onsuccess = () => res(req.result || []);
                    req.onerror = rej;
                });

                const q = query.trim().toLowerCase();
                const filtered = q.length === 0
                    ? allMembers.slice(0, 15)
                    : allMembers.filter(m => {
                        const name = (m.name || '').toLowerCase();
                        const email = (m.email || '').toLowerCase();
                        const phone = (m.phone || '').toLowerCase();
                        return name.includes(q) || email.includes(q) || phone.includes(q);
                    }).slice(0, 15);

                if (filtered.length === 0) {
                    manualResultsList.innerHTML = '<div style="text-align:center; padding: 25px; color: var(--muted);">No matching members found in offline cache.</div>';
                    return;
                }

                manualResultsList.innerHTML = '';
                filtered.forEach(m => {
                    const row = document.createElement('div');
                    row.className = 'manual-item';
                    const safeName = typeof escapeHtml === 'function' ? escapeHtml(m.name) : m.name;
                    const safeRole = typeof escapeHtml === 'function' ? escapeHtml(m.role) : m.role;
                    const safePhone = typeof escapeHtml === 'function' ? escapeHtml(m.phone) : m.phone;
                    const insideBadge = m.is_inside ? `<span style="display:inline-block; margin-top:2px; font-size:0.7rem; font-weight:700; color:#38bdf8;">Currently Inside (Offline Cache)</span>` : '';

                    row.innerHTML = `
                        <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                            ${m.avatar_html}
                            <div style="min-width:0;">
                                <p style="margin:0; font-weight:700; color:var(--ink); font-size:0.9rem;">${safeName}</p>
                                <div style="font-size:0.75rem; color:var(--muted);">${safeRole} • ${safePhone}</div>
                                ${insideBadge}
                            </div>
                        </div>
                        <div>
                            <button type="button" class="btn-manual-action ${m.is_inside ? 'checkout' : 'checkin'}" onclick="submitManualAttendance(${parseInt(m.user_id, 10)})">
                                ${m.is_inside ? 'Check-Out' : 'Check-In'}
                            </button>
                        </div>
                    `;
                    manualResultsList.appendChild(row);
                });
            } catch (err) {
                manualResultsList.innerHTML = '<div style="text-align:center; padding: 25px; color: var(--danger);">Failed to read offline database.</div>';
            }
        }

        // Clock Update
        function updateTerminalClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateStr = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
            
            const timeEl = document.getElementById('terminal-digital-time');
            const dateEl = document.getElementById('terminal-digital-date');
            if (timeEl) timeEl.textContent = timeStr;
            if (dateEl) dateEl.textContent = dateStr;
        }
        setInterval(updateTerminalClock, 1000);
        updateTerminalClock();

        // Audio & Haptic Feedback
        function playChime(type) {
            try {
                if (window.FitTrackAudio && typeof window.FitTrackAudio.play === 'function') {
                    window.FitTrackAudio.play(type === 'error' ? 'warning' : 'success');
                }
            } catch (e) {}

            if (navigator.vibrate) {
                try {
                    if (type === 'error') {
                        navigator.vibrate([150, 80, 150]);
                    } else {
                        navigator.vibrate([80, 40, 80]);
                    }
                } catch (e) {}
            }
        }

        // Initialize Low-Level Html5QrCode instance
        function initScannerEngine() {
            if (!html5QrCode) {
                html5QrCode = new Html5Qrcode("reader-video-container", {
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    },
                    verbose: false
                });
            }
        }

        // Camera permissions & start
        async function startCameraScanner(preferredCameraId = null) {
            initScannerEngine();
            setHudState('processing');

            try {
                // Discover cameras if not yet cached
                if (availableCameras.length === 0) {
                    availableCameras = await Html5Qrcode.getCameras();
                    populateCameraSelect(availableCameras);
                }

                if (!availableCameras || availableCameras.length === 0) {
                    showErrorResult('No Camera Detected', 'Please connect a webcam or enable camera permissions.');
                    return;
                }

                let cameraIdToUse = preferredCameraId;
                if (!cameraIdToUse) {
                    const savedCamera = localStorage.getItem('fittracks_scanner_cam');
                    const foundSaved = availableCameras.find(c => c.id === savedCamera);
                    if (foundSaved) {
                        cameraIdToUse = foundSaved.id;
                    } else {
                        // 1. Prefer rear/environment camera (phones/tablets)
                        const rear = availableCameras.find(c => /back|rear|environment/i.test(c.label));
                        // 2. Prefer real physical webcam over virtual drivers (OBS, ManyCam, DroidCam)
                        const physical = availableCameras.find(c => !/virtual|obs|manycam|droidcam|splitcam|ndi/i.test(c.label));
                        cameraIdToUse = rear ? rear.id : (physical ? physical.id : availableCameras[0].id);
                    }
                }

                currentCameraId = cameraIdToUse;
                cameraSelect.value = currentCameraId;

                // Stop active stream before restarting
                if (isScannerRunning) {
                    await html5QrCode.stop();
                    isScannerRunning = false;
                }

                // Dynamic responsive qrbox
                const config = {
                    fps: 15,
                    qrbox: (viewfinderWidth, viewfinderHeight) => {
                        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                        const boxSize = Math.floor(minEdge * 0.76);
                        return { width: boxSize, height: boxSize };
                    },
                    aspectRatio: 1.0
                };

                try {
                    await html5QrCode.start(
                        cameraIdToUse,
                        config,
                        onQrCodeScanned,
                        onQrScanFailure
                    );
                } catch (startErr) {
                    // If the camera failed and it's a virtual camera (like OBS), try auto-failing over to a physical camera
                    const isVirtual = availableCameras.some(c => c.id === cameraIdToUse && /virtual|obs|manycam|droidcam/i.test(c.label));
                    const physicalFallback = availableCameras.find(c => c.id !== cameraIdToUse && !/virtual|obs|manycam|droidcam/i.test(c.label));
                    if (isVirtual && physicalFallback) {
                        console.warn("Virtual camera failed, auto-failing over to physical webcam:", physicalFallback.label);
                        cameraIdToUse = physicalFallback.id;
                        currentCameraId = cameraIdToUse;
                        cameraSelect.value = currentCameraId;
                        localStorage.setItem('fittracks_scanner_cam', currentCameraId);
                        await html5QrCode.start(
                            cameraIdToUse,
                            config,
                            onQrCodeScanned,
                            onQrScanFailure
                        );
                    } else {
                        throw startErr;
                    }
                }

                isScannerRunning = true;
                isScannerPaused = false;
                standbyScreen.style.display = 'none';
                viewfinderWrap.classList.add('scanning');
                btnPauseScanner.style.display = 'inline-flex';
                btnPauseScanner.querySelector('span').textContent = 'Pause';
                btnStopScanner.style.display = 'inline-flex';
                topBadge.classList.add('is-active');
                topBadgeText.textContent = 'LIVE SCANNING';

                if (availableCameras.length > 1) {
                    btnFlipCam.style.display = 'inline-flex';
                }

                // Check torch support
                checkTorchSupport();
                setHudState('idle');

            } catch (err) {
                console.error("Camera start error:", err);
                viewfinderWrap.classList.remove('scanning');
                standbyScreen.style.display = 'flex';
                topBadge.classList.remove('is-active');
                topBadgeText.textContent = 'OFFLINE';
                btnPauseScanner.style.display = 'none';
                btnStopScanner.style.display = 'none';

                const errStr = (typeof err === 'string' ? err : (err?.message || err?.name || String(err))).toLowerCase();
                let errTitle = 'Camera Error';
                let errMsg = 'Could not start camera stream.';

                if (errStr.includes('notfound') || errStr.includes('devicesnotfound')) {
                    errTitle = 'No Camera Detected';
                    errMsg = 'No webcam or optical sensor found on this system.';
                } else if (errStr.includes('notreadable') || errStr.includes('could not start video source') || errStr.includes('track') || errStr.includes('in use') || errStr.includes('source')) {
                    errTitle = 'Camera Unavailable';
                    errMsg = 'The selected camera (e.g. OBS Virtual Camera) is inactive or in use by another app. Please select your physical webcam from the dropdown above.';
                } else if (errStr.includes('notallowed') || errStr.includes('permission') || errStr.includes('denied')) {
                    errTitle = 'Camera Access Required';
                    errMsg = 'Camera permission was denied. Please allow camera access in your browser.';
                } else {
                    errMsg = (err && err.message) ? err.message : 'Camera failed to activate. Please choose another camera from the dropdown.';
                }
                showErrorResult(errTitle, errMsg);
            }
        }

        function populateCameraSelect(cameras) {
            cameraSelect.innerHTML = '';
            cameras.forEach((cam, idx) => {
                const opt = document.createElement('option');
                opt.value = cam.id;
                opt.textContent = cam.label || `Camera ${idx + 1}`;
                cameraSelect.appendChild(opt);
            });
        }

        async function checkTorchSupport() {
            try {
                const track = html5QrCode.getRunningTrackCameraCapabilities();
                if (track && typeof track.torchFeature === 'function' && track.torchFeature().isSupported()) {
                    btnToggleTorch.style.display = 'inline-flex';
                } else {
                    btnToggleTorch.style.display = 'none';
                }
            } catch (e) {
                btnToggleTorch.style.display = 'none';
            }
        }

        // Toggle Flashlight / Torch
        async function toggleTorch() {
            if (!isScannerRunning) return;
            try {
                isTorchOn = !isTorchOn;
                await html5QrCode.applyVideoConstraints({
                    advanced: [{ torch: isTorchOn }]
                });
                btnToggleTorch.classList.toggle('active', isTorchOn);
            } catch (err) {
                console.warn("Torch not supported:", err);
                btnToggleTorch.style.display = 'none';
            }
        }

        // QR Code Successfully Read
        function onQrCodeScanned(decodedText, decodedResult) {
            if (isProcessing) return;

            // Prevent rapid repeated scans of the exact same token in a single session
            if (processedTokensInSession.has(decodedText)) {
                return;
            }

            processAttendanceData(decodedText, 'qr_code');
        }

        function onQrScanFailure(error) {
            // Ignore frame-by-frame read attempts
        }

        // Process Attendance with Server
        function processAttendanceData(qrString, method = 'qr_code') {
            isProcessing = true;
            if (method !== 'image_file') {
                processedTokensInSession.add(qrString);
            }

            // Audio & Visual Trigger
            viewfinderWrap.classList.add('flash-success');
            setTimeout(() => viewfinderWrap.classList.remove('flash-success'), 600);

            setHudState('processing');

            if (!isNetworkOnline) {
                queueOfflineScan(qrString, method);
                return;
            }

            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process_qr&qr_data=' + encodeURIComponent(qrString) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(res => res.json())
            .then(data => {
                if (data.requires_payment) {
                    playChime('error');
                    viewfinderWrap.classList.add('flash-error');
                    setTimeout(() => viewfinderWrap.classList.remove('flash-error'), 800);

                    // Prompt Walk-In Payment Modal with Auto-Detected Gym Walk-in Fee
                    const defaultFee = data.walk_in_fee ? parseFloat(data.walk_in_fee).toFixed(2) : '100.00';
                    Swal.fire({
                        title: 'Guest Walk-in Payment Required',
                        html: `
                            <div style="text-align: left; margin: 6px 0;">
                                <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px;">
                                    <div style="color: var(--danger); font-weight: 700; font-size: 0.9rem; margin-bottom: 3px;">${data.message}</div>
                                    <div style="font-size: 0.82rem; color: var(--muted);">Member: <strong style="color: var(--ink);">${data.member_name || 'Visitor'}</strong></div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <label style="font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em;">Amount to Collect</label>
                                    <span style="font-size: 0.76rem; color: var(--lime); font-weight: 700;">Rate Detected: ₱${defaultFee}</span>
                                </div>
                                <div style="position: relative; margin-bottom: 16px;">
                                    <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-weight: 800; color: var(--lime); font-size: 16px;">₱</span>
                                    <input type="number" id="swal-amount" class="swal2-input swal-amount-input" placeholder="0.00" step="0.01" min="0" value="${defaultFee}" style="width: 100% !important; margin: 0 !important; padding-left: 36px !important; height: 46px !important; border-radius: 12px !important; box-sizing: border-box !important; font-size: 1.15rem !important; font-weight: 800 !important;">
                                </div>

                                <label style="display: block; font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px;">
                                    Payment Method
                                </label>
                                <div class="swal-pay-methods" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 10px;">
                                    <button type="button" class="swal-method-btn active" data-method="cash" onclick="window.setSwalMethod('cash', this)">
                                        <span class="swal-icon-wrap" style="font-weight: 900; font-size: 1.45rem; line-height: 1;">₱</span>
                                        <span>Cash</span>
                                    </button>
                                    <button type="button" class="swal-method-btn" data-method="gcash" onclick="window.setSwalMethod('gcash', this)">
                                        <span class="swal-icon-wrap">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"></rect><line x1="10" y1="18" x2="14" y2="18"></line></svg>
                                        </span>
                                        <span>GCash</span>
                                    </button>
                                    <button type="button" class="swal-method-btn" data-method="card" onclick="window.setSwalMethod('card', this)">
                                        <span class="swal-icon-wrap">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                        </span>
                                        <span>Card</span>
                                    </button>
                                </div>
                                <input type="hidden" id="swal-method" value="cash">
                            </div>
                        `,
                        background: 'var(--panel)',
                        color: 'var(--ink)',
                        confirmButtonColor: 'var(--lime)',
                        cancelButtonColor: 'color-mix(in srgb, var(--ink) 12%, transparent)',
                        showCancelButton: true,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        confirmButtonText: '<span style="color:#05080c; font-weight:700;">Record Payment & Check-in</span>',
                        cancelButtonText: '<span style="color:var(--ink);">Cancel</span>',
                        didOpen: () => {
                            window.setSwalMethod = function(m, btn) {
                                const hidden = document.getElementById('swal-method');
                                if (hidden) hidden.value = m;
                                document.querySelectorAll('.swal-method-btn').forEach(b => {
                                    b.classList.toggle('active', b.getAttribute('data-method') === m);
                                });
                            };
                        },
                        preConfirm: () => {
                            const amt = document.getElementById('swal-amount').value;
                            const meth = document.getElementById('swal-method').value;
                            return { amount: amt || 0, method: meth };
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch('index.php?page=scanner', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=process_qr&qr_data=' + encodeURIComponent(qrString) + '&amount_paid=' + encodeURIComponent(result.value.amount) + '&payment_method=' + encodeURIComponent(result.value.method) + '&csrf_token=' + encodeURIComponent(csrfToken)
                            })
                            .then(r => r.json())
                            .then(data2 => {
                                if (data2.success) {
                                    handleSuccessResponse(data2);
                                } else {
                                    showErrorResult('Payment / Check-in Failed', data2.message);
                                }
                            })
                            .catch(err => {
                                console.warn('Network error recording payment, saving offline:', err);
                                setNetworkStatus(false);
                                queueOfflineScan(qrString, method, { amount: result.value.amount, method: result.value.method });
                            });
                        } else {
                            isProcessing = false;
                            processedTokensInSession.delete(qrString);
                            setHudState('idle');
                        }
                    });
                    return;
                }

                if (data.success) {
                    handleSuccessResponse(data);
                } else {
                    showErrorResult('Scan Verification Failed', data.message);
                    setTimeout(() => {
                        processedTokensInSession.delete(qrString);
                    }, 4000);
                }
            })
            .catch(err => {
                console.warn('Network unreachable, auto-diverting to offline queue:', err);
                setNetworkStatus(false);
                queueOfflineScan(qrString, method);
            });
        }

        // Handle Success Verification Response
        function handleSuccessResponse(data) {
            playChime('success');

            const isCheckIn = data.action_type === 'checkin';
            const m = data.member;

            veriCard.className = 'verification-card ' + (isCheckIn ? 'state-success-in' : 'state-success-out');
            veriTag.className = 'veri-status-tag ' + (isCheckIn ? 'tag-in' : 'tag-out');
            veriTagText.textContent = isCheckIn ? 'CHECK-IN CONFIRMED' : 'CHECK-OUT RECORDED';

            veriAvatarWrap.innerHTML = m.avatar_html || '';
            veriMemberName.textContent = m.name || 'Member';
            veriRoleBadge.textContent = m.role || 'Member';
            veriPlanBadge.textContent = m.plan_name || 'Active Member';
            veriTimeBadge.textContent = data.time || 'Just now';

            let extraHtml = '';
            if (m.class_name) {
                extraHtml += `<span style="color: var(--lime); font-weight:700;">★ Class Booked:</span> ${m.class_name} (Attended)`;
            } else if (!isCheckIn && m.duration) {
                extraHtml += `<span style="color: #38bdf8; font-weight:700;">Session Duration:</span> ${m.duration}`;
            }
            veriExtraInfo.innerHTML = extraHtml;

            setHudState('result');

            // Update stats
            if (data.stats) {
                if (statTodayCheckins) statTodayCheckins.textContent = data.stats.today_checkins;
                if (statCurrentlyInside) statCurrentlyInside.textContent = data.stats.currently_inside;
            }

            // Prepend to Today's Feed
            prependActivityFeed({
                avatar_html: m.avatar_html,
                name: m.name,
                time_formatted: data.time || 'Just now',
                method: 'QR Scan',
                is_checkout: !isCheckIn,
                duration: m.duration || null
            });

            // Start smooth progress bar countdown for reset (4 seconds)
            veriProgressBar.style.transition = 'none';
            veriProgressBar.style.width = '100%';
            setTimeout(() => {
                veriProgressBar.style.transition = 'width 4s linear';
                veriProgressBar.style.width = '0%';
            }, 50);

            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                isProcessing = false;
                setHudState('idle');
            }, 4000);
        }

        // Show Error Result Card
        function showErrorResult(title, message) {
            playChime('error');
            viewfinderWrap.classList.add('flash-error');
            setTimeout(() => viewfinderWrap.classList.remove('flash-error'), 800);

            veriCard.className = 'verification-card state-error';
            veriErrorTitle.textContent = title;
            veriErrorMsg.textContent = message;
            setHudState('error');

            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                isProcessing = false;
                setHudState('idle');
            }, 4500);
        }

        function setHudState(state) {
            stateIdle.style.display = state === 'idle' ? 'block' : 'none';
            stateProcessing.style.display = state === 'processing' ? 'block' : 'none';
            stateResult.style.display = state === 'result' ? 'block' : 'none';
            stateError.style.display = state === 'error' ? 'block' : 'none';

            if (state === 'idle') {
                veriCard.className = 'verification-card';
            }
        }

        function prependActivityFeed(item) {
            const emptyMsg = document.getElementById('activity-empty');
            if (emptyMsg) emptyMsg.remove();

            const row = document.createElement('div');
            row.className = 'activity-item';
            row.setAttribute('data-status', item.is_checkout ? 'out' : 'in');
            row.style.animation = 'fadeIn 0.3s ease';

            const safeName = typeof escapeHtml === 'function' ? escapeHtml(item.name) : item.name;
            const safeTime = typeof escapeHtml === 'function' ? escapeHtml(item.time_formatted) : item.time_formatted;
            const safeMethod = typeof escapeHtml === 'function' ? escapeHtml(item.method) : item.method;
            const safeDuration = item.duration && typeof escapeHtml === 'function' ? escapeHtml(item.duration) : item.duration;

            let subText = `<span>${safeTime}</span><span>•</span><span>${safeMethod}</span>`;
            if (safeDuration) {
                subText += `<span>• ${safeDuration}</span>`;
            }

            const tagClass = item.is_offline ? 'offline' : (item.is_checkout ? 'out' : 'in');
            const tagLabel = item.is_offline 
                ? (item.is_checkout ? 'OUT (OFFLINE)' : 'IN (OFFLINE)') 
                : (item.is_checkout ? 'CHECK-OUT' : 'CHECK-IN');

            row.innerHTML = `
                <div class="activity-left">
                    ${item.avatar_html}
                    <div class="activity-name-meta">
                        <p class="activity-name">${safeName}</p>
                        <div class="activity-sub">${subText}</div>
                    </div>
                </div>
                <span class="activity-tag ${tagClass}">
                    ${tagLabel}
                </span>
            `;

            activityList.insertBefore(row, activityList.firstChild);
        }

        // Dedicated offscreen instance for file scans (does not interfere with active camera stream)
        let fileScannerInstance = null;
        function getFileScanner() {
            if (!fileScannerInstance) {
                let dummy = document.getElementById('qr-file-dummy-container');
                if (!dummy) {
                    dummy = document.createElement('div');
                    dummy.id = 'qr-file-dummy-container';
                    dummy.style.display = 'none';
                    document.body.appendChild(dummy);
                }
                fileScannerInstance = new Html5Qrcode('qr-file-dummy-container');
            }
            return fileScannerInstance;
        }

        // Scan from Image File
        qrFileInput.addEventListener('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            setHudState('processing');

            getFileScanner().scanFile(file, false)
                .then(decodedText => {
                    processAttendanceData(decodedText, 'image_file');
                    qrFileInput.value = '';
                })
                .catch(err => {
                    console.error("File QR decode error:", err);
                    showErrorResult('No QR Code Found', 'Could not detect a valid FitTrack QR code in this image.');
                    qrFileInput.value = '';
                });
        });

        // Camera Select Dropdown Change
        cameraSelect.addEventListener('change', function() {
            const selectedId = this.value;
            if (selectedId) {
                localStorage.setItem('fittracks_scanner_cam', selectedId);
                startCameraScanner(selectedId);
            }
        });

        // Flip Camera Button
        btnFlipCam.addEventListener('click', function() {
            if (availableCameras.length < 2) return;
            const curIndex = availableCameras.findIndex(c => c.id === currentCameraId);
            const nextIndex = (curIndex + 1) % availableCameras.length;
            const nextCam = availableCameras[nextIndex];
            localStorage.setItem('fittracks_scanner_cam', nextCam.id);
            startCameraScanner(nextCam.id);
        });

        // Torch toggle
        btnToggleTorch.addEventListener('click', toggleTorch);

        // Start button on standby screen
        btnStartCamera.addEventListener('click', function() {
            startCameraScanner();
        });

        // Pause / Resume Scanner
        btnPauseScanner.addEventListener('click', function() {
            if (!html5QrCode || !isScannerRunning) return;

            if (isScannerPaused) {
                html5QrCode.resume();
                isScannerPaused = false;
                viewfinderWrap.classList.add('scanning');
                this.querySelector('span').textContent = 'Pause';
                topBadgeText.textContent = 'LIVE SCANNING';
            } else {
                html5QrCode.pause();
                isScannerPaused = true;
                viewfinderWrap.classList.remove('scanning');
                this.querySelector('span').textContent = 'Resume';
                topBadgeText.textContent = 'PAUSED';
            }
        });

        // Stop Camera Scanner Completely (Returns to Standby Screen)
        async function stopCameraScanner() {
            if (!html5QrCode) return;
            try {
                if (isScannerRunning) {
                    await html5QrCode.stop();
                }
            } catch (e) {
                console.warn("Camera stop error:", e);
            }
            isScannerRunning = false;
            isScannerPaused = false;
            viewfinderWrap.classList.remove('scanning');
            standbyScreen.style.display = 'flex';
            topBadge.classList.remove('is-active');
            topBadgeText.textContent = 'STANDBY';
            btnPauseScanner.style.display = 'none';
            btnStopScanner.style.display = 'none';
            btnFlipCam.style.display = 'none';
            btnToggleTorch.style.display = 'none';
            if (isTorchOn) {
                isTorchOn = false;
                btnToggleTorch.classList.remove('active');
            }
            setHudState('idle');
        }

        btnStopScanner.addEventListener('click', stopCameraScanner);

        // Drag & Drop image files onto the viewfinder
        viewfinderWrap.addEventListener('dragover', (e) => {
            e.preventDefault();
            viewfinderWrap.style.borderColor = 'var(--lime)';
        });
        viewfinderWrap.addEventListener('dragleave', () => {
            viewfinderWrap.style.borderColor = '';
        });
        viewfinderWrap.addEventListener('drop', (e) => {
            e.preventDefault();
            viewfinderWrap.style.borderColor = '';
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                const file = e.dataTransfer.files[0];
                setHudState('processing');
                getFileScanner().scanFile(file, false)
                    .then(decodedText => processAttendanceData(decodedText, 'image_file'))
                    .catch(() => showErrorResult('No QR Code Found', 'Dropped image did not contain a readable QR code.'));
            }
        });

        // Activity Feed Filter
        window.filterActivity = function(filter, btn) {
            document.querySelectorAll('.activity-feed-filter .filter-tab').forEach(t => t.classList.remove('active'));
            if (btn) btn.classList.add('active');

            document.querySelectorAll('#activity-list .activity-item').forEach(item => {
                const status = item.getAttribute('data-status');
                if (filter === 'all') {
                    item.style.display = 'flex';
                } else if (filter === status) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        };

        // Kiosk Mode Fullscreen Toggle
        window.toggleKioskMode = function() {
            const isKiosk = document.body.classList.toggle('scanner-kiosk-mode');
            const kioskBtn = document.getElementById('term-kiosk-btn');
            if (kioskBtn) {
                kioskBtn.classList.toggle('active', isKiosk);
                const span = kioskBtn.querySelector('span');
                if (span) span.textContent = isKiosk ? 'Exit Kiosk' : 'Kiosk';
            }
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 80);

            if (isKiosk && document.fullscreenEnabled && !document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else if (!isKiosk && document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
        };

        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement && document.body.classList.contains('scanner-kiosk-mode')) {
                document.body.classList.remove('scanner-kiosk-mode');
                const kioskBtn = document.getElementById('term-kiosk-btn');
                if (kioskBtn) {
                    kioskBtn.classList.remove('active');
                    const span = kioskBtn.querySelector('span');
                    if (span) span.textContent = 'Kiosk';
                }
                setTimeout(() => window.dispatchEvent(new Event('resize')), 80);
            }
        });

        // Manual Entry Modal
        let searchDebounceTimer = null;
        const manualModal = document.getElementById('manual-modal');
        const manualSearchInput = document.getElementById('manual-search-input');
        const manualResultsList = document.getElementById('manual-results-list');

        window.openManualModal = function() {
            manualModal.classList.add('open');
            manualSearchInput.value = '';
            manualSearchInput.focus();
            fetchMemberSearch('');
        };

        window.closeManualModal = function() {
            manualModal.classList.remove('open');
        };

        manualSearchInput.addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            const val = this.value.trim();
            searchDebounceTimer = setTimeout(() => {
                fetchMemberSearch(val);
            }, 250);
        });

        function fetchMemberSearch(query) {
            manualResultsList.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--muted);">Searching members...</div>';
            
            if (!isNetworkOnline) {
                searchOfflineRoster(query);
                return;
            }

            fetch('index.php?page=scanner&action=search_members&q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.members || data.members.length === 0) {
                        manualResultsList.innerHTML = '<div style="text-align:center; padding: 25px; color: var(--muted);">No matching members found.</div>';
                        return;
                    }

                    manualResultsList.innerHTML = '';
                    data.members.forEach(m => {
                        const row = document.createElement('div');
                        row.className = 'manual-item';
                        const safeName = typeof escapeHtml === 'function' ? escapeHtml(m.name) : m.name;
                        const safeRole = typeof escapeHtml === 'function' ? escapeHtml(m.role) : m.role;
                        const safePhone = typeof escapeHtml === 'function' ? escapeHtml(m.phone) : m.phone;
                        const safeDuration = m.duration && typeof escapeHtml === 'function' ? escapeHtml(m.duration) : m.duration;
                        const insideBadge = m.is_inside ? `<span style="display:inline-block; margin-top:2px; font-size:0.7rem; font-weight:700; color:#38bdf8;">Currently Inside (${safeDuration || 'Active'})</span>` : '';

                        row.innerHTML = `
                            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                                ${m.avatar_html}
                                <div style="min-width:0;">
                                    <p style="margin:0; font-weight:700; color:var(--ink); font-size:0.9rem;">${safeName}</p>
                                    <div style="font-size:0.75rem; color:var(--muted);">${safeRole} • ${safePhone}</div>
                                    ${insideBadge}
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn-manual-action ${m.is_inside ? 'checkout' : 'checkin'}" onclick="submitManualAttendance(${parseInt(m.user_id, 10)})">
                                    ${m.is_inside ? 'Check-Out' : 'Check-In'}
                                </button>
                            </div>
                        `;
                        manualResultsList.appendChild(row);
                    });
                })
                .catch(() => {
                    searchOfflineRoster(query);
                });
        }

        window.submitManualAttendance = function(userId) {
            closeManualModal();

            if (!isNetworkOnline) {
                queueOfflineScan(userId, 'manual');
                return;
            }

            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=manual_checkin&user_id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    handleSuccessResponse(data);
                } else {
                    showErrorResult('Manual Attendance Failed', data.message);
                }
            })
            .catch(err => {
                console.warn('Network error recording manual attendance, queuing offline:', err);
                setNetworkStatus(false);
                queueOfflineScan(userId, 'manual');
            });
        };

        // Auto-check for cameras and prompt on page load
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length > 0) {
                availableCameras = devices;
                populateCameraSelect(devices);
            }
        }).catch(() => {});

        // Network connection listeners
        window.addEventListener('online', () => {
            setNetworkStatus(true);
            syncOfflineQueue();
            refreshOfflineRoster(false);
        });

        window.addEventListener('offline', () => {
            setNetworkStatus(false);
        });

        // Periodic connectivity heartbeat & auto-sync check (every 20s)
        setInterval(async () => {
            if (!navigator.onLine) {
                setNetworkStatus(false);
                return;
            }
            try {
                const ctrl = new AbortController();
                const timeoutId = setTimeout(() => ctrl.abort(), 3500);
                const ping = await fetch('index.php?page=scanner&action=get_activity', { signal: ctrl.signal });
                clearTimeout(timeoutId);
                if (ping.ok) {
                    setNetworkStatus(true);
                    syncOfflineQueue();
                } else {
                    setNetworkStatus(false);
                }
            } catch (e) {
                setNetworkStatus(false);
            }
        }, 20000);

        // Terminal startup initialization for offline DB
        getDB().then(() => {
            updateQueueBadgeUI();
            updateRosterCacheCountUI();
            if (navigator.onLine) {
                setNetworkStatus(true);
                refreshOfflineRoster(false);
                syncOfflineQueue();
            } else {
                setNetworkStatus(false);
            }
        }).catch(err => {
            console.warn('IndexedDB initialization failed:', err);
        });

    })();
