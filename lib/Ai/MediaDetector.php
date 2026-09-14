<?php

class MediaDetector
{
    private const MEDIA_EXTENSIONS = ['mp4', 'webm', 'mp3', 'm4a', 'wav', 'ogg', 'aac', 'mov', 'm3u8'];

    public static function detect(string $url): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'URL inválida.'];
        }

        if (self::isMediaUrl($url)) {
            return ['success' => true, 'media_url' => $url, 'kind' => 'direct', 'page_url' => $url];
        }

        $html = self::fetch($url);
        if ($html === null) {
            return ['success' => false, 'error' => 'Não foi possível carregar a página (timeout ou bloqueio).'];
        }

        $candidates = [];

        if (preg_match_all('/<(?:video|audio|source)[^>]+src=(["\'])([^"\']+)\1/i', $html, $matches)) {
            foreach ($matches[2] as $src) {
                $absolute = self::absolute($src, $url);
                if ($absolute !== '') $candidates[] = $absolute;
            }
        }

        if (preg_match_all('/<meta[^>]+property=(["\'])og:video(?::secure_url|:url)?\1[^>]+content=(["\'])([^"\']+)\2/i', $html, $matches)) {
            foreach ($matches[3] as $src) {
                $candidates[] = $src;
            }
        }
        if (preg_match_all('/<meta[^>]+content=(["\'])([^"\']+)\1[^>]+property=(["\'])og:video(?::secure_url|:url)?\3/i', $html, $matches)) {
            foreach ($matches[2] as $src) {
                $candidates[] = $src;
            }
        }

        if (preg_match_all('#https?://[^\s"\'<>]+\.(?:' . implode('|', self::MEDIA_EXTENSIONS) . ')(?:\?[^\s"\'<>]*)?#i', $html, $matches)) {
            foreach ($matches[0] as $src) {
                $candidates[] = $src;
            }
        }

        $embeds = [];
        if (preg_match_all('#(?:youtube\.com/(?:embed/|watch\?v=)|youtu\.be/|vimeo\.com/)([A-Za-z0-9_\-]+)#i', $html, $matches)) {
            foreach (array_unique($matches[0]) as $embed) {
                $embeds[] = $embed;
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));

        if (!empty($candidates)) {
            return ['success' => true, 'media_url' => $candidates[0], 'candidates' => array_slice($candidates, 0, 8), 'kind' => 'page', 'page_url' => $url, 'embeds' => $embeds];
        }

        if (!empty($embeds)) {
            return ['success' => false, 'error' => 'A página usa player incorporado (' . $embeds[0] . '). Baixe o vídeo/áudio ou informe a URL direta do arquivo de mídia.', 'embeds' => $embeds, 'page_url' => $url];
        }

        return ['success' => false, 'error' => 'Nenhuma mídia detectada na página. Informe a URL direta do arquivo (mp4/mp3/m3u8).'];
    }

    public static function isMediaUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach (self::MEDIA_EXTENSIONS as $ext) {
            if (str_ends_with($path, '.' . $ext)) return true;
        }
        return false;
    }

    private static function absolute(string $src, string $base): string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'data:')) return '';
        if (str_starts_with($src, '//')) return 'https:' . $src;
        if (preg_match('#^https?://#i', $src)) return $src;

        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($src, '/')) return $origin . $src;

        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        return $origin . $dir . '/' . $src;
    }

    private static function fetch(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $status >= 400) return null;
        return $html;
    }
}
