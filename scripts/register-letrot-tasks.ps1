Param(
    [string]$TaskFolder = 'Pronostics',
    [string]$MorningTime = '08:00',
    [string]$EveningTime = '21:30'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$runScript = Join-Path $scriptDir 'run-letrot-sync.ps1'

if (-not (Test-Path $runScript)) {
    throw "Script introuvable: $runScript"
}

$taskBase = "$TaskFolder\Letrot"
$cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File `"$runScript`""

schtasks /Create /TN "$taskBase Morning" /TR "$cmd" /SC DAILY /ST $MorningTime /F | Out-Null
schtasks /Create /TN "$taskBase Evening" /TR "$cmd" /SC DAILY /ST $EveningTime /F | Out-Null

Write-Host "Taches planifiees creees:" -ForegroundColor Green
Write-Host "- $taskBase Morning ($MorningTime)"
Write-Host "- $taskBase Evening ($EveningTime)"
