<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Ai/SttClient.php';
require_once __DIR__ . '/../Ai/SttConfig.php';
require_once __DIR__ . '/../Ai/SttQuota.php';

/**
 * Anexos gerais no chat do Socio (imagem, PDF, audio, texto).
 *
 * O upload salva o arquivo em uploads/agent/<userId>/ (volume persistente, PRIVADO —
 * nunca exposto no router) junto de um sidecar <ref>.meta.json (nome/mime/tamanho/tipo).
 * O envio guarda apenas as refs em tool_args.attachments da mensagem (sem migration);
 * a extracao de conteudo para o prompt acontece no processamento do job
 * (Agent::processJob), com moderacao do texto extraido antes de chegar na IA.
 */
class AgentAttachments
{
    /** Maximo de arquivos por mensagem. */
    public const MAX_FILES = 3;
    public const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
    public const MAX_DOC_BYTES = 10 * 1024 * 1024;
    public const MAX_AUDIO_BYTES = 20 * 1024 * 1024;
    /** Limite total de texto extraido injetado no prompt (por mensagem). */
    public const MAX_TEXT_CHARS = 40000;
    private const ORPHAN_DAYS = 7;

    private const MIME_KINDS = [
        'image/png' => 'image',
        'image/jpeg' => 'image',
        'image/webp' => 'image',
        'image/gif' => 'image',
        'audio/mpeg' => 'audio',
        'audio/mp3' => 'audio',
        'audio/wav' => 'audio',
        'audio/x-wav' => 'audio',
        'audio/mp4' => 'audio',
        'audio/m4a' => 'audio',
        'audio/x-m4a' => 'audio',
        'audio/ogg' => 'audio',
        'audio/webm' => 'audio',
        'application/pdf' => 'pdf',
        'text/plain' => 'text',
        'text/markdown' => 'text',
        'text/csv' => 'text',
        'text/html' => 'text',
        'application/json' => 'text',
    ];

