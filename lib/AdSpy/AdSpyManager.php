<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';
require_once __DIR__ . '/AdSpyQuota.php';
require_once __DIR__ . '/AdSpyHealth.php';
require_once __DIR__ . '/Providers/MetaAdLibraryProvider.php';
require_once __DIR__ . '/Providers/GoogleTransparencyProvider.php';
require_once __DIR__ . '/Providers/TikTokApifyProvider.php';
require_once __DIR__ . '/Providers/TikTokCreativeProvider.php';

class AdSpyManager
{
    public const PROVIDERS = ['meta', 'google', 'tiktok'];
    public const DISCOVER_MODES = ['trends', 'topads'];
    private const CACHE_TTL_HOURS = 24;

    private array $providers = [];

    public function __construct()
    {
        $this->providers = [
            'meta' => new MetaAdLibraryProvider(),
            'google' => new GoogleTransparencyProvider(),
            'tiktok' => new TikTokApifyProvider(),
        ];
    }

    public function search(int $userId, string $plan, string $query, array $providerIds = self::PROVIDERS, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['results' => [], 'errors' => ['query' => 'Informe um termo, domínio ou anunciante.'], 'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH)];
        }

        $screen = ContentModerator::screen($query, 'adspy', $userId);
        if (!$screen['allowed']) {
            return ['results' => [], 'errors' => ['query' => $screen['reason']], 'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH)];
        }
        $query = $screen['clean'];

        $quota = AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH);
        if (!$quota['allowed']) {
            return [
                'results' => [],
                'errors' => ['quota' => 'Sua cota de buscas do mês foi atingida (' . $quota['used'] . '/' . $quota['limit'] . '). Faça upgrade para continuar.'],
                'quota' => $quota,
            ];
        }

        $results = [];
        $errors = [];
        $consumed = 0;

        foreach ($providerIds as $pid) {
            if (!isset($this->providers[$pid])) continue;

            $cacheKey = $this->cacheKey($pid, $query, $options);
            $cached = $this->getCache($cacheKey);
            if ($cached !== null) {
                $results[$pid] = $cached;
                // Cache tambem e um resultado REAL (a busca foi ok) — senao a pill fica "nunca buscou"
                AdSpyHealth::record(
                    $userId,
                    $pid,
                    empty($cached['ads']) ? 'empty' : 'ok',
                    empty($cached['ads']) ? 'Conectou e respondeu — nenhum anúncio para este termo' : (string)(count($cached['ads']) . ' anúncios (cache 24h)')
                );
                AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($cached['ads'] ?? []), true);
                continue;
            }

            if ($quota['limit'] !== -1 && ($quota['used'] + $consumed) >= $quota['limit']) {
                $errors[$pid] = 'Cota de buscas atingida durante esta consulta.';
                continue;
            }

            try {
                $providerOptions = $options;
                $providerOptions['user_id'] = $userId;
                $r = $this->providers[$pid]->search($query, $providerOptions);
            } catch (Throwable $e) {
                $r = ['ads' => [], 'total' => 0, 'error' => 'Erro inesperado: ' . $e->getMessage()];
            }

            $results[$pid] = $r;
            if (empty($r['error'])) {
                // Grava o estado REAL da busca (usado nas pills depois)
                AdSpyHealth::record(
                    $userId,
                    $pid,
                    empty($r['ads']) ? 'empty' : 'ok',
                    empty($r['ads']) ? 'Conectou e respondeu — nenhum anúncio para este termo' : (string)(count($r['ads']) . ' anúncios')
                );
                // So cachea quando ha resultado: "vazio" precisa ser re-verificado
                // (usuario pode ter configurado a chave depois)
                if (!empty($r['ads'])) $this->setCache($cacheKey, $pid, $r);
                AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($r['ads'] ?? []), false);
                $consumed++;
            } else {
                AdSpyHealth::record($userId, $pid, 'error', (string)$r['error']);
                $errors[$pid] = $r['error'];
            }
        }

        return [
            'results' => $results,
            'errors' => $errors,
            'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH),
        ];
    }

    public function searchSystem(string $query, array $providerIds = self::PROVIDERS, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') return ['results' => [], 'errors' => []];

        $results = [];
        $errors = [];

        foreach ($providerIds as $pid) {
            if (!isset($this->providers[$pid])) continue;

            $cacheKey = $this->cacheKey($pid, $query, $options);
            $cached = $this->getCache($cacheKey);
            if ($cached !== null) {
                $results[$pid] = $cached;
                continue;
            }

            try {
                $r = $this->providers[$pid]->search($query, $options);
            } catch (Throwable $e) {
                $r = ['ads' => [], 'total' => 0, 'error' => 'Erro inesperado: ' . $e->getMessage()];
            }

            $results[$pid] = $r;
            if (empty($r['error'])) {
                $this->setCache($cacheKey, $pid, $r);
            } else {
                $errors[$pid] = $r['error'];
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * Descoberta (abas Trends & Hashtags / Top Ads) — multiredes com termo,
     * só TikTok sem termo:
     *  - SEM termo: somente o TikTok consulta de verdade (grátis, sem cota) —
     *    trends → ator anyx (lista publica em alta); topads → Apify quando há
     *    token (fallback 40101 didático). Meta/Google respondem com HINT
     *    "informe um termo" (não é erro, não chama rede, não consome cota).
     *  - COM termo: consulta os providers selecionados (Meta + Google + TikTok)
     *    e consome 1 cota KIND_SEARCH por rede que responder (igual search()).
     *  - Cache 24h isento de cota (consume com fromCache=true); só grava cache
     *    quando há anúncios.
     *  - Trends = janela do período (7/30 dias); Top Ads sem restrição de data
     *    (ordenação por desempenho).
     *  - NÃO grava health (AdSpyHealth) — o estado de trends/topads não deve
     *    sobrescrever o estado da busca por keyword do provider tiktok.
     */
    public function discover(int $userId, string $plan, string $mode, array $options = []): array
    {
        $mode = str_replace(['_', '-'], '', strtolower(trim((string)$mode))); // top_ads/top-ads → topads
        if ($mode === 'hashtags') $mode = 'trends'; // alias: fonte unica (hashtags em alta)
        $mode = in_array($mode, self::DISCOVER_MODES, true) ? $mode : 'trends';

        $query = trim((string)($options['query'] ?? ''));
        $providerIds = $this->sanitizeProviders($options['providers'] ?? self::PROVIDERS);

        $options['user_id'] = $userId;
        // query/providers entram nas options também para a cache key (mesma query
        // com seleção de redes diferente = resultado diferente).
        $options['query'] = $query;
        $options['providers'] = $providerIds;
        $options['countries'] = [strtoupper((string)($options['country'] ?? 'BR'))];
        // Trends sem termo trocou de Steel (top-3 anônimo) p/ anyx público —
        // prefixo muda a cache key e invalida resultados antigos já em cache.
        if ($mode === 'trends' && $query === '') $options['_src'] = 'anyx';

        $quota = AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH);
        $results = [];
        $errors = [];
        $cached = false;

        if ($query === '') {
            foreach ($providerIds as $pid) {
                if ($pid !== 'tiktok') {
                    $results[$pid] = ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                        'hint' => 'Informe um termo para ver anúncios do Meta/Google.'];
                    continue;
                }

                $cacheKey = $this->cacheKey($pid, 'discover:' . $mode, $options);
                $hit = $this->getCache($cacheKey);
                if ($hit !== null) {
                    $results[$pid] = $hit;
                    $cached = true;
                    continue;
                }

                $r = $this->discoverTikTok($mode, $query, $options);
                $results[$pid] = $r;
                if (!empty($r['error'])) {
                    $errors[$pid] = $r['error'];
                } elseif (!empty($r['ads'])) {
                    $this->setCache($cacheKey, $pid, $r);
                }
            }

            return ['results' => $results, 'errors' => $errors, 'mode' => $mode, 'cached' => $cached,
                'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH)];
        }

        $screen = ContentModerator::screen($query, 'adspy', $userId);
        if (!$screen['allowed']) {
            return ['results' => [], 'errors' => ['query' => $screen['reason']], 'mode' => $mode, 'cached' => false,
                'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH)];
        }
        $query = $screen['clean'];
        $options['query'] = $query;

        if (!$quota['allowed']) {
            return [
                'results' => [],
                'errors' => ['quota' => 'Sua cota de buscas do mês foi atingida (' . $quota['used'] . '/' . $quota['limit'] . '). Faça upgrade para continuar.'],
                'mode' => $mode,
                'cached' => false,
                'quota' => $quota,
            ];
        }

        $consumed = 0;
        foreach ($providerIds as $pid) {
            if (!isset($this->providers[$pid])) continue;

            $cacheKey = $this->cacheKey($pid, 'discover:' . $mode, $options);
            $hit = $this->getCache($cacheKey);
            if ($hit !== null) {
                $results[$pid] = $hit;
                $cached = true;
                AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($hit['ads'] ?? []), true);
                continue;
            }

            if ($quota['limit'] !== -1 && ($quota['used'] + $consumed) >= $quota['limit']) {
                $errors[$pid] = 'Cota de buscas atingida durante esta consulta.';
                continue;
            }

            try {
                $r = $pid === 'tiktok'
                    ? $this->discoverTikTok($mode, $query, $options)
                    : $this->discoverSearch($pid, $mode, $query, $options);
            } catch (Throwable $e) {
                $r = ['ads' => [], 'total' => 0, 'error' => 'Erro inesperado: ' . $e->getMessage()];
            }

            $results[$pid] = $r;
            if (empty($r['error'])) {
                // So cachea quando ha resultado: "vazio" precisa ser re-verificado
                if (!empty($r['ads'])) $this->setCache($cacheKey, $pid, $r);
                // no_charge = resposta didatica sem consulta externa (Google sem chave)
                if (empty($r['no_charge'])) {
                    AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($r['ads'] ?? []), false);
                    $consumed++;
                }
            } else {
                $errors[$pid] = $r['error'];
            }
        }

        return ['results' => $results, 'errors' => $errors, 'mode' => $mode, 'cached' => $cached,
            'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH)];
    }

    /** Rota TikTok da descoberta (fluxo original: trends → anyx/powerai, topads → Apify/40101). */
    private function discoverTikTok(string $mode, string $query, array $options): array
    {
        try {
            if ($mode === 'topads' && AdSpyKeys::apify((int)($options['user_id'] ?? 0)) !== '') {
                // Descoberta de Top Ads com token Apify (keyword opcional via options['query'])
                $apify = new TikTokApifyProvider();
                $r = $apify->search($query, $options);
                if (!empty($r['source_label'])) $r['source_label'] .= ' (descoberta)';
            } elseif ($mode === 'trends' && $query !== '') {
                // Busca por termo no modo trends: o Creative Center nao tem busca de
                // hashtag (nem logado) — resolve o ator powerai via Apify (BYOK).
                $apify = new TikTokApifyProvider();
                $r = $apify->hashtagSearch($query, $options);
                if (!empty($r['source_label'])) $r['source_label'] .= ' (descoberta)';
            } elseif ($mode === 'trends') {
                // Lista em alta sem termo: ator anyx (publico, ~3 linhas sem login) via Apify.
                $apify = new TikTokApifyProvider();
                $r = $apify->trendingHashtags($options);
                if (!empty($r['source_label'])) $r['source_label'] .= ' (descoberta)';
            } else {
                // topads sem token: fonte direta do TikTok (resposta 40101 + dica do Apify)
                $creative = new TikTokCreativeProvider();
                $r = $creative->discover($mode, $options);
            }
        } catch (Throwable $e) {
            $r = ['ads' => [], 'total' => 0, 'error' => 'Erro inesperado: ' . $e->getMessage()];
        }
        return $r;
    }

    /**
     * Meta/Google na descoberta COM termo — reusa o search() do provider
     * (token → API oficial → Steel no Meta; SerpApi BYOK no Google).
     * Trends limita à janela do período (7/30 dias); Top Ads sem data.
     */
    private function discoverSearch(string $pid, string $mode, string $query, array $options): array
    {
        if ($mode === 'trends') {
            $days = (int)($options['period'] ?? 7);
            if ($pid === 'meta') {
                $options['started_after'] = date('Y-m-d', strtotime('-' . $days . ' days'));
            } elseif ($pid === 'google') {
                $options['start_date'] = date('Ymd', strtotime('-' . $days . ' days'));
                $options['end_date'] = date('Ymd');
            }
        }

        $r = $this->providers[$pid]->search($query, $options);

        // Google sem chave SerpApi volta como erro didático — na descoberta é DICA
        // (não falha da consulta): a UI exibe como hint, sem quebrar os demais.
        if ($pid === 'google' && !empty($r['error']) && str_starts_with((string)$r['error'], 'Google: configure sua chave')) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'hint' => $r['error'], 'no_charge' => true];
        }

        return $r;
    }

    /** Interseção com a whitelist de providers; vazio → todos. */
    private function sanitizeProviders($providerIds): array
    {
        if (!is_array($providerIds)) $providerIds = self::PROVIDERS;
        $providerIds = array_values(array_intersect($providerIds, self::PROVIDERS));
        return $providerIds ?: self::PROVIDERS;
    }

    /**
     * Fonte efetiva de cada provider para o usuario (exibida como status na UI).
     * Ordem: byok (chave propria) > platform (chave do sistema) > public/scraping > none.
     */
    public function providerStatus(int $userId): array
    {
        $metaByok = AdSpyKeys::meta($userId) !== '';
        $metaPlatform = trim((string)(getenv('META_AD_ACCESS_TOKEN') ?: '')) !== '';
        $serpByok = AdSpyKeys::serpapi($userId) !== '';
        // A chave SerpApi da plataforma serve SOMENTE ao sistema/cron (user 0).
        $serpPlatform = $userId === 0 && trim((string)(getenv('SERPAPI_KEY') ?: '')) !== '';
        $apifyByok = AdSpyKeys::apify($userId) !== '';

        $metaSource = $metaByok ? 'byok' : ($metaPlatform ? 'platform' : 'public');
        $googleSource = $serpByok ? 'byok' : ($serpPlatform ? 'platform' : 'none');
        $tiktokSource = $apifyByok ? 'byok' : 'none';

        // A pill retrata o ESTADO REAL — nunca mente:
        //   configurado sem erro (buscou OK, buscou vazio ou ainda nao buscou) → verde = pronto
        //   erro de chave/token (Meta expirado) → vermelho = voce resolve
        //   falta configurar OU limitacao da propria fonte → amarelo
        $levelFor = function (string $pid, bool $configured, string $status): string {
            if (!$configured) return 'warn';
            if ($status === 'error') return $pid === 'tiktok' ? 'warn' : 'error';
            return 'ok';
        };
        $stateText = function (array $h, string $level, string $configuredText): string {
            if ($level === 'ok' && $h['status'] === 'unknown') {
                return 'Configurado e pronto — ainda não buscou. Faça uma busca para confirmar.';
            }
            if ($level === 'ok') {
                return $h['status'] === 'empty'
                    ? ($h['message'] !== '' ? $h['message'] : 'Conectou e respondeu — nenhum anúncio para este termo.')
                    : rtrim($h['message'] !== '' ? $h['message'] : 'Última busca OK', '.') . '.';
            }
            // error ou warn com tentativa registrada → conta o que aconteceu de verdade
            if ($h['status'] === 'error') return $h['message'] !== '' ? $h['message'] : 'Falhou na última busca.';
            return $configuredText; // warn sem tentativa = falta configurar
        };
        $canAdmin = class_exists('Auth') && \Auth::isAdmin();

        $metaCfg = $metaSource !== 'none';
        $googleCfg = $googleSource !== 'none';
        $tiktokCfg = $apifyByok; // TikTok agora via Apify BYOK

        $metaH = AdSpyHealth::get($userId, 'meta');
        $googleH = AdSpyHealth::get($userId, 'google');
        $tiktokH = AdSpyHealth::get($userId, 'tiktok');

        $metaLevel = $levelFor('meta', $metaCfg, $metaH['status']);
        $googleLevel = $levelFor('google', $googleCfg, $googleH['status']);
        $tiktokLevel = $levelFor('tiktok', $tiktokCfg, $tiktokH['status']);

        // Cada pill acionavel leva o usuario direto para resolver o problema
        $metaAction = $metaLevel === 'error' ? '/admin/ai-settings.php#adspy' : null;
        $googleAction = (!$googleCfg || $googleLevel === 'error') ? '/admin/ai-settings.php#adspy' : null;
        $tiktokAction = (!$apifyByok && $canAdmin) ? '/admin/ai-settings.php#adspy' : null;

        return [
            'meta' => [
                'source' => $metaSource,
                'label' => $metaByok ? 'API oficial (sua chave)' : ($metaPlatform ? 'API oficial (plataforma)' : 'Biblioteca pública'),
                'level' => $metaLevel,
                'state' => $stateText($metaH, $metaLevel, 'Fonte pública ativa.'),
                'action' => $metaAction,
                'hint' => $metaLevel === 'error' ? 'Token expirado ou sem permissão — renove em Configurações.' : '',
                'at' => $metaH['at'],
            ],
            'google' => [
                'source' => $googleSource,
                'label' => $serpByok ? 'SerpApi (sua chave)' : ($serpPlatform ? 'SerpApi (plataforma)' : 'Sem chave'),
                'level' => $googleLevel,
                'state' => $stateText($googleH, $googleLevel, 'Busca por domínio: crie uma chave grátis em serpapi.com (250 buscas/mês) e configure aqui.'),
                'action' => $googleAction,
                'hint' => !$googleCfg ? 'Configure sua chave SerpApi (grátis) para liberar as buscas.' : ($googleLevel === 'error' ? $googleH['message'] : ($googleLevel === 'ok' && $googleH['status'] === 'empty' ? 'Dica: busque pelo domínio do anunciante (ex.: loja.com.br) — termos soltos costumam voltar vazio.' : '')),
                'at' => $googleH['at'],
            ],
            'tiktok' => [
                'source' => $tiktokSource,
                'label' => $apifyByok ? 'TikTok Creative Center via Apify' : 'Sem token Apify',
                'level' => $tiktokLevel,
                'state' => $stateText($tiktokH, $tiktokLevel, $apifyByok ? 'Configurado — busca real por palavra-chave.' : 'Configure seu token Apify (conta grátis em apify.com, $5 de crédito/mês) para liberar o TikTok.'),
                'action' => $tiktokAction,
                'hint' => !$apifyByok ? 'Configure seu token Apify em IA → Busca de Anúncios.' : ($tiktokH['status'] === 'error' ? $tiktokH['message'] : ''),
                'at' => $tiktokH['at'],
            ],
        ];
    }

    /** Google: configurado e ativo? */
    private static function isGoogleAvailable(int $userId): bool
    {
        if ($userId <= 0) return false;
        $key = AdSpyKeys::serpapi($userId);
        return $key !== '';
    }

    /** Meta: configurado e ativo? (via BYOK) */
    private static function isMetaAvailable(int $userId): bool
    {
        if ($userId <= 0) return false;
        $key = AdSpyKeys::meta($userId);
        return $key !== '';
    }

    public function dossier(array $page, int $userId, string $plan): array
    {        $signals = $this->extractSignals($page);

        $query = $signals['domain'] !== '' ? $signals['domain'] : $signals['brand'];
        $search = $this->search($userId, $plan, $query, self::PROVIDERS, ['countries' => ['BR'], 'country' => 'BR']);

        $allAds = [];
        foreach ($search['results'] as $pid => $r) {
            foreach ($r['ads'] ?? [] as $ad) {
                $allAds[] = $ad;
            }
        }

        return [
            'signals' => $signals,
            'search' => $search,
            'total_ads' => count($allAds),
            'ads' => $allAds,
        ];
    }

    public function extractSignals(array $page): array
    {
        $html = $page['html'] ?? '';
        $domain = $page['source_domain'] ?? '';

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }

        $brand = '';
        if ($title !== '') {
            $parts = preg_split('/[\|\-–—:]/', $title);
            $brand = trim($parts[0] ?? $title);
        }
        if ($brand === '') $brand = $domain;

        $terms = array_values(array_filter(
            preg_split('/[\s\|\-–—:,\.]+/u', mb_strtolower($title)),
            fn($t) => mb_strlen($t) >= 4
        ));
        $terms = array_slice(array_unique($terms), 0, 10);

        preg_match_all('#https?://[^"\'\s]*(?:hotmart|kiwify|eduzz|braip|monetizze|cartpanda|payt|ticto|perfectpay|lastlink|nutror|vendd)[^"\'\s]*#i', $html, $ck);
        $checkouts = array_slice(array_values(array_unique($ck[0] ?? [])), 0, 5);

        return [
            'domain' => $domain,
            'title' => $title,
            'brand' => $brand,
            'terms' => $terms,
            'checkouts' => $checkouts,
        ];
    }

    private function cacheKey(string $provider, string $query, array $options): string
    {
        ksort($options);
        return hash('sha256', 'v2|' . $provider . '|' . mb_strtolower(trim($query)) . '|' . json_encode($options));
    }

    private function getCache(string $cacheKey): ?array
    {
        if (!Database::available()) return null;

        try {
            $row = \AfiliaFacil\Models\AdSpyCache::where('cache_key', $cacheKey)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->first();
            if (!$row) return null;

            $payload = json_decode($row->payload, true);
            return is_array($payload) ? $payload : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setCache(string $cacheKey, string $provider, array $data): void
    {
        if (!Database::available()) return;

        try {
            \AfiliaFacil\Models\AdSpyCache::updateOrCreate(
                ['cache_key' => $cacheKey],
                [
                    'provider' => $provider,
                    'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', time() + self::CACHE_TTL_HOURS * 3600),
                ]
            );
        } catch (Throwable $e) {
        }
    }
}
