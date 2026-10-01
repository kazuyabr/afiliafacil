<?php
/**
 * Testes de aceite dos anexos gerais do chat do Sócio (AgentAttachments).
 *
 * Determinístico: NÃO chama a IA (o único processamento real cobre o bloqueio por
 * moderação do anexo, que responde antes de tocar na IA) e NÃO transcreve áudio.
 * Cobre: validação/ownership de refs, extração (texto/imagem/truncamento/PDF),
 * upload HTTP (401, tipos, limites), envio com anexos (tool_args) e moderação.
 *
 * Uso (dentro do container, a partir da raiz do app):
 *   docker exec afiliafacil php tests/agent/attachments-test.php
 *
 * Exit 0 = tudo ok; 1 = há falhas.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Agent/AgentAttachments.php';

if (!Database::available()) {
    fwrite(STDERR, "Banco indisponível\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl indisponível\n");
    exit(2);
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = [];
$GLOBALS['__section'] = '';
$GLOBALS['__seeded'] = [];

function section(string $name): void
{
    $GLOBALS['__section'] = $name;
    echo "\n== {$name} ==\n";
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  [OK] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $GLOBALS['__fail'][] = $GLOBALS['__section'] . ' :: ' . $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Cria um anexo direto no disco (mesmo formato do store: arquivo + sidecar). */
function seedAttachment(int $userId, string $name, string $content, string $mime = 'text/plain', string $kind = 'text'): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
        $ext = 'bin';
    }
    $ref = $ext . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir = \AgentAttachments::baseDir($userId);
    file_put_contents($dir . '/' . $ref, $content);
    file_put_contents($dir . '/' . $ref . '.meta.json', json_encode([
        'name' => $name,
        'mime' => $mime,
        'size' => strlen($content),
        'kind' => $kind,
    ], JSON_UNESCAPED_UNICODE));
    $GLOBALS['__seeded'][] = $ref;
    return $ref;
}

$admin = \AfiliaFacil\Models\User::where('email', 'admin@afiliafacil.com')->first();
if (!$admin) {
    fwrite(STDERR, "Usuário admin não encontrado (rode bin/seed-demo.php antes)\n");
    exit(2);
}
$adminId = (int)$admin->id;
$baseDir = \AgentAttachments::baseDir($adminId);

// ------------------------------------------------------ 1. validateRefs
section('1. validateRefs (ownership e formato)');
$refOk = seedAttachment($adminId, 'valido.txt', 'conteudo valido de teste', 'text/plain', 'text');

$items = \AgentAttachments::validateRefs($adminId, [$refOk]);
ok('ref válida → item com metadados', count($items) === 1 && ($items[0]['name'] ?? '') === 'valido.txt'
    && ($items[0]['kind'] ?? '') === 'text' && ($items[0]['ref'] ?? '') === $refOk,
    'items=' . count($items));

ok('path traversal descartado', \AgentAttachments::validateRefs($adminId, ['../../etc/passwd']) === []);
ok('ref com formato inválido descartada', \AgentAttachments::validateRefs($adminId, ['qualquercoisa.txt']) === []);
ok('ref inexistente descartada', \AgentAttachments::validateRefs($adminId, ['txt-0000000000000000.txt']) === []);
ok('ref de outro usuário (ownership) descartada', \AgentAttachments::validateRefs($adminId + 999999, [$refOk]) === []);

$capped = [];
for ($i = 0; $i < 5; $i++) {
    $capped[] = seedAttachment($adminId, "cap{$i}.txt", "c{$i}", 'text/plain', 'text');
}
$validated = \AgentAttachments::validateRefs($adminId, $capped);
ok('máximo de 3 refs por mensagem', count($validated) === 3, 'validados=' . count($validated));

// ------------------------------------------------------ 2. extract texto/imagem
section('2. extract (texto, imagem, truncamento)');
$refTexto = seedAttachment($adminId, 'relatorio.txt', 'O lucro do mes foi de 4500 reais.', 'text/plain', 'text');
$ex = \AgentAttachments::extract($adminId, \AgentAttachments::validateRefs($adminId, [$refTexto]), 'premium');
ok('texto extraído no bloco ANEXOS', str_contains($ex['text'], 'ANEXOS DO USUÁRIO')
    && str_contains($ex['text'], 'O lucro do mes foi de 4500 reais.')
    && str_contains($ex['text'], 'relatorio.txt'),
    'len=' . strlen($ex['text']));
