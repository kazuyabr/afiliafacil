<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';

/**
 * Contexto de login do TikTok (cookies capturados via Steel Sessions).
 *
 * Guardado criptografado (AES-256-GCM) em user_ai_configs, capability
 * adspy_tiktok_cc — sao CREDENCIAIS: nunca exibir nem logar o conteudo.
 * O login acontece no viewer ao vivo do Steel; aqui so persistimos o resultado.
 */
class TikTokSession
{
    public const CAPABILITY = 'adspy_tiktok_cc';

    /** @return array{connected:bool, connected_at:?string, cookie_count:int} */
    public static function status(int $userId): array
    {
        $data = self::load($userId);
        if ($data === null) {
            return ['connected' => false, 'connected_at' => null, 'cookie_count' => 0];
        }
        return [
            'connected' => true,
            'connected_at' => $data['saved_at'] ?? null,
            'cookie_count' => count($data['cookies'] ?? []),
        ];
    }

    /** Salva o contexto criptografado. Retorna false se nao ha cookies. */
    public static function save(int $userId, array $context): bool
    {
        if ($userId <= 0 || !Database::available()) return false;

        $cookies = [];
        foreach ((array)($context['cookies'] ?? []) as $c) {
            if (!is_array($c) || !isset($c['name'], $c['value'])) continue;
            if ((string)$c['value'] === '') continue;
            // So o que os atores consomem (formato Cookie-Editor)
            $cookies[] = [
                'name' => (string)$c['name'],
                'value' => (string)$c['value'],
                'domain' => (string)($c['domain'] ?? ''),
            ];
        }
        if (empty($cookies)) return false;

        $blob = json_encode(['cookies' => $cookies, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE);
        $enc = Crypto::encrypt($blob);
        if ($enc === '') return false;

        try {
            \AfiliaFacil\Models\UserAiConfig::updateOrCreate(
                ['user_id' => $userId, 'capability' => self::CAPABILITY],
                [
                    'provider' => 'tiktok',
                    'model' => 'cc_session',
                    'base_url' => '',
                    'api_key_encrypted' => $enc,
                    'enabled' => true,
                ]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Cookies no formato {name,value,domain} para os atores Apify. */
    public static function cookies(int $userId): array
    {
        $data = self::load($userId);
        return $data['cookies'] ?? [];
    }

    public static function delete(int $userId): void
    {
        if ($userId <= 0 || !Database::available()) return;
        try {
            \AfiliaFacil\Models\UserAiConfig::where('user_id', $userId)
                ->where('capability', self::CAPABILITY)
                ->delete();
        } catch (Throwable $e) {
        }
    }

    private static function load(int $userId): ?array
    {
        if ($userId <= 0 || !Database::available()) return null;
        try {
            $config = \AfiliaFacil\Models\UserAiConfig::where('user_id', $userId)
                ->where('capability', self::CAPABILITY)
                ->where('enabled', true)
                ->first();
            if (!$config || empty($config->api_key_encrypted)) return null;

            $json = Crypto::decrypt($config->api_key_encrypted);
            $data = $json !== null ? json_decode($json, true) : null;
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
