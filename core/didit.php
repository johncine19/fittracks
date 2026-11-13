<?php
declare(strict_types=1);

/**
 * Didit Identity Verification (KYC) Service
 * Connects FitTrack to Didit's Verification API v3 (https://verification.didit.me).
 */

function didit_environment(): string
{
    $env = strtolower(trim((string) app_env('DIDIT_ENVIRONMENT', 'sandbox')));
    return $env === 'production' || $env === 'live' ? 'live' : 'sandbox';
}

function didit_is_configured(): bool
{
    return trim((string) app_env('DIDIT_API_KEY')) !== ''
        && trim((string) app_env('DIDIT_WORKFLOW_ID')) !== '';
}

function didit_ensure_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS gym_didit_verifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                gym_id INT UNSIGNED NOT NULL,
                session_id VARCHAR(100) NOT NULL,
                session_token VARCHAR(100) NULL,
                verification_url TEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'Not Started',
                extracted_name VARCHAR(255) NULL,
                document_type VARCHAR(100) NULL,
                document_number_masked VARCHAR(64) NULL,
                expiration_date VARCHAR(32) NULL,
                liveness_status VARCHAR(50) NULL,
                face_match_status VARCHAR(50) NULL,
                decline_reasons TEXT NULL,
                raw_decision_json LONGTEXT NULL,
                environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_id (session_id),
                INDEX idx_gym_id (gym_id),
                CONSTRAINT fk_didit_gym FOREIGN KEY (gym_id) REFERENCES gyms(gym_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[Didit] Table creation/check failed: ' . $e->getMessage());
    }
}

/**
 * Execute an HTTP request against the Didit Verification API v3.
 */
function didit_api_request(string $method, string $path, ?array $body = null): array
{
    $apiKey = trim((string) app_env('DIDIT_API_KEY'));
    if ($apiKey === '') {
        throw new RuntimeException('Didit API key is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Didit verification.');
    }

    $url = 'https://verification.didit.me' . $path;
    $headers = [
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ];

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($encodedBody !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedBody);
    }

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('[Didit] API transport error: ' . $curlError);
        throw new RuntimeException('Could not reach Didit verification service. Please try again.');
    }

    $response = json_decode((string) $responseBody, true);
    if (!is_array($response)) {
        error_log('[Didit] Non-JSON response (HTTP ' . $httpStatus . '): ' . substr((string) $responseBody, 0, 500));
        throw new RuntimeException('Didit returned an unexpected response (HTTP ' . $httpStatus . ').');
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $detail = (string) ($response['detail'] ?? $response['message'] ?? $response['error'] ?? 'Request rejected');
        error_log('[Didit] API request failed (HTTP ' . $httpStatus . '): ' . $detail);
        throw new RuntimeException('Didit error (HTTP ' . $httpStatus . '): ' . $detail);
    }

    return $response;
}

/**
 * Create a new Didit verification session for a gym owner and save it to DB.
 */
function didit_create_session(int $gymId, string $callbackUrl = '', array $metadata = []): array
{
    $workflowId = trim((string) app_env('DIDIT_WORKFLOW_ID'));
    if ($workflowId === '') {
        throw new RuntimeException('Didit workflow ID is not configured.');
    }

    $payload = [
        'workflow_id' => $workflowId,
        'vendor_data' => 'gym_' . $gymId,
    ];
    if ($callbackUrl !== '') {
        $payload['callback'] = $callbackUrl;
    }
    if (!empty($metadata)) {
        $payload['metadata'] = $metadata;
    }

    $session = didit_api_request('POST', '/v3/session/', $payload);

    didit_save_session($gymId, $session);

    return $session;
}

/**
 * Create a Didit verification session during gym onboarding (before gym_id exists).
 */
