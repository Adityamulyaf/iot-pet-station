"""Prepare isolated local services and environment. Never prints credentials."""
import argparse
import json
import os
import secrets
import socket
import subprocess
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / '.runtime'


def wait_port(port, seconds=20):
    for _ in range(seconds * 4):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.3):
                return True
        except OSError:
            time.sleep(.25)
    return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mysql-bin', default='C:/xampp/mysql/bin')
    args = parser.parse_args()
    mysql_bin = Path(args.mysql_bin)
    RUNTIME.mkdir(exist_ok=True)
    (RUNTIME / 'logs').mkdir(exist_ok=True)
    credentials_path = RUNTIME / 'credentials.json'
    if credentials_path.exists():
        credentials = json.loads(credentials_path.read_text())
    else:
        credentials = {'database': secrets.token_hex(24), 'root': secrets.token_hex(24), 'backend': secrets.token_hex(24), 'devices': {device: secrets.token_hex(24) for device in ['station-001', 'station-002']}}
        credentials_path.write_text(json.dumps(credentials, indent=2))
    database = RUNTIME / 'mariadb'
    fresh = not (database / 'mysql').exists()
    if fresh:
        subprocess.run([str(mysql_bin / 'mysql_install_db.exe'), '--datadir=' + str(database), '--port=3307', '--silent'], check=True)
    pid_file = RUNTIME / 'database.pid'
    if not wait_port(3307, seconds=1):
        log = open(RUNTIME / 'logs' / 'database.log', 'a')
        proc = subprocess.Popen([str(mysql_bin / 'mysqld.exe'), '--no-defaults', '--basedir=' + str(mysql_bin.parent), '--datadir=' + str(database), '--port=3307', '--bind-address=127.0.0.1', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci', '--pid-file=' + str(pid_file)], stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        if not wait_port(3307):
            raise RuntimeError('MariaDB did not start. Inspect .runtime/logs/database.log')
    elif not pid_file.exists():
        raise RuntimeError('Port 3307 is occupied by another service. No changes made to that service.')
    client_config = RUNTIME / 'database-client.ini'
    if not fresh:
        client_config.write_text('[client]\nuser=root\nhost=127.0.0.1\nport=3307\npassword=' + credentials['root'] + '\n')
        client_args = ['--defaults-extra-file=' + str(client_config)]
    else:
        client_args = ['--no-defaults', '--user=root', '--host=127.0.0.1', '--port=3307']
    sql = "CREATE DATABASE IF NOT EXISTS petstation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
    sql += "CREATE USER IF NOT EXISTS 'petstation'@'127.0.0.1' IDENTIFIED BY '" + credentials['database'] + "';\n"
    sql += "GRANT ALL PRIVILEGES ON petstation.* TO 'petstation'@'127.0.0.1';\n"
    if fresh:
        sql += "ALTER USER 'root'@'localhost' IDENTIFIED BY '" + credentials['root'] + "';\n"
    subprocess.run([str(mysql_bin / 'mysql.exe'), *client_args], input=sql, text=True, check=True, stdout=subprocess.DEVNULL)
    client_config.write_text('[client]\nuser=root\nhost=127.0.0.1\nport=3307\npassword=' + credentials['root'] + '\n')
    password_file = RUNTIME / 'mqtt-passwords'
    password_file.write_text('backend:' + credentials['backend'] + '\n' + ''.join(key + ':' + value + '\n' for key, value in credentials['devices'].items()))
    subprocess.run([str(RUNTIME / 'mosquitto' / 'mosquitto_passwd.exe'), '-U', str(password_file)], check=True, stdout=subprocess.DEVNULL)
    acl = ['user backend', 'topic read petstation/+/telemetry', 'topic read petstation/+/state', 'topic read petstation/+/result', 'topic write petstation/+/command', 'topic write petstation/+/ack', '']
    for device in credentials['devices']:
        acl.extend(['user ' + device, *['topic write petstation/' + device + '/' + kind for kind in ['telemetry', 'state', 'result']], *['topic read petstation/' + device + '/' + kind for kind in ['command', 'ack']], ''])
    (RUNTIME / 'mqtt-acl').write_text('\n'.join(acl))
    normalized = RUNTIME.as_posix()
    (RUNTIME / 'mosquitto.conf').write_text(f'listener 1883 127.0.0.1\nallow_anonymous false\npassword_file {normalized}/mqtt-passwords\nacl_file {normalized}/mqtt-acl\npersistence false\nlog_type error\nlog_type warning\n')
    env_path = ROOT / 'web' / '.env'
    env = env_path.read_text() if env_path.exists() else (ROOT / 'web' / '.env.example').read_text()
    updates = {'APP_NAME': 'Petstation', 'APP_URL': 'http://127.0.0.1:8000', 'DB_CONNECTION': 'mariadb', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3307', 'DB_DATABASE': 'petstation', 'DB_USERNAME': 'petstation', 'DB_PASSWORD': credentials['database'], 'SESSION_DRIVER': 'file', 'CACHE_STORE': 'file', 'MQTT_HOST': '127.0.0.1', 'MQTT_PORT': '1883', 'MQTT_USERNAME': 'backend', 'MQTT_PASSWORD': credentials['backend'], 'MQTT_TLS': 'false'}
    lines = env.splitlines()
    for key, value in updates.items():
        found = False
        for index, line in enumerate(lines):
            if line.startswith(key + '='):
                lines[index] = key + '=' + value
                found = True
        if not found:
            lines.append(key + '=' + value)
    env_path.write_text('\n'.join(lines) + '\n')
    print('Local MariaDB, MQTT credentials, and Laravel environment prepared.')


if __name__ == '__main__':
    main()
