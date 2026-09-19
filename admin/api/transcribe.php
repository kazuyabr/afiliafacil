<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/Ai/SttConfig.php';
require_once Config::getLibDir() . '/Ai/SttClient.php';
require_once Config::getLibDir() . '/Ai/SttQuota.php';
require_once Config::getLibDir() . '/Ai/MediaDetector.php';
require_once Config::getLibDir() . '/Moderation/ContentModerator.php';
require_once Config::getLibDir() . '/Training/TrainingCollector.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Libera o lock da sessao antes de operacoes longas (STT pode levar minutos)
session_write_close();

switch ($action) {
    case 'quota':
        echo json_encode([
            'success' => true,
            'quota' => SttQuota::check($userId, $user['plan']),
            'available' => SttConfig::isAvailable($userId),
            'config' => (function () use ($userId) {
                $config = SttConfig::forUser($userId);
                return ['provider' => $config['provider'], 'model' => $config['model'], 'source' => $config['source']];
            })(),
        ]);
        break;

    case 'list':
        if (!Database::available()) { echo json_encode(['success' => true, 'items' => []]); break; }
        $items = \AfiliaFacil\Models\Transcription::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
                'id' => (int)$t->id,
                'source_url' => $t->source_url,
                'provider' => $t->provider,
                'status' => $t->status,
                'duration_seconds' => (int)$t->duration_seconds,
                'preview' => mb_substr((string)$t->text, 0, 180),
                'error' => $t->error,
                'created_at' => (string)$t->created_at,
            ])->all();
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $transcription = \AfiliaFacil\Models\Transcription::where('id', $id)
            ->where('user_id', $userId)
            ->first();
        if (!$transcription) {
            http_response_code(404);
            echo json_encode(['error' => 'Transcrição não encontrada']);
            break;
        }
        echo json_encode([
            'success' => true,
            'transcription' => [
                'id' => (int)$transcription->id,
                'source_url' => $transcription->source_url,
                'provider' => $transcription->provider,
                'status' => $transcription->status,
                'duration_seconds' => (int)$transcription->duration_seconds,
                'text' => $transcription->text,
                'words' => $transcription->words ?? [],
                'error' => $transcription->error,
                'created_at' => (string)$transcription->created_at,
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $deleted = \AfiliaFacil\Models\Transcription::where('id', $id)->where('user_id', $userId)->delete();
        echo json_encode($deleted ? ['success' => true] : ['error' => 'Transcrição não encontrada']);
        break;

    case 'detect':
        $url = trim($_POST['url'] ?? '');
        $result = MediaDetector::detect($url);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case 'transcribe':
        $url = trim($_POST['url'] ?? '');
        $file = $_FILES['file'] ?? null;

        if ($url === '' && (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK)) {
            echo json_encode(['error' => 'Informe uma URL ou envie um arquivo de áudio/vídeo.']);
            break;
        }

        $quota = SttQuota::check($userId, $user['plan']);
        if (!$quota['allowed']) {
            echo json_encode([
                'error' => sprintf(SttQuota::BYOK_MESSAGE, $quota['used'], $quota['limit']),
                'quota' => $quota,
            ]);
            break;
        }

        $config = SttConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            echo json_encode(['error' => 'Transcrição não configurada. Configure a IA da plataforma (CF_AI_TOKEN) ou sua chave em IA (BYOK).']);
            break;
        }

        $input = [];
        $sourceLabel = $url;

        if ($file && ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
            $tmpPath = sys_get_temp_dir() . '/af-stt-' . bin2hex(random_bytes(8)) . '-' . basename($file['name']);
            if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
                echo json_encode(['error' => 'Falha ao receber o arquivo enviado.']);
                break;
            }
            $input = ['file' => $tmpPath, 'filename' => $file['name']];
            $sourceLabel = 'upload:' . $file['name'];
        } else {
            $detect = ($_POST['detect'] ?? '1') === '1';
            if ($detect && !MediaDetector::isMediaUrl($url)) {
                $media = MediaDetector::detect($url);
                if (empty($media['success'])) {
                    echo json_encode(['error' => $media['error'] ?? 'Mídia não detectada.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $input = ['url' => $media['media_url']];
                $sourceLabel = $media['media_url'];
            } else {
                $input = ['url' => $url];
            }
        }

        $transcriptionId = SttQuota::create($userId, $sourceLabel, $config['provider'], $config['source'] ?? 'platform');
        if ($transcriptionId === null) {
            echo json_encode(['error' => 'Falha ao registrar a transcrição.']);
            break;
        }

        $result = SttClient::transcribe($input, $config);

        if (!empty($input['file']) && is_file($input['file'])) {
            @unlink($input['file']);
        }

        if (empty($result['success'])) {
            SttQuota::fail($transcriptionId, $result['error'] ?? 'Erro desconhecido');
            Audit::log('transcription_failed', 'transcription', (string)$transcriptionId, ['provider' => $config['provider']]);
            echo json_encode(['error' => $result['error'] ?? 'Falha na transcrição.', 'id' => $transcriptionId], JSON_UNESCAPED_UNICODE);
            break;
        }

        SttQuota::complete($transcriptionId, $result);

        $screen = ContentModerator::screen((string)($result['text'] ?? ''), 'transcription', $userId);
        if (!$screen['allowed']) {
            SttQuota::complete($transcriptionId, array_merge($result, ['text' => $screen['reason'], 'words' => []]));
            echo json_encode(['error' => 'A transcrição foi bloqueada pela moderação e registrada.', 'id' => $transcriptionId], JSON_UNESCAPED_UNICODE);
            break;
        }
        if ($screen['action'] === 'redact') {
            SttQuota::complete($transcriptionId, array_merge($result, ['text' => $screen['clean']]));
            $result['text'] = $screen['clean'];
        }

        TrainingCollector::capture($userId, $user['plan'], TrainingCollector::KIND_TRANSCRIPTION, [
            'url' => $sourceLabel,
            'text' => $result['text'],
        ]);

        Audit::log('transcription_completed', 'transcription', (string)$transcriptionId, [
            'provider' => $result['provider'] ?? '',
            'duration' => $result['duration'] ?? 0,
        ]);

        echo json_encode([
            'success' => true,
            'id' => $transcriptionId,
            'text' => $result['text'],
            'words' => $result['words'],
            'duration' => $result['duration'],
            'provider' => $result['provider'],
            'quota' => SttQuota::check($userId, $user['plan']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
