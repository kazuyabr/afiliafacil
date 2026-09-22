<?php

class SttClient
{
    private const MAX_DOWNLOAD_BYTES = 24 * 1024 * 1024;

    public static function transcribe(array $input, array $config): array
    {
        $provider = $config['provider'] ?? 'cloudflare';

        try {
            return match ($provider) {
                'openai', 'groq' => self::openAiCompatible($input, $config),
                'deepgram' => self::deepgram($input, $config),
                'assemblyai' => self::assemblyAi($input, $config),
                default => self::cloudflare($input, $config),
            };
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Erro inesperado: ' . $e->getMessage(), 'provider' => $provider];
        }
    }

    private static int $lastStatus = 0;
    private static ?string $lastBody = null;

    public static function lastStatus(): int
    {
        return self::$lastStatus;
    }

    /** Erro de cota/limite do provider? (ex.: Cloudflare 429 neurons/dia) */
    public static function isQuotaError(?string $body = null, int $status = 0): bool
    {
        if ($status === 429 || self::$lastStatus === 429) return true;
        $t = mb_strtolower((string)($body ?? self::$lastBody));
        return str_contains($t, 'neurons') || str_contains($t, 'daily free')
            || str_contains($t, 'quota exceeded') || str_contains($t, 'too many requests')
            || str_contains($t, 'rate limit');
    }

    public static function quotaMessage(string $tab = 'Transcrição'): string
    {
        return 'A cota gratuita da plataforma acabou por hoje (STT e TTS compartilham o limite diário). '
            . 'Configure sua própria chave em Configurações → Avançado → IA (chaves próprias), aba ' . $tab
            . ', para continuar sem limite — ou tente novamente amanhã.';
    }

    /** Registra a falha de cota da plataforma (exibida como banner nas telas). */
    private static function markQuotaError(): void
    {
        try {
            if (!class_exists('Settings', false)) {
                require_once __DIR__ . '/../Settings.php';
            }
            \Settings::set('ai_quota_error_at', date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
        }
    }

    private static function cloudflare(array $input, array $config): array
    {
        $accountId = trim($config['account_id'] ?? '');
        $token = trim($config['api_key'] ?? '');
        $model = $config['model'] ?: '@cf/openai/whisper-large-v3-turbo';
        if ($accountId === '' || $token === '') {
            return ['success' => false, 'error' => 'Cloudflare Whisper não configurado (CF_ACCOUNT_ID/CF_AI_TOKEN).'];
        }

        $binary = self::readBinary($input);
        if ($binary === null) {
            return ['success' => false, 'error' => self::readBinaryError($input)];
        }

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/" . ltrim($model, '/');
        $response = self::request('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
        ], $binary, 180);

        if ($response === null) {
            if (self::isQuotaError()) {
                if (($config['source'] ?? 'platform') === 'platform') self::markQuotaError();
                return ['success' => false, 'error' => self::quotaMessage('Transcrição'), 'quota_exceeded' => true, 'provider' => 'cloudflare'];
            }
            return ['success' => false, 'error' => 'Cloudflare: falha na requisição (verifique conta/token).'];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !empty($json['errors'])) {
            $cfMsg = $json['errors'][0]['message'] ?? 'resposta inválida';
            if (self::isQuotaError($cfMsg)) {
                if (($config['source'] ?? 'platform') === 'platform') self::markQuotaError();
                return ['success' => false, 'error' => self::quotaMessage('Transcrição'), 'quota_exceeded' => true, 'provider' => 'cloudflare'];
            }
            return ['success' => false, 'error' => 'Cloudflare: ' . $cfMsg];
        }

        $result = $json['result'] ?? [];
        return [
            'success' => true,
            'text' => trim($result['text'] ?? ''),
            'words' => self::normalizeWords($result['words'] ?? []),
            'duration' => (int)round($result['transcription_info']['duration'] ?? 0),
            'provider' => 'cloudflare',
            'model' => $model,
        ];
    }

    private static function openAiCompatible(array $input, array $config): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave da API não configurada.'];

        $provider = $config['provider'] ?? 'openai';
        $defaultBase = $provider === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1';
        $base = rtrim($config['base_url'] ?: $defaultBase, '/');
        $model = $config['model'] ?: ($provider === 'groq' ? 'whisper-large-v3' : 'whisper-1');

        $binary = self::readBinary($input);
        if ($binary === null) {
            return ['success' => false, 'error' => self::readBinaryError($input)];
        }

        $filename = self::filename($input);
        $boundary = '----afiliafacil' . bin2hex(random_bytes(12));
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
            . 'Content-Type: application/octet-stream' . "\r\n\r\n"
            . $binary . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="model"' . "\r\n\r\n" . $model . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="response_format"' . "\r\n\r\nverbose_json\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="language"' . "\r\n\r\npt\r\n"
            . '--' . $boundary . "--\r\n";

        $response = self::request('POST', $base . '/audio/transcriptions', [
            'Authorization: Bearer ' . $key,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ], $body, 180);

        if ($response === null) {
            return ['success' => false, 'error' => ucfirst($provider) . ': falha na requisição (verifique a chave/modelo).'];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || isset($json['error'])) {
            return ['success' => false, 'error' => ucfirst($provider) . ': ' . ($json['error']['message'] ?? 'resposta inválida')];
        }

