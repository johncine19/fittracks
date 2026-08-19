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
     * Stores a member/staff profile picture, compressed to WebP (max 600x600).
     */
    public static function storeProfilePicture(array $file, int $userId): string
    {
        self::validate($file);

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $uploadDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 600, 600, 82)) {
            throw new RuntimeException('Could not save the profile picture.');
        }

        return $filename;
    }

    /**
     * Stores a business permit under assets/permits.
     * Keeps PDF original; compresses image permits to high-clarity WebP.
     */
    public static function storeBusinessPermit(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if ($mime === 'application/pdf') {
            $filename = 'permit_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException('Could not save the business permit.');
            }
            return $filename;
        }

        $filename = 'permit_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $uploadDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2000, 2000, 85)) {
            throw new RuntimeException('Could not save the business permit.');
        }

        return $filename;
    }

    /**
     * Stores a valid ID under assets/permits.
     * Keeps PDF original; compresses image IDs to high-clarity WebP.
     */
    public static function storeValidId(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        if ($mime === 'application/pdf') {
            $filename = 'id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException('Could not save the valid ID.');
            }
            return $filename;
        }

        $filename = 'id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $uploadDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2000, 2000, 85)) {
            throw new RuntimeException('Could not save the valid ID.');
        }

        return $filename;
    }

    /**
     * Stores a gym logo under assets/uploads, compressed to WebP (max 500x500).
     */
    public static function storeGymLogo(array $file, int $gymId): string
    {
        self::validate($file);

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'logo_' . $gymId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $uploadDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 500, 500, 85)) {
            throw new RuntimeException('Could not save the gym logo.');
        }

        return $filename;
    }

    /**
     * Stores a gym gallery photo under assets/uploads, compressed to WebP (max 1600x1200).
     */
    public static function storeGymGalleryImage(array $file, int $gymId): string
    {
        self::validate($file);

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'gym_' . $gymId . '_gallery_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $uploadDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 1600, 1200, 80)) {
            throw new RuntimeException('Could not save the gallery image.');
        }

        return $filename;
    }

    /**
     * Deletes a gym gallery image from the filesystem.
     */
    public static function deleteGymGalleryImage(string $filename): void
    {
        $filePath = __DIR__ . '/../assets/uploads/' . basename($filename);
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }
}
