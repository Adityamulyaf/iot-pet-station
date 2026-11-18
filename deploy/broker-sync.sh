#!/usr/bin/env bash
# Tulis ulang password file dan ACL Mosquitto dari /etc/petstation/credentials.json, lalu muat ulang broker.
# Dipanggil oleh setup-server.sh dan station-create.sh.
set -euo pipefail
ETC=/etc/petstation
[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo." >&2; exit 1; }

python3 - "$ETC/credentials.json" > /etc/mosquitto/petstation.passwd <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
print('backend:' + data['backend'])
for device, password in data['devices'].items():
    print(device + ':' + password)
PY
mosquitto_passwd -U /etc/mosquitto/petstation.passwd
{
  printf 'user backend\ntopic read petstation/+/telemetry\ntopic read petstation/+/state\ntopic read petstation/+/result\ntopic write petstation/+/command\ntopic write petstation/+/ack\n\n'
  for device in $(python3 -c 'import json,sys; print(" ".join(json.load(open(sys.argv[1]))["devices"]))' "$ETC/credentials.json"); do
    printf 'user %s\n' "$device"
    for kind in telemetry state result; do printf 'topic write petstation/%s/%s\n' "$device" "$kind"; done
    for kind in command ack; do printf 'topic read petstation/%s/%s\n' "$device" "$kind"; done
    printf '\n'
  done
} > /etc/mosquitto/petstation.acl
chown mosquitto:mosquitto /etc/mosquitto/petstation.passwd /etc/mosquitto/petstation.acl
chmod 600 /etc/mosquitto/petstation.passwd /etc/mosquitto/petstation.acl

# SIGHUP membuat Mosquitto membaca ulang password file dan ACL tanpa memutus perangkat lain.
if systemctl is-active --quiet mosquitto; then systemctl reload mosquitto; fi
