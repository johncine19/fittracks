<?php

declare(strict_types=1);

/**
 * Public Didit Webhook Endpoint
 * URL: https://fitworks.tech/index.php?page=didit_webhook
 */
function didit_webhook_page(): void
{
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    // Optional HMAC verification if DIDIT_WEBHOOK_SECRET is configured in .env
    $webhookSecret = trim((string) app_env('DIDIT_WEBHOOK_SECRET'));
    if ($webhookSecret !== '') {
        $sigHeader = (string) ($_SERVER['HTTP_X_SIGNATURE_V2'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '');
        if ($sigHeader !== '') {
            $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expected, $sigHeader)) {
                // Also support Didit's X-Signature-Simple if present
                $simpleSig = (string) ($_SERVER['HTTP_X_SIGNATURE_SIMPLE'] ?? '');
                $ts = (string) ($_SERVER['HTTP_X_TIMESTAMP'] ?? ($payload['timestamp'] ?? ''));
                $sid = (string) ($payload['session_id'] ?? '');
                $st = (string) ($payload['status'] ?? '');
                $wt = (string) ($payload['webhook_type'] ?? '');
                $expectedSimple = hash_hmac('sha256', "{$ts}:{$sid}:{$st}:{$wt}", $webhookSecret);
                if ($simpleSig === '' || !hash_equals($expectedSimple, $simpleSig)) {
                    http_response_code(401);
                    echo json_encode(['ok' => false, 'error' => 'Invalid webhook signature']);
                    exit;
                }
            }
        }
    }

    $sessionId = trim((string) ($payload['session_id'] ?? $payload['data']['session_id'] ?? ''));
    if ($sessionId !== '' && function_exists('didit_sync_decision') && function_exists('didit_is_configured') && didit_is_configured()) {
        try {
            // Pull authoritative decision directly from Didit API and update gym_didit_verifications
            didit_sync_decision($sessionId);
        } catch (Throwable $e) {
            error_log('[Didit Webhook] Sync failed for session ' . $sessionId . ': ' . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'session_id' => $sessionId]);
    exit;
}
