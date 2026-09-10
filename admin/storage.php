<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';

Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Armazenamento (R2) - AfiliaFacil</title>
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
                <div class="topbar-title">Armazenamento</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Armazenamento de Mídias (Cloudflare R2)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Salve as mídias clonadas no seu bucket R2 e sirva via CDN — páginas mais leves e independentes da origem.</p>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-database"></i> Modo de mídia</h3></div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Base64</strong>: mídias embutidas no HTML (seguro, porém pesado) ·
                            <strong>R2</strong>: mídias no seu bucket/CDN (leve, recomendado) ·
                            <strong>Original</strong>: mantém links do site clonado (leve, mas depende da origem estar no ar)
                        </div>
                        <div class="form-group">
                            <label>Modo</label>
                            <select id="mediaMode" class="form-control" style="max-width:320px;">
                                <option value="base64">Base64 (padrão)</option>
                                <option value="r2">Cloudflare R2</option>
                                <option value="original">Link original (via proxy)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fab fa-cloudflare" style="color:#f6821f;"></i> Credenciais do R2</h3>
                        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                            <input type="checkbox" id="enabled"> Ativo
                        </label>
                    </div>
                    <div class="card-body">
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Account ID</label>
                                <input type="text" id="accountId" class="form-control" placeholder="ex: a1b2c3d4e5f6...">
                            </div>
                            <div class="form-group">
                                <label>Bucket</label>
                                <input type="text" id="bucket" class="form-control" placeholder="ex: afiliafacil-midias">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Access Key ID</label>
                                <input type="text" id="accessKey" class="form-control" placeholder="chave de acesso">
                            </div>
                            <div class="form-group">
                                <label>Secret Access Key <small id="secretHint" style="color:var(--text-secondary);"></small></label>
                                <input type="password" id="secretKey" class="form-control" placeholder="deixe vazio para manter">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>URL Pública (CDN / r2.dev / domínio custom)</label>
                            <input type="text" id="publicUrl" class="form-control" placeholder="https://pub-xxxx.r2.dev ou https://cdn.seudominio.com">
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save"></i> Salvar configuração</button>
                            <button class="btn btn-outline" onclick="testConfig()"><i class="fas fa-plug"></i> Testar conexão</button>
                        </div>
                        <div id="testResult" style="margin-top:12px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    async function loadConfig() {
        const resp = await fetch('/admin/api/storage.php?action=get');
        const data = await resp.json();
        if (!data.success) return;
        const c = data.config;
        document.getElementById('accountId').value = c.account_id;
        document.getElementById('accessKey').value = c.access_key;
        document.getElementById('bucket').value = c.bucket;
        document.getElementById('publicUrl').value = c.public_url;
        document.getElementById('mediaMode').value = c.media_mode;
        document.getElementById('enabled').checked = c.enabled;
        document.getElementById('secretHint').textContent = c.has_secret ? '(configurado — deixe vazio para manter)' : '';
    }

    function collect() {
        const body = new URLSearchParams();
        body.append('account_id', document.getElementById('accountId').value);
        body.append('access_key', document.getElementById('accessKey').value);
        body.append('secret_key', document.getElementById('secretKey').value);
        body.append('bucket', document.getElementById('bucket').value);
        body.append('public_url', document.getElementById('publicUrl').value);
        body.append('media_mode', document.getElementById('mediaMode').value);
        body.append('enabled', document.getElementById('enabled').checked ? '1' : '0');
        return body;
    }

    async function saveConfig() {
        const body = collect();
        body.append('action', 'save');
        const resp = await fetch('/admin/api/storage.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Configuração salva!', 'success'); loadConfig(); }
        else showToast(data.error || 'Erro ao salvar', 'error');
    }

    async function testConfig() {
        const body = collect();
        body.append('action', 'test');
        const el = document.getElementById('testResult');
        el.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
        const resp = await fetch('/admin/api/storage.php', { method: 'POST', body });
        const data = await resp.json();
        el.innerHTML = data.ok
            ? '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>'
            : '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Falha no teste') + '</div>';
    }

    loadConfig();
    </script>
</body>
</html>
