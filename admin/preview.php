<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AssetProcessor.php';

Auth::requireAuth();

$pm = new PageManager();
$id = (int)($_GET['id'] ?? 0);
$page = $pm->get($id);

if (!$page || empty($page['html'])) {
    die('Página não encontrada');
}

$sourceDomain = $page['source_domain'] ?? '';
$html = AssetProcessor::rewriteForPreview($page['html'], $sourceDomain);

header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Content-Type-Options: nosniff');
echo $html;
