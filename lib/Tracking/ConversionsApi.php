<?php

/**
 * Meta Conversions API (server-side): recupera as conversões que o pixel
 * do navegador perde (iOS 14+, ad blockers). 20–40% das conversões a mais.
 *
 * Deduplicação: o mesmo event_id vai no fbq do navegador e aqui — a Meta
 * conta uma vez só. Teste com capi_test_code (aba Test Events no Events Manager).
 */
class ConversionsApi
{
    public const ALLOWED_EVENTS = ['PageView', 'ViewContent', 'Lead', 'InitiateCheckout', 'AddToCart', 'Purchase'];
    private const GRAPH_VERSION = 'v21.0';

    /**
     * @return array{success:bool, event_id?:string, error?:string, meta_error?:string}
     */
    public static function send(string $pixelId, string $token, string $event, array $customData = [], array $options = []): array
    {
        $pixelId = trim($pixelId);
        $token = trim($token);
        if ($pixelId === '' || !ctype_digit($pixelId)) {
            return ['success' => false, 'error' => 'Pixel ID inválido.'];
        }
        if ($token === '') {
            return ['success' => false, 'error' => 'Token da API de Conversão não configurado.'];
        }
        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            return ['success' => false, 'error' => 'Evento inválido.'];
        }

        $eventId = (string)($options['event_id'] ?? '') !== '' ? (string)$options['event_id'] : self::newEventId();
        $eventTime = (int)($options['event_time'] ?? time());

        $userData = [
            'client_ip_address' => self::clientIp(),
            'client_user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ];
        foreach (['fbp' => 'fbp', 'fbc' => 'fbc'] as $cookie => $field) {
            if (!empty($_COOKIE[$cookie])) $userData[$field] = mb_substr((string)$_COOKIE[$cookie], 0, 255);
        }
        if (!empty($options['email'])) {
            $userData['em'] = [hash('sha256', strtolower(trim((string)$options['email'])))];
        }

        $payload = [
            'data' => [[
                'event_name' => $event,
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'action_source' => 'website',
                'user_data' => $userData,
            ]],
        ];
        if (!empty($customData)) {
            $payload['data'][0]['custom_data'] = $customData;
        }
        $testCode = trim((string)($options['test_code'] ?? ''));
        if ($testCode !== '') $payload['test_event_code'] = $testCode;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . $pixelId . '/events?access_token=' . urlencode($token),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            $metaMsg = '';
            $json = json_decode((string)$body, true);
            if (is_array($json) && isset($json['error']['message'])) {
                $metaMsg = (string)$json['error']['message'];
            }
            return ['success' => false, 'error' => 'Meta CAPI: falha (HTTP ' . $status . '). Verifique pixel/token.' . ($metaMsg !== '' ? ' Detalhe: ' . $metaMsg : ''), 'meta_error' => $metaMsg];
        }

        return ['success' => true, 'event_id' => $eventId];
    }

    public static function newEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = trim((string)($_SERVER[$key] ?? ''));
            if ($value === '') continue;
            // X-Forwarded-For pode trazer lista: usa o primeiro IP público
            foreach (explode(',', $value) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}
