# Product Requirements Document

## 1. Identitas proyek

- **Nama:** Smart Pet Feeder & Hydration Station
- **Tanggal:** 8 November 2026
- **Status:** Implementasi lokal versi pertama tersedia dan diuji menggunakan simulator.
- **Target awal:** Aplikasi web lokal dengan simulator perangkat melalui MQTT.
- **Dokumen sumber:** [Rancangan proyek IoT](https://docs.google.com/document/d/1ycId_ZdRp5TT5tHuDUtER_jy6SWHHhLhpYDUKRLnZyU/edit).

## 2. Latar belakang dan tujuan

Pemilik hewan membutuhkan sarana untuk memantau persediaan pakan dan air serta mengendalikan penyalurannya dari jarak jauh. Proyek ini membangun web dan backend mandiri untuk menerima pembacaan sensor, mengirim perintah, menyimpan riwayat, dan menyajikan analytics.

Pengembangan dimulai menggunakan simulator di lingkungan lokal. Simulator mengikuti kontrak pesan perangkat sehingga ESP32 dapat diintegrasikan kemudian tanpa mengubah alur utama web dan backend. Prototipe fisik ditargetkan satu unit, sedangkan struktur aplikasi mendukung beberapa station dengan identitas dan kepemilikan terpisah.

## 3. Pengguna dan alur utama

Pengguna utama adalah pemilik hewan yang memiliki satu atau beberapa station.

Alur utama:

1. Pengguna login.
2. Pengguna memilih station miliknya.
3. Pengguna melihat persediaan reservoir, kondisi perangkat, dan waktu pembacaan.
4. Pengguna memilih Beri Pakan atau Salurkan Air lalu mengonfirmasi tindakan.
5. Aplikasi menampilkan perkembangan dan hasil perintah.
6. Pengguna meninjau riwayat dan analytics station tersebut.

## 4. Keputusan teknologi

| Bagian      | Teknologi                            | Tanggung jawab                                                  |
| ----------- | ------------------------------------ | --------------------------------------------------------------- |
| Backend     | Laravel                              | Autentikasi, otorisasi, validasi, logika bisnis, dan akses data |
| Frontend    | Blade + Livewire                     | Halaman dan interaksi dashboard                                 |
| Styling     | Tailwind CSS                         | Tampilan responsif                                              |
| Grafik      | Chart.js                             | Visualisasi data historis                                       |
| Database    | MariaDB                              | Penyimpanan pengguna, station, telemetry, state, dan perintah   |
| Broker MQTT | Mosquitto                            | Pertukaran pesan backend dan perangkat                          |
| Worker MQTT | Proses CLI Laravel + php-mqtt/client | Penerimaan pesan, penyimpanan, dan penerbitan perintah          |
| Simulator   | Python + Paho MQTT                   | Simulasi sensor dan respons perangkat                           |

Versi paket ditentukan dan dikunci saat setup setelah kompatibilitas lingkungan lokal diperiksa. Worker MQTT berjalan sebagai proses terpisah dari request web. Pembaruan dashboard menggunakan polling setiap 5 detik; WebSocket belum menjadi kebutuhan versi pertama.

## 5. Cakupan fitur versi pertama

| Halaman            | Kebutuhan                                                                              |
| ------------------ | -------------------------------------------------------------------------------------- |
| Login              | Masuk dan keluar akun; akses hanya ke station milik pengguna                           |
| Daftar station     | Nama, identitas, status koneksi, waktu terakhir terlihat, dan pilihan station          |
| Dashboard station  | Berat pakan, level air, gerakan PIR, state servo/pompa, kualitas dan waktu pembacaan   |
| Kontrol            | Beri Pakan, Salurkan Air, konfirmasi, status proses, dan alasan penolakan/kegagalan    |
| Riwayat            | Daftar perintah dan detail hasil; filter tanggal, jenis, dan status                    |
| Analytics          | Grafik persediaan pakan dan level air, jumlah perintah menurut hasil, dan kejadian PIR |
| Pengaturan station | Nama station serta informasi identitas dan konfigurasi yang relevan                    |

Penambahan station dilakukan melalui mekanisme registrasi/provisioning yang menghubungkan identitas perangkat dengan akun. Bentuk antarmuka provisioning ditentukan saat perancangan detail; kredensial broker tidak ditampilkan ke browser.

### 5.1 Monitoring

- Berat pakan ditampilkan dalam gram berdasarkan reservoir.
- Air ditampilkan sebagai level terkalibrasi, berupa persentase dan/atau kategori rendah, sedang, atau penuh.
- Persentase level air tidak dinyatakan sebagai persentase volume tanpa kalibrasi yang mendukung.
- PIR menampilkan gerakan terdeteksi atau tidak terdeteksi.
- State servo dan relay pompa ditampilkan sebagai laporan firmware/simulator.
- Setiap pembacaan disertai waktu dan indikator validitas.
- Status koneksi dibedakan dari kesegaran telemetry. Data lama tetap memiliki penanda dan tidak ditampilkan seolah pembacaan baru.
- Ambang offline dan data terlambat ditentukan saat kontrak data difinalisasi.

### 5.2 Kontrol dan hasil eksekusi

- Backend memeriksa kepemilikan station, koneksi, parameter, batas laju, dan konflik eksekusi.
- Setiap perintah memiliki `command_id` unik dan waktu kedaluwarsa.
- Tombol dinonaktifkan saat offline atau ada perintah yang belum mencapai status akhir.
- Perangkat tetap melakukan validasi lokal walaupun permintaan lolos validasi backend.
- Status mencakup menunggu, berjalan, selesai, ditolak, gagal, dan timeout.
- Alasan penolakan atau kegagalan ditampilkan dan disimpan.
- Timeout menunjukkan hasil belum terkonfirmasi; aplikasi tidak otomatis menganggap aktuator berhenti atau mengirim perintah baru. Rekonsiliasi dilakukan dari hasil/state perangkat.
- Hasil yang datang terlambat disimpan dan ditampilkan tanpa menghilangkan catatan timeout sebelumnya.
- Riwayat memuat parameter, waktu, durasi, dan pembacaan sebelum/sesudah jika tersedia.

### 5.3 Batas interpretasi data

- Pembacaan reservoir menunjukkan persediaan, bukan konsumsi hewan.
- PIR tidak membuktikan hewan sedang makan atau minum.
- State selesai menunjukkan siklus eksekusi yang dilaporkan perangkat selesai, bukan bukti penyaluran fisik.
- Penurunan berat reservoir menjadi indikasi pakan tersalurkan. Tidak adanya perubahan pada pembacaan valid diberi penanda perlu pemeriksaan.
- Tidak adanya perubahan level air tidak otomatis dianggap kegagalan karena keterbatasan resolusi sensor.
- Data simulator ditandai jelas pada tampilan dan tidak dijadikan bukti pengujian fisik.

## 6. Arsitektur dan kontrak komunikasi

```text
Browser ↔ Laravel ↔ MariaDB
              ↕
         Worker MQTT
              ↕
          Mosquitto
              ↕
     Simulator Python / ESP32
```

Browser mengakses backend; backend menjadi pintu pemeriksaan akses. Simulator dan ESP32 menggunakan kontrak pesan yang sama.

| Topik                                | Arah                 | Isi                                                       |
| ------------------------------------ | -------------------- | --------------------------------------------------------- |
| `petstation/{device_id}/telemetry` | Perangkat → backend | Sensor mentah/terkalibrasi, validitas, state, dan waktu   |
| `petstation/{device_id}/state`     | Perangkat → backend | Perubahan state dan informasi koneksi                     |
| `petstation/{device_id}/command`   | Backend → perangkat | Identitas perintah, tindakan, parameter, dan masa berlaku |
| `petstation/{device_id}/result`    | Perangkat → backend | Perkembangan/hasil eksekusi dan pembacaan terkait         |
| `petstation/{device_id}/ack`       | Backend → perangkat | Konfirmasi penyimpanan pesan setelah transaksi berhasil   |

Ketentuan komunikasi:

- Telemetry aplikasi dikirim setiap 5 detik; perubahan state dan hasil dikirim saat kejadian.
- Payload menggunakan JSON dan memiliki versi skema.
- Pesan perangkat menyertakan `device_id`, `message_id`, dan waktu pembacaan/kejadian.
- Penyimpanan tahan terhadap pesan duplikat; pesan lama tidak menimpa snapshot terbaru.
- Perintah tidak menggunakan retain dan tidak dieksekusi ulang hanya karena pesan dikirim ulang.
- ACK aplikasi berbeda dari konfirmasi broker dan hanya dikirim setelah penyimpanan berhasil.
- Status koneksi menggunakan heartbeat dan Last Will.
- Kredensial unik dan pembatasan topik diterapkan per perangkat.
- HTTPS dan MQTT/TLS menjadi target saat akses dipindahkan ke jaringan eksternal. Konfigurasi development lokal harus didokumentasikan.

## 7. Model data konseptual

| Entitas                     | Data utama                                                                        |
| --------------------------- | --------------------------------------------------------------------------------- |
| Pengguna                    | Identitas dan autentikasi                                                         |
| Station                     | Pemilik, `device_id`, nama, jenis fisik/simulasi, konfigurasi, dan last seen    |
| Telemetry                   | Sensor mentah/terkalibrasi, validitas, state keluaran, waktu perangkat dan server |
| Kejadian state              | Perubahan servo, pompa, koneksi, dan kejadian terkait                             |
| Perintah                    | `command_id`, station, peminta, tindakan, parameter, status, dan masa berlaku   |
| Hasil/perkembangan perintah | Referensi perintah, alasan, durasi, pembacaan sebelum/sesudah, dan waktu          |

Seluruh pembacaan aplikasi serta perubahan state disimpan. Snapshot terbaru boleh digunakan untuk mempercepat dashboard tanpa menggantikan riwayat. Data diindeks berdasarkan station dan waktu serta memiliki batas unik untuk deduplikasi pesan. Grafik menggunakan agregasi yang sesuai rentang waktu tanpa menghapus data mentah secara diam-diam.

## 8. Simulator perangkat

Simulator berjalan sebagai client MQTT terpisah, bukan sekadar data contoh di browser atau pengisian langsung database.

Simulator harus mampu:

1. Mengirim telemetry berkala dengan identitas station unik.
2. Menerima perintah dan mengirim perkembangan serta hasil eksekusi.
3. Meniru perubahan reservoir setelah eksekusi.
4. Menolak perintah saat reservoir rendah, sensor invalid, cooldown aktif, atau eksekusi lain berlangsung.
5. Menerapkan batas durasi, masa berlaku, dan deduplikasi perintah.
6. Meniru offline, reconnect, pesan duplikat, serta hasil terlambat/tidak diterima.
7. Mengirim ulang pesan yang belum mendapat ACK aplikasi dalam batas antrean yang ditentukan.
8. Menjalankan beberapa station untuk menguji pemisahan data dan kontrol.

Simulator menguji perilaku software; hasilnya tidak membuktikan akurasi sensor atau keberhasilan mekanik perangkat fisik.

## 9. Kebutuhan kualitas

- Tampilan dapat digunakan dari ponsel dan desktop.
- Pengguna tidak dapat membaca atau mengendalikan station milik akun lain, termasuk lewat request langsung.
- Perintah berulang tidak menimbulkan eksekusi ganda.
- Kredensial disimpan pada konfigurasi lingkungan yang tidak masuk version control.
- Riwayat dapat ditelusuri melalui identitas pesan/perintah dan waktu.
- Waktu disimpan secara konsisten dan ditampilkan dengan zona waktu yang jelas; target awal Asia/Jakarta.
- Kehilangan koneksi tidak boleh ditampilkan sebagai keberhasilan perintah.
- Kesalahan worker dan koneksi broker dicatat agar dapat ditelusuri saat pengujian lokal.

## 10. Di luar cakupan versi pertama

- Penjadwalan pemberian pakan/air otomatis.
- Klaim pengukuran konsumsi, nutrisi, atau kesehatan hewan.
- Identifikasi hewan melalui PIR.
- Deteksi mangkuk penuh atau verifikasi aliran secara otomatis.
- Aplikasi mobile native, kamera, dan notifikasi eksternal.
- Deployment publik pada tahap awal.
- Klaim kapasitas produksi atau pengujian banyak perangkat fisik.

## 11. Tahapan pengerjaan

| Tahap                         | Hasil yang dituju                                                                           |
| ----------------------------- | ------------------------------------------------------------------------------------------- |
| 1. Rancangan detail           | Wireframe, skema database, payload MQTT, status perintah, dan aturan timeout/kesegaran data |
| 2. Fondasi aplikasi           | Setup lokal, login, kepemilikan, daftar station, dan dashboard awal                         |
| 3. Integrasi simulator        | Broker lokal, worker MQTT, telemetry tersimpan, dan monitoring yang diperbarui              |
| 4. Kontrol perangkat          | Konfirmasi, validasi, penerbitan perintah, hasil, deduplikasi, dan timeout                  |
| 5. Riwayat dan analytics      | Filter, detail perintah, grafik, dan pemisahan data station                                 |
| 6. Validasi lokal             | Uji alur utama, kondisi gagal, akses antar akun, dan tampilan responsif                     |
| 7. Integrasi fisik berikutnya | ESP32 mengikuti kontrak yang sama; verifikasi sensor, aktuator, dan konfigurasi jaringan    |

## 12. Kriteria penerimaan awal

- Pengguna dapat login, memilih station miliknya, dan melihat telemetry simulator beserta waktu pembacaan.
- Pembaruan dashboard mengikuti polling 5 detik saat halaman aktif dan koneksi tersedia.
- Perintah pakan/air menempuh alur web → backend → MQTT → simulator → hasil → riwayat.
- Perintah tidak dinyatakan selesai hanya karena berhasil diterbitkan ke broker.
- Kondisi offline, sensor invalid, reservoir rendah, cooldown, timeout, dan hasil terlambat ditampilkan dengan benar.
- Pesan duplikat tidak menggandakan data atau eksekusi.
- Data dan perintah beberapa station tetap terpisah; akses lintas kepemilikan ditolak.
- Riwayat dan grafik sesuai dengan data yang tersimpan.
- Station simulasi diberi penanda pada tampilan.
- Seluruh alur awal dapat dijalankan lokal tanpa perangkat fisik.

## 13. Keputusan implementasi awal

- Nama tampilan aplikasi: Petstation. UI sederhana, bersih, menggunakan warna netral dan aksen hijau, tanpa emoji dan em dash.
- PHP 8.3, Laravel 13, Livewire 4, Tailwind CSS 4, Chart.js 4, MariaDB, Mosquitto 2.1.2, serta Paho MQTT 2.1.0. Dependensi dikunci dalam lockfile proyek.
- Web, broker, dan database berjalan lokal pada `127.0.0.1`, masing-masing port 8000, 1883, dan 3307. Data MariaDB terpisah dari database XAMPP/Laragon yang sudah ada.
- Dashboard polling setiap 5 detik. Data terlambat setelah 15 detik; offline setelah 20 detik tanpa pembacaan/heartbeat baru.
- Masa berlaku perintah 15 detik; timeout hasil 30 detik. Perintah yang kedaluwarsa sebelum dikirim ditandai gagal. Hasil terlambat direkonsiliasi tanpa menghapus catatan timeout.
- Siklus pakan/air dari web dibatasi 2 detik; batas lokal simulator 3 detik; cooldown 10 detik. Ambang air rendah 20% level dan persediaan pakan minimum 30 gram merupakan parameter awal simulator, bukan hasil kalibrasi fisik.
- Akun demo lokal dan dua station simulator dibuat saat setup. Penambahan kepemilikan station tersedia melalui CLI; provisioning kredensial perangkat tambahan masih terpisah.
- Grafik menggunakan rata-rata pembacaan sensor valid dengan rentang 1 jam, 24 jam, atau 7 hari. Waktu ditampilkan dalam Asia/Jakarta.

## 14. Verifikasi implementasi lokal

- Build aset frontend berhasil.
- 15 pengujian backend berhasil, mencakup autentikasi, otorisasi, validasi kontrol, deduplikasi, urutan pesan, timeout, hasil terlambat, dan analytics.
- 5 pengujian simulator berhasil, mencakup deduplikasi lintas restart, kedaluwarsa, proteksi lokal, cooldown, serta pemulihan hasil tersimpan.
- 2 pengujian browser berhasil pada Microsoft Edge, mencakup alur MQTT dengan simulator aktif, kondisi air rendah, hasil terlambat, filter riwayat, analytics, dan navigasi ponsel.
- Panduan setup, start/stop, akun demo, dan batas implementasi tersedia di `web/README.md`.

## 15. Pengembangan berikutnya

- Integrasi firmware ESP32 dan kalibrasi sensor serta aktuator fisik.
- UI provisioning perangkat dan pengelolaan kredensial yang lebih lengkap.
- Penetapan retensi data dan optimasi berdasarkan pengukuran jumlah station.
- Konfigurasi deployment, HTTPS, MQTT/TLS, sertifikat, serta pengawasan proses server.
- Penjadwalan dan fitur tambahan tetap mengikuti prioritas pengembangan berikutnya.
