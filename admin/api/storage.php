<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/R2Storage.php';
require_once Config::getLibDir() . '/MediaOptimizer.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Audit.php';

use AfiliaFacil\Models\StorageConfig;

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Database::available()) {
    http_response_code(500);
    echo json_encode(['error' => 'Banco de dados indisponível']);
    exit;
}

$userId = (int)Auth::user()['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function storageConfigToArray(?StorageConfig $config): array
{
    if (!$config) {
        return [
            'provider' => 'r2',
            'account_id' => '',
            'access_key' => '',
            'has_secret' => false,
            'bucket' => '',
            'public_url' => '',
            'media_mode' => 'base64',
            'enabled' => false,
        ];
    }
    return [
        'provider' => $config->provider,
        'account_id' => $config->account_id,
        'access_key' => $config->access_key,
        'has_secret' => !empty($config->secret_encrypted),
        'bucket' => $config->bucket,
        'public_url' => $config->public_url,
        'media_mode' => $config->media_mode ?: 'base64',
        'enabled' => (bool)$config->enabled,
    ];
}

function buildStorageFromPost(array $post, ?StorageConfig $existing): ?R2Storage
{
    $secret = trim($post['secret_key'] ?? '');
    if ($secret === '' && $existing) {
        $secret = Crypto::decrypt($existing->secret_encrypted ?? '') ?? '';
    }

    $config = [
        'account_id' => trim($post['account_id'] ?? ''),
        'access_key' => trim($post['access_key'] ?? ''),
        'secret_key' => $secret,
        'bucket' => trim($post['bucket'] ?? ''),
        'public_url' => trim($post['public_url'] ?? ''),
    ];

    return new R2Storage($config);
}

switch ($action) {
    case 'get':
        $config = StorageConfig::where('user_id', $userId)->first();
        echo json_encode(['success' => true, 'config' => storageConfigToArray($config)]);
        break;

    case 'save':
        $config = StorageConfig::where('user_id', $userId)->first();
        $secret = trim($_POST['secret_key'] ?? '');

        $data = [
            'provider' => 'r2',
            'account_id' => trim($_POST['account_id'] ?? ''),
            'access_key' => trim($_POST['access_key'] ?? ''),
            'bucket' => trim($_POST['bucket'] ?? ''),
            'public_url' => rtrim(trim($_POST['public_url'] ?? ''), '/'),
            'media_mode' => in_array($_POST['media_mode'] ?? '', ['base64', 'r2', 'original'], true) ? $_POST['media_mode'] : 'base64',
            'enabled' => !empty($_POST['enabled']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($secret !== '') {
            $data['secret_encrypted'] = Crypto::encrypt($secret);
        }

        if ($config) {
            $config->fill($data);
            $config->save();
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = date('Y-m-d H:i:s');
            StorageConfig::create($data);
        }

        Audit::log('storage_saved', 'storage', (string)$userId, ['media_mode' => $data['media_mode'], 'enabled' => $data['enabled']]);
        echo json_encode(['success' => true]);
        break;

    case 'test':
        $config = StorageConfig::where('user_id', $userId)->first();
        $r2 = buildStorageFromPost($_POST, $config);
        echo json_encode($r2->testConnection());
        break;

    case 'optimize':
        $pageId = (int)($_POST['id'] ?? 0);
        $config = StorageConfig::where('user_id', $userId)->first();

        if (!$config || !$config->enabled || $config->media_mode !== 'r2') {
            echo json_encode(['error' => 'Configure e ative o R2 (modo R2) antes de otimizar']);
            break;
        }

        $secret = Crypto::decrypt($config->secret_encrypted ?? '') ?? '';
        $r2 = new R2Storage([
            'account_id' => $config->account_id,
            'access_key' => $config->access_key,
            'secret_key' => $secret,
            'bucket' => $config->bucket,
            'public_url' => $config->public_url,
        ]);
        if (!$r2->isConfigured()) {
            echo json_encode(['error' => 'Configuração R2 incompleta']);
            break;
        }

        $pm = new PageManager();
        $page = $pm->get($pageId);
        if (!$page) {
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        if ((int)$page['user_id'] !== $userId && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem acesso a esta página']);
            break;
        }

        $html = $page['html'] ?? '';
        if ($html === '') {
            echo json_encode(['error' => 'Página sem HTML']);
            break;
        }

        $revDir = Config::getPagesDir() . '/' . $pageId . '/revisions';
        if (!is_dir($revDir)) mkdir($revDir, 0777, true);
        file_put_contents($revDir . '/' . date('Ymd_His') . '.html', $html);

        $result = MediaOptimizer::optimize($html, $r2, 'clones/' . $pageId);
        $pm->update($pageId, ['html' => $result['html']]);

        echo json_encode([
            'success' => true,
            'optimized' => $result['count'],
            'saved_bytes' => $result['saved_bytes'],
            'new_size' => strlen($result['html']),
        ]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
