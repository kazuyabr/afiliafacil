<?php

/**
 * Download e variação de criativos (imagens de anúncios).
 *
 * - download(): baixa a imagem remota (com proteção anti-SSRF) e devolve o binário.
 * - vary(): aplica transformações via GD (espelho + resize + re-encode) que mudam
 *   o hash do arquivo — reutilizar o criativo idêntico do concorrente pode vincular
 *   contas (detecção de duplicadas pelo hash/ID da entidade). Sempre varie antes de anunciar.
 */
class CreativeStudio
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * Baixa uma imagem remota com proteção anti-SSRF.
     * @return array{success:bool, data?:string, mime?:string, ext?:string, width?:int, height?:int, error?:string}
     */
    public static function download(string $url): array
    {
        $binary = self::fetch($url);
        if ($binary === null) {
            return ['success' => false, 'error' => 'Não foi possível baixar a imagem (URL bloqueada, inacessível ou acima de 8MB).'];
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || empty($info['mime'])) {
            return ['success' => false, 'error' => 'A URL não retornou uma imagem válida.'];
        }

        $mime = $info['mime'];
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => '',
        };
        if ($ext === '') {
            return ['success' => false, 'error' => 'Formato não suportado (' . $mime . '). Use JPG, PNG, WEBP ou GIF.'];
        }

        return [
            'success' => true,
            'data' => $binary,
            'mime' => $mime,
            'ext' => $ext,
            'width' => (int)$info[0],
            'height' => (int)$info[1],
        ];
    }

    /**
     * Gera uma variação da imagem (muda o hash do arquivo).
     * @return array{success:bool, data?:string, mime?:string, ext?:string, width?:int, height?:int, error?:string}
     */
    public static function vary(string $url): array
    {
        if (!self::available()) {
            return ['success' => false, 'error' => 'Variação indisponível: extensão GD não instalada no servidor.'];
        }

        $dl = self::download($url);
        if (empty($dl['success'])) return $dl;

        $src = @imagecreatefromstring($dl['data']);
        if ($src === false) {
            return ['success' => false, 'error' => 'Falha ao processar a imagem.'];
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Espelha horizontalmente (muda o hash sem prejudicar a leitura na maioria dos criativos)
        if (function_exists('imageflip')) imageflip($src, IMG_FLIP_HORIZONTAL);

        // Resize sutil (98%) + re-encode: quebra o hash exato e remove metadados
        $nw = max(1, (int)round($w * 0.98));
        $nh = max(1, (int)round($h * 0.98));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 88);
        $binary = (string)ob_get_clean();
        imagedestroy($dst);

        if ($binary === '') {
            return ['success' => false, 'error' => 'Falha ao gerar a variação.'];
        }

        return [
            'success' => true,
            'data' => $binary,
            'mime' => 'image/jpeg',
            'ext' => 'jpg',
            'width' => $nw,
            'height' => $nh,
        ];
    }

    public static function filename(int $creativeId, string $kind, string $ext): string
    {
        return 'criativo-' . $creativeId . '-' . ($kind === 'vary' ? 'variacao' : 'original') . '.' . $ext;
    }

    /**
     * Fetch com proteção anti-SSRF: só http/https, sem hosts locais/privados.
     */
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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_BUFFERSIZE => 128 * 1024,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) {
                return $downloaded > self::MAX_BYTES ? 1 : 0;
            },
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $status >= 400) return null;
        if (strlen($data) > self::MAX_BYTES) return null;
        return $data;
    }

    private static function isPublicHost(string $host): bool
    {
        if ($host === 'localhost') return false;

        // IP literal: recusa faixas privadas/reservadas
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        if (!str_contains($host, '.')) return false;

        // Resolve e confere o IP real (evita DNS rebinding simples)
        $ip = gethostbyname($host);
        if ($ip === $host) return false;
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
