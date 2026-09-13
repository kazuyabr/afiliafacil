<?php
session_start();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Totp.php';

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
        return self::attemptWithThrottle($email, $password)['ok'];
    }

    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOGIN_WINDOW_MINUTES = 15;

    public static function attemptWithThrottle(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        if (self::isThrottled($email)) {
            Audit::log('login_blocked', 'user', $email, ['reason' => 'rate_limit']);
            return ['ok' => false, 'error' => 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.'];
        }

        $user = self::findAuthenticatedUser($email, $password);

        if ($user) {
            self::recordAttempt($email, true);

            if (!empty($user['two_factor_enabled'])) {
                $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                Audit::log('login_2fa_pending', 'user', $email);
                return ['ok' => false, 'two_factor' => true, 'error' => null];
            }

            self::loginUser($user);
            Audit::log('login', 'user', $email);
            return ['ok' => true, 'error' => null];
        }

        self::recordAttempt($email, false);
        Audit::log('login_failed', 'user', $email);
        return ['ok' => false, 'error' => null];
    }

    public static function completeTwoFactor(string $code): array
    {
        $userId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Sessão de verificação expirada. Faça login novamente.'];
        }

        if (!self::verify2fa($userId, $code)) {
            Audit::log('login_2fa_failed', 'user', (string)$userId);
            return ['ok' => false, 'error' => 'Código inválido. Tente novamente.'];
        }

        $user = self::getUserById($userId);
        if (!$user) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }

        unset($_SESSION['pending_2fa_user_id']);
        self::loginUser($user);
        Audit::log('login', 'user', $user['email']);
        return ['ok' => true, 'error' => null];
    }

    public static function verify2fa(int $userId, string $code): bool
    {
        if (!Database::available()) return false;

        $user = \AfiliaFacil\Models\User::find($userId);
        if (!$user || !$user->two_factor_enabled) return false;

        $code = strtoupper(trim($code));

        if (preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}$/i', $code)) {
            return self::consumeRecoveryCode($user, $code);
        }

        $secret = Crypto::decrypt($user->two_factor_secret ?? '') ?? '';
        if ($secret === '') return false;

        return Totp::verify($secret, $code);
    }

    private static function consumeRecoveryCode($user, string $code): bool
    {
        $codes = json_decode($user->two_factor_recovery_codes ?? '[]', true);
        if (!is_array($codes) || empty($codes)) return false;

        foreach ($codes as $index => $hash) {
            if (password_verify($code, $hash)) {
                unset($codes[$index]);
                $user->two_factor_recovery_codes = json_encode(array_values($codes));
                $user->save();
                Audit::log('2fa_recovery_used', 'user', (string)$user->id);
                return true;
            }
        }
        return false;
    }

    public static function enable2fa(int $userId, string $secret, array $codes): bool
    {
        if (!Database::available()) return false;

        $user = \AfiliaFacil\Models\User::find($userId);
        if (!$user) return false;

        $user->two_factor_secret = Crypto::encrypt($secret);
        $user->two_factor_enabled = true;
        $user->two_factor_recovery_codes = json_encode(array_map(
            fn($code) => password_hash($code, PASSWORD_DEFAULT),
            $codes
        ));
        $user->save();

        Audit::log('2fa_enabled', 'user', (string)$userId);
        return true;
    }

    public static function disable2fa(int $userId): bool
    {
        if (!Database::available()) return false;

        $user = \AfiliaFacil\Models\User::find($userId);
        if (!$user) return false;

        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_recovery_codes = null;
        $user->save();

        Audit::log('2fa_disabled', 'user', (string)$userId);
        return true;
    }

    public static function regenerateRecoveryCodes(int $userId): array
    {
        if (!Database::available()) return [];

        $user = \AfiliaFacil\Models\User::find($userId);
        if (!$user || !$user->two_factor_enabled) return [];

        $codes = Totp::generateRecoveryCodes();
        $user->two_factor_recovery_codes = json_encode(array_map(
            fn($code) => password_hash($code, PASSWORD_DEFAULT),
            $codes
        ));
        $user->save();

        Audit::log('2fa_recovery_regenerated', 'user', (string)$userId);
        return $codes;
    }

    public static function has2fa(int $userId): bool
    {
        if (!Database::available()) return false;
        $user = \AfiliaFacil\Models\User::find($userId);
        return $user && $user->two_factor_enabled;
    }

    private static function findAuthenticatedUser(string $email, string $password): ?array
    {
        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::where('email', $email)->first();
            if (!$user || !$user->active) return null;
            if (!password_verify($password, $user->password)) return null;
            return self::userToArray($user);
        }

        foreach (self::getUsers() as $user) {
            if (strtolower($user['email']) === $email && password_verify($password, $user['password'])) {
                return $user;
            }
        }
        return null;
    }

    private static function isThrottled(string $email): bool
    {
        if (!Database::available()) return false;

        try {
            $since = date('Y-m-d H:i:s', time() - self::LOGIN_WINDOW_MINUTES * 60);
            $count = \AfiliaFacil\Models\LoginAttempt::where('email', $email)
                ->where('success', false)
                ->where('created_at', '>=', $since)
                ->count();
            return $count >= self::MAX_LOGIN_ATTEMPTS;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function recordAttempt(string $email, bool $success): void
    {
        if (!Database::available()) return;

        try {
            \AfiliaFacil\Models\LoginAttempt::create([
                'email' => $email,
                'ip' => Audit::clientIp(),
                'success' => $success,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($success) {
                \AfiliaFacil\Models\LoginAttempt::where('email', $email)
                    ->where('success', false)
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - 24 * 3600))
                    ->delete();
            }
        } catch (Throwable $e) {
        }
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
            'two_factor_enabled' => (bool)($user->two_factor_enabled ?? false),
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
        $result = false;

        if (Database::available()) {
            $user = \AfiliaFacil\Models\User::find($userId);
            if (!$user) return false;
            $user->plan = $plan;
            $user->updated_at = date('Y-m-d H:i:s');
            $user->save();

            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_plan'] = $plan;
            }
            $result = true;
        } else {
            $users = self::getUsers();
            foreach ($users as &$user) {
                if ($user['id'] === $userId) {
                    $user['plan'] = $plan;
                    $result = true;
                    break;
                }
            }
            unset($user);
            if ($result) {
                self::writeUsers($users);
                if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                    $_SESSION['user_plan'] = $plan;
                }
            }
        }

        if ($result) {
            Audit::log('plan_changed', 'user', (string)$userId, ['plan' => $plan]);
        }

        return $result;
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

    public static function canAccessPage(array $page): bool
    {
        if (self::isAdmin()) return true;
        return (int)($page['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);
    }

    public static function requirePageAccess(array $page): void
    {
        if (!self::canAccessPage($page)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Sem acesso a esta página']);
            exit;
        }
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
