# FITTRACK Gym Management System

Simple PHP 8.2 + MySQL implementation based on `gym-management-system.md` and the provided `fitracks.sql` schema.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Create/import the database from `fitracks.sql` into MySQL as `fitracks`.
3. Open `http://localhost/FITTRACK/`.

If the `users` table is empty, the app creates a default admin account:

- Email: `admin@fittrack.local`
- Password: `admin123`

Database settings are in `config/config.php`.

## Project Structure

- `index.php` - front controller and route map.
- `config/` - database credentials and app constants.
- `core/` - bootstrap, database connection, auth/session helpers, common helpers, and seed data.
- `views/` - shared layout and reusable UI components.
- `pages/` - page controllers grouped by feature area.
- `assets/` - CSS and front-end assets.

The admin dashboard charts use Chart.js loaded from CDN in `views/layout.php`, with chart setup in `assets/dashboard-charts.js`.

## Implemented Modules

- Role-based login for admin, staff, trainer, and member accounts.
- Admin user management, membership plan setup, classes, and reports.
- Staff member registration, memberships, class scheduling, and attendance check-in/check-out.
- Member profile, class booking, progress logging, membership view, and generated nutrition targets.
- Trainer assignment management, trainer client workspace, diet plan review/finalization, messages, training plans, and progress logging.
- Nutrition engine using Mifflin-St Jeor BMR, activity factor TDEE, goal-based calorie adjustment, macro targets, and food item suggestions.
- Optical QR Scanner Terminal (`page=scanner`) with fullscreen Kiosk mode, live occupancy HUD, walk-in fee auto-detection, and 1-tap payment processing.
- Hierarchical Inactive Member & Churn Automation Engine (`core/engagement_engine.php`, `cron.php`) with Platform Admin defaults and Gym Owner custom threshold/cooldown/toggle settings.
- Nutrition & Food Lookup APIs (`pages/member/food_lookup.php`): CalorieNinjas NLP search (`CALORIENINJAS_API_KEY`) and Open Food Facts barcode/product search with 1-click gym library import.
- Real-Time Equipment Queue API (`pages/shared/equipment_api.php`): Real-time polling, session countdown timers, waitlist positions, and audio chime alerts.
- Mathematical Member Engagement Engine (`core/engagement_engine.php`): Multi-factor weighted score (attendance, classes, consistency, workouts, progress) classifying members as Highly Engaged, Moderately Engaged, or At-Risk.

---

## Recent System Hardening & Bug Fixes

### 1. Cross-Site Scripting (XSS) Hardening & HTTP Security Headers
- **Security Headers** (`core/bootstrap.php`): Injected standard defense-in-depth headers into every response:
  - `X-Content-Type-Options: nosniff` (stops MIME-sniffing exploits)
  - `X-Frame-Options: SAMEORIGIN` (mitigates clickjacking attacks)
  - `Referrer-Policy: strict-origin-when-cross-origin` (prevents leaking sensitive URL query parameters)
- **Unified Client-Side HTML Escaping** (`views/layout.php`): Implemented global `window.escapeHtml()` early in `<head>` to sanitize `&`, `<`, `>`, `"`, and `'`.
- **Refactored Inline Action Handlers**: Replaced raw string interpolations in inline `onclick` attributes across `memberships.php`, `equipment.php`, `training.php`, `users.php`, and `walk_ins.php` with HTML5 `data-*` attributes and event delegation.
- **Client DOM Sanitization**: Secured client-side dynamic template rendering in `diet_builder.php`, `setup_goal.php`, and `scanner.php`.

### 2. MySQL Error 3065 in Attendance Filters
- In `pages/admin/attendance.php`, resolved MySQL Error 3065 (`Expression #2 of ORDER BY clause is not in SELECT list... incompatible with DISTINCT`) by including `u.first_name`, `u.last_name`, and `u.email` directly in the `SELECT DISTINCT` column list.

### 3. Universal Chart.js Analytics Restoration
- Added Chart.js 4.5.1 CDN import and offline fallback script in `views/layout.php` alongside a local cached bundle (`assets/chart.umd.min.js`), resolving blank canvases across the Gym Owner Dashboard, Reports & Analytics (Revenue Streams, Revenue Mix, Attendance Trends, Hourly Rush, and Day-of-Week Distribution), and Member Progress Hub.

