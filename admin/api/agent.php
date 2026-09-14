<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Agent/Agent.php';
require_once Config::getLibDir() . '/Agent/AgentQuota.php';
require_once Config::getLibDir() . '/Agent/AgentProfile.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();

if (!Plans::hasFeature($user['plan'], 'agent') && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Seu plano não inclui o Sócio (agente).']);
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

    case 'conversations':
        echo json_encode(['success' => true, 'conversations' => $agent->listConversations($userId)]);
        break;

    case 'conversation':
        $id = (int)($_GET['id'] ?? 0);
        $conversation = $agent->getConversation($userId, $id);
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversa não encontrada']);
            break;
        }
        echo json_encode([
            'success' => true,
            'conversation' => ['id' => (int)$conversation->id, 'title' => $conversation->title],
            'messages' => $agent->listMessages($userId, $id),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'new':
        $result = $agent->newConversation($userId);
        echo json_encode($result);
        break;

    case 'send':
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $message = (string)($_POST['message'] ?? '');

        if ($conversationId <= 0) {
            $created = $agent->newConversation($userId);
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
            'conversation_id' => $conversationId,
            'messages' => $agent->listMessages($userId, $conversationId),
            'quota' => $result['quota'] ?? AgentQuota::check($userId, $user['plan']),
        ], JSON_UNESCAPED_UNICODE);
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
