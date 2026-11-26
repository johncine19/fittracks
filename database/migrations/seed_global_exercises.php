<?php
declare(strict_types=1);

/**
 * Idempotent Seeder: Adds missing curated exercises to the global Platform Admin
 * catalog (`gym_id = 0`) with valid `category_id` (1..5), valid `muscle_group_id` (1..9),
 * lowercase `category` values (preserving case-sensitive `cardio`), accurate anatomy,
 * and safe `difficulty_level` scaling (1=Beginner, 2=Intermediate, 3=Advanced).
 *
 * Safe to run multiple times on both local XAMPP MySQL and live TiDB.
 */

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

function seed_global_platform_exercises(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    // Existing global exercise names (lowercase) to guarantee idempotency
    $existing = [];
    $stmtEx = $pdo->query("SELECT LOWER(TRIM(name)) FROM exercises WHERE gym_id = 0");
    foreach ($stmtEx->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $existing[(string)$n] = true;
    }

    // Format: [name, category, category_id, muscle_group, muscle_group_id, equipment, difficulty_level, description]
    // category_id: 1=Strength, 2=Core, 3=Cardio, 4=Functional, 5=Flexibility
    // muscle_group_id: 1=Legs, 2=Chest, 3=Posterior Chain, 4=Back, 5=Abdominals, 6=Full Body, 7=Shoulders, 8=Arms, 9=Obliques
    $catalog = [
        // --- Strength: Chest ---
        ['Dumbbell Bench Press', 'strength', 1, 'chest', 2, 'Dumbbells, Bench', 1, 'Horizontal dumbbell press for chest hypertrophy and unilateral stability.'],
        ['Pushups', 'strength', 1, 'chest', 2, 'Bodyweight', 1, 'Foundational bodyweight horizontal push targeting chest, shoulders, and triceps.'],
        ['Dumbbell Flyes', 'strength', 1, 'chest', 2, 'Dumbbells, Bench', 1, 'Chest isolation movement emphasizing pec stretch and adduction.'],
        ['Incline dumbbell bench press', 'strength', 1, 'chest', 2, 'Dumbbells, Incline Bench', 1, 'Incline horizontal press emphasizing upper pectoral fibers.'],
        ['Low-cable cross-over', 'strength', 1, 'chest', 2, 'Cable Machine', 2, 'Upward cable fly pattern targeting upper chest contraction.'],
        ['Barbell Bench Press - Medium Grip', 'strength', 1, 'chest', 2, 'Barbell, Bench', 2, 'Classic compound barbell press for upper-body pushing strength.'],
        ['Chest dip', 'strength', 1, 'chest', 2, 'Dip Station', 2, 'Forward-lean dip emphasizing lower chest and triceps.'],
        ['Decline Dumbbell Flyes', 'strength', 1, 'chest', 2, 'Dumbbells, Decline Bench', 2, 'Decline fly angle targeting lower pectoral fibers.'],
        ['Bodyweight Flyes', 'strength', 1, 'chest', 2, 'Rings / Sliders', 2, 'Bodyweight suspension fly for chest and anterior core stability.'],
        ['Dumbbell floor press', 'strength', 1, 'chest', 2, 'Dumbbells', 1, 'Floor-limited horizontal press for chest and triceps lockout with shoulder safety.'],
        ['Single-Arm Push-Up', 'strength', 1, 'chest', 2, 'Bodyweight', 3, 'Advanced unilateral bodyweight pressing movement requiring high core stability.'],
        ['Neck Press', 'strength', 1, 'chest', 2, 'Barbell, Bench', 3, 'Advanced high-bar chest press variation; use controlled load and strict shoulder alignment.'],

        // --- Strength: Back / Lats / Middle Back ---
        ['Weighted pull-up', 'strength', 1, 'lats', 4, 'Pull-up Bar, Dip Belt', 3, 'Loaded vertical pull for advanced lat and upper-back strength.'],
        ['T-Bar Row with Handle', 'strength', 1, 'middle back', 4, 'Landmine / T-Bar', 2, 'Heavy supported horizontal row for mid-back thickness.'],
        ['Alternating Front/Behind-Neck Pull-Up', 'strength', 1, 'lats', 4, 'Pull-up Bar', 3, 'Advanced vertical pulling variation requiring high shoulder mobility.'],
        ['Wide-Grip Rear Pull-Up', 'strength', 1, 'lats', 4, 'Pull-up Bar', 3, 'Advanced wide vertical pull emphasizing upper lats and rear delts.'],
        ['Upside-down pull-up', 'strength', 1, 'middle back', 4, 'Pull-up Bar', 3, 'Inverted pulling progression for advanced back and grip strength.'],

        // --- Strength: Shoulders ---
        ['Dumbbell front raise to lateral raise', 'strength', 1, 'shoulders', 7, 'Dumbbells', 1, 'Combined anterior and lateral deltoid raise for balanced shoulder development.'],
        ['Clean and press', 'strength', 1, 'shoulders', 7, 'Barbell / Dumbbells', 3, 'Explosive full-body clean into strict overhead press.'],

        // --- Strength: Biceps, Triceps & Forearms ---
        ['Incline Hammer Curls', 'strength', 1, 'biceps', 8, 'Dumbbells, Incline Bench', 1, 'Neutral-grip incline curl emphasizing long head of biceps and brachialis.'],
        ['Wide-grip barbell curl', 'strength', 1, 'biceps', 8, 'Barbell', 1, 'Wide-grip curl emphasizing the short head of the biceps.'],
        ['EZ-bar spider curl', 'strength', 1, 'biceps', 8, 'EZ-Bar, Incline Bench', 2, 'Strict chest-supported curl maximizing peak biceps contraction.'],
        ['Hammer Curls', 'strength', 1, 'biceps', 8, 'Dumbbells', 1, 'Neutral-grip curl targeting biceps, brachialis, and forearms.'],
        ['EZ-Bar Curl', 'strength', 1, 'biceps', 8, 'EZ-Bar', 1, 'Wrist-friendly barbell curl for overall biceps development.'],
        ['Zottman Curl', 'strength', 1, 'biceps', 8, 'Dumbbells', 2, 'Supinated concentric curl with pronated eccentric lowering for biceps and forearms.'],
        ['Biceps curl to shoulder press', 'strength', 1, 'biceps', 8, 'Dumbbells', 1, 'Compound curl-and-press flow combining biceps and shoulders.'],
        ['Barbell Curl', 'strength', 1, 'biceps', 8, 'Barbell', 1, 'Foundational bilateral barbell biceps curl.'],
        ['Concentration curl', 'strength', 1, 'biceps', 8, 'Dumbbell', 1, 'Seated strict single-arm isolation curl for biceps peak.'],
        ['Flexor incline dumbbell hammer curl', 'strength', 1, 'biceps', 8, 'Dumbbells, Incline Bench', 2, 'Controlled incline hammer curl targeting brachialis and flexors.'],

        ['Close-grip bench press', 'strength', 1, 'triceps', 8, 'Barbell, Bench', 2, 'Compound narrow-grip press primarily targeting triceps strength and mass.'],
        ['Triceps dip', 'strength', 1, 'triceps', 8, 'Dip Station', 2, 'Upright dip emphasizing triceps extension and lockout strength.'],
        ['Decline EZ-bar skullcrusher', 'strength', 1, 'triceps', 8, 'EZ-Bar, Decline Bench', 2, 'Decline elbow-extension movement targeting long head of the triceps.'],
        ['Cable V-bar push-down', 'strength', 1, 'triceps', 8, 'Cable Machine, V-Bar', 1, 'Stable cable isolation pushdown for lateral and medial triceps heads.'],
        ['Weighted bench dip', 'strength', 1, 'triceps', 8, 'Bench, Weight Plate', 2, 'Loaded bench dip for triceps hypertrophy.'],
        ['EZ-Bar Skullcrusher', 'strength', 1, 'triceps', 8, 'EZ-Bar, Bench', 2, 'Lying triceps extension for full triceps development.'],
        ['Reverse Grip Triceps Pushdown', 'strength', 1, 'triceps', 8, 'Cable Machine', 1, 'Underhand cable pushdown targeting the medial head of the triceps.'],
        ['Push-Ups - Close Triceps Position', 'strength', 1, 'triceps', 8, 'Bodyweight', 1, 'Diamond/close-hand push-up emphasizing triceps recruitment.'],
        ['Kneeling cable triceps extension', 'strength', 1, 'triceps', 8, 'Cable Machine, Rope', 1, 'Overhead cable extension maximizing long-head triceps stretch.'],
        ['Single-arm cable triceps extension', 'strength', 1, 'triceps', 8, 'Cable Machine', 1, 'Unilateral cable extension to correct side-to-side arm imbalances.'],

        ['Palms-down wrist curl over bench', 'strength', 1, 'forearms', 8, 'Barbell / Dumbbells', 1, 'Forearm extensor isolation over a bench.'],
        ['Straight-bar wrist roll-up', 'strength', 1, 'forearms', 8, 'Wrist Roller', 1, 'Forearm and grip endurance roller exercise.'],

        // --- Strength: Legs & Posterior Chain ---
        ['Single-Leg Press', 'strength', 1, 'quadriceps', 1, 'Leg Press Machine', 2, 'Unilateral leg press targeting quadriceps and glutes while fixing asymmetries.'],
        ['Narrow-stance squat', 'strength', 1, 'quadriceps', 1, 'Barbell', 2, 'Quad-dominant narrow-stance barbell squat.'],
        ['Barbell forward lunge', 'strength', 1, 'quadriceps', 1, 'Barbell', 2, 'Loaded unilateral lunge for quads, glutes, and balance.'],
        ['Kettlebell Pistol Squat', 'strength', 1, 'quadriceps', 1, 'Kettlebell', 3, 'Advanced single-leg squat demanding strength, balance, and ankle mobility.'],
        ['Deadlift with Bands', 'powerlifting', 1, 'posterior chain', 3, 'Barbell, Resistance Bands', 3, 'Accommodating-resistance deadlift for lockout speed and posterior chain power.'],
        ['Power Snatch', 'olympic weightlifting', 4, 'quadriceps', 1, 'Barbell', 3, 'Explosive Olympic lift developing total-body power and triple extension.'],

        // --- Core (Abdominals & Obliques) ---
        ['Elbow plank', 'core', 2, 'abdominals', 5, 'Bodyweight', 1, 'Isometric anti-extension core hold on forearms.'],
        ['Stomach Vacuum', 'core', 2, 'abdominals', 5, 'Bodyweight', 1, 'Isometric transverse abdominis activation and breath-control drill.'],
        ['Superman', 'core', 2, 'posterior chain', 3, 'Bodyweight', 1, 'Prone spinal-extensor and glute activation exercise for lower-back endurance.'],
        ['Bottoms Up', 'core', 2, 'abdominals', 5, 'Bodyweight', 2, 'Supine reverse hip-raise targeting lower abdominals.'],
        ['Decline reverse crunch', 'core', 2, 'abdominals', 5, 'Decline Bench', 2, 'Decline-bench pelvic curl emphasizing lower rectus abdominis.'],
        ['Cocoons', 'core', 2, 'abdominals', 5, 'Bodyweight', 2, 'Full-range hollow-to-tuck crunch working upper and lower abs together.'],
        ['Suspended ab fall-out', 'core', 2, 'abdominals', 5, 'Suspension Trainer', 2, 'Anti-extension core rollout using suspension straps.'],
        ['Spider crawl', 'core', 2, 'abdominals', 5, 'Bodyweight', 2, 'Dynamic plank-crawl pattern for core stability and shoulder endurance.'],
        ['Landmine twist', 'core', 2, 'obliques', 9, 'Barbell, Landmine', 2, 'Standing rotational core press targeting obliques and anti-rotation stability.'],
        ['Standing cable low-to-high twist', 'core', 2, 'obliques', 9, 'Cable Machine', 2, 'Diagonal cable woodchopper for oblique power and core rotation.'],
        ['Dumbbell V-Sit Cross Jab', 'core', 2, 'obliques', 9, 'Dumbbells', 2, 'Seated V-sit isometric hold with rotational punches.'],
        ['Dumbbell spell caster', 'core', 2, 'obliques', 9, 'Dumbbells', 2, 'Standing rotational dumbbell swing targeting obliques and core control.'],
        ['Windmills', 'core', 2, 'obliques', 9, 'Bodyweight / Kettlebell', 2, 'Overhead hinge rotation targeting obliques, shoulders, and hamstring mobility.'],

        // --- Cardio (STRICTLY lowercase 'cardio') ---
        ['Elliptical trainer', 'cardio', 3, 'legs', 1, 'Elliptical Machine', 1, 'Low-impact aerobic cardio machine for joint-friendly conditioning.'],
        ['Bicycling', 'cardio', 3, 'legs', 1, 'Bicycle / Stationary Bike', 1, 'Continuous lower-body aerobic cycling for endurance and fat loss.'],
        ['Stairmaster', 'cardio', 3, 'legs', 1, 'Stair Climber', 2, 'High-calorie lower-body climbing cardio targeting quads and glutes.'],
        ['Trail Running/Walking', 'cardio', 3, 'legs', 1, 'None / Outdoor', 1, 'Outdoor aerobic walking or running for cardiovascular base building.'],
        ['Treadmill running', 'cardio', 3, 'legs', 1, 'Treadmill', 2, 'Steady-state or tempo treadmill running for aerobic conditioning.'],
        ['Burpee', 'cardio', 3, 'full body', 6, 'Bodyweight', 2, 'High-output full-body bodyweight conditioning movement.'],
        ['Rower', 'cardio', 3, 'full body', 6, 'Rowing Machine', 1, 'Full-body ergometer rowing combining leg drive, back pull, and aerobic conditioning.'],
        ['Jumping rope', 'cardio', 3, 'full body', 6, 'Jump Rope', 2, 'High-cadence aerobic conditioning improving footwork and calorie burn.'],

        // --- Flexibility & Mobility ---
        ['Quadruped Hip Circles', 'flexibility', 5, 'legs', 1, 'Bodyweight', 1, 'Controlled hip-joint mobility circles on hands and knees.'],
        ['Standing Hip Circles', 'flexibility', 5, 'legs', 1, 'Bodyweight', 1, 'Dynamic standing hip warm-up for joint lubrication and range of motion.'],
        ['Groiners', 'flexibility', 5, 'legs', 1, 'Bodyweight', 1, 'Dynamic hip-flexor and adductor mobility stretch from plank position.'],
        ['Side Wrist Pull', 'flexibility', 5, 'shoulders', 7, 'Bodyweight', 1, 'Overhead lateral torso and shoulder stretch.'],
        ['Standing leg swing', 'flexibility', 5, 'posterior chain', 3, 'Bodyweight', 1, 'Dynamic hamstring and hip-flexor warm-up swing.'],
        ['Iliotibial band SMR', 'flexibility', 5, 'legs', 1, 'Foam Roller', 1, 'Self-myofascial release foam rolling along the lateral thigh.'],
        ['Leg-Up Hamstring Stretch', 'flexibility', 5, 'posterior chain', 3, 'Bodyweight', 1, 'Supine static hamstring stretch to improve posterior-chain flexibility.'],

        // --- Strongman / Functional (Advanced) ---
        ['Dumbbell farmer\'s walk', 'strongman', 4, 'forearms', 8, 'Dumbbells', 1, 'Loaded carry building grip strength, upper back, and core bracing.'],
        ['Rickshaw Carry', 'strongman', 4, 'forearms', 8, 'Frame / Trap Bar', 2, 'Heavy loaded carry for grip, traps, and total-body stability.'],
        ['Backward Drag', 'strongman', 4, 'quadriceps', 1, 'Sled', 2, 'Joint-friendly backward sled drag for quad endurance and knee health.'],
        ['Bear crawl sled drag', 'strongman', 4, 'quadriceps', 1, 'Sled, Harness', 3, 'Full-body conditioning sled crawl targeting quads, core, and shoulders.'],
        ['Yoke Walk', 'strongman', 4, 'quadriceps', 1, 'Strongman Yoke', 3, 'Heavy loaded yoke carry for trunk stability and leg drive.'],
        ['Tire flip', 'strongman', 4, 'quadriceps', 1, 'Heavy Tire', 3, 'Explosive full-body strongman power movement.'],
        ['Atlas Stones', 'strongman', 4, 'posterior chain', 3, 'Atlas Stone', 3, 'Advanced strongman stone-loading lift for posterior chain and upper back.'],
        ['Axle Deadlift', 'strongman', 4, 'posterior chain', 3, 'Axle Bar', 3, 'Thick-bar deadlift challenging grip and posterior-chain strength.'],
        ['Car Deadlift', 'strongman', 4, 'quadriceps', 1, 'Deadlift Frame', 3, 'Heavy strongman deadlift variation emphasizing leg drive and lockout.'],
        ['Power Stairs', 'strongman', 4, 'posterior chain', 3, 'Duck Walk Implement', 3, 'Explosive hip-hinge stair loading event for hamstrings and glutes.'],
        ['Circus Bell', 'strongman', 4, 'shoulders', 7, 'Circus Dumbbell', 3, 'Single-arm thick-handle overhead press requiring explosive leg drive.'],
        ['Log Lift', 'strongman', 4, 'shoulders', 7, 'Strongman Log', 3, 'Neutral-grip strongman log clean and overhead press.'],
        ['Axle Clean And Press', 'strongman', 4, 'shoulders', 7, 'Axle Bar', 3, 'Thick-bar clean and overhead press for total-body strength.'],

        // --- Home-Friendly / Bodyweight Essentials (for members without a gym or minimal-equipment days) ---
        ['Bodyweight Squat', 'strength', 1, 'legs', 1, 'Bodyweight', 1, 'Foundational lower-body squat using only bodyweight for quads and glutes.'],
        ['Reverse Lunges', 'strength', 1, 'legs', 1, 'Bodyweight', 1, 'Knee-friendly bodyweight alternating reverse lunge for legs and glutes.'],
        ['Glute Bridge', 'strength', 1, 'posterior chain', 3, 'Bodyweight', 1, 'Floor-based hip extension strengthening glutes and hamstrings.'],
        ['Standing Calf Raises', 'strength', 1, 'legs', 1, 'Bodyweight', 1, 'Bodyweight calf raise for lower-leg strength and ankle stability.'],
        ['Pike Push-ups', 'strength', 1, 'shoulders', 7, 'Bodyweight', 1, 'Vertical bodyweight pressing movement targeting shoulders and triceps.'],
        ['Prone Y-T-W Raises', 'strength', 1, 'back', 4, 'Bodyweight', 1, 'Bodyweight floor upper-back and rear-delt postural strengthening drill.'],
        ['Chair / Bench Tricep Dips', 'strength', 1, 'triceps', 8, 'Bodyweight', 1, 'Bodyweight triceps dip using any sturdy chair or low bench.'],
        ['Mountain Climbers', 'cardio', 3, 'full body', 6, 'Bodyweight', 1, 'Dynamic floor cardio-core conditioning drill requiring zero equipment.'],
        ['Jumping Jacks', 'cardio', 3, 'full body', 6, 'Bodyweight', 1, 'Accessible full-body aerobic warm-up and fat-loss conditioning movement.'],
        ['High Knees', 'cardio', 3, 'full body', 6, 'Bodyweight', 1, 'High-cadence bodyweight cardio drill for conditioning and agility.'],
    ];

    // Backfill blank equipment on legacy gym_id = 0 rows so equipment-aware filtering is accurate
    $backfillEquip = [
        'Squat'               => 'Barbell, Squat Rack',
        'Bench press'         => 'Barbell, Bench',
        'Deadlift'            => 'Barbell',
        'Lat pulldown'        => 'Lat Pulldown',
        'Plank'               => 'Bodyweight',
        'Treadmill intervals' => 'Treadmill',
        'Overhead press'      => 'Barbell / Dumbbells',
        'Barbell row'         => 'Barbell',
        'Leg press'           => 'Leg Press',
        'Dumbbell lunges'     => 'Dumbbells',
        'Bicep curls'         => 'Dumbbells',
        'Tricep pushdown'     => 'Cable Machine',
        'Lateral raise'       => 'Dumbbells',
        'Russian twists'      => 'Bodyweight',
        'Hanging leg raise'   => 'Pull-up Bar',
        'Dead bug'            => 'Bodyweight',
        'Stationary bike'     => 'Stationary Bike',
        'Rowing machine'      => 'Rowing Machine',
        'Jump rope'           => 'Jump Rope',
        'Stair climber'       => 'Stair Climber',
    ];
    $updEq = $pdo->prepare("UPDATE exercises SET equipment = ? WHERE gym_id = 0 AND LOWER(TRIM(name)) = LOWER(?) AND (equipment IS NULL OR TRIM(equipment) = '')");
    foreach ($backfillEquip as $exName => $eqLabel) {
        $updEq->execute([$eqLabel, $exName]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO exercises (gym_id, name, category, category_id, muscle_group, muscle_group_id, equipment, difficulty_level, description)
         VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    $skipped = 0;
    foreach ($catalog as [$name, $cat, $catId, $mg, $mgId, $equip, $diff, $desc]) {
        $key = strtolower(trim($name));
        if (isset($existing[$key])) {
            $skipped++;
            continue;
        }
        $ins->execute([$name, $cat, $catId, $mg, $mgId, $equip, $diff, $desc]);
        $existing[$key] = true;
        $inserted++;
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $res = seed_global_platform_exercises();
    echo "Global exercises seeded: {$res['inserted']} inserted, {$res['skipped']} already existed.\n";
}
