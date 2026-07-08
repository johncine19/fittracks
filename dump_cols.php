<?php
require 'core/bootstrap.php';
print_r(db()->query("SHOW COLUMNS FROM trainer_assignments")->fetchAll(PDO::FETCH_ASSOC));
