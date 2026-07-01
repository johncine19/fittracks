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

    if ((int) $pdo->query('SELECT COUNT(*) FROM food_items')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO food_items (name, serving_size, calories, protein_g, carbs_g, fats_g) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Chicken breast with rice', '1 plate', 520, 48, 58, 9],
            ['Tuna salad wrap', '1 wrap', 410, 34, 42, 12],
            ['Greek yogurt bowl', '1 bowl', 330, 28, 38, 7],
            ['Tofu vegetable stir fry', '1 plate', 450, 26, 44, 18],
            ['Oatmeal with banana', '1 bowl', 360, 12, 66, 7],
            ['Egg and spinach toast', '2 slices', 390, 24, 32, 16],
            ['Salmon quinoa bowl', '1 bowl', 560, 38, 46, 24],
            ['Lentil soup', '1 bowl', 310, 19, 46, 6],
            ['Protein smoothie', '1 bottle', 300, 30, 28, 6],
            ['Turkey sandwich', '1 sandwich', 430, 32, 48, 11],
            ['Cottage cheese fruit cup', '1 cup', 240, 24, 22, 5],
            ['Beef and sweet potato', '1 plate', 590, 42, 54, 21],
        ] as $food) {
            $stmt->execute($food);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM exercises')->fetchColumn() === 0) {
        $stmt = $pdo->prepare('INSERT INTO exercises (name, category, muscle_group, description) VALUES (?, ?, ?, ?)');
        foreach ([
            ['Squat', 'strength', 'legs', 'Compound lower-body lift.'],
            ['Bench press', 'strength', 'chest', 'Horizontal push movement.'],
            ['Deadlift', 'strength', 'posterior chain', 'Hip hinge strength movement.'],
            ['Lat pulldown', 'strength', 'back', 'Vertical pull movement.'],
            ['Plank', 'core', 'abdominals', 'Anti-extension core hold.'],
            ['Treadmill intervals', 'cardio', 'full body', 'Alternating work and recovery intervals.'],
        ] as $exercise) {
            $stmt->execute($exercise);
        }
    }
}

