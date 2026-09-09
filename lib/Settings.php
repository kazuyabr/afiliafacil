<?php
class Settings
{
    private static string $settingsFile;

    private static function file(): string
    {
        if (empty(self::$settingsFile)) {
            self::$settingsFile = Config::getDataDir() . '/settings.json';
            if (!file_exists(self::$settingsFile)) {
                file_put_contents(self::$settingsFile, json_encode([
                    'trial_days' => 3,
                    'checkout_driver' => 'pix',
                ], JSON_PRETTY_PRINT));
            }
        }
        return self::$settingsFile;
    }

    public static function all(): array
    {
        return json_decode(file_get_contents(self::file()), true) ?? [];
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $all = self::all();
        $all[$key] = $value;
        file_put_contents(self::file(), json_encode($all, JSON_PRETTY_PRINT));
    }
}
