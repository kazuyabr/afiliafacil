<?php

require_once __DIR__ . '/Settings.php';

class Crypto
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) return self::$key;

        $raw = getenv('APP_KEY') ?: '';
        if ($raw === '') {
            $raw = (string)Settings::get('app_key', '');
        }
        if ($raw === '') {
            $raw = bin2hex(random_bytes(32));
            Settings::set('app_key', $raw);
        }

        self::$key = hash('sha256', $raw, true);
        return self::$key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return '';
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) return null;

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
