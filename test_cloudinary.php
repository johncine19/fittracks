<?php
require __DIR__ . '/core/bootstrap.php';

echo "<h1>Cloudinary Diagnostic Test</h1>";
echo "<pre>";

$cloudName = app_env('CLOUDINARY_CLOUD_NAME');
$apiKey = app_env('CLOUDINARY_API_KEY');
$apiSecret = app_env('CLOUDINARY_API_SECRET');

echo "Cloud Name: " . ($cloudName ? htmlspecialchars($cloudName) : "❌ MISSING") . "\n";
echo "API Key: " . ($apiKey ? substr($apiKey, 0, 4) . "..." . substr($apiKey, -4) : "❌ MISSING") . "\n";
echo "API Secret: " . ($apiSecret ? "Configured (" . strlen($apiSecret) . " chars)" : "❌ MISSING") . "\n\n";

if (!$cloudName || !$apiKey || !$apiSecret) {
    echo "❌ ERROR: Cloudinary environment variables are missing in Render!\n";
    echo "Please ensure you have added CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, and CLOUDINARY_API_SECRET in Render Environment tab.\n";
    echo "</pre>";
    exit;
}

echo "Attempting test upload to Cloudinary...\n";

// Create a small 1x1 test PNG image in memory
$tempFile = tempnam(sys_get_temp_dir(), 'cld_test_') . '.png';
$im = imagecreatetruecolor(100, 100);
$bg = imagecolorallocate($im, 78, 201, 176); // FitTracks teal
imagefilledrectangle($im, 0, 0, 99, 99, $bg);
imagepng($im, $tempFile);
imagedestroy($im);

$timestamp = time();
$folder = 'fittracks_diagnostic';
$signatureStr = "folder={$folder}&timestamp={$timestamp}{$apiSecret}";
$cloudName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$cloudName));
$apiKey = trim((string)$apiKey);
$apiSecret = trim((string)$apiSecret);

$uploadUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload";
echo "Target URL: " . htmlspecialchars($uploadUrl) . "\n";

$ch = curl_init($uploadUrl);
$cFile = new CURLFile($tempFile, 'image/png', 'diagnostic_test.png');

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => $cFile,
    'api_key' => $apiKey,
    'timestamp' => $timestamp,
    'signature' => $signature,
    'folder' => $folder
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);
@unlink($tempFile);

echo "HTTP Status Code: " . $httpCode . "\n";
if ($curlErrno !== 0) {
    echo "cURL Error (" . $curlErrno . "): " . htmlspecialchars($curlError) . "\n";
}
echo "Response: " . htmlspecialchars($response ?: "(empty)") . "\n\n";

if ($httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    $url = $data['secure_url'] ?? '';
    echo "✅ SUCCESS: Cloudinary upload is working perfectly!\n";
    echo "Uploaded Image URL: <a href='" . htmlspecialchars($url) . "' target='_blank'>" . htmlspecialchars($url) . "</a>\n";
} else {
    echo "❌ ERROR: Cloudinary upload failed. Check the response above.\n";
}

echo "</pre>";
