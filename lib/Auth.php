<?php
session_start();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';

class Auth
{
    private static string $usersFile;
    private static ?array $currentUser = null;

    public static function init(): void
    {
        self::$usersFile = Config::getDataDir() . '/users.json';
        if (!file_exists(self::$usersFile)) {
            self::createDefaultAdmin();
        }
    }

    private static function createDefaultAdmin(): void
    {
        $users = [
            [
                'id' => 1,
                'name' => 'Administrador',
                'email' => 'admin@afiliafacil.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'plan' => 'premium',
                'trial_until' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ];
        file_put_contents(self::$usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function register(string $name, string $email, string $password): ?array
    {
        $email = strtolower(trim($email));

        if (self::emailExists($email)) return null;

        $trialDays = (int)(Settings::get('trial_days', 3));
        $trialUntil = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        $id = time() + random_int(1, 9999);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (Database::available()) {
            $role = \AfiliaFacil\Models\Role::where('name', 'afiliado')->first();
            $user = \AfiliaFacil\Models\User::create([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'role_id' => $role->id ?? null,
                'plan' => 'trial',
                'trial_until' => $trialUntil,
                'active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $userArray = self::userToArray($user);
        } else {
            $users = self::getUsers();
            $userArray = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'plan' => 'trial',
                'trial_until' => $trialUntil,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $users[] = $userArray;
            self::writeUsers($users);
        }

        self::loginUser($userArray);
        return $userArray;
    }

    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));

        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::where('email', $email)->first();
            if (!$user || !$user->active) return false;
            if (!password_verify($password, $user->password)) return false;
            self::loginUser(self::userToArray($user));
            return true;
        }

        foreach (self::getUsers() as $user) {
            if (strtolower($user['email']) === $email && password_verify($password, $user['password'])) {
                self::loginUser($user);
                return true;
            }
        }
        return false;
    }

    private static function userToArray($user): array
    {
        $roleName = null;
        if ($user instanceof \AfiliaFacil\Models\User) {
            $role = $user->role;
            $roleName = $role->name ?? null;
        }

        return [
            'id' => (int)$user->id,
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
            'plan' => $user->plan,
            'trial_until' => $user->trial_until ? (string)$user->trial_until : null,
            'role' => $roleName,
            'role_id' => $user->role_id ?? null,
            'created_at' => (string)$user->created_at,
        ];
    }

    private static function loginUser(array $user): void
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_plan'] = $user['plan'];
        $_SESSION['user_role'] = $user['role'] ?? null;
    }

    public static function syncPlan(): void
    {
        if (!self::check()) return;

        $user = self::getUserById((int)($_SESSION['user_id'] ?? 0));
        if (!$user) return;

        if ($user['plan'] === 'trial' && !empty($user['trial_until'])) {
            if (strtotime($user['trial_until']) < time()) {
                self::setPlan((int)$user['id'], 'trial_expired');
                $user['plan'] = 'trial_expired';
            }
        }

        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_plan'] = $user['plan'];
        $_SESSION['user_role'] = $user['role'] ?? null;
    }

    public static function setPlan(int $userId, string $plan): bool
    {
        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::find($userId);
            if (!$user) return false;
            $user->plan = $plan;
            $user->updated_at = date('Y-m-d H:i:s');
            $user->save();

            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_plan'] = $plan;
            }
            return true;
        }

        $users = self::getUsers();
        $updated = false;
        foreach ($users as &$user) {
            if ($user['id'] === $userId) {
                $user['plan'] = $plan;
                $updated = true;
                break;
            }
        }
        if ($updated) {
            self::writeUsers($users);
            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_plan'] = $plan;
            }
        }
        return $updated;
    }

    public static function logout(): void
    {
        session_destroy();
        header('Location: /login');
        exit;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
        self::syncPlan();
    }

    public static function user(): array
    {
        return [
            'id' => $_SESSION['user_id'] ?? 0,
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['user_email'] ?? '',
            'plan' => $_SESSION['user_plan'] ?? 'free',
            'role' => $_SESSION['user_role'] ?? null,
        ];
    }

    public static function isAdmin(): bool
    {
        $role = $_SESSION['user_role'] ?? null;
        if (in_array($role, ['master', 'admin'], true)) return true;
        return in_array($_SESSION['user_plan'] ?? '', ['premium', 'admin'], true);
    }

    public static function isMaster(): bool
    {
        return ($_SESSION['user_role'] ?? null) === 'master';
    }

    public static function can(string $permission): bool
    {
        if (self::isMaster()) return true;

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) return false;

        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::with('role')->find($userId);
            if (!$user || !$user->role) return false;
            return $user->role->hasPermission($permission);
        }

        return self::isAdmin();
    }

    public static function getUsers(): array
    {
        if (Database::available()) {
            return \AfiliaFacil\Models\User::with('role')->get()->map(fn($u) => self::userToArray($u))->all();
        }
        if (!file_exists(self::$usersFile)) return [];
        return json_decode(file_get_contents(self::$usersFile), true) ?? [];
    }

    public static function emailExists(string $email): bool
    {
        $email = strtolower(trim($email));

        if (Database::available()) {
            return \AfiliaFacil\Models\User::where('email', $email)->exists();
        }

        foreach (self::getUsers() as $user) {
            if (strtolower($user['email']) === $email) return true;
        }
        return false;
    }

    public static function getUserById(int $id): ?array
    {
        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::with('role')->find($id);
            return $user ? self::userToArray($user) : null;
        }

        foreach (self::getUsers() as $user) {
            if ($user['id'] === $id) return $user;
        }
        return null;
    }

    private static function writeUsers(array $users): void
    {
        file_put_contents(self::$usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

Auth::init();
