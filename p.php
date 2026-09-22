<?php
// Serve público das páginas: /p/{slug} (sem login) e POST /p/track (eventos CTA).
// Conta views (ignora bots), injeta pixel do navegador + CAPI PageView server-side
// com o MESMO event_id (a Meta deduplica). Falha de CAPI nunca quebra a página.
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AssetProcessor.php';
require_once Config::getLibDir() . '/Tracking/ConversionsApi.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pm = new PageManager();

function pbot(): bool
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua !== '' && preg_match('/bot|crawl|spider|facebookexternalhit|embedly|preview|monitor/i', $ua);
}

// --- POST /p/track: evento server-side vindo de clique em CTA ---
if ($path === '/p/track') {
    header('Content-Type: application/json; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método inválido']);
        exit;
    }
    $slug = trim((string)($_POST['slug'] ?? ''));
    $event = trim((string)($_POST['event'] ?? ''));
    $allowed = ['ViewContent', 'Lead', 'InitiateCheckout', 'AddToCart', 'Purchase'];
    $page = $slug !== '' ? $pm->getBySlug($slug) : null;
    if (!$page || ($page['status'] ?? '') !== 'active') {
        http_response_code(404);
        echo json_encode(['error' => 'Página não encontrada']);
        exit;
    }
    if (!in_array($event, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Evento inválido']);
        exit;
    }
    $secrets = $pm->trackingSecrets((int)$page['id']);
    if ($secrets['pixel_id'] === '' || $secrets['capi_token'] === '') {
        echo json_encode(['success' => false, 'error' => 'CAPI não configurada nesta página']);
        exit;
    }
    $custom = [];
    $value = (float)($_POST['value'] ?? 0);
    if ($value > 0) {
        $custom['value'] = $value;
        $custom['currency'] = preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : 'BRL';
    }
    $result = ConversionsApi::send($secrets['pixel_id'], $secrets['capi_token'], $event, $custom, ['test_code' => $secrets['test_code']]);
    echo json_encode($result + ['event' => $event], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- GET /p/{slug} ---
$slug = trim(substr($path, 3), '/');
$slug = explode('/', $slug)[0] ?? '';
if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/i', $slug)) {
    http_response_code(404);
    die('Página não encontrada');
}

$page = $pm->getBySlug($slug);
if (!$page || ($page['status'] ?? '') !== 'active' || empty($page['html'])) {
    http_response_code(404);
    die('Página não encontrada');
}

$isBot = pbot();
if (!$isBot) {
    $pm->incrementViews((int)$page['id']);
}

$html = AssetProcessor::rewriteForPreview((string)$page['html'], (string)($page['source_domain'] ?? ''));
$inject = '';
$pixelId = trim((string)($page['meta_pixel_id'] ?? ''));

if ($pixelId !== '' && ctype_digit($pixelId)) {
    $eventId = ConversionsApi::newEventId();

    // Pixel do navegador (com event_id p/ deduplicar com o server-side)
    $inject .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?'
        . 'n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;'
        . 'n.loaded=!0;n.version=\'2.0\';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;'
        . 's=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,\'script\','
        . '\'https://connect.facebook.net/en_US/fbevents.js\');'
        . 'fbq(\'init\',\'' . $pixelId . '\');'
        . 'fbq(\'track\',\'PageView\',{},{\'eventID\':\'' . $eventId . '\'});</script>' . "\n";

    // CAPI server-side (PageView com o MESMO event_id; best effort)
    if (!$isBot) {
        try {
            $secrets = $pm->trackingSecrets((int)$page['id']);
            if ($secrets['capi_token'] !== '') {
                ConversionsApi::send($pixelId, $secrets['capi_token'], 'PageView', [], [
                    'event_id' => $eventId,
                    'test_code' => $secrets['test_code'],
                ]);
            }
        } catch (Throwable $e) {
        }
    }

    // Cliques em CTA disparam InitiateCheckout server-side (beacon, não bloqueia)
    $inject .= '<script>(function(){document.addEventListener(\'click\',function(e){'
        . 'var a=e.target&&e.target.closest?e.target.closest(\'a[href]\'):null;if(!a)return;'
        . 'try{if(navigator.sendBeacon){navigator.sendBeacon(\'/p/track\',new URLSearchParams({slug:'
        . json_encode($slug) . ',event:\'InitiateCheckout\'}));}}catch(_){}} ,true);})();</script>' . "\n";
}

if ($inject !== '' && str_contains($html, '</body>')) {
    $html = str_replace('</body>', $inject . '</body>', $html);
} elseif ($inject !== '') {
    $html .= $inject;
}

header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Content-Type-Options: nosniff');
echo $html;
