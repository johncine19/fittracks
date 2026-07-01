<?php
declare(strict_types=1);

function qr_attendance_page(): void
{
    $user = require_roles(['member']);
    $initialToken = null;
    $initialSecondsRemaining = 0;

    if (!empty($user['qr_token']) && !empty($user['qr_expires_at'])) {
        $expiresAt = new DateTimeImmutable((string) $user['qr_expires_at']);
        $initialSecondsRemaining = max(0, $expiresAt->getTimestamp() - time());
        if ($initialSecondsRemaining > 0) {
            $initialToken = (string) $user['qr_token'];
        }
    }
    
    // Check for AJAX request to refresh token
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'refresh_token') {
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
    }

    render_header('My QR Code', $user);
    ?>
    <section class="panel" style="text-align: center;">
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

    function showQR(token, secondsRemaining) {
        document.getElementById('qr-prompt').style.display = 'none';
        document.getElementById('qr-display').style.display = 'block';
        document.getElementById('qr-expired').style.display = 'none';
        document.getElementById('qr-timer').style.display = 'block';

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

        function renderTimer() {
            if (timeLeft <= 0) {
                clearInterval(window.qrInterval);
                document.getElementById('qr-container').style.opacity = '0.25';
                timerEl.style.display = 'none';
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