    public static function baseDir(int $userId): string
    {
        $dir = Config::getRootDir() . '/uploads/agent/' . $userId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Salva um upload com validacao (mime real via finfo, limites por tipo, nome seguro).
     * Retorna ['ok'=>true, ref, name, mime, size, kind] ou ['error' => ...].
     */
    public static function store(int $userId, array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['error' => self::uploadError($error)];
        }

        $name = self::safeName((string)($file['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Nome de arquivo inválido.'];
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'Falha ao receber o arquivo enviado.'];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['error' => 'Arquivo vazio.'];
        }

        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $kind = self::MIME_KINDS[$mime] ?? null;
        if ($kind === null) {
            return ['error' => 'Tipo de arquivo não suportado. Aceito: imagens (png/jpg/webp/gif), PDF, áudios (mp3/wav/m4a/ogg) e texto (.txt, .md, .csv, .json, .html).'];
        }

        $max = match ($kind) {
            'image' => self::MAX_IMAGE_BYTES,
            'audio' => self::MAX_AUDIO_BYTES,
            default => self::MAX_DOC_BYTES,
        };
        if ($size > $max) {
            return ['error' => 'Arquivo maior que o limite de ' . (int)ceil($max / 1048576) . ' MB.'];
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            $ext = 'bin';
        }
        $ref = $ext . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = self::baseDir($userId) . '/' . $ref;
        if (!move_uploaded_file($tmp, $path)) {
            return ['error' => 'Falha ao salvar o arquivo.'];
        }
        @chmod($path, 0644);

        $meta = ['name' => $name, 'mime' => $mime, 'size' => $size, 'kind' => $kind];
        @file_put_contents($path . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        self::disposeOrphans($userId);

        return ['ok' => true, 'ref' => $ref] + $meta;
    }

    /**
     * Resolve refs vindas do cliente: valida formato, ownership (pasta do usuario)
     * e existencia do sidecar. Descarta silenciosamente o que for invalido.
     * Retorna no maximo MAX_FILES itens ['ref','name','mime','size','kind'].
     */
    public static function validateRefs(int $userId, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) || $ref === '') continue;
            $meta = self::meta($userId, $ref);
            if ($meta === null) continue;
            $out[] = ['ref' => $ref] + $meta;
            if (count($out) >= self::MAX_FILES) break;
        }
        return $out;
    }

    /**
     * Extrai o conteudo dos anexos para o prompt.
     * Retorna ['text' => bloco ANEXOS (com notas de falha), 'images' => [dataUri...]].
     * - imagem  -> data URI (o modelo recebe como content-part; ver Agent::processJob)
     * - audio   -> transcricao via SttClient (consome cota do plano)
     * - pdf     -> texto via smalot/pdfparser (com teto de chars)
     * - texto   -> conteudo direto
     */
    public static function extract(int $userId, array $items, string $plan): array
    {
        $images = [];
        $chunks = [];
        $notes = [];
        $used = 0;
        $total = 0;

        foreach ($items as $item) {
            if ($total >= self::MAX_FILES) break;
            $ref = is_array($item) ? (string)($item['ref'] ?? '') : (string)$item;
            $meta = self::meta($userId, $ref);
            if ($meta === null) {
                $notes[] = 'um anexo não foi encontrado (expirou ou foi removido)';
                continue;
            }
            $total++;

            $name = $meta['name'];
            $kind = $meta['kind'];
            $path = self::resolve($userId, $ref);
            if ($path === null) {
                $notes[] = "[{$name}] arquivo não encontrado";
                continue;
            }

            $chunk = null;
            switch ($kind) {
                case 'image':
                    $blob = @file_get_contents($path);
                    if ($blob === false || $blob === '') {
                        $notes[] = "[{$name}] não consegui ler a imagem";
                        break;
                    }
                    $images[] = 'data:' . $meta['mime'] . ';base64,' . base64_encode($blob);
                    $chunk = "[{$name}] (imagem) — anexada a esta mensagem; analise o que aparece nela.";
                    break;

                case 'audio':
                    $text = self::transcribe($userId, $path, $name, $plan, $notes);
                    if ($text !== '') {
                        $chunk = "[{$name}] (transcrição do áudio):\n" . $text;
                    }
                    break;

                case 'pdf':
                    $text = self::parsePdf($path, $name, $notes);
                    if ($text !== '') {
                        $chunk = "[{$name}] (PDF):\n" . $text;
                    }
                    break;

                default:
                    $blob = @file_get_contents($path);
                    if ($blob === false) {
                        $notes[] = "[{$name}] não consegui ler o arquivo";
                        break;
                    }
                    $chunk = "[{$name}]:\n" . $blob;
            }

            if ($chunk === null) continue;

            $remain = self::MAX_TEXT_CHARS - $used;
            if ($remain <= 0) {
                $notes[] = 'conteúdo adicional truncado (limite de ' . self::MAX_TEXT_CHARS . ' caracteres)';
                break;
            }
            if (mb_strlen($chunk) > $remain) {
                $chunk = mb_substr($chunk, 0, $remain) . "\n[... truncado]";
            }
            $chunks[] = $chunk;
            $used += mb_strlen($chunk);
        }

        if (empty($chunks) && empty($notes)) {
            return ['text' => '', 'images' => []];
        }

        $block = '';
        if (!empty($chunks)) {
            $block = 'ANEXOS DO USUÁRIO (' . count($chunks) . "):\n" . implode("\n\n", $chunks);
        } else {
            $block = 'ANEXOS DO USUÁRIO: nenhum conteúdo pôde ser lido.';
        }
        if (!empty($notes)) {
            $block .= "\n\nObservações sobre os anexos:\n- " . implode("\n- ", $notes);
        }

        return ['text' => $block, 'images' => $images];
    }

    /** Remove arquivos e sidecars orfaos (nao usados ha mais de 7 dias). */
    public static function disposeOrphans(int $userId): void
    {
        $files = @glob(self::baseDir($userId) . '/*') ?: [];
        $cutoff = time() - self::ORPHAN_DAYS * 86400;
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    /** Caminho absoluto seguro da ref (ownership por pasta do usuario; null se invalida). */
    public static function resolve(int $userId, string $ref): ?string
    {
        if (!preg_match('/^[a-z0-9]{1,8}-[a-f0-9]{16}\.[a-z0-9]{1,8}$/', $ref)) {
            return null;
        }
        $dir = realpath(self::baseDir($userId));
        $path = realpath(self::baseDir($userId) . '/' . $ref);
        if ($dir === false || $path === false) return null;
        if (!is_file($path) || dirname($path) !== $dir) return null;
        return $path;
    }

    /** Metadados do sidecar; null se ref invalida/arquivo ausente. */
    public static function meta(int $userId, string $ref): ?array
    {
        $path = self::resolve($userId, $ref);
        if ($path === null) return null;

        $raw = @file_get_contents($path . '.meta.json');
        if ($raw === false) return null;
        $meta = json_decode($raw, true);
        if (!is_array($meta) || empty($meta['name']) || empty($meta['kind'])) return null;

        return [
            'name' => (string)$meta['name'],
            'mime' => (string)($meta['mime'] ?? 'application/octet-stream'),
            'size' => (int)($meta['size'] ?? 0),
            'kind' => (string)$meta['kind'],
        ];
    }

    private static function transcribe(int $userId, string $path, string $name, string $plan, array &$notes): string
    {
        $quota = SttQuota::check($userId, $plan);
        if (!$quota['allowed']) {
            $notes[] = "[{$name}] áudio não transcrito: cota de transcrições esgotada ({$quota['used']}/{$quota['limit']})";
            return '';
        }

        $config = SttConfig::forUser($userId);
        if (trim((string)($config['api_key'] ?? '')) === '') {
            $notes[] = "[{$name}] áudio não transcrito: transcrição não configurada";
            return '';
        }

        $id = SttQuota::create($userId, 'anexo:' . $name, $config['provider'], $config['source'] ?? 'platform');
        if ($id === null) {
            $notes[] = "[{$name}] áudio não transcrito: falha ao registrar a transcrição";
            return '';
        }

        $result = SttClient::transcribe(['file' => $path, 'filename' => basename($path)], $config);
        if (empty($result['success'])) {
            SttQuota::fail($id, (string)($result['error'] ?? 'Erro desconhecido'));
            $notes[] = "[{$name}] falha na transcrição: " . (string)($result['error'] ?? 'erro desconhecido');
            return '';
        }

        SttQuota::complete($id, $result);
        $text = trim((string)($result['text'] ?? ''));
        if ($text === '') {
            $notes[] = "[{$name}] áudio sem fala reconhecida";
        }
        return $text;
    }

    private static function parsePdf(string $path, string $name, array &$notes): string
    {
        if (!class_exists('Smalot\\PdfParser\\Parser')) {
            $autoload = Config::getRootDir() . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists('Smalot\\PdfParser\\Parser')) {
            $notes[] = "[{$name}] PDF não suportado: leitor de PDF indisponível";
            return '';
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $doc = $parser->parseFile($path);
            $text = trim((string)$doc->getText());
        } catch (Throwable $e) {
            $notes[] = "[{$name}] não consegui ler o PDF: " . $e->getMessage();
            return '';
        }

        if ($text === '') {
            $notes[] = "[{$name}] PDF sem texto extraível (pode ser imagem/scanned)";
        }
        return $text;
    }

    private static function safeName(string $name): string
    {
        $name = trim(str_replace(['\\', '/'], '_', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = mb_substr($name, 0, 120);
        return trim($name);
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o permitido pelo servidor.',
            UPLOAD_ERR_PARTIAL => 'Envio incompleto — tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo recebido.',
            default => 'Falha no upload do arquivo (código ' . $error . ').',
        };
    }
}
