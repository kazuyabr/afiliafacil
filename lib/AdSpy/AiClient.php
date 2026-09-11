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
        $model = $config['model'] ?: '@cf/zai-org/glm-4.7-flash';
        if ($accountId === '' || $token === '') return null;

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/" . ltrim($model, '/');
        $response = self::request('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        return $json['result']['response'] ?? null;
    }

    private static function openaiCompatible(array $messages, array $config): ?string
    {
        $baseUrl = rtrim($config['base_url'] ?: 'https://api.openai.com/v1', '/');
        $key = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: 'gpt-4o-mini';
        if ($key === '') return null;

        $response = self::request('POST', $baseUrl . '/chat/completions', [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ], json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE));

        if ($response === null) return null;
        $json = json_decode($response, true);
        return $json['choices'][0]['message']['content'] ?? null;
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

    private static function request(string $method, string $url, array $headers, string $body): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) return null;
        return $response;
    }
}
