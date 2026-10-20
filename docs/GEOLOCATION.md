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
- **Frontend View**: [pages/member/gym_selection.php](file:///c:/xampp/htdocs/Fittracks_before_test/pages/member/gym_selection.php) (includes modern `.gym-toolbar`, `#btn-near-me`, view switcher, distance badges, and `#gym-map-wrapper`).
- **Interactive Script**: [assets/js/pages/gym_selection.js](file:///c:/xampp/htdocs/Fittracks_before_test/assets/js/pages/gym_selection.js) (Leaflet map initialization, CartoDB Dark Matter tiles, user radar pulse marker, distance re-ordering, search filter integration).
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
