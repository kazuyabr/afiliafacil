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
$isTest = $action === 'steel-test';

// Os testes de conexao internos (steel-test) sao autorizados por ADMIN ou por POST valido.
if (!$isTest) {
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
} else {
    // Ainda assim, exige uma validacao simples para nao ficar aberto ao cruzeiro.
    // O proprio painel faz POST com header proprio — nao expomos sem sessao real.
    if (!Auth::check()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
}

switch ($action) {
    case 'steel-test':
        $url = rtrim(trim((string)($_GET['url'] ?? $_POST['url'] ?? '')), '/');
        if ($url === '') {
            echo json_encode(['ok' => false, 'error' => 'URL vazia']);
            break;
        }
        // Em Docker, o "localhost" do cliente nao alcanca o host — traduz p/ host.docker.internal
        if (getenv('DOCKER') && preg_match('#^https?://(localhost|127\.0\.0\.1)(:|$)#', $url)) {
            $url = preg_replace('#^https?://(localhost|127\.0\.0\.1)#', 'http://host.docker.internal', $url);
        }

        // Testa de verdade: POST /v1/scrape (nossa camada de browser) nao /health (pode nao existir).
    // Limpa o estado de saúde dos providers pra proxima busca ser re-avaliada (o usuario configurou agora).
    // Usa o user_id da sessao — iniciada acima por Auth::check().
    $userId = (int)(Auth::user()['id'] ?? 0);
    require_once __DIR__ . '/../../lib/AdSpy/AdSpyHealth.php';
    foreach (['meta', 'tiktok'] as $pid) {
        \Settings::set('adspy_health_' . $userId . '_' . $pid, '');
    }

        $ch = curl_init($url . '/v1/scrape');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['url' => 'https://example.com']),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            echo json_encode(['ok' => false, 'error' => 'Sem conexão: ' . $err]);
            break;
        }

        $data = json_decode((string)$body, true);
        $htmlLen = 0;
        if (is_array($data)) {
            $html = (string)($data['content']['html'] ?? $data['content'] ?? $data['html'] ?? '');
            $htmlLen = mb_strlen($html);
        }

        if ($status < 400 && $htmlLen > 0) {
            echo json_encode(['ok' => true, 'message' => 'Steel respondeu (página extraiu ' . $htmlLen . ' caracteres)']);
        } elseif ($status >= 400) {
            echo json_encode(['ok' => false, 'error' => 'HTTP ' . $status . ' — ' . ($data['message'] ?? $data['error'] ?? 'erro desconhecido')]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Steel respondeu mas sem HTML — verifique se o serviço está estável.']);
        }
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
