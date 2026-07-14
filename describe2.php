<?php
require __DIR__ . '/core/bootstrap.php';
$pdo = db();
$stmt = $pdo->query('DESCRIBE memberships');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query('DESCRIBE membership_plans');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query('DESCRIBE trainer_assignments');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query('DESCRIBE users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
