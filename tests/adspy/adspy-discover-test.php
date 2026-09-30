<?php
/**
 * Testes de regressão/aceite do AdSpy — descoberta multiredes (Trends/Top Ads).
 *
 * Escopo (determinístico, SEM rede externa — todo HTTP é interceptado por probes):
 *   1. alias de modo (top_ads / top-ads / hashtags / inválido)
 *   2. sanitização de providers (whitelist, vazio, não-array)
 *   3. discover SEM termo → Meta/Google ganham hint e NÃO são consultados; cota intacta
 *   4. discover COM termo  → 1 cota por rede que responde; cache 24h isento;
 *      rede com erro não consome; janelas de data (Meta started_after / Google
 *      start_date+end_date em Trends; ausentes em Top Ads)
 *   5. cota esgotada → errors.quota, providers NÃO chamados, allowed=false
 *   6. moderação bloqueia sem consumir cota
 *   7. discover NÃO grava health (AdSpyHealth)
 *   8. payload do Apify usa `keywords: [termo]` (não `keyword`) + fallback
 *      de anunciante vazio → "Anunciante desconhecido"
 *   9. Google SerpApi aceita start_date/end_date normalizados em Yyyymmdd
 *  10. Meta API envia ad_delivery_date_min quando há started_after
 *  11. regressão: search() por termo continua com o comportamento antigo
 *
 * NOTA: o check "google sem chave NÃO deveria consumir cota" (seção 4.7)
 * cobre o BUG-1, já CORRIGIDO na produção (flag `no_charge` no
 * AdSpyManager::discover — resposta didática sem consulta externa não
 * consome cota); o check fica verde como regressão.
 *
 * Rodar (copiar para o container antes, se necessário):
 *   docker cp tests/adspy/adspy-discover-test.php afiliafacil:/app/tests/adspy/
 *   docker exec afiliafacil php tests/adspy/adspy-discover-test.php
 *
 * Exit code 0 = todos os checks passaram; 1 = há falhas.
 */

require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Moderation/ContentModerator.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyQuota.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyHealth.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyKeys.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyManager.php';

// ───────────────────────── helpers de veredito ─────────────────────────
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

// ───────────────────────── cenário ─────────────────────────
// Usuário sintético (nunca toca nas contas demo/admin).
$UID = 999999001;
$PLAN_UNLIMITED = 'master'; // 300 buscas — não atrapalha os checks de consumo
$PLAN_LIMITED = 'trial';    // 3 buscas — usado no check de cota esgotada

function db(): ?PDO
{
    static $pdo = 'unset';
    if ($pdo !== 'unset') return $pdo instanceof PDO ? $pdo : null;
    try {
        $pdo = new PDO(
            'pgsql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_DATABASE') ?: 'afiliafacil'),
            getenv('DB_APP_USER') ?: 'afiliafacil_app',
            getenv('DB_APP_PASSWORD') ?: ''
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo instanceof PDO ? $pdo : null;
}

function quotaUsed(int $uid, string $plan = 'master'): int
{
    return AdSpyQuota::check($uid, $plan, AdSpyQuota::KIND_SEARCH)['used'];
}

/** resultado padrão de provider com N ads (mesma forma do normalizeAd). */
function adsStub(int $n = 2): array
{
    $ads = [];
    for ($i = 1; $i <= $n; $i++) {
        $ads[] = [
            'id' => 'qa' . $i, 'provider' => 'meta', 'advertiser' => 'Loja X',
            'title' => '', 'text' => 'anuncio ' . $i, 'cta' => '',
            'media_type' => 'image', 'media_url' => '', 'thumbnail' => '',
            'landing_page' => '', 'platforms' => ['facebook'],
            'started_at' => null, 'ended_at' => null, 'status' => 'active', 'link' => '',
        ];
    }
    return ['ads' => $ads, 'total' => count($ads), 'error' => null];
}

/** AdSpyManager com providers injetados via reflection (sem alterar produção). */
function managerWith(array $stubs): AdSpyManager
{
    $m = new AdSpyManager();
    $ref = new ReflectionProperty(AdSpyManager::class, 'providers');
    $ref->setValue($m, $stubs);
    return $m;
}

