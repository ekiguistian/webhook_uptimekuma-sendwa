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
~~~

Webhook hanya menerima request `POST` dengan token yang cocok. Status yang sama atau sudah berada di antrean dilewati. Cron worker memproses maksimal satu pesan per eksekusi. Jika pemeriksaan API atau perangkat gagal, pesan yang menunggu dihapus dan dicatat sebagai gagal; pesan tersebut tidak dicoba ulang otomatis.

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
│   ├── queue.json               # antrean runtime
│   └── last-status.json         # status monitor terakhir
├── log/                         # log aplikasi, PHP, dan cron
└── public/                      # document root web server
    ├── index.php                # halaman utama dan generator token
    ├── .htaccess                # URL rewrite untuk Apache
    └── api/uptime.php           # wrapper publik menuju handler
~~~

Document root diarahkan ke `public/`. Dengan susunan ini, `.env`, `api/`, dan `log/` tidak menjadi file publik. PHP-FPM memerlukan akses baca ke `.env` dan akses tulis ke file antrean/status serta log; cron worker memerlukan akses yang sama.

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
UPTIME_WEBHOOK_TOKEN=
~~~

`NGIRIMWA_API_KEY` berasal dari Dashboard NgirimWA → Perangkat → Kunci API. `NGIRIMWA_GROUP_ID` menentukan grup WhatsApp tujuan. Signature dan URL status digunakan sebagai footer notifikasi. File `.env` bersifat lokal dan tidak dilacak Git; `.env.example` merupakan template publik.

## Cron worker

`api/send-queue.php` memeriksa perangkat dan memproses antrean. Jadwal satu menit umum digunakan agar pesan yang menunggu segera diproses. Lokasi PHP CLI dan direktori project bergantung pada susunan server.

### Linux cron

Cron Linux biasanya menggunakan PHP CLI dari sistem. Contoh entry `crontab`:

~~~cron
* * * * * /usr/bin/php /var/www/webhook/api/send-queue.php >> /var/www/webhook/log/cron-run.log 2>&1
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
~~~

Versi yang dipilih untuk website terlihat pada pengaturan PHP website di aaPanel. Versi default untuk perintah shell dapat dilihat pada **Advanced Setup → PHP CLI**; hasil `php -v` menunjukkan binary default shell, sedangkan path `/www/server/php/84/bin/php -v` memeriksa binary PHP 8.4 secara langsung. Direktori dapat berbeda menurut versi yang terpasang. Task cron dijalankan melalui **Cron → Add Task → Shell Script** dengan interval satu menit. Referensi aaPanel: [pengaturan website dan versi PHP](https://www.aapanel.com/docs/Function/php.html), [struktur dan perintah PHP](https://www.aapanel.com/docs/faq/Service_Manage_Commands.html), dan [cron](https://www.aapanel.com/docs/Function/Cron.html).

## Log dan operasi

- `log/system.log` berisi aktivitas umum dan ringkasan sistem.
- `log/send-success.log` mencatat pesan yang diterima NgirimWA, termasuk tracking ID.
- `log/send-failure.log` mencatat pemeriksaan API/perangkat dan pesan yang gagal.
- `log/php-error.log` menampung warning serta fatal error PHP; detail error tidak ditampilkan di browser.
- `log/cron-run.log` menampung output cron Linux/aaPanel.
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
