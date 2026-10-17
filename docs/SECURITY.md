# Security Documentation & Hardening Guide

This document records the security posture, vulnerability audit findings, remediation measures, and deployment hardening guidelines for the **FITTRACK** gym management platform.

---

## 1. Security Audit Findings & Remediations Summary

| Finding | Initial Severity | Status | Affected Files | Resolution |
| :--- | :--- | :--- | :--- | :--- |
| **Public Database Migration Endpoint** | **High** | **Resolved** | `migrate.php` | Restricted execution strictly to CLI (`php_sapi_name() === 'cli'`) or an authenticated `MIGRATION_SECRET`. Returns HTTP 403 Forbidden to unauthorized web requests. |
| **Docker Build Secrets Exposure** | **High** (Deployment) | **Resolved** | `Dockerfile`, `.dockerignore`, `.htaccess` | Created `.dockerignore` to exclude `.env`, `.git`, `.sql` dumps, and logs from container images. Added root `.htaccess` blocking HTTP access to dotfiles and sensitive files. |
| **Unprotected KYC / Verification Documents** | **Medium** | **Resolved** | `assets/permits/`, `core/file_handler.php`, `pages/admin/view_permit.php` | Added `assets/permits/.htaccess` (`Require all denied`) to block direct public URL downloads. Implemented an authenticated and authorized viewer endpoint (`view_permit`) checking user identity and gym ownership. |
| **Disabled TLS Verification on API Uploads** | **Medium** | **Resolved** | `core/file_handler.php` | Re-enabled strict peer and host TLS certificate verification (`CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`) for ImageKit and Cloudinary API calls. |
| **Client IP Spoofing via Forwarded Headers** | **Low to Medium** | **Resolved** | `core/bootstrap.php`, `.env.example` | Restricted `CF-Connecting-IP` and `X-Forwarded-For` header trust to explicitly configured `TRUSTED_PROXIES` IP addresses and CIDR subnets. |
| **Unencrypted Direct Messages (Data-at-Rest)** | **High** | **Resolved** | `core/encryption.php`, `core/bootstrap.php`, `pages/shared/messages.php`, `pages/member/progress.php`, `pages/trainer/training.php` | Implemented authenticated field-level encryption (AES-256-GCM) with random 12-byte IVs and 16-byte integrity tags (`enc:v1:` prefix). Seamlessly protects sensitive health/trainer communications in the database with backward compatibility. |
| **Cross-Tenant & IDOR Data Exposure (Row-Level Authorization)** | **High** | **Enforced** | `pages/member/progress.php`, `pages/member/my_workout.php`, `pages/admin/walk_ins.php`, `pages/admin/attendance.php` | Enforces strict row-level authorization and tenant scoping across all database queries. Members can only view their own biometric and progress data; gym staff and owners are strictly bound to their tenant `gym_id`. |

---

## 2. In-Depth Vulnerability Analysis & Applied Fixes

### 2.1 Database Migration Access Control (`migrate.php`)

* **Vulnerability:**  
  Previously, `migrate.php` was located in the public web root and executed automatically upon receiving any HTTP `GET /migrate.php` request without requiring authentication, role authorization, or CSRF checks. Furthermore, line 36 updated all approved gyms to an active `Professional` subscription status with a renewal date set to `2027-12-31`.
* **Risk:**  
  Unauthenticated visitors or automated web spiders could trigger database table modifications, disrupt service, and grant unpaid premium subscriptions.
* **Remediation:**  
  The script now validates that execution originates from the PHP Command Line Interface (CLI):
  ```php
  $isCli = (php_sapi_name() === 'cli');
  $secretKey = getenv('MIGRATION_SECRET') ?: (defined('MIGRATION_SECRET') ? MIGRATION_SECRET : '');
  $providedKey = $_GET['secret'] ?? '';

  if (!$isCli && (empty($secretKey) || !hash_equals((string)$secretKey, (string)$providedKey))) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=UTF-8');
      exit("Access Denied: Migrations can only be executed via the CLI (e.g. 'php migrate.php') or with a valid MIGRATION_SECRET.\n");
  }
  ```
