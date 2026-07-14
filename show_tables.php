<?php
require __DIR__ . '/core/bootstrap.php';
$pdo = db();
print_r($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
