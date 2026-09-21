<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../Audit.php';
require_once __DIR__ . '/../AdSpy/AiClient.php';
require_once __DIR__ . '/../AdSpy/AiConfig.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';
require_once __DIR__ . '/../Training/TrainingCollector.php';
require_once __DIR__ . '/../Offers/OfferManager.php';
require_once __DIR__ . '/AgentGuard.php';
require_once __DIR__ . '/AgentTools.php';
require_once __DIR__ . '/AgentQuota.php';
require_once __DIR__ . '/AgentProfile.php';
require_once __DIR__ . '/AgentSubagents.php';
require_once __DIR__ . '/AgentPermissions.php';
require_once __DIR__ . '/AgentPrompts.php';
require_once __DIR__ . '/AgentJobs.php';

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

        // Onboarding: se o agente acabou de perguntar o NICHO (question com opcoes) e o usuario
        // respondeu, salvamos no perfil de forma DETERMINISTICA (nao dependemos da IA lembrar).
        try {
            $lastAgentMessage = \AfiliaFacil\Models\AgentMessage::where('conversation_id', (int)$conversation->id)
                ->where('role', 'agent')
                ->orderByDesc('id')
                ->first();
            $options = $lastAgentMessage->tool_args['options'] ?? [];
            if (($lastAgentMessage->status ?? '') === 'question' && is_array($options) && !empty($options)) {
                $niche = OfferManager::matchNiche($message);
                $profile = AgentProfile::forUser($userId);
                if ($niche !== null && trim((string)($profile['niche'] ?? '')) === '') {
                    AgentProfile::update($userId, ['niche' => $niche]);
                }
            }
        } catch (Throwable $e) {
        }

        $kind = !empty($conversation->subagent_id) ? 'subagent' : 'agent';
        $jobId = AgentJobs::enqueue((int)$conversation->id, $userId, $kind);

        if ($jobId === null) {
            $this->saveMessage((int)$conversation->id, 'agent', 'Não consegui iniciar o processamento agora. Tente novamente em instantes.');
            return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
        }

        return [
            'success' => true,
            'queued' => true,
            'job_id' => $jobId,
            'conversation_id' => (int)$conversation->id,
            'quota' => AgentQuota::check($userId, $plan),
        ];
    }

    public function processJob(int $jobId): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        $job = AgentJobs::claim($jobId);
        if ($job === null) {
            return ['skipped' => true];
        }

        $userId = (int)$job->user_id;
        $conversationId = (int)$job->conversation_id;

        try {
            if (($job->kind ?? '') === 'tool') {
                return $this->processToolJob($job);
            }

            $conversation = $this->getConversation($userId, $conversationId);
            if (!$conversation) {
                AgentJobs::fail($jobId, 'Conversa não encontrada');
                return ['error' => 'Conversa não encontrada.'];
            }

            $user = \AfiliaFacil\Models\User::find($userId);
            $plan = $user->plan ?? 'trial';

            $lastUserMessage = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->first();
            $message = (string)($lastUserMessage->content ?? '');

            $config = AiConfig::forUser($userId);
            if (($config['api_key'] ?? '') === '') {
                $this->saveMessage($conversationId, 'agent', 'Não consigo pensar agora: a IA não está configurada. Peça ao administrador para configurar o Cloudflare Workers AI da plataforma (CF_AI_TOKEN) ou configure sua própria chave em IA (BYOK).');
                AgentJobs::complete($jobId);
                return ['success' => true];
            }

            $response = $this->chatWithRetry($this->buildMessages($userId, $plan, $conversationId, $message), $config);
            if ($response === null) {
                $this->saveMessage($conversationId, 'agent', 'Tive um problema para responder agora (falha na chamada da IA). Tente novamente em instantes.');
                AgentJobs::complete($jobId);
                return ['success' => true];
            }

            $this->handleResponse($userId, $plan, $conversationId, $response, $message);
            AgentJobs::complete($jobId);

            return ['success' => true, 'conversation_id' => $conversationId];
        } catch (Throwable $e) {
            AgentJobs::fail($jobId, $e->getMessage());
            return ['error' => 'Falha ao processar: ' . $e->getMessage()];
        }
    }

    public function notifications(int $userId): array
    {
        if (!Database::available()) return ['count' => 0, 'items' => [], 'working' => 0, 'pending_confirmations' => 0];

        try {
            $rows = \AfiliaFacil\Models\AgentMessage::query()
                ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_messages.conversation_id')
                ->where('agent_conversations.user_id', $userId)
                ->where('agent_messages.role', 'agent')
                ->whereNull('agent_messages.seen_at')
                ->orderByDesc('agent_messages.id')
                ->limit(10)
                ->get([
                    'agent_messages.id',
                    'agent_messages.conversation_id',
                    'agent_messages.content',
                    'agent_messages.created_at',
                    'agent_conversations.subagent_id',
                    'agent_conversations.title',
                ]);

            $items = [];
            foreach ($rows as $row) {
                $subagentName = '';
                if (!empty($row->subagent_id)) {
                    $subagent = \AfiliaFacil\Models\AgentSubagent::find($row->subagent_id);
                    $subagentName = $subagent->name ?? '';
                }
                $items[] = [
                    'message_id' => (int)$row->id,
                    'conversation_id' => (int)$row->conversation_id,
                    'preview' => mb_substr((string)$row->content, 0, 140),
                    'subagent_name' => $subagentName,
                    'created_at' => (string)$row->created_at,
                ];
            }

            $pendingRow = \AfiliaFacil\Models\AgentMessage::query()
                ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_messages.conversation_id')
                ->where('agent_conversations.user_id', $userId)
                ->where('agent_messages.role', 'tool')
                ->where('agent_messages.status', 'pending_confirmation')
                ->orderByDesc('agent_messages.id')
                ->first(['agent_messages.conversation_id']);

            $pendingCount = (int)\AfiliaFacil\Models\AgentMessage::query()
                ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_messages.conversation_id')
                ->where('agent_conversations.user_id', $userId)
                ->where('agent_messages.role', 'tool')
                ->where('agent_messages.status', 'pending_confirmation')
                ->count();

            return [
                'count' => count($items),
                'items' => $items,
                'working' => AgentJobs::workingCount($userId),
                'pending_confirmations' => $pendingCount,
                'pending_conversation_id' => $pendingRow ? (int)$pendingRow->conversation_id : null,
            ];
        } catch (Throwable $e) {
            return ['count' => 0, 'items' => [], 'working' => 0, 'pending_confirmations' => 0];
        }
    }

    public function markSeen(int $userId, int $conversationId): void
    {
        if (!Database::available()) return;

        try {
            $conversation = $this->getConversation($userId, $conversationId);
            if (!$conversation) return;

            \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)
                ->where('role', 'agent')
                ->whereNull('seen_at')
                ->update(['seen_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
        }
    }

    public function rate(int $userId, int $messageId, int $rating, string $note = ''): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];
        if (!in_array($rating, [1, -1], true)) return ['error' => 'Avaliação inválida'];

        try {
            $message = \AfiliaFacil\Models\AgentMessage::where('id', $messageId)->where('role', 'agent')->first();
            if (!$message) return ['error' => 'Resposta não encontrada'];

            $conversation = $this->getConversation($userId, (int)$message->conversation_id);
            if (!$conversation) return ['error' => 'Sem acesso a esta resposta'];

            $message->rating = $rating;
            $message->rating_note = mb_substr(trim($note), 0, 500);
            $message->save();

            Audit::log('agent_rated', 'agent', (string)$messageId, ['rating' => $rating]);
            return ['success' => true];
        } catch (Throwable $e) {
            return ['error' => 'Falha ao avaliar: ' . $e->getMessage()];
        }
    }

    public function confirm(int $userId, int $messageId): array
    {
        if (!Database::available()) return ['error' => 'Banco de dados indisponível.'];

        $toolMessage = $this->getToolMessage($userId, $messageId);
        if (!$toolMessage) return ['error' => 'Ação não encontrada.'];
        if ($toolMessage->status !== 'pending_confirmation') return ['error' => 'Esta ação já foi processada.'];

        // Marca como em execucao e enfileira (assincrono — a acao pode ser longa)
        $toolMessage->status = 'processing';
        $toolMessage->save();

        $jobId = AgentJobs::enqueue((int)$toolMessage->conversation_id, $userId, 'tool', (int)$toolMessage->id);
        if ($jobId === null) {
            $toolMessage->status = 'pending_confirmation';
            $toolMessage->save();
            return ['error' => 'Falha ao iniciar a execução. Tente novamente.'];
        }

        return [
            'success' => true,
            'queued' => true,
            'job_id' => $jobId,
            'status' => 'processing',
            'messages' => $this->listMessages($userId, (int)$toolMessage->conversation_id),
        ];
    }

    private function processToolJob($job): array
    {
        $userId = (int)$job->user_id;
        $messageId = (int)$job->tool_message_id;
        $jobId = (int)$job->id;

        $toolMessage = \AfiliaFacil\Models\AgentMessage::find($messageId);
        if (!$toolMessage || $toolMessage->role !== 'tool') {
            AgentJobs::fail($jobId, 'Ação não encontrada');
            return ['error' => 'Ação não encontrada'];
        }

        $user = \AfiliaFacil\Models\User::with('role')->find($userId);
        if (!$user) {
            AgentJobs::fail($jobId, 'Usuário não encontrado');
            return ['error' => 'Usuário não encontrado'];
        }

        $userArray = ['id' => $userId, 'name' => $user->name, 'plan' => $user->plan];

        $access = AgentGuard::checkToolAccess($toolMessage->tool_name, $user->plan, $userId);
        if (!$access['allowed']) {
            $this->updateToolMessage($messageId, 'failed', ['success' => false, 'summary' => $access['reason']]);
            AgentJobs::complete($jobId);
            return ['success' => true, 'status' => 'failed'];
        }

        $result = AgentTools::execute($toolMessage->tool_name, $toolMessage->tool_args ?? [], $userArray, $userId, [
            'is_subagent' => !empty(\AfiliaFacil\Models\AgentConversation::find($toolMessage->conversation_id)->subagent_id ?? null),
        ]);
        $status = !empty($result['success']) ? 'executed' : 'failed';
        $this->updateToolMessage($messageId, $status, $result);

        Audit::log('agent_tool_' . $status, 'agent', (string)$messageId, ['tool' => $toolMessage->tool_name]);

        $this->commentOnResult($userId, (int)$toolMessage->conversation_id, $toolMessage->tool_name, $result);

        AgentJobs::complete($jobId);
        return ['success' => true, 'status' => $status, 'result' => $result];
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
                $greeting = 'Oi! Sou o ' . $subagent['name'] . ($subagent['specialty'] !== '' ? ' - especialista em ' . $subagent['specialty'] : '') . '. Mantenho os princípios do Sócio de IA (sem promessas de ganho, sempre te protegendo). Como posso ajudar?';
                $options = [];
            } else {
                $profile = AgentProfile::forUser($userId);
                $niche = trim((string)($profile['niche'] ?? ''));

                if ($niche !== '') {
                    // Ja conhece o nicho: nao repete a pergunta, oferece acao
                    $label = OfferManager::nicheLabel($niche);
                    $greeting = "Oi de novo! Sou seu Sócio de IA — foco total em te fazer ganhar dinheiro com afiliação.\n\nVi que você atua com {$label}. Quer que eu busque ofertas validadas desse nicho agora?";
                    $options = [
                        'Buscar ofertas de ' . $label,
                        'Ver anúncios ativos',
                        'Trocar de nicho',
                    ];
                } else {
                    // Primeira conversa: descobre o NICHO logo de cara (chips dinamicos do swipe file)
                    $greeting = "Oi! Sou seu Sócio de IA — foco total em te fazer ganhar dinheiro com afiliação.\n\nPara eu trabalhar direito desde o começo: qual nicho você quer atuar?";
                    $options = [];
                    foreach (OfferManager::topNiches(5) as $n) {
                        $options[] = $n['label'];
                    }
                    if (empty($options)) {
                        $options = ['Emagrecimento', 'Finanças', 'Relacionamento', 'Espiritualidade'];
                    }
                    $options[] = 'Quero sugestões';
                    $options[] = 'Outro';
                }
            }

            if (!empty($options)) {
                $this->saveMessage((int)$conversation->id, 'agent', $greeting, '', null, null, 'question', ['options' => $options]);
            } else {
                $this->saveMessage((int)$conversation->id, 'agent', $greeting);
            }

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
                'rating' => $m->rating !== null ? (int)$m->rating : null,
                'rating_note' => $m->rating_note ?? '',
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

            $conversation = \AfiliaFacil\Models\AgentConversation::find($conversationId);
            $isSubagent = !empty($conversation->subagent_id);
            $subagentTools = null;
            if ($isSubagent) {
                $subagent = AgentSubagents::get($userId, (int)$conversation->subagent_id);
                $subagentTools = $subagent['tools'] ?? null;
            }

            // Permissoes do cliente: subagente usa a allowlist do subagente; o Socio usa a do usuario.
            if ($isSubagent) {
                if (is_array($subagentTools) && !empty($subagentTools) && !in_array($tool, $subagentTools, true) && $tool !== 'consultar_quotas') {
                    $this->saveMessage($conversationId, 'agent', 'Não tenho permissão para usar essa ferramenta neste subagente. O cliente pode liberar em Subagentes > Editar.');
                    return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
                }
            } elseif (!AgentPermissions::allows($userId, $tool)) {
                $this->saveMessage($conversationId, 'agent', 'Não tenho permissão para usar essa ferramenta — o cliente pode liberar em Permissões do Sócio.');
                return ['success' => true, 'quota' => AgentQuota::check($userId, $plan)];
            }

            // LEITURA e PESQUISA executam automaticamente (sem confirmacao do usuario).
            if (AgentPermissions::isReading($tool)) {
                $messageId = $this->saveMessage($conversationId, 'tool', $reason, $tool, $args, null, 'processing');
                $user = \AfiliaFacil\Models\User::find($userId);
                $userArray = ['id' => $userId, 'name' => $user->name ?? '', 'plan' => $plan, 'email' => $user->email ?? ''];

                $result = AgentTools::execute($tool, $args, $userArray, $userId, ['is_subagent' => $isSubagent]);
                $status = !empty($result['success']) ? 'executed' : 'failed';
                $this->updateToolMessage($messageId, $status, $result);

                Audit::log('agent_tool_' . $status, 'agent', (string)$messageId, ['tool' => $tool, 'auto' => true]);
                $this->commentOnResult($userId, $conversationId, $tool, $result);

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

    /**
     * Chamada de IA com retry imediato (instabilidade transitoria do provider
     * nao deve virar mensagem de erro definitiva para o usuario).
     */
    private function chatWithRetry(array $messages, array $config, int $attempts = 3): ?string
    {
        for ($i = 1; $i <= $attempts; $i++) {
            $response = AiClient::chat($messages, $config);
            if ($response !== null) return $response;
            if ($i < $attempts) usleep(2000000);
        }

        return null;
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

        $commentary = $this->chatWithRetry($messages, $config, 2);
        if ($commentary === null) return null;

        // O modelo as vezes responde em JSON ({"type":"message","content":"..."}) mesmo pedindo texto:
        // extrai apenas o conteudo para nao vazar JSON cru no chat.
        $parsedComment = $this->parseResponse($commentary);
        if (is_array($parsedComment) && !empty($parsedComment['content'])) {
            $commentary = (string)$parsedComment['content'];
        }

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
        $allowedTools = $isSubagent ? null : AgentPermissions::allowedTools($userId);
        foreach (AgentTools::definitions() as $tool) {
            if ($isSubagent && in_array($tool['name'], ['criar_subagente', 'delegar_subagente'], true)) {
                continue;
            }
            if ($isSubagent && !empty($subagent['tools']) && !in_array($tool['name'], $subagent['tools'], true) && !in_array($tool['name'], ['consultar_quotas'], true)) {
                continue;
            }
            if ($allowedTools !== null && !in_array($tool['name'], $allowedTools, true)) {
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

        // Onboarding: usuario acabou de responder a pergunta de NICHO (clicou numa opcao) -> forca a
        // acao imediata (a IA tende a apenas prometer a busca). NUNCA se aplica a pedidos livres.
        $profileData = AgentProfile::forUser($userId);
        $nicheNow = trim((string)($profileData['niche'] ?? ''));
        if (!$isSubagent && $nicheNow !== '' && $this->isOnboardingReply($conversationId, $message)) {
            $context .= "\n\nATENÇÃO (ONBOARDING): o usuário acabou de informar o nicho \"{$nicheNow}\". Sua PRÓXIMA resposta DEVE ser IMEDIATAMENTE um tool_call de listar_ofertas com q=\"{$nicheNow}\" (é leitura e executa sozinha, sem confirmação). NÃO escreva texto prometendo busca — EXECUTE AGORA.";
        }

        $system = $isSubagent ? AgentPrompts::subagent($subagent) : AgentPrompts::base();

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $context],
        ];
    }

    /**
     * A mensagem do usuario e exatamente uma das opcoes do ultimo greeting de onboarding?
     * (usado para forcar a busca do nicho sem interferir em pedidos livres).
     */
    private function isOnboardingReply(int $conversationId, string $message): bool
    {
        try {
            $lastAgent = \AfiliaFacil\Models\AgentMessage::where('conversation_id', $conversationId)
                ->where('role', 'agent')
                ->orderByDesc('id')
                ->first();
            if (!$lastAgent || ($lastAgent->status ?? '') !== 'question') return false;

            $options = $lastAgent->tool_args['options'] ?? [];
            if (!is_array($options) || empty($options)) return false;

            $needle = mb_strtolower(trim($message));
            foreach ($options as $option) {
                if (mb_strtolower(trim((string)$option)) === $needle) return true;
            }
        } catch (Throwable $e) {
        }

        return false;
    }

    private function parseResponse(string $raw): ?array
    {
        $text = trim($raw);

        // Remove cercas de código markdown (```json ... ```)
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        if (!preg_match('/\{[\s\S]*\}/', $text, $m)) {
            return null;
        }

        $jsonText = $m[0];

        // 1) Tentativa direta
        $decoded = json_decode($jsonText, true);
        if (is_array($decoded) && isset($decoded['type'])) {
            return $decoded;
        }

        // 2) Reparo: quebras de linha literais dentro de strings viram \n (JSON válido)
        $repaired = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"/s', function ($match) {
            return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $match[0]);
        }, $jsonText);

        if (is_string($repaired)) {
            $decoded = json_decode($repaired, true);
            if (is_array($decoded) && isset($decoded['type'])) {
                return $decoded;
            }
        }

        // 3) Último recurso: extrai campos manualmente de um JSON malformado
        $type = null;
        if (preg_match('/"type"\s*:\s*"([a-z_]+)"/i', $jsonText, $tm)) {
            $type = $tm[1];
        }
        if ($type === null) {
            return null;
        }

        $parsed = ['type' => $type];
        if (preg_match('/"content"\s*:\s*"(.*?)"\s*(?:,\s*"(?:options|profile_update|tool|args|reason)"|})/s', $jsonText, $cm)) {
            $parsed['content'] = stripcslashes($cm[1]);
        }
        if (preg_match('/"tool"\s*:\s*"([a-z_]+)"/i', $jsonText, $toolMatch)) {
            $parsed['tool'] = $toolMatch[1];
        }
        if (preg_match('/"reason"\s*:\s*"(.*?)"\s*(?:,\s*"(?:tool|args|profile_update)"|})/s', $jsonText, $rm)) {
            $parsed['reason'] = stripcslashes($rm[1]);
        }
        if (preg_match('/"args"\s*:\s*(\{[\s\S]*?\})\s*(?:,\s*"[a-z_]+"|})/i', $jsonText, $am)) {
            $args = json_decode($am[1], true);
            if (is_array($args)) $parsed['args'] = $args;
        }
        if (preg_match('/"options"\s*:\s*\[([\s\S]*?)\]/i', $jsonText, $om)) {
            $options = json_decode('[' . $om[1] . ']', true);
            if (is_array($options)) $parsed['options'] = array_values(array_filter($options, 'is_string'));
        }

        return isset($parsed['content']) || isset($parsed['tool']) ? $parsed : null;
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
