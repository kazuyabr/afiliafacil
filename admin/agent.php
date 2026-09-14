<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Agent/AgentQuota.php';
require_once Config::getLibDir() . '/Agent/AgentProfile.php';

Auth::requireAuth();

$user = Auth::user();
$isAdmin = Auth::isAdmin();
if (!Plans::hasFeature($user['plan'], 'agent') && !$isAdmin) {
    header('Location: /admin/plan.php?upgrade=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$quota = AgentQuota::check((int)$user['id'], $user['plan']);
$profile = AgentProfile::forUser((int)$user['id']);
$profileText = AgentProfile::describe($profile);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sócio - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .agent-layout { display:flex; gap:20px; height:calc(100vh - 130px); }
        .agent-conversations { width:250px; flex-shrink:0; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); display:flex; flex-direction:column; overflow:hidden; }
        .agent-conversations .header { padding:12px; border-bottom:1px solid var(--border-color); }
        .agent-conversations .list { flex:1; overflow-y:auto; }
        .agent-conv-item { padding:10px 12px; border-bottom:1px solid var(--border-color); cursor:pointer; font-size:.82rem; display:flex; justify-content:space-between; gap:8px; align-items:center; }
        .agent-conv-item:hover { background:var(--bg-secondary); }
        .agent-conv-item.active { background:var(--accent-light); border-left:3px solid var(--accent); }
        .agent-conv-item .title { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .agent-conv-item .del { opacity:.4; }
        .agent-conv-item:hover .del { opacity:1; }
        .agent-chat { flex:1; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); display:flex; flex-direction:column; overflow:hidden; min-width:0; }
        .agent-chat-header { padding:12px 16px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
        .agent-messages { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:14px; }
        .msg { max-width:78%; padding:12px 15px; border-radius:14px; font-size:.88rem; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
        .msg.user { align-self:flex-end; background:var(--accent); color:#fff; border-bottom-right-radius:4px; }
        .msg.agent { align-self:flex-start; background:var(--bg-secondary); border-bottom-left-radius:4px; }
        .msg.tool-card { align-self:flex-start; background:var(--bg-card); border:1px solid var(--border-color); max-width:88%; width:100%; padding:0; overflow:hidden; }
        .tool-head { padding:12px 15px; display:flex; justify-content:space-between; align-items:center; gap:10px; }
        .tool-head .name { font-weight:600; font-size:.85rem; display:flex; align-items:center; gap:8px; }
        .tool-body { padding:0 15px 12px; font-size:.82rem; color:var(--text-secondary); }
        .tool-actions { padding:12px 15px; border-top:1px solid var(--border-color); display:flex; gap:8px; }
        .tool-result { padding:14px 15px; border-top:1px solid var(--border-color); }
        .status-badge { font-size:.68rem; padding:2px 8px; border-radius:10px; text-transform:uppercase; font-weight:700; letter-spacing:.03em; }
        .status-pending_confirmation { background:#fef3c7; color:#92400e; }
        .status-executed { background:#dcfce7; color:#166534; }
        .status-failed { background:#fee2e2; color:#991b1b; }
        .status-cancelled { background:var(--bg-secondary); color:var(--text-secondary); }
        .chip { display:inline-block; padding:6px 14px; border-radius:20px; border:1px solid var(--accent); color:var(--accent); font-size:.82rem; cursor:pointer; margin:4px 6px 0 0; background:var(--bg-card); }
        .chip:hover { background:var(--accent-light); }
        .agent-input { border-top:1px solid var(--border-color); padding:12px 16px; display:flex; gap:10px; align-items:flex-end; }
        .agent-input textarea { flex:1; resize:none; min-height:44px; max-height:120px; padding:11px 14px; border:1px solid var(--border-color); border-radius:var(--radius); background:var(--bg-primary); color:var(--text-primary); font-family:inherit; font-size:.88rem; }
        .mini-offer { display:flex; gap:10px; align-items:center; padding:8px; border:1px solid var(--border-color); border-radius:var(--radius); margin-bottom:6px; background:var(--bg-card); }
        .mini-offer .info { min-width:0; flex:1; }
        .mini-offer .name { font-size:.82rem; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .mini-offer .meta { font-size:.72rem; color:var(--text-secondary); }
        .mini-offer .scale { font-size:.78rem; font-weight:700; }
        .ad-mini { display:flex; gap:10px; padding:8px; border:1px solid var(--border-color); border-radius:var(--radius); margin-bottom:6px; background:var(--bg-card); }
        .ad-mini img { width:70px; height:70px; object-fit:cover; border-radius:6px; flex-shrink:0; }
        .ad-mini .info { font-size:.75rem; min-width:0; }
        .agent-typing { align-self:flex-start; color:var(--text-secondary); font-size:.85rem; display:flex; gap:8px; align-items:center; }
        .principles { font-size:.72rem; color:var(--text-secondary); padding:8px 16px; border-bottom:1px solid var(--border-color); background:var(--bg-secondary); }
        @media (max-width: 900px) { .agent-conversations { display:none; } }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Sócio</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="agent-layout">
                    <aside class="agent-conversations">
                        <div class="header">
                            <button class="btn btn-primary btn-sm btn-full" onclick="newConversation()"><i class="fas fa-plus"></i> Nova conversa</button>
                        </div>
                        <div class="list" id="convList"></div>
                    </aside>
                    <div class="agent-chat">
                        <div class="agent-chat-header">
                            <div>
                                <strong style="font-size:.95rem;"><i class="fas fa-handshake" style="color:var(--accent);"></i> Seu Sócio</strong>
                                <div style="font-size:.72rem;color:var(--text-secondary);margin-top:2px;" id="profileLine">Perfil: <?= htmlspecialchars($profileText) ?></div>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <span class="quota-pill" id="quotaPill"><i class="fas fa-comments"></i> <strong><?= $quota['limit'] === -1 ? 'ilimitado' : $quota['used'] . '/' . $quota['limit'] ?></strong></span>
                                <button class="btn btn-outline btn-sm" onclick="editProfile()" title="Editar perfil"><i class="fas fa-user-pen"></i></button>
                            </div>
                        </div>
                        <div class="principles"><i class="fas fa-shield-halved"></i> Sem promessa de ganho fácil. Comece pequeno e teste. Eu pergunto antes de agir — e te aviso quando algo parece furada.</div>
                        <div class="agent-messages" id="agentMessages"></div>
                        <div class="agent-input">
                            <textarea id="agentInput" placeholder="Escreva para o seu sócio... (Enter envia, Shift+Enter quebra linha)" onkeydown="handleKey(event)"><?= htmlspecialchars($_GET['ask'] ?? '') ?></textarea>
                            <button class="btn btn-primary" id="sendBtn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let conversationId = 0;
    let sending = false;

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function handleKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    }

    function updateQuota(quota) {
        if (!quota) return;
        document.getElementById('quotaPill').innerHTML = '<i class="fas fa-comments"></i> <strong>' + (quota.limit === -1 ? 'ilimitado' : quota.used + '/' + quota.limit) + '</strong>';
    }

    async function loadConversations() {
        const resp = await fetch('/admin/api/agent.php?action=conversations');
        const data = await resp.json();
        const list = document.getElementById('convList');
        const items = data.conversations || [];
        if (!items.length) {
            list.innerHTML = '<div style="padding:16px;font-size:.78rem;color:var(--text-secondary);text-align:center;">Nenhuma conversa ainda.</div>';
            return;
        }
        list.innerHTML = items.map(c =>
            '<div class="agent-conv-item ' + (c.id === conversationId ? 'active' : '') + '" onclick="openConversation(' + c.id + ')">' +
                '<span class="title">' + esc(c.title) + '</span>' +
                '<span class="del" onclick="event.stopPropagation();deleteConversation(' + c.id + ')" title="Excluir"><i class="fas fa-trash"></i></span>' +
            '</div>'
        ).join('');
    }

    async function openConversation(id) {
        conversationId = id;
        const resp = await fetch('/admin/api/agent.php?action=conversation&id=' + id);
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        renderMessages(data.messages || []);
        loadConversations();
    }

    async function newConversation() {
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'new' }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        conversationId = data.conversation_id;
        await openConversation(conversationId);
        document.getElementById('agentInput').focus();
    }

    async function deleteConversation(id) {
        if (!confirm('Excluir esta conversa?')) return;
        await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'delete', id }) });
        if (conversationId === id) { conversationId = 0; document.getElementById('agentMessages').innerHTML = ''; }
        loadConversations();
    }

    function renderMessages(messages) {
        const container = document.getElementById('agentMessages');
        container.innerHTML = messages.map(m => renderMessage(m)).join('');
        container.scrollTop = container.scrollHeight;
    }

    function renderMessage(m) {
        if (m.role === 'user') {
            return '<div class="msg user">' + esc(m.content) + '</div>';
        }

        if (m.role === 'tool') {
            return renderToolCard(m);
        }

        const options = (m.tool_args && m.tool_args.options) || [];
        let html = '<div class="msg agent">' + esc(m.content) + '</div>';
        if (options.length) {
            html += '<div style="align-self:flex-start;">' + options.map(o =>
                '<span class="chip" onclick="pickOption(\'' + esc(o).replace(/'/g, "\\'") + '\')">' + esc(o) + '</span>'
            ).join('') + '</div>';
        }
        return html;
    }

    function renderToolCard(m) {
        const labels = {
            ver_oferta: 'Ver dossiê da oferta',
            espionar_anuncios: 'Espionar anúncios',
            analisar_oferta: 'Analisar oferta com IA',
            transcrever_midia: 'Transcrever mídia',
            gerar_narracao: 'Gerar narração',
            clonar_pagina: 'Clonar página',
        };
        const label = labels[m.tool_name] || m.tool_name;
        const status = m.status || '';
        const argsText = m.tool_args ? Object.entries(m.tool_args).filter(([k]) => k !== 'options').map(([k, v]) => k + ': ' + String(v).substring(0, 120)).join(' | ') : '';

        let html = '<div class="msg tool-card">' +
            '<div class="tool-head">' +
                '<span class="name"><i class="fas fa-bolt" style="color:var(--accent);"></i> ' + esc(label) + '</span>' +
                '<span class="status-badge status-' + esc(status) + '">' + (status === 'pending_confirmation' ? 'aguardando você' : status === 'executed' ? 'executada' : status === 'cancelled' ? 'cancelada' : 'falhou') + '</span>' +
            '</div>' +
            (m.content ? '<div class="tool-body">' + esc(m.content) + '</div>' : '') +
            (argsText ? '<div class="tool-body" style="font-family:monospace;font-size:.75rem;">' + esc(argsText) + '</div>' : '');

        if (status === 'pending_confirmation') {
            html += '<div class="tool-actions">' +
                '<button class="btn btn-primary btn-sm" onclick="confirmTool(' + m.id + ')"><i class="fas fa-check"></i> Confirmar</button>' +
                '<button class="btn btn-outline btn-sm" onclick="cancelTool(' + m.id + ')"><i class="fas fa-xmark"></i> Cancelar</button>' +
            '</div>';
        }

        if (m.tool_result && m.tool_result.render) {
            html += '<div class="tool-result">' + renderToolResult(m.tool_result.render) + '</div>';
        } else if (m.tool_result && !m.tool_result.success && m.tool_result.summary) {
            html += '<div class="tool-result" style="color:var(--danger);font-size:.82rem;">' + esc(m.tool_result.summary) + '</div>';
        }

        return html + '</div>';
    }

    function renderToolResult(render) {
        const data = render.data || {};

        if (render.type === 'quotas') {
            return '<div style="display:flex;gap:6px;flex-wrap:wrap;font-size:.75rem;">' +
                Object.entries(data).filter(([k]) => k !== 'plano').map(([k, v]) => {
                    if (typeof v !== 'object') return '';
                    const label = { paginas: 'Páginas', dominios: 'Domínios', adspy: 'Buscas', ia: 'Análises IA', ofertas: 'Ofertas', transcricoes: 'Transcrições', narracoes: 'Narrações', agente: 'Sócio' }[k] || k;
                    const used = v.used ?? 0;
                    const limit = v.limit === -1 ? '∞' : v.limit;
                    return '<span class="quota-pill" style="padding:4px 10px;">' + esc(label) + ': ' + used + '/' + limit + '</span>';
                }).join('') + '</div>';
        }

        if (render.type === 'ofertas') {
            if (!data.length) return '<div style="font-size:.82rem;color:var(--text-secondary);">Nenhuma oferta encontrada.</div>';
            return data.map(o =>
                '<div class="mini-offer">' +
                    '<div class="info">' +
                        '<div class="name">' + esc(o.name) + '</div>' +
                        '<div class="meta">' + esc(o.niche || '—') + ' · ' + esc((o.structure || '—').replace(/_/g, ' ')) + ' · ' + o.ads_count + ' anúncios</div>' +
                    '</div>' +
                    '<div class="scale" style="color:' + (o.scale_pct >= 0 ? '#16a34a' : '#dc2626') + ';">' + (o.scale_pct > 0 ? '+' : '') + o.scale_pct + '%</div>' +
                '</div>'
            ).join('');
        }

        if (render.type === 'anuncios') {
            if (!data.length) return '<div style="font-size:.82rem;color:var(--text-secondary);">Nenhum anúncio encontrado.</div>';
            return data.slice(0, 8).map(ad =>
                '<div class="ad-mini">' +
                    (ad.thumbnail ? '<img src="' + esc(ad.thumbnail) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
                    '<div class="info">' +
                        '<div style="font-weight:600;">' + esc(ad.advertiser || 'Anunciante') + '</div>' +
                        '<div style="color:var(--text-secondary);">' + esc((ad.text || ad.title || '').substring(0, 120)) + '</div>' +
                        '<div style="margin-top:2px;"><span class="offer-tag" style="font-size:.65rem;background:var(--bg-secondary);padding:1px 6px;border-radius:8px;">' + esc(ad.provider || '') + '</span></div>' +
                    '</div>' +
                '</div>'
            ).join('') + (data.length > 8 ? '<div style="font-size:.75rem;color:var(--text-secondary);">+' + (data.length - 8) + ' anúncios</div>' : '');
        }

        if (render.type === 'oferta') {
            return '<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:.8rem;">' +
                '<span><strong>' + data.ads_count + '</strong> anúncios</span>' +
                '<span>escala <strong style="color:' + (data.scale_pct >= 0 ? '#16a34a' : '#dc2626') + ';">' + (data.scale_pct > 0 ? '+' : '') + data.scale_pct + '%</strong></span>' +
                '<span>score <strong>' + data.score + '</strong></span>' +
                '<span>' + data.creatives_count + ' criativos · ' + data.pages_count + ' páginas</span>' +
            '</div>' + (data.ai_summary ? '<div style="font-size:.8rem;margin-top:8px;line-height:1.6;">' + esc(data.ai_summary) + '</div>' : '');
        }

        if (render.type === 'analise') {
            let html = '';
            if (data.score !== undefined) html += '<div style="font-size:.85rem;"><strong>Score:</strong> ' + data.score + '/100</div>';
            if (data.resumo) html += '<div style="font-size:.82rem;margin-top:6px;line-height:1.6;">' + esc(data.resumo) + '</div>';
            if (Array.isArray(data.angulos) && data.angulos.length) {
                html += '<div style="font-size:.78rem;margin-top:6px;"><strong>Ângulos:</strong> ' + data.angulos.map(a => esc(a)).join(' · ') + '</div>';
            }
            return html;
        }

        if (render.type === 'transcricao') {
            return '<div style="font-size:.8rem;max-height:180px;overflow-y:auto;line-height:1.6;white-space:pre-wrap;">' + esc((data.text || '').substring(0, 1500)) + '</div>' +
                '<div style="margin-top:8px;"><a class="btn btn-outline btn-sm" href="/admin/transcribe.php" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> Abrir Transcrições</a></div>';
        }

        if (render.type === 'narracao') {
            return '<audio controls src="/admin/api/tts.php?action=audio&id=' + data.id + '" style="width:100%;"></audio>' +
                '<div style="margin-top:8px;"><a class="btn btn-outline btn-sm" href="/admin/tts.php" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> Abrir Narração</a></div>';
        }

        if (render.type === 'clone') {
            return '<div style="font-size:.82rem;">Página criada: <strong>' + esc(data.name) + '</strong>' + (data.failed_assets ? ' (' + data.failed_assets + ' assets com falha)' : '') + '</div>' +
                '<div style="margin-top:8px;"><a class="btn btn-outline btn-sm" href="/admin/pages.php" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> Ver minhas páginas</a></div>';
        }

        if (render.type === 'paginas' || render.type === 'transcricoes' || render.type === 'narracoes') {
            if (!data.length) return '<div style="font-size:.82rem;color:var(--text-secondary);">Nada por aqui ainda.</div>';
            return data.map(item =>
                '<div style="font-size:.8rem;padding:4px 0;border-bottom:1px solid var(--border-color);">' +
                    esc(item.name || item.url || ('#' + item.id)) + ' <span style="color:var(--text-secondary);">· ' + esc(item.status || '') + '</span>' +
                '</div>'
            ).join('');
        }

        return '';
    }

    async function sendMessage() {
        if (sending) return;
        const input = document.getElementById('agentInput');
        const message = input.value.trim();
        if (!message) return;

        sending = true;
        input.value = '';
        document.getElementById('sendBtn').disabled = true;

        const container = document.getElementById('agentMessages');
        container.innerHTML += '<div class="msg user">' + esc(message) + '</div>';
        container.innerHTML += '<div class="agent-typing" id="typing"><i class="fas fa-spinner fa-spin"></i> Seu sócio está pensando...</div>';
        container.scrollTop = container.scrollHeight;

        try {
            const body = new URLSearchParams({ action: 'send', conversation_id: conversationId, message });
            const resp = await fetch('/admin/api/agent.php', { method: 'POST', body });
            const data = await resp.json();

            if (data.error) { showToast(data.error, 'error'); }
            if (data.conversation_id) conversationId = data.conversation_id;
            if (data.messages) renderMessages(data.messages);
            if (data.quota) updateQuota(data.quota);
            loadConversations();
        } catch (err) {
            showToast('Erro de conexão: ' + err.message, 'error');
        } finally {
            sending = false;
            document.getElementById('sendBtn').disabled = false;
            input.focus();
        }
    }

    function pickOption(option) {
        document.getElementById('agentInput').value = option;
        sendMessage();
    }

    async function confirmTool(id) {
        showToast('Executando ação...', 'info');
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'confirm', id }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        if (data.messages) renderMessages(data.messages);
        if (data.status === 'failed') showToast('A ação falhou — veja o detalhe no cartão', 'warning');
    }

    async function cancelTool(id) {
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'cancel', id }) });
        const data = await resp.json();
        if (data.messages) renderMessages(data.messages);
    }

    async function editProfile() {
        const resp = await fetch('/admin/api/agent.php?action=profile');
        const data = await resp.json();
        const p = data.profile || {};
        const niche = prompt('Nicho de interesse (ex: emagrecimento, financas, relacionamento):', p.niche || '');
        if (niche === null) return;
        const budget = prompt('Orçamento para tráfego (ex: R$50/dia, sem orçamento):', p.budget || '');
        if (budget === null) return;
        const experience = prompt('Sua experiência (iniciante, intermediário, avançado):', p.experience || '');
        if (experience === null) return;

        const body = new URLSearchParams({ action: 'profile', niche, budget, experience });
        const save = await fetch('/admin/api/agent.php', { method: 'POST', body });
        const saved = await save.json();
        if (saved.success) {
            document.getElementById('profileLine').textContent = 'Perfil: ' + [niche, budget, experience].filter(Boolean).join(' | ');
            showToast('Perfil atualizado', 'success');
        }
    }

    (async () => {
        const resp = await fetch('/admin/api/agent.php?action=conversations');
        const data = await resp.json();
        const items = data.conversations || [];
        if (items.length) {
            await openConversation(items[0].id);
        } else {
            await newConversation();
        }
        const input = document.getElementById('agentInput');
        if (input.value.trim() !== '') input.focus();
    })();
    </script>
</body>
</html>
