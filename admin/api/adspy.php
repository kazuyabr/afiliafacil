<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyQuota.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyManager.php';
require_once Config::getLibDir() . '/AdSpy/AiAnalyzer.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$plan = $user['plan'];

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
