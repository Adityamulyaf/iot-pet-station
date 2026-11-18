# Rencana lanjutan Petstation

Status per 18 November 2026. Urutan kerja yang disarankan: 1 → 2 → 3 → 4. Bagian 5 berisi tugas pemeliharaan.

## Kondisi saat ini

| Komponen | Status |
|---|---|
| Server | Azure for Students, VM `petstation` (Standard_B2ats_v2, x64, 1 GB + swap 2 GB), region East Asia, resource group `petstation-rg` |
| Web | <https://petstation.eastasia.cloudapp.azure.com>, HTTPS Let's Encrypt via Caddy |
| Broker | Mosquitto: TLS `8883` (publik, CA sendiri), `1883` hanya `127.0.0.1` |
| Layanan | MariaDB, PHP-FPM, Caddy, `petstation-mqtt`, `petstation-sim@station-001`, `petstation-sim@station-002` (systemd) |
| NSG Azure | Inbound 22, 80, 443, 8883 |
| Station terdaftar | `station-001`, `station-002` (simulasi, data masuk), `station-esp32` (menunggu perangkat) |
| Akses SSH | `ssh -i ~/.ssh/petstation_key.pem azureuser@petstation.eastasia.cloudapp.azure.com` |
| Kredensial server | `/etc/petstation/credentials.json`, CA MQTT di `/etc/petstation/ca/`, secrets ESP32 di `/etc/petstation/firmware-secrets.h` |

Update server: push ke `main`, lalu jalankan script dari URL commit (bukan `main`, karena `raw.githubusercontent.com` menyimpan cache beberapa menit):

```bash
SHA=<hash commit>
curl -fsSL https://raw.githubusercontent.com/Adityamulyaf/iot-pet-station/$SHA/deploy/setup-server.sh -o setup-server.sh
sudo bash setup-server.sh --domain petstation.eastasia.cloudapp.azure.com
```

## 1. Hubungkan ESP32 ke server cloud

Tujuan: ESP32 bisa dipakai dari Wi-Fi mana pun yang punya internet, tidak perlu satu jaringan dengan laptop.

- [ ] Ambil `/etc/petstation/firmware-secrets.h` dari server, gabungkan dengan `firmware/petstation_esp32/secrets.h` lokal. Bagian MQTT (`MQTT_HOST`, `MQTT_PORT 8883`, `MQTT_TLS 1`, `MQTT_PASSWORD`, `MQTT_CA_CERT`) dari server, `WIFI_SSID`/`WIFI_PASSWORD` dari file lokal.
- [ ] Compile dan upload dari Arduino IDE. Firmware dengan `MQTT_TLS 1` belum pernah dikompilasi, jadi perhatikan error compile pertama.
- [ ] Cek Serial Monitor: harus muncul `MQTT connect... ok`. `rc=-2` berarti port/NTP, `rc=5` berarti password.
- [ ] Cek dashboard: `Station ESP32` online, telemetry masuk, perintah Beri Pakan menaikkan berat wadah.

Catatan: `start-local.ps1` tidak menimpa `secrets.h` yang berisi `MQTT_TLS 1`. Untuk kembali ke broker laptop, hapus `secrets.h` lalu jalankan `start-local.ps1 -Lan`.

## 2. Portal setup Wi-Fi di ESP32

Masalah: SSID dan password Wi-Fi tertanam di firmware, jadi pindah Wi-Fi (misalnya saat presentasi di kampus) mengharuskan edit dan upload ulang.

Rencana:

- [ ] Pakai library WiFiManager (tzapu) dalam mode **non-blocking**, supaya `tickExecution()` tetap menghentikan servo/pompa tepat waktu.
- [ ] Jika gagal terhubung ke Wi-Fi tersimpan dalam ±20 detik, ESP32 membuka hotspot `Petstation-Setup` (dengan password) dan portal captive untuk memilih SSID serta mengisi password.
- [ ] Kredensial Wi-Fi disimpan di flash (NVS). `WIFI_SSID`/`WIFI_PASSWORD` di `secrets.h` menjadi nilai awal opsional.
- [ ] Perintah Serial `wifireset` untuk menghapus Wi-Fi tersimpan dan membuka portal lagi. Opsional: tahan tombol BOOT 5 detik.
- [ ] Perbarui `firmware/README.md`.

## 3. Register akun dan klaim station dengan kode

Status: selesai (18 November 2026). Station baru: `sudo bash /var/www/petstation/deploy/station-create.sh <device-id> "<nama>" [--physical]`; kode baru: `station:code <device-id>`.

Masalah awal: akun dan station hanya bisa dibuat lewat CLI di server.

Desain (pola kode klaim):

