<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS renewal_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('member', 'gym_owner') NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    notice_type ENUM('reminder', 'expired') NOT NULL,
    reference_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_renewal_notice (recipient_type, target_id, notice_type, reference_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Created or verified renewal_email_log.\n";
