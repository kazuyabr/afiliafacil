<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';

class AdSpyKeys
{
    public const CAP_SERPAPI = 'adspy_serpapi';
    public const CAP_META = 'adspy_meta';
    public const CAP_APIFY = 'adspy_apify';

    public static function serpapi(int $userId): string
    {
        return self::key($userId, self::CAP_SERPAPI);
    }

    public static function meta(int $userId): string
    {
        return self::key($userId, self::CAP_META);
    }

    public static function apify(int $userId): string
    {
        return self::key($userId, self::CAP_APIFY);
    }

    public static function has(int $userId, string $provider): bool
    {
        return match ($provider) {
            'serpapi' => self::serpapi($userId) !== '',
            'meta' => self::meta($userId) !== '',
            'apify' => self::apify($userId) !== '',
            default => false,
        };
    }

    public static function hasAny(int $userId): bool
    {
        return self::has($userId, 'serpapi') || self::has($userId, 'meta') || self::has($userId, 'apify');
    }

    public static function isPlatformConfigured(): bool
    {
        return trim((string)(getenv('SERPAPI_KEY') ?: '')) !== ''
            || trim((string)(getenv('META_AD_ACCESS_TOKEN') ?: '')) !== ''
            || trim((string)(getenv('APIFY_TOKEN') ?: '')) !== '';
    }

    private static function key(int $userId, string $capability): string
    {
        if ($userId <= 0) return '';
        if (!Database::available()) return '';

        try {
            $config = \AfiliaFacil\Models\UserAiConfig::where('user_id', $userId)
                ->where('capability', $capability)
                ->where('enabled', true)
                ->first();
            if (!$config) return '';

            return Crypto::decrypt($config->api_key_encrypted ?? '') ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }
}
