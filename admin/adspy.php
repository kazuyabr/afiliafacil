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
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
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
        .ad-card .ad-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 6px; min-height: 0; overflow: hidden; }
        .ad-card .ad-advertiser { font-size: .8rem; font-weight: 600; color: var(--accent); }
        .ad-card .ad-text { font-size: .8rem; color: var(--text-primary); line-height: 1.4; max-height: 80px; overflow: hidden; word-break: break-word; overflow-wrap: anywhere; }
        .ad-card .ad-meta { font-size: .7rem; color: var(--text-secondary); margin-top: auto; display: flex; justify-content: space-between; align-items: center; min-width: 0; gap: 8px; }
        .ad-meta-info { white-space: nowrap; flex-shrink: 0; }
        .ad-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 10px; font-size: .65rem; background: var(--bg-secondary); border: 1px solid var(--border-color); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex-shrink: 0; }
        .ad-badges { display: flex; gap: 4px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: thin; scrollbar-color: var(--border-color) transparent; flex: 1 1 0; min-width: 0; flex-wrap: nowrap; }
        .ad-badges::-webkit-scrollbar { height: 4px; }
        .ad-badges::-webkit-scrollbar-track { background: transparent; }
        .ad-badges::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 2px; }
        .ad-badges::-webkit-scrollbar-thumb:hover { background: var(--text-secondary); }
        .ad-actions { display: flex; gap: 6px; flex-shrink: 0; }
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
                        <span class="quota-pill"><i class="fas fa-brain"></i> Análises IA: <strong id="quotaAnalysis"><?= $quotaAnalysis['source'] === 'byok' ? 'BYOK — sem limite' : ($quotaAnalysis['limit'] === -1 ? 'ilimitado' : $quotaAnalysis['used'] . '/' . $quotaAnalysis['limit']) ?></strong></span>
                    </div>
                </div>

                <div id="providerStatus" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;"></div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-body">
                        <?php if ($pageId > 0): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-crosshairs"></i> Espionando a campanha de: <strong><?= htmlspecialchars($pageName) ?></strong>
                        </div>
                        <?php endif; ?>

                        <div class="mode-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
                            <button class="provider-tab mode-tab active" data-mode="search" onclick="setMode('search')"><i class="fas fa-search"></i> Busca por termo</button>
                            <button class="provider-tab mode-tab" data-mode="trends" onclick="setMode('trends')"><i class="fas fa-hashtag"></i> Trends &amp; Hashtags</button>
                            <button class="provider-tab mode-tab" data-mode="topads" onclick="setMode('topads')"><i class="fas fa-star"></i> Top Ads</button>
                        </div>

                        <div class="form-group">
                            <label id="modeQueryLabel">Termo, domínio ou anunciante</label>
                            <div class="input-group">
                                <input type="text" id="adspyQuery" class="form-control" placeholder="ex: preguicaartificial.com.br, https://loja.com/produto, nome do produto, marca..." value="<?= htmlspecialchars($_GET['query'] ?? '') ?>">
                                <button class="btn btn-primary" onclick="runAction()" id="searchBtn"><i class="fas fa-search" id="searchBtnIcon"></i> <span id="searchBtnText">Espionar</span></button>
                            </div>
                        </div>

