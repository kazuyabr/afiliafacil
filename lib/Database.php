<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class Database
{
    private static bool $initialized = false;
    private static bool $available = false;
    private static array $configs = [];

    public static function config(bool $forMigration = false): array
    {
        $key = $forMigration ? 'migration' : 'app';
        if (!empty(self::$configs[$key])) return self::$configs[$key];

        $url = getenv('DATABASE_URL');
        if ($url) {
            $parts = parse_url($url);
            $scheme = $parts['scheme'] ?? 'pgsql';
            $driver = str_starts_with($scheme, 'mysql') ? 'mysql' : 'pgsql';

            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            if ($forMigration) {
                $user = getenv('DB_MIGRATION_USER') ?: $user;
                $pass = getenv('DB_MIGRATION_PASSWORD') ?: $pass;
            }

            return self::$configs[$key] = [
                'driver' => $driver,
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => $parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
                'database' => ltrim($parts['path'] ?? '', '/'),
                'username' => $user,
                'password' => $pass,
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ];
        }

        $driver = getenv('DB_CONNECTION') ?: 'pgsql';

        $user = getenv('DB_USERNAME') ?: 'afiliafacil_app';
        $pass = getenv('DB_PASSWORD') ?: '';
        if ($forMigration) {
            $user = getenv('DB_MIGRATION_USER') ?: (getenv('DB_USERNAME') ?: 'afiliafacil');
            $pass = getenv('DB_MIGRATION_PASSWORD') ?: (getenv('DB_PASSWORD') ?: '');
        }

        return self::$configs[$key] = [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
            'database' => getenv('DB_DATABASE') ?: 'afiliafacil',
            'username' => $user,
            'password' => $pass,
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
        ];
    }

    public static function isConfigured(): bool
    {
        return getenv('DB_CONNECTION') !== false || getenv('DATABASE_URL') !== false;
    }

    public static function init(): bool
    {
        if (self::$initialized) return self::$available;

        if (!self::isConfigured()) {
            self::$initialized = true;
            return self::$available = false;
        }

        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            self::$initialized = true;
            return self::$available = false;
        }
        require_once $autoload;

        try {
            $capsule = new Capsule;
            $capsule->addConnection(self::config());
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::connection()->getPdo();
            self::$initialized = true;
            self::$available = true;
        } catch (Throwable $e) {
            self::$initialized = false;
            self::$available = false;
        }

        return self::$available;
    }

    public static function available(): bool
    {
        return self::init();
    }

    public static function ping(): bool
    {
        return self::init();
    }

    public static function schema()
    {
        self::init();
        return Capsule::schema();
    }

    public static function table(string $name)
    {
        self::init();
        return Capsule::table($name);
    }
}
