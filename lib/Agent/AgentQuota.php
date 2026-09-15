<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../AdSpy/AiConfig.php';

class AgentQuota
{
    public const BYOK_MESSAGE = 'Sua cota de mensagens do Sócio de IA neste mês acabou (%d/%d). Você pode fazer upgrade ou configurar sua própria chave de IA (BYOK) em /admin/ai-settings.php para continuar sem limite.';

    public static function source(int $userId): string
    {
        try {
            return (AiConfig::forUser($userId)['source'] ?? 'platform') === 'byok' ? 'byok' : 'platform';
        } catch (Throwable $e) {
            return 'platform';
        }
    }

    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\AgentMessage::query()
                ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_messages.conversation_id')
                ->where('agent_conversations.user_id', $userId)
                ->where('agent_messages.role', 'user')
                ->where('agent_messages.status', '!=', 'blocked')
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

    public static function limitMessage(int $used, int $limit): string
    {
        return sprintf(self::BYOK_MESSAGE, $used, $limit);
    }
}
