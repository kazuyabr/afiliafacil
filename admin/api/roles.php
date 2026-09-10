<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/ImportJsonData.php';
require_once Config::getLibDir() . '/Audit.php';

use AfiliaFacil\Models\Role;

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Auth::can('manage_roles')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para gerenciar cargos']);
    exit;
}

if (!Database::available()) {
    http_response_code(500);
    echo json_encode(['error' => 'Banco de dados indisponível']);
    exit;
}

$permissions = ImportJsonData::PERMISSIONS;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        $roles = Role::withCount('users')->orderBy('id', 'asc')->get()->map(function ($r) {
            return [
                'id' => (int)$r->id,
                'name' => $r->name,
                'label' => $r->label,
                'permissions' => $r->permissions ?? [],
                'is_system' => (bool)$r->is_system,
                'users_count' => (int)($r->users_count ?? 0),
            ];
        });
        echo json_encode(['success' => true, 'roles' => $roles, 'permissions' => $permissions]);
        break;

    case 'create':
        $name = strtolower(trim($_POST['name'] ?? ''));
        $label = trim($_POST['label'] ?? '');
        $perms = $_POST['permissions'] ?? [];
        if (is_string($perms)) $perms = array_filter(explode(',', $perms));

        if (!preg_match('/^[a-z0-9_-]{2,60}$/', $name)) {
            echo json_encode(['error' => 'Identificador inválido (use letras minúsculas, números, _ ou -)']);
            break;
        }
        if ($label === '') {
            echo json_encode(['error' => 'Nome de exibição é obrigatório']);
            break;
        }
        if (Role::where('name', $name)->exists()) {
            echo json_encode(['error' => 'Já existe um cargo com esse identificador']);
            break;
        }

        $perms = array_values(array_intersect($perms, $permissions));
        $role = Role::create([
            'name' => $name,
            'label' => $label,
            'permissions' => $perms,
            'is_system' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('role_created', 'role', (string)$role->id, ['name' => $name]);
        echo json_encode(['success' => true, 'id' => $role->id]);
        break;

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $role = Role::find($id);
        if (!$role) {
            echo json_encode(['error' => 'Cargo não encontrado']);
            break;
        }
        if ($role->name === 'master') {
            echo json_encode(['error' => 'O cargo Master não pode ser alterado']);
            break;
        }

        $label = trim($_POST['label'] ?? $role->label);
        $perms = $_POST['permissions'] ?? [];
        if (is_string($perms)) $perms = array_filter(explode(',', $perms));

        $role->label = $label;
        $role->permissions = array_values(array_intersect($perms, $permissions));
        $role->updated_at = date('Y-m-d H:i:s');
        $role->save();

        Audit::log('role_updated', 'role', (string)$id);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $role = Role::withCount('users')->find($id);
        if (!$role) {
            echo json_encode(['error' => 'Cargo não encontrado']);
            break;
        }
        if ($role->name === 'master' || $role->is_system) {
            echo json_encode(['error' => 'Cargos de sistema não podem ser excluídos']);
            break;
        }
        if (($role->users_count ?? 0) > 0) {
            echo json_encode(['error' => 'Existem usuários com este cargo. Reatribua-os antes de excluir.']);
            break;
        }
        Audit::log('role_deleted', 'role', (string)$id, ['name' => $role->name]);
        $role->delete();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
