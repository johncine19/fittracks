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
- Real-Time Equipment Queue API & Anti-Abuse Attendance Gate (`pages/shared/equipment_api.php`, `pages/member/equipment.php`, `pages/member/qr_attendance.php`): Strict attendance-gated machine usage and waitlists to eliminate remote phantom bookings, entrance check-in quick-claim selector, "probably-in-use" active workout transparency notes, real-time polling, session countdown timers, waitlist positions, audio chime alerts, and automated session/queue release on gym check-out.
- Mathematical Member Engagement Engine (`core/engagement_engine.php`): Multi-factor weighted score (attendance, classes, consistency, workouts, progress) classifying members as Highly Engaged, Moderately Engaged, or At-Risk, with Tier-Adaptive Missions that dynamically scale goals based on member fitness tier.

---

## Architecture, Security & Performance Documentation
- 🔒 **[SECURITY.md](SECURITY.md)**: Production security audit remediations, migration authentication, permit authorization endpoints, TLS enforcement, reverse proxy IP spoofing mitigation, and environment hardening.
- ⚡ **[OPTIMIZATIONS.md](OPTIMIZATIONS.md)**: Database scaling guide, sargable index-range queries, equipment poll session lock optimization, batch-grouped engagement calculation, and atomic distributed locking with Redis Lua / flock.
- 🛡️ **[SUBSCRIPTION_GUARDRAILS.md](docs/SUBSCRIPTION_GUARDRAILS.md)**: Anti-hoarding specifications, 7-day renewal window enforcement, advance queue limits, and stale pending checkout session cleanup.
- 🏋️ **[MULTI_GYM_PLAN_SCOPING.md](docs/MULTI_GYM_PLAN_SCOPING.md)**: Multi-gym & multi-coach workout and diet plan isolation, gym-scoped SQL archiving, draft separation, and member interactive plan switcher.

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



### 3. Universal Chart.js Analytics Restoration
- Added Chart.js 4.5.1 CDN import and offline fallback script in `views/layout.php` alongside a local cached bundle (`assets/chart.umd.min.js`), resolving blank canvases across the Gym Owner Dashboard, Reports & Analytics (Revenue Streams, Revenue Mix, Attendance Trends, Hourly Rush, and Day-of-Week Distribution), and Member Progress Hub.

### 4. Multi-Tenant Gym Messaging Isolation
- In `pages/shared/messages.php`, restricted gym owners to only viewing, listing, and messaging users affiliated with their specific gym (`trainer_profiles.gym_id` and `gym_members.gym_id` / active plan memberships).
- Added server-side validation guard `canMessageUser()` to block cross-gym message submission, unauthorized AJAX polling, and direct URL query tampering (`?chat=XX`).
- Corrected helper call to `get_user_gym($user)` to eliminate IDE undefined function warnings.

### 5. Equipment Anti-Abuse Attendance Gate, Usage Transparency & Auto-Release
- **Problem Resolved**: Eliminated "remote phantom bookings" where members sitting at home could start sessions or clog equipment queues on `page=equipment`, locking physical gym equipment away from members physically present on the floor.
- **Strict Active Attendance Gate (`equipment_api.php`)**:
  - `start_session`, `join_queue`, and `claim_session` now verify that the member has an open, active check-in at the gym for the current calendar day (`attendance.check_out_time IS NULL AND DATE(attendance.check_in_time) = CURDATE()`).
  - Remote attempts from outside the gym are immediately rejected with an actionable directive.
- **Remote Browse Mode UI (`page=equipment`)**:
  - Members outside the gym can still safely browse live equipment availability in read-only mode to see how busy the gym is.
  - An amber contextual banner explains that the user is in *Remote Mode*, and action buttons display locked states (`🔒 Check In to Use`, `🔒 Check In to Queue`).
  - Clicking any locked button opens a modal explaining the anti-hogging policy with a 1-tap shortcut to open their entrance QR Code.
- **Realistic Active Usage Transparency Note**:
  - Because workout durations vary from person to person, machines currently occupied display:
    - Current active occupant and elapsed minutes (e.g. *John D. (~12 mins in)*).
    - Status note: `ℹ️ Probably in use by [Member Name]. (Workout finish times vary — join queue to automatically get next claim window)`.
