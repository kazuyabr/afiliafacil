<?php

class TtsClient
{
    public const MAX_CHARS = 5000;

    public static function generate(string $text, array $config, string $voice = '', string $format = 'mp3'): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Texto vazio.'];
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            return ['success' => false, 'error' => 'Texto muito longo (máximo de ' . self::MAX_CHARS . ' caracteres por geração).'];
        }

        $provider = $config['provider'] ?? 'cloudflare';

        try {
            return match ($provider) {
                'openai' => self::openAi($text, $config, $voice),
                'elevenlabs' => self::elevenLabs($text, $config, $voice),
                'google' => self::google($text, $config, $voice),
                default => self::cloudflare($text, $config, $voice),
            };
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Erro inesperado: ' . $e->getMessage(), 'provider' => $provider];
        }
    }

    private static function cloudflare(string $text, array $config, string $voice): array
    {
        $accountId = trim($config['account_id'] ?? '');
        $token = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: '@cf/myshell-ai/melotts';
        if ($accountId === '' || $token === '') {
            return ['success' => false, 'error' => 'Cloudflare TTS não configurado (CF_ACCOUNT_ID/CF_AI_TOKEN).'];
        }

        $lang = $voice !== '' ? $voice : 'pt-BR';
        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/" . ltrim($model, '/');
        $response = self::request('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], json_encode(['prompt' => $text, 'lang' => $lang], JSON_UNESCAPED_UNICODE), 180);

        if ($response === null) {
            return ['success' => false, 'error' => 'Cloudflare: falha na requisição (verifique conta/token).'];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !empty($json['errors'])) {
            return ['success' => false, 'error' => 'Cloudflare: ' . ($json['errors'][0]['message'] ?? 'resposta inválida')];
        }

        $audio = $json['result']['audio'] ?? '';
        if ($audio === '') {
            return ['success' => false, 'error' => 'Cloudflare: áudio vazio na resposta.'];
        }

        if (str_starts_with($audio, 'data:')) {
            $audio = substr($audio, strpos($audio, ',') + 1);
        }
        $binary = base64_decode($audio, true);
        if ($binary === false || $binary === '') {
            return ['success' => false, 'error' => 'Cloudflare: falha ao decodificar o áudio.'];
        }

        return [
            'success' => true,
            'audio' => $binary,
            'format' => 'mp3',
            'provider' => 'cloudflare',
            'model' => $model,
            'voice' => $lang,
        ];
    }

    private static function openAi(string $text, array $config, string $voice): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave da OpenAI não configurada.'];

        $base = rtrim($config['base_url'] ?: 'https://api.openai.com/v1', '/');
        $model = $config['model'] ?: 'gpt-4o-mini-tts';
        $voice = $voice !== '' ? $voice : 'alloy';

        $response = self::request('POST', $base . '/audio/speech', [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ], json_encode([
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => 'mp3',
        ], JSON_UNESCAPED_UNICODE), 180);

        if ($response === null) {
            return ['success' => false, 'error' => 'OpenAI: falha na requisição (verifique a chave/modelo/voz).'];
        }

        return [
            'success' => true,
            'audio' => $response,
            'format' => 'mp3',
            'provider' => 'openai',
            'model' => $model,
            'voice' => $voice,
        ];
    }

    private static function elevenLabs(string $text, array $config, string $voice): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave da ElevenLabs não configurada.'];

        $voiceId = $voice !== '' ? $voice : '21m00Tcm4TlvDq8ikWAM';
        $model = $config['model'] ?: 'eleven_multilingual_v2';

        $response = self::request('POST', 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId), [
            'xi-api-key: ' . $key,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ], json_encode([
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
        ], JSON_UNESCAPED_UNICODE), 240);

        if ($response === null) {
            return ['success' => false, 'error' => 'ElevenLabs: falha na requisição (verifique a chave/voz).'];
        }

        return [
            'success' => true,
            'audio' => $response,
            'format' => 'mp3',
            'provider' => 'elevenlabs',
            'model' => $model,
            'voice' => $voiceId,
        ];
    }

    private static function google(string $text, array $config, string $voice): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave do Google não configurada.'];

        $model = $config['model'] ?: 'gemini-2.5-flash-preview-tts';
        $voiceName = $voice !== '' ? $voice : 'Kore';

        $payload = [
            'contents' => [['parts' => [['text' => $text]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voiceName],
                    ],
                ],
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($key);
        $response = self::request('POST', $url, ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE), 180);

        if ($response === null) {
            return ['success' => false, 'error' => 'Google: falha na requisição (verifique a chave/modelo/voz).'];
        }

        $json = json_decode($response, true);
        $inline = $json['candidates'][0]['content']['parts'][0]['inlineData'] ?? null;
        if (!$inline || empty($inline['data'])) {
            return ['success' => false, 'error' => 'Google: resposta sem áudio.'];
        }

        $pcm = base64_decode($inline['data'], true);
        if ($pcm === false || $pcm === '') {
            return ['success' => false, 'error' => 'Google: falha ao decodificar o áudio.'];
        }

        return [
            'success' => true,
            'audio' => self::pcmToWav($pcm, 24000),
            'format' => 'wav',
            'provider' => 'google',
            'model' => $model,
            'voice' => $voiceName,
        ];
    }

    private static function pcmToWav(string $pcm, int $sampleRate): string
    {
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $dataSize = strlen($pcm);

        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels)
            . pack('V', $sampleRate) . pack('V', $byteRate) . pack('v', $blockAlign) . pack('v', $bitsPerSample)
            . 'data' . pack('V', $dataSize);

        return $header . $pcm;
    }

    private static function request(string $method, string $url, array $headers, string $body, int $timeout): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
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
