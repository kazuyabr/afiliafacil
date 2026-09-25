<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Training/TrainingCollector.php';
Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['theme'])) {
        $_SESSION['theme'] = $_POST['theme'] === 'dark' ? 'dark' : 'light';
    }

    if (isset($_POST['privacy'])) {
        TrainingCollector::setConsent((int)(Auth::user()['id'] ?? 0), !empty($_POST['training_consent']));
    }

    if (isset($_POST['system']) && Auth::can('manage_settings')) {
        if (isset($_POST['trial_days'])) Settings::set('trial_days', max(0, (int)$_POST['trial_days']));
        if (isset($_POST['checkout_driver']) && in_array($_POST['checkout_driver'], ['pix', 'stripe'])) Settings::set('checkout_driver', $_POST['checkout_driver']);
        if (isset($_POST['pix_key'])) Settings::set('pix_key', trim($_POST['pix_key']));
        if (isset($_POST['pix_name'])) Settings::set('pix_name', trim($_POST['pix_name']));
        if (isset($_POST['pix_city'])) Settings::set('pix_city', trim($_POST['pix_city']));
        if (isset($_POST['stripe_secret_key'])) Settings::set('stripe_secret_key', trim($_POST['stripe_secret_key']));
        if (isset($_POST['stripe_webhook_secret'])) Settings::set('stripe_webhook_secret', trim($_POST['stripe_webhook_secret']));
        if (isset($_POST['company_name'])) Settings::set('company_name', trim($_POST['company_name']));
        if (isset($_POST['company_cnpj'])) Settings::set('company_cnpj', trim($_POST['company_cnpj']));
        if (isset($_POST['company_email'])) Settings::set('company_email', trim($_POST['company_email']));
        if (isset($_POST['company_dpo_email'])) Settings::set('company_dpo_email', trim($_POST['company_dpo_email']));
        if (isset($_POST['company_address'])) Settings::set('company_address', trim($_POST['company_address']));
        if (array_key_exists('steel_api_url', $_POST)) {
            $steelUrl = trim($_POST['steel_api_url']);
            // Em Docker, o "localhost" do cliente nao alcanca o host — traduz p/ host.docker.internal
            if (getenv('DOCKER') && preg_match('#^https?://(localhost|127\.0\.0\.1)(:|$)#', $steelUrl)) {
                $steelUrl = preg_replace('#^https?://(localhost|127\.0\.0\.1)#', 'http://host.docker.internal', $steelUrl);
            }
            Settings::set('steel_api_url', $steelUrl);
        }
        if (array_key_exists('steel_api_key', $_POST)) Settings::set('steel_api_key', trim($_POST['steel_api_key']));
    }

    header('Location: /admin/settings.php?saved=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$saved = isset($_GET['saved']);
$settings = Settings::all();
$isAdmin = Auth::can('manage_settings');
$user = Auth::user();
$trainingConsent = false;
if (Database::available()) {
    try {
        $trainingConsent = (bool)(\AfiliaFacil\Models\User::find($user['id'])->training_consent ?? false);
    } catch (Throwable $e) {
        $trainingConsent = false;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
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

                    <div class="card" style="margin-top:24px;">
                        <div class="card-header"><h3><i class="fas fa-user-shield"></i> Privacidade e meus dados</h3></div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="privacy" value="1">
                                <div class="form-group" style="font-size:.85rem;line-height:1.5;">
                                    <label style="display:flex;align-items:flex-start;gap:8px;font-weight:400;cursor:pointer;">
                                        <input type="checkbox" name="training_consent" value="1" style="margin-top:3px;" <?= $trainingConsent ? 'checked' : '' ?>>
                                        <span>Autorizo o uso de dados <strong>anonimizados</strong> das minhas interações (conversas, transcrições e narrações) para melhorar a IA da plataforma. Posso revogar a qualquer momento. Veja a <a href="/privacidade" target="_blank">Política de Privacidade</a>.</span>
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-save"></i> Salvar preferência</button>
                            </form>

                            <?php if (Plans::hasFeature($user['plan'], 'agent') || Auth::isAdmin()): ?>
                            <hr style="border:none;border-top:1px solid var(--border-color);margin:18px 0;">
                            <p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:10px;">
                                <i class="fas fa-download"></i> Baixe seus dados (conversas, transcrições e textos de narração) em Markdown ou JSONL:
                            </p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn btn-outline btn-sm" href="/admin/api/export.php?action=my-data&format=md"><i class="fas fa-file-lines"></i> Baixar Markdown</a>
                                <a class="btn btn-outline btn-sm" href="/admin/api/export.php?action=my-data&format=jsonl"><i class="fas fa-file-code"></i> Baixar JSONL</a>
                            </div>
                            <?php else: ?>
                            <p style="font-size:.8rem;color:var(--text-secondary);margin-top:12px;">
                                <i class="fas fa-lock"></i> Download dos seus dados disponível nos planos Afiliado Pro, Master Elite e Admin.
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card" style="margin-top:24px;">
                        <div class="card-header">
                            <h3><i class="fas fa-shield-halved"></i> Segurança (2FA)</h3>
                            <span id="twoFaStatusBadge" class="status status-draft">Verificando...</span>
                        </div>
                        <div class="card-body">
                            <p style="color:var(--text-secondary);font-size:.9rem;margin-bottom:16px;">
                                Proteja sua conta com verificação em duas etapas (Google Authenticator, Authy, 1Password e similares).
                            </p>

                            <div id="twoFaIdle" style="display:none;">
                                <button class="btn btn-primary" onclick="start2fa()"><i class="fas fa-shield-halved"></i> Ativar 2FA</button>
                            </div>

                            <div id="twoFaSetup" style="display:none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> 1) Escaneie o QR Code no seu aplicativo autenticador. 2) Digite o código gerado para confirmar.
                                </div>
                                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
                                    <img id="twoFaQr" src="" alt="QR Code 2FA" style="width:180px;height:180px;border-radius:12px;border:1px solid var(--border-color);background:#fff;">
                                    <div style="flex:1;min-width:220px;">
                                        <div class="form-group">
                                            <label>Chave manual (se preferir)</label>
                                            <input type="text" id="twoFaSecret" class="form-control" readonly style="font-family:monospace;font-size:.8rem;">
                                        </div>
                                        <div class="form-group">
                                            <label>Código de 6 dígitos</label>
                                            <input type="text" id="twoFaCode" class="form-control" placeholder="000000" inputmode="numeric" style="font-family:monospace;letter-spacing:.15em;">
                                        </div>
                                        <div style="display:flex;gap:8px;">
                                            <button class="btn btn-primary" onclick="confirm2fa()"><i class="fas fa-check"></i> Confirmar e ativar</button>
                                            <button class="btn btn-outline" onclick="cancel2fa()">Cancelar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="twoFaEnabled" style="display:none;">
                                <div class="form-group">
                                    <label>Digite um código atual para confirmar a ação</label>
                                    <input type="text" id="twoFaManageCode" class="form-control" placeholder="000000" inputmode="numeric" style="max-width:220px;font-family:monospace;letter-spacing:.15em;">
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="btn btn-outline" onclick="regenerate2faCodes()"><i class="fas fa-rotate"></i> Regerar códigos de recuperação</button>
                                    <button class="btn btn-danger" onclick="disable2fa()"><i class="fas fa-shield-slash"></i> Desativar 2FA</button>
                                </div>
                            </div>

                            <div id="twoFaCodes" style="display:none;margin-top:16px;">
                                <div class="alert alert-warning">
                                    <i class="fas fa-triangle-exclamation"></i> <strong>Guarde estes códigos de recuperação agora.</strong> Eles são exibidos apenas uma vez e cada um só pode ser usado uma vez.
                                </div>
                                <div id="twoFaCodesList" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;font-family:monospace;"></div>
                            </div>

                            <div id="twoFaResult" style="margin-top:12px;"></div>
                        </div>
                    </div>

                    <div class="card" style="margin-top:24px;">
                        <div class="card-header"><h3><i class="fas fa-sliders"></i> Avançado</h3></div>
                        <div class="card-body">
                            <p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:12px;">Ferramentas avançadas fora do menu principal:</p>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn btn-outline btn-sm" href="/admin/ai-settings.php"><i class="fas fa-robot"></i> IA (chaves próprias)</a>
                                <a class="btn btn-outline btn-sm" href="/admin/transcribe.php"><i class="fas fa-microphone-lines"></i> Transcrições</a>
                                <a class="btn btn-outline btn-sm" href="/admin/tts.php"><i class="fas fa-volume-high"></i> Narração</a>
                                <a class="btn btn-outline btn-sm" href="/admin/storage.php"><i class="fas fa-database"></i> Armazenamento</a>
                                <?php if (Auth::can('manage_roles')): ?>
                                <a class="btn btn-outline btn-sm" href="/admin/roles.php"><i class="fas fa-user-shield"></i> Cargos</a>
                                <?php endif; ?>
                            </div>
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

                            <h4 style="margin:20px 0 10px;font-size:.95rem;"><i class="fas fa-building"></i> Dados da empresa (usados nos documentos legais)</h4>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Razão social / nome</label>
                                    <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name'] ?? 'AfiliaFacil') ?>">
                                </div>
                                <div class="form-group">
                                    <label>CNPJ</label>
                                    <input type="text" name="company_cnpj" class="form-control" value="<?= htmlspecialchars($settings['company_cnpj'] ?? '') ?>" placeholder="00.000.000/0000-00">
                                </div>
                            </div>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label>E-mail de contato</label>
                                    <input type="text" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email'] ?? 'contato@afiliafacil.com') ?>">
                                </div>
                                <div class="form-group">
                                    <label>E-mail do encarregado (DPO/LGPD)</label>
                                    <input type="text" name="company_dpo_email" class="form-control" value="<?= htmlspecialchars($settings['company_dpo_email'] ?? '') ?>" placeholder="dpo@suaempresa.com">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Endereço</label>
                                <input type="text" name="company_address" class="form-control" value="<?= htmlspecialchars($settings['company_address'] ?? '') ?>" placeholder="Rua, número, cidade/UF">
                            </div>

                            <h4 style="margin:20px 0 10px;font-size:.95rem;"><i class="fas fa-globe" style="color:var(--accent);"></i> Scraping Ad Spy (Steel Browser — gratuito self-hosted)</h4>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label>URL da API do Steel</label>
                                    <input type="url" name="steel_api_url" class="form-control" value="<?= htmlspecialchars($settings['steel_api_url'] ?? '') ?>" placeholder="http://host.docker.internal:19876">
                                    <small style="color:var(--text-secondary);">
                                        <strong>Installed:</strong> cole exatamente o copiar rap<strong>http://host.docker.internal:19876</strong> (porta 19876, evita colisar com seus outros projetos na 3000). O painel traduz <code>localhost</code>/<code>127.0.0.1</code> sozinho quando precisar.
                                    </small>
                                </div>
                                <div class="form-group">
                                    <label>Chave (só se habilitar auth no server)</label>
                                    <input type="password" name="steel_api_key" class="form-control" value="" placeholder="deixe vazio se não usar auth">
                                    <small style="color:var(--text-secondary);">Self-hosted padrão não pede chave. O Cloud (steel.dev) usa API key.</small>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                                <button type="button" class="btn btn-outline btn-sm" onclick="testSteel()" id="steelTestBtn"><i class="fas fa-plug"></i> Testar conexão</button>
                                <span id="steelTestResult" style="font-size:.8rem;"></span>
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
    <script>
    async function load2fa() {
        const resp = await fetch('/admin/api/2fa.php?action=status');
        const data = await resp.json();
        const enabled = !!data.enabled;
        const badge = document.getElementById('twoFaStatusBadge');
        badge.textContent = enabled ? 'Ativado' : 'Desativado';
        badge.className = 'status ' + (enabled ? 'status-active' : 'status-draft');
        document.getElementById('twoFaIdle').style.display = enabled ? 'none' : 'block';
        document.getElementById('twoFaEnabled').style.display = enabled ? 'block' : 'none';
        document.getElementById('twoFaSetup').style.display = 'none';
    }

    async function start2fa() {
        const resp = await fetch('/admin/api/2fa.php', { method: 'POST', body: new URLSearchParams({ action: 'setup' }) });
        const data = await resp.json();
        if (!data.success) { showToast(data.error || 'Erro ao iniciar 2FA', 'error'); return; }
        document.getElementById('twoFaQr').src = data.qr_image;
        document.getElementById('twoFaSecret').value = data.secret;
        document.getElementById('twoFaSetup').style.display = 'block';
        document.getElementById('twoFaIdle').style.display = 'none';
    }

    function cancel2fa() {
        document.getElementById('twoFaSetup').style.display = 'none';
        document.getElementById('twoFaIdle').style.display = 'block';
    }

    function showRecoveryCodes(codes) {
        const list = document.getElementById('twoFaCodesList');
        list.innerHTML = codes.map(c => '<div class="quota-pill" style="justify-content:center;padding:6px 10px;font-size:.85rem;">' + c + '</div>').join('');
        document.getElementById('twoFaCodes').style.display = 'block';
    }

    async function confirm2fa() {
        const code = document.getElementById('twoFaCode').value.trim();
        if (!code) { showToast('Digite o código de 6 dígitos', 'warning'); return; }
        const resp = await fetch('/admin/api/2fa.php', { method: 'POST', body: new URLSearchParams({ action: 'confirm', code }) });
        const data = await resp.json();
        if (data.success) {
            showToast('2FA ativado com sucesso!', 'success');
            showRecoveryCodes(data.recovery_codes || []);
            load2fa();
        } else {
            showToast(data.error || 'Erro ao ativar', 'error');
        }
    }

    async function disable2fa() {
        const code = document.getElementById('twoFaManageCode').value.trim();
        if (!code) { showToast('Digite um código atual para desativar', 'warning'); return; }
        if (!confirm('Desativar a verificação em duas etapas?')) return;
        const resp = await fetch('/admin/api/2fa.php', { method: 'POST', body: new URLSearchParams({ action: 'disable', code }) });
        const data = await resp.json();
        if (data.success) { showToast('2FA desativado', 'success'); document.getElementById('twoFaCodes').style.display = 'none'; load2fa(); }
        else showToast(data.error || 'Erro ao desativar', 'error');
    }

    async function regenerate2faCodes() {
        const code = document.getElementById('twoFaManageCode').value.trim();
        if (!code) { showToast('Digite um código atual para regerar', 'warning'); return; }
        const resp = await fetch('/admin/api/2fa.php', { method: 'POST', body: new URLSearchParams({ action: 'regenerate', code }) });
        const data = await resp.json();
        if (data.success) { showToast('Novos códigos gerados!', 'success'); showRecoveryCodes(data.recovery_codes || []); }
        else showToast(data.error || 'Erro ao regerar', 'error');
    }

    // Teste de conexao do Steel Browser (self-hosted para o scraping de anuncios).
    // Letmos a URL digitada, nao a salva — o usuario ve o resultado antes de continuar.
    async function testSteel() {
        const btn = document.getElementById('steelTestBtn');
        const out = document.getElementById('steelTestResult');
        const urlInput = document.querySelector('input[name="steel_api_url"]');
        const url = urlInput ? urlInput.value.trim() : '';
        if (!url) { out.textContent = 'Preencha a URL primeiro'; return; }
        btn.disabled = true;
        out.textContent = 'Testando...';
        try {
            const resp = await fetch('/admin/api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=steel-test&url=' + encodeURIComponent(url)
            });
            const data = await resp.json();
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            out.innerHTML = data.ok
                ? '<span style="color:var(--success);">OK — ' + esc(data.message || 'conectado') + '</span>'
                : '<span style="color:var(--danger);">' + esc(data.error || 'Falhou') + '</span>';
        } catch (e) {
            out.textContent = 'Erro de conexão (' + (e.message || e.name || 'desconhecido') + ')';
        }
        btn.disabled = false;
    }

    load2fa();
    </script>
</body>
</html>
