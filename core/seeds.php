<?php
declare(strict_types=1);

function seed_reference_data_if_empty(): void
{
    $pdo = db();
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
        ['Hanging leg raise', 'core', 'abdominals', 'Lower-ab focused core movement.'],
        ['Dead bug', 'core', 'abdominals', 'Core stability with limb coordination.'],
        ['Treadmill intervals', 'cardio', 'full body', 'Alternating work and recovery intervals.'],
        ['Stationary bike', 'cardio', 'legs', 'Low-impact steady or interval cycling.'],
        ['Rowing machine', 'cardio', 'full body', 'Full-body cardio with pull drive.'],
        ['Jump rope', 'cardio', 'full body', 'High-intensity footwork and conditioning.'],
        ['Stair climber', 'cardio', 'legs', 'Continuous stepping cardio.'],
    ] as $exercise) {
        $stmt->execute([$exercise[0], $exercise[1], $exercise[2], $exercise[3], $exercise[0]]);
    }
}

