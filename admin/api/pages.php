<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        $pm = new PageManager();
        echo json_encode(['success' => true, 'pages' => $pm->list()]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $pm = new PageManager();
        $page = $pm->get($id);
        if ($page) {
            echo json_encode(['success' => true, 'page' => $page]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
        }
        break;

    case 'delete':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'ID inválido']);
            break;
        }
        $pm = new PageManager();
        $pm->delete($id);
        echo json_encode(['success' => true]);
        break;

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $pm = new PageManager();
        $data = [];
        if (isset($_POST['name'])) $data['name'] = $_POST['name'];
        if (isset($_POST['status'])) $data['status'] = $_POST['status'];
        if (isset($_POST['domain'])) $data['domain'] = $_POST['domain'];
        if (isset($_POST['html'])) $data['html'] = $_POST['html'];
        if (isset($_POST['affiliate_link'])) $data['affiliate_link'] = $_POST['affiliate_link'];

        $page = $pm->update($id, $data);
        if ($page) {
            echo json_encode(['success' => true, 'page' => $page]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
        }
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