function didit_create_onboarding_session(int $userId, string $callbackUrl = '', array $metadata = []): array
{
    $workflowId = trim((string) app_env('DIDIT_WORKFLOW_ID'));
    if ($workflowId === '') {
        throw new RuntimeException('Didit workflow ID is not configured.');
    }

    $payload = [
        'workflow_id' => $workflowId,
        'vendor_data' => 'onboarding_user_' . $userId,
    ];
    if ($callbackUrl !== '') {
        $payload['callback'] = $callbackUrl;
    }
    if (!empty($metadata)) {
        $payload['metadata'] = $metadata;
    }

    return didit_api_request('POST', '/v3/session/', $payload);
}

/**
 * Attach a completed/started onboarding Didit session to a newly created gym_id and sync its decision.
 */
function didit_attach_onboarding_session(int $gymId, string $sessionId, ?string $sessionToken = null, ?string $verificationUrl = null): ?array
{
    $sessionId = trim($sessionId);
    if ($gymId <= 0 || $sessionId === '') {
        return null;
    }

    didit_save_session($gymId, [
        'session_id'    => $sessionId,
        'session_token' => $sessionToken,
        'url'           => $verificationUrl,
        'status'        => 'Not Started',
    ]);

    try {
        return didit_sync_decision($sessionId);
    } catch (Throwable $e) {
        error_log('[Didit] Failed to sync attached onboarding session: ' . $e->getMessage());
        return null;
    }
}

/**
 * Save a newly created Didit session into gym_didit_verifications.
 */
function didit_save_session(int $gymId, array $sessionData): void
{
    didit_ensure_table();
    $sessionId = trim((string) ($sessionData['session_id'] ?? ''));
    if ($sessionId === '') {
        return;
    }

    $stmt = db()->prepare("
        INSERT INTO gym_didit_verifications
            (gym_id, session_id, session_token, verification_url, status, environment)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            session_token = VALUES(session_token),
            verification_url = VALUES(verification_url),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $gymId,
        $sessionId,
        $sessionData['session_token'] ?? null,
        $sessionData['url'] ?? null,
        (string) ($sessionData['status'] ?? 'Not Started'),
        didit_environment(),
    ]);
}

/**
 * Retrieve the decision payload from Didit API for a session ID.
 */
function didit_get_decision(string $sessionId): array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Session ID is required.');
    }
    return didit_api_request('GET', '/v3/session/' . rawurlencode($sessionId) . '/decision/');
}

/**
 * Extract structured summary from Didit V3 decision payload.
 */
function didit_extract_summary(array $decision): array
{
    $status = trim((string) ($decision['status'] ?? 'Not Started'));

    $extractedName = null;
    $docType = null;
    $docNumberMasked = null;
    $expirationDate = null;
    $livenessStatus = null;
    $faceMatchStatus = null;
    $reasons = [];

    // Parse id_verifications[] array (V3 format)
    $idList = $decision['id_verifications'] ?? [];
    if (is_array($idList) && !empty($idList)) {
        $id = $idList[0];
        $firstName = trim((string) ($id['first_name'] ?? ''));
        $lastName = trim((string) ($id['last_name'] ?? ''));
        $fullName = trim((string) ($id['full_name'] ?? ''));
        if ($fullName !== '') {
            $extractedName = $fullName;
        } elseif ($firstName !== '' || $lastName !== '') {
            $extractedName = trim($firstName . ' ' . $lastName);
        }

        $docType = trim((string) ($id['document_type'] ?? $id['type'] ?? ''));
        if ($docType === '') {
            $docType = null;
        }
        $rawNum = trim((string) ($id['document_number'] ?? ''));
        if ($rawNum !== '') {
            $len = strlen($rawNum);
            $docNumberMasked = $len > 4
                ? str_repeat('*', $len - 4) . substr($rawNum, -4)
                : str_repeat('*', $len);
        }
        $expirationDate = trim((string) ($id['expiration_date'] ?? ''));
        if ($expirationDate === '') {
            $expirationDate = null;
        }

        if (!empty($id['warnings']) && is_array($id['warnings'])) {
            foreach ($id['warnings'] as $w) {
                $msg = is_array($w) ? ($w['short_description'] ?? $w['long_description'] ?? $w['risk'] ?? '') : (string) $w;
                if ($msg !== '') {
                    $reasons[] = $msg;
                }
            }
        }
    }

    // Parse liveness_checks[]
    $livenessList = $decision['liveness_checks'] ?? [];
    if (is_array($livenessList) && !empty($livenessList)) {
        $livenessStatus = trim((string) ($livenessList[0]['status'] ?? ''));
    }

    // Parse face_matches[]
    $faceList = $decision['face_matches'] ?? [];
    if (is_array($faceList) && !empty($faceList)) {
        $faceMatchStatus = trim((string) ($faceList[0]['status'] ?? ''));
    }

    // Top-level warnings/reviews
    if (!empty($decision['reviews']) && is_array($decision['reviews'])) {
        foreach ($decision['reviews'] as $rev) {
            if (!empty($rev['comment'])) {
                $reasons[] = (string) $rev['comment'];
            }
        }
    }

    return [
        'status' => $status,
        'extracted_name' => $extractedName,
        'document_type' => $docType,
        'document_number_masked' => $docNumberMasked,
        'expiration_date' => $expirationDate,
        'liveness_status' => $livenessStatus ?: null,
        'face_match_status' => $faceMatchStatus ?: null,
        'decline_reasons' => !empty($reasons) ? implode('; ', array_unique($reasons)) : null,
    ];
}

