# FitTracks Geolocation & Proximity Architecture

This document outlines the architecture, implementation specifications, privacy compliance, and interactive map integration for location-based features within **FitTracks**.

---

## 1. Overview & Objectives

FitTracks utilizes device geolocation to improve member convenience, prevent attendance fraud, and provide spatial gym discovery across multi-branch gym networks.

### Core Objectives:
1. **Proximity-Based Gym Discovery**: Allow members to sort and filter partner gyms by real-world distance (*"Nearest to Me"*).
2. **Interactive Map View**: Visual map showing gym locations, member proximity, and branch details.
3. **Attendance Anti-Spoofing (Geofencing)**: Ensure members checking in via QR codes are physically on gym premises.
4. **Smart Branch Routing**: Suggest the appropriate active branch when a member travels between affiliated facilities.

---

## 2. Implementation Status: Complete ✅

The Interactive Map View and Proximity features are fully deployed in the application:
- **Database Schema**: [migrate.php](file:///c:/xampp/htdocs/Fittracks_before_test/migrate.php) (added `latitude`, `longitude`, and `geofence_radius_meters` to the `gyms` table).
- **Service Layer**: [core/gym_selection_service.php](file:///c:/xampp/htdocs/Fittracks_before_test/core/gym_selection_service.php) (loads and types coordinates for frontend client data).
- **Frontend Views**: 
  - [pages/member/gym_selection.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/member/gym_selection.php) (Cards carousel view with modern `.gym-toolbar`, `#btn-near-me`, and view switcher link).
  - [pages/member/gym_map.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/member/gym_map.php) (Dedicated full-page interactive map view with real-time marker filtering, user GPS pin, and popups).
- **Interactive Scripts**: 
  - [assets/js/pages/gym_selection.js](file:///c:/xampp/htdocs/Fittracks_before_test/assets/js/pages/gym_selection.js) (Cards carousel navigation, search filtering, GPS distance re-ordering, and gym preview modals).
  - [assets/js/pages/gym_map.js](file:///c:/xampp/htdocs/Fittracks_before_test/assets/js/pages/gym_map.js) (Dedicated Leaflet map initialization, live pin filtering, GPS radar marker, and popups).
- **Gym Owner Profile Settings**: [pages/admin/gym_profile.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/admin/gym_profile.php) (UI for gym owners to manually enter Lat/Lng, detect device GPS, and drag/click on an interactive Leaflet pin picker map).
- **Design System Styling**: [assets/css/pages/gym_selection.css](file:///c:/xampp/htdocs/Fittracks_before_test/assets/css/pages/gym_selection.css) (dark glassmorphism popups, neon lime gym pins, animated user radar marker, responsive toolbar).
- **Statutory Compliance**: [pages/shared/privacy.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/shared/privacy.php) (Section 2) & [pages/shared/terms.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/shared/terms.php) (Section 3).

---

## 3. Database Schema Changes

To support geolocation, the `gyms` table is extended with coordinates:

```sql
ALTER TABLE gyms 
  ADD COLUMN latitude DECIMAL(10, 8) DEFAULT NULL AFTER address,
  ADD COLUMN longitude DECIMAL(11, 8) DEFAULT NULL AFTER latitude,
  ADD COLUMN geofence_radius_meters SMALLINT UNSIGNED DEFAULT 100 AFTER longitude;
```

- **`latitude` / `longitude`**: Standard WGS84 coordinates of the gym entrance.
- **`geofence_radius_meters`**: Allowed radius (default: 100m) for location-verified QR check-ins.

Sample branch coordinates seeded in migrations:
- **Saitama Gym**: `14.599512, 120.984222` (Manila)
- **Laty Gym**: `14.554729, 121.024445` (Makati)
- **Levi Gym**: `14.650700, 121.033600` (Quezon City)

---

## 4. Distance Calculation (Haversine Formula)

Distance between the member's device $(lat_1, lon_1)$ and the gym $(lat_2, lon_2)$ is computed using the great-circle Haversine formula directly in the client browser:

```javascript
function haversineDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Earth's mean radius in kilometers
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c; // Distance in km
}

function formatDistance(distKm) {
    if (distKm === null || isNaN(distKm)) return '';
    if (distKm < 1) {
        return Math.round(distKm * 1000) + ' m away';
    }
    return distKm.toFixed(1) + ' km away';
}
```

When the user taps **"Near Me"**:
1. Coordinates are retrieved via `navigator.geolocation.getCurrentPosition(...)`.
2. Haversine distance is calculated for every gym on the platform.
3. Badges (`.gym-distance-badge`) are revealed on gym cards (`"📍 1.2 km away"`).
4. The carousel cards are automatically sorted from closest to farthest, with smooth GSAP transition.
5. If the user toggles to the **Map View**, the user's location is marked with an animated radar pulse (`.user-pulse-marker`), and the map view fits the user and surrounding gyms.

---

## 5. Interactive Map View Integration

FitTracks uses **Leaflet.js** combined with **OpenStreetMap tiles** and a tailored CSS inversion filter (`filter: invert(100%) hue-rotate(180deg) brightness(88%) contrast(90%)`). This provides a completely free, 100% watermark-free, zero-API-key map that renders in high-contrast dark mode to match FitTracks' aesthetic.

### CDN Libraries:
```html
<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
```

### Tile Layer Configuration:
- **Tile URL**: `https://tile.openstreetmap.org/{z}/{x}/{y}.png`
- **Dark Mode Filter**: `.leaflet-tile-pane { filter: invert(100%) hue-rotate(180deg) brightness(88%) contrast(90%); }`
- **Attribution**: OpenStreetMap contributors

### UI Components:
1. **View Switcher (`#btn-view-cards` / `#btn-view-map`)**:
   - Toggles effortlessly between the horizontal carousel cards and the full-bleed interactive map.
   - Employs `map.invalidateSize()` on tab transition to ensure tile rendering is crisp without gray areas.
2. **Interactive Dark Glassmorphism Popups**:
   - Displays gym logo, title, address, rating stars, distance badge, direct **"Select Gym"** form submission button, and a **"Details"** button that seamlessly opens the gym preview modal.
3. **Search Bar Sync**:
   - Typing into `#gym-search` simultaneously filters the card carousel and adjusts marker visibility on the map.
4. **Map Controls**:
   - **"Fit All"** zooms out to frame all gyms plus user location.
   - **"My Location"** centers with smooth camera flight to member coordinates.

---

## 6. Statutory Privacy & Legal Compliance

### Must Geolocation Be Disclosed in Terms & Privacy Policy?
**YES. Legally Required.**

Under the **Philippine Data Privacy Act of 2012 (Republic Act No. 10173)**, National Privacy Commission (NPC) circulars, and the **General Data Protection Regulation (GDPR Article 6 & 9)**:
1. **Precise Geolocation is Personal Data**: Exact GPS coordinates identify where an individual is situated at a given timestamp.
2. **Explicit Consent**: Browsers require explicit runtime permission via the `navigator.geolocation` prompt.
3. **Data Minimization**: FitTracks calculates gym distance *in-browser (ephemerally)*. Precise user GPS coordinates are **never stored** in the database for gym selection.
4. **No Third-Party Tracking**: Location data is never shared with ad networks, data brokers, or third parties.

---

## 7. Integrated Policy Clauses

### A. Privacy Policy Clause ([pages/shared/privacy.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/shared/privacy.php))
Documented under **Section 2 ("Information We Collect")**:
> **Geolocation Data (Optional):** With your explicit permission granted via your browser or device settings, we collect real-time latitude and longitude coordinates. This data is utilized solely to:
> - Display affiliated gyms sorted by physical proximity to you (*"Nearest to Me"*);
> - Render partner gym facilities on the interactive floor map;
> - Verify physical presence on gym premises during dynamic QR code attendance check-in to prevent unauthorized credential sharing.  
> *Note:* For proximity browsing, coordinates are processed ephemerally on your local device and are not persistently stored on our servers. You may decline or revoke location permissions at any time through your browser settings without losing access to basic platform services.

### B. Terms of Service Clause ([pages/shared/terms.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/shared/terms.php))
Documented under **Section 3 ("Location-Based Services & Verification")**:
> FitTracks provides optional location-based functionality, including nearest gym sorting, interactive facility mapping, and geofenced attendance verification. You acknowledge that:
> 1. Location accuracy depends on your device hardware, mobile network, and satellite availability.
> 2. Certain security features, such as remote-fraud prevention during check-in or equipment waitlist reservations, may require verified physical presence within the gym's designated perimeter.
> 3. You retain the right to disable device location access at any time; however, certain proximity-dependent features may require manual address selection or staff check-in assistance.

---

## 8. Proximity Arrival Alerts & QR Geofence Verification

To create an effortless gym arrival experience and prevent remote QR attendance sharing, FitTracks integrates a non-intrusive arrival detection system paired with live geofence verification.

### 8.1 In-App Arrival Banner on Member Dashboard (`pages/member/dashboard.php`)
When a member arrives within the designated perimeter of their affiliated gym (`geofence_radius_meters`, default 100m):
1. **Intelligent Suppression**:
   - The banner only triggers if the member has an active affiliated gym with GPS coordinates and is **not already checked in** (`check_out_time IS NULL`).
   - If dismissed by the user, the dismissal is stored in `sessionStorage` (`ft_arrival_dismissed_{gym_id}`) so members are not repeatedly nagged during their visit.
2. **Design & Micro-Animations**:
   - Fixed slide-down card with high z-index (`#gym-arrival-banner`), neon radar pulse ring, and distance indicator badge (*"📍 Near You • At the gym"*).
   - Fast 1-tap action button: **"Check In"** &rarr; immediately routes the member to their dynamic QR attendance code (`index.php?page=qr_attendance`).
3. **OS Push Notification Trigger**:
   - If the user has granted browser notification permissions (`Notification.permission === 'granted'`), the system fires a native desktop/mobile OS notification:
     - Title: *"Welcome to [Gym Name]!"*
     - Body: *"You're within check-in range. Tap here to open your QR check-in pass."*
     - Tag: `gym-arrival-{gym_id}` (prevents duplicate spam).

### 8.2 Real-Time Proximity Badge on QR Attendance (`pages/member/qr_attendance.php`)
On the QR code display page, an interactive proximity pill (`#geofence-status-banner`) is situated right above the QR generation container:
1. **On-Site Verified (`.geofence-on-site`)**:
   - Shown when the member is within the gym's perimeter (`distance <= radius`).
   - Displays: `On-Site Verified (X m away) • Ready for turnstile scan` with a glowing animated pulse dot.
   - Adds an energetic emerald glow border to the QR code box (`#qr-container.on-site-glow`), assuring the user and front-desk staff that the device is present on site.
2. **Off-Site Indicator (`.geofence-off-site`)**:
   - Shown when the member is outside the perimeter.
   - Displays: `Off-Site (X km from [Gym Name])`.
3. **Live Geolocation Watcher**:
   - Uses `navigator.geolocation.watchPosition(...)` so if a member opens their pass while walking in from the parking lot, the badge automatically transitions from *Off-Site* to *On-Site Verified* in real time as they step through the door!

---

## 9. 20-Minute Temporary Exit Grace Period Buffer ("Stepping Out")

### 9.1 The Problem
When a member is actively checked into a fitness club, strict geofence auto-checkout introduces unwanted friction in real-world scenarios:
- Stepping out to a parked vehicle to grab wrist wraps, weightlifting belts, or a shaker bottle.
- Stepping across the street or running home briefly (within 5–10 minutes) for forgotten gear.
- Taking an urgent phone call outside where reception is better or background music is quieter.

Immediately closing the session would split attendance records, disrupt engagement streak scoring, and needlessly revoke claimed equipment or floor access.


### 9.2 What is the Check-Out / Exit Radius?
There is no separate static "check-out radius" constant. Instead, **the check-out (stepping-out) boundary is governed by the gym's configured geofence radius (`geofence_radius_meters`)**:
- **Default Radius**: **100 meters** (database default on the `gyms` table).
- **Admin Configurable**: Each gym administrator can customize their facility's geofence perimeter in **Admin &rarr; Gym Profile** (`pages/admin/gym_profile.php`) anywhere between **20 meters** and **1,000 meters** to match the size of their property or gym campus.
- **Client Floor Minimum**: The member client enforces a minimum fallback of `50 meters` (`max(50, gym.geofence_radius_meters)`).

#### How the Perimeter Threshold Works:
1. **On-Site (`distance <= radius`)**:
   - The member is considered inside the facility. No exit timer is running.
2. **Exit Triggered / Stepped Out (`distance > radius`)**:
   - The moment GPS measures `distance > radius`, the system detects an exit (`geofence_exit`).
   - The **20-minute grace period buffer** starts (tracked in Redis and MySQL).
3. **Checkout Finalized**:
   - If the member stays outside (`distance > radius`) for the full 20 minutes without returning, the session is officially checked out (`check_out_time = exit_detected_at`).
   - If the member returns inside (`distance <= radius`) within 20 minutes, the grace period is cancelled and the session continues uninterrupted.

### 9.3 Architecture & State Flow

```
                      +-----------------------------+
                      |   Member Checked In         |
                      | (check_out_time IS NULL)    |
                      +--------------+--------------+
                                     |
                         Member exits perimeter
                        (distance > gym.radius)
                                     |
                                     v
                      +-----------------------------+
                      | Start 20-Min Exit Grace     |
                      | exit_detected_at = NOW()    |
                      | expires_at = NOW() + 20m    |
                      | Set Redis TTL = 1200s       |
                      +--------------+--------------+
                                     |
                    +----------------+----------------+
                    |                                 |
         Member re-enters perimeter         20 minutes expire
           within 20-min window             without re-entry
                    |                                 |
                    v                                 v
    +-------------------------------+   +-------------------------------+
    | Cancel Grace Period           |   | Auto-Checkout Finalized       |
    | Clear Redis Keys              |   | check_out_time = exit_time    |
    | exit_grace_expires_at = NULL  |   | Release equipment sessions    |
    | Session Resumes Unbroken      |   | Attendance session closed     |
    +-------------------------------+   +-------------------------------+
```

### 9.4 Client-Side Experience
1. **Interactive Floating Banner (`#gym-stepping-out-banner`)**:
   - Displayed in warm amber/gold hues with an animated radar pulse icon.
   - Shows live countdown clock: `🏃 Stepping Out • Outside Gym • 19:45`.
   - Clear explanatory text: *"Grabbed something or stepped out? Your check-in session is kept active. Return within 20 mins to resume uninterrupted."*
2. **One-Tap Early Checkout Option**:
   - If the member actually intended to leave for good, they can tap **"Check Out"** on the banner to close their session immediately without waiting for the 20-minute buffer to expire.
3. **Automatic Restoration on Return**:
   - As soon as GPS detects `distance <= radius`, the client notifies the API (`action: 'geofence_reenter'`), removes the grace banner, and displays an unobtrusive toast notification: *"Welcome back! Your check-in session continues uninterrupted."*

---

## 10. Redis In-Memory TTL Architecture & Live System Scaling

To support high-concurrency production environments with thousands of simultaneous members walking in and out of gym perimeters, FitTracks implements a **Hybrid Redis + MySQL Architecture**.

### 10.1 High-Speed In-Memory Layer (`core/redis_client.php`)
- **Key Namespace**:
  - `ft:exit_grace:{attendance_id}` &rarr; JSON string `{ attendance_id, user_id, gym_id, exit_detected_at, expires_at }` with self-expiring TTL = 1200 seconds (20 minutes).
  - `ft:user_exit_grace:{user_id}` &rarr; `attendance_id` with TTL = 1200 seconds.
- **Sub-Millisecond Polling**:
  - When the client requests grace status (`action: 'get_status'`), the backend queries Redis directly:
    ```php
    $ttl = RedisClient::ttl("ft:exit_grace:{$attendanceId}");
    ```
  - This eliminates millions of repetitive `SELECT` queries against MariaDB during peak rush hours (5:00 PM – 8:00 PM).

### 10.2 Automatic Graceful Fallback (Zero-Downtime Resilience)
If the Redis server is unreachable, undergoing maintenance, or running in an environment without `ext-redis` (such as local development XAMPP):
- `RedisClient::isConnected()` detects this immediately (with a 0.5-second non-blocking connection timeout) and returns `false`.
- All operations seamlessly and transparently fall back to MySQL columns in the `attendance` table:
  - `exit_detected_at DATETIME DEFAULT NULL`
  - `exit_grace_expires_at DATETIME DEFAULT NULL`
  - Indexed via `idx_att_exit_grace (check_out_time, exit_grace_expires_at)` for high-performance index-only scans.
- No exceptions or errors are raised; the system continues operating normally.

### 10.3 Background Cron & Sweeper Integration (`cron.php`)
In addition to on-demand client checks, the automated scheduler runs a sweep:
```bash
# Production Crontab (Runs every 5 minutes)
*/5 * * * * php /path/to/fittracks/cron.php >> /path/to/logs/cron.log 2>&1
```
- **Step 1b**: Sweeps any active attendance records where `exit_grace_expires_at <= NOW() AND check_out_time IS NULL`.
- Sets `check_out_time = exit_detected_at`.
- Invokes `release_user_equipment_on_checkout($userId)` to ensure gym equipment queue slots are freed up for waiting members.

### 10.4 Production Environment Configuration
Configure the Redis connection settings via environment variables (e.g. on Render):

#### Option A: Single Render Connection String (Recommended for Render)
When you spin up a Redis service on Render, copy the **Internal Redis URL** into your web service's Environment tab:
```env
REDIS_URL=redis://default:your_password@red-cu1234567890:6379
```

#### Option B: Discrete Parameters
```env
REDIS_ENABLED=true
REDIS_HOST=red-cu1234567890
REDIS_PORT=6379
REDIS_PASSWORD=your_password
```

#### Note on Optional Variables:
- **`REDIS_TIMEOUT`**: **Optional** (defaults to `0.5` seconds). You do **not** need to add this in Render unless you want a different socket timeout.
- **`REDIS_ENABLED`**: **Optional** (defaults to `true`). If Redis is temporarily disabled or not provisioned, the system gracefully falls back to MySQL.



