<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Social/SocialNetworks.php';
require_once Config::getLibDir() . '/Social/SocialConnections.php';
require_once Config::getLibDir() . '/Social/SocialQuota.php';
require_once Config::getLibDir() . '/Social/SocialOAuth.php';
require_once Config::getLibDir() . '/Social/SocialPublisher.php';
require_once Config::getLibDir() . '/Social/SocialMetrics.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$plan = (string)$user['plan'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Callback do OAuth precisa da sessao (state/nonce) — os demais liberam o lock
// para nao bloquear as demais paginas do usuario durante chamadas HTTP longas.
if ($action !== 'callback') {
    session_write_close();
}

$hasFeature = Plans::hasFeature($plan, 'social');
$requireFeature = function () use ($hasFeature) {
    if (!$hasFeature) {
        http_response_code(403);
        echo json_encode(['error' => 'Seu plano não inclui publicações sociais. Faça upgrade para usar.']);
        return false;
    }
    return true;
};

switch ($action) {
    case 'connections': {
        $networks = [];
        foreach (SocialNetworks::ALL as $n) {
            $meta = SocialNetworks::meta()[$n];
            $networks[$n] = [
                'name' => $meta['name'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'desc' => $meta['desc'],
                'media' => $meta['media'],
                'media_required' => $meta['media_required'],
                'beta' => $meta['beta'],
                'oauth_configured' => SocialOAuth::credentials($n)['configured'],
            ];
        }
        echo json_encode([
            'success' => true,
            'has_feature' => $hasFeature,
            'networks' => $networks,
            'connections' => SocialConnections::list($userId),
            'conn_quota' => SocialConnections::checkCanConnect($userId, $plan),
            'post_quota' => SocialQuota::check($userId, $plan),
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'connect-url': {
        if (!$requireFeature()) break;
        $network = (string)($_POST['network'] ?? '');
        $res = SocialOAuth::authorizeUrl($network, $userId);
        echo json_encode($res + ['network' => $network], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'callback': {
        // Sem JSON: redireciona de volta para a tela com o resultado
        $res = SocialOAuth::handleCallback($_GET);
        $q = !empty($res['ok'])
            ? 'oauth=ok&network=' . rawurlencode($res['network'] ?? '')
            : 'oauth=err&network=' . rawurlencode($res['network'] ?? '') . '&msg=' . rawurlencode($res['error'] ?? 'Falha');
        header('Location: /admin/integrations.php?' . $q);
        exit;
    }

    case 'save-token': {
        if (!$requireFeature()) break;
        $network = (string)($_POST['network'] ?? '');
        $token = (string)($_POST['token'] ?? '');
        $can = SocialConnections::checkCanConnect($userId, $plan);
        if (!$can['ok'] && !SocialConnections::find($userId, $network)) {
            http_response_code(403);
            echo json_encode(['error' => $can['error']], JSON_UNESCAPED_UNICODE);
            break;
        }
        $res = SocialOAuth::saveManual($userId, $network, $token);
        $res['conn_quota'] = SocialConnections::checkCanConnect($userId, $plan);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'disconnect': {
        if (!$requireFeature()) break;
        $network = (string)($_POST['network'] ?? '');
        $ok = SocialConnections::disconnect($userId, $network);
        echo json_encode(['success' => $ok, 'network' => $network,
            'conn_quota' => SocialConnections::checkCanConnect($userId, $plan)], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'upload': {
        if (!$requireFeature()) break;
        if (empty($_FILES['media']) || !is_uploaded_file($_FILES['media']['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum arquivo enviado.']);
            break;
        }
        $f = $_FILES['media'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Falha no upload (código ' . $f['error'] . ').']);
            break;
        }
        if ($f['size'] > 15 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'Arquivo maior que 15MB.']);
            break;
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4'];
        if (!in_array($ext, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Formato não suportado. Use JPG, PNG, WEBP, GIF ou MP4.']);
            break;
        }
        $kind = $ext === 'mp4' ? 'video' : 'image';
        $dir = Config::getRootDir() . '/uploads/social/' . date('Ymd');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            http_response_code(500);
            echo json_encode(['error' => 'Não foi possível salvar o arquivo.']);
            break;
        }
        echo json_encode([
            'success' => true,
            'url' => Config::getBaseUrl() . '/uploads/social/' . date('Ymd') . '/' . $name,
            'kind' => $kind,
        ]);
        break;
    }

    case 'create': {
        if (!$requireFeature()) break;
        $networks = (array)($_POST['networks'] ?? []);
        if (isset($_POST['networks']) && is_string($_POST['networks'])) {
            $networks = array_filter(explode(',', $_POST['networks']));
        }
        $res = SocialPublisher::create($userId, $plan, [
            'caption' => (string)($_POST['caption'] ?? ''),
            'media_url' => (string)($_POST['media_url'] ?? ''),
            'media_kind' => (string)($_POST['media_kind'] ?? ''),
            'networks' => array_values($networks),
            'scheduled_at' => trim((string)($_POST['scheduled_at'] ?? '')) ?: null,
            'source' => (string)($_POST['source'] ?? 'manual'),
        ]);
        if (!$res['ok']) http_response_code(400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'list': {
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 20);
        echo json_encode(['success' => true, 'posts' => SocialPublisher::list($userId, $limit)],
            JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'delete': {
        if (!$requireFeature()) break;
        $ok = SocialPublisher::delete($userId, (int)($_POST['id'] ?? 0));
        echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Não foi possível excluir (post em publicação ou inexistente).']);
        break;
    }

    case 'metrics': {
        // Leitura: dashboard unificado (metricas ja coletadas)
        echo json_encode(['success' => true, 'by_post' => SocialMetrics::forUser($userId)],
            JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'collect': {
        if (!$requireFeature()) break;
        $summary = SocialMetrics::collect($userId, true, (int)($_POST['limit'] ?? 10));
        echo json_encode(['success' => true, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'process': {
        // Polling da tela: publica agendamentos vencidos (o cron faz o mesmo)
        echo json_encode(['success' => true, 'due' => SocialPublisher::processDue(5)],
            JSON_UNESCAPED_UNICODE);
        break;
    }

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
