#!/usr/bin/env bash
# Pasang atau perbarui Petstation di VM Ubuntu 24.04 (Azure for Students atau Oracle Cloud).
# Aman dijalankan ulang: kredensial, CA MQTT, dan database yang sudah ada dipertahankan.
#
#   sudo bash setup-server.sh --domain <domain-vm> [--repo URL] [--branch main] [--no-simulator]
set -euo pipefail

DOMAIN=""
REPO="https://github.com/Adityamulyaf/iot-pet-station.git"
BRANCH="main"
SIMULATOR=1
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --repo) REPO="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --no-simulator) SIMULATOR=0; shift ;;
    *) echo "Argumen tidak dikenal: $1" >&2; exit 1 ;;
  esac
done
[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo." >&2; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "Gunakan --domain nama.duckdns.org" >&2; exit 1; }
. /etc/os-release
[ "$VERSION_ID" = "24.04" ] || { echo "Script ini untuk Ubuntu 24.04 (terdeteksi $PRETTY_NAME)." >&2; exit 1; }

APP=/var/www/petstation
ETC=/etc/petstation
DEVICES=(station-001 station-002 station-esp32)
export DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1
log() { printf '\n==> %s\n' "$*"; }
artisan() { sudo -u www-data php "$APP/web/artisan" "$@"; }

log "Paket sistem"
apt-get update -q
apt-get install -y -q git curl unzip openssl python3 python3-venv \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl \
  composer mariadb-server mosquitto caddy
# Node 24 (npm 11) sama dengan lingkungan pembuat package-lock.json; npm 10 menolak lockfile tersebut.
if ! node -v 2>/dev/null | grep -qE '^v(2[4-9]|[3-9][0-9])\.'; then
  curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
  apt-get install -y -q nodejs
fi

# VM kecil (1 GB) butuh swap agar composer dan build aset tidak kehabisan memori.
if [ "$(awk '/MemTotal/ {print $2}' /proc/meminfo)" -lt 2000000 ] && [ ! -f /swapfile ]; then
  log "Membuat swap 2 GB"
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Image Ubuntu Oracle menolak semua port selain 22 lewat iptables di dalam VM. Azure tidak,
# karena port diatur di Network Security Group, jadi langkah ini hanya berjalan jika ada aturan REJECT.
if iptables -S INPUT | grep -q -- '-j REJECT'; then
  log "Firewall VM (80, 443, 8883)"
  echo "iptables-persistent iptables-persistent/autosave_v4 boolean true" | debconf-set-selections
  echo "iptables-persistent iptables-persistent/autosave_v6 boolean true" | debconf-set-selections
  apt-get install -y -q iptables-persistent
  for port in 80 443 8883; do
    iptables -C INPUT -p tcp --dport "$port" -m conntrack --ctstate NEW -j ACCEPT 2>/dev/null \
      || iptables -I INPUT -p tcp --dport "$port" -m conntrack --ctstate NEW -j ACCEPT
  done
  netfilter-persistent save >/dev/null
fi

log "Kredensial"
install -d -m 750 -o root -g www-data "$ETC"
python3 - "$ETC/credentials.json" "${DEVICES[@]}" <<'PY'
import json, os, secrets, sys
path, devices = sys.argv[1], sys.argv[2:]
data = json.load(open(path)) if os.path.exists(path) else {}
data.setdefault('database', secrets.token_hex(24))
data.setdefault('backend', secrets.token_hex(24))
data.setdefault('devices', {})
for device in devices:
    data['devices'].setdefault(device, secrets.token_hex(24))
with open(path, 'w') as handle:
    json.dump(data, handle, indent=2)
PY
chown root:www-data "$ETC/credentials.json" && chmod 640 "$ETC/credentials.json"
echo "$DOMAIN" > "$ETC/domain"
cred() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1]))
for key in sys.argv[2:]: d=d[key]
print(d)' "$ETC/credentials.json" "$@"; }

