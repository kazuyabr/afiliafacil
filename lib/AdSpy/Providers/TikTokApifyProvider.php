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
        // Aceita token via options (para testar sem salvar) ou do banco
        $apifyToken = $options['apify_token'] ?? AdSpyKeys::apify($userId);
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
            return $this->emptyResult('TikTok (Apify): falha na chamada à API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Creative Center via Apify');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('TikTok (Apify): resposta inválida da API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Creative Center via Apify');
        }

        // Apify retorna array de itens (pode ser vazio []) ou {error:...}
        if (isset($json['error'])) {
            $err = (string)$json['error'];
            if (stripos($err, 'auth') !== false || stripos($err, 'token') !== false || stripos($err, 'unauthorized') !== false || stripos($err, '401') !== false) {
                return $this->emptyResult('TikTok (Apify): token inválido ou sem permissão. Gere um novo em console.apify.com/account/integrations e configure em IA → Busca de Anúncios.', 'TikTok Creative Center via Apify');
            }
            return $this->emptyResult('TikTok (Apify): ' . $err, 'TikTok Creative Center via Apify');
        }

        // Se retornou {data: [...]}, usa o data
        if (isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }

        // Array vazio [] = sucesso sem resultados (não é erro)
        if (!is_array($json)) {
            return $this->emptyResult('TikTok (Apify): formato de resposta inesperado. Verifique se o actor fetch_cat/tiktok-ads-library-scraper está acessível.', 'TikTok Creative Center via Apify');
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