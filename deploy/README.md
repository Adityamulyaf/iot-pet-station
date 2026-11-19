# Deploy ke Azure for Students

Semua layanan berjalan di satu VM Ubuntu 24.04:

| Layanan                                                         | Akses                                                |
| --------------------------------------------------------------- | ---------------------------------------------------- |
| Web (Caddy + PHP-FPM, HTTPS otomatis Let's Encrypt)             | `https://<domain>` dari HP/laptop                  |
| Broker Mosquitto, TLS                                           | port `8883` untuk ESP32                            |
| Broker Mosquitto, tanpa TLS                                     | `127.0.0.1:1883`, hanya worker dan simulator di VM |
| Worker `station:mqtt`, jadwal (`schedule:work`), MariaDB, simulator `station-001/002` | systemd, hidup otomatis setelah reboot               |

Sertifikat broker ditandatangani CA milik sendiri yang dibuat oleh script, lalu CA tersebut ditanam di firmware. Dengan begitu ESP32 memverifikasi server tanpa bergantung pada rantai sertifikat publik.

Biaya: selama 12 bulan, VM B2ats v2 gratis 750 jam/bulan (cukup untuk menyala 24 jam) dan disk Premium SSD P6 64 GB juga gratis. IP publik dipotong dari kredit Azure for Students, bukan kartu. Kredit berlaku 12 bulan dan bisa diperpanjang selama masih berstatus mahasiswa.

## 1. Buat VM

Di portal Azure buka **Education > Free services > Linux Virtual Machine > Create**.

| Tab        | Isian                                                                                                                                      |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Basics     | Subscription: **Azure for Students**. Resource group baru: `petstation-rg`. Nama VM: `petstation`.                               |
|            | Region: **Southeast Asia** atau **Indonesia Central** jika tersedia. Jika muncul error kebijakan region, coba region lain.     |
|            | Image: **Ubuntu Server 24.04 LTS - x64 Gen2**.                                                                                       |
|            | Size: **Standard_B2ats_v2** (2 vCPU AMD, 1 GB, berlabel *free services eligible*). Jangan pilih B2pts v2 karena butuh image ARM64. |
|            | Authentication: **SSH public key**, username `azureuser`, key pair baru. Simpan file `.pem` yang diunduh.                        |
|            | Inbound ports: centang **SSH (22), HTTP (80), HTTPS (443)**.                                                                         |
| Disks      | OS disk size: **64 GiB (P6)**, type **Premium SSD**. Ukuran bawaan 30 GiB (P4) tidak termasuk gratis.                          |
| Management | Pastikan **Auto-shutdown** tidak aktif.                                                                                              |

Klik **Review + create > Create**, lalu unduh private key.

Setelah VM jadi, lakukan dua hal:

1. **Port MQTT**: buka VM > **Networking > Network settings > Create port rule > Inbound port rule**. Isi destination port `8883`, protocol TCP, action Allow, nama `mqtt-tls`.
2. **Domain gratis**: buka VM > **Overview**, klik alamat **Public IP** > **Configuration**. Pilih assignment **Static**, isi **DNS name label** (misalnya `petstation-aditya`), lalu Save. Domain menjadi `petstation-aditya.<region>.cloudapp.azure.com` dan tidak berubah walaupun VM di-restart.

## 2. Pasang aplikasi

Server mengambil kode dari GitHub (`main`), jadi commit dan push perubahan terlebih dahulu. Lalu dari laptop (PowerShell):

```powershell
ssh -i C:\path\ke\petstation_key.pem azureuser@<domain>
```

Jika SSH menolak karena izin file `.pem` terlalu terbuka, jalankan sekali:
`icacls C:\path\ke\petstation_key.pem /inheritance:r /grant:r "$($env:USERNAME):R"`.

Di VM:

```bash
curl -fsSL https://raw.githubusercontent.com/Adityamulyaf/iot-pet-station/main/deploy/setup-server.sh -o setup-server.sh
sudo bash setup-server.sh --domain <domain>
```

Jika repository private, salin script dengan `scp -i <key.pem> deploy/setup-server.sh azureuser@<domain>:` lalu tambahkan `--repo https://<token>@github.com/Adityamulyaf/iot-pet-station.git`. Opsi `--no-simulator` mematikan dua station simulasi.

Buat akun dan daftarkan station (sekali saja):

```bash
cd /var/www/petstation/web
sudo -u www-data php artisan station:user email@anda.com "Nama Anda"
sudo -u www-data php artisan station:provision station-esp32 email@anda.com "Station ESP32" --physical
sudo -u www-data php artisan station:provision station-001 email@anda.com "Station simulasi 1"
sudo -u www-data php artisan station:provision station-002 email@anda.com "Station simulasi 2"
```

Buka `https://<domain>` di HP, lalu pilih **Add to Home Screen** supaya tampil seperti aplikasi.

### Station baru dengan kode klaim

Pengguna lain (misalnya dosen) bisa membuat akun sendiri lewat **Daftar** di halaman login. Untuk memberi mereka station, daftarkan perangkatnya di server:

```bash
sudo bash /var/www/petstation/deploy/station-create.sh station-esp32-2 "Station ESP32 kedua" --physical
```

Script ini membuat password broker dan ACL untuk perangkat, memuat ulang Mosquitto tanpa memutus perangkat lain, lalu mencetak **kode klaim** seperti `K7Q2-9XMP`. Untuk perangkat fisik, `secrets.h` disimpan di `/etc/petstation/firmware-secrets-<device-id>.h`. Tanpa `--physical`, station simulasi langsung dijalankan.

Pengguna membuka **Station saya > Tambah station**, lalu mengisi ID perangkat dan kode klaim. Kode hanya bisa dipakai sekali. Jika pemilik melepas station dari **Pengaturan**, buat kode baru:

```bash
sudo -u www-data php /var/www/petstation/web/artisan station:code station-esp32-2
```

## 3. Hubungkan ESP32

```powershell
ssh -i <key.pem> azureuser@<domain> "sudo cat /etc/petstation/firmware-secrets.h" > firmware/petstation_esp32/secrets.h
```

Isi `WIFI_SSID` dan `WIFI_PASSWORD`, lalu upload ulang firmware. Serial Monitor harus menampilkan `MQTT connect... ok`. ESP32 cukup terhubung ke Wi-Fi mana pun yang punya internet. `start-local.ps1` tidak akan menimpa `secrets.h` mode cloud ini. Untuk kembali ke broker laptop, hapus `secrets.h` lalu jalankan `start-local.ps1 -Lan`.

## Update dan pemeriksaan

Setelah push perubahan, jalankan ulang perintah yang sama. Kredensial, CA, dan data tetap dipertahankan:

```bash
sudo bash setup-server.sh --domain <domain>
```

| Masalah              | Perintah / penyebab                                                                |
| -------------------- | ---------------------------------------------------------------------------------- |
| Status layanan       | `systemctl status petstation-mqtt mosquitto caddy`                               |
| Log worker MQTT      | `journalctl -u petstation-mqtt -n 50`                                            |
| Log jadwal           | `journalctl -u petstation-schedule -n 50`                                        |
| Log HTTPS/sertifikat | `journalctl -u caddy -n 50` (port 80 harus terbuka agar Let's Encrypt berhasil)  |
| Log Laravel          | `tail -n 50 /var/www/petstation/web/storage/logs/laravel.log`                    |
| ESP32 `rc=-2`      | Port 8883 belum dibuka di Network Security Group, atau jam ESP32 belum sinkron NTP |
| ESP32 `rc=5`       | Password MQTT di `secrets.h` tidak sama dengan server                            |
| Pemakaian kredit     | Portal Azure > **Education > Overview** atau **Cost Management**       |

## Alternatif: Oracle Cloud Always Free

Script yang sama berjalan di VM Oracle Ubuntu 24.04 dan otomatis membuka firewall iptables bawaan Oracle. Yang berbeda: port 80, 443, dan 8883 dibuka di **Security List** VCN, domain memakai DuckDNS, dan pendaftaran Oracle membutuhkan kartu untuk verifikasi.
