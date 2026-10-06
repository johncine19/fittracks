<?php
declare(strict_types=1);

/**
 * Rating & Review Service (Gyms & FitTrack Platform)
 */

/**

 * ============================================================================
 * TWO-WAY RATING SYSTEM HELPERS
 * 1. Members -> Gym Rating & Reviews
 * 2. Gym Owners -> FitTrack Platform Rating & Reviews
 * ============================================================================
 */

/**
 * Render visual star rating SVG icons with optional numeric score and count badge.
 */
function render_star_rating(float|int $rating, string|int $size = 'md', bool $showNumber = false, ?int $reviewCount = null, string $class = ''): string
{
    $rounded = round((float) $rating, 1);
    $fullStars = (int) floor($rounded);
    $fraction = $rounded - $fullStars;
    $hasHalf = ($fraction >= 0.25 && $fraction <= 0.75);
    if ($fraction > 0.75) {
        $fullStars++;
        $hasHalf = false;
    }
    $emptyStars = max(0, 5 - $fullStars - ($hasHalf ? 1 : 0));

    if (is_int($size)) {
        $sizePx = $size;
    } elseif (is_numeric($size)) {
        $sizePx = (int) $size;
    } else {
        $sizePx = match ($size) {
            'xs' => 12,
            'sm' => 15,
            'md' => 18,
            'lg' => 24,
            'xl' => 32,
            default => 18,
        };
    }

    $gold = '#fbbf24';
    $emptyColor = 'var(--star-empty, rgba(148, 163, 184, 0.35))';
    $uid = substr(md5((string) mt_rand()), 0, 6);

    $starSvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" fill="' . $gold . '" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    $halfStarSvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><defs><linearGradient id="halfGrad_' . $uid . '"><stop offset="50%" stop-color="' . $gold . '"/><stop offset="50%" stop-color="' . $emptyColor . '"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#halfGrad_' . $uid . ')"/></svg>';
    $emptySvg = '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 24 24" fill="' . $emptyColor . '" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

    $out = '<div class="star-rating-display ' . h($class) . '" style="display:inline-flex;align-items:center;gap:3px;" title="' . number_format($rounded, 1) . ' out of 5 stars">';
    $out .= str_repeat($starSvg, $fullStars);
    if ($hasHalf) {
        $out .= $halfStarSvg;
    }
    $out .= str_repeat($emptySvg, $emptyStars);

    if ($showNumber) {
        $out .= '<strong style="margin-left:6px;font-size:' . ($sizePx >= 20 ? '1.1rem' : '0.9rem') . ';color:#fbbf24;font-weight:700;">' . number_format($rounded, 1) . '</strong>';
    }
    if ($reviewCount !== null) {
        $out .= '<span style="margin-left:4px;font-size:0.82rem;color:var(--muted);font-weight:500;">(' . $reviewCount . ')</span>';
    }
    $out .= '</div>';
    return $out;
}

/**
 * ----------------------------------------------------------------------------
 * 1. MEMBERS -> GYM RATINGS
 * ----------------------------------------------------------------------------
 */