<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                             <div id="modeProviders" style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                                 <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="meta" checked> <i class="fab fa-facebook" style="color:#1877f2;"></i> Meta</label>
                                 <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="google" checked> <i class="fab fa-google" style="color:#4285f4;"></i> Google</label>
                                 <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;"><input type="checkbox" class="provider-check" value="tiktok" checked> <i class="fab fa-tiktok"></i> TikTok</label>
                             </div>
                             <select id="adspyCountry" class="form-control" style="width:auto;">
                                 <option value="BR" selected>Brasil</option>
                                 <option value="US">Estados Unidos</option>
                                 <option value="PT">Portugal</option>
                                 <option value="ALL">Todos</option>
                             </select>
                             <select id="adspyGooglePlatform" class="form-control" style="width:auto;" title="Filtro de posicionamento do Google (SerpApi)">
                                 <option value="">Google: Todos os posicionamentos</option>
                                 <option value="SEARCH">Pesquisa</option>
                                 <option value="YOUTUBE">YouTube</option>
                                 <option value="DISPLAY">Display</option>
                                 <option value="SHOPPING">Shopping</option>
                                 <option value="MAPS">Maps</option>
                             </select>
                             <div id="modeDiscoverFields" style="display:none;gap:12px;align-items:center;">
                                  <select id="adspyPeriod" class="form-control" style="width:auto;" title="Período da descoberta">
                                      <option value="7">Últimos 7 dias</option>
                                      <option value="30">Últimos 30 dias</option>
                                  </select>
                                  <select id="adspyOrderBy" class="form-control" style="width:auto;" title="Ordenação dos Top Ads">
                                      <option value="ctr">Ordenar: CTR</option>
                                      <option value="like">Ordenar: Curtidas</option>
                                      <option value="cost">Ordenar: Custo</option>
                                      <option value="for_you">Ordenar: Relevância</option>
                                  </select>
                             </div>
                         </div>
                         <div id="modeDiscoverHint" style="display:none;margin-top:10px;font-size:.8rem;color:var(--text-secondary);"></div>
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
    const IS_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
    let currentAds = [];

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function selectedProviders() {
        return [...document.querySelectorAll('.provider-check:checked')].map(c => c.value);
    }

    function daysRunning(startedAt) {
        const d = new Date(String(startedAt).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(startedAt).substring(0, 10);
        const days = Math.max(1, Math.round((Date.now() - d.getTime()) / 86400000));
        return 'há ' + days + (days === 1 ? ' dia' : ' dias');
    }

    // ── Modos de descoberta (Busca / Trends & Hashtags / Top Ads) ───────
    let currentMode = 'search';
    const MODE_HINTS = {
        trends: 'Hashtags em alta no TikTok Creative Center (por país e período). Digite um termo (ex.: meme) para buscar hashtags pelo texto — usa seu token Apify. Em branco, mostra a lista em alta (o acesso anônimo mostra só as primeiras hashtags).',
        topads: 'Melhores anúncios do TikTok (Top Ads) por desempenho. Sem token Apify a fonte pública responde com aviso de sessão — configure em IA → Busca de Anúncios. O termo (opcional) filtra por palavra-chave.',
    };

    function setMode(mode) {
        currentMode = mode;
        document.querySelectorAll('.mode-tab').forEach(t => t.classList.toggle('active', t.dataset.mode === mode));
        const isSearch = mode === 'search';
        const label = document.getElementById('modeQueryLabel');
        const input = document.getElementById('adspyQuery');
        document.getElementById('modeProviders').style.display = isSearch ? 'flex' : 'none';
        document.getElementById('adspyGooglePlatform').style.display = isSearch ? '' : 'none';
        document.getElementById('modeDiscoverFields').style.display = isSearch ? 'none' : 'flex';
        document.getElementById('adspyOrderBy').style.display = mode === 'topads' ? '' : 'none';
        const hint = document.getElementById('modeDiscoverHint');
        hint.style.display = isSearch ? 'none' : 'block';
        hint.textContent = isSearch ? '' : MODE_HINTS[mode] || '';
        document.getElementById('searchBtnText').textContent = isSearch ? 'Espionar' : 'Descobrir';
        document.getElementById('searchBtnIcon').className = isSearch ? 'fas fa-search' : 'fas fa-compass';
        if (isSearch) {
            label.textContent = 'Termo, domínio ou anunciante';
            input.placeholder = 'ex: preguicaartificial.com.br, https://loja.com/produto, nome do produto, marca...';
        } else if (mode === 'topads') {
            label.textContent = 'Palavra-chave (opcional — filtra os Top Ads)';
            input.placeholder = 'ex: fogão, roupas... (ou deixe em branco)';
        } else {
            label.textContent = 'Hashtag ou termo (opcional)';
            input.placeholder = 'ex.: meme, fitness, maquiagem... (ou deixe em branco para as em alta)';
        }
    }

    function runAction() {
        if (currentMode === 'search') runSearch();
        else runDiscover();
    }

    async function runDiscover() {
        document.getElementById('loading').style.display = 'block';
        document.getElementById('results').innerHTML = '';
        document.getElementById('errors').innerHTML = '';
        try {
            const body = new URLSearchParams();
            body.append('action', 'discover');
            body.append('mode', currentMode);
            body.append('country', document.getElementById('adspyCountry').value);
            body.append('period', document.getElementById('adspyPeriod').value);
            if (currentMode === 'topads') body.append('order_by', document.getElementById('adspyOrderBy').value);
            const q = document.getElementById('adspyQuery').value.trim();
            if (q) body.append('query', q); // trends: busca hashtag por termo; topads: filtra por palavra-chave

            const resp = await fetch('/admin/api/adspy.php', { method: 'POST', body });
            const data = await resp.json();
            renderResults(data);
        } catch (err) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-danger">Erro de conexão: ' + esc(err.message) + '</div>';
        } finally {
            document.getElementById('loading').style.display = 'none';
        }
    }

    async function loadProviderStatus() {
        try {
            const resp = await fetch('/admin/api/adspy.php?action=status');
            const data = await resp.json();
            const providers = data.providers || {};
            const icons = { meta: '<i class="fab fa-facebook" style="color:#1877f2;"></i>', google: '<i class="fab fa-google" style="color:#4285f4;"></i>', tiktok: '<i class="fab fa-tiktok"></i>' };
            // Cores = estado REAL: verde funcionou · amarelo falta configurar · vermelho erro de verdade · cinza nunca buscou
            const colors = { ok: 'var(--success)', warn: 'var(--warning)', error: 'var(--danger)', idle: 'var(--text-secondary)' };
            document.getElementById('providerStatus').innerHTML = ['meta', 'google', 'tiktok'].map(pid => {
                const p = providers[pid] || {};
                const level = p.level || 'idle';
                const dot = '<span style="width:8px;height:8px;border-radius:50%;background:' + (colors[level] || colors.idle) + ';display:inline-block;flex-shrink:0;"></span>';
                const target = p.action || null;
                const title = esc([p.state, p.hint].filter(Boolean).join(' — ')) + (target ? ' — clique para resolver' : '');
                const click = target ? ' cursor:pointer;" onclick="location.href=\'' + target + '\'"' : '"';
                return '<span class="quota-pill" style="padding:6px 12px;font-size:.78rem;transition:all .12s ease' + click + ' title="' + title + '">' +
                    dot + (icons[pid] || '') + ' <strong>' + esc(pid.toUpperCase()) + '</strong>&nbsp;' + esc(p.label || '') +
                    (target ? ' <i class="fas fa-cog" style="margin-left:4px;opacity:.7;"></i>' : '') + '</span>';
            }).join('');
        } catch (e) {}
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
            const googlePlatform = document.getElementById('adspyGooglePlatform').value;
            if (googlePlatform) body.append('platform', googlePlatform);

            const resp = await fetch('/admin/api/adspy.php', { method: 'POST', body });
            const data = await resp.json();
            renderResults(data);
        } catch (err) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-danger">Erro de conexão: ' + esc(err.message) + '</div>';
        } finally {
            document.getElementById('loading').style.display = 'none';
        }
    }

    function configLinkFor(msg) {
        // Linka so quando o erro pede CONFIGURACAO (chave/Steel) — bloqueio de fonte nao se resolve em config
        if (/token|Meta API|ads_read|biblioteca pública|validating access/i.test(msg)) return '/admin/ai-settings.php#adspy';
        if (/Steel Browser|STEEL_API_URL/i.test(msg) && IS_ADMIN) return '/admin/settings.php';
        if (/SerpApi|Google Ads|quota.*SerpApi/i.test(msg)) return '/admin/ai-settings.php#adspy';
        return null;
    }

