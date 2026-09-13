<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Totp.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$userId = (int)Auth::user()['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        echo json_encode([
            'success' => true,
            'enabled' => Auth::has2fa($userId),
        ]);
        break;

    case 'setup':
        $secret = Totp::generateSecret();
        $_SESSION['2fa_setup_secret'] = $secret;
        $user = Auth::user();
        $uri = Totp::provisioningUri('AfiliaFacil', $user['email'], $secret);
        echo json_encode([
            'success' => true,
            'secret' => $secret,
            'uri' => $uri,
            'qr_image' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri),
        ]);
        break;

    case 'confirm':
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if ($secret === '') {
            echo json_encode(['error' => 'Sessão de configuração expirada. Reinicie o processo.']);
            break;
        }
        if (!Totp::verify($secret, $_POST['code'] ?? '')) {
            echo json_encode(['error' => 'Código inválido. Verifique o horário do seu celular e tente novamente.']);
            break;
        }

        $codes = Totp::generateRecoveryCodes();
        if (!Auth::enable2fa($userId, $secret, $codes)) {
            echo json_encode(['error' => 'Falha ao ativar 2FA']);
            break;
        }

        unset($_SESSION['2fa_setup_secret']);
        echo json_encode(['success' => true, 'recovery_codes' => $codes]);
        break;

    case 'disable':
        if (!Auth::verify2fa($userId, $_POST['code'] ?? '')) {
            echo json_encode(['error' => 'Código inválido.']);
            break;
        }
        Auth::disable2fa($userId);
        echo json_encode(['success' => true]);
        break;

    case 'regenerate':
        if (!Auth::verify2fa($userId, $_POST['code'] ?? '')) {
            echo json_encode(['error' => 'Código inválido.']);
            break;
        }
        $codes = Auth::regenerateRecoveryCodes($userId);
        echo json_encode(['success' => true, 'recovery_codes' => $codes]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
