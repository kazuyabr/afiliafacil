<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/AdSpyQuota.php';
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
                AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($cached['ads'] ?? []), true);
                continue;
            }

            if ($quota['limit'] !== -1 && ($quota['used'] + $consumed) >= $quota['limit']) {
                $errors[$pid] = 'Cota de buscas atingida durante esta consulta.';
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
                AdSpyQuota::consume($userId, AdSpyQuota::KIND_SEARCH, $query, $pid, count($r['ads'] ?? []), false);
                $consumed++;
            } else {
                $errors[$pid] = $r['error'];
            }
        }

        return [
            'results' => $results,
            'errors' => $errors,
            'quota' => AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH),
        ];
    }

    public function dossier(array $page, int $userId, string $plan): array
    {
        $signals = $this->extractSignals($page);

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
