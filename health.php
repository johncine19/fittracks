<?php
// Lightweight health-check endpoint for the loading page.
// Returns a JSON response with CORS headers so the GitHub Pages
// loading screen can verify the app is truly awake.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode(['status' => 'ok', 'app' => 'fittrack']);
