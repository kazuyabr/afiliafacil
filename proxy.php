<?php

$url = $_GET['url'] ?? '';

if (empty($url) || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    die('URL invalida');
}

$ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
$isMedia = in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'mp3', 'm4a', 'ogg', 'ogv', 'wav', 'aac']);
$isCss = ($ext === 'css');

$range = $_SERVER['HTTP_RANGE'] ?? '';

$mimeTypes = [
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
    'otf' => 'font/otf',
    'css' => 'text/css',
    'js' => 'application/javascript',
    'mjs' => 'application/javascript',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'mp4' => 'video/mp4',
    'm4v' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
    'ogv' => 'video/ogg',
    'wav' => 'audio/wav',
    'aac' => 'audio/aac',
    'json' => 'application/json',
];

if ($isMedia) {
    http_response_code(!empty($range) ? 206 : 200);
    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Range, Accept-Ranges, Content-Length');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
}

$responseHeaders = [];
$ch = curl_init();
$opts = [
    CURLOPT_URL => $url,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer: ' . (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/'),
    ],
    CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders, $isMedia) {
        $pos = strpos($header, ':');
        if ($pos !== false) {
            $name = strtolower(trim(substr($header, 0, $pos)));
            $value = trim(substr($header, $pos + 1));
            $responseHeaders[$name] = $value;

            if ($isMedia && !headers_sent()) {
                if ($name === 'content-range') {
                    header('Content-Range: ' . $value);
                } elseif ($name === 'content-length') {
                    header('Content-Length: ' . $value);
                }
            }
        }
        return strlen($header);
    },
];

if (!empty($range)) {
    $opts[CURLOPT_RANGE] = str_replace('bytes=', '', $range);
}

if ($isMedia) {
    $opts[CURLOPT_TIMEOUT] = 3600;
    $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) {
        echo $chunk;
        @ob_flush();
        @flush();
        return strlen($chunk);
    };
} else {
    $opts[CURLOPT_RETURNTRANSFER] = true;
    $opts[CURLOPT_TIMEOUT] = 30;
}

curl_setopt_array($ch, $opts);
$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode >= 400 || ($content === false && !$isMedia)) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    die('Erro ao buscar recurso: ' . ($curlError ?: ('HTTP ' . $httpCode)));
}

if ($isCss && is_string($content)) {
    $cssDir = rtrim(parse_url($url, PHP_URL_PATH), '/');
    $cssDir = substr($cssDir, 0, strrpos($cssDir, '/'));
    $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $cssDir . '/';

    $cssOriginal = $content;
    $content = @preg_replace_callback('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', function ($m) use ($baseUrl) {
        $resourceUrl = $m[1];
        if (strpos($resourceUrl, 'data:') === 0) return $m[0];
        if (strpos($resourceUrl, '//') === 0) $resourceUrl = 'https:' . $resourceUrl;
        if (strpos($resourceUrl, 'http') !== 0) $resourceUrl = rtrim($baseUrl, '/') . '/' . ltrim($resourceUrl, '/');
        $proxied = '/proxy.php?url=' . urlencode($resourceUrl);
        return 'url(' . $proxied . ')';
    }, $content);
    if ($content === null) $content = $cssOriginal;

    $contentType = 'text/css';
}

if (!$isMedia) {
    $finalStatus = ($httpCode === 206 || (!empty($range) && $httpCode === 200)) ? 206 : 200;
    http_response_code($finalStatus);

    header('Content-Type: ' . ($contentType ?: ($mimeTypes[$ext] ?? 'application/octet-stream')));
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Range, Accept-Ranges, Content-Length');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');

    if (!empty($responseHeaders['content-range'])) {
        header('Content-Range: ' . $responseHeaders['content-range']);
    }
    if (!empty($responseHeaders['content-length'])) {
        header('Content-Length: ' . $responseHeaders['content-length']);
    }

    echo $content;
}

exit;