- **Entrance Check-In Quick-Claim & Hybrid Live Search (`page=qr_attendance`)**:
  - Enhanced the post-scan check-in modal to include an optional *"Quick-Claim Your First Machine"* section with real-time hybrid live search.
  - **Live Filter Input**: Members can instantly type to filter equipment across names, unit numbers, zones, and areas (e.g., typing *"tread"*, *"bench"*, or *"cable"* narrows down the list in real-time).
  - **Interactive Category Filter Chips**: Category pills (`All`, `Cardio`, `Strength`, `Free Weights`, `Machines`) act as interactive filters, dynamically updating available counts and grouping matching machines under categorized `<optgroup>` blocks.
  - Members can claim their initial workout station with 1 click right at the door, or dismiss the popup to warm up and claim later.
- **Active Gym Visit Banner & Browser Refresh Resilience (`page=qr_attendance`)**:
  - **Checked-In Status Banner**: When an active check-in exists today, the page displays a prominent status card with a pulsing green indicator, gym name, check-in timestamp, floor time counter, active machine session indicator, and quick actions (*"Choose Equipment & Claim"*, *"View Gym Floor & Queue"*).
  - **Modal Auto-Restore on Refresh**: If a member refreshes within 5 minutes of check-in and hasn't claimed a machine or explicitly dismissed the prompt, the quick-claim modal automatically re-opens.
  - **Smart Dismissal Memory**: Explicitly clicking *"Dismiss"* saves a scoped dismissal flag to `sessionStorage`, ensuring members who choose not to claim right away are not repeatedly interrupted on subsequent page reloads while still retaining 1-tap re-open capability via the banner button.
  - **Check-Out QR Mode**: When checked in, the QR code generator automatically switches context to generate a Check-Out pass for smooth scanning upon departure.
- **Automated Check-Out Release (`core/helpers.php` & `equipment_api.php`)**:
  - Implemented `release_user_equipment_on_checkout()`: whenever a member scans out, performs self-checkout, or is checked out by staff, any active equipment sessions they forgot to end are automatically finalized, and any waitlist queues they were holding are cancelled.
  - `reconcile_equipment_states()` automatically detects and releases stale sessions and advances the queue to the next waiting member on every poll.

### 6. Tier-Adaptive Missions & Gamified Engagement Progression
- **Tier-Adaptive Mission Targets (`core/helpers.php` & `core/engagement_engine.php`)**:
  - Replaced flat, one-size-fits-all mission targets with `get_tier_mission_targets($tier)`, automatically scaling monthly goals based on the member's current fitness tier.
  - **Progressive Target Calibration**:
    - **Tier 1 (Newbie)**: 4 gym visits/month (~1x/wk), 2 group classes, 3 active weeks, 4 workout days. Eliminates beginner friction and habit intimidation.
    - **Tier 2 (Iron Recruit)**: 6 gym visits/month (~1.5x/wk), 3 group classes, 4 active weeks, 6 workout days.
    - **Tier 3 (Bronze Beast)**: 8 gym visits/month (~2x/wk), 4 group classes, 4 active weeks, 8 workout days.
    - **Tier 4 (Silver Spartan)**: 10 gym visits/month (~2.5x/wk), 4 group classes, 4 active weeks, 10 workout days.
    - **Tier 5 (Gold Gladiator)**: 12 gym visits/month (~3x/wk), 4 group classes, 4 active weeks, 12 workout days.
    - **Tier 6 (Apex Legend)**: 14 gym visits/month (~3–4x/wk), 4 group classes, 4 active weeks, 14 workout days.
  - **Unified Scoring Arithmetic (`engagement_score_from_metrics`)**: Synchronized real-time dashboard missions and background batch calculations (`calculate_engagement_scores_batch`) to compute proportional points using each member's tier targets.
  - **Dynamic Dashboard Missions HUD (`pages/member/dashboard.php`)**: Added dynamic description text reflecting current tier requirements and added a contextual tier target badge pill (`Missions [★ Newbie Targets]`).

