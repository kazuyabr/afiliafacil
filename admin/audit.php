<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';

Auth::requireAuth();
if (!Auth::can('manage_settings')) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';

$filterAction = trim($_GET['action'] ?? '');
$filterUser = trim($_GET['user'] ?? '');
$filterDays = (int)($_GET['days'] ?? 30);
$filterQ = trim($_GET['q'] ?? '');
$filterFailures = !empty($_GET['failures']);

$actionLabels = [
    'login' => 'Login',
    'login_failed' => 'Login falhou',
    'login_blocked' => 'Login bloqueado',
    'login_2fa_pending' => 'Login 2FA pendente',
    'login_2fa_failed' => '2FA falhou',
    'plan_changed' => 'Plano alterado',
    'pricing_updated' => 'Preço alterado',
    'page_deleted' => 'Página excluída',
    'user_created' => 'Usuário criado',
    'user_updated' => 'Usuário atualizado',
    'user_activated' => 'Usuário ativado',
    'user_deactivated' => 'Usuário desativado',
    'user_deleted' => 'Usuário excluído',
    'role_created' => 'Cargo criado',
    'role_updated' => 'Cargo atualizado',
    'role_deleted' => 'Cargo excluído',
    'storage_saved' => 'Storage salvo',
    '2fa_enabled' => '2FA ativado',
    '2fa_disabled' => '2FA desativado',
    '2fa_recovery_used' => 'Código de recuperação usado',
    '2fa_recovery_regenerated' => 'Códigos regenerados',
    'ai_config_saved' => 'IA configurada',
    'agent_rated' => 'Resposta avaliada',
    'agent_tool_executed' => 'Ferramenta executada',
    'agent_tool_failed' => 'Ferramenta falhou',
    'subagent_created' => 'Subagente criado',
    'subagent_updated' => 'Subagente atualizado',
    'subagent_deleted' => 'Subagente excluído',
    'transcription_completed' => 'Transcrição concluída',
    'transcription_failed' => 'Transcrição falhou',
    'tts_completed' => 'Narração concluída',
    'tts_failed' => 'Narração falhou',
    'moderation_blocked' => 'Moderação bloqueou',
    'offer_viewed' => 'Oferta vista',
    'offer_approved' => 'Oferta aprovada',
    'offer_rejected' => 'Oferta rejeitada',
    'offers_bulk_approve' => 'Aprovação em lote',
    'offers_bulk_reject' => 'Rejeição em lote',
    'offers_collect' => 'Coleta de ofertas',
    'offers_settings_updated' => 'Config. ofertas',
    'creative_downloaded' => 'Criativo baixado',
    'creative_varied' => 'Variação criada',
    'feedback_sent' => 'Feedback enviado',
    'feedback_replied' => 'Feedback respondido',
    'cron_key_rotated' => 'Chave cron rotacionada',
    'data_exported' => 'Dados exportados',
];

$auditLabel = function (string $action) use ($actionLabels): string {
    if (isset($actionLabels[$action])) return $actionLabels[$action];
    return ucfirst(str_replace('_', ' ', $action));
};

$isFailure = function (string $action): bool {
    return str_ends_with($action, '_failed') || str_ends_with($action, '_blocked')
        || in_array($action, ['moderation_blocked'], true);
};

$events = [];
$allActions = [];

