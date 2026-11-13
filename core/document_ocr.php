<?php
declare(strict_types=1);

/**
 * Automated Compliance Document Scanner (OCR.space API)
 * Scans Business Permits, Barangay Clearances, and Fire Safety Certificates
 * to detect fake/unrelated uploads, expired years, and name mismatches.
 */

function doc_ocr_is_configured(): bool
{
    return trim((string) app_env('OCR_SPACE_API_KEY')) !== '';
}

function doc_ocr_ensure_table(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS gym_document_ocr_checks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                gym_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(50) NOT NULL,
                file_url TEXT NOT NULL,
                verdict ENUM('verified', 'warning', 'flagged', 'error') NOT NULL DEFAULT 'warning',
                years_found VARCHAR(100) NULL,
                current_year_found TINYINT(1) NOT NULL DEFAULT 0,
                keywords_matched TEXT NULL,
                name_matched TINYINT(1) NOT NULL DEFAULT 0,
                extracted_text MEDIUMTEXT NULL,
                summary_note VARCHAR(255) NULL,
                scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_gym_doc (gym_id, document_type),
                INDEX idx_gym_id (gym_id),
                CONSTRAINT fk_doc_ocr_gym FOREIGN KEY (gym_id) REFERENCES gyms(gym_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[DocOCR] Table creation/check failed: ' . $e->getMessage());
    }
}

/**
 * Build a lightweight (<1MB) Cloudinary URL if the URL is hosted on Cloudinary,
 * so OCR.space free tier (1MB limit) never rejects high-res phone photos or PDFs.
 */
function doc_ocr_prepare_url(string $url): string
{
    if (str_contains($url, 'res.cloudinary.com') && str_contains($url, '/upload/')) {
        // Insert w_1600,q_80,f_jpg (and pg_1 for PDFs) right after /upload/
        $isPdf = str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf');
        $transform = $isPdf ? 'pg_1,w_1600,q_80,f_jpg' : 'w_1600,c_limit,q_80,f_jpg';
        return preg_replace('~/upload/(?!w_|pg_)~', '/upload/' . $transform . '/', $url, 1) ?? $url;
    }
    return $url;
}

/**
 * Resolve a stored filename/URL to either a local filesystem path or a public URL.
 */
function doc_ocr_resolve_source(string $storedValue): array
{
    $storedValue = trim($storedValue);
    if ($storedValue === '') {
        return ['type' => 'none', 'value' => ''];
    }

    if (preg_match('~^https?://~i', $storedValue)) {
        return ['type' => 'url', 'value' => doc_ocr_prepare_url($storedValue)];
    }

    $localPath = __DIR__ . '/../storage/uploads/permits/' . ltrim($storedValue, '/\\');
    if (is_file($localPath)) {
        return ['type' => 'file', 'value' => $localPath];
    }

    // Fallback to upload_url() if available
    if (function_exists('upload_url')) {
        $resolvedUrl = upload_url($storedValue, 'permits');
        if (preg_match('~^https?://~i', $resolvedUrl)) {
            return ['type' => 'url', 'value' => doc_ocr_prepare_url($resolvedUrl)];
        }
    }

    return ['type' => 'none', 'value' => ''];
}

/**
 * Compress a local image file to a temporary JPEG under ~900KB if it exceeds 950KB.
 * Returns [pathToSend, shouldUnlinkTempFile].
 */
