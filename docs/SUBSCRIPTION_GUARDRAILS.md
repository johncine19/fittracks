# Subscription & Membership Guardrails Specification

This document details the anti-hoarding, renewal window enforcement, and checkout session lifecycle guardrails implemented for **FitTrack**. It covers both **Gym SaaS Platform Subscriptions** (purchased by Commercial Gym Owners) and **Member Gym Subscriptions** (purchased by gym members online or recorded at the front desk).

---

## 1. Problem Statement & Motivation

Prior to these guardrails, the subscription engines had no constraints on purchase timing or queued volume:
1. **Gym SaaS Over-Purchasing / Premature Renewal**:
   - A gym owner could repeatedly initiate checkouts for their current active plan weeks or months before expiration.
   - If paid prematurely, the existing subscription period would reset or conflict with current terms, risking lost days and creating unnecessary billing disputes.
2. **Checkout Session Spam & Zombie Sessions**:
   - Opening multiple payment modals without completing checkout generated dozens of orphaned `pending` rows in `gym_subscription_payments` and `payments`.
3. **Member Membership Infinite Stacking**:
   - Gym members could repeatedly queue memberships months or years into the future, creating unbounded financial liabilities for gyms and inventory booking conflicts.
4. **Webhook Rollover Discrepancy**:
   - The Xendit payment webhook lacked the `subscription_plan` column in its lock query, preventing proper rollover date extension on renewals.

---

## 2. Guardrails Architecture & Policy Matrix

| Tier | Policy | Trigger Condition | System Action | UI Presentation |
| :--- | :--- | :--- | :--- | :--- |
| **Gym SaaS Plan** | **7-Day Renewal Window** | Active plan, same plan selected, `days_left > 7` | Block POST; flash warning notice; redirect to `gym_subscription` | Button disabled with `Active (X days left)`; displays `Renewals open on [Date]` |
| **Gym SaaS Plan** | **Two-Way Downgrade Guardrail** | Active plan, lower tier selected (e.g. Pro ➔ Starter), `days_left > 7` | Block POST; flash warning notice; redirect to `gym_subscription` | Button disabled with `Active on [Higher Tier]`; displays `Downgrades open on [Date]` |
| **Gym SaaS Plan** | **Plan Upgrade with Notice** | Active plan, higher tier selected (e.g. Pro ➔ Business) | Allowed immediately | Enabled button labeled `Upgrade to [Plan]`; payment modal shows prominent Upgrade Notice banner |
| **Gym SaaS Plan** | **Stale Pending Cleanup** | Gym owner initiates new checkout | Marks all existing `pending` rows for this gym as `failed` | Single active Xendit checkout link at any given time |
| **Member Subscription** | **7-Day Renewal Window** | Active membership, same plan selected, `days_left > 7` | Block POST; flash warning notice; redirect to `memberships` | Button disabled with `Active (Xd left)`; displays `Renewals open [Date]` |
| **Member Subscription** | **Two-Way Downgrade Guardrail** | Active membership, lower priced plan selected, `days_left > 7` | Block POST; flash warning notice; redirect to `memberships` | Button disabled with `Active on [Higher Tier]`; displays `Downgrades open on [Date]` |
| **Member Subscription** | **Plan Upgrade with Notice** | Active membership, higher priced plan selected | Allowed immediately | Enabled button labeled `Upgrade to [Plan]`; modal displays amber Upgrade Notice banner |
| **Member Subscription** | **Advance Queue Limit** | Member already has a queued cycle (`start_date > CURDATE()`) | Block POST; flash warning notice; redirect to `memberships` | Disallows multi-cycle stacking beyond 1 cycle in advance |
| **Member Subscription** | **Stale Pending Cleanup** | Member requests a new plan (Cash or Xendit) | Updates older `pending` payments to `overdue` and memberships to `cancelled` | Replaces abandoned pending requests cleanly |
| **Front Desk / Admin** | **365-Day Safety Ceiling** | Admin records manual payment with `end_date > 365 days` | Rejects stacking; flashes advisory notice | Prevents accidental multi-year stacking loops |

---

## 3. Implementation Details

### 3.1 Gym Owner Platform Subscriptions (`pages/gym_owner/gym_subscription.php`)

