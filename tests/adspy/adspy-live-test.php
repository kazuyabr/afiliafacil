<?php
/**
 * Testes LIVE do AdSpy — pendências da spec (chamadas REAIS, custo baixo).
 *
 * Contas: admin (id 1 — BYOK Apify/SerpApi/Meta) e demo.pro (sem Apify).
 * Custos: ~1 run Apify (max_items=5), 1 crédito SerpApi (BYOK admin),
 * 1 busca real Meta+Google (admin). Sem ações destrutivas.
 *
 * Pendências cobertas:
 *   L1 — Apify Top Ads COM termo: payload keywords[] + evidência do campo
 *        `keyword` por record na resposta bruta do ator
 *   L2 — SerpApi COM start_date/end_date Yyyymmdd: URL aceita e responde 200
 *   L3 — topads SEM token Apify (demo.pro): erro didático 40101, sem crash
 *   L4 — regressão: 1 chamada REAL de search() (Meta + Google) com cota
 *   L5 — discover SEM termo (admin, providers todos): só o TikTok traz ads,
 *        Meta/Google ganham hint "Informe um termo..." e NENHUMA cota é consumida
 *
 * Uso:
 *   docker cp tests/adspy/adspy-live-test.php afiliafacil:/app/tests/adspy/
 *   docker exec afiliafacil php tests/adspy/adspy-live-test.php
 *
 * Exit 0 = tudo ok; 1 = há falhas.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
set_time_limit(300);

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl indisponível\n");
    exit(2);
}

require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyKeys.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyQuota.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyManager.php';

$BASE = 'http://localhost:9876';
$ADMIN = ['email' => 'admin@afiliafacil.com', 'password' => 'admin123'];
$PRO = ['email' => 'demo.pro@afiliafacil.com', 'password' => 'Pro.Demo@2026'];
$ADMIN_ID = 1;

function dbPdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_DATABASE') ?: 'afiliafacil'),
            getenv('DB_APP_USER') ?: 'afiliafacil_app',
            getenv('DB_APP_PASSWORD') ?: ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

$PRO_ID = (int)(dbPdo()->query("SELECT id FROM users WHERE email = 'demo.pro@afiliafacil.com'")->fetchColumn() ?: 0);

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = [];
$GLOBALS['__section'] = '';

function section(string $name): void
{
    $GLOBALS['__section'] = $name;
    echo "\n== {$name} ==\n";
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  [OK] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $GLOBALS['__fail'][] = $GLOBALS['__section'] . ' :: ' . $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function info(string $msg): void
{
    echo "  [INFO] {$msg}\n";
}

function http(string $method, string $url, array $post = null, string $cookieFile = '', int $timeout = 90): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ]);
    if ($cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($method === 'GET') curl_setopt($ch, CURLOPT_HTTPGET, true);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) return ['status' => 0, 'body' => '', 'error' => "curl #{$errno}: {$err}"];
    return ['status' => $status, 'body' => substr($raw, $headerSize), 'error' => null];
}

function login(array $acct): string
{
    global $BASE;
    $jar = sys_get_temp_dir() . '/adspy-live-' . md5($acct['email']) . '.cookie';
    @unlink($jar);
    http('POST', $BASE . '/login', ['email' => $acct['email'], 'password' => $acct['password']], $jar, 20);
    return $jar;
}

function jsonBody(string $body): array
{
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
}

// ───────────────────────── snapshot + limpeza ─────────────────────────
$pdo = dbPdo();
$snapMaxSearchId = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM ad_spy_searches')->fetchColumn() ?: 0);
$healthSnap = [];
foreach (['meta', 'google', 'tiktok'] as $pid) {
    // Settings devolve o JSON bruto gravado pelo AdSpyHealth — restaurar RAW.
    $healthSnap[$pid] = (string)Settings::get('adspy_health_' . $ADMIN_ID . '_' . $pid, '');
}

function cleanupQuota(PDO $pdo, int $uid): void
{
    $pdo->exec("DELETE FROM ad_spy_searches WHERE user_id = {$uid} AND query LIKE 'qa-live%'");
}
cleanupQuota($pdo, $ADMIN_ID);
cleanupQuota($pdo, $PRO_ID);

// ───────────────────────── L1: Apify Top Ads COM termo ─────────────────────────
section('L1. Apify Top Ads COM termo (live) — keywords[] + campo keyword por record');

$apifyToken = AdSpyKeys::apify($ADMIN_ID);
if ($apifyToken === '') {
    ok('BYOK Apify do admin presente', false, 'sem token Apify — pendência não executável');
} else {
    $probe = new class extends TikTokApifyProvider {
        public array $calls = [];
        protected function httpPost(string $url, array $data, array $headers = []): ?string
        {
            $body = parent::httpPost($url, $data, $headers);
            $this->calls[] = ['url' => $url, 'payload' => $data, 'body' => $body];
            return $body;
        }
    };

    $t0 = microtime(true);
    // 'fogao' não tem Top Ads no período (vazio legítimo, validado com probe crua);
    // 'curso' retorna records e ecoa o campo `keyword` por record.
    $res = $probe->search('curso', [
        'user_id' => $ADMIN_ID,
        'max_items' => 5,          // limit baixo (custo ~US$0.11/1000 records)
        'period' => 30,
        'order_by' => 'ctr',
        'country' => 'BR',
    ]);
    $elapsed = microtime(true) - $t0;

    $sent = $probe->calls[0]['payload'] ?? [];
    ok('payload enviado contém keywords=["curso"]', ($sent['keywords'] ?? null) === ['curso'], 'payload=' . json_encode($sent, JSON_UNESCAPED_UNICODE));
    ok('sem erro na chamada live', empty($res['error']), 'error=' . substr((string)($res['error'] ?? ''), 0, 140));
    ok('ads retornados', count($res['ads'] ?? []) > 0, 'total=' . count($res['ads'] ?? []) . sprintf(' (%.1fs)', $elapsed));

    // Evidência (pendência do dev): o ator ecoa o campo `keyword` em CADA record
    $raw = $probe->calls[0]['body'] ?? '';
    $records = json_decode($raw, true);
    $records = is_array($records) ? $records : [];
    if (isset($records['data']) && is_array($records['data'])) $records = $records['data'];
    $withKeyword = 0;
    $keywordValues = [];
    foreach ($records as $rec) {
        if (is_array($rec) && array_key_exists('keyword', $rec)) {
            $withKeyword++;
            $keywordValues[] = (string)($rec['keyword'] ?? '');
        }
    }
    info('records brutos do ator: ' . count($records) . ' | com campo keyword: ' . $withKeyword);
    ok('evidência: records trazem campo keyword = termo buscado',
        count($records) > 0 && $withKeyword === count($records) && array_unique($keywordValues) === ['curso'],
        'keyword values=' . json_encode(array_unique($keywordValues), JSON_UNESCAPED_UNICODE));
    if (count($records) > 0) {
        info('chaves do 1º record: ' . implode(',', array_keys((array)$records[0])));
    }

    // Filtro funcionou? (se há ads, o ator aplicou o filtro — e/ou keyword ecoado)
    $adsSample = array_slice($res['ads'] ?? [], 0, 2);
    foreach ($adsSample as $ad) {
        info('ad: ' . json_encode([
            'adv' => mb_substr((string)($ad['advertiser'] ?? ''), 0, 30),
            'text' => mb_substr((string)($ad['text'] ?? ''), 0, 60),
        ], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────── L2: SerpApi COM start_date/end_date ─────────────────────────
section('L2. SerpApi COM start_date/end_date Yyyymmdd (live, BYOK admin)');

$serpKey = AdSpyKeys::serpapi($ADMIN_ID);
if ($serpKey === '') {
    ok('BYOK SerpApi do admin presente', false, 'sem chave SerpApi — pendência não executável');
} else {
    $gProbe = new class extends GoogleTransparencyProvider {
        public array $urls = [];
        protected function httpGet(string $url, array $headers = []): ?string
        {
            $this->urls[] = $url; // captura a URL e NÃO dispara (o teste dispara à parte)
            return '{"__probe__":true}';
        }
    };

    $start = date('Ymd', strtotime('-7 days'));
    $end = date('Ymd');
    // A engine busca por DOMÍNIO do anunciante (termos soltos → vazio honesto,
    // já tratado pelo hint do provider) — usar domínio evidencia resultados reais.
    $gProbe->search('hotmart.com', ['serpapi_key' => $serpKey, 'start_date' => $start, 'end_date' => $end]);
    $url = $gProbe->urls[0] ?? '';
    ok('URL gerada contém start_date/end_date Yyyymmdd', str_contains($url, "start_date={$start}") && str_contains($url, "end_date={$end}"), 'url=' . preg_replace('/api_key=[^&]+/', 'api_key=***', $url));

    // Dispara a URL capturada (crédito SerpApi) para provar que aceita os params
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    ok('SerpApi responde 200 com JSON válido', $status === 200 && is_array($json), 'status=' . $status . ' err=' . ($cerr ?: '-'));

    $sparams = is_array($json) ? (array)($json['search_parameters'] ?? []) : [];
    info('search_parameters ecoados: ' . json_encode([
        'text' => $sparams['text'] ?? null,
        'start_date' => $sparams['start_date'] ?? null,
        'end_date' => $sparams['end_date'] ?? null,
    ], JSON_UNESCAPED_UNICODE));
    ok('params start_date/end_date aceitos (ecoados no echo)',
        isset($sparams['start_date'], $sparams['end_date']) && (string)$sparams['start_date'] === $start && (string)$sparams['end_date'] === $end,
        'start=' . ($sparams['start_date'] ?? '(ausente)') . ' end=' . ($sparams['end_date'] ?? '(ausente)'));

    $err = is_array($json) ? ($json['error'] ?? null) : null;
    $adsCount = is_array($json) ? count($json['ad_creatives'] ?? []) : 0;
    ok('sem erro de parâmetro/auth do SerpApi', $err === null, 'error=' . json_encode($err, JSON_UNESCAPED_UNICODE));
    ok('resultados reais com a janela de 7 dias (ad_creatives > 0)', $adsCount > 0, 'ad_creatives=' . $adsCount);
    if ($adsCount > 0) {
        $first = (array)($json['ad_creatives'][0] ?? []);
        info('1º ad_creative: ' . json_encode(array_intersect_key($first, array_flip(['advertiser', 'first_shown', 'format']))));
    }
}

// ───────────────────────── L3: topads SEM token Apify (demo.pro) ─────────────────────────
section('L3. topads SEM token Apify (demo.pro) → 40101 didático, sem crash');

$jarPro = login($PRO);
$t0 = microtime(true);
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'top_ads', // alias de propósito
    'query' => '',
    'providers' => 'tiktok',
    'period' => 30,
    'limit' => 30,
], $jarPro, 60);
$elapsed = microtime(true) - $t0;
$disc = jsonBody($r['body']);
$ttErr = (string)($disc['errors']['tiktok'] ?? ($disc['results']['tiktok']['error'] ?? ''));
ok('HTTP 200 (não crashou)', $r['status'] === 200, sprintf('status=%d (%.1fs)', $r['status'], $elapsed));
ok('erro didático citando 40101/sessão + token Apify', $ttErr !== '' && (str_contains($ttErr, '40101') || str_contains($ttErr, 'sessão')) && stripos($ttErr, 'Apify') !== false, 'error=' . mb_substr($ttErr, 0, 160));
ok('sem "Erro inesperado" (exceção vazou)', !str_contains($ttErr, 'Erro inesperado'), 'error=' . mb_substr($ttErr, 0, 100));

// ───────────────────────── L4: regressão search() com 1 chamada real ─────────────────────────
section('L4. Regressão search() — 1 chamada REAL (Meta + Google, admin)');

$jarAdmin = login($ADMIN);
$qBefore = jsonBody(http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarAdmin, 15)['body']);
$usedBefore = (int)($qBefore['search']['used'] ?? 0);

$t0 = microtime(true);
$r = http('POST', $BASE . '/admin/api/adspy.php?action=search', [
    'query' => 'fogao a gas',
    'providers' => 'meta,google',
    'country' => 'BR',
], $jarAdmin, 120);
$elapsed = microtime(true) - $t0;
$res = jsonBody($r['body']);

ok('HTTP 200', $r['status'] === 200, sprintf('status=%d (%.1fs)', $r['status'], $elapsed));
$keys = array_keys($res['results'] ?? []);
ok('results por pid (meta, google)', $keys === ['meta', 'google'] || $keys === ['google', 'meta'], 'keys=' . implode(',', $keys));
ok('sem erro de quota', empty($res['errors']['quota']), 'errors=' . json_encode(array_keys($res['errors'] ?? [])));

$metaAds = count($res['results']['meta']['ads'] ?? []);
$googleAds = count($res['results']['google']['ads'] ?? []);
info("ads meta={$metaAds} google={$googleAds}");
$gErr = (string)($res['results']['google']['error'] ?? '');
$mErr = (string)($res['results']['meta']['error'] ?? '');
if ($gErr !== '') info('google: ' . mb_substr($gErr, 0, 140));
if ($mErr !== '') info('meta: ' . mb_substr($mErr, 0, 140));

// Pelo menos 1 das redes respondeu com anúncios (regressão da rota search)
ok('pelo menos 1 rede respondeu com anúncios', ($metaAds + $googleAds) > 0, "total=" . ($metaAds + $googleAds));

$qAfter = jsonBody(http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarAdmin, 15)['body']);
$usedAfter = (int)($qAfter['search']['used'] ?? 0);
info("cota admin usada: {$usedBefore} → {$usedAfter} (plano premium é ilimitado)");

// ───────────────────────── L5: discover SEM termo (só TikTok consulta) ─────────────────────────
section('L5. discover SEM termo (admin, providers todos) — só TikTok com ads, sem cota');

$searchesBefore = (int)$pdo->query(
    'SELECT COUNT(*) FROM ad_spy_searches WHERE user_id = ' . $ADMIN_ID . ' AND id > ' . $snapMaxSearchId
)->fetchColumn();

$t0 = microtime(true);
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'trends',
    'query' => '',
    'providers' => 'meta,google,tiktok',
    'country' => 'BR',
    'period' => 7,
], $jarAdmin, 180);
$elapsed = microtime(true) - $t0;
$disc = jsonBody($r['body']);
$res = $disc['results'] ?? [];

ok('HTTP 200 + success (sem termo não pode depender de cota)', $r['status'] === 200 && !empty($disc['success']), sprintf('status=%d success=%s (%.1fs)', $r['status'], json_encode($disc['success'] ?? null), $elapsed));

$hint = 'Informe um termo para ver anúncios do Meta/Google.';
ok('Meta ganha o hint didático (não erro)',
    array_key_exists('meta', $res) && ($res['meta']['error'] ?? null) === null && ($res['meta']['hint'] ?? '') === $hint,
    'hint=' . mb_substr((string)($res['meta']['hint'] ?? '(ausente)'), 0, 60));
ok('Google ganha o hint didático (não erro)',
    array_key_exists('google', $res) && ($res['google']['error'] ?? null) === null && ($res['google']['hint'] ?? '') === $hint,
    'hint=' . mb_substr((string)($res['google']['hint'] ?? '(ausente)'), 0, 60));

$ttAds = count($res['tiktok']['ads'] ?? []);
info("tiktok ads={$ttAds} source=" . (string)($res['tiktok']['source_label'] ?? '-'));
ok('só o TikTok traz anúncios reais (sem termo)', $ttAds > 0, 'tiktok=' . $ttAds);
ok('erros vazios (sem falha, sem cota)', empty($disc['errors']), 'errors=' . json_encode($disc['errors'] ?? []));

$searchesAfter = (int)$pdo->query(
    'SELECT COUNT(*) FROM ad_spy_searches WHERE user_id = ' . $ADMIN_ID . ' AND id > ' . $snapMaxSearchId
)->fetchColumn();
ok('NENHUMA cota consumida na descoberta sem termo', $searchesAfter === $searchesBefore,
    "linhas de cota criadas: " . ($searchesAfter - $searchesBefore));

// ───────────────────────── snapshot/restore health + cleanup ─────────────────────────
foreach ($healthSnap as $pid => $raw) {
    Settings::set('adspy_health_' . $ADMIN_ID . '_' . $pid, (string)$raw);
}
cleanupQuota($pdo, $ADMIN_ID);
cleanupQuota($pdo, $PRO_ID);
// Remove QUALQUER linha de cota gerada por esta suíte (qualquer query)
$pdo->exec('DELETE FROM ad_spy_searches WHERE id > ' . $snapMaxSearchId . ' AND user_id IN (' . $ADMIN_ID . ',' . $PRO_ID . ')');
info('health do admin restaurado ao snapshot e cotas de teste removidas');

// ───────────────────────── veredito ─────────────────────────
echo "\n=== RESULTADO LIVE ===\n";
echo 'Passou: ' . $GLOBALS['__pass'] . ' | Falhou: ' . count($GLOBALS['__fail']) . "\n";
foreach ($GLOBALS['__fail'] as $f) {
    echo "  [FAIL] {$f}\n";
}
echo count($GLOBALS['__fail']) === 0 ? "\nADSPY LIVE OK\n" : "\nADSPY LIVE COM FALHAS\n";
exit(count($GLOBALS['__fail']) === 0 ? 0 : 1);
