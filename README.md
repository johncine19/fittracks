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
