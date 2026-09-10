<?php

require_once __DIR__ . '/R2Storage.php';
require_once __DIR__ . '/SafePcre.php';
require_once __DIR__ . '/AssetProcessor.php';

class MediaOptimizer
{
    public static function optimize(string $html, R2Storage $r2, string $prefix): array
    {
        $count = 0;
        $saved = 0;
        $prefix = rtrim($prefix, '/') . '/';

        $upload = function (string $mime, string $b64) use ($r2, $prefix, &$count, &$saved): ?string {
            $data = base64_decode($b64);
            if ($data === false || $data === '') return null;

            $ext = self::extFromMime($mime);
            $key = $prefix . md5($data) . '.' . $ext;
            $url = $r2->upload($key, $data, $mime);
            if ($url === null) return null;

            $count++;
            $saved += strlen($b64) - strlen($url);
            return $url;
        };

        $html = SafePcre::replaceCallback('/url\(\s*([\'"]?)data:([^;]+);base64,([A-Za-z0-9+\/=]+)\1\s*\)/i', function ($m) use ($upload) {
            $url = $upload($m[2], $m[3]);
            return $url ? "url('{$url}')" : $m[0];
        }, $html);

        $html = SafePcre::replaceCallback('/\b(src|poster|data-bg|data-background|data-src|data-original|data-lazy|data-original-src|data-lazy-src)=(["\'])data:([^;]+);base64,([A-Za-z0-9+\/=]+)\2/i', function ($m) use ($upload) {
            $url = $upload($m[3], $m[4]);
            return $url ? $m[1] . '=' . $m[2] . $url . $m[2] : $m[0];
        }, $html);

        $html = SafePcre::replaceCallback('/\bsrcset=(["\'])([^"\']+)\1/i', function ($m) use ($upload) {
            $parts = AssetProcessor::splitSrcset($m[2]);
            $out = [];
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if (preg_match('/^data:([^;]+);base64,([A-Za-z0-9+\/=]+)(\s+\S+)?$/i', $trimmed, $pm)) {
                    $url = $upload($pm[1], $pm[2]);
                    if ($url !== null) {
                        $out[] = $url . ($pm[3] ?? '');
                        continue;
                    }
                }
                $out[] = $trimmed;
            }
            return 'srcset=' . $m[1] . implode(', ', $out) . $m[1];
        }, $html);

        return ['html' => $html, 'count' => $count, 'saved_bytes' => max(0, $saved)];
    }

    private static function extFromMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
            'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
            'image/svg+xml' => 'svg', 'image/x-icon' => 'ico',
            'font/woff' => 'woff', 'font/woff2' => 'woff2', 'font/ttf' => 'ttf',
            'application/vnd.ms-fontobject' => 'eot', 'font/otf' => 'otf',
            'video/mp4' => 'mp4', 'video/webm' => 'webm',
        ];
        return $map[strtolower(trim($mime))] ?? 'bin';
    }
}
