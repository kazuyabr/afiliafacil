<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Feedback.php';

Auth::requireAuth();

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$theme = \Theme::current();
$stats = $isAdmin ? Feedback::stats() : null;
$dailyUsed = 0;
if (Database::available()) {
    try {
        $dailyUsed = (int)\AfiliaFacil\Models\Feedback::where('user_id', (int)$user['id'])
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
    } catch (Throwable $e) {
    }
}
$typeLabels = Feedback::TYPE_LABELS;
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .fb-item { padding:14px 16px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; gap:14px; align-items:flex-start; }
        .fb-item:last-child { border-bottom:none; }
        .fb-item .meta { font-size:.72rem; color:var(--text-secondary); margin-top:4px; }
        .fb-badge { font-size:.65rem; padding:2px 8px; border-radius:10px; font-weight:700; text-transform:uppercase; }
        .fb-sugestao { background:#e0f2fe; color:#075985; }
        .fb-reclamacao { background:#fee2e2; color:#991b1b; }
        .fb-elogio { background:#dcfce7; color:#166534; }
        .fb-bug { background:#fef3c7; color:#92400e; }
        .fb-reply { background:var(--bg-secondary); border-left:3px solid var(--accent); border-radius:6px; padding:10px 12px; margin-top:8px; font-size:.82rem; }
        .fb-status { font-size:.68rem; padding:2px 8px; border-radius:10px; background:var(--bg-secondary); color:var(--text-secondary); text-transform:uppercase; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Feedback</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Feedback</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Conte o que você está achando, sugira melhorias ou reporte um problema — sua opinião guia o nosso roadmap.</p>
                    </div>
                    <span class="quota-pill"><i class="fas fa-paper-plane"></i> Envios hoje: <strong><?= $dailyUsed ?>/<?= Feedback::DAILY_LIMIT ?></strong></span>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-comment-dots"></i> Enviar feedback</h3></div>
                    <div class="card-body">
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Tipo</label>
                                <select id="fbType" class="form-control">
                                    <?php foreach ($typeLabels as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Página relacionada (opcional)</label>
                                <input type="text" id="fbPage" class="form-control" placeholder="ex: Ofertas Escalando" value="<?= htmlspecialchars($_GET['page'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Mensagem</label>
                            <textarea id="fbMessage" class="form-control" rows="5" placeholder="Descreva sua sugestão, reclamação, elogio ou problema (mínimo 10 caracteres)..."></textarea>
                        </div>
                        <button class="btn btn-primary" id="fbSendBtn" onclick="sendFeedback()"><i class="fas fa-paper-plane"></i> Enviar feedback</button>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-clock-rotate-left"></i> Meus envios</h3></div>
                    <div class="card-body" id="fbHistory" style="padding:0;">
                        <div style="text-align:center;color:var(--text-secondary);padding:24px;">Carregando...</div>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-inbox"></i> Todos os feedbacks <?= $stats && $stats['novos'] > 0 ? '<span class="status status-draft" style="margin-left:8px;">' . $stats['novos'] . ' novo(s)</span>' : '' ?></h3>
                        <div style="display:flex;gap:8px;">
                            <a class="btn btn-outline btn-sm" href="/admin/api/feedback.php?action=export&format=csv"><i class="fas fa-file-csv"></i> CSV</a>
                            <a class="btn btn-outline btn-sm" href="/admin/api/feedback.php?action=export&format=json"><i class="fas fa-file-code"></i> JSON</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                            <select id="fbFilterType" class="form-control" style="width:auto;">
                                <option value="">Todos os tipos</option>
                                <?php foreach ($typeLabels as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="fbFilterStatus" class="form-control" style="width:auto;">
                                <option value="">Todos os status</option>
                                <option value="novo">Novo</option>
                                <option value="lido">Lido</option>
                                <option value="respondido">Respondido</option>
                                <option value="arquivado">Arquivado</option>
                            </select>
                            <button class="btn btn-outline btn-sm" onclick="loadAll()"><i class="fas fa-filter"></i> Filtrar</button>
                        </div>
                    </div>
                    <div class="card-body" id="fbAll" style="padding:0;border-top:1px solid var(--border-color);">
                        <div style="text-align:center;color:var(--text-secondary);padding:24px;">Carregando...</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    async function sendFeedback() {
        const message = document.getElementById('fbMessage').value.trim();
        if (message.length < 10) { showToast('Escreva pelo menos 10 caracteres', 'warning'); return; }

        document.getElementById('fbSendBtn').disabled = true;
        const body = new URLSearchParams({
            action: 'send',
            type: document.getElementById('fbType').value,
            message,
            page: document.getElementById('fbPage').value,
        });

        try {
            const resp = await fetch('/admin/api/feedback.php', { method: 'POST', body });
            const data = await resp.json();
            if (data.error) { showToast(data.error, 'error'); return; }
            showToast('Feedback enviado. Obrigado!', 'success');
            document.getElementById('fbMessage').value = '';
            loadHistory();
        } finally {
            document.getElementById('fbSendBtn').disabled = false;
        }
    }

    function renderItem(f, adminView) {
        let html = '<div class="fb-item">' +
            '<div style="min-width:0;flex:1;">' +
                '<div><span class="fb-badge fb-' + esc(f.type) + '">' + esc(f.type_label) + '</span>' +
                (adminView ? ' <span class="fb-status">' + esc(f.status) + '</span>' : '') +
                (f.context && f.context.page ? ' <span class="meta">' + esc(f.context.page) + '</span>' : '') + '</div>' +
                '<div style="font-size:.85rem;line-height:1.6;margin-top:6px;white-space:pre-wrap;">' + esc(f.message) + '</div>' +
                '<div class="meta">' + esc(f.created_at) + (f.context && f.context.plan ? ' · plano ' + esc(f.context.plan) : '') + (adminView ? ' · usuário #' + f.user_id : '') + '</div>';

        if (f.admin_reply) {
            html += '<div class="fb-reply"><strong><i class="fas fa-reply"></i> Resposta da equipe:</strong><br>' + esc(f.admin_reply) + '</div>';
        }

        html += '</div>';

        if (adminView) {
            html += '<div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">' +
                '<button class="btn btn-outline btn-sm" onclick="openReply(' + f.id + ')"><i class="fas fa-reply"></i></button>' +
                (f.status !== 'arquivado' ? '<button class="btn btn-outline btn-sm" onclick="setStatus(' + f.id + ', \'arquivado\')" title="Arquivar"><i class="fas fa-box-archive"></i></button>' : '') +
            '</div>';
        }

        return html + '</div>';
    }

    async function loadHistory() {
        const resp = await fetch('/admin/api/feedback.php?action=list');
        const data = await resp.json();
        const container = document.getElementById('fbHistory');
        const items = data.items || [];
        if (!items.length) {
            container.innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Você ainda não enviou feedback.</div>';
            return;
        }
        container.innerHTML = items.map(f => renderItem(f, false)).join('');
    }

    async function loadAll() {
        const params = new URLSearchParams({ action: 'list', all: '1' });
        const type = document.getElementById('fbFilterType').value;
        const status = document.getElementById('fbFilterStatus').value;
        if (type) params.set('type', type);
        if (status) params.set('status', status);

        const resp = await fetch('/admin/api/feedback.php?' + params.toString());
        const data = await resp.json();
        const container = document.getElementById('fbAll');
        const items = data.items || [];
        if (!items.length) {
            container.innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhum feedback com esses filtros.</div>';
            return;
        }
        container.innerHTML = items.map(f => renderItem(f, true)).join('');
    }

    async function openReply(id) {
        const reply = prompt('Resposta para o cliente:');
        if (!reply) return;
        const resp = await fetch('/admin/api/feedback.php', { method: 'POST', body: new URLSearchParams({ action: 'reply', id, reply }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Resposta enviada', 'success');
        loadAll();
        loadHistory();
    }

    async function setStatus(id, status) {
        const resp = await fetch('/admin/api/feedback.php', { method: 'POST', body: new URLSearchParams({ action: 'status', id, status }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Feedback atualizado', 'success');
        loadAll();
    }

    loadHistory();
    if (IS_ADMIN) loadAll();
    </script>
</body>
</html>
