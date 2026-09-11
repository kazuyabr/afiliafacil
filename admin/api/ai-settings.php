<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/AdSpy/AiClient.php';
require_once Config::getLibDir() . '/AdSpy/AiConfig.php';

use AfiliaFacil\Models\UserAiConfig;

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

switch ($action) {
    case 'get':
        $config = UserAiConfig::where('user_id', $userId)->first();
        echo json_encode([
            'success' => true,
            'config' => [
                'provider' => $config->provider ?? 'cloudflare',
                'model' => $config->model ?? '',
                'base_url' => $config->base_url ?? '',
                'has_key' => !empty($config->api_key_encrypted),
                'enabled' => (bool)($config->enabled ?? false),
            ],
            'platform_configured' => AiConfig::isPlatformConfigured(),
            'platform_model' => getenv('CF_AI_MODEL') ?: '@cf/zai-org/glm-4.7-flash',
        ]);
        break;

    case 'save':
        $config = UserAiConfig::where('user_id', $userId)->first();

        $data = [
            'provider' => trim($_POST['provider'] ?? 'cloudflare'),
            'model' => trim($_POST['model'] ?? ''),
            'base_url' => rtrim(trim($_POST['base_url'] ?? ''), '/'),
            'enabled' => !empty($_POST['enabled']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $key = trim($_POST['api_key'] ?? '');
        if ($key !== '') {
            $data['api_key_encrypted'] = Crypto::encrypt($key);
        }

        if ($config) {
            $config->fill($data);
            $config->save();
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = date('Y-m-d H:i:s');
            UserAiConfig::create($data);
        }

        Audit::log('ai_config_saved', 'user', (string)$userId, ['provider' => $data['provider'], 'model' => $data['model']]);
        echo json_encode(['success' => true]);
        break;

    case 'test':
        $config = AiConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            echo json_encode(['ok' => false, 'error' => 'Nenhuma chave configurada (nem BYOK, nem plataforma)']);
            break;
        }

        $response = AiClient::chat([
            ['role' => 'user', 'content' => 'Responda apenas: OK'],
        ], $config);

        if ($response === null) {
            echo json_encode(['ok' => false, 'error' => 'Falha na chamada (verifique provider/modelo/chave)']);
        } else {
            echo json_encode(['ok' => true, 'message' => 'Conexão OK com ' . $config['provider'] . ' (' . $config['model'] . ')']);
        }
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
