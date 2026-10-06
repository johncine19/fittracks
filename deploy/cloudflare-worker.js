// ══════════════════════════════════════════════════════════════════
// FitTrack Cloudflare Worker — Zero-Subdomain Cold-Start Shield
// Keeps users on https://fitworks.tech without ever exposing Render
// ══════════════════════════════════════════════════════════════════

const BACKEND_HOST = 'fittracks-p3ue.onrender.com';
const BACKEND_ORIGIN = `https://${BACKEND_HOST}`;
const WAKE_TIMEOUT_MS = 2500; // If Render doesn't reply in 2.5s, it is sleeping -> show loading screen

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    // 1. Health-check endpoint: Always proxy directly to Render (no timeout cut-off)
    if (url.pathname === '/health.php') {
      const targetUrl = new URL(BACKEND_ORIGIN + '/health.php' + url.search);
      const newHeaders = new Headers(request.headers);
      newHeaders.set('Host', BACKEND_HOST);

      return fetch(new Request(targetUrl, {
        method: request.method,
        headers: newHeaders,
        redirect: 'manual'
      }));
    }

    // 2. Only intercept standard page navigations (HTML views)
    // Static assets (.css, .js, .png, etc.) or API POSTs pass directly through
    const isPageRequest = request.method === 'GET' && (
      url.pathname === '/' ||
      request.headers.get('accept')?.includes('text/html') ||
      !url.pathname.includes('.')
    );

    if (!isPageRequest) {
      const targetUrl = new URL(BACKEND_ORIGIN + url.pathname + url.search);
      const newHeaders = new Headers(request.headers);
      newHeaders.set('Host', BACKEND_HOST);

      return fetch(new Request(targetUrl, {
        method: request.method,
        headers: newHeaders,
        body: request.body,
        redirect: 'manual'
      }));
    }

    // 3. For page requests, probe Render with a short timeout
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), WAKE_TIMEOUT_MS);

    try {
      const targetUrl = new URL(BACKEND_ORIGIN + url.pathname + url.search);
      const newHeaders = new Headers(request.headers);
      newHeaders.set('Host', BACKEND_HOST);

      const response = await fetch(new Request(targetUrl, {
        method: request.method,
        headers: newHeaders,
        redirect: 'manual'
      }), {
        signal: controller.signal
      });

      clearTimeout(timer);

      // If Render returns 502/503/504 (cold-start intermediate state), show loading screen
      if ([502, 503, 504].includes(response.status)) {
        return new Response(LOADING_SCREEN_HTML, {
          status: 200,
          headers: { 'Content-Type': 'text/html; charset=utf-8' }
        });
      }

      // If Render is awake, return the live app!
      return response;

    } catch (err) {
      // Timeout triggered (> 2.5s) — Render is sleeping! Show the loading screen
      clearTimeout(timer);
      return new Response(LOADING_SCREEN_HTML, {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8' }
      });
    }
  }
};

