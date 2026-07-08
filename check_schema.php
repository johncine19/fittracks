<?php
require __DIR__ . '/core/bootstrap.php';
$schema = db()->query("SHOW CREATE TABLE trainer_profiles")->fetch();
print_r($schema);
