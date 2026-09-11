<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

$staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'txt', 'map'];
$ext = pathinfo($path, PATHINFO_EXTENSION);
if (in_array(strtolower($ext), $staticExtensions)) {
    return false;
}

$routes = [
    '/' => '/landing.php',
    '/index.php' => '/landing.php',
    '/login' => '/login.php',
    '/register' => '/register.php',
    '/planos' => '/planos.php',
    '/proxy.php' => '/proxy.php',
    '/webhooks/stripe' => '/webhooks/stripe.php',
    '/admin/' => '/admin/index.php',
    '/admin/index.php' => '/admin/index.php',
    '/admin/pages.php' => '/admin/pages.php',
    '/admin/clone.php' => '/admin/clone.php',
    '/admin/pressel.php' => '/admin/pressel.php',
    '/admin/video.php' => '/admin/video.php',
    '/admin/pixel.php' => '/admin/pixel.php',
    '/admin/backredirect.php' => '/admin/backredirect.php',
    '/admin/cookie.php' => '/admin/cookie.php',
    '/admin/domains.php' => '/admin/domains.php',
    '/admin/integrations.php' => '/admin/integrations.php',
    '/admin/settings.php' => '/admin/settings.php',
    '/admin/plan.php' => '/admin/plan.php',
    '/admin/pay.php' => '/admin/pay.php',
    '/admin/users.php' => '/admin/users.php',
    '/admin/roles.php' => '/admin/roles.php',
    '/admin/pricing.php' => '/admin/pricing.php',
    '/admin/storage.php' => '/admin/storage.php',
    '/admin/audit.php' => '/admin/audit.php',
    '/admin/adspy.php' => '/admin/adspy.php',
    '/admin/ai-settings.php' => '/admin/ai-settings.php',
    '/admin/preview.php' => '/admin/preview.php',
    '/admin/download.php' => '/admin/download.php',
    '/admin/editor.php' => '/admin/editor.php',
    '/admin/logout.php' => '/admin/logout.php',
    '/admin/api/editor.php' => '/admin/api/editor.php',
    '/admin/api/users.php' => '/admin/api/users.php',
    '/admin/api/roles.php' => '/admin/api/roles.php',
    '/admin/api/pricing.php' => '/admin/api/pricing.php',
    '/admin/api/storage.php' => '/admin/api/storage.php',
    '/admin/api/adspy.php' => '/admin/api/adspy.php',
    '/admin/api/ai-settings.php' => '/admin/api/ai-settings.php',
    '/admin/api/clone.php' => '/admin/api/clone.php',
    '/admin/api/pages.php' => '/admin/api/pages.php',
    '/admin/api/checkout.php' => '/admin/api/checkout.php',
];

function matchRoute(array $routes, string $path): ?string
{
    if (isset($routes[$path])) return $routes[$path];
    $normalized = rtrim($path, '/') . '/';
    if (isset($routes[$normalized])) return $routes[$normalized];
    $noSlash = rtrim($path, '/');
    if (isset($routes[$noSlash])) return $routes[$noSlash];
    if (isset($routes[$noSlash . '/'])) return $routes[$noSlash . '/'];
    return null;
}

$file = matchRoute($routes, $path);
if ($file !== null) {
    require __DIR__ . $file;
    return true;
}

http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404</title></head><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f8f9fa;"><div style="text-align:center;"><h1 style="font-size:4rem;margin:0;">404</h1><p>Página não encontrada</p><a href="/">Voltar ao início</a></div></body></html>';
