<?php

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Database.php';

if (!Database::isConfigured()) {
    echo "[migrate] DB nao configurado - pulando migracoes (modo JSON)\n";
    exit(0);
}

$migrationDb = Database::config(true);

function migrationPing(array $db): bool
{
    try {
        $dsn = $db['driver'] === 'mysql'
            ? "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}"
            : "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}";
        new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$attempts = 0;
while (!migrationPing($migrationDb) && $attempts < 30) {
    $attempts++;
    echo "[migrate] aguardando banco de dados... ($attempts/30)\n";
    sleep(2);
}

if (!migrationPing($migrationDb)) {
    echo "[migrate] ERRO: banco de dados indisponivel\n";
    exit(1);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    echo "[migrate] ERRO: vendor/ nao encontrado (composer install)\n";
    exit(1);
}
require_once $autoload;

try {
    $app = new Phinx\Console\PhinxApplication();
    $app->setAutoExit(false);
    $input = new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'migrate',
        '-c' => __DIR__ . '/../phinx.php',
    ]);
    $output = new Symfony\Component\Console\Output\ConsoleOutput();
    $exit = $app->run($input, $output);
    if ($exit !== 0) {
        echo "[migrate] phinx migrate retornou codigo $exit\n";
        exit($exit);
    }
} catch (Throwable $e) {
    echo "[migrate] ERRO phinx: " . $e->getMessage() . "\n";
    exit(1);
}

require_once __DIR__ . '/../lib/ImportJsonData.php';
try {
    ImportJsonData::run();
} catch (Throwable $e) {
    echo "[migrate] ERRO import: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    createAppRole();
} catch (Throwable $e) {
    echo "[migrate] ERRO role app: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[migrate] concluido\n";
exit(0);

function createAppRole(): void
{
    $db = Database::config(true);
    $appUser = getenv('DB_APP_USER') ?: 'afiliafacil_app';
    $appPass = getenv('DB_APP_PASSWORD') ?: 'change-me';

    if ($db['driver'] === 'mysql') {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}";
    } else {
        $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}";
    }

    $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($db['driver'] === 'mysql') {
        $pdo->exec("CREATE USER IF NOT EXISTS '" . $appUser . "'@'%' IDENTIFIED BY '" . $appPass . "'");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$db['database']}`.* TO '" . $appUser . "'@'%'");
        echo "[migrate] role MySQL '$appUser' criada/atualizada\n";
        return;
    }

    $ident = '"' . str_replace('"', '""', $appUser) . '"';
    $exists = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = " . $pdo->quote($appUser))->fetchColumn();

    if (!$exists) {
        $pdo->exec("CREATE ROLE {$ident} LOGIN PASSWORD " . $pdo->quote($appPass));
        echo "[migrate] role Postgres '$appUser' criada\n";
    } else {
        $pdo->exec("ALTER ROLE {$ident} LOGIN PASSWORD " . $pdo->quote($appPass));
        echo "[migrate] role Postgres '$appUser' atualizada\n";
    }

    $pdo->exec("GRANT USAGE ON SCHEMA public TO {$ident}");
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$ident}");
    $pdo->exec("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$ident}");
    $pdo->exec("REVOKE CREATE ON SCHEMA public FROM {$ident}");
    $pdo->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$ident}");
    $pdo->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$ident}");
    echo "[migrate] grants aplicados (sem DDL) para '$appUser'\n";
}