/** Provider falso: registra chamadas (query + options) e devolve resultado fixo. */
class StubProvider
{
    public array $calls = [];
    public function __construct(private array $result)
    {
    }
    public function search(string $query, array $options = []): array
    {
        $this->calls[] = ['query' => $query, 'options' => $options];
        return $this->result;
    }
}

/** TikTokApifyProvider com HTTP interceptado — captura o payload enviado. */
class ProbeTikTokApify extends TikTokApifyProvider
{
    public ?array $lastPayload = null;
    /** @var string[] respostas JSON em fila */
    public array $responses = [];

    protected function httpPost(string $url, array $data, array $headers = []): ?string
    {
        $this->lastPayload = $data;
        if (empty($this->responses)) return null;
        return array_shift($this->responses);
    }
}

/** GoogleTransparencyProvider com HTTP interceptado — captura as URLs chamadas. */
class ProbeGoogle extends GoogleTransparencyProvider
{
    public array $urls = [];
    public string $response = '{"ad_creatives":[{"ad_creative_id":"g1","advertiser":"Loja","format":"display","first_shown":1757000000,"details_link":"https://example.com"}]}';

    protected function httpGet(string $url, array $headers = []): ?string
    {
        $this->urls[] = $url;
        return $this->response;
    }
}

/** MetaAdLibraryProvider com HTTP interceptado — captura as URLs chamadas. */
class ProbeMeta extends MetaAdLibraryProvider
{
    public array $urls = [];
    protected function httpGet(string $url, array $headers = []): ?string
    {
        $this->urls[] = $url;
        return '{"data":[]}';
    }
}

// ───────────────────────── snapshots de limpeza ─────────────────────────
$p = db();
$cacheStartId = 0;
$hasDb = $p !== null && Database::available();
if ($hasDb) {
    try {
        $cacheStartId = (int)$p->query('SELECT COALESCE(MAX(id), 0) FROM ad_spy_cache')->fetchColumn();
    } catch (Throwable $e) {
        $cacheStartId = 0;
    }
}

function cleanupSearches(int $uid): void
{
    $p = db();
    if (!$p) return;
    foreach (['ad_spy_searches', 'moderation_events'] as $table) {
        try {
            $p->exec("DELETE FROM {$table} WHERE user_id = {$uid}");
        } catch (Throwable $e) {
        }
    }
}

function cleanupAll(int $uid, int $cacheStartId): void
{
    cleanupSearches($uid);
    $p = db();
    if ($p) {
        try {
            $p->exec("DELETE FROM ad_spy_cache WHERE id > {$cacheStartId}");
        } catch (Throwable $e) {
        }
    }
    foreach (['meta', 'google', 'tiktok'] as $pid) {
        Settings::set('adspy_health_' . $uid . '_' . $pid, '');
    }
}

cleanupAll($UID, $cacheStartId);

section('0. Pré-requisitos');
ok('Banco disponível (cache/cota reais)', $hasDb);
ok('Cota inicial do usuário sintético = 0', quotaUsed($UID, $PLAN_UNLIMITED) === 0, 'used=' . quotaUsed($UID, $PLAN_UNLIMITED));

$RUN = 'qa-adspy-' . substr(md5(php_uname('n') . microtime(true)), 0, 8);
$qNew = fn(string $tag): string => $RUN . '-' . $tag;

// ───────────────────────── 1. alias de modo ─────────────────────────
section('1. Alias de modo (discover)');
foreach (['top_ads' => 'topads', 'top-ads' => 'topads', 'TOPADS' => 'topads', 'hashtags' => 'trends', 'inventado' => 'trends'] as $in => $expected) {
    $m = managerWith(['meta' => new StubProvider(adsStub(1))]);
    $r = $m->discover($UID, $PLAN_UNLIMITED, $in, ['query' => '', 'providers' => ['meta']]);
    ok("mode '{$in}' → '{$expected}'", ($r['mode'] ?? '') === $expected, 'mode=' . ($r['mode'] ?? '(ausente)'));
}

// ───────────────────────── 2. sanitização de providers ─────────────────────────
section('2. Sanitização de providers');
$m = managerWith(['meta' => new StubProvider(adsStub(1))]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => ['bogus', 'meta']]);
ok("['bogus','meta'] → só meta", array_keys($r['results']) === ['meta'], 'keys=' . implode(',', array_keys($r['results'])));

