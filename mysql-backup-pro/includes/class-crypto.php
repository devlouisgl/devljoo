<?php
/**
 * Encriptación / desencriptación de credenciales
 */
namespace MBP;

class Crypto
{
    private static function key(): string
    {
        $key = get_option('mbp_crypto_key');
        if (!$key) {
            $key = wp_generate_password(32, true, true);
            update_option('mbp_crypto_key', $key);
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return $encoded; // valor en texto plano legado
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        return ($decrypted !== false) ? $decrypted : $encoded;
    }
}
