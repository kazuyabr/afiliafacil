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
    <title>Sócio de IA - AfiliaFacil</title>
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
        .status-processing { background:#dbeafe; color:#1e40af; }
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
        .subagent-tools { display:flex; flex-wrap:wrap; gap:8px; font-size:.78rem; }
        .subagent-tools label { display:flex; align-items:center; gap:5px; background:var(--bg-secondary); padding:5px 10px; border-radius:14px; cursor:pointer; }
        @media (max-width: 900px) { .agent-conversations { display:none; } }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Sócio de IA</div>
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
                        <div class="header" style="border-top:1px solid var(--border-color);border-bottom:none;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <strong style="font-size:.8rem;"><i class="fas fa-users-gear" style="color:var(--accent);"></i> Subagentes</strong>
                            <button class="btn btn-outline btn-sm" onclick="editSubagent(0)" id="newSubagentBtn" title="Novo subagente"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="list" id="subagentList" style="max-height:40%;"></div>
                    </aside>
                    <div class="agent-chat">
                        <div class="agent-chat-header">
                            <div>
                                <strong style="font-size:.95rem;" id="agentHeaderTitle"><i class="fas fa-handshake" style="color:var(--accent);"></i> Sócio de IA</strong>
                                <div style="font-size:.72rem;color:var(--text-secondary);margin-top:2px;" id="profileLine">Perfil: <?= htmlspecialchars($profileText) ?></div>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <button class="btn btn-outline btn-sm" id="backToMainBtn" style="display:none;" onclick="backToMain()"><i class="fas fa-arrow-left"></i> Voltar ao Sócio de IA</button>
                                <span class="quota-pill" id="quotaPill"><i class="fas fa-comments"></i> <strong><?= $quota['source'] === 'byok' ? 'BYOK — sem limite' : ($quota['limit'] === -1 ? 'ilimitado' : $quota['used'] . '/' . $quota['limit']) ?></strong></span>
                                <button class="btn btn-outline btn-sm" onclick="editProfile()" title="Editar perfil"><i class="fas fa-user-pen"></i></button>
                            </div>
                        </div>
                        <div class="principles"><i class="fas fa-shield-halved"></i> Sem promessa de ganho fácil. Comece pequeno e teste. Eu pergunto antes de agir — e te aviso quando algo parece furada. <strong>Uso ilegal é bloqueado e registrado.</strong></div>
                        <div id="pendingBanner" style="display:none;background:var(--warning);color:#1a1a2e;padding:10px 16px;font-size:.82rem;font-weight:600;">
                            <i class="fas fa-triangle-exclamation"></i> O Sócio aguarda sua confirmação — confira o cartão de ação abaixo.
                        </div>
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

    <div class="modal-overlay" id="subagentModal">
        <div class="modal" style="max-width:640px;">
            <div class="modal-header">
                <h3 id="subagentModalTitle">Novo subagente</h3>
                <button class="modal-close" onclick="closeSubagentModal()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="subagentId" value="0">
                <div class="form-group">
                    <label>Modelo rápido (opcional)</label>
                    <select id="subagentTemplate" class="form-control" onchange="applyTemplate(this.value)">
                        <option value="">Começar do zero</option>
                    </select>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Nome do especialista</label>
                        <input type="text" id="subagentName" class="form-control" placeholder="ex: Analista de Meta Ads">
                    </div>
                    <div class="form-group">
                        <label>Especialidade</label>
                        <input type="text" id="subagentSpecialty" class="form-control" placeholder="ex: Tráfego pago no Facebook">
                    </div>
                </div>
                <div class="form-group">
                    <label>Instruções (como ele deve trabalhar)</label>
                    <textarea id="subagentInstructions" class="form-control" rows="4" placeholder="Descreva o foco e o jeito de trabalhar deste especialista..."></textarea>
                </div>
                <div class="form-group">
                    <label>Ferramentas permitidas</label>
                    <div class="subagent-tools" id="subagentTools"></div>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;">
                        <input type="checkbox" id="subagentActive" checked> Ativo (pode ser consultado)
                    </label>
                </div>
                <div id="subagentError"></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="btn btn-outline" onclick="closeSubagentModal()">Cancelar</button>
                    <button class="btn btn-primary" onclick="saveSubagent()"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let conversationId = 0;
    let sending = false;
    let conversationsCache = [];
    let subagentsCache = [];
    let templatesCache = [];
    let availableToolsCache = [];
    let subagentQuota = null;

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function handleKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    }

    function updateQuota(quota) {
        if (!quota) return;
        const label = quota.source === 'byok' ? 'BYOK — sem limite' : (quota.limit === -1 ? 'ilimitado' : quota.used + '/' + quota.limit);
        document.getElementById('quotaPill').innerHTML = '<i class="fas fa-comments"></i> <strong>' + label + '</strong>';
    }

    function formatDate(s) {
        if (!s) return '';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(s).substring(0, 16);
        return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' ' +
            d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    async function loadConversations() {
        const resp = await fetch('/admin/api/agent.php?action=conversations');
        const data = await resp.json();
        conversationsCache = data.conversations || [];
        const list = document.getElementById('convList');
        if (!conversationsCache.length) {
            list.innerHTML = '<div style="padding:16px;font-size:.78rem;color:var(--text-secondary);text-align:center;">Nenhuma conversa ainda.</div>';
            return;
        }
        list.innerHTML = conversationsCache.map(c =>
            '<div class="agent-conv-item ' + (c.id === conversationId ? 'active' : '') + '" onclick="openConversation(' + c.id + ')">' +
                '<span class="title" style="display:flex;flex-direction:column;gap:2px;min-width:0;">' +
                    '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (c.subagent_name ? '<i class="fas fa-user-gear" style="font-size:.68rem;color:var(--accent);"></i> ' : '') + esc(c.title) + '</span>' +
                    '<span style="font-size:.64rem;color:var(--text-secondary);">' + esc(formatDate(c.updated_at)) + '</span>' +
                '</span>' +
                '<span class="del" onclick="event.stopPropagation();deleteConversation(' + c.id + ')" title="Excluir"><i class="fas fa-trash"></i></span>' +
            '</div>'
        ).join('');
    }

    async function loadSubagents() {
        const resp = await fetch('/admin/api/agent.php?action=subagents');
        const data = await resp.json();
        if (!data.success) return;

        subagentsCache = data.subagents || [];
        templatesCache = data.templates || [];
        availableToolsCache = data.available_tools || [];
        subagentQuota = data.quota;

        const tpl = document.getElementById('subagentTemplate');
        if (tpl.options.length <= 1 && templatesCache.length) {
            templatesCache.forEach(t => tpl.appendChild(new Option(t.name + ' — ' + t.specialty, t.slug)));
        }

        const btn = document.getElementById('newSubagentBtn');
        if (subagentQuota && subagentQuota.limit !== -1 && !subagentQuota.allowed) {
            btn.disabled = true;
            btn.title = 'Limite de subagentes do plano atingido (' + subagentQuota.used + '/' + subagentQuota.limit + ')';
        }

        const list = document.getElementById('subagentList');
        if (!subagentsCache.length) {
            list.innerHTML = '<div style="padding:12px;font-size:.75rem;color:var(--text-secondary);text-align:center;">Nenhum subagente ainda.<br>Peça ao Sócio de IA para criar um ou clique em +.</div>';
            return;
        }
        list.innerHTML = subagentsCache.map(s =>
            '<div class="agent-conv-item" style="opacity:' + (s.active ? '1' : '.5') + ';">' +
                '<span class="title" title="' + esc(s.specialty) + '" onclick="openSubagentChat(' + s.id + ')"><i class="fas fa-user-gear" style="color:var(--accent);font-size:.72rem;"></i> ' + esc(s.name) + '</span>' +
                '<span style="display:flex;gap:7px;flex-shrink:0;">' +
                    '<span class="del" onclick="editSubagent(' + s.id + ')" title="Editar"><i class="fas fa-pen"></i></span>' +
                    '<span class="del" onclick="toggleSubagent(' + s.id + ')" title="' + (s.active ? 'Desativar' : 'Ativar') + '"><i class="fas fa-' + (s.active ? 'pause' : 'play') + '"></i></span>' +
                    '<span class="del" onclick="deleteSubagent(' + s.id + ')" title="Excluir"><i class="fas fa-trash"></i></span>' +
                '</span>' +
            '</div>'
        ).join('');
    }

    async function openSubagentChat(id) {
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'new', subagent_id: id }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        conversationId = data.conversation_id;
        await openConversation(conversationId);
        loadConversations();
        document.getElementById('agentInput').focus();
    }

    function backToMain() {
        const main = conversationsCache.find(c => !c.subagent_id);
        if (main) {
            openConversation(main.id);
        } else {
            newConversation();
        }
    }

    function updateChatHeader(conversation) {
        const conv = conversation || conversationsCache.find(c => c.id === conversationId);
        const sub = conv && conv.subagent_id
            ? (subagentsCache.find(s => s.id === conv.subagent_id) || { name: conv.subagent_name || 'Subagente', specialty: '' })
            : null;
        const title = document.getElementById('agentHeaderTitle');
        const backBtn = document.getElementById('backToMainBtn');

        if (sub) {
            title.innerHTML = '<i class="fas fa-user-gear" style="color:var(--accent);"></i> ' + esc(sub.name) +
                ' <span style="font-size:.7rem;color:var(--text-secondary);font-weight:400;">(' + esc(sub.specialty || 'especialista') + ')</span>';
            backBtn.style.display = 'inline-flex';
        } else {
            title.innerHTML = '<i class="fas fa-handshake" style="color:var(--accent);"></i> Sócio de IA';
            backBtn.style.display = 'none';
        }
    }

    function editSubagent(id) {
        const modal = document.getElementById('subagentModal');
        const sub = id > 0 ? subagentsCache.find(s => s.id === id) : null;

        document.getElementById('subagentId').value = id;
        document.getElementById('subagentModalTitle').textContent = sub ? 'Editar: ' + sub.name : 'Novo subagente';
        document.getElementById('subagentName').value = sub ? sub.name : '';
        document.getElementById('subagentSpecialty').value = sub ? sub.specialty : '';
        document.getElementById('subagentInstructions').value = sub ? (sub.instructions || '') : '';
        document.getElementById('subagentActive').checked = sub ? sub.active : true;
        document.getElementById('subagentTemplate').value = '';
        document.getElementById('subagentError').innerHTML = '';

        const toolsBox = document.getElementById('subagentTools');
        const selected = sub ? (sub.tools || []) : [];
        toolsBox.innerHTML = availableToolsCache.map(t =>
            '<label><input type="checkbox" class="subagent-tool" value="' + esc(t) + '"' + (selected.includes(t) ? ' checked' : '') + '> ' + esc(t) + '</label>'
        ).join('');

        modal.classList.add('active');
    }

    function closeSubagentModal() {
        document.getElementById('subagentModal').classList.remove('active');
    }

    function applyTemplate(slug) {
        if (!slug) return;
        const tpl = templatesCache.find(t => t.slug === slug);
        if (!tpl) return;

        document.getElementById('subagentName').value = tpl.name;
        document.getElementById('subagentSpecialty').value = tpl.specialty;
        document.getElementById('subagentInstructions').value = tpl.instructions;
        document.querySelectorAll('.subagent-tool').forEach(c => { c.checked = (tpl.tools || []).includes(c.value); });
    }

    async function saveSubagent() {
        const id = parseInt(document.getElementById('subagentId').value || '0', 10);
        const name = document.getElementById('subagentName').value.trim();
        if (name.length < 3) { document.getElementById('subagentError').innerHTML = '<div class="alert alert-danger">Informe um nome com ao menos 3 caracteres.</div>'; return; }

        const body = new URLSearchParams();
        body.append('action', 'subagent-save');
        body.append('id', String(id));
        body.append('name', name);
        body.append('specialty', document.getElementById('subagentSpecialty').value);
        body.append('instructions', document.getElementById('subagentInstructions').value);
        body.append('active', document.getElementById('subagentActive').checked ? '1' : '0');
        document.querySelectorAll('.subagent-tool:checked').forEach(c => body.append('tools[]', c.value));

        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.error) { document.getElementById('subagentError').innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>'; return; }

        closeSubagentModal();
        showToast(id > 0 ? 'Subagente atualizado' : 'Subagente criado', 'success');
        loadSubagents();
    }

    async function toggleSubagent(id) {
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'subagent-toggle', id }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        loadSubagents();
    }

    async function deleteSubagent(id) {
        const sub = subagentsCache.find(s => s.id === id);
        if (!confirm('Excluir o subagente "' + (sub ? sub.name : id) + '"?')) return;
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'subagent-delete', id }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Subagente excluído', 'success');
        loadSubagents();
    }

    async function openConversation(id) {
        conversationId = id;
        const resp = await fetch('/admin/api/agent.php?action=conversation&id=' + id);
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        renderMessages(data.messages || []);
        await loadConversations();
        updateChatHeader(data.conversation || null);
        markSeen(id);

        // Se houver job pendente/processando, retoma o acompanhamento
        try {
            const st = await fetch('/admin/api/agent.php?action=job-status&conversation_id=' + id).then(r => r.json());
            const job = st && st.job;
            if (job && (job.status === 'pending' || job.status === 'processing')) {
                showTyping();
                startPolling();
            } else {
                stopPolling();
            }
        } catch (e) {}
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

        const hasPending = messages.some(m => m.role === 'tool' && m.status === 'pending_confirmation');
        const banner = document.getElementById('pendingBanner');
        if (banner) banner.style.display = hasPending ? 'block' : 'none';

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
        html += '<div style="align-self:flex-start;display:flex;gap:10px;align-items:center;font-size:.72rem;color:var(--text-secondary);padding:0 4px;">' +
            '<span>Útil?</span>' +
            '<a href="#" onclick="rateMessage(' + m.id + ',1);return false;" title="Boa resposta" style="' + (m.rating === 1 ? 'color:var(--success);font-weight:700;' : 'color:var(--text-secondary);') + '"><i class="fas fa-thumbs-up"></i></a>' +
            '<a href="#" onclick="rateMessage(' + m.id + ',-1);return false;" title="Resposta ruim" style="' + (m.rating === -1 ? 'color:var(--danger);font-weight:700;' : 'color:var(--text-secondary);') + '"><i class="fas fa-thumbs-down"></i></a>' +
            (m.rating_note ? '<span title="Sua observação">· ' + esc(m.rating_note) + '</span>' : '') +
        '</div>';
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
                '<span class="status-badge status-' + esc(status) + '">' + (status === 'pending_confirmation' ? 'aguardando você' : status === 'executed' ? 'executada' : status === 'processing' ? 'executando...' : status === 'cancelled' ? 'cancelada' : 'falhou') + '</span>' +
            '</div>' +
            (m.content ? '<div class="tool-body">' + esc(m.content) + '</div>' : '') +
            (argsText ? '<div class="tool-body" style="font-family:monospace;font-size:.75rem;">' + esc(argsText) + '</div>' : '');

        if (status === 'pending_confirmation') {
            html += '<div class="tool-actions" data-tool-actions="' + m.id + '">' +
                '<button class="btn btn-primary btn-sm" onclick="confirmTool(' + m.id + ')"><i class="fas fa-check"></i> Confirmar</button>' +
                '<button class="btn btn-outline btn-sm" onclick="cancelTool(' + m.id + ')"><i class="fas fa-xmark"></i> Cancelar</button>' +
            '</div>';
        }

        if (status === 'processing') {
            html += '<div class="tool-actions"><span style="font-size:.8rem;color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Executando a ação... você pode navegar, eu aviso quando terminar.</span></div>';
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
                    const label = { paginas: 'Páginas', dominios: 'Domínios', adspy: 'Buscas', ia: 'Análises IA', ofertas: 'Ofertas', transcricoes: 'Transcrições', narracoes: 'Narrações', agente: 'Sócio de IA' }[k] || k;
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

        if (render.type === 'subagente') {
            return '<div style="font-size:.85rem;"><i class="fas fa-user-gear" style="color:var(--accent);"></i> <strong>' + esc(data.name) + '</strong> — ' + esc(data.specialty || 'especialista') + '</div>' +
                '<div style="margin-top:8px;"><button class="btn btn-outline btn-sm" onclick="openSubagentChat(' + data.id + ')"><i class="fas fa-comments"></i> Conversar com ele</button></div>';
        }

        if (render.type === 'subagente_resposta') {
            return '<div style="font-size:.8rem;font-weight:600;margin-bottom:6px;"><i class="fas fa-user-gear" style="color:var(--accent);"></i> ' + esc(data.name) + ' respondeu:</div>' +
                '<div style="font-size:.82rem;line-height:1.7;white-space:pre-wrap;">' + esc(data.text) + '</div>';
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

    let pollingTimer = null;

    function showTyping() {
        const container = document.getElementById('agentMessages');
        if (document.getElementById('typing')) return;
        container.innerHTML += '<div class="agent-typing" id="typing"><i class="fas fa-spinner fa-spin"></i> Sócio está pensando... você pode navegar — será avisado quando responder.</div>';
        container.scrollTop = container.scrollHeight;
    }

    function startPolling() {
        if (pollingTimer) return;
        pollingTimer = setInterval(pollJob, 4000);
        pollJob();
    }

    function stopPolling() {
        if (pollingTimer) { clearInterval(pollingTimer); pollingTimer = null; }
    }

    async function pollJob() {
        if (!conversationId) { stopPolling(); return; }
        try {
            const resp = await fetch('/admin/api/agent.php?action=job-status&conversation_id=' + conversationId);
            const data = await resp.json();
            if (data.error) { stopPolling(); return; }

            const job = data.job;
            if (!job) { stopPolling(); renderMessages(data.messages || []); return; }

            if (job.status === 'done' || job.status === 'failed') {
                stopPolling();
                renderMessages(data.messages || []);
                if (job.status === 'failed') showToast('A IA falhou ao responder. Tente novamente.', 'error');
            }
        } catch (e) {}
    }

    function markSeen(id) {
        fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'mark-seen', conversation_id: id }) }).catch(function () {});
    }

    async function rateMessage(id, rating) {
        let note = '';
        if (rating === -1) note = prompt('O que ficou ruim nesta resposta? (opcional)') || '';
        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'rate', message_id: id, rating, note }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Obrigado pela avaliação!', 'success');
        openConversation(conversationId);
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
        container.scrollTop = container.scrollHeight;

        try {
            const body = new URLSearchParams({ action: 'send', conversation_id: conversationId, message });
            const resp = await fetch('/admin/api/agent.php', { method: 'POST', body });
            const data = await resp.json();

            if (data.error) { showToast(data.error, 'error'); return; }
            if (data.conversation_id) conversationId = data.conversation_id;
            if (data.messages) renderMessages(data.messages);
            if (data.quota) updateQuota(data.quota);
            loadConversations();

            if (data.queued && data.job_id) {
                showTyping();
                fetch('/admin/api/agent.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'process', job_id: data.job_id })
                }).catch(function () {});
                startPolling();
            }
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
        // Feedback imediato no card (botões desabilitados + spinner)
        const actions = document.querySelector('[data-tool-actions="' + id + '"]');
        if (actions) {
            actions.querySelectorAll('button').forEach(b => { b.disabled = true; });
            const first = actions.querySelector('button');
            if (first) first.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Executando...';
        }

        const resp = await fetch('/admin/api/agent.php', { method: 'POST', body: new URLSearchParams({ action: 'confirm', id }) });
        const data = await resp.json();

        if (data.error) {
            showToast(data.error, 'error');
            openConversation(conversationId);
            return;
        }

        if (data.messages) renderMessages(data.messages);

        if (data.queued && data.job_id) {
            showToast('Ação em execução — você pode navegar, eu aviso quando terminar.', 'info');
            // Dispara o processamento em background (fire-and-forget)
            fetch('/admin/api/agent.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'process', job_id: data.job_id })
            }).catch(function () {});
            showTyping();
            startPolling();
        }
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
        await loadSubagents();

        const urlConv = parseInt(new URLSearchParams(location.search).get('conv') || '0', 10);
        if (urlConv > 0) {
            await openConversation(urlConv);
            const input = document.getElementById('agentInput');
            if (input.value.trim() !== '') input.focus();
            return;
        }

        const resp = await fetch('/admin/api/agent.php?action=conversations');
        const data = await resp.json();
        conversationsCache = data.conversations || [];
        const items = conversationsCache;
        if (items.length) {
            await openConversation(items[0].id);
        } else {
            await newConversation();
        }
        loadConversations();
        const input = document.getElementById('agentInput');
        if (input.value.trim() !== '') input.focus();
    })();
    </script>
</body>
</html>
