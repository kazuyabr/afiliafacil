<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Audit.php';

use AfiliaFacil\Models\User;
use AfiliaFacil\Models\Role;

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Auth::can('manage_users')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para gerenciar usuários']);
    exit;
}

if (!Database::available()) {
    http_response_code(500);
    echo json_encode(['error' => 'Banco de dados indisponível']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$actor = Auth::user();
$actorIsAdmin = Auth::isAdmin();

function isAdminRoleId(int $roleId): bool
{
    if ($roleId <= 0) return false;
    $role = Role::find($roleId);
    return $role && in_array($role->name, ['master', 'admin'], true);
}

switch ($action) {
    case 'list':
        $users = User::with('role')->orderBy('created_at', 'asc')->get()->map(function ($u) {
            return [
                'id' => (int)$u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role_id' => $u->role_id,
                'role_name' => $u->role->name ?? null,
                'role_label' => $u->role->label ?? '—',
                'is_admin' => in_array($u->role->name ?? '', ['master', 'admin'], true),
                'plan' => $u->plan,
                'active' => (bool)$u->active,
                'created_at' => (string)$u->created_at,
            ];
        });
        echo json_encode(['success' => true, 'users' => $users, 'actor_is_admin' => $actorIsAdmin]);
        break;

    case 'create':
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 0);
        $plan = $_POST['plan'] ?? 'trial';

        if ($name === '' || $email === '' || strlen($password) < 6) {
            echo json_encode(['error' => 'Nome, e-mail e senha (mín. 6) são obrigatórios']);
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_URL) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'E-mail inválido']);
            break;
        }
        if (User::where('email', $email)->exists()) {
            echo json_encode(['error' => 'E-mail já cadastrado']);
            break;
        }
        if ($roleId && !Role::find($roleId)) {
            echo json_encode(['error' => 'Cargo inválido']);
            break;
        }
        if (isAdminRoleId($roleId) && !$actorIsAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas administradores podem criar contas de administrador']);
            break;
        }
        if (isAdminRoleId($roleId)) {
            $plan = 'premium';
        }

        $user = User::create([
            'id' => time() + random_int(1, 9999),
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $roleId ?: null,
            'plan' => $plan,
            'active' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('user_created', 'user', (string)$user->id, ['email' => $email, 'role_id' => $roleId, 'plan' => $plan]);
        echo json_encode(['success' => true, 'id' => $user->id]);
        break;

    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $user = User::with('role')->find($id);
        if (!$user) {
            echo json_encode(['error' => 'Usuário não encontrado']);
            break;
        }

        $targetIsMaster = $user->isMaster();
        $targetIsAdmin = in_array($user->role->name ?? '', ['master', 'admin'], true);

        if ($targetIsMaster && $id !== (int)$actor['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'A conta Master só pode ser editada por ela mesma']);
            break;
        }
        if ($targetIsAdmin && !$actorIsAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas administradores podem editar contas de administrador']);
            break;
        }

        $name = trim($_POST['name'] ?? $user->name);
        $email = strtolower(trim($_POST['email'] ?? $user->email));
        $roleId = (int)($_POST['role_id'] ?? $user->role_id);
        $plan = $_POST['plan'] ?? $user->plan;
        $password = $_POST['password'] ?? '';

        if ($targetIsMaster) {
            if ($roleId !== $user->role_id) {
                http_response_code(403);
                echo json_encode(['error' => 'O cargo da conta Master não pode ser alterado']);
                break;
            }
        }

        if (isAdminRoleId($roleId) && !$actorIsAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas administradores podem atribuir cargos de administrador']);
            break;
        }

        if (isAdminRoleId($roleId)) {
            $plan = 'premium';
        } elseif ($targetIsAdmin && !isAdminRoleId($roleId) && $plan === 'premium') {
            $plan = 'trial';
        }

        if ($email !== $user->email && User::where('email', $email)->where('id', '!=', $id)->exists()) {
            echo json_encode(['error' => 'E-mail já cadastrado']);
            break;
        }

        $user->name = $name;
        $user->email = $email;
        $user->role_id = $roleId ?: null;
        $user->plan = $plan;
        $user->updated_at = date('Y-m-d H:i:s');
        if ($password !== '') {
            if (strlen($password) < 6) {
                echo json_encode(['error' => 'Senha deve ter no mínimo 6 caracteres']);
                break;
            }
            $user->password = password_hash($password, PASSWORD_DEFAULT);
        }
        $user->save();

        Audit::log('user_updated', 'user', (string)$id, ['email' => $email, 'role_id' => $roleId, 'plan' => $plan]);
        echo json_encode(['success' => true]);
        break;

    case 'toggle':
        $id = (int)($_POST['id'] ?? 0);
        $user = User::with('role')->find($id);
        if (!$user) {
            echo json_encode(['error' => 'Usuário não encontrado']);
            break;
        }
        if ($user->isMaster()) {
            http_response_code(403);
            echo json_encode(['error' => 'A conta Master não pode ser desativada']);
            break;
        }
        if (in_array($user->role->name ?? '', ['master', 'admin'], true) && !$actorIsAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas administradores podem gerenciar contas de administrador']);
            break;
        }
        $user->active = !$user->active;
        $user->save();
        Audit::log($user->active ? 'user_activated' : 'user_deactivated', 'user', (string)$id);
        echo json_encode(['success' => true, 'active' => (bool)$user->active]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $user = User::with('role')->find($id);
        if (!$user) {
            echo json_encode(['error' => 'Usuário não encontrado']);
            break;
        }
        if ($user->isMaster()) {
            http_response_code(403);
            echo json_encode(['error' => 'A conta Master não pode ser excluída']);
            break;
        }
        if (in_array($user->role->name ?? '', ['master', 'admin'], true) && !$actorIsAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Apenas administradores podem excluir contas de administrador']);
            break;
        }
        if ($id === (int)$actor['id']) {
            echo json_encode(['error' => 'Você não pode excluir sua própria conta']);
            break;
        }
        Audit::log('user_deleted', 'user', (string)$id, ['email' => $user->email]);
        $user->delete();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
