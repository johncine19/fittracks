<?php
require __DIR__ . '/core/bootstrap.php';

try {
    // Modify status ENUM
    db()->exec("ALTER TABLE trainer_assignments MODIFY COLUMN status ENUM('pending_admin','pending_trainer','active','rejected','ended') NOT NULL DEFAULT 'pending_admin'");
    
    // Check if column exists before adding to avoid errors on multiple runs (or just try-catch)
    try {
        db()->exec("ALTER TABLE trainer_assignments ADD COLUMN rejection_reason TEXT DEFAULT NULL");
    } catch (Exception $e) {
        // Ignore if column already exists
    }
    
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
