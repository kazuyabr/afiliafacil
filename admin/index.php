<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';

Auth::requireAuth();

$pm = new PageManager();
$stats = $pm->getStats();
$user = Auth::user();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AfiliaFacil</title>
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
                <div class="topbar-title">Dashboard</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()" title="Alternar tema">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Olá, <?= htmlspecialchars($user['name']) ?> 👋</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;">Aqui está o resumo da sua conta</p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-value"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total de Páginas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value"><?= $stats['active'] ?></div>
                        <div class="stat-label">Ativas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-eye"></i></div>
                        <div class="stat-value"><?= number_format($stats['total_views']) ?></div>
                        <div class="stat-label">Total de Views</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-clone"></i></div>
                        <div class="stat-value"><?= $stats['draft'] ?></div>
                        <div class="stat-label">Rascunhos</div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-rocket"></i> Ações Rápidas</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid-4" style="gap:12px;">
                            <a href="/admin/clone.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#e7f1ff;color:#0d6efd;"><i class="fas fa-clone"></i></div>
                                <h3>Clonar Página</h3>
                                <p>Cole uma URL e gere uma cópia completa</p>
                            </a>
                            <a href="/admin/pages.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#d1e7dd;color:#198754;"><i class="fas fa-plus-circle"></i></div>
                                <h3>Criar Página</h3>
                                <p>Use templates prontos ou crie do zero</p>
                            </a>
                            <a href="/admin/pressel.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#e8daff;color:#6f42c1;"><i class="fas fa-steam"></i></div>
                                <h3>Pressel</h3>
                                <p>Gere pressels prontas em segundos</p>
                            </a>
                            <a href="/admin/domains.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#fff3cd;color:#fd7e14;"><i class="fas fa-globe"></i></div>
                                <h3>Domínios</h3>
                                <p>Configure domínios próprios</p>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-clock"></i> Páginas Recentes</h3>
                            <a href="/admin/pages.php" class="btn btn-sm btn-outline">Ver todas</a>
                        </div>
                        <div class="card-body">
                            <?php
                            $pages = $pm->list();
                            $recent = array_slice(array_reverse($pages), 0, 5);
                            if (empty($recent)):
                            ?>
                            <div class="empty-state">
                                <i class="fas fa-file-plus"></i>
                                <h3>Nenhuma página ainda</h3>
                                <p>Comece clonando uma página ou criando uma nova.</p>
                                <a href="/admin/clone.php" class="btn btn-primary btn-sm"><i class="fas fa-clone"></i> Clonar agora</a>
                            </div>
                            <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Tipo</th>
                                            <th>Status</th>
                                            <th>Views</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $p): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                            <td><span style="font-size:.8rem;color:var(--text-secondary);"><?= $p['type'] ?></span></td>
                                            <td><span class="status status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                                            <td><?= number_format($p['views']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-tools"></i> Ferramentas</h3>
                        </div>
                        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
                            <a href="/admin/pixel.php" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);transition:all .2s;text-decoration:none;color:var(--text-primary);" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border-color)'">
                                <div class="stat-icon blue" style="width:40px;height:40px;font-size:1rem;margin:0;"><i class="fas fa-chart-line"></i></div>
                                <div><strong style="font-size:.9rem;">Rastreamento (Pixel)</strong><br><span style="font-size:.8rem;color:var(--text-secondary);">Facebook, Google ADS, TikTok</span></div>
                            </a>
                            <a href="/admin/video.php" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);transition:all .2s;text-decoration:none;color:var(--text-primary);" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border-color)'">
                                <div class="stat-icon purple" style="width:40px;height:40px;font-size:1rem;margin:0;"><i class="fas fa-play-circle"></i></div>
                                <div><strong style="font-size:.9rem;">Player de Vídeo</strong><br><span style="font-size:.8rem;color:var(--text-secondary);">Fakebar, delay, autoplay</span></div>
                            </a>
                            <a href="/admin/integrations.php" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);transition:all .2s;text-decoration:none;color:var(--text-primary);" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border-color)'">
                                <div class="stat-icon green" style="width:40px;height:40px;font-size:1rem;margin:0;"><i class="fas fa-plug"></i></div>
                                <div><strong style="font-size:.9rem;">Integrações</strong><br><span style="font-size:.8rem;color:var(--text-secondary);">ManyChat, Mailchimp, Zapier</span></div>
                            </a>
                            <a href="/admin/settings.php" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);transition:all .2s;text-decoration:none;color:var(--text-primary);" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border-color)'">
                                <div class="stat-icon orange" style="width:40px;height:40px;font-size:1rem;margin:0;"><i class="fas fa-cog"></i></div>
                                <div><strong style="font-size:.9rem;">Configurações</strong><br><span style="font-size:.8rem;color:var(--text-secondary);">Conta, tema, preferências</span></div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
