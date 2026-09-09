<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Payments.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();

if (!Auth::isAdmin()) {
    header('Location: /admin/');
    exit;
}

$payments = new Payments();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($_POST['action'] === 'approve') {
        $payments->approve($id);
    } elseif ($_POST['action'] === 'reject') {
        $payments->reject($id);
    }
    header('Location: /admin/pay.php');
    exit;
}

$pending = $payments->listPending();
$all = $payments->all();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamentos - AfiliaFacil</title>
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
                <div class="topbar-title">Pagamentos</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Pagamentos PIX</h1>
                    <span class="btn btn-sm btn-warning" style="cursor:default;"><i class="fas fa-hourglass-half"></i> <?= count($pending) ?> pendentes</span>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-exclamation-circle" style="color:var(--warning);"></i> Pagamentos pendentes de aprovação</h3></div>
                    <div class="card-body">
                        <?php if (empty($pending)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>Nenhum pagamento pendente</h3>
                            <p>Ainda não há pagamentos PIX aguardando confirmação.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead><tr><th>#</th><th>Usuário</th><th>Plano</th><th>Ciclo</th><th>Valor</th><th>Referência</th><th>Data</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php foreach ($pending as $p): ?>
                                    <tr>
                                        <td><?= $p['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($p['user_email']) ?></strong></td>
                                        <td><?= Plans::planName($p['plan_id']) ?></td>
                                        <td><?= Plans::CYCLE_LABELS[$p['cycle']] ?? $p['cycle'] ?></td>
                                        <td><strong><?= Plans::formatPrice($p['amount']) ?></strong></td>
                                        <td><small style="font-family:monospace;"><?= htmlspecialchars($p['reference']) ?></small></td>
                                        <td><small><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-success" title="Confirmar pagamento"><i class="fas fa-check"></i></button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-outline" title="Rejeitar"><i class="fas fa-times"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-history"></i> Histórico de pagamentos</h3></div>
                    <div class="card-body">
                        <?php if (empty($all)): ?>
                        <div class="empty-state"><p>Sem pagamentos registrados ainda.</p></div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead><tr><th>#</th><th>Usuário</th><th>Plano</th><th>Valor</th><th>Gateway</th><th>Status</th><th>Data</th></tr></thead>
                                <tbody>
                                    <?php foreach (array_reverse($all) as $p): ?>
                                    <tr>
                                        <td><?= $p['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($p['user_email']) ?></strong></td>
                                        <td><?= Plans::planName($p['plan_id']) ?></td>
                                        <td><?= Plans::formatPrice($p['amount']) ?></td>
                                        <td><?= strtoupper($p['gateway']) ?></td>
                                        <td><span class="status status-<?= $p['status'] === 'paid' ? 'active' : ($p['status'] === 'pending' ? 'draft' : 'expired') ?>"><?= $p['status'] ?></span></td>
                                        <td><small><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small></td>
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
