<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/SocialNetworks.php';
require_once __DIR__ . '/SocialHttp.php';
require_once __DIR__ . '/SocialConnections.php';
require_once __DIR__ . '/SocialOAuth.php';

/**
 * Metricas por post/rede (dashboard unificado — Fase 2).
 * Coleta insights proprios do dono da conta via API oficial de cada rede
 * (sem App Review), com falha graciosa: rede que nao responder nao afeta as
 * demais nem apaga metricas ja coletadas.
 */
class SocialMetrics
{
    /** Recoleta apenas se a ultima coleta for mais antiga que isto (1h). */
    public const STALE_SECONDS = 3600;

    /**
     * Metricas do usuario indexadas por post_id => network => metricas.
     * @return array<int, array<string, array>>
     */
    public static function forUser(int $userId): array
    {
        if (!Database::available()) return [];

        try {
            $postIds = \AfiliaFacil\Models\SocialPost::where('user_id', $userId)
                ->orderByDesc('id')->limit(50)->pluck('id');
            if (count($postIds) === 0) return [];

            $rows = \AfiliaFacil\Models\SocialPostMetric::whereIn('post_id', $postIds)->get();
            $out = [];
            foreach ($rows as $m) {
                $out[(int)$m->post_id][(string)$m['network']] = [
                    'likes' => (int)$m->likes,
                    'comments' => (int)$m->comments,
                    'shares' => (int)$m->shares,
                    'replies' => (int)$m->replies,
                    'impressions' => (int)$m->impressions,
                    'reach' => (int)$m->reach,
                    'views' => (int)$m->views,
                    'collected_at' => $m->collected_at,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Coleta metricas dos targets publicados do usuario.
     * @return array{checked:int, updated:int, failed:int}
     */
    public static function collect(int $userId, bool $force = false, int $limit = 10): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'failed' => 0];
        if (!Database::available()) return $summary;

        try {
            $postIds = \AfiliaFacil\Models\SocialPost::where('user_id', $userId)
                ->whereIn('status', ['published', 'partial'])
                ->orderByDesc('id')->limit(max(1, min(50, $limit)))->pluck('id')->all();
            if (count($postIds) === 0) return $summary;

            $summary = self::collectTargets($postIds, $force);
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }
        return $summary;
    }

    /** Coleta de um post especifico (botao "Atualizar" da tela). */
    public static function collectPost(int $postId, bool $force = true): array
    {
        if (!Database::available()) return ['checked' => 0, 'updated' => 0, 'failed' => 0];
        try {
            return self::collectTargets([$postId], $force);
        } catch (Throwable $e) {
            return ['checked' => 0, 'updated' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }

    /** Cron: posts publicados nas ultimas 7 dias com coleta vencida. */
    public static function collectDue(int $limit = 20): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'failed' => 0];
        if (!Database::available()) return $summary;

        try {
            $postIds = \AfiliaFacil\Models\SocialPost::whereIn('status', ['published', 'partial'])
                ->where('published_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))
                ->orderByDesc('published_at')->limit(max(1, min(50, $limit)))->pluck('id')->all();
            if (count($postIds) === 0) return $summary;

            $summary = self::collectTargets($postIds, false);
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }
        return $summary;
    }

    private static function collectTargets(array $postIds, bool $force): array
    {
        $summary = ['checked' => 0, 'updated' => 0, 'failed' => 0];

        $targets = \AfiliaFacil\Models\SocialPostTarget::whereIn('post_id', $postIds)
            ->where('status', 'published')
            ->where('remote_id', '!=', '')
            ->get();

        foreach ($targets as $t) {
            $metric = \AfiliaFacil\Models\SocialPostMetric::where('post_id', $t->post_id)
                ->where('network', $t->network)->first();

            if (!$force && $metric && $metric->collected_at !== null
                && strtotime((string)$metric->collected_at) > time() - self::STALE_SECONDS) {
                continue; // recente demais
            }

            $summary['checked']++;
            $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id);
            if (!$conn || $conn->status !== 'connected' || !SocialOAuth::refreshIfExpired($conn)) {
                $summary['failed']++;
                continue;
            }
            $conn = \AfiliaFacil\Models\SocialConnection::find($t->connection_id) ?: $conn;

            $data = self::fetch($conn, (string)$t->remote_id);
            if ($data === null) {
                $summary['failed']++;
                continue;
            }

            $now = date('Y-m-d H:i:s');
            if (!$metric) {
                $metric = new \AfiliaFacil\Models\SocialPostMetric([
                    'post_id' => $t->post_id, 'network' => $t->network,
                    'created_at' => $now,
                ]);
            }
            $metric->connection_id = $t->connection_id;
            foreach (['likes', 'comments', 'shares', 'replies', 'impressions', 'reach', 'views'] as $k) {
                $metric->$k = (int)($data[$k] ?? 0);
            }
            $metric->collected_at = $now;
            $metric->updated_at = $now;
            if ($metric->created_at === null) $metric->created_at = $now;
            try {
                $metric->save();
                $summary['updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /** @return array|null mapa de metricas ou null em falha (nao apaga o que existe) */
    private static function fetch(object $conn, string $remoteId): ?array
    {
        $token = SocialConnections::tokenFor($conn);
        if ($token === null || $token === '') return null;
        $meta = SocialConnections::metaFor($conn);

        try {
            return match ((string)$conn->network) {
                'facebook' => self::facebook($token, $remoteId),
                'instagram' => self::instagram($token, $remoteId),
                'threads' => self::threads($token, $remoteId),
                'x' => self::x($token, $remoteId),
                'tiktok' => self::tiktok($token, $remoteId),
                default => null,
            };
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function facebook(string $token, string $postId): ?array
    {
        $r = SocialHttp::json('GET', "https://graph.facebook.com/v21.0/{$postId}", [
            'form' => [
                'fields' => 'likes.summary(true),comments.summary(true),shares,insights.metric(post_impressions,post_engaged_users)',
                'access_token' => $token,
            ],
        ]);
        if (empty($r['id']) && !isset($r['likes'])) return null;

        $impressions = 0;
        foreach (($r['insights']['data'] ?? []) as $ins) {
            if (($ins['name'] ?? '') === 'post_impressions') {
                $impressions = (int)($ins['values'][0]['value'] ?? 0);
            }
        }
        return [
            'likes' => (int)($r['likes']['summary']['total_count'] ?? 0),
            'comments' => (int)($r['comments']['summary']['total_count'] ?? 0),
            'shares' => (int)($r['shares']['count'] ?? 0),
            'impressions' => $impressions,
        ];
    }

    private static function instagram(string $token, string $mediaId): ?array
    {
        $r = SocialHttp::json('GET', "https://graph.facebook.com/v21.0/{$mediaId}/insights", [
            'form' => ['metric' => 'likes,comments,impressions,reach,saved', 'access_token' => $token],
        ]);
        if (empty($r['data'])) return null;

        $out = ['likes' => 0, 'comments' => 0, 'impressions' => 0, 'reach' => 0];
        foreach ($r['data'] as $m) {
            $v = (int)($m['values'][0]['value'] ?? 0);
            match ($m['name'] ?? '') {
                'likes' => $out['likes'] = $v,
                'comments' => $out['comments'] = $v,
                'impressions' => $out['impressions'] = $v,
                'reach' => $out['reach'] = $v,
                default => null,
            };
        }
        return $out;
    }

    private static function threads(string $token, string $threadsMediaId): ?array
    {
        $r = SocialHttp::json('GET', "https://graph.threads.net/v1.0/{$threadsMediaId}/insights", [
            'form' => ['metric' => 'likes,replies,reposts,quotes,impressions', 'access_token' => $token],
        ]);
        if (empty($r['data'])) return null;

        $out = ['likes' => 0, 'replies' => 0, 'shares' => 0, 'impressions' => 0];
        foreach ($r['data'] as $m) {
            $v = (int)($m['values'][0]['value'] ?? 0);
            match ($m['name'] ?? '') {
                'likes' => $out['likes'] = $v,
                'replies' => $out['replies'] = $v,
                'impressions' => $out['impressions'] = $v,
                'reposts', 'quotes' => $out['shares'] += $v,
                default => null,
            };
        }
        return $out;
    }

    private static function x(string $token, string $tweetId): ?array
    {
        $r = SocialHttp::json('GET', "https://api.x.com/2/tweets/{$tweetId}", [
            'headers' => ['Authorization: Bearer ' . $token],
            'form' => ['tweet.fields' => 'public_metrics'],
        ]);
        $m = $r['data']['public_metrics'] ?? null;
        if (!is_array($m)) return null;

        return [
            'likes' => (int)($m['like_count'] ?? 0),
            'comments' => (int)($m['reply_count'] ?? 0),
            'shares' => (int)($m['retweet_count'] ?? 0) + (int)($m['quote_count'] ?? 0),
            'impressions' => (int)($m['impression_count'] ?? 0),
        ];
    }

    private static function tiktok(string $token, string $videoId): ?array
    {
        $r = SocialHttp::json('POST', 'https://open.tiktokapis.com/v2/video/query/', [
            'headers' => ['Authorization: Bearer ' . $token],
            'json' => [
                'fields' => ['id', 'like_count', 'comment_count', 'share_count', 'view_count'],
                'filter' => ['video_ids' => [$videoId]],
            ],
        ]);
        $video = $r['data']['videos'][0] ?? null;
        if (!is_array($video)) return null;

        return [
            'likes' => (int)($video['like_count'] ?? 0),
            'comments' => (int)($video['comment_count'] ?? 0),
            'shares' => (int)($video['share_count'] ?? 0),
            'views' => (int)($video['view_count'] ?? 0),
        ];
    }
}
