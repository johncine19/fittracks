<?php
declare(strict_types=1);

function gym_selection_page(): void
{
    define('AUTH_PAGE', true);
    $user = require_roles(['member']);

    $gyms = db()->query('SELECT * FROM gyms WHERE status = "approved"')->fetchAll();

    $gymData = [];
    foreach ($gyms as $gym) {
        $classes = db()->prepare('SELECT * FROM classes WHERE gym_id = ? ORDER BY class_name ASC');
        $classes->execute([$gym['gym_id']]);
        $gym['classes'] = $classes->fetchAll();

        $plans = db()->prepare('
            SELECT * FROM membership_plans 
            WHERE 
                (gym_id = :gym_id AND is_active = 1)
                OR 
                (plan_id IN (SELECT plan_id FROM shared_plan_gyms WHERE gym_id = :gym_id AND status = "approved") AND plan_scope = "shared" AND is_active = 1)
            ORDER BY price ASC
        ');
        $plans->execute(['gym_id' => $gym['gym_id']]);
        $gym['plans'] = $plans->fetchAll();

        $gymData[] = $gym;
    }

    $profile = member_profile((int) $user['user_id']);
    $userGoal = strtolower($profile['primary_goal'] ?? '');

    render_header('Select Your Gym', $user);
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=DM+Sans:wght@400;500;700&display=swap');

        /* Override the site's default boxed .panel treatment for this page only,
           so it spans the viewport instead of floating as a narrow card. */
        .panel.gym-select {
            --font-display: 'Oswald', 'Arial Narrow', sans-serif;
            --font-ui: 'DM Sans', var(--font-sans, system-ui), sans-serif;
            max-width: none !important;
            width: 100% !important;
            margin: 0 !important;
            border-radius: 0 !important;
            border-left: none !important;
            border-right: none !important;
            box-sizing: border-box;
            padding: 48px clamp(20px, 5vw, 72px) !important;
        }
        .gym-select, .gym-select input, .gym-select button { font-family: var(--font-ui); }
        .gym-select .gym-hero,
        .gym-select .gym-search-wrap { max-width: 620px; margin-left: auto; margin-right: auto; }
        .gym-select .carousel-wrap { max-width: 1440px; margin: 0 auto; }

        /* ---------------- Hero ---------------- */
        .gym-hero { text-align: center; margin-bottom: 26px; position: relative; }
        .gym-hero .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-display);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: var(--lime, #c7ff22);
            margin-bottom: 12px;
        }
        .gym-hero .eyebrow .dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--lime, #c7ff22);
            box-shadow: 0 0 0 0 rgba(199,255,34,0.6);
            animation: pulse-dot 1.8s ease-out infinite;
        }
        @keyframes pulse-dot {
            0% { box-shadow: 0 0 0 0 rgba(199,255,34,0.55); }
            70% { box-shadow: 0 0 0 8px rgba(199,255,34,0); }
            100% { box-shadow: 0 0 0 0 rgba(199,255,34,0); }
        }
        .gym-hero h1 {
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.01em;
            font-size: clamp(2rem, 4.5vw, 3rem);
            line-height: 1.05;
            margin: 0 0 12px;
        }
        .gym-hero h1 mark {
            background: none;
            color: var(--lime, #c7ff22);
            position: relative;
            padding: 0 2px;
        }
        .gym-hero h1 mark::after {
            content: '';
            position: absolute;
            left: 0; right: 0; bottom: 2px;
            height: 6px;
            background: rgba(199,255,34,0.22);
            z-index: -1;
        }
        .gym-hero p.sub { color: var(--muted); font-size: 1.05rem; max-width: 560px; margin: 0 auto; }

        .pulse-line { display: block; width: 100%; max-width: 460px; height: 30px; margin: 20px auto 4px; opacity: 0.85; }
        .pulse-line path {
            fill: none;
            stroke: var(--lime, #c7ff22);
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 480;
            stroke-dashoffset: 480;
            animation: draw-pulse 1.6s ease-out forwards;
        }
        @keyframes draw-pulse { to { stroke-dashoffset: 0; } }

        /* ---------------- Search ---------------- */
        .gym-search-wrap {
            position: relative;
            max-width: 420px;
            margin: 0 auto 10px;
        }
        .gym-search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--muted);
            pointer-events: none;
            transition: color 0.2s;
        }
        #gym-search {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 11px 14px 11px 40px;
            color: var(--ink);
            font-size: 0.92rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #gym-search:focus {
            border-color: rgba(199, 255, 34, 0.5);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.1);
        }
        #gym-search:focus + svg,
        .gym-search-wrap:focus-within svg { color: var(--lime, #c7ff22); }
        #gym-search::placeholder { color: var(--muted); }

        /* ---------------- Carousel ---------------- */
        .carousel-wrap { position: relative; }
        .carousel-container {
            display: flex;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            gap: 20px;
            padding: 26px 4px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .carousel-container::-webkit-scrollbar { display: none; }
        .carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 5;
            transition: border-color 0.2s, color 0.2s, transform 0.15s;
        }
        .carousel-nav:hover { border-color: var(--lime); color: var(--lime); transform: translateY(-50%) scale(1.06); }
        .carousel-nav:focus-visible { outline: 2px solid var(--lime); outline-offset: 2px; }
        .carousel-nav.prev { left: -18px; }
        .carousel-nav.next { right: -18px; }
        .carousel-nav.is-disabled { opacity: 0.25; pointer-events: none; }
        @media (max-width: 640px) { .carousel-nav { display: none; } }

        /* ---------------- Gym card ---------------- */
        .gym-card {
            scroll-snap-align: start;
            flex: 0 0 auto;
            width: 300px;
            background:
                radial-gradient(120% 90% at 100% 0%, rgba(199,255,34,0.06), transparent 60%),
                linear-gradient(160deg, var(--surface), rgba(255,255,255,0.015));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 22px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            will-change: transform;
        }
        .gym-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--lime), transparent);
            opacity: 0;
            transition: opacity 0.25s;
        }
        .gym-card:hover::before { opacity: 1; }
        .gym-card:focus-visible { outline: 2px solid var(--lime); outline-offset: 2px; }
        .gym-card.is-filtered-out { display: none; }

        .gym-badge {
            position: absolute;
            top: 14px;
            left: -1px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(199,255,34,0.12);
            border: 1px solid rgba(199,255,34,0.4);
            border-left: none;
            color: var(--lime);
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 4px 10px 4px 8px;
            border-radius: 0 20px 20px 0;
            text-transform: uppercase;
        }
        .gym-badge .dot {
            width: 5px; height: 5px; border-radius: 50%;
            background: var(--lime);
            box-shadow: 0 0 0 0 rgba(199,255,34,0.6);
            animation: pulse-dot 1.8s ease-out infinite;
        }

        .gym-card-top { display: flex; align-items: center; gap: 11px; margin-bottom: 12px; margin-top: 4px; }
        .gym-logo-tile {
            width: 36px; height: 36px; border-radius: 8px; flex-shrink: 0;
            background: rgba(199,255,34,0.1);
            border: 1px solid rgba(199,255,34,0.25);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display);
            font-weight: 600; color: var(--lime);
            overflow: hidden;
        }
        .gym-logo-tile img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
        .gym-card h3 {
            margin: 0;
            font-family: var(--font-display);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.01em;
            font-size: 1.18rem;
            color: var(--ink);
        }
        .gym-card .addr-row {
            display: flex; align-items: flex-start; gap: 6px;
            margin: 0 0 5px; color: var(--muted); font-size: 13.5px; line-height: 1.4;
        }
        .gym-card .addr-row svg { flex-shrink: 0; margin-top: 2px; color: var(--muted); }

        .gym-chip-row { margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; gap: 8px; flex-wrap: wrap; }
        .gym-chip {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11.5px; font-weight: 600; color: var(--ink);
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--line);
            padding: 4px 9px; border-radius: 20px;
        }
        .gym-chip svg { color: var(--lime); }

        .gym-card .view-hint {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 14px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--lime);
            opacity: 0;
            transform: translateX(-4px);
            transition: opacity 0.2s, transform 0.2s;
        }
        .gym-card:hover .view-hint, .gym-card:focus-visible .view-hint { opacity: 1; transform: translateX(0); }

        .carousel-dots { display: flex; justify-content: center; gap: 6px; margin-top: 6px; }
        .carousel-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--line); cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }
        .carousel-dot.is-active { background: var(--lime); transform: scale(1.4); }

        .gym-no-results { display: none; text-align: center; color: var(--muted); padding: 40px 10px; }
        .gym-no-results.show { display: block; }

        .gym-select .skip-link { text-align: center; margin-top: 34px; }
        .gym-select .skip-link a { color: var(--muted); text-decoration: underline; font-size: 0.9rem; }

        /* ---------------- Modal ---------------- */
        #gymDetailsModal {
            display: flex;
            position: fixed;
            inset: 0;
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            visibility: hidden;
            pointer-events: none;
        }
        /* Backdrop is its own solid, blurred layer -- NOT nested inside anything
           that also fades, so its opacity never compounds with the content's. */
        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(4, 6, 6, 0.94);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 0;
        }
        .gym-details-content {
            position: relative;
            z-index: 1;
            background: var(--bg, #0b0d0d);
            border-radius: 14px;
            width: 100%;
            max-width: 820px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--line);
            box-shadow: 0 30px 80px rgba(0,0,0,0.6);
            opacity: 0;
            scrollbar-width: thin;
            scrollbar-color: rgba(199,255,34,0.35) transparent;
            font-family: var(--font-ui, inherit);
        }
        .gym-details-content::-webkit-scrollbar { width: 6px; }
        .gym-details-content::-webkit-scrollbar-track { background: transparent; }
        .gym-details-content::-webkit-scrollbar-thumb { background: rgba(199,255,34,0.3); border-radius: 999px; }

        .modal-hero {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 28px 30px 0;
            background: linear-gradient(160deg, rgba(199,255,34,0.08), transparent 70%), var(--bg, #0b0d0d);
            border-bottom: 1px solid var(--line);
        }
        .modal-hero-top { display: flex; align-items: flex-start; gap: 16px; }
        .modal-gym-icon {
            flex-shrink: 0;
            width: 52px; height: 52px;
            border-radius: 12px;
            background: rgba(199,255,34,0.12);
            border: 1px solid rgba(199,255,34,0.3);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display);
            font-size: 1.4rem;
        }
        .modal-hero h2 {
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.01em;
            color: var(--ink); font-size: 1.6rem; margin: 0 0 4px;
        }
        .modal-hero .addr { color: var(--muted); font-size: 0.95rem; margin: 0; display: flex; align-items: center; gap: 6px; }
        .modal-quickstats {
            display: flex;
            gap: 18px;
            margin: 16px 0 0;
            font-size: 0.82rem;
            color: var(--muted);
            flex-wrap: wrap;
        }
        .modal-quickstats strong { color: var(--lime); font-family: var(--font-display); font-weight: 600; }

        .modal-pulse-line { display: block; width: 100%; height: 16px; margin-top: 14px; opacity: 0.5; }
        .modal-pulse-line path {
            fill: none; stroke: var(--lime, #c7ff22); stroke-width: 1.5;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .modal-tabs { display: flex; gap: 4px; margin-top: 14px; }
        .modal-tab-btn {
            display: flex; align-items: center; gap: 6px;
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 600;
            padding: 10px 4px;
            margin-right: 22px;
            cursor: pointer;
            position: relative;
        }
        .modal-tab-btn:focus-visible { outline: 2px solid var(--lime); outline-offset: 3px; }
        .modal-tab-btn .tab-underline {
            position: absolute;
            left: 0; right: 0; bottom: -1px;
            height: 2px;
            background: var(--lime);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.25s ease;
        }
        .modal-tab-btn.is-active { color: var(--ink); }
        .modal-tab-btn.is-active .tab-underline { transform: scaleX(1); }

        .modal-body-inner { padding: 22px 30px 30px; }
        .modal-tab-panel { display: none; }
        .modal-tab-panel.is-active { display: block; }

        .close-btn {
            position: absolute;
            top: 20px; right: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            width: 34px; height: 34px;
            border-radius: 50%;
            transition: color 0.2s, background 0.2s, border-color 0.2s;
            z-index: 3;
        }
        .close-btn:hover { color: var(--ink); background: rgba(255,255,255,0.08); border-color: var(--lime); }
        .close-btn:focus-visible { outline: 2px solid var(--lime); outline-offset: 2px; }

        .class-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            gap: 12px;
            transition: border-color 0.2s, transform 0.2s;
        }
        .class-card:hover { border-color: rgba(199,255,34,0.35); }
        .class-icon {
            flex-shrink: 0;
            width: 34px; height: 34px;
            border-radius: 8px;
            background: rgba(199,255,34,0.1);
            display: flex; align-items: center; justify-content: center;
            color: var(--lime);
            font-size: 1.05rem;
        }
        .class-card strong { display: block; color: var(--ink); font-size: 1rem; margin-bottom: 4px; }
        .class-card .desc { font-size: 0.87rem; color: var(--muted); line-height: 1.4; }

        .plan-card {
            background: linear-gradient(160deg, var(--surface), rgba(255,255,255,0.015));
            border: 2px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: border-color 0.2s;
        }
        .plan-card.is-best-value { border-color: rgba(199,255,34,0.5); }
        .plan-best-badge {
            position: absolute;
            top: -11px;
            left: 16px;
            background: var(--lime);
            color: var(--bg);
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.03em;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
        }
        .plan-scope-badge {
            font-size: 10px;
            background: rgba(199,255,34,0.15);
            color: var(--lime);
            padding: 2px 6px;
            border-radius: 12px;
            text-transform: uppercase;
        }
        .plan-price-row { display: flex; align-items: baseline; gap: 6px; margin-bottom: 4px; }
        .plan-price { font-family: var(--font-display); font-size: 1.9rem; font-weight: 700; color: var(--lime); }
        .plan-per-day { font-size: 0.78rem; color: var(--muted); margin-bottom: 14px; }
        .plan-card .btn { transition: none; }

        @media (max-width: 560px) {
            .modal-hero { padding: 22px 20px 0; }
            .modal-body-inner { padding: 18px 20px 24px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .pulse-line path { animation: none; stroke-dashoffset: 0; }
            .gym-badge .dot, .gym-hero .eyebrow .dot { animation: none; }
        }
    </style>

    <div class="panel gym-select">
        <div class="gym-hero" id="gym-header">
            <div class="eyebrow"><span class="dot"></span> Membership</div>
            <h1>
                <?php if ($userGoal): ?>
                    Gyms matched to your <mark><?= h(str_replace('-', ' ', $userGoal)) ?></mark> goal
                <?php else: ?>
                    Find your <mark>perfect</mark> gym
                <?php endif; ?>
            </h1>
            <p class="sub">
                <?php if ($userGoal): ?>
                    We've flagged the gyms whose classes line up with what you're training for.
                <?php else: ?>
                    Browse classes, schedules, and membership plans from every gym on the platform.
                <?php endif; ?>
            </p>
            <svg class="pulse-line" viewBox="0 0 460 30" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,15 L150,15 L168,3 L184,27 L200,15 L215,15 L228,8 L240,22 L252,15 L460,15" />
            </svg>
        </div>

        <?php if (empty($gymData)): ?>
            <p style="text-align: center; color: var(--muted);">No gyms are currently available on the platform.</p>
        <?php else: ?>
            <div class="gym-search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="M21 21l-3.8-3.8"></path>
                </svg>
                <input type="text" id="gym-search" placeholder="Search gyms by name or location..." autocomplete="off">
            </div>

            <div class="carousel-wrap">
                <button type="button" class="carousel-nav prev" id="carousel-prev" aria-label="Previous gym">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"></path></svg>
                </button>
                <div class="carousel-container" id="carousel">
                    <?php foreach ($gymData as $index => $gym):
                        $isMatch = false;
                        if ($userGoal) {
                            $goalKeywords = explode('-', $userGoal);
                            foreach ($gym['classes'] as $c) {
                                $text = strtolower($c['class_name'] . ' ' . $c['description']);
                                foreach ($goalKeywords as $kw) {
                                    if (strlen($kw) > 3 && strpos($text, $kw) !== false) {
                                        $isMatch = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    ?>
                        <div class="gym-card" tabindex="0" role="button" style="--lime: <?= !empty($gym['brand_color']) ? h($gym['brand_color']) : '#c7ff22' ?>;" onclick="openGymModal(<?= (int)$index ?>)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openGymModal(<?= (int)$index ?>);}" data-search="<?= h(mb_strtolower($gym['name'] . ' ' . $gym['address'])) ?>">
                            <?php if ($isMatch): ?>
                                <div class="gym-badge"><span class="dot"></span> Recommended</div>
                            <?php endif; ?>
                            <div class="gym-card-top">
                                <?php if (!empty($gym['logo_url'])): ?>
                                    <div class="gym-logo-tile"><img src="assets/uploads/<?= h($gym['logo_url']) ?>" alt="<?= h($gym['name']) ?> logo"></div>
                                <?php else: ?>
                                    <div class="gym-logo-tile"><?= substr(h($gym['name']), 0, 1) ?></div>
                                <?php endif; ?>
                                <h3><?= h($gym['name']) ?></h3>
                            </div>
                            <p class="addr-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <span><?= h($gym['address']) ?></span>
                            </p>
                            <div class="gym-chip-row">
                                <span class="gym-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    <?= count($gym['classes']) ?> Classes
                                </span>
                                <span class="gym-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                                    <?= count($gym['plans']) ?> Plans
                                </span>
                            </div>
                            <div class="view-hint">
                                View details
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="carousel-nav next" id="carousel-next" aria-label="Next gym">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"></path></svg>
                </button>
            </div>

            <div class="carousel-dots" id="carousel-dots"></div>

            <div class="gym-no-results" id="gym-no-results">No gyms match your search.</div>
        <?php endif; ?>

        <div class="skip-link">
            <a href="index.php?page=dashboard">Skip for now</a>
        </div>
    </div>

    <!-- The Modal -->
    <div id="gymDetailsModal">
        <div class="modal-backdrop" id="modal-backdrop"></div>
        <div class="gym-details-content" id="modal-content">
            <button class="close-btn" id="modal-close-btn" aria-label="Close">&times;</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
    const gymData = <?= json_encode($gymData) ?>;
    const hasGSAP = typeof gsap !== 'undefined';

    const carousel = document.getElementById('carousel');
    const modal = document.getElementById('gymDetailsModal');
    const modalBackdrop = document.getElementById('modal-backdrop');
    const modalContent = document.getElementById('modal-content');
    const modalBody = document.getElementById('modalBody');

    // ---------------------------------------------------------------
    // Entrance animation
    // ---------------------------------------------------------------
    (function initEntrance() {
        const header = document.getElementById('gym-header');
        const searchWrap = document.querySelector('.gym-search-wrap');
        const cards = document.querySelectorAll('.gym-card');
        if (!hasGSAP) return;

        gsap.set(header, { opacity: 0, y: 16 });
        if (searchWrap) gsap.set(searchWrap, { opacity: 0, y: 12 });
        gsap.set(cards, { opacity: 0, y: 24 });

        const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });
        tl.to(header, { opacity: 1, y: 0, duration: 0.45 });
        if (searchWrap) tl.to(searchWrap, { opacity: 1, y: 0, duration: 0.35 }, '-=0.2');
        tl.to(cards, { opacity: 1, y: 0, duration: 0.4, stagger: 0.08 }, '-=0.15');

        cards.forEach(function (c) {
            c.addEventListener('mouseenter', function () {
                gsap.to(c, { y: -6, boxShadow: '0 14px 30px rgba(0,0,0,0.3), 0 0 0 1px rgba(199,255,34,0.25)', borderColor: 'var(--lime)', duration: 0.25, ease: 'power2.out' });
            });
            c.addEventListener('mouseleave', function () {
                gsap.to(c, { y: 0, boxShadow: '0 0px 0px rgba(0,0,0,0)', borderColor: 'var(--line)', duration: 0.3, ease: 'power2.out' });
            });
        });
    })();

    // ---------------------------------------------------------------
    // Carousel navigation
    // ---------------------------------------------------------------
    (function initCarousel() {
        if (!carousel) return;
        const prevBtn = document.getElementById('carousel-prev');
        const nextBtn = document.getElementById('carousel-next');
        const dotsWrap = document.getElementById('carousel-dots');
        const cards = Array.prototype.slice.call(carousel.querySelectorAll('.gym-card'));

        cards.forEach(function (_, i) {
            const dot = document.createElement('div');
            dot.className = 'carousel-dot' + (i === 0 ? ' is-active' : '');
            dot.addEventListener('click', function () {
                cards[i].scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
            });
            dotsWrap.appendChild(dot);
        });
        const dots = Array.prototype.slice.call(dotsWrap.children);

        function scrollByCard(direction) {
            const cardWidth = cards[0] ? cards[0].getBoundingClientRect().width + 20 : 320;
            carousel.scrollBy({ left: direction * cardWidth, behavior: 'smooth' });
        }
        if (prevBtn) prevBtn.addEventListener('click', function () { scrollByCard(-1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { scrollByCard(1); });

        function updateNavState() {
            const maxScroll = carousel.scrollWidth - carousel.clientWidth - 4;
            if (prevBtn) prevBtn.classList.toggle('is-disabled', carousel.scrollLeft <= 4);
            if (nextBtn) nextBtn.classList.toggle('is-disabled', carousel.scrollLeft >= maxScroll);

            let closestIndex = 0;
            let closestDist = Infinity;
            cards.forEach(function (c, i) {
                const dist = Math.abs(c.offsetLeft - carousel.scrollLeft);
                if (dist < closestDist) { closestDist = dist; closestIndex = i; }
            });
            dots.forEach(function (d, i) { d.classList.toggle('is-active', i === closestIndex); });
        }

        carousel.addEventListener('scroll', function () { window.requestAnimationFrame(updateNavState); });
        updateNavState();
    })();

    // ---------------------------------------------------------------
    // Search / filter
    // ---------------------------------------------------------------
    (function initSearch() {
        const input = document.getElementById('gym-search');
        const noResults = document.getElementById('gym-no-results');
        if (!input || !carousel) return;

        input.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let anyVisible = false;
            carousel.querySelectorAll('.gym-card').forEach(function (card) {
                const matches = !q || card.dataset.search.indexOf(q) !== -1;
                if (matches) anyVisible = true;
                if (matches && card.classList.contains('is-filtered-out')) {
                    card.classList.remove('is-filtered-out');
                    if (hasGSAP) gsap.fromTo(card, { opacity: 0, y: -6 }, { opacity: 1, y: 0, duration: 0.25 });
                } else if (!matches) {
                    card.classList.add('is-filtered-out');
                }
            });
            if (noResults) noResults.classList.toggle('show', !anyVisible);
        });
    })();

    // ---------------------------------------------------------------
    // Modal helpers
    // ---------------------------------------------------------------
    function guessClassIcon(name, desc) {
        const text = (name + ' ' + (desc || '')).toLowerCase();
        if (/(yoga|stretch|mobility|flex)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
        if (/(cycle|spin|bike)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>';
        if (/(crossfit|power|strength|lift|weight)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
        if (/(hiit|burn|cardio|zumba|dance)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0011 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 11-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 002.5 2.5z"/></svg>';
        if (/(core|abs|pilates)/.test(text)) return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }

    function buildModalHtml(gym) {
        const classCount = gym.classes ? gym.classes.length : 0;
        const planCount = gym.plans ? gym.plans.length : 0;
        let minPrice = null;
        let bestValuePlanId = null;
        if (gym.plans && gym.plans.length > 0) {
            minPrice = Math.min.apply(null, gym.plans.map(p => parseFloat(p.price)));
            let bestPerDay = Infinity;
            gym.plans.forEach(function (p) {
                const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                if (perDay < bestPerDay) { bestPerDay = perDay; bestValuePlanId = p.plan_id; }
            });
        }

        let logoHtml = gym.logo_url
            ? `<img src="assets/uploads/${escapeHtml(gym.logo_url)}" alt="Logo" style="width: 100%; height: 100%; object-fit: contain; border-radius: 10px; background: white;">`
            : `<span style="color: var(--lime); font-size: 1.5rem;">${escapeHtml(gym.name.charAt(0))}</span>`;

        let html = `
            <div class="modal-hero">
                <div class="modal-hero-top">
                    <div class="modal-gym-icon" style="overflow: hidden; padding: ${gym.logo_url ? '0' : '8px'};">${logoHtml}</div>
                    <div>
                        <h2>${escapeHtml(gym.name)}</h2>
                        <p class="addr">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            ${escapeHtml(gym.address)}
                        </p>
                    </div>
                </div>
                <div class="modal-quickstats">
                    <span><strong>${classCount}</strong> classes</span>
                    <span><strong>${planCount}</strong> plans</span>
                    ${minPrice !== null ? `<span>From <strong>₱${minPrice.toFixed(2)}</strong></span>` : ''}
                </div>
                <svg class="modal-pulse-line" viewBox="0 0 500 16" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0,8 L200,8 L212,2 L224,14 L236,8 L500,8" />
                </svg>
                <div class="modal-tabs">
                    <button type="button" class="modal-tab-btn is-active" id="tabbtn-classes" data-tab="classes">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        Classes<span class="tab-underline"></span>
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabbtn-plans" data-tab="plans">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                        Membership Plans<span class="tab-underline"></span>
                    </button>
                </div>
            </div>
            <div class="modal-body-inner">
                <div class="modal-tab-panel is-active" id="tab-classes">
        `;

        if (classCount > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px;">`;
            gym.classes.forEach(c => {
                html += `
                    <div class="class-card">
                        <div class="class-icon">${guessClassIcon(c.class_name, c.description)}</div>
                        <div>
                            <strong>${escapeHtml(c.class_name)}</strong>
                            <div class="desc">${escapeHtml(c.description || '')}</div>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<p style="color: var(--muted);">No classes currently offered.</p>`;
        }

        html += `</div><div class="modal-tab-panel" id="tab-plans">`;

        if (planCount > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px;">`;
            gym.plans.forEach(p => {
                const isBest = p.plan_id === bestValuePlanId && gym.plans.length > 1;
                const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                const scopeBadge = p.plan_scope === 'shared' ? `<span class="plan-scope-badge">Shared</span>` : '';
                html += `
                    <div class="plan-card${isBest ? ' is-best-value' : ''}">
                        ${isBest ? '<span class="plan-best-badge">Best Value</span>' : ''}
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; margin-top: ${isBest ? '4px' : '0'};">
                            <strong style="font-size: 1.15rem; color: var(--ink);">${escapeHtml(p.plan_name)}</strong>
                            ${scopeBadge}
                        </div>
                        <div class="plan-price-row">
                            <span class="plan-price">₱${parseFloat(p.price).toFixed(2)}</span>
                        </div>
                        <div class="plan-per-day">~₱${perDay.toFixed(2)}/day &middot; ${p.duration_days} days</div>
                        <p style="color: var(--muted); font-size: 0.88rem; flex-grow: 1; margin-bottom: 18px; line-height: 1.45;">
                            ${escapeHtml(p.description || '')}
                        </p>
                        <form method="post" action="index.php?page=memberships">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="subscribe">
                            <input type="hidden" name="subscribe_plan_id" value="${p.plan_id}">
                            <input type="hidden" name="payment_method" value="gcash">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Subscribe</button>
                        </form>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            html += `<p style="color: var(--muted);">No membership plans currently available.</p>`;
        }

        html += `</div>`;
        return html;
    }

    function wireModalInteractions() {
        const tabBtns = modalBody.querySelectorAll('.modal-tab-btn');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = btn.dataset.tab;
                tabBtns.forEach(b => b.classList.toggle('is-active', b === btn));
                const panels = modalBody.querySelectorAll('.modal-tab-panel');
                panels.forEach(function (panel) {
                    const isTarget = panel.id === 'tab-' + target;
                    if (isTarget) {
                        panel.classList.add('is-active');
                        if (hasGSAP) {
                            gsap.fromTo(panel, { opacity: 0, y: 8 }, { opacity: 1, y: 0, duration: 0.25, ease: 'power2.out' });
                            const items = panel.querySelectorAll('.class-card, .plan-card');
                            gsap.fromTo(items, { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.25, stagger: 0.03, delay: 0.05, ease: 'power2.out' });
                        }
                    } else {
                        panel.classList.remove('is-active');
                    }
                });
            });
        });

        modalBody.querySelectorAll('.plan-card .btn').forEach(function (btn) {
            btn.addEventListener('mouseenter', function () { if (hasGSAP) gsap.to(btn, { scale: 1.03, duration: 0.2, ease: 'power2.out' }); });
            btn.addEventListener('mouseleave', function () { if (hasGSAP) gsap.to(btn, { scale: 1, duration: 0.2, ease: 'power2.out' }); });
        });
    }

    function openGymModal(index) {
        const gym = gymData[index];
        modalBody.innerHTML = buildModalHtml(gym);
        wireModalInteractions();

        if (gym.brand_color) {
            modalContent.style.setProperty('--lime', gym.brand_color);
        } else {
            modalContent.style.removeProperty('--lime');
        }

        modal.style.visibility = 'visible';
        modal.style.pointerEvents = 'auto';

        if (hasGSAP) {
            gsap.killTweensOf([modalBackdrop, modalContent]);
            // Backdrop and content animate independently (siblings) so their
            // opacities never multiply together -- this is what fixes the
            // "page bleeding through" issue.
            gsap.to(modalBackdrop, { opacity: 1, duration: 0.25, ease: 'power2.out' });
            gsap.fromTo(modalContent,
                { scale: 0.95, opacity: 0, y: 12 },
                { scale: 1, opacity: 1, y: 0, duration: 0.35, ease: 'power3.out' }
            );
            const items = modalBody.querySelectorAll('.class-card');
            gsap.fromTo(items, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.3, stagger: 0.03, delay: 0.15, ease: 'power2.out' });
        } else {
            modalBackdrop.style.opacity = '1';
            modalContent.style.opacity = '1';
        }

        document.addEventListener('keydown', onModalKeydown);
    }

    function closeGymModal() {
        document.removeEventListener('keydown', onModalKeydown);
        if (hasGSAP) {
            gsap.to(modalContent, { scale: 0.96, opacity: 0, y: 8, duration: 0.2, ease: 'power2.in' });
            gsap.to(modalBackdrop, {
                opacity: 0,
                duration: 0.25,
                delay: 0.05,
                ease: 'power2.in',
                onComplete: function () {
                    modal.style.visibility = 'hidden';
                    modal.style.pointerEvents = 'none';
                }
            });
        } else {
            modalBackdrop.style.opacity = '0';
            modalContent.style.opacity = '0';
            modal.style.visibility = 'hidden';
            modal.style.pointerEvents = 'none';
        }
    }

    function onModalKeydown(e) {
        if (e.key === 'Escape') closeGymModal();
    }

    document.getElementById('modal-close-btn').addEventListener('click', closeGymModal);
    modalBackdrop.addEventListener('click', closeGymModal);

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
    </script>
    <?php
    render_footer();
}