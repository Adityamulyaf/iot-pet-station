param([string]$Php = '', [string]$Composer = 'C:/ProgramData/ComposerSetup/bin/composer.phar', [string]$MysqlBin = 'C:/xampp/mysql/bin')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot
Set-Location -LiteralPath $projectRoot
if (!$Php) { $Php = (Get-ChildItem 'C:/laragon/bin/php/*/php.exe' | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
if (!(Test-Path -LiteralPath $Php)) { throw 'Gunakan -Php untuk menentukan PHP 8.3+.' }
$env:PATH = (Split-Path $Php) + ';' + $env:PATH
$env:COMPOSER_HOME = Join-Path $projectRoot '.composer-local'
$env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.composer-cache'
New-Item -ItemType Directory -Force -Path '.runtime/downloads','.runtime/logs' | Out-Null
if (!(Test-Path '.runtime/mosquitto/mosquitto.exe')) {
    Invoke-WebRequest 'https://www.7-zip.org/a/7z2501-x64.exe' -OutFile '.runtime/downloads/7zip.exe'
    New-Item -ItemType Directory -Force -Path '.runtime/7zip' | Out-Null
    tar -xf '.runtime/downloads/7zip.exe' -C '.runtime/7zip' 7z.exe 7z.dll
    Invoke-WebRequest 'https://mosquitto.org/files/binary/win64/mosquitto-2.1.2-install-windows-x64.exe' -OutFile '.runtime/downloads/mosquitto.exe'
    & '.runtime/7zip/7z.exe' x '.runtime/downloads/mosquitto.exe' '-o.runtime/mosquitto' -y | Out-Null
}
if (!(Test-Path '.venv/Scripts/python.exe')) { python -m venv .venv }
& '.venv/Scripts/python.exe' -m pip install -r simulator/requirements.txt
if ($LASTEXITCODE -ne 0) { throw 'Instalasi dependensi Python gagal.' }
Push-Location web
try {
    foreach ($directory in @('bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs')) {
        New-Item -ItemType Directory -Force -Path $directory | Out-Null
    }
    Get-ChildItem bootstrap,storage -Directory -Recurse | ForEach-Object { $_.Attributes = $_.Attributes -band (-bnot [System.IO.FileAttributes]::ReadOnly) }
    & $Php $Composer install --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Instalasi Composer gagal.' }
    npm.cmd ci
    if ($LASTEXITCODE -ne 0) { throw 'Instalasi npm gagal.' }
    npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'Build aset gagal.' }
} finally { Pop-Location }
& '.venv/Scripts/python.exe' scripts/bootstrap.py --mysql-bin $MysqlBin
if ($LASTEXITCODE -ne 0) { throw 'Bootstrap lokal gagal.' }
Push-Location web
try {
    if (!(Select-String -Path '.env' -Pattern '^APP_KEY=base64:')) { & $Php artisan key:generate --no-interaction }
    & $Php artisan migrate --seed --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Migrasi/seed gagal.' }
} finally { Pop-Location }
Write-Host 'Setup selesai. Jalankan ./scripts/start-local.ps1'
