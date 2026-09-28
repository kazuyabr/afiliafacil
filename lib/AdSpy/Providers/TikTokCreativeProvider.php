<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../SteelBrowser.php';

/**
 * TikTok Creative Center — descoberta sem Apify (gratuita).
 *
 * - search(): Top Ads por palavra-chave (API creative_radar_api, fallback Steel)
 * - discover(): trends / hashtags / topads (periodo, regiao, ordenacao)
 *
 * Preferencia sempre: API JSON direta -> Steel Browser (JS rendering).
 * O Creative Center e publico, mas o scraping direto as vezes exige sessao;
 * o Steel contorna renderizando a pagina com navegador real.
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
     * Descoberta sem palavra-chave.
     * @param string $mode trends (hashtags em alta) | topads | hashtags (alias de trends)
     */
    public function discover(string $mode, array $options = []): array
    {
        switch ($mode) {
            case 'topads':
                return $this->topAdsDiscovery($options);
            case 'hashtags':
            case 'trends':
            default:
                return $this->trends($options);
        }
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

        return $this->adsFromItems($items, 'topads', 'TikTok Creative Center (API direta)', 'TikTok: nenhum anúncio no Top Ads para este filtro.');
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

    /**
     * Trends & Hashtags em alta — pagina publica do Creative Center renderizada via Steel.
     * O Creative Center anonimo entrega apenas as TOP N hashtags (as demais exigem
     * login no TikTok) — por isso a fonte e a lista de hashtags do modulo Trends.
     */
    private function trends(array $options): array
    {
        $country = strtoupper($options['country'] ?? 'BR');
        $period = (int)($options['period'] ?? 7);

        $r = $this->viaPage(
            'https://ads.tiktok.com/creative/creativeCenter/trends/hashtag?locale=pt&deviceType=pc&region='
                . $country . '&period=' . $period,
            $options,
            'trends'
        );
        if (!empty($r['ads'])) {
            $r['hint'] = 'Creative Center anônimo: só as primeiras hashtags aparecem — faça login no TikTok em ads.tiktok.com para ver a lista completa.';
        }
        return $r;
    }

    /** Busca a pagina do Creative Center (Steel, com render de JS) e extrai itens do estado embutido. */
    private function viaPage(string $url, array $options, string $kind): array
    {
        if (!SteelBrowser::isConfigured()) {
            return $this->emptyResult(
                'TikTok ' . $kind . ': configure Steel Browser em Admin → Configurações para ler o Creative Center (página pública com JS).',
                'TikTok Creative Center (' . ucfirst($kind) . ')'
            );
        }

        // waitForTimeout: o Creative Center e uma SPA — sem espera, o Steel só captura o shell SSR.
        $steel = SteelBrowser::fetch($url, 120, ['waitForTimeout' => 8000]);
        if (!$steel['ok']) {
            return $this->emptyResult(
                'TikTok ' . $kind . ': ' . ($steel['error'] ?? 'falha no Steel Browser') . ' Verifique a URL em Admin → Configurações.',
                'TikTok Creative Center (' . ucfirst($kind) . ')'
            );
        }

        $items = $this->parseEmbeddedJson($steel['html'], $kind);
        if (empty($items)) {
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true,
                'source_label' => 'TikTok Creative Center (' . ucfirst($kind) . ')',
                'hint' => 'TikTok: a página do Creative Center carregou mas não retornou ' . $kind . ' reconhecíveis (mudança de layout?).'];
        }

        return $this->adsFromItems($items, $kind, 'TikTok Creative Center (' . ucfirst($kind) . ') via Steel Browser', 'TikTok: nada em alta para este filtro.');
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

    /**
     * Extrai JSON embutido em <script> do HTML do Creative Center
     * e procura arrays de objetos com campos esperados (defensivo).
     */
    private function parseEmbeddedJson(string $html, string $kind): array
    {
        $items = [];
        // Procura tags <script ...> com JSON (state do app)
        if (preg_match_all('#<script[^>]*>(\{.+?\})</script>#s', $html, $m)) {
            foreach ($m[1] as $chunk) {
                $json = json_decode($chunk, true);
                if (!is_array($json)) continue;
                $items = $this->findItemArrays($json, $kind);
                if (!empty($items)) return $items;
            }
        }
        // Fallback: JSON inline em janelas globais (window._X = {...};)
        if (preg_match_all('#window\.[A-Za-z_$][\w$]*\s*=\s*(\{.+?\});#s', $html, $m)) {
            foreach ($m[1] as $chunk) {
                $json = json_decode($chunk, true);
                if (!is_array($json)) continue;
                $items = $this->findItemArrays($json, $kind);
                if (!empty($items)) return $items;
            }
        }
        return [];
    }

    /** Varre o JSON em profundidade por arrays de "itens" com shape conhecido. */
    private function findItemArrays($node, string $kind): array
    {
        if (!is_array($node)) return [];
        if (array_is_list($node)) {
            foreach ($node as $el) {
                if (!is_array($el)) continue;
                $mapped = $kind === 'topads'
                    ? $this->mapTopAdItem($el)
                    : $this->mapTrendItem($el, $kind);
                if ($mapped !== null) return $node; // array inteiro ja mapeavel
            }
            // Lista sem itens diretos: desce nos filhos (ex.: queries[].state.data.pages[].data)
            foreach ($node as $el) {
                $found = $this->findItemArrays($el, $kind);
                if (!empty($found)) return $found;
            }
            return [];
        }
        foreach ($node as $v) {
            $found = $this->findItemArrays($v, $kind);
            if (!empty($found)) return $found;
        }
        return [];
    }

    // ── Mapeamento para ads ──────────────────────────────────────────────

    private function adsFromItems(array $items, string $kind, string $sourceLabel, string $emptyHint): array
    {
        $ads = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $ad = $kind === 'topads'
                ? $this->mapTopAdItem($item)
                : $this->mapTrendItem($item, $kind);
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

    /** Item de trend/hashtag vira "ad" visualizavel no grid. */
    private function mapTrendItem(array $item, string $kind): ?array
    {
        $name = (string)($item['hashtagName'] ?? $item['hashtag'] ?? $item['hashtag_name'] ?? $item['challenge_name']
            ?? $item['name'] ?? $item['title'] ?? $item['word'] ?? $item['desc'] ?? '');
        $name = ltrim(trim($name), '#');
        if ($name === '') return null;

        // Formato real do Creative Center: publishCnt (posts), vv (views), rankIndex, topCreators
        $count = $item['publishCnt'] ?? $item['post_count'] ?? $item['uses'] ?? $item['video_count']
            ?? $item['popularities'] ?? ($item['stats']['post_count'] ?? null);
        $views = $item['vv'] ?? $item['views'] ?? null;
        $rank = $item['rankIndex'] ?? $item['rank'] ?? null;
        $cover = (string)($item['cover'] ?? $item['cover_url'] ?? $item['coverUrl'] ?? $item['display_image'] ?? $item['image'] ?? '');
        if ($cover === '' && !empty($item['topCreators'][0]['avatarURL'])) {
            $cover = (string)$item['topCreators'][0]['avatarURL']; // avatar do criador #1 como capa
        }
        $desc = (string)($item['description'] ?? $item['text'] ?? '');
        if ($desc === '' && !empty($item['topCreators'][0]['nickname'])) {
            $desc = 'Criador em alta: ' . $item['topCreators'][0]['nickname'];
        }

        $statsBits = [];
        if ($rank !== null && $rank !== '') $statsBits[] = '#' . $rank;
        if ($count !== null && $count !== '') $statsBits[] = (is_numeric($count) ? $this->formatBigNumber((float)$count) : $count) . ' posts';
        if ($views !== null && $views !== '') $statsBits[] = (is_numeric($views) ? $this->formatBigNumber((float)$views) : $views) . ' views';
        if (isset($item['growth']) && is_numeric($item['growth'])) $statsBits[] = '+' . $item['growth'] . '%';
        if (isset($item['engagement_rate']) && is_numeric($item['engagement_rate'])) $statsBits[] = $item['engagement_rate'] . '% engajamento';

        $text = trim($desc . ($statsBits ? ($desc ? ' · ' : '') . implode(' · ', $statsBits) : ''));
        if ($text === '') $text = $kind === 'hashtags' ? 'Hashtag em alta no TikTok' : 'Conteúdo em alta no TikTok';

        return [
            'id' => sha1($kind . '|#|' . $name),
            'advertiser' => '#' . $name,
            'title' => ucfirst($kind) . ' · ' . ($statsBits[0] ?? 'em alta'),
            'text' => $text,
            'cta' => '',
            'media_type' => $cover ? 'image' : 'text',
            'media_url' => $cover,
            'thumbnail' => $cover,
            'landing_page' => '',
            'platforms' => ['tiktok'],
            'started_at' => null,
            'ended_at' => null,
            'status' => 'active',
            'link' => 'https://ads.tiktok.com/creative/creativeCenter/trends/hashtag?locale=pt&deviceType=pc&region=BR&period=7',
        ];
    }

    /** 582501992 → "582,5M" | 24896 → "24.896" */
    private function formatBigNumber(float $n): string
    {
        if ($n >= 1e9) return rtrim(rtrim(number_format($n / 1e9, 1, ',', '.'), '0'), ',') . 'B';
        if ($n >= 1e6) return rtrim(rtrim(number_format($n / 1e6, 1, ',', '.'), '0'), ',') . 'M';
        if ($n >= 1e4) return number_format($n, 0, ',', '.') ;
        return number_format($n, 0, ',', '.');
    }
}
