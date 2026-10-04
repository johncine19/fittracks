<?php
declare(strict_types=1);

function gym_selection_page(): void
{
    define('AUTH_PAGE', true);
    $user = require_roles(['member']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_gym') {
        verify_csrf();
        $gymId = (int) ($_POST['gym_id'] ?? 0);
        if ($gymId > 0) {
            $targetGym = db()->query("SELECT * FROM gyms WHERE gym_id = " . $gymId)->fetch(PDO::FETCH_ASSOC);
            if (!$targetGym || gym_subscription_tier($targetGym) === 'none' || !gym_can_add_member($gymId, $targetGym)) {
                $limit = gym_member_limit($targetGym);
                $current = gym_active_member_count($gymId);
                if ($limit > 0 && $current >= $limit) {
                    flash("This gym has reached its maximum active member capacity ({$current}/{$limit} members). Please select another gym or contact the gym owner.", 'warning');
                } else {
                    flash('This gym is currently not accepting new members. Please select another gym or contact the gym owner.', 'warning');
                }
                redirect('index.php?page=gym_selection');
                return;
            }
            // Cleanly switch gym affiliation
            db()->prepare('DELETE FROM gym_members WHERE user_id = ?')->execute([$user['user_id']]);
            db()->prepare('INSERT INTO gym_members (user_id, gym_id) VALUES (?, ?)')
                ->execute([$user['user_id'], $gymId]);
            $_SESSION['current_gym_id'] = $gymId;
            flash('Successfully affiliated with ' . $targetGym['name'] . '.', 'success');
            redirect('index.php?page=dashboard');
        }
    }

    // Display gyms that are approved and accessible (active subscription, free trial, or free tier)
    $gyms = db()->query("
        SELECT * FROM gyms 
        WHERE status = 'approved'
    ")->fetchAll();

    $gymData = [];
    foreach ($gyms as $gym) {
        if (gym_subscription_tier($gym) === 'none') {
            continue;
        }
        $classes = db()->prepare('SELECT * FROM classes WHERE gym_id = ? ORDER BY class_name ASC');
        $classes->execute([$gym['gym_id']]);
        $gym['classes'] = $classes->fetchAll();

        $plans = db()->prepare('
            SELECT * FROM membership_plans 
            WHERE gym_id = :gym_id AND is_active = 1
            ORDER BY price ASC
        ');
        $plans->execute(['gym_id' => $gym['gym_id']]);
        $rawPlans = $plans->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawPlans as &$p) {
            $p['features'] = get_membership_plan_features($p);
            $p['is_popular'] = !empty($p['is_popular']);
        }
        unset($p);
        $gym['plans'] = $rawPlans;

        $images = db()->prepare('SELECT image_url FROM gym_images WHERE gym_id = ? ORDER BY created_at DESC LIMIT 10');
        $images->execute([$gym['gym_id']]);
        $gym['images'] = $images->fetchAll(PDO::FETCH_COLUMN);

        $gym['rating_stats'] = get_gym_rating_stats((int)$gym['gym_id']);

        $gymData[] = $gym;
    }

    $profile = member_profile((int) $user['user_id']);
    $userGoal = strtolower($profile['primary_goal'] ?? '');

    render_header('Select Your Gym', $user);
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=DM+Sans:wght@400;500;700&display=swap');

        /* Page Background Styling */
        body:has(.gym-select),
        body.auth-body:has(.gym-select),
        body.app-body:has(.gym-select),
        [data-theme="light"] body:has(.gym-select),
        [data-theme="light"] body.auth-body:has(.gym-select),
        [data-theme="light"] body.app-body:has(.gym-select) {
            background-color: #07090d !important;
            background-image: 
                radial-gradient(ellipse at 50% 15%, rgba(199, 255, 34, 0.12) 0%, transparent 60%),
                linear-gradient(180deg, rgba(7, 9, 13, 0.78) 0%, rgba(9, 12, 18, 0.92) 100%),
                url('assets/images/loginback.png?v=3') !important;
            background-size: cover !important;
            background-position: center top !important;
            background-repeat: no-repeat !important;
            background-attachment: fixed !important;
            min-height: 100vh !important;
            color: #f8fafc !important;
        }

        .auth-shell:has(.gym-select),
        .app-frame:has(.gym-select) {
            background: transparent !important;
        }

        /* Override the site's default boxed .panel treatment & enforce permanent dark tokens */
        .panel.gym-select {
            --font-display: 'Oswald', 'Arial Narrow', sans-serif;
            --font-ui: 'DM Sans', var(--font-sans, system-ui), sans-serif;
            --ink: #f8fafc !important;
            --muted: #94a3b8 !important;
            --panel: #0d121a !important;
            --panel-soft: #141b27 !important;
            --line: rgba(255, 255, 255, 0.12) !important;
            --lime: #c7ff22 !important;
            color-scheme: dark !important;
            color: #f8fafc !important;
            max-width: 100% !important;
            width: 100% !important;
            margin: 0 !important;
            border-radius: 0 !important;
            border: none !important;
            box-shadow: none !important;
            box-sizing: border-box;
            padding: 48px clamp(16px, 4vw, 72px) !important;
            overflow-x: hidden;
            background: transparent !important;
            min-height: 100vh;
            position: relative;
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
            font-size: clamp(1.75rem, 7vw, 3rem);
            line-height: 1.1;
            margin: 0 0 12px;
            word-wrap: break-word;
            overflow-wrap: break-word;
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
        .gym-hero p.sub { color: var(--muted); font-size: 0.95rem; max-width: 560px; margin: 0 auto; padding: 0 10px; }

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
            background: rgba(14, 18, 17, 0.82);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 12px 14px 12px 42px;
            color: var(--ink);
            font-size: 0.92rem;
            outline: none;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #gym-search:focus {
            border-color: rgba(199, 255, 34, 0.5);
            box-shadow: 0 0 0 3px rgba(199, 255, 34, 0.15);
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
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(14, 18, 17, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 5;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
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
                rgba(14, 18, 17, 0.88);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 14px;
            padding: 22px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            will-change: transform;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7);
            color: #f8fafc;
            transition: border-color 0.25s, transform 0.25s, box-shadow 0.25s;
        }
        .gym-card:hover {
            border-color: var(--lime);
            transform: translateY(-4px);
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.85), 0 0 20px rgba(199, 255, 34, 0.15);
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
            --ink: #f8fafc !important;
            --muted: #94a3b8 !important;
            --panel: #0d121a !important;
            --panel-soft: #141b27 !important;
            --surface: #131917 !important;
            --surface-elevated: #1a221f !important;
            --ink: #f8fafc !important;
            --muted: #94a3b8 !important;
            --line: rgba(255, 255, 255, 0.12) !important;
            --lime: #c7ff22 !important;
            color-scheme: dark !important;
            color: #f8fafc !important;
            background: rgba(12, 16, 15, 0.96);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 16px;
            width: 100%;
            max-width: 880px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 35px 90px rgba(0,0,0,0.85);
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
            padding: 26px 28px 0;
            background: linear-gradient(160deg, rgba(199,255,34,0.08), transparent 70%), rgba(12, 16, 15, 0.98);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }
        .modal-hero-top {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-right: 56px; /* Generous clearance preventing overlap with close button */
        }
        .modal-hero-sub {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .desktop-only { display: block; }
        .mobile-only { display: none; }
        .modal-select-btn-sm {
            background: var(--lime, #c7ff22) !important;
            color: #0b0d0d !important;
            font-weight: 700 !important;
            font-size: 0.82rem !important;
            padding: 6px 14px !important;
            border-radius: 8px !important;
            border: none !important;
            white-space: nowrap !important;
            cursor: pointer !important;
            box-shadow: 0 2px 10px rgba(199, 255, 34, 0.25) !important;
            transition: all 0.2s ease !important;
            line-height: 1.3;
        }
        .modal-select-btn-sm:hover {
            background: #d4ff42 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(199, 255, 34, 0.4) !important;
        }
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
            align-items: center;
        }
        .modal-quickstats strong { color: var(--lime); font-family: var(--font-display); font-weight: 600; }

        .modal-pulse-line { display: block; width: 100%; height: 16px; margin-top: 14px; opacity: 0.5; }
        .modal-pulse-line path {
            fill: none; stroke: var(--lime, #c7ff22); stroke-width: 1.5;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .modal-tabs {
            display: flex;
            gap: 6px;
            margin-top: 14px;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 0;
        }
        .modal-tabs::-webkit-scrollbar { display: none; }
        .modal-tab-btn {
            display: flex; align-items: center; gap: 6px;
            background: transparent;
            border: none;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 600;
            padding: 10px 14px;
            margin-right: 0;
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
            white-space: nowrap;
            border-radius: 8px 8px 0 0;
            transition: color 0.2s ease, background 0.2s ease;
        }
        .modal-tab-btn:hover {
            color: var(--ink);
            background: rgba(255, 255, 255, 0.04);
        }
        .modal-tab-btn:focus-visible { outline: 2px solid var(--lime); outline-offset: 2px; }
        .modal-tab-btn .tab-underline {
            position: absolute;
            left: 0; right: 0; bottom: -1px;
            height: 2px;
            background: var(--lime);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.25s ease;
        }
        .modal-tab-btn.is-active {
            color: #ffffff;
            background: rgba(199, 255, 34, 0.08);
        }
        .modal-tab-btn.is-active .tab-underline { transform: scaleX(1); }

        .modal-tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 19px;
            height: 18px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            background: rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.14);
            margin-left: 3px;
            transition: all 0.2s ease;
        }
        .modal-tab-btn:hover .modal-tab-badge {
            background: rgba(255, 255, 255, 0.16);
            color: #f8fafc;
            border-color: rgba(255, 255, 255, 0.24);
        }
        .modal-tab-btn.is-active .modal-tab-badge {
            background: rgba(199, 255, 34, 0.22);
            color: var(--lime);
            border-color: rgba(199, 255, 34, 0.5);
            font-weight: 800;
        }

        .modal-body-inner { padding: 22px 30px 30px; }
        .modal-tab-panel { display: none; }
        .modal-tab-panel.is-active { display: block; }

        /* Gallery Carousel Styles */
        .gym-gallery-carousel {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .gallery-viewport {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 10;
            max-height: 380px;
            border-radius: 14px;
            overflow: hidden;
            background: #0d1210;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        .gallery-track {
            display: flex;
            width: 100%;
            height: 100%;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .gallery-track::-webkit-scrollbar { display: none; }
        .gallery-slide {
            flex: 0 0 100%;
            width: 100%;
            height: 100%;
            scroll-snap-align: start;
            scroll-snap-stop: always;
            position: relative;
            background: #0f1513;
        }
        .gallery-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            user-select: none;
            -webkit-user-drag: none;
        }
        .gallery-counter {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(10, 15, 13, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 4px 10px;
            border-radius: 999px;
            z-index: 3;
            pointer-events: none;
        }
        .gallery-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(10, 15, 13, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 3;
            transition: all 0.2s ease;
            padding: 0;
        }
        .gallery-arrow:hover {
            background: rgba(199, 255, 34, 0.2);
            border-color: var(--lime);
            color: var(--lime);
            transform: translateY(-50%) scale(1.08);
        }
        .gallery-arrow.prev { left: 12px; }
        .gallery-arrow.next { right: 12px; }

        .gallery-dots {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }
        .gallery-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            border: none;
            cursor: pointer;
            padding: 0;
            transition: all 0.25s ease;
        }
        .gallery-dot.is-active {
            width: 22px;
            background: var(--lime);
            box-shadow: 0 0 10px rgba(199, 255, 34, 0.4);
        }

        .gallery-thumbs {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 2px 0 6px;
            scrollbar-width: none;
        }
        .gallery-thumbs::-webkit-scrollbar { display: none; }
        .gallery-thumb-btn {
            flex: 0 0 72px;
            height: 52px;
            border-radius: 8px;
            overflow: hidden;
            border: 1.5px solid rgba(255, 255, 255, 0.1);
            background: #0f1513;
            padding: 0;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.2s ease;
        }
        .gallery-thumb-btn:hover {
            opacity: 0.9;
            border-color: rgba(255, 255, 255, 0.3);
        }
        .gallery-thumb-btn.is-active {
            opacity: 1;
            border-color: var(--lime);
            box-shadow: 0 0 10px rgba(199, 255, 34, 0.3);
            transform: scale(1.03);
        }
        .gallery-thumb-btn img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .close-btn {
            position: absolute;
            top: 22px;
            right: 22px;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s, background 0.2s, border-color 0.2s, transform 0.15s;
            z-index: 10;
        }
        .close-btn:hover {
            color: var(--ink);
            background: rgba(255,255,255,0.12);
            border-color: var(--lime);
            transform: scale(1.06);
        }
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
        .class-card strong { display: block; color: #f8fafc !important; font-size: 1rem; margin-bottom: 4px; }
        .class-card .desc { font-size: 0.87rem; color: #94a3b8 !important; line-height: 1.4; }

        .gym-plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 16px;
            align-items: stretch;
        }

        /* Mobile Plans Carousel Controls */
        .plan-carousel-indicator {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 14px;
        }
        .plan-carousel-dots {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .plan-carousel-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            border: none;
            padding: 0;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .plan-carousel-dot.is-active {
            width: 22px;
            background: var(--lime);
            box-shadow: 0 0 8px rgba(199, 255, 34, 0.4);
        }
        .plan-nav-arrow {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            line-height: 1;
            padding: 0;
        }
        .plan-nav-arrow:hover {
            background: rgba(199, 255, 34, 0.15);
            border-color: var(--lime);
            color: var(--lime);
        }
        .plan-swipe-hint {
            display: none;
            text-align: center;
            margin-top: 8px;
            font-size: 0.75rem;
            color: #64748b;
            letter-spacing: 0.03em;
        }

        @media (max-width: 680px) {
            .gym-plans-grid {
                display: flex !important;
                overflow-x: auto !important;
                scroll-snap-type: x mandatory !important;
                -webkit-overflow-scrolling: touch !important;
                gap: 14px !important;
                padding: 14px 4px 16px !important;
                scrollbar-width: none !important;
                margin: 0 -4px;
            }
            .gym-plans-grid::-webkit-scrollbar { display: none !important; }
            .gym-plans-grid .plan-card {
                flex: 0 0 86% !important;
                max-width: 320px !important;
                min-width: 250px !important;
                scroll-snap-align: center !important;
                scroll-snap-stop: always !important;
            }
            .plan-carousel-indicator {
                display: flex !important;
            }
            .plan-swipe-hint {
                display: block !important;
            }
            .gallery-viewport {
                aspect-ratio: 4 / 3;
            }
            .gallery-thumbs {
                display: none;
            }
        }

        .plan-card {
            background: linear-gradient(165deg, rgba(22, 28, 25, 0.95), rgba(12, 16, 15, 0.98)) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 14px;
            padding: 24px 20px 20px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
        }
        .plan-card:hover {
            transform: translateY(-2px);
            border-color: rgba(199, 255, 34, 0.4) !important;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.5);
        }
        .plan-card.is-best-value {
            background: linear-gradient(165deg, rgba(28, 38, 26, 0.95), rgba(14, 20, 16, 0.98)) !important;
            border: 1.5px solid rgba(199, 255, 34, 0.65) !important;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5), 0 0 24px rgba(199, 255, 34, 0.14);
        }
        .plan-card.is-best-value:hover {
            border-color: var(--lime) !important;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.6), 0 0 32px rgba(199, 255, 34, 0.22);
        }
        .plan-best-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--lime, #c7ff22);
            color: #0b0d0d;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.06em;
            padding: 3px 14px;
            border-radius: 999px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(199, 255, 34, 0.35);
            white-space: nowrap;
        }
        .plan-scope-badge {
            font-size: 10px;
            background: rgba(199,255,34,0.15);
            color: var(--lime);
            padding: 2px 6px;
            border-radius: 12px;
            text-transform: uppercase;
        }
        .plan-title {
            color: #ffffff !important;
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.25;
            letter-spacing: -0.01em;
        }
        .plan-price-row {
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin-top: 10px;
            margin-bottom: 2px;
        }
        .plan-price {
            font-family: var(--font-display);
            font-size: 2.1rem;
            font-weight: 800;
            color: var(--lime, #c7ff22) !important;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }
        .plan-per-day {
            font-size: 0.8rem;
            color: #94a3b8 !important;
            margin-bottom: 18px;
            font-weight: 500;
        }
        .plan-features-list {
            list-style: none;
            padding: 0;
            margin: 0 0 20px;
            display: flex;
            flex-direction: column;
            gap: 9px;
            flex-grow: 1;
        }
        .plan-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 0.84rem;
            color: #e2e8f0 !important;
            line-height: 1.4;
        }
        .plan-feature-item .plan-check {
            color: var(--lime, #c7ff22) !important;
            font-weight: 800;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .plan-card .btn {
            width: 100%;
            background: var(--lime, #c7ff22) !important;
            color: #080b0d !important;
            font-weight: 700 !important;
            font-size: 0.95rem;
            padding: 11px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(199, 255, 34, 0.22);
            display: block;
            text-align: center;
        }
        .plan-card .btn:hover {
            background: #d4ff42 !important;
            color: #000000 !important;
            box-shadow: 0 6px 20px rgba(199, 255, 34, 0.4);
        }

        @media (max-width: 560px) {
            #gymDetailsModal { padding: 6px 4px; }
            .gym-details-content { max-height: 94vh; border-radius: 14px; }
            .panel.gym-select { padding: 28px 14px !important; }
            .modal-hero { padding: 14px 14px 0 !important; }
            .modal-body-inner { padding: 14px 12px 18px !important; }

            .modal-hero-top {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                gap: 10px !important;
                padding-right: 38px !important; /* Clearance for mobile close button */
            }
            .modal-gym-icon {
                width: 40px !important;
                height: 40px !important;
                border-radius: 9px !important;
                font-size: 1.2rem !important;
                flex-shrink: 0 !important;
            }
            .modal-hero-meta {
                flex: 1 !important;
                min-width: 0 !important;
            }
            .modal-hero-meta h2 {
                font-size: 1.15rem !important;
                margin: 0 0 2px !important;
                line-height: 1.25 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            .modal-hero-meta .addr {
                font-size: 0.78rem !important;
                margin: 0 !important;
                line-height: 1.2 !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                color: #94a3b8 !important;
                display: flex !important;
                align-items: center !important;
                gap: 4px !important;
            }

            .desktop-only { display: none !important; }
            .mobile-only { display: block !important; }

            .modal-hero-sub {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 8px !important;
                margin-top: 8px !important;
            }
            .modal-quickstats {
                margin: 0 !important;
                font-size: 0.76rem !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
                line-height: 1.3 !important;
            }
            .modal-quickstats .modal-reviews-link {
                display: none !important;
            }

            .modal-pulse-line {
                display: none !important; /* Hide decorative SVG line on mobile to reclaim vertical height */
            }

            .modal-tabs {
                margin-top: 8px !important;
                padding-bottom: 0 !important;
                gap: 4px !important;
            }
            .modal-tab-btn {
                margin-right: 0 !important;
                font-size: 0.8rem !important;
                padding: 7px 10px !important;
            }
            .modal-tab-badge {
                font-size: 10px !important;
                min-width: 17px !important;
                height: 16px !important;
                padding: 0 4px !important;
            }
            .close-btn {
                top: 12px !important;
                right: 12px !important;
                width: 30px !important;
                height: 30px !important;
                font-size: 18px !important;
            }
            .gym-card { width: 85vw; }
        }

        @media (max-width: 400px) {
            .gym-card { width: 90vw; }
            .gym-hero h1 { font-size: 1.6rem; }
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
            <div style="margin-top: 14px;">
                <a href="index.php?page=dashboard" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 999px; background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.15); color: #94a3b8; font-size: 12.5px; font-weight: 600; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.color='#f8fafc'; this.style.borderColor='rgba(199,255,34,0.4)'; this.style.background='rgba(199,255,34,0.08)';" onmouseout="this.style.color='#94a3b8'; this.style.borderColor='rgba(255,255,255,0.15)'; this.style.background='rgba(255,255,255,0.06)';">
                    <span>Skip gym selection for now &amp; go to Dashboard</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
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
                                    <div class="gym-logo-tile"><img src="<?= h(upload_url($gym['logo_url'])) ?>" alt="<?= h($gym['name']) ?> logo" loading="lazy" decoding="async" onerror="this.onerror=null; this.outerHTML='<?= htmlspecialchars(substr(h($gym['name']), 0, 1), ENT_QUOTES) ?>';"></div>
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
                                <?php $gStats = $gym['rating_stats'] ?? ['avg_rating' => 0, 'total_reviews' => 0]; ?>
                                <span class="gym-chip" style="color: #fbbf24; border-color: rgba(251, 191, 36, 0.3); background: rgba(251, 191, 36, 0.08);">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="#fbbf24" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    <?= number_format((float)$gStats['avg_rating'], 1) ?> (<?= (int)$gStats['total_reviews'] ?>)
                                </span>
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

    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
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

    function getImageUrl(filename, folder = 'uploads') {
        if (!filename) return '';
        if (filename.startsWith('http://') || filename.startsWith('https://')) return escapeHtml(filename);
        return `assets/${folder}/${escapeHtml(filename)}`;
    }

    function buildModalHtml(gym) {
        const classCount = gym.classes ? gym.classes.length : 0;
        const planCount = gym.plans ? gym.plans.length : 0;
        const imageCount = gym.images ? gym.images.length : 0;
        let minPrice = null;
        let highlightedPlanId = null;
        let highlightBadgeText = 'Most Popular';
        if (gym.plans && gym.plans.length > 0) {
            minPrice = Math.min.apply(null, gym.plans.map(p => parseFloat(p.price)));
            
            // Respect the gym owner's configured "Most Popular" plan from page=plans
            const popularPlan = gym.plans.find(p => p.is_popular === true || p.is_popular === 1 || p.is_popular === '1');
            if (popularPlan) {
                highlightedPlanId = popularPlan.plan_id;
                highlightBadgeText = 'Most Popular';
            } else {
                // Fallback: check for quarterly plan or lowest per-day price
                const quarterlyPlan = gym.plans.find(p => parseInt(p.duration_days, 10) === 90 || (p.plan_type && p.plan_type.toLowerCase() === 'quarterly'));
                if (quarterlyPlan && gym.plans.length > 1) {
                    highlightedPlanId = quarterlyPlan.plan_id;
                    highlightBadgeText = 'Most Popular';
                } else {
                    let bestPerDay = Infinity;
                    gym.plans.forEach(function (p) {
                        const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                        if (perDay < bestPerDay) {
                            bestPerDay = perDay;
                            highlightedPlanId = p.plan_id;
                            highlightBadgeText = 'Best Value';
                        }
                    });
                }
            }
        }

        let logoHtml = gym.logo_url
            ? `<img src="${getImageUrl(gym.logo_url)}" alt="Logo" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: contain; border-radius: 10px; background: white;" onerror="this.onerror=null; this.outerHTML='<span style=\\'color: var(--lime); font-size: 1.5rem;\\'>'+escapeHtml(gym.name.charAt(0))+'</span>';">`
            : `<span style="color: var(--lime); font-size: 1.5rem;">${escapeHtml(gym.name.charAt(0))}</span>`;

        const hasGallery = gym.images && gym.images.length > 0;
                const ratingScore = gym.rating_stats ? parseFloat(gym.rating_stats.avg_rating) : 0;
                const reviewCount = gym.rating_stats ? parseInt(gym.rating_stats.total_reviews, 10) : 0;
                let html = `
            <div class="modal-hero">
                <div class="modal-hero-top">
                    <div class="modal-gym-icon" style="overflow: hidden; padding: ${gym.logo_url ? '0' : '8px'};">${logoHtml}</div>
                    <div class="modal-hero-meta">
                        <h2>${escapeHtml(gym.name)}</h2>
                        <p class="addr">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>${escapeHtml(gym.address)}</span>
                        </p>
                    </div>
                    <form method="post" action="index.php?page=gym_selection" class="desktop-only" style="margin: 0; flex-shrink: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-size: 0.95rem; white-space: nowrap; box-shadow: 0 4px 12px rgba(199, 255, 34, 0.2);">Select Gym</button>
                    </form>
                </div>
                <div class="modal-hero-sub">
                    <div class="modal-quickstats">
                        <span style="color:#fbbf24;"><strong style="color:#fbbf24;">★ ${ratingScore.toFixed(1)}</strong> (${reviewCount})</span>
                        ${minPrice !== null ? `<span>From <strong>₱${minPrice.toFixed(0)}</strong></span>` : ''}
                        <a href="index.php?page=view_gym&gym_id=${gym.gym_id}#gym-ratings-section" class="modal-reviews-link" style="color:var(--lime);font-size:12px;text-decoration:none;font-weight:600;margin-left:auto;">View Full Page & Reviews →</a>
                    </div>
                    <form method="post" action="index.php?page=gym_selection" class="mobile-only" style="margin: 0; flex-shrink: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="select_gym">
                        <input type="hidden" name="gym_id" value="${gym.gym_id}">
                        <button type="submit" class="modal-select-btn-sm">Select Gym</button>
                    </form>
                </div>
                <svg class="modal-pulse-line" viewBox="0 0 500 16" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0,8 L200,8 L212,2 L224,14 L236,8 L500,8" />
                </svg>
                <div class="modal-tabs">
                    ${hasGallery ? `
                    <button type="button" class="modal-tab-btn is-active" id="tabbtn-gallery" data-tab="gallery">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span>Gym Images</span>
                        <span class="modal-tab-badge">${imageCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                    ` : ''}
                    <button type="button" class="modal-tab-btn ${!hasGallery ? 'is-active' : ''}" id="tabbtn-classes" data-tab="classes">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>Classes</span>
                        <span class="modal-tab-badge">${classCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabbtn-plans" data-tab="plans">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/></svg>
                        <span>Membership Plans</span>
                        <span class="modal-tab-badge">${planCount}</span>
                        <span class="tab-underline"></span>
                    </button>
                </div>
            </div>
            <div class="modal-body-inner">
        `;
        
        const fallbackGallerySvg = "data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='500' viewBox='0 0 800 500'%3E%3Crect width='800' height='500' fill='%23111715'/%3E%3Cpath d='M160 380 L280 200 L380 290 L520 120 L660 380 Z' fill='none' stroke='%23223028' stroke-width='6' stroke-linejoin='round'/%3E%3Ccircle cx='600' cy='150' r='40' fill='%23223028'/%3E%3Crect x='300' y='220' width='200' height='50' rx='25' fill='rgba(199,255,34,0.1)' stroke='%23c7ff22' stroke-width='2'/%3E%3Ctext x='400' y='252' fill='%23c7ff22' font-family='system-ui,sans-serif' font-size='16' font-weight='800' letter-spacing='2' text-anchor='middle'%3EFITTRACK%3C/text%3E%3Ctext x='400' y='320' fill='%23ffffff' font-family='system-ui,sans-serif' font-size='20' font-weight='700' text-anchor='middle'%3EGym Gallery Preview%3C/text%3E%3Ctext x='400' y='348' fill='%2364748b' font-family='system-ui,sans-serif' font-size='14' text-anchor='middle'%3EVisit gym for full facility tour%3C/text%3E%3C/svg%3E";

        if (hasGallery) {
            let slidesHtml = '';
            gym.images.forEach(img => {
                slidesHtml += `
                    <div class="gallery-slide">
                        <img src="${getImageUrl(img)}" alt="Gym Image" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='${fallbackGallerySvg}';">
                    </div>
                `;
            });

            let dotsHtml = '';
            let thumbsHtml = '';
            if (gym.images.length > 1) {
                dotsHtml = `<div class="gallery-dots">` + 
                    gym.images.map((_, i) => `<button type="button" class="gallery-dot${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Go to image ${i + 1}"></button>`).join('') + 
                `</div>`;

                thumbsHtml = `<div class="gallery-thumbs">` +
                    gym.images.map((img, i) => `
                        <button type="button" class="gallery-thumb-btn${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Thumbnail ${i + 1}">
                            <img src="${getImageUrl(img)}" alt="Thumbnail" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='${fallbackGallerySvg}';">
                        </button>
                    `).join('') +
                `</div>`;
            }

            html += `
                <div class="modal-tab-panel is-active" id="tab-gallery">
                    <div class="gym-gallery-carousel">
                        <div class="gallery-viewport">
                            <div class="gallery-track">
                                ${slidesHtml}
                            </div>
                            ${gym.images.length > 1 ? `
                                <div class="gallery-counter">
                                    <span class="gallery-curr">1</span> / ${gym.images.length}
                                </div>
                                <button type="button" class="gallery-arrow prev" aria-label="Previous photo">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                </button>
                                <button type="button" class="gallery-arrow next" aria-label="Next photo">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </button>
                            ` : ''}
                        </div>
                        ${dotsHtml}
                        ${thumbsHtml}
                    </div>
                </div>
            `;
        }
        
        html += `<div class="modal-tab-panel ${!hasGallery ? 'is-active' : ''}" id="tab-classes">`;

        if (classCount > 0) {
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); gap: 14px;">`;
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
            html += `<div class="gym-plans-grid">`;
            gym.plans.forEach(p => {
                const isHighlighted = p.plan_id === highlightedPlanId && gym.plans.length > 1;
                const perDay = parseFloat(p.price) / Math.max(1, parseInt(p.duration_days, 10));
                const features = p.features && p.features.length > 0
                    ? p.features
                    : (p.description ? p.description.split('\n').map(s => s.trim()).filter(Boolean) : []);

                let featuresHtml = '';
                if (features.length > 0) {
                    featuresHtml = `<ul class="plan-features-list">`;
                    features.forEach(f => {
                        featuresHtml += `
                            <li class="plan-feature-item">
                                <span class="plan-check">✓</span>
                                <span>${escapeHtml(f)}</span>
                            </li>
                        `;
                    });
                    featuresHtml += `</ul>`;
                } else {
                    featuresHtml = `<p style="color: #94a3b8; font-size: 0.88rem; flex-grow: 1; margin-bottom: 18px; line-height: 1.45;">${escapeHtml(p.description || '')}</p>`;
                }

                html += `
                    <div class="plan-card${isHighlighted ? ' is-best-value' : ''}">
                        ${isHighlighted ? `<span class="plan-best-badge">${escapeHtml(highlightBadgeText)}</span>` : ''}
                        <div style="margin-bottom: 8px; margin-top: ${isHighlighted ? '6px' : '0'};">
                            <strong class="plan-title">${escapeHtml(p.plan_name)}</strong>
                        </div>
                        <div class="plan-price-row">
                            <span class="plan-price">₱${parseFloat(p.price).toFixed(2)}</span>
                        </div>
                        <div class="plan-per-day">~₱${perDay.toFixed(2)}/day &middot; ${p.duration_days} days</div>
                        ${featuresHtml}
                        <form method="post" action="index.php?page=memberships" style="margin-top: auto;">
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

            if (planCount > 1) {
                html += `
                    <div class="plan-carousel-indicator">
                        <button type="button" class="plan-nav-arrow prev" aria-label="Previous plan">‹</button>
                        <div class="plan-carousel-dots">
                            ${gym.plans.map((_, i) => `<button type="button" class="plan-carousel-dot${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Go to plan ${i + 1}"></button>`).join('')}
                        </div>
                        <button type="button" class="plan-nav-arrow next" aria-label="Next plan">›</button>
                    </div>
                    <div class="plan-swipe-hint">
                        <span>&larr; Swipe to view all plans &rarr;</span>
                    </div>
                `;
            }
        } else {
            html += `<p style="color: #94a3b8;">No membership plans currently available.</p>`;
        }

        html += `</div>`;
        return html;
    }

    function wireGalleryCarousel(container) {
        const carousel = container.querySelector('.gym-gallery-carousel');
        if (!carousel) return;

        const track = carousel.querySelector('.gallery-track');
        const slides = carousel.querySelectorAll('.gallery-slide');
        const counterCurr = carousel.querySelector('.gallery-counter .gallery-curr');
        const dots = carousel.querySelectorAll('.gallery-dot');
        const thumbs = carousel.querySelectorAll('.gallery-thumb-btn');
        const prevBtn = carousel.querySelector('.gallery-arrow.prev');
        const nextBtn = carousel.querySelector('.gallery-arrow.next');

        if (!track || slides.length <= 1) return;

        function updateActive(index) {
            if (counterCurr) counterCurr.textContent = index + 1;
            dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
            thumbs.forEach((thumb, i) => {
                thumb.classList.toggle('is-active', i === index);
                if (i === index) {
                    thumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                }
            });
        }

        let isScrolling = null;
        track.addEventListener('scroll', function () {
            clearTimeout(isScrolling);
            isScrolling = setTimeout(function () {
                const scrollLeft = track.scrollLeft;
                const slideWidth = track.clientWidth || track.offsetWidth || 1;
                const activeIndex = Math.min(slides.length - 1, Math.max(0, Math.round(scrollLeft / slideWidth)));
                updateActive(activeIndex);
            }, 40);
        }, { passive: true });

        if (prevBtn) {
            prevBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const slideWidth = track.clientWidth;
                track.scrollBy({ left: -slideWidth, behavior: 'smooth' });
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const slideWidth = track.clientWidth;
                track.scrollBy({ left: slideWidth, behavior: 'smooth' });
            });
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                const idx = parseInt(dot.dataset.index, 10);
                const slideWidth = track.clientWidth;
                track.scrollTo({ left: idx * slideWidth, behavior: 'smooth' });
                updateActive(idx);
            });
        });

        thumbs.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                const idx = parseInt(thumb.dataset.index, 10);
                const slideWidth = track.clientWidth;
                track.scrollTo({ left: idx * slideWidth, behavior: 'smooth' });
                updateActive(idx);
            });
        });
    }

    function wirePlansCarousel(container) {
        const grid = container.querySelector('.gym-plans-grid');
        const indicator = container.querySelector('.plan-carousel-indicator');
        if (!grid || !indicator) return;

        const cards = grid.querySelectorAll('.plan-card');
        const dots = indicator.querySelectorAll('.plan-carousel-dot');
        const prevBtn = indicator.querySelector('.plan-nav-arrow.prev');
        const nextBtn = indicator.querySelector('.plan-nav-arrow.next');

        if (cards.length <= 1) return;

        function updateActivePlan(index) {
            dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
        }

        let planScrollTimeout = null;
        grid.addEventListener('scroll', function () {
            clearTimeout(planScrollTimeout);
            planScrollTimeout = setTimeout(function () {
                const gridLeft = grid.scrollLeft;
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : grid.clientWidth;
                const activeIndex = Math.min(cards.length - 1, Math.max(0, Math.round(gridLeft / cardWidth)));
                updateActivePlan(activeIndex);
            }, 40);
        }, { passive: true });

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : 280;
                grid.scrollBy({ left: -cardWidth, behavior: 'smooth' });
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                const cardWidth = cards[0] ? cards[0].offsetWidth + 14 : 280;
                grid.scrollBy({ left: cardWidth, behavior: 'smooth' });
            });
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                const idx = parseInt(dot.dataset.index, 10);
                if (cards[idx]) {
                    cards[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    updateActivePlan(idx);
                }
            });
        });
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

        wireGalleryCarousel(modalBody);
        wirePlansCarousel(modalBody);

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