$m = managerWith(['meta' => new StubProvider(adsStub(1)), 'google' => new StubProvider(adsStub(1))]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => []]);
ok('providers [] → fallback para a whitelist completa', array_keys($r['results']) === ['meta', 'google', 'tiktok'], 'keys=' . implode(',', array_keys($r['results'])));

$m = managerWith(['meta' => new StubProvider(adsStub(1)), 'google' => new StubProvider(adsStub(1))]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => 'nao-e-array']);
ok('providers não-array → whitelist completa (a explosão da string é feita pelo API)', array_keys($r['results']) === ['meta', 'google', 'tiktok'], 'keys=' . implode(',', array_keys($r['results'])));

// ───────────────────────── 3. discover SEM termo ─────────────────────────
section('3. Discover SEM termo (só TikTok consulta; Meta/Google hint)');
$HINT = 'Informe um termo para ver anúncios do Meta/Google.';
$stubMeta = new StubProvider(adsStub(3));
$stubGoogle = new StubProvider(adsStub(3));
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith(['meta' => $stubMeta, 'google' => $stubGoogle]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '   ', 'providers' => ['meta', 'google']]);

ok('Meta ganha o hint "Informe um termo..."', ($r['results']['meta']['hint'] ?? '') === $HINT, 'hint=' . ($r['results']['meta']['hint'] ?? '(nenhum)'));
ok('Google ganha o hint "Informe um termo..."', ($r['results']['google']['hint'] ?? '') === $HINT, 'hint=' . ($r['results']['google']['hint'] ?? '(nenhum)'));
ok('Meta/Google NÃO são consultados sem termo', count($stubMeta->calls) === 0 && count($stubGoogle->calls) === 0, 'meta=' . count($stubMeta->calls) . ' google=' . count($stubGoogle->calls));
ok('hint não é erro (error null)', empty($r['results']['meta']['error']) && empty($r['results']['google']['error']));
ok('errors vazio (sem cota, sem falha)', empty($r['errors']), 'errors=' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
ok('cota NÃO consumida sem termo', quotaUsed($UID, $PLAN_UNLIMITED) === $before, "antes={$before} depois=" . quotaUsed($UID, $PLAN_UNLIMITED));

// providers REAIS (user sintético sem token Apify — sem qualquer chamada de rede)
$m2 = new AdSpyManager();
$before2 = quotaUsed($UID, $PLAN_UNLIMITED);
$r2 = $m2->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => ['meta', 'google', 'tiktok']]);
ok('Meta/Google: hint também com providers reais', ($r2['results']['meta']['hint'] ?? '') === $HINT && ($r2['results']['google']['hint'] ?? '') === $HINT);
ok('TikTok sem token → erro didático citando Apify (não crash)', stripos((string)($r2['results']['tiktok']['error'] ?? ''), 'Apify') !== false, 'error=' . substr((string)($r2['results']['tiktok']['error'] ?? '(nenhum)'), 0, 90));
ok('cota intacta mesmo com providers reais', quotaUsed($UID, $PLAN_UNLIMITED) === $before2, "antes={$before2} depois=" . quotaUsed($UID, $PLAN_UNLIMITED));

// ───────────────────────── 4. discover COM termo ─────────────────────────
section('4. Discover COM termo (cota por rede + cache + janelas)');