### 7. Leaderboard Member Tier Badges & Milestone Badge Restoration
- **Gym Leaderboard Tier Badges (`pages/member/dashboard.php`, `assets/css/pages/member_dashboard.css`)**:
  - Enhanced the top-5 gym leaderboard to join `member_profiles.fitness_tier` and render tier badges beside each member's name (`#1 Yu Y. [★ Newbie Tier] 48 pts`).
  - Added dedicated CSS classes (`.leader-tier-badge`, `.leader-tier-1` through `.leader-tier-6`) with tailored color schemes for each tier and complete light-theme overrides for high-contrast visibility.
- **Goal Milestone Badge Restoration (`pages/member/dashboard.php`)**:
  - Resolved an issue where fitness progress badges awarded by `core/goal_service.php` were rendering as `"Unknown"` on the member dashboard.
  - Added full definitions, vector SVG icons, and descriptions for all milestone badges (`milestone_first_log` [Journey Begun], `milestone_waist_trimmer` [Waist Sculptor], `milestone_weight_goal_met` [Target Achieved], `milestone_lean_warrior`, `milestone_arm_builder`, `milestone_chest_sculptor`, and `milestone_consistency_streak`).

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
- 30-day attendance frequency (10%)
- Class booking and attendance (20%)
- Consistency across active weeks (20%)
- Completed workout exercise days (40%)
- Body progress & weight logs (10%)

Running these multi-table aggregations synchronously on every page load would freeze database performance as gym membership scales. `cron.php` schedules `recompute_all_engagement_scores_batch` in the background, keeping user web requests instantaneous (~50ms).

### 2. Rule-Based Inactivity Alerts
FitTracks continuously categorizes members into tiers: **Highly Engaged**, **Moderately Engaged**, and **At-Risk**.
- When `cron.php` runs `process_automated_at_risk_notifications`:
  1. It identifies members who have not checked in for the configured number of days. This alert rule is separate from engagement score categories.
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

### 5. Automated Midnight Attendance Auto Check-Out (Hybrid Implementation)
When members, trainers, or staff leave the gym without manually checking out, their active session remains open in the database. Left unchecked, this causes:
- **Distorted Metrics**: Workout durations logged as 18–24+ hours.
- **Inaccurate Live Occupancy**: "Currently in Gym" counters remain artificially inflated overnight.
- **Next-Day Scan Interference**: The morning scan is mistakenly registered as yesterday's check-out rather than a new check-in.

To solve this, FitTracks implements a **high-performance hybrid auto check-out system**:
1. **Midnight Scheduled Sweep (`cron.php`)**:
   - Executes daily at **12:00:00 AM Philippine Time (PHT / UTC+8)** via external cron (e.g. `cron-job.org`).
   - Runs `auto_checkout_past_attendance(null, true)` before engagement score recomputations.
   - Detects all sessions where `check_out_time IS NULL AND check_in_time < CURDATE()` and automatically sets `check_out_time = CONCAT(DATE(check_in_time), ' 23:59:59')`, keeping all duration logs cleanly bounded within the calendar day.
2. **Just-In-Time Code Safety Net (Zero-Dependency Fallback)**:
   - When any member scans their QR code (`scanner.php`) or an admin accesses attendance records (`attendance.php`), localized safety guards auto-close any lingering past-day check-in for that specific user.
   - This ensures full self-healing behavior even in local development (XAMPP offline at night) or if external cron triggers fail.
3. **Database Performance Indexing**:
   - Backed by a covering B-Tree index: `idx_att_checkout_checkin (check_out_time, check_in_time)`.
   - The query resolves as an in-memory index range scan (`Using where; Using index`), ensuring instant execution (`< 1ms`) with zero table locks even with 100,000+ attendance records.

