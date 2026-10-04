<?php
declare(strict_types=1);

/**
 * Securely streams verification documents (permits, valid IDs, clearances)
 * only to authenticated administrators or the gym owner who uploaded them.
 */
function view_permit_handler(): void
{
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        exit('Unauthorized. Please log in to view this document.');
    }

    $allowedRoles = ['platform_admin', 'admin', 'gym_owner'];
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        exit('Access Denied: You do not have permission to access verification documents.');
    }

    $rawFile = $_GET['file'] ?? '';
    // Sanitize and prevent directory traversal
    $filename = basename((string)$rawFile);
    if (empty($filename)) {
        http_response_code(400);
        exit('Invalid document request.');
    }

    $permitsDir = realpath(__DIR__ . '/../../assets/permits');
    $filePath = realpath(__DIR__ . '/../../assets/permits/' . $filename);

    if ($permitsDir === false || $filePath === false || !str_starts_with($filePath, $permitsDir) || !is_file($filePath)) {
        http_response_code(404);
        exit('Document not found.');
    }

    // If gym_owner, ensure the requested document belongs to their gym
    if ($user['role'] === 'gym_owner') {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT gym_id FROM gyms 
            WHERE owner_user_id = ? 
              AND (business_permit_url = ? OR barangay_clearance_url = ? OR fire_safety_cert_url = ? OR valid_id_url = ?)
            LIMIT 1
        ');
        $stmt->execute([(int)$user['user_id'], $filename, $filename, $filename, $filename]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            exit('Access Denied: You do not have permission to view this gym document.');
        }
    }

    // Determine MIME type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf'  => 'application/pdf',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        default => (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream') ?: 'application/octet-stream',
    };

    // Clean any prior output buffering to avoid corrupted file output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($filePath));
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    readfile($filePath);
    exit;
}
