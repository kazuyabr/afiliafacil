<?php

class R2Storage
{
    private string $accountId;
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $publicUrl;
    private string $region = 'auto';

    public function __construct(array $config)
    {
        $this->accountId = trim($config['account_id'] ?? '');
        $this->accessKey = trim($config['access_key'] ?? '');
        $this->secretKey = (string)($config['secret_key'] ?? '');
        $this->bucket = trim($config['bucket'] ?? '');
        $this->publicUrl = rtrim(trim($config['public_url'] ?? ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->accountId !== ''
            && $this->accessKey !== ''
            && $this->secretKey !== ''
            && $this->bucket !== ''
            && $this->publicUrl !== '';
    }

    public function publicUrl(string $key): string
    {
        return $this->publicUrl . '/' . ltrim($key, '/');
    }

    public function upload(string $key, string $content, string $contentType = 'application/octet-stream'): ?string
    {
        if (!$this->isConfigured()) return null;

        $response = $this->request('PUT', $key, $content, $contentType);
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return $this->publicUrl($key);
        }
        return null;
    }

    public function delete(string $key): bool
    {
        if (!$this->isConfigured()) return false;
        $response = $this->request('DELETE', $key);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public function deletePrefix(string $prefix): int
    {
        if (!$this->isConfigured()) return 0;

        $deleted = 0;
        $continuation = null;
        $guard = 0;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '500'];
            if ($continuation) $query['continuation-token'] = $continuation;

            $response = $this->request('GET', '', '', '', $query);
            if ($response['status'] !== 200) break;

            $xml = @simplexml_load_string($response['body']);
            if ($xml === false) break;

            foreach ($xml->Contents ?? [] as $item) {
                $key = (string)$item->Key;
                if ($key !== '' && $this->delete($key)) $deleted++;
            }

            $continuation = null;
            if ((string)($xml->IsTruncated ?? '') === 'true') {
                $continuation = (string)($xml->NextContinuationToken ?? '');
            }
        } while ($continuation && ++$guard < 20);

        return $deleted;
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Configuração incompleta'];
        }

        $key = '.afiliafacil-test-' . bin2hex(random_bytes(6)) . '.txt';
        $url = $this->upload($key, 'afiliafacil-test', 'text/plain');
        if ($url === null) {
            return ['ok' => false, 'error' => 'Falha no upload de teste (verifique credenciais/bucket)'];
        }

        $this->delete($key);

        return ['ok' => true, 'message' => 'Conexão OK — upload e remoção funcionando'];
    }

    private function request(string $method, string $key, string $body = '', string $contentType = '', array $query = []): array
    {
        $host = $this->accountId . '.r2.cloudflarestorage.com';
        $uriPath = '/' . $this->bucket . ($key !== '' ? '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/'))) : '');

        $queryString = '';
        if (!empty($query)) {
            ksort($query);
            $pairs = [];
            foreach ($query as $k => $v) {
                $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
            $queryString = implode('&', $pairs);
        }

        $now = time();
        $date = gmdate('Ymd', $now);
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $payloadHash = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
            $signedHeadersList[] = $name;
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = $method . "\n"
            . $uriPath . "\n"
            . $queryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $url = 'https://' . $host . $uriPath . ($queryString !== '' ? '?' . $queryString : '');

        $ch = curl_init();
        $curlHeaders = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Host: ' . $host,
        ];
        if ($contentType !== '') $curlHeaders[] = 'Content-Type: ' . $contentType;

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : $responseBody,
            'error' => $error,
        ];
    }
}
