# Firmware ESP32 Petstation

Firmware `petstation_esp32` memakai kontrak MQTT yang sama dengan `simulator/station.py`, jadi web tidak perlu diubah. Komponen yang dipakai: PIR, load cell + HX711, water level sensor, mini servo (pakan), dan pompa lewat XY-MOS. Buzzer tidak dipakai.

## Pin

| Komponen | Pin ESP32 |
|---|---|
| PIR OUT | GPIO 13 |
| HX711 DT / SCK | GPIO 19 / GPIO 21 |
| Water level AO | GPIO 34 |
| XY-MOS signal (pompa) | GPIO 26 |
| Servo signal | GPIO 27 (VCC servo dari 5V eksternal, GND disatukan dengan ESP32) |

## Library (Arduino IDE > Library Manager)

- HX711 (bogde)
- ESP32Servo (Kevin Harrington)
- PubSubClient (Nick O'Leary)
- ArduinoJson 7.x (Benoit Blanchon)

Board: ESP32 Dev Module (paket `esp32` by Espressif). Sudah diuji dengan core 2.0.17, yang jauh lebih kecil daripada 3.x (3.x memasang library untuk semua chip ESP32 dan butuh beberapa GB).

## Langkah

1. Jalankan web dengan broker terbuka ke LAN (hentikan dulu jika sedang berjalan):
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stop-local.ps1
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1 -Lan
   ```
   Langkah ini membuat kredensial `station-esp32`, ACL broker, dan file `firmware/petstation_esp32/secrets.h` (diabaikan Git) berisi IP laptop dan password MQTT.
2. Daftarkan station ke akun (sekali saja, jika setup awal dilakukan sebelum perubahan ini):
   ```powershell
   cd web
   php artisan db:seed --force
   ```
   atau `php artisan station:provision station-esp32 demo@petstation.local "Station ESP32" --physical`.
3. Izinkan port 1883 di Windows Firewall (PowerShell admin):
   ```powershell
   New-NetFirewallRule -DisplayName "Petstation MQTT" -Direction Inbound -Protocol TCP -LocalPort 1883 -Action Allow -Profile Private
   ```
4. Isi `WIFI_SSID` dan `WIFI_PASSWORD` di `secrets.h`. ESP32 dan laptop harus di jaringan Wi-Fi yang sama (2.4 GHz). Jika IP laptop berubah, jalankan ulang `start-local.ps1 -Lan` lalu upload ulang.
5. Upload `petstation_esp32.ino`, buka Serial Monitor 115200 (line ending: Newline).

## Kalibrasi (lewat Serial Monitor, tersimpan di flash)

| Perintah | Kegunaan |
|---|---|
| `info` | Tampilkan berat, ADC air, PIR, status Wi-Fi/MQTT |
| `tare` | Wadah pakan kosong di atas load cell, set nol |
| `cal 200` | Letakkan beban acuan (mis. 200 g), hitung faktor skala |
| `wkering` | Sensor air kering / wadah air kosong |
| `wpenuh` | Wadah air penuh sampai batas sensor |
| `servo 120` | Putar servo ke sudut tertentu (0-180) tanpa menyimpan, untuk mencari posisi |
| `stutup` | Simpan sudut terakhir dari `servo` sebagai posisi tutup |
| `sbuka` | Simpan sudut terakhir dari `servo` sebagai posisi buka, lalu servo kembali menutup |

Persentase air adalah posisi relatif antara kalibrasi kering dan penuh, bukan persentase volume.

Servo hanya berputar 0-180 derajat. Jika posisi tutup yang pas berada di luar rentang itu, lepas horn atau penutup dari poros, putar servo ke sudut di tengah rentang (mis. `servo 150`), pasang ulang penutup dalam keadaan menutup, lalu simpan dengan `stutup`.

## Perilaku

- Telemetry setiap 5 detik, PIR dilaporkan aktif jika ada gerakan sejak telemetry sebelumnya.
- Waktu dari NTP (wajib, karena backend menolak pesan dengan waktu tidak wajar), jadi jaringan perlu akses internet.
- Beri Pakan: servo ke 90 derajat selama `duration_ms` (2 detik dari web) lalu kembali ke 0. Salurkan Air: pompa menyala selama durasi yang sama.
- Validasi lokal: kedaluwarsa, aktuator sibuk, sensor tidak valid, pakan < 30 g, air < 20%, cooldown 10 detik, durasi 0,5 sampai 3 detik.
- Aktuator dihentikan berdasarkan timer lokal walaupun koneksi putus. Hasil akhir dikirim ulang setiap 10 detik sampai ACK aplikasi diterima.
- Jika ESP32 restart saat aktuator berjalan, perintah tersebut dilaporkan gagal dan tidak diulang.
- Sudut servo (`SERVO_OPEN_DEG`) dan batas di bagian atas sketch dapat disesuaikan dengan mekanisme fisik.
