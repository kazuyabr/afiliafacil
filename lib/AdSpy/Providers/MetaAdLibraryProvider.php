<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../AdSpyKeys.php';
require_once __DIR__ . '/../SteelBrowser.php';

class MetaAdLibraryProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'meta';
    }

    public function search(string $query, array $options = []): array
    {
        $userId = (int)($options['user_id'] ?? 0);
        $token = AdSpyKeys::meta($userId) ?: (getenv('META_AD_ACCESS_TOKEN') ?: '');
        $sourceLabel = 'Biblioteca pública da Meta';
        if ($token !== '') {
            $result = $this->searchOfficialApi($query, $options, $token);
            $sourceLabel = 'API oficial da Meta';
            // Qualquer resposta "sem dados" da API oficial (erro de permissao OU lista
            // vazia por app sem Advanced Access) ganha segunda chance na pagina publica
            // da biblioteca via navegador — espionagem nao depende de App Review.
            if ($result['error'] !== null || !empty($result['empty'])) {
                $public = $this->searchPublicPage($query, $options);
                if ($public !== null) {
                    $public['source_label'] = 'Biblioteca de Anúncios da Meta (pública)';
                    return $public;
                }
            }
            $result['source_label'] = $sourceLabel;
            return $result;
        }

        return $this->searchPublicLibrary($query, $options);
    }

    /**
     * Pagina PUBLICA da biblioteca (a mesma do facebook.com/ads/library), ordenada por
     * mais impressoes — e o que o navegador renderiza quando alguem pesquisa na mao.
     */
    private function publicPageUrl(string $query, array $options): string
    {
        return 'https://www.facebook.com/ads/library/?' . http_build_query([
            'active_status' => 'active',
            'ad_type' => 'all',
            'country' => $options['countries'][0] ?? 'BR',
            'is_targeted_country' => 'false',
            'media_type' => 'all',
            'q' => $query,
            'search_type' => 'keyword_unordered',
            'sort_data' => ['direction' => 'desc', 'mode' => 'total_impressions'],
        ]);
    }

    /**
     * Busca na pagina publica via Steel Browser e extrai o JSON Relay embutido.
     * Retorna null quando a pagina nao abriu (bloqueio/mudanca) — preserva o erro original.
     */
    private function searchPublicPage(string $query, array $options): ?array
    {
        if (!SteelBrowser::isConfigured()) return null;

        $steel = SteelBrowser::fetch($this->publicPageUrl($query, $options));
        if (!$steel['ok'] || trim($steel['html']) === '') return null;

        $parsed = $this->parseRelayAds($steel['html']);
        if ($parsed !== null) {
            if ($parsed['total'] === 0) {
                // Pagina respondeu com 0 anuncios de verdade (count=0 no JSON) — nao é erro.
                return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'Biblioteca de Anúncios da Meta (pública)',
                    'hint' => 'Meta: nenhum anúncio ativo para este termo na biblioteca pública'];
            }
            $parsed['source_label'] = 'Biblioteca de Anúncios da Meta (pública)';
            return $parsed;
        }

        $legacy = $this->parseHtmlAds($steel['html']);
        if ($legacy['total'] > 0) {
            $legacy['source_label'] = 'Biblioteca de Anúncios da Meta (pública)';
            return $legacy;
        }
        return null;
    }

    /**
     * Extrai os anuncios do JSON Relay embutido em <script data-sjs> da pagina publica
     * (estrutura: ad_library_main -> search_results_connection -> edges -> collated_results).
     * Retorna null quando NEM o marcador da conexao aparece (pagina bloqueada/renderizada).
     */
    private function parseRelayAds(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*data-sjs[^>]*>(.*?)</script>#is', $html, $m)) {
            return null;
        }

        $found = false;
        $ads = [];
        $seen = [];

        foreach ($m[1] as $raw) {
            $json = json_decode($raw, true);
            if (!is_array($json)) continue;

            $stack = [$json];
            $guard = 0;
            while (!empty($stack) && $guard++ < 4000) {
                $cur = array_pop($stack);
                if (!is_array($cur)) continue;

                if (isset($cur['search_results_connection']) && is_array($cur['search_results_connection'])) {
                    $found = true;
                    foreach ((array)($cur['search_results_connection']['edges'] ?? []) as $edge) {
                        $node = $edge['node'] ?? [];
                        if (!is_array($node)) continue;
                        foreach ((array)($node['collated_results'] ?? []) as $collected) {
                            if (!is_array($collected)) continue;
                            $ad = $this->normalizeRelayAd($collected);
                            if ($ad !== null && !isset($seen[$ad['id']])) {
                                $seen[$ad['id']] = true;
                                $ads[] = $ad;
                            }
                        }
                    }
                    continue;
                }
                foreach ($cur as $v) {
                    if (is_array($v)) $stack[] = $v;
                }
            }
        }

        if (!$found) return null;
        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }

    private function normalizeRelayAd(array $collected): ?array
    {
        $snap = (array)($collected['snapshot'] ?? []);
        $id = (string)($collected['ad_archive_id'] ?? '');
        if ($id === '') return null;

        $images = (array)($snap['images'] ?? []);
        $videos = (array)($snap['videos'] ?? []);
        $isVideo = !empty($videos) || strtolower((string)($snap['display_format'] ?? '')) === 'video';
        $imageUrl = (string)($images[0]['original_image_url'] ?? $images[0]['resized_image_url'] ?? '');

        // DCO (Dynamic Creative Optimization): mídia está em snapshot.cards[]
        if ($snap['display_format'] === 'DCO' && empty($images) && !empty($snap['cards'])) {
            $firstCard = $snap['cards'][0] ?? [];
            $imageUrl = (string)($firstCard['original_image_url'] ?? $firstCard['resized_image_url'] ?? '');
            $videoHd = (string)($firstCard['video_hd_url'] ?? '');
            $videoPreview = (string)($firstCard['video_preview_image_url'] ?? '');
            if ($videoHd || $videoPreview) {
                $isVideo = true;
                $imageUrl = $videoHd ?: $videoPreview;
            }
        }

        // Sanitizar placeholders de template (ex.: {{product.description}})
        $text = (string)($snap['body']['text'] ?? $snap['caption'] ?? '');
        $title = (string)($snap['link_title'] ?? $snap['link_description'] ?? $snap['caption'] ?? '');
        $text = $this->sanitizeTemplatePlaceholders($text);
        $title = $this->sanitizeTemplatePlaceholders($title);
        // Fallback de texto vazio
        if ($text === '' && isset($snap['caption'])) $text = $this->sanitizeTemplatePlaceholders((string)$snap['caption']);

        return $this->normalizeAd([
            'id' => $id,
            'advertiser' => (string)($snap['page_name'] ?? ''),
            'title' => $title,
            'text' => $text,
            'cta' => (string)($snap['cta_text'] ?? ''),
            'media_type' => $isVideo ? 'video' : 'image',
            'media_url' => (string)($videos[0]['video_preview_image_url'] ?? $imageUrl),
            'thumbnail' => (string)($imageUrl), // thumb = imagem do anúncio (não avatar)
            'landing_page' => (string)($snap['link_url'] ?? ''),
            'platforms' => (array)($collected['publisher_platform'] ?? []),
            'started_at' => !empty($collected['start_date']) ? date('Y-m-d', (int)$collected['start_date']) : null,
            'ended_at' => !empty($collected['end_date']) ? date('Y-m-d', (int)$collected['end_date']) : null,
            'status' => empty($collected['end_date']) ? 'active' : 'inactive',
            'link' => 'https://www.facebook.com/ads/library/?id=' . $id,
        ]);
    }

    private function sanitizeTemplatePlaceholders(string $text): string
    {
        // Remove {{product.*}} e templates similares
        $text = preg_replace('/\{\{[^}]+\}\}/', '', $text);
        return trim($text);
    }

    private function searchOfficialApi(string $query, array $options, string $token): array
    {
        $countries = $options['countries'] ?? ['BR'];
        $params = [
            'access_token' => $token,
            'search_terms' => $query,
            'ad_reached_countries' => json_encode($countries),
            'ad_type' => 'ALL',
            'ad_active_status' => $options['status'] ?? 'ALL',
            'fields' => 'id,page_id,page_name,ad_creative_bodies,ad_creative_link_titles,ad_creative_link_captions,ad_delivery_start_time,ad_delivery_stop_time,publisher_platforms,ad_snapshot_url',
            'limit' => 50,
        ];
        if (!empty($options['started_after'])) $params['ad_delivery_date_min'] = $options['started_after'];

        $body = $this->httpGet('https://graph.facebook.com/v21.0/ads_archive?' . http_build_query($params));
        if ($body === null) return $this->emptyResult('Meta API: sem resposta do servidor da Meta (instável agora). Aguarde 1 minuto e toque em "Espionar" de novo — o sistema tentará novamente.');

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('Meta API: resposta invalida.');
        }
        if (isset($json['error'])) {
            return $this->emptyResult($this->friendlyMetaError($json['error']));
        }

        $ads = [];
        foreach ($json['data'] ?? [] as $item) {
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['id'] ?? ''),
                'advertiser' => $item['page_name'] ?? '',
                'title' => $item['ad_creative_link_titles'][0] ?? '',
                'text' => $item['ad_creative_bodies'][0] ?? '',
                'media_type' => 'image',
                'platforms' => $item['publisher_platforms'] ?? [],
                'started_at' => isset($item['ad_delivery_start_time']) ? substr($item['ad_delivery_start_time'], 0, 10) : null,
                'ended_at' => isset($item['ad_delivery_stop_time']) ? substr($item['ad_delivery_stop_time'], 0, 10) : null,
                'status' => empty($item['ad_delivery_stop_time']) ? 'active' : 'inactive',
                'link' => $item['ad_snapshot_url'] ?? ('https://www.facebook.com/ads/library/?id=' . ($item['id'] ?? '')),
            ]);
        }

        if (empty($ads)) {
            // Token valido + zero resultados = o app Meta sofre a restricao "somente
            // anuncios politicos/sociais" sem aprovacao. Aviso honesto, sem falsar erro.
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'API oficial da Meta',
                'hint' => 'Meta: 0 anúncios com sua chave. Se o termo tem anúncios, seu aplicativo Meta provavelmente ainda nao foi aprovado para a biblioteca completa'];
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'API oficial da Meta'];
    }

    /** Traduz o cru JSON de erro da Meta em orientacao pratica. */
    private function friendlyMetaError(array $err): string
    {
        $msg = (string)($err['message'] ?? 'erro desconhecido');
        $code = (int)($err['code'] ?? 0);

        if ($code === 190 || str_contains($msg, 'access token')) {
            if (stripos($msg, 'expired') !== false) {
                return 'Meta API: seu token expirou (expira sozinho em ~1-3 meses, não é bug). Gere um novo em developers.facebook.com (em 5 min) e cole em Configurações → Avançado → IA → Busca de Anúncios. Detalhe: ' . $msg;
            }
            return 'Meta API: o token foi rejeitado (' . $msg . '). Gere um novo em developers.facebook.com e atualize em Configurações → Avançado → IA (Busca de Anúncios).';
        }
        if ($code === 200 || str_contains(mb_strtolower($msg), 'permission')) {
            return 'Meta API: permissão negada (' . $msg . '). O app precisa da permissao ads_read válida de um usuário com conta ativa.';
        }
        if ($code === 613 || $code === 4 || $code === 17 || str_contains(mb_strtolower($msg), 'rate limit')) {
            return 'Meta API: limite de chamadas temporário atingido. Aguarde alguns minutos e tente de novo.';
        }
        if (str_contains(mb_strtolower($msg), 'ads_archive') || str_contains(mb_strtolower($msg), 'library')) {
            return 'Meta API: o acesso a ads_archive exige app revisado/aprovado pela Meta — sem isso, somente anúncios políticos/sociais ficam visíveis.';
        }
        return 'Meta API: ' . $msg;
    }

    private function searchPublicLibrary(string $query, array $options): array
    {
        $countries = $options['countries'] ?? ['BR'];
        $params = [
            'q' => $query,
            'count' => 30,
            'active_status' => $options['status'] ?? 'all',
            'ad_type' => 'all',
            'media_type' => 'all',
            'search_type' => 'keyword_unordered',
        ];
        foreach (array_values($countries) as $i => $country) {
            $params["countries[{$i}]"] = $country;
        }

        $body = $this->httpGet(
            'https://www.facebook.com/ads/library/async/search_ads/?' . http_build_query($params),
            ['Referer: https://www.facebook.com/ads/library/']
        );

        // Scraping direto bloqueado (403/sessao)? Pagina publica via Steel Browser.
        if ($body === null) {
            $public = $this->searchPublicPage($query, $options);
            if ($public !== null) return $public;
            return $this->emptyResult('Meta: biblioteca pública indisponível (bloqueio ou mudança de layout). Configure STEEL_API_URL (navegador) ou META_AD_ACCESS_TOKEN (API oficial).');
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->emptyResult('Meta: resposta inesperada da biblioteca pública (layout pode ter mudado).');
        }

        $ads = [];
        foreach ($json['payload']['results'] ?? ($json['results'] ?? []) as $item) {
            $snapshot = $item['snapshot'] ?? [];
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['ad_archive_id'] ?? $item['adArchiveID'] ?? ''),
                'advertiser' => $snapshot['page_name'] ?? ($item['page_name'] ?? ''),
                'title' => $snapshot['title'] ?? '',
                'text' => $snapshot['body']['text'] ?? ($snapshot['caption'] ?? ''),
                'cta' => $snapshot['cta_text'] ?? '',
                'media_type' => !empty($snapshot['videos']) ? 'video' : 'image',
                'media_url' => $snapshot['videos'][0]['video_preview_image_url'] ?? ($snapshot['images'][0]['original_image_url'] ?? ''),
                'thumbnail' => $snapshot['images'][0]['original_image_url'] ?? '',
                'landing_page' => $snapshot['link_url'] ?? '',
                'platforms' => $item['publisher_platform'] ?? [],
                'started_at' => isset($item['start_date']) ? date('Y-m-d', (int)$item['start_date']) : null,
                'ended_at' => isset($item['end_date']) ? date('Y-m-d', (int)$item['end_date']) : null,
                'status' => empty($item['end_date']) ? 'active' : 'inactive',
                'link' => 'https://www.facebook.com/ads/library/?id=' . ($item['ad_archive_id'] ?? ''),
            ]);
        }

        if (empty($ads)) {
            return $this->emptyResult('Meta: nenhum anúncio retornado (a biblioteca pública pode exigir sessão de navegador). Configure META_AD_ACCESS_TOKEN ou STEEL_API_URL.', 'Biblioteca de Anúncios da Meta (pública)');
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'Biblioteca de Anúncios da Meta (pública)'];
    }

    /**
     * Parse de anúncios do HTML completo da biblioteca pública (via Steel Browser).
     * Extrai item da tabela/card com seletores resistentes a mudancas de DOM.
     */
    private function parseHtmlAds(string $html): array
    {
        $ads = [];

        // Estrategia 1: elementos com data-ad-archive-id (mais confiavel)
        if (preg_match_all('/data-ad-archive-id=["\'](\d+)["\']/', $html, $m)) {
            foreach (array_unique($m[1]) as $id) {
                $ads[] = $this->normalizeAd([
                    'id' => $id,
                    'title' => '',
                    'text' => '',
                    'media_type' => 'image',
                    'link' => 'https://www.facebook.com/ads/library/?id=' . $id,
                ]);
            }
        }

        // Estrategia 2: re coletar por <article> ou card se o primeiro falhar
        if (empty($ads) && preg_match_all('/<article[^>]*class=["\'][^"\']*card[^"\']*["\'][^>]*>(.*?)<\/article>/is', $html, $m)) {
            foreach ($m[1] as $cardHtml) {
                $id = '';
                $title = '';
                if (preg_match('/data-ad-archive-id=["\'](\d+)["\']/', $cardHtml, $lm)) $id = $lm[1];
                if (preg_match('/<h[1-6][^>]*>([^<]{2,80})<\/h1>/i', $cardHtml, $t)) $title = trim($t[1]);
                $ads[] = $this->normalizeAd([
                    'id' => $id !== '' ? $id : md5($cardHtml),
                    'title' => $title,
                    'link' => $id !== '' ? 'https://www.facebook.com/ads/library/?id=' . $id : '',
                ]);
            }
        }

        if (empty($ads)) {
            return $this->emptyResult('Meta: a biblioteca pública via navegador retornou HTML sem ads reconhecíveis (mudança de layout). Tente a API Oficial (BYOK) ou revise o SteelBrowser.');
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }
}
