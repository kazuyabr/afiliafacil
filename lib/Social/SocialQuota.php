<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

/**
 * Quota de publicacoes sociais por mes (transparencia: a UI sempre mostra
 * usado/restante; excedente bloqueia com mensagem clara).
 */
class SocialQuota
{
    public static function countMonth(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\SocialPost::where('user_id', $userId)
                ->where('created_at', '>=', date('Y-m-01 00:00:00'))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{ok:bool, used:int, max:int, remaining:int, source:string, error?:string}
     */
    public static function check(int $userId, string $plan): array
    {
        if (!Plans::hasFeature($plan, 'social')) {
            return ['ok' => false, 'used' => 0, 'max' => 0, 'remaining' => 0, 'source' => 'plan',
                'error' => 'Seu plano não inclui publicações sociais. Faça upgrade para publicar.'];
        }

        $max = Plans::maxPostsMonth($plan);
        $used = self::countMonth($userId);
        if ($max !== -1 && $used >= $max) {
            return ['ok' => false, 'used' => $used, 'max' => $max, 'remaining' => 0, 'source' => 'plan',
                'error' => "Cota de publicações do mês atingida ({$used}/{$max}). Ela renova no próximo mês ou você pode aumentar o limite no plano."];
        }

        return ['ok' => true, 'used' => $used, 'max' => $max,
            'remaining' => $max === -1 ? -1 : max(0, $max - $used), 'source' => 'plan'];
    }
}
