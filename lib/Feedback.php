<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Moderation/ContentModerator.php';

class Feedback
{
    public const TYPES = ['sugestao', 'reclamacao', 'elogio', 'bug'];
    public const STATUSES = ['novo', 'lido', 'respondido', 'arquivado'];
    public const DAILY_LIMIT = 5;

    public const TYPE_LABELS = [
        'sugestao' => 'Sugestão',
        'reclamacao' => 'Reclamação',
        'elogio' => 'Elogio',
        'bug' => 'Problema (bug)',
    ];

    public static function send(int $userId, string $type, string $message, array $context = []): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $type = in_array($type, self::TYPES, true) ? $type : 'sugestao';
        $message = trim($message);

        if (mb_strlen($message) < 10) {
            return ['error' => 'Escreva pelo menos 10 caracteres para nos ajudar a entender.'];
        }
        if (mb_strlen($message) > 4000) {
            return ['error' => 'Mensagem muito longa (máximo 4000 caracteres).'];
        }

        if (self::dailyCount($userId) >= self::DAILY_LIMIT) {
            return ['error' => 'Você já enviou ' . self::DAILY_LIMIT . ' feedbacks hoje. Tente novamente amanhã.'];
        }

        $screen = ContentModerator::screen($message, 'feedback', $userId);
        if (!$screen['allowed']) {
            return ['error' => $screen['reason']];
        }
        $message = $screen['clean'];

        try {
            $feedback = \AfiliaFacil\Models\Feedback::create([
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'context' => array_slice($context, 0, 10, true),
                'status' => 'novo',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::log('feedback_sent', 'feedback', (string)$feedback->id, ['type' => $type]);

            return ['success' => true, 'id' => (int)$feedback->id];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao enviar o feedback: ' . $e->getMessage()];
        }
    }

    public static function listForUser(int $userId): array
    {
        if (!Database::available()) return [];

        try {
            return \AfiliaFacil\Models\Feedback::where('user_id', $userId)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn($f) => self::toArray($f))
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function listAll(array $filters = []): array
    {
        if (!Database::available()) return ['items' => [], 'total' => 0];

        try {
            $query = \AfiliaFacil\Models\Feedback::query();

            if (!empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
                $query->where('type', $filters['type']);
            }
            if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['plan'])) {
                $query->where('context->plan', $filters['plan']);
            }

            $total = (clone $query)->count();
            $items = $query->orderByDesc('id')->limit(100)->get();

            return [
                'items' => $items->map(fn($f) => self::toArray($f, true))->all(),
                'total' => $total,
            ];
        } catch (Throwable $e) {
            return ['items' => [], 'total' => 0];
        }
    }

    public static function reply(int $id, int $adminId, string $reply): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];

        $reply = trim($reply);
        if ($reply === '') return ['error' => 'Escreva a resposta.'];

        $screen = ContentModerator::screen($reply, 'feedback', $adminId);
        if (!$screen['allowed']) return ['error' => $screen['reason']];

        try {
            $feedback = \AfiliaFacil\Models\Feedback::find($id);
            if (!$feedback) return ['error' => 'Feedback não encontrado'];

            $feedback->admin_reply = mb_substr($screen['clean'], 0, 4000);
            $feedback->replied_by = $adminId;
            $feedback->replied_at = date('Y-m-d H:i:s');
            $feedback->status = 'respondido';
            $feedback->updated_at = date('Y-m-d H:i:s');
            $feedback->save();

            Audit::log('feedback_replied', 'feedback', (string)$id, []);
            return ['success' => true];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao responder: ' . $e->getMessage()];
        }
    }

    public static function setStatus(int $id, string $status): array
    {
        if (!Database::available()) return ['error' => 'Banco indisponível'];
        if (!in_array($status, self::STATUSES, true)) return ['error' => 'Status inválido'];

        try {
            $feedback = \AfiliaFacil\Models\Feedback::find($id);
            if (!$feedback) return ['error' => 'Feedback não encontrado'];

            $feedback->status = $status;
            $feedback->updated_at = date('Y-m-d H:i:s');
            $feedback->save();

            return ['success' => true];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao atualizar: ' . $e->getMessage()];
        }
    }

    public static function stats(): array
    {
        if (!Database::available()) return ['total' => 0, 'novos' => 0, 'respondidos' => 0, 'by_type' => []];

        try {
            $byType = [];
            foreach (self::TYPES as $type) {
                $byType[$type] = (int)\AfiliaFacil\Models\Feedback::where('type', $type)->count();
            }

            return [
                'total' => (int)\AfiliaFacil\Models\Feedback::count(),
                'novos' => (int)\AfiliaFacil\Models\Feedback::where('status', 'novo')->count(),
                'respondidos' => (int)\AfiliaFacil\Models\Feedback::where('status', 'respondido')->count(),
                'by_type' => $byType,
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'novos' => 0, 'respondidos' => 0, 'by_type' => []];
        }
    }

    public static function unrepliedCount(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\Feedback::where('user_id', $userId)
                ->where('status', 'respondido')
                ->where('updated_at', '>=', date('Y-m-d H:i:s', time() - 30 * 86400))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function newCount(): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\Feedback::where('status', 'novo')->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function exportCsv(): string
    {
        if (!Database::available()) return '';

        $rows = \AfiliaFacil\Models\Feedback::orderByDesc('id')->limit(5000)->get();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'data', 'tipo', 'status', 'plano', 'pagina', 'mensagem', 'resposta'], ';');
        foreach ($rows as $f) {
            fputcsv($out, [
                (int)$f->id,
                (string)$f->created_at,
                self::TYPE_LABELS[$f->type] ?? $f->type,
                $f->status,
                $f->context['plan'] ?? '',
                $f->context['page'] ?? '',
                (string)$f->message,
                (string)$f->admin_reply,
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string)$csv;
    }

    public static function exportJson(): string
    {
        if (!Database::available()) return '[]';

        $rows = \AfiliaFacil\Models\Feedback::orderByDesc('id')->limit(5000)->get();
        return json_encode($rows->map(fn($f) => self::toArray($f, true))->all(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';
    }

    private static function dailyCount(int $userId): int
    {
        try {
            return (int)\AfiliaFacil\Models\Feedback::where('user_id', $userId)
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function toArray($feedback, bool $adminView = false): array
    {
        $data = [
            'id' => (int)$feedback->id,
            'type' => $feedback->type,
            'type_label' => self::TYPE_LABELS[$feedback->type] ?? $feedback->type,
            'message' => $feedback->message,
            'context' => $feedback->context ?? [],
            'status' => $feedback->status,
            'admin_reply' => $feedback->admin_reply,
            'replied_at' => $feedback->replied_at ? (string)$feedback->replied_at : null,
            'created_at' => (string)$feedback->created_at,
        ];

        if ($adminView) {
            $data['user_id'] = (int)$feedback->user_id;
        }

        return $data;
    }
}
