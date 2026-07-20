<?php
require 'core/database.php';
$pdo = db();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $tables);
