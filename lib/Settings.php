<?php

require_once __DIR__ . '/Database.php';

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
        if (Database::available()) {
            $out = [];
            foreach (\AfiliaFacil\Models\Setting::all() as $row) {
                $decoded = json_decode($row->value, true);
                $out[$row->key] = $decoded === null ? $row->value : $decoded;
            }
            return $out;
        }
        return json_decode(file_get_contents(self::file()), true) ?? [];
    }

    public static function get(string $key, $default = null)
    {
        if (Database::available()) {
            return \AfiliaFacil\Models\Setting::getValue($key, $default);
        }
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        if (Database::available()) {
            \AfiliaFacil\Models\Setting::setValue($key, $value);
            return;
        }
        $all = self::all();
        $all[$key] = $value;
        file_put_contents(self::file(), json_encode($all, JSON_PRETTY_PRINT));
    }
}
