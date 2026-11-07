# Petstation

Web lokal untuk Smart Pet Feeder & Hydration Station. Menggunakan Laravel 13, Livewire 4, Tailwind CSS 4, Chart.js 4, MariaDB, Mosquitto 2.1.2, dan simulator Python dengan Paho MQTT 2.1.0. Versi dependensi yang terpasang dikunci di `composer.lock` dan `package-lock.json`.

PRD berada di [docs/PRD.md](../docs/PRD.md). UI menggunakan bahasa Indonesia, warna netral dengan aksen hijau, tanpa emoji dan em dash.

## Menjalankan lokal di Windows

Jalankan dari folder `iot-pet-station`, satu tingkat di atas folder ini.

Prasyarat: PHP 8.3+ dengan ekstensi PDO MySQL, SQLite untuk pengujian, Composer, Node.js yang kompatibel dengan Vite 8, Python 3.10+, dan binary MariaDB. Script menyediakan pencarian PHP Laragon serta menggunakan MariaDB XAMPP secara default. Database lama XAMPP/Laragon tidak digunakan.

Setup pertama:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/setup-local.ps1
```

Path alternatif dapat diberikan melalui `-Php`, `-Composer`, dan `-MysqlBin`. Setup mengunduh binary resmi Mosquitto dan alat ekstraksi ke `.runtime`, memasang dependensi, membuat database terisolasi, menjalankan migrasi, dan membuat akun demo. Setup memerlukan akses internet.

Menjalankan seluruh layanan:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1
```

Buka http://127.0.0.1:8000. Akun development lokal:

- Email: `demo@petstation.local`
- Kata sandi: `petstation-local`

Akun ini hanya di-seed pada lingkungan lokal/testing. Kredensial database dan MQTT dibuat acak saat setup, disimpan di `.runtime/credentials.json` dan `.env` yang diabaikan Git, serta tidak ditampilkan di browser.

Menghentikan layanan proyek:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/stop-local.ps1
```

Port lokal: web `8000`, MariaDB `3307`, MQTT `1883`. Semuanya terikat ke `127.0.0.1`. Log berada di `.runtime/logs`. Jika port MariaDB sudah dimiliki layanan lain, bootstrap berhenti tanpa mengubah layanan tersebut. Konfigurasi saat ini khusus development lokal; koneksi eksternal memerlukan HTTPS dan MQTT/TLS beserta pengelolaan sertifikat dan proses server.

## Fitur yang tersedia

- Login/logout dan pemisahan akses station berdasarkan pemilik akun.
- Daftar dua station simulasi, dashboard sensor, state aktuator, indikator offline, dan data terlambat.
- Konfirmasi perintah pakan/air dengan durasi tetap 2 detik.
- Riwayat berfilter, detail perintah, durasi, pembacaan sebelum/sesudah, dan catatan hasil terlambat.
- Analytics berdasarkan data tersimpan, dengan rata-rata pembacaan valid untuk rentang 1 jam, 24 jam, dan 7 hari.
- Pengubahan nama/lokasi station dan skenario simulator.
- Worker MQTT, outbox perintah di database, deduplikasi pesan, ACK aplikasi setelah commit, dan pelaporan timeout.

Grafik tidak diberi data historis buatan saat seed. Riwayat dan grafik akan bertambah setelah simulator berjalan dan perintah dikirim. Analytics PIR menghitung sampel aktif, bukan jumlah kunjungan hewan. Data reservoir tidak mengukur konsumsi hewan.

## Simulator dan aturan kontrol

Simulator menggunakan topik `petstation/{device_id}/telemetry`, `/state`, `/command`, `/result`, dan `/ack`. Envelope perangkat berisi `schema_version`, `device_id`, `message_id`, serta `recorded_at` UTC. Perintah memiliki `command_id`, `action`, `parameters`, dan `expires_at`. Lihat `simulator/station.py` dan `app/Services/MessageIngestor.php` untuk kontrak serta validasinya.

Telemetry dikirim setiap 5 detik. Pembaruan dashboard menggunakan polling 5 detik. Batas data terlambat 15 detik, offline 20 detik, masa berlaku perintah 15 detik, dan timeout hasil 30 detik. Hasil yang belum diterima mempertahankan kunci kontrol sampai hasil perangkat direkonsiliasi. Perintah yang kedaluwarsa sebelum diterbitkan ditandai gagal.

Skenario dapat dipilih dari Pengaturan:

- Normal: mengisi ulang reservoir dan memulihkan simulator.
- Air rendah: level 8%, menolak penyaluran air.
- Sensor tidak valid: kontrol diblokir.
- Offline: telemetry berhenti dan status offline dilaporkan. Jalur konfigurasi simulasi tetap tersedia agar dapat dipulihkan.
- Hasil terlambat: aktuator tetap berhenti setelah 2 detik, sedangkan laporan hasil ditunda 40 detik.
- Hasil tidak diterima: hasil akhir disimpan simulator tetapi tidak dikirim. Pilih Normal untuk mengirim ulang hasil tersimpan dan merekonsiliasi perintah.
- Pesan duplikat: payload dengan identitas pesan yang sama dikirim ulang untuk menguji deduplikasi.

State simulator serta antrean pesan disimpan di `.runtime/simulator`. Pesan dikirim ulang sampai ACK aplikasi diterima, dengan batas antrean 500 pesan per station. Identitas perintah dipersist sebelum eksekusi, cooldown 10 detik, dan batas durasi 3 detik ditegakkan secara lokal. Restart tidak menjalankan ulang perintah lama.

Untuk penambahan station:

```powershell
cd web
php artisan station:provision station-003 demo@petstation.local "Station kamar"
```

Perintah tersebut mendaftarkan kepemilikan pada aplikasi. Provisioning kredensial dan ACL broker untuk perangkat tambahan masih dilakukan terpisah. Dua station bawaan dikonfigurasi otomatis oleh script setup. UI provisioning perangkat dan integrasi ESP32 fisik belum diimplementasikan.

## Pengembangan dan pengujian

Perintah berikut dijalankan dari folder `web`, dengan PHP 8.3+ pada PATH:

```powershell
php artisan test --compact
npm run build
```

Uji proteksi lokal simulator dari folder `iot-pet-station`:

```powershell
.venv/Scripts/python.exe -m unittest discover -s simulator -p "test_*.py" -v
```

Pengujian backend menggunakan SQLite terpisah, tidak menghapus database lokal MariaDB. Uji mencakup otorisasi, login, deduplikasi, urutan telemetry, kondisi penolakan, konflik perintah, timeout, rekonsiliasi, dan analytics.

Pengujian browser memerlukan layanan lokal aktif dan Microsoft Edge:

```powershell
npx playwright test
```

Pengujian browser mengirim perintah nyata ke simulator, mengubah skenario sementara, menguji hasil terlambat, serta memeriksa tampilan ponsel. Hasilnya disimpan di `../artifacts`. Gunakan workspace development dengan akun demo, bukan perangkat fisik.

Untuk perubahan frontend, jalankan `npm run dev` atau bangun ulang aset dengan `npm run build`. Setelah perubahan pada worker atau layanan PHP yang berjalan lama, hentikan dan jalankan kembali layanan lokal.

## Batas implementasi

Belum mencakup hardware ESP32, kalibrasi sensor fisik, penjadwalan, kamera, aplikasi native, notifikasi eksternal, atau deployment publik. Hasil simulator membuktikan perilaku software yang diuji, bukan akurasi sensor maupun penyaluran fisik.
