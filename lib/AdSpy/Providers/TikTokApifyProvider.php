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
        // Query vazia = descoberta (Top Ads do país/período); o input do actor
        // fetch_cat/tiktok-ads-library-scraper espera "keywords" (ARRAY de strings)
        // — campo desconhecido é ignorado pelo actor e a busca volvava sem filtro.
        if (trim($query) !== '') $payload['keywords'] = [$query];

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
            if ($brand === '') $brand = 'Anunciante desconhecido';

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
            // Fallback: Apify vazio → tenta a API direta do Creative Center (40101 sem sessão)
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

    /**
     * Busca de hashtags por termo (modo Trends com query).
     * O Creative Center NAO tem busca por hashtag nem logado — o ator
     * powerai/tiktok-hashtag-search-scraper resolve (keywords + maxResults).
     */
    public function hashtagSearch(string $query, array $options = []): array
    {
        $userId = (int)($options['user_id'] ?? 0);
        $apifyToken = $options['apify_token'] ?? AdSpyKeys::apify($userId);
        if ($apifyToken === '') {
            return $this->emptyResult('TikTok Hashtags: configure seu token Apify em IA → Busca de Anúncios para buscar hashtags por termo (conta grátis em apify.com, $5 de crédito/mês).');
        }
        $query = trim($query);
        if ($query === '') {
            return $this->emptyResult('TikTok Hashtags: informe um termo para buscar hashtags.');
        }

        // Actor valida maxResults >= 30 (default dele) — acima de 30 nao ha ganho real
        $maxResults = min(100, max(30, (int)($options['limit'] ?? 30)));
        $payload = ['keywords' => $query, 'maxResults' => $maxResults];

        // run-sync espera o run terminar — timeout maior que o normal
        $prevTimeout = $this->timeout;
        $this->timeout = 90;
        $body = null;
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) sleep(2);
            $body = $this->httpPost(
                'https://api.apify.com/v2/acts/powerai~tiktok-hashtag-search-scraper/run-sync-get-dataset-items?token=' . urlencode($apifyToken),
                $payload
            );
            if ($body !== null) break;
        }
        $this->timeout = $prevTimeout;

        if ($body === null) {
            return $this->emptyResult('TikTok Hashtags (Apify): falha na chamada à API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Hashtags via Apify');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('TikTok Hashtags (Apify): resposta inválida da API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Hashtags via Apify');
        }

        if (isset($json['error'])) {
            $err = $json['error'];
            if (is_array($err)) {
                $err = (string)($err['message'] ?? $err['msg'] ?? json_encode($err, JSON_UNESCAPED_UNICODE));
            } else {
                $err = (string)$err;
            }
            if (stripos($err, 'auth') !== false || stripos($err, 'token') !== false || stripos($err, 'unauthorized') !== false || stripos($err, '401') !== false) {
                return $this->emptyResult('TikTok Hashtags (Apify): token inválido ou sem permissão. Gere um novo em console.apify.com/account/integrations e configure em IA → Busca de Anúncios.', 'TikTok Hashtags via Apify');
            }
            return $this->emptyResult('TikTok Hashtags (Apify): ' . $err, 'TikTok Hashtags via Apify');
        }

        if (isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }
        if (!is_array($json)) {
            return $this->emptyResult('TikTok Hashtags (Apify): formato de resposta inesperado.', 'TikTok Hashtags via Apify');
        }

        $ads = [];
        foreach ($json as $item) {
            if (!is_array($item)) continue;
            $name = ltrim(trim((string)($item['cha_name'] ?? $item['hashtag_name'] ?? $item['name'] ?? '')), '#');
            if ($name === '') continue;

            $posts = is_numeric($item['user_count'] ?? null) ? (float)$item['user_count'] : null;
            $views = is_numeric($item['view_count'] ?? null) ? (float)$item['view_count'] : null;
            $stats = [];
            if ($posts !== null && $posts > 0) $stats[] = $this->bigNumber($posts) . ' posts';
            if ($views !== null && $views > 0) $stats[] = $this->bigNumber($views) . ' views';

            $desc = $this->sanitizeText((string)($item['desc'] ?? ''));
            $text = trim($desc . ($stats ? ($desc ? ' · ' : '') . implode(' · ', $stats) : ''));
            if ($text === '') $text = 'Hashtag encontrada no TikTok';

            $cover = (string)($item['cover'] ?? '');
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['id'] ?? sha1($name)),
                'advertiser' => '#' . $name,
                'title' => 'Hashtag · ' . ($stats[0] ?? 'em alta'),
                'text' => $text,
                'media_type' => $cover !== '' ? 'image' : 'text',
                'media_url' => $cover,
                'thumbnail' => $cover,
                'platforms' => ['tiktok'],
                'link' => 'https://www.tiktok.com/tag/' . rawurlencode($name),
            ]);
        }

        if (empty($ads)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                'source_label' => 'TikTok Hashtags via Apify',
                'hint' => 'TikTok: nenhuma hashtag encontrada para "' . $query . '". Tente termos mais curtos ou em inglês (ex.: "meme").'];
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'TikTok Hashtags via Apify'];
    }

    /**
     * Trends em alta sem termo (ator anyx) via Apify — lista PUBLICA.
     * O Creative Center anonimo so serve ~3 linhas; login no painel foi
     * descontinuado, entao a rota padrão é o ator anyx sem cookies.
     * Periodo do ator: 7 | 30 | 120.
     */
    public function trendingHashtags(array $options = []): array
    {
        $userId = (int)($options['user_id'] ?? 0);
        $apifyToken = $options['apify_token'] ?? AdSpyKeys::apify($userId);
        if ($apifyToken === '') {
            return $this->emptyResult('TikTok Trends: falta seu token Apify — configure em IA → Busca de Anúncios para ver as hashtags em alta.', 'TikTok Trends via Apify');
        }

        $country = strtoupper((string)($options['country'] ?? 'BR'));
        if ($country === '' || $country === 'ALL') $country = 'BR';
        $period = in_array((string)($options['period'] ?? '7'), ['7', '30'], true) ? (string)$options['period'] : '7';
        $maxItems = min(100, max(10, (int)($options['limit'] ?? 30)));

        $payload = [
            'countryCode' => $country,
            'period' => $period,
            'maxItems' => $maxItems,
        ];

        $prevTimeout = $this->timeout;
        $this->timeout = 120;
        $body = null;
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) sleep(2);
            $body = $this->httpPost(
                'https://api.apify.com/v2/acts/anyxsolutions~tiktok-trending-hashtags-scraper/run-sync-get-dataset-items?token=' . urlencode($apifyToken),
                $payload
            );
            if ($body !== null) break;
        }
        $this->timeout = $prevTimeout;

        if ($body === null) {
            return $this->emptyResult('TikTok Trends (Apify): falha na chamada à API Apify. Verifique seu token em IA → Busca de Anúncios.', 'TikTok Trends via Apify');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('TikTok Trends (Apify): resposta inválida da API Apify.', 'TikTok Trends via Apify');
        }
        if (isset($json['error'])) {
            $err = $json['error'];
            if (is_array($err)) {
                $err = (string)($err['message'] ?? $err['msg'] ?? json_encode($err, JSON_UNESCAPED_UNICODE));
            } else {
                $err = (string)$err;
            }
            if (stripos($err, 'auth') !== false || stripos($err, 'token') !== false || stripos($err, 'unauthorized') !== false || stripos($err, '401') !== false) {
                return $this->emptyResult('TikTok Trends (Apify): token inválido ou sem permissão. Gere um novo em console.apify.com/account/integrations e configure em IA → Busca de Anúncios.', 'TikTok Trends via Apify');
            }
            return $this->emptyResult('TikTok Trends (Apify): ' . $err, 'TikTok Trends via Apify');
        }
        if (isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }
        if (!is_array($json)) {
            return $this->emptyResult('TikTok Trends (Apify): formato de resposta inesperado.', 'TikTok Trends via Apify');
        }

        $ads = [];
        foreach ($json as $item) {
            if (!is_array($item)) continue;
            $name = ltrim(trim((string)($item['hashtagName'] ?? $item['hashtag_name'] ?? '')), '#');
            if ($name === '') continue;

            $stats = [];
            $rank = $item['rank'] ?? null;
            if ($rank !== null && $rank !== '' && $rank !== 0) $stats[] = '#' . $rank;
            $posts = is_numeric($item['publishCount'] ?? null) ? (float)$item['publishCount'] : null;
            if ($posts !== null && $posts > 0) $stats[] = $this->bigNumber($posts) . ' posts';
            $views = is_numeric($item['videoViews'] ?? null) ? (float)$item['videoViews'] : null;
            if ($views !== null && $views > 0) $stats[] = $this->bigNumber($views) . ' views';

            $cover = '';
            $desc = '';
            $creator = $item['topCreators'][0] ?? null;
            if (is_array($creator)) {
                $cover = (string)($creator['avatarUrl'] ?? '');
                $nick = trim((string)($creator['nickname'] ?? ''));
                if ($nick !== '') $desc = 'Criador em alta: ' . $nick;
            }
            $text = trim($desc . ($stats ? ($desc ? ' · ' : '') . implode(' · ', $stats) : ''));
            if ($text === '') $text = 'Hashtag em alta no TikTok';

            $link = (string)($item['url'] ?? '');
            if ($link === '') $link = 'https://www.tiktok.com/tag/' . rawurlencode($name);

            $ads[] = $this->normalizeAd([
                'id' => (string)($item['hashtagId'] ?? sha1($name)),
                'advertiser' => '#' . $name,
                'title' => 'Trends · ' . ($stats[0] ?? 'em alta'),
                'text' => $text,
                'media_type' => $cover !== '' ? 'image' : 'text',
                'media_url' => $cover,
                'thumbnail' => $cover,
                'platforms' => ['tiktok'],
                'link' => $link,
            ]);
        }

        if (empty($ads)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                'source_label' => 'TikTok Trends via Apify',
                'hint' => 'TikTok: a lista não voltou — tente de novo em instantes.'];
        }

        $result = ['ads' => $ads, 'total' => count($ads), 'error' => null,
            'source_label' => 'TikTok Trends via Apify'];
        // Sem login a lista pública do Creative Center traz só as primeiras (~3)
        if (count($ads) <= 3) {
            $result['hint'] = 'TikTok: sem login a lista pública traz só as primeiras hashtags (' . count($ads) . ' resultados).';
        }
        return $result;
    }

    /** 582501992 → "582,5M" | 24896 → "24.896" */
    private function bigNumber(float $n): string
    {
        if ($n >= 1e9) return rtrim(rtrim(number_format($n / 1e9, 1, ',', '.'), '0'), ',') . 'B';
        if ($n >= 1e6) return rtrim(rtrim(number_format($n / 1e6, 1, ',', '.'), '0'), ',') . 'M';
        return number_format($n, 0, ',', '.');
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