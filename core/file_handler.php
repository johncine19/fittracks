<?php
declare(strict_types=1);

/**
 * Secure handling of user-uploaded files (currently: profile pictures).
 *
 * Unlike a plain extension check, this validates the real MIME type via
 * fileinfo (so a renamed .php file can't masquerade as a .jpg), enforces a
 * size limit, and writes a random filename so uploads can't collide or be
 * used to overwrite another user's file.
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
        'application/pdf' => 'pdf',
    ];

    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB

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
            throw new RuntimeException('File is too large (max 5MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime]) && !isset(self::ALLOWED_MIME_PERMIT[$mime])) {
            throw new RuntimeException('Unsupported file type.');
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
            throw new RuntimeException('File is too large (max 5MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME_PERMIT[$mime])) {
            throw new RuntimeException('Unsupported file type. Please upload a JPG, PNG, or PDF.');
        }
    }

    /**
     * Validates and stores the file under assets/uploads, returning the
     * generated filename to store in the database.
     *
     * @param array $file one entry of $_FILES
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function storeProfilePicture(array $file, int $userId): string
    {
        self::validate($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::ALLOWED_MIME[$mime];

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        return $filename;
    }

    /**
     * Validates and stores a business permit under assets/permits.
     *
     * @param array $file one entry of $_FILES
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function storeBusinessPermit(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::ALLOWED_MIME_PERMIT[$mime];

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'permit_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the business permit.');
        }

        return $filename;
    }

    /**
     * Validates and stores a valid ID under assets/permits.
     *
     * @param array $file one entry of $_FILES
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function storeValidId(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::ALLOWED_MIME_PERMIT[$mime];

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the valid ID.');
        }

        return $filename;
    }

    /**
     * Validates and stores a gym logo under assets/uploads.
     *
     * @param array $file one entry of $_FILES
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function storeGymLogo(array $file, int $gymId): string
    {
        self::validate($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::ALLOWED_MIME[$mime];

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'logo_' . $gymId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the gym logo.');
        }

        return $filename;
    }

    /**
     * Validates and stores a gym gallery image under assets/uploads, returning the
     * generated filename to store in the database.
     *
     * @param array $file one entry of $_FILES
     * @throws RuntimeException on invalid/oversized/unreadable file
     */
    public static function storeGymGalleryImage(array $file, int $gymId): string
    {
        self::validate($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::ALLOWED_MIME[$mime];

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = 'gym_' . $gymId . '_gallery_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new RuntimeException('Could not save the gallery image.');
        }

        return $filename;
    }

    /**
     * Deletes a gym gallery image from the filesystem.
     *
     * @param string $filename the filename to delete
     */
    public static function deleteGymGalleryImage(string $filename): void
    {
        $filePath = __DIR__ . '/../assets/uploads/' . basename($filename);
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }
}
