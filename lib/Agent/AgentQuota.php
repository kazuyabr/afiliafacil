<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

class AgentQuota
{
    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\AgentMessage::query()
                ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_messages.conversation_id')
                ->where('agent_conversations.user_id', $userId)
                ->where('agent_messages.role', 'user')
                ->where('agent_messages.created_at', '>=', $start)
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function limit(string $plan): int
    {
        return Plans::maxAgentMessages($plan);
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
}
