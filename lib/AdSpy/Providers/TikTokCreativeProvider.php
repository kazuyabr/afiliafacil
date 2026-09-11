<?php

require_once __DIR__ . '/AdSpyProvider.php';

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

        if ($body === null) {
            return $this->emptyResult('TikTok: Creative Center indisponível (bloqueio ou mudança de API).');
        }

        $json = json_decode($body, true);
        if (!is_array($json) || (int)($json['code'] ?? -1) !== 0) {
            return $this->emptyResult('TikTok: resposta inesperada do Creative Center.');
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

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }
}