log "Kode aplikasi ($BRANCH)"
if [ -d "$APP/.git" ]; then
  git -C "$APP" fetch -q origin "$BRANCH"
  git -C "$APP" reset -q --hard "origin/$BRANCH"
else
  git clone -q -b "$BRANCH" "$REPO" "$APP"
fi
install -d -m 750 -o www-data -g www-data "$APP/.runtime"
ln -sfn "$ETC/credentials.json" "$APP/.runtime/credentials.json"

log "Database"
systemctl enable --now mariadb >/dev/null
DB_PASSWORD="$(cred database)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS petstation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'petstation'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'petstation'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON petstation.* TO 'petstation'@'localhost';
SQL

log "Broker MQTT (TLS 8883 untuk perangkat, 1883 hanya lokal)"
install -d -m 700 "$ETC/ca"
if [ ! -f "$ETC/ca/ca.key" ]; then
  printf '[req]\nprompt=no\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\nCN=Petstation MQTT CA\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n' > "$ETC/ca/ca.cnf"
  openssl req -x509 -new -nodes -newkey rsa:2048 -sha256 -days 3650 -config "$ETC/ca/ca.cnf" \
    -keyout "$ETC/ca/ca.key" -out "$ETC/ca/ca.crt"
fi
CERTS=/etc/mosquitto/certs
install -d -m 750 -o mosquitto -g mosquitto "$CERTS"
if [ ! -f "$CERTS/server.crt" ] || ! openssl x509 -in "$CERTS/server.crt" -noout -checkhost "$DOMAIN" | grep -q 'does match'; then
  openssl req -new -nodes -newkey rsa:2048 -subj "/CN=$DOMAIN" -keyout "$CERTS/server.key" -out "$ETC/ca/server.csr"
  printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:%s\n' "$DOMAIN" > "$ETC/ca/server.ext"
  openssl x509 -req -sha256 -days 3650 -in "$ETC/ca/server.csr" -CA "$ETC/ca/ca.crt" -CAkey "$ETC/ca/ca.key" -CAcreateserial \
    -extfile "$ETC/ca/server.ext" -out "$CERTS/server.crt"
fi
chown mosquitto:mosquitto "$CERTS"/server.* && chmod 600 "$CERTS/server.key"
bash "$APP/deploy/broker-sync.sh"
cat > /etc/mosquitto/conf.d/petstation.conf <<CONF
allow_anonymous false
password_file /etc/mosquitto/petstation.passwd
acl_file /etc/mosquitto/petstation.acl

listener 1883 127.0.0.1

listener 8883
certfile $CERTS/server.crt
keyfile $CERTS/server.key
CONF
systemctl enable mosquitto >/dev/null && systemctl restart mosquitto

log "Konfigurasi Laravel"
cd "$APP/web"
[ -f .env ] || cp .env.example .env
python3 - .env <<PY
import sys
path = sys.argv[1]
updates = {
    'APP_NAME': 'Petstation', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_URL': 'https://$DOMAIN',
    'LOG_LEVEL': 'warning',
    'DB_CONNECTION': 'mariadb', 'DB_HOST': 'localhost', 'DB_SOCKET': '/run/mysqld/mysqld.sock', 'DB_PORT': '3306',
    'DB_DATABASE': 'petstation', 'DB_USERNAME': 'petstation', 'DB_PASSWORD': '$DB_PASSWORD',
    'SESSION_DRIVER': 'file', 'SESSION_SECURE_COOKIE': 'true', 'CACHE_STORE': 'file',
    'MQTT_HOST': '127.0.0.1', 'MQTT_PORT': '1883', 'MQTT_USERNAME': 'backend', 'MQTT_PASSWORD': '$(cred backend)', 'MQTT_TLS': 'false',
}
lines = open(path).read().splitlines()
for key, value in updates.items():
    matches = [i for i, line in enumerate(lines) if line.startswith(key + '=')]
    if matches:
        for i in matches:
            lines[i] = key + '=' + value
    else:
        lines.append(key + '=' + value)