* **Operational Usage:**  
  - Run migrations from server shell / Docker container: `php migrate.php`
  - Or supply your configured secret token if triggered remotely: `https://your-domain.com/migrate.php?secret=YOUR_TOKEN`

---

### 2.2 Docker Image Hardening & Secret Isolation (`.dockerignore`, `.htaccess`)

* **Vulnerability:**  
  The project lacked a `.dockerignore` file while `Dockerfile` used `COPY . /var/www/html/`. If built locally in a directory containing `.env`, secret database credentials and API keys were included in the image layers and could potentially be served if Apache allowed dotfiles.
* **Risk:**  
  Leakage of production database passwords, SMTP credentials, Cloudinary/ImageKit secret keys, and application secrets.
* **Remediation:**  
  1. Added `.dockerignore` with exclusion rules:
     ```dockerignore
     .env
     .env.*
     !.env.example
     .git
     .gitignore
     storage/cache/*
     !storage/cache/.gitkeep
     storage/logs/*
     !storage/logs/.gitkeep
     node_modules
     .DS_Store
     *.sql
     *.md
     ```
  2. Created root `.htaccess` denying web requests to hidden files, SQL dumps, logs, and lockfiles:
     ```apache
     Options -Indexes

     <FilesMatch "^\.">
         Require all denied
     </FilesMatch>

     <FilesMatch "\.(sql|log|lock)$">
         Require all denied
     </FilesMatch>
     ```

---

### 2.3 Verification Document & KYC Protection (`assets/permits/`)

* **Vulnerability:**  
  Government-issued IDs, DTI/SEC Business Permits, Barangay Clearances, and Fire Safety Certificates were stored in `assets/permits/` within Apache's DocumentRoot. Files could be accessed directly by URL without authentication.
* **Risk:**  
  Exposure of personally identifiable information (PII) and compliance documentation, violating data protection regulations (e.g., GDPR and Philippine Data Privacy Act of 2012).
* **Remediation:**  
  1. Added `assets/permits/.htaccess` containing `Require all denied` to prevent direct public HTTP access.
  2. Created an authenticated controller endpoint (`pages/admin/view_permit.php`) registered as `index.php?page=view_permit&file=<filename>`.
  3. The controller enforces:
     - Authentication (`current_user()`).
     - Role-based authorization: Only `platform_admin`, `admin`, or the specific `gym_owner` who owns the permit can access it.
     - Path-traversal protection using `basename()` and `realpath()` verification against `assets/permits`.
     - Secure binary streaming with appropriate `Content-Type`, `X-Content-Type-Options: nosniff`, and `inline` disposition.
  4. Updated `upload_url()` in `core/helpers.php` and `pages/admin/gym_profile.php` to generate secure viewer URLs automatically.

---

### 2.4 Outbound TLS Verification (`core/file_handler.php`)

* **Vulnerability:**  
  cURL requests to third-party CDNs (ImageKit REST API and Cloudinary REST API) explicitly turned off certificate verification (`CURLOPT_SSL_VERIFYPEER => false`, `CURLOPT_SSL_VERIFYHOST => 0`).
* **Risk:**  
  Man-in-the-Middle (MitM) attacks on outgoing requests could intercept sensitive API credentials (`IMAGEKIT_PRIVATE_KEY` and Cloudinary API secrets) transmitted during file upload and deletion.
* **Remediation:**  
  Enabled standard TLS certificate verification across all upload and delete routines:
  ```php
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  ```

---

### 2.5 Reverse Proxy Validation & IP Spoofing Prevention (`core/bootstrap.php`)

* **Vulnerability:**  
  `core/bootstrap.php` previously overwrote `$_SERVER['REMOTE_ADDR']` directly from incoming `HTTP_CF_CONNECTING_IP` or `HTTP_X_FORWARDED_FOR` headers without verifying if the direct client was an authorized proxy.
