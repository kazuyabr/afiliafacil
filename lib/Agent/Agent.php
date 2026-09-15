<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../Audit.php';
require_once __DIR__ . '/../AdSpy/AiClient.php';
require_once __DIR__ . '/../AdSpy/AiConfig.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';
require_once __DIR__ . '/../Training/TrainingCollector.php';
require_once __DIR__ . '/AgentGuard.php';
require_once __DIR__ . '/AgentTools.php';
require_once __DIR__ . '/AgentQuota.php';
require_once __DIR__ . '/AgentProfile.php';
require_once __DIR__ . '/AgentSubagents.php';
require_once __DIR__ . '/AgentPrompts.php';

class Agent
{
    private const HISTORY_LIMIT = 12;

    public function send(int $userId, string $plan, int $conversationId, string $message): array
    {
        if (!Database::available()) {
            return ['error' => 'Banco de dados indisponível.'];
        }

        $message = trim($message);
        if ($message === '') {
            return ['error' => 'Escreva uma mensagem para o sócio.'];
        }
        if (mb_strlen($message) > 2000) {
            return ['error' => 'Mensagem muito longa (máximo 2000 caracteres).'];
        }

        $screen = ContentModerator::screen($message, 'agent', $userId);
        if (!$screen['allowed']) {
            $conversation = $this->getConversation($userId, $conversationId);
            if ($conversation) {
                $this->saveMessage((int)$conversation->id, 'user', $message, '', null, null, 'blocked', [], AgentQuota::source($userId));
                $this->saveMessage((int)$conversation->id, 'agent', $screen['reason']);
            }
            return ['success' => true, 'blocked' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }
        $message = $screen['clean'];

        $quota = AgentQuota::check($userId, $plan);
        if (!$quota['allowed']) {
            return [
                'error' => AgentQuota::limitMessage($quota['used'], $quota['limit']),
                'quota' => $quota,
            ];
        }

        $conversation = $this->getConversation($userId, $conversationId);
        if (!$conversation) {
            return ['error' => 'Conversa não encontrada.'];
        }

        $this->saveMessage((int)$conversation->id, 'user', $message, '', null, null, '', [], AgentQuota::source($userId));

        $config = AiConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            $this->saveMessage((int)$conversation->id, 'agent', 'Não consigo pensar agora: a IA não está configurada. Peça ao administrador para configurar o Cloudflare Workers AI da plataforma (CF_AI_TOKEN) ou configure sua própria chave em IA (BYOK).');
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        $response = AiClient::chat($this->buildMessages($userId, $plan, (int)$conversation->id, $message), $config);
        if ($response === null) {
            $this->saveMessage((int)$conversation->id, 'agent', 'Tive um problema para responder agora (falha na chamada da IA). Tente novamente em instantes.');
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        return $this->handleResponse($userId, $plan, (int)$conversation->id, $response, $message);
    }

    public function confirm(int $userId, int $messageId): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        $toolMessage = $this->getToolMessage($userId, $messageId);
        if (!$toolMessage) return ['error' => 'Ação não encontrada.'];
        if ($toolMessage->status !== 'pending_confirmation') return ['error' => 'Esta ação já foi processada.'];

        $user = \AfiliaFacil\Models\User::with('role')->find($userId);
        if (!$user) return ['error' => 'Usuário não encontrado.'];

        $userArray = ['id' => $userId, 'name' => $user->name, 'plan' => $user->plan];

        $access = AgentGuard::checkToolAccess($toolMessage->tool_name, $user->plan, $userId);
        if (!$access['allowed']) {
            $this->updateToolMessage((int)$toolMessage->id, 'failed', ['success' => false, 'summary' => $access['reason']]);
            return ['success' => true, 'result' => ['success' => false, 'summary' => $access['reason'], 'render' => null], 'status' => 'failed'];
        }

        $result = AgentTools::execute($toolMessage->tool_name, $toolMessage->tool_args ?? [], $userArray, $userId, [
            'is_subagent' => !empty(\AfiliaFacil\Models\AgentConversation::find($toolMessage->conversation_id)->subagent_id ?? null),
        ]);
        $status = !empty($result['success']) ? 'executed' : 'failed';
        $this->updateToolMessage((int)$toolMessage->id, $status, $result);

        Audit::log('agent_tool_' . $status, 'agent', (string)$messageId, ['tool' => $toolMessage->tool_name]);

        $commentary = $this->commentOnResult($userId, (int)$toolMessage->conversation_id, $toolMessage->tool_name, $result);

        return [
            'success' => true,
            'result' => $result,
            'status' => $status,
            'commentary' => $commentary,
        ];
    }

    public function cancel(int $userId, int $messageId): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        $toolMessage = $this->getToolMessage($userId, $messageId);
        if (!$toolMessage) return ['error' => 'Ação não encontrada.'];
        if ($toolMessage->status !== 'pending_confirmation') return ['error' => 'Esta ação já foi processada.'];

        $this->updateToolMessage((int)$toolMessage->id, 'cancelled', null);
        $this->saveMessage((int)$toolMessage->conversation_id, 'agent', 'Sem problemas, cancelei a ação. Quer seguir por outro caminho?');

        return ['success' => true];
    }

