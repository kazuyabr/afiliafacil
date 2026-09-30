<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Flows/FlowRunner.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$plan = (string)$user['plan'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Operacoes longas liberam o lock da sessao (padrao das APIs do modulo social)
session_write_close();

$hasFeature = Plans::hasFeature($plan, 'social');
$requireFeature = function () use ($hasFeature) {
    if (!$hasFeature) {
        http_response_code(403);
        echo json_encode(['error' => 'Seu plano não inclui automações sociais. Faça upgrade para usar.']);
        return false;
    }
    return true;
};

/** Le JSON do body (create) ou campos POST (compat). */
$readInput = function (): array {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return $_POST;
};

switch ($action) {
    case 'list': {
        echo json_encode([
            'success' => true,
            'has_feature' => $hasFeature,
            'flows' => FlowRunner::list($userId),
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'create': {
        if (!$requireFeature()) break;
        $res = FlowRunner::create($userId, $readInput());
        if (empty($res['ok'])) http_response_code(400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'toggle': {
        if (!$requireFeature()) break;
        $id = (int)($_POST['id'] ?? 0);
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $res = FlowRunner::toggle($userId, $id, $enabled);
        if (empty($res['ok'])) http_response_code(400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'delete': {
        if (!$requireFeature()) break;
        $id = (int)($_POST['id'] ?? 0);
        $ok = FlowRunner::delete($userId, $id);
        if (!$ok) http_response_code(400);
        echo json_encode(['success' => $ok, 'id' => $id,
            'error' => $ok ? null : 'Fluxo não encontrado.'], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'runs': {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        echo json_encode([
            'success' => true,
            'runs' => FlowRunner::runs($userId, $id),
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'run-now': {
        // Executa na hora (teste/debug do fluxo pelo usuario)
        if (!$requireFeature()) break;
        $id = (int)($_POST['id'] ?? 0);
        $flow = \AfiliaFacil\Models\Flow::where('id', $id)->where('user_id', $userId)->first();
        if (!$flow) {
            http_response_code(404);
            echo json_encode(['error' => 'Fluxo não encontrado.']);
            break;
        }
        $res = FlowRunner::run($flow, 'manual');
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'process': {
        // Polling da tela: roda fluxos schedule vencidos (mesmo padrao do social)
        if (!$requireFeature()) break;
        echo json_encode(['success' => true, 'due' => FlowRunner::runDue(10)], JSON_UNESCAPED_UNICODE);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida']);
    }
}
