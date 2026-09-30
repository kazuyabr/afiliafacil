<?php
/**
 * Testes de aceite da API HTTP do AdSpy — /admin/api/adspy.php (descoberta multiredes).
 *
 * Determinístico: NÃO faz chamadas de rede externa (sem termo só TikTok responde —
 * user demo sem token Apify → erro didático; e o caso de cota esgotada bloqueia
 * antes de qualquer consulta). A regressão "1 chamada real" está em adspy-live-test.php.
 *
 * Uso (dentro do container; rodar a partir da raiz do app):
 *   docker cp tests/adspy/adspy-api-test.php afiliafacil:/app/tests/adspy/
 *   docker exec afiliafacil php tests/adspy/adspy-api-test.php
 *
 * Exit 0 = tudo ok; 1 = há falhas.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl indisponível\n");
    exit(2);
}

$BASE = 'http://localhost:9876';
$ACCOUNTS = [
    'pro' => ['email' => 'demo.pro@afiliafacil.com', 'password' => 'Pro.Demo@2026'],
];

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

/** HTTP com cookie jar (sessão). */
function http(string $method, string $url, array $post = null, string $cookieFile = '', int $timeout = 25): array
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
    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'error' => "curl #{$errno}: {$err}"];
    }
    return ['status' => $status, 'body' => substr($raw, $headerSize), 'error' => null];
}

function jsonBody(string $body): array
{
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
}

$cookieDir = sys_get_temp_dir();
$login = function (string $tag, array $acct) use ($BASE, $cookieDir): string {
    $jar = $cookieDir . '/adspy-api-' . $tag . '.cookie';
    @unlink($jar);
    $r = http('POST', $BASE . '/login', ['email' => $acct['email'], 'password' => $acct['password']], $jar, 20);
    return $jar;
};

// ───────────────────────── 1. autenticação ─────────────────────────
section('1. Autenticação');
$r = http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, '', 15);
$bodyAuth = json_decode($r['body'], true) ?: [];
ok('sem sessão → 401 "Não autenticado"', $r['status'] === 401 && ($bodyAuth['error'] ?? '') === 'Não autenticado', 'status=' . $r['status'] . ' error=' . ($bodyAuth['error'] ?? '(ausente)'));

$jarPro = $login('pro', $ACCOUNTS['pro']);
$r = http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarPro, 15);
$quota = jsonBody($r['body']);
ok('login demo.pro → quota ok (limit 30)', $r['status'] === 200 && !empty($quota['success']) && (int)($quota['search']['limit'] ?? 0) === 30, 'status=' . $r['status'] . ' limit=' . ($quota['search']['limit'] ?? '?') . ' used=' . ($quota['search']['used'] ?? '?'));

$usedBefore = (int)($quota['search']['used'] ?? 0);

// ───────────────────────── 2. discover sem termo ─────────────────────────
section('2. discover SEM termo (hints + cota intacta)');
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'trends',
    'query' => '',
    'providers' => 'meta,google',
    'period' => 7,
    'limit' => 30,
], $jarPro, 25);
$disc = jsonBody($r['body']);
$metaHint = (string)($disc['results']['meta']['hint'] ?? '');
$googleHint = (string)($disc['results']['google']['hint'] ?? '');
ok('HTTP 200 + success', $r['status'] === 200 && !empty($disc['success']), 'status=' . $r['status']);
ok('meta traz hint "Informe um termo..."', $metaHint === 'Informe um termo para ver anúncios do Meta/Google.', 'hint=' . substr($metaHint, 0, 80));
ok('google traz o mesmo hint', $googleHint === 'Informe um termo para ver anúncios do Meta/Google.', 'hint=' . substr($googleHint, 0, 80));
ok('error null (hint não vira erro)', array_key_exists('error', $disc['results']['meta'] ?? []) && ($disc['results']['meta']['error'] ?? 'x') === null || !isset($disc['results']['meta']['error']), 'meta=' . json_encode(array_keys($disc['results']['meta'] ?? [])));

$r2 = http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarPro, 15);
$usedAfter = (int)(jsonBody($r2['body'])['search']['used'] ?? -1);
ok('cota inalterada após descoberta sem termo', $usedAfter === $usedBefore, "antes={$usedBefore} depois={$usedAfter}");

// ───────────────────────── 3. sanitização de providers (string com vírgulas) ─────────────────────────
section('3. providers string com vírgulas (o explode é do API)');
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'trends',
    'query' => '',
    'providers' => 'bogus,meta,naoexiste,google',
    'period' => 7,
    'limit' => 30,
], $jarPro, 25);
$disc = jsonBody($r['body']);
$keys = array_keys($disc['results'] ?? []);
sort($keys);
ok("['bogus,meta,naoexiste,google'] → só meta+google", $keys === ['google', 'meta'], 'keys=' . implode(',', $keys));
ok('nenhum provider inválido vaza', !in_array('bogus', $keys, true) && !in_array('naoexiste', $keys, true));

// providers vazio → whitelist completa
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'trends',
    'query' => '',
    'providers' => '',
    'period' => 7,
], $jarPro, 25);
$disc = jsonBody($r['body']);
$keys = array_keys($disc['results'] ?? []);
sort($keys);
ok('providers vazio → fallback whitelist (meta,google,tiktok)', $keys === ['google', 'meta', 'tiktok'], 'keys=' . implode(',', $keys));

// ───────────────────────── 4. alias de modo via API ─────────────────────────
section('4. alias de modo via API');
foreach (['top_ads' => 'topads', 'top-ads' => 'topads', 'hashtags' => 'trends'] as $in => $expected) {
    $r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
        'mode' => $in,
        'query' => '',
        'providers' => 'meta',
    ], $jarPro, 25);
    $disc = jsonBody($r['body']);
    ok("mode '{$in}' → '{$expected}'", ($disc['mode'] ?? '') === $expected, 'mode=' . ($disc['mode'] ?? '(ausente)'));
}