    public function newConversation(int $userId, int $subagentId = 0): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        try {
            $subagent = null;
            if ($subagentId > 0) {
                $subagent = AgentSubagents::get($userId, $subagentId);
                if (!$subagent) {
                    return ['error' => 'Subagente não encontrado.'];
                }
            }

            $conversation = \AfiliaFacil\Models\AgentConversation::create([
                'user_id' => $userId,
                'subagent_id' => $subagent ? $subagent['id'] : null,
                'title' => $subagent ? 'Com ' . $subagent['name'] : 'Nova conversa',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($subagent) {
                $greeting = 'Oi! Sou o ' . $subagent['name'] . ($subagent['specialty'] !== '' ? ' — especialista em ' . $subagent['specialty'] : '') . '. Mantenho os princípios do Sócio de IA (sem promessas de ganho, sempre te protegendo). Como posso ajudar?';
            } else {
                $greeting = 'Oi! Sou seu Sócio de IA aqui na AfiliaFacil. Meu papel é te ajudar a ganhar dinheiro com tráfego (pago ou orgânico) gastando pouco — e te proteger de furada. Antes de qualquer coisa: o que você busca agora?';
            }
            $this->saveMessage((int)$conversation->id, 'agent', $greeting);

            return ['success' => true, 'conversation_id' => (int)$conversation->id];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao criar conversa: ' . $e->getMessage()];
        }
    }

    public function getConversation(int $userId, int $conversationId)
    {
        if ($conversationId <= 0) return null;
        return \AfiliaFacil\Models\AgentConversation::where('id', $conversationId)
            ->where('user_id', $userId)
            ->first();
    }

    public function listConversations(int $userId): array
    {
        if (!Database::available()) return [];

        return \AfiliaFacil\Models\AgentConversation::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(function ($c) {
                $subagentName = '';
                if (!empty($c->subagent_id)) {
                    $subagent = \AfiliaFacil\Models\AgentSubagent::find($c->subagent_id);
                    $subagentName = $subagent->name ?? '';
                }
                return [
                    'id' => (int)$c->id,
                    'title' => $c->title,
                    'subagent_id' => $c->subagent_id ? (int)$c->subagent_id : null,
                    'subagent_name' => $subagentName,
                    'updated_at' => (string)$c->updated_at,
                ];
            })->all();
    }

    public function listMessages(int $userId, int $conversationId): array
    {
        $conversation = $this->getConversation($userId, $conversationId);
        if (!$conversation) return [];

        return \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn($m) => [
                'id' => (int)$m->id,
                'role' => $m->role,
                'content' => $m->content,
                'tool_name' => $m->tool_name,
                'tool_args' => $m->tool_args,
                'tool_result' => $m->tool_result,
                'status' => $m->status,
                'created_at' => (string)$m->created_at,
            ])->all();
    }

