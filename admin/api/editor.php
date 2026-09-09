<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Plans.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$plan = $user['plan'];

if (!Plans::hasFeature($plan, 'editor')) {
    http_response_code(403);
    echo json_encode(['error' => 'O editor de código está disponível nos planos Essencial e Master. Faça upgrade em "Meu Plano".']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function getPageOrFail(int $id): array
{
    $pm = new PageManager();
    $page = $pm->get($id);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Página não encontrada']);
        exit;
    }
    return $page;
}

function revisionsDir(int $id): string
{
    $dir = Config::getPagesDir() . '/' . $id . '/revisions';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

switch ($action) {
    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $page = getPageOrFail($id);
        $revDir = revisionsDir($id);
        $revisions = array_map(function ($f) {
            return ['file' => basename($f), 'date' => date('Y-m-d H:i:s', filemtime($f))];
        }, array_reverse(glob($revDir . '/*.html')));
        echo json_encode([
            'success' => true,
            'page' => [
                'id' => $page['id'],
                'name' => $page['name'],
                'html' => $page['html'] ?? '',
            ],
            'revisions' => $revisions,
        ]);
        break;

    case 'save':
        $id = (int)($_POST['id'] ?? 0);
        $html = $_POST['html'] ?? '';
        $page = getPageOrFail($id);

        if (empty($html)) {
            echo json_encode(['error' => 'HTML vazio não permitido']);
            break;
        }
        if (strlen($html) > 5_000_000) {
            echo json_encode(['error' => 'HTML muito grande (máx 5MB)']);
            break;
        }

        $revDir = revisionsDir($id);
        file_put_contents($revDir . '/' . date('Ymd_His') . '.html', $page['html'] ?? '');

        $pm = new PageManager();
        $pm->update($id, ['html' => $html]);

        echo json_encode(['success' => true, 'id' => $id, 'saved_at' => date('Y-m-d H:i:s')]);
        break;

    case 'restore':
        $id = (int)($_GET['id'] ?? 0);
        $rev = basename((string)($_GET['rev'] ?? ''));
        $page = getPageOrFail($id);

        $revFile = revisionsDir($id) . '/' . $rev;
        if (!preg_match('/^\d{8}_\d{6}\.html$/', $rev) || !file_exists($revFile)) {
            echo json_encode(['error' => 'Revisão inválida']);
            break;
        }

        $revDir = revisionsDir($id);
        file_put_contents($revDir . '/' . date('Ymd_His') . '.html', $page['html'] ?? '');

        $pm = new PageManager();
        $pm->update($id, ['html' => file_get_contents($revFile)]);

        echo json_encode(['success' => true, 'id' => $id]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