* **Risk:**  
  - Attackers could send forged `X-Forwarded-For` or `CF-Connecting-IP` headers to rotate their IP address continuously, bypassing rate-limiting mechanisms in `core/rate_limiter.php` (e.g., login brute-force and OTP protections).
  - Forged `127.0.0.1` values could bypass localhost developer error-handling checks in `core/helpers.php`.
* **Remediation:**  
  `core/bootstrap.php` now requires `TRUSTED_PROXIES` to be defined in `.env`. Only requests originating directly from a listed IP or CIDR block can supply client IP override headers:
  ```php
  $directIp = $_SERVER['REMOTE_ADDR'] ?? '';
  $trustedProxiesRaw = (string) app_env('TRUSTED_PROXIES', '');

  if (!empty($trustedProxiesRaw) && !empty($directIp)) {
      $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesRaw)));
      // Validates exact IP or CIDR subnet matches before trusting CF-Connecting-IP / X-Forwarded-For
  }
  ```

---

### 2.6 Direct Message Field-Level At-Rest Encryption (`core/encryption.php`)

* **Vulnerability & Threat Vector:**  
  Previously, personal direct messages between gym members, personal trainers, staff, and gym owners (`trainer_messages.message_text`) were stored as plaintext in MySQL. In fitness and gym applications, chats frequently contain sensitive health data, injury reports, body weight/measurement disclosures, and personal nutrition notes. A database dump leak, rogue server inspection, or SQL injection vulnerability would expose all historical conversations.
* **Risk:**  
  Mass compromise of private health and personal conversations, violating user trust and privacy laws (e.g. Philippine Data Privacy Act / GDPR).
* **Remediation & Cryptographic Architecture:**  
  Implemented authenticated field-level encryption (AES-256-GCM) via `core/encryption.php`:
  1. **Cipher & Key Derivation:** Uses `aes-256-gcm` with a 256-bit binary key derived via SHA-256 from `MESSAGE_ENCRYPTION_KEY` in `.env`.
  2. **AEAD Authenticated Encryption:** Every message generates a cryptographic 96-bit (12-byte) random Initialization Vector (IV) via `random_bytes()`, along with a 128-bit (16-byte) authentication tag for tamper resistance.
  3. **Envelope Packaging:** Encrypted payloads are formatted as:
     ```text
     enc:v1:<base64(12-byte IV + 16-byte TAG + CIPHERTEXT)>
     ```
  4. **Backward Compatibility:** `decrypt_message()` inspects incoming database values. If the string lacks the `enc:v1:` prefix, it returns the value directly as legacy plaintext. This guarantees zero-downtime and allows historical unencrypted records to coexist seamlessly with newly encrypted messages.
  5. **Protected Endpoints:**
     - `pages/shared/messages.php` (Direct chat sending, polling, recent conversation previews, history rendering)
     - `pages/member/progress.php` (Trainer progress notes and feedback feed)
     - `pages/trainer/training.php` (Latest member feedback previews)

  6. **TiDB / MySQL Database Verification Query:**  
     Run this query directly in TiDB Cloud or your MySQL console to verify that messages are stored encrypted:
     ```sql
     SELECT 
         message_id,
         sender_id,
         recipient_id,
         IF(message_text LIKE 'enc:v1:%', 'ENCRYPTED (AES-256-GCM)', 'PLAINTEXT') AS encryption_status,
         message_text,
         sent_at
     FROM trainer_messages
     ORDER BY message_id DESC
     LIMIT 5;
     ```

---

### 2.7 Strict Row-Level Authorization & Biometric Data Isolation

* **Threat Vector & Security Principle:**  
  In a multi-tenant gym management system, physical body measurements (weight, waist, hips, chest, body fat percentage), workout routines, and membership finances must never be exposed across tenant boundaries or leaked via Insecure Direct Object Reference (IDOR) attacks.
