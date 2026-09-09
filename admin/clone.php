<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';

Auth::requireAuth();

$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clonador de Páginas - AfiliaFacil</title>
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
                <div class="topbar-title">Clonador de Páginas</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Clonador de Páginas</h1>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-link"></i> Clonar por URL</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Cole a URL de qualquer página de vendas. O sistema vai copiar tudo — HTML, CSS, imagens, fonts — para o seu servidor.
                            </div>
                            <form id="cloneUrlForm">
                                <div class="form-group">
                                    <label>URL da página para clonar</label>
                                    <input type="url" name="source_url" class="form-control" placeholder="https://exemplo.com/pagina-de-vendas" required>
                                </div>
                                <div class="form-group">
                                    <label>Seu link de afiliado (para substituir CTAs)</label>
                                    <input type="url" name="affiliate_link" class="form-control" placeholder="https://go.hotmart.com/seu-produto">
                                </div>
                                <div class="form-group">
                                    <label>Nome da página</label>
                                    <input type="text" name="page_name" class="form-control" placeholder="Minha Landing Page">
                                </div>
                                <button type="submit" class="btn btn-primary btn-full" id="cloneBtn">
                                    <i class="fas fa-clone"></i> Clonar Página
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-code"></i> Clonar por HTML</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Cole o código-fonte completo da página. Ideal quando a URL original bloqueia acesso direto.
                            </div>
                            <form id="cloneHtmlForm">
                                <div class="form-group">
                                    <label>Código HTML da página</label>
                                    <textarea name="source_html" class="form-control" rows="8" placeholder="<!DOCTYPE html>..." style="font-family:monospace;font-size:.85rem;"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Seu link de afiliado</label>
                                    <input type="url" name="affiliate_link" class="form-control" placeholder="https://go.hotmart.com/seu-produto">
                                </div>
                                <div class="form-group">
                                    <label>Nome da página</label>
                                    <input type="text" name="page_name" class="form-control" placeholder="Minha Landing Page">
                                </div>
                                <button type="submit" class="btn btn-primary btn-full" id="cloneHtmlBtn">
                                    <i class="fas fa-clone"></i> Clonar de HTML
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="cloneResult" style="display:none;margin-top:24px;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-check-circle" style="color:var(--success);"></i> Clonagem Concluída!</h3>
                        </div>
                        <div class="card-body">
                            <div id="cloneResultContent"></div>
                        </div>
                    </div>
                </div>

                <div id="cloneLoading" style="display:none;margin-top:24px;">
                    <div class="card">
                        <div class="card-body" style="text-align:center;padding:40px;">
                            <div style="font-size:2rem;margin-bottom:16px;"><i class="fas fa-spinner fa-spin" style="color:var(--accent);"></i></div>
                            <h3>Clonando página...</h3>
                            <p style="color:var(--text-secondary);margin-top:8px;">Baixando assets, processando CSS/imagens e substituindo CTAs. Isso pode levar alguns segundos.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
    <script>
    document.getElementById('cloneUrlForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('cloneBtn');
        const loading = document.getElementById('cloneLoading');
        const result = document.getElementById('cloneResult');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clonando...';
        loading.style.display = 'block';
        result.style.display = 'none';

        try {
            const formData = new FormData(form);
            formData.append('action', 'clone_url');
            const resp = await fetch('/admin/api/clone.php', { method: 'POST', body: formData });
            const data = await resp.json();

            if (data.success) {
                result.style.display = 'block';
                document.getElementById('cloneResultContent').innerHTML = `
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Página clonada com sucesso!</div>
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-value" style="font-size:1.2rem;">${data.page.name}</div><div class="stat-label">Nome</div></div>
                        <div class="stat-card"><div class="stat-value" style="font-size:1.2rem;">${data.page.views || 0}</div><div class="stat-label">Tamanho original</div></div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:16px;">
                        <a href="/admin/pages.php?action=edit&id=${data.page.id}" class="btn btn-primary"><i class="fas fa-pen"></i> Editar</a>
                        <a href="/admin/preview.php?id=${data.page.id}" class="btn btn-outline" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                        <a href="/admin/download.php?id=${data.page.id}" class="btn btn-outline"><i class="fas fa-download"></i> Baixar ZIP</a>
                    </div>
                `;
            } else {
                result.style.display = 'block';
                document.getElementById('cloneResultContent').innerHTML = `
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Erro: ${data.error || 'Falha ao clonar página'}</div>
                `;
            }
        } catch(err) {
            result.style.display = 'block';
            document.getElementById('cloneResultContent').innerHTML = `
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Erro de conexão: ${err.message}</div>
            `;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-clone"></i> Clonar Página';
            loading.style.display = 'none';
        }
    });

    document.getElementById('cloneHtmlForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('cloneHtmlBtn');
        const loading = document.getElementById('cloneLoading');
        const result = document.getElementById('cloneResult');

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clonando...';
        loading.style.display = 'block';
        result.style.display = 'none';

        try {
            const formData = new FormData(form);
            formData.append('action', 'clone_html');
            const resp = await fetch('/admin/api/clone.php', { method: 'POST', body: formData });
            const data = await resp.json();

            if (data.success) {
                result.style.display = 'block';
                document.getElementById('cloneResultContent').innerHTML = `
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Página clonada com sucesso!</div>
                    <div style="display:flex;gap:8px;margin-top:16px;">
                        <a href="/admin/pages.php?action=edit&id=${data.page.id}" class="btn btn-primary"><i class="fas fa-pen"></i> Editar</a>
                        <a href="/admin/preview.php?id=${data.page.id}" class="btn btn-outline" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                        <a href="/admin/download.php?id=${data.page.id}" class="btn btn-outline"><i class="fas fa-download"></i> Baixar ZIP</a>
                    </div>
                `;
            } else {
                result.style.display = 'block';
                document.getElementById('cloneResultContent').innerHTML = `
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Erro: ${data.error || 'Falha ao clonar página'}</div>
                `;
            }
        } catch(err) {
            result.style.display = 'block';
            document.getElementById('cloneResultContent').innerHTML = `
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Erro de conexão: ${err.message}</div>
            `;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-clone"></i> Clonar de HTML';
            loading.style.display = 'none';
        }
    });
    </script>
</body>
</html>
