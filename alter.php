<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=fittrack_db", "root", "johncine");
    $pdo->exec("ALTER TABLE walk_in_transactions ADD COLUMN payment_method ENUM('cash', 'gcash') NOT NULL DEFAULT 'cash' AFTER amount_paid");
    echo "Success!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
