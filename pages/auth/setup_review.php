<?php
declare(strict_types=1);

function setup_review_page(): void
{
    require_once __DIR__ . '/../shared/workouts.php';
    require_once __DIR__ . '/../shared/exercise.php';
    define('AUTH_PAGE', true);

    $user = current_user();
    if (!$user) {
        redirect('login');
    }

    // Suppress conflicting "Welcome back" alert on onboarding review
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    if ($user['role'] !== 'member') {
        redirect('dashboard');
    }

    $profile = member_profile((int) $user['user_id']);
    if (!$profile) {
        redirect('setup_profile');
    }

    if (empty($profile['primary_goal'])) {
        redirect('setup_goal');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');

        if ($action === 'confirm_setup') {
            // Generate starter workout plan and dietary plan upon final confirmation
            // This ensures plans and notifications are generated exactly once, not on draft changes.
            $existingPlanId = scalar('SELECT plan_id FROM training_plans WHERE member_user_id = ? LIMIT 1', [$user['user_id']]);
            if (!$existingPlanId) {
                generate_workout_plan((int) $user['user_id']);
                generate_dietary_plan((int) $user['user_id']);

                $currentGoal = (string) ($profile['primary_goal'] ?? 'General Fitness');
                $basicGoal = map_detailed_goal_to_basic($currentGoal);
                $tier = (int) ($profile['fitness_tier'] ?? 1);
                $sex = (string) ($profile['biological_sex'] ?? 'male');
                $activity = (string) ($profile['activity_level'] ?? 'sedentary');

                $pdo = db();
                $wRule = $pdo->prepare('SELECT recommended_workout_structure FROM workout_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
                $wRule->execute([$tier, $sex, $basicGoal, $activity]);
                $workoutStruct = $wRule->fetchColumn() ?: 'General full body workout 3 times a week.';

                $dRule = $pdo->prepare('SELECT macro_split, notes FROM diet_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
                $dRule->execute([$tier, $sex, $basicGoal, $activity]);
                $dietInfo = $dRule->fetch();
                $dietStruct = $dietInfo ? ($dietInfo['macro_split'] . ' - ' . $dietInfo['notes']) : 'Balanced diet.';

                $restLabel = (string) ($profile['dietary_restrictions'] ?? 'none');
                $restText = ($restLabel !== '' && $restLabel !== 'none') ? " (Tailored for " . ucwords(str_replace('-', ' ', $restLabel)) . " preferences)" : "";
                $msgBody = "Based on your goal to **" . $currentGoal . "**, your personalized workout and nutrition plans are now active!\n\n**Workout Structure:**\n$workoutStruct\n\n**Diet & Macros$restText:**\n$dietStruct";
                notify_user((int) $user['user_id'], 'system', 'Your Starter Plan is Ready!', $msgBody);
            }

            flash('Setup complete! Your personalized fitness journey is ready.', 'success');
            $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
            $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);
            if (!$hasMembership && !$hasGym) {
                redirect('gym_selection');
            }
            redirect('dashboard');
        }

        if (isset($_POST['height_cm'])) {
            // Sanitize numeric inputs to avoid negative numbers
            $numericReviewFields = ['height_cm', 'weight_kg', 'age', 'neck_cm', 'waist_cm', 'hip_cm', 'weekly_workout_target', 'preferred_duration_mins'];
            foreach ($numericReviewFields as $rf) {
                if (isset($_POST[$rf]) && $_POST[$rf] !== '') {
                    $clean = str_replace(['-', '+'], '', (string)$_POST[$rf]);
                    if (is_numeric($clean)) {
                        $_POST[$rf] = (string) abs((float) $clean);
                    }
                }
            }

            $validator = new Validator();
            $valid = $validator->validate($_POST, [
                'height_cm'               => 'numeric|min_num:100|max_num:250',
                'weight_kg'               => 'numeric|min_num:20|max_num:300',
                'age'                     => 'numeric|min_num:16|max_num:120',
                'neck_cm'                 => 'numeric|min_num:20|max_num:100',
                'waist_cm'                => 'numeric|min_num:30|max_num:200',
                'hip_cm'                  => 'numeric|min_num:30|max_num:200',
                'weekly_workout_target'   => 'numeric|min_num:1|max_num:7',
                'preferred_duration_mins' => 'numeric|min_num:15|max_num:180',
            ]);

            if (!$valid) {
                flash($validator->firstError() ?? 'Invalid values provided. Please check the fields.', 'danger');
                redirect('setup_review');
            }

            $oldGoal = (string) ($profile['primary_goal'] ?? '');
            save_member_profile((int) $user['user_id']);
            $newGoal = (string) post('primary_goal');

            // Only regenerate plans if the user ALREADY completed onboarding (has an existing plan) AND the primary goal actually changed
            $hasExistingPlan = scalar('SELECT 1 FROM training_plans WHERE member_user_id = ? LIMIT 1', [$user['user_id']]);
            if ($hasExistingPlan && !empty($newGoal) && $oldGoal !== $newGoal && can_recalculate_workout((int) $user['user_id'])) {
                generate_workout_plan((int) $user['user_id']);
                generate_dietary_plan((int) $user['user_id']);
                // Deduplicate older starter plan notifications before sending updated one
                try {
                    db()->prepare('DELETE FROM notifications WHERE user_id = ? AND (title LIKE "Starter plan%" OR title LIKE "Your Starter Plan%")')->execute([$user['user_id']]);
                } catch (Throwable) {}
                notify_user((int) $user['user_id'], 'system', 'Starter plan updated', 'Your personalized workout and nutrition plans were updated to reflect your new goal: ' . $newGoal);
                flash('Setup details and workout/diet plans updated!', 'success');
            } else {
                flash('Setup details updated successfully!', 'success');
            }

            redirect('setup_review');
        }
    }

    // Refresh profile in case of updates
    $profile = member_profile((int) $user['user_id']);

    $hasMembership = scalar('SELECT 1 FROM memberships WHERE user_id = ? AND status IN ("active", "pending")', [$user['user_id']]);
    $hasGym = scalar('SELECT 1 FROM gym_members WHERE user_id = ?', [$user['user_id']]);

    // Map goal and fetch recommendation rules
    $goal = (string) ($profile['primary_goal'] ?? '');
    $basicGoal = map_detailed_goal_to_basic($goal);
    $tier = (int) ($profile['fitness_tier'] ?? 1);
    $sex = (string) ($profile['biological_sex'] ?? 'male');
    $activity = (string) ($profile['activity_level'] ?? 'sedentary');

    $pdo = db();
    $wRule = $pdo->prepare('SELECT recommended_workout_structure FROM workout_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
    $wRule->execute([$tier, $sex, $basicGoal, $activity]);
    $workoutStruct = $wRule->fetchColumn();
    if (!$workoutStruct) {
        $wRule->execute([1, 'any', $basicGoal, 'any']);
        $workoutStruct = $wRule->fetchColumn() ?: 'General full body workout 3 times a week.';
    }

    $dRule = $pdo->prepare('SELECT macro_split, notes FROM diet_rules WHERE experience_level = ? AND (biological_sex = ? OR biological_sex = "any") AND primary_goal = ? AND (activity_level = ? OR activity_level = "any") LIMIT 1');
    $dRule->execute([$tier, $sex, $basicGoal, $activity]);
    $dietInfo = $dRule->fetch();
    if (!$dietInfo) {
        $dRule->execute([1, 'any', $basicGoal, 'any']);
        $dietInfo = $dRule->fetch();
    }
    $dietStruct = $dietInfo ? ($dietInfo['macro_split'] . ' - ' . $dietInfo['notes']) : 'Balanced diet.';

    // Biometrics calculation
    $hM = ((float)($profile['height_cm'] ?? 0)) / 100.0;
    $wKg = (float)($profile['weight_kg'] ?? 0);
    $bmi = ($hM > 0 && $wKg > 0) ? round($wKg / ($hM * $hM), 1) : null;
    $bmiCategory = 'Normal';
    $bmiBadgeColor = '#84cc16';
    if ($bmi !== null) {
        if ($bmi < 18.5) {
            $bmiCategory = 'Underweight';
            $bmiBadgeColor = '#38bdf8';
        } elseif ($bmi < 25.0) {
            $bmiCategory = 'Normal';
            $bmiBadgeColor = '#84cc16';
        } elseif ($bmi < 30.0) {
            $bmiCategory = 'Overweight';
            $bmiBadgeColor = '#f59e0b';
        } else {
            $bmiCategory = 'Obese';
            $bmiBadgeColor = '#ef4444';
        }
    }

    // Navy Body Fat % estimate
    $userNeck = (float)($profile['neck_cm'] ?? 0);
    $userWaist = (float)($profile['waist_cm'] ?? 0);
    $userHip = (float)($profile['hip_cm'] ?? 0);
    $userHeight = (float)($profile['height_cm'] ?? 0);
    $bfEst = null;
    if ($userHeight > 0 && $userNeck > 0 && $userWaist > 0) {
        if ($sex !== 'female' && $userWaist > $userNeck) {
            $bfCalc = 495 / (1.0324 - 0.19077 * log10($userWaist - $userNeck) + 0.15456 * log10($userHeight)) - 450;
            if ($bfCalc >= 3 && $bfCalc <= 65) $bfEst = round($bfCalc, 1);
        } elseif ($sex === 'female' && $userHip > 0 && ($userWaist + $userHip) > $userNeck) {
            $bfCalc = 495 / (1.29579 - 0.35004 * log10($userWaist + $userHip - $userNeck) + 0.22100 * log10($userHeight)) - 450;
            if ($bfCalc >= 3 && $bfCalc <= 65) $bfEst = round($bfCalc, 1);
        }
    }

    $daysPerWeek = max(1, min(7, (int)($profile['weekly_workout_target'] ?? 3)));
    $durationMins = max(15, min(180, (int)($profile['preferred_duration_mins'] ?? 45)));
    $expLabel = match ($tier) {
        3, 4 => 'Intermediate (1–2 yrs)',
        5 => 'Advanced (3+ yrs)',
        default => 'Starter / Beginner',
    };
    $dietRest = (string)($profile['dietary_restrictions'] ?? 'none');

    render_header('Review Your Setup', null);
?>
    <style>
        body:has(.review-viewport) {
            margin: 0 !important;
            padding: 0 !important;
            background-color: #090d14 !important;
            overflow-x: hidden !important;
        }

        .review-viewport {
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            background: #090d14;
            background-image: 
                radial-gradient(ellipse at 50% 0%, rgba(132, 204, 22, 0.12) 0%, transparent 60%),
                linear-gradient(180deg, rgba(9, 13, 20, 0.88) 0%, rgba(7, 10, 15, 0.96) 100%),
                url('assets/images/loginback.png?v=3');
            background-size: cover;
            background-position: center center;
            background-attachment: fixed;
            padding: 40px 24px 60px;
            box-sizing: border-box;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .review-container {
            width: 100%;
            max-width: 1360px;
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 25px 65px rgba(0, 0, 0, 0.45), 0 4px 20px rgba(0, 0, 0, 0.15);
            padding: 34px 34px 28px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 22px;
            animation: reviewFadeUp 0.35s ease-out;
            color: #0f172a;
        }

        @keyframes reviewFadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Top Header & Stepper */
        .review-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .review-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .review-brand-icon {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .review-brand-text {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .review-brand-name {
            font-size: 19px;
            font-weight: 900;
            letter-spacing: 0.5px;
            color: #0f172a;
        }

        .review-brand-name span {
            color: #84cc16;
        }

        .review-brand-badge {
            font-size: 11px;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            padding: 3px 9px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            letter-spacing: 0.03em;
        }

        .review-stepper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .review-stepper-title {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #65a30d;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .review-stepper-bars {
            display: flex;
            gap: 5px;
        }

        .review-stepper-bar {
            width: 26px;
            height: 6px;
            border-radius: 3px;
            background: #84cc16;
            box-shadow: 0 0 8px rgba(132, 204, 22, 0.4);
        }

        .review-title-banner {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .review-title-text h1 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        .review-title-text p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }

        /* 3-Column Horizontal Grid */
        .review-horizontal-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            align-items: stretch;
        }

        .review-section-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 18px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            transition: all 0.22s ease;
            box-sizing: border-box;
            position: relative;
        }

        .review-section-card:hover {
            border-color: #cbd5e1;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
        }

        .review-section-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
            gap: 10px;
        }

        .review-section-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            line-height: 1.25;
        }

        .review-section-desc {
            font-size: 12.5px;
            color: #64748b;
            margin: 3px 0 0 0;
            line-height: 1.35;
        }

        .review-edit-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .review-edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .review-edit-btn:hover {
            background: #84cc16;
            color: #090b10;
            border-color: #84cc16;
            box-shadow: 0 2px 6px rgba(132, 204, 22, 0.25);
        }

        /* Card 1: Goal Specifics */
        .review-goal-callout {
            background: rgba(132, 204, 22, 0.08);
            border: 1.5px solid rgba(132, 204, 22, 0.35);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .review-goal-name {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
        }

        .review-goal-tag {
            background: #84cc16;
            color: #090b10;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .review-plan-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .review-plan-row {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 12.5px;
            line-height: 1.45;
        }

        .review-plan-title {
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.04em;
        }

        .review-plan-content {
            color: #475569;
        }

        /* Card 2 & 3: Stat Grids */
        .review-grid-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 10px;
        }

        .review-stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .review-stat-label {
            font-size: 10.5px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .review-stat-value {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: baseline;
            gap: 3px;
        }

        .review-stat-value small {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }

        .review-highlight-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 14px;
            font-size: 12px;
            color: #475569;
            line-height: 1.45;
        }

        /* Bottom Action Bar */
        .review-actions-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            margin-top: 4px;
        }

        .btn-confirm-all {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #84cc16;
            color: #090b10;
            font-size: 14.5px;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            padding: 13px 28px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 16px rgba(132, 204, 22, 0.35);
            text-decoration: none;
        }

        .btn-confirm-all:hover {
            background: #a3e635;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(132, 204, 22, 0.45);
        }

        .btn-back-setup {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 9px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            transition: all 0.18s ease;
        }

        .btn-back-setup:hover {
            color: #0f172a;
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        /* Modal Dialog Styling */
        #reviewEditModal:not([open]) {
            display: none !important;
        }
        #reviewEditModal[open] {
            display: flex !important;
            flex-direction: column;
            width: min(92vw, 580px) !important;
            max-width: 580px !important;
            max-height: min(88vh, 88dvh) !important;
            overflow: hidden !important;
            box-sizing: border-box;
            margin: auto;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.6);
        }
        #reviewEditModal .modal-header {
            flex-shrink: 0;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #reviewEditModal .modal-header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
        }
        #reviewEditModal .modal-close {
            background: transparent;
            border: none;
            color: var(--muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #reviewEditModal .modal-close:hover {
            color: var(--ink);
            background: var(--panel-soft);
        }
        #reviewEditModal .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            -webkit-overflow-scrolling: touch !important;
            overscroll-behavior: contain !important;
            padding: 0 20px !important;
            display: block !important;
        }
        #reviewEditModal .pm-footer {
            position: sticky;
            bottom: 0;
            background: var(--panel);
            border-top: 1px solid var(--line);
            margin-top: auto;
            margin-left: -20px;
            margin-right: -20px;
            padding: 12px 20px 14px 20px;
            z-index: 30;
            box-shadow: 0 -8px 20px rgba(0, 0, 0, 0.35);
        }

        /* Responsive Breakpoints */
        @media (max-width: 1100px) {
            .review-horizontal-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .review-section-card.card-goal {
                grid-column: span 2;
            }
        }

        @media (max-width: 768px) {
            .review-viewport {
                padding: 16px 12px 32px;
            }
            .review-container {
                padding: 20px 16px;
                border-radius: 18px;
                gap: 18px;
            }
            .review-header-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .review-stepper {
                width: 100%;
                justify-content: space-between;
            }
            .review-title-text h1 {
                font-size: 21px;
            }
            .review-title-text p {
                font-size: 13px;
            }
            .review-horizontal-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .review-section-card.card-goal {
                grid-column: span 1;
            }
            .review-grid-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .review-actions-bar {
                flex-direction: column-reverse;
                gap: 10px;
            }
            .btn-confirm-all,
            .btn-back-setup {
                width: 100%;
                justify-content: center;
                box-sizing: border-box;
            }
        }

        @media (max-width: 480px) {
            .review-section-card {
                padding: 16px 14px;
            }
            .review-section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .review-edit-actions {
                width: 100%;
                justify-content: flex-end;
            }
            .review-goal-callout {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .review-goal-name {
                font-size: 15px;
            }
            .review-grid-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            .review-stat-box {
                padding: 8px 10px;
            }
        }
    </style>

    <div class="review-viewport">
        <div class="review-container">
            <!-- Header Bar with FitTrack Brand & Stepper -->
            <div class="review-header-bar">
                <div class="review-brand">
                    <div class="review-brand-icon">
                        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                            <path d="M6 8 L32 8 L29 14 L15 14 L13 18 L26 18 L23 24 L10 24 L5 34 L1 34 L6 8 Z" fill="#84cc16" />
                            <polygon points="12,5 36,5 34,9 10,9" fill="#a3e635" opacity="0.8" />
                            <polygon points="2,32 10,32 8,36 0,36" fill="#65a30d" />
                        </svg>
                    </div>
                    <div class="review-brand-text">
                        <div class="review-brand-name">FIT<span>TRACK</span></div>
                        <span class="review-brand-badge">Setup Finalization</span>
                    </div>
                </div>

                <!-- Stepper Header -->
                <div class="review-stepper">
                    <span class="review-stepper-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        <span>Step 3 of 3: Summary &amp; Review</span>
                    </span>
                    <div class="review-stepper-bars">
                        <div class="review-stepper-bar"></div>
                        <div class="review-stepper-bar"></div>
                        <div class="review-stepper-bar"></div>
                    </div>
                </div>
            </div>

            <!-- Title & Subtitle -->
            <div class="review-title-banner">
                <div class="review-title-text">
                    <h1>Setup Summary &amp; Review</h1>
                    <p>Review and fine-tune your answers before launching your personalized starter plan</p>
                </div>
            </div>

            <!-- HORIZONTAL 3-COLUMN CARDS GRID -->
            <div class="review-horizontal-grid">
                <!-- CARD 1: PRIMARY GOAL & GENERATED STARTER PLAN -->
                <div class="review-section-card card-goal">
                    <div>
                        <div class="review-section-header">
                            <div>
                                <h3 class="review-section-title">Primary Fitness Goal</h3>
                                <p class="review-section-desc">Target workout split &amp; nutrition plan</p>
                            </div>
                            <div class="review-edit-actions">
                                <a href="index.php?page=setup_goal&edit=1" class="review-edit-btn" title="Browse all categories in full-screen view">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                                    Browse
                                </a>
                                <button type="button" class="review-edit-btn" onclick="openReviewEdit('goal')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    Edit
                                </button>
                            </div>
                        </div>

                        <div class="review-goal-callout">
                            <div>
                                <div class="review-goal-name"><?= h(str_contains($goal, '_') ? ucwords(str_replace('_', ' ', $goal)) : $goal) ?></div>
                                <span style="font-size: 12px; color: #64748b;">Classification: <strong><?= h(ucwords(str_replace('_', ' ', $basicGoal))) ?></strong></span>
                            </div>
                            <span class="review-goal-tag">Active Goal</span>
                        </div>

                        <div class="review-plan-preview">
                            <div class="review-plan-row">
                                <span class="review-plan-title">Workout Structure:</span>
                                <span class="review-plan-content"><?= h($workoutStruct) ?></span>
                            </div>
                            <div class="review-plan-row">
                                <span class="review-plan-title">Diet &amp; Macro Split:</span>
                                <span class="review-plan-content">
                                    <?= h($dietStruct) ?>
                                    <?php if ($dietRest !== 'none' && $dietRest !== ''): ?>
                                        <span style="display: block; font-size: 11.5px; color: #65a30d; margin-top: 3px; font-weight: 600;">
                                            &bull; Calibrated for <?= h(ucwords(str_replace('-', ' ', $dietRest))) ?> preference
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="review-highlight-summary" style="margin-top: 14px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#65a30d" stroke-width="2.5" style="vertical-align: -2px; margin-right: 4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span>Starter training program is automatically configured to this path.</span>
                    </div>
                </div>

                <!-- CARD 2: BODY MEASUREMENTS & BIOMETRICS -->
                <div class="review-section-card card-body">
                    <div>
                        <div class="review-section-header">
                            <div>
                                <h3 class="review-section-title">Body Measurements</h3>
                                <p class="review-section-desc">Key metrics for caloric baseline &amp; progress</p>
                            </div>
                            <div class="review-edit-actions">
                                <button type="button" class="review-edit-btn" onclick="openReviewEdit('body')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    Edit
                                </button>
                            </div>
                        </div>

                        <div class="review-grid-stats">
                            <div class="review-stat-box">
                                <span class="review-stat-label">Height</span>
                                <span class="review-stat-value"><?= h((string)($profile['height_cm'] ?? '—')) ?> <small>cm</small></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Weight</span>
                                <span class="review-stat-value"><?= h((string)($profile['weight_kg'] ?? '—')) ?> <small>kg</small></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Age</span>
                                <span class="review-stat-value"><?= h((string)($profile['age'] ?? '—')) ?> <small>yrs</small></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Sex</span>
                                <span class="review-stat-value"><?= h(ucwords((string)($profile['biological_sex'] ?? '—'))) ?></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Neck</span>
                                <span class="review-stat-value"><?= !empty($profile['neck_cm']) ? h((string)$profile['neck_cm']) . ' <small>cm</small>' : '—' ?></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Waist</span>
                                <span class="review-stat-value"><?= !empty($profile['waist_cm']) ? h((string)$profile['waist_cm']) . ' <small>cm</small>' : '—' ?></span>
                            </div>
                            <?php if ($sex === 'female'): ?>
                                <div class="review-stat-box">
                                    <span class="review-stat-label">Hip</span>
                                    <span class="review-stat-value"><?= !empty($profile['hip_cm']) ? h((string)$profile['hip_cm']) . ' <small>cm</small>' : '—' ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($profile['chest_cm'])): ?>
                                <div class="review-stat-box">
                                    <span class="review-stat-label">Chest</span>
                                    <span class="review-stat-value"><?= h((string)$profile['chest_cm']) ?> <small>cm</small></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($profile['arm_cm'])): ?>
                                <div class="review-stat-box">
                                    <span class="review-stat-label">Arm</span>
                                    <span class="review-stat-value"><?= h((string)$profile['arm_cm']) ?> <small>cm</small></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: <?= ($bmi !== null && $bfEst !== null) ? 'repeat(2, 1fr)' : '1fr' ?>; gap: 8px; margin-top: 14px;">
                        <?php if ($bmi !== null): ?>
                            <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.4); background: rgba(132, 204, 22, 0.06);">
                                <span class="review-stat-label">BMI</span>
                                <span class="review-stat-value" style="color: <?= $bmiBadgeColor ?>;">
                                    <?= $bmi ?> <small style="color: <?= $bmiBadgeColor ?>; font-weight:700;">(<?= $bmiCategory ?>)</small>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($bfEst !== null): ?>
                            <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.4); background: rgba(132, 204, 22, 0.06);">
                                <span class="review-stat-label">Est. Body Fat</span>
                                <span class="review-stat-value" style="color: #65a30d;">
                                    <?= $bfEst ?> <small>% (Navy)</small>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php 
                        $hasTargets = !empty($profile['target_weight_kg']) || 
                                      !empty($profile['target_body_fat_percent']) || 
                                      !empty($profile['target_waist_cm']) || 
                                      (!empty($profile['target_strength_max_kg']) && !empty($profile['target_exercise'])) || 
                                      (!empty($profile['endurance_activity']) && (!empty($profile['target_endurance_distance_km']) || !empty($profile['target_endurance_time_mins'])));
                    ?>
                    <?php if ($hasTargets): ?>
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b;">Supporting Targets</span>
                                <button type="button" class="review-edit-btn" onclick="openReviewEdit('targets')" style="font-size: 11px; padding: 2px 8px; height: auto;">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    Edit Targets
                                </button>
                            </div>
                            <div class="review-grid-stats" style="grid-template-columns: repeat(2, 1fr);">
                                <?php if (!empty($profile['target_weight_kg'])): ?>
                                    <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.3); background: #fdfdfd;">
                                        <span class="review-stat-label">Target Weight</span>
                                        <span class="review-stat-value" style="color: #65a30d;"><?= h((string)$profile['target_weight_kg']) ?> <small>kg</small></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($profile['target_body_fat_percent'])): ?>
                                    <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.3); background: #fdfdfd;">
                                        <span class="review-stat-label">Target Body Fat</span>
                                        <span class="review-stat-value" style="color: #65a30d;"><?= h((string)$profile['target_body_fat_percent']) ?> <small>%</small></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($profile['target_waist_cm'])): ?>
                                    <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.3); background: #fdfdfd;">
                                        <span class="review-stat-label">Target Waist</span>
                                        <span class="review-stat-value" style="color: #65a30d;"><?= h((string)$profile['target_waist_cm']) ?> <small>cm</small></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($profile['target_strength_max_kg'])): ?>
                                    <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.3); background: #fdfdfd;">
                                        <span class="review-stat-label"><?= h(ucwords((string)($profile['target_exercise'] ?? 'PR Target'))) ?></span>
                                        <span class="review-stat-value" style="color: #65a30d;"><?= h((string)$profile['target_strength_max_kg']) ?> <small>kg</small></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($profile['endurance_activity'])): ?>
                                    <div class="review-stat-box" style="border-color: rgba(132, 204, 22, 0.3); background: #fdfdfd; grid-column: span 2;">
                                        <span class="review-stat-label"><?= h(ucwords((string)$profile['endurance_activity'])) ?></span>
                                        <span class="review-stat-value" style="color: #65a30d; font-size: 13px;">
                                            <?= !empty($profile['target_endurance_distance_km']) ? h((string)$profile['target_endurance_distance_km']) . ' km' : '' ?>
                                            <?= (!empty($profile['target_endurance_distance_km']) && !empty($profile['target_endurance_time_mins'])) ? ' in ' : '' ?>
                                            <?= !empty($profile['target_endurance_time_mins']) ? h((string)$profile['target_endurance_time_mins']) . ' mins' : '' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- CARD 3: SCHEDULE & LIFESTYLE -->
                <div class="review-section-card card-lifestyle">
                    <div>
                        <div class="review-section-header">
                            <div>
                                <h3 class="review-section-title">Schedule &amp; Lifestyle</h3>
                                <p class="review-section-desc">Training frequency, duration &amp; habit baseline</p>
                            </div>
                            <div class="review-edit-actions">
                                <button type="button" class="review-edit-btn" onclick="openReviewEdit('lifestyle')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    Edit
                                </button>
                            </div>
                        </div>

                        <div class="review-grid-stats">
                            <div class="review-stat-box">
                                <span class="review-stat-label">Experience</span>
                                <span class="review-stat-value" style="font-size: 13.5px;"><?= h($expLabel) ?></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Activity Level</span>
                                <span class="review-stat-value" style="font-size: 13.5px;"><?= h(ucwords(str_replace('_', ' ', $activity))) ?></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Workouts / Wk</span>
                                <span class="review-stat-value"><?= $daysPerWeek ?> <small>days</small></span>
                            </div>
                            <div class="review-stat-box">
                                <span class="review-stat-label">Session Length</span>
                                <span class="review-stat-value"><?= $durationMins ?> <small>mins</small></span>
                            </div>
                            <div class="review-stat-box" style="grid-column: span 2;">
                                <span class="review-stat-label">Dietary Preference</span>
                                <span class="review-stat-value" style="font-size: 13.5px;"><?= h(ucwords(str_replace('-', ' ', $dietRest))) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="review-highlight-summary" style="margin-top: 14px;">
                        <strong><?= $daysPerWeek ?> training days</strong> &bull; <strong><?= 7 - $daysPerWeek ?> recovery days</strong> per week (~<?= round(($daysPerWeek * $durationMins) / 60, 1) ?> hrs weekly volume).
                    </div>
                </div>
            </div>

            <!-- Bottom Action Confirmation -->
            <div class="review-actions-bar">
                <a href="index.php?page=setup_goal&edit=1" class="btn-back-setup">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                    <span>Back to Goals</span>
                </a>

                <form method="post" action="index.php?page=setup_review" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_setup">
                    <button type="submit" class="btn-confirm-all">
                        <span><?= ($hasMembership || $hasGym) ? 'Confirm &amp; Continue to Dashboard' : 'Confirm &amp; Choose Gym' ?></span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Edit Modal -->
    <dialog id="reviewEditModal" class="modal" onclick="if (event.target === this) this.close();">
        <div class="modal-header">
            <h3>Edit Profile & Goals</h3>
            <button class="modal-close" onclick="this.closest('dialog').close()" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
        </div>
        <div class="modal-body">
            <?php render_member_form('setup_review', $user, $profile); ?>
        </div>
    </dialog>

    <script>
    function openReviewEdit(tabName) {
        const dialog = document.getElementById('reviewEditModal');
        if (dialog) {
            dialog.showModal();
            if (typeof switchProfileTab_setup_review === 'function' && tabName) {
                switchProfileTab_setup_review(tabName);
            }
        }
    }
    </script>
<?php
    render_footer();
}
