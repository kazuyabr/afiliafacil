<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/R2Storage.php';
require_once Config::getLibDir() . '/Audit.php';

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

        Audit::log('page_deleted', 'page', (string)$id, ['name' => $page['name'] ?? '']);
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
        foreach (['meta_pixel_id', 'google_conversion_id', 'google_conversion_label', 'tiktok_pixel_id', 'capi_test_code'] as $f) {
            if (isset($_POST[$f])) $data[$f] = trim((string)$_POST[$f]);
        }
        // Token CAPI: vazio mantém o existente (o valor real nunca é exibido)
        if (isset($_POST['meta_capi_token']) && trim((string)$_POST['meta_capi_token']) !== '') {
            $data['meta_capi_token'] = Crypto::encrypt(trim((string)$_POST['meta_capi_token']));
        }

        $page = $pm->update($id, $data);
        if ($page) {
            echo json_encode(['success' => true, 'page' => $page]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
        }
        break;

    case 'test-capi':
        $id = (int)($_POST['id'] ?? 0);
        $pm = new PageManager();
        $page = $pm->get($id);
        if (!$page) {
            http_response_code(404);
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        Auth::requirePageAccess($page);
        require_once Config::getLibDir() . '/Tracking/ConversionsApi.php';
        $secrets = $pm->trackingSecrets($id);
        if ($secrets['pixel_id'] === '' || $secrets['capi_token'] === '') {
            echo json_encode(['error' => 'Configure o Pixel ID e o token da API de Conversão primeiro.']);
            break;
        }
        if ($secrets['test_code'] === '') {
            echo json_encode(['error' => 'Configure o código de teste (Events Manager → Test Events) para testar sem poluir os dados.']);
            break;
        }
        $result = ConversionsApi::send($secrets['pixel_id'], $secrets['capi_token'], 'PageView', [], ['test_code' => $secrets['test_code']]);
        Audit::log('capi_tested', 'page', (string)$id, ['success' => !empty($result['success'])]);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
