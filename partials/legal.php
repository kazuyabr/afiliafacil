<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Theme.php';

$legalTitle = $legalTitle ?? 'Documento Legal';
$legalContent = $legalContent ?? '';
$legalActive = $legalActive ?? '';

$companyName = Settings::get('company_name', 'AfiliaFacil');
$companyCnpj = Settings::get('company_cnpj', '');
$companyEmail = Settings::get('company_email', 'contato@afiliafacil.com');
$companyDpo = Settings::get('company_dpo_email', $companyEmail);
$companyAddress = Settings::get('company_address', '');
$updatedAt = Settings::get('legal_updated_at', date('d/m/Y'));
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= Theme::current() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= Theme::antiFlashScript() ?>
    <title><?= htmlspecialchars($legalTitle) ?> - <?= htmlspecialchars($companyName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .legal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .legal-header .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 1.05rem; color: var(--text-primary); text-decoration: none; }
        .legal-header .brand .logo { width: 34px; height: 34px; border-radius: 10px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; }
        .legal-nav { display: flex; gap: 14px; flex-wrap: wrap; font-size: .85rem; }
        .legal-nav a:not(.btn) { color: var(--text-secondary); text-decoration: none; padding: 4px 2px; }
        .legal-nav a:not(.btn).active, .legal-nav a:not(.btn):hover { color: var(--accent); }
        .legal-nav .btn-primary { color: #fff; }
        .legal-nav .btn-primary:hover { color: #fff; }
        .legal-wrap { max-width: 860px; margin: 0 auto; padding: 32px 24px 60px; }
        .legal-wrap h1 { font-size: 1.7rem; margin-bottom: 6px; }
        .legal-wrap .updated { font-size: .8rem; color: var(--text-secondary); margin-bottom: 24px; }
        .legal-wrap h2 { font-size: 1.1rem; margin: 28px 0 10px; }
        .legal-wrap h3 { font-size: .95rem; margin: 20px 0 8px; }
        .legal-wrap p, .legal-wrap li { font-size: .9rem; line-height: 1.75; color: var(--text-primary); }
        .legal-wrap ul { padding-left: 22px; }
        .legal-wrap table { width: 100%; border-collapse: collapse; font-size: .85rem; margin: 14px 0; }
        .legal-wrap th, .legal-wrap td { border: 1px solid var(--border-color); padding: 8px 10px; text-align: left; }
        .legal-wrap th { background: var(--bg-secondary); }
        .legal-footer { border-top: 1px solid var(--border-color); padding: 26px 24px; text-align: center; font-size: .82rem; color: var(--text-secondary); }
        .legal-footer a { color: var(--accent); text-decoration: none; }
    </style>
</head>
<body>
    <header class="legal-header">
        <a class="brand" href="/"><span class="logo"><i class="fas fa-bolt"></i></span> <?= htmlspecialchars($companyName) ?></a>
        <nav class="legal-nav">
            <a href="/termos" class="<?= $legalActive === 'termos' ? 'active' : '' ?>">Termos de Uso</a>
            <a href="/privacidade" class="<?= $legalActive === 'privacidade' ? 'active' : '' ?>">Privacidade</a>
            <a href="/cookies" class="<?= $legalActive === 'cookies' ? 'active' : '' ?>">Cookies</a>
            <a href="/degustacao" class="<?= $legalActive === 'degustacao' ? 'active' : '' ?>">Degustação</a>
            <?= Theme::toggleButton('theme-toggle', 'border:1px solid var(--border-color);') ?>
            <a href="/register" class="btn btn-sm btn-primary" style="padding:4px 12px;">Criar conta</a>
        </nav>
    </header>

    <main class="legal-wrap">
        <h1><?= htmlspecialchars($legalTitle) ?></h1>
        <p class="updated">Última atualização: <?= htmlspecialchars($updatedAt) ?></p>
        <?= $legalContent ?>
    </main>

    <footer class="legal-footer">
        <p>
            <?= htmlspecialchars($companyName) ?><?= $companyCnpj !== '' ? ' · CNPJ ' . htmlspecialchars($companyCnpj) : '' ?><?= $companyAddress !== '' ? ' · ' . htmlspecialchars($companyAddress) : '' ?><br>
            Contato: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>"><?= htmlspecialchars($companyEmail) ?></a> · Privacidade/LGPD: <a href="mailto:<?= htmlspecialchars($companyDpo) ?>"><?= htmlspecialchars($companyDpo) ?></a>
        </p>
        <p style="margin-top:8px;">
            <a href="/termos">Termos de Uso</a> · <a href="/privacidade">Política de Privacidade</a> · <a href="/cookies">Cookies</a> · <a href="/degustacao">Degustação</a>
        </p>
        <p style="margin-top:10px;font-size:.75rem;">Este documento é um modelo e deve ser revisado por profissional jurídico antes da publicação.</p>
    </footer>
    <script src="/assets/js/app.js"></script>
</body>
</html>