* **Why Numeric Biometrics Do Not Require Column-Level Encryption:**  
  Physical sizes are numeric telemetry values (`DECIMAL(5,2)`). Encrypting them into ciphertext strings would break SQL aggregations (`AVG()`, `MIN()`, `MAX()`), progress percentage changes, BMR/calorie generation formulas, and Chart.js timeline graphs. In FitTracks, security for biometric data is achieved through **Strict Row-Level Authorization** combined with **Storage-Layer Encryption (TDE)**:
  1. **Member Personal Isolation (Anti-IDOR):**  
     Personal health metrics in `progress_logs`, `member_measurements`, and workout logs are always bound directly to the authenticated session (`$user['user_id']`). Even if an attacker tampers with HTTP GET or POST parameters (`?user_id=999`), server-side queries enforce:
     ```php
     $memberId = (int)$user['user_id'];
     $logs = query_all('SELECT * FROM progress_logs WHERE user_id = ? ORDER BY log_date DESC', [$memberId]);
     ```
  2. **Trainer-Client Relationship Scoping:**  
     Personal trainers cannot inspect random members across the platform. A trainer is authorized to view a member's progress hub if and only if an active assignment exists in the `trainer_assignments` table:
     ```sql
     SELECT 1 FROM trainer_assignments 
     WHERE trainer_id = ? AND member_user_id = ? AND status = 'active'
     ```
  3. **Gym Multi-Tenancy Scoping:**  
     Gym Owners, Front Desk Staff, and Managers are strictly isolated by their gym's tenant ID:
     - Attendance check-ins: `WHERE gym_id = ?`
     - Walk-in drop-ins: `WHERE w.gym_id = ?`
     - Member roster: `WHERE gm.gym_id = ?`
     A gym owner or staff member cannot view or query transactions, visits, or metrics belonging to another facility.
  4. **Database Storage Encryption (TDE):**  
     The underlying database volumes in **TiDB Cloud** and cloud container environments are encrypted at rest using enterprise AES-256 Transparent Data Encryption, protecting raw disk data against physical storage theft.

---

## 3. Environment Configuration Reference

Add or verify these environment variables in your production `.env`:

| Key | Description | Example / Recommended Value |
| :--- | :--- | :--- |
| `MESSAGE_ENCRYPTION_KEY` | 256-bit secret key used by `core/encryption.php` for AES-256-GCM at-rest message field encryption. | Generate with `php -r "echo bin2hex(random_bytes(32));"` |
| `REDIS_URL` | Redis connection URL on Render/cloud hosting. When present, offloads sessions from MySQL to Redis in RAM. | `redis://red-xxxx:6379` or `rediss://...` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_USER`, `REDIS_PASSWORD` | Discrete Redis credentials (as set on Render dashboard). Supported as an alternative to `REDIS_URL`. | Host, port (e.g. 6379), user (e.g. `default`), and password |
| `TRUSTED_PROXIES` | Comma-separated list of trusted reverse proxy IPs or CIDR blocks. Leave blank if the app is directly internet-facing without a reverse proxy. | `127.0.0.1, 10.0.0.0/8, 172.16.0.0/12` (or Cloudflare IP ranges) |
| `MIGRATION_SECRET` | Secret token required to run migrations via HTTP GET request. | A 32+ character random hex or alphanumeric string |
| `APP_ENV` | Application environment state. Set to `production` on live systems to suppress stack traces. | `production` |

---

## 4. Production Deployment Checklist

- [ ] Ensure `.env` is NOT tracked in Git and is excluded from Docker images via `.dockerignore`.
- [ ] Set a secure, random `MESSAGE_ENCRYPTION_KEY` in production `.env` (`php -r "echo bin2hex(random_bytes(32));"`).
- [ ] Confirm Apache has `mod_authz_core` and `mod_rewrite` enabled so `.htaccess` deny rules are enforced.
- [ ] Verify that directory indexing (`Options -Indexes`) is disabled globally or via `.htaccess`.
- [ ] Run database migrations exclusively via CLI: `php migrate.php` during deployment pipelines.
- [ ] If using Cloudflare or an AWS/GCP Application Load Balancer, populate `TRUSTED_PROXIES` with the proxy subnet addresses.
- [ ] Ensure valid CA certificates are present on the host system (`/etc/ssl/certs/ca-certificates.crt` on Linux/Docker, or `curl.cainfo` in `php.ini` on Windows).
