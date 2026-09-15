<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';

class TrainingCollector
{
    public const KIND_CHAT = 'chat';
    public const KIND_TRANSCRIPTION = 'transcription';
    public const KIND_TTS = 'tts';
    public const KIND_ANALYSIS = 'analysis';

    public static function hasConsent(int $userId): bool
    {
        if (!Database::available()) return false;

        try {
            $user = \AfiliaFacil\Models\User::find($userId);
            return $user && (bool)$user->training_consent;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function setConsent(int $userId, bool $consent): bool
    {
        if (!Database::available()) return false;

        try {
            $user = \AfiliaFacil\Models\User::find($userId);
            if (!$user) return false;

            $user->training_consent = $consent;
            $user->updated_at = date('Y-m-d H:i:s');
            $user->save();

            if (class_exists('Audit')) {
                Audit::log($consent ? 'training_consent_granted' : 'training_consent_revoked', 'user', (string)$userId);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function capture(int $userId, string $plan, string $kind, array $payload): void
    {
        if (!Database::available()) return;
        if (!self::hasConsent($userId)) return;

        try {
            $sanitized = [];
            foreach ($payload as $key => $value) {
                if (is_string($value)) {
                    $result = ContentModerator::sanitizeForTraining($value);
                    $sanitized[$key] = $result['text'];
                } elseif (is_array($value)) {
                    $sanitized[$key] = array_map(function ($item) {
                        if (is_string($item)) {
                            return ContentModerator::sanitizeForTraining($item)['text'];
                        }
                        return is_scalar($item) ? $item : null;
                    }, $value);
                } else {
                    $sanitized[$key] = is_scalar($value) ? $value : null;
                }
            }

            \AfiliaFacil\Models\TrainingSample::create([
                'user_id' => $userId,
                'plan' => $plan,
                'kind' => $kind,
                'payload' => $sanitized,
                'consent' => true,
                'redacted' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }

    public static function stats(): array
    {
        if (!Database::available()) return ['total' => 0, 'by_kind' => [], 'by_plan' => []];

        try {
            $byKind = [];
            foreach (\AfiliaFacil\Models\TrainingSample::query()
                ->select('kind', \Illuminate\Database\Capsule\Manager::connection()->raw('count(*) as total'))
                ->groupBy('kind')->get() as $row) {
                $byKind[$row->kind] = (int)$row->total;
            }

            $byPlan = [];
            foreach (\AfiliaFacil\Models\TrainingSample::query()
                ->select('plan', \Illuminate\Database\Capsule\Manager::connection()->raw('count(*) as total'))
                ->groupBy('plan')->get() as $row) {
                $byPlan[$row->plan] = (int)$row->total;
            }

            return [
                'total' => (int)\AfiliaFacil\Models\TrainingSample::count(),
                'by_kind' => $byKind,
                'by_plan' => $byPlan,
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'by_kind' => [], 'by_plan' => []];
        }
    }

    public static function exportJsonl(int $limit = 20000): string
    {
        if (!Database::available()) return '';

        $lines = [];
        $samples = \AfiliaFacil\Models\TrainingSample::orderBy('id')->limit($limit)->get();

        foreach ($samples as $sample) {
            $payload = $sample->payload ?? [];
            $lines[] = json_encode([
                'kind' => $sample->kind,
                'plan' => $sample->plan,
                'payload' => $payload,
                'created_at' => (string)$sample->created_at,
            ], JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines);
    }

    public static function exportUserData(int $userId, string $format = 'md'): array
    {
        if (!Database::available()) return ['filename' => '', 'content' => ''];

        $user = \AfiliaFacil\Models\User::find($userId);
        $name = $user->name ?? 'usuario';

        if ($format === 'jsonl') {
            $lines = [];

            foreach (\AfiliaFacil\Models\AgentConversation::where('user_id', $userId)->get() as $conversation) {
                foreach (\AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversation->id)->orderBy('id')->get() as $message) {
                    $lines[] = json_encode([
                        'type' => 'agent_message',
                        'conversation' => $conversation->title,
                        'role' => $message->role,
                        'content' => $message->content,
                        'created_at' => (string)$message->created_at,
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            foreach (\AfiliaFacil\Models\Transcription::where('user_id', $userId)->where('status', 'completed')->get() as $t) {
                $lines[] = json_encode(['type' => 'transcription', 'url' => $t->source_url, 'text' => $t->text, 'created_at' => (string)$t->created_at], JSON_UNESCAPED_UNICODE);
            }

            foreach (\AfiliaFacil\Models\TtsGeneration::where('user_id', $userId)->where('status', 'completed')->get() as $t) {
                $lines[] = json_encode(['type' => 'tts', 'voice' => $t->voice, 'text' => $t->text, 'created_at' => (string)$t->created_at], JSON_UNESCAPED_UNICODE);
            }

            return ['filename' => 'meus-dados-' . date('Ymd-His') . '.jsonl', 'content' => implode("\n", $lines)];
        }

        $md = "# Meus dados — {$name}\n\nGerado em " . date('d/m/Y H:i') . "\n\n";

        $conversations = \AfiliaFacil\Models\AgentConversation::where('user_id', $userId)->orderByDesc('id')->get();
        if ($conversations->count()) {
            $md .= "## Conversas com o Sócio de IA\n\n";
            foreach ($conversations as $conversation) {
                $md .= "### {$conversation->title}\n\n";
                foreach (\AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversation->id)->orderBy('id')->get() as $message) {
                    $who = $message->role === 'user' ? 'Você' : ($message->role === 'tool' ? '[Ação]' : 'Sócio de IA');
                    $md .= "**{$who}:** " . trim((string)$message->content) . "\n\n";
                }
            }
        }

        $transcriptions = \AfiliaFacil\Models\Transcription::where('user_id', $userId)->where('status', 'completed')->orderByDesc('id')->limit(50)->get();
        if ($transcriptions->count()) {
            $md .= "## Transcrições\n\n";
            foreach ($transcriptions as $t) {
                $md .= "### {$t->source_url}\n\n" . trim((string)$t->text) . "\n\n";
            }
        }

        $narracoes = \AfiliaFacil\Models\TtsGeneration::where('user_id', $userId)->where('status', 'completed')->orderByDesc('id')->limit(50)->get();
        if ($narracoes->count()) {
            $md .= "## Narrações (textos)\n\n";
            foreach ($narracoes as $t) {
                $md .= "### Voz: {$t->voice}\n\n" . trim((string)$t->text) . "\n\n";
            }
        }

        return ['filename' => 'meus-dados-' . date('Ymd-His') . '.md', 'content' => $md];
    }
}
