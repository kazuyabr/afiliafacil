<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Crypto.php';
require_once __DIR__ . '/../Plans.php';

/**
 * Conexoes de redes sociais por usuario. Tokens SEMPRE criptografados
 * (Crypto AES-256-GCM) e nunca expostos na UI/API (somente status/conta).
 */
class SocialConnections
{
    public static function list(int $userId): array
    {
        if (!Database::available()) return [];

        try {
            $rows = \AfiliaFacil\Models\SocialConnection::where('user_id', $userId)
                ->orderBy('id')->get();

            return $rows->map(fn ($c) => [
                'network' => $c->network,
                'status' => $c->status,
                'account_id' => $c->account_id,
                'account_name' => $c->account_name,
                'token_expires_at' => $c->token_expires_at,
                'created_at' => $c->created_at,
            ])->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function find(int $userId, string $network): ?object
    {
        if (!Database::available()) return null;

        try {
            return \AfiliaFacil\Models\SocialConnection::where('user_id', $userId)
                ->where('network', $network)->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Token descriptografado (uso interno dos publishers). */
    public static function tokenFor(object $connection): ?string
    {
        $token = Crypto::decrypt((string)$connection->access_token);
        return $token === null || $token === '' ? null : $token;
    }

    public static function metaFor(object $connection): array
    {
        $meta = json_decode((string)($connection->account_meta ?? ''), true);
        return is_array($meta) ? $meta : [];
    }

    /**
     * Cria/atualiza a conexao do usuario na rede (token criptografado).
     * data: token, refresh_token?, expires_at?, account_id, account_name, meta?
     */
    public static function upsert(int $userId, string $network, array $data): bool
    {
        if (!Database::available()) return false;
        if (!SocialNetworks::supports($network)) return false;
        if (empty($data['token'])) return false;

        try {
            $now = date('Y-m-d H:i:s');
            $conn = self::find($userId, $network) ?: new \AfiliaFacil\Models\SocialConnection([
                'user_id' => $userId,
                'network' => $network,
            ]);

            $conn->user_id = $userId;
            $conn->network = $network;
            $conn->status = 'connected';
            $conn->access_token = Crypto::encrypt((string)$data['token']);
            $conn->refresh_token = !empty($data['refresh_token'])
                ? Crypto::encrypt((string)$data['refresh_token']) : null;
            $conn->token_expires_at = $data['expires_at'] ?? null;
            $conn->account_id = mb_substr((string)($data['account_id'] ?? ''), 0, 190);
            $conn->account_name = mb_substr((string)($data['account_name'] ?? ''), 0, 190);
            $conn->account_meta = isset($data['meta']) && $data['meta'] !== []
                ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE) : null;
            $conn->created_at = $conn->created_at ?: $now;
            $conn->updated_at = $now;
            $conn->save();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function disconnect(int $userId, string $network): bool
    {
        if (!Database::available()) return false;

        try {
            return \AfiliaFacil\Models\SocialConnection::where('user_id', $userId)
                ->where('network', $network)->delete() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function count(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\SocialConnection::where('user_id', $userId)->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Pode conectar mais uma conta? (feature + quota do plano; -1 = ilimitado)
     * @return array{ok:bool, used:int, max:int, remaining:int, error?:string}
     */
    public static function checkCanConnect(int $userId, string $plan): array
    {
        if (!Plans::hasFeature($plan, 'social')) {
            return ['ok' => false, 'used' => 0, 'max' => 0, 'remaining' => 0,
                'error' => 'Seu plano não inclui publicações sociais. Faça upgrade para conectar contas.'];
        }

        $max = Plans::maxSocialConnections($plan);
        $used = self::count($userId);
        if ($max !== -1 && $used >= $max) {
            return ['ok' => false, 'used' => $used, 'max' => $max, 'remaining' => 0,
                'error' => "Limite de contas conectadas atingido ({$used}/{$max}). Remova uma conexão ou aumente o limite no plano."];
        }

        return ['ok' => true, 'used' => $used, 'max' => $max,
            'remaining' => $max === -1 ? -1 : max(0, $max - $used)];
    }
}
