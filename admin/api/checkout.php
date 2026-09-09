<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Payments.php';
require_once Config::getLibDir() . '/Checkout.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        $paymentId = (int)($_GET['payment_id'] ?? 0);
        $payments = new Payments();
        $payment = $payments->get($paymentId);
        if (!$payment) {
            echo json_encode(['error' => 'Pagamento não encontrado']);
            break;
        }
        if ($payment['user_id'] !== (int)Auth::user()['id'] && !Auth::isAdmin()) {
            echo json_encode(['error' => 'Acesso negado']);
            break;
        }
        echo json_encode(['status' => $payment['status'], 'plan' => $payment['plan_id']]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
