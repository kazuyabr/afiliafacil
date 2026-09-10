<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Settings.php';
Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['theme'])) {
        $_SESSION['theme'] = $_POST['theme'] === 'dark' ? 'dark' : 'light';
    }

    if (isset($_POST['system']) && Auth::can('manage_settings')) {
        if (isset($_POST['trial_days'])) Settings::set('trial_days', max(0, (int)$_POST['trial_days']));
        if (isset($_POST['checkout_driver']) && in_array($_POST['checkout_driver'], ['pix', 'stripe'])) Settings::set('checkout_driver', $_POST['checkout_driver']);
        if (isset($_POST['pix_key'])) Settings::set('pix_key', trim($_POST['pix_key']));
        if (isset($_POST['pix_name'])) Settings::set('pix_name', trim($_POST['pix_name']));
        if (isset($_POST['pix_city'])) Settings::set('pix_city', trim($_POST['pix_city']));
        if (isset($_POST['stripe_secret_key'])) Settings::set('stripe_secret_key', trim($_POST['stripe_secret_key']));
        if (isset($_POST['stripe_webhook_secret'])) Settings::set('stripe_webhook_secret', trim($_POST['stripe_webhook_secret']));
    }

    header('Location: /admin/settings.php?saved=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$saved = isset($_GET['saved']);
$settings = Settings::all();
$isAdmin = Auth::can('manage_settings');
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - AfiliaFacil</title>
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
                <div class="topbar-title">Configurações</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">
                <?php if ($saved): ?>
                <div class="alert alert-success"><i class="fas fa-check"></i> Configurações salvas com sucesso!</div>
                <?php endif; ?>

                <div class="grid-2">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-palette"></i> Tema</h3></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label>Aparência do painel</label>
                                    <div style="display:flex;gap:12px;margin-top:8px;">
                                        <label style="flex:1;cursor:pointer;">
                                            <input type="radio" name="theme" value="light" <?= $theme === 'light' ? 'checked' : '' ?> style="display:none;">
                                            <div style="border:2px solid <?= $theme === 'light' ? 'var(--accent)' : 'var(--border-color)' ?>;border-radius:var(--radius);padding:16px;text-align:center;transition:all .2s;">
                                                <i class="fas fa-sun" style="font-size:1.5rem;color:#ffc107;"></i>
                                                <div style="margin-top:8px;font-weight:500;">Claro</div>
                                            </div>
                                        </label>
                                        <label style="flex:1;cursor:pointer;">
                                            <input type="radio" name="theme" value="dark" <?= $theme === 'dark' ? 'checked' : '' ?> style="display:none;">
                                            <div style="border:2px solid <?= $theme === 'dark' ? 'var(--accent)' : 'var(--border-color)' ?>;border-radius:var(--radius);padding:16px;text-align:center;transition:all .2s;">
                                                <i class="fas fa-moon" style="font-size:1.5rem;color:#3d8bfd;"></i>
                                                <div style="margin-top:8px;font-weight:500;">Escuro</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-user"></i> Conta</h3></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Nome</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>E-mail</label>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                            </div>
                            <div class="form-group">
                                <label>Plano</label>
                                <input type="text" class="form-control" value="<?= ucfirst($user['plan']) ?>" disabled>
                            </div>
                            <a href="/admin/plan.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-up"></i> Ver meu plano</a>
                        </div>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                <div class="card" style="margin-top:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-cog"></i> Configurações do Sistema (Admin)</h3>
                        <span class="status status-active">Somente administrador</span>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="system" value="1">
                            <div class="grid-2">
                                <div>
                                    <div class="form-group">
                                        <label>Dias de trial para novos registros</label>
                                        <input type="number" name="trial_days" class="form-control" value="<?= (int)$settings['trial_days'] ?>" min="0">
                                        <small style="color:var(--text-secondary);">Quantos dias de teste grátis novos usuários recebem. 0 = sem trial.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Gateway de pagamento</label>
                                        <select name="checkout_driver" class="form-control">
                                            <option value="pix" <?= ($settings['checkout_driver'] ?? 'pix') === 'pix' ? 'selected' : '' ?>>PIX (estático, aprovação manual)</option>
                                            <option value="stripe" <?= ($settings['checkout_driver'] ?? '') === 'stripe' ? 'selected' : '' ?>>Stripe (checkout automático)</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group">
                                        <label>Chave PIX</label>
                                        <input type="text" name="pix_key" class="form-control" value="<?= htmlspecialchars($settings['pix_key'] ?? '') ?>" placeholder="CPF, CNPJ, e-mail, celular ou chave aleatória">
                                    </div>
                                    <div class="form-group">
                                        <label>Nome do recebedor PIX</label>
                                        <input type="text" name="pix_name" class="form-control" value="<?= htmlspecialchars($settings['pix_name'] ?? 'AfiliaFacil') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Cidade (PIX)</label>
                                        <input type="text" name="pix_city" class="form-control" value="<?= htmlspecialchars($settings['pix_city'] ?? 'SAO PAULO') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="grid-2" style="margin-top:8px;">
                                <div class="form-group">
                                    <label>Stripe Secret Key (teste)</label>
                                    <input type="text" name="stripe_secret_key" class="form-control" value="<?= htmlspecialchars($settings['stripe_secret_key'] ?? '') ?>" placeholder="sk_test_... (ou via env STRIPE_SECRET_KEY)">
                                </div>
                                <div class="form-group">
                                    <label>Stripe Webhook Secret</label>
                                    <input type="text" name="stripe_webhook_secret" class="form-control" value="<?= htmlspecialchars($settings['stripe_webhook_secret'] ?? '') ?>" placeholder="whsec_... (ou via env STRIPE_WEBHOOK_SECRET)">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar configurações do sistema</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
