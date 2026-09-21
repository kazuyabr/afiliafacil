<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';
require_once __DIR__ . '/AiClient.php';

class AiConfig
{
    /**
     * Config do usuario: BYOK (se configurado e ativo) ou a chave da plataforma.
     */
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
                    // Modelos locais (LM Studio/Ollama) podem funcionar sem chave
                    $isLocal = AiClient::isLocalUrl((string)($config->base_url ?? ''));
                    if ($key !== '' || $isLocal) {
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

        return self::platform();
    }

    /**
     * Lista de configs em ordem de prioridade (com fallback automatico de cota):
     *  - BYOK do usuario (se houver) → plataforma → chave alternativa da plataforma
     *  - sem BYOK → plataforma → chave alternativa da plataforma
     * Assim, quando uma cota/limite estoura, o sistema tenta a proxima automaticamente.
     */
    public static function candidates(int $userId): array
    {
        $list = [];
        $primary = self::forUser($userId);
        // BYOK local pode ter chave vazia (LM Studio/Ollama)
        $primaryIsLocal = ($primary['source'] ?? '') === 'byok' && AiClient::isLocalUrl((string)($primary['base_url'] ?? ''));
        if (($primary['api_key'] ?? '') !== '' || $primaryIsLocal) $list[] = $primary;

        if (($primary['source'] ?? '') === 'byok') {
            $platform = self::platform();
            if (($platform['api_key'] ?? '') !== '') $list[] = $platform;
        }

        $alt = self::platformAlt();
        if (($alt['api_key'] ?? '') !== '') $list[] = $alt;

        // Remove duplicados (mesma chave/conta)
        $seen = [];
        $unique = [];
        foreach ($list as $config) {
            $id = ($config['provider'] ?? '') . '|' . ($config['account_id'] ?? '') . '|' . substr(md5($config['api_key'] ?? ''), 0, 8);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $unique[] = $config;
        }

        return $unique;
    }

    /** Chave principal da plataforma (Cloudflare Workers AI). */
    public static function platform(): array
    {
        return [
            'provider' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'api_key' => getenv('CF_AI_TOKEN') ?: '',
            'account_id' => getenv('CF_ACCOUNT_ID') ?: '',
            'source' => 'platform',
        ];
    }

    /**
     * Chave alternativa da plataforma (opcional) — usada quando a principal estoura a cota.
     * Configure CF_AI_TOKEN_2/CF_ACCOUNT_ID_2 (ex.: segunda conta Cloudflare) para somar cotas.
     */
    public static function platformAlt(): array
    {
        return [
            'provider' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'api_key' => getenv('CF_AI_TOKEN_2') ?: '',
            'account_id' => getenv('CF_ACCOUNT_ID_2') ?: '',
            'source' => 'platform_alt',
        ];
    }

    public static function isPlatformConfigured(): bool
    {
        return (getenv('CF_AI_TOKEN') ?: '') !== '' && (getenv('CF_ACCOUNT_ID') ?: '') !== '';
    }

    public static function isAvailable(int $userId): bool
    {
        return !empty(self::candidates($userId));
    }
}
