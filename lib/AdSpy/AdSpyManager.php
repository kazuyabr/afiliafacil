<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Moderation/ContentModerator.php';
require_once __DIR__ . '/AdSpyQuota.php';
require_once __DIR__ . '/SteelBrowser.php';
require_once __DIR__ . '/AdSpyHealth.php';
require_once __DIR__ . '/Providers/MetaAdLibraryProvider.php';
require_once __DIR__ . '/Providers/GoogleTransparencyProvider.php';
require_once __DIR__ . '/Providers/TikTokCreativeProvider.php';

class AdSpyManager
{
    public const PROVIDERS = ['meta', 'google', 'tiktok'];
    private const CACHE_TTL_HOURS = 24;

    private array $providers = [];

    public function __construct()
    {
        $this->providers = [
            'meta' => new MetaAdLibraryProvider(),
            'google' => new GoogleTransparencyProvider(),
            'tiktok' => new TikTokCreativeProvider(),
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
        $steel = SteelBrowser::isConfigured();

        $metaSource = $metaByok ? 'byok' : ($metaPlatform ? 'platform' : 'public');
        $googleSource = $serpByok ? 'byok' : ($serpPlatform ? 'platform' : 'none');

        // A pill retrata o ESTADO REAL — nunca mente:
        //   configurado sem erro (buscou OK, buscou vazio ou ainda nao buscou) → verde = pronto
        //   erro de chave/token (Meta expirado) → vermelho = voce resolve
        //   falta configurar OU limitacao da propria fonte (TikTok sem sessao) → amarelo
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

        $metaCfg = $metaSource !== 'none';   // Meta sempre tem fallback público (nunca "sem fonte")
        $googleCfg = $googleSource !== 'none';
        $tiktokCfg = $steel;                 // sem Steel, o scraping direto do TikTok é bloqueado

        $metaH = AdSpyHealth::get($userId, 'meta');
        $googleH = AdSpyHealth::get($userId, 'google');
        $tiktokH = AdSpyHealth::get($userId, 'tiktok');

        $metaLevel = $levelFor('meta', $metaCfg, $metaH['status']);
        $googleLevel = $levelFor('google', $googleCfg, $googleH['status']);
        $tiktokLevel = $levelFor('tiktok', $tiktokCfg, $tiktokH['status']);

        // Erro antigo que mandava "configure o Steel" fica obsoleto assim que o Steel existe —
        // nao pode continuar culpando a config quando a limitacao real e a sessao do Creative Center.
        if ($tiktokH['status'] === 'error' && $steel && preg_match('/Steel|STEEL/i', $tiktokH['message'])) {
            $tiktokH['message'] = 'TikTok: o Creative Center bloqueia acesso sem sessão logada (limitação da fonte pública — não é configuração sua). Tente mais tarde ou busque em Meta/Google.';
        }

        // Cada pill acionavel leva o usuario direto para resolver o problema
        $metaAction = $metaLevel === 'error' ? '/admin/ai-settings.php#adspy' : null;
        $googleAction = (!$googleCfg || $googleLevel === 'error') ? '/admin/ai-settings.php#adspy' : null;
        // Só aponta pra Configurações se AINDA falta configurar o Steel (bloqueio da fonte não se resolve lá)
        $tiktokAction = (!$steel && $canAdmin) ? '/admin/settings.php' : null;

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
                'source' => $steel ? 'steel' : 'scraping',
                'label' => $steel ? 'Navegador (Steel)' : 'Scraping direto',
                'level' => $tiktokLevel,
                'state' => $stateText($tiktokH, $tiktokLevel, 'Navegador (Steel) não configurado — o acesso direto ao Creative Center é bloqueado.'),
                'action' => $tiktokAction,
                'hint' => !$steel ? 'Admin: configure o Steel Browser em Configurações para liberar o TikTok.' : ($tiktokH['status'] === 'error' ? 'O Creative Center continua bloqueado sem sessão logada no navegador.' : ''),
                'at' => $tiktokH['at'],
            ],
            'steel' => ['configured' => $steel],
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
        return hash('sha256', $provider . '|' . mb_strtolower(trim($query)) . '|' . json_encode($options));
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