open(path, 'w').write('\n'.join(lines) + '\n')
PY
chown root:www-data .env && chmod 640 .env

log "Dependensi dan build aset"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
# Lockfile memuat dependensi opsional (ink/react) yang tidak lengkap; jika npm ci menolaknya,
# npm install tetap memakai versi dari lockfile tanpa mengubah file di repository.
npm ci --no-audit --no-fund --loglevel=error || npm install --no-save --no-audit --no-fund --loglevel=error
npm run build --silent
install -d storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction
chown root:www-data .env && chmod 640 .env
artisan migrate --force --no-interaction
artisan optimize

log "Layanan"
cat > /etc/systemd/system/petstation-mqtt.service <<UNIT
[Unit]
Description=Petstation MQTT worker
After=network-online.target mariadb.service mosquitto.service
Wants=network-online.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=$APP/web
ExecStart=/usr/bin/php artisan station:mqtt
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/petstation-schedule.service <<UNIT
[Unit]
Description=Petstation jadwal pakan dan air
After=network-online.target mariadb.service petstation-mqtt.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=$APP/web
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/petstation-sim@.service <<UNIT
[Unit]
Description=Petstation simulator %i
After=mosquitto.service petstation-mqtt.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=$APP
ExecStart=$APP/.venv/bin/python simulator/station.py --device %i
KillSignal=SIGINT
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable petstation-mqtt >/dev/null && systemctl restart petstation-mqtt
systemctl enable petstation-schedule >/dev/null && systemctl restart petstation-schedule
if [ "$SIMULATOR" -eq 1 ]; then
  [ -x "$APP/.venv/bin/python" ] || python3 -m venv "$APP/.venv"
  "$APP/.venv/bin/pip" install -q -r "$APP/simulator/requirements.txt"
  for device in station-001 station-002; do
    systemctl enable "petstation-sim@$device" >/dev/null && systemctl restart "petstation-sim@$device"
  done
else
  for device in station-001 station-002; do systemctl disable --now "petstation-sim@$device" >/dev/null 2>&1 || true; done
fi

cat > /etc/caddy/Caddyfile <<CADDY
$DOMAIN {
	root * $APP/web/public
	encode zstd gzip
	php_fastcgi unix//run/php/php8.3-fpm.sock
	file_server
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options nosniff
		Referrer-Policy strict-origin-when-cross-origin
		-Server
	}
}
CADDY
usermod -aG www-data caddy
systemctl enable php8.3-fpm caddy >/dev/null
systemctl restart php8.3-fpm caddy

log "secrets.h untuk ESP32"
bash "$APP/deploy/firmware-secrets.sh" station-esp32 > "$ETC/firmware-secrets.h"
chmod 600 "$ETC/firmware-secrets.h"

for service in mariadb mosquitto php8.3-fpm caddy petstation-mqtt petstation-schedule; do
  systemctl is-active --quiet "$service" || { echo "Layanan $service tidak berjalan. Periksa: journalctl -u $service -n 50" >&2; exit 1; }
done
log "Selesai: https://$DOMAIN"
cat <<NEXT
Langkah berikutnya (sekali saja):
  sudo -u www-data php $APP/web/artisan station:user email@anda.com "Nama Anda"
  sudo -u www-data php $APP/web/artisan station:provision station-esp32 email@anda.com "Station ESP32" --physical
  sudo -u www-data php $APP/web/artisan station:provision station-001 email@anda.com "Station simulasi 1"
  sudo -u www-data php $APP/web/artisan station:provision station-002 email@anda.com "Station simulasi 2"
Ambil secrets.h untuk ESP32: sudo cat $ETC/firmware-secrets.h
Station baru dengan kode klaim: sudo bash $APP/deploy/station-create.sh <device-id> "<nama>" [--physical]
NEXT
