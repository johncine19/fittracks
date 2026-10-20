# Floor Equipment Management & Trainer Circuit Allocation System

This document details the architecture, operational rules, and technical implementation of FitTrack's **Floor Equipment Management** and **Trainer Circuit Allocation System**.

---

## 1. Overview & Architecture

To optimize floor utilization, streamline check-ins, and prevent equipment hogging, the system transitions equipment access away from cramped pop-up modals to a dedicated, high-performance **Floor Equipment Hub** (`pages/member/equipment.php`).

### 1.1 Key Modules
- **Floor Equipment Hub (`pages/member/equipment.php`)**: Accessible by both **Members** and **Trainers**. Displays real-time equipment status, queue waitlists, workout timers, and zone filters.
- **Real-Time Polling API (`pages/shared/equipment_api.php`)**: Optimized backend API handling session initiation, queue claims, automatic expiration sweeps, and state reconciliation.
- **Interactive UI Controller (`assets/js/pages/member_equipment.js`)**: Manages real-time DOM updates, dynamic polling intervals, card flips, audio alerts, and trainer circuit controls.
- **Attendance Check-In Redirect (`pages/member/qr_attendance.php`)**: After optical or digital QR check-in, the user receives a brief celebratory notification and is smoothly routed to the equipment hub.

---

## 2. Floor Equipment Hub Features

### 2.1 Horizontal Zone Category Quick-Tabs Bar
- Positioned prominently at the top of the hub.
- Displays responsive, pill-shaped zone chips: `[ All Stations ]`, `[ Free Weights ]`, `[ Cardio ]`, `[ Strength / Machines ]`, `[ Functional / Turf ]`, etc.
- Clicking any tab syncs the query parameter (`?category=...`) and instantly filters the grid with live station counts, eliminating redundant select dropdowns.

### 2.2 Dual-Faced 3D Flip Cards
- **Front Face**: Displays category, unit pill identifier (e.g., `#LP-02`), location area, condition grade, live occupant info (with elapsed minutes), queue count, and contextual action buttons.
- **Back Face**: An aesthetic dual-layer gym photography stage with a 100% full-bleed background blur layer and a contained foreground hero image with zero image cropping.

### 2.3 Live Queue & Claim Window
- When a machine is in use, members can join a FIFO queue.
- When an active session concludes, the first member in line receives a **2-minute claim window** (`claim_deadline`).
- The system plays an audible chime (`window.playNotifSound('ready')`) and pulses a bright green claim card on their screen.

---

## 3. Trainer Equipment Circuit Allocation

Trainers conducting 1-on-1 personal training or group classes need the flexibility to set up multi-station circuits without violating gym etiquette or creating machine shortages for general members.

### 3.1 Dual-Mode Session Initiation
When a trainer clicks **Use / Assign Station**, an interactive dialog presents two workflows:
1. **Assign to Client(s)**: Allocates the equipment to one or more checked-in clients.
2. **Myself (Coach)**: Personal workout session for the trainer (limited to 1 concurrent personal session).

### 3.2 Client Selection & "Select All" Toggle
- Shows a list of all assigned personal clients and scheduled class attendees who are **physically checked in at the gym today**.
- Displays each client's current status:
  - `Checked In`: Ready for station assignment.
  - `On [Station Name] (will rotate)`: Indicates the client is currently working on another machine.
- **Select All / Deselect All** button: Allows the trainer to select their entire group with a single click.

### 3.3 Group & Partner Sets
- Multiple clients can be assigned to a single equipment station simultaneously (recorded in `partner_user_ids`).
- Displays on floor cards as e.g., `Alice + group (Coach Lyod)`.
- Encourages paired rest-work intervals and saves floor equipment for other gym members.

### 3.4 Live Coach Circuit Banner
- Displays all concurrent equipment stations operated by the trainer.
- Cards show:
  - Station name and unit number.
  - Assigned client name and `+ Partner Set` badge.
  - Live individual workout timers.
  - **Finish / Release** button for quick machine turnaround.

---

## 4. Fair-Play & Anti-Hogging Floor Rules

To ensure fair floor access during peak gym hours, strict guardrails are enforced on both client and server:

| Rule | Enforcement Level | Description |
|---|---|---|
| **Physical Check-In Required** | Server + Client Gate | Live sessions and waitlists require an active `attendance` check-in today without checkout. Remote machine reservation from home is blocked. |
| **1 Station Per Present Client** | Server (`equipment_api.php`) | A trainer cannot hoard stations. The maximum number of concurrent active circuit stations is strictly capped to the number of physically checked-in clients (`maxAllowed = max(1, checkedInClients)`). |
| **Automatic Station Rotation** | Server Transaction | If a client is currently on Station A and the coach assigns them to Station B, the system automatically marks Station A as completed, freeing it for other members. |
| **No Ghost Sessions** | Server Polling Sweeps | Expired claim windows (120s) and orphaned sessions are swept and released automatically by `reconcile_equipment_states()`. |
| **Personal Session Limit** | Server + Client | A member or trainer in personal mode can only hold 1 active equipment session at a time. |

---

## 5. Database Schema Additions

Executed in migration scripts and tracked in `migrate.php`:

```sql
-- Track trainer ownership and partner sets in active equipment sessions
ALTER TABLE equipment_sessions 
    ADD COLUMN trainer_user_id INT UNSIGNED NULL DEFAULT NULL AFTER user_id,
    ADD COLUMN partner_user_ids TEXT NULL DEFAULT NULL AFTER trainer_user_id,
    ADD INDEX idx_trainer_user_id (trainer_user_id);
```

### Column Definitions
- `trainer_user_id`: Stores the user ID of the trainer who initiated the session on behalf of clients.
- `partner_user_ids`: Comma-separated user IDs of additional clients sharing the station for partner/group sets.

---

## 6. API Reference (`pages/shared/equipment_api.php`)

### `POST /index.php?page=equipment_api&action=poll`
- **Permissions**: `member`, `trainer`, `staff`, `admin`.
- **Response**:
  ```json
  {
    "success": true,
    "is_checked_in": true,
    "active_session": { ... },
    "my_queues": [ ... ],
    "equipment": [ ... ],
    "trainer_clients": [
      {
        "user_id": 14,
        "first_name": "Alice",
        "last_name": "Guillermo",
        "active_equipment_name": null
      }
    ],
    "trainer_sessions": [
      {
        "session_id": 42,
        "equipment_id": 7,
        "name": "Lat Pulldown",
        "unit_number": "LP-02",
        "primary_client_name": "Alice Guillermo",
        "partner_user_ids": "18,22",
        "elapsed_seconds": 184
      }
    ]
  }
  ```

### `POST /index.php?page=equipment_api&action=start_session`
- **Parameters**:
  - `equipment_id`: ID of available equipment.
  - `target_mode`: `'self'` or `'clients'`.
  - `client_user_ids[]`: Array of selected client user IDs (required when `target_mode === 'clients'`).
- **Validations**:
  - Validates physical check-in status.
  - Enforces `maxAllowedStations = count(checkedInClients)`.
  - Automatically completes prior session for any rotated clients.

### `POST /index.php?page=equipment_api&action=finish_session`
- **Parameters**:
  - `session_id`: ID of the session to end.
- **Permissions**: Allowed for the session member, the initiating trainer (`trainer_user_id`), or gym staff/admin.
