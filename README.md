# Uptime Kuma ke WhatsApp melalui NgirimWA

Aplikasi PHP ini menerima notifikasi status dari Uptime Kuma, memasukkannya ke antrean lokal, lalu mengirim pesan ke grup WhatsApp melalui API NgirimWA. Aplikasi menyediakan autentikasi token untuk webhook, worker cron, dan pencatatan log.

## Fitur

- Meneruskan notifikasi status UP/DOWN dan pemulihan ke WhatsApp.
- Melindungi endpoint webhook dengan header rahasia `X-Webhook-Token`.
- Menyediakan halaman untuk membuat dan menyalin token.
- Mengecek status perangkat NgirimWA sebelum mengirim pesan.
- Mencatat aktivitas, keberhasilan, kegagalan, dan error PHP di folder `log/`.
- Menyediakan URL webhook tanpa ekstensi PHP: `/webhook`.

## Alur sistem

~~~mermaid
flowchart LR
  Kuma["Uptime Kuma"] -->|POST JSON + X-Webhook-Token| Web["/webhook"]
  Web -->|rewrite internal| Handler["api/uptime.php"]
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

1. Uptime Kuma mengirim payload JSON dan header `X-Webhook-Token` ke `/webhook`.
2. Web server menulis ulang `/webhook` ke handler PHP. Handler memvalidasi token sebelum memproses payload.
3. Status baru yang valid disimpan di `api/queue.json`. Status yang sama atau sudah mengantre dilewati.
4. Cron menjalankan worker. Setiap eksekusi memproses maksimal satu pesan.
5. Worker memeriksa API dan status perangkat NgirimWA melalui `GET /devices/status`. Pesan dikirim melalui `POST /messages/send` hanya jika perangkat berstatus `connected`.
6. Jika pemeriksaan API/perangkat gagal, antrean yang menunggu dihapus dan kegagalan dicatat. Pesan yang gagal tidak dicoba ulang otomatis.

