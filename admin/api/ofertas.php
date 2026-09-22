<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/Offers/OfferManager.php';
require_once Config::getLibDir() . '/Offers/OfferQuota.php';
require_once Config::getLibDir() . '/Offers/OfferCollector.php';
require_once Config::getLibDir() . '/Offers/OfferAi.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();

if (!Plans::hasFeature($user['plan'], 'offers') && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Seu plano não inclui o módulo Ofertas Escalando']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$manager = new OfferManager();

switch ($action) {
    case 'list':
        $filters = [
            'status' => $isAdmin ? ($_GET['status'] ?? 'approved') : 'approved',
            'niche' => trim($_GET['niche'] ?? ''),
            'language' => trim($_GET['language'] ?? ''),
            'structure' => trim($_GET['structure'] ?? ''),
            'platform' => trim($_GET['platform'] ?? ''),
            'traffic' => trim($_GET['traffic'] ?? ''),
            'q' => trim($_GET['q'] ?? ''),
            'order' => $_GET['order'] ?? 'score',
            // Admin (curadoria) ve as demos para limpa-las; clientes nunca veem dados ficticios.
            'include_demo' => $isAdmin,
        ];
        $result = $manager->list($filters, min(120, max(1, (int)($_GET['limit'] ?? 60))), max(0, (int)($_GET['offset'] ?? 0)));
        $result['quota'] = OfferQuota::check($userId, $user['plan']);
        $result['is_admin'] = $isAdmin;
        $result['stats'] = $isAdmin ? $manager->stats() : null;
        $result['filters'] = [
            'niches' => OfferManager::NICHES,
            'structures' => OfferManager::STRUCTURES,
            'traffic' => OfferManager::TRAFFIC,
            'languages' => ['pt', 'es', 'en'],
        ];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Oferta inválida']);
            break;
        }

        $quota = OfferQuota::check($userId, $user['plan'], $id);
        if (!$quota['allowed']) {
            echo json_encode([
                'error' => 'Sua cota de ofertas do mês foi atingida (' . $quota['used'] . '/' . $quota['limit'] . '). Faça upgrade para ver mais ofertas.',
                'quota' => $quota,
            ]);
            break;
        }

        $offer = $manager->get($id);
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['error' => 'Oferta não encontrada']);
            break;
        }

        if (!$isAdmin && $offer['status'] !== 'approved') {
            echo json_encode(['error' => 'Oferta não disponível']);
            break;
        }

        if (!$isAdmin && str_starts_with((string)($offer['slug'] ?? ''), 'demo-')) {
            echo json_encode(['error' => 'Oferta não disponível']);
            break;
        }

        if (!$isAdmin) {
            OfferQuota::consume($userId, $id);
            Audit::log('offer_viewed', 'offer', (string)$id, ['name' => $offer['name']]);
        }

        echo json_encode([
            'success' => true,
            'offer' => $offer,
            'quota' => OfferQuota::check($userId, $user['plan'], $id),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'creatives':
        if (!Database::available()) { echo json_encode(['success' => true, 'items' => []]); break; }
        $offerId = (int)($_GET['offer_id'] ?? 0);
        $query = \AfiliaFacil\Models\OfferCreative::query();
        if ($offerId > 0) $query->where('offer_id', $offerId);
        if (!$isAdmin) {
            $approvedIds = \AfiliaFacil\Models\Offer::where('status', 'approved')->where('slug', 'not like', 'demo-%')->pluck('id')->all();
            $query->whereIn('offer_id', $approvedIds);
        }
        $items = $query->orderByDesc('id')->limit(120)->get()->map(fn($c) => [
            'id' => (int)$c->id,
            'offer_id' => (int)$c->offer_id,
            'platform' => $c->platform,
            'advertiser' => $c->advertiser,
            'title' => $c->title,
            'body' => $c->body,
            'cta' => $c->cta,
            'media_type' => $c->media_type,
            'thumbnail_url' => $c->thumbnail_url,
            'media_url' => $c->media_url,
            'landing_page' => $c->landing_page,
            'ad_url' => $c->ad_url,
            'status' => $c->status,
            'started_at' => $c->started_at,
        ])->all();
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        break;

    case 'pages':
        if (!Database::available()) { echo json_encode(['success' => true, 'items' => []]); break; }
        $offerId = (int)($_GET['offer_id'] ?? 0);
        $type = trim($_GET['type'] ?? '');
        $query = \AfiliaFacil\Models\OfferPage::query();
        if ($offerId > 0) $query->where('offer_id', $offerId);
        if ($type !== '') $query->where('type', $type);
        if (!$isAdmin) {
            $approvedIds = \AfiliaFacil\Models\Offer::where('status', 'approved')->where('slug', 'not like', 'demo-%')->pluck('id')->all();
            $query->whereIn('offer_id', $approvedIds);
        }
        $items = $query->orderByDesc('id')->limit(120)->get()->map(fn($p) => [
            'id' => (int)$p->id,
            'offer_id' => (int)$p->offer_id,
            'url' => $p->url,
            'type' => $p->type,
            'title' => $p->title,
            'thumbnail_url' => $p->thumbnail_url,
            'status' => $p->status,
        ])->all();
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        break;

    case 'approve':
    case 'reject':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $ok = $action === 'approve' ? $manager->approve($id) : $manager->reject($id);
        echo json_encode($ok ? ['success' => true] : ['error' => 'Falha ao atualizar oferta']);
        break;

    case 'bulk':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) { echo json_encode(['error' => 'Nenhuma oferta selecionada']); break; }
        $op = $_POST['op'] ?? 'approve';
        $count = $op === 'reject' ? $manager->bulkReject($ids) : $manager->bulkApprove($ids);
        Audit::log('offers_bulk_' . $op, 'offer', null, ['count' => $count]);
        echo json_encode(['success' => true, 'count' => $count]);
        break;

    case 'analyze':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $ai = new OfferAi();
        echo json_encode($ai->analyze($id, $userId), JSON_UNESCAPED_UNICODE);
        break;

    case 'analyze-pending':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $ai = new OfferAi();
        $result = $ai->analyzePending(min(20, max(1, (int)($_POST['limit'] ?? 10))), $userId);
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        break;

    case 'collect':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $collector = new OfferCollector();
        $termsRaw = (string)($_POST['terms'] ?? Settings::get('offers_terms', ''));
        $terms = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $termsRaw))));
        $providers = $_POST['providers'] ?? ['meta', 'google', 'tiktok'];
        $result = $collector->collect($terms, array_values(array_intersect($providers, ['meta', 'google', 'tiktok'])), (int)Settings::get('offers_collect_limit', '8'));
        Audit::log('offers_collect', 'offer', null, $result);
        echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        break;

    case 'monitor':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }
        $collector = new OfferCollector();
        $result = $collector->monitor((int)Settings::get('offers_monitor_limit', '40'));
        echo json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        break;

    case 'settings':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $map = [
                'offers_monitor_mode' => ['cron', 'manual'],
                'offers_auto_approve' => ['0', '1'],
            ];
            foreach ($map as $key => $allowed) {
                if (isset($_POST[$key]) && in_array((string)$_POST[$key], $allowed, true)) {
                    Settings::set($key, (string)$_POST[$key]);
                }
            }
            if (isset($_POST['offers_auto_approve_score'])) {
                Settings::set('offers_auto_approve_score', (string)max(0, min(100, (int)$_POST['offers_auto_approve_score'])));
            }
            if (isset($_POST['offers_terms'])) {
                Settings::set('offers_terms', trim((string)$_POST['offers_terms']));
            }
            if (isset($_POST['offers_monitor_limit'])) {
                Settings::set('offers_monitor_limit', (string)max(1, min(200, (int)$_POST['offers_monitor_limit'])));
            }
            if (isset($_POST['offers_collect_limit'])) {
                Settings::set('offers_collect_limit', (string)max(1, min(30, (int)$_POST['offers_collect_limit'])));
            }
            Audit::log('offers_settings_updated', 'settings', null, []);
            echo json_encode(['success' => true]);
            break;
        }

        echo json_encode([
            'success' => true,
            'settings' => [
                'monitor_mode' => Settings::get('offers_monitor_mode', 'cron'),
                'auto_approve' => Settings::get('offers_auto_approve', '0') === '1',
                'auto_approve_score' => (int)Settings::get('offers_auto_approve_score', '70'),
                'terms' => (string)Settings::get('offers_terms', ''),
                'monitor_limit' => (int)Settings::get('offers_monitor_limit', '40'),
                'collect_limit' => (int)Settings::get('offers_collect_limit', '8'),
            ],
        ]);
        break;

    case 'cron-key':
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Apenas administradores']); break; }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $key = bin2hex(random_bytes(32));
            Settings::set('cron_key', $key);
            Audit::log('cron_key_rotated', 'settings', null, []);
            echo json_encode(['success' => true, 'key' => $key]);
            break;
        }

        $key = trim(getenv('CRON_KEY') ?: '') ?: (string)Settings::get('cron_key', '');
        $masked = $key === '' ? '' : substr($key, 0, 6) . str_repeat('•', 10) . substr($key, -4);
        $host = ($_SERVER['HTTP_HOST'] ?? 'SEU-DOMINIO');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        echo json_encode([
            'success' => true,
            'configured' => $key !== '',
            'from_env' => (getenv('CRON_KEY') ?: '') !== '',
            'masked' => $masked,
            'endpoint' => $scheme . '://' . $host . '/cron/monitor.php?key=SEU_CRON_KEY',
        ]);
        break;

    case 'creative-download':
    case 'creative-vary':
        if (!Database::available()) { echo json_encode(['error' => 'Banco de dados indisponível']); break; }
        $creativeId = (int)($_GET['id'] ?? 0);
        $creative = \AfiliaFacil\Models\OfferCreative::find($creativeId);
        $offer = $creative ? \AfiliaFacil\Models\Offer::find($creative->offer_id) : null;
        if (!$creative || !$offer) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Criativo não encontrado']);
            exit;
        }
        if (!$isAdmin && ($offer->status !== 'approved' || str_starts_with((string)($offer->slug ?? ''), 'demo-'))) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Criativo não disponível']);
            exit;
        }

        require_once Config::getLibDir() . '/Offers/CreativeStudio.php';
        $url = $creative->media_url ?: $creative->thumbnail_url;
        $isVary = $action === 'creative-vary';
        $file = $isVary ? CreativeStudio::vary($url) : CreativeStudio::download($url);
        if (empty($file['success'])) {
            http_response_code(502);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => $file['error'] ?? 'Falha ao obter a imagem']);
            exit;
        }

        Audit::log($isVary ? 'creative_varied' : 'creative_downloaded', 'offer_creative', (string)$creativeId, ['offer_id' => (int)$offer->id]);
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . strlen($file['data']));
        header('Content-Disposition: attachment; filename="' . CreativeStudio::filename($creativeId, $isVary ? 'vary' : 'download', $file['ext']) . '"');
        echo $file['data'];
        exit;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
