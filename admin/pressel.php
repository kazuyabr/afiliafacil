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
    <title>Gerador de Pressel - AfiliaFacil</title>
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
                <div class="topbar-title">Gerador de Pressel</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Gerador de Pressel</h1></div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-steam"></i> Nova Pressel</h3></div>
                    <div class="card-body">
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> Escolha um modelo, cole seu link de afiliado e pronto! A pressel será gerada em HTML puro com carregamento instantâneo.</div>
                        <div class="form-group">
                            <label>Modelo</label>
                            <select class="form-control">
                                <option value="">Selecione um modelo...</option>
                                <option>Review com IA</option>
                                <option>Pressel Robusta</option>
                                <option>Promoção Simples</option>
                                <option>Press & Hold</option>
                                <option>Cupom de Desconto</option>
                                <option>Formulário</option>
                                <option>VSL com Delay</option>
                                <option>Captcha</option>
                                <option>Cookie</option>
                                <option>Idade</option>
                                <option>Países</option>
                                <option>COD</option>
                                <option>Simples</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Link de Afiliado</label>
                            <input type="url" class="form-control" placeholder="https://go.hotmart.com/seu-produto">
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Cor Principal</label>
                                <input type="color" class="form-control" value="#0d6efd" style="height:42px;padding:4px;">
                            </div>
                            <div class="form-group">
                                <label>Texto do Botão</label>
                                <input type="text" class="form-control" value="COMPRAR AGORA" placeholder="Texto do botão CTA">
                            </div>
                        </div>
                        <button class="btn btn-primary"><i class="fas fa-magic"></i> Gerar Pressel</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-th"></i> Modelos Disponíveis</h3></div>
                    <div class="card-body">
                        <div class="grid-4">
                            <?php
                            $templates = [
                                ['name' => 'Review IA', 'icon' => 'fas fa-robot', 'color' => '#6f42c1'],
                                ['name' => 'Robusta', 'icon' => 'fas fa-shield-alt', 'color' => '#198754'],
                                ['name' => 'Promoção', 'icon' => 'fas fa-percentage', 'color' => '#dc3545'],
                                ['name' => 'VSL Delay', 'icon' => 'fas fa-play', 'color' => '#fd7e14'],
                                ['name' => 'Cupom', 'icon' => 'fas fa-ticket-alt', 'color' => '#ffc107'],
                                ['name' => 'Simples', 'icon' => 'fas fa-minus', 'color' => '#6c757d'],
                                ['name' => 'Cookie', 'icon' => 'fas fa-cookie', 'color' => '#0dcaf0'],
                                ['name' => 'Formulário', 'icon' => 'fas fa-edit', 'color' => '#0d6efd'],
                            ];
                            foreach ($templates as $t): ?>
                            <div class="feature-card">
                                <div class="feature-icon" style="background:<?= $t['color'] ?>22;color:<?= $t['color'] ?>;">
                                    <i class="<?= $t['icon'] ?>"></i>
                                </div>
                                <h3><?= $t['name'] ?></h3>
                                <button class="btn btn-sm btn-outline" style="margin-top:8px;">Usar</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
