<?php

require_once __DIR__ . '/AdSpyProvider.php';

class GoogleTransparencyProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'google';
    }

    public function search(string $query, array $options = []): array
    {
        $apiKey = $options['serpapi_key'] ?? (getenv('SERPAPI_KEY') ?: '');
        if ($apiKey === '') {
            return $this->emptyResult('Google: configure SERPAPI_KEY (free: 250 buscas/mês) ou sua chave em BYOK.');
        }

        $params = [
            'engine' => 'google_ads_transparency_center',
            'api_key' => $apiKey,
            'num' => 40,
        ];

        if (!empty($options['advertiser_id'])) {
            $params['advertiser_id'] = $options['advertiser_id'];
        } else {
            $params['text'] = $query;
        }
        if (!empty($options['region'])) $params['region'] = $options['region'];

        $body = $this->httpGet('https://serpapi.com/search.json?' . http_build_query($params));
        if ($body === null) return $this->emptyResult('Google: falha na requisição ao SerpApi');

        $json = json_decode($body, true);
        if (!is_array($json) || isset($json['error'])) {
            return $this->emptyResult('Google: ' . ($json['error'] ?? 'resposta inválida'));
        }

        $ads = [];
        foreach ($json['ad_creatives'] ?? [] as $item) {
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['ad_creative_id'] ?? ''),
                'advertiser' => $item['advertiser'] ?? '',
                'title' => '',
                'text' => '',
                'media_type' => $item['format'] ?? 'text',
                'media_url' => $item['image'] ?? '',
                'thumbnail' => $item['image'] ?? '',
                'landing_page' => '',
                'platforms' => [$item['format'] ?? 'display'],
                'started_at' => isset($item['first_shown']) ? date('Y-m-d', (int)$item['first_shown']) : null,
                'ended_at' => isset($item['last_shown']) ? date('Y-m-d', (int)$item['last_shown']) : null,
                'status' => 'active',
                'link' => $item['details_link'] ?? '',
            ]);
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }
}
