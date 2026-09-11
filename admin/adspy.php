<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AdSpy/AdSpyQuota.php';

Auth::requireAuth();

$user = Auth::user();
if (!Plans::hasFeature($user['plan'], 'adspy') && !Auth::isAdmin()) {
    header('Location: /admin/plan.php?upgrade=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$quotaSearch = AdSpyQuota::check((int)$user['id'], $user['plan'], AdSpyQuota::KIND_SEARCH);
$quotaAnalysis = AdSpyQuota::check((int)$user['id'], $user['plan'], AdSpyQuota::KIND_ANALYSIS);

$pageId = (int)($_GET['page'] ?? 0);
$pageName = '';
if ($pageId > 0) {
    $pm = new PageManager();
    $page = $pm->get($pageId);
    if ($page && Auth::canAccessPage($page)) {
        $pageName = $page['name'] . ' (' . ($page['source_domain'] ?: 'sem domínio') . ')';
    } else {
        $pageId = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espionar Anúncios - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .ad-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column; }
        .ad-card .ad-media { height: 180px; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .ad-card .ad-media img { width: 100%; height: 100%; object-fit: cover; }
        .ad-card .ad-media .no-media { color: var(--text-secondary); font-size: 2rem; }
        .ad-card .ad-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .ad-card .ad-advertiser { font-size: .8rem; font-weight: 600; color: var(--accent); }
        .ad-card .ad-text { font-size: .8rem; color: var(--text-primary); line-height: 1.4; max-height: 80px; overflow: hidden; }
        .ad-card .ad-meta { font-size: .7rem; color: var(--text-secondary); margin-top: auto; display: flex; justify-content: space-between; align-items: center; }
        .provider-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .provider-tab { padding: 8px 16px; border: 1px solid var(--border-color); border-radius: var(--radius); background: var(--bg-card); cursor: pointer; font-size: .85rem; display: flex; align-items: center; gap: 8px; }
        .provider-tab.active { border-color: var(--accent); background: var(--accent-light); color: var(--accent); font-weight: 500; }
        .provider-tab .count { background: var(--bg-secondary); padding: 1px 8px; border-radius: 10px; font-size: .75rem; }
        .quota-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: var(--radius); border: 1px solid var(--border-color); background: var(--bg-card); font-size: .85rem; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Espionar Anúncios</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Espionar Anúncios</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Centralize anúncios de Meta, Google e TikTok em um só lugar e descubra a estratégia dos concorrentes.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="quota-pill"><i class="fas fa-search"></i> Buscas: <strong id="quotaSearch"><?= $quotaSearch['limit'] === -1 ? 'ilimitado' : $quotaSearch['used'] . '/' . $quotaSearch['limit'] ?></strong></span>
                        <span class="quota-pill"><i class="fas fa-brain"></i> Análises IA: <strong id="quotaAnalysis"><?= $quotaAnalysis['limit'] === -1 ? 'ilimitado' : $quotaAnalysis['used'] . '/' . $quotaAnalysis['limit'] ?></strong></span>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-body">
                        <?php if ($pageId > 0): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-crosshairs"></i> Espionando a campanha de: <strong><?= htmlspecialchars($pageName) ?></strong>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Termo, domínio ou anunciante</label>
                            <div class="input-group">
                                <input type="text" id="adspyQuery" class="form-control" placeholder="ex: preguicaartificial.com.br, nome do produto, marca..." value="<?= $pageId > 0 ? '' : '' ?>">
                                <button class="btn btn-primary" onclick="runSearch()" id="searchBtn"><i class="fas fa-search"></i> Espionar</button>
                            </div>
                        </div>

                        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="meta" checked> <i class="fab fa-facebook" style="color:#1877f2;"></i> Meta</label>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="google" checked> <i class="fab fa-google" style="color:#4285f4;"></i> Google</label>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="tiktok" checked> <i class="fab fa-tiktok"></i> TikTok</label>
                            <select id="adspyCountry" class="form-control" style="width:auto;">
                                <option value="BR" selected>Brasil</option>
                                <option value="US">Estados Unidos</option>
                                <option value="PT">Portugal</option>
                                <option value="ALL">Todos</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="loading" style="display:none;">
                    <div class="card"><div class="card-body" style="text-align:center;padding:40px;">
                        <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--accent);"></i>
                        <p style="margin-top:12px;color:var(--text-secondary);">Consultando as bibliotecas de anúncios...</p>
                    </div></div>
                </div>

                <div id="errors"></div>

                <div id="results"></div>

                <div id="analysisCard" style="display:none;margin-top:24px;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-brain"></i> Análise de Estratégia (IA)</h3>
                            <span id="analysisProvider" style="font-size:.75rem;color:var(--text-secondary);"></span>
                        </div>
                        <div class="card-body" id="analysisBody"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    const PAGE_ID = <?= $pageId ?>;
    let currentAds = [];

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function selectedProviders() {
        return [...document.querySelectorAll('.provider-check:checked')].map(c => c.value);
    }

    async function runSearch() {
        const query = document.getElementById('adspyQuery').value.trim();
        if (!query) { showToast('Informe um termo, domínio ou anunciante', 'warning'); return; }

        const providers = selectedProviders();
        if (!providers.length) { showToast('Selecione ao menos uma plataforma', 'warning'); return; }

        document.getElementById('loading').style.display = 'block';
        document.getElementById('results').innerHTML = '';
        document.getElementById('errors').innerHTML = '';

        try {
            const body = new URLSearchParams();
            body.append('action', 'search');
            body.append('query', query);
            providers.forEach(p => body.append('providers[]', p));
            body.append('country', document.getElementById('adspyCountry').value);

            const resp = await fetch('/admin/api/adspy.php', { method: 'POST', body });
            const data = await resp.json();
            renderResults(data);
        } catch (err) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-danger">Erro de conexão: ' + esc(err.message) + '</div>';
        } finally {
            document.getElementById('loading').style.display = 'none';
        }
    }

    function renderResults(data) {
        const errors = data.errors || {};
        if (errors.quota) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' + esc(errors.quota) + '</div>';
            return;
        }
        Object.entries(errors).forEach(([pid, msg]) => {
            if (pid === 'query') return;
            document.getElementById('errors').innerHTML += '<div class="alert alert-warning"><strong>' + esc(pid) + ':</strong> ' + esc(msg) + '</div>';
        });

        const results = data.results || {};
        currentAds = [];
        Object.values(results).forEach(r => { (r.ads || []).forEach(a => currentAds.push(a)); });

        let html = '<div class="provider-tabs">';
        Object.entries(results).forEach(([pid, r]) => {
            html += '<button class="provider-tab" data-provider="' + esc(pid) + '" onclick="filterProvider(\'' + esc(pid) + '\')">' +
                esc(pid.toUpperCase()) + ' <span class="count">' + (r.ads || []).length + '</span></button>';
        });
        html += '<button class="provider-tab active" data-provider="all" onclick="filterProvider(\'all\')">Todos <span class="count">' + currentAds.length + '</span></button>';
        html += '</div>';

        html += '<div class="grid-3" id="adsGrid"></div>';
        document.getElementById('results').innerHTML = html;
        renderAds('all');
    }

    function renderAds(provider) {
        const grid = document.getElementById('adsGrid');
        const ads = provider === 'all' ? currentAds : currentAds.filter(a => a.provider === provider);
        if (!ads.length) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-search-minus"></i><h3>Nenhum anúncio encontrado</h3><p>Tente outro termo ou plataforma.</p></div>';
            return;
        }
        grid.innerHTML = ads.map(ad => {
            const media = ad.thumbnail || ad.media_url;
            return '<div class="ad-card">' +
                '<div class="ad-media">' + (media ? '<img src="' + esc(media) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-image no-media"></i>') + '</div>' +
                '<div class="ad-body">' +
                '<div class="ad-advertiser">' + esc(ad.advertiser || 'Anunciante desconhecido') + '</div>' +
                (ad.title ? '<div style="font-size:.8rem;font-weight:500;">' + esc(ad.title) + '</div>' : '') +
                (ad.text ? '<div class="ad-text">' + esc(ad.text) + '</div>' : '') +
                '<div class="ad-meta"><span>' + esc(ad.provider) + (ad.started_at ? ' · ' + esc(ad.started_at) : '') + '</span>' +
                (ad.link ? '<a href="' + esc(ad.link) + '" target="_blank" class="btn btn-sm btn-outline"><i class="fas fa-external-link-alt"></i></a>' : '') +
                '</div></div></div>';
        }).join('');
    }

    function filterProvider(provider) {
        document.querySelectorAll('.provider-tab').forEach(t => t.classList.toggle('active', t.dataset.provider === provider));
        renderAds(provider);
    }

    async function runDossier() {
        document.getElementById('loading').style.display = 'block';
        try {
            const body = new URLSearchParams();
            body.append('action', 'dossier');
            body.append('id', PAGE_ID);
            const resp = await fetch('/admin/api/adspy.php', { method: 'POST', body });
            const data = await resp.json();
            if (!data.success && data.error) {
                document.getElementById('errors').innerHTML = '<div class="alert alert-warning">' + esc(data.error) + '</div>';
                return;
            }
            if (data.signals) {
                document.getElementById('adspyQuery').value = data.signals.domain || data.signals.brand || '';
            }
            renderResults({ results: data.search.results, errors: data.search.errors });
            renderAnalysis(data.analysis);
        } catch (err) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-danger">Erro: ' + esc(err.message) + '</div>';
        } finally {
            document.getElementById('loading').style.display = 'none';
        }
    }

    function renderAnalysis(analysis) {
        if (!analysis) return;
        const card = document.getElementById('analysisCard');
        const bodyEl = document.getElementById('analysisBody');
        card.style.display = 'block';

        if (analysis.error) {
            bodyEl.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' + esc(analysis.error) + '</div>';
            return;
        }

        const a = analysis.analysis || {};
        document.getElementById('analysisProvider').textContent = (analysis.provider || '') + ' · ' + (analysis.model || '');

        let html = '';
        if (a.resumo) html += '<p style="line-height:1.6;">' + esc(a.resumo) + '</p>';
        if (a.oferta) html += '<p><strong>Oferta:</strong> ' + esc(a.oferta) + '</p>';
        if (a.publico) html += '<p><strong>Público provável:</strong> ' + esc(a.publico) + '</p>';
        if (a.funil) html += '<p><strong>Funil:</strong> ' + esc(a.funil) + '</p>';
        if (a.cta) html += '<p><strong>CTA:</strong> ' + esc(a.cta) + '</p>';
        if (Array.isArray(a.angulos) && a.angulos.length) {
            html += '<p style="margin-top:12px;"><strong>Ângulos identificados:</strong></p><ul style="padding-left:20px;line-height:1.8;">' + a.angulos.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>';
        }
        if (Array.isArray(a.termos_busca) && a.termos_busca.length) {
            html += '<p style="margin-top:12px;"><strong>Termos para testar:</strong></p><div style="display:flex;gap:6px;flex-wrap:wrap;">' + a.termos_busca.map(t => '<span class="quota-pill" style="padding:4px 12px;font-size:.8rem;">' + esc(t) + '</span>').join('') + '</div>';
        }
        if (Array.isArray(a.sugestoes) && a.sugestoes.length) {
            html += '<p style="margin-top:12px;"><strong>Sugestões para a campanha:</strong></p><ul style="padding-left:20px;line-height:1.8;">' + a.sugestoes.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>';
        }
        bodyEl.innerHTML = html || '<p>Sem conteúdo na análise.</p>';
    }

    if (PAGE_ID > 0) {
        runDossier();
    }
    </script>
</body>
</html>
