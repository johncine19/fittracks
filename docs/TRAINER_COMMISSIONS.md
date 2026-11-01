# Trainer Commissions

How FitTrack calculates, records and pays trainer commissions on membership payments.

> **Status:** Implemented November 2026. This replaces the old "one flat % per plan" model.
> **Tier gate:** Gym owners need the `commissions` subscription feature (Professional plan and up).

---

## 1. Overview

When a member's membership payment is marked **paid**, the trainer that member is actively assigned to (at the **same gym** as the plan) earns a commission. 

FitTrack separates **commission eligibility** from **commission rates**:
1. **Eligibility Check**: Is the membership plan enabled for trainer commissions? (`allow_trainer_commission == 1`). If disabled, **0% commission is generated regardless of what individual rate a trainer has**.
2. **5-Level Rate Hierarchy**: When commission is allowed, the base rate is evaluated by cascading down priorities (the first non-NULL rate is used; base rates are never added together):
   - **Level 1 (Highest)**: Trainer + Membership Plan Override (`trainer_plan_commission_overrides`)
   - **Level 2**: Trainer Base Rate (`trainer_profiles.commission_rate`)
   - **Level 3**: Membership Plan Default Rate (`membership_plans.commission_rate`)
   - **Level 4**: Gym Default Rate (`gyms.default_commission_rate`)
   - **Level 5 (Fallback)**: Platform Default Rate (`system_settings` or `5.00%`)
3. **NULL vs 0.00% Explicit Distinction**:
   - `NULL` means *inherit the next applicable rate down the hierarchy*.
   - `0.00%` means *an explicitly configured 0% commission rate* (which yields ₱0 commission).
   - `allow_trainer_commission = 0` halts commission creation altogether.

```
if (!plan.allow_trainer_commission) => return (no commission)
final_rate = resolve_hierarchy(trainer_plan_override, trainer_base, plan_default, gym_default, platform_default)
commission = round(payment.amount × final_rate / 100, 2)
```

### Examples (plan rate 10%)

| Trainer | Personal rate | Final rate | On ₱1,000 payment |
|---|---|---|---|
| New trainer | *(blank)* | 10% | ₱100 |
| Senior trainer | 15% | 15% | ₱150 |
| Anyone, on a plan with rate **0%** | any | — | **no commission** |

> **A plan rate of 0% means "this plan never pays commission"** (for example day passes). A trainer's personal rate does not override that.

---

## 2. Rate snapshots & Immutable Reversals (Accounting Integrity)

Every `trainer_commissions` row stores how it was calculated:

| Column | Meaning |
|---|---|
| `amount` | Commission owed (positive for credits, negative `-₱X.XX` for reversal debits) |
| `base_amount` | Payment amount it was calculated from (honours promos/discounts) |
| `rate_applied` | Final % used (`base_rate`), snapshotted |
| `base_rate` | Resolved rate from the 5-level hierarchy |
| `rate_source` | Source tag (`trainer_plan_override`, `trainer`, `plan`, `gym`, or `platform`) |
| `status` | `pending`, `paid`, `cancelled`, or `reversed` |
| `reversal_reason` | Audit description for why a commission was cancelled or reversed |
| `reversed_at` | Timestamp when the reversal occurred |
| `reversed_by` | User ID of the owner/admin who executed or triggered the reversal |

### Immutable Reversal Rules (No Financial Deletes)
In financial software, records must never be silently deleted (`DELETE FROM trainer_commissions`). FitTrack enforces strict accounting rules when payments are refunded, charged back, or cancelled via `reverse_trainer_commission_for_payment($paymentId, $reason, $actorUserId)`:

1. **Pending Commissions (`status = 'pending'`)**:
   - Switched to `status = 'cancelled'`.
   - Records the timestamp and audit reason (e.g., `Payment refunded` or `Member subscription cancelled`).
   - Does not affect the trainer's historical paid balance.
2. **Paid Commissions (`status = 'paid'`)**:
   - The original payment row is marked `status = 'reversed'` with audit reasons.
   - An **immutable offsetting debit entry** (`amount: -₱X.XX`, `status = 'reversed'`) is appended to the ledger.
   - This offsets future payouts and nets to ₱0.00 without deleting history.
   - Payout calculations (`my_commissions.php`, `trainer/dashboard.php`, and `admin/reports.php`) sum `status IN ('paid', 'reversed')`, accurately reflecting true net earnings.

---

## 4. Retroactive commissions (trainer assigned after payment)

If a member pays first and is assigned a trainer later, `grant_retroactive_commission()` credits the **assigned trainer** for the member's latest payment, but only if:

- the payment is on an **active** membership,
- the payment is at the **trainer's gym**,
- the payment date is within `COMMISSION_RETRO_WINDOW_DAYS` (**30 days**, defined in `core/helpers.php`),
- the payment has not already earned a commission (one commission per payment, enforced by the unique key `uq_trainer_comm_payment`).

It runs when a gym owner creates or extends an assignment (`pages/admin/trainer_assignments.php`) and when a trainer accepts an appointment (`pages/trainer/trainer.php`).

---

## 5. Where commissions are triggered

`process_trainer_commission(int $paymentId, float $amount, ?int $preferredTrainerId = null)` is called from:

- `core/xendit.php`: online (Xendit) payment confirmation
- `pages/admin/payments.php`: manual payment recording
- `pages/admin/memberships.php`: membership creation, renewal and approval flows
- `pages/admin/users.php`: member creation with an initial payment
- `grant_retroactive_commission()`: see §4

