<?php
/**
 * Testes de aceite da Publicação Unificada (Fase 1 — conector + publicação).
 *
 * Determinístico: NÃO faz chamadas de rede externa (SocialHttp::$handler fake).
 * Cobre: catálogo de redes, criptografia/quota de conexões, validações do
 * composer, falha ISOLADA por rede, agendamento + processDue, quota mensal e
 * a API HTTP (401 / login / connections / página).
 *
 * Uso (dentro do container, a partir da raiz do app):
 *   docker exec afiliafacil php tests/social/social-test.php
 *
 * Exit 0 = tudo ok; 1 = há falhas.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Crypto.php';
require_once Config::getLibDir() . '/Social/SocialNetworks.php';
require_once Config::getLibDir() . '/Social/SocialHttp.php';
require_once Config::getLibDir() . '/Social/SocialConnections.php';
require_once Config::getLibDir() . '/Social/SocialQuota.php';
require_once Config::getLibDir() . '/Social/SocialPublisher.php';
require_once Config::getLibDir() . '/Social/SocialMetrics.php';

if (!Database::available()) {
    fwrite(STDERR, "Banco indisponível\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl indisponível\n");
    exit(2);
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = [];
$GLOBALS['__section'] = '';

function section(string $name): void
{
    $GLOBALS['__section'] = $name;
    echo "\n== {$name} ==\n";
}

function ok(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  [OK] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $GLOBALS['__fail'][] = $GLOBALS['__section'] . ' :: ' . $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function cleanup(int $adminId, int $trialId): void
{
    try {
        $posts = \AfiliaFacil\Models\SocialPost::whereIn('user_id', [$adminId, $trialId])
            ->where('caption', 'like', 'SOCIALTEST%')->pluck('id');
        if (count($posts)) {
            \AfiliaFacil\Models\SocialPostTarget::whereIn('post_id', $posts)->delete();
            \AfiliaFacil\Models\SocialPostMetric::whereIn('post_id', $posts)->delete();
            \AfiliaFacil\Models\SocialPost::whereIn('id', $posts)->delete();
        }
        \AfiliaFacil\Models\SocialConnection::where('user_id', $adminId)
            ->where('account_meta', 'like', '%"via":"test"%')->delete();
    } catch (Throwable $e) {
        echo "  (cleanup parcial: {$e->getMessage()})\n";
    }
}

$admin = \AfiliaFacil\Models\User::where('email', 'admin@afiliafacil.com')->first();
$trial = \AfiliaFacil\Models\User::where('email', 'demo.trial@afiliafacil.com')->first();
if (!$admin || !$trial) {
    fwrite(STDERR, "Contas de teste ausentes (rode bin/seed-demo.php)\n");
    exit(2);
}
$adminId = (int)$admin->id;
$trialId = (int)$trial->id;

cleanup($adminId, $trialId);
register_shutdown_function(function () use ($adminId, $trialId) {
    cleanup($adminId, $trialId);
});

// Fake HTTP: rotas das APIs sociais com sucesso, exceto threads (falha simulada)
SocialHttp::$handler = function (string $method, string $url, array $opts = []): array {
    $j = fn (array $data, int $status = 200) => ['status' => $status, 'body' => json_encode($data)];

    // --- metricas (GET) ---
    if (preg_match('#graph\.facebook\.com/v21\.0/fb_feed_1$#', $url)) {
        return $j(['id' => 'fb_feed_1',
            'likes' => ['summary' => ['total_count' => 12]],
            'comments' => ['summary' => ['total_count' => 3]],
            'shares' => ['count' => 4],
            'insights' => ['data' => [['name' => 'post_impressions', 'values' => [['value' => 340]]]]],
        ]);
    }
    if (str_contains($url, '/ig_pub_1/insights')) {
        return $j(['data' => [
            ['name' => 'likes', 'values' => [['value' => 7]]],
            ['name' => 'comments', 'values' => [['value' => 2]]],
            ['name' => 'impressions', 'values' => [['value' => 150]]],
            ['name' => 'reach', 'values' => [['value' => 120]]],
            ['name' => 'saved', 'values' => [['value' => 1]]],
        ]]);
    }
    if (str_contains($url, 'graph.threads.net') && str_contains($url, '/insights')) {
        return $j(['data' => [
            ['name' => 'likes', 'values' => [['value' => 3]]],
            ['name' => 'replies', 'values' => [['value' => 1]]],
            ['name' => 'reposts', 'values' => [['value' => 1]]],
            ['name' => 'quotes', 'values' => [['value' => 1]]],
            ['name' => 'impressions', 'values' => [['value' => 90]]],
        ]]);
    }
    if (str_contains($url, 'api.x.com/2/tweets/')) {
        return $j(['data' => ['id' => 'x_post_1',
            'public_metrics' => ['like_count' => 5, 'reply_count' => 2, 'retweet_count' => 1,
                'quote_count' => 1, 'impression_count' => 77]]]);
    }
    if (str_contains($url, 'open.tiktokapis.com/v2/video/query/')) {
        return $j(['data' => ['videos' => [['id' => 'tt_9',
            'like_count' => 10, 'comment_count' => 1, 'share_count' => 2, 'view_count' => 100]]]]);
    }

    // --- publicacao ---
    if (str_contains($url, 'graph.facebook.com') && str_contains($url, '/photos')) return $j(['id' => 'fb_photo_1']);
    if (str_contains($url, 'graph.facebook.com') && str_contains($url, '/feed')) return $j(['id' => 'fb_feed_1']);
    if (str_contains($url, 'graph.facebook.com') && str_ends_with($url, '/media')) return $j(['id' => 'ig_container_1']);
    if (str_contains($url, 'graph.facebook.com') && str_contains($url, '/media_publish')) return $j(['id' => 'ig_pub_1']);
    if (str_contains($url, 'graph.threads.net') && str_ends_with($url, '/threads')) return $j(['id' => 'th_container_1']);
    if (str_contains($url, 'graph.threads.net') && str_contains($url, '/threads_publish')) {
        return $j(['error' => ['message' => 'threads exploded']], 400);
    }
    if (str_contains($url, 'upload.x.com')) return $j(['media_id_string' => 'media_1']);
    if (str_contains($url, 'api.x.com/2/tweets')) return $j(['data' => ['id' => 'x_post_1']]);
    return $j(['error' => ['message' => 'rota fake desconhecida: ' . $url]], 500);
};

// ---------------------------------------------------------------- 1. catálogo
section('1. Catálogo de redes');
$meta = SocialNetworks::meta();
ok('5 redes no catálogo', count($meta) === 5 && array_keys($meta) === SocialNetworks::ALL);
ok('label facebook = Facebook', SocialNetworks::label('facebook') === 'Facebook');
ok('instagram exige mídia', $meta['instagram']['media_required'] === true);
ok('tiktok é vídeo + beta', $meta['tiktok']['media'] === 'video' && $meta['tiktok']['beta'] === true);
ok('custo X com link = 0.20', SocialNetworks::xCost('veja https://oferta.com') === 0.20);
ok('custo X sem link = 0.015', SocialNetworks::xCost('só texto') === 0.015);

// --------------------------------------------------- 2. conexões + cripto
section('2. Conexões (cripto + lista + limite)');
$before = SocialConnections::count($adminId);
$plain = 'plain-token-' . bin2hex(random_bytes(6));
$saved = SocialConnections::upsert($adminId, 'facebook', [
    'token' => $plain,
    'account_id' => 'page_1', 'account_name' => 'Página Teste',
    'meta' => ['page_id' => 'page_1', 'via' => 'test'],
]);
ok('upsert cria conexão', $saved && SocialConnections::count($adminId) === $before + 1);
$conn = SocialConnections::find($adminId, 'facebook');
ok('find devolve linha', $conn !== null && $conn->status === 'connected');
ok('token armazenado CRIPTOGRAFADO', $conn !== null && (string)$conn->access_token !== $plain
    && !str_contains((string)$conn->access_token, $plain));
ok('tokenFor descriptografa de volta', SocialConnections::tokenFor($conn) === $plain);
$list = SocialConnections::list($adminId);
ok('list expõe status/conta sem token', in_array('facebook', array_column($list, 'network'), true)
    && !array_key_exists('access_token', $list[0] ?? []));
$cc = SocialConnections::checkCanConnect($adminId, 'premium');
ok('premium ilimitado permite conectar', $cc['ok'] && $cc['max'] === -1);
$ccTrial = SocialConnections::checkCanConnect($trialId, 'trial');
ok('trial com 1 conexão no máximo (1/1)', $ccTrial['max'] === 1, 'max=' . $ccTrial['max']);

// ------------------------------------------- 3. quota mensal de publicações
section('3. Quota mensal de posts');
$pq = SocialQuota::check($adminId, 'premium');
ok('premium ilimitado', $pq['ok'] && $pq['max'] === -1);
$existingTrialPosts = SocialQuota::countMonth($trialId);
$need = 10 - $existingTrialPosts;
for ($i = 0; $i < $need; $i++) {
    \AfiliaFacil\Models\SocialPost::create([
        'user_id' => $trialId, 'caption' => 'SOCIALTEST quota ' . $i,
        'media_url' => '', 'media_kind' => '', 'status' => 'published',
        'scheduled_at' => null, 'source' => 'manual',
        'published_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
$pqFull = SocialQuota::check($trialId, 'trial');
ok('trial 10/10 bloqueia com mensagem clara', !$pqFull['ok']
    && str_contains((string)($pqFull['error'] ?? ''), 'Cota de publicações do mês atingida'),
    'error=' . ($pqFull['error'] ?? '(ausente)'));
$blocked = SocialPublisher::create($trialId, 'trial', [
    'caption' => 'SOCIALTEST não deve passar', 'networks' => ['facebook'],
]);
ok('create respeita quota (bloqueia antes de validar rede)', !$blocked['ok']
    && str_contains((string)($blocked['error'] ?? ''), 'Cota'), 'error=' . ($blocked['error'] ?? ''));

// ------------------------------------------------------ 4. validações create
section('4. Validações do composer');
$r = SocialPublisher::create($adminId, 'premium', ['caption' => 'SOCIALTEST x', 'networks' => []]);
ok('sem rede → erro claro', !$r['ok'] && str_contains((string)($r['error'] ?? ''), 'ao menos uma rede'));
$r = SocialPublisher::create($adminId, 'premium', ['caption' => '', 'networks' => ['facebook']]);
ok('sem legenda e sem mídia → erro', !$r['ok'] && str_contains((string)($r['error'] ?? ''), 'legenda'));
$r = SocialPublisher::create($adminId, 'premium', ['caption' => 'SOCIALTEST rede desconectada', 'networks' => ['threads']]);
ok('rede não conectada → "Conecte antes"', !$r['ok'] && str_contains((string)($r['error'] ?? ''), 'Conecte antes'),
    'error=' . ($r['error'] ?? ''));

// conexões auxiliares (bypass do probe — só linhas para o teste)
foreach ([
    'threads' => ['threads_user_id' => 'th_1'],
    'x' => ['username' => 'testuser'],
    'instagram' => ['ig_user_id' => 'ig_1', 'page_id' => 'page_1'],
    'tiktok' => ['open_id' => 'tt_1'],
] as $net => $extra) {
    SocialConnections::upsert($adminId, $net, [
        'token' => 'tok-' . $net . '-' . bin2hex(random_bytes(4)),
        'account_id' => $net . '_acc', 'account_name' => 'Conta ' . $net,
        'meta' => $extra + ['via' => 'test'],
    ]);
}
ok('5 redes conectadas para o admin', SocialConnections::count($adminId) - $before >= 5,
    'total=' . SocialConnections::count($adminId));

$r = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST ig sem imagem', 'networks' => ['instagram'],
]);
$errs = implode(' | ', (array)($r['errors'] ?? [])) ?: (string)($r['error'] ?? '');
ok('instagram sem mídia → erro de política', !$r['ok'] && stripos($errs, 'exige mídia') !== false,
    'detail=' . $errs);

$r = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST tiktok', 'media_url' => 'https://example.com/img.jpg',
    'media_kind' => 'image', 'networks' => ['tiktok'],
]);
$errs = implode(' | ', (array)($r['errors'] ?? [])) ?: (string)($r['error'] ?? '');
ok('tiktok com imagem → erro (aceita apenas vídeo)', !$r['ok'] && stripos($errs, 'apenas vídeo') !== false,
    'detail=' . $errs);

$r = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST ' . str_repeat('a', 300), 'networks' => ['x'],
]);
$errs = implode(' | ', (array)($r['errors'] ?? [])) ?: (string)($r['error'] ?? '');
ok('legenda X > 280 → cita o limite', !$r['ok'] && stripos($errs, '280') !== false,
    'detail=' . $errs);

$r = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST agendamento passado',
    'networks' => ['facebook'], 'scheduled_at' => date('Y-m-d H:i:s', time() - 3600),
]);
ok('agendamento no passado → erro', !$r['ok'] && str_contains((string)($r['error'] ?? ''), 'futuro'));

// --------------------------------------------- 5. falha isolada por rede
section('5. Publicação multiredes com falha isolada');
$res = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST multirede com falha no threads',
    'networks' => ['facebook', 'threads', 'x'],
]);
$targets = $res['targets'] ?? [];
$byNet = array_column($targets, 'status', 'network');
ok('create executou (2 ok + 1 falha)', !empty($res['ok']) && ($res['status'] ?? '') === 'partial',
    'status=' . ($res['status'] ?? ''));
ok('facebook publicou', ($byNet['facebook'] ?? '') === 'published', 'remote=' . json_encode($targets));
ok('threads FALHOU isolado (mensagem preservada)', ($byNet['threads'] ?? '') === 'failed'
    && stripos(json_encode($targets), 'threads exploded') !== false);
ok('x publicou (não foi afetada pela falha)', ($byNet['x'] ?? '') === 'published');
ok('resumo traz published=2/failed=1', (int)($res['published'] ?? -1) === 2 && (int)($res['failed'] ?? -1) === 1,
    'published=' . ($res['published'] ?? '?') . ' failed=' . ($res['failed'] ?? '?'));
$postId = (int)($res['post_id'] ?? 0);
$row = $postId ? \AfiliaFacil\Models\SocialPost::find($postId) : null;
ok('post gravado como partial + published_at', $row && $row->status === 'partial' && !empty($row->published_at));

// ------------------------------------------------------ 6. agendamento
section('6. Agendamento + processDue');
$res = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST agendado',
    'networks' => ['facebook'],
    'scheduled_at' => date('Y-m-d H:i:s', time() + 120),
]);
ok('create agenda (status scheduled, sem publicar)', !empty($res['ok']) && ($res['status'] ?? '') === 'scheduled'
    && ($res['targets'][0]['status'] ?? '') === 'pending', 'status=' . ($res['status'] ?? ''));
$schedId = (int)($res['post_id'] ?? 0);

$due0 = SocialPublisher::processDue();
ok('processDue não publica o que ainda está no futuro', ($due0['processed'] ?? -1) === 0,
    'processed=' . json_encode($due0));

if ($schedId) {
    \AfiliaFacil\Models\SocialPost::where('id', $schedId)
        ->update(['scheduled_at' => date('Y-m-d H:i:s', time() - 60)]);
}
$due = SocialPublisher::processDue();
ok('processDue publica o vencido', ($due['processed'] ?? 0) >= 1 && ($due['published'] ?? 0) >= 1,
    'due=' . json_encode($due));
$after = $schedId ? \AfiliaFacil\Models\SocialPost::find($schedId) : null;
$tAfter = $schedId ? \AfiliaFacil\Models\SocialPostTarget::where('post_id', $schedId)->first() : null;
ok('post vencido vira published (target remoto ok)', $after && $after->status === 'published'
    && $tAfter && $tAfter->status === 'published' && $tAfter->remote_id !== '',
    'status=' . ($after->status ?? '?') . ' remote=' . ($tAfter->remote_id ?? '?'));

// ----------------------------------------------- 7. histórico + exclusão
section('7. Histórico + exclusão');
$hist = SocialPublisher::list($adminId, 50);
$histTest = array_filter($hist, fn ($p) => str_starts_with((string)$p['caption'], 'SOCIALTEST'));
ok('list devolve posts com targets', count($histTest) >= 2
    && !empty($histTest[array_key_first($histTest)]['targets']));
$firstId = (int)($histTest[array_key_first($histTest)]['id']);
ok('delete remove post', SocialPublisher::delete($adminId, $firstId)
    && !\AfiliaFacil\Models\SocialPost::find($firstId));
ok('delete nega post de outro usuário', !SocialPublisher::delete($trialId, $schedId));

// ------------------------------------------- 8. métricas (dashboard)
section('8. Métricas por post/rede (Fase 2)');
$now = date('Y-m-d H:i:s');
$fxPost = \AfiliaFacil\Models\SocialPost::create([
    'user_id' => $adminId, 'caption' => 'SOCIALTEST fixture de metricas',
    'media_url' => '', 'media_kind' => '', 'status' => 'published',
    'scheduled_at' => null, 'published_at' => $now, 'source' => 'manual',
    'created_at' => $now, 'updated_at' => $now,
]);
$fxRemotes = ['facebook' => 'fb_feed_1', 'instagram' => 'ig_pub_1', 'threads' => 'th_pub_9',
    'x' => 'x_post_1', 'tiktok' => 'tt_9'];
foreach ($fxRemotes as $net => $remote) {
    $c = SocialConnections::find($adminId, $net);
    \AfiliaFacil\Models\SocialPostTarget::create([
        'post_id' => (int)$fxPost->id, 'connection_id' => (int)$c->id, 'network' => $net,
        'status' => 'published', 'remote_id' => $remote, 'error' => '',
        'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

$sum = SocialMetrics::collectPost((int)$fxPost->id, true);
ok('coleta respondeu as 5 redes', ($sum['updated'] ?? 0) === 5 && ($sum['failed'] ?? 0) === 0,
    'summary=' . json_encode($sum));

$byPost = SocialMetrics::forUser($adminId);
$m = $byPost[(int)$fxPost->id] ?? [];
ok('facebook: likes 12 / comments 3 / shares 4 / impr 340',
    (($m['facebook']['likes'] ?? -1) === 12) && (($m['facebook']['comments'] ?? -1) === 3)
    && (($m['facebook']['shares'] ?? -1) === 4) && (($m['facebook']['impressions'] ?? -1) === 340),
    'fb=' . json_encode($m['facebook'] ?? null));
ok('instagram: likes 7 / reach 120', (($m['instagram']['likes'] ?? -1) === 7)
    && (($m['instagram']['reach'] ?? -1) === 120), 'ig=' . json_encode($m['instagram'] ?? null));
ok('threads: likes 3 / shares (reposts+quotes) 2 / impr 90',
    (($m['threads']['likes'] ?? -1) === 3) && (($m['threads']['shares'] ?? -1) === 2)
    && (($m['threads']['impressions'] ?? -1) === 90), 'th=' . json_encode($m['threads'] ?? null));
ok('x: likes 5 / comments 2 / impr 77', (($m['x']['likes'] ?? -1) === 5)
    && (($m['x']['comments'] ?? -1) === 2) && (($m['x']['impressions'] ?? -1) === 77),
    'x=' . json_encode($m['x'] ?? null));
ok('tiktok: views 100 / shares 2', (($m['tiktok']['views'] ?? -1) === 100)
    && (($m['tiktok']['shares'] ?? -1) === 2), 'tt=' . json_encode($m['tiktok'] ?? null));
ok('collected_at preenchido', !empty($m['facebook']['collected_at']));

$sum2 = SocialMetrics::collectPost((int)$fxPost->id, false);
ok('sem force recoleta nada (coleta recente < 1h)', ($sum2['checked'] ?? -1) === 0,
    'summary=' . json_encode($sum2));

// falha graciosa: token removido nao apaga metricas ja coletadas
$connX = SocialConnections::find($adminId, 'x');
if ($connX) {
    \AfiliaFacil\Models\SocialConnection::where('id', $connX->id)
        ->update(['status' => 'expired', 'updated_at' => $now]);
}
$sum3 = SocialMetrics::collectPost((int)$fxPost->id, true);
$mAfter = SocialMetrics::forUser($adminId)[(int)$fxPost->id] ?? [];
ok('conexão expirada: 4 ok + 1 falha, métricas antigas preservadas',
    ($sum3['updated'] ?? -1) === 4 && ($sum3['failed'] ?? -1) === 1
    && (($mAfter['x']['likes'] ?? -1) === 5), 'summary=' . json_encode($sum3));
SocialConnections::upsert($adminId, 'x', [
    'token' => 'tok-x-restored-' . bin2hex(random_bytes(3)),
    'account_id' => 'x_acc', 'account_name' => 'Conta x',
    'meta' => ['username' => 'testuser', 'via' => 'test'],
]);

// ------------------------------------------------------ 9. API HTTP
section('9. API HTTP (/admin/api/social.php + tela)');
$BASE = 'http://localhost:9876';
$http = function (string $method, string $url, array $post = null, string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => $raw === false ? '' : substr($raw, $headerSize)];
};

$r = $http('GET', $BASE . '/admin/api/social.php?action=connections');
$jb = json_decode($r['body'], true) ?: [];
ok('sem sessão → 401 Não autenticado', $r['status'] === 401 && ($jb['error'] ?? '') === 'Não autenticado',
    'status=' . $r['status']);

$jar = sys_get_temp_dir() . '/social-test.cookie';
@unlink($jar);
$http('POST', $BASE . '/login', ['email' => 'admin@afiliafacil.com', 'password' => 'admin123'], $jar);
$r = $http('GET', $BASE . '/admin/api/social.php?action=connections', null, $jar);
$jb = json_decode($r['body'], true) ?: [];
ok('connections logado → success + redes + quotas', $r['status'] === 200 && !empty($jb['success'])
    && count($jb['networks'] ?? []) === 5 && isset($jb['conn_quota']['max'], $jb['post_quota']['max']),
    'networks=' . count($jb['networks'] ?? []) . ' has_feature=' . var_export($jb['has_feature'] ?? null, true));
ok('connections expõe a conta do facebook (sem token)', in_array('facebook', array_column($jb['connections'] ?? [], 'network'), true)
    && !isset(($jb['connections'] ?? [])[0]['access_token']));

$r = $http('POST', $BASE . '/admin/api/social.php', ['action' => 'list', 'limit' => 5], $jar);
$jl = json_decode($r['body'], true) ?: [];
ok('list retorna posts do usuário', $r['status'] === 200 && !empty($jl['success'])
    && array_key_exists('posts', $jl), 'posts=' . count($jl['posts'] ?? []));

$r = $http('POST', $BASE . '/admin/api/social.php', ['action' => 'create'], $jar);
$jc = json_decode($r['body'], true) ?: [];
ok('create sem nada → 400 com erro claro', $r['status'] === 400 && !empty($jc['error']),
    'error=' . ($jc['error'] ?? ''));

$r = $http('POST', $BASE . '/admin/api/social.php', ['action' => 'process'], $jar);
$jp = json_decode($r['body'], true) ?: [];
ok('action=process roda (polling)', $r['status'] === 200 && !empty($jp['success'])
    && isset($jp['due']['processed']), 'due=' . json_encode($jp['due'] ?? []));

$r = $http('POST', $BASE . '/admin/api/social.php', ['action' => 'metrics'], $jar);
$jm = json_decode($r['body'], true) ?: [];
ok('action=metrics → by_post populado', $r['status'] === 200 && !empty($jm['success'])
    && !empty($jm['by_post']), 'posts=' . count($jm['by_post'] ?? []));

$r = $http('POST', $BASE . '/admin/api/social.php', ['action' => 'collect'], $jar);
$jc2 = json_decode($r['body'], true) ?: [];
ok('action=collect → summary sem erro', $r['status'] === 200 && !empty($jc2['success'])
    && isset($jc2['summary']['checked']) && !isset($jc2['summary']['error']),
    'summary=' . json_encode($jc2['summary'] ?? null));

$r = $http('GET', $BASE . '/admin/integrations.php', null, $jar);
ok('tela /admin/integrations.php → 200 com composer', $r['status'] === 200
    && str_contains($r['body'], 'Redes sociais') && str_contains($r['body'], 'publishBtn'),
    'status=' . $r['status'] . ' len=' . strlen($r['body']));
ok('tela tem seção Desempenho + métricas', str_contains($r['body'], 'Desempenho')
    && str_contains($r['body'], 'metricsBody') && str_contains($r['body'], 'collectMetrics'));

// ------------------------------------------------------------------ resumo
echo "\n----------------------------------------\n";
echo "PASS: {$GLOBALS['__pass']}  FAIL: " . count($GLOBALS['__fail']) . "\n";
if ($GLOBALS['__fail']) {
    foreach ($GLOBALS['__fail'] as $f) echo "  ✗ {$f}\n";
    exit(1);
}
echo "OK\n";
exit(0);
