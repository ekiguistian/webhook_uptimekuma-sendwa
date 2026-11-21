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
  Worker -->|HTTPS, x-api-key| NgirimWA["NgirimWA API"]
  NgirimWA --> Group["Grup WhatsApp"]
~~~

1. Uptime Kuma mengirim JSON ke endpoint webhook.
2. Pintu masuk publik meneruskan request ke handler yang berada di luar document root.
3. Handler membaca konfigurasi dari .env, mengabaikan status yang sama atau sudah mengantre, lalu menyimpan pesan alert/recovery ke api/queue.json.
4. Cron menjalankan worker. Setiap eksekusi memproses maksimal satu pesan yang siap dikirim.
5. Worker mengirim pesan ke NgirimWA dengan header x-api-key dan ID grup sebagai tujuan. NgirimWA menerima pesan ke antreannya dan mengembalikan tracking ID.

HTTP sukses dari NgirimWA berarti pesan diterima ke antrean layanan, bukan bukti pesan sudah sampai atau dibaca. Worker mencatat tracking ID di api/send.log. Pelacakan status akhir belum dihubungkan.

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

.env dikecualikan dari Git; hanya .env.example yang dipush. Simpan .env di luar document root jika memungkinkan dan batasi izin baca ke akun PHP/cron. Root project harus dapat ditulis oleh akun worker karena file antrean, status, dan log dibuat di api/.

## Deploy di Linux

Contoh struktur deployment:

~~~text
/var/www/webhook/
├── .env
├── api/
└── public/
~~~

Atur document root web server ke /var/www/webhook/public. URL /api/uptime.php dilayani oleh wrapper public/api/uptime.php, sedangkan worker cron tetap berada di luar document root.

Pastikan PHP CLI dan ekstensi cURL tersedia. Salin .env.example menjadi .env, isi pengaturannya, lalu atur owner/permission agar PHP-FPM dan cron dapat membaca .env dan menulis file di api/. Jangan gunakan permission 777.

Cari lokasi PHP CLI dengan command -v php dan lihat versinya dengan php -v. Buat cron setiap satu menit dengan crontab -e:

~~~cron
* * * * * /usr/bin/php /var/www/webhook/api/send-queue.php >> /var/www/webhook/api/cron-run.log 2>&1
~~~

Ganti /usr/bin/php dan /var/www/webhook dengan lokasi yang benar di server. Jalankan command worker sekali secara manual untuk memeriksa konfigurasi dan permission sebelum mengaktifkan cron.

## Deploy di aaPanel

1. Tambahkan domain sebagai website PHP.
2. Di pengaturan Site Directory/Document Root, arahkan root website ke folder project/public. Ikuti versi aaPanel yang digunakan; lihat panduan resmi [Website aaPanel](https://www.aapanel.com/docs/SubaaPanel/Website.html).
3. Pastikan versi PHP yang dipilih menyediakan cURL. Worker CLI tetap perlu binary PHP CLI yang sesuai.
4. Letakkan .env di root project, isi key, ID grup, URL gateway, signature, dan URL website. Pastikan akun yang menjalankan PHP dan cron memiliki permission yang sesuai.
5. Buka menu Cron → Add Task → Shell Script. Beri nama, atur Execution cycle ke setiap 1 menit, lalu isi Script content dengan command berikut. Ganti path versi PHP dan direktori project sesuai server:

~~~bash
/www/server/php/83/bin/php /www/wwwroot/webhook.nexan.id/api/send-queue.php
~~~

6. Klik Execute satu kali, lalu periksa Log task. Setelah berhasil, aktifkan jadwalnya. Panduan resmi aaPanel menjelaskan bahwa Shell Script cron sebaiknya memakai path absolut dan environment cron dapat berbeda dari shell interaktif: [Cron aaPanel](https://www.aapanel.com/docs/Function/Cron.html).

## Retry dan pemeriksaan

- Error jaringan, HTTP 408/409/429, dan 5xx dicoba ulang dengan Retry-After atau jeda bertahap.
- HTTP 401/403 menghentikan pengiriman berulang untuk API key yang sama. Perbaiki key atau langganan. Jika langganan diperbaiki tanpa mengganti key, hapus api/ngirimwa-auth.lock.
- Error validasi lain tetap berada di antrean dengan field blocked_error untuk diperiksa sebelum dicoba ulang.
- Periksa api/app.log untuk webhook, api/send.log untuk respons worker, api/queue.json untuk antrean, dan log task di aaPanel.

Pada Nginx, .htaccess tidak berlaku. Karena itu document root harus tetap public agar .env dan api/ tidak bisa diakses langsung; tambahkan aturan deny dotfile di konfigurasi Nginx jika diperlukan.
