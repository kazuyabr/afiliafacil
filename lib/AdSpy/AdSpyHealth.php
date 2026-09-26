<?php

require_once __DIR__ . '/../Settings.php';

/**
 * Saúde real dos providers de Ad Spy por usuário.
 *
 * Grava o resultado da ultima busca (ok/falha/sem resultados) e serve como
 * unica fonte de verdade para as pills de status — nunca deve mentir.
 */
class AdSpyHealth
{
    public static function record(int $userId, string $provider, string $status, string $message = ''): void
    {
        try {
            $key = self::key($userId, $provider);
            Settings::set($key, json_encode([
                'status' => $status, // ok | error | empty
                'message' => mb_substr($message, 0, 300),
                'at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }
    }

    /**
     * Limpa o estado registrado (chave nova/testada com sucesso, Steel ok...).
     * A proxima busca re-avalia do zero — erro antigo nunca sobrevive a uma correcao.
     */
    public static function clear(int $userId, string $provider): void
    {
        try {
            Settings::set(self::key($userId, $provider), '');
        } catch (Throwable $e) {
        }
    }

    /** @return array{status:string,message:string,at:string} */
    public static function get(int $userId, string $provider): array
    {
        $raw = (string)Settings::get(self::key($userId, $provider), '');
        if ($raw === '') {
            return ['status' => 'unknown', 'message' => 'Nunca buscou ainda', 'at' => ''];
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return ['status' => 'unknown', 'message' => 'Nunca buscou ainda', 'at' => ''];
        }
        return [
            'status' => (string)($d['status'] ?? 'unknown'),
            'message' => (string)($d['message'] ?? ''),
            'at' => (string)($d['at'] ?? ''),
        ];
    }

    private static function key(int $userId, string $provider): string
    {
        return 'adspy_health_' . $userId . '_' . $provider;
    }
}