async function renderResults(data) {
        const errors = data.errors || {};
        if (errors.quota) {
            document.getElementById('errors').innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' + esc(errors.quota) + '</div>';
            return;
        }
        Object.entries(errors).forEach(([pid, msg]) => {
            if (pid === 'query') return;
            const link = configLinkFor(msg);
            const inner = '<strong>' + esc(pid) + ':</strong> ' + esc(msg) +
                (link ? ' <a href="' + link + '" style="font-weight:600;text-decoration:underline;">Configurar agora <i class="fas fa-arrow-right" style="font-size:.7rem;"></i></a>' : '');
            document.getElementById('errors').innerHTML += '<div class="alert alert-warning" style="cursor:' + (link ? 'pointer' : 'default') + '"' + (link ? ' onclick="location.href=\'' + link + '\'"' : '') + '>' + inner + '</div>';
        });

        // Dicas do provider (vazio OU sucesso — ex.: "só as primeiras hashtags aparecem")
        const resultsForHints = data.results || {};
        Object.entries(resultsForHints).forEach(([pid, r]) => {
            if (!r || r.error || !r.hint) return;
            document.getElementById('errors').innerHTML += '<div class="alert alert-info"><strong>' + esc(pid) + ':</strong> ' + esc(r.hint) + '</div>';
        });

        // Mostrar fonte dos resultados (source_label) acima das abas
        const results = data.results || {};
        let sourceHtml = '';
        Object.entries(results).forEach(([pid, r]) => {
            if (!r || r.error || !r.source_label) return;
            sourceHtml += '<span class="quota-pill" style="font-size:.75rem;padding:4px 10px;background:var(--bg-secondary);">' +
                (pid === 'meta' ? '<i class="fab fa-facebook" style="color:#1877f2;"></i>' : (pid === 'google' ? '<i class="fab fa-google" style="color:#4285f4;"></i>' : '<i class="fab fa-tiktok"></i>')) +
                ' ' + esc(r.source_label) + '</span>';
        });
        if (sourceHtml) {
            document.getElementById('errors').innerHTML = '<div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">' + sourceHtml + '</div>' + document.getElementById('errors').innerHTML;
        }

        // Pills atualizam com o resultado desta busca (era: so recarregava no load da pagina)
        await loadProviderStatus();

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
        // Helper: remove placeholders {{...}} e limpa vazio
        const clean = (s) => {
            if (!s) return '';
            return s.replace(/\{\{[^}]+\}\}/g, '').trim();
        };
        grid.innerHTML = ads.map(ad => {
            // media_url PRIMEIRO (imagem do anúncio), thumbnail como fallback
            const media = ad.media_url || ad.thumbnail;
            // Badges de plataforma por anúncio
            const platformBadges = (ad.platforms || []).map(p => {
                const icons = {
                    facebook: '<i class="fab fa-facebook-f" style="color:#1877f2;"></i>',
                    instagram: '<i class="fab fa-instagram" style="color:#e1306c;"></i>',
                    messenger: '<i class="fab fa-facebook-messenger" style="color:#0084ff;"></i>',
                    audience_network: '<i class="fas fa-globe" style="color:#6c757d;"></i>',
                    whatsapp: '<i class="fab fa-whatsapp" style="color:#25d366;"></i>',
                    youtube: '<i class="fab fa-youtube" style="color:#ff0000;"></i>',
                    search: '<i class="fas fa-search" style="color:#4285f4;"></i>',
                    display: '<i class="fas fa-image" style="color:#34a853;"></i>',
                    shopping: '<i class="fas fa-shopping-bag" style="color:#ea4335;"></i>',
                    maps: '<i class="fas fa-map-marker-alt" style="color:#34a853;"></i>',
                    tiktok: '<i class="fab fa-tiktok" style="color:#000;"></i>',
                };
                const labels = {
                    facebook: 'Facebook', instagram: 'Instagram', messenger: 'Messenger',
                    audience_network: 'Audience', whatsapp: 'WhatsApp',
                    youtube: 'YouTube', search: 'Pesquisa', display: 'Display',
                    shopping: 'Shopping', maps: 'Maps', tiktok: 'TikTok',
                };
                return '<span class="ad-badge">' + (icons[p] || '<i class="fas fa-circle"></i>') + ' ' + esc(labels[p] || p) + '</span>';
            }).join(' ');
            // Limpar placeholders no frontend (defesa extra para cache antigo)
            const adv = clean(ad.advertiser || 'Anunciante desconhecido');
            const title = clean(ad.title);
            const text = clean(ad.text);
            return '<div class="ad-card">' +
                '<div class="ad-media">' + (media ? '<img src="' + esc(media) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-image no-media"></i>') + '</div>' +
                '<div class="ad-body">' +
                '<div class="ad-advertiser">' + esc(adv) + '</div>' +
                (title ? '<div style="font-size:.8rem;font-weight:500;">' + esc(title) + '</div>' : '') +
                (text ? '<div class="ad-text">' + esc(text) + '</div>' : '') +
                '<div class="ad-meta">' +
                '<span class="ad-meta-info">' + esc(ad.provider) + (ad.started_at ? ' · ' + esc(daysRunning(ad.started_at)) : '') + '</span>' +
                (platformBadges ? '<div class="ad-badges">' + platformBadges + '</div>' : '') +
                '<div class="ad-actions">' +
                (ad.landing_page ? '<a href="/admin/clone.php?url=' + encodeURIComponent(ad.landing_page) + '" target="_blank" class="btn btn-sm btn-outline" title="Clonar esta página"><i class="fas fa-clone"></i></a>' : '') +
                (ad.link ? '<a href="' + esc(ad.link) + '" target="_blank" class="btn btn-sm btn-outline" title="Ver anúncio original"><i class="fas fa-external-link-alt"></i></a>' : '') +
                '</div></div></div></div>';
        }).join('');
    }

    function filterProvider(provider) {
        document.querySelectorAll('.provider-tab:not(.mode-tab)').forEach(t => t.classList.toggle('active', t.dataset.provider === provider));
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

    loadProviderStatus();
    if (PAGE_ID > 0) {
        runDossier();
    } else if (document.getElementById('adspyQuery').value.trim() !== '') {
        runSearch();
    }
    </script>
</body>
</html>
