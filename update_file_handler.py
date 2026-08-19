import os, sys, re

filename = 'core/file_handler.php'
with open(filename, 'r') as f:
    content = f.read()

# 1. Insert uploadToCloudinary method before storeProfilePicture
cloudinary_method = """
    /**
     * Uploads a file to Cloudinary via REST API.
     * Returns the secure_url if successful, or false on failure.
     */
    private static function uploadToCloudinary(string $filePath, string $mimeType, string $folder): string|false
    {
        $cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '';
        $apiKey = $_ENV['CLOUDINARY_API_KEY'] ?? '';
        $apiSecret = $_ENV['CLOUDINARY_API_SECRET'] ?? '';

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return false;
        }

        $timestamp = time();
        $signatureStr = "folder={$folder}&timestamp={$timestamp}{$apiSecret}";
        $signature = sha1($signatureStr);

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload");
        
        $cFile = new CURLFile($filePath, $mimeType, basename($filePath));
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => $cFile,
            'api_key' => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder' => $folder
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $data = json_decode($response, true);
            return $data['secure_url'] ?? false;
        }

        return false;
    }

"""

content = content.replace('    public static function storeProfilePicture', cloudinary_method + '    public static function storeProfilePicture')

# Rewrite storeProfilePicture
new_storeProfilePicture = """    public static function storeProfilePicture(array $file, int $userId): string
    {
        self::validate($file);

        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 600, 600, 82)) {
            throw new RuntimeException('Could not process the profile picture.');
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', 'fittracks_profiles');
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }"""

content = re.sub(r'    public static function storeProfilePicture\(array \$file, int \$userId\): string\s+\{.*?(?=    /\*\*|    public static function storeBusinessPermit)', new_storeProfilePicture + '\n\n', content, flags=re.DOTALL)


# Rewrite storeBusinessPermit
new_storeBusinessPermit = """    public static function storeBusinessPermit(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime === 'application/pdf') {
            $cloudinaryUrl = self::uploadToCloudinary($file['tmp_name'], 'application/pdf', 'fittracks_permits');
            if ($cloudinaryUrl) return $cloudinaryUrl;

            $uploadDir = __DIR__ . '/../assets/permits/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
            $filename = 'permit_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) throw new RuntimeException('Could not save the business permit.');
            return $filename;
        }

        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'permit_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2000, 2000, 85)) {
            throw new RuntimeException('Could not process the business permit.');
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', 'fittracks_permits');
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }"""

content = re.sub(r'    public static function storeBusinessPermit\(array \$file, int \$userId\): string\s+\{.*?(?=    /\*\*|    public static function storeValidId)', new_storeBusinessPermit + '\n\n', content, flags=re.DOTALL)

# Rewrite storeValidId
new_storeValidId = """    public static function storeValidId(array $file, int $userId): string
    {
        self::validatePermit($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mime === 'application/pdf') {
            $cloudinaryUrl = self::uploadToCloudinary($file['tmp_name'], 'application/pdf', 'fittracks_permits');
            if ($cloudinaryUrl) return $cloudinaryUrl;

            $uploadDir = __DIR__ . '/../assets/permits/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
            $filename = 'id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) throw new RuntimeException('Could not save the valid ID.');
            return $filename;
        }

        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 2000, 2000, 85)) {
            throw new RuntimeException('Could not process the valid ID.');
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', 'fittracks_permits');
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/permits/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }"""

content = re.sub(r'    public static function storeValidId\(array \$file, int \$userId\): string\s+\{.*?(?=    /\*\*|    public static function storeGymLogo)', new_storeValidId + '\n\n', content, flags=re.DOTALL)


# Rewrite storeGymLogo
new_storeGymLogo = """    public static function storeGymLogo(array $file, int $gymId): string
    {
        self::validate($file);

        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'gym_logo_' . $gymId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 500, 500, 85)) {
            throw new RuntimeException('Could not process the gym logo.');
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', 'fittracks_gyms');
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }"""

content = re.sub(r'    public static function storeGymLogo\(array \$file, int \$gymId\): string\s+\{.*?(?=    /\*\*|    public static function storeGymImage)', new_storeGymLogo + '\n\n', content, flags=re.DOTALL)


# Rewrite storeGymImage
new_storeGymImage = """    public static function storeGymImage(array $file, int $gymId): string
    {
        self::validate($file);

        $tempDir = sys_get_temp_dir() . '/';
        $filename = 'gym_img_' . $gymId . '_' . bin2hex(random_bytes(8)) . '.webp';
        $destPath = $tempDir . $filename;

        if (!self::processAndStoreImage($file['tmp_name'], $destPath, 1920, 1080, 82)) {
            throw new RuntimeException('Could not process the gym image.');
        }

        $cloudinaryUrl = self::uploadToCloudinary($destPath, 'image/webp', 'fittracks_gyms');
        if ($cloudinaryUrl) {
            unlink($destPath);
            return $cloudinaryUrl;
        }

        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        rename($destPath, $uploadDir . $filename);
        return $filename;
    }"""

content = re.sub(r'    public static function storeGymImage\(array \$file, int \$gymId\): string\s+\{.*?(?=\n\})', new_storeGymImage + '\n', content, flags=re.DOTALL)

with open(filename, 'w') as f:
    f.write(content)

print('Updated file_handler.php successfully')