        return [
            'success' => true,
            'text' => trim($json['text'] ?? ''),
            'words' => self::normalizeWords($json['words'] ?? []),
            'duration' => (int)round($json['duration'] ?? 0),
            'provider' => $provider,
            'model' => $model,
        ];
    }

    private static function deepgram(array $input, array $config): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave da Deepgram não configurada.'];

        $model = $config['model'] ?: 'nova-3';
        $query = http_build_query([
            'model' => $model,
            'language' => 'pt',
            'smart_format' => 'true',
            'punctuate' => 'true',
        ]);
        $url = 'https://api.deepgram.com/v1/listen?' . $query;

        if (!empty($input['url'])) {
            $payload = json_encode(['url' => $input['url']]);
            $contentType = 'application/json';
        } else {
            $binary = self::readBinary($input);
            if ($binary === null) return ['success' => false, 'error' => self::readBinaryError($input)];
            $payload = $binary;
            $contentType = 'application/octet-stream';
        }

        $response = self::request('POST', $url, [
            'Authorization: Token ' . $key,
            'Content-Type: ' . $contentType,
        ], $payload, 240);

        if ($response === null) {
            return ['success' => false, 'error' => 'Deepgram: falha na requisição (verifique a chave).'];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || isset($json['err_code'])) {
            return ['success' => false, 'error' => 'Deepgram: ' . ($json['err_msg'] ?? 'resposta inválida')];
        }

        $alternative = $json['results']['channels'][0]['alternatives'][0] ?? [];
        return [
            'success' => true,
            'text' => trim($alternative['transcript'] ?? ''),
            'words' => self::normalizeWords($alternative['words'] ?? []),
            'duration' => (int)round($json['metadata']['duration'] ?? 0),
            'provider' => 'deepgram',
            'model' => $model,
        ];
    }

    private static function assemblyAi(array $input, array $config): array
    {
        $key = trim($config['api_key'] ?? '');
        if ($key === '') return ['success' => false, 'error' => 'Chave da AssemblyAI não configurada.'];

        $audioUrl = $input['url'] ?? '';
        if ($audioUrl === '') {
            return ['success' => false, 'error' => 'A AssemblyAI exige uma URL pública de áudio/vídeo (upload local não suportado).'];
        }

        $model = $config['model'] ?: 'best';
        $create = self::request('POST', 'https://api.assemblyai.com/v2/transcript', [
            'Authorization: ' . $key,
            'Content-Type: application/json',
        ], json_encode(['audio_url' => $audioUrl, 'language_code' => 'pt', 'speech_model' => $model]), 60);

        if ($create === null) {
            return ['success' => false, 'error' => 'AssemblyAI: falha ao criar a transcrição.'];
        }

        $job = json_decode($create, true);
        $jobId = $job['id'] ?? '';
        if ($jobId === '') {
            return ['success' => false, 'error' => 'AssemblyAI: ' . ($job['error'] ?? 'resposta inválida')];
        }

        $deadline = time() + 300;
        while (time() < $deadline) {
            sleep(4);
            $poll = self::request('GET', 'https://api.assemblyai.com/v2/transcript/' . $jobId, [
                'Authorization: ' . $key,
            ], null, 30);

            if ($poll === null) continue;
            $status = json_decode($poll, true);

            if (($status['status'] ?? '') === 'completed') {
                return [
                    'success' => true,
                    'text' => trim($status['text'] ?? ''),
                    'words' => self::normalizeWords($status['words'] ?? []),
                    'duration' => (int)round(($status['audio_duration'] ?? 0)),
                    'provider' => 'assemblyai',
                    'model' => $model,
                ];
            }
            if (($status['status'] ?? '') === 'error') {
                return ['success' => false, 'error' => 'AssemblyAI: ' . ($status['error'] ?? 'erro desconhecido')];
            }
        }

        return ['success' => false, 'error' => 'AssemblyAI: tempo esgotado aguardando a transcrição.'];
    }

    private static function readBinary(array $input): ?string
    {
        if (!empty($input['file']) && is_file($input['file'])) {
            $size = filesize($input['file']);
            if ($size === false || $size > self::MAX_DOWNLOAD_BYTES) return null;
            $data = file_get_contents($input['file']);
            return $data === false ? null : $data;
        }

        if (!empty($input['url'])) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $input['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AfiliaFacil/1.0',
                CURLOPT_BUFFERSIZE => 128 * 1024,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) {
                    return $downloaded > self::MAX_DOWNLOAD_BYTES ? 1 : 0;
                },
            ]);
            $data = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($data === false || $status >= 400) return null;
            if (strlen($data) > self::MAX_DOWNLOAD_BYTES) return null;
            return $data;
        }

        return null;
    }

    private static function readBinaryError(array $input): string
    {
        if (!empty($input['url'])) {
            return 'Não foi possível baixar a mídia (limite de 24MB para este provedor). Para VSLs longas, use Deepgram ou AssemblyAI (aceitam URL direta).';
        }
        return 'Arquivo inválido ou acima do limite de 24MB.';
    }

    private static function filename(array $input): string
    {
        if (!empty($input['filename'])) return preg_replace('/[^A-Za-z0-9._-]/', '_', $input['filename']);
        if (!empty($input['url'])) {
            $path = parse_url($input['url'], PHP_URL_PATH) ?: '';
            $base = basename($path);
            if ($base !== '' && str_contains($base, '.')) return preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        }
        return 'audio.mp3';
    }

    private static function normalizeWords(array $words): array
    {
        $normalized = [];
        foreach (array_slice($words, 0, 5000) as $word) {
            $normalized[] = [
                'word' => (string)($word['word'] ?? $word['text'] ?? ''),
                'start' => round((float)($word['start'] ?? 0), 2),
                'end' => round((float)($word['end'] ?? 0), 2),
            ];
        }
        return $normalized;
    }

    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::$lastStatus = $status;
        self::$lastBody = ($status >= 400 && is_string($response)) ? $response : null;

        if ($response === false || $status >= 400) return null;
        return $response;
    }
}
