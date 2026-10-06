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

## 3. Environment Configuration Reference

Add or verify these environment variables in your production `.env`:

| Key | Description | Example / Recommended Value |
| :--- | :--- | :--- |
| `REDIS_URL` | Redis connection URL on Render/cloud hosting. When present, offloads sessions from MySQL to Redis in RAM. | `redis://red-xxxx:6379` or `rediss://...` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_USER`, `REDIS_PASSWORD` | Discrete Redis credentials (as set on Render dashboard). Supported as an alternative to `REDIS_URL`. | Host, port (e.g. 6379), user (e.g. `default`), and password |
| `TRUSTED_PROXIES` | Comma-separated list of trusted reverse proxy IPs or CIDR blocks. Leave blank if the app is directly internet-facing without a reverse proxy. | `127.0.0.1, 10.0.0.0/8, 172.16.0.0/12` (or Cloudflare IP ranges) |
| `MIGRATION_SECRET` | Secret token required to run migrations via HTTP GET request. | A 32+ character random hex or alphanumeric string |
| `APP_ENV` | Application environment state. Set to `production` on live systems to suppress stack traces. | `production` |

---

## 4. Production Deployment Checklist

- [ ] Ensure `.env` is NOT tracked in Git and is excluded from Docker images via `.dockerignore`.
- [ ] Confirm Apache has `mod_authz_core` and `mod_rewrite` enabled so `.htaccess` deny rules are enforced.
- [ ] Verify that directory indexing (`Options -Indexes`) is disabled globally or via `.htaccess`.
- [ ] Run database migrations exclusively via CLI: `php migrate.php` during deployment pipelines.
- [ ] If using Cloudflare or an AWS/GCP Application Load Balancer, populate `TRUSTED_PROXIES` with the proxy subnet addresses.
- [ ] Ensure valid CA certificates are present on the host system (`/etc/ssl/certs/ca-certificates.crt` on Linux/Docker, or `curl.cainfo` in `php.ini` on Windows).
