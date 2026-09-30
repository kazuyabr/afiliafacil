<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Social/SocialNetworks.php';
Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
$user = Auth::user();
$plan = (string)$user['plan'];
$hasFeature = Plans::hasFeature($plan, 'social');
$oauthMsg = null;
if (isset($_GET['oauth'])) {
    $oauthMsg = $_GET['oauth'] === 'ok'
        ? ['ok' => true, 'text' => 'Conta conectada com sucesso!']
        : ['ok' => false, 'text' => 'Falha na conexão: ' . htmlspecialchars($_GET['msg'] ?? 'erro desconhecido', ENT_QUOTES)];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrações — AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .net-card { display:flex; gap:14px; align-items:flex-start; padding:18px; }
        .net-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
        .net-body { flex:1; min-width:0; }
        .net-body h3 { margin:0; font-size:1rem; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .net-body p { margin:4px 0 10px; font-size:.82rem; color:var(--text-secondary); }
        .pill { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:600; padding:3px 9px; border-radius:20px; background:var(--bg-secondary,#eee); border:1px solid var(--border,#ddd); }
        .pill.ok { background:#e6f6ec; border-color:#9fdcb8; color:#14713d; }
        .pill.warn { background:#fff6e0; border-color:#f0d391; color:#8a6100; }
        .pill.err { background:#fdecec; border-color:#f0a8a8; color:#a32020; }
        [data-theme="dark"] .pill.ok { background:#123f26; border-color:#1d6b41; color:#7ee0a8; }
        [data-theme="dark"] .pill.warn { background:#4a3a10; border-color:#7a6122; color:#ffd979; }
        [data-theme="dark"] .pill.err { background:#4a1a1a; border-color:#7a2e2e; color:#ff9c9c; }
        .net-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .composer textarea { width:100%; min-height:110px; resize:vertical; font-family:inherit; }
        .net-checks { display:flex; gap:8px; flex-wrap:wrap; margin:12px 0; }
        .net-check { display:flex; align-items:center; gap:7px; padding:8px 13px; border:1.5px solid var(--border,#ddd); border-radius:10px; cursor:pointer; font-size:.85rem; user-select:none; }
        .net-check.on { border-color:var(--accent,#0b5ed7); background:color-mix(in srgb, var(--accent,#0b5ed7) 8%, transparent); }
        .net-check input { display:none; }
        .media-preview { margin-top:8px; max-height:130px; border-radius:8px; border:1px solid var(--border,#ddd); }
        .hist-row td { vertical-align:top; }
        .tgt-chip { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; padding:2px 8px; border-radius:12px; margin:2px 3px 2px 0; border:1px solid var(--border,#ddd); }
        .tgt-chip.published { background:#e6f6ec; border-color:#9fdcb8; color:#14713d; }
        .tgt-chip.failed { background:#fdecec; border-color:#f0a8a8; color:#a32020; }
        .tgt-chip.pending, .tgt-chip.publishing { background:#fff6e0; border-color:#f0d391; color:#8a6100; }
        [data-theme="dark"] .tgt-chip.published { background:#123f26; border-color:#1d6b41; color:#7ee0a8; }
        [data-theme="dark"] .tgt-chip.failed { background:#4a1a1a; border-color:#7a2e2e; color:#ff9c9c; }
        [data-theme="dark"] .tgt-chip.pending, [data-theme="dark"] .tgt-chip.publishing { background:#4a3a10; border-color:#7a6122; color:#ffd979; }
        .manual-box { display:none; gap:8px; margin-top:10px; }
        .manual-box.open { display:flex; }
        .manual-box input { flex:1; }
        .cost-box { font-size:.8rem; color:var(--text-secondary); }
        .cost-box b { color:var(--text); }
        .sec-title { display:flex; justify-content:space-between; align-items:center; margin:26px 0 12px; gap:12px; flex-wrap:wrap; }
        .sec-title h2 { margin:0; font-size:1.1rem; }
        .empty { text-align:center; padding:26px; color:var(--text-secondary); font-size:.88rem; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Integrações</div>
                <div class="topbar-actions">
                    <span class="pill" id="quotaPill"><i class="fas fa-spinner fa-spin"></i></span>
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Redes sociais</h1>
                    <p style="color:var(--text-secondary);font-size:.9rem;">Conecte suas contas e publique em várias redes de uma vez — manualmente, agendado ou pelo Sócio.</p>
                </div>

                <?php if ($oauthMsg): ?>
                <div class="alert alert-<?= $oauthMsg['ok'] ? 'success' : 'danger' ?>">
                    <i class="fas fa-<?= $oauthMsg['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i> <?= $oauthMsg['text'] ?>
                </div>
                <?php endif; ?>

                <div id="featureAlert" class="alert alert-warning" style="display:none;">
                    <i class="fas fa-lock"></i> Seu plano não inclui publicações sociais.
                    <a href="/admin/plan.php">Fazer upgrade</a> para liberar conexões e publicações.
                </div>

                <div class="sec-title">
                    <h2>Contas conectadas</h2>
                    <span class="pill" id="connPill">—</span>
                </div>
                <div class="grid-3" id="netGrid">
                    <div class="card"><div class="card-body"><span class="pill"><i class="fas fa-spinner fa-spin"></i> Carregando…</span></div></div>
                </div>

                <div id="composerSection">
                    <div class="sec-title">
                        <h2>Publicar</h2>
                        <span class="pill" id="postPill">—</span>
                    </div>
                    <div class="card composer">
                        <div class="card-body">
                            <label style="font-size:.85rem;font-weight:600;">Legenda</label>
                            <textarea id="caption" placeholder="Escreva a legenda do post… (emoji e links permitidos)"></textarea>

                            <div class="net-checks" id="netChecks"></div>

                            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                                <div style="flex:1;min-width:220px;">
                                    <label style="font-size:.85rem;font-weight:600;">Mídia (imagem ou vídeo)</label>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <input type="file" id="mediaFile" accept="image/*,video/mp4" style="max-width:240px;">
                                        <input type="url" id="mediaUrl" placeholder="…ou cole uma URL de mídia" style="flex:1;min-width:180px;font-size:.85rem;">
                                    </div>
                                    <img id="mediaPreview" class="media-preview" style="display:none;" alt="">
                                    <video id="mediaPreviewVid" class="media-preview" style="display:none;" controls muted></video>
                                </div>
                                <div style="min-width:210px;">
                                    <label style="font-size:.85rem;font-weight:600;">Agendar (opcional)</label>
                                    <input type="datetime-local" id="scheduledAt" style="width:100%;">
                                </div>
                            </div>

                            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-top:16px;">
                                <div class="cost-box" id="costBox"></div>
                                <div style="display:flex;gap:10px;">
                                    <span id="composerMsg" style="font-size:.82rem;color:var(--text-secondary);"></span>
                                    <button class="btn btn-primary" id="publishBtn" onclick="publish()"><i class="fas fa-paper-plane"></i> <span id="publishLabel">Publicar agora</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sec-title">
                    <h2>Desempenho</h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span class="pill" id="metricsPill">—</span>
                        <button class="btn btn-sm" id="metricsBtn" onclick="collectMetrics()"><i class="fas fa-rotate"></i> Atualizar métricas</button>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body" style="padding:0;overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                            <thead><tr style="text-align:left;border-bottom:1px solid var(--border,#ddd);">
                                <th style="padding:11px 14px;">Post</th>
                                <th style="padding:11px 14px;">Rede</th>
                                <th style="padding:11px 14px;">Curtidas</th>
                                <th style="padding:11px 14px;">Coment.</th>
                                <th style="padding:11px 14px;">Compart.</th>
                                <th style="padding:11px 14px;">Impressões</th>
                                <th style="padding:11px 14px;">Alcance/Views</th>
                                <th style="padding:11px 14px;">Coletado</th>
                            </tr></thead>
                            <tbody id="metricsBody">
                                <tr><td colspan="8" class="empty"><i class="fas fa-spinner fa-spin"></i></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sec-title">
                    <h2>Histórico</h2>
                    <button class="btn btn-sm" onclick="loadHistory(true)"><i class="fas fa-rotate"></i> Atualizar</button>
                </div>
                <div class="card">
                    <div class="card-body" style="padding:0;overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                            <thead><tr style="text-align:left;border-bottom:1px solid var(--border,#ddd);">
                                <th style="padding:11px 14px;">Post</th>
                                <th style="padding:11px 14px;">Redes</th>
                                <th style="padding:11px 14px;">Status</th>
                                <th style="padding:11px 14px;">Data</th>
                                <th style="padding:11px 14px;"></th>
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
    let STATE = { networks: {}, connections: [], selected: [], media: { url: '', kind: '' } };

    const STATUS_PT = { pending: 'pendente', publishing: 'publicando…', published: 'publicado', failed: 'falhou', scheduled: 'agendado' };
    const POST_PT = { draft: 'rascunho', scheduled: 'agendado', publishing: 'publicando…', published: 'publicado', partial: 'parcial', failed: 'falhou' };

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
        if (j.error) { document.getElementById('netGrid').innerHTML = '<div class="empty">' + esc(j.error) + '</div>'; return; }
        STATE.networks = j.networks || {};
        STATE.connections = j.connections || [];
        STATE.connQuota = j.conn_quota;
        STATE.postQuota = j.post_quota;

        document.getElementById('featureAlert').style.display = j.has_feature ? 'none' : '';
        document.getElementById('composerSection').style.display = j.has_feature ? '' : 'none';

        const cq = j.conn_quota || {};
        document.getElementById('connPill').innerHTML = cq.max === -1
            ? '<i class="fas fa-link"></i> ' + (cq.used || 0) + ' conexões (ilimitado)'
            : '<i class="fas fa-link"></i> ' + (cq.used || 0) + '/' + (cq.max ?? 0) + ' conexões';
        const pq = j.post_quota || {};
        document.getElementById('postPill').innerHTML = pq.max === -1
            ? '<i class="fas fa-calendar"></i> ' + (pq.used || 0) + ' posts (ilimitado)'
            : '<i class="fas fa-calendar"></i> ' + (pq.used || 0) + '/' + (pq.max ?? 0) + ' posts/mês';
        document.getElementById('quotaPill').innerHTML = j.has_feature
            ? '<i class="fas fa-circle" style="color:#28a745;font-size:.6rem;"></i> Social ativo'
            : '<i class="fas fa-lock"></i> Social: upgrade';

        const connByNet = {};
        (j.connections || []).forEach(c => connByNet[c.network] = c);

        const grid = document.getElementById('netGrid');
        grid.innerHTML = '';
        Object.entries(j.networks).forEach(([n, m]) => {
            const c = connByNet[n];
            const div = document.createElement('div');
            div.className = 'card';
            div.innerHTML = `
                <div class="card-body net-card">
                    <div class="net-icon" style="background:${m.color}1f;color:${m.color};"><i class="${m.icon}"></i></div>
                    <div class="net-body">
                        <h3>${esc(m.name)}
                            ${m.beta ? '<span class="pill warn">beta</span>' : ''}
                            ${c && c.status === 'connected'
                                ? '<span class="pill ok"><i class="fas fa-check"></i> conectada</span>'
                                : c ? '<span class="pill err"><i class="fas fa-exclamation"></i> ' + esc(c.status === 'expired' ? 'expirada' : c.status) + '</span>'
                                : '<span class="pill">não conectada</span>'}
                        </h3>
                        <p>${esc(m.desc)} · ${m.media === 'video' ? 'vídeo' : 'imagem'}${m.media_required ? ' (obrigatório)' : ' (opcional)'}</p>
                        ${c && c.status === 'connected'
                            ? `<div class="net-actions">
                                 <span style="font-size:.8rem;"><i class="fas fa-circle-user"></i> ${esc(c.account_name || c.account_id)}</span>
                                 <button class="btn btn-sm" onclick="disconnect('${n}')"><i class="fas fa-unlink"></i> Desconectar</button>
                               </div>`
                            : `<div class="net-actions">
                                 <button class="btn btn-sm btn-primary" onclick="connectOAuth('${n}')" ${!m.oauth_configured ? 'disabled title="OAuth da plataforma não configurado — use o token"' : ''}><i class="fas fa-plug"></i> Conectar</button>
                                 <button class="btn btn-sm" onclick="toggleManual('${n}')">Colar token</button>
                               </div>
                               <div class="manual-box" id="manual-${n}">
                                 <input type="password" id="token-${n}" placeholder="Token de acesso da ${esc(m.name)}">
                                 <button class="btn btn-sm btn-primary" onclick="saveToken('${n}')">Salvar</button>
                               </div>`}
                    </div>
                </div>`;
            grid.appendChild(div);
        });

        renderChecks();
        loadHistory();
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
            box.innerHTML = '<span style="font-size:.83rem;color:var(--text-secondary);">Conecte ao menos uma conta acima para publicar.</span>';
        }
        updateCost();
    }

    function toggleNet(n) {
        const i = STATE.selected.indexOf(n);
        if (i >= 0) STATE.selected.splice(i, 1); else STATE.selected.push(n);
        renderChecks();
    }

    function toggleManual(n) {
        document.getElementById('manual-' + n).classList.toggle('open');
    }

    async function connectOAuth(n) {
        const j = await api('connect-url', { network: n });
        if (j.url) { window.location.href = j.url; return; }
        alert(j.error || 'Não foi possível iniciar a conexão.');
    }

    async function saveToken(n) {
        const token = document.getElementById('token-' + n).value.trim();
        if (!token) { alert('Cole o token de acesso.'); return; }
        const btn = event.target.closest('button'); btn.disabled = true;
        const j = await api('save-token', { network: n, token });
        btn.disabled = false;
        if (j.ok) { await loadConnections(); }
        else alert(j.error || 'Falha ao validar o token.');
    }

    async function disconnect(n) {
        if (!confirm('Desconectar esta conta? Publicações agendadas para ela falharão.')) return;
        await api('disconnect', { network: n });
        STATE.selected = STATE.selected.filter(s => s !== n);
        await loadConnections();
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
    </script>
</body>
</html>