#### Calculation of Remaining Validity & Plan Tiers
```php
$currentPlan = strtolower((string)($gym['subscription_plan'] ?? ''));
$hasActiveSub = ($gym['subscription_status'] === 'active' && !empty($gym['subscription_plan']));
$gymRenewalDate = !empty($gym['subscription_renewal_date']) ? (string)$gym['subscription_renewal_date'] : null;
$gymDaysLeft = null;
if ($hasActiveSub && $gymRenewalDate) {
    $gymDaysLeft = (int)ceil((strtotime($gymRenewalDate) - strtotime('today')) / 86400);
}

// Plan tier hierarchy (Starter = 1, Pro = 2, Business = 3)
$planRank = [];
$pRankIndex = 1;
foreach ($plans as $pk => $pVal) {
    $planRank[$pk] = $pRankIndex++;
}
$currentPlanRank = $planRank[$currentPlan] ?? 0;
```

#### Server-Side Enforcement (POST Handler)
```php
// Guardrail 1: Disallow early renewal of the same plan if > 7 days remain
if ($hasActiveSub && $currentPlan === $selectedKey && $gymDaysLeft !== null && $gymDaysLeft > 7) {
    flash("Your {$planName} subscription is active with {$gymDaysLeft} days remaining. Renewals open 7 days before your renewal date (" . date('M j, Y', strtotime($gymRenewalDate)) . ").", 'warning');
    redirect('gym_subscription');
}

// Guardrail 2 (Two-Way Rule): Disallow early downgrade if > 7 days remain on higher tier
$selectedPlanRank = $planRank[$selectedKey] ?? 0;
if ($hasActiveSub && $currentPlanRank > 0 && $selectedPlanRank < $currentPlanRank && $gymDaysLeft !== null && $gymDaysLeft > 7) {
    $curName = $plans[$currentPlan]['name'] ?? 'current tier';
    flash("You currently have an active {$curName} subscription with {$gymDaysLeft} days remaining. Downgrading to {$planName} is only available within 7 days of your renewal date (" . date('M j, Y', strtotime($gymRenewalDate)) . ") so you don't forfeit your {$curName} features early.", 'warning');
    redirect('gym_subscription');
}

// Guardrail 3: Cancel any older pending payments for this gym to prevent checkout spam / stacking
$pdo->prepare('UPDATE gym_subscription_payments SET status = "failed" WHERE gym_id = ? AND status = "pending"')
    ->execute([(int)$gym['gym_id']]);
```

#### Client-Side UI & Upgrade Warning Banner
In `gym_subscription.php`:
```php
<?php if ($isRenewalLocked): ?>
    <button type="button" class="pricing-btn current-locked-btn" disabled>
        Active (<?= $gymDaysLeft ?> days left)
    </button>
    <div class="renewal-note">
        Renewals open on <?= date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) ?>
    </div>
<?php elseif ($isDowngradeLocked): ?>
    <button type="button" class="pricing-btn current-locked-btn" disabled>
        Active on <?= h($plans[$currentPlan]['name'] ?? 'Higher Tier') ?>
    </button>
    <div class="renewal-note">
        Downgrades open on <?= date('M j, Y', strtotime('-7 days', strtotime($gymRenewalDate))) ?>
    </div>
<?php else: ?>
    <button type="button" 
            class="pricing-btn <?= $isPopular ? 'popular-btn' : 'standard-btn' ?>"
            onclick="openPaymentModal('<?= h($key) ?>', '<?= h($plan['name']) ?>')">
        <?= $isCurrent ? 'Renew Plan' : ($isUpgrade ? 'Upgrade to ' . h($plan['name']) : 'Switch to ' . h($plan['name'])) ?>
    </button>
<?php endif; ?>
```
In `assets/js/pages/gym_subscription.js`:
```javascript
function openPaymentModal(key, name) {
    if (window.isCurrentPlanLocked && key === window.currentPlanKey) {
        const days = window.currentPlanDaysLeft || '';
        const msg = `Your ${name} plan is currently active with ${days} days remaining. Renewals open 7 days prior to expiry.`;
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'Plan Active',
                text: msg,
                confirmButtonColor: '#84cc16'
            });
        } else {
            alert(msg);
        }
        return;
    }
    // Proceed to open modal...
}
```

