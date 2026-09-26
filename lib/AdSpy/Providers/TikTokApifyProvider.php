<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../AdSpyKeys.php';

class TikTokApifyProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'tiktok';
    }

    public function search(string $query, array $options = []): array
    {
        $userId = (int)($options['user_id'] ?? 0);
        $apifyToken = AdSpyKeys::apify($userId);
        if ($apifyToken === '') {
            return $this->emptyResult('TikTok: configure seu token Apify em IA → Busca de Anúncios (conta grátis em apify.com, $5 de crédito/mês).');
        }

        $country = strtoupper($options['country'] ?? 'BR');
        $period = $options['period'] ?? 30;
        $maxItems = $options['max_items'] ?? 20;

        $body = $this->httpPost(
            'https://api.apify.com/v2/acts/fetch_cat~tiktok-ads-library-scraper/run-sync-get-dataset-items?token=' . urlencode($apifyToken),
            [
                'keywords' => [$query],
                'regions' => [$country],
                'period' => (string)$period,
                'sortBy' => 'ctr',
                'maxItems' => $maxItems,
            ]
        );

        if ($body === null) {
            return $this->emptyResult('TikTok (Apify): falha na chamada à API Apify.', 'TikTok Creative Center via Apify');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('TikTok (Apify): resposta inválida da API Apify.', 'TikTok Creative Center via Apify');
        }

        // Apify retorna array de itens ou {error:...}
        if (isset($json['error'])) {
            return $this->emptyResult('TikTok (Apify): ' . $json['error'], 'TikTok Creative Center via Apify');
        }

        if (!isset($json[0]) || !is_array($json)) {
            // Pode retornar {data: [...]} ou array direto
            if (isset($json['data']) && is_array($json['data'])) {
                $json = $json['data'];
            } else {
                return $this->emptyResult('TikTok (Apify): formato de resposta inesperado.', 'TikTok Creative Center via Apify');
            }
        }

        $ads = [];
        foreach ($json as $item) {
            if (!is_array($item)) continue;
            $videoUrl = (string)($item['videoUrl'] ?? '');
            $coverUrl = (string)($item['coverImageUrl'] ?? '');
            $detailUrl = (string)($item['detailUrl'] ?? '');

            // TikTok video URLs expiram — usamos cover + link do detalhe
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['adId'] ?? ''),
                'advertiser' => (string)($item['brandName'] ?? ''),
                'title' => (string)($item['adTitle'] ?? ''),
                'text' => (string)($item['adText'] ?? ''),
                'cta' => (string)($item['cta'] ?? ''),
                'media_type' => $videoUrl ? 'video' : 'image',
                'media_url' => $coverUrl,
                'thumbnail' => $coverUrl,
                'landing_page' => (string)($item['landingPage'] ?? ''),
                'platforms' => ['tiktok'],
                'started_at' => null,
                'ended_at' => null,
                'status' => 'active',
                'link' => $detailUrl ?: ($videoUrl ?: 'https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/pt'),
            ]);
        }

        if (empty($ads)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'TikTok Creative Center via Apify',
                'hint' => 'TikTok: nenhum anúncio encontrado para este termo no Creative Center.'];
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'TikTok Creative Center via Apify'];
    }
}