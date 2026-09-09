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
    <title>Marcação de Cookie - AfiliaFacil</title>
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
                <div class="topbar-title">Marcação de Cookie</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Marcação de Cookie</h1></div>
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-cookie-bite"></i> Configurar Cookie de Afiliado</h3></div>
                    <div class="card-body">
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> Marque o cookie de afiliado no navegador de todos os visitantes. Mesmo que o usuário saia da sua página e compre depois, você receberá a comissão.</div>
                        <div class="form-group">
                            <label>ID do Afiliado / Parâmetro</label>
                            <input type="text" class="form-control" placeholder="Ex: afid ou utm_source">
                        </div>
                        <div class="form-group">
                            <label>Nome do Cookie</label>
                            <input type="text" class="form-control" value="afiliafacil_ref" placeholder="afiliafacil_ref">
                        </div>
                        <div class="form-group">
                            <label>Dias de validade</label>
                            <input type="number" class="form-control" value="30" placeholder="30">
                        </div>
                        <button class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
