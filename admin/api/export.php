<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/Training/TrainingCollector.php';

if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$action = $_GET['action'] ?? '';
$format = ($_GET['format'] ?? 'md') === 'jsonl' ? 'jsonl' : 'md';

switch ($action) {
    case 'my-data':
        if (!Plans::hasFeature($user['plan'], 'agent') && !Auth::isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Download dos seus dados disponível nos planos Afiliado Pro, Master Elite e Admin.']);
            break;
        }

        $export = TrainingCollector::exportUserData($userId, $format);
        Audit::log('data_exported', 'user', (string)$userId, ['format' => $format]);

        header('Content-Type: ' . ($format === 'jsonl' ? 'application/x-ndjson' : 'text/markdown') . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
        echo $export['content'];
        break;

    default:
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Ação inválida']);
}