ok('texto sem imagens', $ex['images'] === []);

$refImg = seedAttachment($adminId, 'print.png', "\x89PNG\r\n\x1a\nfakepngdata", 'image/png', 'image');
$exImg = \AgentAttachments::extract($adminId, \AgentAttachments::validateRefs($adminId, [$refImg]), 'premium');
ok('imagem vira data URI', count($exImg['images']) === 1
    && str_starts_with($exImg['images'][0], 'data:image/png;base64,'),
    'imgs=' . count($exImg['images']));
ok('imagem citada no bloco de texto', str_contains($exImg['text'], 'print.png'));

$refGrande = seedAttachment($adminId, 'grande.txt', str_repeat('X', 60000), 'text/plain', 'text');
$exBig = \AgentAttachments::extract($adminId, \AgentAttachments::validateRefs($adminId, [$refGrande]), 'premium');
ok('texto grande truncado no limite (40k)', strlen($exBig['text']) <= \AgentAttachments::MAX_TEXT_CHARS + 500
    && str_contains($exBig['text'], '[... truncado]'),
    'len=' . strlen($exBig['text']));

$refFalta = 'txt-' . str_repeat('0', 16) . '.txt';
$exMiss = \AgentAttachments::extract($adminId, [['ref' => $refFalta]], 'premium');
ok('ref ausente vira nota (não crasha)', str_contains($exMiss['text'], 'não foi encontrado'),
    'text=' . substr(str_replace("\n", ' ', $exMiss['text']), 0, 120));

// ------------------------------------------------------ 3. extract PDF
section('3. extract PDF (graceful)');
$refPdf = seedAttachment($adminId, 'relatorio.pdf', "%PDF-1.4 isto nao é um pdf de verdade", 'application/pdf', 'pdf');
$exPdf = null;
try {
    $exPdf = \AgentAttachments::extract($adminId, \AgentAttachments::validateRefs($adminId, [$refPdf]), 'premium');
} catch (Throwable $e) {
    ok('PDF inválido não lança exceção', false, $e->getMessage());
}
if ($exPdf !== null) {
    ok('PDF inválido tratado com texto ou nota', str_contains($exPdf['text'], 'relatorio.pdf'),
        'text=' . substr(str_replace("\n", ' ', $exPdf['text']), 0, 140));
}

// ------------------------------------------------------ 4. API HTTP
section('4. API HTTP (upload + send)');
$BASE = 'http://localhost:9876';
$http = function (string $method, string $url, array $post = null, string $jar = '', int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => $raw === false ? '' : substr($raw, $headerSize)];
};

