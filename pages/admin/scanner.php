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
        $stmt = $pdo->prepare('SELECT user_id, first_name, last_name, qr_token, qr_expires_at FROM users WHERE user_id = ?');
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
        
        // Log attendance
        $stmt = $pdo->prepare('INSERT INTO attendance (user_id, check_in_time, check_in_method, recorded_by) VALUES (?, NOW(), "qr_code", ?)');
        $stmt->execute([$userId, $user['user_id']]);
        
        // Invalidate token after single use
        $pdo->prepare('UPDATE users SET qr_token = NULL, qr_expires_at = NULL WHERE user_id = ?')->execute([$userId]);
        
        echo json_encode(['success' => true, 'message' => 'Check-in successful for ' . $member['first_name'] . ' ' . $member['last_name']]);
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
    document.addEventListener('DOMContentLoaded', function() {
        let isProcessing = false;
        
        function onScanSuccess(decodedText, decodedResult) {
            if (isProcessing) return;
            isProcessing = true;
            
            const resultEl = document.getElementById('scan-result');
            resultEl.textContent = 'Processing...';
            resultEl.style.color = 'var(--muted)';
            
            fetch('index.php?page=scanner', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process_qr&qr_data=' + encodeURIComponent(decodedText)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    resultEl.textContent = '✅ ' + data.message;
                    resultEl.style.color = 'var(--success)';
                } else {
                    resultEl.textContent = '❌ ' + data.message;
                    resultEl.style.color = 'var(--danger)';
                }
                
                setTimeout(() => {
                    isProcessing = false;
                    resultEl.textContent = '';
                }, 3000);
            })
            .catch(err => {
                console.error(err);
                resultEl.textContent = 'Error processing request.';
                isProcessing = false;
            });
        }

        function onScanFailure(error) {
            // handle scan failure, usually better to ignore and keep scanning
        }

        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            { fps: 10, qrbox: {width: 250, height: 250} },
            /* verbose= */ false);
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    });
    </script>
    <?php
    render_footer();
}
