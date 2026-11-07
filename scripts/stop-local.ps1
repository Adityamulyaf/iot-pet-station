$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
$servicesFile = Join-Path $projectRoot '.runtime/services.json'
if (Test-Path -LiteralPath $servicesFile) {
    $services = Get-Content -LiteralPath $servicesFile -Raw | ConvertFrom-Json
    foreach ($service in $services.PSObject.Properties) {
        $process = Get-Process -Id $service.Value.pid -ErrorAction SilentlyContinue
        if ($process -and $process.StartTime.ToUniversalTime().Ticks.ToString() -eq $service.Value.started) {
            Stop-Process -Id $process.Id
        }
    }
    Set-Content -LiteralPath $servicesFile -Value '{}'
}
$credentialsPath = Join-Path $projectRoot '.runtime/credentials.json'
$clientConfig = Join-Path $projectRoot '.runtime/database-client.ini'
if ((Test-Path -LiteralPath $clientConfig) -and (Test-Path 'C:/xampp/mysql/bin/mysqladmin.exe')) {
    & 'C:/xampp/mysql/bin/mysqladmin.exe' "--defaults-extra-file=$clientConfig" shutdown
}
Write-Host 'Layanan lokal proyek dihentikan.'
