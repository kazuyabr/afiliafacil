<?php

/**
 * HTTP enxuto para as APIs das redes, com handler substituivel em testes
 * (SocialHttp::$handler recebe (method, url, opts) e devolve status/body).
 */
class SocialHttp
{
    /** @var callable|null */
    public static $handler = null;

    /**
     * opts: headers[] (assoc), form[] (urlencoded), json[] (application/json),
     * multipart[] (arquivos/campos), timeout (segundos).
     * @return array{status:int, body:string}
     */
    public static function request(string $method, string $url, array $opts = []): array
    {
        if (self::$handler !== null) {
            return (self::$handler)($method, $url, $opts);
        }

        // GET com form: servidores IGNORAM o corpo de GET (o Graph devolvia
        // "Missing client_id parameter" na troca de token) — params vão para
        // a query string. POST continua no corpo (urlencoded).
        if (strtoupper($method) === 'GET' && !empty($opts['form'])) {
            $url = self::formToQuery($url, $opts['form']);
            unset($opts['form']);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($opts['timeout'] ?? 30));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $headers = $opts['headers'] ?? [];
        if (isset($opts['json'])) {
            $body = json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($opts['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
        } elseif (isset($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => json_encode(['error' => ['message' => 'Falha de conexão: ' . $err]])];
        }
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$body];
    }

    /** Merga um form urlencoded na query string (chamadas GET). */
    public static function formToQuery(string $url, array $form): string
    {
        $qs = http_build_query($form);
        if ($qs === '') return $url;
        return $url . (str_contains($url, '?') ? '&' : '?') . $qs;
    }

    /** request + json_decode; devolve [] em corpo vazio/inválido. `_status` é sempre preenchido. */
    public static function json(string $method, string $url, array $opts = []): array
    {
        $resp = self::request($method, $url, $opts);
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) $data = [];
        $data['_status'] = $resp['status'];
        return $data;
    }

    /** Bytes de um arquivo (URL http ou caminho local /app...). */
    public static function bytes(string $urlOrPath): ?string
    {
        if (is_file($urlOrPath)) {
            $raw = file_get_contents($urlOrPath);
            return $raw === false ? null : $raw;
        }
        $resp = self::request('GET', $urlOrPath, ['timeout' => 60]);
        if ($resp['status'] < 200 || $resp['status'] >= 300) return null;
        return $resp['body'];
    }

    /** Extrai a mensagem de erro de uma resposta de API (padroes Graph/X/TikTok). */
    public static function errorMsg(array $data, string $fallback = 'Falha ao publicar'): string
    {
        if (isset($data['error']['message'])) return mb_substr((string)$data['error']['message'], 0, 500);
        if (isset($data['error']['error_description'])) return mb_substr((string)$data['error']['error_description'], 0, 500);
        if (isset($data['error']) && is_string($data['error'])) return mb_substr($data['error'], 0, 500);
        if (isset($data['error']['code']) && is_numeric($data['error']['code'])) {
            return 'Erro ' . $data['error']['code'] . ($data['error']['message'] ?? '');
        }
        return $fallback;
    }
}