if (Database::available()) {
    try {
        $allActions = \AfiliaFacil\Models\AuditLog::select('action')->distinct()->orderBy('action')->pluck('action')->all();

        $query = \AfiliaFacil\Models\AuditLog::query();
        if ($filterAction !== '') $query->where('action', $filterAction);
        if ($filterFailures) {
            $query->where(function ($sub) {
                $sub->where('action', 'like', '%_failed')->orWhere('action', 'like', '%_blocked')
                    ->orWhere('action', 'moderation_blocked');
            });
        }
        if ($filterUser !== '') {
            if (ctype_digit($filterUser)) {
                $query->where('user_id', (int)$filterUser);
            } else {
                $ids = \AfiliaFacil\Models\User::where('email', 'like', '%' . $filterUser . '%')->pluck('id')->all();
                if (empty($ids)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('user_id', $ids);
                }
            }
        }
        if ($filterDays > 0) {
            $query->where('created_at', '>=', date('Y-m-d H:i:s', time() - $filterDays * 86400));
        }
        if ($filterQ !== '') {
            $like = '%' . $filterQ . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('entity', 'like', $like)->orWhere('entity_id', 'like', $like)->orWhere('meta', 'like', $like);
            });
        }

        if (($_GET['export'] ?? '') === 'csv') {
            $rows = (clone $query)->orderByDesc('id')->limit(5000)->get();
            $userIds = $rows->pluck('user_id')->filter()->unique()->all();
            $users = $userIds ? \AfiliaFacil\Models\User::whereIn('id', $userIds)->get()->keyBy('id') : collect();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="auditoria-' . date('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['data', 'acao', 'usuario', 'entidade', 'entidade_id', 'detalhes', 'ip'], ';');
            foreach ($rows as $log) {
                fputcsv($out, [
                    (string)$log->created_at,
                    $log->action,
                    $log->user_id ? ($users[$log->user_id]->email ?? ('#' . $log->user_id)) : 'sistema',
                    (string)$log->entity,
                    (string)$log->entity_id,
                    (string)$log->meta,
                    (string)$log->ip,
                ], ';');
            }
            fclose($out);
            exit;
        }

        $logs = (clone $query)->orderByDesc('id')->limit(200)->get();
        $userIds = $logs->pluck('user_id')->filter()->unique()->all();
        $users = $userIds ? \AfiliaFacil\Models\User::whereIn('id', $userIds)->get()->keyBy('id') : collect();

        foreach ($logs as $log) {
            $meta = (string)($log->meta ?? '');
            $metaPretty = $meta;
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) $metaPretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $events[] = [
                'created_at' => (string)$log->created_at,
                'action' => $log->action,
                'action_label' => $auditLabel($log->action),
                'failure' => $isFailure($log->action),
                'user' => $log->user_id ? ($users[$log->user_id]->email ?? ('#' . $log->user_id)) : 'sistema',
                'entity' => $log->entity,
                'entity_id' => $log->entity_id,
                'meta' => $meta,
                'meta_short' => mb_substr($meta, 0, 100),
                'meta_pretty' => $metaPretty,
                'ip' => $log->ip,
            ];
        }
    } catch (Throwable $e) {
        $events = [];
    }
}

