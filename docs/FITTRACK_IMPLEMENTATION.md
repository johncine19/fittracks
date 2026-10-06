# FitTrack — Implementation Plan

Derived from *FitTrack: A Data-Driven Web Application for Gym Operations and Member Engagement Monitoring* (Star Gym, Barangay III Calubian, Tangub City, Misamis Occidental). This translates the Chapter 1–2 specification into a build-ready plan.

## 1. Tech Stack (per Chapter 2.4)

| Layer | Technology |
|---|---|
| Structure | HTML |
| Styling | CSS (responsive) |
| Client interactivity | Vanilla JavaScript (no framework) |
| Database | MySQL |
| Charts/analytics | Chart.js |
| Notifications/dialogs | SweetAlert2 |
| QR generation | QR Code Generator library (client-side) |
| Backend | PHP (implied — pair with your existing FITTRACKS backend patterns: PDO, CSRF tokens, rate limiting, audit logging) |

## 2. User Roles (RBAC)

- **Administrator** — manages accounts, memberships, subscriptions, walk-ins, reports, system settings.
- **Trainer** — manages own schedule, views assigned classes, monitors participant attendance/progress.
- **Member** — books classes, views own attendance/progress/engagement score, scans/uses QR or username check-in.
- **Walk-in customer** — no login; recorded via admin-entered transaction, convertible to a Member account later (should retain historical walk-in records after conversion — design the FK/link so no data is orphaned).

## 3. Core Modules & Implementation Notes

### 3.1 Authentication & RBAC
- Single `users` table with a `role` enum (`admin`, `trainer`, `member`), or role table + join — pick one and keep it consistent with your existing FITTRACKS auth.
- Reuse existing CSRF, rate limiting, and email verification flows already built.

### 3.2 Attendance Monitoring
- **Dynamic QR code**: regenerate/rotate the code per member (e.g., time-boxed token embedded in the QR payload) so a screenshot can't be reused indefinitely. Store the current token + expiry per member; validate token + expiry server-side on scan.
- **Username-based verification**: fallback check-in form (username + maybe password/PIN) for members without QR access. Must hit the same attendance-recording logic as QR scan to avoid duplicate code paths.
- Every check-in writes to an `attendance` table: `member_id`, `method` (`qr`/`username`), `timestamp`, `gym_id`/`branch` if applicable.

### 3.3 Membership & Subscription Monitoring
- `memberships` table: `member_id`, `plan_id`, `start_date`, `end_date`, `status`.
- Scheduled job (cron or on-login check) flags subscriptions expiring within N days → generates an alert record and/or notification.
- Inactive-member detection: define "inactive" (e.g., no attendance in last 14/30 days — make this configurable) and flag automatically.

### 3.4 Class Booking & Trainer Scheduling
- `classes` (trainer_id, schedule, capacity), `class_bookings` (member_id, class_id, status).
- Enforce capacity limits and prevent double-booking at the DB layer (unique constraint on member_id+class_id) not just in JS.
- Trainer view: roster per class session, mark attendance for the session.

### 3.5 Walk-in Transactions
- `walkins` table: name/contact, transaction details, `converted_to_member_id` (nullable FK).
- Conversion flow: create member account, copy relevant walk-in history forward, keep original walk-in row for audit trail.

### 3.6 Fitness Progress Tracking
- `progress_logs`: member_id, date, metrics (weight, measurements, notes) — manually entered, not device-verified (explicitly out of scope per the document).
- Feed this into engagement scoring (see 3.7) and into Chart.js line charts.

### 3.7 Member Engagement Score
Per the document, this is computed from three inputs:
1. Attendance frequency
2. Class participation
3. Fitness progress updates

**Implementation approach:**
- Define a weighted formula, e.g. `score = w1*attendance_rate + w2*class_participation_rate + w3*progress_update_rate`, normalized to 0–100.
- Store weights as config (admin-adjustable) rather than hardcoding — you'll likely need to tune them during testing/evaluation.
- Classification bands (document specifies three tiers): e.g. Highly Engaged ≥ 70, Moderately Engaged 40–69, At-Risk < 40 — set actual thresholds during evaluation with real Star Gym data.
- Recompute on a schedule (nightly job) rather than per page-load, and cache the result per member with a `computed_at` timestamp.

