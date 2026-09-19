<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Agent/AgentMonitor.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Auth::can('manage_ai') && !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para monitorar a IA']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Exportacao/listagem podem ser longas: libera o lock da sessao
session_write_close();

switch ($action) {
    case 'list':
        $result = AgentMonitor::conversations([
            'kind' => (string)($_GET['kind'] ?? ''),
            'rating' => (string)($_GET['rating'] ?? ''),
            'days' => (int)($_GET['days'] ?? 0),
            'q' => trim((string)($_GET['q'] ?? '')),
        ], min(120, max(1, (int)($_GET['limit'] ?? 60))));
        $result['success'] = true;
        $result['stats'] = AgentMonitor::stats();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'conversation':
        $conversation = AgentMonitor::getConversation((int)($_GET['id'] ?? 0));
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversa não encontrada']);
            break;
        }
        echo json_encode(['success' => true, 'conversation' => $conversation], JSON_UNESCAPED_UNICODE);
        break;

    case 'export':
        header('Content-Type: application/x-ndjson; charset=UTF-8');
        header('Content-Disposition: attachment; filename="agente-dataset-' . date('Ymd-His') . '.jsonl"');
        echo AgentMonitor::exportJsonl();
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
