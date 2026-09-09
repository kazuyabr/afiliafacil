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
    <title>Player de Vídeo - AfiliaFacil</title>
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
                <div class="topbar-title">Player de Vídeo</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Player de Vídeo</h1></div>

                <div class="grid-2">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-cog"></i> Configurações do Player</h3></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>URL do Vídeo (MP4 ou YouTube)</label>
                                <input type="url" class="form-control" placeholder="https://...">
                            </div>
                            <div class="form-group">
                                <label>Thumbnail / Poster</label>
                                <input type="url" class="form-control" placeholder="URL da imagem de capa">
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" style="width:auto;margin-right:6px;"> Autoplay (mudo)
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" style="width:auto;margin-right:6px;"> Loop
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" style="width:auto;margin-right:6px;"> Fakebar (barra de progresso falsa)
                                </label>
                            </div>
                            <div class="form-group">
                                <label>Esconder elementos após (segundos)</label>
                                <input type="number" class="form-control" placeholder="0 = desativado" value="0">
                            </div>
                            <button class="btn btn-primary"><i class="fas fa-save"></i> Gerar Código</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-eye"></i> Preview</h3></div>
                        <div class="card-body" style="text-align:center;padding:40px;background:#000;border-radius:var(--radius);">
                            <i class="fas fa-play-circle" style="font-size:4rem;color:rgba(255,255,255,.5);"></i>
                            <p style="color:rgba(255,255,255,.5);margin-top:12px;">Configure o player para ver o preview</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
