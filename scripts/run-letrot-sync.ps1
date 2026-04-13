Param(
    [string]$Date = (Get-Date -Format 'yyyy-MM-dd'),
    [switch]$DryRun,
    [double]$QualityThreshold = 0.75,
    [switch]$NoForceReimport,
    [int]$LimitMeetings = 0,
    [int]$LimitRaces = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Resolve-Path (Join-Path $scriptDir '..')

Push-Location $projectRoot
try {
    $args = @(
        'bin/console',
        'app:automation:letrot-sync',
        "--date=$Date",
        "--quality-threshold=$QualityThreshold",
        "--letrot-limit-meetings=$LimitMeetings",
        "--letrot-limit-races=$LimitRaces"
    )

    if ($DryRun) {
        $args += '--dry-run'
    }

    if ($NoForceReimport) {
        $args += '--no-force-reimport'
    }

    & php @args
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
