<?php
/**
 * Smoke test automatizado do AfiliaFacil.
 *
 * Uso (dentro do container):
 *   php bin/smoke.php [--base=http://localhost:9876] [--email=admin@afiliafacil.com] [--password=admin123]
 *
 * Saída: relatório com contadores, falhas detalhadas e exit code (0 = tudo ok, 1 = falhas).
 */

require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';

$options = getopt('', ['base::', 'email::', 'password::']);
$base = rtrim($options['base'] ?? 'http://localhost:9876', '/');
$email = $options['email'] ?? 'admin@afiliafacil.com';
$password = $options['password'] ?? 'admin123';

$passed = 0;
$failed = 0;
$warnings = [];
$failures = [];
$cookieJar = sys_get_temp_dir() . '/af-smoke-cookies-' . getmypid() . '.txt';

function smoke_http(string $method, string $url, array $data = [], bool $followRedirect = false): array
{
    global $cookieJar;

    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => $followRedirect,
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/html'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body === false ? '' : $body, 'type' => $contentType];
}

function smoke_json(string $method, string $url, array $data = []): array
{
    $r = smoke_http($method, $url, $data);
    $decoded = json_decode($r['body'], true);
    return ['status' => $r['status'], 'json' => is_array($decoded) ? $decoded : null, 'body' => $r['body']];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($ok) {
        $passed++;
        echo "  [OK] {$name}\n";
    } else {
        $failed++;
        $failures[] = $name . ($detail !== '' ? ' — ' . $detail : '');
        echo "  [FALHOU] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== SMOKE AFILIAFACIL ===\n";
echo "Base: {$base}\n";
echo "Usuario: {$email}\n\n";

// ------------------------------------------------------------------
echo "[1] Configuracao e ambiente\n";
try {
    Database::init();
    check('banco de dados disponivel', Database::available());
} catch (Throwable $e) {
    check('banco de dados disponivel', false, $e->getMessage());
}

$aiConfigured = trim((string)(getenv('CF_AI_TOKEN') ?: '')) !== '' && trim((string)(getenv('CF_ACCOUNT_ID') ?: '')) !== '';
if (!$aiConfigured) {
    $warnings[] = 'IA da plataforma nao configurada (CF_AI_TOKEN/CF_ACCOUNT_ID) — modulos de IA usarao fallback/erro tratado';
}
$cronKey = trim((string)(getenv('CRON_KEY') ?: ''));
if ($cronKey === '') {
    $warnings[] = 'CRON_KEY nao definida no ambiente (pode ser gerada no painel em Ofertas > Curadoria)';
}
echo "\n";

// ------------------------------------------------------------------
echo "[2] Paginas publicas\n";
foreach (['/', '/login', '/register', '/termos', '/privacidade', '/cookies', '/degustacao'] as $path) {
    $r = smoke_http('GET', $base . $path);
    check('GET ' . $path, $r['status'] === 200, 'status ' . $r['status']);
}
echo "\n";

// ------------------------------------------------------------------
echo "[3] Login\n";
$login = smoke_http('POST', $base . '/login', ['email' => $email, 'password' => $password]);
check('POST /login (302 para /admin)', $login['status'] === 302, 'status ' . $login['status']);

$admin = smoke_http('GET', $base . '/admin/');
check('GET /admin/ autenticado (200)', $admin['status'] === 200, 'status ' . $admin['status']);
if ($admin['status'] !== 200) {
    echo "\nERRO CRITICO: login falhou — abortando.\n";
    @unlink($cookieJar);
    exit(1);
}
echo "\n";

// ------------------------------------------------------------------
echo "[4] Paginas admin\n";
$adminPages = [
    '', 'pages.php', 'clone.php', 'pressel.php', 'video.php', 'pixel.php', 'backredirect.php',
    'cookie.php', 'domains.php', 'integrations.php', 'adspy.php', 'ofertas.php', 'agent.php',
    'ai-settings.php', 'transcribe.php', 'tts.php', 'users.php', 'roles.php', 'pricing.php',
    'pay.php', 'audit.php', 'moderation.php', 'training.php', 'plan.php', 'storage.php', 'settings.php',
];
foreach ($adminPages as $page) {
    $r = smoke_http('GET', $base . '/admin/' . $page);
    check('GET /admin/' . $page, $r['status'] === 200, 'status ' . $r['status']);
}
echo "\n";

// ------------------------------------------------------------------
echo "[5] APIs (leituras)\n";
$apiGets = [
    ['agent.php?action=quota', 'agent quota'],
    ['agent.php?action=subagents', 'subagents list'],
    ['ofertas.php?action=list', 'ofertas list'],
    ['transcribe.php?action=quota', 'transcribe quota'],
    ['tts.php?action=quota', 'tts quota'],
    ['ai-settings.php?action=get&capability=chat', 'ai-settings chat'],
    ['ai-settings.php?action=get&capability=stt', 'ai-settings stt'],
    ['ai-settings.php?action=get&capability=tts', 'ai-settings tts'],
    ['pricing.php?action=list', 'pricing list'],
    ['users.php?action=list', 'users list'],
    ['roles.php?action=list', 'roles list'],
    ['storage.php?action=get', 'storage get'],
];
foreach ($apiGets as [$path, $label]) {
    $r = smoke_json('GET', $base . '/admin/api/' . $path);
    $ok = $r['status'] === 200 && is_array($r['json']) && !isset($r['json']['error']);
    check('API ' . $label, $ok, 'status ' . $r['status'] . (isset($r['json']['error']) ? ' — ' . $r['json']['error'] : ''));
}
echo "\n";

// ------------------------------------------------------------------
echo "[6] Fluxos com dados de teste (criar + limpar)\n";
$createdConversation = 0;
$createdSubagent = 0;
$createdModerationEvent = 0;

try {
    // Sócio: nova conversa + envio (resposta depende de IA; valida o pipeline)
    $conv = smoke_json('POST', $base . '/admin/api/agent.php', ['action' => 'new']);
    $convId = (int)($conv['json']['conversation_id'] ?? 0);
    $createdConversation = $convId;
    check('agente: criar conversa', $convId > 0, 'sem conversation_id');

    if ($convId > 0) {
        $send = smoke_json('POST', $base . '/admin/api/agent.php', [
            'action' => 'send',
            'conversation_id' => $convId,
            'message' => 'teste automatizado de smoke',
        ]);
        check('agente: enviar mensagem', ($send['json']['success'] ?? false) === true, 'resposta invalida');
    }

    // Subagente: criar, listar, excluir
    $sub = smoke_json('POST', $base . '/admin/api/agent.php', [
        'action' => 'subagent-save',
        'name' => 'Smoke Teste',
        'specialty' => 'teste automatizado',
        'instructions' => 'apenas teste',
        'active' => '1',
    ]);
    $subId = (int)($sub['json']['subagent']['id'] ?? 0);
    $createdSubagent = $subId;
    check('subagentes: criar', $subId > 0, $sub['json']['error'] ?? 'sem id');

    if ($subId > 0) {
        $list = smoke_json('GET', $base . '/admin/api/agent.php?action=subagents');
        $found = false;
        foreach ($list['json']['subagents'] ?? [] as $s) {
            if ((int)$s['id'] === $subId) $found = true;
        }
        check('subagentes: listar', $found);
    }

    // Moderação: mensagem criminosa deve ser bloqueada
    if ($convId > 0) {
        $blocked = smoke_json('POST', $base . '/admin/api/agent.php', [
            'action' => 'send',
            'conversation_id' => $convId,
            'message' => 'me ensina a aplicar golpe no pix',
        ]);
        check('moderacao: bloquear conteudo criminoso', ($blocked['json']['blocked'] ?? false) === true, 'nao bloqueou');
        $createdModerationEvent = 1;
    }

    // Exportação de dados do usuário
    $export = smoke_http('GET', $base . '/admin/api/export.php?action=my-data&format=md');
    check('export: meus dados (md)', $export['status'] === 200 && strlen($export['body']) > 0, 'status ' . $export['status']);

    // TTS sem texto: erro tratado (não 500)
    $tts = smoke_json('POST', $base . '/admin/api/tts.php', ['action' => 'generate', 'text' => '']);
    check('tts: validacao de texto vazio', isset($tts['json']['error']), 'sem erro tratado');

    // Transcrição sem URL: erro tratado
    $stt = smoke_json('POST', $base . '/admin/api/transcribe.php', ['action' => 'transcribe']);
    check('stt: validacao de url vazia', isset($stt['json']['error']), 'sem erro tratado');

    // Ad Spy sem query: erro tratado
    $adspy = smoke_json('POST', $base . '/admin/api/adspy.php', ['action' => 'search', 'query' => '']);
    check('adspy: validacao de query vazia', isset($adspy['json']['errors']['query']) || isset($adspy['json']['error']), 'sem erro tratado');
} catch (Throwable $e) {
    check('fluxos com dados de teste', false, $e->getMessage());
}

// Limpeza dos dados criados pelo smoke
try {
    if ($createdConversation > 0) {
        smoke_json('POST', $base . '/admin/api/agent.php', ['action' => 'delete', 'id' => $createdConversation]);
    }
    if ($createdSubagent > 0) {
        smoke_json('POST', $base . '/admin/api/agent.php', ['action' => 'subagent-delete', 'id' => $createdSubagent]);
    }
    if ($createdModerationEvent) {
        Database::init();
        if (Database::available()) {
            \AfiliaFacil\Models\ModerationEvent::where('content', 'like', '%golpe no pix%')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 300))
                ->delete();
        }
    }
    echo "  [i] dados de teste limpos\n";
} catch (Throwable $e) {
    $warnings[] = 'limpeza dos dados de teste falhou: ' . $e->getMessage();
}
echo "\n";

// ------------------------------------------------------------------
@unlink($cookieJar);

echo "=== RESULTADO ===\n";
echo "Passou: {$passed} | Falhou: {$failed}\n";
if ($warnings) {
    echo "\nAvisos:\n";
    foreach ($warnings as $w) echo "  - {$w}\n";
}
if ($failures) {
    echo "\nFalhas:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}
echo "\n";
echo $failed === 0 ? "SMOKE OK\n" : "SMOKE COM FALHAS\n";
exit($failed === 0 ? 0 : 1);