---

### 3.2 Webhook Rollover Extension (`core/xendit.php`)

In `xendit_handle_gym_subscription_payment_webhook()`:
1. Updated lock query to include `subscription_plan`:
   ```sql
   SELECT subscription_plan, subscription_status, subscription_renewal_date 
   FROM gyms WHERE gym_id = ? FOR UPDATE
   ```
2. Seamless renewal date calculation:
   ```php
   if (($gym['subscription_status'] ?? '') === 'active' && !empty($gym['subscription_renewal_date']) && $currentPlan === $newPlan) {
       $renewal = new DateTimeImmutable((string) $gym['subscription_renewal_date']);
       $maxReasonableWindow = $today->modify($billingCycle === 'yearly' ? '+366 days' : '+35 days');
       if ($renewal > $start && $renewal <= $maxReasonableWindow) {
           $start = $renewal;
       }
   }
   $end = xendit_add_billing_period($start, $billingCycle);
   ```

---

### 3.3 Member Gym Subscriptions (`pages/admin/memberships.php`)

#### Renewal Window, Two-Way Downgrade Guardrail & Upgrades
```php
if ($currentActive) {
    $daysUntilEnd = (int)ceil((strtotime($currentActive['end_date']) - strtotime('today')) / 86400);

    if ((int)$currentActive['plan_id'] === $planId) {
        // Guardrail 1: Enforce 7-day renewal window for the same membership plan
        if ($daysUntilEnd > 7) {
            flash("Your {$plan['plan_name']} membership is currently active with {$daysUntilEnd} days remaining. Renewals open 7 days before your expiration date (" . date('M j, Y', strtotime($currentActive['end_date'])) . ").", 'warning');
            redirect('memberships');
        }

        // Guardrail 2: Prevent stacking more than 1 advance renewal cycle
        $alreadyQueued = (int) scalar("SELECT COUNT(*) FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = ? AND mp.gym_id = ? AND m.status = 'active' AND m.start_date > CURDATE()", [$user['user_id'], $planGymId]);
        if ($alreadyQueued > 0) {
            flash("You already have an advance renewal queued through " . date('M j, Y', strtotime($currentActive['end_date'])) . ". Further renewals cannot be queued until your current cycle ends.", 'warning');
            redirect('memberships');
        }

        // Renewal: Queue it from currentActive end_date
        $start = new DateTime($currentActive['end_date']);
        $end = (clone $start)->modify('+' . $plan['duration_days'] . ' days')->format('Y-m-d');
    } else {
        // Guardrail 3 (Two-Way Rule): Disallow early downgrade if > 7 days remain on higher tier
        $currentPlanPrice = (float)($currentActive['old_price'] ?? 0);
        $newPlanPrice = (float)$plan['price'];
        if ($newPlanPrice < $currentPlanPrice && $daysUntilEnd > 7) {
            $curName = $currentActive['plan_name'] ?? 'current tier';
            flash("You currently have an active {$curName} membership with {$daysUntilEnd} days remaining. Downgrading to {$plan['plan_name']} is only available within 7 days of your expiration date (" . date('M j, Y', strtotime($currentActive['end_date'])) . ") so you don't forfeit your {$curName} privileges early.", 'warning');
            redirect('memberships');
        }

        // Same-day upgrade credit
        if (date('Y-m-d', strtotime($currentActive['created_at'])) === date('Y-m-d')) {
            $finalPrice = max(0, $finalPrice - (float)$currentActive['old_price']);
        }
    }
}
```

#### Member Plan UI & Modal Experience
1. **Downgrade Cards**: Disabled button displaying `Active on [Current Higher Plan]` with subtext `Downgrades open on [Date]`. Intercepted on click if clicked programmatically with SweetAlert notice.
2. **Upgrade Cards**: Active button labeled `Upgrade to [Plan]`. Opening the checkout modal renders a dynamic amber alert banner:
   > **Plan Upgrade Notice:** You currently have **X days remaining** on your **[Current Plan]** membership. Upgrading takes effect immediately upon payment confirmation and starts a fresh validity period today. Any remaining days on your previous tier will be superseded.
