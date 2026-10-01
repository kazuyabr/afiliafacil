<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';
Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
$user = Auth::user();
$plan = (string)$user['plan'];
$hasFeature = Plans::hasFeature($plan, 'social');
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicações — AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .pill { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:600; padding:3px 9px; border-radius:20px; background:var(--bg-secondary,#eee); border:1px solid var(--border,#ddd); }
        .pill.ok { background:#e6f6ec; border-color:#9fdcb8; color:#14713d; }
        .pill.warn { background:#fff6e0; border-color:#f0d391; color:#8a6100; }
        .pill.err { background:#fdecec; border-color:#f0a8a8; color:#a32020; }
        [data-theme="dark"] .pill.ok { background:#123f26; border-color:#1d6b41; color:#7ee0a8; }
        [data-theme="dark"] .pill.warn { background:#4a3a10; border-color:#7a6122; color:#ffd979; }
        [data-theme="dark"] .pill.err { background:#4a1a1a; border-color:#7a2e2e; color:#ff9c9c; }
        .net-checks { display:flex; gap:8px; flex-wrap:wrap; }
        .net-check { display:flex; align-items:center; gap:7px; padding:8px 13px; border:1.5px solid var(--border,#ddd); border-radius:10px; cursor:pointer; font-size:.85rem; user-select:none; }
        .net-check.on { border-color:var(--accent,#0b5ed7); background:color-mix(in srgb, var(--accent,#0b5ed7) 8%, transparent); }
        .net-check input { display:none; }
        .form-hint { font-size:.78rem; color:var(--text-secondary); margin-top:4px; line-height:1.5; }
        .form-hint a { color:var(--accent,#0b5ed7); }
        .media-preview { margin-top:8px; max-height:130px; border-radius:8px; border:1px solid var(--border,#ddd); }
        .tgt-chip { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; padding:2px 8px; border-radius:12px; margin:2px 3px 2px 0; border:1px solid var(--border,#ddd); }
        .tgt-chip.published { background:#e6f6ec; border-color:#9fdcb8; color:#14713d; }
        .tgt-chip.failed { background:#fdecec; border-color:#f0a8a8; color:#a32020; }
        .tgt-chip.pending, .tgt-chip.publishing { background:#fff6e0; border-color:#f0d391; color:#8a6100; }
        [data-theme="dark"] .tgt-chip.published { background:#123f26; border-color:#1d6b41; color:#7ee0a8; }
        [data-theme="dark"] .tgt-chip.failed { background:#4a1a1a; border-color:#7a2e2e; color:#ff9c9c; }
        [data-theme="dark"] .tgt-chip.pending, [data-theme="dark"] .tgt-chip.publishing { background:#4a3a10; border-color:#7a6122; color:#ffd979; }
        .cost-box { font-size:.8rem; color:var(--text-secondary); }
        .cost-box b { color:var(--text); }
        .empty { text-align:center; padding:26px; color:var(--text-secondary); font-size:.88rem; }
        .empty a { color:var(--accent,#0b5ed7); }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Publicações</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Criar publicação</h1>
                    <p style="color:var(--text-secondary);font-size:.9rem;">Escreva uma vez e publique em todas as suas redes — manualmente ou agendado. As contas são gerenciadas em <a href="/admin/integrations.php">Integrações</a>.</p>
                </div>

                <div id="featureAlert" class="alert alert-warning" style="display:none;">
                    <i class="fas fa-lock"></i> Seu plano não inclui publicações sociais.
                    <a href="/admin/plan.php">Fazer upgrade</a> para liberar conexões e publicações.
                </div>

                <div class="card" id="composerCard" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-paper-plane"></i> Criar publicação</h3>
                        <span class="pill" id="postPill">—</span>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="caption">Legenda</label>
                            <textarea id="caption" class="form-control" rows="4" placeholder="Escreva a legenda do post… (emoji e links permitidos)"></textarea>
                        </div>

                        <div class="form-group">
                            <label>Publicar nas redes</label>
                            <div class="net-checks" id="netChecks"></div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label for="mediaFile">Mídia (imagem ou vídeo)</label>
                                <input type="file" id="mediaFile" class="form-control" accept="image/*,video/mp4">
                                <input type="url" id="mediaUrl" class="form-control" style="margin-top:8px;" placeholder="…ou cole uma URL de mídia">
                                <img id="mediaPreview" class="media-preview" style="display:none;" alt="">
                                <video id="mediaPreviewVid" class="media-preview" style="display:none;" controls muted></video>
                            </div>
                            <div class="form-group">
                                <label for="scheduledAt">Agendar (opcional)</label>
                                <input type="datetime-local" id="scheduledAt" class="form-control">
                                <div class="form-hint">Em branco = publicar agora. Com agendamento, o post sai sozinho no horário (cron + polling da tela).</div>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
                            <div class="cost-box" id="costBox"></div>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <span id="composerMsg" style="font-size:.82rem;color:var(--text-secondary);"></span>
                                <button class="btn btn-primary" id="publishBtn" onclick="publish()"><i class="fas fa-paper-plane"></i> <span id="publishLabel">Publicar agora</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-simple"></i> Desempenho</h3>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span class="pill" id="metricsPill">—</span>
                            <button class="btn btn-sm" id="metricsBtn" onclick="collectMetrics()"><i class="fas fa-rotate"></i> Atualizar métricas</button>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;overflow-x:auto;">
                        <table class="table">
                            <thead><tr>
                                <th>Post</th>
                                <th>Rede</th>
                                <th>Curtidas</th>
                                <th>Coment.</th>
                                <th>Compart.</th>
                                <th>Impressões</th>
                                <th>Alcance/Views</th>
                                <th>Coletado</th>
                            </tr></thead>
                            <tbody id="metricsBody">
                                <tr><td colspan="8" class="empty"><i class="fas fa-spinner fa-spin"></i></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock-rotate-left"></i> Histórico</h3>
                        <button class="btn btn-sm" onclick="loadHistory(true)"><i class="fas fa-rotate"></i> Atualizar</button>
                    </div>
                    <div class="card-body" style="padding:0;overflow-x:auto;">
                        <table class="table">
                            <thead><tr>
                                <th>Post</th>
                                <th>Redes</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="histBody">
                                <tr><td colspan="5" class="empty"><i class="fas fa-spinner fa-spin"></i></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    const HAS_FEATURE = <?= $hasFeature ? 'true' : 'false' ?>;
    const API = '/admin/api/social.php';
    const POST_PT = { draft: 'rascunho', scheduled: 'agendado', publishing: 'publicando…', published: 'publicado', partial: 'parcial', failed: 'falhou' };
    let STATE = { networks: {}, connections: [], selected: [], media: { url: '', kind: '' }, posts: [], metrics: {} };

    async function api(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        if (data) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
        let j = null;
        try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor' }; }
        if (!r.ok && !j.error) j.error = 'Erro HTTP ' + r.status;
        return j;
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    async function loadConnections() {
        const j = await api('connections');
        const box = document.getElementById('netChecks');
        if (j.error) { box.innerHTML = '<span class="form-hint">' + esc(j.error) + '</span>'; return; }
        STATE.networks = j.networks || {};
        STATE.connections = j.connections || [];

        document.getElementById('featureAlert').style.display = j.has_feature ? 'none' : '';
        document.getElementById('composerCard').style.display = j.has_feature ? '' : 'none';

        const pq = j.post_quota || {};
        document.getElementById('postPill').innerHTML = pq.max === -1
            ? '<i class="fas fa-calendar"></i> ' + (pq.used || 0) + ' posts (ilimitado)'
            : '<i class="fas fa-calendar"></i> ' + (pq.used || 0) + '/' + (pq.max ?? 0) + ' posts/mês';

        renderChecks();
    }

    function renderChecks() {
        const box = document.getElementById('netChecks');
        box.innerHTML = '';
        const connected = STATE.connections.filter(c => c.status === 'connected').map(c => c.network);
        connected.forEach(n => {
            const m = STATE.networks[n];
            const on = STATE.selected.includes(n);
            const label = document.createElement('label');
            label.className = 'net-check' + (on ? ' on' : '');
            label.innerHTML = `<input type="checkbox" ${on ? 'checked' : ''} onchange="toggleNet('${n}')"><i class="${m.icon}" style="color:${m.color};"></i> ${esc(m.name)}`;
            box.appendChild(label);
        });
        if (!connected.length) {
            box.innerHTML = '<span class="form-hint">Conecte ao menos uma conta em <a href="/admin/integrations.php">Integrações</a> para publicar.</span>';
        }
        updateCost();
    }

    function toggleNet(n) {
        const i = STATE.selected.indexOf(n);
        if (i >= 0) STATE.selected.splice(i, 1); else STATE.selected.push(n);
        renderChecks();
    }

    document.getElementById('mediaFile').addEventListener('change', async (e) => {
        const f = e.target.files[0];
        if (!f) return;
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('media', f);
        const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.error) { alert(j.error); e.target.value = ''; return; }
        STATE.media = { url: j.url, kind: j.kind };
        document.getElementById('mediaUrl').value = '';
        showPreview(j.url, j.kind);
        updateCost();
    });

    document.getElementById('mediaUrl').addEventListener('input', (e) => {
        const url = e.target.value.trim();
        const kind = /\.(mp4|webm|mov)(\?|$)/i.test(url) ? 'video' : (url ? 'image' : '');
        STATE.media = url ? { url, kind } : { url: '', kind: '' };
        showPreview(STATE.media.url, kind);
        updateCost();
    });

    function showPreview(url, kind) {
        const img = document.getElementById('mediaPreview');
        const vid = document.getElementById('mediaPreviewVid');
        img.style.display = 'none'; vid.style.display = 'none';
        if (!url) return;
        if (kind === 'video') { vid.src = url; vid.style.display = ''; }
        else { img.src = url; img.style.display = ''; }
    }

    function updateCost() {
        const box = document.getElementById('costBox');
        const caption = document.getElementById('caption').value;
        const hasX = STATE.selected.includes('x');
        if (!hasX) { box.innerHTML = '<i class="fas fa-circle-info"></i> Publique em todas as redes de uma vez — falha numa rede não afeta as outras.'; return; }
        const hasUrl = /(https?:\/\/\S+)/i.test(caption);
        const cost = hasUrl ? 0.20 : 0.015;
        box.innerHTML = '<i class="fab fa-x-twitter"></i> Custo estimado do X com sua conta (BYOK): <b>$' + cost.toFixed(hasUrl ? 2 : 3) + '</b> por post' + (hasUrl ? ' (com link)' : ' (sem link)');
    }
    document.getElementById('caption').addEventListener('input', updateCost);

    document.getElementById('scheduledAt').addEventListener('change', (e) => {
        document.getElementById('publishLabel').textContent = e.target.value ? 'Agendar' : 'Publicar agora';
    });

    async function publish() {
        const caption = document.getElementById('caption').value.trim();
        const msg = document.getElementById('composerMsg');
        msg.textContent = '';
        if (!STATE.selected.length) { msg.textContent = 'Selecione ao menos uma rede conectada.'; return; }
        if (!caption && !STATE.media.url) { msg.textContent = 'Escreva uma legenda ou envie uma mídia.'; return; }

        const mediaUrl = document.getElementById('mediaUrl').value.trim() || STATE.media.url;
        const sched = document.getElementById('scheduledAt').value;
        if (sched) {
            const ts = new Date(sched).getTime();
            if (isNaN(ts) || ts < Date.now() + 60000) { msg.textContent = 'O agendamento precisa ser ao menos 1 minuto no futuro.'; return; }
        }

        const btn = document.getElementById('publishBtn');
        btn.disabled = true;
        msg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…';
        try {
            const j = await api('create', {
                caption,
                media_url: mediaUrl,
                media_kind: STATE.media.kind || '',
                networks: STATE.selected.join(','),
                scheduled_at: sched ? new Date(sched).toISOString().slice(0, 19).replace('T', ' ') : '',
            });
            if (!j.ok) {
                msg.textContent = j.error || 'Falha ao publicar.';
                if (j.errors && j.errors.length) msg.textContent = j.errors.join(' · ');
                return;
            }
            const published = (j.targets || []).filter(t => t.status === 'published').length;
            const failed = (j.targets || []).filter(t => t.status === 'failed').length;
            msg.innerHTML = j.status === 'scheduled'
                ? '<i class="fas fa-check" style="color:#28a745;"></i> Agendado para ' + esc(j.scheduled_at) + '!'
                : '<i class="fas fa-check" style="color:#28a745;"></i> ' + published + ' publicada(s)' + (failed ? ', ' + failed + ' falhou (ver histórico)' : '') + '!';
            if (j.status !== 'scheduled') {
                document.getElementById('caption').value = '';
                STATE.media = { url: '', kind: '' };
                document.getElementById('mediaFile').value = '';
                document.getElementById('mediaUrl').value = '';
                showPreview('', '');
            }
            document.getElementById('scheduledAt').value = '';
            document.getElementById('publishLabel').textContent = 'Publicar agora';
            loadConnections();
            loadHistory(true);
        } catch (e) {
            msg.textContent = 'Erro de rede: ' + e.message;
        } finally {
            btn.disabled = false;
            setTimeout(() => { if (msg.textContent.indexOf('Enviando') === -1) msg.textContent = ''; }, 6000);
        }
    }

    async function loadHistory(force) {
        const j = await api('list', { limit: 20 });
        const body = document.getElementById('histBody');
        const posts = (j && j.posts) || [];
        if (!posts.length) {
            body.innerHTML = '<tr><td colspan="5" class="empty"><i class="fas fa-feather"></i> Nenhuma publicação ainda — escreva a primeira acima.</td></tr>';
            return;
        }
        body.innerHTML = posts.map(p => `
            <tr class="hist-row" style="border-bottom:1px solid var(--border,#eee);">
                <td style="padding:11px 14px;max-width:340px;">
                    ${p.media_url ? '<i class="fas fa-' + (p.media_kind === 'video' ? 'video' : 'image') + '" style="color:var(--text-secondary);"></i> ' : ''}
                    ${esc((p.caption || '(sem legenda)').slice(0, 120))}${(p.caption || '').length > 120 ? '…' : ''}
                </td>
                <td style="padding:8px 14px;">${p.targets.map(t =>
                    `<span class="tgt-chip ${t.status}" title="${esc(t.error || '')}"><i class="fas fa-circle" style="font-size:.5rem;"></i> ${esc(t.network_label)}${t.error ? ' — ' + esc(t.error.slice(0, 70)) : ''}</span>`).join('')}</td>
                <td style="padding:11px 14px;"><span class="pill ${p.status === 'published' ? 'ok' : (p.status === 'failed' ? 'err' : (p.status === 'partial' ? 'warn' : ''))}">${POST_PT[p.status] || esc(p.status)}</span></td>
                <td style="padding:11px 14px;white-space:nowrap;">${fmtDate(p.published_at || p.scheduled_at || p.created_at)}${p.status === 'scheduled' ? '<br><small>agendado</small>' : ''}</td>
                <td style="padding:11px 14px;text-align:right;">
                    ${['scheduled', 'failed', 'partial'].includes(p.status) ? `<button class="btn btn-sm" onclick="retry(${p.id})" title="Publicar agora"><i class="fas fa-rotate"></i></button> ` : ''}
                    <button class="btn btn-sm" onclick="removePost(${p.id})" title="Excluir"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`).join('');

        const active = posts.some(p => ['scheduled', 'publishing'].includes(p.status));
        STATE.posts = posts;
        loadMetrics();
        if (active && !force) setTimeout(() => loadHistory(), 15000);
    }

    async function loadMetrics() {
        const j = await api('metrics');
        const byPost = (j && j.by_post) || {};
        STATE.metrics = byPost;
        const posts = (STATE.posts || []).filter(p =>
            p.targets.some(t => t.status === 'published'));
        const body = document.getElementById('metricsBody');
        const pill = document.getElementById('metricsPill');
        if (!posts.length) {
            body.innerHTML = '<tr><td colspan="8" class="empty">As métricas aparecem aqui após publicar.</td></tr>';
            pill.innerHTML = '<i class="fas fa-chart-simple"></i> sem dados';
            return;
        }
        let last = '';
        const rows = [];
        posts.slice(0, 15).forEach(p => {
            p.targets.filter(t => t.status === 'published').forEach(t => {
                const m = (byPost[p.id] || {})[t.network] || null;
                if (m && m.collected_at) last = m.collected_at;
                rows.push(`<tr style="border-bottom:1px solid var(--border,#eee);">
                    <td style="padding:9px 14px;max-width:260px;">${esc((p.caption || '(sem legenda)').slice(0, 70))}</td>
                    <td style="padding:9px 14px;"><span class="tgt-chip published"><i class="fas fa-circle" style="font-size:.5rem;"></i> ${esc(t.network_label)}</span></td>
                    <td style="padding:9px 14px;">${m ? (m.likes || 0).toLocaleString('pt-BR') : '—'}</td>
                    <td style="padding:9px 14px;">${m ? (m.comments || 0).toLocaleString('pt-BR') : '—'}</td>
                    <td style="padding:9px 14px;">${m ? (m.shares || 0).toLocaleString('pt-BR') : '—'}</td>
                    <td style="padding:9px 14px;">${m ? (m.impressions || 0).toLocaleString('pt-BR') : '—'}</td>
                    <td style="padding:9px 14px;">${m ? ((m.reach || m.views || 0)).toLocaleString('pt-BR') : '—'}</td>
                    <td style="padding:9px 14px;white-space:nowrap;font-size:.78rem;color:var(--text-secondary);">${m && m.collected_at ? fmtDate(m.collected_at) : 'não coletado'}</td>
                </tr>`);
            });
        });
        body.innerHTML = rows.join('') || '<tr><td colspan="8" class="empty">As métricas aparecem aqui após publicar.</td></tr>';
        pill.innerHTML = last
            ? '<i class="fas fa-chart-simple"></i> atualizado ' + fmtDate(last)
            : '<i class="fas fa-chart-simple"></i> clique em Atualizar';
    }

    async function collectMetrics() {
        const btn = document.getElementById('metricsBtn');
        const pill = document.getElementById('metricsPill');
        btn.disabled = true;
        pill.innerHTML = '<i class="fas fa-spinner fa-spin"></i> coletando…';
        try {
            const j = await api('collect');
            const s = (j && j.summary) || {};
            pill.innerHTML = '<i class="fas fa-chart-simple"></i> ' + (s.updated || 0) + ' atualizada(s)'
                + ((s.failed || 0) ? ', ' + s.failed + ' falha(s)' : '');
            await loadMetrics();
        } finally {
            btn.disabled = false;
        }
    }

    async function retry(id) {
        await api('process');
        await loadHistory(true);
    }

    async function removePost(id) {
        if (!confirm('Excluir esta publicação do histórico?')) return;
        await api('delete', { id });
        loadHistory(true);
    }

    // Polling de agendamentos vencidos (o cron faz o mesmo no servidor)
    setInterval(async () => {
        if (!document.hasFocus()) return;
        await api('process');
    }, 60000);

    loadConnections();
    loadHistory();
    </script>
</body>
</html>
