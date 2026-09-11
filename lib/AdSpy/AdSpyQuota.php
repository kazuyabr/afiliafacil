<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

class AdSpyQuota
{
    public const KIND_SEARCH = 'search';
    public const KIND_ANALYSIS = 'analysis';

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
        $limit = self::limit($plan, $kind);
        $used = self::used($userId, $kind);

        if ($limit === -1) {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1];
        }

        return [
            'allowed' => $used < $limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
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
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }
}