function get_gym_rating_stats(int $gymId): array
{
    $pdo = db();
    try {
        $row = $pdo->prepare('
            SELECT 
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as avg_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star_1
            FROM gym_ratings 
            WHERE gym_id = ?
        ');
        $row->execute([$gymId]);
        $stats = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($stats['total_reviews'] ?? 0);
        $avg = round((float) ($stats['avg_rating'] ?? 0), 1);
        
        $breakdown = [
            5 => (int) ($stats['star_5'] ?? 0),
            4 => (int) ($stats['star_4'] ?? 0),
            3 => (int) ($stats['star_3'] ?? 0),
            2 => (int) ($stats['star_2'] ?? 0),
            1 => (int) ($stats['star_1'] ?? 0),
        ];

        $breakdownPct = [];
        foreach ($breakdown as $stars => $cnt) {
            $breakdownPct[$stars] = $total > 0 ? (int) round(($cnt / $total) * 100) : 0;
        }

        return [
            'total_reviews' => $total,
            'avg_rating' => $avg,
            'breakdown' => $breakdown,
            'breakdown_pct' => $breakdownPct,
        ];
    } catch (Throwable $e) {
        error_log('get_gym_rating_stats error: ' . $e->getMessage());
        return [
            'total_reviews' => 0,
            'avg_rating' => 0.0,
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'breakdown_pct' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        ];
    }
}

function get_gym_reviews(int $gymId, int $limit = 50, ?int $filterStar = null): array
{
    $pdo = db();
    try {
        $sql = '
            SELECT r.*, 
                   u.first_name, u.last_name, u.profile_picture, u.email,
                   EXISTS(SELECT 1 FROM gym_members gm WHERE gm.user_id = r.user_id AND gm.gym_id = r.gym_id) as is_enrolled
            FROM gym_ratings r
            JOIN users u ON u.user_id = r.user_id
            WHERE r.gym_id = ?
        ';
        $params = [$gymId];
        if ($filterStar !== null && $filterStar >= 1 && $filterStar <= 5) {
            $sql .= ' AND r.rating = ? ';
            $params[] = $filterStar;
        }
        $sql .= ' ORDER BY r.updated_at DESC, r.created_at DESC LIMIT ' . (int) $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_gym_reviews error: ' . $e->getMessage());
        return [];
    }
}

function get_user_gym_review(int $userId, int $gymId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM gym_ratings WHERE user_id = ? AND gym_id = ? LIMIT 1');
        $stmt->execute([$userId, $gymId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function can_user_review_gym(int $userId, int $gymId): bool
{
    $pdo = db();
    try {
        // Enrolled member
        $enrolled = (bool) scalar('SELECT 1 FROM gym_members WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($enrolled) return true;

        // Active or past membership plan
        $hasMembership = (bool) scalar('
            SELECT 1 FROM memberships m 
            JOIN membership_plans p ON p.plan_id = m.plan_id 
            WHERE m.user_id = ? AND p.gym_id = ? LIMIT 1
        ', [$userId, $gymId]);
        if ($hasMembership) return true;

        // Checked-in / attendance records
        $hasAttended = (bool) scalar('SELECT 1 FROM attendance WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($hasAttended) return true;

        // Walk-in transactions
        $hasWalkIn = (bool) scalar('SELECT 1 FROM walk_in_transactions WHERE user_id = ? AND gym_id = ? LIMIT 1', [$userId, $gymId]);
        if ($hasWalkIn) return true;

        return false;
    } catch (Throwable) {
        return false;
    }
}

function save_gym_rating(int $userId, int $gymId, int $rating, ?string $review): array
{
    $rating = max(1, min(5, $rating));
    $cleanReview = $review !== null ? mb_substr(trim($review), 0, 3000) : null;
    if ($cleanReview === '') $cleanReview = null;

    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gym_ratings (
            rating_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gym_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            review TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_member_gym_rating (user_id, gym_id),
            INDEX idx_gym_ratings_gym (gym_id),
            INDEX idx_gym_ratings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $pdo->prepare('
            INSERT INTO gym_ratings (gym_id, user_id, rating, review, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                rating = VALUES(rating),
                review = VALUES(review),
                updated_at = NOW()
        ');
        $stmt->execute([$gymId, $userId, $rating, $cleanReview]);

        // Notify Gym Owner
        try {
            $ownerId = (int) scalar('SELECT owner_user_id FROM gyms WHERE gym_id = ?', [$gymId]);
            if ($ownerId > 0 && function_exists('notify_user')) {
                $reviewer = scalar('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE user_id = ?', [$userId]) ?: 'A gym member';
                $starStr = str_repeat('★', $rating);
                notify_user(
                    $ownerId,
                    'system',
                    'New Member Review',
                    "{$reviewer} submitted a {$rating}-star review ({$starStr}) for your gym." . ($cleanReview ? " \"{$cleanReview}\"" : ""),
                    $gymId
                );
            }
        } catch (Throwable) {}

        return ['success' => true, 'message' => 'Your review for this gym has been recorded.'];
    } catch (Throwable $e) {
        error_log('save_gym_rating error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save review: ' . $e->getMessage()];
    }
}

/**
 * ----------------------------------------------------------------------------
 * 2. GYM OWNERS -> FITTRACK PLATFORM RATINGS & FEEDBACK
 * ----------------------------------------------------------------------------
 */

function get_platform_rating_stats(): array
{
    $pdo = db();
    try {
        $row = $pdo->query('
            SELECT 
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as avg_rating,
                COALESCE(AVG(system_experience), 0) as avg_system,
                COALESCE(AVG(features_rating), 0) as avg_features,
                COALESCE(AVG(service_rating), 0) as avg_service,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star_1
            FROM platform_reviews
        ')->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total_reviews'] ?? 0);
        $avg = round((float) ($row['avg_rating'] ?? 0), 1);

        $breakdown = [
            5 => (int) ($row['star_5'] ?? 0),
            4 => (int) ($row['star_4'] ?? 0),
            3 => (int) ($row['star_3'] ?? 0),
            2 => (int) ($row['star_2'] ?? 0),
            1 => (int) ($row['star_1'] ?? 0),
        ];

        return [
            'total_reviews' => $total,
            'avg_rating' => $total > 0 ? $avg : 0.0,
            'avg_system' => $total > 0 ? round((float) ($row['avg_system'] ?? 0), 1) : 0.0,
            'avg_features' => $total > 0 ? round((float) ($row['avg_features'] ?? 0), 1) : 0.0,
            'avg_service' => $total > 0 ? round((float) ($row['avg_service'] ?? 0), 1) : 0.0,
            'breakdown' => $breakdown,
        ];
    } catch (Throwable $e) {
        return [
            'total_reviews' => 0,
            'avg_rating' => 0.0,
            'avg_system' => 0.0,
            'avg_features' => 0.0,
            'avg_service' => 0.0,
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        ];
    }
}

function get_platform_reviews(int $limit = 20): array
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare('
            SELECT pr.*,
                   u.first_name, u.last_name, u.profile_picture, u.email,
                   COALESCE(g.name, (SELECT g2.name FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1), "Commercial Gym Partner") as gym_name,
                   COALESCE(g.logo_url, (SELECT g2.logo_url FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1)) as gym_logo,
                   COALESCE(g.brand_color, (SELECT g2.brand_color FROM gyms g2 WHERE g2.owner_user_id = u.user_id LIMIT 1)) as gym_color
            FROM platform_reviews pr
            JOIN users u ON u.user_id = pr.user_id
            LEFT JOIN gyms g ON g.gym_id = pr.gym_id
            ORDER BY pr.updated_at DESC, pr.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_platform_reviews error: ' . $e->getMessage());
        return [];
    }
}

function get_owner_platform_review(int $userId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM platform_reviews WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function save_platform_review(int $userId, ?int $gymId, int $rating, ?string $review, ?int $systemExp = null, ?int $features = null, ?int $service = null): array
{
    $rating = max(1, min(5, $rating));
    $cleanReview = $review !== null ? mb_substr(trim($review), 0, 3000) : null;
    if ($cleanReview === '') $cleanReview = null;

    if ($systemExp !== null) $systemExp = max(1, min(5, $systemExp));
    if ($features !== null) $features = max(1, min(5, $features));
    if ($service !== null) $service = max(1, min(5, $service));

    if (!$gymId) {
        $gymId = (int) (scalar('SELECT gym_id FROM gyms WHERE owner_user_id = ? LIMIT 1', [$userId]) ?? 0) ?: null;
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO platform_reviews (user_id, gym_id, rating, review, system_experience, features_rating, service_rating, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                gym_id = VALUES(gym_id),
                rating = VALUES(rating),
                review = VALUES(review),
                system_experience = VALUES(system_experience),
                features_rating = VALUES(features_rating),
                service_rating = VALUES(service_rating),
                updated_at = NOW()
        ');
        $stmt->execute([$userId, $gymId, $rating, $cleanReview, $systemExp, $features, $service]);

        if (function_exists('audit_log')) {
            audit_log($userId, 'platform_rating', 'platform', (string) $rating, json_encode([
                'rating' => $rating,
                'system_experience' => $systemExp,
                'features' => $features,
                'service' => $service,
                'review_preview' => mb_substr((string)$cleanReview, 0, 100)
            ]));
        }

        return ['success' => true, 'message' => 'Thank you for your feedback! Your review for FitTrack platform has been saved and will appear on the landing page.'];
    } catch (Throwable $e) {
        error_log('save_platform_review error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save platform review: ' . $e->getMessage()];
    }
}
