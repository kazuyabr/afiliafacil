<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';

Auth::requireAuth();

$pm = new PageManager();
$pages = $pm->list();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Páginas - AfiliaFacil</title>
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
                <div class="topbar-title">Minhas Páginas</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()" title="Alternar tema">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Minhas Páginas</h1>
                    <div style="display:flex;gap:8px;">
                        <a href="/admin/clone.php" class="btn btn-primary"><i class="fas fa-clone"></i> Clonar</a>
                        <a href="/admin/pages.php?action=new" class="btn btn-outline"><i class="fas fa-plus"></i> Nova Página</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-sm btn-outline filter-btn active" data-filter="all">Todas</button>
                            <button class="btn btn-sm btn-outline filter-btn" data-filter="active">Ativas</button>
                            <button class="btn btn-sm btn-outline filter-btn" data-filter="draft">Rascunhos</button>
                            <button class="btn btn-sm btn-outline filter-btn" data-filter="clone">Clones</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pages)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-plus"></i>
                            <h3>Nenhuma página criada</h3>
                            <p>Comece clonando uma página existente ou criando uma nova do zero.</p>
                            <a href="/admin/clone.php" class="btn btn-primary"><i class="fas fa-clone"></i> Clonar página agora</a>
                        </div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table" id="pagesTable">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Tipo</th>
                                        <th>Domínio</th>
                                        <th>Status</th>
                                        <th>Views</th>
                                        <th>Criada em</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($pages) as $p): ?>
                                    <tr data-status="<?= $p['status'] ?>" data-type="<?= $p['type'] ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($p['name']) ?></strong>
                                            <?php if ($p['source_domain']): ?>
                                            <br><small style="color:var(--text-secondary);"><?= htmlspecialchars($p['source_domain']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span style="font-size:.8rem;background:var(--bg-secondary);padding:3px 8px;border-radius:4px;"><?= $p['type'] ?></span></td>
                                        <td><small><?= $p['domain'] ?: '<span style="color:var(--text-secondary);">—</span>' ?></small></td>
                                        <td><span class="status status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                                        <td><?= number_format($p['views']) ?></td>
                                        <td><small style="color:var(--text-secondary);"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small></td>
                                        <td>
                                            <div style="display:flex;gap:4px;">
                                                <?php if ($p['domain']): ?>
                                                <a href="https://<?= htmlspecialchars($p['domain']) ?>/<?= $p['slug'] ?>" target="_blank" class="btn btn-sm btn-outline" title="Abrir"><i class="fas fa-external-link-alt"></i></a>
                                                <?php endif; ?>
                                                <a href="/admin/preview.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline" title="Preview"><i class="fas fa-eye"></i></a>
                                                <a href="/admin/pages.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline" title="Editar"><i class="fas fa-pen"></i></a>
                                                <a href="/admin/download.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline" title="Baixar ZIP"><i class="fas fa-download"></i></a>
                                                <button class="btn btn-sm btn-danger" onclick="deletePage(<?= $p['id'] ?>)" title="Excluir"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
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
    <script>
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const filter = btn.dataset.filter;
            document.querySelectorAll('#pagesTable tbody tr').forEach(row => {
                if (filter === 'all') { row.style.display = ''; return; }
                const match = row.dataset.status === filter || row.dataset.type === filter;
                row.style.display = match ? '' : 'none';
            });
        });
    });
    function deletePage(id) {
        if (!confirm('Tem certeza que deseja excluir esta página?')) return;
        fetch('/admin/api/pages.php?action=delete&id=' + id, { method: 'POST' })
            .then(r => r.json())
            .then(d => { if (d.success) location.reload(); else alert(d.error || 'Erro ao excluir'); });
    }
    </script>
</body>
</html>
