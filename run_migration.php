<?php
require 'core/bootstrap.php';

try {
    $pdo = db();
    
    // Read the SQL file
    $sql = file_get_contents('migration.sql');
    
    // Execute the SQL
    $pdo->exec($sql);
    
    echo "Migration successful.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
