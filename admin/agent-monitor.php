<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Agent/AgentMonitor.php';
require_once Config::getLibDir() . '/Theme.php';

Auth::requireAuth();

if (!Auth::can('manage_ai') && !Auth::isAdmin()) {
    header('Location: /admin/');
    exit;
}

$theme = \Theme::current();
$stats = AgentMonitor::stats();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor da IA - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .mon-table { width:100%; border-collapse:collapse; font-size:.82rem; }
        .mon-table th { text-align:left; padding:8px 10px; border-bottom:1px solid var(--border-color); color:var(--text-secondary); font-size:.7rem; text-transform:uppercase; }
        .mon-table td { padding:8px 10px; border-bottom:1px solid var(--border-color); vertical-align:top; }
        .msg-line { padding:8px 0; border-bottom:1px dashed var(--border-color); }
        .msg-line .who { font-size:.7rem; font-weight:700; text-transform:uppercase; color:var(--text-secondary); }
        .msg-line .txt { font-size:.83rem; line-height:1.6; white-space:pre-wrap; margin-top:3px; }
        .rating-up { color:var(--success); font-weight:700; }
        .rating-down { color:var(--danger); font-weight:700; }
        .stat-card.mon-click { cursor:pointer; transition:transform .12s ease, box-shadow .12s ease; }
        .stat-card.mon-click:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,.12); }
        .mon-row { display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px dashed var(--border-color); }
        .mon-row:last-child { border-bottom:none; }
        .mon-back { display:inline-block;margin-bottom:10px;font-size:.78rem; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Monitor da IA</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Monitor da IA</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Acompanhe a precisão do Sócio de IA e dos subagentes — avaliações, diálogos e export para treinamento.</p>
                    </div>
                    <a class="btn btn-primary btn-sm" href="/admin/api/agent-monitor.php?action=export"><i class="fas fa-file-export"></i> Exportar dataset (JSONL)</a>
                </div>

                <div class="stats-grid" style="margin-bottom:20px;">
                    <div class="stat-card mon-click" onclick="openCard('conversations')" title="Ver conversas recentes"><div class="stat-icon blue"><i class="fas fa-comments"></i></div><div class="stat-value"><?= $stats['conversations'] ?></div><div class="stat-label">Conversas</div></div>
                    <div class="stat-card mon-click" onclick="openCard('responses')" title="Ver respostas recentes da IA"><div class="stat-icon purple"><i class="fas fa-robot"></i></div><div class="stat-value"><?= $stats['agent_messages'] ?></div><div class="stat-label">Respostas da IA</div></div>
                    <div class="stat-card mon-click" onclick="openCard('up')" title="Ver respostas avaliadas como boas"><div class="stat-icon green"><i class="fas fa-thumbs-up"></i></div><div class="stat-value"><?= $stats['up'] ?></div><div class="stat-label">Avaliações boas</div></div>
                    <div class="stat-card mon-click" onclick="openCard('down')" title="Ver respostas avaliadas como ruins"><div class="stat-icon orange"><i class="fas fa-thumbs-down"></i></div><div class="stat-value"><?= $stats['down'] ?></div><div class="stat-label">Avaliações ruins</div></div>
                    <div class="stat-card mon-click" onclick="openCard('none')" title="Ver respostas ainda sem avaliação"><div class="stat-icon blue"><i class="fas fa-percent"></i></div><div class="stat-value"><?= $stats['rated_pct'] ?>%</div><div class="stat-label">Respostas avaliadas</div></div>
                </div>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                            <div class="form-group" style="margin:0;">
                                <label>Tipo</label>
                                <select id="monKind" class="form-control">
                                    <option value="">Todos</option>
                                    <option value="agent">Sócio de IA</option>
                                    <option value="subagent">Subagentes</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Avaliação</label>
                                <select id="monRating" class="form-control">
                                    <option value="">Todas</option>
                                    <option value="up">Com 👍</option>
                                    <option value="down">Com 👎</option>
                                    <option value="none">Sem avaliação</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Período</label>
                                <select id="monDays" class="form-control">
                                    <option value="7">7 dias</option>
                                    <option value="30" selected>30 dias</option>
                                    <option value="90">90 dias</option>
                                    <option value="0">Tudo</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                                <label>Buscar</label>
                                <input type="text" id="monQ" class="form-control" placeholder="título da conversa ou e-mail do usuário...">
                            </div>
                            <button class="btn btn-primary" onclick="loadMonitor()"><i class="fas fa-filter"></i> Filtrar</button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-list"></i> Conversas</h3></div>
                    <div class="card-body" style="overflow-x:auto;" id="monList">
                        <div style="text-align:center;color:var(--text-secondary);padding:24px;">Carregando...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="monModal">
        <div class="modal" style="max-width:860px;width:94%;">
            <div class="modal-header">
                <h3 id="monModalTitle">Conversa</h3>
                <button class="modal-close" onclick="document.getElementById('monModal').classList.remove('active')"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="monModalBody"></div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    async function loadMonitor() {
        const params = new URLSearchParams({ action: 'list' });
        const kind = document.getElementById('monKind').value;
        const rating = document.getElementById('monRating').value;
        const days = document.getElementById('monDays').value;
        const q = document.getElementById('monQ').value.trim();
        if (kind) params.set('kind', kind);
        if (rating) params.set('rating', rating);
        if (days) params.set('days', days);
        if (q) params.set('q', q);

        const resp = await fetch('/admin/api/agent-monitor.php?' + params.toString());
        const data = await resp.json();
        const container = document.getElementById('monList');
        const items = data.items || [];

        if (!items.length) {
            container.innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhuma conversa com esses filtros.</div>';
            return;
        }

        container.innerHTML = '<table class="mon-table"><thead><tr>' +
            '<th>Conversa</th><th>Usuário</th><th>Tipo</th><th>Msgs</th><th>Avaliações</th><th>Atualizada</th><th></th>' +
            '</tr></thead><tbody>' +
            items.map(c =>
                '<tr>' +
                    '<td>' + esc(c.title) + '</td>' +
                    '<td>' + esc(c.user_email) + '</td>' +
                    '<td>' + (c.subagent_name ? '<i class="fas fa-user-gear"></i> ' + esc(c.subagent_name) : 'Sócio de IA') + '</td>' +
                    '<td>' + c.messages + '</td>' +
                    '<td>' + (c.up ? '<span class="rating-up">👍 ' + c.up + '</span> ' : '') + (c.down ? '<span class="rating-down">👎 ' + c.down + '</span>' : '') + (!c.up && !c.down ? '—' : '') + '</td>' +
                    '<td>' + esc(c.updated_at) + '</td>' +
                    '<td><button class="btn btn-outline btn-sm" onclick="openConversation(' + c.id + ')"><i class="fas fa-eye"></i></button></td>' +
                '</tr>'
            ).join('') + '</tbody></table>';
    }

    let monBack = '';

    async function openConversation(id, back) {
        if (back !== undefined) monBack = back;
        const modal = document.getElementById('monModal');
        modal.classList.add('active');
        document.getElementById('monModalBody').innerHTML = '<div style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i></div>';

        const resp = await fetch('/admin/api/agent-monitor.php?action=conversation&id=' + id);
        const data = await resp.json();
        if (data.error) { document.getElementById('monModalBody').innerHTML = '<div class="alert alert-warning">' + esc(data.error) + '</div>'; return; }

        const c = data.conversation;
        document.getElementById('monModalTitle').textContent = c.title + (c.subagent_name ? ' — ' + c.subagent_name : '');

        let html = (monBack ? '<a href="#" class="mon-back" onclick="openCard(\'' + monBack + '\');return false;"><i class="fas fa-arrow-left"></i> Voltar</a>' : '') +
            '<p style="font-size:.78rem;color:var(--text-secondary);margin-bottom:12px;">Usuário: ' + esc(c.user_email) + ' · atualizada em ' + esc(c.updated_at) + '</p>';
        html += c.messages.map(m => {
            const who = m.role === 'user' ? 'Usuário' : (m.role === 'tool' ? 'Ação (' + esc(m.tool_name) + ')' : 'IA');
            const rating = m.rating === 1 ? ' <span class="rating-up">👍</span>' : (m.rating === -1 ? ' <span class="rating-down">👎 ' + esc(m.rating_note || '') + '</span>' : '');
            const extra = m.tool_result_summary ? '<div style="font-size:.75rem;color:var(--text-secondary);margin-top:4px;">' + esc(String(m.tool_result_summary).substring(0, 300)) + '</div>' : '';
            return '<div class="msg-line"><span class="who">' + who + rating + '</span><div class="txt">' + esc(m.content || '') + '</div>' + extra + '</div>';
        }).join('');

        document.getElementById('monModalBody').innerHTML = html;
    }

    const MON_CARD_TITLES = {
        conversations: 'Conversas recentes',
        responses: 'Respostas recentes da IA',
        up: 'Avaliadas como boas (👍)',
        down: 'Avaliadas como ruins (👎)',
        none: 'Sem avaliação ainda'
    };

    function monMsgHtml(m) {
        const rating = m.rating === 1 ? ' <span class="rating-up">👍</span>' : (m.rating === -1 ? ' <span class="rating-down">👎 ' + esc(m.rating_note || '') + '</span>' : ' <span style="font-size:.7rem;color:var(--text-secondary);">sem avaliação</span>');
        return '<div class="msg-line"><span class="who">' + esc(m.conv_title || ('Conversa #' + m.conversation_id)) + ' · ' + esc(m.user_email) + rating + '</span>' +
            '<div class="txt">' + esc((m.content || '').substring(0, 600)) + '</div>' +
            '<div style="margin-top:6px;display:flex;gap:8px;align-items:center;font-size:.72rem;color:var(--text-secondary);">' +
                '<span>' + esc(m.created_at || '') + '</span>' +
                '<button class="btn btn-outline btn-sm" onclick="openConversation(' + m.conversation_id + ')"><i class="fas fa-eye"></i> Ver diálogo</button>' +
            '</div></div>';
    }

    async function openCard(kind) {
        monBack = kind;
        const modal = document.getElementById('monModal');
        modal.classList.add('active');
        document.getElementById('monModalTitle').textContent = MON_CARD_TITLES[kind] || 'Detalhe';
        document.getElementById('monModalBody').innerHTML = '<div style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i></div>';

        try {
            if (kind === 'conversations') {
                const resp = await fetch('/admin/api/agent-monitor.php?action=list&limit=15');
                const data = await resp.json();
                const items = data.items || [];
                if (!items.length) {
                    document.getElementById('monModalBody').innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhuma conversa ainda.</div>';
                    return;
                }
                document.getElementById('monModalBody').innerHTML = items.map(c =>
                    '<div class="mon-row"><div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(c.title) + '</div>' +
                        '<div style="font-size:.72rem;color:var(--text-secondary);">' + esc(c.user_email) + ' · ' + c.messages + ' msgs' +
                        (c.up ? ' · <span class="rating-up">👍 ' + c.up + '</span>' : '') + (c.down ? ' · <span class="rating-down">👎 ' + c.down + '</span>' : '') + '</div>' +
                    '</div><button class="btn btn-outline btn-sm" onclick="openConversation(' + c.id + ')"><i class="fas fa-eye"></i></button></div>'
                ).join('');
                return;
            }

            const rating = kind === 'responses' ? '' : kind;
            const resp = await fetch('/admin/api/agent-monitor.php?action=messages&rating=' + encodeURIComponent(rating) + '&limit=20');
            const data = await resp.json();
            const items = data.items || [];
            const exportBtn = (kind === 'up' || kind === 'down' || kind === 'none')
                ? '<div style="margin-bottom:10px;"><a class="btn btn-outline btn-sm" href="/admin/api/agent-monitor.php?action=export"><i class="fas fa-file-export"></i> Exportar dataset (JSONL)</a></div>'
                : '';
            if (!items.length) {
                document.getElementById('monModalBody').innerHTML = exportBtn + '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhuma resposta aqui ainda.</div>';
                return;
            }
            document.getElementById('monModalBody').innerHTML = exportBtn + items.map(monMsgHtml).join('');
        } catch (e) {
            document.getElementById('monModalBody').innerHTML = '<div class="alert alert-warning">Falha ao carregar: ' + esc(e.message) + '</div>';
        }
    }

    loadMonitor();
    </script>
</body>
</html>
