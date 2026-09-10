<?php $currentPage = basename($_SERVER['SCRIPT_NAME']); ?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo"><i class="fas fa-bolt"></i></div>
        <span>AfiliaFacil</span>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Principal</div>
        <a href="/admin/" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>

        <div class="nav-section">Minhas Páginas</div>
        <a href="/admin/pages.php" class="nav-item <?= $currentPage === 'pages.php' ? 'active' : '' ?>">
            <i class="fas fa-file-alt"></i> Todas as Páginas
        </a>
        <a href="/admin/clone.php" class="nav-item <?= $currentPage === 'clone.php' ? 'active' : '' ?>">
            <i class="fas fa-clone"></i> Clonador
        </a>
        <a href="/admin/pressel.php" class="nav-item <?= $currentPage === 'pressel.php' ? 'active' : '' ?>">
            <i class="fas fa-steam"></i> Pressel
        </a>

        <div class="nav-section">Ferramentas</div>
        <a href="/admin/video.php" class="nav-item <?= $currentPage === 'video.php' ? 'active' : '' ?>">
            <i class="fas fa-play-circle"></i> Player de Vídeo
        </a>
        <a href="/admin/pixel.php" class="nav-item <?= $currentPage === 'pixel.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i> Rastreamento
        </a>
        <a href="/admin/backredirect.php" class="nav-item <?= $currentPage === 'backredirect.php' ? 'active' : '' ?>">
            <i class="fas fa-undo"></i> Back Redirect
        </a>
        <a href="/admin/cookie.php" class="nav-item <?= $currentPage === 'cookie.php' ? 'active' : '' ?>">
            <i class="fas fa-cookie-bite"></i> Cookie
        </a>

        <div class="nav-section">Infraestrutura</div>
        <a href="/admin/domains.php" class="nav-item <?= $currentPage === 'domains.php' ? 'active' : '' ?>">
            <i class="fas fa-globe"></i> Domínios
        </a>
        <a href="/admin/integrations.php" class="nav-item <?= $currentPage === 'integrations.php' ? 'active' : '' ?>">
            <i class="fas fa-plug"></i> Integrações
        </a>

        <?php if (Auth::can('manage_users') || Auth::can('manage_roles') || Auth::can('manage_pricing') || Auth::can('manage_payments')): ?>
        <div class="nav-section">Administração</div>
        <?php if (Auth::can('manage_users')): ?>
        <a href="/admin/users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> Usuários
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_roles')): ?>
        <a href="/admin/roles.php" class="nav-item <?= $currentPage === 'roles.php' ? 'active' : '' ?>">
            <i class="fas fa-user-shield"></i> Cargos
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_pricing')): ?>
        <a href="/admin/pricing.php" class="nav-item <?= $currentPage === 'pricing.php' ? 'active' : '' ?>">
            <i class="fas fa-tags"></i> Preços
        </a>
        <?php endif; ?>
        <?php if (Auth::can('manage_payments')): ?>
        <a href="/admin/pay.php" class="nav-item <?= $currentPage === 'pay.php' ? 'active' : '' ?>">
            <i class="fas fa-hand-holding-usd"></i> Pagamentos
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <div class="nav-section">Conta</div>
        <a href="/admin/plan.php" class="nav-item <?= $currentPage === 'plan.php' ? 'active' : '' ?>">
            <i class="fas fa-rocket"></i> Meu Plano
        </a>
        <a href="/admin/settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i> Configurações
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuário') ?></div>
                <div class="user-plan"><?= ucfirst($_SESSION['user_plan'] ?? 'free') ?></div>
            </div>
            <a href="/admin/logout.php" style="margin-left:auto;color:var(--text-sidebar);" title="Sair"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</aside>