NgirimWA dapat menerima pesan ke antrean layanan sebelum pesan terkirim ke WhatsApp. Worker mengatur `expires_in` menjadi 30 detik untuk membatasi masa tunggu. Respons sukses API berarti pesan diterima ke antrean layanan, bukan bukti pesan sudah sampai atau dibaca. Lihat [panduan status perangkat](https://panduan.ngirimwa.com/api/kelola-perangkat) dan [panduan kirim pesan](https://panduan.ngirimwa.com/api/kirim-pesan).

## Struktur direktori

~~~text
project/
├── .env                         # konfigurasi rahasia; jangan commit
├── .env.example                 # contoh konfigurasi
├── api/
│   ├── config.php               # membaca .env dan mengatur log
│   ├── uptime.php               # validasi webhook dan membuat antrean
│   ├── send-queue.php           # worker cron
│   ├── queue.json               # antrean runtime
│   └── last-status.json         # status monitor terakhir
├── log/                         # log aplikasi, PHP, dan cron
└── public/
    ├── index.php                # halaman utama dan generator token
    ├── .htaccess                # rewrite URL untuk Apache
    └── api/uptime.php           # wrapper publik menuju handler
~~~

## Instalasi

### 1. Siapkan server dan file aplikasi

Siapkan server dengan PHP untuk web dan CLI serta ekstensi cURL. Unduh atau clone repository ini ke direktori project. Arahkan **document root** domain ke folder `public`, bukan ke root project. Dengan begitu, `.env`, `api/`, dan `log/` berada di luar document root.

Contoh struktur server:

~~~text
/var/www/webhook/
├── .env
├── api/
├── log/
└── public/                     # document root domain
~~~

Salin `.env.example` menjadi `.env`, lalu isi konfigurasi:

~~~dotenv
NGIRIMWA_API_KEY=api_key_dari_dashboard
NGIRIMWA_GROUP_ID=id_grup_WhatsApp@g.us
NGIRIMWA_BASE_URL=https://dash.ngirimwa.com/api/v1
UPTIME_MESSAGE_SIGNATURE=Server Monitoring
UPTIME_STATUS_WEBSITE=https://status.example.com
UPTIME_WEBHOOK_TOKEN=
~~~

Buat API key melalui Dashboard NgirimWA → Perangkat → Kunci API. Isi ID grup WhatsApp tujuan. `UPTIME_MESSAGE_SIGNATURE` dan `UPTIME_STATUS_WEBSITE` digunakan sebagai footer pesan dan dapat diganti sesuai kebutuhan.

Atur izin agar PHP-FPM dapat membaca `.env` serta menulis file antrean/status di `api/` dan log di `log/`. Cron juga harus dapat membaca `.env` dan menulis log. Batasi izin ke akun layanan; jangan gunakan permission `777`.

### 2. Aktifkan URL webhook tanpa ekstensi `.php`

URL webhook yang dipakai adalah:

~~~text
https://domain-anda/webhook
~~~

Web server menulis ulang URL tersebut secara internal ke `public/api/uptime.php`.

**Apache:** aturan sudah tersedia di `public/.htaccess`. Pastikan `mod_rewrite` aktif dan virtual host mengizinkan aturan `.htaccess` (minimal `AllowOverride FileInfo`). Document root harus mengarah ke folder `public`.

**Nginx / aaPanel dengan Nginx:** Nginx tidak membaca `.htaccess`. Tambahkan aturan berikut di dalam blok `server { ... }` domain, dan biarkan konfigurasi PHP-FPM yang sudah ada menangani `/api/uptime.php`:

~~~nginx
rewrite ^/webhook/?$ /api/uptime.php last;
~~~

Simpan konfigurasi dan reload Nginx. Jika aturan belum diterapkan, URL `/webhook` tidak akan mencapai handler.

### 3. Buat token dan konfigurasi Uptime Kuma

1. Buka halaman utama aplikasi melalui HTTPS, misalnya `https://domain-anda/`.
2. Klik **Generate Token**, lalu **Salin Token**. Halaman menampilkan URL webhook sesuai domain yang sedang dibuka. Token dibuat secara acak dan tidak disimpan oleh aplikasi.
3. Tempel token ke `UPTIME_WEBHOOK_TOKEN` di `.env` pada server.
4. Di Uptime Kuma, buat notifikasi **Webhook**, pilih metode **POST**, lalu aktifkan **Additional Headers** dan masukkan header berikut dengan token yang sama:

~~~json
{
  "X-Webhook-Token": "tempel_token_di_sini"
}
~~~

5. Gunakan URL `/webhook` yang ditampilkan di halaman utama. Simpan rahasia tersebut hanya di `.env` server dan konfigurasi Uptime Kuma. Jangan commit token ke Git atau membagikannya. Jika token diganti, perbarui `.env` dan header Uptime Kuma dengan token baru yang sama.

Webhook mengembalikan HTTP 503 bila token belum diatur di `.env`, dan HTTP 401 bila header hilang atau tidak cocok. Gunakan HTTPS agar token tidak dikirim melalui koneksi yang tidak terenkripsi.

### 4. Atur cron worker

Worker harus dijalankan berkala untuk mengirim pesan dari antrean.

**Linux:** cari lokasi PHP CLI dengan `command -v php`, lalu tambahkan cron melalui `crontab -e`:

~~~cron
* * * * * /usr/bin/php /var/www/webhook/api/send-queue.php >> /var/www/webhook/log/cron-run.log 2>&1
~~~

Sesuaikan lokasi PHP dan direktori project. Jalankan command sekali secara manual untuk memeriksa konfigurasi dan izin file.

**aaPanel:** buat task melalui **Cron → Add Task → Shell Script**, atur jadwal setiap satu menit, lalu gunakan command dengan path server Anda. Contoh:

~~~bash
/www/server/php/83/bin/php /www/wwwroot/your-domain/api/send-queue.php >> /www/wwwroot/your-domain/log/cron-run.log 2>&1
~~~

Sesuaikan versi PHP dan `your-domain`. Jalankan task sekali melalui **Execute**, periksa log, lalu aktifkan jadwalnya. Lihat panduan aaPanel untuk [website](https://www.aapanel.com/docs/SubaaPanel/Website.html) dan [cron](https://www.aapanel.com/docs/Function/Cron.html).

## Log dan penanganan error

- `log/system.log` berisi aktivitas umum dan ringkasan pengiriman.
- `log/send-success.log` mencatat pesan yang diterima NgirimWA, termasuk tracking ID.
- `log/send-failure.log` mencatat pemeriksaan API/perangkat atau pengiriman yang gagal.
- `log/php-error.log` menampung warning dan fatal error PHP. Detail error tidak ditampilkan di browser.
- `log/cron-run.log` menampung output cron Linux/aaPanel.
- Penolakan autentikasi webhook dicatat tanpa mencatat token atau isi payload.
- Folder `log/` tidak masuk Git dan diblokir dari akses HTTP pada Apache. Atur rotasi log sesuai kebijakan server.

Pada Nginx, `.htaccess` tidak berlaku. Pastikan document root tetap `public`, atur rewrite di konfigurasi server, dan gunakan halaman error umum untuk error 4xx/5xx.
