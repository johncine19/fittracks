<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$pdo = db();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$columnExists = static function (string $column) use ($pdo, $database): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([$database, 'gym_subscription_payments', $column]);
    return (bool)$stmt->fetchColumn();
};
$indexExists = static function (string $index) use ($pdo, $database): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
    $stmt->execute([$database, 'gym_subscription_payments', $index]);
    return (bool)$stmt->fetchColumn();
};

$pdo->exec("ALTER TABLE gym_subscription_payments MODIFY payment_method ENUM('gcash','card','bank_transfer','cash','online') NOT NULL DEFAULT 'gcash'");
foreach ([
    'xendit_reference_id' => 'VARCHAR(64) NULL',
    'xendit_session_id' => 'VARCHAR(64) NULL',
    'xendit_payment_id' => 'VARCHAR(64) NULL',
] as $column => $definition) {
    if (!$columnExists($column)) {
        $pdo->exec("ALTER TABLE gym_subscription_payments ADD COLUMN `$column` $definition");
    }
}
foreach ([
    'uq_sub_xendit_reference' => 'xendit_reference_id',
    'uq_sub_xendit_session' => 'xendit_session_id',
] as $index => $column) {
    if (!$indexExists($index)) {
        $pdo->exec("ALTER TABLE gym_subscription_payments ADD UNIQUE KEY `$index` (`$column`)");
    }
}

echo "Xendit subscription payment schema is ready.\n";
