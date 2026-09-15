# bin/roteiro-pdf.ps1 — gera o PDF do roteiro de testes (Markdown -> HTML -> PDF)
#
# Uso (na raiz do projeto):
#   .\bin\roteiro-pdf.ps1
#   .\bin\roteiro-pdf.ps1 -Md docs\validacao-manual.md -Out docs\validacao-manual.pdf -Title "Validacao Manual"
#
# Requisitos: Node (npx) + Google Chrome instalado.

param(
    [string]$Md = "docs\roteiro-testes-homol.md",
    [string]$Out = "docs\roteiro-testes-homol.pdf",
    [string]$Title = "Roteiro de Testes - AfiliaFacil"
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$mdPath = Join-Path $root $Md
$outPath = Join-Path $root $Out
$fragPath = Join-Path $env:TEMP 'af-roteiro-frag.html'
$htmlPath = Join-Path $env:TEMP 'af-roteiro.html'

if (-not (Test-Path $mdPath)) {
    Write-Host "Arquivo nao encontrado: $mdPath" -ForegroundColor Red
    exit 1
}

Write-Host "[1/3] Convertendo Markdown -> HTML..." -ForegroundColor Yellow
npx --yes marked -i "$mdPath" -o "$fragPath"
if (-not $?) { Write-Host "Falha no marked (npx)." -ForegroundColor Red; exit 1 }

Write-Host "[2/3] Aplicando template..." -ForegroundColor Yellow
node (Join-Path $PSScriptRoot 'roteiro-html.js') "$fragPath" "$htmlPath" "$Title"
if (-not $?) { Write-Host "Falha ao gerar o HTML." -ForegroundColor Red; exit 1 }

Write-Host "[3/3] Gerando PDF com o Chrome..." -ForegroundColor Yellow
$chromePaths = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
)
$chrome = $chromePaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $chrome) {
    Write-Host "Google Chrome nao encontrado. Abra o HTML manualmente e imprima para PDF:" -ForegroundColor Red
    Write-Host "  $htmlPath" -ForegroundColor White
    exit 1
}

$fileUrl = 'file:///' + ($htmlPath -replace '\\', '/')
$chromeArgs = @('--headless', '--disable-gpu', '--no-pdf-header-footer', "--print-to-pdf=$outPath", $fileUrl)
Start-Process -FilePath $chrome -ArgumentList $chromeArgs -Wait -WindowStyle Hidden | Out-Null
Start-Sleep -Seconds 2

if (Test-Path $outPath) {
    $size = [math]::Round((Get-Item $outPath).Length / 1KB, 1)
    Write-Host ""
    Write-Host "PDF gerado: $outPath ($size KB)" -ForegroundColor Green
} else {
    Write-Host "Falha ao gerar o PDF." -ForegroundColor Red
    exit 1
}