### 3.8 Retention Monitoring & Analytics
- Aggregate views/queries for: attendance trends, engagement distribution, class participation rates, at-risk member lists.
- Render via Chart.js (bar/line/pie/doughnut per the doc) on the admin dashboard.

### 3.9 Reports & Dashboards
- Admin dashboard: attendance stats, membership trends, engagement breakdown, expiring subscriptions, at-risk list.
- Exportable reports (CSV/PDF) are a reasonable stretch goal even though not explicitly required.

## 4. Explicit Out-of-Scope (per Chapter 1.4 — don't build these)
- Accounting, payroll, inventory, procurement, tax computation
- Online payment integration
- Biometric devices, RFID, wearables, other IoT integrations

## 5. Suggested Build Order (maps to SDLC/Agile phases in the doc)

1. **Foundation**: auth, RBAC, base schema, CSRF/rate limiting (likely already done in your current FITTRACKS codebase — confirm reuse vs. rebuild).
2. **Attendance core**: QR generation/rotation + scan validation, username fallback, attendance table + logging.
3. **Membership/subscriptions**: plans, expiration alerts, inactive-member flagging.
4. **Walk-ins**: transaction recording + member conversion flow.
5. **Classes/scheduling**: trainer scheduling, class booking, capacity enforcement.
6. **Progress tracking**: manual entry forms + history views.
7. **Engagement scoring engine**: formula, weights config, classification, scheduled recompute.
8. **Analytics/dashboards**: Chart.js visualizations, retention reports, admin overview.
9. **Testing & evaluation**: functionality, usability, reliability, effectiveness — matches your Chapter 1.3 objectives, useful as your test plan outline too.

## 6. Notes for Your Existing Codebase
- Your current FITTRACKS hardening work (CSRF, rate limiting, email verification, N+1 fixes, audit logging) is infrastructure this feature set sits on top of — no rework needed there.
- The engagement score and attendance modules are the most novel/differentiating parts relative to the reviewed systems (Odoo, SmartFit, FitBoat) per your own synthesis in Chapter 2.3 — prioritize these for your defense/demo.

---

## 7. Operational Enhancements & Automation Architecture

### 7.1 Optical QR Scanner & Kiosk Terminal (`pages/admin/scanner.php`)
- **Modern HUD & Live Counters**: Real-time attendance ticker, live digital clock, and dynamic occupancy badges (`Checked In Today`, `Currently Inside`).
- **Low-Level Camera Engine**: Directly integrates `Html5Qrcode` with custom laser sweep animations, front/rear camera switcher, and hardware flashlight (torch) control.
- **Kiosk Mode**: Fullscreen mode (`toggleKioskMode()`) with responsive layout protection for `.app-frame` to prevent column squishing on widescreen monitors.
- **Walk-In Payment Modal**: Fast 1-tap payment selector (Cash, GCash, Card) and auto-calculated change calculator with high-contrast UI.
- **Sound Toggle Standardization**: Pixel-perfect alignment of `.btn-sound-toggle` and `.sound-toggle-icon` across scanner and equipment modules.

### 7.2 Configurable Walk-in Pricing
- **Schema**: `gyms.walk_in_fee DECIMAL(10,2) NOT NULL DEFAULT 100.00`.
- **Gym Owner Control**: Configurable in **Gym Profile** (`pages/admin/gym_profile.php`).
- **Auto-Detection**: The scanner terminal and walk-in entry forms dynamically detect the gym's configured walk-in fee and pre-fill payment dialogs automatically.

### 7.3 Hierarchical Inactive Member Notifications & Churn Prevention
To balance platform standardization with gym owner autonomy, the system uses a 2-tier hierarchical configuration:

| Layer | Location | Key Settings | Behavior |
|---|---|---|---|
| **Platform Admin** | Platform Settings (`settings.php`) | `at_risk_inactivity_days` (default: 3), `at_risk_notification_cooldown` (default: 14) | Sets global fallback defaults for all gyms on the platform. |
| **Gym Owner** | Gym Profile (`gym_profile.php`) | `auto_inactivity_alerts` (toggle), `inactivity_threshold_days` (override), `inactivity_cooldown_days` (override) | Customizes alert thresholds per gym or disables automated alerts entirely. |

