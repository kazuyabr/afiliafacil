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
    <title>Domínios - AfiliaFacil</title>
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
                <div class="topbar-title">Domínios</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Domínios</h1>
                    <button class="btn btn-primary" onclick="document.getElementById('addDomainModal').classList.add('active')"><i class="fas fa-plus"></i> Adicionar Domínio</button>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Configure seu domínio próprio para publicar suas páginas. Adicione os registros DNS indicados no seu registrador (HostGator, GoDaddy, Registro.br, etc).
                        </div>

                        <div class="empty-state">
                            <i class="fas fa-globe"></i>
                            <h3>Nenhum domínio configurado</h3>
                            <p>Adicione um domínio próprio para publicar suas páginas com endereço personalizado.</p>
                            <button class="btn btn-primary" onclick="document.getElementById('addDomainModal').classList.add('active')"><i class="fas fa-plus"></i> Adicionar primeiro domínio</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="addDomainModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Adicionar Domínio</h3>
                <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Domínio</label>
                    <input type="text" class="form-control" placeholder="seusite.com.br">
                </div>
                <div class="alert alert-warning">
                    <strong>DNS Records:</strong><br>
                    A record → IP do servidor<br>
                    CNAME → afiliafacil.com
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('active')">Cancelar</button>
                <button class="btn btn-primary"><i class="fas fa-plus"></i> Adicionar</button>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
</body>
</html>
