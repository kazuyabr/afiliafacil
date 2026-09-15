# bin/homol.ps1 — Ambiente de homologação do AfiliaFacil via túnel cloudflared
#
# Uso:
#   .\bin\homol.ps1          # sobe o app + inicia o túnel e mostra a URL pública
#   .\bin\homol.ps1 -Stop    # encerra o túnel
#
# Requisitos: Docker rodando + cloudflared instalado (winget install --id Cloudflare.cloudflared)

param(
    [int]$Port = 9876,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

if ($Stop) {
    Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
    Write-Host "Tunel encerrado." -ForegroundColor Yellow
    Write-Host "Para derrubar o app: docker compose down (na pasta do projeto)" -ForegroundColor DarkGray
    exit 0
}

Write-Host ""
Write-Host "=== HOMOLOGACAO AFILIAFACIL ===" -ForegroundColor Cyan
Write-Host ""

# 1) Containers
Push-Location $projectRoot
try {
    $running = docker ps --filter "name=afiliafacil" --format "{{.Names}}" 2>$null
    if ($running -notcontains 'afiliafacil') {
        Write-Host "[1/3] Subindo containers (aguarde)..." -ForegroundColor Yellow
        docker compose up -d --build | Out-Null
        Start-Sleep -Seconds 8
    } else {
        Write-Host "[1/3] Containers ja estao rodando." -ForegroundColor Green
    }
} finally {
    Pop-Location
}

# 2) cloudflared instalado?
$cf = Get-Command cloudflared -ErrorAction SilentlyContinue
if (-not $cf) {
    Write-Host "[2/3] cloudflared NAO encontrado." -ForegroundColor Red
    Write-Host ""
    Write-Host "Instale com um dos comandos abaixo e rode este script de novo:" -ForegroundColor Yellow
    Write-Host "  winget install --id Cloudflare.cloudflared" -ForegroundColor White
    Write-Host "  (alternativa sem instalacao: npx localtunnel --port $Port)" -ForegroundColor DarkGray
    exit 1
}

# 3) Tunel
Write-Host "[2/3] Iniciando tunel cloudflared..." -ForegroundColor Yellow
$logOut = Join-Path $env:TEMP 'af-homol-cf-out.log'
$logErr = Join-Path $env:TEMP 'af-homol-cf-err.log'
Remove-Item $logOut, $logErr -ErrorAction SilentlyContinue

$proc = Start-Process -FilePath $cf.Source `
    -ArgumentList "tunnel", "--url", "http://localhost:$Port" `
    -RedirectStandardOutput $logOut -RedirectStandardError $logErr `
    -PassThru -WindowStyle Hidden

$url = $null
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    $content = ''
    if (Test-Path $logErr) { $content += (Get-Content $logErr -Raw -ErrorAction SilentlyContinue) }
    if (Test-Path $logOut) { $content += (Get-Content $logOut -Raw -ErrorAction SilentlyContinue) }
    if ($content -match 'https://[a-z0-9-]+\.trycloudflare\.com') {
        $url = $Matches[0]
        break
    }
    if ($proc.HasExited) { break }
}

if (-not $url) {
    Write-Host "[3/3] Nao foi possivel obter a URL do tunel." -ForegroundColor Red
    Write-Host "Verifique o log: $logErr" -ForegroundColor DarkGray
    if (-not $proc.HasExited) { $proc.Kill() }
    exit 1
}

Write-Host "[3/3] Tunel ativo!" -ForegroundColor Green
Write-Host ""
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host " URL PUBLICA: $url" -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Credenciais de teste:" -ForegroundColor White
Write-Host "  Admin:        admin@afiliafacil.com / admin123"
Write-Host "  Demo Trial:   demo.trial@afiliafacil.com / demo123456"
Write-Host "  Demo Pro:     demo.pro@afiliafacil.com / demo123456"
Write-Host "  Demo Master:  demo.master@afiliafacil.com / demo123456"
Write-Host ""
Write-Host "Avisos:" -ForegroundColor Yellow
Write-Host "  - O tunel depende deste computador ligado e do container rodando."
Write-Host "  - Para encerrar o tunel: .\bin\homol.ps1 -Stop"
Write-Host "  - O cron de ofertas nao roda automaticamente no tunel; use o botao"
Write-Host "    'Buscar ofertas agora' / 'Atualizar metricas agora' na Curadoria."
Write-Host ""
Write-Host "Roteiro de testes do cliente: docs\roteiro-testes-homol.md" -ForegroundColor DarkGray
Write-Host ""
