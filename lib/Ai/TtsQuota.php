<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

class TtsQuota
{
    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\TtsGeneration::where('user_id', $userId)
                ->where('status', 'completed')
                ->where('created_at', '>=', $start)
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function limit(string $plan): int
    {
        return Plans::maxTts($plan);
    }

    public static function check(int $userId, string $plan): array
    {
        $limit = self::limit($plan);
        $used = self::used($userId);

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

    public static function create(int $userId, string $provider, string $model, string $voice, string $format, string $text): ?int
    {
        if (!Database::available()) return null;

        try {
            $generation = \AfiliaFacil\Models\TtsGeneration::create([
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'voice' => $voice,
                'format' => $format,
                'text' => mb_substr($text, 0, 10000),
                'chars' => mb_strlen($text),
                'status' => 'processing',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return (int)$generation->id;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function complete(int $id, string $filePath): void
    {
        if (!Database::available()) return;

        try {
            $generation = \AfiliaFacil\Models\TtsGeneration::find($id);
            if (!$generation) return;

            $generation->status = 'completed';
            $generation->file_path = $filePath;
            $generation->updated_at = date('Y-m-d H:i:s');
            $generation->save();
        } catch (Throwable $e) {
        }
    }

    public static function fail(int $id, string $error): void
    {
        if (!Database::available()) return;

        try {
            $generation = \AfiliaFacil\Models\TtsGeneration::find($id);
            if (!$generation) return;

            $generation->status = 'failed';
            $generation->error = mb_substr($error, 0, 500);
            $generation->updated_at = date('Y-m-d H:i:s');
            $generation->save();
        } catch (Throwable $e) {
        }
    }
}
