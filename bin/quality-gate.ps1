<#
.SYNOPSIS
  Gate de qualidade do AfiliaFacil - pre-condicao de commit/entrega.

.DESCRIPTION
  G0  containers saudaveis + sync working tree -> container
  G1  php -l em todo o repo            (container: bin/quality-gate.php)
  G2  suites PHP + smoke               (container: bin/quality-gate.php)
  G3  JS servido das telas (sintaxe)   (host: tests/e2e/served-check.js)
  G4  responsividade 360/768/1280 + zero pageerror (host: tests/e2e/responsive.js)

  Full  = obrigatorio antes de commit/entrega (qualquer vermelho = exit 1).
  -Quick = so G0+G1+G2 sem smoke, sem G3/G4 (iteracao interna; NAO serve para commit).

.EXAMPLE
  powershell -File bin\quality-gate.ps1
  powershell -File bin\quality-gate.ps1 -Quick
#>
param([switch]$Quick)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot   # .../afiliafacil
$results = @()

function Fail([string]$msg) {
    Write-Host "[gate] FAIL: $msg" -ForegroundColor Red
    Write-Host "[gate] GATE: FAIL" -ForegroundColor Red
    exit 1
}

function Add-Result([string]$name, [bool]$ok) {
    $script:results += ,@($name, $ok)
    if (-not $ok) { Write-Host "[gate] $name -> FAIL" -ForegroundColor Red }
}

# ------------------------------------------------- G0: ambiente + sync
Write-Host '[gate] ==== G0 ambiente ===='
$all = @(docker ps -a --format '{{.Names}}')
if ($all -notcontains 'afiliafacil' -or $all -notcontains 'afiliafacil-db') {
    Fail "containers nao existem. Rode em ${root}: docker compose up -d --build"
}
$running = @(docker ps --format '{{.Names}}')
if ($running -notcontains 'afiliafacil-db') { docker start afiliafacil-db | Out-Null; Write-Host '[gate] iniciando afiliafacil-db...' }
if ($running -notcontains 'afiliafacil')   { docker start afiliafacil   | Out-Null; Write-Host '[gate] iniciando afiliafacil...' }

$dbHealth = ''
for ($i = 0; $i -lt 45; $i++) {
    $dbHealth = (docker inspect -f '{{.State.Health.Status}}' afiliafacil-db 2>$null) -join ''
    $appState = (docker inspect -f '{{.State.Status}}' afiliafacil 2>$null) -join ''
    if ($dbHealth -eq 'healthy' -and $appState -eq 'running') { break }
    Start-Sleep 2
}
if ($dbHealth -ne 'healthy') { Fail "afiliafacil-db nao ficou healthy (status: $dbHealth)" }
if ($appState -ne 'running') { Fail "afiliafacil nao esta running (status: $appState)" }

Write-Host '[gate] ==== G0 sync working tree -> container ===='
Push-Location $root
try {
    $files = @(git ls-files -m -o --exclude-standard)
    $copied = 0
    foreach ($f in $files) {
        $rel = ($f -replace '\\', '/')
        if (-not (Test-Path -LiteralPath $f -PathType Leaf)) { continue }   # removido no worktree
        $dir = Split-Path -Parent $f
        if ($dir) { docker exec afiliafacil sh -c "mkdir -p '/app/$($dir -replace '\\', '/')'" | Out-Null }
        docker cp -- "$f" "afiliafacil:/app/$rel" | Out-Null
        if ($LASTEXITCODE -ne 0) { Fail "docker cp falhou: $rel" }
        $copied++
    }
    Write-Host "[gate]   $copied arquivo(s) sincronizado(s)"
} finally {
    Pop-Location
}
Add-Result 'G0 ambiente+sync' $true

# ------------------------------------------------- G1+G2: lint + suites (container)
Write-Host '[gate] ==== G1+G2 lint + suites (container) ===='
if ($Quick) { docker exec afiliafacil php bin/quality-gate.php --quick }
else        { docker exec afiliafacil php bin/quality-gate.php }
Add-Result 'G1+G2 lint+suites' ($LASTEXITCODE -eq 0)

if ($Quick) {
    Write-Host '[gate] modo -Quick: G3 e G4 pulados (NAO serve para commit)'
    Write-Host '[gate] ---------- RESUMO ----------'
    foreach ($r in $results) { Write-Host ('[gate] {0,-22} {1}' -f $r[0], $(if ($r[1]) { 'OK' } else { 'FAIL' })) }
    Write-Host '[gate] GATE: QUICK (incompleto)'
    exit 0
}

# ------------------------------------------------- host: node + playwright
$e2e = Join-Path $root 'tests\e2e'
if (-not (Test-Path (Join-Path $e2e 'node_modules\playwright'))) {
    Write-Host '[gate] instalando dependencias e2e (npm install)...'
    Push-Location $e2e
    npm install --no-fund --no-audit
    $npmOk = $LASTEXITCODE -eq 0
    Pop-Location
    if (-not $npmOk) { Fail 'npm install falhou em tests/e2e' }
}
$pwCache = Join-Path $env:LOCALAPPDATA 'ms-playwright'
$hasBrowser = (Test-Path $pwCache) -and (@(Get-ChildItem $pwCache -Directory -Filter 'chromium*' -ErrorAction SilentlyContinue).Count -gt 0)
if (-not $hasBrowser) {
    Write-Host '[gate] baixando chromium do Playwright...'
    Push-Location $e2e
    npx playwright install chromium
    $pwOk = $LASTEXITCODE -eq 0
    Pop-Location
    if (-not $pwOk) { Fail 'playwright install chromium falhou' }
}

# ------------------------------------------------- G3: JS servido
Write-Host '[gate] ==== G3 JS servido (sintaxe) ===='
$up = $false
for ($i = 0; $i -lt 30; $i++) {
    try {
        Invoke-WebRequest -Uri 'http://localhost:9876/login' -UseBasicParsing -TimeoutSec 3 | Out-Null
        $up = $true
        break
    } catch { Start-Sleep 2 }
}
if (-not $up) { Fail 'app nao responde em http://localhost:9876' }
node (Join-Path $e2e 'served-check.js')
Add-Result 'G3 JS servido' ($LASTEXITCODE -eq 0)

# ------------------------------------------------- G4: responsividade
Write-Host '[gate] ==== G4 responsividade + pageerror ===='
node (Join-Path $e2e 'responsive.js')
Add-Result 'G4 responsividade' ($LASTEXITCODE -eq 0)

# ------------------------------------------------- resumo final
Write-Host '[gate] ---------- RESUMO ----------'
$failed = 0
foreach ($r in $results) {
    Write-Host ('[gate] {0,-22} {1}' -f $r[0], $(if ($r[1]) { 'OK' } else { 'FAIL' }))
    if (-not $r[1]) { $failed++ }
}
if ($failed -gt 0) {
    Write-Host "[gate] GATE: FAIL ($failed estagio(s)) - commit proibido" -ForegroundColor Red
    exit 1
}
Write-Host '[gate] GATE: PASS - entrega apta a commit' -ForegroundColor Green
exit 0
