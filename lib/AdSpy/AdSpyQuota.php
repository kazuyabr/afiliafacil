<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/AiConfig.php';

class AdSpyQuota
{
    public const KIND_SEARCH = 'search';
    public const KIND_ANALYSIS = 'analysis';

    public const BYOK_MESSAGE = 'Sua cota de análises IA do mês acabou (%d/%d). Você pode fazer upgrade ou configurar sua própria chave (BYOK) em /admin/ai-settings.php para continuar sem limite.';

    public static function source(int $userId, string $kind): string
    {
        if ($kind !== self::KIND_ANALYSIS) return 'platform';

        try {
            return (AiConfig::forUser($userId)['source'] ?? 'platform') === 'byok' ? 'byok' : 'platform';
        } catch (Throwable $e) {
            return 'platform';
        }
    }

    public static function used(int $userId, string $kind): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\AdSpySearch::where('user_id', $userId)
                ->where('kind', $kind)
                ->where('from_cache', false)
                ->where('created_at', '>=', $start)
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function limit(string $plan, string $kind): int
    {
        return $kind === self::KIND_ANALYSIS
            ? Plans::maxAiAnalyses($plan)
            : Plans::maxAdSpySearches($plan);
    }

    public static function check(int $userId, string $plan, string $kind): array
    {
        $source = self::source($userId, $kind);
        $used = self::used($userId, $kind);

        if ($source === 'byok') {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1, 'source' => 'byok'];
        }

        $limit = self::limit($plan, $kind);

        if ($limit === -1) {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1, 'source' => 'platform'];
        }

        return [
            'allowed' => $used < $limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'source' => 'platform',
        ];
    }

    public static function consume(int $userId, string $kind, string $query, string $provider, int $resultsCount = 0, bool $fromCache = false): void
    {
        if (!Database::available()) return;

        try {
            \AfiliaFacil\Models\AdSpySearch::create([
                'user_id' => $userId,
                'kind' => $kind,
                'query' => mb_substr($query, 0, 250),
                'provider' => $provider,
                'results_count' => $resultsCount,
                'from_cache' => $fromCache,
                'source' => self::source($userId, $kind),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }
}
