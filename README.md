# Uptime Kuma ke WhatsApp

Aplikasi ini menerima notifikasi Uptime Kuma dan meneruskannya ke grup WhatsApp melalui NgirimWA. Kode webhook, antrean pesan, pemantauan, dan log disimpan dalam folder terpisah.

## Fitur

- Menerima status monitor `UP` dan `DOWN` melalui webhook.
- Memeriksa token webhook sebelum menerima pesan.
- Menyimpan pesan di antrean sebelum dikirim.
- Menahan pesan saat konfigurasi atau layanan belum siap. Pesan yang tetap tertahan lebih dari 10 menit dicatat gagal dan dihapus.
- Mengirim ulang status yang sama setelah jeda 10 menit sejak pesan terakhir diterima API, jika tidak ada pesan monitor itu yang masih di antrean.
- Menghitung pesan berhasil, pesan gagal, dan status sama yang dilewati secara terpisah.
- Menampilkan status DNS, ping ICMP, konfigurasi, dan jumlah pesan di halaman utama.
- Membuat dan menyalin token webhook dari halaman utama.
- Menulis log ke folder `log/`.

## Alur singkat

1. Uptime Kuma mengirim data JSON dan header `X-Webhook-Token` ke `/webhook`.
2. Aplikasi memeriksa token dan memasukkan pesan ke antrean.
3. Cron menjalankan worker. Worker memeriksa konfigurasi, API NgirimWA, dan status perangkat.
4. Jika siap, pesan dikirim. Jika belum siap, pesan tetap di antrean hingga 10 menit.
5. Halaman utama membaca status dari snapshot worker. Browser tidak menghubungi API NgirimWA secara langsung.

Pesan berhasil berarti API NgirimWA menerima permintaan. Itu belum memastikan pesan sudah diterima atau dibaca di WhatsApp.

## URL webhook

Gunakan URL dengan path `/webhook`, tanpa ekstensi `.php`:

~~~text
https://domain-anda/webhook
~~~

Halaman utama menampilkan URL sesuai domain yang dibuka dan menyediakan tombol **Salin URL**. Web server perlu rewrite agar `/webhook` diarahkan ke handler aplikasi. Aturan rewrite untuk Apache dan Nginx ada pada panduan pemasangan masing-masing di bawah.

Di Uptime Kuma, tambahkan header berikut pada konfigurasi webhook:

~~~text
X-Webhook-Token: token-yang-ada-di-file-env
~~~

Token harus sama dengan `UPTIME_WEBHOOK_TOKEN` pada `.env`.

## Konfigurasi

Salin `.env.example` menjadi `.env`, lalu isi API key, group ID, URL API, token, dan alamat website status. `NGIRIMWA_BASE_URL` perlu diisi agar worker dapat mengirim pesan. Nilai rahasia tidak ditampilkan pada halaman utama.

Variabel utama:

~~~dotenv
NGIRIMWA_API_KEY=
NGIRIMWA_GROUP_ID=
NGIRIMWA_BASE_URL=https://dash.ngirimwa.com/api/v1
UPTIME_MESSAGE_SIGNATURE=Server Monitoring
UPTIME_STATUS_WEBSITE=https://status.example.com
UPTIME_WEBHOOK_TOKEN=
~~~

`UPTIME_STATUS_WEBSITE` dipakai pada isi pesan dan sebagai target ping. Tidak ada variabel URL tambahan untuk target Uptime Kuma.

## Antrean dan jumlah pesan

- **Berhasil**: pesan yang diterima API NgirimWA.
- **Gagal**: pesan yang gagal diproses atau tertahan lebih dari 10 menit.
- **Dilewati · status sama**: webhook dengan status sama dalam jeda 10 menit. Angka ini tidak menambah jumlah pesan gagal.
- Jika ada pesan monitor yang masih di antrean, webhook baru untuk monitor itu dilewati agar tidak membuat antrean ganda.
- Pesan uji Uptime Kuma memakai nama dan status angka acak 10 digit.

## Monitor

- DNS diuji dengan kueri UDP ke resolver yang terdeteksi dari konfigurasi DNS server.
- NgirimWA dan `UPTIME_STATUS_WEBSITE` diuji dengan ICMP echo ping menggunakan perintah `ping`, bukan HTTP/HTTPS.
- Ping domain berjalan paling banyak sekali per menit. Snapshot diperbarui setiap 5 detik oleh worker monitor.
- API key, token, dan group ID hanya ditampilkan sebagai status terisi atau belum.

Binary `ping` harus tersedia dan diizinkan pada server. Cron untuk worker monitor dijalankan sekali per menit dengan opsi `--loop`; jangan jadwalkan perintah itu setiap 5 detik karena loop sudah berjalan selama sekitar satu menit.

## File utama

~~~text
api/uptime.php          Handler webhook dan antrean
api/send-queue.php      Worker pengiriman pesan
api/live-monitor.php    Worker monitor jaringan
api/live-status.php     Data monitor untuk halaman utama
public/index.php        Halaman utama
public/api/             Wrapper publik untuk endpoint aplikasi
log/                    Log sistem, pengiriman, dan cron
~~~

Document root web server diarahkan ke folder `public/`. Dengan begitu, `.env`, `api/`, dan `log/` berada di luar akses web langsung. Folder `api/` dan `log/` perlu bisa ditulis oleh PHP-FPM dan pengguna cron.

## Pemasangan di aaPanel

