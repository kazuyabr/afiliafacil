<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AdSpy/AdSpyManager.php';
require_once __DIR__ . '/OfferManager.php';

class OfferCollector
{
    public const DEFAULT_TERMS = [
        'emagrecimento', 'renda extra', 'dinheiro', 'receita', 'dieta',
        'ansiedade', 'relacionamento', 'espiritualidade', 'trader', 'investimento',
    ];

    private AdSpyManager $adSpy;
    private OfferManager $offers;

    public function __construct()
    {
        $this->adSpy = new AdSpyManager();
        $this->offers = new OfferManager();
    }

    public function collect(array $terms, array $providers = ['meta', 'google', 'tiktok'], int $maxTerms = 8): array
    {
        $terms = array_values(array_filter(array_map('trim', $terms)));
        if (empty($terms)) $terms = self::DEFAULT_TERMS;
        $terms = array_slice($terms, 0, max(1, $maxTerms));

        $summary = ['terms' => 0, 'offers' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($terms as $term) {
            $search = $this->adSpy->searchSystem($term, $providers, ['countries' => ['BR'], 'country' => 'BR', 'status' => 'active']);
            foreach ($search['errors'] as $pid => $error) {
                $summary['errors'][$term . ':' . $pid] = $error;
            }
            $summary['terms']++;

            $groups = $this->groupAds($search['results']);
            foreach ($groups as $group) {
                $result = $this->offers->upsert($group['data'], $group['ads']);
                if (!empty($result['offer'])) {
                    $summary['offers']++;
                    if (!empty($result['created'])) $summary['created']++;
                    else $summary['updated']++;
                }
            }
        }

        return $summary;
    }

    public function monitor(int $maxOffers = 40): array
    {
        if (!Database::available()) return ['checked' => 0, 'errors' => ['banco indisponível']];

        $summary = ['checked' => 0, 'metrics' => 0, 'errors' => []];

        try {
            $offers = \AfiliaFacil\Models\Offer::whereIn('status', ['approved', 'pending'])
                ->orderByDesc('last_seen_at')
                ->limit($maxOffers)
                ->get();

            foreach ($offers as $offer) {
                $query = $offer->domain !== '' ? $offer->domain : ($offer->advertiser !== '' ? $offer->advertiser : $offer->name);
                if ($query === '') continue;

                $search = $this->adSpy->searchSystem($query, ['meta'], ['countries' => ['BR'], 'country' => 'BR', 'status' => 'active']);
                $ads = [];
                foreach ($search['results'] as $pid => $r) {
                    foreach ($r['ads'] ?? [] as $ad) {
                        $ad['provider'] = $ad['provider'] ?? $pid;
                        $ads[] = $ad;
                    }
                }
                foreach ($search['errors'] as $pid => $error) {
                    $summary['errors'][$query . ':' . $pid] = $error;
                }

                if (empty($ads)) continue;

                $prev = (int)$offer->ads_count;
                $count = count($ads);
                $offer->ads_count_prev = $prev;
                $offer->ads_count = $count;
                $offer->scale_pct = $prev > 0 ? (int)round((($count - $prev) / $prev) * 100) : 0;
                $offer->last_seen_at = date('Y-m-d H:i:s');
                $offer->updated_at = date('Y-m-d H:i:s');
                $offer->save();

                $this->offers->recordMetric((int)$offer->id, $count);
                $this->offers->storeCreatives((int)$offer->id, $ads);
                $this->offers->storePages((int)$offer->id, $ads);

                $summary['checked']++;
                $summary['metrics']++;
            }
        } catch (Throwable $e) {
            $summary['errors']['monitor'] = $e->getMessage();
        }

        return $summary;
    }

    private function groupAds(array $results): array
    {
        $groups = [];

        foreach ($results as $pid => $result) {
            foreach ($result['ads'] ?? [] as $ad) {
                $ad['provider'] = $pid;

                $domain = $this->extractDomain($ad['landing_page'] ?? '');
                $advertiser = trim($ad['advertiser'] ?? '');

                if ($domain !== '') {
                    $key = 'domain:' . $domain;
                } elseif ($advertiser !== '') {
                    $key = 'advertiser:' . mb_strtolower($advertiser);
                } else {
                    continue;
                }

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'data' => [
                            'slug' => $domain !== '' ? $domain : $advertiser,
                            'name' => $advertiser !== '' ? $advertiser : ($domain !== '' ? $domain : 'Oferta'),
                            'advertiser' => $advertiser,
                            'domain' => $domain,
                            'source_url' => $ad['landing_page'] ?? '',
                            'platform' => $pid,
                            'traffic_sources' => [$this->trafficFor($pid)],
                            'thumbnail_url' => $ad['thumbnail'] ?? ($ad['media_url'] ?? ''),
                        ],
                        'ads' => [],
                    ];
                }

                if (!in_array($this->trafficFor($pid), $groups[$key]['data']['traffic_sources'], true)) {
                    $groups[$key]['data']['traffic_sources'][] = $this->trafficFor($pid);
                }
                if (!empty($ad['thumbnail']) && empty($groups[$key]['data']['thumbnail_url'])) {
                    $groups[$key]['data']['thumbnail_url'] = $ad['thumbnail'];
                }

                $groups[$key]['ads'][] = $ad;
            }
        }

        return array_values($groups);
    }

    public function extractDomain(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return '';

        $host = mb_strtolower($host);
        $host = preg_replace('/^www\./', '', $host);
        return $host ?: '';
    }

    private function trafficFor(string $provider): string
    {
        return match ($provider) {
            'google' => 'google',
            'tiktok' => 'tiktok',
            default => 'facebook',
        };
    }
}
