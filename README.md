# Uptime Kuma → NgirimWA

Integrasi ini menerima webhook perubahan status dari Uptime Kuma, menyimpannya ke antrean lokal, lalu mengirim notifikasi WhatsApp melalui API NgirimWA.

## Struktur direktori

~~~text
project/
├── .env                         # rahasia dan pengaturan lokal; jangan commit
├── .env.example                 # template tanpa API key
├── api/
│   ├── config.php               # membaca .env
│   ├── uptime.php               # memvalidasi webhook dan membuat pesan antrean
│   ├── send-queue.php           # worker yang dijalankan cron
│   ├── queue.json               # antrean runtime
│   └── last-status.json         # status terakhir yang diterima NgirimWA
├── log/                         # seluruh log aplikasi dan PHP
│   ├── system.log
│   ├── send-success.log
│   ├── send-failure.log
│   ├── php-error.log
│   └── cron-run.log
└── public/
    ├── index.php                # halaman informasi umum
    └── api/uptime.php           # pintu masuk HTTP menuju api/uptime.php
~~~

## Alur sistem

~~~mermaid
flowchart LR
  Kuma["Uptime Kuma"] -->|POST JSON| Entry["public/api/uptime.php"]
  Entry --> Core["api/uptime.php"]
  Core -->|Baca konfigurasi| Env[".env di root project"]
  Core -->|Simpan perubahan status| Queue["api/queue.json"]
  Cron["Cron Linux / aaPanel"] --> Worker["api/send-queue.php"]
  Queue --> Worker
  Worker -->|GET status, x-api-key| Status["NgirimWA /devices/status"]
  Status -->|connected| Send["POST /messages/send"]
  Status -->|gagal atau offline| Failure["hapus antrean + log gagal"]
  Send -->|diterima| Group["Grup WhatsApp"]
  Worker --> Logs["Folder log/"]
~~~

