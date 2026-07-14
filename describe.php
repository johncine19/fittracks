<?php
require __DIR__ . '/core/bootstrap.php';
$pdo = db();
$stmt = $pdo->query('DESCRIBE classes');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query('DESCRIBE gyms');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
