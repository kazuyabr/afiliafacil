<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AfiliaFacil - Clone, Crie e Hospede Páginas para Afiliados</title>
    <meta name="description" content="Clone páginas de vendas, gere pressels e hospede sua estrutura de afiliado em minutos. Plano grátis por 3 dias.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .lp-header { position: fixed; top: 0; left: 0; right: 0; height: 64px; background: rgba(255,255,255,.92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border-color); z-index: 100; }
        body[data-theme="dark"] .lp-header { background: rgba(10,12,16,.92); }
        .lp-header-inner { max-width: 1100px; margin: 0 auto; height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .lp-logo { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 1.15rem; color: var(--text-primary); }
        .lp-logo-box { width: 34px; height: 34px; border-radius: 8px; background: var(--accent); display: flex; align-items: center; justify-content: center; color: #fff; }
        .lp-nav { display: flex; align-items: center; gap: 24px; font-size: .9rem; }
        .lp-nav a { color: var(--text-secondary); }
        .lp-nav a:hover { color: var(--accent); }
        .lp-hero { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #fff; padding: 120px 20px 80px; text-align: center; }
        .lp-hero h1 { font-size: 2.8rem; font-weight: 800; max-width: 800px; margin: 0 auto 16px; line-height: 1.15; }
        .lp-hero p { font-size: 1.15rem; opacity: .9; max-width: 620px; margin: 0 auto 32px; line-height: 1.6; }
        .lp-hero-badges { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; margin-top: 40px; opacity: .85; font-size: .85rem; }
        .lp-section { max-width: 1100px; margin: 0 auto; padding: 70px 20px; }
        .lp-section-title { text-align: center; margin-bottom: 48px; }
        .lp-section-title h2 { font-size: 2rem; font-weight: 700; margin-bottom: 10px; }
        .lp-section-title p { color: var(--text-secondary); max-width: 600px; margin: 0 auto; }
        .lp-features { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .lp-feature { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 24px; transition: all .2s; }
        .lp-feature:hover { border-color: var(--accent); transform: translateY(-2px); }
        .lp-feature i { font-size: 1.5rem; color: var(--accent); margin-bottom: 12px; }
        .lp-feature h3 { font-size: 1rem; margin-bottom: 6px; }
        .lp-feature p { font-size: .85rem; color: var(--text-secondary); line-height: 1.45; }
        .lp-plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; align-items: stretch; }
        .lp-plan { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 28px 24px; display: flex; flex-direction: column; position: relative; }
        .lp-plan.featured { border-color: var(--accent); box-shadow: 0 8px 24px rgba(13,110,253,.15); }
        .lp-plan .tag { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--accent); color: #fff; font-size: .7rem; font-weight: 600; padding: 4px 14px; border-radius: 20px; }
        .lp-plan h3 { font-size: 1.05rem; }
        .lp-plan .price { font-size: 2.2rem; font-weight: 800; margin: 10px 0 2px; }
        .lp-plan .price small { font-size: .9rem; font-weight: 500; color: var(--text-secondary); }
        .lp-plan ul { list-style: none; margin: 18px 0 24px; padding: 0; flex: 1; }
        .lp-plan ul li { padding: 7px 0; font-size: .88rem; display: flex; gap: 10px; align-items: center; }
        .lp-plan ul li i { color: var(--success); font-size: .8rem; }
        .lp-plan ul li.no i { color: var(--text-secondary); }
        .lp-plan ul li.no { color: var(--text-secondary); }
        .lp-faq { max-width: 760px; margin: 0 auto; }
        .lp-faq details { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius); margin-bottom: 10px; padding: 14px 18px; }
        .lp-faq summary { font-weight: 600; font-size: .95rem; cursor: pointer; }
        .lp-faq p { font-size: .9rem; color: var(--text-secondary); margin-top: 10px; line-height: 1.5; }
        .lp-footer { border-top: 1px solid var(--border-color); padding: 32px 20px; text-align: center; font-size: .85rem; color: var(--text-secondary); }
        @media (max-width: 768px) { .lp-hero h1 { font-size: 2rem; } .lp-nav { display: none; } }
    </style>
</head>
<body data-theme="<?= $theme ?>">
    <header class="lp-header">
        <div class="lp-header-inner">
            <div class="lp-logo"><div class="lp-logo-box"><i class="fas fa-bolt"></i></div> AfiliaFacil</div>
            <nav class="lp-nav">
                <a href="#features">Funcionalidades</a>
                <a href="#plans">Planos</a>
                <a href="#faq">FAQ</a>
                <?php if (Auth::check()): ?>
                    <a href="/admin/" class="btn btn-sm btn-primary">Ir para o painel</a>
                <?php else: ?>
                    <a href="/login">Entrar</a>
                    <a href="/register" class="btn btn-sm btn-primary">Criar conta grátis</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <section class="lp-hero">
        <h1>Clone, Crie e Hospede Páginas que Vendem</h1>
        <p>A forma mais fácil de vender online como afiliado. Clone qualquer página de vendas, gere pressels e hospede sua estrutura em minutos. Sem código. Sem complicação.</p>
        <a href="/register" class="btn btn-primary btn-full" style="max-width:300px;margin:0 auto;font-size:1rem;padding:14px 28px;display:inline-flex;">
            <i class="fas fa-rocket"></i> Começar grátis por 3 dias
        </a>
        <div class="lp-hero-badges">
            <span><i class="fas fa-check-circle"></i> Clonador de páginas</span>
            <span><i class="fas fa-check-circle"></i> Pressel & Player</span>
            <span><i class="fas fa-check-circle"></i> Domínio próprio</span>
            <span><i class="fas fa-check-circle"></i> Sem WordPress</span>
        </div>
    </section>

    <section class="lp-section" id="features">
        <div class="lp-section-title">
            <h2>Principais funcionalidades</h2>
            <p>Tudo o que você precisa para profissionalizar sua operação de afiliado em uma única plataforma.</p>
        </div>
        <div class="lp-features">
            <?php
            $features = [
                ['icon' => 'fas fa-clone', 'title' => 'Clonador de Página', 'desc' => 'Clone qualquer página de vendas inteira — HTML, CSS, imagens e fonts. Sua página nunca quebra, mesmo que a original saia do ar.'],
                ['icon' => 'fas fa-steam', 'title' => 'Gerador de Pressel', 'desc' => 'Modelos prontos de pressel para afiliados em HTML puro, com carregamento instantâneo.'],
                ['icon' => 'fas fa-play-circle', 'title' => 'Player de Vídeo', 'desc' => 'Player de alta conversão com autoplay, fakebar e limite zero de play\'s e banda.'],
                ['icon' => 'fas fa-chart-line', 'title' => 'Rastreamento (Pixel)', 'desc' => 'Instale Meta Pixel, Google ADS e TikTok Pixel. API de Conversão Meta ADS inclusa.'],
                ['icon' => 'fas fa-cookie-bite', 'title' => 'Marcação de Cookie', 'desc' => 'Marque cookie de afiliado e receba comissão mesmo se a compra ocorrer depois.'],
                ['icon' => 'fas fa-undo', 'title' => 'Back Redirect', 'desc' => 'Impeça a saída do visitante e redirecione para uma promoção especial.'],
                ['icon' => 'fas fa-hourglass-half', 'title' => 'Delay & VSL', 'desc' => 'Oculte elementos e revele no momento psicológico ideal, sincronizado com o vídeo.'],
                ['icon' => 'fas fa-globe', 'title' => 'Domínio Próprio', 'desc' => 'Use seu domínio ou subdomínio próprio com SSL e hospedagem premium inclusa.'],
                ['icon' => 'fas fa-plug', 'title' => 'Integrações', 'desc' => 'ManyChat, Mailchimp, ActiveCampaign e muitos outros. Zapier e webhooks.'],
            ];
            foreach ($features as $f): ?>
            <div class="lp-feature">
                <i class="<?= $f['icon'] ?>"></i>
                <h3><?= $f['title'] ?></h3>
                <p><?= $f['desc'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="lp-section" id="plans" style="background:var(--bg-secondary);">
        <div class="lp-section-title">
            <h2>Planos e preços</h2>
            <p>Experimente grátis por 3 dias em qualquer plano. Sem fidelidade, cancele quando quiser.</p>
        </div>
        <div class="lp-plans">
            <?php
            $plans = [
                ['id' => 'vsl', 'name' => 'VSL', 'price' => 79, 'class' => '', 'items' => [
                    ['Página de VSL com delay', true],
                    ['Clonador de páginas', true],
                    ['Player de vídeo', true],
                    ['1 página', true],
                    ['1 domínio', true],
                    ['Pressel', false],
                    ['Pixel & rastreamento', false],
                ]],
                ['id' => 'essencial', 'name' => 'Essencial', 'price' => 119, 'class' => 'featured', 'tag' => 'Mais popular', 'items' => [
                    ['Clonador de páginas', true],
                    ['Pressel', true],
                    ['Player de vídeo', true],
                    ['Pixel & rastreamento', true],
                    ['Cookie & Back Redirect', true],
                    ['5 páginas', true],
                    ['2 domínios', true],
                    ['Integrações', false],
                ]],
                ['id' => 'master', 'name' => 'Master', 'price' => 149, 'class' => '', 'items' => [
                    ['Tudo do Essencial', true],
                    ['Integrações (ManyChat, Mailchimp)', true],
                    ['Quizz', true],
                    ['Páginas ilimitadas', true],
                    ['10 domínios', true],
                    ['Suporte prioritário', true],
                ]],
            ];
            foreach ($plans as $p): ?>
            <div class="lp-plan <?= $p['class'] ?>">
                <?php if (!empty($p['tag'])): ?><span class="tag"><?= $p['tag'] ?></span><?php endif; ?>
                <h3><?= $p['name'] ?></h3>
                <div class="price"><?= Plans::formatPrice($p['price']) ?><small>/mês</small></div>
                <ul>
                    <?php foreach ($p['items'] as $item): ?>
                    <li class="<?= $item[1] ? '' : 'no' ?>"><i class="fas fa-<?= $item[1] ? 'check' : 'times' ?>"></i> <?= $item[0] ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="/register" class="btn btn-<?= $p['class'] === 'featured' ? 'primary' : 'outline' ?> btn-full">Começar grátis</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="lp-section" id="faq">
        <div class="lp-section-title">
            <h2>Dúvidas frequentes</h2>
        </div>
        <div class="lp-faq">
            <details open>
                <summary>Já tenho um domínio! Posso usá-lo?</summary>
                <p>Sim! Você pode usar qualquer domínio comprado em HostGator, GoDaddy, Registro.br, Umbler ou qualquer outro registrador. Basta apontar o DNS conforme instruções do painel.</p>
            </details>
            <details>
                <summary>Quantas páginas posso clonar?</summary>
                <p>Depende do seu plano: 1 página no VSL, 5 páginas no Essencial e ilimitadas no Master. A clonagem em si não tem limite de páginas por domínio.</p>
            </details>
            <details>
                <summary>Preciso de hospedagem ou WordPress?</summary>
                <p>Não! A AfiliaFacil hospeda tudo em servidores de alta performance. Sem WordPress, sem plugins, sem dor de cabeça.</p>
            </details>
            <details>
                <summary>Como funciona o teste grátis?</summary>
                <p>Ao criar sua conta você tem 3 dias de acesso a um plano sem custo. Após o período, escolha o plano que deseja assinar para continuar usando a plataforma.</p>
            </details>
            <details>
                <summary>Posso cancelar a qualquer momento?</summary>
                <p>Sim! Sem fidelidade e sem multa. Você pode cancelar quando quiser direto no painel.</p>
            </details>
        </div>
    </section>

    <footer class="lp-footer">
        <p>AfiliaFacil &copy; <?= date('Y') ?> — Todos os direitos reservados. <a href="/login">Entrar</a> · <a href="/register">Criar conta</a></p>
    </footer>
</body>
</html>
