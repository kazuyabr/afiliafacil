<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/AdSpy/SteelBrowser.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Auth::can('manage_settings')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'steel-test':
        $url = rtrim(trim((string)($_GET['url'] ?? '')), '/');
        if ($url === '') {
            echo json_encode(['ok' => false, 'error' => 'URL vazia']);
            break;
        }
        $ch = curl_init($url . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status < 400) {
            echo json_encode(['ok' => true, 'message' => 'Steel Browser ativo (HTTP ' . $status . ')']);
        } elseif ($body !== false) {
            echo json_encode(['ok' => false, 'error' => 'HTTP ' . $status]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Sem conexão: ' . $err]);
        }
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
