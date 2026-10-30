with open('views/components.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = """<select name="weekly_workout_target" id="weeklyWorkoutTarget_lifestyle_<?= h($context) ?>" class="custom-select-native" onchange="const el = document.getElementById('weeklyWorkoutTarget_<?= h($context) ?>'); if (el) el.value = this.value;">"""
replacement = """<select name="weekly_workout_target" id="weeklyWorkoutTarget_lifestyle_<?= h($context) ?>" class="custom-select-native" onchange="const el = document.getElementById('weeklyWorkoutTarget_<?= h($context) ?>'); if (el) el.value = this.value; const tn = document.getElementById('prefDaysTargetNum_<?= h($context) ?>'); if (tn) tn.textContent = this.value;">"""

if target in content:
    content = content.replace(target, replacement, 1)
    with open('views/components.php', 'w', encoding='utf-8') as f:
        f.write(content)
    print("Updated weekly workout onchange!")
else:
    print("Target not found")

