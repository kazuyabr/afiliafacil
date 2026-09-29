<?php

require_once __DIR__ . '/AdSpyProvider.php';

/**
 * TikTok Creative Center — Top Ads direto da fonte (gratuita, sem Apify).
 *
 * - search()/topAds(): Top Ads por palavra-chave (API creative_radar_api)
 * - discover(): só Top Ads sem keyword (via Apify quando há token — o manager rota)
 *
 * trends/hashtags SAÍRAM deste provider (página pública exige sessão e o
 * scraping direto devolve 40101) — rodam no AdSpyManager via ator anyx do
 * Apify. Steel Browser não é usado aqui (só na Meta).
 */
class TikTokCreativeProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'tiktok';
    }

    public function search(string $query, array $options = []): array
    {
        return $this->topAds($query, $options);
    }

    /**
     * Descoberta sem palavra-chave — apenas Top Ads.
     * (trends/hashtags são roteados pelo AdSpyManager para o Apify)
     */
    public function discover(string $mode, array $options = []): array
    {
        return $this->topAdsDiscovery($options);
    }

    /** Top Ads (inspiracao) — API JSON direta (a pagina exige sessao no TikTok). */
    private function topAds(string $query, array $options): array
    {
        $country = strtoupper($options['country'] ?? 'BR');
        $period = (int)($options['period'] ?? 30);
        $orderBy = preg_replace('/[^a-z_]/', '', strtolower((string)($options['order_by'] ?? 'ctr')));
        if (!in_array($orderBy, ['ctr', 'like', 'share', 'comment', 'play'], true)) $orderBy = 'ctr';

        $params = [
            'period' => $period,
            'page' => 1,
            'limit' => (int)($options['limit'] ?? 20),
            'order_by' => $orderBy,
            'country_code' => $country,
            'ad_language' => 'all',
        ];
        if ($query !== '') $params['keyword'] = $query;

        $apiUrl = 'https://ads.tiktok.com/creative_radar_api/v1/top_ads/v2/list?' . http_build_query($params);
        $body = $this->httpGet($apiUrl, [
            'Referer: https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/pt',
            'Origin: https://ads.tiktok.com',
        ]);

        $items = $this->parseTopAdsJson($body);
        if ($items === null) {
            return $this->emptyResult(
                'TikTok: a fonte de Top Ads exige sessão no TikTok (bloqueio 40101). Configure seu token Apify em IA → Busca de Anúncios ou use Meta/Google.',
                'TikTok Creative Center (Top Ads)'
            );
        }

        return $this->adsFromItems($items, 'TikTok Creative Center (API direta)', 'TikTok: nenhum anúncio no Top Ads para este filtro.');
    }

    /** Descoberta de TopAds sem keyword — só via Apify (o manager rota p/ ele quando há token). */
    private function topAdsDiscovery(array $options): array
    {
        $r = $this->topAds('', $options);
        if (empty($r['ads'])) {
            $r['hint'] = 'TikTok Top Ads: a fonte pública exige sessão. Configure seu token Apify (IA → Busca de Anúncios) para descobrir Top Ads sem palavra-chave.';
        }
        return $r;
    }

    // ── Parse ────────────────────────────────────────────────────────────

    private function parseTopAdsJson(?string $body): ?array
    {
        if ($body === null || $body === '') return null;
        $json = json_decode($body, true);
        if (!is_array($json)) return null;
        if ((int)($json['code'] ?? -1) !== 0) return null;
        $list = $json['data']['list'] ?? $json['data'] ?? null;
        if (!is_array($list)) return null;
        return $list;
    }

    // ── Mapeamento para ads ──────────────────────────────────────────────

    private function adsFromItems(array $items, string $sourceLabel, string $emptyHint): array
    {
        $ads = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $ad = $this->mapTopAdItem($item);
            if ($ad === null) continue;
            $ads[] = $this->normalizeAd($this->sanitizeAd($ad));
        }

        if (empty($ads)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                'source_label' => $sourceLabel, 'hint' => $emptyHint];
        }
        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => $sourceLabel];
    }

    /** Sanitiza placeholders {{...}} de todos os campos de texto. */
    private function sanitizeAd(array $ad): array
    {
        foreach (['advertiser', 'title', 'text', 'cta'] as $f) {
            if (!empty($ad[$f])) {
                $ad[$f] = $this->sanitizeText((string)$ad[$f]);
            }
        }
        return $ad;
    }

    private function mapTopAdItem(array $item): ?array
    {
        $id = (string)($item['id'] ?? $item['adId'] ?? '');
        $brand = (string)($item['brand_name'] ?? $item['brandName'] ?? '');
        $title = (string)($item['ad_title'] ?? $item['adTitle'] ?? '');
        $text = (string)($item['ad_text'] ?? $item['adText'] ?? $title);
        $video = is_array($item['video_info'] ?? null) ? $item['video_info'] : [];
        $cover = (string)($video['cover_url'] ?? $item['cover_url'] ?? $item['coverImageUrl'] ?? '');
        $videoUrl = (string)($video['video_url'] ?? $item['video_url'] ?? '');
        $landing = (string)($item['landing_page'] ?? $item['landingPage'] ?? '');
        $id = $id !== '' ? $id : sha1($brand . '|' . $title . '|' . $landing);

        if ($brand === '' && $title === '' && $cover === '' && $videoUrl === '') return null;

        return [
            'id' => $id,
            'advertiser' => $brand !== '' ? $brand : $title,
            'title' => $title,
            'text' => $text,
            'cta' => (string)($item['cta'] ?? ''),
            'media_type' => $videoUrl ? 'video' : 'image',
            'media_url' => $cover ?: $videoUrl,
            'thumbnail' => $cover ?: $videoUrl,
            'landing_page' => $landing,
            'platforms' => ['tiktok'],
            'started_at' => null,
            'ended_at' => null,
            'status' => 'active',
            'link' => $id !== '' ? 'https://ads.tiktok.com/business/creativecenter/inspiration/topads/' . $id . '/pc/pt'
                : 'https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/pt',
        ];
    }
}
