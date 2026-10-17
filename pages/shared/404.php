<?php
declare(strict_types=1);

/**
 * 404 Not Found Page
 * Full-screen clean white presentation matching reference modern illustration design.
 * Highly tuned for responsive mobile & tablet viewports.
 */
function not_found_page(): void
{
    http_response_code(404);
    $user = current_user();

    // Set AUTH_PAGE to disable sidebar frames and give clean root canvas
    if (!defined('AUTH_PAGE')) {
        define('AUTH_PAGE', true);
    }

    $pageParam = trim((string)($_GET['page'] ?? ''));
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $cleanPath = $requestUri !== '' ? parse_url($requestUri, PHP_URL_PATH) : '';
    $displayPath = $pageParam !== '' && $pageParam !== '404' 
        ? '?page=' . htmlspecialchars($pageParam, ENT_QUOTES, 'UTF-8') 
        : htmlspecialchars((string)$cleanPath, ENT_QUOTES, 'UTF-8');

    render_header('404 Page Not Found', $user);
?>
<style>
    /* ============================================================
       Full Screen Seamless White Canvas (Zero black margins)
       ============================================================ */
    html,
    body {
        background: #ffffff !important;
        background-color: #ffffff !important;
        color: #1e293b !important;
        margin: 0 !important;
        padding: 0 !important;
        min-height: 100vh !important;
        min-height: 100dvh !important;
        width: 100% !important;
        overflow-x: hidden;
    }

    /* Suppress dark backgrounds, noise filters, or app-level overlays */
    body::before,
    body::after,
    .app-frame::before,
    .app-frame::after,
    #ft-splash,
    svg:has(#grain-filter),
    svg filter#grain-filter {
        display: none !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    /* Stretch core container to 100% viewport height and width */
    .shell,
    .auth-shell,
    .main-content,
    .app-frame,
    .main-area {
        width: 100% !important;
        max-width: 100% !important;
        min-height: 100vh !important;
        min-height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        background-color: #ffffff !important;
        border: none !important;
        box-shadow: none !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
    }

    /* Main Fullscreen Flex Center Container */
    .ft-404-fullscreen-wrap {
        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: clamp(20px, 3.5vw, 40px);
        box-sizing: border-box;
        background: #ffffff;
        position: relative;
    }

    /* Brand Watermark / Home Link */
    .ft-404-brand {
        position: absolute;
        top: clamp(20px, 3vw, 36px);
        left: clamp(20px, 4vw, 48px);
        z-index: 10;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ft-404-brand a {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: #1e3a8a;
        font-weight: 800;
        font-size: 19px;
        letter-spacing: -0.5px;
        transition: opacity 0.2s ease;
    }

    .ft-404-brand a:hover {
        opacity: 0.8;
    }

    /* 2-Column Split Canvas */
    .ft-404-canvas {
        width: 100%;
        max-width: 1160px;
        margin: auto;
        padding: 16px 0;
        box-sizing: border-box;
        background: transparent;
        border: none;
        box-shadow: none;
        display: grid;
        grid-template-columns: 1fr minmax(320px, 520px);
        align-items: center;
        gap: clamp(28px, 5vw, 68px);
        position: relative;
    }

    /* Left Content Column */
    .ft-404-left {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        z-index: 2;
    }

    /* Stylized Layered / 3D 404 Typography */
    .ft-404-number-wrap {
        position: relative;
        display: inline-block;
        margin-bottom: 6px;
        user-select: none;
    }

    .ft-404-number-svg {
        display: block;
        width: clamp(200px, 28vw, 310px);
        height: auto;
    }

    /* Subtitle Heading */
    .ft-404-heading {
        font-family: Inter, system-ui, -apple-system, sans-serif;
        font-size: clamp(1.75rem, 3.2vw, 2.65rem);
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #1e3a8a;
        margin: 0 0 14px;
        line-height: 1.15;
    }

    .ft-404-desc {
        color: #64748b;
        font-size: clamp(14.5px, 1.35vw, 16px);
        line-height: 1.6;
        margin: 0 0 24px;
        max-width: 440px;
    }

    /* Primary Pill Button */
    .ft-404-pill-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 13px 38px;
        border: 2.5px solid #2563eb;
        border-radius: 999px;
        background: transparent;
        color: #2563eb;
        font-family: Inter, system-ui, sans-serif;
        font-size: 14.5px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.12);
        box-sizing: border-box;
    }

    .ft-404-pill-btn:hover {
        background: #2563eb;
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.3);
        text-decoration: none;
    }

    /* Actions Group & Sub-links */
    .ft-404-actions-group {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 22px;
        width: 100%;
    }

    .ft-404-sub-actions {
        display: flex;
        align-items: center;
        gap: 14px;
        font-size: 13.5px;
        color: #64748b;
    }

    .ft-404-link-btn {
        color: #64748b;
        font-weight: 600;
        text-decoration: none;
        padding: 4px 6px;
        border-radius: 6px;
        transition: color 0.2s;
    }

    .ft-404-link-btn:hover {
        color: #1e3a8a;
        text-decoration: underline;
    }

    .ft-404-divider {
        color: #cbd5e1;
        font-size: 12px;
    }

    /* Diagnostic Block */
    .ft-404-diagnostic {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 14px;
        font-family: var(--font-mono, monospace);
        font-size: 11.5px;
        color: #64748b;
        width: 100%;
        max-width: 440px;
        box-sizing: border-box;
        margin-bottom: 20px;
    }

    /* Role Quick Links */
    .ft-404-quicklinks {
        border-top: 1px solid #e2e8f0;
        padding-top: 14px;
        width: 100%;
        max-width: 440px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        font-size: 13px;
        color: #94a3b8;
    }

    .ft-404-quicklinks a {
        color: #2563eb;
        text-decoration: none;
        font-weight: 600;
        transition: opacity 0.2s;
    }

    .ft-404-quicklinks a:hover {
        opacity: 0.8;
        text-decoration: underline;
    }

    /* Right Illustration Column */
    .ft-404-right {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        position: relative;
    }

    .ft-404-illustration-svg {
        width: 100%;
        max-width: 500px;
        height: auto;
        display: block;
    }

    /* Micro-Animations for the vector scene */
    .ft-gear-spin-cw {
        transform-origin: 485px 125px;
        animation: ftSpinCw 22s linear infinite;
    }

    .ft-gear-spin-ccw {
        transform-origin: 410px 88px;
        animation: ftSpinCcw 16s linear infinite;
    }

    .ft-gear-spin-small {
        transform-origin: 300px 150px;
        animation: ftSpinCw 12s linear infinite;
    }

    .ft-steam-float {
        animation: ftSteamWave 3.5s ease-in-out infinite;
    }

    .ft-error-dialog-1 {
        animation: ftDialogPulse1 4s ease-in-out infinite;
    }

    .ft-error-dialog-2 {
        animation: ftDialogPulse2 4s ease-in-out infinite 0.7s;
    }

    @keyframes ftSpinCw {
        100% { transform: rotate(360deg); }
    }

    @keyframes ftSpinCcw {
        100% { transform: rotate(-360deg); }
    }

    @keyframes ftSteamWave {
        0%, 100% {
            opacity: 0.85;
            transform: translateY(0px) scale(1);
        }
        50% {
            opacity: 0.35;
            transform: translateY(-6px) scale(1.05);
        }
    }

    @keyframes ftDialogPulse1 {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-4px); }
    }

    @keyframes ftDialogPulse2 {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-3px); }
    }

    /* ============================================================
       Responsive Tweaks: Tablets (< 900px)
       ============================================================ */
    @media (max-width: 900px) {
        .ft-404-fullscreen-wrap {
            padding: 24px 20px 32px;
            justify-content: flex-start;
        }

        .ft-404-brand {
            position: relative;
            top: auto;
            left: auto;
            margin-bottom: 16px;
            align-self: center;
        }

        .ft-404-canvas {
            grid-template-columns: 1fr;
            text-align: center;
            justify-items: center;
            padding: 8px 0 24px;
            gap: 28px;
        }

        .ft-404-left {
            align-items: center;
            text-align: center;
            order: 2;
        }

        .ft-404-right {
            order: 1;
        }

        .ft-404-actions-group {
            align-items: center;
        }

        .ft-404-sub-actions {
            justify-content: center;
        }

        .ft-404-quicklinks {
            justify-content: center;
        }

        .ft-404-diagnostic {
            text-align: left;
        }

        .ft-404-illustration-svg {
            max-width: 320px;
        }
    }

    /* ============================================================
       Responsive Tweaks: Mobile Phones (< 600px)
       ============================================================ */
    @media (max-width: 600px) {
        .ft-404-fullscreen-wrap {
            padding: 16px 16px 28px;
            min-height: 100dvh;
            justify-content: center;
        }

        .ft-404-brand {
            margin-bottom: 8px;
        }

        .ft-404-brand a span {
            font-size: 18px;
        }

        .ft-404-canvas {
            gap: 16px;
            padding: 4px 0 16px;
        }

        /* Scaled illustration so it fits perfectly on phone screen */
        .ft-404-illustration-svg {
            max-width: 250px;
            margin: 0 auto;
            display: block;
        }

        /* Scaled 3D 404 number */
        .ft-404-number-svg {
            width: 190px;
        }

        .ft-404-heading {
            font-size: 1.55rem;
            letter-spacing: 0.04em;
            margin: 0 0 10px;
        }

        .ft-404-desc {
            font-size: 13.5px;
            line-height: 1.5;
            margin: 0 0 16px;
            max-width: 310px;
            padding: 0 4px;
        }

        /* Compact Diagnostic Block on mobile */
        .ft-404-diagnostic {
            font-size: 10.5px;
            padding: 8px 12px;
            max-width: 310px;
            margin-bottom: 16px;
            border-radius: 8px;
        }

        /* Mobile Action Buttons: full-width hero pill + clean sub-links */
        .ft-404-actions-group {
            margin-bottom: 18px;
            gap: 10px;
        }

        .ft-404-pill-btn {
            width: 100%;
            max-width: 270px;
            padding: 12px 20px;
            font-size: 13.5px;
            letter-spacing: 0.06em;
        }

        .ft-404-sub-actions {
            font-size: 13px;
            gap: 12px;
        }

        /* Compact quicklinks */
        .ft-404-quicklinks {
            font-size: 12px;
            gap: 8px;
            max-width: 310px;
            padding-top: 12px;
        }
    }
