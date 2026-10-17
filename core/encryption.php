<?php
declare(strict_types=1);

/**
 * Message Field Encryption Service (AES-256-GCM At-Rest Encryption)
 * 
 * Provides authenticated field-level encryption for sensitive member, trainer,
 * and gym owner direct messages. Protects communication against database breaches
 * and unauthorized data dumps while maintaining seamless multi-device web access.
 */

const MSG_ENCRYPTION_CIPHER = 'aes-256-gcm';
const MSG_ENCRYPTION_PREFIX = 'enc:v1:';
const MSG_IV_LENGTH = 12; // 96 bits recommended for AES-GCM
const MSG_TAG_LENGTH = 16; // 128 bits authentication tag

/**
 * Derives a 32-byte binary key from the environment configuration.
 */
function get_message_encryption_key(): string
{
    static $derivedKey = null;
    if ($derivedKey !== null) {
        return $derivedKey;
    }

    $rawKey = (string) (
        $_ENV['MESSAGE_ENCRYPTION_KEY'] 
        ?? getenv('MESSAGE_ENCRYPTION_KEY') 
        ?? $_ENV['APP_KEY'] 
        ?? getenv('APP_KEY') 
        ?? ''
    );

    if ($rawKey === '') {
        // Fallback salt derived from DB credentials to prevent plaintext storage even if .env variable was omitted
        $rawKey = 'fittracks_secure_msg_key_' . (defined('DB_NAME') ? DB_NAME : 'fittrack_db');
    }

    $derivedKey = hash('sha256', $rawKey, true);
    return $derivedKey;
}

/**
 * Encrypts plaintext message into authenticated ciphertext with version prefix.
 */
function encrypt_message(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    // Do not double-encrypt if already encrypted
    if (is_encrypted_message($plaintext)) {
        return $plaintext;
    }

    $key = get_message_encryption_key();
    $iv = random_bytes(MSG_IV_LENGTH);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        MSG_ENCRYPTION_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '', // aad (additional authenticated data)
        MSG_TAG_LENGTH
    );

    if ($ciphertext === false) {
        error_log('[Encryption] Failed to encrypt message.');
        return $plaintext;
    }

    // Binary payload: IV (12 bytes) + TAG (16 bytes) + CIPHERTEXT
    return MSG_ENCRYPTION_PREFIX . base64_encode($iv . $tag . $ciphertext);
}

/**
 * Checks if a string has the FitTracks encryption envelope prefix.
 */
function is_encrypted_message(?string $text): bool
{
    if ($text === null || $text === '') {
        return false;
    }
    return str_starts_with($text, MSG_ENCRYPTION_PREFIX);
}

/**
 * Decrypts an authenticated encrypted message back to plaintext.
 * Gracefully returns original text if legacy plaintext (ensuring 100% backward compatibility).
 */
function decrypt_message(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }

    // Legacy unencrypted plaintext fallback
    if (!is_encrypted_message($text)) {
        return $text;
    }

    $b64 = substr($text, strlen(MSG_ENCRYPTION_PREFIX));
    $raw = base64_decode($b64, true);

    if ($raw === false || strlen($raw) < (MSG_IV_LENGTH + MSG_TAG_LENGTH)) {
        return '[Encrypted Message]';
    }

    $iv = substr($raw, 0, MSG_IV_LENGTH);
    $tag = substr($raw, MSG_IV_LENGTH, MSG_TAG_LENGTH);
    $ciphertext = substr($raw, MSG_IV_LENGTH + MSG_TAG_LENGTH);

    $key = get_message_encryption_key();
    $decrypted = openssl_decrypt(
        $ciphertext,
        MSG_ENCRYPTION_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($decrypted === false) {
        error_log('[Encryption] Failed to decrypt message: tag mismatch or invalid key.');
        return '[Encrypted Message]';
    }

    return $decrypted;
}
