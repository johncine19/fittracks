<?php
$_GET['page'] = 'trainers';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/bootstrap.php';
require 'pages/member/trainers.php';
$_SESSION['user_id'] = 8; // John Cine (member)
$_SESSION['role'] = 'member';
$_SESSION['first_name'] = 'John';
$_SESSION['last_name'] = 'Cine';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];

ob_start();
try {
    trainers_page();
    ob_end_clean();
    echo "NO ERROR IN trainers.php\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR IN trainers.php: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

$_SESSION['user_id'] = 1; // System Admin
$_SESSION['role'] = 'admin';
ob_start();
try {
    trainer_assignments_page();
    ob_end_clean();
    echo "NO ERROR IN trainer_assignments.php\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR IN trainer_assignments.php: " . $e->getMessage() . "\n";
}

$_SESSION['user_id'] = 3; // John Chris (trainer)
$_SESSION['role'] = 'trainer';
ob_start();
try {
    trainer_members_page();
    ob_end_clean();
    echo "NO ERROR IN trainer.php\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR IN trainer.php: " . $e->getMessage() . "\n";
}
