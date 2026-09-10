<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Cloner.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Crypto.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = Auth::user();
if (!Plans::hasFeature($user['plan'], 'clone')) {
    http_response_code(403);
    echo json_encode(['error' => 'Seu plano atual não permite clonar páginas. Faça upgrade em "Meu Plano".']);
    exit;
}

$pm = new PageManager();
$plan = $user['plan'];
if (Plans::maxPages($plan) !== -1) {
    $count = $pm->countByUser($user['id']);
    if ($count >= Plans::maxPages($plan)) {
        http_response_code(403);
        echo json_encode(['error' => 'Você atingiu o limite de ' . Plans::maxPages($plan) . ' página(s) do plano ' . Plans::planName($plan) . '. Faça upgrade para continuar.']);
        exit;
    }
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'clone_url':
        cloneByUrl();
        break;
    case 'clone_html':
        cloneByHtml();
        break;
    case 'reclone':
        reclonePage();
        break;
    default:
        echo json_encode(['error' => 'Ação inválida']);
}

function reclonePage(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $userId = (int)(Auth::user()['id'] ?? 0);

    $pm = new PageManager();
    $page = $pm->get($id);
    if (!$page) {
        echo json_encode(['error' => 'Página não encontrada']);
        return;
    }
    if ((int)$page['user_id'] !== $userId && !Auth::isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem acesso a esta página']);
        return;
    }
    if (empty($page['source_domain'])) {
        echo json_encode(['error' => 'Página sem domínio de origem para re-clonar']);
        return;
    }

    $sourceUrl = 'https://' . $page['source_domain'] . '/';
    $cloner = new Cloner();
    $fetchResult = $cloner->fetchUrl($sourceUrl);
    if (!$fetchResult['success']) {
        echo json_encode(['error' => 'Não foi possível buscar a origem: ' . ($fetchResult['error'] ?? 'erro')]);
        return;
    }

    $storageConfig = loadUserStorage($userId);
    $result = $cloner->process($fetchResult['html'], $page['affiliate_link'] ?? '', 'url', $storageConfig, 'clones/' . $id);

    $revDir = Config::getPagesDir() . '/' . $id . '/revisions';
    if (!is_dir($revDir)) mkdir($revDir, 0777, true);
    file_put_contents($revDir . '/' . date('Ymd_His') . '.html', $page['html'] ?? '');

    $pm->update($id, [
        'html' => $result['html'],
        'failed_assets' => $result['failed_assets'] ?? [],
        'cloner_version' => $result['cloner_version'] ?? '',
    ]);

    echo json_encode([
        'success' => true,
        'id' => $id,
        'failed_assets' => count($result['failed_assets'] ?? []),
        'validation' => $result['validation'] ?? [],
        'processed_size' => $result['processed_size'] ?? 0,
    ]);
}

function cloneByUrl(): void
{
    $sourceUrl = trim($_POST['source_url'] ?? '');
    $affiliateLink = trim($_POST['affiliate_link'] ?? '');
    $pageName = trim($_POST['page_name'] ?? '');

    if (empty($sourceUrl)) {
        echo json_encode(['error' => 'URL é obrigatória']);
        return;
    }

    if (empty($affiliateLink)) {
        echo json_encode(['error' => 'Link de afiliado é obrigatório']);
        return;
    }

    if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'URL inválida']);
        return;
    }

    $cloner = new Cloner();
    $fetchResult = $cloner->fetchUrl($sourceUrl);

    if (!$fetchResult['success']) {
        echo json_encode(['error' => 'Não foi possível buscar a URL: ' . ($fetchResult['error'] ?? 'Erro desconhecido')]);
        return;
    }

    processAndSave($fetchResult['html'], $affiliateLink, $sourceUrl, $pageName);
}

function cloneByHtml(): void
{
    $html = trim($_POST['source_html'] ?? '');
    $affiliateLink = trim($_POST['affiliate_link'] ?? '');
    $pageName = trim($_POST['page_name'] ?? '');

    if (empty($html)) {
        echo json_encode(['error' => 'HTML é obrigatório']);
        return;
    }

    if (strlen($html) < 200) {
        echo json_encode(['error' => 'HTML muito curto. Cole o código-fonte completo.']);
        return;
    }

    if (empty($affiliateLink)) {
        echo json_encode(['error' => 'Link de afiliado é obrigatório']);
        return;
    }

    $sourceDomain = '';
    if (preg_match('#https?://([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})#', $html, $m)) {
        $sourceDomain = $m[1];
    }

    processAndSave($html, $affiliateLink, $sourceDomain, $pageName);
}

function processAndSave(string $html, string $affiliateLink, string $sourceUrlOrDomain, string $pageName): void
{
    $userId = (int)(Auth::user()['id'] ?? 1);
    $storageConfig = loadUserStorage($userId);
    $pageId = time() + random_int(1, 9999);

    $cloner = new Cloner();
    $result = $cloner->process($html, $affiliateLink, 'url', $storageConfig, 'clones/' . $pageId);

    $sourceDomain = $result['source_domain'] ?? '';
    if (empty($sourceDomain) && !filter_var($sourceUrlOrDomain, FILTER_VALIDATE_URL)) {
        $sourceDomain = $sourceUrlOrDomain;
    }

    if (empty($pageName)) {
        $pageName = $sourceDomain ?: 'Página Clonada ' . date('d/m/Y H:i');
    }

    $pm = new PageManager();
    $page = $pm->create([
        'id' => $pageId,
        'user_id' => $userId,
        'name' => $pageName,
        'type' => 'clone',
        'html' => $result['html'],
        'source_domain' => $sourceDomain,
        'failed_assets' => $result['failed_assets'] ?? [],
        'cloner_version' => $result['cloner_version'] ?? '',
        'affiliate_link' => $affiliateLink,
        'status' => 'active',
    ]);

    echo json_encode([
        'success' => true,
        'page' => [
            'id' => $page['id'],
            'name' => $page['name'],
            'slug' => $page['slug'],
            'type' => $page['type'],
            'source_domain' => $page['source_domain'],
        ],
        'ctas_found' => count($result['ctas'] ?? []),
        'original_size' => $result['original_size'] ?? 0,
        'processed_size' => $result['processed_size'] ?? 0,
        'validation' => $result['validation'] ?? [],
    ]);
}

function loadUserStorage(int $userId): ?array
{
    if (!Database::available()) return null;

    try {
        $config = \AfiliaFacil\Models\StorageConfig::where('user_id', $userId)->first();
        if (!$config || !$config->enabled) return null;

        $secret = Crypto::decrypt($config->secret_encrypted ?? '') ?? '';
        if ($secret === '') return null;

        return [
            'enabled' => (bool)$config->enabled,
            'account_id' => $config->account_id,
            'access_key' => $config->access_key,
            'secret_key' => $secret,
            'bucket' => $config->bucket,
            'public_url' => $config->public_url,
            'media_mode' => $config->media_mode ?: 'base64',
        ];
    } catch (Throwable $e) {
        return null;
    }
}
