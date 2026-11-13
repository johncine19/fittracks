<?php
declare(strict_types=1);

/**
 * Secure and Optimized handling of user-uploaded files.
 *
 * Validates real MIME types, enforces size limits, and automatically
 * optimizes/downscales images into lightweight WebP format to save server
 * bandwidth and storage.
 */
final class FileUpload
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const ALLOWED_MIME_PERMIT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private const ALLOWED_MIME_ANIMATION = [
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mp4',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB input limit (will be compressed down to <150KB)

    /**
     * @param array $file one entry of $_FILES, e.g. $_FILES['profile_picture']
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function validate(array $file): void
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.');
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new RuntimeException('File is too large (max 10MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime]) && !isset(self::ALLOWED_MIME_PERMIT[$mime])) {
            throw new RuntimeException('Unsupported file type. Please upload a JPG, PNG, GIF, or WebP image.');
        }
    }

    public static function validatePermit(array $file): void
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.');
        }

        if ($file['size'] > self::MAX_SIZE) {
            throw new RuntimeException('File is too large (max 10MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_PERMIT[$mime])) {
            throw new RuntimeException('Unsupported file type. Please upload a JPG, PNG, WebP, or PDF document.');
        }
    }

    public static function validateAnimation(array $file): void
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.');
        }

        // Enforce 15MB limit for animations
        if ($file['size'] > 15 * 1024 * 1024) {
            throw new RuntimeException('Animation file is too large (max 15MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime === 'application/octet-stream' || !isset(self::ALLOWED_MIME_ANIMATION[$mime])) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $extToMime = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            if (isset($extToMime[$ext])) {
                $mime = $extToMime[$ext];
            }
        }

        if (!isset(self::ALLOWED_MIME_ANIMATION[$mime])) {
            throw new RuntimeException('Unsupported animation format. Please upload an MP4, WebM, GIF, or WebP file.');
        }
    }

    /**
     * Stores an exercise animation on ImageKit CDN (with automatic local fallback).
     */
    public static function storeExerciseAnimation(array $file, int $exerciseId): string
    {
        self::validateAnimation($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if ($mime === 'application/octet-stream' || !isset(self::ALLOWED_MIME_ANIMATION[$mime])) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $extToMime = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            if (isset($extToMime[$ext])) {
                $mime = $extToMime[$ext];
            }
        }

        $extension = self::ALLOWED_MIME_ANIMATION[$mime] ?? 'mp4';
        $filename = 'ex_' . $exerciseId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        // Attempt upload to ImageKit CDN first
        $imageKitUrl = self::uploadToImageKit($file['tmp_name'], $mime, $filename, '/exercise_animations');
        if ($imageKitUrl) {
            return $imageKitUrl;
        }

        // Fallback to local storage under assets/exercise_animations/
        $uploadDir = __DIR__ . '/../assets/exercise_animations/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the exercise animation.');
        }

        return $filename;
    }

    /**
     * Validates a gym equipment image file with a 5MB size limit.
     */
    public static function validateEquipmentImage(array $file): void
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Equipment image upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.');
        }

        // Enforce 5MB limit requested by user
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Equipment image is too large. Maximum allowed size is 5MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Unsupported image type. Please upload a JPG, PNG, WebP, or GIF file.');
        }
    }

    /**
     * Stores a gym equipment image on ImageKit CDN (folder: /equipment) with automatic local fallback.
     */
    public static function storeEquipmentImage(array $file, int $gymId = 0): string
    {
        self::validateEquipmentImage($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $extension = self::ALLOWED_MIME[$mime] ?? 'jpg';
        $filename = 'equip_' . ($gymId > 0 ? $gymId . '_' : '') . bin2hex(random_bytes(6)) . '.' . $extension;

        // Upload to ImageKit CDN (/equipment folder)
        $imageKitUrl = self::uploadToImageKit($file['tmp_name'], $mime, $filename, '/equipment');
        if ($imageKitUrl) {
            return $imageKitUrl;
        }

        // Fallback to local storage under assets/equipment/
        $uploadDir = __DIR__ . '/../assets/equipment/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the equipment image locally.');
        }

        return 'assets/equipment/' . $filename;
    }

    /**
     * Stores a meal photo on ImageKit CDN (folder: /meals) with automatic local fallback.
     */
    public static function storeMealImage(array $file): string
    {
        self::validateEquipmentImage($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $extension = self::ALLOWED_MIME[$mime] ?? 'jpg';
        $filename = 'meal_' . bin2hex(random_bytes(6)) . '.' . $extension;

        // Upload to ImageKit CDN (/meals folder)
        $imageKitUrl = self::uploadToImageKit($file['tmp_name'], $mime, $filename, '/meals');
        if ($imageKitUrl) {
            return $imageKitUrl;
        }

        // Fallback to local storage under assets/uploads/meals/
        $uploadDir = __DIR__ . '/../assets/uploads/meals/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the meal image locally.');
        }

        return 'assets/uploads/meals/' . $filename;
    }

    /**
     * Uploads an exercise animation to ImageKit via REST API.
     * Returns the full CDN URL if successful, or false on failure.
     */
    private static function uploadToImageKit(string $filePath, string $mimeType, string $fileName, string $folder = '/exercise_animations'): string|false
    {
        $privateKey = trim((string)app_env('IMAGEKIT_PRIVATE_KEY'));
        if (!$privateKey) {
            return false;
        }

        $ch = curl_init('https://upload.imagekit.io/api/v1/files/upload');
        $cFile = new CURLFile($filePath, $mimeType, $fileName);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $privateKey . ':',
            CURLOPT_POSTFIELDS => [
                'file' => $cFile,
                'fileName' => $fileName,
                'folder' => $folder,
                'useUniqueFileName' => 'true'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['url'])) {
                return $data['url'];
            }
        }

        error_log("ImageKit upload failed (HTTP {$httpCode}): {$curlError} - Response: {$response}");
        return false;
    }

    /**
     * Resizes and encodes an uploaded image to WebP with aspect ratio preservation and auto-orientation.
     */
    private static function processAndStoreImage(string $tmpPath, string $destPath, int $maxWidth, int $maxHeight, int $quality = 82): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return move_uploaded_file($tmpPath, $destPath);
        }

        $imageInfo = @getimagesize($tmpPath);
        if (!$imageInfo) {
            return move_uploaded_file($tmpPath, $destPath);
        }

        $imgW = (int) ($imageInfo[0] ?? 0);
        $imgH = (int) ($imageInfo[1] ?? 0);
        if ($imgW <= 0 || $imgH <= 0 || $imgW > 6000 || $imgH > 6000 || ($imgW * $imgH) > 25000000) {
            return false;
        }
        $mime = $imageInfo['mime'];
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            'image/gif'  => @imagecreatefromgif($tmpPath),
            default      => null,
        };

        if (!$src) {
            return move_uploaded_file($tmpPath, $destPath);
        }

        // Correct smartphone EXIF orientation if available
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($tmpPath);
                if (!empty($exif['Orientation'])) {
                    $src = match ($exif['Orientation']) {
                        3 => imagerotate($src, 180, 0),
                        6 => imagerotate($src, -90, 0),
                        8 => imagerotate($src, 90, 0),
                        default => $src,
                    };
                }
            } catch (Throwable) {}
        }

        $origWidth = imagesx($src);
        $origHeight = imagesy($src);

        // Proportional downscaling
        $scale = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1.0);
        $newWidth = (int) max(1, round($origWidth * $scale));
        $newHeight = (int) max(1, round($origHeight * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG/WebP
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        $saved = imagewebp($canvas, $destPath, $quality);

        imagedestroy($src);
        imagedestroy($canvas);

        return $saved;
    }

    /**
     * Converts a human name into a safe folder/public_id slug.
     */
    private static function slugify(string $text, string $fallback = 'item'): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($text));
        $slug = trim((string)$slug, '_');
        if ($slug === '') {
            return $fallback;
        }
        return substr($slug, 0, 45);
    }

    /**
     * Sanitizes a value for Cloudinary pipe-delimited context metadata.
     */
    private static function sanitizeContextValue(string $val): string
    {
        return trim(str_replace(['|', '='], [' ', '-'], $val));
    }

    /**
     * Looks up user metadata for organizing Cloudinary uploads.
     */
    private static function resolveUserMeta(int $userId): array
    {
        $fullName = "User {$userId}";
        $role = 'member';
        $email = '';
        $profilePic = '';

        if ($userId > 0 && function_exists('db')) {
            try {
                $stmt = db()->prepare('SELECT first_name, last_name, role, email, profile_picture FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    if ($name !== '') {
                        $fullName = $name;
                    }
                    if (!empty($u['role'])) {
                        $role = (string)$u['role'];
                    }
                    $email = (string)($u['email'] ?? '');
                    $profilePic = (string)($u['profile_picture'] ?? '');
                }
            } catch (Throwable) {}
        }

        return [
            'user_id'         => $userId,
            'full_name'       => $fullName,
            'slug'            => self::slugify($fullName, 'User_' . $userId),
            'role'            => preg_replace('/[^a-zA-Z0-9_-]/', '', $role) ?: 'member',
            'email'           => $email,
            'profile_picture' => $profilePic,
        ];
    }

    /**
     * Resolves gym & owner metadata whether $id is a gym_id or owner user_id.
     */
    private static function resolveGymMeta(int $id, bool $preferUserAsOwner = false): array
    {
        $gymId = 0;
        $gymName = '';
        $ownerId = 0;

        if ($id > 0 && function_exists('db')) {
            try {
                $pdo = db();
                if ($preferUserAsOwner) {
                    $ownerId = $id;
                    $gymName = trim((string)($_POST['gym_name'] ?? $_POST['name'] ?? ''));
                    $stmt = $pdo->prepare('SELECT gym_id, name FROM gyms WHERE owner_user_id = ? ORDER BY gym_id DESC LIMIT 1');
                    $stmt->execute([$ownerId]);
                    $g = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($g) {
                        $gymId = (int)$g['gym_id'];
                        if ($gymName === '') {
                            $gymName = (string)$g['name'];
                        }
                    }
                } else {
                    $stmt = $pdo->prepare('SELECT gym_id, name, owner_user_id FROM gyms WHERE gym_id = ? LIMIT 1');
                    $stmt->execute([$id]);
                    $g = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($g) {
                        $gymId = (int)$g['gym_id'];
                        $gymName = trim((string)($_POST['name'] ?? '')) ?: (string)$g['name'];
                        $ownerId = (int)($g['owner_user_id'] ?? 0);
                    } else {
                        $ownerId = $id;
                        $gymName = trim((string)($_POST['gym_name'] ?? $_POST['name'] ?? ''));
                        $stmt2 = $pdo->prepare('SELECT gym_id, name FROM gyms WHERE owner_user_id = ? ORDER BY gym_id DESC LIMIT 1');
                        $stmt2->execute([$ownerId]);
                        $g2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                        if ($g2) {
                            $gymId = (int)$g2['gym_id'];
                            if ($gymName === '') {
                                $gymName = (string)$g2['name'];
                            }
                        }
                    }
                }
            } catch (Throwable) {}
        }

        if ($ownerId <= 0 && !empty($_SESSION['user_id'])) {
            $ownerId = (int)$_SESSION['user_id'];
        }
        $ownerMeta = self::resolveUserMeta($ownerId);

        if ($gymName === '') {
            $gymName = $gymId > 0 ? "Gym {$gymId}" : "Gym Application";
        }
        $gymSlug = self::slugify($gymName, $gymId > 0 ? "Gym_{$gymId}" : 'Gym_Application');
        $ownerSlug = $ownerMeta['slug'];

        $folderName = $gymSlug . ($ownerId > 0 ? "_(Owner_{$ownerId}_{$ownerSlug})" : '');

        return [
            'gym_id'      => $gymId,
            'gym_name'    => $gymName,
            'gym_slug'    => $gymSlug,
            'owner_id'    => $ownerId,
            'owner_name'  => $ownerMeta['full_name'],
            'owner_slug'  => $ownerSlug,
            'owner_email' => $ownerMeta['email'],
            'folder_name' => $folderName,
        ];
    }

    /**
     * Uploads a file to Cloudinary via REST API.
     * Returns the secure_url if successful, or false on failure.
     */
    private static function uploadToCloudinary(string $filePath, string $mimeType, string $folder, array $options = []): string|false
    {
        $cloudName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)app_env('CLOUDINARY_CLOUD_NAME')));
        $apiKey = trim((string)app_env('CLOUDINARY_API_KEY'));
        $apiSecret = trim((string)app_env('CLOUDINARY_API_SECRET'));

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return false;
        }

        $params = [
            'asset_folder' => (string)($options['asset_folder'] ?? $folder),
            'folder'       => $folder,
            'timestamp'    => time(),
        ];

        foreach (['public_id', 'display_name', 'tags', 'context'] as $optKey) {
            if (!empty($options[$optKey])) {
                $val = (string)$options[$optKey];
                if ($optKey === 'display_name') {
                    $val = trim((string)preg_replace('/\s+/', ' ', str_replace(['/', '\\'], '-', $val)));
                }
                $params[$optKey] = $val;
            }
        }

        ksort($params);
        $sigParts = [];
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $sigParts[] = $k . '=' . $v;
            }
        }
        $signature = sha1(implode('&', $sigParts) . $apiSecret);

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload");
        
        $cFile = new CURLFile($filePath, $mimeType, basename($filePath));
        $postFields = $params;
        $postFields['file'] = $cFile;
        $postFields['api_key'] = $apiKey;
        $postFields['signature'] = $signature;
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $data = json_decode($response, true);
            return $data['secure_url'] ?? false;
        }

        error_log("Cloudinary upload failed (HTTP {$httpCode}): {$curlError} - Response: {$response}");
        return false;
    }

    public static function storeProfilePicture(array $file, int $userId): string
    {
        self::validate($file);

        $uMeta = self::resolveUserMeta($userId);
        $shortHash = bin2hex(random_bytes(4));
        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'profile_' . $userId . '_' . $shortHash . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 600, 600, 82)) {
            throw new RuntimeException('Could not process the profile picture.');
        }

        $folder = 'fittracks_profiles/' . $uMeta['role'] . '/User_' . $userId . '_' . $uMeta['slug'];
        $publicId = 'user_' . $userId . '_' . strtolower($uMeta['slug']) . '_avatar_' . $shortHash;
        $roleLabel = ucwords(str_replace('_', ' ', $uMeta['role']));
        $displayName = "User #{$userId} - {$uMeta['full_name']} ({$roleLabel} Avatar)";
        $context = 'user_id=' . $userId
            . '|name=' . self::sanitizeContextValue($uMeta['full_name'])
            . '|role=' . self::sanitizeContextValue($uMeta['role'])
            . ($uMeta['email'] !== '' ? '|email=' . self::sanitizeContextValue($uMeta['email']) : '');

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', $folder, [
            'asset_folder' => $folder,
            'public_id'    => $publicId,
            'display_name' => $displayName,
            'tags'         => "profile_picture,user_{$userId},{$uMeta['role']}",
            'context'      => $context,
        ]);
        if ($cloudinaryUrl) {
            @unlink($destPath);
            // Clean up previous profile picture so duplicates do not accumulate
            if (!empty($uMeta['profile_picture']) && $uMeta['profile_picture'] !== $cloudinaryUrl) {
                self::deleteGymGalleryImage($uMeta['profile_picture']);
            }
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        rename($destPath, $uploadDir . $filename);
        if (!empty($uMeta['profile_picture']) && $uMeta['profile_picture'] !== $filename) {
            self::deleteGymGalleryImage($uMeta['profile_picture']);
        }
        return $filename;
    }

    /**
     * Internal helper to store verification documents (PDF or image).
     */
    private static function storeVerificationDoc(array $file, int $userId, string $prefix, string $label): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $preferUserAsOwner = !empty($_POST['gym_name']);
        $gMeta = self::resolveGymMeta($userId, $preferUserAsOwner);
        $shortHash = bin2hex(random_bytes(4));
        $folder = 'fittracks_permits/' . $gMeta['folder_name'];
        $publicId = strtolower($gMeta['gym_slug']) . '_' . $prefix . '_owner_' . $gMeta['owner_id'] . '_' . $shortHash;
        $prettyLabel = ucwords($label);
        $gymPrefix = $gMeta['gym_id'] > 0 ? "Gym #{$gMeta['gym_id']} {$gMeta['gym_name']}" : $gMeta['gym_name'];
        $displayName = "{$gymPrefix} - {$prettyLabel} ({$gMeta['owner_name']})";
        $tags = "verification_doc,{$prefix},owner_{$gMeta['owner_id']}" . ($gMeta['gym_id'] > 0 ? ",gym_{$gMeta['gym_id']}" : '');
        $context = 'gym_name=' . self::sanitizeContextValue($gMeta['gym_name'])
            . ($gMeta['gym_id'] > 0 ? '|gym_id=' . $gMeta['gym_id'] : '')
            . '|owner_id=' . $gMeta['owner_id']
            . '|owner_name=' . self::sanitizeContextValue($gMeta['owner_name'])
            . '|doc_type=' . self::sanitizeContextValue($prettyLabel);

        $cldOptions = [
            'asset_folder' => $folder,
            'public_id'    => $publicId,
            'display_name' => $displayName,
            'tags'         => $tags,
            'context'      => $context,
        ];

        if ($mime === 'application/pdf') {
            $cloudinaryUrl = self::uploadToCloudinary($file['tmp_name'], 'application/pdf', $folder, $cldOptions);
            if ($cloudinaryUrl) return $cloudinaryUrl;

            $uploadDir = __DIR__ . '/../assets/permits/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
            $filename = $prefix . '_' . $userId . '_' . $shortHash . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException("Could not save the {$label}.");
            }
            return $filename;
        }

        $tempDir = sys_get_temp_dir() . '/';
        $filename = $prefix . '_' . $userId . '_' . $shortHash . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2000, 2000, 85)) {
            throw new RuntimeException("Could not process the {$label}.");
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', $folder, $cldOptions);
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }

    public static function storeBusinessPermit(array $file, int $userId): string
    {
        return self::storeVerificationDoc($file, $userId, 'business_permit', 'business permit');
    }

    /**
     * Stores a valid ID under assets/permits.
     * Keeps PDF original; compresses image IDs to high-clarity WebP.
     */
    public static function storeValidId(array $file, int $userId, string $subType = 'primary'): string
    {
        if ($subType === 'secondary' || $subType === 'supporting') {
            return self::storeVerificationDoc($file, $userId, 'supporting_valid_id', 'supporting valid ID');
        }
        return self::storeVerificationDoc($file, $userId, 'valid_id', 'valid ID');
    }

    /**
     * Stores a Barangay Clearance under assets/permits.
     */
    public static function storeBarangayClearance(array $file, int $userId): string
    {
        return self::storeVerificationDoc($file, $userId, 'barangay_clearance', 'barangay clearance');
    }

    /**
     * Stores a Fire Safety Inspection Certificate under assets/permits.
     */
    public static function storeFireSafetyCert(array $file, int $userId): string
    {
        return self::storeVerificationDoc($file, $userId, 'fire_safety_cert', 'fire safety inspection certificate');
    }

    /**
     * Stores a gym logo under assets/uploads, compressed to WebP (max 500x500).
     */
    public static function storeGymLogo(array $file, int $gymId): string
    {
        self::validate($file);

        $gMeta = self::resolveGymMeta($gymId, false);
        $shortHash = bin2hex(random_bytes(4));
        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'gym_logo_' . $gymId . '_' . $shortHash . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 500, 500, 85)) {
            throw new RuntimeException('Could not process the gym logo.');
        }

        $folder = 'fittracks_gyms/' . $gMeta['folder_name'];
        $publicId = 'gym_' . $gymId . '_' . strtolower($gMeta['gym_slug']) . '_logo_' . $shortHash;
        $displayName = "Gym #{$gymId} {$gMeta['gym_name']} - Gym Logo ({$gMeta['owner_name']})";
        $context = "gym_id={$gymId}|gym_name=" . self::sanitizeContextValue($gMeta['gym_name'])
            . "|owner_id={$gMeta['owner_id']}|owner_name=" . self::sanitizeContextValue($gMeta['owner_name'])
            . "|asset_type=Gym Logo";

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', $folder, [
            'asset_folder' => $folder,
            'public_id'    => $publicId,
            'display_name' => $displayName,
            'tags'         => "gym_logo,gym_{$gymId},owner_{$gMeta['owner_id']}",
            'context'      => $context,
        ]);
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }

    /** Stores a public gym cover image, optimized for a wide hero banner. */
    public static function storeGymCoverImage(array $file, int $gymId): string
    {
        self::validate($file);

        $gMeta = self::resolveGymMeta($gymId, false);
        $shortHash = bin2hex(random_bytes(4));
        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'gym_' . $gymId . '_cover_' . $shortHash . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2200, 1100, 82)) {
            throw new RuntimeException('Could not process the gym cover image.');
        }

        $folder = 'fittracks_gym_covers/' . $gMeta['folder_name'];
        $publicId = 'gym_' . $gymId . '_' . strtolower($gMeta['gym_slug']) . '_cover_' . $shortHash;
        $displayName = "Gym #{$gymId} {$gMeta['gym_name']} - Cover Banner ({$gMeta['owner_name']})";
        $context = "gym_id={$gymId}|gym_name=" . self::sanitizeContextValue($gMeta['gym_name'])
            . "|owner_id={$gMeta['owner_id']}|owner_name=" . self::sanitizeContextValue($gMeta['owner_name'])
            . "|asset_type=Cover Banner";

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', $folder, [
            'asset_folder' => $folder,
            'public_id'    => $publicId,
            'display_name' => $displayName,
            'tags'         => "gym_cover,gym_{$gymId},owner_{$gMeta['owner_id']}",
            'context'      => $context,
        ]);
        if ($cloudinaryUrl) {
            @unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        if (!rename($destPath, $uploadDir . $filename)) {
            @unlink($destPath);
            throw new RuntimeException('Could not save the gym cover image.');
        }

        return $filename;
    }

    /**
     * Deletes an asset from Cloudinary by its full URL or public_id.
     */
    public static function deleteFromCloudinary(string $url): bool
    {
        $cloudName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)app_env('CLOUDINARY_CLOUD_NAME')));
        $apiKey = trim((string)app_env('CLOUDINARY_API_KEY'));
        $apiSecret = trim((string)app_env('CLOUDINARY_API_SECRET'));

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return false;
        }

        // Determine Cloudinary resource_type from URL path (defaults to image)
        $resourceType = 'image';
        if (str_contains($url, '/raw/upload/')) {
            $resourceType = 'raw';
        } elseif (str_contains($url, '/video/upload/')) {
            $resourceType = 'video';
        }

        // Extract public_id from Cloudinary URL: .../upload/(?:v\d+/)?(.+?)(?:\.[a-zA-Z0-9]+)?$
        $publicId = $url;
        if (preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.[a-zA-Z0-9]+)?$#', $url, $matches)) {
            $publicId = rawurldecode($matches[1]);
        }
        // Raw uploads in Cloudinary retain their file extension in public_id
        if ($resourceType === 'raw' && preg_match('#/raw/upload/(?:v\d+/)?(.+)$#', $url, $rawMatches)) {
            $publicId = rawurldecode($rawMatches[1]);
        }

        $timestamp = time();
        $signatureStr = "invalidate=true&public_id={$publicId}&timestamp={$timestamp}{$apiSecret}";
        $signature = sha1($signatureStr);

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/destroy");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id'  => $publicId,
            'invalidate' => 'true',
            'api_key'    => $apiKey,
            'timestamp'  => $timestamp,
            'signature'  => $signature,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Stores a gym gallery photo under Cloudinary (fittracks_gallery) with fallback to assets/uploads, compressed to WebP (max 1600x1200).
     */
    public static function storeGymGalleryImage(array $file, int $gymId): string
    {
        self::validate($file);

        $gMeta = self::resolveGymMeta($gymId, false);
        $shortHash = bin2hex(random_bytes(4));
        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'gym_' . $gymId . '_gallery_' . $shortHash . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 1600, 1200, 80)) {
            throw new RuntimeException('Could not save the gallery image.');
        }

        $folder = 'fittracks_gallery/' . $gMeta['folder_name'];
        $publicId = 'gym_' . $gymId . '_' . strtolower($gMeta['gym_slug']) . '_gallery_' . $shortHash;
        $displayName = "Gym #{$gymId} {$gMeta['gym_name']} - Gallery Photo ({$gMeta['owner_name']})";
        $context = "gym_id={$gymId}|gym_name=" . self::sanitizeContextValue($gMeta['gym_name'])
            . "|owner_id={$gMeta['owner_id']}|owner_name=" . self::sanitizeContextValue($gMeta['owner_name'])
            . "|asset_type=Gallery Photo";

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', $folder, [
            'asset_folder' => $folder,
            'public_id'    => $publicId,
            'display_name' => $displayName,
            'tags'         => "gym_gallery,gym_{$gymId},owner_{$gMeta['owner_id']}",
            'context'      => $context,
        ]);
        if ($cloudinaryUrl) {
            @unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $finalLocalPath = $uploadDir . $filename;
        rename($destPath, $finalLocalPath);

        return $filename;
    }

    /**
     * Deletes a verification/permit document from Cloudinary or the local permits directory.
     */
    public static function deletePermitDocument(string $filename): void
    {
        if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
            if (str_contains($filename, 'cloudinary.com')) {
                self::deleteFromCloudinary($filename);
            }
            return;
        }

        $cleanName = basename($filename);
        foreach ([__DIR__ . '/../assets/uploads/permits/' . $cleanName, __DIR__ . '/../assets/uploads/' . $cleanName] as $filePath) {
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * Deletes a gym gallery image from Cloudinary or the local filesystem.
     */
    public static function deleteGymGalleryImage(string $filename): void
    {
        if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
            if (str_contains($filename, 'cloudinary.com')) {
                self::deleteFromCloudinary($filename);
            }
            return;
        }

        $filePath = __DIR__ . '/../assets/uploads/' . basename($filename);
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Updates an existing Cloudinary asset's display_name, asset_folder, tags, and context metadata
     * via the Admin API without altering its public_id or delivery URL (zero broken links).
     */
    public static function updateCloudinaryAssetMetadata(string $urlOrPublicId, array $metadata): bool
    {
        $cloudName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)app_env('CLOUDINARY_CLOUD_NAME')));
        $apiKey = trim((string)app_env('CLOUDINARY_API_KEY'));
        $apiSecret = trim((string)app_env('CLOUDINARY_API_SECRET'));

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return false;
        }

        $publicId = $urlOrPublicId;
        if (preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.[a-zA-Z0-9]+)?$#', $urlOrPublicId, $matches)) {
            $publicId = rawurldecode($matches[1]);
        }

        $postData = [];
        foreach (['display_name', 'asset_folder', 'tags', 'context'] as $k) {
            if (!empty($metadata[$k])) {
                $val = (string)$metadata[$k];
                if ($k === 'display_name') {
                    $val = trim((string)preg_replace('/\s+/', ' ', str_replace(['/', '\\'], '-', $val)));
                }
                $postData[$k] = $val;
            }
        }
        if (empty($postData)) {
            return false;
        }

        $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/resources/image/upload/" . rawurlencode($publicId);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$apiKey}:{$apiSecret}",
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300 && !empty($response));
    }

    /**
     * Executes multiple Cloudinary metadata updates concurrently via curl_multi.
     *
     * @param array<int, array{url: string, meta: array}> $items
     * @return array{updated: int, failed: int}
     */
    private static function updateCloudinaryAssetMetadataBatch(array $items, int $concurrency = 12): array
    {
        $cloudName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)app_env('CLOUDINARY_CLOUD_NAME')));
        $apiKey = trim((string)app_env('CLOUDINARY_API_KEY'));
        $apiSecret = trim((string)app_env('CLOUDINARY_API_SECRET'));

        if (!$cloudName || !$apiKey || !$apiSecret || empty($items)) {
            return ['updated' => 0, 'failed' => count($items)];
        }

        $updated = 0;
        $failed = 0;

        foreach (array_chunk($items, max(1, $concurrency)) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $idx => $entry) {
                $urlOrPublicId = (string)($entry['url'] ?? '');
                $metadata = (array)($entry['meta'] ?? []);

                $publicId = $urlOrPublicId;
                if (preg_match('#/upload/(?:v\d+/)?(.+?)(?:\.[a-zA-Z0-9]+)?$#', $urlOrPublicId, $matches)) {
                    $publicId = rawurldecode($matches[1]);
                }

                $postData = [];
                foreach (['display_name', 'asset_folder', 'tags', 'context'] as $k) {
                    if (!empty($metadata[$k])) {
                        $val = (string)$metadata[$k];
                        if ($k === 'display_name') {
                            $val = trim((string)preg_replace('/\s+/', ' ', str_replace(['/', '\\'], '-', $val)));
                        }
                        $postData[$k] = $val;
                    }
                }
                if (empty($postData)) {
                    $failed++;
                    continue;
                }

                $endpoint = "https://api.cloudinary.com/v1_1/{$cloudName}/resources/image/upload/" . rawurlencode($publicId);
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_USERPWD        => "{$apiKey}:{$apiSecret}",
                    CURLOPT_POSTFIELDS     => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $ch) {
                $resp = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code >= 200 && $code < 300 && !empty($resp)) {
                    $updated++;
                } else {
                    $failed++;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Synchronizes a single gym's verification documents, logo, and cover image on Cloudinary
     * (useful immediately after gym_onboarding inserts the new gym_id).
     */
    public static function syncSingleGymAssetsToCloudinary(int $gymId, ?PDO $pdo = null): void
    {
        if ($gymId <= 0) {
            return;
        }
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM gyms WHERE gym_id = ? LIMIT 1');
            $stmt->execute([$gymId]);
            $g = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$g) {
                return;
            }
            if (function_exists('hydrate_gym_rows')) {
                $hydrated = hydrate_gym_rows([$g]);
                $g = $hydrated[0] ?? $g;
            }

            $ownerId = (int)($g['owner_user_id'] ?? 0);
            $owner = self::resolveUserMeta($ownerId);
            $gymName = trim((string)($g['name'] ?? '')) ?: "Gym {$gymId}";
            $gymSlug = self::slugify($gymName, "Gym_{$gymId}");
            $folderName = "{$gymSlug}_(Owner_{$ownerId}_{$owner['slug']})";

            $batch = [];
            $docCols = [
                'business_permit_url'    => ['tag' => 'business_permit', 'label' => 'Business Permit'],
                'valid_id_url'           => ['tag' => 'valid_id', 'label' => 'Valid ID'],
                'secondary_valid_id_url' => ['tag' => 'supporting_valid_id', 'label' => 'Supporting Valid ID'],
                'barangay_clearance_url' => ['tag' => 'barangay_clearance', 'label' => 'Barangay Clearance'],
                'fire_safety_cert_url'   => ['tag' => 'fire_safety_cert', 'label' => 'Fire Safety Certificate'],
            ];
            foreach ($docCols as $col => $info) {
                $url = (string)($g[$col] ?? '');
                if ($url !== '' && str_contains($url, 'cloudinary.com')) {
                    $batch[] = [
                        'url'  => $url,
                        'meta' => [
                            'asset_folder' => "fittracks_permits/{$folderName}",
                            'display_name' => "Gym #{$gymId} {$gymName} - {$info['label']} ({$owner['full_name']})",
                            'tags'         => "verification_doc,{$info['tag']},gym_{$gymId},owner_{$ownerId}",
                            'context'      => "gym_id={$gymId}|gym_name=" . self::sanitizeContextValue($gymName) . "|owner_id={$ownerId}|owner_name=" . self::sanitizeContextValue($owner['full_name']) . "|doc_type={$info['label']}",
                        ],
                    ];
                }
            }
            if (!empty($batch)) {
                self::updateCloudinaryAssetMetadataBatch($batch);
            }
        } catch (Throwable) {}
    }

    /**
     * Runs a one-time automatic sync of all existing database-linked Cloudinary assets on the current environment
     * (e.g., when deployed to production) so existing live gyms and users automatically align with folders.
     */
    public static function ensureDatabaseAssetsSynced(?PDO $pdo = null): void
    {
        if (!function_exists('get_platform_setting') || !function_exists('set_platform_setting')) {
            return;
        }
        try {
            if (get_platform_setting('cloudinary_folders_synced_v1', '0') === '1') {
                return;
            }
            set_platform_setting('cloudinary_folders_synced_v1', '1');
            self::syncDatabaseAssetsToCloudinary($pdo);
        } catch (Throwable $e) {
            error_log('Cloudinary auto-sync error: ' . $e->getMessage());
        }
    }

    /**
     * Synchronizes all database-linked Cloudinary URLs (users, gyms, verification documents, gallery)
     * with organized subfolders, human-readable display names, tags, and context metadata.
     * Safe to run anytime: never changes public_id or delivery URLs.
     */
    public static function syncDatabaseAssetsToCloudinary(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) {
            return ['updated' => 0, 'failed' => 0];
        }

        $batch = [];

        $users = [];
        try {
            foreach ($pdo->query('SELECT user_id, first_name, last_name, role, email, profile_picture FROM users')->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $uid = (int)$u['user_id'];
                $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: "User {$uid}";
                $role = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($u['role'] ?? 'member')) ?: 'member';
                $slug = self::slugify($fullName, "User_{$uid}");
                $users[$uid] = [
                    'user_id'   => $uid,
                    'full_name' => $fullName,
                    'slug'      => $slug,
                    'role'      => $role,
                    'email'     => (string)($u['email'] ?? ''),
                ];

                $pic = (string)($u['profile_picture'] ?? '');
                if ($pic !== '' && str_contains($pic, 'cloudinary.com')) {
                    $roleLabel = ucwords(str_replace('_', ' ', $role));
                    $batch[] = [
                        'url'  => $pic,
                        'meta' => [
                            'asset_folder' => "fittracks_profiles/{$role}/User_{$uid}_{$slug}",
                            'display_name' => "User #{$uid} - {$fullName} ({$roleLabel} Avatar)",
                            'tags'         => "profile_picture,user_{$uid},{$role}",
                            'context'      => "user_id={$uid}|name=" . self::sanitizeContextValue($fullName) . "|role={$role}",
                        ],
                    ];
                }
            }
        } catch (Throwable) {}

        $gyms = [];
        try {
            $rawGyms = $pdo->query('SELECT * FROM gyms')->fetchAll(PDO::FETCH_ASSOC);
            if (function_exists('hydrate_gym_rows')) {
                $rawGyms = hydrate_gym_rows($rawGyms);
            }
            foreach ($rawGyms as $g) {
                $gid = (int)$g['gym_id'];
                $ownerId = (int)($g['owner_user_id'] ?? 0);
                $owner = $users[$ownerId] ?? [
                    'full_name' => "Owner #{$ownerId}",
                    'slug'      => "Owner_{$ownerId}",
                ];
                $gymName = trim((string)($g['name'] ?? '')) ?: "Gym {$gid}";
                $gymSlug = self::slugify($gymName, "Gym_{$gid}");
                $folderName = "{$gymSlug}_(Owner_{$ownerId}_{$owner['slug']})";
                $gyms[$gid] = [
                    'gym_id'      => $gid,
                    'gym_name'    => $gymName,
                    'gym_slug'    => $gymSlug,
                    'owner_id'    => $ownerId,
                    'owner_name'  => $owner['full_name'],
                    'folder_name' => $folderName,
                ];

                if (!empty($g['logo_url']) && str_contains((string)$g['logo_url'], 'cloudinary.com')) {
                    $batch[] = [
                        'url'  => (string)$g['logo_url'],
                        'meta' => [
                            'asset_folder' => "fittracks_gyms/{$folderName}",
                            'display_name' => "Gym #{$gid} {$gymName} - Gym Logo ({$owner['full_name']})",
                            'tags'         => "gym_logo,gym_{$gid},owner_{$ownerId}",
                            'context'      => "gym_id={$gid}|gym_name=" . self::sanitizeContextValue($gymName) . "|owner_id={$ownerId}|owner_name=" . self::sanitizeContextValue($owner['full_name']) . "|asset_type=Gym Logo",
                        ],
                    ];
                }

                if (!empty($g['cover_image_url']) && str_contains((string)$g['cover_image_url'], 'cloudinary.com')) {
                    $batch[] = [
                        'url'  => (string)$g['cover_image_url'],
                        'meta' => [
                            'asset_folder' => "fittracks_gym_covers/{$folderName}",
                            'display_name' => "Gym #{$gid} {$gymName} - Cover Banner ({$owner['full_name']})",
                            'tags'         => "gym_cover,gym_{$gid},owner_{$ownerId}",
                            'context'      => "gym_id={$gid}|gym_name=" . self::sanitizeContextValue($gymName) . "|owner_id={$ownerId}|owner_name=" . self::sanitizeContextValue($owner['full_name']) . "|asset_type=Cover Banner",
                        ],
                    ];
                }

                $docCols = [
                    'business_permit_url'    => ['tag' => 'business_permit', 'label' => 'Business Permit'],
                    'valid_id_url'           => ['tag' => 'valid_id', 'label' => 'Valid ID'],
                    'secondary_valid_id_url' => ['tag' => 'supporting_valid_id', 'label' => 'Supporting Valid ID'],
                    'barangay_clearance_url' => ['tag' => 'barangay_clearance', 'label' => 'Barangay Clearance'],
                    'fire_safety_cert_url'   => ['tag' => 'fire_safety_cert', 'label' => 'Fire Safety Certificate'],
                ];
                foreach ($docCols as $col => $info) {
                    $url = (string)($g[$col] ?? '');
                    if ($url !== '' && str_contains($url, 'cloudinary.com')) {
                        $batch[] = [
                            'url'  => $url,
                            'meta' => [
                                'asset_folder' => "fittracks_permits/{$folderName}",
                                'display_name' => "Gym #{$gid} {$gymName} - {$info['label']} ({$owner['full_name']})",
                                'tags'         => "verification_doc,{$info['tag']},gym_{$gid},owner_{$ownerId}",
                                'context'      => "gym_id={$gid}|gym_name=" . self::sanitizeContextValue($gymName) . "|owner_id={$ownerId}|owner_name=" . self::sanitizeContextValue($owner['full_name']) . "|doc_type={$info['label']}",
                            ],
                        ];
                    }
                }
            }
        } catch (Throwable) {}

        try {
            $galleryIdx = [];
            foreach ($pdo->query('SELECT gym_id, image_url FROM gym_images')->fetchAll(PDO::FETCH_ASSOC) as $img) {
                $url = (string)($img['image_url'] ?? '');
                $gid = (int)($img['gym_id'] ?? 0);
                if ($url !== '' && str_contains($url, 'cloudinary.com') && isset($gyms[$gid])) {
                    $g = $gyms[$gid];
                    $galleryIdx[$gid] = ($galleryIdx[$gid] ?? 0) + 1;
                    $num = $galleryIdx[$gid];
                    $batch[] = [
                        'url'  => $url,
                        'meta' => [
                            'asset_folder' => "fittracks_gallery/{$g['folder_name']}",
                            'display_name' => "Gym #{$gid} {$g['gym_name']} - Gallery Photo #{$num} ({$g['owner_name']})",
                            'tags'         => "gym_gallery,gym_{$gid},owner_{$g['owner_id']}",
                            'context'      => "gym_id={$gid}|gym_name=" . self::sanitizeContextValue($g['gym_name']) . "|owner_id={$g['owner_id']}|owner_name=" . self::sanitizeContextValue($g['owner_name']) . "|asset_type=Gallery Photo",
                        ],
                    ];
                }
            }
        } catch (Throwable) {}

        return self::updateCloudinaryAssetMetadataBatch($batch);
    }
}
