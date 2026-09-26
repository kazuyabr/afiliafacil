<?php

require_once __DIR__ . '/AdSpyProvider.php';
require_once __DIR__ . '/../AdSpyKeys.php';
require_once __DIR__ . '/../../Web/WebSearch.php';

class GoogleTransparencyProvider extends AdSpyProvider
{
    /** Dominios que nunca sao anunciantes de oferta (evita desperdicio de credito). */
    private const NON_ADVERTISERS = ['google.', 'facebook.', 'instagram.', 'youtube.', 'tiktok.', 'twitter.', 'x.com', 'pinterest.', 'wikipedia.', 'gov.br'];
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
        if (($json === null || empty($json['ad_creatives'])) && $this->looksLikeWord($query)) {
            $candidate = $this->domainCandidate($query);
            if ($candidate !== null) {
                $retry = $this->request($apiKey, $candidate, $options);
                if (!empty($retry['ad_creatives'])) {
                    $json = $retry;
                }
            }
        }

        // Ainda vazio e o termo eh solto (sem advertiser_id fixo)? Resolve dominios
        // candidatos via busca web (ex.: "achadinhos" -> achadinhosdahora.com.br) e
        // tenta no max. 2 — cada retry consome 1 credito SerpApi, por isso so em vazio.
        // ("hasn't returned any results" vem como error do SerpApi, mas eh vazio honesto.)
        $usedDomain = null;
        $serpEmpty = $json !== null && empty($json['ad_creatives'])
            && (empty($json['error']) || str_contains((string)$json['error'], "hasn't returned"));
        if ($serpEmpty && $this->looksLikeWord($query) && empty($options['advertiser_id'])) {
            foreach (array_slice($this->resolveDomainsViaWeb($query, $userId), 0, 2) as $dom) {
                $retry = $this->request($apiKey, $dom, $options);
                if (!empty($retry['ad_creatives'])) {
                    $json = $retry;
                    $usedDomain = $dom;
                    break;
                }
            }
        }

        if ($json === null) return $this->emptyResult('Google: falha na requisição ao SerpApi', 'Google Ads Transparency Center via SerpApi');
        if (isset($json['error'])) {
            $msg = (string)$json['error'];
            // SerpApi usa a mesma string para "sem nada pra mostrar"
            if (str_contains($msg, "hasn't returned any results")) {
                return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'Google Ads Transparency Center via SerpApi',
                    'hint' => 'Google: nenhum anúncio aqui. A fonte do Google busca por domínio do anunciante — prefira "loja.com.br" a termos soltos.'];
            }
            if (str_contains($msg, 'Invalid API key')) {
                return $this->emptyResult('Google: chave SerpApi rejeitada — confira em Configurações → Avançado → IA (Busca de Anúncios).', 'Google Ads Transparency Center via SerpApi');
            }
            if (str_contains($msg, 'limit')) {
                return $this->emptyResult('Google: limite da conta SerpApi atingido (free = 250/mês).', 'Google Ads Transparency Center via SerpApi');
            }
            return $this->emptyResult('Google: ' . $msg, 'Google Ads Transparency Center via SerpApi');
        }

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

        if (empty($ads)) {
            // Zero resultados no Google = na maioria das vezes é "sem anuncios para esse dominio",
            // nao erro de API. Informar como info (nao como falha).
            return ['ads' => [], 'total' => 0, 'error' => null, 'empty' => true, 'source_label' => 'Google Ads Transparency Center via SerpApi',
                'hint' => 'Google: nenhum anúncio aqui. A fonte do Google busca por domínio do anunciante — prefira "loja.com.br" a termos soltos.'];
        }

        $result = ['ads' => $ads, 'total' => count($ads), 'error' => null, 'source_label' => 'Google Ads Transparency Center via SerpApi'];
        if ($usedDomain !== null) {
            $result['hint'] = "Google: anúncios encontrados no domínio {$usedDomain} (a fonte do Google só busca por domínio do anunciante).";
        }
        return $result;
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
            // Sem "region": o SerpApi so aceita esse parametro junto com political_ads
            // (testado — qualquer outro uso retorna "Unsupported region parameter").
        ];

        if (!empty($options['platform'])) {
            $params['platform'] = $options['platform'];
        }

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

    /**
     * Descobre dominios candidatos para um termo solto usando a busca web (SearXNG/BYOK).
     * Retorna ate 6 dominios unicos de resultados organicos, pulando plataformas que
     * nunca sao anunciantes de oferta (google/facebook/...).
     */
    private function resolveDomainsViaWeb(string $query, int $userId): array
    {
        try {
            $search = WebSearch::search($userId, $query, 8);
        } catch (Throwable $e) {
            return [];
        }
        if (empty($search['results'])) return [];

        $out = [];
        foreach ($search['results'] as $item) {
            $host = parse_url((string)($item['url'] ?? ''), PHP_URL_HOST);
            if (!is_string($host) || !str_contains($host, '.')) continue;
            $host = preg_replace('/^www\./i', '', strtolower((string)$host) ?? '') ?? '';
            if ($host === '') continue;
            foreach (self::NON_ADVERTISERS as $blocked) {
                if (str_contains($host, $blocked)) continue 2;
            }
            if (!isset($out[$host])) $out[$host] = true;
        }
        return array_keys($out);
    }
}
