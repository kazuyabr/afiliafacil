<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();

$pm = new PageManager();
$pages = $pm->list();
$theme = $_SESSION['theme'] ?? 'light';

$action = $_GET['action'] ?? '';
$editPage = null;

if ($action === 'edit') {
    $editPage = $pm->get((int)($_GET['id'] ?? 0));
    if (!$editPage) {
        header('Location: /admin/pages.php');
        exit;
    }
}

if ($action === 'new') {
    header('Location: /admin/clone.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editPage ? 'Editar Página' : 'Minhas Páginas' ?> - AfiliaFacil</title>
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
                <div class="topbar-title"><?= $editPage ? 'Editar Página' : 'Minhas Páginas' ?></div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()" title="Alternar tema">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">

                <?php if ($editPage): ?>

                    <div class="page-header">
                        <div>
                            <h1>Editar — <?= htmlspecialchars($editPage['name']) ?></h1>
                            <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">
                                <i class="fas fa-clone"></i> <?= htmlspecialchars($editPage['source_domain']) ?>
                                <?php if ($editPage['affiliate_link']): ?> · <i class="fas fa-link"></i> <?= htmlspecialchars($editPage['affiliate_link']) ?><?php endif; ?>
                            </p>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <a href="/admin/pages.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
                            <a href="/admin/preview.php?id=<?= $editPage['id'] ?>" target="_blank" class="btn btn-outline"><i class="fas fa-eye"></i> Preview</a>
                            <a href="/admin/download.php?id=<?= $editPage['id'] ?>" class="btn btn-outline"><i class="fas fa-download"></i> ZIP</a>
                        </div>
                    </div>

                    <?php
                    $failedAssets = $editPage['failed_assets'] ?? [];
                    if (!empty($failedAssets)):
                    ?>
                    <div class="alert alert-warning" style="margin-bottom:24px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                            <div>
                                <strong><i class="fas fa-exclamation-triangle"></i> <?= count($failedAssets) ?> mídia(s) não puderam ser copiadas na clonagem</strong>
                                <p style="margin-top:4px;font-size:.85rem;">Elas continuam apontando para o site original (podem quebrar se a origem sair do ar). Verifique os links abaixo:</p>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('failedAssetsList').style.display = document.getElementById('failedAssetsList').style.display === 'none' ? 'block' : 'none'">
                                <i class="fas fa-list"></i> Ver lista
                            </button>
                        </div>
                        <div id="failedAssetsList" style="display:none;margin-top:12px;max-height:200px;overflow-y:auto;">
                            <?php foreach ($failedAssets as $fa): ?>
                            <div style="font-family:monospace;font-size:.72rem;word-break:break-all;padding:4px 0;border-bottom:1px solid rgba(0,0,0,.08);"><?= htmlspecialchars($fa) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card" style="margin-bottom:24px;">
                        <div class="card-header"><h3><i class="fas fa-cog"></i> Informações da página</h3></div>
                        <div class="card-body">
                            <form id="editPageForm">
                                <input type="hidden" name="id" value="<?= $editPage['id'] ?>">
                                <div class="grid-2">
                                    <div class="form-group">
                                        <label>Nome da página</label>
                                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editPage['name']) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="status" class="form-control">
                                            <option value="active" <?= $editPage['status'] === 'active' ? 'selected' : '' ?>>Ativa</option>
                                            <option value="draft" <?= $editPage['status'] === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid-2">
                                    <div class="form-group">
                                        <label>Domínio (onde está publicada)</label>
                                        <input type="text" name="domain" class="form-control" placeholder="seusite.com.br" value="<?= htmlspecialchars($editPage['domain'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Link de afiliado (CTA)</label>
                                        <input type="url" name="affiliate_link" class="form-control" placeholder="https://go.hotmart.com/..." value="<?= htmlspecialchars($editPage['affiliate_link'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>URL pública</label>
                                    <input type="text" class="form-control" disabled value="<?= htmlspecialchars(($editPage['domain'] ? 'https://' . $editPage['domain'] . '/' : '/admin/preview.php?id=') . ($editPage['domain'] ? $editPage['slug'] : $editPage['id'])) ?>">
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar alterações</button>
                                    <?php if (Plans::hasFeature(Auth::user()['plan'], 'editor')): ?>
                                    <a href="/admin/editor.php?id=<?= $editPage['id'] ?>" class="btn btn-success"><i class="fas fa-code"></i> Editar Código Online</a>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-success" onclick="showToast('Editor disponível nos planos Essencial e Master', 'warning')"><i class="fas fa-code"></i> Editar Código Online</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-undo"></i> Histórico de revisões (código)</h3></div>
                        <div class="card-body">
                            <?php
                            $revDir = Config::getPagesDir() . '/' . $editPage['id'] . '/revisions';
                            $revisions = [];
                            if (is_dir($revDir)) {
                                $revisions = array_reverse(glob($revDir . '/*.html'));
                            }
                            if (empty($revisions)): ?>
                            <div class="empty-state" style="padding:24px;">
                                <p style="margin:0;">Nenhuma revisão ainda — aparecerão aqui quando você salvar no editor de código.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead><tr><th>Revisão</th><th>Data</th><th style="text-align:right;">Ações</th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($revisions, 0, 10) as $rev): ?>
                                        <tr>
                                            <td><small style="font-family:monospace;"><?= basename($rev) ?></small></td>
                                            <td><small><?= date('d/m/Y H:i:s', filemtime($rev)) ?></small></td>
                                            <td style="text-align:right;">
                                                <button class="btn btn-sm btn-outline" onclick="restoreRevision(<?= $editPage['id'] ?>, '<?= basename($rev) ?>')"><i class="fas fa-undo"></i> Restaurar</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>

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
                                                    <a href="/admin/preview.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline" title="Preview"><i class="fas fa-eye"></i></a>
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

                <?php endif; ?>
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
    const editForm = document.getElementById('editPageForm');
    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            const btn = f.querySelector('button[type=submit]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            try {
                const resp = await fetch('/admin/api/pages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=update&id=' + encodeURIComponent(f.id.value) +
                        '&name=' + encodeURIComponent(f.name.value) +
                        '&status=' + encodeURIComponent(f.status.value) +
                        '&domain=' + encodeURIComponent(f.domain.value) +
                        '&affiliate_link=' + encodeURIComponent(f.affiliate_link.value)
                });
                const data = await resp.json();
                if (data.success) {
                    showToast('Página salva com sucesso!', 'success');
                } else {
                    showToast(data.error || 'Erro ao salvar', 'error');
                }
            } catch (err) {
                showToast('Erro de conexão: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Salvar alterações';
            }
        });
    }
    function restoreRevision(id, rev) {
        if (!confirm('Restaurar esta revisão? O HTML atual será sobrescrito (uma nova revisão será criada antes).')) return;
        fetch('/admin/api/editor.php?action=restore&id=' + id + '&rev=' + encodeURIComponent(rev))
            .then(r => r.json())
            .then(d => { if (d.success) { showToast('Revisão restaurada!', 'success'); setTimeout(() => location.reload(), 1200); } else alert(d.error || 'Erro ao restaurar'); });
    }
    </script>
</body>
</html>
