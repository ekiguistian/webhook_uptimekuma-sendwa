# Uptime Kuma ke WhatsApp melalui NgirimWA
Vendor Wa Gateway : [NgirimWA](https://ngirimwa.com/)
Aplikasi PHP ini menerima perubahan status monitor dari Uptime Kuma dan mengirim notifikasi ke grup WhatsApp melalui API NgirimWA. Pemrosesan webhook, antrean pesan, cron worker, dan log berada di komponen terpisah.

## Fungsi utama

- Menerima status UP/DOWN dan pemulihan dari Uptime Kuma.
- Memvalidasi header rahasia `X-Webhook-Token` sebelum payload diproses.
- Menyimpan pesan sementara di antrean lokal dan memprosesnya melalui cron.
- Memeriksa koneksi API serta status perangkat NgirimWA sebelum mengirim.
- Menulis log aplikasi, hasil pengiriman, dan error PHP ke folder `log/`.
- Menyediakan halaman untuk melihat URL webhook sesuai domain serta membuat dan menyalin token.
- Menampilkan live monitor koneksi DNS, NgirimWA, Uptime Kuma, status konfigurasi, serta jumlah pesan berhasil dan gagal.

## Alur sistem

~~~mermaid
flowchart LR
  Kuma["Uptime Kuma"] -->|POST JSON + X-Webhook-Token| Web["/webhook"]
  Web -->|rewrite internal| Public["public/api/uptime.php"]
  Public --> Handler["api/uptime.php"]
  Env[".env"] --> Auth{"Token cocok?"}
  Handler --> Auth
  Auth -->|tidak| Reject["HTTP 401 + log"]
  Auth -->|ya| Queue["api/queue.json"]
  Cron["Cron Linux / aaPanel"] --> Worker["api/send-queue.php"]
  Queue --> Worker
  Worker -->|GET status| Status["NgirimWA /devices/status"]
  Status -->|connected| Send["POST /messages/send"]
  Status -->|gagal/offline| Failure["hapus antrean + log gagal"]
  Send --> Group["Grup WhatsApp"]
  Worker --> Logs["Folder log/"]
  MonitorCron["Cron live monitor"] --> MonitorWorker["api/live-monitor.php"]
  MonitorWorker --> Checks["DNS 1.1.1.1 + NgirimWA + Uptime Kuma"]
  Checks --> Snapshot["api/live-monitor.json"]
  Browser["Halaman public"] -->|poll 5 detik| MonitorStatus["public/api/live-status.php"]
  MonitorStatus --> Snapshot
~~~

Webhook hanya menerima request `POST` dengan token yang cocok. Status yang sama atau sudah berada di antrean dilewati. Cron worker memproses maksimal satu pesan per eksekusi. Resolusi DNS dan koneksi API diukur terpisah; lookup DNS yang tidak menghasilkan alamat dan error koneksi API masing-masing dicoba ulang sekali. Jika pemeriksaan akhir tetap gagal atau perangkat tidak terhubung, pesan yang menunggu dihapus dan dicatat sebagai gagal; pesan tersebut tidak dicoba ulang pada eksekusi cron berikutnya.

Worker mengatur `expires_in` menjadi 30 detik untuk membatasi masa tunggu pesan pada antrean NgirimWA. Respons sukses API menunjukkan pesan diterima oleh antrean layanan, bukan bukti pesan sudah sampai atau dibaca. Detail endpoint tersedia di [panduan status perangkat](https://panduan.ngirimwa.com/api/kelola-perangkat) dan [panduan kirim pesan](https://panduan.ngirimwa.com/api/kirim-pesan).

## Struktur project

~~~text
project/
├── .env                         # konfigurasi lokal dan rahasia
├── .env.example                 # contoh konfigurasi
├── api/
│   ├── config.php               # pemuatan .env dan fungsi log
│   ├── uptime.php               # handler webhook dan pembuatan antrean
│   ├── send-queue.php           # worker cron
│   ├── live-monitor.php         # pemeriksaan live monitor tiap 5 detik
│   ├── live-status.php          # respons status publik yang disanitasi
│   ├── live-monitor.json        # snapshot runtime (diabaikan Git)
│   ├── queue.json               # antrean runtime
│   └── last-status.json         # status monitor terakhir
├── log/                         # log aplikasi, PHP, dan cron
└── public/                      # document root web server
    ├── index.php                # halaman utama dan generator token
    ├── .htaccess                # URL rewrite untuk Apache
    └── api/                      # wrapper publik menuju handler internal
        ├── uptime.php
        └── live-status.php
~~~

Document root diarahkan ke `public/`. Dengan susunan ini, `.env`, `api/`, dan `log/` tidak menjadi file publik. PHP-FPM memerlukan akses baca ke `.env` dan akses tulis ke file antrean/status serta log; cron worker memerlukan akses yang sama.

Halaman utama juga menampilkan live monitor. Browser membaca snapshot yang disiapkan worker; request halaman tidak memanggil API NgirimWA atau Uptime Kuma secara langsung. Snapshot berada di `api/live-monitor.json`, sedangkan penghitung pesan berada di `log/delivery-metrics.json`. Endpoint publik hanya mengirim waktu, status koneksi, jumlah pesan, dan indikator apakah konfigurasi sudah terisi. Nilai API key, token, group ID, dan URL Uptime Kuma tidak dikirim ke browser.

## URL webhook dan rewrite

URL publik menggunakan path tanpa ekstensi PHP:

~~~text
https://domain-anda/webhook
~~~

Halaman utama membentuk URL dari domain yang sedang dibuka di browser. Tombol **Salin URL** menyalin alamat untuk konfigurasi Uptime Kuma. Agar route `/webhook` mencapai handler, web server memetakan route tersebut secara internal ke `public/api/uptime.php`. Wrapper itu meneruskan request ke `api/uptime.php` di luar document root.

Pada Apache, aturan rewrite berada di file `public/.htaccess`. Aturan tersebut memerlukan modul `mod_rewrite` dan izin penggunaan `.htaccess` pada virtual host, seperti `AllowOverride FileInfo`.

Nginx tidak membaca `.htaccess`. Aturan ekuivalen berada di konfigurasi server domain, di dalam blok `server { ... }`; konfigurasi PHP-FPM yang ada tetap menangani file tujuan:

~~~nginx
rewrite ^/webhook/?$ /api/uptime.php last;
~~~

Apabila route rewrite tidak aktif, `/webhook` tidak akan diarahkan ke handler. Document root tetap perlu mengarah ke folder `public/`.

## Autentikasi dan konfigurasi

Halaman utama dapat membuat token acak melalui `random_bytes(32)`. Token ditampilkan untuk disalin dan tidak disimpan oleh aplikasi. Respons halaman menggunakan header no-cache. Nilai token yang sama dipasang pada `UPTIME_WEBHOOK_TOKEN` di `.env` server dan sebagai header tambahan di Uptime Kuma:

~~~json
{
  "X-Webhook-Token": "token-rahasia"
}
~~~

Webhook memakai metode `POST` dan payload JSON. Request tanpa token yang cocok mendapat HTTP 401; jika token belum dikonfigurasi di `.env`, webhook mengembalikan HTTP 503. HTTPS diperlukan agar token tidak dikirim melalui koneksi tanpa enkripsi.

`.env.example` berisi template konfigurasi. Nilai yang umum disesuaikan dengan layanan masing-masing:

~~~dotenv
NGIRIMWA_API_KEY=
NGIRIMWA_GROUP_ID=
NGIRIMWA_BASE_URL=https://dash.ngirimwa.com/api/v1
UPTIME_MESSAGE_SIGNATURE=Server Monitoring
UPTIME_STATUS_WEBSITE=https://status.example.com
UPTIME_KUMA_URL=
UPTIME_WEBHOOK_TOKEN=
~~~

`NGIRIMWA_API_KEY` berasal dari Dashboard NgirimWA → Perangkat → Kunci API. `NGIRIMWA_GROUP_ID` menentukan grup WhatsApp tujuan. `UPTIME_KUMA_URL` adalah URL instance Uptime Kuma yang akan diperiksa live monitor. Jika variabel ini kosong, monitor menggunakan `UPTIME_STATUS_WEBSITE` sebagai target. Signature dan `UPTIME_STATUS_WEBSITE` juga digunakan sebagai footer notifikasi. File `.env` bersifat lokal dan tidak dilacak Git; `.env.example` merupakan template publik.

## Cron worker dan live monitor

`api/send-queue.php` memeriksa perangkat dan memproses antrean. `api/live-monitor.php --loop` memeriksa DNS 1.1.1.1, endpoint NgirimWA, dan URL Uptime Kuma setiap lima detik, lalu menyimpan snapshot untuk halaman utama. Pemeriksaan DNS 1.1.1.1 berupa kueri DNS melalui UDP, bukan ICMP echo. Cron standar hanya memulai task per menit; opsi `--loop` menjalankan 12 pemeriksaan dengan jarak lima detik dalam satu proses. Lock worker mencegah proses monitor berjalan tumpang tindih. Lokasi PHP CLI dan direktori project bergantung pada susunan server.

Untuk memeriksa worker satu kali dari terminal, jalankan `php /path/project/api/live-monitor.php --once`. Snapshot terbaru ditulis ke `api/live-monitor.json`; baris worker mulai dan selesai dapat dilihat di `log/cron-run.log`. Folder `api/` harus bisa ditulis oleh pengguna cron dan snapshot harus bisa dibaca oleh PHP-FPM.

### Linux cron

Cron Linux biasanya menggunakan PHP CLI dari sistem. Contoh entry `crontab`:

~~~cron
* * * * * /usr/bin/php /var/www/webhook/api/send-queue.php >> /var/www/webhook/log/cron-run.log 2>&1
* * * * * /usr/bin/php /var/www/webhook/api/live-monitor.php --loop >> /var/www/webhook/log/cron-run.log 2>&1
~~~

`/usr/bin/php` dan `/var/www/webhook` pada contoh tersebut mewakili lokasi binary PHP dan project di server.

### aaPanel cron dan versi PHP

Versi PHP-FPM untuk domain tercantum pada pengaturan website aaPanel. Versi tersebut dapat berbeda dari PHP CLI yang dipakai task cron. Daftar direktori PHP aaPanel dapat dilihat dari terminal:

~~~bash
ls -d /www/server/php/*/
~~~

aaPanel menamai direktori berdasarkan nomor versi tanpa titik. Contohnya, PHP 8.4 berada di `/www/server/php/84/`, dengan binary CLI `/www/server/php/84/bin/php`. Versi binary tersebut dapat diperiksa langsung:

~~~bash
/www/server/php/84/bin/php -v
~~~

`php -v` juga menunjukkan versi default CLI pada shell, tetapi cron sebaiknya memakai path absolut ke binary aaPanel yang versinya sesuai dengan kebutuhan aplikasi. Dengan PHP 8.4 dan project di `/www/wwwroot/your-domain`, contoh command cron-nya:

~~~bash
/www/server/php/84/bin/php /www/wwwroot/your-domain/api/send-queue.php >> /www/wwwroot/your-domain/log/cron-run.log 2>&1
/www/server/php/84/bin/php /www/wwwroot/your-domain/api/live-monitor.php --loop >> /www/wwwroot/your-domain/log/cron-run.log 2>&1
~~~

Buat dua task terpisah di crontab atau aaPanel, keduanya dengan interval satu menit: satu untuk `send-queue.php`, satu untuk `live-monitor.php --loop`. Pada aaPanel, gunakan **Cron → Add Task → Shell Script**. Versi yang dipilih untuk website terlihat pada pengaturan PHP website di aaPanel. Versi default untuk perintah shell dapat dilihat pada **Advanced Setup → PHP CLI**; hasil `php -v` menunjukkan binary default shell, sedangkan path `/www/server/php/84/bin/php -v` memeriksa binary PHP 8.4 secara langsung. Direktori dapat berbeda menurut versi yang terpasang. Referensi aaPanel: [pengaturan website dan versi PHP](https://www.aapanel.com/docs/Function/php.html), [struktur dan perintah PHP](https://www.aapanel.com/docs/faq/Service_Manage_Commands.html), dan [cron](https://www.aapanel.com/docs/Function/Cron.html).

## Log dan operasi

- `log/system.log` berisi aktivitas umum dan ringkasan sistem.
- `log/send-success.log` mencatat pesan yang diterima NgirimWA, termasuk tracking ID.
- `log/send-failure.log` mencatat pemeriksaan API/perangkat dan pesan yang gagal.
- `log/php-error.log` menampung warning serta fatal error PHP; detail error tidak ditampilkan di browser.
- `log/cron-run.log` mencatat pemanggilan worker, antrean kosong, hasil proses, serta waktu DNS, koneksi TCP, dan handshake TLS dalam milidetik. Worker live monitor juga mencatat waktu mulai dan selesai. Redirect stdout/stderr pada command cron menjadi tambahan untuk output CLI yang tidak tertangani aplikasi.
- `api/live-monitor.json` menyimpan snapshot jaringan terakhir; `log/delivery-metrics.json` menyimpan jumlah pesan berhasil/gagal. Keduanya merupakan file runtime yang tidak dilacak Git dan tidak disajikan langsung oleh web server.
- Penolakan token dicatat tanpa menyimpan nilai token atau isi payload.
- Folder `log/` tidak dilacak Git dan diblokir dari akses HTTP pada Apache. Rotasi log mengikuti kebijakan server.

Pada Nginx, perlindungan `.htaccess` tidak berlaku. Document root `public/`, konfigurasi rewrite, dan penanganan error HTTP ditentukan dalam konfigurasi server.

## Keamanan

Webhook menerima `POST` dengan `Content-Type: application/json`, membatasi payload hingga 32 KB, dan memvalidasi status serta nama monitor. Penolakan token dicatat paling banyak sekali per alamat sumber setiap 60 detik agar log tidak mudah dipenuhi request gagal. Pembatasan ini hanya untuk pencatatan; rate limit request dapat diterapkan di reverse proxy atau CDN.

`.env`, antrean, lock, dan log runtime dikecualikan dari Git. Rahasia webhook dan API key tidak boleh masuk ke commit, issue publik, atau contoh konfigurasi. Panduan pelaporan kerentanan ada di [SECURITY.md](SECURITY.md).

## Kontribusi

Perbaikan kode, dokumentasi, dan usulan fitur dapat diajukan melalui [GitHub Issues](https://github.com/ekiguistian/webhook_uptimekuma-sendwa/issues) atau [Pull Requests](https://github.com/ekiguistian/webhook_uptimekuma-sendwa/pulls). Panduan kontribusi tersedia di [CONTRIBUTING.md](CONTRIBUTING.md).

## Dukungan

Jika project ini bermanfaat, dukungan untuk segelas kopi dapat dikirim melalui [PayPal](https://paypal.me/ekiguistian).

Repository juga menyediakan tautan ini melalui `.github/FUNDING.yml`. GitHub menampilkan tombol **Sponsor** dari file tersebut jika fitur Sponsorships aktif pada pengaturan repository; lihat [dokumentasi tombol sponsor GitHub](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/displaying-a-sponsor-button-in-your-repository).
