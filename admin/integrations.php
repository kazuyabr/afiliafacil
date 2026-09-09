<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrações - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Integrações</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Integrações</h1></div>
                <div class="grid-3">
                    <?php
                    $integrations = [
                        ['name' => 'ManyChat', 'icon' => 'fas fa-robot', 'color' => '#00b0f0', 'desc' => 'Automação de mensagens WhatsApp e Messenger'],
                        ['name' => 'Mailchimp', 'icon' => 'fab fa-mailchimp', 'color' => '#ffe01b', 'desc' => 'Email marketing e sequências automáticas'],
                        ['name' => 'ActiveCampaign', 'icon' => 'fas fa-envelope-open-text', 'color' => '#356ae6', 'desc' => 'Automação de marketing e CRM'],
                        ['name' => 'Zapier', 'icon' => 'fas fa-bolt', 'color' => '#ff4a00', 'desc' => 'Conecte +5000 apps sem código'],
                        ['name' => 'Webhook', 'icon' => 'fas fa-plug', 'color' => '#6f42c1', 'desc' => 'Envie dados para qualquer API'],
                        ['name' => 'Google Sheets', 'icon' => 'fas fa-table', 'color' => '#0f9d58', 'desc' => 'Salve leads direto em planilhas'],
                    ];
                    foreach ($integrations as $int): ?>
                    <div class="card">
                        <div class="card-body" style="text-align:center;padding:32px;">
                            <div style="width:56px;height:56px;border-radius:var(--radius-lg);background:<?= $int['color'] ?>22;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.5rem;color:<?= $int['color'] ?>;">
                                <i class="<?= $int['icon'] ?>"></i>
                            </div>
                            <h3><?= $int['name'] ?></h3>
                            <p style="font-size:.85rem;color:var(--text-secondary);margin:8px 0 16px;"><?= $int['desc'] ?></p>
                            <button class="btn btn-sm btn-outline"><i class="fas fa-link"></i> Conectar</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
