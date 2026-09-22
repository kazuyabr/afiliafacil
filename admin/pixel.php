<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
$user = Auth::user();
$pm = new PageManager();
$pages = Auth::isAdmin() ? $pm->list() : $pm->listByUser((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastreamento (Pixel) - AfiliaFacil</title>
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
                <div class="topbar-title">Rastreamento de Tráfego</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header"><h1>Rastreamento (Pixel)</h1></div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> O <strong>pixel do navegador</strong> perde 20–40% das conversões (iOS, ad blockers). A <strong>API de Conversão (CAPI)</strong> envia server-side com o mesmo ID de evento (deduplicação automática). Configure por página — clique em <strong>Editar</strong>.
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Pixels por página</h3></div>
                    <div class="card-body">
                        <?php if (empty($pages)): ?>
                        <div class="empty-state" style="padding:24px;">
                            <i class="fas fa-file-plus"></i>
                            <h3>Nenhuma página ainda</h3>
                            <p>Clone ou crie uma página para configurar o rastreamento.</p>
                            <a href="/admin/clone.php" class="btn btn-primary btn-sm"><i class="fas fa-clone"></i> Clonar agora</a>
                        </div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead><tr><th>Página</th><th>URL pública</th><th>Views</th><th>Meta Pixel</th><th>CAPI</th><th>Google</th><th>TikTok</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($pages as $p): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($p['name']) ?></strong><br><small style="color:var(--text-secondary);"><?= htmlspecialchars($p['status']) ?></small></td>
                                        <td><a href="/p/<?= htmlspecialchars($p['slug']) ?>" target="_blank" style="font-size:.78rem;">/p/<?= htmlspecialchars($p['slug']) ?></a></td>
                                        <td><?= number_format($p['views'] ?? 0) ?></td>
                                        <td><?= !empty($p['meta_pixel_id']) ? '<span class="status status-active">' . htmlspecialchars($p['meta_pixel_id']) . '</span>' : '<span style="color:var(--text-secondary);">—</span>' ?></td>
                                        <td><?= !empty($p['meta_capi_configured']) ? '<span class="status status-active">ativa</span>' : '<span style="color:var(--text-secondary);">—</span>' ?></td>
                                        <td><?= !empty($p['google_conversion_id']) ? '<span class="status status-active">ok</span>' : '<span style="color:var(--text-secondary);">—</span>' ?></td>
                                        <td><?= !empty($p['tiktok_pixel_id']) ? '<span class="status status-active">ok</span>' : '<span style="color:var(--text-secondary);">—</span>' ?></td>
                                        <td><a href="/admin/pages.php?action=edit&id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline">Editar</a></td>
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
