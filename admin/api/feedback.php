<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Feedback.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'send':
        $context = [
            'plan' => $user['plan'],
            'page' => mb_substr(trim((string)($_POST['page'] ?? '')), 0, 190),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 190),
        ];
        $result = Feedback::send($userId, (string)($_POST['type'] ?? 'sugestao'), (string)($_POST['message'] ?? ''), $context);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'list':
        if ($isAdmin && ($_GET['all'] ?? '') === '1') {
            $result = Feedback::listAll([
                'type' => (string)($_GET['type'] ?? ''),
                'status' => (string)($_GET['status'] ?? ''),
                'plan' => (string)($_GET['plan'] ?? ''),
            ]);
            $result['success'] = true;
            $result['stats'] = Feedback::stats();
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['success' => true, 'items' => Feedback::listForUser($userId)], JSON_UNESCAPED_UNICODE);
        break;

    case 'reply':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        echo json_encode(Feedback::reply((int)($_POST['id'] ?? 0), $userId, (string)($_POST['reply'] ?? '')), JSON_UNESCAPED_UNICODE);
        break;

    case 'status':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        echo json_encode(Feedback::setStatus((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? '')), JSON_UNESCAPED_UNICODE);
        break;

    case 'export':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';

        if ($format === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="feedback-' . date('Ymd-His') . '.json"');
            echo Feedback::exportJson();
        } else {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="feedback-' . date('Ymd-His') . '.csv"');
            echo Feedback::exportCsv();
        }
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
