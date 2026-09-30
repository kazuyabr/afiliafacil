<?php
/**
 * Testes de ACEITE — inspeção ESTÁTICA da UI do Ad Spy (sem browser).
 *
 * Lê admin/adspy.php (HTML+JS) e valida os critérios de aceite da spec:
 *   U1 — checkboxes #modeProviders visíveis (display flex) em todos os modos,
 *        incluindo trends/topads (setMode força 'flex')
 *   U2 — runDiscover envia providers[] e valida seleção vazia (toast)
 *   U3 — card .ad-meta-info SEM esc(ad.provider) e COM guard de started_at
 *   U4 — card sem texto "tiktok" vindo do provider (só badges com label)
 *   U5 — fallback 'Anunciante desconhecido' no provider (TikTokApifyProvider)
 *   U6 — updateQuotaPill chamada dentro de renderResults
 *   U7 — MODE_HINTS/labels dos modos trends/topads atualizados (multiredes + cota)
 *   U8 — erros por pid exibidos via configLinkFor: padrões extraídos DO ARQUIVO
 *        e aplicados às mensagens REAIS de erro (40101 Apify, SerpApi, TikTok
 *        sem token, Meta/Steel, mensagem genérica)
 *
 * Determinístico: 100% offline (só leitura de arquivos).
 *
 * Uso (dentro da raiz do app):
 *   docker cp tests/adspy/adspy-ui-static-test.php afiliafacil:/app/tests/adspy/
 *   docker exec afiliafacil php tests/adspy/adspy-ui-static-test.php
 *
 * Exit 0 = tudo ok; 1 = há falhas.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$UI = __DIR__ . '/../../admin/adspy.php';
$PROVIDER = __DIR__ . '/../../lib/AdSpy/Providers/TikTokApifyProvider.php';

if (!is_readable($UI)) {
    fwrite(STDERR, "não consegui ler {$UI}\n");
    exit(2);
}
$html = (string)file_get_contents($UI);
$providerSrc = (string)file_get_contents($PROVIDER);

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

/** Extrai o corpo da função JS de nome $fn do HTML. */
function jsFunction(string $html, string $fn): string
{
    $pos = strpos($html, "function {$fn}(");
    if ($pos === false) return '';
    // Extrai ate a proxima declaracao de funcao de mesmo nivel (contador de
    // chaves ingenuo quebra com strings/regex JS como /[^}]/)
    if (preg_match('/\n    function \w+\(/', $html, $m, PREG_OFFSET_CAPTURE, $pos + 10)) {
        return substr($html, $pos, $m[0][1] - $pos);
    }
    return substr($html, $pos, 8000);
}

// ───────────────────────── U1: checkboxes visíveis ─────────────────────────
section('U1 — checkboxes de rede visíveis em Trends/Top Ads');

ok('div #modeProviders existe', str_contains($html, 'id="modeProviders"'));
preg_match_all('/<input type="checkbox" class="provider-check" value="(\w+)"( checked)?>/', $html, $m);
$values = $m[1] ?? [];
$checked = 0;
foreach ($m[2] ?? [] as $c) if ($c !== '') $checked++;
ok('3 checkboxes .provider-check (meta, google, tiktok)', $values === ['meta', 'google', 'tiktok'], 'values=' . implode(',', $values));
ok('todos marcados por padrão', $checked === 3, "checked={$checked}/3");

// setMode força display flex (visível) — em TODOS os modos (search/trends/topads)
$setMode = jsFunction($html, 'setMode');
ok('setMode força #modeProviders display:flex (visível)', $setMode !== '' && str_contains($setMode, "getElementById('modeProviders').style.display = 'flex'"));
ok('setMode aplica hints dos modos discover', str_contains($setMode, 'MODE_HINTS[mode]'));
// HTML inicial visível (antes de qualquer setMode)
ok('estado inicial do #modeProviders é visível', (bool)preg_match('/id="modeProviders" style="display:flex/', $html));

// ───────────────────────── U2: runDiscover envia providers[] + valida vazio ──
section('U2 — runDiscover envia providers[] e valida seleção vazia');

