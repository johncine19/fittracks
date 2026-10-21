# FitTracks Idempotency & Fault-Tolerant Execution Guide

This document details the idempotency mechanisms, concurrency controls, and double-submit prevention architectures implemented across FitTracks to prevent duplicate charges, unintended state flipping, and race conditions.

---

## Table of Contents
1. [Overview & Core Philosophy](#1-overview--core-philosophy)
2. [Layer 1: Global Form Double-Submit & Rapid-Click Protection](#2-layer-1-global-form-double-submit--rapid-click-protection)
3. [Layer 2: QR Scanner & Attendance Debounce Idempotency](#3-layer-2-qr-scanner--attendance-debounce-idempotency)
4. [Layer 3: Payment Webhook & Subscription Idempotency](#4-layer-3-payment-webhook--subscription-idempotency)
5. [Layer 4: Financial Accruals & Trainer Commissions](#5-layer-4-financial-accruals--trainer-commissions)
6. [Layer 5: Database-Level Idempotency Patterns](#6-layer-5-database-level-idempotency-patterns)
7. [Verification & Failure Matrix](#7-verification--failure-matrix)
8. [Developer Guidelines for New Features](#8-developer-guidelines-for-new-features)

---

## 1. Overview & Core Philosophy

An operation is **idempotent** if executing it once produces the exact same system state as executing it multiple times with the same input:

$$\text{Operation}(X) = \text{Operation}(\text{Operation}(X))$$

In a modern gym management system, distributed network latency, cellular drops, rapid user taps, and automated webhook retries will inevitably trigger duplicate requests. FitTracks employs a multi-tiered defense strategy:

```
[User / Webhook]
       │
       ▼
[Layer 1: Global Client Double-Tap Lock] (views/layout.php)
       │
       ▼
[Layer 2: Application State Debouncing] (pages/admin/scanner.php)
       │
       ▼
[Layer 3: Concurrency Locks & Reference Checks] (core/xendit.php, core/helpers.php)
       │
       ▼
[Layer 4: Atomic DB Constraints] (UNIQUE indexes, ON DUPLICATE KEY UPDATE)
```

---

## 2. Layer 1: Global Form Double-Submit & Rapid-Click Protection

### File: `views/layout.php`

### The Problem
On mobile devices or high-latency cellular connections, members and staff often tap submit buttons multiple times if the page does not immediately reload. This previously caused duplicate form POST requests (e.g. creating two equipment units, sending duplicate emails, or double-logging macros).

### The Implementation
A transparent, non-intrusive event listener is registered in capture mode on the document object:
- **Scope**: Every `POST` `<form>` across all public, member, staff, and admin views.
- **Locking**: Upon dispatch, `form.dataset.submitting = 'true'` is stamped. Any identical submission event in flight is stopped immediately via `e.preventDefault()` and `e.stopPropagation()`.
- **Button Feedback**: Submit buttons automatically have `pointer-events: none` and an `.is-submitting` class applied.
- **Graceful Recovery**: A 3.5-second auto-unlock timeout restores pointer events and clears the submitting flag in case client-side validation fails or an AJAX handler prevents page navigation.

```javascript
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;

    var method = (form.method || 'GET').toUpperCase();
    if (method !== 'POST') return;

    if (form.dataset.submitting === 'true') {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }

    form.dataset.submitting = 'true';
    var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    submitButtons.forEach(function(btn) {
        btn.dataset.prevPointerEvents = btn.style.pointerEvents || '';
        btn.style.pointerEvents = 'none';
        btn.classList.add('is-submitting');
    });

    setTimeout(function() {
        delete form.dataset.submitting;
        submitButtons.forEach(function(btn) {
            btn.style.pointerEvents = btn.dataset.prevPointerEvents || '';
            btn.classList.remove('is-submitting');
        });
    }, 3500);
}, true);
```

---

## 3. Layer 2: QR Scanner & Attendance Debounce Idempotency

### File: `pages/admin/scanner.php`

### The Problem
Camera QR scanners operate at 15–30 frames per second. If a member held their phone up to the scanner, the camera frequently decoded the same token 2 to 3 times in 1–2 seconds. 
Because FitTracks uses a toggle model (check-in if no active visit, check-out if an active visit exists), the second scan would see the visit that was just created 1 second prior and **immediately check the member out**, corrupting gym attendance statistics.

### The Implementation
1. **30-Second Active Check-In Window**:
   When an open attendance record is found (`check_out_time IS NULL`), the backend computes the seconds elapsed:
   ```php
   $secondsSinceCheckIn = time() - strtotime($openRecord['check_in_time']);
   if ($secondsSinceCheckIn < 30) {
       echo json_encode([
           'success' => true,
           'action' => 'checkin',
           'is_duplicate' => true,
           'message' => $member['first_name'] . ' ' . $member['last_name'] . ' was already checked in ' . max(1, $secondsSinceCheckIn) . 's ago.',
           ...
       ]);
       exit;
   }
   ```
   A re-scan during this window is recognized as an idempotent duplicate: the active visit is left open, and the UI displays a clean notification without database mutation.

2. **Offline Sync Idempotency**:
   When offline front-desk terminals reconnect and flush cached local scans, the sync worker verifies whether an attendance entry with the exact timestamp already exists:
   ```php
   $alreadyRecorded = scalar('SELECT attendance_id FROM attendance WHERE user_id = ? AND (check_in_time = ? OR check_out_time = ?) LIMIT 1', [$userId, $scannedAt, $scannedAt]);
   if ($alreadyRecorded) {
       $syncedIds[] = $tempId;
       continue;
   }
   ```
   Repeated offline batch pushes acknowledge synced entries (`$syncedIds[]`) without inserting duplicate logs.

---

## 4. Layer 3: Payment Webhook & Subscription Idempotency

### File: `core/xendit.php`

### The Problem
Payment gateways (such as Xendit) employ automated retry schedules. If the application server encounters network delays in replying with HTTP `200 OK`, the gateway resends the completed payment webhook payload.

### The Implementation
FitTracks secures webhook endpoints with strict database transaction locking and status preconditions:
1. **Pessimistic Row Locking (`FOR UPDATE`)**:
   ```sql
   SELECT * FROM payments WHERE payment_id = ? FOR UPDATE;
   ```
   Any concurrent webhook thread blocks until the first transaction commits or rolls back.
2. **Precondition State Check**:
   ```php
   if (!$lockedPayment || $lockedPayment['status'] !== 'pending') {
       $pdo->commit();
       http_response_code(200);
       echo json_encode(['success' => true, 'duplicate' => true]);
       return;
   }
   ```
   If the payment has already transitioned to `paid`, subsequent webhook calls immediately acknowledge receipt with HTTP `200` (`duplicate => true`) without re-running subscription date extensions, emails, or notifications.

---

## 5. Layer 4: Financial Accruals & Trainer Commissions

### File: `core/helpers.php`

### The Problem
When a membership plan with an active trainer commission is purchased, automated triggers (`xendit_handle_membership_payment_webhook`, manual receipt issuance, or retroactive commission reconciliations) call `process_trainer_commission()`. Without an idempotency check, re-triggering would add duplicate pending payouts to the trainer's account.

### The Implementation
`process_trainer_commission()` checks for existing credits tied to the payment before processing:
```php
function process_trainer_commission(int $paymentId, float $amount): void
{
    $pdo = db();
    // Idempotency: Ensure commission is not credited multiple times for the same payment
    $alreadyCredited = (bool) scalar('SELECT 1 FROM trainer_commissions WHERE payment_id = ? LIMIT 1', [(int)$paymentId]);
    if ($alreadyCredited) {
        return;
    }
    ...
}
```
Regardless of how many times the function is called for a payment, exactly one commission record is created.

---

## 6. Layer 5: Database-Level Idempotency Patterns

Across the schema, FitTracks reinforces application logic with database-level uniqueness constraints so that even if concurrent race conditions bypass application memory, the storage engine guarantees zero duplicate state mutations.

### Database Migrations (`migrate.php`)
```sql
-- 1. Enforces exactly one commission record per payment ID
CREATE UNIQUE INDEX uq_trainer_comm_payment ON trainer_commissions (payment_id);

-- 2. Prevents duplicate check-in insertions for the same user at the exact same timestamp
CREATE UNIQUE INDEX uq_att_user_checkin_exact ON attendance (user_id, check_in_time);
```

### Complete Schema Idempotency Matrix
| Table | Constraint / Mechanism | Protection |
| :--- | :--- | :--- |
| `trainer_commissions` | `UNIQUE KEY uq_trainer_comm_payment (payment_id)` | **New**: Eliminates multi-crediting payouts for the same payment transaction under concurrency. |
| `attendance` | `UNIQUE KEY uq_att_user_checkin_exact (user_id, check_in_time)` | **New**: Rejects replay attacks or duplicate batch syncs from front-desk scanners. |
| `payments` | `UNIQUE KEY uq_payment_xendit_reference (xendit_reference_id)`<br>`UNIQUE KEY uq_payment_xendit_session (xendit_session_id)` | Prevents duplicate member invoice records during online gateway checkouts. |
| `gym_subscription_payments` | `UNIQUE KEY uq_sub_xendit_reference (xendit_reference_id)`<br>`UNIQUE KEY uq_sub_xendit_session (xendit_session_id)` | Prevents duplicate gym owner SaaS tier subscription renewals. |
| `checkout_ratings` | `UNIQUE KEY uq_attendance (attendance_id)` + `ON DUPLICATE KEY UPDATE` | A member can only submit one rating per workout session. Repeated checkouts update the rating rather than creating duplicate reviews. |
| `member_meal_logs` | `UNIQUE KEY uq_user_meal_date (user_id, meal_type, log_date)` | Enforces exactly one meal record per meal type (breakfast, lunch, etc.) per day. |
| `gym_members` | `INSERT IGNORE INTO gym_members (user_id, gym_id)` | Prevents duplicate user-gym affiliation rows during membership purchases or scans. |
| `gym_equipment` | `UNIQUE KEY uq_gym_equip_unit (gym_id, name, unit_number)` | Prevents duplicate physical machine registrations on the gym floor. |
| `gym_ratings` | `UNIQUE KEY uq_member_gym_rating (user_id, gym_id)` | Restricts member gym reviews to one verified submission per gym. |

---

## 7. Verification & Failure Matrix

| Scenario | Behavior Without Idempotency | Behavior With FitTracks Idempotency |
| :--- | :--- | :--- |
| **Member double-clicks "Submit" on manual check-in** | Two attendance records created within 100ms; user immediately checked out or logged twice. | First click submits; second click ignored by `dataset.submitting` lock. Scanner debounces any sub-30s duplicate. |
| **Scanner camera reads QR token twice in 1.5 seconds** | Member checked in on frame 1; member checked out on frame 2. | First frame checks in member; second frame returns friendly *"already checked in"* notice. |
| **Xendit resends webhook after network timeout** | Membership dates extended twice; trainer gets double commission; owner gets duplicate emails. | Webhook detects `status != 'pending'`, returns `{success: true, duplicate: true}` and exits safely. |
| **Offline terminal reconnects and syncs queue twice** | Multiple attendance rows inserted with the same timestamp. | Duplicate timestamps recognized and skipped; queue acknowledged cleanly. |
| **Commission calculation triggered multiple times** | Trainer wallet balance credited multiple times for one membership. | `SELECT 1 FROM trainer_commissions` halts execution on duplicate calls. |

---

## 8. Developer Guidelines for New Features

When creating new endpoints, forms, or background workers, adhere to these guidelines:

1. **Never mutate on GET**: All state-changing endpoints must use `POST` or `PUT`.
2. **Use Database Uniqueness**: Whenever a record represents a 1-to-1 relationship with a transaction or event (e.g. payout, receipt, scan), enforce a `UNIQUE` index on the reference column.
3. **Atomic Conditional Updates**: Prefer conditional updates over read-modify-write:
   ```sql
   -- Correct (Atomic & Idempotent)
   UPDATE memberships SET status = 'active' WHERE id = ? AND status = 'pending';
   
   -- Avoid (Vulnerable to Race Conditions)
   $status = query("SELECT status FROM memberships WHERE id = ?");
   if ($status === 'pending') query("UPDATE memberships SET status = 'active'");
   ```
4. **Debounce Interactive Toggles**: For physical device interactions (QR cameras, NFC readers, barcode scanners), always maintain a minimum 15–30 second debounce threshold between opposing state toggles.
