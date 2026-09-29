<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyQuota.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyManager.php';
require_once Config::getLibDir() . '/AdSpy/AiAnalyzer.php';
require_once Config::getLibDir() . '/AdSpy/SteelBrowser.php';
require_once Config::getLibDir() . '/AdSpy/TikTokSession.php';
require_once Config::getLibDir() . '/Audit.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$plan = $user['plan'];

// Libera o lock da sessao antes de operacoes longas (busca/análise pode demorar)
session_write_close();

if (!Plans::hasFeature($plan, 'adspy') && !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'A espionagem de anúncios está disponível nos planos Afiliado Pro e Master Elite. Faça upgrade em "Meu Plano".']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'quota':
        echo json_encode([
            'success' => true,
            'search' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH),
            'analysis' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_ANALYSIS),
        ]);
        break;

    case 'status':
        $manager = new AdSpyManager();
        echo json_encode(['success' => true, 'providers' => $manager->providerStatus($userId)], JSON_UNESCAPED_UNICODE);
        break;

    case 'search':
        $query = trim($_POST['query'] ?? '');
        $providers = $_POST['providers'] ?? AdSpyManager::PROVIDERS;
        if (is_string($providers)) $providers = array_filter(explode(',', $providers));
        $providers = array_values(array_intersect($providers, AdSpyManager::PROVIDERS));
        if (empty($providers)) $providers = AdSpyManager::PROVIDERS;

        $options = [
            'countries' => [strtoupper($_POST['country'] ?? 'BR')],
            'country' => strtoupper($_POST['country'] ?? 'BR'),
            'status' => $_POST['status'] ?? 'all',
        ];

        $manager = new AdSpyManager();
        $result = $manager->search($userId, $plan, $query, $providers, $options);
        $result['success'] = empty($result['errors']['quota']);
        echo json_encode($result);
        break;

    case 'discover':
        $mode = $_POST['mode'] ?? 'trends';
        $country = strtoupper($_POST['country'] ?? 'BR');
        if ($country === 'ALL') $country = 'BR'; // Creative Center exige país específico
        $options = [
            'country' => $country,
            'period' => (int)($_POST['period'] ?? 7),
            'order_by' => $_POST['order_by'] ?? 'ctr',
            'limit' => min(50, max(5, (int)($_POST['limit'] ?? 30))),
            'query' => trim((string)($_POST['query'] ?? '')), // keyword opcional (Top Ads via Apify)
        ];

        $manager = new AdSpyManager();
        $result = $manager->discover($userId, $plan, $mode, $options);
        $result['success'] = empty($result['errors']);
        echo json_encode($result);
        break;

    // ── Sessão TikTok: login no viewer do Steel p/ liberar trends completa ──

    case 'tt_status':
        echo json_encode([
            'success' => true,
            'tiktok' => TikTokSession::status($userId),
            'steel' => SteelBrowser::isConfigured(),
            'apify' => AdSpyKeys::apify($userId) !== '',
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'tt_connect':
        if (!SteelBrowser::isConfigured()) {
            echo json_encode(['success' => false, 'error' => 'Steel Browser não está configurado — peça ao admin para configurar em Admin → Configurações.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $s = SteelBrowser::createSession();
        if (empty($s['ok'])) {
            echo json_encode(['success' => false, 'error' => $s['error'] ?? 'Falha ao criar a sessão no Steel Browser.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['success' => true, 'session_id' => $s['id'], 'viewer_url' => $s['viewer_url']]);
        break;

    case 'tt_context':
        $sid = trim((string)($_POST['session_id'] ?? ''));
        if ($sid === '') {
            echo json_encode(['success' => false, 'error' => 'Sessão ausente — clique em "Conectar TikTok" de novo.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $ctx = SteelBrowser::sessionContext($sid);
        SteelBrowser::releaseSession($sid); // sempre devolve o browser
        if (empty($ctx['ok'])) {
            echo json_encode(['success' => false, 'error' => $ctx['error'] ?? 'Falha ao ler a sessão.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // Login real = cookie de sessão do TikTok (sessionid/sid_tt/sid_guard)
        $loggedIn = false;
        foreach ((array)($ctx['context']['cookies'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $name = strtolower((string)($c['name'] ?? ''));
            $domain = strtolower((string)($c['domain'] ?? ''));
            $value = (string)($c['value'] ?? '');
            if ($value !== '' && in_array($name, ['sessionid', 'sid_tt', 'sid_guard'], true) && str_contains($domain, 'tiktok')) {
                $loggedIn = true;
                break;
            }
        }
        if (!$loggedIn) {
            echo json_encode(['success' => false, 'error' => 'Não detectei login do TikTok na janela aberta. Faça login no TikTok e clique em "Conectar TikTok" de novo.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        if (!TikTokSession::save($userId, $ctx['context'])) {
            echo json_encode(['success' => false, 'error' => 'Não consegui salvar a sessão (banco indisponível?). Tente de novo.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        Audit::log('tiktok_connected', 'adspy', (string)$userId, ['cookies' => TikTokSession::status($userId)['cookie_count']]);
        echo json_encode(['success' => true, 'tiktok' => TikTokSession::status($userId)]);
        break;

    case 'tt_disconnect':
        TikTokSession::delete($userId);
        Audit::log('tiktok_disconnected', 'adspy', (string)$userId);
        echo json_encode(['success' => true]);
        break;

    case 'dossier':
        $pageId = (int)($_POST['id'] ?? 0);
        $pm = new PageManager();
        $page = $pm->get($pageId);
        if (!$page) {
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        if (!Auth::canAccessPage($page)) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem acesso a esta página']);
            break;
        }

        $manager = new AdSpyManager();
        $dossier = $manager->dossier($page, $userId, $plan);

        $analyzer = new AiAnalyzer();
        $analysis = $analyzer->analyzeCampaign($dossier['signals'], $dossier['ads'], $userId, $plan);

        echo json_encode([
            'success' => true,
            'signals' => $dossier['signals'],
            'search' => $dossier['search'],
            'ads' => $dossier['ads'],
            'total_ads' => $dossier['total_ads'],
            'analysis' => $analysis,
        ]);
        break;

    case 'analyze':
        $pageId = (int)($_POST['id'] ?? 0);
        $pm = new PageManager();
        $page = $pm->get($pageId);
        if (!$page) {
            echo json_encode(['error' => 'Página não encontrada']);
            break;
        }
        if (!Auth::canAccessPage($page)) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem acesso a esta página']);
            break;
        }

        $manager = new AdSpyManager();
        $signals = $manager->extractSignals($page);
        $ads = json_decode($_POST['ads'] ?? '[]', true) ?: [];

        $analyzer = new AiAnalyzer();
        echo json_encode($analyzer->analyzeCampaign($signals, $ads, $userId, $plan));
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
