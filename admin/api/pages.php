<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/R2Storage.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int)(Auth::user()['id'] ?? 0);
$isAdmin = Auth::isAdmin();

switch ($action) {
    case 'list':
        $pm = new PageManager();
        $pages = $isAdmin ? $pm->list() : $pm->listByUser($userId);
        echo json_encode(['success' => true, 'pages' => $pages]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $pm = new PageManager();
        $page = $pm->get($id);
        if (!$page) {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        Auth::requirePageAccess($page);
        echo json_encode(['success' => true, 'page' => $page]);
        break;

    case 'delete':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'ID inválido']);
            break;
        }
        $pm = new PageManager();
        $page = $pm->get($id);

        if (!$page) {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        Auth::requirePageAccess($page);

        if (Database::available()) {
            try {
                $storage = \AfiliaFacil\Models\StorageConfig::where('user_id', (int)$page['user_id'])->first();
                if ($storage && $storage->enabled && $storage->media_mode === 'r2') {
                    $secret = Crypto::decrypt($storage->secret_encrypted ?? '') ?? '';
                    if ($secret !== '') {
                        $r2 = new R2Storage([
                            'account_id' => $storage->account_id,
                            'access_key' => $storage->access_key,
                            'secret_key' => $secret,
                            'bucket' => $storage->bucket,
                            'public_url' => $storage->public_url,
                        ]);
                        if ($r2->isConfigured()) $r2->deletePrefix('clones/' . $id . '/');
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $pm->delete($id);
        echo json_encode(['success' => true]);
        break;

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $pm = new PageManager();
        $existing = $pm->get($id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        Auth::requirePageAccess($existing);

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