3. **Same Plan Renewals**: Disabled when `days_left > 7` with `Renewals open on [Date]`. When within 7 days, opens checkout to seamlessly queue onto current expiration date.

#### Stale Pending Checkout Cleanup
```php
// Clean up any older pending memberships and pending payments for this member at this gym
$pdo = db();
$pendingMids = $pdo->query("SELECT m.membership_id FROM memberships m JOIN membership_plans mp ON mp.plan_id = m.plan_id WHERE m.user_id = " . (int)$user['user_id'] . " AND mp.gym_id = " . (int)$planGymId . " AND m.status = 'pending'")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($pendingMids)) {
    $inList = implode(',', array_map('intval', $pendingMids));
    $pdo->exec("UPDATE payments SET status = 'overdue' WHERE membership_id IN ($inList) AND status = 'pending'");
    $pdo->exec("UPDATE memberships SET status = 'cancelled' WHERE membership_id IN ($inList) AND status = 'pending'");
}
```

#### Admin Manual Payment 365-Day Safety Ceiling (`pages/admin/payments.php`)
```php
if ($currentActive) {
    if ((int)$currentActive['plan_id'] === $planId) {
        $daysUntilEnd = (int)ceil((strtotime($currentActive['end_date']) - strtotime('today')) / 86400);
        if ($daysUntilEnd > 365) {
            flash("This member already has active coverage extended over 1 year in advance (until " . date('M j, Y', strtotime($currentActive['end_date'])) . "). Additional renewals cannot be stacked.", 'warning');
            redirect('payments');
        }
        // Proceed with renewal queue...
    }
}
```

---

## 4. Automated Renewal Reminders & Expiration Email Engine

FitTracks pairs in-app notification alerts with automated email delivery for both members and gym owners.

### 4.1 Automated Email Schedule Matrix

| Recipient | Notice Type | Timing | Key Content | Call-to-Action Link |
| :--- | :--- | :--- | :--- | :--- |
| **Member** | **Renewal Reminder** | **3 days before** plan expires | Plan name, gym name, expiration date, days left countdown | `index.php?page=memberships` (1-Click Online Renewal) |
| **Member** | **Expiration Notice** | **When membership expires** (up to 14d post-expiry) | Notification of paused gym check-in & equipment queues | `index.php?page=memberships` (Reactivate & Renew) |
| **Gym Owner** | **Renewal Reminder** | **7 days before** SaaS subscription renews | Platform tier (Starter/Pro/Business), billing cycle, amount, renewal date | `index.php?page=gym_subscription` (Manage & Renew) |
| **Gym Owner** | **Expiration Notice** | **When SaaS plan / trial expires** | Notice of restricted staff accounts, check-in terminals, and analytics | `index.php?page=gym_subscription` (Reactivate Subscription) |

### 4.2 Deduplication Architecture (`renewal_email_log`)

To prevent spamming users across cron runs, all dispatches are tracked in `renewal_email_log` with a composite unique key:

```sql
CREATE TABLE IF NOT EXISTS renewal_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('member', 'gym_owner') NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    notice_type ENUM('reminder', 'expired') NOT NULL,
    reference_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_renewal_notice (recipient_type, target_id, notice_type, reference_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Members**: `target_id = membership_id`, `reference_date = end_date`.
- **Gym Owners**: `target_id = gym_id`, `reference_date = subscription_renewal_date`.
- Ensures **exactly one** reminder and **exactly one** expiration email is delivered per billing cycle.

### 4.3 Scheduled & Just-In-Time Execution
1. **Background Cron (`cron.php`)**:
   Runs daily at midnight via Step 5: `process_all_renewal_email_notices()`.
2. **Just-In-Time Web Visits (`views/layout.php`)**:
   When members or gym owners log into the web application, localized triggers verify if an email has not yet been logged for the current cycle and queues it immediately.

---

## 5. Verification & Testing

The test suite passes all regression, arithmetic, multi-gym, and database concurrency checks:
```bash
php tests/run.php
```
Outputs:
- `PASS: 5 score arithmetic test(s) passed.`
- `PASS: All at-risk batching and boundary tests passed.`
- `PASS: All lock concurrency tests passed.`
- `ALL TEST SUITES PASSED SUCCESSFULLY.`

