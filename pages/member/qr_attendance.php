<?php
declare(strict_types=1);

function qr_attendance_page(): void
{
    $user = require_roles(['member', 'trainer']);
    auto_checkout_past_attendance((int) $user['user_id']);
    $initialToken = null;
    $initialSecondsRemaining = 0;

    if (!empty($user['qr_token']) && !empty($user['qr_expires_at'])) {
        $expiresAt = new DateTimeImmutable((string) $user['qr_expires_at']);
        $initialSecondsRemaining = max(0, $expiresAt->getTimestamp() - time());
        if ($initialSecondsRemaining > 0) {
            $initialToken = (string) $user['qr_token'];
        }
    }
    
    // Handle AJAX actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'refresh_token') {
            $token = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable())->modify('+5 minutes');
            $expires = $expiresAt->format('Y-m-d H:i:s');
            db()->prepare('UPDATE users SET qr_token = ?, qr_expires_at = ? WHERE user_id = ?')->execute([$token, $expires, $user['user_id']]);
            header('Content-Type: application/json');
            echo json_encode([
                'token' => $token,
                'expires' => $expires,
                'seconds_remaining' => max(0, $expiresAt->getTimestamp() - time()),
            ]);
            exit;
        } elseif ($action === 'poll_status') {
            $tokenRaw = scalar('SELECT qr_token FROM users WHERE user_id = ?', [$user['user_id']]);
            // If token is null, it means the scanner just invalidated it
            if ($tokenRaw === null) {
                // Find latest attendance
                $row = db()->query('SELECT attendance_id, gym_id, check_in_time, check_out_time FROM attendance WHERE user_id = ' . (int)$user['user_id'] . ' ORDER BY attendance_id DESC LIMIT 1')->fetch();
                if ($row) {
                    $isCheckout = ($row['check_out_time'] !== null && strtotime($row['check_out_time']) >= strtotime($row['check_in_time']));
                    $gymId = (int)($row['gym_id'] ?? 0);
                    if (!$gymId) {
                        $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                    }
                    if (!$gymId && $user['role'] === 'trainer') {
                        $gymId = (int) scalar('SELECT gym_id FROM trainer_profiles WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                    }

                    $equipmentCats = [];
                    $equipmentList = [];
                    if ($gymId > 0) {
                        $equipmentCats = db()->query('SELECT category, COUNT(*) as cnt FROM gym_equipment WHERE gym_id = ' . $gymId . ' AND status = "available" GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
                        $equipmentList = db()->query('SELECT equipment_id, name, category, location_area FROM gym_equipment WHERE gym_id = ' . $gymId . ' AND status = "available" ORDER BY name ASC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
                    }

                    header('Content-Type: application/json');
                    echo json_encode([
                        'scanned' => true,
                        'type' => $isCheckout ? 'checkout' : 'checkin',
                        'attendance_id' => $row['attendance_id'],
                        'role' => $user['role'],
                        'gym_id' => $gymId,
                        'equipment_categories' => $equipmentCats,
                        'equipment_list' => $equipmentList
                    ]);
                    exit;
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['scanned' => false]);
            exit;
        } elseif ($action === 'submit_rating') {
            $attendanceId = (int) post('attendance_id');
            $rating = (int) post('rating');
            $comment = mb_substr(trim((string) post('comment')), 0, 1000) ?: null;
            if ($rating >= 1 && $rating <= 5) {
                try {
                    db()->prepare('INSERT INTO checkout_ratings (attendance_id, user_id, rating, comment) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)')
                       ->execute([$attendanceId, $user['user_id'], $rating, $comment]);
                } catch (Throwable) {}

                // Save to gym_ratings so QR checkout ratings contribute to the gym's overall score
                $gymId = (int) scalar('SELECT gym_id FROM attendance WHERE attendance_id = ?', [$attendanceId]);
                if (!$gymId) {
                    $gymId = (int) ($user['gym_id'] ?? 0);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT gym_id FROM gym_members WHERE user_id = ? LIMIT 1', [$user['user_id']]);
                }
                if (!$gymId) {
                    $gymId = (int) scalar('SELECT mp.gym_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND m.status = "active" LIMIT 1', [$user['user_id']]);
                }
                if ($gymId > 0) {
                    save_gym_rating((int)$user['user_id'], $gymId, $rating, $comment);
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    }

    render_header('My QR Code', $user);
    ?>
    <div class="skeleton-wrapper">
        <section class="panel" style="text-align: center; display:flex; flex-direction:column; align-items:center; padding: 40px 20px;">
            <div class="sk sk-title" style="width:200px;margin-bottom:12px"></div>
            <div class="sk sk-text" style="width:240px;height:12px;margin-bottom:40px"></div>
            
            <div class="sk-qr" style="margin-bottom:24px"></div>
            
            <div class="sk sk-text short" style="width:160px;margin-bottom:20px"></div>
            <div class="sk sk-rect" style="width:200px;height:44px;border-radius:6px"></div>
        </section>
    </div>
    <section class="panel skeleton-content sk-display-block" style="text-align: center;">
        <div class="page-header" style="justify-content: center;">
            <div>
                <h1>Dynamic QR Code</h1>
                <p>Scan this at the front desk to check in.</p>
            </div>
        </div>

        <!-- Initial prompt shown before any QR is generated -->
        <div id="qr-prompt" style="margin: 30px auto;">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h.01M18 14h.01M14 18h.01M18 18h.01M14 21h.01M21 14h.01M21 18h.01M21 21h.01"/></svg>
            <p class="muted" style="margin-bottom: 20px;">Your QR code is ready to be generated.<br>Click the button below when you arrive at the front desk.</p>
            <button type="button" class="btn btn-primary" onclick="generateAndShow()" id="generate-btn" style="font-size: 15px; padding: 12px 28px;">
                 Generate My QR Code
            </button>
        </div>

        <!-- QR display - hidden until generated or restored -->
        <div id="qr-display" style="display: none;">
            <div id="qr-container" style="margin: 20px auto; padding: 20px; background: white; display: inline-block; border-radius: 8px; position: relative;"></div>
            <p class="muted" id="qr-timer"></p>
            
            <div id="qr-manual-refresh" style="display: none; margin-top: 15px;">
                <button type="button" class="btn" style="background: var(--panel-soft); border: 1px solid var(--line); color: var(--muted); font-size: 13px; padding: 6px 16px;" onclick="manualRefresh()">
                    Regenerate (<span id="qr-refreshes-left">2</span> left)
                </button>
            </div>
            
            <div id="qr-expired" style="display: none; margin-top: 12px;">
                <p style="color: var(--danger); font-weight: 700; margin-bottom: 12px;">This QR code has expired</p>
                <button type="button" class="btn btn-primary" onclick="refreshQR()">Regenerate QR Code</button>
            </div>
        </div>
    </section>

    <!-- Load a browser-compatible QR code library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script>
    const userId = <?= json_encode((string) $user['user_id']) ?>;
    const initialToken = <?= json_encode($initialToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const initialSecondsRemaining = <?= (int) $initialSecondsRemaining ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    function generateQR(text) {
        const container = document.getElementById('qr-container');
        container.innerHTML = '';
        container.style.opacity = '1';

        const qr = qrcode(0, 'M'); // Error correction level M (15%) - produces cleaner, less dense QR codes
        qr.addData(text);
        qr.make();

        // Use built-in function to create a highly compatible img element (cellSize=8, margin=4)
        container.innerHTML = qr.createImgTag(8, 4);
        
        // Ensure the generated image is responsive
        const img = container.querySelector('img');
        if (img) {
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.display = 'block';
            img.style.margin = '0 auto';
        }
    }

    let manualRegeneratesLeft = 2;

    function showQR(token, secondsRemaining) {
        document.getElementById('qr-prompt').style.display = 'none';
        document.getElementById('qr-display').style.display = 'block';
        document.getElementById('qr-expired').style.display = 'none';
        document.getElementById('qr-timer').style.display = 'block';
        
        if (manualRegeneratesLeft > 0) {
            document.getElementById('qr-manual-refresh').style.display = 'block';
            document.getElementById('qr-refreshes-left').textContent = manualRegeneratesLeft;
        } else {
            document.getElementById('qr-manual-refresh').style.display = 'none';
        }

        generateQR(userId + ':' + token);
        startTimer(secondsRemaining);
    }

    function generateAndShow() {
        refreshQR();
    }

    function startTimer(secondsRemaining) {
        const timerEl = document.getElementById('qr-timer');
        const expiredEl = document.getElementById('qr-expired');
        let timeLeft = Math.max(0, Number(secondsRemaining) || 0);

        clearInterval(window.qrInterval);
        clearInterval(window.qrPollInterval);

        function renderTimer() {
            if (timeLeft <= 0) {
                clearInterval(window.qrInterval);
                clearInterval(window.qrPollInterval);
                document.getElementById('qr-container').style.opacity = '0.25';
                timerEl.style.display = 'none';
                document.getElementById('qr-manual-refresh').style.display = 'none';
                expiredEl.style.display = 'block';
                return;
            }

            const mins = Math.floor(timeLeft / 60);
            const secs = timeLeft % 60;
            timerEl.textContent = 'Expires in ' + mins + ':' + String(secs).padStart(2, '0') + '...';
        }

        renderTimer();
        window.qrInterval = setInterval(() => {
            timeLeft--;
            renderTimer();
        }, 1000);

        // Poll server to check if admin scanned the QR
        window.qrPollInterval = setInterval(pollScanStatus, 3000);
    }
    
    function pollScanStatus() {
        fetch('index.php?page=qr_attendance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=poll_status&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.scanned) {
                clearInterval(window.qrInterval);
                clearInterval(window.qrPollInterval);
                document.getElementById('qr-display').style.display = 'none';
                
                if (data.type === 'checkout') {
                    promptRating(data.attendance_id);
                } else {
                    promptEquipmentUsage(data);
                }
            }
        }).catch(() => {});
    }

    function promptEquipmentUsage(data) {
        const isTrainer = (data.role === 'trainer');
        const categories = data.equipment_categories || {};
        const catKeys = Object.keys(categories);

        let catChipsHtml = '';
        if (catKeys.length > 0) {
            catChipsHtml = '<div style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin:16px 0;">' +
                catKeys.map(cat => {
                    const count = categories[cat];
                    return `<a href="index.php?page=equipment&category=${encodeURIComponent(cat)}" style="background:rgba(199,255,34,0.1); border:1px solid rgba(199,255,34,0.3); color:var(--lime,#c7ff22); padding:7px 14px; border-radius:20px; font-size:12.5px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        <span>${cat}</span>
                        <span style="background:var(--lime,#c7ff22); color:#000; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700;">${count}</span>
                    </a>`;
                }).join('') +
            '</div>';
        } else {
            catChipsHtml = '<p style="color:var(--muted); font-size:13px; margin:14px 0;">All gym stations and free zones are ready for your session.</p>';
        }

        const titleText = isTrainer
            ? "Session Setup: What training equipment and circuit areas will you utilize for your clients today?"
            : "Checked In! What would you like to train or use today?";

        const descText = isTrainer
            ? "Select the training equipment and workout zones you plan to utilize with your clients during today's training."
            : "Explore available equipment in the gym right now or jump straight into your personalized workout routine.";

        const primaryBtnText = isTrainer ? "Browse Equipment Availability" : "View Gym Equipment";
        const primaryBtnUrl = "index.php?page=equipment";
        const secondaryBtnText = isTrainer ? "Client Training Plans" : "Start Today's Workout";
        const secondaryBtnUrl = isTrainer ? "index.php?page=training" : "index.php?page=my_workout";

        Swal.fire({
            title: titleText,
            html: `
                <p style="color:var(--muted); font-size:13.5px; line-height:1.5; margin-bottom:12px;">${descText}</p>
                ${catChipsHtml}
                <div style="display:flex; flex-direction:column; gap:10px; margin-top:20px;">
                    <a href="${primaryBtnUrl}" class="swal2-confirm swal2-styled" style="display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; padding:12px 20px; border-radius:8px; background:var(--lime,#c7ff22); color:#000; font-weight:700; font-size:14px; margin:0; box-shadow: 0 4px 14px rgba(199,255,34,0.25);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        ${primaryBtnText}
                    </a>
                    <a href="${secondaryBtnUrl}" class="swal2-cancel swal2-styled" style="display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; padding:12px 20px; border-radius:8px; background:var(--panel,#161a23); border:1px solid var(--line,#2a3142); color:var(--ink,#fff); font-weight:600; font-size:14px; margin:0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        ${secondaryBtnText}
                    </a>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Dismiss',
            background: 'var(--surface-color, #090b10)',
            color: 'var(--ink, #ffffff)'
        });
    }

    function promptRating(attendanceId) {
        let selectedRating = 0;
        const isLight = document.documentElement.getAttribute('data-theme') === 'light' || document.body.getAttribute('data-theme') === 'light';
        const emptyColor = isLight ? '#cbd5e1' : '#475569';
        const activeColor = '#f59e0b';

        let starHtml = '<div id="swal-star-row">'
            + [1,2,3,4,5].map(v => '<span class="co-star" data-val="' + v + '">★</span>').join('')
            + '</div>';

        Swal.fire({
            title: 'Rate Your Gym Experience',
            html: '<p class="checkout-modal-desc">How was your workout session? Leave a 1–5 star rating and optional review for your gym on check-out.</p>'
                + starHtml
                + '<textarea id="swal-comment" placeholder="Write an optional review (equipment, cleanliness, trainers, overall experience)..." rows="2"></textarea>',
            showCancelButton: true,
            showDenyButton: false,
            confirmButtonText: 'Submit Review',
            cancelButtonText: 'Skip',
            customClass: {
                popup: 'swal-checkout-modal',
                confirmButton: 'swal-checkout-confirm-btn',
                cancelButton: 'swal-skip-btn'
            },
            didOpen: () => {
                const stars = document.querySelectorAll('.co-star');
                const updateStars = (val) => {
                    stars.forEach((s, i) => {
                        const isFilled = i < val;
                        s.style.color = isFilled ? activeColor : emptyColor;
                        s.style.transform = isFilled ? 'scale(1.15)' : 'scale(1)';
                        s.style.textShadow = isFilled ? '0 0 12px rgba(245, 158, 11, 0.45)' : 'none';
                    });
                };
                stars.forEach(star => {
                    star.addEventListener('mouseover', () => {
                        let val = parseInt(star.dataset.val, 10);
                        updateStars(val);
                    });
                    star.addEventListener('mouseout', () => {
                        updateStars(selectedRating);
                    });
                    star.addEventListener('click', () => {
                        selectedRating = parseInt(star.dataset.val, 10);
                        updateStars(selectedRating);
                    });
                });
            },
            preConfirm: () => {
                if (selectedRating > 0) {
                    let comment = document.getElementById('swal-comment').value || '';
                    return fetch('index.php?page=qr_attendance', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=submit_rating&attendance_id=${attendanceId}&rating=${selectedRating}&comment=${encodeURIComponent(comment)}&csrf_token=${encodeURIComponent(csrfToken)}`
                    });
                }
            }
        }).then(() => {
            Swal.fire({
                icon: 'success',
                title: selectedRating > 0 ? 'Review Submitted & Checked Out!' : 'Checked Out!',
                text: selectedRating > 0 ? 'Thank you for rating your gym. See you next time!' : 'See you next time.',
                background: 'var(--surface-color, #090b10)',
                color: 'var(--ink, #ffffff)',
                confirmButtonColor: 'var(--lime, #c7ff22)'
            });
        });
    }
    
    function manualRefresh() {
        if (manualRegeneratesLeft <= 0) return;
        manualRegeneratesLeft--;
        refreshQR();
    }

    function refreshQR() {
        const timerEl = document.getElementById('qr-timer');
        const expiredEl = document.getElementById('qr-expired');
        document.getElementById('qr-prompt').style.display = 'none';
        document.getElementById('qr-display').style.display = 'block';
        timerEl.style.display = 'block';
        timerEl.textContent = 'Generating...';
        expiredEl.style.display = 'none';

        fetch('index.php?page=qr_attendance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=refresh_token&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            showQR(data.token, data.seconds_remaining || 300);
        })
        .catch(err => console.error('QR refresh error:', err));
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (initialToken && initialSecondsRemaining > 0) {
            showQR(initialToken, initialSecondsRemaining);
        }
    });
    </script>
    <?php
    render_footer();
}
