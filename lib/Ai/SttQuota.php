<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/SttConfig.php';

class SttQuota
{
    public const BYOK_MESSAGE = 'Sua cota de transcrições do mês acabou (%d/%d). Você pode fazer upgrade ou configurar sua própria chave (BYOK) em /admin/ai-settings.php para continuar sem limite.';

    public static function source(int $userId): string
    {
        try {
            return (SttConfig::forUser($userId)['source'] ?? 'platform') === 'byok' ? 'byok' : 'platform';
        } catch (Throwable $e) {
            return 'platform';
        }
    }

    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\Transcription::where('user_id', $userId)
                ->where('status', 'completed')
                ->where('created_at', '>=', $start)
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function limit(string $plan): int
    {
        return Plans::maxTranscriptions($plan);
    }

    public static function check(int $userId, string $plan): array
    {
        $source = self::source($userId);
        $used = self::used($userId);

        if ($source === 'byok') {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1, 'source' => 'byok'];
        }

        $limit = self::limit($plan);

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

    public static function create(int $userId, string $sourceUrl, string $provider, string $source = 'platform'): ?int
    {
        if (!Database::available()) return null;

        try {
            $transcription = \AfiliaFacil\Models\Transcription::create([
                'user_id' => $userId,
                'source_url' => mb_substr($sourceUrl, 0, 500),
                'provider' => $provider,
                'source' => $source,
                'status' => 'processing',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return (int)$transcription->id;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function complete(int $id, array $result): void
    {
        if (!Database::available()) return;

        try {
            $transcription = \AfiliaFacil\Models\Transcription::find($id);
            if (!$transcription) return;

            $transcription->status = 'completed';
            $transcription->text = $result['text'] ?? '';
            $transcription->words = $result['words'] ?? [];
            $transcription->duration_seconds = (int)($result['duration'] ?? 0);
            $transcription->provider = $result['provider'] ?? $transcription->provider;
            $transcription->updated_at = date('Y-m-d H:i:s');
            $transcription->save();
        } catch (Throwable $e) {
        }
    }

    public static function fail(int $id, string $error): void
    {
        if (!Database::available()) return;

        try {
            $transcription = \AfiliaFacil\Models\Transcription::find($id);
            if (!$transcription) return;

            $transcription->status = 'failed';
            $transcription->error = mb_substr($error, 0, 500);
            $transcription->updated_at = date('Y-m-d H:i:s');
            $transcription->save();
        } catch (Throwable $e) {
        }
    }
}
