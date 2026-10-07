<?php
/**
 * Pre-condicao do gate: seed de primeiro boot presente no banco.
 *
 * Falha (exit 1) quando roles/plans/admin/contas demo nao existem — tipicamente
 * ImportJsonData::run() pulado no boot (role da app ainda nao criada) ou
 * bin/seed-demo.php nao executado. Roda dentro do container:
 *   php bin/check-seed.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Database.php';

Database::init();

$fail = 0;

function chk(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? '[OK] ' : '[FALHOU] ') . $name . ($detail !== '' ? " - {$detail}" : '') . "\n";
    if (!$ok) {
        $fail++;
    }
}

$available = Database::available();
chk('banco disponivel para a app', $available, 'rode php bin/migrate.php (cria a role da app)');

if ($available) {
    $plans = \AfiliaFacil\Models\Plan::count();
    $roles = \AfiliaFacil\Models\Role::count();
    $admin = \AfiliaFacil\Models\User::where('email', 'admin@afiliafacil.com')->exists();
    $demoTrial = \AfiliaFacil\Models\User::where('email', 'demo.trial@afiliafacil.com')->exists();
    $demoPro = \AfiliaFacil\Models\User::where('email', 'demo.pro@afiliafacil.com')->exists();

    chk('plans seedados', $plans >= 5, "plans={$plans} (esperado >= 5) - rode php bin/migrate.php");
    chk('roles seedadas', $roles >= 5, "roles={$roles} (esperado >= 5) - rode php bin/migrate.php");
    chk('admin padrao existe', $admin, 'admin@afiliafacil.com - rode php bin/migrate.php');
    chk('contas demo existem', $demoTrial && $demoPro, 'demo.trial/demo.pro - rode php bin/seed-demo.php');
}

if ($fail > 0) {
    echo "[check-seed] FALHOU ({$fail} verificacao(es))\n";
    exit(1);
}

echo "[check-seed] OK\n";
exit(0);
