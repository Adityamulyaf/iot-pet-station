#!/usr/bin/env bash
# Cetak secrets.h untuk satu perangkat ESP32 yang terhubung ke broker cloud.
# Pemakaian: sudo bash firmware-secrets.sh <device-id>
set -euo pipefail
ETC=/etc/petstation
DEVICE="${1:?Pemakaian: firmware-secrets.sh <device-id>}"
[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo." >&2; exit 1; }

PASSWORD="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["devices"][sys.argv[2]])' "$ETC/credentials.json" "$DEVICE")" \
  || { echo "Perangkat $DEVICE belum punya kredensial broker." >&2; exit 1; }
echo '// Dibuat oleh deploy/firmware-secrets.sh untuk broker cloud. Jangan di-commit.'
echo '#pragma once'
echo '#define WIFI_SSID "NAMA_WIFI"'
echo '#define WIFI_PASSWORD "PASSWORD_WIFI"'
echo "#define MQTT_HOST \"$(cat "$ETC/domain")\""
echo '#define MQTT_PORT 8883'
echo '#define MQTT_TLS 1'
echo "#define DEVICE_ID \"$DEVICE\""
echo "#define MQTT_PASSWORD \"$PASSWORD\""
echo 'static const char MQTT_CA_CERT[] = R"PEM('
cat "$ETC/ca/ca.crt"
echo ')PEM";'