### 7. Subscription Anti-Hoarding & Over-Purchase Guardrails
Comprehensive renewal window restrictions and anti-spam measures across gym SaaS subscriptions and member plans:
- **Gym SaaS Platform Subscriptions (`pages/gym_owner/gym_subscription.php`, `core/xendit.php`)**:
  - **7-Day Renewal Window Constraint**: Disallows early renewal of the same plan if > 7 days remain. The checkout button is disabled and displays `Active (X days left)` along with the exact renewal opening date.
  - **Immediate Tier Upgrades**: Upgrading from lower tiers to higher tiers (e.g. Starter ➔ Pro/Business) remains available immediately without window restrictions.
  - **Stale Pending Session Cleanup**: Automatically invalidates and marks prior pending checkout sessions as `failed` before generating new Xendit links, preventing checkout spam.
  - **Webhook Renewal Date Rollover**: Fixed `SELECT` query in `xendit_handle_gym_subscription_payment_webhook` to include `subscription_plan`, ensuring legitimate renewals within the 7-day window properly extend from `subscription_renewal_date`.
- **Member Gym Subscriptions (`pages/admin/memberships.php`, `pages/admin/payments.php`)**:
  - **7-Day Renewal Window & Single Advance Cycle**: Members cannot renew the same plan if > 7 days remain on their active membership, and cannot queue more than 1 advance cycle.
  - **Stale Pending Request Cleanup**: Submitting a new membership subscription automatically supersedes and cancels older unpaid `pending` memberships and payments for that member at the gym.
  - **Admin Manual Recording Safeguard**: Added validation in `payments.php` blocking manual membership stacking beyond 365 days in advance.
- Full architectural and workflow documentation available in 🛡️ **[SUBSCRIPTION_GUARDRAILS.md](docs/SUBSCRIPTION_GUARDRAILS.md)**.

---



*(Note: In production on Render, you can alternatively navigate to `https://<your-app>.onrender.com/migrate.php` to apply these indexes automatically).*

