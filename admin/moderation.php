<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';

Auth::requireAuth();

if (!Auth::isAdmin()) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$export = $_GET['export'] ?? '';
$filterCategory = trim($_GET['category'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterUser = trim($_GET['user'] ?? '');

$events = [];
$stats = ['total' => 0, 'blocked' => 0, 'redacted' => 0, 'crime' => 0, 'pii' => 0, 'profanity' => 0];

if (Database::available()) {
    try {
        $query = \AfiliaFacil\Models\ModerationEvent::query();
        if ($filterCategory !== '') $query->where('category', $filterCategory);
        if ($filterAction !== '') $query->where('action', $filterAction);
        if ($filterUser !== '') $query->where('user_id', (int)$filterUser);

        if ($export !== '') {
            $rows = (clone $query)->orderByDesc('id')->limit(5000)->get();
            $payload = $rows->map(fn($e) => [
                'id' => (int)$e->id,
                'user_id' => (int)$e->user_id,
                'context' => $e->context,
                'category' => $e->category,
                'action' => $e->action,
                'reason' => $e->reason,
                'content' => $e->content,
                'clean_content' => $e->clean_content ?? '',
                'ip' => $e->ip,
                'user_agent' => $e->user_agent,
                'created_at' => (string)$e->created_at,
            ])->all();

            if ($export === 'json') {
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="moderacao-' . date('Ymd-His') . '.json"');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="moderacao-' . date('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'user_id', 'context', 'category', 'action', 'reason', 'content', 'clean_content', 'ip', 'user_agent', 'created_at'], ';');
            foreach ($payload as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
            exit;
        }

        $events = (clone $query)->orderByDesc('id')->limit(200)->get();

        $modUserIds = $events->pluck('user_id')->filter()->unique()->all();
        $modUsers = $modUserIds ? \AfiliaFacil\Models\User::whereIn('id', $modUserIds)->get()->keyBy('id') : collect();

        $stats['total'] = (int)\AfiliaFacil\Models\ModerationEvent::count();
        $stats['blocked'] = (int)\AfiliaFacil\Models\ModerationEvent::where('action', 'blocked')->count();
        $stats['redacted'] = (int)\AfiliaFacil\Models\ModerationEvent::where('action', 'redacted')->count();
        foreach (['crime', 'pii', 'profanity'] as $cat) {
            $stats[$cat] = (int)\AfiliaFacil\Models\ModerationEvent::where('category', $cat)->count();
        }
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderação - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .mod-table { width:100%; border-collapse:collapse; font-size:.8rem; }
        .mod-table th { text-align:left; padding:8px 10px; border-bottom:1px solid var(--border-color); color:var(--text-secondary); font-size:.7rem; text-transform:uppercase; }
        .mod-table td { padding:8px 10px; border-bottom:1px solid var(--border-color); vertical-align:top; }
        .mod-table .content { max-width:420px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-secondary); cursor:pointer; }
        .badge-cat { font-size:.65rem; padding:2px 8px; border-radius:10px; font-weight:700; text-transform:uppercase; }
        .cat-crime { background:#fee2e2; color:#991b1b; }
        .cat-pii { background:#fef3c7; color:#92400e; }
        .cat-profanity { background:#e0e7ff; color:#3730a3; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Moderação</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Moderação e Segurança</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Registros de conteúdo bloqueado ou redigido — retidos com data, hora, IP e conteúdo para eventual solicitação de autoridades.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-outline btn-sm" href="?export=csv&category=<?= urlencode($filterCategory) ?>&action=<?= urlencode($filterAction) ?>&user=<?= urlencode($filterUser) ?>"><i class="fas fa-file-csv"></i> Exportar CSV</a>
                        <a class="btn btn-outline btn-sm" href="?export=json&category=<?= urlencode($filterCategory) ?>&action=<?= urlencode($filterAction) ?>&user=<?= urlencode($filterUser) ?>"><i class="fas fa-file-code"></i> Exportar JSON</a>
                    </div>
                </div>

                <div class="stats-grid" style="margin-bottom:20px;">
                    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-list"></i></div><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Total de eventos</div></div>
                    <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-ban"></i></div><div class="stat-value"><?= $stats['blocked'] ?></div><div class="stat-label">Bloqueados</div></div>
                    <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-eye-slash"></i></div><div class="stat-value"><?= $stats['redacted'] ?></div><div class="stat-label">Redigidos</div></div>
                    <div class="stat-card"><div class="stat-icon red" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-value"><?= $stats['crime'] ?></div><div class="stat-label">Crime/abuso</div></div>
                </div>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body">
                        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                            <div class="form-group" style="margin:0;">
                                <label>Categoria</label>
                                <select name="category" class="form-control">
                                    <option value="">Todas</option>
                                    <option value="crime" <?= $filterCategory === 'crime' ? 'selected' : '' ?>>Crime/abuso</option>
                                    <option value="pii" <?= $filterCategory === 'pii' ? 'selected' : '' ?>>PII/dados sensíveis</option>
                                    <option value="profanity" <?= $filterCategory === 'profanity' ? 'selected' : '' ?>>Palavras torpes</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Ação</label>
                                <select name="action" class="form-control">
                                    <option value="">Todas</option>
                                    <option value="blocked" <?= $filterAction === 'blocked' ? 'selected' : '' ?>>Bloqueado</option>
                                    <option value="redacted" <?= $filterAction === 'redacted' ? 'selected' : '' ?>>Redigido</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>ID do usuário</label>
                                <input type="text" name="user" class="form-control" value="<?= htmlspecialchars($filterUser) ?>" placeholder="ex: 1789...">
                            </div>
                            <button class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-shield-halved"></i> Eventos recentes</h3></div>
                    <div class="card-body" style="overflow-x:auto;">
                        <?php if (empty($events)): ?>
                        <div class="empty-state" style="padding:30px;"><i class="fas fa-shield-halved"></i><h3>Nenhum evento de moderação</h3><p>Nada bloqueado ou redigido até agora.</p></div>
                        <?php else: ?>
                        <table class="mod-table">
                            <thead>
                                <tr>
                                    <th>Data/hora</th>
                                    <th>Usuário</th>
                                    <th>Contexto</th>
                                    <th>Categoria</th>
                                    <th>Ação</th>
                                    <th>Motivo</th>
                                    <th>Conteúdo</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= htmlspecialchars((string)$event->created_at) ?></td>
                                    <td><small><?= htmlspecialchars($modUsers[$event->user_id]->email ?? ('#' . (int)$event->user_id)) ?></small></td>
                                    <td><?= htmlspecialchars($event->context) ?></td>
                                    <td><span class="badge-cat cat-<?= htmlspecialchars($event->category) ?>"><?= htmlspecialchars($event->category) ?></span></td>
                                    <td><?= $event->action === 'blocked' ? '<span style="color:var(--danger);font-weight:600;">bloqueado</span>' : 'redigido' ?></td>
                                    <td style="max-width:260px;"><?= htmlspecialchars($event->reason) ?></td>
                                    <td><div class="content" title="Clique para ver original x limpo" onclick="showModEvent(<?= (int)$event->id ?>)"><?= htmlspecialchars(mb_substr((string)$event->content, 0, 120)) ?></div>
                                        <template id="mod-content-<?= (int)$event->id ?>"><?= htmlspecialchars((string)$event->content) ?></template>
                                        <template id="mod-clean-<?= (int)$event->id ?>"><?= htmlspecialchars((string)($event->clean_content ?? '')) ?></template>
                                        <template id="mod-meta-<?= (int)$event->id ?>"><?= htmlspecialchars(($modUsers[$event->user_id]->email ?? ('#' . (int)$event->user_id)) . ' · ' . $event->context . ' · ' . $event->category . ' · ' . $event->action . ' · ' . $event->created_at . ' · ' . $event->ip) ?></template>
                                    </td>
                                    <td style="white-space:nowrap;"><?= htmlspecialchars($event->ip) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modModal">
        <div class="modal" style="max-width:760px;width:94%;">
            <div class="modal-header">
                <h3>Conteúdo registrado</h3>
                <button class="modal-close" onclick="document.getElementById('modModal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="modModalSub" style="font-size:.78rem;color:var(--text-secondary);margin-bottom:12px;"></p>
                <h4 style="font-size:.8rem;margin-bottom:6px;">Original</h4>
                <pre class="audit-pre" id="modModalOrig" style="background:var(--bg-secondary);border-radius:var(--radius);padding:12px;font-size:.78rem;line-height:1.6;white-space:pre-wrap;max-height:240px;overflow-y:auto;margin:0 0 12px;"></pre>
                <h4 style="font-size:.8rem;margin-bottom:6px;">Como ficou (limpo)</h4>
                <pre class="audit-pre" id="modModalClean" style="background:var(--bg-secondary);border-radius:var(--radius);padding:12px;font-size:.78rem;line-height:1.6;white-space:pre-wrap;max-height:240px;overflow-y:auto;margin:0;"></pre>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    function showModEvent(id) {
        const orig = document.getElementById('mod-content-' + id);
        const clean = document.getElementById('mod-clean-' + id);
        const meta = document.getElementById('mod-meta-' + id);
        if (!orig) return;
        document.getElementById('modModalSub').textContent = meta ? meta.textContent : '';
        document.getElementById('modModalOrig').textContent = orig.textContent || '(vazio)';
        const cleanTxt = clean ? clean.textContent : '';
        document.getElementById('modModalClean').textContent = cleanTxt !== '' ? cleanTxt : '(igual ao original — bloqueio total)';
        document.getElementById('modModal').classList.add('active');
    }
    </script>
</body>
</html>
