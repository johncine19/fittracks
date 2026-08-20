<?php
declare(strict_types=1);

function log_macros_page(): void
{
    $user = require_roles(['member']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('diet');
    }
    
    $calories = (int) post('calories');
    $protein = (int) post('protein_g');
    $carbs = (int) post('carbs_g');
    $fat = (int) post('fat_g');
    
    $userId = (int) $user['user_id'];
    
    $pdo = db();
    
    $stmt = $pdo->prepare('INSERT INTO daily_macros (user_id, log_date, calories, protein_g, carbs_g, fat_g) 
                           VALUES (?, CURDATE(), ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE calories = VALUES(calories), protein_g = VALUES(protein_g), carbs_g = VALUES(carbs_g), fat_g = VALUES(fat_g)');
    
    $stmt->execute([$userId, $calories, $protein, $carbs, $fat]);
    
    flash('Macros logged successfully for today!', 'success');
    
    redirect('diet');
}
