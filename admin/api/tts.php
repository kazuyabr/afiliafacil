<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Audit.php';
require_once Config::getLibDir() . '/Ai/TtsConfig.php';
require_once Config::getLibDir() . '/Ai/TtsClient.php';
require_once Config::getLibDir() . '/Ai/TtsQuota.php';
require_once Config::getLibDir() . '/Moderation/ContentModerator.php';
require_once Config::getLibDir() . '/Training/TrainingCollector.php';

if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Libera o lock da sessao antes de operacoes longas (TTS pode demorar)
session_write_close();

if ($action === 'audio') {
    $id = (int)($_GET['id'] ?? 0);
    $generation = \AfiliaFacil\Models\TtsGeneration::where('id', $id)->where('user_id', $userId)->first();
    if (!$generation || $generation->status !== 'completed' || $generation->file_path === '') {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Áudio não encontrado']);
        exit;
    }

    $path = Config::getUploadsDir() . '/tts/' . basename($generation->file_path);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Arquivo não encontrado']);
        exit;
    }

    $format = $generation->format === 'wav' ? 'wav' : 'mp3';
    header('Content-Type: audio/' . ($format === 'wav' ? 'wav' : 'mpeg'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="narracao-' . $id . '.' . $format . '"');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

switch ($action) {
    case 'quota':
        $config = TtsConfig::forUser($userId);
        echo json_encode([
            'success' => true,
            'quota' => TtsQuota::check($userId, $user['plan']),
            'available' => TtsConfig::isAvailable($userId),
            'config' => ['provider' => $config['provider'], 'model' => $config['model'], 'source' => $config['source']],
            'voices' => TtsConfig::VOICES[$config['provider']] ?? [],
            'default_voice' => TtsConfig::defaultVoice($config['provider']),
            'max_chars' => TtsClient::MAX_CHARS,
        ]);
        break;

    case 'list':
        if (!Database::available()) { echo json_encode(['success' => true, 'items' => []]); break; }
        $items = \AfiliaFacil\Models\TtsGeneration::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
                'id' => (int)$t->id,
                'provider' => $t->provider,
                'model' => $t->model,
                'voice' => $t->voice,
                'format' => $t->format,
                'chars' => (int)$t->chars,
                'status' => $t->status,
                'preview' => mb_substr((string)$t->text, 0, 180),
                'error' => $t->error,
                'created_at' => (string)$t->created_at,
            ])->all();
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $generation = \AfiliaFacil\Models\TtsGeneration::where('id', $id)->where('user_id', $userId)->first();
        if (!$generation) { echo json_encode(['error' => 'Geração não encontrada']); break; }

        if ($generation->file_path !== '') {
            $path = Config::getUploadsDir() . '/tts/' . basename($generation->file_path);
            if (is_file($path)) @unlink($path);
        }
        $generation->delete();
        echo json_encode(['success' => true]);
        break;

    case 'generate':
        $text = trim($_POST['text'] ?? '');
        if ($text === '') { echo json_encode(['error' => 'Informe o texto para narração.']); break; }

        $screen = ContentModerator::screen($text, 'tts', $userId);
        if (!$screen['allowed']) {
            echo json_encode(['error' => $screen['reason']]);
            break;
        }
        $text = $screen['clean'];

        $quota = TtsQuota::check($userId, $user['plan']);
        if (!$quota['allowed']) {
            echo json_encode([
                'error' => sprintf(TtsQuota::BYOK_MESSAGE, $quota['used'], $quota['limit']),
                'quota' => $quota,
            ]);
            break;
        }

        $config = TtsConfig::forUser($userId);
        if (($config['api_key'] ?? '') === '') {
            echo json_encode(['error' => 'Narração não configurada. Configure a IA da plataforma (CF_AI_TOKEN) ou sua chave em IA (BYOK).']);
            break;
        }

        $provider = $config['provider'];
        $voice = trim($_POST['voice'] ?? '') ?: TtsConfig::defaultVoice($provider);
        $format = $config['provider'] === 'google' ? 'wav' : 'mp3';

        $generationId = TtsQuota::create($userId, $provider, (string)$config['model'], $voice, $format, $text, $config['source'] ?? 'platform');
        if ($generationId === null) {
            echo json_encode(['error' => 'Falha ao registrar a geração.']);
            break;
        }

        $result = TtsClient::generate($text, $config, $voice, $format);

        if (empty($result['success'])) {
            TtsQuota::fail($generationId, $result['error'] ?? 'Erro desconhecido');
            Audit::log('tts_failed', 'tts', (string)$generationId, ['provider' => $provider]);
            echo json_encode(['error' => $result['error'] ?? 'Falha na geração.', 'id' => $generationId, 'quota_exceeded' => !empty($result['quota_exceeded'])], JSON_UNESCAPED_UNICODE);
            break;
        }

        $ext = $result['format'] === 'wav' ? 'wav' : 'mp3';
        $dir = Config::getUploadsDir() . '/tts';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $filename = 'tts-' . $generationId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $filename, $result['audio']) === false) {
            TtsQuota::fail($generationId, 'Falha ao salvar o áudio gerado.');
            echo json_encode(['error' => 'Falha ao salvar o áudio gerado.']);
            break;
        }

        TtsQuota::complete($generationId, $filename);
        TrainingCollector::capture($userId, $user['plan'], TrainingCollector::KIND_TTS, [
            'voice' => $voice,
            'text' => $text,
        ]);
        Audit::log('tts_completed', 'tts', (string)$generationId, [
            'provider' => $result['provider'] ?? '',
            'chars' => mb_strlen($text),
        ]);

        echo json_encode([
            'success' => true,
            'id' => $generationId,
            'format' => $ext,
            'provider' => $result['provider'],
            'voice' => $result['voice'],
            'quota' => TtsQuota::check($userId, $user['plan']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
