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
require_once Config::getLibDir() . '/Social/SocialOAuth.php';
require_once Config::getLibDir() . '/Social/SocialAppCredentials.php';
require_once Config::getLibDir() . '/Flows/FlowRunner.php';

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
        $flows = \AfiliaFacil\Models\Flow::where('user_id', $adminId)
            ->where('name', 'like', 'SOCIALTEST%')->pluck('id');
        if (count($flows)) {
            \AfiliaFacil\Models\FlowRun::whereIn('flow_id', $flows)->delete();
            \AfiliaFacil\Models\Flow::whereIn('id', $flows)->delete();
        }
        \AfiliaFacil\Models\SocialAppCredential::where('user_id', $adminId)
            ->whereIn('provider', ['meta', 'threads', 'x', 'tiktok'])->delete();
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

    // --- webhook do fluxo (Fase 3) ---
    if (str_contains($url, 'hooks.test')) {
        $GLOBALS['WF_WEBHOOKS'][] = ['url' => $url, 'payload' => $opts['json'] ?? null];
        return $j(['ok' => true]);
    }

    // --- conexao/probe OAuth (Fase 4) ---
    if (str_contains($url, 'graph.threads.net/oauth/access_token')) {
        return $j(['access_token' => 'th_short_1', 'token_type' => 'Bearer',
            'expires_in' => 3600, 'refresh_token' => 'th_refresh_1']);
    }
    if (str_contains($url, 'graph.threads.net/refresh_access_token')) {
        return $j(['access_token' => 'th_long_60d', 'token_type' => 'Bearer',
            'expires_in' => 60 * 86400, 'refresh_token' => 'th_refresh_1']);
    }
    if (str_contains($url, 'graph.threads.net') && str_ends_with($url, '/v1.0/me')) {
        return $j(['id' => 'th_probe_1', 'username' => 'th_probe']);
    }
    if (str_ends_with($url, '/v26.0/me')) {
        return $j(['id' => 'fb_user_probe', 'name' => 'Usuário Probe']);
    }
    if (str_contains($url, 'debug_token')) {
        $f = $GLOBALS['FB_DEBUG'] ?? null;
        if (is_array($f)) return $j($f);
        return $j(['data' => ['app_id' => 'meta_app_1', 'is_valid' => true,
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            'expires_at' => time() + 30 * 86400]]);
    }
    if (str_contains($url, '/me/accounts')) {
        return $j(['data' => [[
            'id' => 'page_probe_1', 'name' => 'Página Probe',
            'access_token' => 'page_token_probe_1',
            'instagram_business_account' => ['id' => 'ig_probe_1', 'username' => 'ig_probe_1'],
        ]]]);
    }
    if (str_contains($url, 'api.x.com/2/users/me')) {
        if (isset($GLOBALS['X_ME_ERROR'])) {
            $e = $GLOBALS['X_ME_ERROR'];
            return $j($e['body'], (int)($e['status'] ?? 400));
        }
        return $j(['data' => ['id' => 'x_probe_1', 'name' => 'X Probe', 'username' => 'x_probe_1']]);
    }
    if ($method === 'GET' && str_contains($url, 'open.tiktokapis.com/v2/oauth/token')) {
        return $j(['scope' => $GLOBALS['TT_SCOPE'] ?? 'user.info.basic,video.publish',
            'expires_in' => 86400]);
    }
    if (str_contains($url, 'open.tiktokapis.com/v2/user/info')) {
        return $j(['data' => ['user' => ['open_id' => 'tt_probe_1', 'display_name' => 'TT Probe']]]);
    }

    // --- metricas (GET) ---
    if (preg_match('#graph\.facebook\.com/v26\.0/fb_feed_1$#', $url)) {
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
ok('tela /admin/integrations.php → 200 sem composer (composer migrou p/ Publicações)', $r['status'] === 200
    && str_contains($r['body'], 'Redes sociais') && str_contains($r['body'], 'Contas conectadas')
    && !str_contains($r['body'], 'publishBtn'),
    'status=' . $r['status'] . ' len=' . strlen($r['body']));
ok('tela tem seção Fluxos + form', str_contains($r['body'], 'Fluxos (automação)')
    && str_contains($r['body'], 'flowFormCard') && str_contains($r['body'], 'flowsBody')
    && str_contains($r['body'], 'loadFlows'));

$r = $http('GET', $BASE . '/admin/publicacoes.php', null, $jar);
ok('tela /admin/publicacoes.php → 200 com composer', $r['status'] === 200
    && str_contains($r['body'], 'Criar publicação') && str_contains($r['body'], 'publishBtn'),
    'status=' . $r['status'] . ' len=' . strlen($r['body']));
ok('tela tem seção Desempenho + métricas', str_contains($r['body'], 'Desempenho')
    && str_contains($r['body'], 'metricsBody') && str_contains($r['body'], 'collectMetrics'));
ok('tela tem seção Histórico', str_contains($r['body'], 'Histórico')
    && str_contains($r['body'], 'histBody') && str_contains($r['body'], 'loadHistory'));
ok('sidebar do composer linka Publicações (grupo Criar)', str_contains($r['body'], '/admin/publicacoes.php'));

// ------------------------------------------------- 10. Fluxos (Fase 3)
section('10. Fluxos de automação (Fase 3)');

$res = FlowRunner::create($adminId, [
    'name' => '', 'trigger_kind' => 'schedule', 'trigger_config' => [],
    'action_kind' => 'webhook', 'action_config' => ['url' => 'https://hooks.test/x'],
]);
ok('create sem nome → erro', !$res['ok'] && str_contains((string)$res['error'], 'nome'));

$res = FlowRunner::create($adminId, [
    'name' => 'SOCIALTEST valida schedule', 'trigger_kind' => 'schedule',
    'trigger_config' => ['time' => '', 'days' => []],
    'action_kind' => 'webhook', 'action_config' => ['url' => 'https://hooks.test/x'],
]);
ok('schedule sem horário → erro claro', !$res['ok'] && stripos((string)$res['error'], 'horário') !== false,
    'error=' . ($res['error'] ?? ''));

$res = FlowRunner::create($adminId, [
    'name' => 'SOCIALTEST valida url', 'trigger_kind' => 'post_published', 'trigger_config' => [],
    'action_kind' => 'webhook', 'action_config' => ['url' => 'notaurl'],
]);
ok('webhook com URL inválida → erro', !$res['ok'] && stripos((string)$res['error'], 'URL') !== false,
    'error=' . ($res['error'] ?? ''));

$resW = FlowRunner::create($adminId, [
    'name' => 'SOCIALTEST webhook', 'trigger_kind' => 'post_published', 'trigger_config' => [],
    'action_kind' => 'webhook', 'action_config' => ['url' => 'https://hooks.test/afilia'],
]);
ok('cria fluxo webhook pós-publicação', !empty($resW['ok']) && !empty($resW['id']),
    'error=' . ($resW['error'] ?? ''));

$resC = FlowRunner::create($adminId, [
    'name' => 'SOCIALTEST cascata', 'trigger_kind' => 'post_published', 'trigger_config' => [],
    'action_kind' => 'publish_post',
    'action_config' => ['networks' => ['facebook'], 'caption' => 'SOCIALTEST cascata'],
]);
ok('cria fluxo publicação em cascata', !empty($resC['ok']), 'error=' . ($resC['error'] ?? ''));

// publicar dispara os 2 fluxos; o guard de profundidade impede o loop
$GLOBALS['WF_WEBHOOKS'] = [];
$resP = SocialPublisher::create($adminId, 'premium', [
    'caption' => 'SOCIALTEST gatilho post_published',
    'networks' => ['facebook'],
]);
$hooks = $GLOBALS['WF_WEBHOOKS'];
$cascataCount = \AfiliaFacil\Models\SocialPost::where('caption', 'SOCIALTEST cascata')->count();
ok('publicação dispara fluxos: 1 webhook + 1 post em cascata (sem loop)',
    !empty($resP['ok']) && count($hooks) === 1 && $cascataCount === 1,
    'hooks=' . count($hooks) . ' cascata=' . $cascataCount . ' status=' . ($resP['status'] ?? ''));
$payload = $hooks[0]['payload'] ?? null;
ok('webhook recebeu event=post_published + post_id',
    is_array($payload) && ($payload['event'] ?? '') === 'post_published'
    && !empty($payload['data']['post_id']) && !empty($payload['flow_name']),
    'payload=' . json_encode($payload));

// fluxo agendado: última ocorrência vencida executa; depois não repete
$resS = FlowRunner::create($adminId, [
    'name' => 'SOCIALTEST diario', 'trigger_kind' => 'schedule',
    'trigger_config' => ['time' => '00:00', 'days' => [0, 1, 2, 3, 4, 5, 6]],
    'action_kind' => 'publish_post',
    'action_config' => ['networks' => ['facebook'], 'caption' => 'SOCIALTEST via fluxo'],
]);
ok('cria fluxo agendado (schedule + publish_post)', !empty($resS['ok']),
    'error=' . ($resS['error'] ?? ''));
$schedFlowId = (int)($resS['id'] ?? 0);
if ($schedFlowId) {
    \AfiliaFacil\Models\Flow::where('id', $schedFlowId)->update(['created_at' => '2020-01-01 00:00:00']);
}

$due = FlowRunner::runDue(10);
ok('runDue executa o fluxo vencido', ($due['processed'] ?? 0) >= 1 && ($due['ok'] ?? 0) >= 1,
    'due=' . json_encode($due));
$flowPost = \AfiliaFacil\Models\SocialPost::where('caption', 'SOCIALTEST via fluxo')->first();
ok('fluxo publicou o post agendado', $flowPost && $flowPost->status === 'published',
    'status=' . ($flowPost->status ?? '?'));
$flowRow = $schedFlowId ? \AfiliaFacil\Models\Flow::find($schedFlowId) : null;
ok('last_run_at + last_status gravados', $flowRow && !empty($flowRow->last_run_at)
    && $flowRow->last_status === 'ok', 'last=' . ($flowRow->last_run_at ?? '?'));
$runs = $schedFlowId ? FlowRunner::runs($adminId, $schedFlowId) : [];
ok('flow_runs registrou a execução', count($runs) >= 1 && $runs[0]['status'] === 'ok',
    'runs=' . count($runs) . ' first=' . json_encode($runs[0] ?? null));

$due2 = FlowRunner::runDue(10);
ok('segundo runDue não reexecuta (ocorrência já coberta)', ($due2['processed'] ?? -1) === 0,
    'due=' . json_encode($due2));

// API HTTP dos fluxos
$r = $http('GET', $BASE . '/admin/api/flows.php?action=list');
ok('flows sem sessão → 401', $r['status'] === 401, 'status=' . $r['status']);

$r = $http('POST', $BASE . '/admin/api/flows.php', ['action' => 'list'], $jar);
$jl = json_decode($r['body'], true) ?: [];
ok('flows list → success + fluxos criados', $r['status'] === 200 && !empty($jl['success'])
    && count($jl['flows'] ?? []) >= 3, 'flows=' . count($jl['flows'] ?? []));

$r = $http('POST', $BASE . '/admin/api/flows.php', [
    'action' => 'create',
    'name' => 'SOCIALTEST http',
    'trigger_kind' => 'post_published',
    'trigger_config' => '{}',
    'action_kind' => 'webhook',
    'action_config' => json_encode(['url' => 'https://hooks.test/http']),
], $jar);
$jc = json_decode($r['body'], true) ?: [];
ok('flows create via HTTP (configs como JSON string) → ok',
    $r['status'] === 200 && !empty($jc['ok']) && !empty($jc['id']),
    'status=' . $r['status'] . ' error=' . ($jc['error'] ?? ''));
$httpFlowId = (int)($jc['id'] ?? 0);

$r = $http('POST', $BASE . '/admin/api/flows.php',
    ['action' => 'toggle', 'id' => $httpFlowId, 'enabled' => 'false'], $jar);
$jt = json_decode($r['body'], true) ?: [];
ok('toggle pausa o fluxo', $r['status'] === 200 && !empty($jt['ok']) && $jt['enabled'] === false,
    'body=' . $r['body']);

$r = $http('POST', $BASE . '/admin/api/flows.php', ['action' => 'runs', 'id' => $httpFlowId], $jar);
$jr = json_decode($r['body'], true) ?: [];
ok('runs → lista do fluxo', $r['status'] === 200 && !empty($jr['success'])
    && is_array($jr['runs'] ?? null), 'runs=' . count($jr['runs'] ?? []));

$r = $http('POST', $BASE . '/admin/api/flows.php', ['action' => 'process'], $jar);
$jd = json_decode($r['body'], true) ?: [];
ok('flows process → success + due', $r['status'] === 200 && !empty($jd['success'])
    && isset($jd['due']['processed']), 'due=' . json_encode($jd['due'] ?? []));

$r = $http('POST', $BASE . '/admin/api/flows.php', ['action' => 'delete', 'id' => $httpFlowId], $jar);
$jd2 = json_decode($r['body'], true) ?: [];
ok('delete remove o fluxo', $r['status'] === 200 && !empty($jd2['success'])
    && !\AfiliaFacil\Models\Flow::find($httpFlowId));

// ------------------------------ 11. Conexão guiada + BYOK de app (Fase 4)
section('11. Conexão guiada + credenciais de app (Fase 4)');

// --- UI: modal com jornada por rede ---
$r = $http('GET', $BASE . '/admin/integrations.php', null, $jar);
$ui = (string)$r['body'];
ok('tela tem modal de conexão guiada', $r['status'] === 200
    && str_contains($ui, 'id="connModal"') && str_contains($ui, 'openConnModal(')
    && str_contains($ui, 'Pré-requisitos (1 vez nesta rede)'), 'status=' . $r['status']);
ok('tela traz guias por rede + redirect URI oficial', str_contains($ui, 'const GUIDES')
    && str_contains($ui, 'console.x.com') && str_contains($ui, 'developers.tiktok.com')
    && str_contains($ui, 'social.php?action=callback'));
ok('OAuth via popup com postMessage + credenciais BYOK', str_contains($ui, 'af-social-oauth')
    && str_contains($ui, 'waitForOAuthMessage') && str_contains($ui, 'saveAppCreds')
    && str_contains($ui, "api('app-save'"));
ok('caminho manual antigo removido (Conectar único)', !str_contains($ui, 'toggleManual(')
    && !str_contains($ui, 'manual-box') && !str_contains($ui, 'onclick="saveToken('));
ok('sem credenciais o clique guia para as credenciais (nunca botão morto)',
    str_contains($ui, 'promptAppCreds') && !str_contains($ui, 'btn.disabled = !m.oauth_configured'));
ok('guias Meta/Instagram incluem cadastro do Redirect URI (3 locais do dashboard 2026)',
    str_contains($ui, 'Valid OAuth Redirect URIs') && str_contains($ui, 'URL bloqueada')
    && str_contains($ui, 'Facebook Login for Business') && str_contains($ui, 'Add Product')
    && str_contains($ui, 'fb-login/settings/'));
ok('modal (admin): avanco escondido de clientes + guia do dono + SSO do .env',
    str_contains($ui, 'Configuração avançada (só admin)')
    && str_contains($ui, 'como um SSO')
    && str_contains($ui, 'App da plataforma (.env)')
    && str_contains($ui, 'Guia do dono')
    && str_contains($ui, '<span class="conn-step-n">2</span> Conectar')
    && !str_contains($ui, 'Seu app da rede (recomendado)')
    && !str_contains($ui, 'Conexão rápida não habilitada'));
ok('guias avisam Development x Live + App Review para clientes',
    str_contains($ui, 'App Review'));
ok('guias cobrem Invalid Scopes, Use Cases e App Domains (dashboard 2026)',
    str_contains($ui, 'Invalid Scopes') && str_contains($ui, 'Use Cases')
    && str_contains($ui, 'Ready for testing') && str_contains($ui, 'App Domains')
    && str_contains($ui, 'Something else') && !str_contains($ui, 'tipo Consumer'));
ok('guia do dono orienta a criar o proprio app (uma unica vez, sem App Review)',
    str_contains($ui, 'criar seu próprio app') && str_contains($ui, 'uma única vez')
    && str_contains($ui, 'testers') && str_contains($ui, 'sem App Review'));
ok('guia Instagram inclui instagram_basic (dependencia do publish)',
    str_contains($ui, 'instagram_basic'));
ok('card expirado/erro oferece Reconectar + Desconectar (conexao nunca fica orfa)',
    str_contains($ui, 'Reconectar') && str_contains($ui, 'token expirado')
    && str_contains($ui, 'conexão com erro'));

// --- visão do cliente: SÓ o botão Conectar (sem guia, IDs, tokens ou .env) ---
$jarT = sys_get_temp_dir() . '/social-test-trial.cookie';
@unlink($jarT);
$http('POST', $BASE . '/login', ['email' => 'demo.trial@afiliafacil.com', 'password' => 'Trial.Demo@2026'], $jarT);
$r = $http('GET', $BASE . '/admin/integrations.php', null, $jarT);
$uiT = (string)$r['body'];
ok('cliente: so o botao Conectar (GUIES={}, sem guia/IDs/redirect/token no HTML)',
    $r['status'] === 200
    && str_contains($uiT, 'openConnModal(') && str_contains($uiT, 'connOAuthBtn')
    && str_contains($uiT, 'const GUIDES = {}')
    && str_contains($uiT, '<span class="conn-step-n">1</span> Conectar')
    && !str_contains($uiT, 'id="connCredsBox"') && !str_contains($uiT, 'id="connAppId"')
    && !str_contains($uiT, 'id="connToken"') && !str_contains($uiT, 'Pré-requisitos')
    && !str_contains($uiT, 'Valid OAuth Redirect URIs') && !str_contains($uiT, 'Guia do dono')
    && !str_contains($uiT, 'fb-login/settings/'),
    'status=' . $r['status']);
@unlink($jarT);

// --- guia didático do dono no repo (Meta 2026) ---
$guide = @file_get_contents(__DIR__ . '/../../docs/guia-meta.md');
ok('docs/guia-meta.md existe e cobre .env + menus do dashboard 2026',
    is_string($guide) && str_contains($guide, 'META_APP_ID')
    && str_contains($guide, 'Valid OAuth Redirect URIs') && str_contains($guide, 'Use Cases')
    && str_contains($guide, 'force-recreate'));

$r = $http('GET', $BASE . '/assets/css/app.css', null, $jar);
ok('app.css estiliza botões disabled (estado óbvio)', $r['status'] === 200
    && str_contains((string)$r['body'], 'button:disabled'), 'status=' . $r['status']);

// --- credenciais de app (BYOK): usuário > env + dialogs corretos ---
// instagram sem NENHUMA credencial (env removido + sem BYOK) → oauth_not_configured
$envMid = getenv('META_APP_ID');
$envMsec = getenv('META_APP_SECRET');
putenv('META_APP_ID');
putenv('META_APP_SECRET');
SocialAppCredentials::delete($adminId, 'meta');
$resIg = SocialOAuth::authorizeUrl('instagram', $adminId);
$igNotCfg = !$resIg['ok'] && ($resIg['error'] ?? '') === 'oauth_not_configured';
if ($envMid !== false) putenv('META_APP_ID=' . $envMid);
if ($envMsec !== false) putenv('META_APP_SECRET=' . $envMsec);
ok('instagram sem credencial → oauth_not_configured', $igNotCfg,
    'error=' . ($resIg['error'] ?? '?'));

// env da plataforma (SSO): sem nenhuma credencial de usuário, o app DO
// PROJETO habilita o "Entrar" — cliente nunca vê App ID/Secret.
putenv('META_APP_ID=env_meta_9');
putenv('META_APP_SECRET=env_secret_9');
$credEnv = SocialOAuth::credentials('instagram', $adminId);
$resEnv = SocialOAuth::authorizeUrl('instagram', $adminId);
ok('env da plataforma → SSO configurado (source=env + dialog com client_id)',
    !empty($credEnv['configured']) && ($credEnv['source'] ?? '') === 'env'
    && !empty($resEnv['ok']) && str_contains((string)($resEnv['url'] ?? ''), 'client_id=env_meta_9'),
    'cred=' . json_encode($credEnv) . ' err=' . ($resEnv['error'] ?? ''));

ok('salva credencial BYOK do Meta', SocialAppCredentials::save($adminId, 'meta', 'meta_app_1', 'meta_secret_1'));
$res = SocialOAuth::authorizeUrl('facebook', $adminId);
ok('facebook BYOK → dialog Meta com pages_show_list', !empty($res['ok'])
    && str_contains((string)($res['url'] ?? ''), 'facebook.com/v26.0/dialog/oauth')
    && str_contains((string)($res['url'] ?? ''), 'pages_show_list'),
    'url=' . substr((string)($res['url'] ?? ''), 0, 80));
$cred = SocialOAuth::credentials('facebook', $adminId);
ok('credencial do usuário vence o env (source=user)', !empty($cred['configured'])
    && ($cred['source'] ?? '') === 'user' && ($cred['id'] ?? '') === 'meta_app_1',
    'source=' . ($cred['source'] ?? ''));
putenv('META_APP_ID');   // devolve o ambiente real (sem env da plataforma)
putenv('META_APP_SECRET');

// connect-url HTTP (endpoint) com credencial → URL oficial do dialog Meta
$r = $http('POST', $BASE . '/admin/api/social.php',
    ['action' => 'connect-url', 'network' => 'instagram'], $jar);
$jc = json_decode($r['body'], true) ?: [];
$urlIg = (string)($jc['url'] ?? '');
ok('connect-url instagram → dialog Meta com client_id/redirect/state', $r['status'] === 200
    && !empty($jc['ok']) && str_contains($urlIg, 'facebook.com/v26.0/dialog/oauth')
    && str_contains($urlIg, 'client_id=meta_app_1')
    && str_contains($urlIg, 'redirect_uri=') && str_contains($urlIg, 'state='),
    'body=' . $r['body']);
ok('dialog instagram pede instagram_basic (dependencia de instagram_content_publish)',
    str_contains($urlIg, 'instagram_basic') && str_contains($urlIg, 'instagram_content_publish'),
    'url=' . substr($urlIg, 0, 160));

// Threads: sem credencial → oauth_not_configured (env removido do processo)
$envTid = getenv('THREADS_APP_ID');
$envTsec = getenv('THREADS_APP_SECRET');
putenv('THREADS_APP_ID');
putenv('THREADS_APP_SECRET');
SocialAppCredentials::delete($adminId, 'threads');
$res = SocialOAuth::authorizeUrl('threads', $adminId);
$notCfg = !$res['ok'] && ($res['error'] ?? '') === 'oauth_not_configured';
if ($envTid !== false) putenv('THREADS_APP_ID=' . $envTid);
if ($envTsec !== false) putenv('THREADS_APP_SECRET=' . $envTsec);
ok('threads sem credencial → oauth_not_configured', $notCfg, 'error=' . ($res['error'] ?? '?'));

ok('salva credencial BYOK do Threads', SocialAppCredentials::save($adminId, 'threads', 'th_app_1', 'th_secret_1'));
$res = SocialOAuth::authorizeUrl('threads', $adminId);
$urlTh = (string)($res['url'] ?? '');
ok('threads BYOK → Authorization Window própria (threads.com)', !empty($res['ok'])
    && str_starts_with($urlTh, 'https://threads.com/oauth/authorize?')
    && str_contains($urlTh, 'client_id=th_app_1')
    && str_contains($urlTh, 'threads_content_publish')
    && !str_contains($urlTh, 'facebook.com'), 'url=' . substr($urlTh, 0, 80));

// callback oficial do Threads: code → troca → long-lived 60d → descoberta
$state = '';
foreach (explode('&', (string)parse_url($urlTh, PHP_URL_QUERY)) as $kv) {
    $pair = explode('=', $kv, 2);
    if (($pair[0] ?? '') === 'state') $state = rawurldecode((string)($pair[1] ?? ''));
}
ok('authorizeUrl gera state anti-CSRF na sessão', $state !== '');
$cb = SocialOAuth::handleCallback(['state' => $state, 'code' => 'th-code-1']);
$connTh = SocialConnections::find($adminId, 'threads');
$thToken = $connTh ? (string)SocialConnections::tokenFor($connTh) : '';
ok('callback threads: code trocado por long-lived 60d + refresh', !empty($cb['ok'])
    && ($cb['network'] ?? '') === 'threads' && $thToken === 'th_long_60d'
    && $connTh !== null && !empty($connTh->refresh_token)
    && !empty($connTh->token_expires_at)
    && strtotime((string)$connTh->token_expires_at) > time() + 55 * 86400,
    'cb=' . json_encode($cb) . ' token=' . $thToken);
ok('callback threads: perfil th_probe descoberto', $connTh !== null
    && $connTh->account_name === 'th_probe' && $connTh->status === 'connected'
    && str_contains((string)$connTh->account_meta, 'threads_user_id'),
    'account=' . ($connTh->account_name ?? '?'));

// devolve o estado (credencial/conexão de teste são recriadas limpas no próximo run)
SocialConnections::disconnect($adminId, 'threads');
SocialAppCredentials::delete($adminId, 'threads');

// --- callback Meta com erro do Facebook: mensagem rica ---
$resFb = SocialOAuth::authorizeUrl('facebook', $adminId);
$stateFb = '';
foreach (explode('&', (string)parse_url((string)($resFb['url'] ?? ''), PHP_URL_QUERY)) as $kv) {
    $pair = explode('=', $kv, 2);
    if (($pair[0] ?? '') === 'state') $stateFb = rawurldecode((string)($pair[1] ?? ''));
}
$cbErr = SocialOAuth::handleCallback(['state' => $stateFb,
    'error' => 'access_denied', 'error_description' => 'Permissions error']);
ok('callback com erro do Facebook exibe error_description (contexto acionavel)',
    empty($cbErr['ok']) && str_contains((string)($cbErr['error'] ?? ''), 'access_denied')
    && str_contains((string)($cbErr['error'] ?? ''), 'Permissions error'),
    'cb=' . json_encode($cbErr));

// --- probe com diagnóstico acionável (Meta) ---
$GLOBALS['FB_DEBUG'] = ['data' => ['app_id' => 'meta_app_1', 'is_valid' => true,
    'scopes' => ['pages_show_list', 'pages_read_engagement'],
    'expires_at' => time() + 30 * 86400]];
$p = SocialOAuth::probe('facebook', 'tok-meta-1', $adminId);
ok('probe Meta sem pages_manage_posts → erro aponta passo 3 do guia',
    empty($p['ok']) && str_contains((string)($p['error'] ?? ''), 'pages_manage_posts')
    && str_contains((string)($p['error'] ?? ''), 'passo 3'),
    'error=' . ($p['error'] ?? ''));

$GLOBALS['FB_DEBUG'] = ['data' => ['app_id' => 'meta_app_1', 'is_valid' => true,
    'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
    'expires_at' => time() + 30 * 86400]];
$p = SocialOAuth::probe('facebook', 'tok-meta-2', $adminId);
ok('probe Meta completo → ok (via manual, sem note)', !empty($p['ok'])
    && (($p['meta']['via'] ?? '') === 'manual') && !isset($p['note']) && !isset($p['error']),
    'account=' . ($p['account_name'] ?? ''));

$GLOBALS['FB_DEBUG'] = ['data' => ['app_id' => 'meta_app_1', 'is_valid' => true,
    'scopes' => ['pages_manage_posts'], 'expires_at' => time() + 2 * 86400]];
$p = SocialOAuth::probe('facebook', 'tok-meta-3', $adminId);
ok('probe Meta: token perto de expirar → note (não bloqueia)', !empty($p['ok'])
    && str_contains((string)($p['note'] ?? ''), 'expira em'), 'note=' . ($p['note'] ?? ''));

$GLOBALS['FB_DEBUG'] = ['data' => ['app_id' => 'app_de_outrem', 'is_valid' => true,
    'scopes' => [], 'expires_at' => 0]];
$p = SocialOAuth::probe('facebook', 'tok-meta-4', $adminId);
ok('probe Meta: token de outro app → sem diagnóstico (sem falso negativo)',
    !empty($p['ok']) && !isset($p['error']) && !isset($p['note']));
unset($GLOBALS['FB_DEBUG']);

$p = SocialOAuth::probe('instagram', 'tok-ig-1', $adminId);
ok('probe instagram descobre conta profissional ligada à Página', !empty($p['ok'])
    && ($p['account_name'] ?? '') === 'ig_probe_1', 'account=' . ($p['account_name'] ?? ''));

// --- probe X: billing x permissão ---
$GLOBALS['X_ME_ERROR'] = ['status' => 403, 'body' => ['title' => 'Forbidden',
    'detail' => 'When authenticating requests to the Twitter API v2 endpoints, you must use...']];
$p = SocialOAuth::probe('x', 'tok-x-403', $adminId);
ok('probe X 403 → aponta tweet.write/console.x.com', empty($p['ok'])
    && str_contains((string)($p['error'] ?? ''), 'tweet.write')
    && str_contains((string)($p['error'] ?? ''), 'console.x.com'),
    'error=' . ($p['error'] ?? ''));

$GLOBALS['X_ME_ERROR'] = ['status' => 402, 'body' => ['title' => 'Payment Required',
    'detail' => 'You have no credits remaining for this endpoint.']];
$p = SocialOAuth::probe('x', 'tok-x-402', $adminId);
ok('probe X 402 → aponta créditos/Billing', empty($p['ok'])
    && str_contains((string)($p['error'] ?? ''), 'Billing')
    && str_contains((string)($p['error'] ?? ''), 'créditos'),
    'error=' . ($p['error'] ?? ''));
unset($GLOBALS['X_ME_ERROR']);

$p = SocialOAuth::probe('x', 'tok-x-ok', $adminId);
ok('probe X válido → ok com username', !empty($p['ok'])
    && ($p['account_name'] ?? '') === 'x_probe_1', 'account=' . ($p['account_name'] ?? ''));

// --- probe TikTok: escopo video.publish ---
$GLOBALS['TT_SCOPE'] = 'user.info.basic';
$p = SocialOAuth::probe('tiktok', 'tok-tt-noscope', $adminId);
ok('probe TikTok sem video.publish → erro acionável', empty($p['ok'])
    && str_contains((string)($p['error'] ?? ''), 'video.publish'),
    'error=' . ($p['error'] ?? ''));

$GLOBALS['TT_SCOPE'] = 'user.info.basic,video.publish';
$p = SocialOAuth::probe('tiktok', 'tok-tt-ok', $adminId);
ok('probe TikTok com escopo → ok', !empty($p['ok'])
    && ($p['account_name'] ?? '') === 'TT Probe', 'account=' . ($p['account_name'] ?? ''));
unset($GLOBALS['TT_SCOPE']);

$p = SocialOAuth::probe('threads', 'tok-th-1', $adminId);
ok('probe threads (graph.threads.net/v1.0/me) → ok', !empty($p['ok'])
    && ($p['account_id'] ?? '') === 'th_probe_1', 'account=' . ($p['account_id'] ?? ''));

// --- API: credenciais de app (BYOK) ---
$r = $http('POST', $BASE . '/admin/api/social.php',
    ['action' => 'app-save', 'network' => 'facebook', 'app_id' => '', 'app_secret' => ''], $jar);
$ja = json_decode($r['body'], true) ?: [];
ok('app-save sem credencial → 400 com mensagem clara', $r['status'] === 400
    && str_contains((string)($ja['error'] ?? ''), 'App ID'), 'error=' . ($ja['error'] ?? ''));

$r = $http('POST', $BASE . '/admin/api/social.php',
    ['action' => 'app-save', 'network' => 'facebook', 'app_id' => 'meta_app_1',
        'app_secret' => 's3cret-http'], $jar);
$ja = json_decode($r['body'], true) ?: [];
ok('app-save grava credencial do usuário → ok + configured', $r['status'] === 200
    && !empty($ja['ok']) && !empty($ja['oauth_configured']), 'body=' . $r['body']);

$r = $http('GET', $BASE . '/admin/api/social.php?action=connections', null, $jar);
$jb2 = json_decode($r['body'], true) ?: [];
$fbNet = $jb2['networks']['facebook'] ?? [];
ok('connections expõe oauth_source=user + app_id', ($fbNet['oauth_configured'] ?? false) === true
    && ($fbNet['oauth_source'] ?? '') === 'user' && ($fbNet['app_id'] ?? '') === 'meta_app_1',
    'fb=' . json_encode($fbNet));

$r = $http('POST', $BASE . '/admin/api/social.php',
    ['action' => 'app-delete', 'network' => 'facebook'], $jar);
$jd3 = json_decode($r['body'], true) ?: [];
ok('app-delete remove as credenciais', $r['status'] === 200 && !empty($jd3['ok']),
    'body=' . $r['body']);

$r = $http('GET', $BASE . '/admin/api/social.php?action=connections', null, $jar);
$jb3 = json_decode($r['body'], true) ?: [];
$fbNet = $jb3['networks']['facebook'] ?? [];
ok('após app-delete oauth_source não é mais user', ($fbNet['oauth_source'] ?? '') !== 'user',
    'source=' . ($fbNet['oauth_source'] ?? '?'));

$r = $http('POST', $BASE . '/admin/api/social.php',
    ['action' => 'app-save', 'network' => 'facebook', 'app_id' => 'a', 'app_secret' => 'b']);
ok('app-save sem sessão → 401', $r['status'] === 401, 'status=' . $r['status']);

// ------------------------------------------------------------------ resumo
echo "\n----------------------------------------\n";
echo "PASS: {$GLOBALS['__pass']}  FAIL: " . count($GLOBALS['__fail']) . "\n";
if ($GLOBALS['__fail']) {
    foreach ($GLOBALS['__fail'] as $f) echo "  ✗ {$f}\n";
    exit(1);
}
echo "OK\n";
exit(0);
