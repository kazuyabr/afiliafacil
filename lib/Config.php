<?php
class Config
{
    private static ?string $rootDir = null;

    public static function getRootDir(): string
    {
        if (self::$rootDir !== null) return self::$rootDir;

        if (getenv('DOCKER') !== false || is_dir('/app/public')) {
            self::$rootDir = '/app';
        } else {
            self::$rootDir = dirname(__DIR__);
        }
        return self::$rootDir;
    }

    public static function getDataDir(): string
    {
        $dir = self::getRootDir() . '/data';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir;
    }

    public static function getPagesDir(): string
    {
        $dir = self::getRootDir() . '/pages';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir;
    }

    public static function getLibDir(): string
    {
        return self::getRootDir() . '/lib';
    }

    public static function getUploadsDir(): string
    {
        $dir = self::getRootDir() . '/uploads';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir;
    }

    public static function getBaseUrl(): string
    {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }
}
