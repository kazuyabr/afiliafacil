<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/AdSpy/AiClient.php';
require_once Config::getLibDir() . '/AdSpy/AiConfig.php';
require_once Config::getLibDir() . '/Ai/SttConfig.php';
require_once Config::getLibDir() . '/Ai/TtsConfig.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyKeys.php';

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
$capability = in_array($_POST['capability'] ?? $_GET['capability'] ?? '', ['chat', 'stt', 'tts', 'adspy_serpapi', 'adspy_meta'], true)
    ? ($_POST['capability'] ?? $_GET['capability'])
    : 'chat';

switch ($action) {
    case 'get':
        $config = UserAiConfig::where('user_id', $userId)->where('capability', $capability)->first();
        $defaultProvider = match ($capability) {
            'adspy_serpapi' => 'serpapi',
            'adspy_meta' => 'meta',
            default => 'cloudflare',
        };
        echo json_encode([
            'success' => true,
            'capability' => $capability,
            'config' => [
                'provider' => $config->provider ?? $defaultProvider,
                'api_type' => $config->api_type ?? '',
                'model' => $config->model ?? '',
                'base_url' => $config->base_url ?? '',
                'local_ttl' => (int)($config->local_ttl ?? 0),
                'has_key' => !empty($config->api_key_encrypted),
                'enabled' => (bool)($config->enabled ?? false),
            ],
            'platform_configured' => AiConfig::isPlatformConfigured(),
            'platform_model' => getenv('CF_AI_MODEL') ?: '@cf/zai-org/glm-4.7-flash',
            'stt_platform_configured' => (getenv('CF_AI_TOKEN') ?: '') !== '' && (getenv('CF_ACCOUNT_ID') ?: '') !== '',
            'stt_default_model' => SttConfig::DEFAULT_MODELS['cloudflare'],
        ]);
        break;

    case 'save':
        $config = UserAiConfig::where('user_id', $userId)->where('capability', $capability)->first();

        $data = [
            'provider' => trim($_POST['provider'] ?? 'cloudflare'),
            'model' => trim($_POST['model'] ?? ''),
            'base_url' => rtrim(trim($_POST['base_url'] ?? ''), '/'),
            'enabled' => !empty($_POST['enabled']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($capability === 'chat') {
            $apiType = strtolower(trim((string)($_POST['api_type'] ?? '')));
            $data['api_type'] = in_array($apiType, ['openai', 'anthropic', 'google', 'azure', 'cloudflare'], true) ? $apiType : null;
            $data['local_ttl'] = max(0, min(86400, (int)($_POST['local_ttl'] ?? 0))) ?: null;
        }

        if ($capability === 'stt' && !in_array($data['provider'], SttConfig::PROVIDERS, true)) {
            $data['provider'] = 'cloudflare';
        }
        if ($capability === 'tts' && !in_array($data['provider'], TtsConfig::PROVIDERS, true)) {
            $data['provider'] = 'cloudflare';
        }
        if ($capability === 'adspy_serpapi') {
            $data['provider'] = 'serpapi';
        }
        if ($capability === 'adspy_meta') {
            $data['provider'] = 'meta';
        }

        $key = trim($_POST['api_key'] ?? '');
        if ($key !== '') {
            $data['api_key_encrypted'] = Crypto::encrypt($key);
        }

        if ($config) {
            $config->fill($data);
            $config->save();
        } else {
            $data['user_id'] = $userId;
            $data['capability'] = $capability;
            $data['created_at'] = date('Y-m-d H:i:s');
            UserAiConfig::create($data);
        }

        Audit::log('ai_config_saved', 'user', (string)$userId, ['capability' => $capability, 'provider' => $data['provider'], 'model' => $data['model']]);
        echo json_encode(['success' => true]);
        break;

    case 'test':
        if ($capability === 'adspy_serpapi' || $capability === 'adspy_meta') {
            $key = $capability === 'adspy_serpapi' ? AdSpyKeys::serpapi($userId) : AdSpyKeys::meta($userId);
            if ($key === '') {
                echo json_encode(['ok' => false, 'error' => 'Nenhuma chave configurada para este provedor']);
                break;
            }

            $url = $capability === 'adspy_serpapi'
                ? 'https://serpapi.com/account?api_key=' . urlencode($key)
                : 'https://graph.facebook.com/v21.0/me?access_token=' . urlencode($key);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $status < 400) {
                $json = json_decode($response, true);
                if ($capability === 'adspy_serpapi') {
                    $plan = $json['plan_name'] ?? 'ok';
                    $left = $json['total_searches_left'] ?? null;
                    echo json_encode(['ok' => true, 'message' => 'SerpApi conectada (' . $plan . ($left !== null ? ' — ' . $left . ' buscas restantes' : '') . ')']);
                } else {
                    $name = $json['name'] ?? 'ok';
                    echo json_encode(['ok' => true, 'message' => 'Meta conectada (' . $name . ')']);
                }
            } else {
                $json = is_string($response) ? json_decode($response, true) : null;
                $error = $json['error'] ?? ($json['error_message'] ?? ('HTTP ' . $status));
                echo json_encode(['ok' => false, 'error' => ($capability === 'adspy_serpapi' ? 'SerpApi: ' : 'Meta: ') . $error]);
            }
            break;
        }

        if ($capability === 'stt') {
            $config = SttConfig::forUser($userId);
            if (($config['api_key'] ?? '') === '') {
                echo json_encode(['ok' => false, 'error' => 'Nenhuma chave configurada (nem BYOK, nem plataforma)']);
                break;
            }
            echo json_encode(testStt($config));
            break;
        }

        if ($capability === 'tts') {
            $config = TtsConfig::forUser($userId);
            if (($config['api_key'] ?? '') === '') {
                echo json_encode(['ok' => false, 'error' => 'Nenhuma chave configurada (nem BYOK, nem plataforma)']);
                break;
            }
            echo json_encode(testStt($config));
            break;
        }

        $config = AiConfig::forUser($userId);
        $isLocal = AiClient::isLocalUrl((string)($config['base_url'] ?? ''));
        if (($config['api_key'] ?? '') === '' && !$isLocal) {
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

    case 'local-models':
        $baseUrl = trim($_GET['base_url'] ?? $_POST['base_url'] ?? '');
        echo json_encode(localModels($baseUrl), JSON_UNESCAPED_UNICODE);
        break;

    case 'local-load':
        $baseUrl = trim($_POST['base_url'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $ttl = max(0, min(86400, (int)($_POST['local_ttl'] ?? 0)));
        echo json_encode(localLoad($baseUrl, $model, $ttl), JSON_UNESCAPED_UNICODE);
        break;

    case 'local-unload':
        $baseUrl = trim($_POST['base_url'] ?? '');
        $model = trim($_POST['model'] ?? '');
        echo json_encode(localUnload($baseUrl, $model), JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}

function testStt(array $config): array
{
    $provider = $config['provider'] ?? 'cloudflare';
    $key = trim($config['api_key'] ?? '');

    [$method, $url, $headers] = match ($provider) {
        'openai' => ['GET', rtrim($config['base_url'] ?: 'https://api.openai.com/v1', '/') . '/models', ['Authorization: Bearer ' . $key]],
        'groq' => ['GET', rtrim($config['base_url'] ?: 'https://api.groq.com/openai/v1', '/') . '/models', ['Authorization: Bearer ' . $key]],
        'deepgram' => ['GET', 'https://api.deepgram.com/v1/projects', ['Authorization: Token ' . $key]],
        'assemblyai' => ['GET', 'https://api.assemblyai.com/v2/transcript?limit=1', ['Authorization: ' . $key]],
        'elevenlabs' => ['GET', 'https://api.elevenlabs.io/v1/user', ['xi-api-key: ' . $key]],
        'google' => ['GET', 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key), []],
        default => ['GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify', ['Authorization: Bearer ' . $key]],
    };

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $status < 400) {
        return ['ok' => true, 'message' => 'Credenciais OK com ' . $provider . ' (' . ($config['model'] ?? '') . ')'];
    }

        $json = is_string($response) ? json_decode($response, true) : null;
    $error = $json['error']['message'] ?? $json['err_msg'] ?? $json['errors'][0]['message'] ?? ('HTTP ' . $status);
    return ['ok' => false, 'error' => ucfirst($provider) . ': ' . $error];
}

// ------------------------------------------------------------------
// Modelos locais (LM Studio / Ollama / openai-compatible)
// ------------------------------------------------------------------

/**
 * Request a um servidor local com fallback de URL (127.0.0.1 <-> host.docker.internal).
 */
function localRequest(string $baseUrl, string $path, string $method = 'GET', ?array $payload = null): array
{
    $lastError = '';

    foreach (\AiClient::localUrlVariants($baseUrl) as $url) {
        $ch = curl_init($url . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status > 0) {
            $json = json_decode((string)$body, true);
            if ($status >= 400) {
                $message = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? ('HTTP ' . $status);
                return ['ok' => false, 'error' => is_string($message) ? $message : ('HTTP ' . $status)];
            }
            return ['ok' => true, 'json' => is_array($json) ? $json : []];
        }

        $lastError = $err;
    }

    return ['ok' => false, 'error' => 'Nao foi possivel conectar ao servidor local (' . $lastError . ')'];
}

/**
 * Raiz do servidor local (sem o sufixo /v1) — as APIs do LM Studio ficam em /api/v0 e /api/v1.
 */
function localRoot(string $baseUrl): string
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    return (string)preg_replace('#/v1/?$#', '', $baseUrl);
}

/**
 * Lista os modelos do servidor local com estado (LM Studio expõe /api/v0/models com `state`).
 */
function localModels(string $baseUrl): array
{
    $root = localRoot($baseUrl);
    if ($root === '') return ['ok' => false, 'error' => 'Informe a Base URL do servidor local'];

    $result = localRequest($root, '/api/v0/models');
    if ($result['ok'] && isset($result['json']['data']) && is_array($result['json']['data']) && !empty($result['json']['data'])) {
        $models = [];
        foreach ($result['json']['data'] as $m) {
            $models[] = [
                'id' => (string)($m['id'] ?? ''),
                'state' => (string)($m['state'] ?? 'unknown'),
                'quantization' => (string)($m['quantization'] ?? ''),
                'max_context_length' => (int)($m['max_context_length'] ?? 0),
                'type' => (string)($m['type'] ?? 'llm'),
            ];
        }
        return ['ok' => true, 'server' => 'lmstudio', 'models' => $models];
    }

    // Generico (Ollama/openai-compatible): /v1/models (sem estado)
    $result = localRequest($root, '/v1/models');
    if ($result['ok'] && isset($result['json']['data']) && is_array($result['json']['data'])) {
        $models = [];
        foreach ($result['json']['data'] as $m) {
            $models[] = [
                'id' => (string)($m['id'] ?? ''),
                'state' => 'unknown',
                'quantization' => '',
                'max_context_length' => 0,
                'type' => 'llm',
            ];
        }
        return ['ok' => true, 'server' => 'openai-compatible', 'models' => $models];
    }

    return ['ok' => false, 'error' => $result['error'] ?? 'Falha ao listar modelos do servidor local'];
}

/**
 * Carrega um modelo na VRAM — nao carrega de novo se ja estiver carregado (evita duplicar).
 */
function localLoad(string $baseUrl, string $model, int $ttl = 0): array
{
    $root = localRoot($baseUrl);
    $model = trim($model);
    if ($root === '' || $model === '') return ['ok' => false, 'error' => 'Informe a Base URL e o modelo'];

    // Ja esta carregado? (nao sobe duas vezes para a VRAM)
    $list = localModels($baseUrl);
    if ($list['ok'] && ($list['server'] ?? '') === 'lmstudio') {
        foreach ($list['models'] as $m) {
            if ($m['id'] === $model && $m['state'] === 'loaded') {
                return ['ok' => true, 'already' => true, 'message' => 'Modelo ja estava carregado na VRAM'];
            }
        }
    }

    // O LM Studio nao aceita `ttl` no load (so em requests de chat) — o TTL e aplicado
    // automaticamente quando o modelo e usado pelo Socio.
    $r = localRequest($root, '/api/v1/models/load', 'POST', ['model' => $model]);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'] ?? 'Falha ao carregar o modelo'];
    }

    $seconds = $r['json']['load_time_seconds'] ?? null;
    return ['ok' => true, 'message' => 'Modelo carregado' . ($seconds ? ' em ' . round((float)$seconds, 1) . 's' : '')];
}

/**
 * Descarrega um modelo da VRAM (libera recursos).
 */
function localUnload(string $baseUrl, string $model): array
{
    $root = localRoot($baseUrl);
    $model = trim($model);
    if ($root === '' || $model === '') return ['ok' => false, 'error' => 'Informe a Base URL e o modelo'];

    $r = localRequest($root, '/api/v1/models/unload', 'POST', ['instance_id' => $model]);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'] ?? 'Falha ao descarregar o modelo'];
    }

    return ['ok' => true, 'message' => 'Modelo descarregado da VRAM'];
}
