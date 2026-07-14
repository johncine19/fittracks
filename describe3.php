<?php
require __DIR__ . '/core/bootstrap.php';
$pdo = db();
$stmt = $pdo->query('DESCRIBE trainer_profiles');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
