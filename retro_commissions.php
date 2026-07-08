<?php
require __DIR__ . '/core/bootstrap.php';
$assignments = db()->query("SELECT member_user_id FROM trainer_assignments WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($assignments as $a) {
    grant_retroactive_commission((int)$a['member_user_id']);
}
echo 'Retroactive commissions granted.';
