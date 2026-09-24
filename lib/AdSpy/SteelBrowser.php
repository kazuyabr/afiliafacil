<?php

/**
 * Client do Steel Browser (browser self-hosted para scraping com JS rendering).
 *
 * Uso: fallback dos providers Meta/TikTok quando o scraping direto e bloqueado
 * (403/sessao exigida). Sem STEEL_API_URL configurado, fica inativo e nada muda.
 *
 * Contrato: POST {STEEL_API_URL}/scrape com {"url": "..."} (header opcional
 * Authorization: Bearer {STEEL_API_KEY}). A resposta pode ser HTML direto ou
 * JSON com o conteudo em html|content|data|text|markdown|result — o parse e
 * defensivo para tolerar versoes diferentes da API.
 */
class SteelBrowser
{
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '';
    }

    public static function baseUrl(): string
    {
        // Painel (admin) tem prioridade; .env e o fallback
        $panel = '';
        try {
            if (!class_exists('Settings', false)) {
                require_once __DIR__ . '/../Settings.php';
            }
            $panel = trim((string)\Settings::get('steel_api_url', ''));
        } catch (Throwable $e) {
            $panel = '';
        }
        if ($panel !== '') return rtrim($panel, '/');
        return rtrim(trim((string)(getenv('STEEL_API_URL') ?: '')), '/');
    }

    public static function apiKey(): string
    {
        try {
            if (!class_exists('Settings', false)) {
                require_once __DIR__ . '/../Settings.php';
            }
            $panel = trim((string)\Settings::get('steel_api_key', ''));
            if ($panel !== '') return $panel;
        } catch (Throwable $e) {
        }
        return trim((string)(getenv('STEEL_API_KEY') ?: ''));
    }

    /**
     * Busca conteudo renderizado de uma URL via Steel Browser.
     * @return array{ok:bool, html:string, error?:string}
     */
    public static function fetch(string $url, int $timeout = 60): array
    {
        $base = self::baseUrl();
        if ($base === '') {
            return ['ok' => false, 'html' => '', 'error' => 'Steel Browser não configurado (STEEL_API_URL).'];
        }
        if (trim($url) === '') {
            return ['ok' => false, 'html' => '', 'error' => 'URL vazia.'];
        }

        $headers = ['Content-Type: application/json'];
        $apiKey = self::apiKey();
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

        // Steel v1 precisa que o JSON chegue como string direta (sem re-encoding nem CR extra).
        $jsonBody = json_encode(['url' => $url], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . '/v1/scrape',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = is_string(curl_error($ch)) ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false || $status >= 400) {
            $detail = $curlError !== '' ? ': ' . $curlError : '';
            return ['ok' => false, 'html' => '', 'error' => 'Steel Browser indisponível (HTTP ' . $status . $detail . ').'];
        }

        $html = self::extractHtml((string)$body);
        if ($html === '') {
            return ['ok' => false, 'html' => '', 'error' => 'Steel Browser retornou resposta vazia.'];
        }
        return ['ok' => true, 'html' => $html];
    }

    /**
     * Extrai o HTML/conteudo de respostas em formatos variados (JSON ou texto).
     */
    public static function extractHtml(string $body): string
    {
        if ($body === '') return '';

        $json = json_decode($body, true);
        if (is_array($json)) {
            // Steel v1: {"content":{"html":"..."}}
            if (isset($json['content']['html']) && is_string($json['content']['html']) && trim($json['content']['html']) !== '') {
                return $json['content']['html'];
            }
            foreach (['html', 'content', 'data', 'text', 'markdown', 'result'] as $key) {
                if (isset($json[$key]) && is_string($json[$key]) && trim($json[$key]) !== '') {
                    return $json[$key];
                }
                if (isset($json[$key]['html']) && is_string($json[$key]['html']) && trim($json[$key]['html']) !== '') {
                    return $json[$key]['html'];
                }
            }
            return '';
        }

        // Nao e JSON: assume HTML/texto direto (cheiro de pagina = tag html ou json inline)
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<') || str_starts_with($trimmed, '{')) {
            return $body;
        }
        return '';
    }
}
