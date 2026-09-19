<?php

require_once __DIR__ . '/../Database.php';

class AgentMonitor
{
    public static function conversations(array $filters = [], int $limit = 60): array
    {
        if (!Database::available()) return ['items' => [], 'total' => 0];

        try {
            $query = \AfiliaFacil\Models\AgentConversation::query()
                ->leftJoin('users', 'users.id', '=', 'agent_conversations.user_id')
                ->leftJoin('agent_subagents', 'agent_subagents.id', '=', 'agent_conversations.subagent_id')
                ->select([
                    'agent_conversations.id',
                    'agent_conversations.title',
                    'agent_conversations.user_id',
                    'agent_conversations.subagent_id',
                    'agent_conversations.created_at',
                    'agent_conversations.updated_at',
                    'users.email as user_email',
                    'agent_subagents.name as subagent_name',
                ]);

            if (($filters['kind'] ?? '') === 'subagent') {
                $query->whereNotNull('agent_conversations.subagent_id');
            } elseif (($filters['kind'] ?? '') === 'agent') {
                $query->whereNull('agent_conversations.subagent_id');
            }

            if (!empty($filters['days'])) {
                $query->where('agent_conversations.updated_at', '>=', date('Y-m-d H:i:s', time() - (int)$filters['days'] * 86400));
            }

            if (!empty($filters['q'])) {
                $like = '%' . $filters['q'] . '%';
                $query->where(function ($sub) use ($like) {
                    $sub->where('agent_conversations.title', 'like', $like)
                        ->orWhere('users.email', 'like', $like);
                });
            }

            $total = (clone $query)->count();
            $rows = $query->orderByDesc('agent_conversations.updated_at')->limit($limit)->get();

            $items = [];
            foreach ($rows as $row) {
                $messages = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $row->id)->count();
                $agentMessages = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $row->id)->where('role', 'agent')->count();
                $up = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $row->id)->where('rating', 1)->count();
                $down = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $row->id)->where('rating', -1)->count();

                if (($filters['rating'] ?? '') === 'up' && $up === 0) continue;
                if (($filters['rating'] ?? '') === 'down' && $down === 0) continue;
                if (($filters['rating'] ?? '') === 'none' && ($up > 0 || $down > 0)) continue;

                $items[] = [
                    'id' => (int)$row->id,
                    'title' => $row->title,
                    'user_id' => (int)$row->user_id,
                    'user_email' => $row->user_email ?? '',
                    'subagent_id' => $row->subagent_id ? (int)$row->subagent_id : null,
                    'subagent_name' => $row->subagent_name ?? '',
                    'messages' => $messages,
                    'agent_messages' => $agentMessages,
                    'up' => $up,
                    'down' => $down,
                    'updated_at' => (string)$row->updated_at,
                ];
            }

            return ['items' => $items, 'total' => $total];
        } catch (Throwable $e) {
            return ['items' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    public static function getConversation(int $id): ?array
    {
        if (!Database::available()) return null;

        try {
            $conversation = \AfiliaFacil\Models\AgentConversation::find($id);
            if (!$conversation) return null;

            $user = \AfiliaFacil\Models\User::find($conversation->user_id);
            $subagentName = '';
            if (!empty($conversation->subagent_id)) {
                $subagent = \AfiliaFacil\Models\AgentSubagent::find($conversation->subagent_id);
                $subagentName = $subagent->name ?? '';
            }

            $messages = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $id)
                ->orderBy('id')
                ->limit(300)
                ->get()
                ->map(fn($m) => [
                    'id' => (int)$m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'tool_name' => $m->tool_name,
                    'tool_args' => $m->tool_args,
                    'tool_result_summary' => is_array($m->tool_result) ? ($m->tool_result['summary'] ?? '') : '',
                    'status' => $m->status,
                    'rating' => $m->rating !== null ? (int)$m->rating : null,
                    'rating_note' => $m->rating_note ?? '',
                    'created_at' => (string)$m->created_at,
                ])->all();

            return [
                'id' => (int)$conversation->id,
                'title' => $conversation->title,
                'user_email' => $user->email ?? '',
                'subagent_name' => $subagentName,
                'updated_at' => (string)$conversation->updated_at,
                'messages' => $messages,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function stats(): array
    {
        if (!Database::available()) return ['conversations' => 0, 'agent_messages' => 0, 'up' => 0, 'down' => 0, 'rated_pct' => 0];

        try {
            $conversations = (int)\AfiliaFacil\Models\AgentConversation::count();
            $agentMessages = (int)\AfiliaFacil\Models\AgentMessage::where('role', 'agent')->count();
            $up = (int)\AfiliaFacil\Models\AgentMessage::where('rating', 1)->count();
            $down = (int)\AfiliaFacil\Models\AgentMessage::where('rating', -1)->count();
            $rated = $up + $down;

            return [
                'conversations' => $conversations,
                'agent_messages' => $agentMessages,
                'up' => $up,
                'down' => $down,
                'rated_pct' => $agentMessages > 0 ? (int)round(($rated / $agentMessages) * 100) : 0,
            ];
        } catch (Throwable $e) {
            return ['conversations' => 0, 'agent_messages' => 0, 'up' => 0, 'down' => 0, 'rated_pct' => 0];
        }
    }

    public static function exportJsonl(int $limit = 5000): string
    {
        if (!Database::available()) return '';

        try {
            $lines = [];
            $conversations = \AfiliaFacil\Models\AgentConversation::orderBy('id')->limit(1000)->get();

            foreach ($conversations as $conversation) {
                $messages = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversation->id)
                    ->orderBy('id')
                    ->limit(60)
                    ->get();

                $pendingUser = null;
                foreach ($messages as $message) {
                    if ($message->role === 'user') {
                        $pendingUser = $message;
                        continue;
                    }
                    if ($message->role === 'agent' && $pendingUser !== null && trim((string)$message->content) !== '') {
                        $lines[] = json_encode([
                            'conversation_id' => (int)$conversation->id,
                            'subagent' => $conversation->subagent_id ? (int)$conversation->subagent_id : null,
                            'user' => (string)$pendingUser->content,
                            'assistant' => (string)$message->content,
                            'rating' => $message->rating !== null ? (int)$message->rating : null,
                            'rating_note' => $message->rating_note ?? '',
                            'created_at' => (string)$message->created_at,
                        ], JSON_UNESCAPED_UNICODE);
                        $pendingUser = null;
                    }
                }

                if (count($lines) >= $limit) break;
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '';
        }
    }
}