The function is **idempotent**: calling it twice for the same payment does nothing the second time.

### Automatic Reversal & Cancellation Cascade
`reverse_trainer_commission_for_payment(int $paymentId, string $reason, ?int $actorUserId, bool $endTrainerAssignment = true)` automatically executes:
- When an admin or member cancels a subscription plan in `pages/admin/memberships.php`.
- Reverses pending/paid commissions with immutable debit ledger logging.
- Automatically transitions the active client assignment in `trainer_assignments` to `status = 'ended', ended_date = CURDATE()` so trainers are never left training unpaid or cancelled clients.

---

## 6. Screens

### Gym owner: `index.php?page=commissions`
A tab switcher separates the commission payout logs from the configuration panels:
- **Tab 1: Commission Payouts (`?tab=payouts`)**:
  - Full payout ledger with live filters, pagination, and settlement actions.
  - **Search filter**: instant search by trainer name, member name, receipt number, or plan name with reset shortcut.
  - Shows the **Rate** applied column.
  - **Dedicated Note Column (`ℹ` icon)**: Cleans up the status pill by moving cancellation/reversal reasons to an interactive info modal. Clicking `ℹ` opens a clean pop-up detailing the audit reason and timestamp.
  - **Cancel/Reverse Modal with Client Unassignment**: When reversing or cancelling a commission, owners can toggle `[x] Also end trainer assignment for this member` (checked by default), immediately ending the coach's assignment and pruning them from the trainer's active roster.
  - Supports deep linking with `&trainer_id=<trainer_profiles.trainer_id>` from reports.
- **Tab 2: Commission Settings (`?tab=settings`)**:
  - **Trainer Rates**: configure individual commission % overrides per coach. Includes an **instant search box** (`Search trainers by name or specialization...`) and scrollable container when managing large rosters of coaches. Each row displays real-time statistics (active client count, current month's revenue).
  - Submitting forms preserves your position on the settings tab.

### Trainer: `index.php?page=my_commissions`
- **Your Commission Rate** card: personal rate (or "Plan rate"), active clients and revenue this month.
- History table has a **Rate** column.

---

## 7. Database changes

Applied by `php migrate.php` (idempotent) and reflected in `gym_management.sql` / `tidb_setup.sql`:

```sql
ALTER TABLE trainer_profiles ADD COLUMN commission_rate DECIMAL(5,2) NULL;  -- NULL = use plan rate

ALTER TABLE trainer_commissions
  ADD COLUMN base_amount  DECIMAL(10,2) NULL,
  ADD COLUMN rate_applied DECIMAL(5,2)  NULL,
  ADD COLUMN base_rate    DECIMAL(5,2)  NULL,
  ADD COLUMN rate_source  VARCHAR(30)   NOT NULL DEFAULT 'plan';
```

> `trainer_commissions.trainer_id` references **`users.user_id`** (not `trainer_profiles.trainer_id`). `trainer_assignments` uses `trainer_profiles.trainer_id`.

---

## 8. Bugs fixed along the way

| # | Issue | Fix |
|---|---|---|
| 1 | Editing a plan rewrote all its pending commissions using the plan list price, so discounts were lost and amounts changed after the fact | Recalculation removed; rates and amounts are snapshotted |
| 2 | Commission used the `$amount` argument passed by the caller, not the recorded payment | Uses `payments.amount`; the argument is only a fallback |
| 3 | A trainer from **another gym** could earn commission if the member had an active assignment there | Trainer must belong to the plan's gym (`tp.gym_id <=> mp.gym_id`) |
| 4 | Retroactive commission could pick up any old payment, from any gym, and credit whichever trainer happened to match | Limited to 30 days, the trainer's gym and the specific trainer being assigned |
| 5 | **Gym owners could see every gym's commissions and mark any of them paid** | Commission list and `mark_paid` are scoped to the owner's gym |
| 6 | A trainer could accept *another trainer's* appointment by posting its `assignment_id` | The accept action is scoped to the logged-in trainer |
| 7 | SQL in the commission engine was built by joining strings together | Converted to prepared statements |

---

## 9. Testing

`scratch/test_commissions.php` (kept outside the repo in the agent's scratch folder) ran these scenarios inside a rolled-back transaction, and all passed:

1. Plan rate only → 10% of ₱1,000 = ₱100 (and the recorded payment amount wins over the caller's argument)
2. Trainer override 15% → ₱150, `rate_source = trainer`
3. Tiers (1+ client → +5%, ₱1,500 revenue → +3%) → best bonus only → 20%
4. Second call for the same payment → still one row
5. Plan rate 0% → no commission despite the trainer override
6. Trainer moved to another gym → no commission
7. Retroactive grant after moving back → credited at 20%

### Manual QA checklist
- [ ] Set a trainer rate, record a payment for one of their members, and check the Rate column.
- [ ] Add a tier the trainer already meets, record another payment, and check the `(x% + y% tier)` label.
- [ ] Edit the plan price and rate, and confirm existing pending commissions are unchanged.
- [ ] As gym owner A, confirm gym B's commissions are not visible.
- [ ] Log in as the trainer and check the "Your Commission Rate" card.

---

## 10. Possible next steps (not implemented)

- **Trainer + plan overrides** (for example Coach X gets 20% on the annual plan only)
- **Fixed-amount commissions** (₱ per signup) as an alternative to %
- **New sale vs. renewal** rates
- **Commission splits** when a member changes trainer partway through a membership
- Voiding a commission automatically when its payment is refunded

