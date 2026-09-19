<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AdSpy/AdSpyKeys.php';

/**
 * Pesquisa web para o Socio de IA.
 *
 * Prioridade:
 *  1. Chave SerpApi do usuario (BYOK) — ilimitado do nosso lado;
 *  2. Chave SerpApi da plataforma — limitada a PLATFORM_DAILY_LIMIT buscas/dia por usuario
 *     (controle de custo do Socio; o admin/cron nao consome esse limite);
 *  3. Fallback gratuito: DuckDuckGo HTML (sem chave).
 */
class WebSearch
{
    public const PLATFORM_DAILY_LIMIT = 20;

    public static function search(int $userId, string $query, int $limit = 6): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['success' => false, 'error' => 'Informe o termo de pesquisa.', 'results' => [], 'provider' => null, 'source' => null];
        }

        $limit = max(1, min(10, $limit));

        // 1) BYOK do usuario
        $userKey = AdSpyKeys::serpapi($userId);
        if ($userKey !== '') {
            $results = self::serpApi($userKey, $query, $limit);
            if ($results !== null) {
                self::log($userId, $query, 'serpapi', 'byok', count($results));
                return ['success' => true, 'error' => null, 'results' => $results, 'provider' => 'serpapi', 'source' => 'byok', 'remaining' => -1];
            }
        }

        // 2) Chave da plataforma com limite diario (sistema/admin nao consome o limite)
        $platformKey = trim((string)(getenv('SERPAPI_KEY') ?: ''));
        if ($platformKey !== '') {
            if ($userId <= 0) {
                $results = self::serpApi($platformKey, $query, $limit);
                if ($results !== null) {
                    return ['success' => true, 'error' => null, 'results' => $results, 'provider' => 'serpapi', 'source' => 'platform', 'remaining' => -1];
                }
            } else {
                $used = self::platformUsedToday($userId);
                if ($used < self::PLATFORM_DAILY_LIMIT) {
                    $results = self::serpApi($platformKey, $query, $limit);
                    if ($results !== null) {
                        self::log($userId, $query, 'serpapi', 'platform', count($results));
                        return [
                            'success' => true,
                            'error' => null,
                            'results' => $results,
                            'provider' => 'serpapi',
                            'source' => 'platform',
                            'remaining' => max(0, self::PLATFORM_DAILY_LIMIT - $used - 1),
                        ];
                    }
                }
            }
        }

        // 3) Fallback gratuito (DuckDuckGo Lite -> Bing)
        $results = self::duckDuckGo($query, $limit);
        if ($results !== null) {
            self::log($userId, $query, 'duckduckgo', 'fallback', count($results));
            return ['success' => true, 'error' => null, 'results' => $results, 'provider' => 'duckduckgo', 'source' => 'fallback', 'remaining' => -1];
        }

        $results = self::bing($query, $limit);
        if ($results !== null) {
            self::log($userId, $query, 'bing', 'fallback', count($results));
            return ['success' => true, 'error' => null, 'results' => $results, 'provider' => 'bing', 'source' => 'fallback', 'remaining' => -1];
        }

        return ['success' => false, 'error' => 'Nao foi possivel pesquisar agora (todas as fontes falharam).', 'results' => [], 'provider' => null, 'source' => null];
    }

    public static function platformUsedToday(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            return (int)\AfiliaFacil\Models\AdSpySearch::where('user_id', $userId)
                ->where('kind', 'web')
                ->where('source', 'platform')
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function platformRemainingToday(int $userId): int
    {
        return max(0, self::PLATFORM_DAILY_LIMIT - self::platformUsedToday($userId));
    }

    private static function serpApi(string $apiKey, string $query, int $limit): ?array
    {
        $params = [
            'engine' => 'google',
            'q' => $query,
            'num' => max(10, $limit),
            'gl' => 'br',
            'hl' => 'pt-br',
            'api_key' => $apiKey,
        ];

        $body = self::httpGet('https://serpapi.com/search.json?' . http_build_query($params));
        if ($body === null) return null;

        $json = json_decode($body, true);
        if (!is_array($json) || isset($json['error'])) return null;

        $results = [];
        foreach (array_slice($json['organic_results'] ?? [], 0, $limit) as $item) {
            $results[] = [
                'title' => trim((string)($item['title'] ?? '')),
                'url' => (string)($item['link'] ?? ''),
                'snippet' => trim((string)($item['snippet'] ?? '')),
            ];
        }

        return $results;
    }

    private static function duckDuckGo(string $query, int $limit): ?array
    {
        // DDG Lite via POST (o html.duckduckgo.com via GET bloqueia com "anomaly")
        $body = self::httpPost('https://lite.duckduckgo.com/lite/', ['q' => $query]);
        if ($body === null) return null;

        $links = self::xpath($body, "//a[contains(@class,'result-link')]");
        $snippets = self::xpath($body, "//td[contains(@class,'result-snippet')]");
        if (empty($links)) return null;

        $results = [];
        foreach ($links as $i => $a) {
            $url = self::decodeDdgUrl(trim($a['href'] ?? ''));
            $title = trim(preg_replace('/\s+/', ' ', $a['text'] ?? '') ?? '');
            if ($url === '' || $title === '') continue;

            // Descarta anuncios/paginas do proprio DuckDuckGo (y.js, help, etc.)
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            if (str_contains($host, 'duckduckgo.com')) continue;

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => trim(preg_replace('/\s+/', ' ', $snippets[$i]['text'] ?? '') ?? ''),
            ];
            if (count($results) >= $limit) break;
        }

        return empty($results) ? null : $results;
    }

    private static function bing(string $query, int $limit): ?array
    {
        $body = self::httpGet('https://www.bing.com/search?q=' . urlencode($query) . '&setlang=pt-BR&count=' . max(10, $limit));
        if ($body === null) return null;

        $items = self::xpath($body, "//li[contains(@class,'b_algo')]");
        if (empty($items)) return null;

        $results = [];
        foreach ($items as $item) {
            $html = $item['html'] ?? '';
            if ($html === '') continue;

            $anchors = self::xpath($html, "//a[@href]");
            $url = '';
            $title = '';
            foreach ($anchors as $a) {
                $href = trim($a['href'] ?? '');
                if ($href !== '' && str_starts_with($href, 'http')) {
                    $url = $href;
                    $title = trim(preg_replace('/\s+/', ' ', $a['text'] ?? '') ?? '');
                    break;
                }
            }
            if ($url === '' || $title === '') continue;

            $paragraphs = self::xpath($html, "//p");
            $snippet = '';
            foreach ($paragraphs as $p) {
                $text = trim(preg_replace('/\s+/', ' ', $p['text'] ?? '') ?? '');
                if (mb_strlen($text) > 30) {
                    $snippet = $text;
                    break;
                }
            }

            $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
            if (count($results) >= $limit) break;
        }

        return empty($results) ? null : $results;
    }

    /**
     * Extrai elementos via XPath retornando href/texto (e o HTML interno do no).
     * Usa DOMDocument com fallback silencioso (nunca lanca excecao).
     */
    private static function xpath(string $html, string $query): array
    {
        $out = [];
        try {
            $dom = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            $xp = new DOMXPath($dom);
            foreach ($xp->query($query) ?: [] as $node) {
                $item = ['href' => '', 'text' => '', 'html' => ''];
                if ($node instanceof DOMElement) {
                    $item['href'] = $node->getAttribute('href');
                    $item['html'] = $dom->saveHTML($node) ?: '';
                }
                $item['text'] = trim($node->textContent ?? '');
                $out[] = $item;
            }
        } catch (Throwable $e) {
            return [];
        }

        return $out;
    }

    private static function decodeDdgUrl(string $url): string
    {
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['uddg'])) {
                return (string)$query['uddg'];
            }
        }
        return $url;
    }

    private static function httpGet(string $url, array $extraHeaders = []): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge(['Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'], $extraHeaders),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $code >= 400) return null;
        return $body;
    }

    private static function httpPost(string $url, array $fields): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $code >= 400) return null;
        return $body;
    }

    private static function log(int $userId, string $query, string $provider, string $source, int $count): void
    {
        if ($userId <= 0 || !Database::available()) return;

        try {
            \AfiliaFacil\Models\AdSpySearch::create([
                'user_id' => $userId,
                'kind' => 'web',
                'query' => mb_substr($query, 0, 250),
                'provider' => $provider,
                'results_count' => $count,
                'from_cache' => false,
                'source' => $source,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }
}
