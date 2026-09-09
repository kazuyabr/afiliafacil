<?php
require_once dirname(__DIR__) . '/lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Auth.php';

header('Content-Type: application/json');

$secret = getenv('STRIPE_WEBHOOK_SECRET') ?: Settings::get('stripe_webhook_secret', '');
if (empty($secret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook Stripe não configurado']);
    exit;
}

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!self_verifySignature($payload, $sigHeader, $secret, 300)) {
    http_response_code(400);
    echo json_encode(['error' => 'Assinatura inválida']);
    exit;
}

$event = json_decode($payload, true);
$type = $event['type'] ?? '';

if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'])) {
    $session = $event['data']['object'] ?? [];
    $metadata = $session['metadata'] ?? [];
    $paymentId = (int)($session['client_reference_id'] ?? $metadata['payment_id'] ?? 0);
    $planId = $metadata['plan_id'] ?? '';
    $userId = (int)($metadata['user_id'] ?? 0);

    $payments = new Payments();
    $payment = $paymentId ? $payments->get($paymentId) : null;

    if ($payment && $payment['status'] !== 'paid') {
        $payments->approve($payment['id']);
    }
    echo json_encode(['ok' => true, 'payment' => $paymentId, 'plan' => $planId]);
    exit;
}

echo json_encode(['ok' => true, 'ignored' => $type]);

function self_verifySignature(string $payload, string $signature, string $secret, int $tolerance = 300): bool
{
    if (empty($signature)) return false;
    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$k, $v] = explode('=', trim($part), 2);
        $parts[$k] = $v;
    }
    if (empty($parts['t']) || empty($parts['v1'])) return false;

    if (abs(time() - (int)$parts['t']) > $tolerance) return false;

    $signed = $parts['t'] . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    return hash_equals($expected, $parts['v1']);
}