    public function deleteConversation(int $userId, int $conversationId): bool
    {
        $conversation = $this->getConversation($userId, $conversationId);
        if (!$conversation) return false;

        \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)->delete();
        $conversation->delete();
        return true;
    }

    private function handleResponse(int $userId, string $plan, int $conversationId, string $rawResponse, string $userMessage = ''): array
    {
        $parsed = $this->parseResponse($rawResponse);

        if ($parsed === null) {
            $filtered = AgentGuard::filterResponse(trim($rawResponse));
            $this->saveMessage($conversationId, 'agent', $filtered['text']);
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        if (!empty($parsed['profile_update']) && is_array($parsed['profile_update'])) {
            AgentProfile::update($userId, $parsed['profile_update']);
        }

        $type = $parsed['type'] ?? 'message';

        if ($type === 'question') {
            $content = ContentModerator::redact(trim((string)($parsed['content'] ?? '')));
            $options = array_slice(array_values(array_filter((array)($parsed['options'] ?? []), 'is_string')), 0, 5);
            $this->saveMessage($conversationId, 'agent', $content, '', null, null, 'question', $options);
            TrainingCollector::capture($userId, $plan, TrainingCollector::KIND_CHAT, [
                'user' => $userMessage,
                'assistant' => $content,
            ]);
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        if ($type === 'tool_call') {
            $tool = (string)($parsed['tool'] ?? '');
            $args = is_array($parsed['args'] ?? null) ? $parsed['args'] : [];
            $reason = trim((string)($parsed['reason'] ?? ''));

            $known = array_column(AgentTools::definitions(), 'name');
            if (!in_array($tool, $known, true)) {
                $this->saveMessage($conversationId, 'agent', 'Pensei em usar uma ferramenta que não existe. Pode reformular seu pedido?');
                return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
            }

            $access = AgentGuard::checkToolAccess($tool, $plan, $userId);
            if (!$access['allowed']) {
                $this->saveMessage($conversationId, 'agent', 'Não posso fazer isso agora: ' . $access['reason'] . ' Quer que eu sugira alternativas dentro do seu plano?');
                return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
            }

            $this->saveMessage($conversationId, 'tool', $reason, $tool, $args, null, 'pending_confirmation');
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        $filtered = AgentGuard::filterResponse(trim((string)($parsed['content'] ?? '')));
        $this->saveMessage($conversationId, 'agent', ContentModerator::redact($filtered['text']));
        TrainingCollector::capture($userId, $plan, TrainingCollector::KIND_CHAT, [
            'user' => $userMessage,
            'assistant' => $filtered['text'],
        ]);
        return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
    }

    private function commentOnResult(int $userId, int $conversationId, string $toolName, array $result): ?string
    {
        $config = AiConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') return null;

        $status = !empty($result['success']) ? 'SUCESSO' : 'FALHOU';
        $context = "FERRAMENTA EXECUTADA: {$toolName}\nRESULTADO ({$status}): " . ($result['summary'] ?? 'sem detalhes');

        $messages = [
            ['role' => 'system', 'content' => AgentPrompts::base()],
            ['role' => 'user', 'content' => $context . "\n\nComente o resultado em 1-3 frases como o Sócio: o que isso significa, próximo passo prático e, se falhou, o que fazer. NÃO repita dados já visíveis. Responda em texto simples (sem JSON)."],
        ];

        $commentary = AiClient::chat($messages, $config);
        if ($commentary === null) return null;

        $filtered = AgentGuard::filterResponse(trim($commentary));
        $this->saveMessage($conversationId, 'agent', ContentModerator::redact($filtered['text']));
        return $filtered['text'];
    }

    private function buildMessages(int $userId, string $plan, int $conversationId, string $message): array
    {
        $profile = AgentProfile::describe(AgentProfile::forUser($userId));
        $quotas = AgentQuota::check($userId, $plan);

        $conversation = \AfiliaFacil\Models\AgentConversation::find($conversationId);
        $subagent = null;
        if ($conversation && !empty($conversation->subagent_id)) {
            $subagent = AgentSubagents::get($userId, (int)$conversation->subagent_id);
        }
        $isSubagent = $subagent !== null;

        $offerCount = 0;
        $pageCount = 0;
        try {
            $offerCount = (int)\AfiliaFacil\Models\Offer::where('status', 'approved')->count();
            $pm = new PageManager();
            $pageCount = count($pm->listByUser($userId));
        } catch (Throwable $e) {
        }

        $toolsText = '';
        foreach (AgentTools::definitions() as $tool) {
            if ($isSubagent && in_array($tool['name'], ['criar_subagente', 'delegar_subagente'], true)) {
                continue;
            }
            if ($isSubagent && !empty($subagent['tools']) && !in_array($tool['name'], $subagent['tools'], true) && !in_array($tool['name'], ['consultar_quotas'], true)) {
                continue;
            }
            $access = AgentGuard::checkToolAccess($tool['name'], $plan, $userId);
            $mark = $access['allowed'] ? 'OK' : 'BLOQUEADA (' . ($access['reason'] ?? '') . ')';
            $params = $tool['params'] ? json_encode($tool['params'], JSON_UNESCAPED_UNICODE) : '{}';
            $toolsText .= '- ' . $tool['name'] . ' params=' . $params . ' — ' . $tool['desc'] . ' [' . $mark . "]\n";
        }

        $history = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $historyText = '';
        foreach ($history as $entry) {
            if ($entry->role === 'user') {
                $historyText .= 'Usuário: ' . mb_substr((string)$entry->content, 0, 600) . "\n";
            } elseif ($entry->role === 'tool') {
                $status = $entry->status === 'executed' ? 'executada' : ($entry->status === 'cancelled' ? 'cancelada pelo usuário' : 'pendente');
                $historyText .= '[AÇÃO ' . $entry->tool_name . ' — ' . $status . "]\n";
            } else {
                $historyText .= ($isSubagent ? $subagent['name'] : 'Sócio de IA') . ': ' . mb_substr((string)$entry->content, 0, 600) . "\n";
            }
        }

        $context = "CONTEXTO ATUAL:\n"
            . "- Usuário: {$userId} | Plano: " . Plans::planName($plan) . "\n"
            . ($isSubagent ? "- Você está no modo SUBAGENTE: {$subagent['name']} (especialidade: " . ($subagent['specialty'] ?: 'geral') . ")\n" : '')
            . "- Perfil: {$profile}\n"
            . "- Mensagens do sócio neste mês: {$quotas['used']}" . ($quotas['limit'] === -1 ? ' (ilimitado)' : "/{$quotas['limit']}") . "\n"
            . "- Ofertas aprovadas disponíveis no swipe file: {$offerCount}\n"
            . "- Páginas clonadas do usuário: {$pageCount}\n\n"
            . "FERRAMENTAS (use SOMENTE estas; as BLOQUEADAS não podem ser chamadas):\n{$toolsText}\n"
            . "CONVERSA ATÉ AGORA:\n{$historyText}\n"
            . "NOVA MENSAGEM DO USUÁRIO:\n{$message}";

        $system = $isSubagent ? AgentPrompts::subagent($subagent) : AgentPrompts::base();

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $context],
        ];
    }

    private function parseResponse(string $raw): ?array
    {
        $text = trim($raw);

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && isset($decoded['type'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function saveMessage(int $conversationId, string $role, string $content, string $toolName = '', ?array $toolArgs = null, ?array $toolResult = null, string $status = '', array $extra = [], string $source = ''): int
    {
        $payload = [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'tool_name' => $toolName,
            'tool_args' => $toolArgs,
            'tool_result' => $toolResult,
            'status' => $status,
            'source' => $source !== '' ? $source : 'platform',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($extra['options'])) {
            $payload['tool_args'] = ['options' => $extra['options']];
        }

        $message = \AfiliaFacil\Models\AgentMessage::create($payload);
        $this->touchConversation($conversationId, $role === 'user' ? $content : '');
        return (int)$message->id;
    }

    private function touchConversation(int $conversationId, string $titleCandidate): void
    {
        try {
            $conversation = \AfiliaFacil\Models\AgentConversation::find($conversationId);
            if (!$conversation) return;

            if ($titleCandidate !== '' && ($conversation->title === 'Nova conversa' || $conversation->title === '')) {
                $conversation->title = mb_substr($titleCandidate, 0, 80);
            }
            $conversation->updated_at = date('Y-m-d H:i:s');
            $conversation->save();
        } catch (Throwable $e) {
        }
    }

    private function getToolMessage(int $userId, int $messageId)
    {
        $message = \AfiliaFacil\Models\AgentMessage::where('id', $messageId)->where('role', 'tool')->first();
        if (!$message) return null;

        $conversation = $this->getConversation($userId, (int)$message->conversation_id);
        if (!$conversation) return null;

        return $message;
    }

    private function updateToolMessage(int $messageId, string $status, ?array $result): void
    {
        try {
            $message = \AfiliaFacil\Models\AgentMessage::find($messageId);
            if (!$message) return;
            $message->status = $status;
            $message->tool_result = $result;
            $message->save();
        } catch (Throwable $e) {
        }
    }
}