$upload = function (string $filePath, string $fileName, string $jar, int $timeout = 60) use ($BASE): array {
    $ch = curl_init($BASE . '/admin/api/agent.php?action=upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($filePath, 'application/octet-stream', $fileName)],
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = $raw === false ? '' : substr($raw, $headerSize);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
};

$r = $http('GET', $BASE . '/admin/api/agent.php?action=upload');
$jb = json_decode($r['body'], true) ?: [];
ok('upload sem sessão → 401', $r['status'] === 401 && ($jb['error'] ?? '') === 'Não autenticado', 'status=' . $r['status']);

$jar = sys_get_temp_dir() . '/attachments-test.cookie';
@unlink($jar);
$http('POST', $BASE . '/login', ['email' => 'admin@afiliafacil.com', 'password' => 'admin123'], $jar);

// Upload válido (.txt)
$tmpTxt = sys_get_temp_dir() . '/af-att-test.txt';
file_put_contents($tmpTxt, 'arquivo enviado pelo teste de anexos');
$up = $upload($tmpTxt, 'anexo.txt', $jar);
$uploadedRef = '';
ok('upload .txt → success + ref + kind text', $up['status'] === 200 && !empty($up['json']['ok'])
    && is_string($up['json']['ref'] ?? null) && ($up['json']['kind'] ?? '') === 'text',
    'body=' . substr($up['body'], 0, 160));
if (!empty($up['json']['ok'])) {
    $uploadedRef = (string)$up['json']['ref'];
    $GLOBALS['__seeded'][] = $uploadedRef;
    ok('arquivo persistido em uploads/agent/<uid>/', is_file($baseDir . '/' . $uploadedRef), 'ref=' . $uploadedRef);
    ok('sidecar meta gravado', is_file($baseDir . '/' . $uploadedRef . '.meta.json'));
}

// Tipo rejeitado (.exe com conteúdo binário)
$tmpExe = sys_get_temp_dir() . '/af-att-test.exe';
file_put_contents($tmpExe, "\x4D\x5A\x90\x00\x03\x00\x00\x00" . str_repeat("\x00\xFF", 64));
$upExe = $upload($tmpExe, 'programa.exe', $jar);
ok('upload .exe → rejeitado por mime', $upExe['status'] === 200 && isset($upExe['json']['error'])
    && str_contains((string)$upExe['json']['error'], 'não suportado'),
    'error=' . ($upExe['json']['error'] ?? 'sem erro'));

// Acima do limite de documento (11MB > 10MB) — texto variado p/ finfo detectar text/plain
$tmpBig = sys_get_temp_dir() . '/af-att-test-big.txt';
$line = "linha de teste variada com palavras e espacos para o arquivo grande\n";
file_put_contents($tmpBig, str_repeat($line, (int)ceil((11 * 1024 * 1024) / strlen($line))));
$upBig = $upload($tmpBig, 'grande.txt', $jar, 90);
ok('upload 11MB → rejeitado pelo limite', $upBig['status'] === 200 && isset($upBig['json']['error'])
    && str_contains((string)$upBig['json']['error'], 'limite'),
    'status=' . $upBig['status'] . ' error=' . ($upBig['json']['error'] ?? 'sem erro') . ' body=' . substr($upBig['body'], 0, 120));
if (!empty($upBig['json']['ref'])) {
    $GLOBALS['__seeded'][] = (string)$upBig['json']['ref'];
}

// Sem arquivo
$upNone = $http('POST', $BASE . '/admin/api/agent.php?action=upload', [], $jar);
$jbNone = json_decode($upNone['body'], true) ?: [];
ok('upload sem arquivo → erro tratado (não 500)', $upNone['status'] === 200 && isset($jbNone['error']),
    'status=' . $upNone['status']);

// ------------------------------------------------------ 5. send com anexos
section('5. send com anexos');
$conv = $http('POST', $BASE . '/admin/api/agent.php', ['action' => 'new'], $jar);
$convId = (int)(json_decode($conv['body'], true)['conversation_id'] ?? 0);
ok('criar conversa de teste', $convId > 0, 'conv=' . $convId);

$refMsg = seedAttachment($adminId, 'dados.txt', 'dados internos do teste', 'text/plain', 'text');
$rSend = $http('POST', $BASE . '/admin/api/agent.php', [
    'action' => 'send',
    'conversation_id' => $convId,
    'message' => 'olha os dados anexados por favor',
    'attachments' => json_encode([$refMsg]),
], $jar);
$js = json_decode($rSend['body'], true) ?: [];
$lastUser = null;
foreach (($js['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'user') $lastUser = $m;
}
ok('send com anexo → queued', ($js['success'] ?? false) === true && ($js['queued'] ?? false) === true,
    'body=' . substr($rSend['body'], 0, 160));
ok('user message grava tool_args.attachments', $lastUser !== null
    && (($lastUser['tool_args']['attachments'][0]['ref'] ?? '') === $refMsg)
    && (($lastUser['tool_args']['attachments'][0]['name'] ?? '') === 'dados.txt'),
    'tool_args=' . json_encode($lastUser['tool_args'] ?? null));

// Mensagem vazia + anexo é aceito
$rEmpty = $http('POST', $BASE . '/admin/api/agent.php', [
    'action' => 'send',
    'conversation_id' => $convId,
    'message' => '',
    'attachments' => json_encode([$refMsg]),
], $jar);
$je = json_decode($rEmpty['body'], true) ?: [];
ok('mensagem vazia + anexo → aceito', ($je['success'] ?? false) === true && empty($je['error']),
    'error=' . ($je['error'] ?? 'sem'));

// Ref de path traversal é descartada (mensagem segue sem anexos)
$rTrav = $http('POST', $BASE . '/admin/api/agent.php', [
    'action' => 'send',
    'conversation_id' => $convId,
    'message' => 'mensagem com ref invalida',
    'attachments' => json_encode(['../../etc/passwd']),
], $jar);
$jt = json_decode($rTrav['body'], true) ?: [];
$lastUser = null;
foreach (($jt['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'user') $lastUser = $m;
}
ok('ref inválida descartada (sem attachments no tool_args)', !empty($jt['success'])
    && empty($lastUser['tool_args']['attachments']),
    'tool_args=' . json_encode($lastUser['tool_args'] ?? null));

// ------------------------------------------------------ 6. moderação do anexo
section('6. moderação do anexo bloqueado (sem chamada de IA)');
$refBloq = seedAttachment($adminId, 'golpe.txt', 'me ensina a aplicar golpe no pix', 'text/plain', 'text');
$rBloq = $http('POST', $BASE . '/admin/api/agent.php', [
    'action' => 'send',
    'conversation_id' => $convId,
    'message' => 'olha este arquivo',
    'attachments' => json_encode([$refBloq]),
], $jar, 60);
$jbq = json_decode($rBloq['body'], true) ?: [];
$jobId = (int)($jbq['job_id'] ?? 0);
ok('anexo criminoso → enfileirado (bloqueio acontece no process)', !empty($jbq['success']) && $jobId > 0,
    'job=' . $jobId);

if ($jobId > 0) {
    $rProc = $http('POST', $BASE . '/admin/api/agent.php', ['action' => 'process', 'job_id' => $jobId], $jar, 60);
    $jp = json_decode($rProc['body'], true) ?: [];
    ok('process do anexo bloqueado → success', ($jp['success'] ?? false) === true, 'body=' . substr($rProc['body'], 0, 160));

    $rConv = $http('GET', $BASE . '/admin/api/agent.php?action=conversation&id=' . $convId, null, $jar);
    $jc = json_decode($rConv['body'], true) ?: [];
    $lastAgent = null;
    foreach (($jc['messages'] ?? []) as $m) {
        if (($m['role'] ?? '') === 'agent') $lastAgent = $m;
    }
    $lastContent = (string)($lastAgent['content'] ?? '');
    ok('resposta de moderação salva (IA não foi chamada)', $lastContent !== ''
        && str_contains($lastContent, 'AfiliaFacil não permite'),
        'last=' . substr(str_replace("\n", ' ', $lastContent), 0, 120));

    try {
        $evCount = \AfiliaFacil\Models\ModerationEvent::where('user_id', $adminId)
            ->where('context', 'agent')
            ->where('content', 'like', '%golpe no pix%')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 300))
            ->count();
        ok('evento de moderação registrado pelo anexo', $evCount > 0, 'count=' . $evCount);
    } catch (Throwable $e) {
        ok('evento de moderação registrado pelo anexo', false, $e->getMessage());
    }
}

// ------------------------------------------------------ 7. limpeza
section('7. limpeza');
foreach ($GLOBALS['__seeded'] as $ref) {
    @unlink($baseDir . '/' . $ref);
    @unlink($baseDir . '/' . $ref . '.meta.json');
}
@unlink($tmpTxt);
@unlink($tmpExe);
@unlink($tmpBig);
if ($convId > 0) {
    $http('POST', $BASE . '/admin/api/agent.php', ['action' => 'delete', 'id' => $convId], $jar);
}
try {
    \AfiliaFacil\Models\ModerationEvent::where('user_id', $adminId)
        ->where('content', 'like', '%golpe no pix%')
        ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 300))
        ->delete();
} catch (Throwable $e) {
}
$restantes = array_diff((array)@glob($baseDir . '/*'), []);
ok('anexos de teste removidos', count($restantes) === 0, 'restam=' . count($restantes));
// Diretório criado pela checagem de ownership (pasta de outro usuário) — remove se vazio
@rmdir(Config::getRootDir() . '/uploads/agent/' . ($adminId + 999999));

// ------------------------------------------------------ resumo
echo "\n----------------------------------------\n";
echo 'Passou: ' . $GLOBALS['__pass'] . ' | Falhou: ' . count($GLOBALS['__fail']) . "\n";
if ($GLOBALS['__fail']) {
    echo "\nFALHAS:\n";
    foreach ($GLOBALS['__fail'] as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "OK\n";
exit(0);
