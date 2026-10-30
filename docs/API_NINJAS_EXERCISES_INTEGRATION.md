# API Ninjas Exercises Library Integration & Rate Limiting Documentation

## 1. Overview & Purpose

The **API Ninjas Exercises Integration** allows Gym Owners and Platform Admins to search, browse, and import thousands of verified exercise movements directly into their gym's dedicated exercise library. 

Imported exercises automatically inherit the gym's isolation scope (`gym_id`), making them immediately available to coaches and personal trainers when building customized workout routines in `workout_builder.php`.

Each exercise item imported from API Ninjas contains:
* **Exercise Name** (e.g., *Incline Dumbbell Press*, *Barbell Squat*)
* **Category / Type** (e.g., *Strength*, *Cardio*, *Plyometrics*, *Olympic Weightlifting*)
* **Primary Target Muscle Group** (e.g., *Chest*, *Biceps*, *Quadriceps*, *Lats*, *Abdominals*)
* **Equipment Required** (e.g., *Barbell, Bench*, *Dumbbell*, *Cable Machine*, *Bodyweight*)
* **Difficulty Level** (mapped to numeric levels `1 = Beginner`, `2 = Intermediate`, `3 = Expert`)
* **Comprehensive Description & Safety Notes** (Step-by-step form execution instructions and injury-prevention cues)

---

## 2. Environment Configuration

The API Key is retrieved via `app_env('API_NINJAS_KEY')` with fallback to `app_env('CALORIENINJAS_API_KEY')`.

### `.env` & `.env.example`
```dotenv
# API Ninjas Configuration (for Exercise Library Import & Nutrition)
API_NINJAS_KEY=your_api_ninjas_api_key_here
```

---

## 3. Database Schema & Migration

### Migration File: `migrate.php` (Lines 162–165)
To support long form instructions and equipment specifications without truncation, schema adjustments were added to `$migrations` in `migrate.php`:

```php
// Lines 162-165 in migrate.php
// ── API Ninjas Exercise Database Schema Support ──
"ALTER TABLE `exercises` MODIFY COLUMN `description` TEXT DEFAULT NULL",
"ALTER TABLE `exercises` ADD COLUMN `equipment` VARCHAR(80) DEFAULT NULL AFTER `muscle_group`"
```

### Table Structure: `exercises`
| Column | Type | Nullable | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| `exercise_id` | `int unsigned` | NO | Auto Increment | Primary Key |
| `gym_id` | `int unsigned` | NO | NULL | Foreign Key to `gyms.gym_id` (scoping) |
| `name` | `varchar(80)` | NO | NULL | Exercise title |
| `category` | `varchar(50)` | YES | NULL | Type of exercise (Strength, Cardio, etc.) |
| `category_id` | `int unsigned` | YES | NULL | Auto-resolved via DB trigger `trg_exercises_bi` |
| `muscle_group` | `varchar(50)` | YES | NULL | Target muscle (Chest, Quadriceps, Lats, etc.) |
| `muscle_group_id`| `int unsigned` | YES | NULL | Auto-resolved via DB trigger `trg_exercises_bi` |
| `equipment` | `varchar(80)` | YES | NULL | Equipment needed (Dumbbell, Bench, etc.) |
| `description` | `text` | YES | NULL | Full execution instructions and safety notes |
| `animation_url`| `varchar(255)` | YES | NULL | Video/GIF or local asset link |
| `difficulty_level` | `int` | NO | `1` | Numeric rating (1=Beginner, 2=Intermediate, 3=Expert) |

---

## 4. Rate Limiting Policy & Implementation

To protect API Ninjas quota, prevent server overload, and eliminate malicious button-mashing or automated scraping, rate limiting is applied via the application's native `RateLimiter` (`core/rate_limiter.php`).

### Rate Limit Thresholds

