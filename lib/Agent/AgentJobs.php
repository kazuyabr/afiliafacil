<?php

require_once __DIR__ . '/../Database.php';

class AgentJobs
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;
    public const STUCK_MINUTES = 5;

    public static function enqueue(int $conversationId, int $userId, string $kind = 'agent', ?int $toolMessageId = null): ?int
    {
        if (!Database::available()) return null;

        try {
            $job = \AfiliaFacil\Models\AgentJob::create([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'kind' => in_array($kind, ['agent', 'subagent', 'tool'], true) ? $kind : 'agent',
                'tool_message_id' => $toolMessageId,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int)$job->id;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function workingCount(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            // Considera apenas jobs recentes: jobs orfaos antigos nao deixam o sino "trabalhando" eternamente
            // (o cron reprocessa pendentes antigos via processPending/releaseStuck).
            return (int)\AfiliaFacil\Models\AgentJob::where('user_id', $userId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 600))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function claim(int $jobId): ?object
    {
        if (!Database::available()) return null;

        try {
            $affected = \AfiliaFacil\Models\AgentJob::where('id', $jobId)
                ->where('status', self::STATUS_PENDING)
                ->update([
                    'status' => self::STATUS_PROCESSING,
                    'started_at' => date('Y-m-d H:i:s'),
                    'attempts' => \Illuminate\Database\Capsule\Manager::connection()->raw('attempts + 1'),
                ]);

            if ($affected < 1) return null;

            return \AfiliaFacil\Models\AgentJob::find($jobId);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function complete(int $jobId): void
    {
        self::setStatus($jobId, self::STATUS_DONE);
    }

    public static function fail(int $jobId, string $error): void
    {
        if (!Database::available()) return;

        try {
            $job = \AfiliaFacil\Models\AgentJob::find($jobId);
            if (!$job) return;

            $job->error = mb_substr($error, 0, 500);
            $job->finished_at = date('Y-m-d H:i:s');

            if ((int)$job->attempts < self::MAX_ATTEMPTS) {
                $job->status = self::STATUS_PENDING;
            } else {
                $job->status = self::STATUS_FAILED;
            }

            $job->save();
        } catch (Throwable $e) {
        }
    }

    public static function lastForConversation(int $conversationId): ?array
    {
        if (!Database::available()) return null;

        try {
            $job = \AfiliaFacil\Models\AgentJob::where('conversation_id', $conversationId)
                ->orderByDesc('id')
                ->first();
            if (!$job) return null;

            return [
                'id' => (int)$job->id,
                'status' => $job->status,
                'error' => $job->error,
                'created_at' => (string)$job->created_at,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function processPending(int $limit = 5): array
    {
        if (!Database::available()) return ['processed' => 0, 'failed' => 0];

        self::releaseStuck();

        $summary = ['processed' => 0, 'failed' => 0];

        try {
            $jobs = \AfiliaFacil\Models\AgentJob::where('status', self::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            if ($jobs->isEmpty()) return $summary;

            require_once __DIR__ . '/Agent.php';
            $agent = new Agent();

            foreach ($jobs as $job) {
                $result = $agent->processJob((int)$job->id);
                if (!empty($result['success'])) {
                    $summary['processed']++;
                } elseif (!empty($result['error'])) {
                    $summary['failed']++;
                }
            }
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    public static function releaseStuck(): int
    {
        if (!Database::available()) return 0;

        try {
            $threshold = date('Y-m-d H:i:s', time() - self::STUCK_MINUTES * 60);
            return (int)\AfiliaFacil\Models\AgentJob::where('status', self::STATUS_PROCESSING)
                ->where('started_at', '<', $threshold)
                ->update(['status' => self::STATUS_PENDING, 'error' => 'Reprocessado (travamento)']);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function setStatus(int $jobId, string $status): void
    {
        if (!Database::available()) return;

        try {
            \AfiliaFacil\Models\AgentJob::where('id', $jobId)->update([
                'status' => $status,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }
}
