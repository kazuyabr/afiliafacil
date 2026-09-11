<?php

require_once __DIR__ . '/AdSpyProvider.php';

class MetaAdLibraryProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'meta';
    }

    public function search(string $query, array $options = []): array
    {
        $token = getenv('META_AD_ACCESS_TOKEN') ?: '';
        if ($token !== '') {
            $result = $this->searchOfficialApi($query, $options, $token);
            if ($result['total'] > 0 || $result['error'] === null) return $result;
        }

        return $this->searchPublicLibrary($query, $options);
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
        if ($body === null) return $this->emptyResult('Meta API: falha na requisição (verifique o token)');

        $json = json_decode($body, true);
        if (!is_array($json) || isset($json['error'])) {
            return $this->emptyResult('Meta API: ' . ($json['error']['message'] ?? 'resposta inválida'));
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

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
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

        if ($body === null) {
            return $this->emptyResult('Meta: biblioteca pública indisponível (bloqueio ou mudança de layout). Configure META_AD_ACCESS_TOKEN para a API oficial.');
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
            return $this->emptyResult('Meta: nenhum anúncio retornado (a biblioteca pública pode exigir sessão de navegador). Configure META_AD_ACCESS_TOKEN.');
        }

        return ['ads' => $ads, 'total' => count($ads), 'error' => null];
    }
}
