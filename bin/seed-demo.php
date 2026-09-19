<?php
/**
 * Seed de demonstração para homologação.
 *
 * Cria contas demo por plano, ofertas fictícias realistas e uma página de exemplo.
 * Uso (dentro do container):
 *   php bin/seed-demo.php          # cria os dados demo
 *   php bin/seed-demo.php --clean  # remove tudo que o seed criou
 *
 * Senha das contas demo: demo123456
 */

require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Offers/OfferManager.php';

Database::init();

if (!Database::available()) {
    fwrite(STDERR, "Banco de dados indisponível.\n");
    exit(1);
}

$clean = in_array('--clean', $argv, true);

const DEMO_ACCOUNTS = [
    ['email' => 'demo.trial@afiliafacil.com', 'name' => 'Demo Trial', 'plan' => 'trial', 'days' => 3, 'password' => 'Trial.Demo@2026'],
    ['email' => 'demo.pro@afiliafacil.com', 'name' => 'Demo Afiliado Pro', 'plan' => 'essencial', 'days' => 0, 'password' => 'Pro.Demo@2026'],
    ['email' => 'demo.master@afiliafacil.com', 'name' => 'Demo Master Elite', 'plan' => 'master', 'days' => 0, 'password' => 'Master.Demo@2026'],
];

const DEMO_PAGE_NAME = 'DEMO - Página de Exemplo';

function demo_clean(): void
{
    echo "Removendo dados demo...\n";

    // Ofertas demo (slug começa com "demo-")
    $offerIds = \AfiliaFacil\Models\Offer::where('slug', 'like', 'demo-%')->pluck('id')->all();
    if ($offerIds) {
        \AfiliaFacil\Models\OfferMetric::whereIn('offer_id', $offerIds)->delete();
        \AfiliaFacil\Models\OfferCreative::whereIn('offer_id', $offerIds)->delete();
        \AfiliaFacil\Models\OfferPage::whereIn('offer_id', $offerIds)->delete();
        \AfiliaFacil\Models\OfferSuggestion::whereIn('offer_id', $offerIds)->delete();
        \AfiliaFacil\Models\OfferView::whereIn('offer_id', $offerIds)->delete();
        \AfiliaFacil\Models\Offer::whereIn('id', $offerIds)->delete();
        echo '  ofertas removidas: ' . count($offerIds) . "\n";
    }

    // Contas demo
    foreach (DEMO_ACCOUNTS as $account) {
        $user = \AfiliaFacil\Models\User::where('email', $account['email'])->first();
        if (!$user) continue;

        // Páginas do usuário demo
        $pm = new PageManager();
        foreach ($pm->listByUser((int)$user->id) as $page) {
            $pm->delete($page['id']);
        }

        // Conversas do agente
        $convIds = \AfiliaFacil\Models\AgentConversation::where('user_id', $user->id)->pluck('id')->all();
        if ($convIds) {
            \AfiliaFacil\Models\AgentMessage::whereIn('conversation_id', $convIds)->delete();
            \AfiliaFacil\Models\AgentConversation::whereIn('id', $convIds)->delete();
        }
        \AfiliaFacil\Models\AgentSubagent::where('user_id', $user->id)->delete();
        \AfiliaFacil\Models\AgentProfile::where('user_id', $user->id)->delete();
        \AfiliaFacil\Models\Transcription::where('user_id', $user->id)->delete();
        \AfiliaFacil\Models\TtsGeneration::where('user_id', $user->id)->delete();
        \AfiliaFacil\Models\UserAiConfig::where('user_id', $user->id)->delete();

        $user->delete();
        echo '  conta removida: ' . $account['email'] . "\n";
    }

    echo "Limpeza concluída.\n";
}

