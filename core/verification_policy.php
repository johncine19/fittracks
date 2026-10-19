<?php
declare(strict_types=1);

/**
 * FitTrack Government ID Verification Policy Helper
 * Manages accepted Primary & Secondary ID types and verification rules.
 */

/**
 * Fallback list of accepted government ID types with approved classifications.
 */
function get_default_verification_id_types(): array
{
    return [
        ['id' => 1, 'id_code' => 'philsys', 'id_name' => 'Philippine National ID (PhilSys ID)', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 10, 'is_active' => 1],
        ['id' => 2, 'id_code' => 'drivers_license', 'id_name' => "Driver's License", 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 20, 'is_active' => 1],
        ['id' => 3, 'id_code' => 'passport', 'id_name' => 'Passport', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 30, 'is_active' => 1],
        ['id' => 4, 'id_code' => 'umid', 'id_name' => 'UMID (Unified Multi-Purpose ID)', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 40, 'is_active' => 1],
        ['id' => 5, 'id_code' => 'prc', 'id_name' => 'PRC ID', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 50, 'is_active' => 1],
        ['id' => 6, 'id_code' => 'sss', 'id_name' => 'SSS ID', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 60, 'is_active' => 1],
        ['id' => 7, 'id_code' => 'gsis', 'id_name' => 'GSIS ID', 'category' => 'primary', 'requires_supporting_id' => 0, 'display_order' => 70, 'is_active' => 1],
        ['id' => 8, 'id_code' => 'tin', 'id_name' => 'TIN ID', 'category' => 'secondary', 'requires_supporting_id' => 1, 'display_order' => 80, 'is_active' => 1],
        ['id' => 9, 'id_code' => 'postal', 'id_name' => 'Postal ID', 'category' => 'secondary', 'requires_supporting_id' => 1, 'display_order' => 90, 'is_active' => 1],
        ['id' => 10, 'id_code' => 'other', 'id_name' => 'Other Valid Government ID', 'category' => 'secondary', 'requires_supporting_id' => 1, 'display_order' => 100, 'is_active' => 1],
    ];
}

/**
 * Returns all accepted verification ID types from the database.
 * Falls back to default list if table does not exist.
 *
 * @param bool $activeOnly If true, only returns active ID types.
 * @return array<int, array<string, mixed>>
 */
function get_verification_id_types(bool $activeOnly = true): array
{
    static $cache = [];
    $cacheKey = $activeOnly ? 'active' : 'all';

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $pdo = db();
        $sql = "SELECT * FROM gym_verification_id_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, id ASC";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $cache[$cacheKey] = $rows;
            return $rows;
        }
    } catch (\Throwable $e) {
        error_log('get_verification_id_types error: ' . $e->getMessage());
    }

    $fallback = get_default_verification_id_types();
    if ($activeOnly) {
        $fallback = array_values(array_filter($fallback, fn($i) => !empty($i['is_active'])));
    }
    $cache[$cacheKey] = $fallback;
    return $fallback;
}

/**
 * Finds an ID type definition by its name or code.
 */
function get_id_type_by_name(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $all = get_verification_id_types(false);
    foreach ($all as $item) {
        if (strcasecmp($item['id_name'], $name) === 0 || strcasecmp($item['id_code'], $name) === 0) {
            return $item;
        }
    }

    // Handle "Other: Voter's ID" prefix or custom strings
    if (str_starts_with(strtolower($name), 'other')) {
        foreach ($all as $item) {
            if ($item['id_code'] === 'other' || strcasecmp($item['id_name'], 'Other Valid Government ID') === 0) {
                return $item;
            }
        }
    }

    return null;
}

/**
 * Checks if a given ID name or code is classified as a Primary ID.
 */
function is_primary_id(string $idType): bool
{
    $item = get_id_type_by_name($idType);
    if ($item !== null) {
        return ($item['category'] ?? '') === 'primary';
    }
    // Fallback: If not recognized or "Other", treat as secondary
    return false;
}

/**
 * Checks if a given ID requires an additional supporting ID.
 */
function is_secondary_id(string $idType): bool
{
    $item = get_id_type_by_name($idType);
    if ($item !== null) {
        return ($item['category'] ?? '') === 'secondary' || !empty($item['requires_supporting_id']);
    }
    // Default unrecognized or other IDs to requiring supporting ID
    return true;
}

/**
 * Updates an ID type's category, supporting-ID requirement, or active status.
 */
function update_id_type_policy(int $id, string $category, int $isActive = 1, ?int $displayOrder = null): bool
{
    try {
        $pdo = db();
        $requiresSupporting = ($category === 'secondary') ? 1 : 0;
        
        if ($displayOrder !== null) {
            $stmt = $pdo->prepare("UPDATE gym_verification_id_types SET category = ?, requires_supporting_id = ?, is_active = ?, display_order = ? WHERE id = ?");
            return $stmt->execute([$category, $requiresSupporting, $isActive, $displayOrder, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE gym_verification_id_types SET category = ?, requires_supporting_id = ?, is_active = ? WHERE id = ?");
            return $stmt->execute([$category, $requiresSupporting, $isActive, $id]);
        }
    } catch (\Throwable $e) {
        error_log('update_id_type_policy error: ' . $e->getMessage());
        return false;
    }
}