### 2. External Web Cron Setup (`cron-job.org`)
For platforms like Render without background OS daemons, schedule a single unified daily task on [cron-job.org](https://cron-job.org):
- **URL**: `https://<your-app>.onrender.com/cron.php?key=fittracks_secret_cron_2026`
- **Schedule**: Daily at `12:00:00 AM`
- **Timezone**: `Asia/Manila` (PHT / UTC+8)
- **End-of-Day Summary Emails (Automated Midnight Cron + Manual Staff Shift Close)**:
  - **Automated Midnight Cron (12:00:00 AM PHT)**: At midnight, `cron.php` auto checks-out lingering attendance sessions at `23:59:59` and queues a finalized executive settlement email for the **previous Philippine calendar day**.
  - **Eligibility**: Emails are sent to the owner of each approved gym whose subscription includes the Business `eod_summary` feature and whose **Midnight Daily Settlement Email** (`eod_email_summary`) setting is enabled in Gym Profile (defaults to ON).
  - **Zero-Activity Days Included**: The message reports zero revenue and check-ins alongside active member counts and memberships expiring tomorrow, providing a daily operational heartbeat.
  - **Duplicate Prevention**: A unique `(gym_id, report_date)` record in `gym_eod_email_log` prevents duplicate queueing if the cron endpoint runs more than once for the same day.
  - **Manual Staff Shift-End EOD Window (5:00 PM – 11:59 PM PHT)**:
    - **Literal EOD Gate (5:00 PM)**: Manual email sending from the Staff Portal and Reports Modal is locked during daytime hours (12:00 AM – 4:59 PM) to preserve the literal meaning of EOD and prevent premature reporting while business hours are underway. Physical slip printing at the counter remains available 24/7.
    - **Multi-Staff Anti-Spam Guardrail**: Once a staff member emails the daily closing slip during the 5:00 PM – 11:59 PM window, the gym-wide record (`gyms.last_eod_email_sent_at` and `gyms.last_eod_email_sent_by`) locks the button for the rest of that day (`Already Sent`) so overlapping or incoming shift staff cannot spam the owner with duplicate emails.
    - **Sender Transparency**: The settlement modal displays a live audit banner (`✓ EOD settlement already emailed today at [Time] by [Staff Name]`).
    - **Shift Handover Signature**: The email delivered to the owner embeds a verified shift handover notice detailing the exact staff member's name, role, and dispatch timestamp.
  - **Database setup**: Ensure `gym_eod_email_log` and the `last_eod_email_sent_at` / `last_eod_email_sent_by` columns on `gyms` are applied through `migrate.php`.
- **Execution Order Handled Automatically by `cron.php`**:
  1. Auto check-out unclosed attendance from yesterday (`23:59:59` timestamp).
  2. Queue the previous day's End-of-Day summary emails for eligible gyms.
  3. Smart tiered notification cleanup (30-day read, 60-day unread/system).
  4. Expire lapsed gym subscriptions.
  5. Recompute member engagement scores and points.
  6. Dispatch automated at-risk member notifications and emails.

---

## Offline Resilience & Brownout Continuity (PWA + IndexedDB Auto-Sync)

When FitTracks is deployed on a live cloud domain (e.g., Render, VPS, AWS), the central server runs 24/7 in the cloud. However, the physical gym facility can experience **sudden brownouts (power cuts)** or **ISP internet connection drops**.

To prevent front-desk queues, lost attendance, and downtime, FitTracks includes a native **Offline-First PWA & IndexedDB Attendance Engine** in `pages/admin/scanner.php`:

### 1. Architectural Components

```
[Member Scans QR / Staff Enters ID]
              │
      ┌───────┴───────┐
      ▼               ▼
[Internet UP]   [Internet DOWN / Brownout]
      │               │
  Live AJAX       Save to Browser IndexedDB Queue
      │           (FitTracksTerminalDB -> 'queue')
      │               │
      │           Verify against Cached Roster
      │           Toggle Local 'is_inside' State
      │           Display "OFFLINE CONFIRMED" & Audio Chime
      │           Prepend Activity with [OFFLINE] Tag
      │               │
      │           [Electricity / Internet Restores]
      │               │
      │           Background Sync Worker Detects 'online'
      │           Batch POST to 'action=sync_offline_batch'
      │           (Preserves Original 'scanned_at' Timestamps)
      ▼               ▼
  [Central Cloud Database Updated with 100% Accurate Logs]
```

* **Service Worker (`sw.js`) & Web App Manifest (`manifest.json`)**:
  - Caches the entire terminal application shell (HTML layout, CSS design system, audio feedback chimes, and offline assets).
  - Even if staff opens or refreshes the scanner tab with **zero internet**, the terminal loads instantly without the browser "No Internet" screen.
  - Front-desk staff can click *"Install App"* in Google Chrome or Microsoft Edge to run FitTracks as an independent desktop window on Windows.
* **Local Member Roster (`IndexedDB -> roster`)**:
  - Automatically fetches an encrypted, lightweight cache of active gym members, roles, plans, and QR tokens on startup via `action=get_offline_roster`.
  - Enables instant QR token validation and name lookup offline without contacting the server.
* **Preserved Client Timestamps (`scanned_at`)**:
  - When an offline scan occurs, the browser captures the exact ISO timestamp (e.g. `09:14:22 AM`).
  - When synced later (e.g. `11:00:00 AM`), the server writes `09:14:22 AM` into the database—ensuring attendance history and daily rush metrics remain accurate.
* **Offline Manual Search & Entry**:
  - If staff uses **Manual Entry** during a blackout, the search input automatically filters the local `IndexedDB` roster by name, email, or phone.
* **Zero-CDN Dependency**:
  - `assets/html5-qrcode.min.js` and `assets/sweetalert2.all.min.js` are bundled locally, ensuring camera scanner initialization and interactive feedback modals run completely offline.

### 2. Operational Standard Operating Procedure (SOP) for Staff

| Situation | System Action | Staff Action |
| :--- | :--- | :--- |
| **Sudden Brownout / Outage** | Front desk laptop or battery-backed PC continues running. Status badge turns to `⚡ OFFLINE MODE`. | Continue scanning member QR codes or using Manual Entry as normal. |
| **During Blackout** | Scans display `OFFLINE CHECK-IN / CHECK-OUT CONFIRMED`, increment local counters, and queue in IndexedDB. | Allow member entry immediately. No paper logging required. |
| **Power / Internet Restores** | System automatically detects reconnection and pushes batch to `action=sync_offline_batch`. | A confirmation toast appears: *"⚡ Back Online! Synced X offline record(s)"*. All records are live. |
