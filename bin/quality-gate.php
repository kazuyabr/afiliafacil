<?php
/**
 * Gate de qualidade — roda DENTRO do container (G1 lint + G2 suites).
 *
 * Uso:   docker exec afiliafacil php bin/quality-gate.php [--quick]
 *   --quick: pula o smoke (iteracao interna; NAO serve para commit).
 *
 * Orquestrador completo (G0 sync + G3 JS servido + G4 responsividade):
 *   powershell -File bin\quality-gate.ps1
 *
 * Exit 0 = verde; exit 1 = qualquer estagio vermelho (commit proibido).
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

$quick = in_array('--quick', $argv ?? [], true);

/** @return array{0:string,1:int,2:float} */
function stage(string $name, callable $fn): array
{
    echo "[gate] ==== {$name} ====\n";
    flush();
    $t0 = microtime(true);
    $code = $fn();
    $sec = round(microtime(true) - $t0, 1);
    echo "[gate] ==== {$name}: " . ($code === 0 ? 'OK' : 'FAIL') . " ({$sec}s) ====\n\n";
    flush();
    return [$name, $code, $sec];
}

$results = [];

// ---------------------------------------------------------------- G1: lint
$results[] = stage('G1 lint php -l', static function (): int {
    $cmd = "find . -name '*.php'"
        . " -not -path './vendor/*' -not -path './.git/*' -not -path './data/*'"
        . " -not -path './pages/*' -not -path './uploads/*' -not -path './cache/*'"
        . " -not -path './storage/*' -not -path './node_modules/*'"
        . " -print0 2>/dev/null | xargs -0 -r -n 50 php -l 2>&1";
    exec($cmd, $out, $code);
    $bad = array_values(array_filter(
        $out,
        static fn(string $l): bool => !str_contains($l, 'No syntax errors') && trim($l) !== ''
    ));
    if ($bad) {
        foreach ($bad as $l) {
            echo "  {$l}\n";
        }
        return 1;
    }
    return $code === 0 || $out !== [] ? 0 : $code;
});

// ---------------------------------------------------- G2: suites PHP + smoke
$results[] = stage('G2 ambiente (migrate+seed)', static function (): int {
    $code = 0;
    passthru('php bin/migrate.php', $code);
    if ($code !== 0) {
        return $code;
    }
    passthru('php bin/seed-demo.php', $code);
    if ($code !== 0) {
        return $code;
    }
    passthru('php bin/check-seed.php', $code);
    return $code;
});

$suites = [
    ['G2 smoke', 'php bin/smoke.php'],
    ['G2 suite social', 'php tests/social/social-test.php'],
    ['G2 suite attachments', 'php tests/agent/attachments-test.php'],
    ['G2 suite adspy-api', 'php tests/adspy/adspy-api-test.php'],
    ['G2 suite adspy-discover', 'php tests/adspy/adspy-discover-test.php'],
    ['G2 suite adspy-ui', 'php tests/adspy/adspy-ui-static-test.php'],
];
if ($quick) {
    array_shift($suites); // pula smoke
}

foreach ($suites as [$name, $cmd]) {
    $results[] = stage($name, static function () use ($cmd): int {
        passthru($cmd, $code);
        return $code;
    });
}

// ------------------------------------------------------------------ resumo
echo "[gate] ---------- RESUMO ----------\n";
$failed = 0;
foreach ($results as [$n, $c, $s]) {
    printf("[gate] %-24s %s (%ss)\n", $n, $c === 0 ? 'OK  ' : 'FAIL', $s);
    if ($c !== 0) {
        $failed++;
    }
}
echo $failed === 0 ? "[gate] GATE(PHP): PASS\n" : "[gate] GATE(PHP): FAIL ({$failed} estagio(s))\n";
exit($failed === 0 ? 0 : 1);
