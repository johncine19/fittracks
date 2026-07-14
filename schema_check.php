<?php
require 'core/bootstrap.php';
$stmt = db()->query('SHOW CREATE TABLE member_profiles');
print_r($stmt->fetch());
