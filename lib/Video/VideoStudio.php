<?php

require_once __DIR__ . '/../Config.php';

/**
 * Ferramentas de vídeo via ffmpeg (cortar, capa, legenda queimada, variação).
 *
 * Branches: cada operação gera um arquivo derivado com sufixo
 * (-corte-A-B, -capa-Ss, -legenda, -varN) preservando o original —
 * ver docs/videos.md para a estratégia de branches (teste A/B).
 */
class VideoStudio
{
    public const MAX_IMPORT_BYTES = 200 * 1024 * 1024;
    public const ALLOWED_EXT = ['mp4', 'mov', 'webm', 'mkv', 'avi'];
    private const TIMEOUT = 280;

    public static function available(): bool
    {
        return self::bin('ffmpeg') !== '' && self::bin('ffprobe') !== '';
    }

    public static function dir(): string
    {
        $dir = rtrim(Config::getUploadsDir(), '/') . '/videos';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir;
    }

    /**
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function storeUpload(array $file): array
    {
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_file($file['tmp_name'] ?? '')) {
            return ['success' => false, 'error' => 'Upload inválido.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? 'video.mp4', PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['success' => false, 'error' => 'Formato não suportado. Use MP4, MOV, WEBM, MKV ou AVI.'];
        }
        $name = self::uniqueName(self::safeBase($file['name']), 'mp4');
        if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $name)) {
            return ['success' => false, 'error' => 'Falha ao salvar o upload.'];
        }
        $info = self::info(self::dir() . '/' . $name);
        if (empty($info['is_video'])) {
            @unlink(self::dir() . '/' . $name);
            return ['success' => false, 'error' => 'O arquivo não é um vídeo válido.'];
        }
        return ['success' => true, 'name' => $name];
    }

    /**
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function importUrl(string $url): array
    {
        $binary = self::fetch($url);
        if ($binary === null) {
            return ['success' => false, 'error' => 'Não foi possível baixar o vídeo (URL bloqueada, inacessível ou acima de 200MB).'];
        }
        $path = parse_url(trim($url), PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) $ext = 'mp4';
        $name = self::uniqueName(self::safeBase(basename($path) ?: 'video') . '-import', 'mp4');
        if (file_put_contents(self::dir() . '/' . $name, $binary) === false) {
            return ['success' => false, 'error' => 'Falha ao salvar o vídeo.'];
        }
        $info = self::info(self::dir() . '/' . $name);
        if (empty($info['is_video'])) {
            @unlink(self::dir() . '/' . $name);
            return ['success' => false, 'error' => 'A URL não retornou um vídeo válido.'];
        }
        return ['success' => true, 'name' => $name];
    }

    /**
     * @return array{is_video:bool, duration:float, width:int, height:int, size:int}
     */
    public static function info(string $path): array
    {
        $out = ['is_video' => false, 'duration' => 0.0, 'width' => 0, 'height' => 0, 'size' => is_file($path) ? filesize($path) : 0];
        if (!is_file($path)) return $out;
        $ffprobe = self::bin('ffprobe');
        if ($ffprobe === '') return $out;
        $r = self::run($ffprobe . ' -v error -select_streams v:0 -show_entries stream=width,height -show_entries format=duration -of json ' . escapeshellarg($path));
        if ($r['code'] !== 0) return $out;
        $json = json_decode($r['output'], true);
        if (!is_array($json) || empty($json['streams'])) return $out;
        $out['is_video'] = true;
        $out['width'] = (int)($json['streams'][0]['width'] ?? 0);
        $out['height'] = (int)($json['streams'][0]['height'] ?? 0);
        $out['duration'] = (float)($json['format']['duration'] ?? 0);
        return $out;
    }

