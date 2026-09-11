<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/AdSpy/AiConfig.php';

Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inteligência Artificial - AfiliaFacil</title>
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
                <div class="topbar-title">Inteligência Artificial</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Inteligência Artificial (BYOK)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Use a IA da plataforma (Cloudflare Workers AI) ou conecte seu próprio provider.</p>
                    </div>
                </div>

                <?php if (!AiConfig::isPlatformConfigured()): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> A IA da plataforma ainda não está configurada (CF_AI_TOKEN). Sem uma chave própria (BYOK), as análises IA ficarão indisponíveis.
                </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-robot"></i> Seu provider de IA</h3>
                        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                            <input type="checkbox" id="aiEnabled"> Ativo (usar meu provider)
                        </label>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Com o BYOK ativo, as análises usam a sua conta do provider escolhido — sem consumir a cota da plataforma.
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label>Provider <small style="color:var(--text-secondary);">(catálogo models.dev)</small></label>
                                <select id="aiProvider" class="form-control">
                                    <option value="cloudflare">Cloudflare Workers AI (plataforma)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Modelo</label>
                                <select id="aiModel" class="form-control">
                                    <option value="">Carregando catálogo...</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label>Base URL <small style="color:var(--text-secondary);">(apenas OpenAI-compatible)</small></label>
                                <input type="text" id="aiBaseUrl" class="form-control" placeholder="https://api.openai.com/v1">
                            </div>
                            <div class="form-group">
                                <label>API Key <small id="keyHint" style="color:var(--text-secondary);"></small></label>
                                <input type="password" id="aiApiKey" class="form-control" placeholder="deixe vazio para manter">
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="btn btn-primary" onclick="saveAi()"><i class="fas fa-save"></i> Salvar</button>
                            <button class="btn btn-outline" onclick="testAi()"><i class="fas fa-plug"></i> Testar conexão</button>
                        </div>
                        <div id="aiTestResult" style="margin-top:12px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let catalog = null;

    async function loadCatalog() {
        try {
            let resp = await fetch('https://models.dev/api.json').catch(() => null);
            if (!resp || !resp.ok) {
                resp = await fetch('/proxy.php?url=' + encodeURIComponent('https://models.dev/api.json'));
            }
            catalog = await resp.json();
        } catch (e) {
            catalog = null;
        }
        populateProviders();
    }

    function populateProviders() {
        const select = document.getElementById('aiProvider');
        select.innerHTML = '<option value="cloudflare">Cloudflare Workers AI (plataforma)</option>';

        if (catalog) {
            const preferred = ['openai', 'anthropic', 'google', 'openrouter', 'groq', 'deepseek', 'mistral', 'xai'];
            const ids = Object.keys(catalog).sort((a, b) => {
                const ia = preferred.indexOf(a), ib = preferred.indexOf(b);
                if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
                return a.localeCompare(b);
            });
            ids.forEach(id => {
                const provider = catalog[id];
                if (!provider || !provider.models) return;
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = (provider.name || id) + ' — ' + Object.keys(provider.models).length + ' modelos';
                select.appendChild(opt);
            });
        }
        updateModels();
    }

    function updateModels() {
        const providerId = document.getElementById('aiProvider').value;
        const modelSelect = document.getElementById('aiModel');
        modelSelect.innerHTML = '';

        if (providerId === 'cloudflare') {
            [['@cf/zai-org/glm-4.7-flash', 'GLM 4.7 Flash (grátis)'], ['@cf/google/gemma-4-26b-a4b-it', 'Gemma 4 26B (grátis)'], ['@cf/nvidia/nemotron-3-120b-a12b', 'Nemotron 3 120B (grátis)']]
                .forEach(([v, l]) => modelSelect.appendChild(new Option(l, v)));
            return;
        }

        if (catalog && catalog[providerId] && catalog[providerId].models) {
            const models = Object.keys(catalog[providerId].models).sort();
            models.forEach(m => modelSelect.appendChild(new Option(m, m)));
        } else {
            modelSelect.appendChild(new Option('(digite manualmente abaixo)', ''));
        }
    }

    async function loadConfig() {
        const resp = await fetch('/admin/api/ai-settings.php?action=get');
        const data = await resp.json();
        if (!data.success) return;
        const c = data.config;
        document.getElementById('aiEnabled').checked = c.enabled;
        document.getElementById('aiBaseUrl').value = c.base_url;
        document.getElementById('keyHint').textContent = c.has_key ? '(configurada — vazio mantém)' : '';

        if (c.provider && c.provider !== 'cloudflare') {
            document.getElementById('aiProvider').value = c.provider;
            updateModels();
            if (c.model) {
                const opt = new Option(c.model, c.model);
                document.getElementById('aiModel').appendChild(opt);
                document.getElementById('aiModel').value = c.model;
            }
        }
    }

    async function saveAi() {
        const body = new URLSearchParams();
        body.append('action', 'save');
        body.append('provider', document.getElementById('aiProvider').value);
        body.append('model', document.getElementById('aiModel').value);
        body.append('base_url', document.getElementById('aiBaseUrl').value);
        body.append('api_key', document.getElementById('aiApiKey').value);
        body.append('enabled', document.getElementById('aiEnabled').checked ? '1' : '0');

        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Configuração de IA salva!', 'success'); loadConfig(); }
        else showToast(data.error || 'Erro ao salvar', 'error');
    }

    async function testAi() {
        const el = document.getElementById('aiTestResult');
        el.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
        const body = new URLSearchParams({ action: 'test' });
        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        el.innerHTML = data.ok
            ? '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + data.message + '</div>'
            : '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Falha') + '</div>';
    }

    document.getElementById('aiProvider').addEventListener('change', updateModels);
    loadCatalog().then(loadConfig);
    </script>
</body>
</html>