1. **Admin/pembuat alat** menjalankan `station:create <device-id> "<nama>" [--physical]`. Perintah ini membuat kredensial broker, memperbarui password file dan ACL Mosquitto, lalu mencetak **kode klaim** acak (misalnya `K7Q2-9XMP`) dan `secrets.h` perangkat. Kode ditempel di alat (stiker/QR).
2. **Pengguna** register sendiri di halaman **Daftar**.
3. **Pengguna** membuka **Tambah station**, mengisi ID perangkat dan kode klaim. Jika cocok dan station belum punya pemilik, station masuk ke akunnya dan kode hangus.

Pekerjaan:

- [x] Halaman register (nama, email, password minimal 10 karakter, konfirmasi) mengikuti gaya `auth/login.blade.php`, rate limit per IP.
- [x] Migrasi: `stations.user_id` boleh null (belum diklaim), kolom `claim_code_hash` dan `claimed_at`.
- [x] Halaman/aksi **Tambah station** dengan rate limit percobaan klaim. Pesan gagal yang sama untuk ID salah dan kode salah, supaya ID tidak bisa ditebak.
- [x] Aksi **Lepas station** (pemilik melepas kepemilikan, kode klaim baru dibuat oleh admin).
- [x] Perintah `station:create` menggantikan langkah provisioning manual. Penulisan konfigurasi Mosquitto dijalankan sebagai root lewat CLI, bukan dari proses web.
- [x] Tes fitur: register, klaim berhasil, klaim kode salah, klaim station yang sudah dimiliki, rate limit, akses lintas akun tetap ditolak.
- [x] Perbarui `deploy/README.md` dan `docs/PRD.md` (bagian provisioning).

## 4. Akses bersama (untuk dosen atau anggota keluarga)

Masalah: satu station hanya punya satu pemilik, sehingga akun lain melihat dashboard kosong.

Rencana:

- [ ] Tabel `station_user` (station, user, peran `viewer` atau `operator`).
- [ ] `viewer`: melihat dashboard, riwayat, analytics. `operator`: juga bisa Beri Pakan/Salurkan Air. Pengaturan station dan berbagi akses hanya untuk pemilik.
- [ ] Policy Laravel menggantikan pengecekan `user_id` langsung di `StationApp` dan `CommandService`.
- [ ] Menu **Bagikan** di Pengaturan station: undang lewat email akun yang sudah terdaftar, pilih peran, cabut akses.
- [ ] Tes: viewer tidak bisa mengirim perintah, akses dicabut langsung berlaku, pengguna tanpa akses tetap ditolak.

Alternatif sementara sebelum fitur ini ada: dosen login memakai akun pemilik.

## 5. Pemeliharaan dan catatan

- [ ] **Biaya Azure**: 2–3 hari setelah VM berjalan (sekitar 21 November 2026), cek **Cost Management > Cost analysis**. Biaya VM B2ats_v2 dan disk P6 seharusnya mendekati $0 (jatah gratis 12 bulan). Portal menampilkan harga normal $9.56/bulan saat membuat VM.
- [ ] **Masa berlaku**: kredit Azure for Students berakhir 18 November 2027. Perpanjang lewat portal Education jika masih berstatus mahasiswa.
- [ ] **Lockfile npm**: `web/package-lock.json` tidak mencatat `react`, yang dibutuhkan `ink` (dependensi opsional dari `@laravel/multiplex`), sehingga `npm ci` gagal di mesin mana pun. Deploy memakai fallback `npm install --no-save`. Regenerasi lockfile dengan `npm install --package-lock-only` di npm 11 malah menghapus entri `@emnapi/*`, jadi perlu dicari solusi yang benar (misalnya update `@laravel/multiplex`, atau tambah `react` sebagai devDependency jika memang dibutuhkan).
- [ ] **`web/AGENTS.md`**: ada perubahan lokal yang belum di-commit (tab nyasar di judul `# Laravel Boost`). Kemungkinan tidak sengaja; kembalikan dengan `git checkout web/AGENTS.md` jika benar.
- [ ] **Data lama simulator lokal**: `.runtime/simulator/*.json` di laptop masih berisi riwayat dari logika lama (isi wadah berkurang saat perintah). Hapus atau biarkan; tidak memengaruhi server.
- [ ] **Region Azure**: subscription hanya mengizinkan koreacentral, australiaeast, indonesiacentral, eastasia, japaneast. Dari kelimanya, hanya East Asia yang menyediakan B2ats_v2 (x64). Cadangan: Japan East atau Australia East dengan B2pts_v2 (ARM) dan image Ubuntu 24.04 Arm64.
- [ ] **Keamanan opsional**: batasi SSH (port 22) di NSG hanya dari IP Anda, atau tutup dan buka saat perlu.
