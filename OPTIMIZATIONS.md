# FitTracks Performance, Scaling & Database Optimization Guide

This document details the database performance optimizations, query index enhancements, background worker scaling architectures, and distributed locking mechanisms implemented in FitTracks to support high-concurrency production deployments.

---

## Table of Contents
1. [Sargable Date Filtering & Index Range Seeks](#1-sargable-date-filtering--index-range-seeks)
2. [Equipment Real-Time Polling & Concurrency Scaling](#2-equipment-real-time-polling--concurrency-scaling)
3. [Batch Engagement Scoring Architecture](#3-batch-engagement-scoring-architecture)
4. [Concurrency-Safe Distributed Locking & Run Deduplication](#4-concurrency-safe-distributed-locking--run-deduplication)
5. [Automated At-Risk Member Reminder Sweep Optimization](#5-automated-at-risk-member-reminder-sweep-optimization)
6. [Verification Coverage](#6-verification-coverage)
7. [Expected Query-Shape Changes (Not Benchmarks)](#7-expected-query-shape-changes-not-benchmarks)

---

## 1. Sargable Date Filtering & Index Range Seeks

### The Bottleneck
Queries filtering for "today's activity" previously wrapped column names in functions, such as `WHERE DATE(check_in_time) = CURDATE()` or `WHERE DATE(s.start_datetime) = CURDATE()`. In SQL databases (MySQL, MariaDB, TiDB), function-wrapped columns are **non-sargable** (Search Argument Able): the database engine cannot use a B-Tree index to jump to the relevant dates and is forced to perform a full table scan, evaluating `DATE()` on every row.

Furthermore, changing `DATE(col) = CURDATE()` to open-ended `col >= CURDATE()` risks capturing future-dated records (e.g. from client clock skew or pre-scheduled entries) as today's activity.

### The Solution: Half-Open Range Intervals
All date-based filters now enforce index-seekable half-open intervals:
- **For Today**: `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY`
- **For Selected/Synced Dates**: `check_in_time >= ? AND check_in_time < DATE_ADD(?, INTERVAL 1 DAY)`
- **For Yesterday**: `check_in_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND check_in_time < CURDATE()`

### Optimized Endpoints
| File | Line | Previous Syntax | Optimized Index-Seekable Syntax |
| :--- | :--- | :--- | :--- |
| `pages/admin/scanner.php` | Line 38 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Line 48 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Line 72 | `DATE(a.check_in_time) = CURDATE()` | `a.check_in_time >= CURDATE() AND a.check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Lines 125–126 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Line 162 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Line 263 | `DATE(check_in_time) = ?` | `check_in_time >= ? AND check_in_time < DATE_ADD(?, INTERVAL 1 DAY)` |
| `pages/admin/scanner.php` | Line 348 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/scanner.php` | Line 484 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/gym_owner/dashboard.php` | Lines 34–35 | `DATE(start_datetime) = CURDATE()` | `start_datetime >= CURDATE() AND < CURDATE() + INTERVAL 1 DAY` |
| `pages/gym_owner/dashboard.php` | Lines 45–46 | `DATE(cs.start_datetime) = CURDATE()` | `cs.start_datetime >= CURDATE() AND < CURDATE() + INTERVAL 1 DAY` |
| `pages/gym_owner/dashboard.php` | Line 98 | `DATE(s.start_datetime) = CURDATE()` | `s.start_datetime >= CURDATE() AND < CURDATE() + INTERVAL 1 DAY` |
| `pages/admin/dashboard.php` | Lines 67–70 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND < CURDATE() + INTERVAL 1 DAY` |
| `pages/member/equipment.php` | Line 26 | `DATE(check_in_time) = CURDATE()` | `check_in_time >= CURDATE() AND < CURDATE() + INTERVAL 1 DAY` |
| `pages/shared/equipment_api.php`| Lines 230, 254 | `a.check_in_time >= CURDATE()` | `a.check_in_time >= CURDATE() AND a.check_in_time < CURDATE() + INTERVAL 1 DAY` |
| `pages/shared/equipment_api.php`| Lines 428, 468, 700 | `check_in_time >= CURDATE()` | `check_in_time >= CURDATE() AND check_in_time < CURDATE() + INTERVAL 1 DAY` |

---

## 2. Equipment Real-Time Polling & Concurrency Scaling

### The Bottleneck
1. **PHP Session Blocking**: The member equipment page polls every 5–8 seconds. Because PHP defaults to exclusive session file locks, repeated AJAX polls serialized requests for the same session, freezing navigation and causing thread contention.
2. **Maintenance Throttling**: Every poll executed active session reconciliation and queue maintenance against `attendance`, multiplying query overhead by the number of open browser tabs.
3. **Correlated Subqueries**: `handle_poll` executed 8 separate correlated subqueries per equipment item in inventory.

### The Solutions
1. **Early Session Release**: In `pages/shared/equipment_api.php`, read-only `action === 'poll'` requests call `session_write_close()` immediately after verifying authentication. This frees the PHP session lock in `< 1ms` so concurrent navigation or background requests are never blocked.
2. **Throttled Queue Maintenance (30s per Gym)**: Maintenance and attendance reconciliations run at most once every 30 seconds per gym using a distributed lock (`lock:equipment_maint_{gymId}`).
3. **Consolidated Indexed Equipment Query**: Replaced correlated subqueries with a single `LEFT JOIN` query scoped strictly to `gym_id`:
   ```sql
   SELECT e.*, s.session_id, s.start_time, s.user_id, ...
   FROM gym_equipment e
   LEFT JOIN equipment_sessions s ON s.session_id = e.current_session_id
   LEFT JOIN (
       SELECT equipment_id, COUNT(*) as waiting_count 
       FROM equipment_queues 
       WHERE gym_id = ? AND queue_status = 'waiting' 
       GROUP BY equipment_id
   ) q_agg ON q_agg.equipment_id = e.equipment_id
   WHERE e.gym_id = ?
   ```
4. **Composite Index Support**: Added composite index in `migrate.php` and `tidb_setup.sql`:
   ```sql
   ALTER TABLE equipment_queues ADD INDEX idx_equip_queue_gym_status (gym_id, queue_status, equipment_id);
   ```
5. **Adaptive Client Polling**: `pages/member/equipment.php` implements the Page Visibility API:
   - Polling rate slows from 8s to **30s** when the browser tab is hidden/backgrounded.
   - Refreshes immediately when the user returns to the tab.
   - Speeds to **5s** only when the user is actively in a session or holding a claimed machine.

---

## 3. Batch Engagement Scoring Architecture

### The Bottleneck
Previously, the nightly cron run queued a separate job for every active member (`1 job = 1 member`). When the worker ran, each job performed 8 separate count queries and individual badge queries. For 1,000 members, this generated **8,000+ individual database round-trips**, causing worker timeouts and large queue backlogs.

### The Solution: Grouped Reads & Bulk Writes
In `core/engagement_engine.php`:
1. **Cursor-Based Resumable Batching**: `recompute_all_engagement_scores_batch` processes members in bounded chunks of **25 members** using a `user_id > ?` cursor.
2. **6 Grouped Read Queries**: Instead of querying each member individually, metrics across all 25 user IDs are fetched in 6 bulk aggregate queries:
   - Attendance visits and active weeks: `WHERE user_id IN (...) AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY user_id`
   - Class attendance: `WHERE user_id IN (...) AND booking_status = 'attended' AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY user_id`
   - Completed workout days: `WHERE user_id IN (...) AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY user_id`
   - Progress logs: `WHERE user_id IN (...) AND log_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) GROUP BY user_id`
   - Iron Lifter badges: `WHERE user_id IN (...) GROUP BY user_id HAVING COUNT(*) >= 50`
   - Early Bird badges: `WHERE user_id IN (...) AND TIME(check_in_time) < '07:00:00'`
3. **Single Bulk UPDATE**: Engagement scores for all 25 members are updated in **one single SQL query** using a `CASE` statement:
   ```sql
   UPDATE users 
   SET engagement_score = CASE user_id 
       WHEN ? THEN ? 
       WHEN ? THEN ? 
       ... 
   END, 
   engagement_computed_at = NOW() 
   WHERE user_id IN (?, ?, ...)
   ```
4. **Single Multi-Row Badge INSERT**: All qualifying badges are inserted in **one multi-row statement**:
   ```sql
   INSERT IGNORE INTO member_badges (user_id, badge_type) VALUES (?, ?), (?, ?), ...
   ```
5. **Dashboard Score Cache**: `pages/member/dashboard.php` caches the computed score in the `users` table (`engagement_computed_at`), reusing values computed within the last hour rather than recalculating on every dashboard reload.

---

## 4. Concurrency-Safe Distributed Locking & Run Deduplication

### The Race Conditions Prevented
1. **Concurrent Sweep Overlap**: Without synchronization, two cron workers starting at the same time could both start separate sweeps from `last_user_id = 0`, computing scores twice and inflating queue volume.
2. **Stale Lock Takeover Race**: A naive `get()` followed by `setex()` allows two workers to both read an expired lock simultaneously and both take over.
3. **Release Deletion Race**: A slow worker whose lock expired could delete a newer worker's lock if it simply called `del()`.
4. **File Inode Race**: Calling `unlink($lockFile)` after `flock($fp, LOCK_UN)` causes subsequent workers to open different inodes, breaking mutual exclusion.

### Implementation Details in `core/engagement_engine.php`

#### A. Atomic Redis Locking (with Lua Scripts)
- **Acquisition (`acquire_engagement_sweep_lock`)**:
  1. Attempts atomic `SET lock:engagement_sweep <data> EX 3600 NX`.
  2. If the key exists, an atomic Lua script evaluates whether `now - updated_at >= 1800` (30 minutes stale) and takes over in one step:
     ```lua
     local val = redis.call("get", KEYS[1])
     if val then
         local data = cjson.decode(val)
         local updated_at = tonumber(data["updated_at"] or 0)
         local now = tonumber(ARGV[2])
         if (now - updated_at) >= 1800 then
             redis.call("setex", KEYS[1], tonumber(ARGV[3]), ARGV[1])
             return 1
         end
     end
     return 0
     ```
- **Progress Refresh (`update_engagement_sweep_lock`)**:
  Atomic Lua script confirms `data.run_id == ARGV[1]` before updating `last_user_id` and refreshing the TTL:
  ```lua
  local val = redis.call("get", KEYS[1])
  if val then
      local data = cjson.decode(val)
      if data and data["run_id"] == ARGV[1] then
          data["updated_at"] = tonumber(ARGV[2])
          data["last_user_id"] = tonumber(ARGV[3])
          redis.call("setex", KEYS[1], tonumber(ARGV[4]), cjson.encode(data))
          return 1
      end
  end
  return 0
  ```
- **Safe Release (`release_engagement_sweep_lock`)**:
  Atomic compare-and-delete Lua script ensures only the owning `run_id` can delete the key:
  ```lua
  local val = redis.call("get", KEYS[1])
  if val then
      local data = cjson.decode(val)
      if data and data["run_id"] == ARGV[1] then
          return redis.call("del", KEYS[1])
      end
  end
  return 0
  ```

#### B. File Lock Fallback (Atomic flock Without Unlink)
- When Redis is unavailable, locking falls back to `fittracks_engagement_sweep.lock` in the system temporary directory.
- `flock($fp, LOCK_EX | LOCK_NB)` wraps the entire read, check, and write cycle.
- **Permanent File Inode**: On release, the file contents are cleared (`ftruncate($fp, 0)`), but the file is **never unlinked**. This guarantees all processes coordinate against the identical filesystem inode.

#### C. Queue & Cron Deduplication
- In `cron.php`, `get_engagement_sweep_lock()` is checked before queueing. If an active sweep was updated within the last 30 minutes, `cron.php` skips pushing a duplicate `last_user_id = 0` job.
- If a superseded or orphaned job with an outdated `run_id` executes, it detects the mismatch against the active lock and terminates cleanly without queueing further jobs.

---

## 5. Automated At-Risk Member Reminder Sweep Optimization

### The Bottlenecks
1. **Trailing Space Cooldown Bug**: Notifications were saved as `'We miss you at the gym! '` (with trailing whitespace), but the cooldown query checked `WHERE title = 'We miss you at the gym!'` (without trailing whitespace). Because the strings mismatched, `recentCount` always returned `0`, bypassing the 14-day cooldown and sending duplicate reminders every night.
2. **N-Query Inefficiency**: The worker loaded all active members and fired an individual `SELECT COUNT(*)` query for every candidate to check prior notifications.
3. **Missing Index**: The `notifications` table had no index covering `(user_id, type, title, created_at)`.

### The Solutions
1. **Shared Title Constant**: Defined `const AT_RISK_NOTIFICATION_TITLE = 'We miss you at the gym!';` shared by both `send_at_risk_notification_job` and `process_automated_at_risk_notifications`, guaranteeing 100% exact string matching.
2. **Covering Composite Index**: Added `idx_notif_cooldown (user_id, type, title, created_at)` in `migrate.php` and `tidb_setup.sql`.
3. **Cursor Batching & Grouped Cooldown Queries**:
   - `process_automated_at_risk_notifications` pages distinct active members in bounded cursor batches (`LIMIT 50`) before resolving affiliation and attendance. Each member gets one deterministic gym affiliation, preventing join fan-out from duplicating a member or splitting one member across pages.
   - Prior reminders for qualifying members are looked up in one grouped query using `IN ($placeholders) GROUP BY user_id`. This reduces application-level cooldown lookups to one query per candidate batch; runtime and total query cost still depend on candidate count, indexes, and the database query plan.

---

## 6. Verification Coverage

The automated test suite (`composer test` / `php tests/run.php`) exercises three key subsystems:
1. **Engagement-Score Arithmetic** (`tests/run.php`): Validates metric calculations, target clamping, zero-activity thresholds, and weight configurations.
2. **At-Risk Member Multi-Gym Affiliation & Batch Boundaries** (`tests/at_risk_batch_test.php`):
   - Verifies that members with multiple gym affiliations (`gym_members` composite PK `(user_id, gym_id)`) are never duplicated within a batch.
   - Verifies that deterministic gym affiliation precedence (recent attendance > active membership > pending membership > minimum `gym_id`) is strictly enforced.
   - Verifies that users lying at and across batch cursor boundaries (`last_user_id`) receive exactly one reminder job and are never re-evaluated or skipped across boundaries.
   - Verifies that cooldown windows and gym-level alert disable flags (`auto_inactivity_alerts = 0`) are honored.
3. **Concurrency Locking & Release** (`tests/lock_concurrency_test.php`):
   - Verifies that `acquire_engagement_sweep_lock()` guarantees mutual exclusion between workers.
   - Verifies that active locks track cursor progress updates (`update_engagement_sweep_lock()`).
   - Verifies that unauthorized release attempts by conflicting run IDs are rejected, preserving active locks.
   - Verifies that authorized releases clear the lock and allow subsequent workers to acquire.

Full multi-worker Redis latency benchmarks and query plan metrics under production datasets still require measurement against a dedicated TiDB Cloud and Redis cluster setup.

---

## 7. Expected Query-Shape Changes (Not Benchmarks)

The table records code-level changes only. It does not claim measured latency or throughput improvements.

| Operation | Code-level change | What to measure |
| :--- | :--- | :--- |
| Equipment polling | Consolidated equipment read; maintenance is throttled | Query plan, queries per poll, and p95 poll latency under concurrent clients |
| Session handling | Read-only poll closes the PHP session after authentication | Session lock wait under concurrent requests |
| Date filters | Function-wrapped date filters replaced with half-open ranges in listed paths | `EXPLAIN` plans and rows examined with production-like data |
| Engagement scoring | Member metrics use grouped reads and batched writes | Query count, rows examined, and batch duration at increasing member counts |
| At-risk reminders | Distinct-member cursor batches and grouped cooldown lookup | Query plan, duplicate-free pagination, and sweep duration at increasing member counts |
