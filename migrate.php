<?php
require __DIR__ . '/core/bootstrap.php';

try {
    $pdo = db();
    $pdo->exec("ALTER TABLE membership_plans ADD commission_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00");
    echo "Added commission_rate to membership_plans.\n";
} catch (PDOException $e) {
    echo "Error altering membership_plans: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trainer_commissions (
            commission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trainer_id INT UNSIGNED NOT NULL,
            payment_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (trainer_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");
    echo "Created trainer_commissions table.\n";
} catch (PDOException $e) {
    echo "Error creating trainer_commissions: " . $e->getMessage() . "\n";
}
