<?php

require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/Database.php';

if (!Database::isConfigured()) {
    echo "[migrate] DB nao configurado - pulando migracoes (modo JSON)\n";
    exit(0);
}

$attempts = 0;
while (!Database::ping() && $attempts < 30) {
    $attempts++;
    echo "[migrate] aguardando banco de dados... ($attempts/30)\n";
    sleep(2);
}

if (!Database::ping()) {
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

echo "[migrate] concluido\n";
exit(0);
