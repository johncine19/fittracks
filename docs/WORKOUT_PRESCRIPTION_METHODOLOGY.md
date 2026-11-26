# FitTracks Workout Prescription Methodology & Sports-Science References

This document explains how FitTracks generates **sets, reps, rest intervals, tempo, RPE, and exercise counts** for member workout plans in [`pages/shared/workouts.php`](../pages/shared/workouts.php) (`generate_workout_plan()`, `_get_workout_parameters()`, and `_assign_exercise_details()`).

---

## 1. Member Profile Inputs Used
When generating a baseline workout plan (for **self-guided** members or when a trainer initiates an auto-populated draft), the rule engine reads 5 profile parameters:

| Input | Source Column | Purpose |
| --- | --- | --- |
| **Primary Goal** | `member_profiles.primary_goal` | Selects rep ranges, rest intervals, tempo, and conditioning style (`strength`, `muscle_gain`, `fat_loss`, `endurance`, `general_health`). |
| **Experience Level** | `member_profiles.experience_level` | Scales exercise `difficulty_level`, number of sets, and target **RPE** (`1 = Beginner`, `2 = Intermediate`, `3 = Advanced`). |
| **Session Duration** | `member_profiles.preferred_duration_mins` | Controls exercises per workout day (`<= 35 mins` → 3–4 exercises, `45–60 mins` → 4–5 exercises, `>= 65 mins` → 5–6 exercises). |
| **Training Schedule** | `member_profiles.preferred_workout_days` / `workout_rules` | Determines weekly split and training days. |
| **Focus Areas** | `member_focus_areas` (`secondary_focus`) | Prioritizes exercises targeting the member's selected muscle groups. |

> **Coached Member Guard:** Automated profile updates (`$coachId === null`) never overwrite an active plan for a member who has an active trainer (`!is_member_self_guided()`).

---

## 2. Resistance Training Prescription Matrix (NSCA / ACSM)

Implemented in `_assign_exercise_details()` ([`pages/shared/workouts.php`](../pages/shared/workouts.php#L296-L465)):

| Goal | Experience Level | Sets | Reps | Rest | Tempo | Target RPE |
| --- | --- | --- | --- | --- | --- | --- |
| **Strength / Power** | Beginner (1) | 3 | 6–8 | 120s | 2-1-1-0 | RPE 7–8 |
| **Strength / Power** | Intermediate (2) | 4 | 5–6 | 150s | 2-1-1-0 | RPE 8 |
| **Strength / Power** | Advanced (3) | 5 | 3–5 | 180s | 2-1-1-0 | RPE 8–9 |
| **Muscle Gain (Hypertrophy)** | Beginner (1) | 3 | 10–12 | 60s | 3-0-1-0 | RPE 7 |
| **Muscle Gain (Hypertrophy)** | Intermediate (2) | 4 | 8–10 | 90s | 3-0-1-0 | RPE 8 |
| **Muscle Gain (Hypertrophy)** | Advanced (3) | 4 | 6–10 | 90s | 3-0-1-1 | RPE 8–9 |
| **Fat Loss / Weight Loss** | Beginner (1) | 3 | 12–15 | 45s | 2-0-2-0 | RPE 6–7 |
| **Fat Loss / Weight Loss** | Intermediate (2) | 4 | 12–15 | 60s | 2-0-2-0 | RPE 7–8 |
| **Fat Loss / Weight Loss** | Advanced (3) | 4 | 10–12 | 60s | 2-0-1-0 | RPE 8 |
| **Endurance** | Beginner (1) | 2 | 15–20 | 30s | 2-0-2-0 | RPE 6–7 |
| **Endurance** | Intermediate (2) | 3 | 15–20 | 30s | 2-0-1-0 | RPE 7–8 |
| **Endurance** | Advanced (3) | 4 | 15–25 | 25s | 2-0-1-0 | RPE 8 |
| **General Health / Maintenance** | Beginner (1) | 3 | 10–12 | 60s | 2-0-2-0 | RPE 6 |

---

## 3. Cardiorespiratory Prescription Matrix (ACSM Stages 1–3)

Implemented in `_assign_exercise_details()` for `category = 'cardio'`:

| Experience Level | Goal | Sets × Duration | Rest | Pacing | Target RPE |
| --- | --- | --- | --- | --- | --- |
| **Beginner (Stage 1)** | Any | 1 × 20–30 mins | — | Continuous Aerobic | RPE 4–5 |
| **Intermediate (Stage 2)** | Fat Loss / Endurance | 3 × 10 mins | 60s | Interval Pace | RPE 7 |
| **Intermediate (Stage 2)** | Other | 1 × 30–45 mins | — | Steady Aerobic | RPE 5–6 |
| **Advanced (Stage 3)** | Fat Loss / Power | 4 × 8 mins | 60s | HIIT Intervals | RPE 8–9 |
| **Advanced (Stage 3)** | Other | 1 × 45–60 mins | — | Sustained Threshold | RPE 6–7 |

---

## 4. Published Sports-Science References

1. **American College of Sports Medicine (ACSM).** *ACSM's Guidelines for Exercise Testing and Prescription* (11th ed.). Wolters Kluwer.
   - Basis for cardiorespiratory intensity progression (Stages 1–3), session duration scaling, and Rating of Perceived Exertion (RPE) bands.
2. **American College of Sports Medicine (ACSM).** (2009). *Position Stand: Progression Models in Resistance Training for Healthy Adults.* *Medicine & Science in Sports & Exercise*, 41(3), 687–708.
   - Basis for novice vs. intermediate/advanced set volume (1–3 sets for novices; higher multi-set volume for experienced lifters) and progressive overload.
3. **Haff, G. G., & Triplett, N. T. (Eds.).** *Essentials of Strength Training and Conditioning* (4th ed.). National Strength and Conditioning Association (NSCA) / Human Kinetics.
   - Basis for goal-specific repetition and rest-interval assignments:
     - **Strength:** ≤ 6–8 reps, 2–5 min rest (`120–180s`)
     - **Hypertrophy:** 6–12 reps, 30–90s rest (`60–90s`)
     - **Muscular Endurance:** ≥ 12–20+ reps, ≤ 30–60s rest (`25–60s`)
4. **Zourdos, M. C., et al. (2016).** *Novel Resistance Training–Specific Rating of Perceived Exertion Scale Measuring Repetitions in Reserve.* *Journal of Strength and Conditioning Research*, 30(1), 267–275.
   - Basis for using **RPE** autoregulation so self-guided members can safely select working weights without performing maximal 1RM testing.
