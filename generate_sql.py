import re

with open('gym_management.sql', 'r', encoding='utf-8') as f:
    sql = f.read()

# Exact mapping of table to its auto-increment primary key
table_pk_map = {
    'admin_audit_logs': 'log_id',
    'announcements': 'announcement_id',
    'attendance': 'attendance_id',
    'checkout_ratings': 'rating_id',
    'classes': 'class_id',
    'class_bookings': 'booking_id',
    'class_schedules': 'schedule_id',
    'dietary_plans': 'plan_id',
    'dietary_plan_meals': 'meal_id',
    'diet_rules': 'rule_id',
    'email_verifications': 'user_id',
    'exercises': 'exercise_id',
    'exercise_completions': 'completion_id',
    'gyms': 'gym_id',
    'memberships': 'membership_id',
    'membership_plans': 'plan_id',
    'member_profiles': 'profile_id',
    'member_transfers': 'transfer_id',
    'notifications': 'notification_id',
    'password_resets': 'email',
    'payments': 'payment_id',
    'progress_logs': 'log_id',
    'system_settings': 'setting_key',
    'trainer_assignments': 'assignment_id',
    'trainer_commissions': 'commission_id',
    'trainer_messages': 'message_id',
    'trainer_profiles': 'trainer_id',
    'training_plans': 'plan_id',
    'training_plan_exercises': 'plan_exercise_id',
    'users': 'user_id',
    'walk_in_transactions': 'transaction_id',
    'workout_rules': 'rule_id'
}

def transform_create_table(match):
    table_name = match.group(1)
    body = match.group(2)
    engine_part = match.group(3)
    
    pk_col = table_pk_map.get(table_name)
    lines = body.split('\n')
    new_lines = []
    
    for line in lines:
        stripped = line.strip()
        # Look for the primary key column definition
        if pk_col and (f'`{pk_col}`' in line) and (table_name != 'gym_members'):
            # Only add to the first line defining this column
            if 'int' in line:
                line = re.sub(r'int\s+UNSIGNED\s+NOT\s+NULL', 'int UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', line)
            elif 'varchar' in line:
                line = re.sub(r'varchar\(\d+\)\s+NOT\s+NULL', r'\g<0> PRIMARY KEY', line)
        new_lines.append(line)
        
    return f"CREATE TABLE `{table_name}` (\n" + "\n".join(new_lines) + f"\n) ENGINE{engine_part}"

# Match CREATE TABLE blocks
sql = re.sub(r'CREATE TABLE `(\w+)` \(\n(.*?)\n\) ENGINE(.*?;)', transform_create_table, sql, flags=re.DOTALL)

# Gym members composite key
sql = sql.replace(
    'CREATE TABLE IF NOT EXISTS `gym_members` (\n  `user_id` int UNSIGNED NOT NULL,\n  `gym_id` int UNSIGNED NOT NULL,\n  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP\n) ENGINE',
    'CREATE TABLE IF NOT EXISTS `gym_members` (\n  `user_id` int UNSIGNED NOT NULL,\n  `gym_id` int UNSIGNED NOT NULL,\n  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (`user_id`, `gym_id`)\n) ENGINE'
)

# Remove ADD PRIMARY KEY in ALTER TABLE statements
sql = re.sub(r'ALTER TABLE `(\w+)`\s+ADD PRIMARY KEY \([^\)]+\);\n?', '', sql)
sql = re.sub(r'ADD PRIMARY KEY \([^\)]+\),\s*', '', sql)

# Remove AUTO_INCREMENT for dumped tables section
parts = sql.split('-- AUTO_INCREMENT for dumped tables')
if len(parts) > 1:
    header = parts[0]
    after = parts[1]
    constraints_parts = after.split('-- Constraints for table')
    if len(constraints_parts) > 1:
        constraints = '-- Constraints for table' + '-- Constraints for table'.join(constraints_parts[1:])
        sql = header + '\n' + constraints
    else:
        sql = header

# Extra tables
extra_tables = """
CREATE TABLE IF NOT EXISTS `sessions` (
    `session_id` VARCHAR(128) NOT NULL PRIMARY KEY,
    `session_data` MEDIUMTEXT NOT NULL,
    `expires_at` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_class` VARCHAR(255) NOT NULL,
    `payload` TEXT NOT NULL,
    `attempts` INT UNSIGNED DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL,
    `available_at` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cache` (
    `cache_key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `cache_value` LONGTEXT NOT NULL,
    `expires_at` INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

top = """SET FOREIGN_KEY_CHECKS = 0;
DROP DATABASE IF EXISTS gym_management;
CREATE DATABASE gym_management;
USE gym_management;
"""

final_sql = top + sql + "\n" + extra_tables + "\nSET FOREIGN_KEY_CHECKS = 1;\n"

# Clean any empty ALTER TABLE statements
final_sql = re.sub(r'ALTER TABLE `\w+`\s*;\n?', '', final_sql)

with open('gym_management.sql', 'w', encoding='utf-8') as f:
    f.write(final_sql)

with open('tidb_setup.sql', 'w', encoding='utf-8') as f:
    f.write(final_sql)

print('Successfully generated clean, perfect SQL dump!')
