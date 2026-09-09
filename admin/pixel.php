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
    <title>Rastreamento (Pixel) - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-<?= $theme ?>.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Rastreamento de Tráfego</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Rastreamento (Pixel)</h1></div>

                <div class="grid-3">
                    <div class="card">
                        <div class="card-header"><h3><i class="fab fa-facebook" style="color:#1877f2;"></i> Meta Pixel</h3></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Pixel ID</label>
                                <input type="text" class="form-control" placeholder="Ex: 123456789">
                            </div>
                            <div class="form-group">
                                <label>API de Conversão (Access Token)</label>
                                <input type="text" class="form-control" placeholder="Token da API de conversão">
                            </div>
                            <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salvar</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fab fa-google" style="color:#4285f4;"></i> Google ADS</h3></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Conversion ID</label>
                                <input type="text" class="form-control" placeholder="Ex: AW-123456789">
                            </div>
                            <div class="form-group">
                                <label>Conversion Label</label>
                                <input type="text" class="form-control" placeholder="Label do evento">
                            </div>
                            <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salvar</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fab fa-tiktok" style="color:#000;"></i> TikTok Pixel</h3></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Pixel ID</label>
                                <input type="text" class="form-control" placeholder="ID do Pixel TikTok">
                            </div>
                            <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salvar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