// ───────────────────────── 5. cota esgotada bloqueia sem rede ─────────────────────────
section('5. cota esgotada (demo.pro, limite 30) → errors.quota, sem consulta');
$pdo = null;
try {
    $pdo = new PDO(
        'pgsql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_DATABASE') ?: 'afiliafacil'),
        getenv('DB_APP_USER') ?: 'afiliafacil_app',
        getenv('DB_APP_PASSWORD') ?: ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    ok('conexão ao banco para pré-fill de cota', false, $e->getMessage());
}

if ($pdo) {
    $stmt = $pdo->query("SELECT id FROM users WHERE email = 'demo.pro@afiliafacil.com'");
    $proId = (int)$stmt->fetchColumn();
    $limit = 30;
    $pdo->exec("DELETE FROM ad_spy_searches WHERE user_id = {$proId} AND query = 'qa-api-pre-fill'");
    $ins = $pdo->prepare("INSERT INTO ad_spy_searches (user_id, kind, query, provider, results_count, from_cache, created_at) VALUES (?, 'search', 'qa-api-pre-fill', 'meta', 0, false, ?)");
    for ($i = 0; $i < $limit; $i++) {
        $ins->execute([$proId, date('Y-m-d H:i:s')]);
    }

    $t0 = microtime(true);
    $r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
        'mode' => 'trends',
        'query' => 'fogao a gas', // COM termo — só cota pode bloquear (ou consultaria a rede)
        'providers' => 'meta',
        'period' => 7,
        'limit' => 30,
    ], $jarPro, 30);
    $elapsed = microtime(true) - $t0;
    $disc = jsonBody($r['body']);

    ok('errors.quota presente', !empty($disc['errors']['quota']), 'errors=' . json_encode($disc['errors'], JSON_UNESCAPED_UNICODE));
    ok('success=false quando cota esgotada', isset($disc['success']) && $disc['success'] === false, 'success=' . var_export($disc['success'] ?? null, true));
    ok('results vazio (providers NÃO consultados)', empty($disc['results']), 'results=' . json_encode(array_keys($disc['results'] ?? [])));
    ok('resposta rápida (bloqueio antes da rede)', $elapsed < 5, sprintf('%.2fs', $elapsed));
    ok('quota.allowed=false', ($disc['quota']['allowed'] ?? true) === false, 'allowed=' . var_export($disc['quota']['allowed'] ?? null, true));

    $r = http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarPro, 15);
    $usedNow = (int)(jsonBody($r['body'])['search']['used'] ?? -1);
    ok('cota não cresceu com o bloqueio', $usedNow === $limit, "used={$usedNow} limit={$limit}");

    $pdo->exec("DELETE FROM ad_spy_searches WHERE user_id = {$proId} AND query = 'qa-api-pre-fill'");
    $r = http('GET', $BASE . '/admin/api/adspy.php?action=quota', null, $jarPro, 15);
    $usedClean = (int)(jsonBody($r['body'])['search']['used'] ?? -1);
    ok('pré-fill removido (cleanup)', $usedClean < $limit, "used={$usedClean}");
}

// ───────────────────────── 6. moderação via API ─────────────────────────
section('6. moderação via API (query bloqueada)');
$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'trends',
    'query' => 'como assaltar um banco',
    'providers' => 'meta',
    'period' => 7,
], $jarPro, 25);
$disc = jsonBody($r['body']);
ok('errors.query com texto didático', !empty($disc['errors']['query']) && str_contains((string)$disc['errors']['query'], 'atividades ilegais'), 'errors=' . json_encode($disc['errors'], JSON_UNESCAPED_UNICODE));
ok('results vazio no bloqueio', empty($disc['results']), 'results=' . json_encode(array_keys($disc['results'] ?? [])));

// ───────────────────────── 7. ação inválida / modo inválido ─────────────────────────
section('7. casos-limite');
$r = http('GET', $BASE . '/admin/api/adspy.php?action=invented', null, $jarPro, 15);
$errBody = json_decode($r['body'], true) ?: [];
ok('action inválida → "Ação inválida"', ($errBody['error'] ?? '') === 'Ação inválida', 'error=' . ($errBody['error'] ?? '(ausente)'));

$r = http('POST', $BASE . '/admin/api/adspy.php?action=discover', [
    'mode' => 'modo-inexistente',
    'query' => '',
    'providers' => 'meta',
], $jarPro, 25);
$disc = jsonBody($r['body']);
ok('mode inventado cai em trends (default)', ($disc['mode'] ?? '') === 'trends', 'mode=' . ($disc['mode'] ?? '(ausente)'));

// ───────────────────────── 8. feature gating (plano sem adspy) ─────────────────────────
// (sem conta sem feature disponível nas demos — verificado no unitário via Plans;
//  aqui garantimos ao menos que 401/403 continuam funcionando sem sessão.)

// ───────────────────────── veredito ─────────────────────────
echo "\n=== RESULTADO API ===\n";
echo 'Passou: ' . $GLOBALS['__pass'] . ' | Falhou: ' . count($GLOBALS['__fail']) . "\n";
foreach ($GLOBALS['__fail'] as $f) {
    echo "  [FAIL] {$f}\n";
}
echo count($GLOBALS['__fail']) === 0 ? "\nADSPY API OK\n" : "\nADSPY API COM FALHAS\n";
exit(count($GLOBALS['__fail']) === 0 ? 0 : 1);
