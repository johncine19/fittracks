<?php
require __DIR__ . '/core/bootstrap.php';
$pdo = db();

// Ensure there is at least one approved gym
$gymStmt = $pdo->query("SELECT gym_id FROM gyms WHERE status = 'approved' LIMIT 1");
$gymId = $gymStmt->fetchColumn();

if (!$gymId) {
    // Check for any gym and approve it
    $gymStmt = $pdo->query("SELECT gym_id FROM gyms LIMIT 1");
    $gymId = $gymStmt->fetchColumn();
    
    if ($gymId) {
        $pdo->exec("UPDATE gyms SET status = 'approved' WHERE gym_id = " . (int)$gymId);
    } else {
        // We need an owner
        $userStmt = $pdo->query("SELECT user_id FROM users WHERE role = 'gym_owner' LIMIT 1");
        $ownerId = $userStmt->fetchColumn();
        if (!$ownerId) {
            $pdo->exec("INSERT INTO users (email, password_hash, first_name, last_name, role, status) VALUES ('owner@example.com', 'password', 'Gym', 'Owner', 'gym_owner', 'active')");
            $ownerId = $pdo->lastInsertId();
        }
        $pdo->exec("INSERT INTO gyms (owner_user_id, name, address, status) VALUES ($ownerId, 'Elite Fitness Center', '123 Fit Street', 'approved')");
        $gymId = $pdo->lastInsertId();
    }
}

// Clear existing classes (optional, but let's just add)
// $pdo->exec("TRUNCATE TABLE classes");

$classes = [
    // Fat Loss Classes
    ['Ultimate HIIT Burn', 'High-intensity interval training designed to burn maximum fat and calories in 45 minutes.', 30],
    ['Core & Abs Crusher', 'A specialized core class focusing on the abdominal wall to reveal that six-pack.', 20],
    ['Spin & Sweat Cycle', 'Intense indoor cycling class that guarantees a massive sweat session and calorie burn.', 40],
    ['Zumba Fat Blast', 'Dance your way to fat loss with this high-energy cardiovascular workout.', 50],
    
    // Muscle Gain Classes
    ['Powerlifting 101', 'Learn the mechanics of the big lifts: Squat, Bench, and Deadlift for maximum strength.', 15],
    ['Hypertrophy Bootcamp', 'Targeted muscle isolation exercises designed for optimal muscle growth and bodybuilding.', 25],
    ['CrossFit Power', 'Explosive movements and heavy lifting to build raw power and overall strength.', 20],
    ['Heavy Weights Hour', 'Guided free weight sessions for intermediate and advanced lifters focusing on muscle gain.', 20],
    
    // General Health / Maintenance Classes
    ['Sunrise Yoga Flow', 'Start your day with a gentle stretch and mindfulness practice for overall wellness.', 30],
    ['Mobility & Balance', 'Focus on joint health, flexibility, and stability to move freely and without pain.', 20],
    ['Pilates Core Wellness', 'Low-impact movements for a healthy spine, better posture, and holistic health.', 25],
    ['Restorative Stretch', 'Deep stretching techniques to enhance physical endurance and daily mobility.', 20]
];

foreach ($classes as $c) {
    $stmt = $pdo->prepare("INSERT INTO classes (gym_id, class_name, description, capacity) VALUES (?, ?, ?, ?)");
    $stmt->execute([$gymId, $c[0], $c[1], $c[2]]);
}

echo "Successfully seeded " . count($classes) . " classes into gym ID $gymId.\n";