| Action | Route / Parameter | Rate Limit | Time Window | Limit Exceeded Response |
| :--- | :--- | :---: | :---: | :--- |
| **Search Exercises** | `GET action=search_ninja` | **20 requests** | 60 seconds | `HTTP 429 Too Many Requests`<br>`{"success":false,"rate_limited":true,"retry_after":X,"error":"..."}` |
| **Single Import** | `POST action=import_ninja` | **30 imports** | 60 seconds | `HTTP 429 Too Many Requests`<br>`{"success":false,"rate_limited":true,"retry_after":X,"error":"..."}` |
| **Batch Import** | `POST action=batch_import_ninja` | **30 imports** | 60 seconds | `HTTP 429 Too Many Requests`<br>`{"success":false,"rate_limited":true,"retry_after":X,"error":"..."}` |
| **Batch Item Cap** | `POST action=batch_import_ninja` | **Max 50 items** | per request | `HTTP 422 Unprocessable Content`<br>`{"success":false,"error":"You can import a maximum of 50..."}` |

### Storage & Eviction Architecture
`RateLimiter` operates in a tiered caching hierarchy:
1. **Redis** (atomic `INCR` + `EXPIRE` if Redis is enabled).
2. **Database Cache Table (`cache`)**: Uses `cache_key = 'rl:' . hash('sha256', key)` with automated garbage collection.
3. **Local Disk Storage (`storage/ratelimit/`)**: Failsafe JSON bucket storage per hashed key.

---

## 5. Source Code Breakdown & Line References

### A. Backend Handlers in `pages/admin/exercises.php`

#### 1. Live Exercise Search API (`action === 'search_ninja'`)
* **Location:** `pages/admin/exercises.php` (Lines 26–188)
* **Rate Limiter Code (Lines 30–42):**
```php
$searchRateLimitKey = 'ninja_search:' . $user['user_id'];
if (RateLimiter::tooManyAttempts($searchRateLimitKey, 20, 60)) {
    $retryAfter = max(1, RateLimiter::secondsRemaining($searchRateLimitKey, 60));
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'rate_limited' => true,
        'retry_after' => $retryAfter,
        'error' => "Rate limit reached: Up to 20 searches per minute allowed. Please wait {$retryAfter} second(s) before searching again."
    ]);
    exit;
}
RateLimiter::hit($searchRateLimitKey, 60);
```
* **API Ninjas Remote cURL Call (Lines 66–93):**
  - Endpoint: `https://api.api-ninjas.com/v1/exercises`
  - Passes headers: `X-Api-Key: $apiKey`
  - Passes parameters: `name`, `muscle`, `type`, `difficulty`, `offset`
* **Gym Duplicate Detection (Lines 111–123):**
  - Queries `SELECT LOWER(name) FROM exercises WHERE gym_id = ? AND LOWER(name) IN (...)`
  - Adds `'already_imported' => true/false` to each returned record.

#### 2. Single Exercise Import (`postAction === 'import_ninja'`)
* **Location:** `pages/admin/exercises.php` (Lines 204–264)
* **Rate Limiter Code (Lines 209–221):**
```php
$importRateLimitKey = 'ninja_import:' . $user['user_id'];
if (RateLimiter::tooManyAttempts($importRateLimitKey, 30, 60)) {
    $retryAfter = max(1, RateLimiter::secondsRemaining($importRateLimitKey, 60));
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'rate_limited' => true,
        'retry_after' => $retryAfter,
        'error' => "Import rate limit reached. Please wait {$retryAfter} second(s) before importing more exercises."
    ]);
    exit;
}
RateLimiter::hit($importRateLimitKey, 60);
```
* **Database Insertion & Audit Logging (Lines 237–260):**
  - Inserts into `exercises` table with `targetGymId`.
  - Calls `audit_log($user['user_id'], 'import_ninja', 'exercise', (string)$newId, ...)`.

#### 3. Batch Exercise Import (`postAction === 'batch_import_ninja'`)
* **Location:** `pages/admin/exercises.php` (Lines 267–346)
* **Rate Limiter & 50-Item Cap (Lines 272–295):**
```php
if (count($items) > 50) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'You can import a maximum of 50 exercises per batch.']);
    exit;
}
```
* Loops through selected exercises, verifies non-existence, inserts each item, and logs audit events.

---

### B. User Interface & Dialog Modal

