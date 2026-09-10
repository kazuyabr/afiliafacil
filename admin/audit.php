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

$events = [];
if (Database::available()) {
    try {
        $logs = \AfiliaFacil\Models\AuditLog::orderBy('created_at', 'desc')->limit(100)->get();
        $userIds = $logs->pluck('user_id')->filter()->unique()->all();
        $users = $userIds ? \AfiliaFacil\Models\User::whereIn('id', $userIds)->get()->keyBy('id') : collect();

        $actionLabels = [
            'login' => 'Login',
            'login_failed' => 'Login falhou',
            'login_blocked' => 'Login bloqueado',
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
        ];

        foreach ($logs as $log) {
            $events[] = [
                'created_at' => (string)$log->created_at,
                'action' => $log->action,
                'action_label' => $actionLabels[$log->action] ?? $log->action,
                'user' => $log->user_id ? ($users[$log->user_id]->email ?? ('#' . $log->user_id)) : 'sistema',
                'entity' => $log->entity,
                'entity_id' => $log->entity_id,
                'meta' => $log->meta,
                'ip' => $log->ip,
            ];
        }
    } catch (Throwable $e) {
        $events = [];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
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
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Últimos 100 eventos registrados na plataforma.</p>
                    </div>
                    <button class="btn btn-outline" onclick="location.reload()"><i class="fas fa-sync"></i> Atualizar</button>
                </div>

                <div class="card">
                    <div class="card-body">
                        <?php if (empty($events)): ?>
                        <div class="empty-state" style="padding:32px;">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>Nenhum evento registrado</h3>
                            <p>Os eventos aparecerão aqui conforme a plataforma for usada.</p>
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
                                        <th>Detalhes</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $e): ?>
                                    <tr>
                                        <td><small><?= date('d/m/Y H:i:s', strtotime($e['created_at'])) ?></small></td>
                                        <td>
                                            <span style="font-size:.8rem;background:var(--bg-secondary);padding:3px 8px;border-radius:4px;">
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
                                        <td><small style="font-family:monospace;font-size:.72rem;"><?= htmlspecialchars($e['meta'] ?? '') ?></small></td>
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
    <script src="/assets/js/app.js"></script>
</body>
</html>