$runDiscover = jsFunction($html, 'runDiscover');
ok('runDiscover existe', $runDiscover !== '');
ok('valida seleção vazia com toast', $runDiscover !== '' && str_contains($runDiscover, "if (!providers.length) { showToast('Selecione ao menos uma plataforma', 'warning'); return; }"));
ok("envia providers[] no body", $runDiscover !== '' && str_contains($runDiscover, "body.append('providers[]', p)"));
ok('usa selectedProviders() (checkboxes)', $runDiscover !== '' && str_contains($runDiscover, 'selectedProviders()'));
ok('action=discover no body', $runDiscover !== '' && str_contains($runDiscover, "body.append('action', 'discover')"));

// ───────────────────────── U3/U4: card sem ad.provider, guard started_at ────
section('U3/U4 — card: sem esc(ad.provider), guard started_at, sem "tiktok"');

$renderAds = jsFunction($html, 'renderAds');
ok('renderAds existe', $renderAds !== '');
ok('card .ad-meta-info NÃO imprime ad.provider', $renderAds !== '' && !str_contains($renderAds, 'ad.provider'));
ok('template do card NÃO usa esc(ad.provider) em lugar nenhum', !str_contains($html, 'esc(ad.provider)'));
ok("guard de started_at antes de .ad-meta-info", $renderAds !== '' && (bool)preg_match('/ad\.started_at\s*\?\s*\'<span class="ad-meta-info">/', $renderAds));
ok("fallback 'Anunciante desconhecido' no render (UI)", $renderAds !== '' && str_contains($renderAds, "clean(ad.advertiser || 'Anunciante desconhecido')"));

// "tiktok" minúsculo não pode aparecer como TEXTO no card (só badges com labels)
$hasProviderText = $renderAds !== '' && (bool)preg_match('/esc\(\s*ad\.provider|>\s*\{\{\s*ad\.provider/', $renderAds);
ok('card não imprime o id do provider (texto "tiktok")', !$hasProviderText);
// Badges usam labels legíveis (TikTok com maiúscula), não o id
ok("badges usam labels ('TikTok'), não o id crú", $renderAds !== '' && str_contains($renderAds, "tiktok: 'TikTok'"));

// ───────────────────────── U5: fallback no provider ─────────────────────────
section("U5 — fallback 'Anunciante desconhecido' no provider TikTokApifyProvider");

ok('provider define brand vazio → Anunciante desconhecido', str_contains($providerSrc, "\$brand = 'Anunciante desconhecido';"));
ok("label antigo 'TikTok Ads' removido do provider", !str_contains($providerSrc, "'TikTok Ads'"));
ok('payload envia keywords (array) — bugfix presente', str_contains($providerSrc, "\$payload['keywords'] = [\$query];"));
ok('campo antigo keyword (string) não é mais enviado', !str_contains($providerSrc, "'keyword' =>"));

// ───────────────────────── U6: updateQuotaPill em renderResults ─────────────
section('U6 — updateQuotaPill chamada em renderResults');

$renderResults = jsFunction($html, 'renderResults');
$updateQuotaPill = jsFunction($html, 'updateQuotaPill');
ok('renderResults existe', $renderResults !== '');
ok('updateQuotaPill existe', $updateQuotaPill !== '');
ok('renderResults chama updateQuotaPill(data.quota)', $renderResults !== '' && str_contains($renderResults, 'if (data.quota) updateQuotaPill(data.quota)'));
ok('errors.quota aborta com alerta antes do resto', $renderResults !== '' && str_contains($renderResults, 'if (errors.quota)'));

// ───────────────────────── U7: MODE_HINTS/labels ────────────────────────────
section('U7 — MODE_HINTS/labels atualizados (multiredes + cota)');

ok('MODE_HINTS definido', str_contains($html, 'const MODE_HINTS = {'));
ok('hint trends cita redes selecionadas', (bool)preg_match('/trends:.*redes selecionadas/s', $html));
ok('hint trends cita cota por rede', (bool)preg_match('/trends:.*consome 1 cota de busca por rede/s', $html));
ok('hint topads cita redes selecionadas + cota', (bool)preg_match('/topads:.*redes selecionadas.*consome 1 cota de busca por rede/s', $html));
ok('label topads explica "em branco consulta só o TikTok"', str_contains($html, 'Termo (opcional — em branco consulta só o TikTok)'));
ok('label trends explica "em branco consulta só o TikTok"', str_contains($html, 'Hashtag ou termo (opcional — em branco consulta só o TikTok)'));