function doc_ocr_compress_local_if_needed(string $filePath): array
{
    if (!is_file($filePath) || filesize($filePath) <= 950 * 1024) {
        return [$filePath, false];
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!function_exists('imagecreatefromstring') || $ext === 'pdf') {
        return [$filePath, false];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return [$filePath, false];
    }
    $img = @imagecreatefromstring($raw);
    if (!$img) {
        return [$filePath, false];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $maxDim = 1600;
    if ($w > $maxDim || $h > $maxDim) {
        $ratio = min($maxDim / $w, $maxDim / $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fittrack_ocr_' . uniqid('', true) . '.jpg';
    @imagejpeg($img, $tmpPath, 78);
    imagedestroy($img);

    if (is_file($tmpPath) && filesize($tmpPath) > 0) {
        return [$tmpPath, true];
    }
    return [$filePath, false];
}

/**
 * Call OCR.space API to extract text from a URL or local file.
 */
function doc_ocr_extract_text(string $storedValue): string
{
    $apiKey = trim((string) app_env('OCR_SPACE_API_KEY'));
    if ($apiKey === '') {
        throw new RuntimeException('OCR.space API key is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for OCR.');
    }

    $source = doc_ocr_resolve_source($storedValue);
    if ($source['type'] === 'none') {
        throw new RuntimeException('Document file could not be located for scanning.');
    }

    $postFields = [
        'language'          => 'eng',
        'isOverlayRequired' => 'false',
        'detectOrientation' => 'true',
        'scale'             => 'true',
        'OCREngine'         => '2',
    ];

    $tmpToDelete = null;
    if ($source['type'] === 'url') {
        $postFields['url'] = $source['value'];
    } else {
        [$sendPath, $isTemp] = doc_ocr_compress_local_if_needed($source['value']);
        if ($isTemp) {
            $tmpToDelete = $sendPath;
        }
        $mime = mime_content_type($sendPath) ?: 'image/jpeg';
        $postFields['file'] = new CURLFile($sendPath, $mime, basename($sendPath));
    }

    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $apiKey],
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => 45,
    ]);

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tmpToDelete && is_file($tmpToDelete)) {
        @unlink($tmpToDelete);
    }

    if ($responseBody === false) {
        throw new RuntimeException('OCR.space connection failed: ' . $curlErr);
    }

    $json = json_decode((string) $responseBody, true);
    if (!is_array($json)) {
        throw new RuntimeException('OCR.space returned invalid JSON (HTTP ' . $httpCode . ').');
    }

    if (!empty($json['IsErroredOnProcessing'])) {
        $errMsgs = $json['ErrorMessage'] ?? ['Unknown OCR error'];
        $msg = is_array($errMsgs) ? implode('; ', $errMsgs) : (string) $errMsgs;
        throw new RuntimeException('OCR error: ' . $msg);
    }

    $parsedText = '';
    foreach (($json['ParsedResults'] ?? []) as $pr) {
        $parsedText .= "\n" . (string) ($pr['ParsedText'] ?? '');
    }

    return trim($parsedText);
}

/**
 * Analyze extracted OCR text against expected Philippine compliance document patterns.
 */
function doc_ocr_analyze_text(string $docType, string $text, string $gymName = '', string $ownerName = ''): array
{
    $normalized = strtolower((string) preg_replace('/\s+/', ' ', $text));
    $textLength = strlen(trim($normalized));
    $currentYear = (string) date('Y');
    $nextYear = (string) ((int) date('Y') + 1);

    $keywordSets = [
        'business_permit' => [
            'permit', 'mayor', 'business', 'license', 'municipality', 'city',
            'bplo', 'dti', 'sec', 'bir', 'renewal', 'certificate', 'registration',
            'republic of the philippines', 'office of the', 'treasurer', 'tax'
        ],
        'barangay_clearance' => [
            'barangay', 'clearance', 'punong barangay', 'captain', 'certify',
            'certification', 'this is to certify', 'issued', 'republic of the philippines',
            'kagawad', 'purok', 'sitio', 'residents', 'business'
        ],
        'fire_safety_cert' => [
            'fire', 'safety', 'bfp', 'bureau of fire', 'inspection', 'fsic',
            'protection', 'clearance', 'certificate', 'marshal', 'republic of the philippines'
        ],
    ];

    $expectedKeywords = $keywordSets[$docType] ?? $keywordSets['business_permit'];
    $matchedKeywords = [];
    foreach ($expectedKeywords as $kw) {
        if (str_contains($normalized, $kw)) {
            $matchedKeywords[] = $kw;
        }
    }

    // Extract 4-digit years (2018 - 2035)
    preg_match_all('/\b(201[89]|202\d|203[0-5])\b/', $normalized, $yearMatches);
    $yearsFound = array_values(array_unique($yearMatches[1] ?? []));
    sort($yearsFound);
    $hasCurrentYear = in_array($currentYear, $yearsFound, true) || in_array($nextYear, $yearsFound, true);

    // Check if gym name or owner name words appear
    $nameMatched = false;
    $candidateTokens = array_filter(
        preg_split('/[\s,.\-]+/', strtolower(trim($gymName . ' ' . $ownerName))) ?: [],
        static fn(string $t): bool => strlen($t) >= 3 && !in_array($t, ['gym', 'fitness', 'the', 'and', 'inc', 'corp', 'llc'], true)
    );
    foreach ($candidateTokens as $tok) {
        if (str_contains($normalized, $tok)) {
            $nameMatched = true;
            break;
        }
    }

    // Determine Verdict
    $kwCount = count($matchedKeywords);
    if ($textLength < 25 || $kwCount === 0) {
        $verdict = 'flagged';
        $note = $textLength < 25
            ? 'Unreadable or blank image — very little text detected.'
            : 'Missing expected document keywords (may be an unrelated image).';
    } elseif ($kwCount >= 2 && $hasCurrentYear) {
        $verdict = 'verified';
        $note = "Valid {$currentYear} document keywords detected" . ($nameMatched ? ' & facility/owner name matched.' : '.');
    } elseif ($kwCount >= 2 && !empty($yearsFound) && !$hasCurrentYear) {
        $verdict = 'warning';
        $note = 'Document keywords found, but year ' . $currentYear . ' was not detected (found: ' . implode(', ', $yearsFound) . '). Check expiration.';
    } else {
        $verdict = 'warning';
        $note = 'Partial match (' . $kwCount . ' keyword' . ($kwCount === 1 ? '' : 's') . '). Recommend manual visual check.';
    }

    return [
        'verdict'            => $verdict,
        'years_found'        => implode(', ', $yearsFound),
        'current_year_found' => $hasCurrentYear ? 1 : 0,
        'keywords_matched'   => implode(', ', array_slice($matchedKeywords, 0, 8)),
        'name_matched'       => $nameMatched ? 1 : 0,
        'summary_note'       => $note,
    ];
}