- **Automated Engine Execution (`cron.php` & `core/engagement_engine.php`)**:
  - `cron.php` pushes `process_automated_at_risk_notifications` to the queue worker.
  - The worker checks each gym's `auto_inactivity_alerts`. If toggled OFF (`0`), member alerts for that gym are skipped.
  - Resolves effective days using: `Gym Override ?? Platform Default`.
  - Sends in-app reminder (`"We miss you at the gym!"`) and dispatches notification emails (`Emails::sendInactiveReminder`) respecting cooldown intervals.

### 7.4 TiDB Cloud Migration Scripts
```sql
ALTER TABLE `gyms`
  ADD COLUMN IF NOT EXISTS `walk_in_fee` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  ADD COLUMN IF NOT EXISTS `inactivity_threshold_days` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inactivity_cooldown_days` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `auto_inactivity_alerts` TINYINT(1) NOT NULL DEFAULT 1;
```

### 7.5 Biometric & Nutrition Calculation Engine (`core/helpers.php`)
FitTracks automatically calculates personalized dietary plans from biometric member data:
1. **Basal Metabolic Rate (BMR)** — **Mifflin-St Jeor Equation**:
   - Men: `BMR = (10 × weight_kg) + (6.25 × height_cm) - (5 × age) + 5`
   - Women: `BMR = (10 × weight_kg) + (6.25 × height_cm) - (5 × age) - 161`
2. **Total Daily Energy Expenditure (TDEE)**:
   - `TDEE = BMR × Activity Multiplier`
   - Multipliers: Sedentary (`1.20`), Lightly Active (`1.375`), Moderately Active (`1.55`), Very Active (`1.725`), Extra Active (`1.90`).
3. **Goal-Based Calorie Targets**:
   - Fat Loss: `TDEE - 500 kcal` (safe deficit)
   - Muscle Gain: `TDEE + 300 kcal` (controlled surplus)
   - Maintenance / General Health: `TDEE` (enforced minimum floor: `1,200 kcal`)
4. **Macronutrient Gram Distributions**:
   - Uses `diet_rules` table based on goal and experience level (e.g. 35% Protein / 35% Carbs / 30% Fat).
   - Protein Grams: `(Target Calories × Protein%) ÷ 4 kcal/g`
   - Carbohydrate Grams: `(Target Calories × Carbs%) ÷ 4 kcal/g`
   - Dietary Fat Grams: `(Target Calories × Fat%) ÷ 9 kcal/g`
   - Automatically filters meals matching the member's dietary restrictions (e.g. vegan, halal, keto).

### 7.6 Integrated Nutrition APIs & Equipment Queue API
1. **CalorieNinjas API (`pages/member/food_lookup.php`)**:
   - Natural Language Processing (NLP) nutrition lookup (`https://api.calorieninjas.com/v1/nutrition?query=...`).
   - Converts natural phrases (e.g., *"2 eggs and 1 cup brown rice"*) into exact calories, protein, carbs, fat, fiber, sugar, sodium, and potassium.
   - Configured via `.env` key: `CALORIENINJAS_API_KEY`.
2. **Open Food Facts API (`pages/member/food_lookup.php`)**:
   - Barcode and packaged product lookup mirror.
   - Enables members, trainers, and gym owners to search and 1-click import external items directly into their gym's local food library (`action=import_external_food`).
3. **Real-Time Gym Equipment Queue API (`pages/shared/equipment_api.php`)**:
   - Dynamic real-time polling endpoint (`action=poll`) for equipment queue status, session countdown timers, waitlist position tracking, and audio chime notification triggers.

### 7.7 Member Engagement Score Formula (`core/engagement_engine.php`)
Calculates a weighted engagement score (0–100) reflecting member health and retention:
- **Attendance Rate** (Weight configured via system settings)
- **Class Bookings & Attendance**
- **Weekly Consistency Streak** (Activity across 4 distinct weeks)
- **Completed Daily Workouts** (Workouts on 8+ distinct days)
- **Progress Tracking Updates** (Weigh-ins / body measurement logs)
- **Classification Tiers**:
  - `Highly Engaged`: Score ≥ 75
  - `Moderately Engaged`: Score 40 – 74
  - `At-Risk`: Score < 40 (eligible for automated churn re-engagement)