#### 1. Banner Import Button
* **Location:** `pages/admin/exercises.php` (Lines 686–691)
```html
<button type="button" class="exercises-ninja-button" onclick="openNinjaModal()">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
    Import from API Ninjas
</button>
```

#### 2. Modal HTML Dialog (`#ninjaModal`)
* **Location:** `pages/admin/exercises.php` (Lines 799–956)
* Contains:
  - Responsive search filters (Name keyword, Target Muscle, Type, Difficulty).
  - Popular quick-filter chips (`Chest`, `Biceps`, `Triceps`, `Quads & Legs`, `Back & Lats`, `Abs & Core`, `Cardio`).
  - Batch action toolbar with `Select All Available` checkbox and `Import Selected (X)` button.
  - Interactive cards with collapsible form instructions and library status badges.

#### 3. Client JavaScript Controller
* **Location:** `pages/admin/exercises.php` (Lines 1132–1464)
* **Key Functions:**
  - `openNinjaModal()` (Line 1137): Displays dialog via `modal.showModal()`.
  - `closeNinjaModal()` (Line 1148): Closes dialog and reloads if exercises were imported.
  - `quickNinjaFilter(muscle, type)` (Line 1172): Pre-fills search dropdowns and executes search.
  - `triggerNinjaSearch()` (Line 1182): Sends async `GET` request. Includes live countdown timer on `#ninjaSearchBtn` when receiving `HTTP 429`.
  - `renderNinjaResults(exercises)` (Line 1243): Renders cards, pills, difficulty badges, and instruction toggle.
  - `importSingleNinjaExercise(btn, idx)` (Line 1341): Performs AJAX POST with CSRF verification and updates card to `✓ Imported`.
  - `importBatchNinjaExercises()` (Line 1399): Submits array of checked items in a single batch request.

---

### C. Workout Builder Integration (`pages/trainer/workout_builder.php`)

* **Location:** `pages/trainer/workout_builder.php` (Lines 398–415)
* When trainers build workout routines, exercises are queried strictly using the facility's `gym_id`:
```php
// Lines 398-405 in pages/trainer/workout_builder.php
if ($exerciseLibraryGymId > 0) {
    $stmt = $pdo->prepare('SELECT exercise_id, name, muscle_group, category, difficulty_level FROM exercises WHERE gym_id = ? ORDER BY muscle_group, name');
    $stmt->execute([$exerciseLibraryGymId]);
    $allExercises = $stmt->fetchAll();
}
```
* Newly imported exercises from API Ninjas immediately populate the trainer's workout routine dropdowns.

---

### D. Manual Exercise Form Taxonomy Alignment

The manual "Add New Exercise" and "Edit Exercise" modal (`#exModal`) in `pages/admin/exercises.php` uses the same standardized taxonomy as API Ninjas:
* **Target Muscle**: Predefined dropdown (`Chest`, `Biceps`, `Triceps`, `Forearms`, `Quadriceps`, `Hamstrings`, `Calves`, `Glutes`, `Lats`, `Middle Back`, `Lower Back`, `Traps`, `Neck`, `Abdominals`, `Abductors`, `Adductors`).
* **Exercise Type**: Predefined dropdown (`Strength`, `Cardio`, `Powerlifting`, `Olympic Lifting`, `Plyometrics`, `Stretching`, `Strongman`).
* **Difficulty**: Predefined dropdown (`Beginner` [1], `Intermediate` [2], `Expert` [3]).
* **Equipment**: Free-text gear specifications (`Barbell, Dumbbell, Cable Machine, Bodyweight`).
* Ensures 100% data consistency between manually created and API Ninjas imported exercises.

---

## 6. Security & Audit Logging

1. **Role Guard**: Limited strictly to authenticated `platform_admin` and `gym_owner` roles via `require_roles(['platform_admin', 'gym_owner'])`.
2. **CSRF Protection**: All POST operations require a valid token validated via `verify_csrf()` and `csrf_token()`.
3. **Audit Trail**: Every imported exercise records an entry in the system audit logs via `audit_log()`.
4. **HTML Sanitization**: All user inputs and API responses are escaped via `h()` and JavaScript DOM text escaping to prevent XSS.

