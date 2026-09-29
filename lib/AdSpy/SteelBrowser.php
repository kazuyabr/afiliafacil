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
        $raw = $panel !== '' ? $panel : (getenv('STEEL_API_URL') ?: '');
        return self::normalizeUrl($raw);
    }

    /** Quando rodamos em Docker, localhost/127.0.0.1 do Host precisa ser host.docker.internal. */
    private static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') return '';
        if (!getenv('DOCKER')) return $url;

        // Dentro do container, "localhost/127.0.0.1" resolve para a propria maquina — traduz p/ o host.
        return str_replace(
            ['http://localhost:', 'http://127.0.0.1:', 'https://localhost:', 'https://127.0.0.1:'],
            ['http://host.docker.internal:', 'http://host.docker.internal:', 'https://host.docker.internal:', 'https://host.docker.internal:'],
            $url
        );
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
     * @param array $extra campos extras do payload (ex.: waitForTimeout p/ SPA renderizar JS)
     * @return array{ok:bool, html:string, error?:string}
     */
    public static function fetch(string $url, int $timeout = 60, array $extra = []): array
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
        $jsonBody = json_encode(array_merge(['url' => $url], $extra), JSON_UNESCAPED_UNICODE);

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

    // ── Sessions (login do usuario no browser) ─────────────────────────

    /**
     * URL que o BROWSER DO USUARIO alcanca (sem a traducao Docker do container).
     * O viewer do Steel e aberto pelo usuario no host — localhost, nao host.docker.internal.
     */
    public static function publicUrl(): string
    {
        return str_replace('host.docker.internal', 'localhost', self::baseUrl());
    }

    /**
     * Cria uma sessao Steel para o usuario logar no TikTok no viewer ao vivo.
     * @return array{ok:bool, id?:string, viewer_url?:string, error?:string}
     */
    public static function createSession(int $timeoutMs = 900000): array
    {
        $r = self::apiCall('POST', '/v1/sessions', ['timeout' => $timeoutMs, 'inactivityTimeout' => 300000]);
        $json = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($json) || empty($json['id'])) {
            return ['ok' => false, 'error' => 'Steel Browser não criou a sessão (HTTP ' . $r['status'] . '). Verifique a URL em Admin → Configurações.'];
        }
        // sessionViewerUrl vem com host interno (0.0.0.0:3000) — reescreve p/ a URL publica
        $path = '/';
        if (!empty($json['sessionViewerUrl'])) {
            $p = parse_url((string)$json['sessionViewerUrl'], PHP_URL_PATH);
            if (is_string($p) && $p !== '') $path = $p;
        }
        return ['ok' => true, 'id' => (string)$json['id'], 'viewer_url' => rtrim(self::publicUrl(), '/') . $path];
    }

    /**
     * Le cookies/localStorage da sessao (formato Cookie-Editor: [{name,value,...}]).
     * @return array{ok:bool, context?:array, error?:string}
     */
    public static function sessionContext(string $sessionId): array
    {
        $r = self::apiCall('GET', '/v1/sessions/' . rawurlencode($sessionId) . '/context');
        $json = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($json)) {
            return ['ok' => false, 'error' => 'Não foi possível ler a sessão do Steel (HTTP ' . $r['status'] . ') — ela pode ter expirado. Tente conectar de novo.'];
        }
        return ['ok' => true, 'context' => $json];
    }

    /** Encerra a sessao (o browser fica livre). Idempotente. */
    public static function releaseSession(string $sessionId): void
    {
        self::apiCall('POST', '/v1/sessions/' . rawurlencode($sessionId) . '/release');
    }

    /** Chamada JSON generica a API do Steel (GET/POST). */
    private static function apiCall(string $method, string $path, ?array $payload = null): array
    {
        $base = self::baseUrl();
        if ($base === '') return ['status' => 0, 'body' => 'Steel Browser não configurado (STEEL_API_URL).'];

        $headers = ['Content-Type: application/json'];
        $apiKey = self::apiKey();
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? '' : (string)$body];
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
