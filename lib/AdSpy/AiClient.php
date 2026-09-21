<?php

class AiClient
{
    public static function chat(array $messages, array $config): ?string
    {
        return match ($config['provider'] ?? 'openai') {
            'cloudflare' => self::cloudflare($messages, $config),
            'anthropic' => self::anthropic($messages, $config),
            'google' => self::google($messages, $config),
            default => self::openaiCompatible($messages, $config),
        };
    }

    private static function cloudflare(array $messages, array $config): ?string
    {
        $accountId = trim($config['account_id'] ?? '');
        $token = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: '@cf/nvidia/nemotron-3-120b-a12b';
        if ($accountId === '' || $token === '') return null;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/" . ltrim($model, '/');
        $response = self::request('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        if (!is_array($json)) return null;

        $content = $json['result']['response']
            ?? $json['result']['choices'][0]['message']['content']
            ?? $json['result']['choices'][0]['text']
            ?? null;

        return self::stringifyContent($content);
    }

    /**
     * Alguns modelos retornam o conteudo como array de blocos (ex: [{type:text,text:...}]).
     * Normaliza para string — sem isso o retorno tipado ?string quebra (TypeError).
     */
    private static function stringifyContent($content): ?string
    {
        if (is_string($content)) return $content;
        if (!is_array($content)) return null;

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }
            if (is_array($part)) {
                $piece = $part['text'] ?? $part['content'] ?? null;
                if (is_string($piece)) $text .= $piece;
            }
        }

        return $text !== '' ? $text : null;
    }

    /**
     * Tenta cada config em ordem (fallback automatico quando uma cota/limite estoura).
     * Ex.: BYOK do usuario -> plataforma -> chave alternativa da plataforma.
     */
    public static function chatWithFallback(array $messages, array $candidates, int $attemptsPerConfig = 2): ?string
    {
        foreach ($candidates as $config) {
            for ($i = 1; $i <= $attemptsPerConfig; $i++) {
                $response = self::chat($messages, $config);
                if ($response !== null) return $response;

                // Cota/limite estourou: nao adianta insistir nesta chave, tenta a proxima
                if (self::isQuotaError()) break;
                if ($i < $attemptsPerConfig) usleep(1500000);
            }
        }

        return null;
    }

    private static function openaiCompatible(array $messages, array $config): ?string
    {
        $baseUrl = rtrim($config['base_url'] ?: 'https://api.openai.com/v1', '/');
        $key = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: 'gpt-4o-mini';

        // Modelos locais (LM Studio/Ollama) podem nao exigir chave — so remotos exigem
        if ($key === '' && !self::isLocalUrl($baseUrl)) {
            self::$lastError = 'Chave obrigatoria para provider remoto (' . $baseUrl . ')';
            return null;
        }

        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        $response = self::request('POST', $baseUrl . '/chat/completions', $headers, json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        return $json['choices'][0]['message']['content'] ?? null;
    }

    /** A URL aponta para um servidor local (host ou rede privada)? */
    public static function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if ($host === '') return false;

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', 'host.docker.internal', '::1'], true)) return true;

        return str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) === 1;
    }

    private static function anthropic(array $messages, array $config): ?string
    {
        $key = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: 'claude-3-5-haiku-latest';
        if ($key === '') return null;

        $system = '';
        $chat = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
            } else {
                $chat[] = $m;
            }
        }

        $payload = ['model' => $model, 'max_tokens' => 2000, 'messages' => $chat];
        if ($system !== '') $payload['system'] = trim($system);

        $response = self::request('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        return $json['content'][0]['text'] ?? null;
    }

    private static function google(array $messages, array $config): ?string
    {
        $key = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: 'gemini-2.0-flash';
        if ($key === '') return null;

        $contents = [];
        $system = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
                continue;
            }
            $contents[] = [
                'role' => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ];
        }

        $payload = ['contents' => $contents];
        if ($system !== '') $payload['systemInstruction'] = ['parts' => [['text' => trim($system)]]];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);
        $response = self::request('POST', $url, ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /** Ultimo erro retornado pela API (para mensagens especificas ao usuario). */
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** Erro de cota/limite do provider? (ex.: Cloudflare neurons/dia) */
    public static function isQuotaError(): bool
    {
        $e = mb_strtolower(self::$lastError);
        return str_contains($e, 'neurons') || str_contains($e, 'daily free') || str_contains($e, 'quota')
            || str_contains($e, 'rate limit') || str_contains($e, 'too many requests');
    }

    private static function request(string $method, string $url, array $headers, string $body): ?string
    {
        self::$lastError = '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::$lastError = 'falha de conexao: ' . $curlError;
            return null;
        }

        if ($status >= 400) {
            $json = json_decode((string)$response, true);
            $message = $json['errors'][0]['message']
                ?? $json['error']['message']
                ?? $json['error']
                ?? $json['message']
                ?? ('HTTP ' . $status);
            self::$lastError = is_string($message) ? mb_substr($message, 0, 400) : ('HTTP ' . $status);
            return null;
        }

        return $response;
    }
}
