<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../SteelBrowser.php';

class TikTokCreativeProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'tiktok';
    }

    public function search(string $query, array $options = []): array
    {
        $country = $options['country'] ?? 'BR';
        $params = [
            'period' => $options['period'] ?? 30,
            'page' => 1,
            'limit' => 20,
            'order_by' => 'ctr',
            'country_code' => $country,
            'keyword' => $query,
            'ad_language' => 'all',
        ];

        $body = $this->httpGet(
            'https://ads.tiktok.com/creative_radar_api/v1/top_ads/v2/list?' . http_build_query($params),
            [
                'Referer: https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/pt',
                'Origin: https://ads.tiktok.com',
            ]
        );

        // Scraping direto bloqueado (sessao exigida)? Tenta via Steel Browser.
        if ($body === null && SteelBrowser::isConfigured()) {
            $steel = SteelBrowser::fetch('https://ads.tiktok.com/creative_radar_api/v1/top_ads/v2/list?' . http_build_query($params));
            if ($steel['ok']) $body = $steel['html'];
        }

        if ($body === null) {
            return $this->emptyResult('TikTok: Creative Center indisponível (bloqueio ou mudança de API). Configure STEEL_API_URL para scraping com navegador.');
        }

        $json = json_decode($body, true);
        if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
            return $this->emptyResult('TikTok: Creative Center exige sessão (a resposta mudou e pode estar bloqueando scraping). Quando o admin ativar o Steel Browser (STEEL_API_URL no sistema), a busca volta a funcionar via navegador.');
        }

        $ads = [];
        foreach ($json['data']['list'] ?? [] as $item) {
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['id'] ?? ''),
                'advertiser' => $item['brand_name'] ?? '',
                'title' => $item['ad_title'] ?? '',
                'text' => $item['ad_title'] ?? '',
                'cta' => $item['cta'] ?? '',
                'media_type' => 'video',
                'media_url' => $item['video_info']['video_url'] ?? '',
                'thumbnail' => $item['video_info']['cover_url'] ?? '',
                'landing_page' => $item['landing_page'] ?? '',
                'platforms' => ['tiktok'],
                'started_at' => null,
                'ended_at' => null,
                'status' => 'active',
                'link' => !empty($item['id']) ? 'https://ads.tiktok.com/business/creativecenter/topads/' . $item['id'] . '/pc/pt' : '',
            ]);
        }

        if (empty($ads)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                'hint' => 'TikTok: nenhum anúncio para este termo no Creative Center'];
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }
}
