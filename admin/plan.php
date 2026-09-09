<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Payments.php';
require_once Config::getLibDir() . '/Checkout.php';

Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';

$user = Auth::user();
$currentPlan = Auth::getUserById($user['id']);
$plan = Plans::get($user['plan']);

$checkout = null;
$checkoutErr = null;
if (isset($_GET['plan']) && isset($_GET['cycle'])) {
    $planId = $_GET['plan'];
    $cycle = $_GET['cycle'];
    $result = Checkout::createCheckout($user['id'], $planId, $cycle);
    if (isset($result['error'])) {
        $checkoutErr = $result['error'];
    } else {
        $checkout = $result;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Plano - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-<?= $theme ?>.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .plan-card { border: 2px solid var(--border-color); border-radius: var(--radius-lg); padding: 24px; text-align: center; }
        .plan-card.current { border-color: var(--accent); }
        .plan-card h3 { font-size: 1.1rem; margin-bottom: 8px; }
        .plan-card .price { font-size: 2rem; font-weight: 800; margin: 8px 0; }
        .plan-card ul { list-style: none; padding: 0; margin: 16px 0; text-align: left; }
        .plan-card ul li { padding: 5px 0; font-size: .85rem; display: flex; gap: 8px; align-items: center; }
        .plan-card ul li i { color: var(--success); font-size: .75rem; }
        .cycle-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--border-color); border-radius: var(--radius); margin: 4px; font-size: .8rem; cursor: pointer; background: var(--bg-card); color: var(--text-primary); }
        .cycle-btn.active { border-color: var(--accent); background: var(--accent-light); color: var(--accent); }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Meu Plano</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <?php if (isset($_GET['payment_success']) && isset($_SESSION['user_plan'])): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Pagamento confirmado! Seu plano foi atualizado com sucesso.</div>
                <?php endif; ?>
                <?php if (isset($_GET['payment_cancel'])): ?>
                    <div class="alert alert-warning"><i class="fas fa-info-circle"></i> Pagamento cancelado. Você pode tentar novamente quando quiser.</div>
                <?php endif; ?>
                <?php if ($checkoutErr): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($checkoutErr) ?></div>
                <?php endif; ?>
                <?php if ($checkout && $checkout['type'] === 'stripe'): ?>
                    <script>window.location.href = "<?= htmlspecialchars($checkout['redirect_url']) ?>";</script>
                <?php endif; ?>

                <div class="page-header">
                    <h1>Meu Plano</h1>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div>
                            <h2 style="font-size:1.3rem;">Plano atual: <span style="color:var(--accent);"><?= $plan['name'] ?></span></h2>
                            <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;"><?= $plan['label'] ?></p>
                            <?php if ($user['plan'] === 'trial'): ?>
                                <p style="color:var(--text-secondary);font-size:.85rem;margin-top:4px;">
                                    Trial termina em <strong><?= date('d/m/Y H:i', strtotime($currentPlan['trial_until'] ?? 'now +3 days')) ?></strong>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <?php if ($user['plan'] !== 'premium'): ?>
                                <a href="#plans-grid" class="btn btn-outline"><i class="fas fa-arrow-up"></i> Fazer upgrade</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="plans-grid" class="grid-3" style="margin-bottom:24px;">
                    <?php foreach (['vsl', 'essencial', 'master'] as $planId): ?>
                    <?php $p = Plans::get($planId); ?>
                    <div class="plan-card <?= $user['plan'] === $planId ? 'current' : '' ?>">
                        <h3><?= $p['name'] ?></h3>
                        <div class="price"><?= Plans::formatPrice($p['price']) ?><small style="font-size:.9rem;font-weight:500;color:var(--text-secondary);">/mês</small></div>
                        <ul>
                            <li><i class="fas fa-check"></i> <?= $p['label'] ?></li>
                            <li><i class="fas fa-check"></i> <?= $p['max_pages'] === -1 ? 'Páginas ilimitadas' : $p['max_pages'] . ' páginas' ?></li>
                            <li><i class="fas fa-check"></i> <?= $p['max_domains'] === -1 ? 'Domínios ilimitados' : $p['max_domains'] . ' domínios' ?></li>
                            <li><i class="fas fa-check"></i> <?= count($p['features']) ?> recursos inclusos</li>
                        </ul>
                        <?php if ($user['plan'] === $planId): ?>
                            <button class="btn btn-outline btn-full" disabled><i class="fas fa-check"></i> Plano atual</button>
                        <?php else: ?>
                            <a href="/admin/plan.php?plan=<?= $planId ?>&cycle=monthly" class="btn btn-primary btn-full">Assinar agora</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($checkout && $checkout['type'] === 'pix'): ?>
                <div class="card" id="pixCard">
                    <div class="card-header"><h3><i class="fas fa-qrcode"></i> Pagar com PIX</h3></div>
                    <div class="card-body" style="text-align:center;">
                        <img src="<?= htmlspecialchars($checkout['qr_image']) ?>" alt="QR Code PIX" style="width:220px;height:220px;border-radius:12px;margin:0 auto 16px;display:block;" id="pixQrImg">
                        <p style="font-weight:600;margin-bottom:4px;"><?= Plans::formatPrice($checkout['amount']) ?></p>
                        <p style="color:var(--text-secondary);font-size:.85rem;margin-bottom:16px;">Abra o app do seu banco e escaneie o QR Code, ou copie o código abaixo:</p>
                        <div class="input-group" style="max-width:460px;margin:0 auto 16px;">
                            <input type="text" id="pixCode" class="form-control" readonly value="<?= htmlspecialchars($checkout['copia_cola']) ?>" style="font-family:monospace;font-size:.7rem;">
                            <button class="btn btn-outline" onclick="copyPix()"><i class="fas fa-copy"></i> Copiar</button>
                        </div>
                        <div class="alert alert-warning" style="text-align:left;display:inline-block;">
                            <i class="fas fa-info-circle"></i> Após o pagamento, aguarde a aprovação manual. Enquanto isso, sua assinatura fica <strong>pendente</strong>.
                        </div>
                        <p><a href="/admin/plan.php" class="btn btn-sm btn-outline"><i class="fas fa-sync"></i> Verificar status</a></p>
                    </div>
                </div>
                <script>
                function copyPix() {
                    const el = document.getElementById('pixCode');
                    el.select();
                    document.execCommand('copy');
                    showToast('Código PIX copiado!', 'success');
                }
                var checkInterval = setInterval(function() {
                    fetch('/admin/api/checkout.php?action=status&payment_id=<?= $checkout['payment_id'] ?>')
                        .then(r => r.json())
                        .then(d => {
                            if (d.status === 'paid') {
                                clearInterval(checkInterval);
                                showToast('Pagamento confirmado!', 'success');
                                setTimeout(function() { location.reload(); }, 1500);
                            }
                        });
                }, 5000);
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
