<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class Database
{
    private static bool $initialized = false;
    private static bool $available = false;
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) return self::$config;

        $url = getenv('DATABASE_URL');
        if ($url) {
            $parts = parse_url($url);
            $scheme = $parts['scheme'] ?? 'pgsql';
            $driver = str_starts_with($scheme, 'mysql') ? 'mysql' : 'pgsql';
            return self::$config = [
                'driver' => $driver,
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => $parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
                'database' => ltrim($parts['path'] ?? '', '/'),
                'username' => $parts['user'] ?? '',
                'password' => $parts['pass'] ?? '',
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'prefix' => '',
            ];
        }

        $driver = getenv('DB_CONNECTION') ?: 'pgsql';
        return self::$config = [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
            'database' => getenv('DB_DATABASE') ?: 'afiliafacil',
            'username' => getenv('DB_USERNAME') ?: 'afiliafacil',
            'password' => getenv('DB_PASSWORD') ?: '',
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