1. Uptime Kuma mengirim JSON ke endpoint webhook.
2. Pintu masuk publik meneruskan request ke handler yang berada di luar document root.
3. Handler membaca konfigurasi dari .env, mengabaikan status yang sama atau sudah mengantre, lalu menyimpan pesan alert/recovery ke api/queue.json.
4. Cron menjalankan worker. Setiap eksekusi memproses maksimal satu pesan yang siap dikirim.
5. Sebelum mengirim, worker mengecek akses API dan status perangkat lewat GET /devices/status. Hanya status connected yang dilanjutkan ke POST /messages/send. NgirimWA mendokumentasikan endpoint dan nilai status ini di [panduan status perangkat](https://panduan.ngirimwa.com/api/kelola-perangkat).
6. Jika pemeriksaan API/perangkat gagal, semua item yang sedang menunggu dihapus dan dicatat sebagai gagal. Jika satu pengiriman pesan gagal, item itu dihapus dan tidak dicoba ulang. Pesan gagal tidak akan terkirim otomatis ketika koneksi pulih.

Worker mengatur expires_in ke 30 detik. Ini membatasi lama pesan berada di antrean internal NgirimWA jika perangkat terputus tepat setelah pemeriksaan status. Karena NgirimWA mengantrekan pesan yang diterima, kondisi putus yang terjadi di sela pemeriksaan dan request kirim masih dapat membuat pesan diterima oleh antrean provider; API pembatalan antrean provider tidak dipakai di integrasi ini. Lihat [panduan kirim pesan NgirimWA](https://panduan.ngirimwa.com/api/kirim-pesan).

HTTP sukses dari NgirimWA berarti pesan diterima ke antrean layanan, bukan bukti pesan sudah sampai atau dibaca. Worker mencatat tracking ID di log/send-success.log. Pelacakan status akhir belum dihubungkan.

## Fungsi public/index.php

public/index.php hanyalah halaman informasi yang tampil saat domain dibuka di browser. Halaman ini menjelaskan bahwa domain dipakai oleh Uptime Kuma dan menampilkan URL webhook. Halaman tersebut tidak menerima webhook, tidak mengirim pesan, dan tidak mengecek cron atau koneksi NgirimWA. Badge dan tag noindex hanya informasi/SEO, bukan perlindungan akses.

URL webhook yang dipasang di Uptime Kuma:

~~~text
https://webhook.nexan.id/api/uptime.php
~~~

Endpoint mengharapkan payload JSON yang memiliki monitor.name dan heartbeat.status. Field heartbeat.msg serta heartbeat.localDateTime digunakan sebagai informasi tambahan bila tersedia.

## Konfigurasi

Di root project, salin .env.example menjadi .env, lalu isi:

~~~dotenv
NGIRIMWA_API_KEY=API_key_dari_dashboard
NGIRIMWA_GROUP_ID=ID_grup_WhatsApp@g.us
NGIRIMWA_BASE_URL=https://dash.ngirimwa.com/api/v1
UPTIME_MESSAGE_SIGNATURE=Nexan Network Monitoring
UPTIME_STATUS_WEBSITE=https://status-server.nexan.id
~~~

API key dibuat di Dashboard NgirimWA → Perangkat → Kunci API. ID grup biasanya berakhiran @g.us. Signature dan URL website di atas menjadi footer pesan dan dapat diubah tanpa mengedit PHP.

.env dikecualikan dari Git; hanya .env.example yang dipush. Simpan .env dan api/ di luar document root. Akun PHP/cron perlu membaca .env dan menulis file antrean/status di api/ serta file log di log/. Batasi izin folder tersebut ke akun layanan; jangan gunakan permission 777.

## Deploy di Linux

Contoh struktur deployment:

~~~text
/var/www/webhook/
├── .env
├── api/
├── log/
└── public/
~~~

Atur document root web server ke /var/www/webhook/public. URL /api/uptime.php dilayani oleh wrapper public/api/uptime.php, sedangkan worker cron tetap berada di luar document root.

Pastikan PHP CLI dan ekstensi cURL tersedia. Salin .env.example menjadi .env, isi pengaturannya, lalu atur owner/permission agar PHP-FPM dan cron dapat membaca .env dan menulis file di api/ serta log/. Jangan gunakan permission 777.

Cari lokasi PHP CLI dengan command -v php dan lihat versinya dengan php -v. Buat cron setiap satu menit dengan crontab -e:

~~~cron
* * * * * /usr/bin/php /var/www/webhook/api/send-queue.php >> /var/www/webhook/log/cron-run.log 2>&1
~~~

Ganti /usr/bin/php dan /var/www/webhook dengan lokasi yang benar di server. Jalankan command worker sekali secara manual untuk memeriksa konfigurasi dan permission sebelum mengaktifkan cron.

## Deploy di aaPanel

1. Tambahkan domain sebagai website PHP.
2. Di pengaturan Site Directory/Document Root, arahkan root website ke folder project/public. Ikuti versi aaPanel yang digunakan; lihat panduan resmi [Website aaPanel](https://www.aapanel.com/docs/SubaaPanel/Website.html).
3. Pastikan versi PHP yang dipilih menyediakan cURL. Worker CLI tetap perlu binary PHP CLI yang sesuai.
4. Letakkan .env di root project, isi key, ID grup, URL gateway, signature, dan URL website. Pastikan akun yang menjalankan PHP dan cron memiliki permission yang sesuai.
5. Buka menu Cron → Add Task → Shell Script. Beri nama, atur Execution cycle ke setiap 1 menit, lalu isi Script content dengan command berikut. Ganti path versi PHP dan direktori project sesuai server:

~~~bash
/www/server/php/83/bin/php /www/wwwroot/webhook.nexan.id/api/send-queue.php >> /www/wwwroot/webhook.nexan.id/log/cron-run.log 2>&1
~~~

6. Klik Execute satu kali, lalu periksa Log task. Setelah berhasil, aktifkan jadwalnya. Panduan resmi aaPanel menjelaskan bahwa Shell Script cron sebaiknya memakai path absolut dan environment cron dapat berbeda dari shell interaktif: [Cron aaPanel](https://www.aapanel.com/docs/Function/Cron.html).

## Log dan penanganan error

- log/system.log berisi aktivitas umum aplikasi dan merangkum keberhasilan maupun kegagalan.
- log/send-success.log mencatat pesan yang diterima NgirimWA, termasuk tracking ID.
- log/send-failure.log mencatat pemeriksaan API/perangkat yang gagal dan pesan yang ditolak/gagal.
- log/php-error.log menjadi tujuan warning/fatal error PHP. Detail error tidak ditampilkan ke browser; endpoint hanya memberi respons umum.
- log/cron-run.log menampung output command cron Linux/aaPanel. Log task aaPanel juga dapat dilihat dari menu Cron → Log.
- Folder log/ tidak masuk Git dan diblokir dari akses HTTP pada Apache. Pastikan PHP/cron bisa menulis ke folder ini.
- API key 401/403 membuat pengiriman dihentikan untuk key tersebut. Perbaiki key/langganan; bila hanya langganan yang diperbaiki, hapus api/ngirimwa-auth.lock. Item yang sudah dinyatakan gagal tetap tidak dikirim ulang.
- Log akses web server dan log panel aaPanel tetap mengikuti lokasi yang dikonfigurasi di server; folder log/ menampung log aplikasi, PHP, dan output cron proyek.

Atur display_errors=Off pada konfigurasi PHP aaPanel/server sebagai lapisan tambahan. Pada Nginx, .htaccess tidak berlaku; document root harus tetap public agar .env, api/, dan log/ tidak bisa diakses langsung, dan konfigurasi server perlu memakai halaman error umum untuk 4xx/5xx.
