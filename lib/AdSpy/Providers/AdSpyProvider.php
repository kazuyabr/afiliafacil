<?php

abstract class AdSpyProvider
{
    protected int $timeout = 25;

    abstract public function id(): string;

    abstract public function search(string $query, array $options = []): array;

    protected function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: application/json, text/plain, */*',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ], $headers),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) return null;
        return $body;
    }

    protected function emptyResult(string $error = null): array
    {
        return ['ads' => [], 'total' => 0, 'error' => $error];
    }

    protected function normalizeAd(array $ad): array
    {
        return array_merge([
            'id' => '',
            'provider' => $this->id(),
            'advertiser' => '',
            'title' => '',
            'text' => '',
            'cta' => '',
            'media_type' => 'text',
            'media_url' => '',
            'thumbnail' => '',
            'landing_page' => '',
            'platforms' => [],
            'started_at' => null,
            'ended_at' => null,
            'status' => 'active',
            'link' => '',
        ], $ad);
    }
}
