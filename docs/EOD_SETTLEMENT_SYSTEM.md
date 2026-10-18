# End-of-Day (EOD) Daily Settlement System & Shift Guardrails

This document specifies the architecture, dispatch rules, time gates, and multi-staff anti-spam guardrails for the **FitTracks End-of-Day (EOD) Daily Settlement (Z-Reading)** engine.

---

## 1. System Overview

FitTracks provides an executive daily settlement slip summarizing:
* **Total Gross Revenue** (Subscriptions/Memberships + Walk-in passes)
* **Transaction Count**
* **Facility Check-ins**
* **Memberships Expiring Tomorrow**
* **Total Active Enrolled Members**

The settlement slip can be delivered via **Automated Midnight Cron** or **Staff/Admin Shift Closing**.

---

## 2. Dual-Dispatch Architecture

```
                  ┌────────────────────────────────────────────────────────┐
                  │                 FitTracks EOD System                   │
                  └──────────────────────────┬─────────────────────────────┘
                                             │
                  ┌──────────────────────────┴──────────────────────────┐
                  ▼                                                     ▼
      [Automated Midnight Cron]                             [Manual Shift Closing]
     • Triggers daily at 12:00:00 AM PHT                   • Available 5:00 PM – 11:59 PM PHT
     • Computes full 24-hr period (yesterday)              • Triggered by Staff or Gym Owner
     • Finalizes unclosed attendance at 23:59:59           • In-modal transparency banner
     • De-duplicates via gym_eod_email_log                 • Locks for other staff once sent today
```

---

## 3. The Literal EOD Gate & Operating Hours

To respect the literal business meaning of an **End-of-Day (EOD)** closing report, manual email dispatches are governed by a daily time gate in Philippine Time (`Asia/Manila` / UTC+8). By default, this unlocks at **5:00 PM**, but can be customized by gym owners in **Gym Profile / Branding**:

| Time Window | EOD Gate Status | Permitted Actions |
| :--- | :--- | :--- |
| **12:00:00 AM – Gate Cutoff** | 🔒 **Locked** | Business day is underway. Email button is locked with status `Opens [Time]` (e.g. `Opens 5 PM`). Physical counter slip printing (`Print Slip`) is permitted 24/7. |
| **Gate Cutoff – 11:59:59 PM** | 🔓 **Open** | Staff shift closing window. Front desk staff or owners can dispatch the official EOD settlement email. |
| **12:00:00 AM (Midnight)** | ⚙️ **Automated Cron** | Calendar rolls over. `cron.php` processes finalized figures for yesterday and resets the manual gate for the new day. |

### 3.1 Owner Customization & Next-Day Activation Freeze
Gym owners (Business Tier / Custom Branding) can adjust their facility's EOD gate opening hour under **Gym Profile → Branding Settings**:
* **Options**: `12:00 PM` to `11:00 PM` (Default: `5:00 PM`).
* **Next-Day Activation Policy**: To safeguard financial shift integrity and prevent mid-day shift manipulation, any changes made to the EOD gate time **do not alter the current operating day**. Changes are saved as `pending_eod_gate_time` and automatically become active the **next calendar day** (`eod_gate_effective_date`).
* **SweetAlert Confirmation**: When the owner selects a new time, an interactive SweetAlert dialog confirms the new schedule and notifies them that today's gate remains intact while the new gate takes effect tomorrow.

---

## 4. Multi-Staff Guardrails & Anti-Spam Protection

In facilities with multiple front desk staff or overlapping shift schedules, the following guardrails prevent duplicate emails and inbox flooding:

### 4.1 Gym-Wide Lock (`gyms.last_eod_email_sent_at` & `last_eod_email_sent_by`)
* The dispatch state is tracked at the **gym level**, not per-user.
* When **Staff Member A** emails the settlement slip after 5:00 PM, the gym's record is stamped with `NOW()` and `user_id`.
* When **Staff Member B** opens the settlement modal:
  * The button turns into a locked badge: `Already Sent ([Time])`.
  * The modal displays a prominent audit banner:  
    `✓ EOD settlement already emailed today at [Time] by [Staff Name] ([Role]).`
  * Any repeated POST to the backend is rejected with HTTP 200 JSON `success: false`.

### 4.2 Shift Handover Tag in Owner Email
* The email template (`Emails::sendEODSummary`) embeds the sender's identity in the email body:
  > **Shift Handover:** Dispatched by **Maria Santos** (Front Desk Staff) at 5:15 PM.
* The gym owner knows exactly which staff member closed out the drawer and verified the numbers.

### 4.3 Confirmation Prompt
* Clicking "Email Owner" triggers a SweetAlert2 confirmation dialog:
  > *"Send Shift Closing Settlement? This will officially email the Z-Reading settlement to the gym owner with your staff shift signature."*
* Eliminates accidental clicks and ensures intentional dispatch.

---

## 5. Automated Midnight Cron Specifications (`cron.php`)

* **Endpoint**: `GET /cron.php?key=fittracks_secret_cron_2026`
* **Schedule**: Daily at `12:00:00 AM Asia/Manila` via external scheduler (e.g. cron-job.org).
* **Scope**: Summarizes `yesterday` (allowing auto-checkout to close open attendance sessions at `23:59:59` first).
* **Duplicate Protection**: Claims a row in `gym_eod_email_log (gym_id, report_date)`. If the cron runs multiple times on the same date, existing entries are skipped as `already_queued`.
* **Zero-Activity Coverage**: Zero-activity days are included. The report communicates ₱0.00 revenue and 0 check-ins alongside active enrollment and expiring plans, providing a daily heartbeat.

---

## 6. Eligibility Criteria for Gyms

For an automated or manual EOD settlement email to be sent:
1. **Subscription Plan**: Gym must be on an active **Business** plan tier (`eod_summary` feature enabled in `core/subscription_service.php`).
2. **Gym Profile Setting**: The **Automated Midnight EOD Revenue Digest** toggle (`gyms.eod_email_summary`) must be `1`.
3. **Gym Status**: `gyms.status` must be `approved`.
4. **Owner Email**: Gym owner account must have a valid email address configured.

---

## 7. Database Schema Reference

### `gym_eod_email_log`
```sql
CREATE TABLE IF NOT EXISTS gym_eod_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    gym_id INT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gym_eod_email_date (gym_id, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `gyms` EOD Tracking Columns
```sql
ALTER TABLE gyms ADD COLUMN eod_email_summary TINYINT(1) DEFAULT 1;
ALTER TABLE gyms ADD COLUMN last_eod_email_sent_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE gyms ADD COLUMN last_eod_email_sent_by INT UNSIGNED NULL DEFAULT NULL;
```
