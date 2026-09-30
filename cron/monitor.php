<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Offers/OfferCollector.php';
require_once Config::getLibDir() . '/Offers/OfferAi.php';
require_once Config::getLibDir() . '/Agent/AgentJobs.php';

header('Content-Type: application/json; charset=UTF-8');

$expected = trim(getenv('CRON_KEY') ?: '') ?: (string)Settings::get('cron_key', '');
$key = trim($_GET['key'] ?? '');

if ($expected === '' || $key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Chave de cron inválida ou não configurada']);
    exit;
}

$startedAt = microtime(true);
$result = ['started_at' => date('Y-m-d H:i:s')];

// Publicacoes agendadas das redes sociais rodam sempre (independem do modo de ofertas)
try {
    require_once Config::getLibDir() . '/Social/SocialPublisher.php';
    $result['social_posts'] = SocialPublisher::processDue(5);
} catch (Throwable $e) {
    $result['social_posts'] = ['error' => $e->getMessage()];
}

if (Settings::get('offers_monitor_mode', 'cron') === 'manual') {
    echo json_encode(['skipped' => true, 'reason' => 'Monitor em modo manual (Admin > Ofertas > Curadoria)',
        'social_posts' => $result['social_posts'] ?? null]);
    exit;
}

try {
    $collector = new OfferCollector();

    $result['monitor'] = $collector->monitor((int)Settings::get('offers_monitor_limit', '40'));

    $termsRaw = (string)Settings::get('offers_terms', '');
    $terms = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $termsRaw))));
    $result['collect'] = $collector->collect($terms, ['meta', 'google', 'tiktok'], (int)Settings::get('offers_collect_limit', '8'));

    $result['ai'] = (new OfferAi())->analyzePending((int)Settings::get('offers_ai_limit', '10'));

    // Fallback: processa jobs pendentes do Socio de IA (caso o navegador tenha fechado)
    $result['agent_jobs'] = AgentJobs::processPending(5);

    $result['success'] = true;
} catch (Throwable $e) {
    $result['success'] = false;
    $result['error'] = $e->getMessage();
}

$result['elapsed_seconds'] = round(microtime(true) - $startedAt, 1);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
