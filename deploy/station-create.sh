#!/usr/bin/env bash
# Daftarkan station baru tanpa pemilik: kredensial broker, ACL, dan kode klaim sekali pakai.
# Pemakaian: sudo bash /var/www/petstation/deploy/station-create.sh <device-id> "<nama>" [--physical]
#   --physical  perangkat ESP32; secrets.h disimpan di /etc/petstation/firmware-secrets-<device-id>.h
#   tanpa opsi  station simulasi; simulator langsung dijalankan sebagai layanan systemd
set -euo pipefail
APP=/var/www/petstation
ETC=/etc/petstation
DEVICE="${1:-}"; NAME="${2:-}"; PHYSICAL="${3:-}"
[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo." >&2; exit 1; }
[[ "$DEVICE" =~ ^[a-zA-Z0-9_-]{1,80}$ ]] && [ -n "$NAME" ] || { echo 'Pemakaian: station-create.sh <device-id> "<nama>" [--physical]' >&2; exit 1; }
[ -z "$PHYSICAL" ] || [ "$PHYSICAL" = "--physical" ] || { echo "Opsi tidak dikenal: $PHYSICAL" >&2; exit 1; }
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Station dibuat lebih dulu; jika ID sudah terpakai, kredensial broker tidak disentuh.
sudo -u www-data php "$APP/web/artisan" station:create "$DEVICE" "$NAME" $PHYSICAL

python3 - "$ETC/credentials.json" "$DEVICE" <<'PY'
import json, secrets, sys
path, device = sys.argv[1], sys.argv[2]
data = json.load(open(path))
data['devices'].setdefault(device, secrets.token_hex(24))
with open(path, 'w') as handle:
    json.dump(data, handle, indent=2)
PY
bash "$DIR/broker-sync.sh"

if [ "$PHYSICAL" = "--physical" ]; then
  bash "$DIR/firmware-secrets.sh" "$DEVICE" > "$ETC/firmware-secrets-$DEVICE.h"
  chmod 600 "$ETC/firmware-secrets-$DEVICE.h"
  echo "secrets.h perangkat: sudo cat $ETC/firmware-secrets-$DEVICE.h"
else
  systemctl enable --now "petstation-sim@$DEVICE" >/dev/null
  echo "Simulator petstation-sim@$DEVICE berjalan."
fi
echo "Berikan ID perangkat dan kode klaim di atas kepada pemilik. Pemilik memasukkannya di halaman Station saya > Tambah station."
