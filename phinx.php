<?php

require_once __DIR__ . '/lib/Database.php';

$db = Database::config();

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'migrations',
        'default_environment' => 'default',
        'default' => [
            'adapter' => $db['driver'] === 'mysql' ? 'mysql' : 'pgsql',
            'host' => $db['host'],
            'port' => $db['port'],
            'name' => $db['database'],
            'user' => $db['username'],
            'pass' => $db['password'],
            'charset' => $db['charset'],
        ],
    ],
];
