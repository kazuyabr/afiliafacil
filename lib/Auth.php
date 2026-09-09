<?php
session_start();

class Auth
{
    private static string $usersFile;

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
        $users = self::getUsers();

        foreach ($users as $user) {
            if (strtolower($user['email']) === strtolower($email)) {
                return null;
            }
        }

        $trialDays = (int)(Settings::get('trial_days', 3));

        $user = [
            'id' => time() + random_int(1, 9999),
            'name' => $name,
            'email' => strtolower($email),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'plan' => 'trial',
            'trial_until' => date('Y-m-d H:i:s', strtotime("+{$trialDays} days")),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $users[] = $user;
        self::writeUsers($users);

        self::loginUser($user);

        return $user;
    }

    public static function attempt(string $email, string $password): bool
    {
        $users = self::getUsers();
        foreach ($users as $user) {
            if ($user['email'] === $email && password_verify($password, $user['password'])) {
                self::loginUser($user);
                return true;
            }
        }
        return false;
    }

    private static function loginUser(array $user): void
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_plan'] = $user['plan'];
    }

    public static function syncPlan(): void
    {
        if (!self::check()) return;

        $users = self::getUsers();
        foreach ($users as $user) {
            if ($user['id'] === (int)($_SESSION['user_id'] ?? 0)) {
                if ($user['plan'] === 'trial' && !empty($user['trial_until'])) {
                    if (strtotime($user['trial_until']) < time()) {
                        $user['plan'] = 'trial_expired';
                        self::writeUsers(array_map(function ($u) use ($user) {
                            return $u['id'] === $user['id'] ? $user : $u;
                        }, $users));
                    }
                }
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_plan'] = $user['plan'];
                return;
            }
        }
    }

    public static function setPlan(int $userId, string $plan): bool
    {
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
        ];
    }

    public static function isAdmin(): bool
    {
        return in_array($_SESSION['user_plan'] ?? '', ['premium', 'admin']);
    }

    public static function getUsers(): array
    {
        if (!file_exists(self::$usersFile)) return [];
        return json_decode(file_get_contents(self::$usersFile), true) ?? [];
    }

    public static function getUserById(int $id): ?array
    {
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
