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
        if ($country === '' || $country === 'ALL') $country = 'BR'; // actor FALHA com "ALL" (run FAILED)
        $period = $options['period'] ?? 30;
        $maxItems = $options['max_items'] ?? 20;

        // Actor só aceita period "7"|"30"|"180" e sortBy "for_you"|"ctr"|"like"|"cost"
        $period = in_array((string)$period, ['7', '30'], true) ? (string)$period : '180';
        $sortBy = (string)($options['order_by'] ?? 'ctr');
        if (!in_array($sortBy, ['for_you', 'ctr', 'like', 'cost'], true)) $sortBy = 'ctr';

        $payload = [
            'regions' => [$country],
            'period' => $period,
            'sortBy' => $sortBy,
            'maxItems' => $maxItems,
        ];
        // Query vazia = descoberta (Top Ads do país/período); campo correto do actor é "keyword" (string)
        if (trim($query) !== '') $payload['keyword'] = $query;

        // Até 3 tentativas: o actor tem ~8% de runs transitórios FAILED ("did not succeed")
        $body = null;
        for ($try = 0; $try < 3; $try++) {
            if ($try > 0) sleep(2);
            $body = $this->httpPost(
                'https://api.apify.com/v2/acts/fetch_cat~tiktok-ads-library-scraper/run-sync-get-dataset-items?token=' . urlencode($apifyToken),
                $payload
            );
            if ($body === null) continue;
            $probe = json_decode($body, true);
            if (!is_array($probe) || !isset($probe['error'])) break;
            $msg = $probe['error'];
            if (is_array($msg)) $msg = $msg['message'] ?? '';
            if (stripos((string)$msg, 'did not succeed') === false && stripos((string)$msg, 'ECONN') === false) break;
        }

        if ($body === null) {
            return $this->emptyResult('TikTok (Apify): falha na chamada à API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Creative Center via Apify');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('TikTok (Apify): resposta inválida da API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Creative Center via Apify');
        }

        // Apify retorna array de itens (pode ser vazio []) ou {error:...} (string OU objeto)
        if (isset($json['error'])) {
            $err = $json['error'];
            if (is_array($err)) {
                $err = (string)($err['message'] ?? $err['msg'] ?? json_encode($err, JSON_UNESCAPED_UNICODE));
            } else {
                $err = (string)$err;
            }
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
            $adText = (string)($item['adText'] ?? '');
            $landing = (string)($item['landingPageUrl'] ?? $item['landingPage'] ?? '');

            // Anunciante: brandName/advertiserName nem sempre existem (modo descoberta) → domínio do link
            $brand = (string)($item['brandName'] ?? '');
            if ($brand === '') $brand = (string)($item['advertiserName'] ?? '');
            if ($brand === '' && $landing !== '') $brand = (string)(parse_url($landing, PHP_URL_HOST) ?: '');
            if ($brand === '') $brand = 'TikTok Ads';

            $title = (string)($item['adTitle'] ?? '');
            if ($title === '' && $adText !== '') $title = mb_substr($adText, 0, 60);

            // Métricas do card (rank/likes/ctr do Creative Center)
            $stats = [];
            if (!empty($item['rank']) && is_numeric($item['rank'])) $stats[] = 'Top ' . (int)$item['rank'];
            if (isset($item['likes']) && is_numeric($item['likes']) && (float)$item['likes'] > 0) {
                $stats[] = number_format((float)$item['likes']) . ' curtidas';
            }
            if (isset($item['ctr']) && is_numeric($item['ctr']) && (float)$item['ctr'] > 0) {
                $ctr = (float)$item['ctr'];
                $stats[] = 'CTR ' . rtrim(rtrim(number_format($ctr < 1 ? $ctr * 100 : $ctr, 2, ',', '.'), '0'), ',') . '%';
            }
            $text = trim($adText . ($stats ? ($adText ? ' · ' : '') . implode(' · ', $stats) : ''));

            // TikTok video URLs expiram — usamos cover + link do detalhe
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['adId'] ?? ''),
                'advertiser' => $brand,
                'title' => $title,
                'text' => $text,
                'cta' => (string)($item['cta'] ?? ''),
                'media_type' => $videoUrl ? 'video' : 'image',
                'media_url' => $coverUrl,
                'thumbnail' => $coverUrl,
                'landing_page' => $landing,
                'platforms' => ['tiktok'],
                'started_at' => null,
                'ended_at' => null,
                'status' => 'active',
                'link' => $detailUrl ?: ($videoUrl ?: 'https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/pt'),
            ]);
        }

        if (empty($ads)) {
            // Fallback: Apify vazio → tenta o Creative Center direto (API JSON + Steel)
            $fallback = $this->creativeFallback($query, $options);
            if (!empty($fallback['ads'])) {
                return [
                    'ads' => $fallback['ads'],
                    'total' => count($fallback['ads']),
                    'error' => null,
                    'source_label' => 'TikTok Creative Center (fallback do Apify)',
                ];
            }
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'TikTok Creative Center via Apify',
                'hint' => trim($query) === ''
                    ? 'TikTok: o Apify não retornou Top Ads para este país/período. Tente outro filtro ou use Meta/Google.'
                    : 'TikTok: nenhum anúncio encontrado para este termo. Termos em inglês costumam retornar mais resultados que em português.'];
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'TikTok Creative Center via Apify'];
    }

    /** Busca direta no Creative Center quando o Apify não achou nada. */
    private function creativeFallback(string $query, array $options): array
    {
        try {
            if (!class_exists('TikTokCreativeProvider', false)) {
                require_once __DIR__ . '/TikTokCreativeProvider.php';
            }
            $creative = new TikTokCreativeProvider();
            return $creative->search($query, $options);
        } catch (Throwable $e) {
            return ['ads' => [], 'total' => 0, 'error' => null];
        }
    }
}