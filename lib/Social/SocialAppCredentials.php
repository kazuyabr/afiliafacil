<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';

/**
 * Credenciais de app OAuth do proprio usuario (BYOK de app).
 * O usuario ja precisa criar o app na plataforma para gerar token — ao colar
 * App ID + Secret aqui, o OAuth oficial da plataforma passa a funcionar com o
 * APP DELE (sem App Review: app em modo dev + papel do usuario), com
 * refresh/long-lived automatico. Secret criptografado (AES-256-GCM) e nunca
 * retornado pela API.
 */
class SocialAppCredentials
{
    private const PROVIDERS = ['meta', 'threads', 'x', 'tiktok'];

    /** @return array{app_id:string, secret:string}|null */
    public static function get(int $userId, string $provider): ?array
    {
        if (!Database::available()) return null;
        if ($userId <= 0 || !in_array($provider, self::PROVIDERS, true)) return null;

        try {
            $row = \AfiliaFacil\Models\SocialAppCredential::where('user_id', $userId)
                ->where('provider', $provider)->first();
            if (!$row) return null;
            $secret = Crypto::decrypt((string)$row->app_secret);
            if ($secret === null || $secret === '') return null;
            return ['app_id' => (string)$row->app_id, 'secret' => $secret];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function save(int $userId, string $provider, string $appId, string $appSecret): bool
    {
        if (!Database::available()) return false;
        if ($userId <= 0 || !in_array($provider, self::PROVIDERS, true)) return false;
        $appId = trim($appId);
        $appSecret = trim($appSecret);
        if ($appId === '' || $appSecret === '') return false;

        try {
            $now = date('Y-m-d H:i:s');
            $row = \AfiliaFacil\Models\SocialAppCredential::where('user_id', $userId)
                ->where('provider', $provider)->first()
                ?: new \AfiliaFacil\Models\SocialAppCredential([
                    'user_id' => $userId,
                    'provider' => $provider,
                ]);
            $row->user_id = $userId;
            $row->provider = $provider;
            $row->app_id = mb_substr($appId, 0, 190);
            $row->app_secret = Crypto::encrypt($appSecret);
            $row->created_at = $row->created_at ?: $now;
            $row->updated_at = $now;
            $row->save();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function delete(int $userId, string $provider): bool
    {
        if (!Database::available()) return false;
        if ($userId <= 0 || !in_array($provider, self::PROVIDERS, true)) return false;

        try {
            return \AfiliaFacil\Models\SocialAppCredential::where('user_id', $userId)
                ->where('provider', $provider)->delete() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** true = o usuario tem credenciais de app salvas nesse provider. */
    public static function has(int $userId, string $provider): bool
    {
        return self::get($userId, $provider) !== null;
    }
}