    /**
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function cut(string $name, float $start, float $end): array
    {
        $src = self::path($name);
        if ($src === null) return ['success' => false, 'error' => 'Vídeo não encontrado.'];
        if ($start < 0 || $end <= $start) return ['success' => false, 'error' => 'Intervalo inválido (início >= 0 e fim > início, em segundos).'];
        $info = self::info($src);
        if ($info['duration'] > 0 && $start >= $info['duration']) {
            return ['success' => false, 'error' => 'Início além da duração do vídeo (' . (int)$info['duration'] . 's).'];
        }
        $dest = self::uniqueName(self::branchBase($name) . '-corte-' . self::num($start) . '-' . self::num($end), 'mp4');
        $r = self::run(self::ffmpeg() . ' -y -ss ' . escapeshellarg((string)$start) . ' -to ' . escapeshellarg((string)$end) . ' -i ' . escapeshellarg($src) . ' -c copy ' . escapeshellarg(self::dir() . '/' . $dest));
        if ($r['code'] !== 0 || !is_file(self::dir() . '/' . $dest)) {
            return ['success' => false, 'error' => 'Falha ao cortar (o formato pode exigir re-encode).'];
        }
        return ['success' => true, 'name' => $dest];
    }

    /**
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function cover(string $name, float $second): array
    {
        $src = self::path($name);
        if ($src === null) return ['success' => false, 'error' => 'Vídeo não encontrado.'];
        if ($second < 0) return ['success' => false, 'error' => 'Segundo inválido.'];
        $dest = self::uniqueName(self::branchBase($name) . '-capa-' . self::num($second) . 's', 'jpg');
        $r = self::run(self::ffmpeg() . ' -y -ss ' . escapeshellarg((string)$second) . ' -i ' . escapeshellarg($src) . ' -frames:v 1 -q:v 3 ' . escapeshellarg(self::dir() . '/' . $dest));
        if ($r['code'] !== 0 || !is_file(self::dir() . '/' . $dest)) {
            return ['success' => false, 'error' => 'Falha ao extrair a capa.'];
        }
        return ['success' => true, 'name' => $dest];
    }

    /**
     * Queima legenda .srt no vídeo (ideal p/ anúncios: plataformas ignoram sidecar).
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function subtitle(string $name, string $srtPath): array
    {
        $src = self::path($name);
        if ($src === null) return ['success' => false, 'error' => 'Vídeo não encontrado.'];
        if (!is_file($srtPath)) return ['success' => false, 'error' => 'Arquivo .srt inválido.'];
        $dest = self::uniqueName(self::branchBase($name) . '-legenda', 'mp4');
        // Estilo legível em mobile: fonte 20, contorno, caixa semi-transparente
        $style = "FontSize=20,PrimaryColour=&H00FFFFFF,OutlineColour=&H80000000,BorderStyle=3,Outline=1,Shadow=0,MarginV=25";
        $audioArgs = self::hasAudio($src) ? '-c:a copy' : '-an';
        $r = self::run(self::ffmpeg() . ' -y -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg('subtitles=' . self::escapeFilterPath($srtPath) . ":force_style='" . $style . "'") . ' ' . $audioArgs . ' ' . escapeshellarg(self::dir() . '/' . $dest));
        if ($r['code'] !== 0 || !is_file(self::dir() . '/' . $dest)) {
            return ['success' => false, 'error' => 'Falha ao queimar a legenda (verifique o .srt).'];
        }
        return ['success' => true, 'name' => $dest];
    }

    /**
     * Variação anti-duplicada: espelha + resize sutil + re-encode (muda o hash).
     * @return array{success:bool, name?:string, error?:string}
     */
    public static function vary(string $name): array
    {
        $src = self::path($name);
        if ($src === null) return ['success' => false, 'error' => 'Vídeo não encontrado.'];
        $base = self::branchBase($name);
        $n = 1;
        do {
            $dest = $base . '-var' . $n . '.mp4';
            $n++;
        } while (is_file(self::dir() . '/' . $dest) && $n < 100);
        // Audios: preserva se existir; videos sem faixa de audio usam -an (senao o ffmpeg falha)
        $audioArgs = self::hasAudio($src) ? '-c:a aac' : '-an';
        // x264 exige dimensoes pares: trunc(.../2)*2 apos o resize de 98%
        $r = self::run(self::ffmpeg() . ' -y -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg('hflip,scale=trunc(iw*0.98/2)*2:trunc(ih*0.98/2)*2') . ' -c:v libx264 -crf 23 -preset veryfast ' . $audioArgs . ' ' . escapeshellarg(self::dir() . '/' . $dest));
        if ($r['code'] !== 0 || !is_file(self::dir() . '/' . $dest)) {
            return ['success' => false, 'error' => 'Falha ao gerar a variação.'];
        }
        return ['success' => true, 'name' => $dest];
    }

    public static function list(): array
    {
        $dir = self::dir();
        $files = glob($dir . '/*.{mp4,jpg,jpeg,png}', GLOB_BRACE) ?: [];
        $items = [];
        foreach ($files as $path) {
            $name = basename($path);
            $isVideo = (bool)preg_match('/\.mp4$/i', $name);
            $item = ['name' => $name, 'size' => filesize($path), 'mtime' => filemtime($path), 'kind' => $isVideo ? 'video' : 'image'];
            if ($isVideo) {
                $info = self::info($path);
                $item['duration'] = $info['duration'];
                $item['width'] = $info['width'];
                $item['height'] = $info['height'];
            }
            $items[] = $item;
        }
        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return array_slice($items, 0, 100);
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);
        if ($path === null) return false;
        return @unlink($path);
    }