$exportQs = http_build_query(array_filter([
    'export' => 'csv',
    'action' => $filterAction,
    'user' => $filterUser,
    'days' => $filterDays,
    'q' => $filterQ,
    'failures' => $filterFailures ? '1' : '',
]));
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .audit-fail { color:var(--danger); font-weight:600; }
        .audit-meta { font-family:monospace; font-size:.72rem; color:var(--text-secondary); cursor:pointer; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .audit-pre { background:var(--bg-secondary); border-radius:var(--radius); padding:12px; font-size:.78rem; line-height:1.6; white-space:pre-wrap; max-height:380px; overflow-y:auto; margin:0; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Auditoria</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Auditoria</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Quem fez o quê, quando e por quê — filtre por ação, usuário, período ou busque nos detalhes.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-outline btn-sm" href="?<?= htmlspecialchars($exportQs) ?>"><i class="fas fa-file-csv"></i> Exportar CSV</a>
                        <button class="btn btn-outline" onclick="location.reload()"><i class="fas fa-sync"></i> Atualizar</button>
                    </div>
                </div>

                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body">
                        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                            <div class="form-group" style="margin:0;">
                                <label>Ação</label>
                                <select name="action" class="form-control">
                                    <option value="">Todas</option>
                                    <?php foreach ($allActions as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= htmlspecialchars($auditLabel($a)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Usuário (e-mail ou ID)</label>
                                <input type="text" name="user" class="form-control" value="<?= htmlspecialchars($filterUser) ?>" placeholder="ex: cliente@... ou 12">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Período</label>
                                <select name="days" class="form-control">
                                    <option value="7" <?= $filterDays === 7 ? 'selected' : '' ?>>7 dias</option>
                                    <option value="30" <?= $filterDays === 30 ? 'selected' : '' ?>>30 dias</option>
                                    <option value="90" <?= $filterDays === 90 ? 'selected' : '' ?>>90 dias</option>
                                    <option value="0" <?= $filterDays === 0 ? 'selected' : '' ?>>Tudo</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                                <label>Buscar (entidade/detalhes)</label>
                                <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($filterQ) ?>" placeholder="ex: offer, 401, token...">
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:.85rem;cursor:pointer;padding-bottom:8px;">
                                <input type="checkbox" name="failures" value="1" <?= $filterFailures ? 'checked' : '' ?>> Só falhas
                            </label>
                            <button class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <?php if (empty($events)): ?>
                        <div class="empty-state" style="padding:32px;">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>Nenhum evento com esses filtros</h3>
                            <p>Ajuste os filtros ou aguarde novos eventos da plataforma.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Ação</th>
                                        <th>Usuário</th>
                                        <th>Entidade</th>
                                        <th>Motivo/detalhes</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $i => $e): ?>
                                    <tr>
                                        <td><small><?= date('d/m/Y H:i:s', strtotime($e['created_at'])) ?></small></td>
                                        <td>
                                            <span style="font-size:.8rem;background:var(--bg-secondary);padding:3px 8px;border-radius:4px;<?= $e['failure'] ? 'color:var(--danger);font-weight:600;' : '' ?>">
                                                <?= htmlspecialchars($e['action_label']) ?>
                                            </span>
                                        </td>
                                        <td><small><?= htmlspecialchars($e['user']) ?></small></td>
                                        <td>
                                            <?php if ($e['entity']): ?>
                                            <small><?= htmlspecialchars($e['entity']) ?><?= $e['entity_id'] ? ' #' . htmlspecialchars($e['entity_id']) : '' ?></small>
                                            <?php else: ?>
                                            <small style="color:var(--text-secondary);">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><div class="audit-meta" title="Clique para ver completo" onclick="showAudit(<?= $i ?>)"><?= $e['meta_short'] !== '' ? htmlspecialchars($e['meta_short']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
                                            <template id="audit-meta-<?= $i ?>"><?= htmlspecialchars($e['meta_pretty']) ?></template>
                                            <template id="audit-row-<?= $i ?>"><?= htmlspecialchars($e['action_label'] . ' · ' . $e['user'] . ' · ' . $e['created_at'] . ($e['ip'] ? ' · ' . $e['ip'] : '')) ?></template>
                                        </td>
                                        <td><small style="color:var(--text-secondary);"><?= htmlspecialchars($e['ip'] ?? '') ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="auditModal">
        <div class="modal" style="max-width:720px;width:94%;">
            <div class="modal-header">
                <h3 id="auditModalTitle">Detalhe</h3>
                <button class="modal-close" onclick="document.getElementById('auditModal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="auditModalSub" style="font-size:.78rem;color:var(--text-secondary);margin-bottom:12px;"></p>
                <pre class="audit-pre" id="auditModalBody"></pre>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function showAudit(i) {
        const meta = document.getElementById('audit-meta-' + i);
        const row = document.getElementById('audit-row-' + i);
        if (!meta) return;
        document.getElementById('auditModalTitle').textContent = 'Motivo / detalhes';
        document.getElementById('auditModalSub').textContent = row ? row.textContent : '';
        document.getElementById('auditModalBody').textContent = meta.textContent.trim() !== '' ? meta.textContent : '(sem detalhes registrados)';
        document.getElementById('auditModal').classList.add('active');
    }
    </script>
</body>
</html>