// ══════════════════════════════════════════════════════════════════
// Inlined FitTrack Loading Screen (Same-Origin Polling)
// ══════════════════════════════════════════════════════════════════
const LOADING_SCREEN_HTML = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FitTrack — Loading</title>
    <meta name="description" content="FitTrack is your all-in-one fitness management platform.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #0a0a09;
            --bg-elev: #131211;
            --ink: #f3f1ea;
            --muted: #93897d;
            --lime: #27f246;
            --lime-deep: #27982f;
            --line: rgba(243, 241, 234, 0.1);
            --font-display: 'Bricolage Grotesque', Inter, ui-sans-serif, sans-serif;
            --font-mono: 'IBM Plex Mono', ui-monospace, 'SFMono-Regular', monospace;
        }
        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, sans-serif;
            overflow: hidden;
        }
        .bg-grid {
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 60px 60px;
            z-index: 0;
        }
        .bg-glow {
            position: fixed;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            filter: blur(150px);
            opacity: 0.15;
            z-index: 0;
            pointer-events: none;
        }
        .bg-glow-1 {
            top: -200px;
            left: -100px;
            background: var(--lime);
            animation: glow-drift-1 8s ease-in-out infinite alternate;
        }
        .bg-glow-2 {
            bottom: -250px;
            right: -150px;
            background: #27982f;
            animation: glow-drift-2 10s ease-in-out infinite alternate;
        }
        @keyframes glow-drift-1 {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(80px, 60px) scale(1.2); }
        }
        @keyframes glow-drift-2 {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(-60px, -80px) scale(1.15); }
        }
        .grain-overlay {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
            opacity: 0.04;
        }
        .loader-container {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            gap: 40px;
            padding: 24px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            animation: fade-in 0.8s ease both;
        }
        .brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--lime), var(--lime-deep));
            color: #0a0a09;
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 18px;
            letter-spacing: -0.5px;
            box-shadow: 0 0 30px rgba(39, 242, 70, 0.3);
        }
        .brand-name {
            font-family: var(--font-display);
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--ink);
        }
        .spinner-wrapper {
            position: relative;
            width: 100px;
            height: 100px;
            animation: fade-in 1s ease 0.3s both;
        }
        .spinner-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid var(--line);
            border-top-color: var(--lime);
            animation: spin 1s cubic-bezier(0.6, 0.15, 0.35, 0.85) infinite;
        }
        .spinner-ring-inner {
            position: absolute;
            inset: 10px;
            border-radius: 50%;
            border: 2px solid transparent;
            border-bottom-color: rgba(39, 242, 70, 0.4);
            animation: spin 1.5s cubic-bezier(0.6, 0.15, 0.35, 0.85) infinite reverse;
        }
        .spinner-dot {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 10px;
            height: 10px;
            margin: -5px 0 0 -5px;
            border-radius: 50%;
            background: var(--lime);
            box-shadow: 0 0 20px rgba(39, 242, 70, 0.6);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.3); opacity: 0.6; } }
        .status-area {
            text-align: center;
            animation: fade-in 1s ease 0.6s both;
        }
        .status-text {
            font-family: var(--font-mono);
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 12px;
            min-height: 20px;
        }
        .status-text .lime { color: var(--lime); }
        .progress-track {
            width: 260px;
            height: 3px;
            border-radius: 3px;
            background: var(--line);
            overflow: hidden;
            margin: 0 auto 16px;
        }
        .progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--lime-deep), var(--lime));
            box-shadow: 0 0 12px rgba(39, 242, 70, 0.4);
            transition: width 0.6s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        .status-steps {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--muted);
            letter-spacing: 0.05em;
        }
        .status-step { opacity: 0; transform: translateY(8px); transition: all 0.4s ease; }
        .status-step.visible { opacity: 1; transform: translateY(0); }
        .status-step.active { color: var(--lime); }
        .status-step.done { color: var(--muted); opacity: 0.6; }
        .status-step .check { display: inline-block; margin-right: 6px; }
        .tip {
            position: fixed;
            bottom: 32px;
            left: 0;
            right: 0;
            width: 100%;
            padding: 0 24px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--muted);
            opacity: 0;
            letter-spacing: 0.05em;
            animation: fade-in 1s ease 3s both;
            text-align: center;
        }
        @keyframes fade-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .loader-container.redirecting { animation: zoom-fade 0.6s ease forwards; }
        @keyframes zoom-fade { to { opacity: 0; transform: scale(1.05); filter: blur(8px); } }
        .error-msg { display: none; text-align: center; animation: fade-in 0.5s ease both; }
        .error-msg.visible { display: block; }
        .error-msg p { color: #ff9548; font-size: 14px; margin-bottom: 16px; }
        .error-msg a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--bg-elev);
            color: var(--ink);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>

    <div class="loader-container" id="loader">
        <div class="brand">
            <div class="brand-icon">FT</div>
            <div class="brand-name">FitTrack</div>
        </div>

        <div class="spinner-wrapper">
            <div class="spinner-ring"></div>
            <div class="spinner-ring-inner"></div>
            <div class="spinner-dot"></div>
        </div>

        <div class="status-area">
            <div class="status-text" id="status-text">Connecting to server<span class="dots">...</span></div>
            <div class="progress-track">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
            <div class="status-steps" id="status-steps">
                <div class="status-step" data-step="0"><span class="check">◦</span> Connecting to server</div>
                <div class="status-step" data-step="1"><span class="check">◦</span> Waking up services</div>
                <div class="status-step" data-step="2"><span class="check">◦</span> Loading application</div>
                <div class="status-step" data-step="3"><span class="check">◦</span> Preparing your experience</div>
            </div>
        </div>

        <div class="error-msg" id="error-msg">
            <p>The server is taking longer than expected.<br>This can happen after periods of inactivity.</p>
            <a href="#" id="retry-btn">↻ Try Again</a>
        </div>
    </div>

    <div class="tip">First visit may take up to 30 seconds — our server is warming up for you</div>

    <script>
    (() => {
        const MAX_ATTEMPTS   = 60;
        const PING_INTERVAL  = 2500;
        const REDIRECT_DELAY = 600;

        const loader      = document.getElementById('loader');
        const statusText  = document.getElementById('status-text');
        const progressBar = document.getElementById('progress-fill');
        const errorMsg    = document.getElementById('error-msg');
        const retryBtn    = document.getElementById('retry-btn');
        const steps       = document.querySelectorAll('.status-step');

        let attempts  = 0;
        let pingTimer = null;
        let currentStep = -1;

        const statusMessages = [
            'Connecting to server<span class="dots">...</span>',
            'Waking up services<span class="dots">...</span>',
            'Loading application<span class="dots">...</span>',
            'Almost there<span class="dots">...</span>',
        ];

        function setProgress(pct) {
            progressBar.style.width = Math.min(pct, 100) + '%';
        }

        function advanceStep(index) {
            if (index <= currentStep) return;
            currentStep = index;

            steps.forEach((step, i) => {
                if (i < index) {
                    step.classList.add('visible', 'done');
                    step.classList.remove('active');
                    step.querySelector('.check').textContent = '✓';
                } else if (i === index) {
                    step.classList.add('visible', 'active');
                    step.classList.remove('done');
                    step.querySelector('.check').textContent = '›';
                }
            });

            if (statusMessages[index]) {
                statusText.innerHTML = statusMessages[index];
            }
        }

        function completeAllSteps() {
            steps.forEach(step => {
                step.classList.add('visible', 'done');
                step.classList.remove('active');
                step.querySelector('.check').textContent = '✓';
            });
            statusText.innerHTML = '<span class="lime">Ready!</span> Launching...';
            setProgress(100);
        }

        function reloadApp() {
            loader.classList.add('redirecting');
            setTimeout(() => {
                // Same-origin reload — seamless, never changes domain!
                window.location.reload();
            }, REDIRECT_DELAY);
        }

        function showError() {
            errorMsg.classList.add('visible');
            statusText.innerHTML = 'Server is taking a while<span class="dots">...</span>';
        }

        async function pingServer() {
            attempts++;

            if (attempts === 1) advanceStep(0);
            if (attempts === 3) advanceStep(1);
            if (attempts === 6) advanceStep(2);
            if (attempts === 10) advanceStep(3);

            const rawPct = (attempts / MAX_ATTEMPTS) * 100;
            const visualPct = Math.min(90, 30 * Math.log(rawPct + 1));
            setProgress(visualPct);

            try {
                // Same-origin request: /health.php on https://fitworks.tech
                // No CORS, no Brave Shields tracking flag!
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), PING_INTERVAL - 300);

                const response = await fetch('/health.php', {
                    method: 'GET',
                    cache: 'no-store',
                    signal: controller.signal
                });
                clearTimeout(timeout);

                if (response.ok) {
                    const data = await response.json();
                    if (data && data.status === 'ok') {
                        completeAllSteps();
                        clearInterval(pingTimer);
                        setTimeout(reloadApp, REDIRECT_DELAY);
                        return;
                    }
                }
            } catch (err) {
                // Server still warming up...
            }

            if (attempts >= MAX_ATTEMPTS) {
                clearInterval(pingTimer);
                showError();
            }
        }

        function startPinging() {
            attempts = 0;
            currentStep = -1;
            errorMsg.classList.remove('visible');
            steps.forEach(s => {
                s.classList.remove('visible', 'active', 'done');
                s.querySelector('.check').textContent = '◦';
            });
            setProgress(0);
            statusText.innerHTML = 'Initializing<span class="dots">...</span>';

            pingServer();
            pingTimer = setInterval(pingServer, PING_INTERVAL);
        }

        retryBtn.addEventListener('click', (e) => {
            e.preventDefault();
            startPinging();
        });

        startPinging();
    })();
    </script>
</body>
</html>`;
