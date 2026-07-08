<?php
declare(strict_types=1);

function scanner_page(): void
{
    $user = require_roles(['admin']);
    
    // Handle AJAX check-in request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'process_qr') {
        header('Content-Type: application/json');
        
        $qrData = post('qr_data');
        if (!$qrData || !str_contains((string) $qrData, ':')) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR Code format.']);
            exit;
        }
        
        list($userId, $token) = explode(':', (string) $qrData, 2);
        
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id, first_name, last_name, role, qr_token, qr_expires_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $member = $stmt->fetch();
        
        if (!$member || $member['qr_token'] !== $token) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired QR token.']);
            exit;
        }
        
        if (new DateTime() > new DateTime($member['qr_expires_at'])) {
            echo json_encode(['success' => false, 'message' => 'QR token has expired.']);
            exit;
        }
        
        // Check for open attendance record
        $stmt = $pdo->prepare('SELECT attendance_id FROM attendance WHERE user_id = ? AND check_out_time IS NULL ORDER BY check_in_time DESC LIMIT 1');
        $stmt->execute([$userId]);
        $openRecord = $stmt->fetch();

        if ($openRecord) {
            // Check out
            $pdo->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')->execute([$openRecord['attendance_id']]);
            $message = 'Check-out successful for ' . $member['first_name'] . ' ' . $member['last_name'];
        } else {
            // Check in
            if ($member['role'] !== 'trainer' && $member['role'] !== 'admin') {
                $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status = "active" AND end_date >= CURDATE()', [$userId]);
                if (!$hasMembership && !isset($_POST['amount_paid'])) {
                    echo json_encode(['success' => false, 'requires_payment' => true, 'qr_data' => $qrData, 'message' => 'No active membership. Please collect payment for this session.']);
                    exit;
                }
            }
            
            // Check if member has a booked class starting soon (within +/- 1 hour)
            $classBooking = $pdo->prepare('
                SELECT b.schedule_id 
                FROM class_bookings b
                JOIN class_schedules s ON b.schedule_id = s.schedule_id
                WHERE b.user_id = ? AND b.booking_status = "booked"
                  AND s.start_datetime >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND s.start_datetime <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
                ORDER BY s.start_datetime ASC LIMIT 1
            ');
            $classBooking->execute([$userId]);
            $bookedClass = $classBooking->fetch();
            $scheduleId = $bookedClass ? $bookedClass['schedule_id'] : null;

            $stmt = $pdo->prepare('INSERT INTO attendance (user_id, schedule_id, check_in_time, check_in_method, recorded_by) VALUES (?, ?, NOW(), "qr_code", ?)');
            $stmt->execute([$userId, $scheduleId, $user['user_id']]);
            
            if ($scheduleId) {
                $pdo->prepare('UPDATE class_bookings SET booking_status = "attended" WHERE user_id = ? AND schedule_id = ?')->execute([$userId, $scheduleId]);
                $message = 'Check-in successful & Class Auto-Attended for ' . $member['first_name'] . ' ' . $member['last_name'];
            } else {
                $message = 'Check-in successful for ' . $member['first_name'] . ' ' . $member['last_name'];
            }
            
            $amount = (float) (post('amount_paid') ?: 0);
            if ($amount > 0) {
                $guestName = $member['first_name'] . ' ' . $member['last_name'];
                $contactInfo = scalar('SELECT phone FROM users WHERE user_id = ?', [$userId]) ?: 'N/A';
                $pdo->prepare('INSERT INTO walk_in_transactions (guest_name, contact_info, amount_paid, payment_method, visit_date, processed_by, converted_to_member_id) VALUES (?, ?, ?, ?, NOW(), ?, ?)')
                    ->execute([$guestName, $contactInfo, $amount, post('payment_method') ?: 'cash', $user['user_id'], $userId]);
            }
        }
        
        // Invalidate token after single use
        $pdo->prepare('UPDATE users SET qr_token = NULL, qr_expires_at = NULL WHERE user_id = ?')->execute([$userId]);
        
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }

    render_header('QR Scanner', $user);
    ?>
    <section class="panel" style="text-align: center;">
        <div class="page-header" style="justify-content: center;">
            <div>
                <h1>QR Scanner</h1>
                <p>Scan member's dynamic QR code to log attendance.</p>
            </div>
        </div>
        
        <div style="max-width: 500px; margin: 0 auto;">
            <div id="reader" style="width: 100%;"></div>
            <p id="scan-result" style="margin-top: 20px; font-weight: bold;"></p>
        </div>
    </section>

    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script>
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    document.addEventListener('DOMContentLoaded', function() {
        let isProcessing = false;
        const scannedTokens = new Set();
        
        function onScanSuccess(decodedText, decodedResult) {
            if (isProcessing) return;
            
            // Prevent duplicate scans of the exact same QR code in one session
            // since the token is single-use and will throw an error the second time.
            if (scannedTokens.has(decodedText)) return;
            
            isProcessing = true;
            scannedTokens.add(decodedText);
            
            const resultEl = document.getElementById('scan-result');
            resultEl.textContent = 'Processing...';
            resultEl.style.color = 'var(--muted)';
            
            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process_qr&qr_data=' + encodeURIComponent(decodedText) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(r => r.json())
            .then(data => {
                if (data.requires_payment) {
                    Swal.fire({
                        title: 'Payment Required',
                        html: `
                            <p style="margin-bottom: 15px; color: var(--danger); font-weight: bold;">${data.message}</p>
                            <input type="number" id="swal-amount" class="swal2-input" placeholder="Amount Paid (PHP)" step="0.01" min="0" style="background: color-mix(in srgb, var(--ink) 5%, transparent); color: var(--ink); border: 1px solid color-mix(in srgb, var(--ink) 10%, transparent);">
                            <select id="swal-method" class="swal2-select" style="display: flex; margin: 15px auto; background: color-mix(in srgb, var(--ink) 5%, transparent); color: var(--ink); border: 1px solid color-mix(in srgb, var(--ink) 10%, transparent);">
                                <option style="background:var(--bg);color:var(--ink);" value="cash">Cash</option>
                                <option style="background:var(--bg);color:var(--ink);" value="gcash">GCash</option>
                            </select>
                        `,
                        background: 'var(--bg)',
                        color: 'var(--ink)',
                        confirmButtonColor: 'var(--lime)',
                        cancelButtonColor: 'color-mix(in srgb, var(--ink) 10%, transparent)',
                        showCancelButton: true,
                        confirmButtonText: '<span style="color:var(--bg); font-weight:600;">Record Payment & Check-in</span>',
                        cancelButtonText: '<span style="color:var(--ink);">Cancel</span>',
                        preConfirm: () => {
                            const amt = document.getElementById('swal-amount').value;
                            const method = document.getElementById('swal-method').value;
                            return { amount: amt || 0, method: method };
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch('index.php?page=scanner', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=process_qr&qr_data=' + encodeURIComponent(decodedText) + '&amount_paid=' + encodeURIComponent(result.value.amount) + '&payment_method=' + encodeURIComponent(result.value.method) + '&csrf_token=' + encodeURIComponent(csrfToken)
                            })
                            .then(r => r.json())
                            .then(data2 => {
                                if (data2.success) {
                                    resultEl.textContent = '✅ ' + data2.message;
                                    resultEl.style.color = 'var(--success)';
                                } else {
                                    resultEl.textContent = '❌ ' + data2.message;
                                    resultEl.style.color = 'var(--danger)';
                                }
                                setTimeout(() => { isProcessing = false; }, 3000);
                            });
                        } else {
                            isProcessing = false;
                            scannedTokens.delete(decodedText);
                            resultEl.textContent = 'Check-in cancelled.';
                        }
                    });
                    return;
                }

                if (data.success) {
                    resultEl.textContent = '✅ ' + data.message;
                    resultEl.style.color = 'var(--success)';
                } else {
                    resultEl.textContent = '❌ ' + data.message;
                    resultEl.style.color = 'var(--danger)';
                }
                
                setTimeout(() => {
                    isProcessing = false;
                }, 3000);
            })
            .catch(err => {
                console.error(err);
                resultEl.textContent = 'Error processing request.';
                isProcessing = false;
                scannedTokens.delete(decodedText); // allow retry on network error
            });
        }

        function onScanFailure(error) {
            // handle scan failure, usually better to ignore and keep scanning
        }

        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            { 
                fps: 10,
                useBarCodeDetectorIfSupported: true,
                rememberLastUsedCamera: true
            },
            /* verbose= */ false);
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    });
    </script>
    <?php
    render_footer();
}
