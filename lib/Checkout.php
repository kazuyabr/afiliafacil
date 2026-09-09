<?php
class Checkout
{
    public static function createCheckout(int $userId, string $planId, string $cycle): ?array
    {
        $user = Auth::getUserById($userId);
        if (!$user) return ['error' => 'Usuário não encontrado'];
        if (!isset(Plans::PLANS[$planId])) return ['error' => 'Plano inválido'];

        $plan = Plans::get($planId);
        $amount = Plans::cyclePrice($planId, $cycle);
        $reference = strtoupper(bin2hex(random_bytes(6)));
        $driver = Settings::get('checkout_driver', 'pix');

        $payments = new Payments();
        $payment = $payments->create([
            'user_id' => $userId,
            'user_email' => $user['email'],
            'plan_id' => $planId,
            'cycle' => $cycle,
            'amount' => $amount,
            'gateway' => $driver,
            'status' => 'pending',
            'reference' => $reference,
        ]);

        if ($driver === 'stripe') {
            return self::createStripeSession($payment, $plan, $cycle);
        }

        return self::createPix($payment, $plan, $amount, $reference);
    }

    private static function createPix(array $payment, array $plan, int $amount, string $reference): array
    {
        $key = getenv('PIX_KEY') ?: Settings::get('pix_key', '');
        if (empty($key)) {
            return ['error' => 'PIX não configurado. Configure a chave PIX (env PIX_KEY).'];
        }

        $payload = self::pixPayload($key, $amount, $reference);

        $p = new Payments();
        $found = $p->get($payment['id']);
        if ($found) {
            $payments = $p->all();
            foreach ($payments as &$pay) {
                if ($pay['id'] === $payment['id']) {
                    $pay['payload'] = $payload;
                    break;
                }
            }
            file_put_contents(Config::getDataDir() . '/payments.json', json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return [
            'type' => 'pix',
            'payment_id' => $payment['id'],
            'amount' => $amount,
            'reference' => $reference,
            'copia_cola' => $payload,
            'qr_image' => 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($payload),
        ];
    }

    private static function createStripeSession(array $payment, array $plan, string $cycle): array
    {
        $secret = getenv('STRIPE_SECRET_KEY') ?: Settings::get('stripe_secret_key', '');
        if (empty($secret)) {
            return ['error' => 'Stripe não configurado. Configure STRIPE_SECRET_KEY (env).'];
        }

        if (!class_exists('curl_init') || !function_exists('curl_init')) {
            return ['error' => 'cURL não disponível no servidor'];
        }

        $baseUrl = Config::getBaseUrl();
        $unitAmount = $payment['amount'] ?? 0;

        $params = [
            'mode' => 'payment',
            'currency' => 'brl',
            'locale' => 'pt-BR',
            'success_url' => $baseUrl . '/admin/plan.php?payment_success=1',
            'cancel_url' => $baseUrl . '/admin/plan.php?payment_cancel=1',
            'metadata[plan_id]' => $payment['plan_id'],
            'metadata[cycle]' => $cycle,
            'metadata[user_id]' => $payment['user_id'],
            'client_reference_id' => (string)$payment['id'],
            'line_items[0][price_data][currency]' => 'brl',
            'line_items[0][price_data][unit_amount]' => (string)($unitAmount * 100),
            'line_items[0][price_data][product_data][name]' => 'AfiliaFacil - Plano ' . $plan['name'] . ' (' . Plans::CYCLE_LABELS[$cycle] . ')',
            'line_items[0][quantity]' => '1',
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['error' => 'Erro ao criar sessão Stripe. Código: ' . $httpCode];
        }

        $data = json_decode($response, true);
        if (empty($data['url'])) {
            return ['error' => 'Stripe não retornou URL de checkout'];
        }

        return [
            'type' => 'stripe',
            'payment_id' => $payment['id'],
            'redirect_url' => $data['url'],
        ];
    }

    private static function pixPayload(string $key, int $amount, string $txid): string
    {
        $name = mb_substr(trim(getenv('PIX_NAME') ?: Settings::get('pix_name', 'AfiliaFacil')), 0, 25);
        $city = mb_substr(trim(getenv('PIX_CITY') ?: Settings::get('pix_city', 'SAO PAULO')), 0, 15);
        $amountStr = number_format($amount, 2, '.', '');

        $payload = self::emv('00', '01') . self::emv('26', self::emv('00', 'BR.GOV.BCB.PIX') . self::emv('01', $key)) . self::emv('52', '0000') . self::emv('53', '986') . self::emv('54', $amountStr) . self::emv('58', 'BR') . self::emv('59', $name) . self::emv('60', $city) . self::emv('62', self::emv('05', substr($txid, 0, 25)));

        $crc = self::crc16($payload . '6304');
        return $payload . '6304' . $crc;
    }

    private static function emv(string $id, string $value): string
    {
        return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = ($crc << 1) ^ 0x1021;
                } else {
                    $crc <<= 1;
                }
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
