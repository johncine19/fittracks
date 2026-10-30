<?php
declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

try {
    db()->exec("ALTER TABLE payments MODIFY COLUMN status ENUM('paid','pending','overdue','refunded','cancelled') NOT NULL DEFAULT 'pending'");
    echo "SUCCESS: payments.status altered.\n";

    $col = db()->query("SHOW COLUMNS FROM payments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    echo "Current column definition: " . $col['Type'] . "\n";

    // Also update any pending payments whose membership was already cancelled
    $updated = db()->exec("UPDATE payments p JOIN memberships m ON m.membership_id = p.membership_id SET p.status = 'cancelled' WHERE m.status = 'cancelled' AND p.status = 'pending'");
    echo "Cleaned up orphaned pending payments: " . $updated . "\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