1. Unggah project ke folder website, misalnya `/www/wwwroot/domain-anda`.
2. Atur document root website ke `/www/wwwroot/domain-anda/public`.
3. Pilih versi PHP di pengaturan website aaPanel. Contoh panduan ini memakai PHP 8.4.
4. Salin dan isi `.env`:

   ~~~bash
   cd /www/wwwroot/domain-anda
   cp .env.example .env
   ~~~

5. Pastikan pengguna website dan cron dapat menulis ke `api/` dan `log/`.
6. Periksa direktori PHP aaPanel dan versinya:

   ~~~bash
   ls -d /www/server/php/*/
   /www/server/php/84/bin/php -v
   ~~~

   Jika menggunakan versi lain, ganti `84` sesuai folder PHP yang tersedia. `php -v` memeriksa PHP CLI default, sedangkan `/www/server/php/84/bin/php -v` memeriksa PHP 8.4 secara langsung.

7. Buat dua task melalui **Cron → Add Task → Shell Script**, interval satu menit:

   ~~~bash
   /www/server/php/84/bin/php /www/wwwroot/domain-anda/api/send-queue.php >> /www/wwwroot/domain-anda/log/cron-run.log 2>&1
   ~~~

   ~~~bash
   /www/server/php/84/bin/php /www/wwwroot/domain-anda/api/live-monitor.php --loop >> /www/wwwroot/domain-anda/log/cron-run.log 2>&1
   ~~~

8. Atur rewrite sesuai web server yang dipilih:
   - Apache: gunakan `public/.htaccess` dan pastikan `mod_rewrite` aktif.
   - Nginx: tambahkan aturan rewrite pada konfigurasi domain seperti contoh di bagian **Pemasangan Linux dengan Nginx**.

## Pemasangan Linux dengan Apache

1. Pasang PHP, ekstensi cURL, dan Apache dengan PHP-FPM atau modul PHP.
2. Tempatkan project di folder website dan arahkan `DocumentRoot` ke folder `public/`.
3. Aktifkan `mod_rewrite` dan izinkan aturan `.htaccess` untuk website. File `public/.htaccess` sudah berisi rewrite `/webhook`.
4. Salin `.env.example` menjadi `.env`, lalu isi konfigurasi. Pastikan pengguna web dan cron dapat menulis ke `api/` serta `log/`.
5. Tambahkan dua cron berikut dengan interval satu menit. Sesuaikan lokasi PHP dan folder project:

   ~~~cron
   * * * * * /usr/bin/php /var/www/domain-anda/api/send-queue.php >> /var/www/domain-anda/log/cron-run.log 2>&1
   * * * * * /usr/bin/php /var/www/domain-anda/api/live-monitor.php --loop >> /var/www/domain-anda/log/cron-run.log 2>&1
   ~~~

6. Pastikan URL `https://domain-anda/webhook` dapat dibuka melalui rewrite. HTTPS diperlukan untuk melindungi token saat dikirim.

## Pemasangan Linux dengan Nginx

1. Pasang PHP-FPM, ekstensi cURL, dan Nginx.
2. Tempatkan project di folder website dan atur `root` Nginx ke folder `public/`. Gunakan konfigurasi PHP-FPM yang sesuai dengan versi PHP server.
3. Salin `.env.example` menjadi `.env`, lalu isi konfigurasi. Pastikan pengguna PHP-FPM dan cron dapat menulis ke `api/` serta `log/`.
4. Tambahkan aturan ini di dalam blok `server { ... }` domain. Pastikan konfigurasi PHP-FPM yang sudah ada tetap menangani `/api/uptime.php`:

   ~~~nginx
   rewrite ^/webhook/?$ /api/uptime.php last;
   ~~~

5. Uji konfigurasi dan muat ulang Nginx:

   ~~~bash
   nginx -t
   systemctl reload nginx
   ~~~

6. Tambahkan dua cron dengan interval satu menit. Sesuaikan PHP CLI dan lokasi project:

   ~~~cron
   * * * * * /usr/bin/php /var/www/domain-anda/api/send-queue.php >> /var/www/domain-anda/log/cron-run.log 2>&1
   * * * * * /usr/bin/php /var/www/domain-anda/api/live-monitor.php --loop >> /var/www/domain-anda/log/cron-run.log 2>&1
   ~~~

## Log

- `log/send-success.log`: pesan yang diterima API NgirimWA.
- `log/send-failure.log`: pesan yang gagal atau kedaluwarsa.
- `log/system.log`: aktivitas umum, termasuk status sama yang dilewati.
- `log/cron-run.log`: aktivitas cron dan worker.
- `log/php-error.log`: error PHP yang tidak ditampilkan di browser.

Angka pada monitor halaman utama tersimpan di `log/delivery-metrics.json`. Folder `log/` tidak dilacak Git. Terapkan rotasi log sesuai pengaturan server.

## Keamanan

- Gunakan HTTPS dan token webhook yang sulit ditebak.
- Jangan unggah `.env`, API key, token, atau log berisi data sensitif ke GitHub.
- Pastikan document root hanya mengarah ke `public/`.
- Laporkan masalah keamanan secara privat melalui petunjuk di [SECURITY.md](SECURITY.md).

## Kontribusi

Perbaikan dan usulan dapat dikirim melalui [GitHub Issues](https://github.com/ekiguistian/webhook_uptimekuma-sendwa/issues) atau [Pull Requests](https://github.com/ekiguistian/webhook_uptimekuma-sendwa/pulls). Lihat [CONTRIBUTING.md](CONTRIBUTING.md).

## Dukungan

Jika project ini bermanfaat, dukungan segelas kopi tersedia melalui [PayPal](https://paypal.me/ekiguistian). Tautan ini juga ada di `.github/FUNDING.yml`.
