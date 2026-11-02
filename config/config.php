<?php
declare(strict_types=1);
define('DB_HOST', (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? (getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost')));
define('DB_NAME', (string) ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? (getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'fitrack_db')));
define('DB_USER', (string) ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? (getenv('DB_USER') !== false ? getenv('DB_USER') : 'root')));
define('DB_PASS', (string) ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '')));
