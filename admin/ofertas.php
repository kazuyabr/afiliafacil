<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Offers/OfferQuota.php';

Auth::requireAuth();

$user = Auth::user();
$isAdmin = Auth::isAdmin();
if (!Plans::hasFeature($user['plan'], 'offers') && !$isAdmin) {
    header('Location: /admin/plan.php?upgrade=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$quota = OfferQuota::check((int)$user['id'], $user['plan']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ofertas Escalando - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; border-bottom:1px solid var(--border-color); padding-bottom:0; }
        .tab-btn { padding:10px 18px; border:none; background:none; cursor:pointer; font-size:.9rem; color:var(--text-secondary); border-bottom:2px solid transparent; display:flex; align-items:center; gap:8px; }
        .tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
        .tab-btn .count { background:var(--bg-secondary); padding:1px 8px; border-radius:10px; font-size:.72rem; }
        .offer-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; display:flex; flex-direction:column; transition:transform .15s, box-shadow .15s; }
        .offer-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.08); }
        .offer-media { height:150px; background:var(--bg-secondary); display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative; }
        .offer-media img { width:100%; height:100%; object-fit:cover; }
        .offer-media .no-media { color:var(--text-secondary); font-size:1.8rem; }
        .offer-media .platform-badge { position:absolute; top:8px; left:8px; background:rgba(0,0,0,.65); color:#fff; padding:2px 8px; border-radius:10px; font-size:.68rem; text-transform:uppercase; }
        .offer-media .scale-badge { position:absolute; top:8px; right:8px; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:700; color:#fff; }
        .scale-up { background:#16a34a; } .scale-down { background:#dc2626; } .scale-flat { background:#64748b; }
        .offer-body { padding:12px; flex:1; display:flex; flex-direction:column; gap:6px; }
        .offer-name { font-weight:600; font-size:.9rem; line-height:1.3; }
        .offer-advertiser { font-size:.78rem; color:var(--accent); }
        .offer-tags { display:flex; gap:4px; flex-wrap:wrap; }
        .offer-tag { font-size:.65rem; padding:2px 8px; border-radius:10px; background:var(--bg-secondary); color:var(--text-secondary); text-transform:uppercase; letter-spacing:.03em; }
        .offer-meta { font-size:.72rem; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center; margin-top:auto; }
        .offer-actions { display:flex; gap:6px; padding:10px 12px; border-top:1px solid var(--border-color); }
        .offer-actions .btn { flex:1; justify-content:center; font-size:.75rem; padding:6px 8px; }
        .sparkline { width:100%; height:28px; display:block; }
        .modal-overlay .modal.dossier-modal { max-width:920px; width:94%; }
        .dossier-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:18px; }
        .dossier-stat { background:var(--bg-secondary); border-radius:var(--radius); padding:12px; text-align:center; }
        .dossier-stat .value { font-size:1.4rem; font-weight:800; }
        .dossier-stat .label { font-size:.72rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.04em; }
        .creative-card { background:var(--bg-secondary); border-radius:var(--radius); overflow:hidden; display:flex; flex-direction:column; }
        .creative-card .thumb { height:130px; background:var(--bg-card); display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .creative-card .thumb img { width:100%; height:100%; object-fit:cover; }
        .creative-card .info { padding:10px; font-size:.75rem; display:flex; flex-direction:column; gap:4px; }
        .page-card { background:var(--bg-secondary); border-radius:var(--radius); overflow:hidden; }
        .page-card .thumb { height:120px; background:var(--bg-card); display:flex; align-items:center; justify-content:center; }
        .page-card .thumb img { width:100%; height:100%; object-fit:cover; }
        .page-card .info { padding:10px; font-size:.75rem; }
        .page-type { font-size:.65rem; padding:2px 8px; border-radius:10px; background:var(--accent-light); color:var(--accent); text-transform:uppercase; font-weight:600; }
        .curation-table { width:100%; border-collapse:collapse; font-size:.82rem; }
        .curation-table th { text-align:left; padding:8px 10px; border-bottom:1px solid var(--border-color); color:var(--text-secondary); font-size:.72rem; text-transform:uppercase; }
        .curation-table td { padding:8px 10px; border-bottom:1px solid var(--border-color); }
        .cron-code { background:var(--bg-secondary); border-radius:var(--radius); padding:12px; font-family:monospace; font-size:.78rem; word-break:break-all; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Ofertas Escalando</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Ofertas Escalando</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Swipe file de ofertas validadas nas bibliotecas de anúncios — com métricas de escala, criativos e páginas.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="quota-pill" id="quotaPill"><i class="fas fa-eye"></i> Ofertas vistas: <strong><?= $quota['limit'] === -1 ? 'ilimitado' : $quota['used'] . '/' . $quota['limit'] ?></strong></span>
                    </div>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="offers" onclick="switchTab('offers')"><i class="fas fa-fire"></i> Ofertas</button>
                    <button class="tab-btn" data-tab="creatives" onclick="switchTab('creatives')"><i class="fas fa-images"></i> Criativos</button>
                    <button class="tab-btn" data-tab="pages" onclick="switchTab('pages')"><i class="fas fa-file-code"></i> Páginas</button>
                    <?php if ($isAdmin): ?>
                    <button class="tab-btn" data-tab="curation" onclick="switchTab('curation')"><i class="fas fa-shield-halved"></i> Curadoria</button>
                    <?php endif; ?>
                </div>

                <div id="tab-offers">
                    <div class="card" style="margin-bottom:20px;">
                        <div class="card-body">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <div class="form-group" style="flex:2;min-width:200px;margin:0;">
                                    <label>Buscar</label>
                                    <input type="text" id="filterQ" class="form-control" placeholder="nome, anunciante ou domínio...">
                                </div>
                                <?php if ($isAdmin): ?>
                                <div class="form-group" style="margin:0;">
                                    <label>Status</label>
                                    <select id="filterStatus" class="form-control">
                                        <option value="approved">Aprovadas</option>
                                        <option value="pending">Pendentes</option>
                                        <option value="rejected">Rejeitadas</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="form-group" style="margin:0;">
                                    <label>Nicho</label>
                                    <select id="filterNiche" class="form-control"><option value="">Todos</option></select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label>Estrutura</label>
                                    <select id="filterStructure" class="form-control"><option value="">Todas</option></select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label>Tráfego</label>
                                    <select id="filterTraffic" class="form-control"><option value="">Todos</option></select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label>Idioma</label>
                                    <select id="filterLanguage" class="form-control"><option value="">Todos</option></select>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label>Ordenar</label>
                                    <select id="filterOrder" class="form-control">
                                        <option value="score">Score</option>
                                        <option value="scale">Escala</option>
                                        <option value="ads">Anúncios</option>
                                        <option value="recent">Recentes</option>
                                    </select>
                                </div>
                                <button class="btn btn-primary" onclick="loadOffers()"><i class="fas fa-filter"></i> Filtrar</button>
                            </div>
                        </div>
                    </div>

                    <div id="offersLoading" style="display:none;"><div class="card"><div class="card-body" style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:var(--accent);"></i></div></div></div>
                    <div id="offersGrid" class="grid-3"></div>
                </div>

                <div id="tab-creatives" style="display:none;">
                    <div id="creativesGrid" class="grid-3"></div>
                </div>

                <div id="tab-pages" style="display:none;">
                    <div id="pagesGrid" class="grid-3"></div>
                </div>

                <?php if ($isAdmin): ?>
                <div id="tab-curation" style="display:none;">
                    <div class="grid-3" id="curationStats" style="margin-bottom:20px;"></div>

                    <div class="card" style="margin-bottom:20px;">
                        <div class="card-header">
                            <h3><i class="fas fa-list-check"></i> Ofertas pendentes</h3>
                            <div style="display:flex;gap:8px;">
                                <button class="btn btn-outline btn-sm" onclick="analyzePending()"><i class="fas fa-brain"></i> Analisar com IA</button>
                                <button class="btn btn-outline btn-sm" onclick="bulkAction('approve')"><i class="fas fa-check"></i> Aprovar selecionadas</button>
                                <button class="btn btn-outline btn-sm" onclick="bulkAction('reject')"><i class="fas fa-xmark"></i> Rejeitar selecionadas</button>
                            </div>
                        </div>
                        <div class="card-body" style="overflow-x:auto;">
                            <table class="curation-table">
                                <thead>
                                    <tr>
                                        <th style="width:30px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
                                        <th>Oferta</th>
                                        <th>Nicho</th>
                                        <th>Estrutura</th>
                                        <th>Anúncios</th>
                                        <th>Score IA</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="pendingTable"><tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:20px;">Carregando...</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom:20px;">
                        <div class="card-header"><h3><i class="fas fa-robot"></i> Monitoramento & IA</h3></div>
                        <div class="card-body">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                                <div class="form-group">
                                    <label>Modo do monitor</label>
                                    <select id="setMonitorMode" class="form-control">
                                        <option value="cron">Cron (automático)</option>
                                        <option value="manual">Manual (somente botões)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Aprovar automaticamente acima de (score)</label>
                                    <input type="number" id="setAutoScore" class="form-control" min="0" max="100">
                                </div>
                                <div class="form-group">
                                    <label>Auto-aprovação</label>
                                    <select id="setAutoApprove" class="form-control">
                                        <option value="1">Ativada</option>
                                        <option value="0">Desativada</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Ofertas por ciclo de monitor</label>
                                    <input type="number" id="setMonitorLimit" class="form-control" min="1" max="200">
                                </div>
                                <div class="form-group">
                                    <label>Termos por coleta</label>
                                    <input type="number" id="setCollectLimit" class="form-control" min="1" max="30">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Termos de busca (separados por vírgula ou linha)</label>
                                <textarea id="setTerms" class="form-control" rows="3" placeholder="emagrecimento, renda extra, dieta..."></textarea>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn btn-primary" onclick="saveSettings()"><i class="fas fa-save"></i> Salvar</button>
                                <button class="btn btn-outline" onclick="runCollect()"><i class="fas fa-magnifying-glass"></i> Buscar ofertas agora</button>
                                <button class="btn btn-outline" onclick="runMonitor()"><i class="fas fa-arrows-rotate"></i> Atualizar métricas agora</button>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-clock"></i> Cron</h3></div>
                        <div class="card-body">
                            <p style="color:var(--text-secondary);font-size:.85rem;margin-bottom:12px;">
                                Configure uma tarefa no seu servidor para rodar o monitor automaticamente (recomendado: a cada 6 horas).
                            </p>
                            <div class="cron-code" id="cronEndpoint">carregando...</div>
                            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                                <button class="btn btn-outline btn-sm" onclick="rotateCronKey()"><i class="fas fa-rotate"></i> Gerar nova chave</button>
                                <button class="btn btn-outline btn-sm" onclick="copyCronCommand()"><i class="fas fa-copy"></i> Copiar comando cron</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="dossierModal">
        <div class="modal dossier-modal">
            <div class="modal-header">
                <h3 id="dossierTitle">Dossiê da oferta</h3>
                <button class="modal-close" onclick="closeDossier()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="dossierBody"></div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    let offersCache = [];
    let currentOffer = null;

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
        ['offers', 'creatives', 'pages', 'curation'].forEach(t => {
            const el = document.getElementById('tab-' + t);
            if (el) el.style.display = t === tab ? 'block' : 'none';
        });
        if (tab === 'creatives' && !document.getElementById('creativesGrid').dataset.loaded) loadCreatives();
        if (tab === 'pages' && !document.getElementById('pagesGrid').dataset.loaded) loadPages();
        if (tab === 'curation' && !document.getElementById('pendingTable').dataset.loaded) { loadPending(); loadSettings(); loadCronKey(); }
    }

    function sparklineSvg(values, color) {
        if (!values || values.length < 2) return '<svg class="sparkline" viewBox="0 0 100 28" preserveAspectRatio="none"><line x1="0" y1="14" x2="100" y2="14" stroke="var(--border-color)" stroke-width="1"/></svg>';
        const max = Math.max(...values, 1);
        const min = Math.min(...values);
        const range = Math.max(1, max - min);
        const points = values.map((v, i) => {
            const x = (i / (values.length - 1)) * 100;
            const y = 24 - ((v - min) / range) * 20;
            return x.toFixed(1) + ',' + y.toFixed(1);
        }).join(' ');
        return '<svg class="sparkline" viewBox="0 0 100 28" preserveAspectRatio="none"><polyline points="' + points + '" fill="none" stroke="' + color + '" stroke-width="1.8" vector-effect="non-scaling-stroke"/></svg>';
    }

    function scaleBadge(pct) {
        if (pct > 0) return '<span class="scale-badge scale-up"><i class="fas fa-arrow-trend-up"></i> ' + pct + '%</span>';
        if (pct < 0) return '<span class="scale-badge scale-down"><i class="fas fa-arrow-trend-down"></i> ' + pct + '%</span>';
        return '<span class="scale-badge scale-flat">—</span>';
    }

    async function loadOffers() {
        document.getElementById('offersLoading').style.display = 'block';
        const params = new URLSearchParams({ action: 'list', limit: 60 });
        const q = document.getElementById('filterQ').value.trim();
        if (q) params.set('q', q);
        const status = document.getElementById('filterStatus');
        if (status) params.set('status', status.value);
        ['Niche', 'Structure', 'Traffic', 'Language', 'Order'].forEach((name, i) => {
            const key = ['niche', 'structure', 'traffic', 'language', 'order'][i];
            const el = document.getElementById('filter' + name);
            if (el && el.value) params.set(key, el.value);
        });

        try {
            const resp = await fetch('/admin/api/ofertas.php?' + params.toString());
            const data = await resp.json();
            if (data.error) { document.getElementById('offersGrid').innerHTML = '<div class="alert alert-warning" style="grid-column:1/-1;">' + esc(data.error) + '</div>'; return; }
            offersCache = data.items || [];
            renderOffers();
            fillFilters(data.filters || {});
            if (data.quota) updateQuota(data.quota);
        } finally {
            document.getElementById('offersLoading').style.display = 'none';
        }
    }

    function fillFilters(filters) {
        const maps = [['filterNiche', filters.niches], ['filterStructure', filters.structures], ['filterTraffic', filters.traffic], ['filterLanguage', filters.languages]];
        maps.forEach(([id, values]) => {
            const el = document.getElementById(id);
            if (!el || el.dataset.filled || !values) return;
            values.forEach(v => { const o = document.createElement('option'); o.value = v; o.textContent = v.replace(/_/g, ' '); el.appendChild(o); });
            el.dataset.filled = '1';
        });
    }

    function renderOffers() {
        const grid = document.getElementById('offersGrid');
        if (!offersCache.length) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-fire"></i><h3>Nenhuma oferta encontrada</h3><p>' + (IS_ADMIN ? 'Rode a coleta na aba Curadoria para popular o swipe file.' : 'Tente outros filtros.') + '</p></div>';
            return;
        }
        grid.innerHTML = offersCache.map(o => {
            const media = o.thumbnail_url;
            const color = o.scale_pct >= 0 ? '#16a34a' : '#dc2626';
            return '<div class="offer-card">' +
                '<div class="offer-media">' +
                    (media ? '<img src="' + esc(media) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-image no-media"></i>') +
                    '<span class="platform-badge">' + esc(o.platform) + '</span>' +
                    scaleBadge(o.scale_pct) +
                '</div>' +
                '<div class="offer-body">' +
                    '<div class="offer-name">' + esc(o.name) + '</div>' +
                    (o.advertiser ? '<div class="offer-advertiser">' + esc(o.advertiser) + '</div>' : '') +
                    '<div class="offer-tags">' +
                        (o.niche ? '<span class="offer-tag">' + esc(o.niche) + '</span>' : '') +
                        (o.structure ? '<span class="offer-tag">' + esc(o.structure.replace(/_/g, ' ')) + '</span>' : '') +
                        (o.language ? '<span class="offer-tag">' + esc(o.language) + '</span>' : '') +
                    '</div>' +
                    sparklineSvg(o.sparkline, color) +
                    '<div class="offer-meta"><span><i class="fas fa-bullhorn"></i> ' + o.ads_count + ' anúncios</span><span>' + esc((o.domain || '').substring(0, 28)) + '</span></div>' +
                '</div>' +
                '<div class="offer-actions">' +
                    '<button class="btn btn-primary" onclick="openDossier(' + o.id + ')"><i class="fas fa-eye"></i> Ver dossiê</button>' +
                    (o.source_url || o.domain ? '<button class="btn btn-outline" onclick="cloneOffer(' + o.id + ')"><i class="fas fa-clone"></i> Clonar</button>' : '') +
                    '<button class="btn btn-outline" onclick="spyOffer(' + o.id + ')"><i class="fas fa-crosshairs"></i></button>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    function updateQuota(quota) {
        const pill = document.getElementById('quotaPill');
        if (!pill || !quota) return;
        pill.innerHTML = '<i class="fas fa-eye"></i> Ofertas vistas: <strong>' + (quota.limit === -1 ? 'ilimitado' : quota.used + '/' + quota.limit) + '</strong>';
    }

    async function openDossier(id) {
        const modal = document.getElementById('dossierModal');
        modal.classList.add('active');
        document.getElementById('dossierBody').innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:var(--accent);"></i></div>';

        const resp = await fetch('/admin/api/ofertas.php?action=get&id=' + id);
        const data = await resp.json();

        if (data.error) {
            document.getElementById('dossierBody').innerHTML = '<div class="alert alert-warning">' + esc(data.error) + '</div>';
            return;
        }

        currentOffer = data.offer;
        if (data.quota) updateQuota(data.quota);
        renderDossier(data.offer);
    }

    function renderDossier(o) {
        document.getElementById('dossierTitle').textContent = o.name;
        const ai = o.ai_data || {};
        let html = '';

        html += '<div class="dossier-grid">' +
            '<div class="dossier-stat"><div class="value">' + o.ads_count + '</div><div class="label">Anúncios ativos</div></div>' +
            '<div class="dossier-stat"><div class="value" style="color:' + (o.scale_pct >= 0 ? '#16a34a' : '#dc2626') + ';">' + (o.scale_pct > 0 ? '+' : '') + o.scale_pct + '%</div><div class="label">Escala</div></div>' +
            '<div class="dossier-stat"><div class="value">' + o.score + '</div><div class="label">Score IA</div></div>' +
            '<div class="dossier-stat"><div class="value">' + (o.creatives || []).length + '</div><div class="label">Criativos</div></div>' +
            '<div class="dossier-stat"><div class="value">' + (o.pages || []).length + '</div><div class="label">Páginas</div></div>' +
        '</div>';

        html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">' +
            (o.niche ? '<span class="offer-tag">' + esc(o.niche) + '</span>' : '') +
            (o.structure ? '<span class="offer-tag">' + esc(o.structure.replace(/_/g, ' ')) + '</span>' : '') +
            (o.language ? '<span class="offer-tag">' + esc(o.language) + '</span>' : '') +
            (o.traffic_sources || []).map(t => '<span class="offer-tag">' + esc(t) + '</span>').join('') +
        '</div>';

        if (o.domain) html += '<p style="font-size:.85rem;margin-bottom:6px;"><strong>Domínio:</strong> ' + esc(o.domain) + '</p>';
        if (o.advertiser) html += '<p style="font-size:.85rem;margin-bottom:6px;"><strong>Anunciante:</strong> ' + esc(o.advertiser) + '</p>';

        if (o.ai_summary) {
            html += '<div class="card" style="margin:14px 0;background:var(--bg-secondary);border:none;"><div class="card-body" style="padding:14px;">' +
                '<p style="font-size:.85rem;line-height:1.6;"><i class="fas fa-brain" style="color:var(--accent);"></i> ' + esc(o.ai_summary) + '</p>';
            if (Array.isArray(ai.angulos) && ai.angulos.length) {
                html += '<p style="font-size:.78rem;margin-top:8px;"><strong>Ângulos:</strong> ' + ai.angulos.map(a => esc(a)).join(' · ') + '</p>';
            }
            if (Array.isArray(ai.sugestoes) && ai.sugestoes.length) {
                html += '<ul style="font-size:.78rem;margin-top:6px;padding-left:18px;line-height:1.7;">' + ai.sugestoes.map(s => '<li>' + esc(s) + '</li>').join('') + '</ul>';
            }
            html += '</div></div>';
        }

        if (IS_ADMIN) {
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">' +
                '<button class="btn btn-outline btn-sm" onclick="analyzeOffer(' + o.id + ')"><i class="fas fa-brain"></i> Analisar com IA</button>' +
                (o.status !== 'approved' ? '<button class="btn btn-primary btn-sm" onclick="offerAction(' + o.id + ', \'approve\')"><i class="fas fa-check"></i> Aprovar</button>' : '') +
                (o.status !== 'rejected' ? '<button class="btn btn-outline btn-sm" onclick="offerAction(' + o.id + ', \'reject\')"><i class="fas fa-xmark"></i> Rejeitar</button>' : '') +
            '</div>';
        }

        html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">' +
            (o.source_url || o.domain ? '<button class="btn btn-primary btn-sm" onclick="cloneCurrentOffer()"><i class="fas fa-clone"></i> Clonar página principal</button>' : '') +
            '<button class="btn btn-outline btn-sm" onclick="spyCurrentOffer()"><i class="fas fa-crosshairs"></i> Espionar campanha</button>' +
            '<button class="btn btn-outline btn-sm" onclick="transcribeCurrentOffer()"><i class="fas fa-microphone-lines"></i> Transcrever VSL</button>' +
            '<button class="btn btn-outline btn-sm" onclick="askAgentCurrentOffer()"><i class="fas fa-handshake"></i> Discutir com o Sócio de IA</button>' +
        '</div>';

        if ((o.creatives || []).length) {
            html += '<h4 style="margin-bottom:10px;"><i class="fas fa-images"></i> Criativos (' + o.creatives.length + ')</h4>' +
                '<div class="grid-3" style="margin-bottom:18px;">' + o.creatives.slice(0, 12).map(c =>
                    '<div class="creative-card">' +
                        '<div class="thumb">' + (c.thumbnail_url ? '<img src="' + esc(c.thumbnail_url) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-image" style="color:var(--text-secondary);"></i>') + '</div>' +
                        '<div class="info">' +
                            '<div style="font-weight:600;">' + esc(c.advertiser || '') + '</div>' +
                            (c.title ? '<div>' + esc(c.title) + '</div>' : '') +
                            (c.body ? '<div style="color:var(--text-secondary);">' + esc(c.body.substring(0, 110)) + '</div>' : '') +
                            (c.ad_url ? '<a href="' + esc(c.ad_url) + '" target="_blank" style="font-size:.72rem;">Ver anúncio <i class="fas fa-external-link-alt"></i></a>' : '') +
                        '</div>' +
                    '</div>'
                ).join('') + '</div>';
        }

        if ((o.pages || []).length) {
            html += '<h4 style="margin-bottom:10px;"><i class="fas fa-file-code"></i> Páginas (' + o.pages.length + ')</h4>' +
                '<div class="grid-3">' + o.pages.map(p =>
                    '<div class="page-card">' +
                        '<div class="thumb">' + (p.thumbnail_url ? '<img src="' + esc(p.thumbnail_url) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-file-code" style="color:var(--text-secondary);"></i>') + '</div>' +
                        '<div class="info">' +
                            '<span class="page-type">' + esc(p.type.replace(/_/g, ' ')) + '</span>' +
                            '<div style="margin-top:6px;word-break:break-all;"><a href="' + esc(p.url) + '" target="_blank">' + esc(p.url.substring(0, 60)) + '</a></div>' +
                        '</div>' +
                    '</div>'
                ).join('') + '</div>';
        }

        document.getElementById('dossierBody').innerHTML = html;
    }

    function closeDossier() { document.getElementById('dossierModal').classList.remove('active'); }

    function cloneOffer(id) {
        const o = offersCache.find(x => x.id === id);
        if (!o) return;
        const url = o.source_url || (o.domain ? 'https://' + o.domain : '');
        if (!url) { showToast('Oferta sem URL de origem', 'warning'); return; }
        window.open('/admin/clone.php?url=' + encodeURIComponent(url) + '&name=' + encodeURIComponent(o.name), '_blank');
    }

    function cloneCurrentOffer() {
        if (!currentOffer) return;
        const url = currentOffer.source_url || (currentOffer.domain ? 'https://' + currentOffer.domain : '');
        if (!url) { showToast('Oferta sem URL de origem', 'warning'); return; }
        window.open('/admin/clone.php?url=' + encodeURIComponent(url) + '&name=' + encodeURIComponent(currentOffer.name), '_blank');
    }

    function spyOffer(id) {
        const o = offersCache.find(x => x.id === id);
        if (!o) return;
        const query = o.domain || o.advertiser || o.name;
        window.open('/admin/adspy.php?query=' + encodeURIComponent(query), '_blank');
    }

    function spyCurrentOffer() {
        if (!currentOffer) return;
        const query = currentOffer.domain || currentOffer.advertiser || currentOffer.name;
        window.open('/admin/adspy.php?query=' + encodeURIComponent(query), '_blank');
    }

    function transcribeCurrentOffer() {
        if (!currentOffer) return;
        const url = currentOffer.source_url || (currentOffer.domain ? 'https://' + currentOffer.domain : '');
        if (!url) { showToast('Oferta sem URL de origem', 'warning'); return; }
        window.open('/admin/transcribe.php?url=' + encodeURIComponent(url), '_blank');
    }

    function askAgentCurrentOffer() {
        if (!currentOffer) return;
        const text = 'Estou olhando a oferta "' + currentOffer.name + '" (ID ' + currentOffer.id + '). Vale a pena eu promover? Me diga os riscos e o que voce faria no meu lugar.';
        window.open('/admin/agent.php?ask=' + encodeURIComponent(text), '_blank');
    }

    async function loadCreatives() {
        const grid = document.getElementById('creativesGrid');
        grid.dataset.loaded = '1';
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:var(--accent);"></i></div>';
        const resp = await fetch('/admin/api/ofertas.php?action=creatives');
        const data = await resp.json();
        const items = data.items || [];
        if (!items.length) { grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-images"></i><h3>Nenhum criativo ainda</h3><p>Rode a coleta para capturar anúncios.</p></div>'; return; }
        grid.innerHTML = items.map(c =>
            '<div class="creative-card">' +
                '<div class="thumb">' + (c.thumbnail_url ? '<img src="' + esc(c.thumbnail_url) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-image" style="color:var(--text-secondary);"></i>') + '</div>' +
                '<div class="info">' +
                    '<div style="font-weight:600;">' + esc(c.advertiser || '') + '</div>' +
                    (c.title ? '<div>' + esc(c.title) + '</div>' : '') +
                    (c.body ? '<div style="color:var(--text-secondary);">' + esc(c.body.substring(0, 120)) + '</div>' : '') +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">' +
                        '<span class="offer-tag">' + esc(c.platform) + '</span>' +
                        (c.ad_url ? '<a href="' + esc(c.ad_url) + '" target="_blank" style="font-size:.72rem;">Ver <i class="fas fa-external-link-alt"></i></a>' : '') +
                    '</div>' +
                '</div>' +
            '</div>'
        ).join('');
    }

    async function loadPages() {
        const grid = document.getElementById('pagesGrid');
        grid.dataset.loaded = '1';
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:var(--accent);"></i></div>';
        const resp = await fetch('/admin/api/ofertas.php?action=pages');
        const data = await resp.json();
        const items = data.items || [];
        if (!items.length) { grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-file-code"></i><h3>Nenhuma página ainda</h3><p>Rode a coleta para capturar páginas.</p></div>'; return; }
        grid.innerHTML = items.map(p =>
            '<div class="page-card">' +
                '<div class="thumb">' + (p.thumbnail_url ? '<img src="' + esc(p.thumbnail_url) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '<i class="fas fa-file-code" style="color:var(--text-secondary);"></i>') + '</div>' +
                '<div class="info">' +
                    '<span class="page-type">' + esc(p.type.replace(/_/g, ' ')) + '</span>' +
                    '<div style="margin-top:6px;word-break:break-all;"><a href="' + esc(p.url) + '" target="_blank">' + esc(p.url.substring(0, 70)) + '</a></div>' +
                '</div>' +
            '</div>'
        ).join('');
    }

    async function loadPending() {
        document.getElementById('pendingTable').dataset.loaded = '1';
        const resp = await fetch('/admin/api/ofertas.php?action=list&status=pending&limit=100&order=ads');
        const data = await resp.json();
        const rows = data.items || [];
        const tbody = document.getElementById('pendingTable');
        if (data.stats) renderCurationStats(data.stats);
        if (!rows.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:20px;">Nenhuma oferta pendente. 🎉</td></tr>'; return; }
        tbody.innerHTML = rows.map(o =>
            '<tr>' +
                '<td><input type="checkbox" class="pending-check" value="' + o.id + '"></td>' +
                '<td><strong>' + esc(o.name) + '</strong><div style="font-size:.72rem;color:var(--text-secondary);">' + esc(o.domain || o.advertiser || '') + '</div></td>' +
                '<td>' + esc(o.niche || '—') + '</td>' +
                '<td>' + esc((o.structure || '—').replace(/_/g, ' ')) + '</td>' +
                '<td>' + o.ads_count + '</td>' +
                '<td>' + (o.score || '—') + '</td>' +
                '<td><button class="btn btn-outline btn-sm" onclick="openDossier(' + o.id + ')"><i class="fas fa-eye"></i></button></td>' +
            '</tr>'
        ).join('');
    }

    function renderCurationStats(stats) {
        document.getElementById('curationStats').innerHTML =
            '<div class="dossier-stat"><div class="value">' + stats.approved + '</div><div class="label">Aprovadas</div></div>' +
            '<div class="dossier-stat"><div class="value">' + stats.pending + '</div><div class="label">Pendentes</div></div>' +
            '<div class="dossier-stat"><div class="value">' + stats.rejected + '</div><div class="label">Rejeitadas</div></div>' +
            '<div class="dossier-stat"><div class="value">' + stats.creatives + '</div><div class="label">Criativos</div></div>' +
            '<div class="dossier-stat"><div class="value">' + stats.pages + '</div><div class="label">Páginas</div></div>';
    }

    function toggleAll(el) {
        document.querySelectorAll('.pending-check').forEach(c => c.checked = el.checked);
    }

    async function bulkAction(op) {
        const ids = [...document.querySelectorAll('.pending-check:checked')].map(c => c.value);
        if (!ids.length) { showToast('Selecione ao menos uma oferta', 'warning'); return; }
        const body = new URLSearchParams({ action: 'bulk', op });
        ids.forEach(id => body.append('ids[]', id));
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast(data.count + ' ofertas ' + (op === 'approve' ? 'aprovadas' : 'rejeitadas'), 'success'); loadPending(); }
        else showToast(data.error || 'Erro', 'error');
    }

    async function offerAction(id, op) {
        const body = new URLSearchParams({ action: op, id });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Oferta atualizada', 'success'); closeDossier(); loadPending(); loadOffers(); }
        else showToast(data.error || 'Erro', 'error');
    }

    async function analyzeOffer(id) {
        showToast('Analisando com IA...', 'info');
        const body = new URLSearchParams({ action: 'analyze', id });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Análise concluída (score ' + data.analysis.score + ')', 'success'); openDossier(id); loadPending(); }
        else showToast(data.error || 'Erro na análise', 'error');
    }

    async function analyzePending() {
        showToast('Analisando pendentes...', 'info');
        const body = new URLSearchParams({ action: 'analyze-pending', limit: 10 });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        showToast((data.analyzed || 0) + ' ofertas analisadas', 'success');
        loadPending();
    }

    async function runCollect() {
        showToast('Buscando ofertas nas bibliotecas (pode levar 1-2 min)...', 'info');
        const body = new URLSearchParams({ action: 'collect' });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) {
            const r = data.result || {};
            showToast('Coleta: ' + (r.created || 0) + ' novas, ' + (r.updated || 0) + ' atualizadas', 'success');
            loadPending(); loadOffers();
        } else showToast(data.error || 'Erro na coleta', 'error');
    }

    async function runMonitor() {
        showToast('Atualizando métricas...', 'info');
        const body = new URLSearchParams({ action: 'monitor' });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) showToast('Monitor: ' + (data.result.checked || 0) + ' ofertas atualizadas', 'success');
        else showToast(data.error || 'Erro no monitor', 'error');
    }

    async function loadSettings() {
        const resp = await fetch('/admin/api/ofertas.php?action=settings');
        const data = await resp.json();
        if (!data.success) return;
        const s = data.settings;
        document.getElementById('setMonitorMode').value = s.monitor_mode;
        document.getElementById('setAutoApprove').value = s.auto_approve ? '1' : '0';
        document.getElementById('setAutoScore').value = s.auto_approve_score;
        document.getElementById('setMonitorLimit').value = s.monitor_limit;
        document.getElementById('setCollectLimit').value = s.collect_limit;
        document.getElementById('setTerms').value = s.terms;
    }

    async function saveSettings() {
        const body = new URLSearchParams({
            action: 'settings',
            offers_monitor_mode: document.getElementById('setMonitorMode').value,
            offers_auto_approve: document.getElementById('setAutoApprove').value,
            offers_auto_approve_score: document.getElementById('setAutoScore').value,
            offers_monitor_limit: document.getElementById('setMonitorLimit').value,
            offers_collect_limit: document.getElementById('setCollectLimit').value,
            offers_terms: document.getElementById('setTerms').value,
        });
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body });
        const data = await resp.json();
        showToast(data.success ? 'Configurações salvas' : (data.error || 'Erro'), data.success ? 'success' : 'error');
    }

    async function loadCronKey() {
        const resp = await fetch('/admin/api/ofertas.php?action=cron-key');
        const data = await resp.json();
        const el = document.getElementById('cronEndpoint');
        if (!data.success) { el.textContent = 'Erro ao carregar'; return; }
        el.innerHTML = data.configured
            ? 'Chave: <strong>' + esc(data.masked) + '</strong> (' + (data.from_env ? 'definida no .env' : 'gerada no painel') + ')<br>' + esc(data.endpoint)
            : 'Nenhuma chave configurada. Clique em "Gerar nova chave".';
    }

    async function rotateCronKey() {
        if (!confirm('Gerar uma nova chave de cron? A chave anterior deixa de funcionar.')) return;
        const resp = await fetch('/admin/api/ofertas.php', { method: 'POST', body: new URLSearchParams({ action: 'cron-key' }) });
        const data = await resp.json();
        if (data.success) { showToast('Nova chave gerada', 'success'); loadCronKey(); }
        else showToast(data.error || 'Erro', 'error');
    }

    async function copyCronCommand() {
        const resp = await fetch('/admin/api/ofertas.php?action=cron-key');
        const data = await resp.json();
        if (!data.success || !data.configured) { showToast('Gere uma chave primeiro', 'warning'); return; }
        const url = data.endpoint.replace('SEU_CRON_KEY', 'SUA_CHAVE');
        const cmd = '0 */6 * * * curl -s "' + url + '" > /dev/null';
        navigator.clipboard.writeText(cmd).then(() => showToast('Comando copiado', 'success'));
    }

    loadOffers();
    </script>
</body>
</html>
