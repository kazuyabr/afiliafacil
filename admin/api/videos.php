<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/Video/VideoStudio.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();

// Libera o lock da sessao (ffmpeg pode levar minutos em videos longos)
session_write_close();

if (!Plans::hasFeature($user['plan'], 'videos') && !Plans::hasFeature($user['plan'], 'video') && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Ferramentas de vídeo disponíveis no plano VSL. Faça upgrade em "Meu Plano".']);
    exit;
}

if (!VideoStudio::available()) {
    http_response_code(503);
    echo json_encode(['error' => 'ffmpeg indisponível no servidor.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        echo json_encode(['success' => true, 'items' => VideoStudio::list()], JSON_UNESCAPED_UNICODE);
        break;

    case 'upload':
        $result = VideoStudio::storeUpload($_FILES['file'] ?? []);
        if (!empty($result['success'])) {
            Audit::log('video_uploaded', 'video', $result['name'], []);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'import':
        $result = VideoStudio::importUrl(trim($_POST['url'] ?? ''));
        if (!empty($result['success'])) {
            Audit::log('video_imported', 'video', $result['name'], []);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'delete':
        $ok = VideoStudio::delete((string)($_POST['name'] ?? ''));
        echo json_encode($ok ? ['success' => true] : ['error' => 'Arquivo não encontrado']);
        break;

    case 'download': {
            $path = VideoStudio::path((string)($_GET['name'] ?? ''));
            if ($path === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Arquivo não encontrado']);
                exit;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = $ext === 'mp4' ? 'video/mp4' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            exit;
        }

    case 'cut': {
            $result = VideoStudio::cut((string)($_POST['name'] ?? ''), (float)($_POST['start'] ?? 0), (float)($_POST['end'] ?? 0));
            if (!empty($result['success'])) {
                Audit::log('video_cut', 'video', $result['name'], ['from' => $_POST['name'] ?? '']);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
        }

    case 'cover': {
            $result = VideoStudio::cover((string)($_POST['name'] ?? ''), (float)($_POST['second'] ?? 1));
            if (!empty($result['success'])) {
                Audit::log('video_cover', 'video', $result['name'], ['from' => $_POST['name'] ?? '']);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
        }

    case 'subtitle': {
            $name = (string)($_POST['name'] ?? '');
            $srt = $_FILES['srt'] ?? null;
            if (!$srt || ($srt['error'] ?? 1) !== UPLOAD_ERR_OK || !is_file($srt['tmp_name'])) {
                echo json_encode(['error' => 'Envie o arquivo .srt da legenda.']);
                break;
            }
            $tmpSrt = sys_get_temp_dir() . '/af-sub-' . bin2hex(random_bytes(8)) . '.srt';
            if (!move_uploaded_file($srt['tmp_name'], $tmpSrt)) {
                echo json_encode(['error' => 'Falha ao receber o .srt.']);
                break;
            }
            $result = VideoStudio::subtitle($name, $tmpSrt);
            @unlink($tmpSrt);
            if (!empty($result['success'])) {
                Audit::log('video_subtitled', 'video', $result['name'], ['from' => $name]);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
        }

    case 'vary': {
            $result = VideoStudio::vary((string)($_POST['name'] ?? ''));
            if (!empty($result['success'])) {
                Audit::log('video_varied', 'video', $result['name'], ['from' => $_POST['name'] ?? '']);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
        }

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
