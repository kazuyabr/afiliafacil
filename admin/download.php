<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/ZipBuilder.php';

Auth::requireAuth();

$pm = new PageManager();
$id = (int)($_GET['id'] ?? 0);
$page = $pm->get($id);

if (!$page || empty($page['html'])) {
    die('Página não encontrada');
}

$zipBuilder = new ZipBuilder();
try {
    $zipPath = $zipBuilder->buildHtmlZip($page['html'], $page['id'], [
        'source_domain' => $page['source_domain'] ?? '',
        'affiliate_link' => $page['affiliate_link'] ?? '',
        'name' => $page['name'],
    ]);
} catch (Throwable $e) {
    die('Erro ao gerar ZIP: ' . $e->getMessage());
}

if (!file_exists($zipPath)) {
    die('Falha ao criar arquivo');
}

if (ob_get_level()) ob_end_clean();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $page['slug'] . '.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache');
readfile($zipPath);
@unlink($zipPath);
exit;
