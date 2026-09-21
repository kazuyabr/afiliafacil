<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Agent/Agent.php';
require_once Config::getLibDir() . '/Agent/AgentQuota.php';
require_once Config::getLibDir() . '/Agent/AgentProfile.php';
require_once Config::getLibDir() . '/Agent/AgentSubagents.php';
require_once Config::getLibDir() . '/Agent/AgentPermissions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();

// Libera o lock da sessao antes de operacoes longas (IA em background):
// sem isso, qualquer outra pagina do mesmo usuario fica bloqueada esperando o lock.
session_write_close();

if (!Plans::hasFeature($user['plan'], 'agent') && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Seu plano não inclui o Sócio de IA (agente).']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$agent = new Agent();

switch ($action) {
    case 'quota':
        echo json_encode([
            'success' => true,
            'quota' => AgentQuota::check($userId, $user['plan']),
            'profile' => AgentProfile::forUser($userId),
        ]);
        break;

    case 'profile':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!empty($_POST['clear'])) {
                AgentProfile::clear($userId);
                echo json_encode(['success' => true, 'profile' => AgentProfile::forUser($userId)]);
                break;
            }
            $data = [];
            foreach (['niche', 'budget', 'experience'] as $field) {
                if (isset($_POST[$field])) $data[$field] = (string)$_POST[$field];
            }
            if (isset($_POST['goals']) && is_array($_POST['goals'])) $data['goals'] = $_POST['goals'];
            AgentProfile::update($userId, $data);
            echo json_encode(['success' => true, 'profile' => AgentProfile::forUser($userId)]);
            break;
        }
        echo json_encode(['success' => true, 'profile' => AgentProfile::forUser($userId)]);
        break;

    case 'permissions':
        echo json_encode([
            'success' => true,
            'allowed_tools' => AgentPermissions::allowedTools($userId),
            'all_tools' => AgentPermissions::all(),
            'reading_tools' => AgentTools::LEITURA,
            'sensitive_tools' => AgentPermissions::sensitive(),
            'descriptions' => array_column(AgentTools::definitions(), 'desc', 'name'),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'save-permissions':
        $tools = $_POST['tools'] ?? [];
        if (is_string($tools)) $tools = json_decode($tools, true) ?: [];
        $result = AgentPermissions::save($userId, is_array($tools) ? $tools : []);
        echo json_encode(array_merge(['success' => empty($result['error'])], $result), JSON_UNESCAPED_UNICODE);
        break;

    case 'conversations':
        echo json_encode(['success' => true, 'conversations' => $agent->listConversations($userId)]);
        break;

    case 'subagents':
        echo json_encode([
            'success' => true,
            'subagents' => AgentSubagents::list($userId),
            'quota' => AgentSubagents::quota($userId, $user['plan']),
            'templates' => AgentSubagents::TEMPLATES,
            'available_tools' => array_column(AgentTools::definitions(), 'name'),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'subagent':
        $id = (int)($_GET['id'] ?? 0);
        $subagent = AgentSubagents::get($userId, $id);
        if (!$subagent) {
            http_response_code(404);
            echo json_encode(['error' => 'Subagente não encontrado']);
            break;
        }
        echo json_encode(['success' => true, 'subagent' => $subagent], JSON_UNESCAPED_UNICODE);
        break;

    case 'subagent-save':
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => (string)($_POST['name'] ?? ''),
            'specialty' => (string)($_POST['specialty'] ?? ''),
            'instructions' => (string)($_POST['instructions'] ?? ''),
            'tools' => $_POST['tools'] ?? [],
            'active' => !empty($_POST['active']),
        ];

        if ($id > 0) {
            $result = AgentSubagents::update($userId, $id, $data);
        } else {
            $quota = AgentSubagents::quota($userId, $user['plan']);
            if (!$quota['allowed']) {
                echo json_encode(['error' => 'Limite de subagentes do plano atingido (' . $quota['used'] . '/' . $quota['limit'] . ').']);
                break;
            }
            $result = AgentSubagents::create($userId, $data, 'user');
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'subagent-toggle':
        $id = (int)($_POST['id'] ?? 0);
        echo json_encode(AgentSubagents::toggle($userId, $id));
        break;

    case 'subagent-delete':
        $id = (int)($_POST['id'] ?? 0);
        echo json_encode(AgentSubagents::delete($userId, $id));
        break;

    case 'conversation':
        $id = (int)($_GET['id'] ?? 0);
        $conversation = $agent->getConversation($userId, $id);
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversa não encontrada']);
            break;
        }
        $subagentName = '';
        if (!empty($conversation->subagent_id)) {
            $subagent = AgentSubagents::get($userId, (int)$conversation->subagent_id);
            $subagentName = $subagent['name'] ?? '';
        }
        echo json_encode([
            'success' => true,
            'conversation' => [
                'id' => (int)$conversation->id,
                'title' => $conversation->title,
                'subagent_id' => $conversation->subagent_id ? (int)$conversation->subagent_id : null,
                'subagent_name' => $subagentName,
            ],
            'messages' => $agent->listMessages($userId, $id),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'new':
        $subagentId = (int)($_POST['subagent_id'] ?? 0);
        $result = $agent->newConversation($userId, $subagentId);
        echo json_encode($result);
        break;

    case 'send':
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $message = (string)($_POST['message'] ?? '');
        $subagentId = (int)($_POST['subagent_id'] ?? 0);

        if ($conversationId <= 0) {
            $created = $agent->newConversation($userId, $subagentId);
            if (!empty($created['conversation_id'])) {
                $conversationId = (int)$created['conversation_id'];
            }
        }

        $result = $agent->send($userId, $user['plan'], $conversationId, $message);
        if (isset($result['error'])) {
            echo json_encode($result);
            break;
        }

        echo json_encode([
            'success' => true,
            'blocked' => !empty($result['blocked']),
            'queued' => !empty($result['queued']),
            'job_id' => $result['job_id'] ?? null,
            'conversation_id' => $conversationId,
            'messages' => $agent->listMessages($userId, $conversationId),
            'quota' => $result['quota'] ?? AgentQuota::check($userId, $user['plan']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'process':
        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) { echo json_encode(['error' => 'Job inválido']); break; }
        echo json_encode($agent->processJob($jobId), JSON_UNESCAPED_UNICODE);
        break;

    case 'job-status':
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) { echo json_encode(['error' => 'Conversa inválida']); break; }
        $conversation = $agent->getConversation($userId, $conversationId);
        if (!$conversation) { echo json_encode(['error' => 'Conversa não encontrada']); break; }
        echo json_encode([
            'success' => true,
            'job' => \AgentJobs::lastForConversation($conversationId),
            'messages' => $agent->listMessages($userId, $conversationId),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'notifications':
        echo json_encode(['success' => true] + $agent->notifications($userId), JSON_UNESCAPED_UNICODE);
        break;

    case 'mark-seen':
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $agent->markSeen($userId, $conversationId);
        echo json_encode(['success' => true]);
        break;

    case 'rate':
        echo json_encode($agent->rate(
            $userId,
            (int)($_POST['message_id'] ?? 0),
            (int)($_POST['rating'] ?? 0),
            (string)($_POST['note'] ?? '')
        ), JSON_UNESCAPED_UNICODE);
        break;

    case 'confirm':
        $messageId = (int)($_POST['id'] ?? 0);
        $result = $agent->confirm($userId, $messageId);
        $conversationId = 0;
        if ($messageId > 0) {
            $toolMessage = \AfiliaFacil\Models\AgentMessage::find($messageId);
            if ($toolMessage) $conversationId = (int)$toolMessage->conversation_id;
        }
        $result['messages'] = $conversationId > 0 ? $agent->listMessages($userId, $conversationId) : [];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'cancel':
        $messageId = (int)($_POST['id'] ?? 0);
        $result = $agent->cancel($userId, $messageId);
        $conversationId = 0;
        if ($messageId > 0) {
            $toolMessage = \AfiliaFacil\Models\AgentMessage::find($messageId);
            if ($toolMessage) $conversationId = (int)$toolMessage->conversation_id;
        }
        $result['messages'] = $conversationId > 0 ? $agent->listMessages($userId, $conversationId) : [];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $deleted = $agent->deleteConversation($userId, $id);
        echo json_encode($deleted ? ['success' => true] : ['error' => 'Conversa não encontrada']);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
