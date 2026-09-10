<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/ImportJsonData.php';

Auth::requireAuth();
if (!Auth::can('manage_roles')) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$permissionLabels = [
    'manage_users' => 'Gerenciar usuários',
    'manage_roles' => 'Gerenciar cargos',
    'manage_pricing' => 'Gerenciar preços/planos',
    'manage_pages' => 'Gerenciar páginas',
    'manage_settings' => 'Gerenciar configurações',
    'manage_payments' => 'Gerenciar pagamentos',
    'manage_storage' => 'Gerenciar armazenamento (R2)',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargos e Permissões - AfiliaFacil</title>
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
                <div class="topbar-title">Cargos e Permissões</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Cargos e Permissões</h1>
                    <button class="btn btn-primary" onclick="openCreate()"><i class="fas fa-plus"></i> Novo Cargo</button>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="table" id="rolesTable">
                                <thead>
                                    <tr>
                                        <th>Cargo</th>
                                        <th>Identificador</th>
                                        <th>Permissões</th>
                                        <th>Usuários</th>
                                        <th style="text-align:right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="rolesBody">
                                    <tr><td colspan="5" style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="roleModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="roleModalTitle">Novo Cargo</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="roleId">
                <div class="grid-2">
                    <div class="form-group">
                        <label>Identificador (minúsculo)</label>
                        <input type="text" id="roleName" class="form-control" placeholder="ex: suporte">
                    </div>
                    <div class="form-group">
                        <label>Nome de exibição</label>
                        <input type="text" id="roleLabel" class="form-control" placeholder="ex: Suporte">
                    </div>
                </div>
                <div class="form-group">
                    <label>Permissões</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <?php foreach ($permissionLabels as $key => $label): ?>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;">
                            <input type="checkbox" class="perm-check" value="<?= $key ?>"> <?= htmlspecialchars($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="saveRole()"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let rolesCache = [];

    async function loadRoles() {
        const resp = await fetch('/admin/api/roles.php?action=list');
        const data = await resp.json();
        if (!data.success) { document.getElementById('rolesBody').innerHTML = '<tr><td colspan="5">' + (data.error || 'Erro') + '</td></tr>'; return; }
        rolesCache = data.roles;
        renderRoles();
    }

    function renderRoles() {
        const body = document.getElementById('rolesBody');
        body.innerHTML = rolesCache.map(r => `
            <tr>
                <td><strong>${esc(r.label)}</strong>${r.is_system ? ' <small style="color:var(--text-secondary);">(sistema)</small>' : ''}</td>
                <td><code style="font-size:.75rem;">${esc(r.name)}</code></td>
                <td><small>${r.permissions.length ? r.permissions.map(esc).join(', ') : '—'}</small></td>
                <td>${r.users_count}</td>
                <td style="text-align:right;">
                    ${r.name === 'master' ? '<span style="font-size:.75rem;color:var(--text-secondary);">protegido</span>' : `
                    <button class="btn btn-sm btn-outline" onclick="openEdit(${r.id})" title="Editar"><i class="fas fa-pen"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="deleteRole(${r.id})" title="Excluir" ${r.is_system ? 'disabled' : ''}><i class="fas fa-trash"></i></button>`}
                </td>
            </tr>
        `).join('');
    }

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function openCreate() {
        document.getElementById('roleModalTitle').textContent = 'Novo Cargo';
        document.getElementById('roleId').value = '';
        document.getElementById('roleName').value = '';
        document.getElementById('roleName').disabled = false;
        document.getElementById('roleLabel').value = '';
        document.querySelectorAll('.perm-check').forEach(c => c.checked = false);
        document.getElementById('roleModal').classList.add('active');
    }

    function openEdit(id) {
        const r = rolesCache.find(x => x.id === id);
        if (!r) return;
        document.getElementById('roleModalTitle').textContent = 'Editar Cargo';
        document.getElementById('roleId').value = r.id;
        document.getElementById('roleName').value = r.name;
        document.getElementById('roleName').disabled = true;
        document.getElementById('roleLabel').value = r.label;
        document.querySelectorAll('.perm-check').forEach(c => c.checked = r.permissions.includes(c.value));
        document.getElementById('roleModal').classList.add('active');
    }

    function closeModal() { document.getElementById('roleModal').classList.remove('active'); }

    async function saveRole() {
        const id = document.getElementById('roleId').value;
        const body = new URLSearchParams();
        body.append('action', id ? 'update' : 'create');
        if (id) body.append('id', id);
        body.append('name', document.getElementById('roleName').value);
        body.append('label', document.getElementById('roleLabel').value);
        document.querySelectorAll('.perm-check:checked').forEach(c => body.append('permissions[]', c.value));

        const resp = await fetch('/admin/api/roles.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { closeModal(); showToast('Cargo salvo!', 'success'); loadRoles(); }
        else showToast(data.error || 'Erro ao salvar', 'error');
    }

    async function deleteRole(id) {
        if (!confirm('Excluir este cargo?')) return;
        const body = new URLSearchParams({ action: 'delete', id });
        const resp = await fetch('/admin/api/roles.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Cargo excluído', 'success'); loadRoles(); }
        else showToast(data.error || 'Erro', 'error');
    }

    loadRoles();
    </script>
</body>
</html>