// 4.1 rede consultada consome 1 cota, cacheia e Trends injeta started_after
$q1 = $qNew('c1');
$stubMeta = new StubProvider(adsStub(2));
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith(['meta' => $stubMeta]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q1, 'providers' => ['meta']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('rede que respondeu consome exatamente 1 cota', $after === $before + 1, "antes={$before} depois={$after}");
ok('ads retornados', count($r['results']['meta']['ads'] ?? []) === 2);
ok('sem erros', empty($r['errors']), 'errors=' . json_encode($r['errors']));
$expectedAfter = date('Y-m-d', strtotime('-7 days'));
ok('Trends/meta injeta started_after = hoje-7d (janela do período)', ($stubMeta->calls[0]['options']['started_after'] ?? '') === $expectedAfter, 'started_after=' . ($stubMeta->calls[0]['options']['started_after'] ?? '(ausente)') . " esperado={$expectedAfter}");

// 4.2 segunda chamada idêntica → cache 24h, sem nova cota e sem nova consulta
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$stubMetaB = new StubProvider(adsStub(2));
$mB = managerWith(['meta' => $stubMetaB]);
$rB = $mB->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q1, 'providers' => ['meta']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('2ª chamada sai do cache (cached=true)', ($rB['cached'] ?? false) === true, 'cached=' . var_export($rB['cached'] ?? null, true));
ok('cache 24h isento de cota', $after === $before, "antes={$before} depois={$after}");
ok('cache NÃO re-consultou a rede', count($stubMetaB->calls) === 0, 'calls=' . count($stubMetaB->calls));
ok('results do cache trazem os ads', count($rB['results']['meta']['ads'] ?? []) === 2);

// 4.3 duas redes → 2 cotas (uma por rede)
$q2 = $qNew('c2');
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith(['meta' => new StubProvider(adsStub(1)), 'google' => new StubProvider(adsStub(1))]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q2, 'providers' => ['meta', 'google']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('cada rede que respondeu consome 1 (2 redes → +2)', $after === $before + 2, "antes={$before} depois={$after}");
ok('ambas presentes e sem erros', isset($r['results']['meta'], $r['results']['google']) && empty($r['errors']), 'errors=' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));

// 4.4 Top Ads: SEM janela de datas; Trends Google: Yyyymmdd
$q3 = $qNew('c3');
$stubGoogle = new StubProvider(adsStub(1));
$m = managerWith(['google' => $stubGoogle]);
$m->discover($UID, $PLAN_UNLIMITED, 'topads', ['query' => $q3, 'providers' => ['google']]);
$gOpts = $stubGoogle->calls[0]['options'] ?? [];
ok('Top Ads: Google sem start_date/end_date', empty($gOpts['start_date']) && empty($gOpts['end_date']), 'start=' . var_export($gOpts['start_date'] ?? null, true) . ' end=' . var_export($gOpts['end_date'] ?? null, true));

$q4 = $qNew('c4');
$stubGoogle2 = new StubProvider(adsStub(1));
$m = managerWith(['google' => $stubGoogle2]);
$m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q4, 'providers' => ['google'], 'period' => 7]);
$gOpts2 = $stubGoogle2->calls[0]['options'] ?? [];
$expectedStart = date('Ymd', strtotime('-7 days'));
$expectedEnd = date('Ymd');
ok('Trends: Google recebe start_date/end_date Yyyymmdd', ($gOpts2['start_date'] ?? '') === $expectedStart && ($gOpts2['end_date'] ?? '') === $expectedEnd, 'start=' . ($gOpts2['start_date'] ?? '(ausente)') . ' end=' . ($gOpts2['end_date'] ?? '(ausente)'));