function demo_create(): void
{
    $role = \AfiliaFacil\Models\Role::where('name', 'afiliado')->first();

    echo "Criando contas demo...\n";
    $userIds = [];
    foreach (DEMO_ACCOUNTS as $account) {
        $user = \AfiliaFacil\Models\User::where('email', $account['email'])->first();
        if (!$user) {
            $user = \AfiliaFacil\Models\User::create([
                'id' => time() + random_int(1, 99999),
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => password_hash($account['password'], PASSWORD_DEFAULT),
                'role_id' => $role->id ?? null,
                'plan' => $account['plan'],
                'trial_until' => $account['days'] > 0 ? date('Y-m-d H:i:s', time() + $account['days'] * 86400) : null,
                'active' => true,
                'training_consent' => false,
                'terms_accepted_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            echo '  criada: ' . $account['email'] . ' (' . $account['plan'] . ')' . "\n";
        } else {
            $user->plan = $account['plan'];
            $user->trial_until = $account['days'] > 0 ? date('Y-m-d H:i:s', time() + $account['days'] * 86400) : null;
            $user->password = password_hash($account['password'], PASSWORD_DEFAULT);
            $user->updated_at = date('Y-m-d H:i:s');
            $user->save();
            echo '  atualizada: ' . $account['email'] . ' (' . $account['plan'] . ')' . "\n";
        }
        $userIds[$account['plan']] = (int)$user->id;
    }

    echo "\nCriando ofertas demo...\n";
    $manager = new OfferManager();

    $datasets = [
        [
            'slug' => 'demo-metodo-renda-extra.com.br',
            'name' => 'Método Renda Extra em Casa',
            'advertiser' => 'Renda Extra Oficial',
            'domain' => 'demo-metodo-renda-extra.com.br',
            'source_url' => 'https://demo-metodo-renda-extra.com.br/vsl',
            'platform' => 'meta',
            'traffic' => ['facebook', 'tiktok'],
            'niche' => 'financas',
            'structure' => 'vsl',
            'count' => 42,
            'metrics' => [18, 22, 25, 24, 30, 33, 31, 36, 38, 40, 41, 42],
            'creatives' => 6,
        ],
        [
            'slug' => 'demo-dieta-21-dias.com',
            'name' => 'Dieta 21 Dias',
            'advertiser' => 'Vida Saudável BR',
            'domain' => 'demo-dieta-21-dias.com',
            'source_url' => 'https://demo-dieta-21-dias.com/',
            'platform' => 'google',
            'traffic' => ['google'],
            'niche' => 'emagrecimento',
            'structure' => 'low_ticket',
            'count' => 15,
            'metrics' => [30, 28, 25, 22, 20, 18, 17, 16, 16, 15, 15, 15],
            'creatives' => 4,
        ],
        [
            'slug' => 'demo-quiz-prosperidade.app',
            'name' => 'Quiz da Prosperidade',
            'advertiser' => 'Prosperidade App',
            'domain' => 'demo-quiz-prosperidade.app',
            'source_url' => 'https://demo-quiz-prosperidade.app/quiz',
            'platform' => 'tiktok',
            'traffic' => ['tiktok'],
            'niche' => 'espiritualidade',
            'structure' => 'quiz',
            'count' => 88,
            'metrics' => [10, 20, 35, 50, 60, 70, 75, 80, 82, 85, 87, 88],
            'creatives' => 8,
        ],
        [
            'slug' => 'demo-relacionamento-segredo.com',
            'name' => 'Segredo do Relacionamento',
            'advertiser' => 'Amor em Foco',
            'domain' => 'demo-relacionamento-segredo.com',
            'source_url' => 'https://demo-relacionamento-segredo.com/vsl',
            'platform' => 'meta',
            'traffic' => ['facebook'],
            'niche' => 'relacionamento',
            'structure' => 'vsl',
            'count' => 27,
            'metrics' => [12, 14, 15, 18, 20, 22, 23, 25, 26, 26, 27, 27],
            'creatives' => 5,
        ],
        [
            'slug' => 'demo-curso-trader.com.br',
            'name' => 'Curso Trader Iniciante',
            'advertiser' => 'Trader Pro BR',
            'domain' => 'demo-curso-trader.com.br',
            'source_url' => 'https://demo-curso-trader.com.br/',
            'platform' => 'google',
            'traffic' => ['google', 'facebook'],
            'niche' => 'financas',
            'structure' => 'infoproduto',
            'count' => 61,
            'metrics' => [40, 42, 45, 48, 50, 52, 55, 57, 58, 60, 60, 61],
            'creatives' => 7,
        ],
    ];

    foreach ($datasets as $ds) {
        $ads = [];
        for ($i = 0; $i < $ds['creatives']; $i++) {
            $ads[] = [
                'id' => $ds['slug'] . '-ad-' . $i,
                'provider' => $ds['platform'],
                'advertiser' => $ds['advertiser'],
                'title' => 'Criativo ' . ($i + 1) . ' - ' . $ds['name'],
                'text' => 'Texto de exemplo do anúncio ' . ($i + 1) . ' com promessa ética e chamada para ação.',
                'cta' => 'Saiba mais',
                'thumbnail' => 'https://picsum.photos/seed/' . $ds['slug'] . $i . '/300/200',
                'landing_page' => $ds['source_url'],
                'link' => 'https://facebook.com/ads/library/?id=' . (1000 + $i),
                'status' => 'active',
                'started_at' => '2026-09-0' . (($i % 8) + 1),
            ];
        }

        $result = $manager->upsert([
            'slug' => $ds['slug'],
            'name' => $ds['name'],
            'advertiser' => $ds['advertiser'],
            'domain' => $ds['domain'],
            'source_url' => $ds['source_url'],
            'platform' => $ds['platform'],
            'traffic_sources' => $ds['traffic'],
            'thumbnail_url' => 'https://picsum.photos/seed/' . $ds['slug'] . '/400/300',
        ], $ads);

        if (empty($result['offer'])) {
            echo '  ERRO em ' . $ds['name'] . ': ' . ($result['error'] ?? 'desconhecido') . "\n";
            continue;
        }

        $offer = $result['offer'];
        $offer->niche = $ds['niche'];
        $offer->structure = $ds['structure'];
        $offer->language = 'pt';
        $offer->ads_count = $ds['count'];
        $offer->ads_count_prev = $ds['metrics'][count($ds['metrics']) - 2];
        $offer->scale_pct = (int)round((($ds['count'] - $offer->ads_count_prev) / max(1, $offer->ads_count_prev)) * 100);
        $offer->score = min(100, 40 + (int)round($offer->scale_pct * 1.2) + (int)round($ds['count'] / 4));
        $offer->ai_summary = 'Oferta de demonstração no nicho de ' . $ds['niche'] . ' com estrutura ' . $ds['structure'] . '. Métricas simuladas para fins de teste da plataforma.';
        $offer->ai_data = ['nicho' => $ds['niche'], 'estrutura' => $ds['structure'], 'score' => $offer->score, 'resumo' => $offer->ai_summary];
        $offer->save();

        $base = time() - count($ds['metrics']) * 86400;
        foreach ($ds['metrics'] as $i => $value) {
            \AfiliaFacil\Models\OfferMetric::create([
                'offer_id' => $offer->id,
                'ads_count' => $value,
                'captured_at' => date('Y-m-d H:i:s', $base + $i * 86400),
            ]);
        }

        $manager->approve((int)$offer->id);
        echo '  criada: ' . $ds['name'] . ' (' . $ds['count'] . ' anúncios, score ' . $offer->score . ')' . "\n";
    }

    echo "\nCriando página de exemplo (conta Master)...\n";
    $masterId = $userIds['master'] ?? 0;
    if ($masterId > 0) {
        $pm = new PageManager();
        $existing = null;
        foreach ($pm->listByUser($masterId) as $page) {
            if ($page['name'] === DEMO_PAGE_NAME) {
                $existing = $page;
                break;
            }
        }

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Página de Exemplo - Demonstração</title>
<style>
body { font-family: Arial, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
.hero { max-width: 720px; margin: 0 auto; padding: 60px 24px; text-align: center; }
h1 { font-size: 2.2rem; line-height: 1.2; margin-bottom: 16px; }
p { font-size: 1.05rem; line-height: 1.7; color: #94a3b8; }
.cta { display: inline-block; margin-top: 24px; background: #22c55e; color: #052e16; font-weight: bold; padding: 16px 36px; border-radius: 12px; text-decoration: none; font-size: 1.1rem; }
.box { background: #1e293b; border-radius: 16px; padding: 28px; margin-top: 40px; text-align: left; }
ul { line-height: 2; color: #cbd5e1; }
</style>
</head>
<body>
<div class="hero">
<h1>Página de Exemplo para Demonstração</h1>
<p>Esta é uma página criada pelo seed de homologação para você testar o editor, o preview, o ZIP e a publicação.</p>
<div class="box">
<strong>O que testar aqui:</strong>
<ul>
<li>Editar o código no editor online</li>
<li>Alterar o link de afiliado e ver os CTAs</li>
<li>Baixar o ZIP e abrir localmente</li>
<li>Trocar o status (ativa/rascunho)</li>
</ul>
</div>
<a class="cta" href="https://exemplo.com/seu-link-de-afiliado" target="_blank">QUERO MEU ACESSO AGORA</a>
</div>
</body>
</html>
HTML;

        if ($existing) {
            $pm->update($existing['id'], ['html' => $html, 'status' => 'active']);
            echo '  página atualizada: ' . DEMO_PAGE_NAME . ' (ID ' . $existing['id'] . ')' . "\n";
        } else {
            $page = $pm->create([
                'id' => time() + random_int(1, 9999),
                'user_id' => $masterId,
                'name' => DEMO_PAGE_NAME,
                'type' => 'clone',
                'html' => $html,
                'source_domain' => 'demo.exemplo.com',
                'failed_assets' => [],
                'cloner_version' => 'demo-seed',
                'affiliate_link' => 'https://exemplo.com/seu-link-de-afiliado',
                'status' => 'active',
            ]);
            echo '  página criada: ' . DEMO_PAGE_NAME . ' (ID ' . $page['id'] . ')' . "\n";
        }
    }

    echo "\n=== SEED DEMO CONCLUÍDO ===\n";
    echo "Contas (senha individual por conta):\n";
    foreach (DEMO_ACCOUNTS as $account) {
        echo '  - ' . $account['email'] . ' (' . $account['plan'] . ') -> senha: ' . $account['password'] . "\n";
    }
    echo "\nOfertas: " . \AfiliaFacil\Models\Offer::where('slug', 'like', 'demo-%')->count() . " no swipe file (aprovadas)\n";
    echo "Para remover: php bin/seed-demo.php --clean\n";
}

if ($clean) {
    demo_clean();
} else {
    demo_create();
}
