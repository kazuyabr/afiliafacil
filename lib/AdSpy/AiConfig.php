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
                            'api_type' => (string)($config->api_type ?? ''),
                            'model' => $config->model,
                            'base_url' => $config->base_url,
                            'local_ttl' => (int)($config->local_ttl ?? 0),
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
     *  - BYOK do usuario (se houver) → chave DEDICADA do segmento (admin/trial, se configurada)
     *    → plataforma → chave alternativa da plataforma.
     * Chaves dedicadas isolam o consumo: o admin (premium) nao depende da cota compartilhada
     * dos clientes e o trial pode ter cota propria.
     */
    public static function candidates(int $userId): array
    {
        $list = [];
        $primary = self::forUser($userId);
        $isByok = ($primary['source'] ?? '') === 'byok';
        // BYOK local pode ter chave vazia (LM Studio/Ollama)
        $primaryIsLocal = $isByok && AiClient::isLocalUrl((string)($primary['base_url'] ?? ''));
        $segment = self::segmentKey($userId);

        if ($isByok) {
            if (($primary['api_key'] ?? '') !== '' || $primaryIsLocal) $list[] = $primary;
            // Chave dedicada do segmento (admin/trial) — antes da chave compartilhada
            if ($segment !== null) $list[] = $segment;
            $platform = self::platform();
            if (($platform['api_key'] ?? '') !== '') $list[] = $platform;
        } else {
            // Sem BYOK: a chave dedicada do segmento tem prioridade sobre a compartilhada
            if ($segment !== null) $list[] = $segment;
            if (($primary['api_key'] ?? '') !== '') $list[] = $primary;
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

    /**
     * Chave dedicada do segmento do usuario (opcional):
     *  - premium (admin): CF_AI_TOKEN_ADMIN / CF_ACCOUNT_ID_ADMIN
     *  - trial: CF_AI_TOKEN_TRIAL / CF_ACCOUNT_ID_TRIAL
     */
    private static function segmentKey(int $userId): ?array
    {
        if ($userId <= 0 || !Database::available()) return null;

        try {
            $plan = (string)(\AfiliaFacil\Models\User::find($userId)->plan ?? '');
        } catch (Throwable $e) {
            return null;
        }

        $map = [
            'premium' => ['CF_AI_TOKEN_ADMIN', 'CF_ACCOUNT_ID_ADMIN', 'platform_admin'],
            'trial' => ['CF_AI_TOKEN_TRIAL', 'CF_ACCOUNT_ID_TRIAL', 'platform_trial'],
        ];
        if (!isset($map[$plan])) return null;

        [$tokenEnv, $accountEnv, $source] = $map[$plan];
        $token = trim((string)(getenv($tokenEnv) ?: ''));
        if ($token === '') return null;

        return [
            'provider' => 'cloudflare',
            'api_type' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'local_ttl' => 0,
            'api_key' => $token,
            'account_id' => trim((string)(getenv($accountEnv) ?: getenv('CF_ACCOUNT_ID') ?: '')),
            'source' => $source,
        ];
    }

    /** Chave principal da plataforma (Cloudflare Workers AI). */
    public static function platform(): array
    {
        return [
            'provider' => 'cloudflare',
            'api_type' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'local_ttl' => 0,
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
            'api_type' => 'cloudflare',
            'model' => getenv('CF_AI_MODEL') ?: '@cf/nvidia/nemotron-3-120b-a12b',
            'base_url' => '',
            'local_ttl' => 0,
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
