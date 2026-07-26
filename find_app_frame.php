<?php
$lines = file('assets/app.css');
foreach ($lines as $i => $line) {
    if (strpos($line, '.app-frame') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
?>