    /** Caminho absoluto seguro dentro de uploads/videos (null se inválido). */
    public static function path(string $name): ?string
    {
        $base = basename(trim($name));
        if ($base === '' || $base !== trim($name)) return null;
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*\.(mp4|jpg|jpeg|png)$/i', $base)) return null;
        $full = self::dir() . '/' . $base;
        return is_file($full) ? $full : null;
    }

    private static function branchBase(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        // Remove sufixos de branch anteriores para nao empilhar (corte-0-30-var1 -> corte-0-30)
        $base = preg_replace('/-(corte-[0-9]+-[0-9]+|capa-[0-9]+s|legenda|var[0-9]+|import)$/', '', $base) ?? $base;
        return self::safeBase($base);
    }

    private static function safeBase(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-z0-9-_]/i', '-', strtolower(trim($base))) ?? 'video';
        $base = trim(preg_replace('/-+/', '-', $base) ?? '', '-');
        return $base !== '' ? mb_substr($base, 0, 60) : 'video';
    }

    private static function uniqueName(string $base, string $ext): string
    {
        $candidate = $base . '.' . $ext;
        $n = 2;
        while (is_file(self::dir() . '/' . $candidate) && $n < 1000) {
            $candidate = $base . '-' . $n . '.' . $ext;
            $n++;
        }
        return $candidate;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function bin(string $tool): string
    {
        $path = trim((string)shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null'));
        return ($path !== '' && is_executable($path)) ? $path : '';
    }

    private static function ffmpeg(): string
    {
        return self::bin('ffmpeg');
    }

    /** Executa comando com timeout rígido; nunca interpola input sem escapeshellarg. */
    private static function run(string $cmd): array
    {
        $out = [];
        $code = -1;
        exec('timeout ' . self::TIMEOUT . ' ' . $cmd . ' 2>&1', $out, $code);
        return ['code' => $code, 'output' => implode("\n", $out)];
    }

    /** O arquivo tem faixa de áudio? (define -c:a vs -an nos re-encodes) */
    private static function hasAudio(string $path): bool
    {
        $ffprobe = self::bin('ffprobe');
        if ($ffprobe === '') return true;
        $r = self::run($ffprobe . ' -v error -select_streams a:0 -show_entries stream=index -of csv=p=0 ' . escapeshellarg($path));
        return $r['code'] === 0 && trim($r['output']) !== '';
    }

    /** Escapa path p/ filtro ffmpeg (subtitles=): dois-pontos e aspas. */
    private static function escapeFilterPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = str_replace(':', '\\:', $path);
        return "'" . str_replace("'", "'\\''", $path) . "'";
    }

    private static function fetch(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        $parts = parse_url($url);
        if (!is_array($parts)) return null;
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') return null;
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || !self::isPublicHost($host)) return null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AfiliaFacil/1.0',
            CURLOPT_BUFFERSIZE => 256 * 1024,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) {
                return $downloaded > self::MAX_IMPORT_BYTES ? 1 : 0;
            },
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $status >= 400) return null;
        if (strlen($data) > self::MAX_IMPORT_BYTES) return null;
        return $data;
    }

    private static function isPublicHost(string $host): bool
    {
        if ($host === 'localhost') return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        if (!str_contains($host, '.')) return false;
        $ip = gethostbyname($host);
        if ($ip === $host) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