// ───────────────────────── U8: configLinkFor com mensagens reais ────────────
section('U8 — erros por pid → configLinkFor (padrões extraídos do arquivo)');

$clf = jsFunction($html, 'configLinkFor');
ok('configLinkFor existe', $clf !== '');
ok('renderResults itera errors por pid e chama configLinkFor',
    $renderResults !== '' && str_contains($renderResults, 'Object.entries(errors).forEach') && str_contains($renderResults, 'const link = configLinkFor(msg);'));

// Extrai os pares (padrão, destino) DAS LINHAS do configLinkFor
preg_match_all('#if\s*\((/.+?/i)\.test\(msg\)(?:\s*&&\s*IS_ADMIN)?\)\s*return\s*\'([^\']+)\'#', $clf, $matches, PREG_SET_ORDER);
ok('3 regras de link extraídas', count($matches) === 3, 'regras=' . count($matches));
info('regras: ' . json_encode(array_map(fn($m) => ['pat' => $m[1], 'to' => $m[2]], $matches), JSON_UNESCAPED_UNICODE));

/**
 * Replica o configLinkFor de admin/adspy.php aplicando os padrões EXTRAÍDOS
 * do arquivo (não hardcodados) às mensagens reais. $isAdmin simula IS_ADMIN.
 */
function configLinkForExtracted(array $rules, string $msg, bool $isAdmin = true): ?string
{
    foreach ($rules as $m) {
        $pattern = rtrim($m[1], 'i');      // "/pat/i" → "/pat/"
        $pat = substr($pattern, 1, -1);    // remove as barras
        $lineHasAdminGate = str_contains($m[0], 'IS_ADMIN');
        if (@preg_match('#' . str_replace('#', '\#', $pat) . '#iu', $msg)) {
            if ($lineHasAdminGate && !$isAdmin) continue;
            return $m[2];
        }
    }
    return null;
}

$cases = [
    // [descrição, mensagem real (código), destino esperado, é-admin]
    ['topads sem token Apify (40101) → link IA',
        'TikTok: a fonte de Top Ads exige sessão no TikTok (bloqueio 40101). Configure seu token Apify em IA → Busca de Anúncios ou use Meta/Google.',
        '/admin/ai-settings.php#adspy', true],
    ['TikTok trends sem token → link IA',
        'TikTok Trends: falta seu token Apify — configure em IA → Busca de Anúncios para ver as hashtags em alta.',
        '/admin/ai-settings.php#adspy', true],
    ['Google sem chave SerpApi → link IA',
        'Google: configure sua chave SerpApi em IA (BYOK) > Busca de Anuncios (gratis: 250 buscas/mes em serpapi.com).',
        '/admin/ai-settings.php#adspy', true],
    ['erro Steel (admin) → link Configurações',
        'Steel Browser: sem STEEL_API_URL configurado.',
        '/admin/settings.php', true],
    ['erro Steel (não-admin) → SEM link',
        'Steel Browser: sem STEEL_API_URL configurado.',
        null, false],
    ['mensagem genérica → sem link',
        'Erro inesperado: falhou do nada.',
        null, true],
];

foreach ($cases as [$desc, $msg, $expected, $isAdmin]) {
    $got = configLinkForExtracted($matches, $msg, $isAdmin);
    ok($desc, $got === $expected, 'got=' . var_export($got, true));
}

// ───────────────────────── veredito ─────────────────────────
echo "\n=== RESULTADO UI (estático) ===\n";
echo 'Passou: ' . $GLOBALS['__pass'] . ' | Falhou: ' . count($GLOBALS['__fail']) . "\n";
foreach ($GLOBALS['__fail'] as $f) {
    echo "  [FAIL] {$f}\n";
}
echo count($GLOBALS['__fail']) === 0 ? "\nADSPY UI ESTATICO OK\n" : "\nADSPY UI ESTATICO COM FALHAS\n";
exit(count($GLOBALS['__fail']) === 0 ? 0 : 1);