// 4.5 rede com erro → erro por pid e NÃO consome cota
$q5 = $qNew('c5');
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith(['meta' => new StubProvider(['ads' => [], 'total' => 0, 'error' => 'Meta: falhou'])]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q5, 'providers' => ['meta']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('provider com erro NÃO consome cota', $after === $before, "antes={$before} depois={$after}");
ok('erro reportado por pid', ($r['errors']['meta'] ?? '') === 'Meta: falhou', 'errors=' . json_encode($r['errors']));

// 4.6 resposta vazia SEM erro → consome (não é falha) e não cacheia
$q6 = $qNew('c6');
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith(['meta' => new StubProvider(['ads' => [], 'total' => 0, 'error' => null, 'empty' => true])]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q6, 'providers' => ['meta']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('vazio sem erro consome 1 cota e não cacheia', $after === $before + 1 && empty($r['cached']), "antes={$before} depois={$after}");

// 4.7 google "sem chave" vira hint na descoberta — o SerpApi NÃO é consultado,
//     portanto a cota não deveria ser consumida (ver BUG-1 no relatório do QA)
$q7 = $qNew('c7');
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$realGoogle = new AdSpyManager(); // providers reais; user sintético não tem SerpApi
$r7 = $realGoogle->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $q7, 'providers' => ['google']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
$gRes = $r7['results']['google'] ?? [];
$hintOk = array_key_exists('error', $gRes) && $gRes['error'] === null && isset($gRes['hint']) && $gRes['hint'] !== '';
ok('google sem chave vira hint (error null) na descoberta', $hintOk, 'error=' . json_encode($gRes['error'] ?? '(ausente)', JSON_UNESCAPED_UNICODE) . ' hint=' . substr((string)($gRes['hint'] ?? ''), 0, 80));
ok('BUG-1 check: google sem chave NÃO deveria consumir cota', $after === $before, "antes={$before} depois={$after} — consumiu " . ($after - $before) . " cota sem consultar o SerpApi");

// ───────────────────────── 5. cota esgotada ─────────────────────────
section('5. Cota esgotada → bloqueia SEM chamar providers');
cleanupSearches($UID); // zera o consumo dos checks anteriores (mesmo user, planos diferentes)
$p = db();
$limit = AdSpyQuota::limit($PLAN_LIMITED, AdSpyQuota::KIND_SEARCH); // trial = 3
if ($p) {
    $ins = $p->prepare("INSERT INTO ad_spy_searches (user_id, kind, query, provider, results_count, from_cache, created_at) VALUES (?, 'search', 'qa-pre-fill', 'meta', 0, false, ?)");
    for ($i = 0; $i < $limit; $i++) {
        $ins->execute([$UID, date('Y-m-d H:i:s')]);
    }
}
$before = quotaUsed($UID, $PLAN_LIMITED);
$stubMeta = new StubProvider(adsStub(1));
$m = managerWith(['meta' => $stubMeta, 'google' => new StubProvider(adsStub(1))]);
$r = $m->discover($UID, $PLAN_LIMITED, 'trends', ['query' => $qNew('blocked'), 'providers' => ['meta', 'google']]);
$after = quotaUsed($UID, $PLAN_LIMITED);
ok("cota exatamente no limite ({$before}/{$limit})", $before === $limit, "used={$before} limit={$limit}");
ok('errors.quota presente', !empty($r['errors']['quota']), 'errors=' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
ok('results vazio (providers NÃO chamados)', $r['results'] === [] && count($stubMeta->calls) === 0, 'results=' . json_encode(array_keys($r['results'])) . ' calls=' . count($stubMeta->calls));
ok('cota não cresce quando bloqueada', $after === $before, "antes={$before} depois={$after}");
ok('quota.allowed=false no payload', ($r['quota']['allowed'] ?? true) === false, 'allowed=' . var_export($r['quota']['allowed'] ?? null, true));
cleanupSearches($UID);

// ───────────────────────── 6. moderação ─────────────────────────
section('6. Moderação — query bloqueada não consome');
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$stubMeta = new StubProvider(adsStub(1));
$m = managerWith(['meta' => $stubMeta]);
$r = $m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => 'como assaltar um banco', 'providers' => ['meta']]);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('bloqueio em errors.query', !empty($r['errors']['query']), 'errors=' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
ok('provider NÃO chamado', count($stubMeta->calls) === 0, 'calls=' . count($stubMeta->calls));
ok('cota não consumida no bloqueio', $after === $before, "antes={$before} depois={$after}");

// ───────────────────────── 7. health ─────────────────────────
section('7. discover não grava health (AdSpyHealth)');
foreach (['meta', 'google', 'tiktok'] as $pid) {
    Settings::set('adspy_health_' . $UID . '_' . $pid, '');
}
$m = managerWith(['meta' => new StubProvider(adsStub(1)), 'google' => new StubProvider(adsStub(1))]);
$m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => $qNew('health'), 'providers' => ['meta', 'google']]);
$m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => ['meta']]);
$m->discover($UID, $PLAN_UNLIMITED, 'trends', ['query' => '', 'providers' => ['meta', 'google', 'tiktok']]);
$healthTouched = false;
foreach (['meta', 'google', 'tiktok'] as $pid) {
    if (AdSpyHealth::get($UID, $pid)['status'] !== 'unknown') $healthTouched = true;
}
ok('nenhum AdSpyHealth gravado pela descoberta', !$healthTouched);

