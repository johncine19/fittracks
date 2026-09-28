<?php
declare(strict_types=1);

function seed_reference_data_if_empty(): void
{
    $pdo = db();
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            session_id VARCHAR(128) NOT NULL PRIMARY KEY,
            session_data MEDIUMTEXT NOT NULL,
            expires_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            available_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS cache (
            cache_key VARCHAR(255) NOT NULL PRIMARY KEY,
            cache_value LONGTEXT NOT NULL,
            expires_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    if ((int) $pdo->query('SELECT COUNT(*) FROM membership_plans')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO membership_plans (plan_name, plan_type, duration_days, price, description, is_active) VALUES (?, ?, ?, ?, ?, 1)');
        foreach ([
            ['Monthly Starter', 'monthly', 30, 1200, 'Gym access with standard class booking.'],
            ['Quarterly Plus', 'quarterly', 90, 3200, 'Best for consistent members and coaching add-ons.'],
            ['Annual Elite', 'annual', 365, 12000, 'Full-year access with preferred class scheduling.'],
        ] as $plan) {
            $stmt->execute($plan);
        }
    }

    seed_reference_exercises();
    seed_ratings_if_empty();
}

function seed_reference_exercises(): void
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO exercises (name, category, muscle_group, description)
         SELECT ?, ?, ?, ? FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM exercises WHERE name = ?)'
    );
    foreach ([
        ['Squat', 'strength', 'legs', 'Compound lower-body lift.'],
        ['Bench press', 'strength', 'chest', 'Horizontal push movement.'],
        ['Deadlift', 'strength', 'posterior chain', 'Hip hinge strength movement.'],
        ['Lat pulldown', 'strength', 'back', 'Vertical pull movement.'],
        ['Overhead press', 'strength', 'shoulders', 'Vertical push for shoulder strength.'],
        ['Barbell row', 'strength', 'back', 'Horizontal pull for upper back.'],
        ['Leg press', 'strength', 'legs', 'Machine-based quad and glute work.'],
        ['Dumbbell lunges', 'strength', 'legs', 'Unilateral lower-body strength.'],
        ['Bicep curls', 'strength', 'arms', 'Isolation curl for biceps.'],
        ['Tricep pushdown', 'strength', 'arms', 'Cable pushdown for triceps.'],
        ['Lateral raise', 'strength', 'shoulders', 'Isolation work for side delts.'],
        ['Plank', 'core', 'abdominals', 'Anti-extension core hold.'],
        ['Russian twists', 'core', 'obliques', 'Rotational core exercise.'],
        ['Bicycle crunches', 'core', 'abdominals', 'Dynamic core work.'],
        ['Hanging leg raise', 'core', 'abdominals', 'Lower-ab focused core movement.'],
        ['Dead bug', 'core', 'abdominals', 'Core stability with limb coordination.'],
        ['Treadmill intervals', 'cardio', 'full body', 'Alternating work and recovery intervals.'],
        ['Stationary bike', 'cardio', 'legs', 'Low-impact steady or interval cycling.'],
        ['Rowing machine', 'cardio', 'full body', 'Full-body cardio with pull drive.'],
        ['Jump rope', 'cardio', 'full body', 'High-intensity footwork and conditioning.'],
        ['Stair climber', 'cardio', 'legs', 'Continuous stepping cardio.']
    ] as $ex) {
        $stmt->execute([
            $ex[0], $ex[1], $ex[2], $ex[3],
            $ex[0]
        ]);
    }
}

function seed_ratings_if_empty(): void
{
    $pdo = db();
    try {
        // 1. Seed Platform Reviews from Gym Owners if empty
        $platformCount = (int) $pdo->query('SELECT COUNT(*) FROM platform_reviews')->fetchColumn();
        if ($platformCount === 0) {
            $owners = $pdo->query("SELECT u.user_id, g.gym_id, g.name as gym_name FROM users u JOIN gyms g ON g.owner_user_id = u.user_id WHERE u.role = 'gym_owner'")->fetchAll(PDO::FETCH_ASSOC);
            
            $seedPlatformFeedbacks = [
                [
                    'rating' => 5,
                    'system_experience' => 5,
                    'features_rating' => 5,
                    'service_rating' => 5,
                    'review' => "FitTrack transformed our entire facility's check-in flow with dynamic QR codes. Our member retention jumped by 24% after automated expiration notices went live!"
                ],
                [
                    'rating' => 5,
                    'system_experience' => 5,
                    'features_rating' => 4,
                    'service_rating' => 5,
                    'review' => "The trainer commission tracking and class scheduling tools are unmatched. Managing multiple coaches without spreadsheet headaches has saved us 15+ hours weekly."
                ],
                [
                    'rating' => 5,
                    'system_experience' => 4,
                    'features_rating' => 5,
                    'service_rating' => 5,
                    'review' => "FitTrack gave us total visibility over attendance trends. The churn risk alerts helped us re-engage 70% of inactive members before their memberships lapsed."
                ],
                [
                    'rating' => 5,
                    'system_experience' => 5,
                    'features_rating' => 5,
                    'service_rating' => 5,
                    'review' => "Online payment tracking and automated renewal reminders boosted our cash flow predictability significantly. Best gym management platform we have ever used!"
                ]
            ];

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO platform_reviews 
                (user_id, gym_id, rating, review, system_experience, features_rating, service_rating, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))
            ");

            foreach ($owners as $idx => $o) {
                $fb = $seedPlatformFeedbacks[$idx % count($seedPlatformFeedbacks)];
                $stmt->execute([
                    $o['user_id'],
                    $o['gym_id'],
                    $fb['rating'],
                    $fb['review'],
                    $fb['system_experience'],
                    $fb['features_rating'],
                    $fb['service_rating'],
                    ($idx + 1) * 3
                ]);
            }
        }

        // 2. Seed Gym Ratings from Members if empty
        $gymRatingCount = (int) $pdo->query('SELECT COUNT(*) FROM gym_ratings')->fetchColumn();
        if ($gymRatingCount === 0) {
            $gymMembers = $pdo->query("SELECT gm.user_id, gm.gym_id FROM gym_members gm JOIN users u ON u.user_id = gm.user_id WHERE u.role = 'member'")->fetchAll(PDO::FETCH_ASSOC);
            
            $seedMemberReviews = [
                ['rating' => 5, 'review' => "Awesome gym! Clean equipment, friendly staff, and the dynamic QR scanner at the front desk is super convenient."],
                ['rating' => 5, 'review' => "Great atmosphere and modern strength machines. The coaches are very supportive and my workout plans are easy to follow."],
                ['rating' => 4, 'review' => "Very well maintained facility. Peak hours get a bit busy, but equipment availability and cleanliness are always top notch."],
                ['rating' => 5, 'review' => "Clean facilities, great air-conditioning, and high-quality free weights. 10/10 would recommend to anyone in the area!"]
            ];

            $stmtGym = $pdo->prepare("
                INSERT IGNORE INTO gym_ratings 
                (gym_id, user_id, rating, review, created_at)
                VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))
            ");

            foreach ($gymMembers as $idx => $gm) {
                $rev = $seedMemberReviews[$idx % count($seedMemberReviews)];
                $stmtGym->execute([
                    $gm['gym_id'],
                    $gm['user_id'],
                    $rev['rating'],
                    $rev['review'],
                    ($idx + 1) * 4
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('seed_ratings_if_empty error: ' . $e->getMessage());
    }
}

