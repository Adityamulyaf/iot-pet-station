param([string]$Php = '', [string]$Python = '', [switch]$SkipBuild)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
Set-Location -LiteralPath $projectRoot
if (!$Php) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $Php = $phpCommand.Source }
    elseif (Test-Path 'C:/laragon/bin/php') { $Php = (Get-ChildItem 'C:/laragon/bin/php/*/php.exe' | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
}
if (!$Php -or !(Test-Path -LiteralPath $Php)) { throw 'PHP 8.3+ tidak ditemukan. Gunakan -Php dengan path php.exe.' }
if (!$Python) {
    $venvConfig = Join-Path $projectRoot '.venv/pyvenv.cfg'
    if (Test-Path -LiteralPath $venvConfig) {
        $executableLine = Get-Content -LiteralPath $venvConfig | Where-Object { $_.StartsWith('executable = ') } | Select-Object -First 1
        if ($executableLine) { $Python = $executableLine.Substring(13) }
    }
    if (!$Python) { $Python = Join-Path $projectRoot '.venv/Scripts/python.exe' }
    $env:PYTHONPATH = Join-Path $projectRoot '.venv/Lib/site-packages'
}
if (!(Test-Path -LiteralPath $Python)) { throw 'Virtual environment belum tersedia. Jalankan scripts/setup-local.ps1.' }
if (!(Test-Path '.runtime/credentials.json')) { throw 'Konfigurasi lokal belum tersedia. Jalankan scripts/setup-local.ps1.' }
$runtime = Join-Path $projectRoot '.runtime'
function Test-LocalPort([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1',$Port); return $true } catch { return $false } finally { $client.Dispose() }
}
& $Python scripts/bootstrap.py
if ($LASTEXITCODE -ne 0) { throw 'Persiapan layanan lokal gagal.' }
$servicesFile = Join-Path $runtime 'services.json'
$services = @{}
if (Test-Path -LiteralPath $servicesFile) {
    $saved = Get-Content -LiteralPath $servicesFile -Raw | ConvertFrom-Json
    foreach ($property in $saved.PSObject.Properties) { $services[$property.Name] = $property.Value }
}
function Start-LocalService([string]$Name,[string]$Executable,[string]$Arguments,[string]$Directory) {
    if ($services.ContainsKey($Name)) {
        $existing = Get-Process -Id $services[$Name].pid -ErrorAction SilentlyContinue
        if ($existing -and $existing.StartTime.ToUniversalTime().Ticks.ToString() -eq $services[$Name].started) { return }
    }
    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $Directory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $runtime "logs/$Name.out.log") -RedirectStandardError (Join-Path $runtime "logs/$Name.err.log") -PassThru
    $services[$Name] = @{ pid = $process.Id; started = $process.StartTime.ToUniversalTime().Ticks.ToString() }
    $services | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $servicesFile
}
function Test-OwnedService([string]$Name) {
    if (!$services.ContainsKey($Name)) { return $false }
    $process = Get-Process -Id $services[$Name].pid -ErrorAction SilentlyContinue
    return $process -and $process.StartTime.ToUniversalTime().Ticks.ToString() -eq $services[$Name].started
}
if ((Test-LocalPort 1883) -and !(Test-OwnedService 'broker')) { throw 'Port 1883 digunakan layanan lain. Tidak ada perubahan pada layanan tersebut.' }
if ((Test-LocalPort 8000) -and !(Test-OwnedService 'web')) { throw 'Port 8000 digunakan layanan lain. Tidak ada perubahan pada layanan tersebut.' }
if (!(Test-LocalPort 1883)) { Start-LocalService 'broker' (Join-Path $runtime 'mosquitto/mosquitto.exe') '-c .runtime/mosquitto.conf' $projectRoot }
for ($attempt = 0; $attempt -lt 20 -and !(Test-LocalPort 1883); $attempt++) { Start-Sleep -Milliseconds 250 }
if (!(Test-LocalPort 1883)) { throw 'Broker tidak dapat dijalankan. Periksa .runtime/logs/broker.err.log.' }
Push-Location web
try {
    & $Php artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Migrasi database gagal.' }
    if (!(Test-Path 'public/build/manifest.json') -and !$SkipBuild) { npm.cmd run build; if ($LASTEXITCODE -ne 0) { throw 'Build aset gagal.' } }
} finally { Pop-Location }
Start-LocalService 'worker' $Php 'artisan station:mqtt' (Join-Path $projectRoot 'web')
Start-LocalService 'station-001' $Python 'simulator/station.py --device station-001' $projectRoot
Start-LocalService 'station-002' $Python 'simulator/station.py --device station-002' $projectRoot
if (!(Test-LocalPort 8000)) { Start-LocalService 'web' $Php '-S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php' (Join-Path $projectRoot 'web/public') }
for ($attempt = 0; $attempt -lt 20 -and !(Test-LocalPort 8000); $attempt++) { Start-Sleep -Milliseconds 250 }
foreach ($name in @('broker','worker','station-001','station-002','web')) {
    if (!(Test-OwnedService $name)) { throw "Layanan $name tidak berjalan. Periksa .runtime/logs/$name.err.log dan $name.out.log." }
}
if (!(Test-LocalPort 8000)) { throw 'Server web belum siap. Periksa .runtime/logs/web.err.log.' }
Write-Host 'Petstation lokal: http://127.0.0.1:8000'
Write-Host 'Log layanan: .runtime/logs'