// ───────────────────────── 8. payload Apify ─────────────────────────
section('8. Payload Apify — keywords[] (bugfix TikTok)');
$probe = new ProbeTikTokApify();
$probe->responses[] = json_encode([
    ['adId' => '1', 'adText' => 'Fogao 5 bocas', 'landingPageUrl' => 'https://loja.com/fogao', 'brandName' => 'Loja Fogoes'],
]);
$probe->search('fogao', ['apify_token' => 'qa-token', 'user_id' => 0, 'country' => 'BR', 'period' => 7, 'limit' => 5]);
$payload = $probe->lastPayload;
ok('payload envia keywords[] como ARRAY de strings', ($payload['keywords'] ?? null) === ['fogao'], 'keywords=' . json_encode($payload['keywords'] ?? null));
ok('payload NÃO envia o campo antigo "keyword" (string)', !is_array($payload) || !array_key_exists('keyword', $payload), 'chaves=' . implode(',', array_keys($payload ?? [])));
ok('payload mantém regions/period/sortBy/maxItems', isset($payload['regions'], $payload['period'], $payload['sortBy'], $payload['maxItems']), 'chaves=' . implode(',', array_keys($payload ?? [])));

$probe2 = new ProbeTikTokApify();
$probe2->responses[] = json_encode([['adId' => '2', 'adText' => 'geral', 'landingPageUrl' => 'https://x.com/a', 'brandName' => 'Marca']]);
$probe2->search('', ['apify_token' => 'qa-token', 'user_id' => 0]);
ok('query vazia → SEM keywords (descoberta aberta)', !array_key_exists('keywords', $probe2->lastPayload ?? []), 'chaves=' . implode(',', array_keys($probe2->lastPayload ?? [])));

$probe3 = new ProbeTikTokApify();
$probe3->responses[] = json_encode([
    ['adId' => '3', 'adText' => 'sem marca nenhuma'],
    ['adId' => '4', 'adText' => 'com landing', 'landingPageUrl' => 'https://meusite.com.br/p'],
    ['adId' => '5', 'adText' => 'com brand', 'brandName' => 'Fogoes SA'],
]);
$r3 = $probe3->search('qualquer', ['apify_token' => 'qa-token', 'user_id' => 0]);
$adv = array_map(fn($a) => $a['advertiser'], $r3['ads'] ?? []);
ok('anunciante vazio → "Anunciante desconhecido"', in_array('Anunciante desconhecido', $adv, true), 'advertiser=' . json_encode($adv, JSON_UNESCAPED_UNICODE));
ok('fallback de domínio (landing) preservado', in_array('meusite.com.br', $adv, true), 'advertiser=' . json_encode($adv, JSON_UNESCAPED_UNICODE));
ok('brandName preservado', in_array('Fogoes SA', $adv, true), 'advertiser=' . json_encode($adv, JSON_UNESCAPED_UNICODE));
ok('label antigo "TikTok Ads" não aparece mais', !in_array('TikTok Ads', $adv, true));

// ───────────────────────── 9. Google datas ─────────────────────────
section('9. Google SerpApi — start_date/end_date (Yyyymmdd)');
$g = new ProbeGoogle();
$g->search('loja.com.br', ['serpapi_key' => 'qa-chave', 'start_date' => '2026-09-22', 'end_date' => '2026-09-29']);
$url = $g->urls[0] ?? '';
ok('URL com start_date=20260922', str_contains($url, 'start_date=20260922'), 'url=' . substr($url, 0, 200));
ok('URL com end_date=20260929', str_contains($url, 'end_date=20260929'), 'url=' . substr($url, 0, 200));

$g2 = new ProbeGoogle();
$g2->search('outro.com', ['serpapi_key' => 'qa-chave', 'start_date' => '2026-09-22', 'end_date' => '20260922']);
ok('datas vindas de date("Ymd") chegam intactas', str_contains($g2->urls[0] ?? '', 'start_date=20260922'), 'url=' . substr($g2->urls[0] ?? '', 0, 200));

$g3 = new ProbeGoogle();
$g3->search('terceiro.com', ['serpapi_key' => 'qa-chave', 'start_date' => '2026-9-9']); // 6 dígitos
ok('data com dígitos != 8 é ignorada (guarda do provider)', !str_contains($g3->urls[0] ?? '', 'start_date='), 'url=' . substr($g3->urls[0] ?? '', 0, 200));

