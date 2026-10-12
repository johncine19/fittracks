<?php
declare(strict_types=1);

/**
 * Load the related data for all selectable gyms in a fixed number of queries.
 * Each result is grouped by gym_id so the existing selection-page template can
 * continue to consume one complete gym record at a time.
 */
function load_gym_selection_data(array $gyms): array
{
    $eligibleGyms = array_values(array_filter(
        $gyms,
        static fn(array $gym): bool => gym_subscription_tier($gym) !== 'none'
    ));

    if ($eligibleGyms === []) {
        return [];
    }

    $gymIds = array_map(static fn(array $gym): int => (int) $gym['gym_id'], $eligibleGyms);
    $placeholders = implode(',', array_fill(0, count($gymIds), '?'));
    $pdo = db();

    $classesByGym = [];
    $classesStmt = $pdo->prepare(
        "SELECT * FROM classes WHERE gym_id IN ($placeholders) ORDER BY gym_id ASC, class_name ASC"
    );
    $classesStmt->execute($gymIds);
    foreach ($classesStmt->fetchAll(PDO::FETCH_ASSOC) as $class) {
        $classesByGym[(int) $class['gym_id']][] = $class;
    }

    $plansByGym = [];
    $plansStmt = $pdo->prepare(
        "SELECT * FROM membership_plans WHERE gym_id IN ($placeholders) AND is_active = 1 ORDER BY gym_id ASC, price ASC"
    );
    $plansStmt->execute($gymIds);
    foreach ($plansStmt->fetchAll(PDO::FETCH_ASSOC) as $plan) {
        $plan['features'] = get_membership_plan_features($plan);
        $plan['is_popular'] = !empty($plan['is_popular']);
        $plansByGym[(int) $plan['gym_id']][] = $plan;
    }

    $imagesByGym = [];
    $imagesStmt = $pdo->prepare(
        "SELECT gym_id, image_url
         FROM (
             SELECT gym_id,
                    image_url,
                    ROW_NUMBER() OVER (
                        PARTITION BY gym_id
                        ORDER BY created_at DESC, id DESC
                    ) AS image_rank
             FROM gym_images
             WHERE gym_id IN ($placeholders)
         ) ranked_images
         WHERE image_rank <= 10
         ORDER BY gym_id ASC, image_rank ASC"
    );
    $imagesStmt->execute($gymIds);
    foreach ($imagesStmt->fetchAll(PDO::FETCH_ASSOC) as $image) {
        $imagesByGym[(int) $image['gym_id']][] = $image['image_url'];
    }

    $ratingsByGym = [];
    foreach ($gymIds as $gymId) {
        $ratingsByGym[$gymId] = empty_gym_selection_rating_stats();
    }

    try {
        $ratingsStmt = $pdo->prepare(
            "SELECT gym_id,
                    COUNT(*) AS total_reviews,
                    COALESCE(AVG(rating), 0) AS avg_rating,
                    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS star_5,
                    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS star_4,
                    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS star_3,
                    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS star_2,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS star_1
             FROM gym_ratings
             WHERE gym_id IN ($placeholders)
             GROUP BY gym_id"
        );
        $ratingsStmt->execute($gymIds);
        foreach ($ratingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gymId = (int) $row['gym_id'];
            $total = (int) $row['total_reviews'];
            $breakdown = [
                5 => (int) $row['star_5'],
                4 => (int) $row['star_4'],
                3 => (int) $row['star_3'],
                2 => (int) $row['star_2'],
                1 => (int) $row['star_1'],
            ];
            $breakdownPct = [];
            foreach ($breakdown as $stars => $count) {
                $breakdownPct[$stars] = $total > 0 ? (int) round(($count / $total) * 100) : 0;
            }

            $ratingsByGym[$gymId] = [
                'total_reviews' => $total,
                'avg_rating' => round((float) $row['avg_rating'], 1),
                'breakdown' => $breakdown,
                'breakdown_pct' => $breakdownPct,
            ];
        }
    } catch (Throwable $e) {
        // Keep gym selection available if the optional ratings query fails.
        error_log('Failed to load gym selection rating totals: ' . $e->getMessage());
    }

    $xenditConfigured = (string) app_env('XENDIT_SECRET_KEY') !== '';
    foreach ($eligibleGyms as &$gym) {
        $gymId = (int) $gym['gym_id'];
        $gym['classes'] = $classesByGym[$gymId] ?? [];
        $gym['plans'] = $plansByGym[$gymId] ?? [];
        $gym['images'] = $imagesByGym[$gymId] ?? [];
        $gym['rating_stats'] = $ratingsByGym[$gymId];
        $gym['xendit_enabled'] = $xenditConfigured
            && trim((string) ($gym['xendit_subaccount_id'] ?? '')) !== '';
    }
    unset($gym);

    return $eligibleGyms;
}

function empty_gym_selection_rating_stats(): array
{
    return [
        'total_reviews' => 0,
        'avg_rating' => 0.0,
        'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        'breakdown_pct' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
    ];
}
