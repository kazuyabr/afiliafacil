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
                    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-comments"></i></div><div class="stat-value"><?= $stats['conversations'] ?></div><div class="stat-label">Conversas</div></div>
                    <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-robot"></i></div><div class="stat-value"><?= $stats['agent_messages'] ?></div><div class="stat-label">Respostas da IA</div></div>
                    <div class="stat-card"><div class="stat-icon green"><i class="fas fa-thumbs-up"></i></div><div class="stat-value"><?= $stats['up'] ?></div><div class="stat-label">Avaliações boas</div></div>
                    <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-thumbs-down"></i></div><div class="stat-value"><?= $stats['down'] ?></div><div class="stat-label">Avaliações ruins</div></div>
                    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-percent"></i></div><div class="stat-value"><?= $stats['rated_pct'] ?>%</div><div class="stat-label">Respostas avaliadas</div></div>
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

    async function openConversation(id) {
        const modal = document.getElementById('monModal');
        modal.classList.add('active');
        document.getElementById('monModalBody').innerHTML = '<div style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i></div>';

        const resp = await fetch('/admin/api/agent-monitor.php?action=conversation&id=' + id);
        const data = await resp.json();
        if (data.error) { document.getElementById('monModalBody').innerHTML = '<div class="alert alert-warning">' + esc(data.error) + '</div>'; return; }

        const c = data.conversation;
        document.getElementById('monModalTitle').textContent = c.title + (c.subagent_name ? ' — ' + c.subagent_name : '');

        let html = '<p style="font-size:.78rem;color:var(--text-secondary);margin-bottom:12px;">Usuário: ' + esc(c.user_email) + ' · atualizada em ' + esc(c.updated_at) + '</p>';
        html += c.messages.map(m => {
            const who = m.role === 'user' ? 'Usuário' : (m.role === 'tool' ? 'Ação (' + esc(m.tool_name) + ')' : 'IA');
            const rating = m.rating === 1 ? ' <span class="rating-up">👍</span>' : (m.rating === -1 ? ' <span class="rating-down">👎 ' + esc(m.rating_note || '') + '</span>' : '');
            const extra = m.tool_result_summary ? '<div style="font-size:.75rem;color:var(--text-secondary);margin-top:4px;">' + esc(String(m.tool_result_summary).substring(0, 300)) + '</div>' : '';
            return '<div class="msg-line"><span class="who">' + who + rating + '</span><div class="txt">' + esc(m.content || '') + '</div>' + extra + '</div>';
        }).join('');

        document.getElementById('monModalBody').innerHTML = html;
    }

    loadMonitor();
    </script>
</body>
</html>
