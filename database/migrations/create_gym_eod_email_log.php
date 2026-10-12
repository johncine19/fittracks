<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gym_eod_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    gym_id INT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gym_eod_email_date (gym_id, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

echo "Created or verified gym_eod_email_log.\n";