### 4. Multi-Tenant Gym Messaging Isolation
- In `pages/shared/messages.php`, restricted gym owners to only viewing, listing, and messaging users affiliated with their specific gym (`trainer_profiles.gym_id` and `gym_members.gym_id` / active plan memberships).
- Added server-side validation guard `canMessageUser()` to block cross-gym message submission, unauthorized AJAX polling, and direct URL query tampering (`?chat=XX`).
- Corrected helper call to `get_user_gym($user)` to eliminate IDE undefined function warnings.

---

## Docker Architecture: Why It Is Crucial

FitTracks includes a production-ready container definition in `Dockerfile` (`FROM php:8.2-apache`). Containerization is essential to the platform for the following reasons:

### 1. Strict Compiled PHP Extensions
FitTracks requires compiled C-extensions that standard shared hosting or default XAMPP/WAMP stacks often lack or misconfigure:
- **`gd` (with FreeType, JPEG, and WebP)**: Required to process gym owner business permit uploads, valid IDs, exercise animations, and member avatars. Without WebP/FreeType support compiled into GD, image uploads crash.
- **`exif` and `fileinfo`**: Used in `core/file_handler.php` for MIME-type binary inspection to prevent malicious file uploads.
- **`pdo` & `pdo_mysql`**: Enables secure prepared statements and transactional database queries.
- **Apache `mod_rewrite`**: Directs all page routing through `index.php`.

### 2. Environment Parity ("Works on My Machine" Elimination)
- **Local Dev vs. Cloud Production**: Development on Windows (under XAMPP) relies on Windows backslashes (`\`) and case-insensitive file systems. Production environments (such as Render, Cloud Run, AWS, or DigitalOcean) run Linux, which is strictly case-sensitive and uses forward slashes (`/`).
- Docker guarantees that PHP 8.2 configurations, directory casing rules, and Composer dependencies run identically on every machine.

### 3. Automated Storage Permissions & Lifecycle
The `Dockerfile` automatically creates and grants web server ownership (`www-data:www-data`) to required runtime storage directories at build time:
- `/var/www/html/storage` (session fallback, locks, temporary files)
- `/var/www/html/assets/uploads` (profile photos, receipts)
- `/var/www/html/assets/permits` (gym owner business permits & valid IDs)
- `/var/www/html/assets/exercise_animations`

---

## Cron & Background Task Processing: Why It Is Crucial

FitTracks manages asynchronous background workflows through `cron.php`, `core/Queue.php`, and `core/engagement_engine.php`. This infrastructure is essential for the following reasons:

### 1. Automated Member Engagement Scoring (0–100 Engine)
In `core/engagement_engine.php`, FitTracks evaluates member health using a composite engagement algorithm factoring in:
- 30-day attendance frequency (40%)
- Class booking and attendance (20%)
- Consistency across active weeks (20%)
- Completed workout exercises (10%)
- Body progress & weight logs (10%)

Running these multi-table aggregations synchronously on every page load would freeze database performance as gym membership scales. `cron.php` schedules `recompute_all_engagement_scores_batch` in the background, keeping user web requests instantaneous (~50ms).

### 2. Automated Churn Prevention & At-Risk Retention
FitTracks continuously categorizes members into tiers: **Highly Engaged**, **Moderately Engaged**, and **At-Risk**.
- When `cron.php` runs `process_automated_at_risk_notifications`:
  1. It identifies members who haven't visited in several days and whose engagement score is dropping.
  2. It automatically dispatches in-app push notifications and personalized re-engagement emails (*"We miss you at the gym! Check out this week's classes..."*).
- This operates automatically without requiring gym staff to manually check spreadsheets or remember to follow up.

### 3. Non-Blocking Asynchronous Queue Workers
Outbound SMTP emails (such as welcome emails, verification codes, password resets, and membership renewal reminders) often take 1–3 seconds to negotiate with external mail servers.
- FitTracks pushes these to `Queue::push()`.
- The user's browser response finishes instantly (~50ms).
- The worker executes the job in the background without blocking the user.

### 4. Resilient Multi-Platform Execution Architecture
FitTracks employs a dual-strategy for background processing:
1. **Dedicated Schedulers**: `cron.php` is protected with a token (`?key=fittracks_secret_cron_2026`) and capped at a safe 15-second execution ceiling to fit cron services (like `cron-job.org` or Google Cloud Scheduler) without triggering HTTP 504 timeouts.
2. **"Poor Man's Cron" Fallback**: In `index.php`, a `register_shutdown_function()` worker drains up to 3 queue jobs immediately after the HTTP response is sent (`fastcgi_finish_request`), ensuring emails are still sent even if a hosting provider has no native background cron daemon.