/**
 * Fetch the latest decision from Didit and update gym_didit_verifications.
 */
function didit_sync_decision(string $sessionId): ?array
{
    didit_ensure_table();
    $decision = didit_get_decision($sessionId);
    $summary = didit_extract_summary($decision);

    $stmt = db()->prepare("
        UPDATE gym_didit_verifications
        SET status = ?,
            extracted_name = ?,
            document_type = ?,
            document_number_masked = ?,
            expiration_date = ?,
            liveness_status = ?,
            face_match_status = ?,
            decline_reasons = ?,
            raw_decision_json = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE session_id = ?
    ");
    $stmt->execute([
        $summary['status'],
        $summary['extracted_name'],
        $summary['document_type'],
        $summary['document_number_masked'],
        $summary['expiration_date'],
        $summary['liveness_status'],
        $summary['face_match_status'],
        $summary['decline_reasons'],
        json_encode($decision, JSON_UNESCAPED_SLASHES),
        $sessionId,
    ]);

    return array_merge(['session_id' => $sessionId], $summary);
}

/**
 * Retrieve the latest Didit verification record for a given gym.
 */
function didit_get_latest_for_gym(int $gymId): ?array
{
    didit_ensure_table();
    try {
        $stmt = db()->prepare("
            SELECT *
            FROM gym_didit_verifications
            WHERE gym_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$gymId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Return UI badge styling & text for a Didit verification status.
 */
function didit_status_badge(string $status): array
{
    $norm = strtolower(trim($status));
    if ($norm === 'approved') {
        return [
            'label' => 'Verified Genuine (Didit)',
            'color' => '#10b981',
            'bg'    => 'rgba(16, 185, 129, 0.14)',
            'border'=> 'rgba(16, 185, 129, 0.35)',
            'icon'  => '✅',
        ];
    }
    if ($norm === 'declined' || $norm === 'abandoned') {
        return [
            'label' => $norm === 'declined' ? 'Flagged / Declined (Didit)' : 'Verification Abandoned',
            'color' => '#ef4444',
            'bg'    => 'rgba(239, 68, 68, 0.14)',
            'border'=> 'rgba(239, 68, 68, 0.35)',
            'icon'  => '⚠️',
        ];
    }
    if ($norm === 'in review' || $norm === 'in progress') {
        return [
            'label' => $norm === 'in review' ? 'In Review (Didit)' : 'Verification In Progress',
            'color' => '#f59e0b',
            'bg'    => 'rgba(245, 158, 11, 0.14)',
            'border'=> 'rgba(245, 158, 11, 0.35)',
            'icon'  => '⏳',
        ];
    }
    return [
        'label' => 'Not Started',
        'color' => '#94a3b8',
        'bg'    => 'rgba(148, 163, 184, 0.14)',
        'border'=> 'rgba(148, 163, 184, 0.3)',
        'icon'  => '🛡️',
    ];
}
