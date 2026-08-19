<?php
require __DIR__ . '/core/bootstrap.php';

try {
    $pdo = db();
    
    // Check if column target_weight_kg exists
    $stmt = $pdo->query("SHOW COLUMNS FROM member_profiles LIKE 'target_weight_kg'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE member_profiles ADD COLUMN target_weight_kg DECIMAL(5,2) DEFAULT NULL");
        echo "Added target_weight_kg successfully.\n";
    } else {
        echo "target_weight_kg already exists.\n";
    }

    // Check if column target_body_fat_percent exists
    $stmt = $pdo->query("SHOW COLUMNS FROM member_profiles LIKE 'target_body_fat_percent'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE member_profiles ADD COLUMN target_body_fat_percent DECIMAL(5,2) DEFAULT NULL");
        echo "Added target_body_fat_percent successfully.\n";
    } else {
        echo "target_body_fat_percent already exists.\n";
    }

    // Create jobs table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_class VARCHAR(255) NOT NULL,
        payload JSON NOT NULL,
        attempts INT UNSIGNED DEFAULT 0,
        created_at INT UNSIGNED NOT NULL,
        available_at INT UNSIGNED NOT NULL
    )");
    echo "Jobs table created or already exists.\n";

    // --- 4. Gym Onboarding Columns ---
try {
    $pdo->exec("ALTER TABLE gyms ADD COLUMN permit_url VARCHAR(255) DEFAULT NULL AFTER contact_info");
    echo "Added permit_url column to gyms.<br>";
} catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
        echo "Error adding permit_url: " . $e->getMessage() . "<br>";
    }
}

try {
    $pdo->exec("ALTER TABLE gyms ADD COLUMN id_url VARCHAR(255) DEFAULT NULL AFTER permit_url");
    echo "Added id_url column to gyms.<br>";
} catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
        echo "Error adding id_url: " . $e->getMessage() . "<br>";
    }
}

echo "<h3>Database structure checks completed.</h3>";

    // Custom Exercises Migration
    $stmt = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'gym_id'");
    if ($stmt->rowCount() == 0) {
        // Step 1: Add column as nullable
        $pdo->exec("ALTER TABLE exercises ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER exercise_id");
        
        // Step 2: Update existing rows to the first gym
        $firstGymId = $pdo->query("SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1")->fetchColumn();
        if ($firstGymId) {
            $pdo->prepare("UPDATE exercises SET gym_id = ?")->execute([$firstGymId]);
            echo "Migrated all global exercises to gym_id $firstGymId.\n";
            
            // Step 3: Now make it NOT NULL and add foreign key
            $pdo->exec("ALTER TABLE exercises MODIFY gym_id INT UNSIGNED NOT NULL");
            $pdo->exec("ALTER TABLE exercises ADD CONSTRAINT fk_exercises_gym FOREIGN KEY (gym_id) REFERENCES gyms (gym_id) ON DELETE CASCADE");
        } else {
            echo "No gyms found to migrate exercises to. Leaving gym_id nullable for now.\n";
        }
    } else {
        echo "gym_id column already exists in exercises.\n";
    }

    // Walk-in Transactions Migration
    $stmt = $pdo->query("SHOW COLUMNS FROM walk_in_transactions LIKE 'gym_id'");
    if ($stmt->rowCount() == 0) {
        // Step 1: Add column as nullable
        $pdo->exec("ALTER TABLE walk_in_transactions ADD COLUMN gym_id INT UNSIGNED DEFAULT NULL AFTER transaction_id");
        
        // Step 2: Update existing rows to the first gym
        $firstGymId = $pdo->query("SELECT gym_id FROM gyms ORDER BY gym_id ASC LIMIT 1")->fetchColumn();
        if ($firstGymId) {
            $pdo->prepare("UPDATE walk_in_transactions SET gym_id = ?")->execute([$firstGymId]);
            echo "Migrated all global walk-ins to gym_id $firstGymId.\n";
            
            // Step 3: Now make it NOT NULL and add foreign key
            $pdo->exec("ALTER TABLE walk_in_transactions MODIFY gym_id INT UNSIGNED NOT NULL");
            $pdo->exec("ALTER TABLE walk_in_transactions ADD CONSTRAINT fk_walk_ins_gym FOREIGN KEY (gym_id) REFERENCES gyms (gym_id) ON DELETE CASCADE");
        } else {
            echo "No gyms found to migrate walk-ins to. Leaving gym_id nullable for now.\n";
        }
    } else {
        echo "gym_id column already exists in walk_in_transactions.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