$g4 = new ProbeGoogle();
$g4->search('quarto.com', ['serpapi_key' => 'qa-chave']);
ok('sem opções → nenhuma data enviada (comportamento pré-existente preservado)', !str_contains($g4->urls[0] ?? '', 'start_date=') && !str_contains($g4->urls[0] ?? '', 'end_date='), 'url=' . substr($g4->urls[0] ?? '(sem chamada)', 0, 200));

$gReal = new GoogleTransparencyProvider();
$rG = $gReal->search('qualquercoisa.com', ['user_id' => $UID]); // user sintético sem SerpApi
ok('sem chave → erro didático "configure sua chave SerpApi"', str_contains((string)($rG['error'] ?? ''), 'configure sua chave SerpApi'), 'error=' . substr((string)($rG['error'] ?? ''), 0, 100));

// ───────────────────────── 10. Meta started_after ─────────────────────────
section('10. Meta API — ad_delivery_date_min (Trends)');
$ref = new ReflectionMethod(MetaAdLibraryProvider::class, 'searchOfficialApi');
$ref->setAccessible(true);
$mp = new ProbeMeta();
$ref->invoke($mp, 'fogao', ['started_after' => '2026-09-22', 'countries' => ['BR']], 'qa-token');
ok('ad_delivery_date_min enviado quando há started_after', str_contains($mp->urls[0] ?? '', 'ad_delivery_date_min=2026-09-22'), 'url=' . substr($mp->urls[0] ?? '(sem chamada)', 0, 220));
$mp2 = new ProbeMeta();
$ref->invoke($mp2, 'fogao', ['countries' => ['BR']], 'qa-token');
ok('sem started_after → parâmetro ausente (Busca/Top Ads inalterados)', !str_contains($mp2->urls[0] ?? '', 'ad_delivery_date_min'), 'url=' . substr($mp2->urls[0] ?? '(sem chamada)', 0, 220));

// ───────────────────────── 11. regressão search() ─────────────────────────
section('11. Regressão — search() por termo');
cleanupSearches($UID);
$stubMeta = new StubProvider(adsStub(1));
$before = quotaUsed($UID, $PLAN_UNLIMITED);
$m = managerWith([
    'meta' => $stubMeta,
    'google' => new StubProvider(['ads' => [], 'total' => 0, 'error' => 'Google: configure sua chave SerpApi em IA (BYOK) > Busca de Anuncios (gratis: 250 buscas/mes em serpapi.com).']),
]);
$rS = $m->search($UID, $PLAN_UNLIMITED, $qNew('regress'), ['meta', 'google'], ['country' => 'BR']);
$after = quotaUsed($UID, $PLAN_UNLIMITED);
ok('search devolve results por pid', isset($rS['results']['meta'], $rS['results']['google']));
ok('google sem chave continua como ERRO (não hint) e só meta consome', !empty($rS['errors']['google']) && $after === $before + 1, 'errors=' . json_encode(array_keys($rS['errors'])) . " used {$before}→{$after}");
ok('search mantém quota no payload', isset($rS['quota']['used']));
$rEmpty = $m->search($UID, $PLAN_UNLIMITED, '   ');
ok('search com query vazia → errors.query (pré-existente)', !empty($rEmpty['errors']['query']));
$rMod = $m->search($UID, $PLAN_UNLIMITED, 'como roubar um banco');
ok('search com crime → errors.query (pré-existente)', !empty($rMod['errors']['query']));

// ───────────────────────── limpeza final ─────────────────────────
cleanupAll($UID, $cacheStartId);

// ───────────────────────── veredito ─────────────────────────
echo "\n=== RESULTADO ===\n";
echo 'Passou: ' . $GLOBALS['__pass'] . ' | Falhou: ' . count($GLOBALS['__fail']) . "\n";
foreach ($GLOBALS['__fail'] as $f) {
    echo "  [FAIL] {$f}\n";
}
echo count($GLOBALS['__fail']) === 0 ? "\nADSPY DISCOVER OK\n" : "\nADSPY DISCOVER COM FALHAS\n";
exit(count($GLOBALS['__fail']) === 0 ? 0 : 1);
