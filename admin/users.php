<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();
if (!Auth::can('manage_users')) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$roles = Database::available() ? \AfiliaFacil\Models\Role::orderBy('id')->get() : collect();
$plans = Plans::all();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários - AfiliaFacil</title>
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
                <div class="topbar-title">Usuários</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Usuários</h1>
                    <button class="btn btn-primary" onclick="openCreate()"><i class="fas fa-plus"></i> Novo Usuário</button>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>E-mail</th>
                                        <th>Cargo</th>
                                        <th>Plano</th>
                                        <th>Status</th>
                                        <th>Criado em</th>
                                        <th style="text-align:right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="usersBody">
                                    <tr><td colspan="7" style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Novo Usuário</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="userId">
                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" id="userName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" id="userEmail" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Senha <small id="passHint" style="color:var(--text-secondary);">(mín. 6 caracteres)</small></label>
                    <input type="password" id="userPassword" class="form-control" placeholder="Deixe vazio para manter">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Cargo</label>
                        <select id="userRole" class="form-control">
                            <?php foreach ($roles as $role): ?>
                            <option value="<?= $role->id ?>"><?= htmlspecialchars($role->label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Plano</label>
                        <select id="userPlan" class="form-control">
                            <?php foreach ($plans as $id => $plan): ?>
                            <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($plan['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="saveUser()"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let usersCache = [];

    async function loadUsers() {
        const resp = await fetch('/admin/api/users.php?action=list');
        const data = await resp.json();
        if (!data.success) { document.getElementById('usersBody').innerHTML = '<tr><td colspan="7">' + (data.error || 'Erro') + '</td></tr>'; return; }
        usersCache = data.users;
        renderUsers();
    }

    function renderUsers() {
        const body = document.getElementById('usersBody');
        if (!usersCache.length) {
            body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;">Nenhum usuário</td></tr>';
            return;
        }
        body.innerHTML = usersCache.map(u => `
            <tr data-id="${u.id}">
                <td><strong>${escapeHtml(u.name)}</strong></td>
                <td><small>${escapeHtml(u.email)}</small></td>
                <td><span style="font-size:.8rem;background:var(--bg-secondary);padding:3px 8px;border-radius:4px;">${escapeHtml(u.role_label)}</span></td>
                <td>${escapeHtml(u.plan)}</td>
                <td><span class="status status-${u.active ? 'active' : 'expired'}">${u.active ? 'Ativo' : 'Inativo'}</span></td>
                <td><small style="color:var(--text-secondary);">${u.created_at.slice(0, 10)}</small></td>
                <td style="text-align:right;">
                    <button class="btn btn-sm btn-outline" onclick="openEdit(${u.id})" title="Editar"><i class="fas fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline" onclick="toggleUser(${u.id})" title="${u.active ? 'Desativar' : 'Ativar'}"><i class="fas fa-${u.active ? 'ban' : 'check'}"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="deleteUser(${u.id})" title="Excluir"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function openCreate() {
        document.getElementById('modalTitle').textContent = 'Novo Usuário';
        document.getElementById('userId').value = '';
        document.getElementById('userName').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userPassword').value = '';
        document.getElementById('passHint').textContent = '(mín. 6 caracteres)';
        document.getElementById('userModal').classList.add('active');
    }

    function openEdit(id) {
        const u = usersCache.find(x => x.id === id);
        if (!u) return;
        document.getElementById('modalTitle').textContent = 'Editar Usuário';
        document.getElementById('userId').value = u.id;
        document.getElementById('userName').value = u.name;
        document.getElementById('userEmail').value = u.email;
        document.getElementById('userPassword').value = '';
        document.getElementById('passHint').textContent = '(deixe vazio para manter)';
        document.getElementById('userRole').value = u.role_id || '';
        document.getElementById('userPlan').value = u.plan;
        document.getElementById('userModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('userModal').classList.remove('active');
    }

    async function saveUser() {
        const id = document.getElementById('userId').value;
        const body = new URLSearchParams();
        body.append('action', id ? 'update' : 'create');
        if (id) body.append('id', id);
        body.append('name', document.getElementById('userName').value);
        body.append('email', document.getElementById('userEmail').value);
        body.append('password', document.getElementById('userPassword').value);
        body.append('role_id', document.getElementById('userRole').value);
        body.append('plan', document.getElementById('userPlan').value);

        const resp = await fetch('/admin/api/users.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) {
            closeModal();
            showToast(id ? 'Usuário atualizado!' : 'Usuário criado!', 'success');
            loadUsers();
        } else {
            showToast(data.error || 'Erro ao salvar', 'error');
        }
    }

    async function toggleUser(id) {
        const body = new URLSearchParams({ action: 'toggle', id });
        const resp = await fetch('/admin/api/users.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Status atualizado', 'success'); loadUsers(); }
        else showToast(data.error || 'Erro', 'error');
    }

    async function deleteUser(id) {
        if (!confirm('Excluir este usuário?')) return;
        const body = new URLSearchParams({ action: 'delete', id });
        const resp = await fetch('/admin/api/users.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Usuário excluído', 'success'); loadUsers(); }
        else showToast(data.error || 'Erro', 'error');
    }

    loadUsers();
    setInterval(loadUsers, 15000);
    </script>
</body>
</html>
