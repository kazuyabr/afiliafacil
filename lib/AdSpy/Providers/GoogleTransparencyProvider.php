<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../AdSpyKeys.php';

class GoogleTransparencyProvider extends AdSpyProvider
{
    public function id(): string
    {
        return 'google';
    }

    public function search(string $query, array $options = []): array
    {
        $userId = (int)($options['user_id'] ?? 0);
        $apiKey = (string)($options['serpapi_key'] ?? '');
        if ($apiKey === '') {
            $apiKey = AdSpyKeys::serpapi($userId);
        }
        if ($apiKey === '' && $userId === 0) {
            $apiKey = getenv('SERPAPI_KEY') ?: '';
        }
        if ($apiKey === '') {
            return $this->emptyResult('Google: configure sua chave SerpApi em IA (BYOK) > Busca de Anuncios (gratis: 250 buscas/mes em serpapi.com).');
        }

        // A engine busca por DOMINIO: se o usuario colou uma URL, extrai o host antes.
        $domain = $this->extractDomain($query);
        if ($domain !== null) $query = $domain;

        $json = $this->request($apiKey, $query, $options);

        // A engine do Google Ads Transparency busca por DOMINIO (ex: "hotmart.com").
        // Se o termo nao parece dominio e nao retornou nada, tenta "<termo>.com" uma vez.
        if ($json === null || (empty($json['ad_creatives']) && $this->looksLikeWord($query))) {
            $candidate = $this->domainCandidate($query);
            if ($candidate !== null) {
                $retry = $this->request($apiKey, $candidate, $options);
                if (!empty($retry['ad_creatives'])) {
                    $json = $retry;
                }
            }
        }

        if ($json === null) return $this->emptyResult('Google: falha na requisição ao SerpApi');
        if (isset($json['error'])) return $this->emptyResult('Google: ' . $json['error']);

        $ads = [];
        foreach ($json['ad_creatives'] ?? [] as $item) {
            $ads[] = $this->normalizeAd([
                'id' => (string)($item['ad_creative_id'] ?? ''),
                'advertiser' => $item['advertiser'] ?? '',
                'title' => '',
                'text' => '',
                'media_type' => $item['format'] ?? 'text',
                'media_url' => '',
                'thumbnail' => '',
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

    /**
     * @return array|null Resposta decodificada do SerpApi (null em falha de rede).
     */
    private function request(string $apiKey, string $text, array $options): ?array
    {
        $params = [
            'engine' => 'google_ads_transparency_center',
            'api_key' => $apiKey,
            'num' => 40,
        ];

        if (!empty($options['advertiser_id'])) {
            $params['advertiser_id'] = $options['advertiser_id'];
        } else {
            $params['text'] = $text;
        }
        if (!empty($options['platform'])) {
            $params['platform'] = $options['platform'];
        }

        $body = $this->httpGet('https://serpapi.com/search.json?' . http_build_query($params));
        if ($body === null) return null;

        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function looksLikeWord(string $query): bool
    {
        $query = trim($query);
        return $query !== '' && !str_contains($query, '.') && !str_contains($query, ' ') && !str_starts_with($query, 'AR');
    }

    /**
     * Extrai o dominio quando o usuario cola uma URL (https://loja.com/produto -> loja.com).
     * Retorna null quando a entrada ja e termo/dominio puro (nada a extrair).
     */
    private function extractDomain(string $query): ?string
    {
        $q = trim($query);
        if ($q === '') return null;
        $lower = strtolower($q);
        if (!preg_match('#^https?://#i', $q) && !str_starts_with($lower, 'www.')) return null;

        $host = parse_url(preg_match('#^https?://#i', $q) ? $q : 'https://' . $q, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !str_contains($host, '.')) return null;
        $host = preg_replace('/^www\./i', '', strtolower($host));
        return $host !== '' ? $host : null;
    }

    private function domainCandidate(string $query): ?string
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($query))) ?? '';
        if ($slug === '') return null;
        return $slug . '.com';
    }
}
