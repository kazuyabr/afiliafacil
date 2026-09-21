<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';

class AiConfig
{
    public static function forUser(int $userId): array
    {
        if (Database::available()) {
            try {
                $config = \AfiliaFacil\Models\UserAiConfig::where('user_id', $userId)
                    ->where('capability', 'chat')
                    ->where('enabled', true)
                    ->first();
                if ($config) {
                    $key = Crypto::decrypt($config->api_key_encrypted ?? '') ?? '';
                    if ($key !== '') {
                        return [
                            'provider' => $config->provider,
                            'model' => $config->model,
                            'base_url' => $config->base_url,
                            'api_key' => $key,
                            'account_id' => '',
                            'source' => 'byok',
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
        }

        return [
            'provider' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'api_key' => getenv('CF_AI_TOKEN') ?: '',
            'account_id' => getenv('CF_ACCOUNT_ID') ?: '',
            'source' => 'platform',
        ];
    }

    public static function isPlatformConfigured(): bool
    {
        return (getenv('CF_AI_TOKEN') ?: '') !== '' && (getenv('CF_ACCOUNT_ID') ?: '') !== '';
    }

    public static function isAvailable(int $userId): bool
    {
        $config = self::forUser($userId);
        return ($config['api_key'] ?? '') !== '';
    }
}