/**
 * Scan a gym's uploaded compliance document, analyze it, and save to gym_document_ocr_checks.
 */
function doc_ocr_scan_gym_document(int $gymId, string $docType, string $fileUrl, string $gymName = '', string $ownerName = ''): array
{
    doc_ocr_ensure_table();
    $fileUrl = trim($fileUrl);
    if ($gymId <= 0 || $fileUrl === '') {
        throw new InvalidArgumentException('Gym ID and file URL are required.');
    }

    try {
        $rawText = doc_ocr_extract_text($fileUrl);
        $analysis = doc_ocr_analyze_text($docType, $rawText, $gymName, $ownerName);
    } catch (Throwable $e) {
        $analysis = [
            'verdict'            => 'error',
            'years_found'        => '',
            'current_year_found' => 0,
            'keywords_matched'   => '',
            'name_matched'       => 0,
            'summary_note'       => substr($e->getMessage(), 0, 250),
        ];
        $rawText = '';
    }

    $stmt = db()->prepare("
        INSERT INTO gym_document_ocr_checks
            (gym_id, document_type, file_url, verdict, years_found, current_year_found, keywords_matched, name_matched, extracted_text, summary_note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            file_url = VALUES(file_url),
            verdict = VALUES(verdict),
            years_found = VALUES(years_found),
            current_year_found = VALUES(current_year_found),
            keywords_matched = VALUES(keywords_matched),
            name_matched = VALUES(name_matched),
            extracted_text = VALUES(extracted_text),
            summary_note = VALUES(summary_note),
            scanned_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $gymId,
        $docType,
        $fileUrl,
        $analysis['verdict'],
        $analysis['years_found'] ?: null,
        $analysis['current_year_found'],
        $analysis['keywords_matched'] ?: null,
        $analysis['name_matched'],
        mb_substr($rawText, 0, 15000),
        $analysis['summary_note'],
    ]);

    return array_merge(['document_type' => $docType, 'file_url' => $fileUrl], $analysis);
}

/**
 * Fetch all OCR check records for a gym keyed by document_type.
 */
function doc_ocr_get_for_gym(int $gymId): array
{
    doc_ocr_ensure_table();
    try {
        $stmt = db()->prepare('SELECT * FROM gym_document_ocr_checks WHERE gym_id = ?');
        $stmt->execute([$gymId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['document_type']] = $row;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Return UI badge styling for an OCR verdict.
 */
function doc_ocr_badge(string $verdict): array
{
    switch ($verdict) {
        case 'verified':
            return ['icon' => '🟢', 'label' => 'OCR Passed', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.12)', 'border' => 'rgba(16,185,129,0.35)'];
        case 'flagged':
            return ['icon' => '🔴', 'label' => 'OCR Flagged', 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.12)', 'border' => 'rgba(239,68,68,0.35)'];
        case 'error':
            return ['icon' => '⚪', 'label' => 'Scan Error', 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.12)', 'border' => 'rgba(148,163,184,0.3)'];
        default:
            return ['icon' => '🟡', 'label' => 'Review Needed', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.35)'];
    }
}