</style>

<div class="ft-404-fullscreen-wrap">
    <!-- Main Responsive Canvas -->
    <div class="ft-404-canvas">
        <!-- ================= LEFT / BOTTOM ON MOBILE: CONTENT ================= -->
        <div class="ft-404-left">
            <!-- 3D Layered 404 Typography -->
            <div class="ft-404-number-wrap">
                <svg class="ft-404-number-svg" viewBox="0 0 340 160" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="404">
                    <defs>
                        <linearGradient id="numShadowGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#3b82f6" />
                            <stop offset="100%" stop-color="#1d4ed8" />
                        </linearGradient>
                        <linearGradient id="numFrontGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#1e3a8a" />
                            <stop offset="100%" stop-color="#0f172a" />
                        </linearGradient>
                    </defs>

                    <!-- === DIGIT 1: "4" === -->
                    <g fill="url(#numShadowGrad)" opacity="0.65" transform="translate(14, 14)">
                        <path d="M68 15 L22 92 L68 92 L68 125 L92 125 L92 92 L106 92 L106 72 L92 72 L92 15 Z M68 45 L68 72 L42 72 Z" />
                        <line x1="20" y1="92" x2="110" y2="92" stroke="#ffffff" stroke-width="2.5" opacity="0.6" />
                        <line x1="68" y1="40" x2="92" y2="40" stroke="#ffffff" stroke-width="2" opacity="0.6" />
                    </g>
                    <g fill="url(#numFrontGrad)">
                        <path d="M68 15 L22 92 L68 92 L68 125 L92 125 L92 92 L106 92 L106 72 L92 72 L92 15 Z M68 45 L68 72 L42 72 Z" />
                        <line x1="18" y1="90" x2="108" y2="90" stroke="#3b82f6" stroke-width="2.5" />
                        <line x1="68" y1="40" x2="94" y2="40" stroke="#3b82f6" stroke-width="2" />
                    </g>

                    <!-- === DIGIT 2: "0" === -->
                    <g fill="url(#numShadowGrad)" opacity="0.65" transform="translate(14, 14)">
                        <path d="M165 15 C135 15 125 38 125 70 C125 102 135 125 165 125 C195 125 205 102 205 70 C205 38 195 15 165 15 Z M165 42 C176 42 180 54 180 70 C180 86 176 98 165 98 C154 98 150 86 150 70 C150 54 154 42 165 42 Z" />
                        <line x1="125" y1="70" x2="205" y2="70" stroke="#ffffff" stroke-width="2.5" opacity="0.6" />
                        <line x1="128" y1="52" x2="202" y2="52" stroke="#ffffff" stroke-width="2" opacity="0.5" />
                    </g>
                    <g fill="url(#numFrontGrad)">
                        <path d="M165 15 C135 15 125 38 125 70 C125 102 135 125 165 125 C195 125 205 102 205 70 C205 38 195 15 165 15 Z M165 42 C176 42 180 54 180 70 C180 86 176 98 165 98 C154 98 150 86 150 70 C150 54 154 42 165 42 Z" />
                        <line x1="125" y1="68" x2="205" y2="68" stroke="#3b82f6" stroke-width="2.5" />
                        <line x1="128" y1="50" x2="202" y2="50" stroke="#3b82f6" stroke-width="2" />
                    </g>

                    <!-- === DIGIT 3: "4" === -->
                    <g fill="url(#numShadowGrad)" opacity="0.65" transform="translate(14, 14)">
                        <path d="M268 15 L222 92 L268 92 L268 125 L292 125 L292 92 L306 92 L306 72 L292 72 L292 15 Z M268 45 L268 72 L242 72 Z" />
                        <line x1="220" y1="92" x2="310" y2="92" stroke="#ffffff" stroke-width="2.5" opacity="0.6" />
                        <line x1="268" y1="40" x2="292" y2="40" stroke="#ffffff" stroke-width="2" opacity="0.6" />
                    </g>
                    <g fill="url(#numFrontGrad)">
                        <path d="M268 15 L222 92 L268 92 L268 125 L292 125 L292 92 L306 92 L306 72 L292 72 L292 15 Z M268 45 L268 72 L242 72 Z" />
                        <line x1="218" y1="90" x2="308" y2="90" stroke="#3b82f6" stroke-width="2.5" />
                        <line x1="268" y1="40" x2="294" y2="40" stroke="#3b82f6" stroke-width="2" />
                    </g>
                </svg>
            </div>

            <!-- Heading -->
            <h1 class="ft-404-heading">PAGE NOT FOUND</h1>

            <!-- Subtitle -->
            <p class="ft-404-desc">
                The page you're looking for doesn't exist, has been moved, or may be temporarily unavailable.
            </p>

            <!-- Diagnostic info -->
            <?php if ($displayPath !== '' && $displayPath !== '/'): ?>
            <div class="ft-404-diagnostic">
                <div style="display: flex; gap: 8px; margin-bottom: 2px;">
                    <strong style="color: #ef4444;">ROUTE:</strong>
                    <span style="word-break: break-all;"><?= $displayPath ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 10.5px; opacity: 0.8;">
                    <span>CODE: 404 (Not Found)</span>
                    <span><?= date('H:i:s T') ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Buttons Group (Clean mobile stacked arrangement) -->
            <div class="ft-404-actions-group">
                <?php if ($user): ?>
                    <a href="index.php?page=dashboard" class="ft-404-pill-btn">
                        GO TO DASHBOARD
                    </a>
                <?php else: ?>
                    <a href="index.php?page=landing" class="ft-404-pill-btn">
                        GO TO HOME
                    </a>
                <?php endif; ?>

                <div class="ft-404-sub-actions">
                    <?php if (!$user): ?>
                        <a href="index.php?page=login" class="ft-404-link-btn">
                            Sign In
                        </a>
                        <span class="ft-404-divider">&bull;</span>
                    <?php endif; ?>
                    <a href="javascript:history.length > 1 ? history.back() : window.location.href='index.php'" class="ft-404-link-btn">
                        &larr; Previous Page
                    </a>
                </div>
            </div>

            <!-- Quick Jump Links based on user session -->
            <?php if ($user): ?>
                <div class="ft-404-quicklinks">
                    <span>Quick jumps:</span>
                    <?php if (($user['role'] ?? '') === 'member'): ?>
                        <a href="index.php?page=my_workout">Workouts</a> &bull;
                        <a href="index.php?page=diet">Nutrition</a> &bull;
                        <a href="index.php?page=book_classes">Classes</a> &bull;
                        <a href="index.php?page=profile">Profile</a>
                    <?php elseif (in_array($user['role'] ?? '', ['gym_owner', 'admin'], true)): ?>
                        <a href="index.php?page=users">Users</a> &bull;
                        <a href="index.php?page=attendance">Attendance</a> &bull;
                        <a href="index.php?page=plans">Plans</a> &bull;
                        <a href="index.php?page=reports">Reports</a>
                    <?php elseif (($user['role'] ?? '') === 'trainer'): ?>
                        <a href="index.php?page=trainer_members">Clients</a> &bull;
                        <a href="index.php?page=training">Workouts</a> &bull;
                        <a href="index.php?page=classes">My Classes</a>
                    <?php else: ?>
                        <a href="index.php?page=dashboard">Dashboard</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ft-404-quicklinks">
                    <span>Need support? Check our <a href="index.php?page=terms">Terms</a> or <a href="index.php?page=privacy">Privacy Policy</a>.</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================= RIGHT / TOP ON MOBILE: VECTOR ILLUSTRATION ================= -->
        <div class="ft-404-right">
            <svg class="ft-404-illustration-svg" viewBox="165 30 385 335" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Error monitor and mechanical gears vector art">
                <defs>
                    <linearGradient id="blobGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#bfdbfe" stop-opacity="0.95" />
                        <stop offset="100%" stop-color="#93c5fd" stop-opacity="0.6" />
                    </linearGradient>

                    <linearGradient id="bezelGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#1e293b" />
                        <stop offset="100%" stop-color="#0f172a" />
                    </linearGradient>

                    <linearGradient id="errorModalGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#dbeafe" />
                        <stop offset="100%" stop-color="#bfdbfe" />
                    </linearGradient>

                    <filter id="softCardShadow" x="-10%" y="-10%" width="120%" height="120%">
                        <feDropShadow dx="0" dy="4" stdDeviation="4" flood-color="#0f172a" flood-opacity="0.08" />
                    </filter>
                </defs>

                <!-- 1. Organic Fluid Backdrop Blob -->
                <path d="M220,185 C175,100 240,45 340,55 C440,65 525,105 520,205 C515,305 445,355 350,360 C250,365 200,310 185,255 C175,210 250,240 220,185 Z" fill="url(#blobGrad)" />

                <!-- 2. Trajectory Dashed Curve with Connection Dots -->
                <path d="M370,110 C410,75 450,85 480,115" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-dasharray="4 5" fill="none" />
                <circle cx="370" cy="110" r="3" fill="#3b82f6" />
                <circle cx="480" cy="115" r="3" fill="#3b82f6" />

                <!-- 3. Rotating Mechanical Gear Cogs -->
                <g class="ft-gear-spin-cw">
                    <g fill="#1e293b">
                        <circle cx="485" cy="125" r="42" />
                        <rect x="477" y="73" width="16" height="15" rx="3" />
                        <rect x="477" y="162" width="16" height="15" rx="3" />
                        <rect x="433" y="117" width="15" height="16" rx="3" />
                        <rect x="522" y="117" width="15" height="16" rx="3" />
                        <rect x="449" y="89" width="16" height="15" rx="3" transform="rotate(45 457 96)" />
                        <rect x="505" y="145" width="16" height="15" rx="3" transform="rotate(45 513 152)" />
                        <rect x="449" y="145" width="16" height="15" rx="3" transform="rotate(-45 457 152)" />
                        <rect x="505" y="89" width="16" height="15" rx="3" transform="rotate(-45 513 96)" />
                    </g>
                    <circle cx="485" cy="125" r="16" fill="#f8fafc" />
                </g>

                <g class="ft-gear-spin-ccw">
                    <g fill="#93c5fd">
                        <circle cx="410" cy="88" r="28" />
                        <rect x="404" y="53" width="12" height="10" rx="2" />
                        <rect x="404" y="113" width="12" height="10" rx="2" />
                        <rect x="375" y="82" width="10" height="12" rx="2" />
                        <rect x="435" y="82" width="10" height="12" rx="2" />
                        <rect x="385" y="65" width="12" height="10" rx="2" transform="rotate(45 391 70)" />
                        <rect x="423" y="101" width="12" height="10" rx="2" transform="rotate(45 429 106)" />
                    </g>
                    <circle cx="410" cy="88" r="11" fill="#ffffff" />
                </g>

                <g class="ft-gear-spin-small">
                    <g fill="#bfdbfe">
                        <circle cx="300" cy="150" r="18" />
                        <rect x="296" y="128" width="8" height="7" rx="1.5" />
                        <rect x="296" y="165" width="8" height="7" rx="1.5" />
                        <rect x="278" y="146" width="7" height="8" rx="1.5" />
                        <rect x="315" y="146" width="7" height="8" rx="1.5" />
                    </g>
                    <circle cx="300" cy="150" r="7" fill="#f8fafc" />
                </g>

                <!-- 4. Ground Baseline with stylish dashed accents -->
                <line x1="225" y1="352" x2="520" y2="352" stroke="#60a5fa" stroke-width="3" stroke-linecap="round" stroke-dasharray="10 10 160 12 40 8 25" />

                <!-- 5. Computer Monitor (Centerpiece) -->
                <path d="M375,295 C375,330 365,348 355,350 L435,350 C425,348 415,330 415,295 Z" fill="#1e293b" />
                <rect x="345" y="347" width="100" height="7" rx="3.5" fill="#0f172a" />

                <rect x="310" y="145" width="220" height="150" rx="14" fill="url(#bezelGrad)" stroke="#0f172a" stroke-width="2.5" />

                <rect x="320" y="155" width="200" height="130" rx="8" fill="#f8fafc" />

                <circle cx="332" cy="165" r="2.5" fill="#ef4444" />
                <circle cx="340" cy="165" r="2.5" fill="#f59e0b" />
                <circle cx="348" cy="165" r="2.5" fill="#10b981" />

                <g class="ft-error-dialog-1" filter="url(#softCardShadow)">
                    <rect x="338" y="180" width="74" height="38" rx="6" fill="url(#errorModalGrad)" stroke="#93c5fd" stroke-width="1.2" />
                    <text x="375" y="204" font-family="Inter, system-ui, sans-serif" font-size="11.5" font-weight="800" fill="#1e40af" text-anchor="middle" letter-spacing="1">ERROR</text>
                </g>

                <g class="ft-error-dialog-2" filter="url(#softCardShadow)">
                    <rect x="424" y="214" width="74" height="38" rx="6" fill="url(#errorModalGrad)" stroke="#93c5fd" stroke-width="1.2" />
                    <text x="461" y="238" font-family="Inter, system-ui, sans-serif" font-size="11.5" font-weight="800" fill="#1e40af" text-anchor="middle" letter-spacing="1">ERROR</text>
                </g>

                <rect x="382" y="268" width="76" height="12" rx="6" fill="#e2e8f0" />
                <circle cx="395" cy="274" r="2.5" fill="#64748b" />
                <circle cx="407" cy="274" r="2.5" fill="#64748b" />
                <circle cx="419" cy="274" r="2.5" fill="#64748b" />
                <circle cx="431" cy="274" r="2.5" fill="#64748b" />

                <!-- 6. Ladder Leaning on the Monitor -->
                <g>
                    <line x1="312" y1="352" x2="328" y2="218" stroke="#93c5fd" stroke-width="4" stroke-linecap="round" />
                    <line x1="328" y1="352" x2="344" y2="218" stroke="#93c5fd" stroke-width="4" stroke-linecap="round" />
                    <line x1="315" y1="334" x2="331" y2="334" stroke="#60a5fa" stroke-width="2.5" stroke-linecap="round" />
                    <line x1="318" y1="310" x2="334" y2="310" stroke="#60a5fa" stroke-width="2.5" stroke-linecap="round" />
                    <line x1="321" y1="286" x2="337" y2="286" stroke="#60a5fa" stroke-width="2.5" stroke-linecap="round" />
                    <line x1="324" y1="262" x2="340" y2="262" stroke="#60a5fa" stroke-width="2.5" stroke-linecap="round" />
                    <line x1="327" y1="238" x2="343" y2="238" stroke="#60a5fa" stroke-width="2.5" stroke-linecap="round" />
                </g>

                <!-- 7. Steaming Coffee Mug -->
                <g>
                    <path d="M276,312 L278,352 L298,352 L300,312 Z" fill="#1e293b" />
                    <rect x="274" y="307" width="28" height="6" rx="2" fill="#334155" />
                    <rect x="277" y="322" width="22" height="15" rx="2" fill="#475569" />

                    <g class="ft-steam-float">
                        <path d="M285,302 Q278,288 288,274 T282,246" stroke="#94a3b8" stroke-width="2.4" stroke-linecap="round" fill="none" />
                        <path d="M293,298 Q300,284 292,270 T298,242" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round" fill="none" />
                    </g>
                </g>
            </svg>
        </div>
    </div>
</div>

<?php
    render_footer();
}
